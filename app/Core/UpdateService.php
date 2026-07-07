<?php declare(strict_types=1);

namespace App\Core;

use Nette\Database\Explorer;
use Tracy\ILogger;


/**
 * Služba pro kontrolu a provádění aktualizací aplikace.
 *
 * Kontroluje nejnovější verzi přes GitHub API (tagy),
 * provádí aktualizaci přes git pull + composer install + migrace.
 *
 * Pro privátní repozitáře nastavte githubToken v local.neon.
 * Token se používá pro GitHub API i git operace.
 */
final class UpdateService
{
	private const GITHUB_API = 'https://api.github.com/repos/%s/tags';
	private const CACHE_TTL = 3600; // 1 hodina

	private string $rootDir;
	private string $cacheFile;


	public function __construct(
		private string $githubRepo,
		private string $githubToken,
		private MigrationService $migrationService,
		private Explorer $database,
		private ?ILogger $logger = null,
	) {
		$this->rootDir = dirname(__DIR__, 2);
		$this->cacheFile = $this->rootDir . '/temp/update-check.json';
	}


	/**
	 * Vrátí aktuální verzi aplikace.
	 */
	public function getCurrentVersion(): string
	{
		return Version::get();
	}


	/**
	 * Zkontroluje nejnovější verzi na GitHubu.
	 * Výsledek cachuje po dobu CACHE_TTL.
	 *
	 * @return array{version: string|null, error: string|null, cached: bool}
	 */
	public function checkLatestVersion(bool $forceRefresh = false): array
	{
		// Zkusit cache
		if (!$forceRefresh && is_file($this->cacheFile)) {
			$cached = json_decode((string) file_get_contents($this->cacheFile), true);
			if (is_array($cached) && isset($cached['checked_at'])) {
				if (time() - $cached['checked_at'] < self::CACHE_TTL) {
					return [
						'version' => $cached['version'] ?? null,
						'error' => null,
						'cached' => true,
					];
				}
			}
		}

		// Načíst z GitHub API
		$url = sprintf(self::GITHUB_API, $this->githubRepo);

		$headers = "User-Agent: Spolujizda-Updater\r\nAccept: application/vnd.github.v3+json\r\n";
		if ($this->githubToken !== '') {
			$headers .= "Authorization: Bearer {$this->githubToken}\r\n";
		}

		$context = stream_context_create([
			'http' => [
				'header' => $headers,
				'timeout' => 10,
			],
		]);

		$response = @file_get_contents($url, false, $context);

		if ($response === false) {
			$lastError = error_get_last();
			$errorMsg = $lastError['message'] ?? '';

			// Rozlišit typ chyby pro srozumitelnou hlášku
			if (str_contains($errorMsg, '404')) {
				$error = $this->githubToken === ''
					? 'Repozitář nenalezen – pokud je privátní, nastavte githubToken v local.neon.'
					: 'Repozitář nenalezen – zkontrolujte githubRepo a platnost tokenu.';
			} elseif (str_contains($errorMsg, '401') || str_contains($errorMsg, '403')) {
				$error = 'Neplatný nebo expirovaný GitHub token – zkontrolujte githubToken v local.neon.';
			} elseif (str_contains($errorMsg, 'getaddrinfo') || str_contains($errorMsg, 'Connection')) {
				$error = 'Nelze se připojit k api.github.com – zkontrolujte internetové připojení serveru.';
			} else {
				$error = 'Nepodařilo se spojit s GitHub API: ' . $errorMsg;
			}

			$this->log($error);
			return ['version' => null, 'error' => $error, 'cached' => false];
		}

		$tags = json_decode($response, true);

		if (!is_array($tags) || empty($tags)) {
			// Žádné tagy – aktuální verze je nejnovější
			$this->saveCache($this->getCurrentVersion());
			return ['version' => $this->getCurrentVersion(), 'error' => null, 'cached' => false];
		}

		// Najít nejnovější verzi z tagů (formát v1.0.0 nebo 1.0.0)
		$latestVersion = $this->findLatestVersion($tags);

		if ($latestVersion !== null) {
			$this->saveCache($latestVersion);
		}

		return [
			'version' => $latestVersion,
			'error' => null,
			'cached' => false,
		];
	}


	/**
	 * Zjistí, zda je k dispozici nová verze.
	 */
	public function isUpdateAvailable(): bool
	{
		$result = $this->checkLatestVersion();
		if ($result['version'] === null) {
			return false;
		}

		return version_compare($result['version'], $this->getCurrentVersion(), '>');
	}


	/**
	 * Vrátí informace o dostupné aktualizaci.
	 * @return array{available: bool, current: string, latest: string|null, error: string|null}
	 */
	public function getUpdateInfo(bool $forceRefresh = false): array
	{
		$current = $this->getCurrentVersion();
		$result = $this->checkLatestVersion($forceRefresh);

		return [
			'available' => $result['version'] !== null && version_compare($result['version'], $current, '>'),
			'current' => $current,
			'latest' => $result['version'],
			'error' => $result['error'],
		];
	}


	/**
	 * Provede kompletní aktualizaci.
	 * @return array{success: bool, log: string[], error: string|null}
	 */
	public function performUpdate(): array
	{
		$log = [];
		$maintenanceFile = $this->rootDir . '/temp/.maintenance';

		try {
			// 1. Zapnout maintenance mode
			file_put_contents($maintenanceFile, json_encode([
				'started_at' => date('c'),
				'reason' => 'update',
			]));
			$log[] = '🔧 Maintenance mode zapnut';

			// 2. Nastavit git credentials (pro privátní repo)
			if ($this->githubToken !== '') {
				$this->configureGitAuth();
				$log[] = '🔑 Git autentizace nastavena';
			}

			// 3. Git fetch + pull
			$log[] = '📥 Stahuji aktualizace z Gitu...';
			$gitResult = $this->executeCommand('git fetch origin 2>&1', $this->rootDir);
			$log[] = '  fetch: ' . ($gitResult['output'] ?: 'OK');

			if ($gitResult['exitCode'] !== 0) {
				throw new \RuntimeException('Git fetch selhal: ' . $gitResult['output']);
			}

			// Zjistit, zda se změnil composer.lock
			$composerChanged = $this->hasFileChanged('composer.lock');

			$gitResult = $this->executeCommand('git pull origin master 2>&1', $this->rootDir);
			$log[] = '  pull: ' . ($gitResult['output'] ?: 'OK');

			if ($gitResult['exitCode'] !== 0) {
				throw new \RuntimeException('Git pull selhal: ' . $gitResult['output']);
			}

			// 3. Composer install (pokud se změnil composer.lock)
			if ($composerChanged) {
				$log[] = '📦 Instaluji závislosti (composer)...';
				$composerResult = $this->executeCommand(
					'composer install --no-dev --optimize-autoloader --no-interaction 2>&1',
					$this->rootDir,
				);
				$log[] = '  composer: ' . (strlen($composerResult['output']) > 200
					? substr($composerResult['output'], -200)
					: $composerResult['output']);

				if ($composerResult['exitCode'] !== 0) {
					throw new \RuntimeException('Composer install selhal: ' . $composerResult['output']);
				}
			} else {
				$log[] = '📦 Závislosti se nezměnily – přeskočeno';
			}

			// 4. Spustit migrace
			$log[] = '🗃️ Spouštím databázové migrace...';
			$migrationResult = $this->migrationService->runPendingMigrations();

			if (!empty($migrationResult['executed'])) {
				foreach ($migrationResult['executed'] as $file) {
					$log[] = "  ✅ {$file}";
				}
			} else {
				$log[] = '  Žádné nové migrace';
			}

			if (!empty($migrationResult['errors'])) {
				foreach ($migrationResult['errors'] as $file => $error) {
					$log[] = "  ❌ {$file}: {$error}";
				}
				throw new \RuntimeException('Migrace selhaly – viz log výše');
			}

			// 5. Vyčistit cache
			$log[] = '🧹 Mažu cache...';
			$this->clearCache();
			Version::clearCache();
			$log[] = '  Cache vymazána';

			// 6. Smazat update-check cache
			if (is_file($this->cacheFile)) {
				@unlink($this->cacheFile);
			}

			$log[] = '';
			$log[] = '✅ Aktualizace proběhla úspěšně!';

			$this->log('Update proveden úspěšně na verzi ' . $this->getCurrentVersion());

			return ['success' => true, 'log' => $log, 'error' => null];

		} catch (\Throwable $e) {
			$log[] = '';
			$log[] = '❌ CHYBA: ' . $e->getMessage();
			$this->log('Update selhal: ' . $e->getMessage());

			return ['success' => false, 'log' => $log, 'error' => $e->getMessage()];

		} finally {
			// Vždy uklidit
			if ($this->githubToken !== '') {
				$this->removeGitAuth();
			}
			if (is_file($maintenanceFile)) {
				@unlink($maintenanceFile);
			}
			$log[] = '🔓 Maintenance mode vypnut';
		}
	}


	/**
	 * Spustí pouze čekající databázové migrace (bez git pull).
	 * @return array{success: bool, log: string[], error: string|null}
	 */
	public function runMigrationsOnly(): array
	{
		$log = [];

		try {
			$log[] = '🗃️ Spouštím databázové migrace...';
			$result = $this->migrationService->runPendingMigrations();

			if (!empty($result['executed'])) {
				foreach ($result['executed'] as $file) {
					$log[] = "  ✅ {$file}";
				}
			} else {
				$log[] = '  Žádné nové migrace';
			}

			if (!empty($result['errors'])) {
				foreach ($result['errors'] as $file => $error) {
					$log[] = "  ❌ {$file}: {$error}";
				}
				throw new \RuntimeException('Migrace selhaly');
			}

			return ['success' => true, 'log' => $log, 'error' => null];

		} catch (\Throwable $e) {
			$log[] = '❌ CHYBA: ' . $e->getMessage();
			return ['success' => false, 'log' => $log, 'error' => $e->getMessage()];
		}
	}


	/**
	 * Zkontroluje stav systémových požadavků.
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function checkRequirements(): array
	{
		$checks = [];

		// Git
		$gitResult = $this->executeCommand('git --version 2>&1', $this->rootDir);
		$checks['git'] = [
			'ok' => $gitResult['exitCode'] === 0,
			'message' => $gitResult['exitCode'] === 0
				? trim($gitResult['output'])
				: 'Git není nainstalován',
		];

		// Git remote
		$remoteResult = $this->executeCommand('git remote get-url origin 2>&1', $this->rootDir);
		$checks['git_remote'] = [
			'ok' => $remoteResult['exitCode'] === 0,
			'message' => $remoteResult['exitCode'] === 0
				? trim($remoteResult['output'])
				: 'Git remote "origin" není nastaven',
		];

		// Composer
		$composerResult = $this->executeCommand('composer --version 2>&1', $this->rootDir);
		$checks['composer'] = [
			'ok' => $composerResult['exitCode'] === 0,
			'message' => $composerResult['exitCode'] === 0
				? trim($composerResult['output'])
				: 'Composer není nainstalován',
		];

		// exec() function
		$checks['exec'] = [
			'ok' => function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions') ?: ''), true),
			'message' => function_exists('exec')
				? 'Funkce exec() je dostupná'
				: 'Funkce exec() je zakázána',
		];

		// Zápis do projektu
		$checks['writable'] = [
			'ok' => is_writable($this->rootDir),
			'message' => is_writable($this->rootDir)
				? 'Projektový adresář je zapisovatelný'
				: 'Projektový adresář není zapisovatelný',
		];

		// Temp dir
		$checks['temp'] = [
			'ok' => is_writable($this->rootDir . '/temp'),
			'message' => is_writable($this->rootDir . '/temp')
				? 'Adresář temp/ je zapisovatelný'
				: 'Adresář temp/ není zapisovatelný',
		];

		// Git autentizace (pro privátní repozitáře)
		if ($this->githubToken !== '') {
			// Ověřit, že token funguje – zkusit přístup k API
			$testUrl = sprintf('https://api.github.com/repos/%s', $this->githubRepo);
			$testContext = stream_context_create([
				'http' => [
					'header' => "User-Agent: Spolujizda-Updater\r\nAuthorization: Bearer {$this->githubToken}\r\n",
					'timeout' => 5,
				],
			]);
			$testResponse = @file_get_contents($testUrl, false, $testContext);
			$testData = $testResponse ? json_decode($testResponse, true) : null;
			$isPrivate = is_array($testData) && ($testData['private'] ?? false);

			$checks['git_auth'] = [
				'ok' => $testResponse !== false,
				'message' => $testResponse !== false
					? 'Token platný' . ($isPrivate ? ' (privátní repozitář)' : ' (veřejný repozitář)')
					: 'Token není platný nebo nemá přístup k repozitáři',
			];
		} else {
			$checks['git_auth'] = [
				'ok' => true,
				'message' => 'Bez tokenu (veřejný repozitář)',
			];
		}

		return $checks;
	}


	/**
	 * Vrátí seznam čekajících migrací (proxy pro MigrationService).
	 * @return string[]
	 */
	public function getPendingMigrations(): array
	{
		return $this->migrationService->getPendingMigrations();
	}


	/**
	 * Vrátí seznam provedených migrací z databáze.
	 * @return \Nette\Database\Row[]
	 */
	public function getExecutedMigrationRows(): array
	{
		try {
			$this->migrationService->ensureMigrationsTable();
			return $this->database->query('SELECT * FROM `_migrations` ORDER BY id')->fetchAll();
		} catch (\Throwable) {
			return [];
		}
	}


	// ── Privátní metody ──────────────────────────────────────────

	/**
	 * Nastaví git autentizaci pro privátní repozitáře.
	 *
	 * Používá git http.extraheader pro vložení Authorization hlavičky
	 * bez trvalé modifikace remote URL.
	 */
	private function configureGitAuth(): void
	{
		$encodedToken = base64_encode("x-access-token:{$this->githubToken}");

		// Nastavit lokální git config pro tento repozitář
		$this->executeCommand(
			sprintf(
				'git config http.https://github.com/.extraheader "Authorization: Basic %s"',
				$encodedToken,
			),
			$this->rootDir,
		);
	}


	/**
	 * Odstraní git autentizaci (po aktualizaci).
	 */
	private function removeGitAuth(): void
	{
		$this->executeCommand(
			'git config --unset http.https://github.com/.extraheader 2>/dev/null',
			$this->rootDir,
		);
	}

	/**
	 * Najde nejnovější sémantickou verzi z Git tagů.
	 */
	private function findLatestVersion(array $tags): ?string
	{
		$versions = [];

		foreach ($tags as $tag) {
			$name = $tag['name'] ?? '';
			// Odstranit prefix 'v' (v1.0.0 → 1.0.0)
			$version = ltrim($name, 'v');
			if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
				$versions[] = $version;
			}
		}

		if (empty($versions)) {
			return null;
		}

		// Seřadit podle semver a vzít nejnovější
		usort($versions, 'version_compare');
		return end($versions);
	}


	/**
	 * Zjistí, zda se daný soubor změnil mezi lokálním a vzdáleným stavem.
	 */
	private function hasFileChanged(string $filename): bool
	{
		$result = $this->executeCommand(
			"git diff HEAD..origin/master --name-only -- {$filename} 2>&1",
			$this->rootDir,
		);
		return trim($result['output']) !== '';
	}


	/**
	 * Spustí shell příkaz a vrátí výsledek.
	 * @return array{output: string, exitCode: int}
	 */
	private function executeCommand(string $command, string $cwd): array
	{
		$output = '';
		$exitCode = -1;

		$descriptorspec = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w'],  // stderr
		];

		$process = proc_open($command, $descriptorspec, $pipes, $cwd);

		if (is_resource($process)) {
			fclose($pipes[0]);
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$exitCode = proc_close($process);
			$output = trim(($stdout ?: '') . ($stderr ? "\n" . $stderr : ''));
		}

		return ['output' => $output, 'exitCode' => $exitCode];
	}


	/**
	 * Vymaže Nette cache (temp adresář).
	 * Ponechává samotné složky (strukturu) a maže pouze soubory, aby běžící PHP procesy
	 * (např. RobotLoader) neselhaly při pokusu o zápis zámků/keše na konci požadavku.
	 */
	private function clearCache(): void
	{
		$cacheDir = $this->rootDir . '/temp/cache';
		if (is_dir($cacheDir)) {
			$this->cleanDirectory($cacheDir);
		}
	}


	/**
	 * Rekurzivně vymaže obsah adresáře (pouze soubory), ale zachová adresáře.
	 */
	private function cleanDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$items = scandir($dir);
		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path)) {
				$this->cleanDirectory($path);
			} else {
				@unlink($path);
			}
		}
	}


	/**
	 * Uloží výsledek kontroly verze do cache.
	 */
	private function saveCache(string $version): void
	{
		$dir = dirname($this->cacheFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}

		@file_put_contents($this->cacheFile, json_encode([
			'version' => $version,
			'checked_at' => time(),
		], JSON_PRETTY_PRINT));
	}


	/**
	 * Zaloguje zprávu.
	 */
	private function log(string $message): void
	{
		$this->logger?->log('[UpdateService] ' . $message, ILogger::INFO);

		// Zapsat i do update.log
		$logFile = $this->rootDir . '/log/update.log';
		$line = date('[Y-m-d H:i:s] ') . $message . "\n";
		@file_put_contents($logFile, $line, FILE_APPEND);
	}
}
