<?php declare(strict_types=1);

namespace App\Core;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;

final class SettingsService
{
	private string $configPath;

	public function __construct()
	{
		$this->configPath = dirname(__DIR__, 2) . '/config/local.neon';
	}

	public function getSettings(): array
	{
		if (!is_file($this->configPath)) {
			return [];
		}

		try {
			$content = FileSystem::read($this->configPath);
			return Neon::decode($content) ?? [];
		} catch (\Throwable) {
			return [];
		}
	}

	public function getMergedSettings(): array
	{
		$local = $this->getSettings();
		$defaults = [
			'parameters' => [
				'baseUrl' => '',
				'mailFrom' => 'spolujizda@example.com',
				'mailFromName' => 'Spolujízda',
				'co2EmissionFactor' => 0.150,
				'githubRepo' => 'owner/repo',
				'githubToken' => '',
				'gdprAuthor' => '',
				'gdprEmail' => '',
				'gdprText' => '',
				'donationAccount' => '',
				'donationMessage' => 'Dar Spolujízda',
				'donationUrl' => '',
				'donationText' => '',
			],
			'mail' => [
				'smtp' => false,
				'host' => '',
				'port' => 587,
				'username' => '',
				'password' => '',
				'secure' => 'tls',
			]
		];

		$merged = $defaults;
		if (isset($local['parameters'])) {
			$merged['parameters'] = array_merge($defaults['parameters'], $local['parameters']);
		}
		if (isset($local['mail'])) {
			$merged['mail'] = array_merge($defaults['mail'], $local['mail']);
		}
		
		if (isset($local['database'])) {
			$merged['database'] = $local['database'];
		}

		return $merged;
	}

	public function saveSettings(array $values): void
	{
		$current = $this->getSettings();

		// Zajištění struktury
		if (!isset($current['parameters'])) {
			$current['parameters'] = [];
		}
		if (!isset($current['mail'])) {
			$current['mail'] = [];
		}

		// Aktualizace parametrů
		if (isset($values['baseUrl'])) {
			$current['parameters']['baseUrl'] = rtrim($values['baseUrl'], '/');
		}
		if (isset($values['mailFrom'])) {
			$current['parameters']['mailFrom'] = $values['mailFrom'];
		}
		if (isset($values['mailFromName'])) {
			$current['parameters']['mailFromName'] = $values['mailFromName'];
		}
		if (isset($values['co2EmissionFactor'])) {
			$current['parameters']['co2EmissionFactor'] = (float) $values['co2EmissionFactor'];
		}
		if (isset($values['githubRepo'])) {
			$current['parameters']['githubRepo'] = $values['githubRepo'];
		}
		if (isset($values['githubToken']) && $values['githubToken'] !== '') {
			$current['parameters']['githubToken'] = $values['githubToken'];
		}
		if (isset($values['removeGithubToken']) && $values['removeGithubToken']) {
			$current['parameters']['githubToken'] = '';
		}
		if (array_key_exists('gdprAuthor', $values)) {
			$current['parameters']['gdprAuthor'] = $values['gdprAuthor'] ?? '';
		}
		if (array_key_exists('gdprEmail', $values)) {
			$current['parameters']['gdprEmail'] = $values['gdprEmail'] ?? '';
		}
		if (array_key_exists('gdprText', $values)) {
			$current['parameters']['gdprText'] = $values['gdprText'] ?? '';
		}
		if (array_key_exists('donationAccount', $values)) {
			$current['parameters']['donationAccount'] = $values['donationAccount'] ?? '';
		}
		if (array_key_exists('donationMessage', $values)) {
			$current['parameters']['donationMessage'] = $values['donationMessage'] ?? '';
		}
		if (array_key_exists('donationUrl', $values)) {
			$current['parameters']['donationUrl'] = $values['donationUrl'] ?? '';
		}
		if (array_key_exists('donationText', $values)) {
			$current['parameters']['donationText'] = $values['donationText'] ?? '';
		}

		// Aktualizace hesla admina (pokud je zadáno)
		if (isset($values['newPassword']) && $values['newPassword'] !== '') {
			$current['parameters']['adminPasswordHash'] = password_hash($values['newPassword'], PASSWORD_BCRYPT);
		}

		// Aktualizace mail/SMTP nastavení
		if (isset($values['smtp'])) {
			$current['mail']['smtp'] = (bool) $values['smtp'];
		}
		if (isset($values['smtpHost'])) {
			$current['mail']['host'] = $values['smtpHost'];
		}
		if (isset($values['smtpPort'])) {
			$current['mail']['port'] = (int) $values['smtpPort'];
		}
		if (isset($values['smtpUsername'])) {
			$current['mail']['username'] = $values['smtpUsername'];
		}
		if (isset($values['smtpPassword']) && $values['smtpPassword'] !== '') {
			$current['mail']['password'] = $values['smtpPassword'];
		}
		if (isset($values['smtpSecure'])) {
			$current['mail']['secure'] = $values['smtpSecure'] !== '' ? $values['smtpSecure'] : null;
		}

		// Uložení zpět do souboru
		$content = Neon::encode($current, Neon::BLOCK);
		
		$header = "# Zkopírujte jako local.neon a vyplňte skutečné hodnoty\n# Tento soubor NEDÁVEJTE do Gitu!\n# Automaticky uloženo administrací\n\n";
		FileSystem::write($this->configPath, $header . $content);

		// Smazání cache DI kontejneru, aby se změny projevily v aplikaci
		$cacheDir = dirname($this->configPath, 2) . '/temp/cache/nette.configurator';
		if (is_dir($cacheDir)) {
			FileSystem::delete($cacheDir);
		}
	}
}
