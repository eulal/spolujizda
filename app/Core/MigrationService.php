<?php declare(strict_types=1);

namespace App\Core;

use Nette\Database\Explorer;


/**
 * Služba pro správu databázových migrací.
 *
 * Migrace jsou SQL soubory ve složce migrations/, pojmenované
 * podle vzoru: NNN_nazev_migrace.sql (např. 001_create_migrations_table.sql).
 * Spouštějí se postupně v abecedním pořadí. Každá migrace se spustí jen jednou.
 */
final class MigrationService
{
	private string $migrationsDir;


	public function __construct(
		private Explorer $database,
	) {
		$this->migrationsDir = dirname(__DIR__, 2) . '/migrations';
	}


	/**
	 * Zajistí, že tabulka _migrations existuje.
	 */
	public function ensureMigrationsTable(): void
	{
		$this->database->query("
			CREATE TABLE IF NOT EXISTS `_migrations` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`version` VARCHAR(10) NOT NULL COMMENT 'Verze aplikace',
				`filename` VARCHAR(255) NOT NULL COMMENT 'Název migračního souboru',
				`checksum` VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash obsahu migrace',
				`executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE KEY `uq_filename` (`filename`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}


	/**
	 * Vrátí seznam všech migračních souborů (seřazených).
	 * @return string[] filenames
	 */
	public function getAvailableMigrations(): array
	{
		if (!is_dir($this->migrationsDir)) {
			return [];
		}

		$files = glob($this->migrationsDir . '/*.sql');
		if ($files === false) {
			return [];
		}

		$filenames = array_map('basename', $files);
		sort($filenames, SORT_NATURAL);
		return $filenames;
	}


	/**
	 * Vrátí seznam již provedených migrací.
	 * @return string[] filenames (as keys and values)
	 */
	public function getExecutedMigrations(): array
	{
		$this->ensureMigrationsTable();

		$filenames = $this->database->query(
			'SELECT filename FROM `_migrations` ORDER BY id',
		)->fetchPairs(null, 'filename');

		// Vrátit jako [filename => filename] pro rychlé isset() vyhledávání
		return array_combine($filenames, $filenames) ?: [];
	}


	/**
	 * Vrátí seznam čekajících (dosud nespuštěných) migrací.
	 * @return string[] filenames
	 */
	public function getPendingMigrations(): array
	{
		$available = $this->getAvailableMigrations();
		$executed = $this->getExecutedMigrations();

		return array_values(array_filter(
			$available,
			fn(string $file) => !isset($executed[$file]),
		));
	}


	/**
	 * Spustí všechny čekající migrace.
	 * @return array{executed: string[], errors: array<string, string>}
	 */
	public function runPendingMigrations(): array
	{
		$this->ensureMigrationsTable();

		$version = Version::get();

		$pending = $this->getPendingMigrations();
		$executed = [];
		$errors = [];

		foreach ($pending as $filename) {
			try {
				$this->executeMigration($filename, $version);
				$executed[] = $filename;
			} catch (\Throwable $e) {
				$errors[$filename] = $e->getMessage();
				break; // Zastavit při první chybě
			}
		}

		return [
			'executed' => $executed,
			'errors' => $errors,
		];
	}


	/**
	 * Spustí jednu migraci.
	 */
	private function executeMigration(string $filename, string $version): void
	{
		$filepath = $this->migrationsDir . '/' . $filename;

		if (!is_file($filepath)) {
			throw new \RuntimeException("Migrační soubor nenalezen: {$filename}");
		}

		$sql = file_get_contents($filepath);
		if ($sql === false) {
			throw new \RuntimeException("Nelze přečíst migrační soubor: {$filename}");
		}

		$checksum = hash('sha256', $sql);

		// Spustit SQL – podporuje více příkazů oddělených středníkem
		$statements = $this->splitStatements($sql);
		foreach ($statements as $statement) {
			$statement = trim($statement);
			if ($statement !== '' && !$this->isComment($statement)) {
				$this->database->query($statement);
			}
		}

		// Zaznamenat migraci
		$this->database->query('INSERT INTO `_migrations`', [
			'version' => $version,
			'filename' => $filename,
			'checksum' => $checksum,
		]);
	}


	/**
	 * Rozdělí SQL na jednotlivé příkazy.
	 * @return string[]
	 */
	private function splitStatements(string $sql): array
	{
		// Jednoduchý split po střednících (nefunguje s uloženými procedurami,
		// ale pro naše migrace je to dostatečné)
		return preg_split('/;\s*$/m', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	}


	/**
	 * Kontroluje, zda je řetězec jen komentář.
	 */
	private function isComment(string $statement): bool
	{
		$cleaned = trim($statement);
		return $cleaned === '' || str_starts_with($cleaned, '--') || str_starts_with($cleaned, '/*');
	}
}
