<?php declare(strict_types=1);

namespace App\Core;


/**
 * Statická třída pro přístup k verzi aplikace.
 *
 * Verze se automaticky čte z Git tagu (v1.0.0 → 1.0.0).
 * Není potřeba ručně udržovat version.php – stačí tagovat:
 *   git tag v1.1.0 && git push origin v1.1.0
 *
 * Pořadí zdrojů:
 *   1. Cache soubor temp/version.cache (rychlé, bez volání gitu)
 *   2. Git tag (git describe --tags --abbrev=0)
 *   3. Fallback na version.php (pro prostředí bez gitu)
 *
 * Používá se v Latte šablonách: {App\Core\Version::get()}
 */
final class Version
{
	private static ?string $cached = null;


	/**
	 * Vrátí aktuální verzi aplikace.
	 */
	public static function get(): string
	{
		if (self::$cached !== null) {
			return self::$cached;
		}

		$rootDir = dirname(__DIR__, 2);

		// 1. Zkusit cache soubor (vymazává se při aktualizaci)
		$cacheFile = $rootDir . '/temp/version.cache';
		if (is_file($cacheFile)) {
			$version = trim((string) file_get_contents($cacheFile));
			if ($version !== '') {
				return self::$cached = $version;
			}
		}

		// 2. Přečíst z Git tagu
		$version = self::readFromGitTag($rootDir);

		// 3. Fallback na version.php
		if ($version === null) {
			$version = self::readFromFile($rootDir);
		}

		// Uložit do cache
		self::saveCache($cacheFile, $version);

		return self::$cached = $version;
	}


	/**
	 * Vymaže cache – volat po aktualizaci nebo tagu.
	 */
	public static function clearCache(): void
	{
		self::$cached = null;
		$cacheFile = dirname(__DIR__, 2) . '/temp/version.cache';
		if (is_file($cacheFile)) {
			@unlink($cacheFile);
		}
	}


	/**
	 * Přečte verzi z nejnovějšího Git tagu.
	 */
	private static function readFromGitTag(string $rootDir): ?string
	{
		// Ověřit, že jsme v git repozitáři
		if (!is_dir($rootDir . '/.git')) {
			return null;
		}

		$descriptorspec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open(
			'git describe --tags --abbrev=0 2>/dev/null',
			$descriptorspec,
			$pipes,
			$rootDir,
		);

		if (!is_resource($process)) {
			return null;
		}

		fclose($pipes[0]);
		$tag = trim((string) stream_get_contents($pipes[1]));
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		if ($exitCode !== 0 || $tag === '') {
			return null;
		}

		// Odstranit prefix 'v' (v1.0.0 → 1.0.0)
		$version = ltrim($tag, 'v');

		// Ověřit formát semver
		if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
			return $version;
		}

		return null;
	}


	/**
	 * Přečte verzi ze souboru version.php (fallback).
	 */
	private static function readFromFile(string $rootDir): string
	{
		$versionFile = $rootDir . '/version.php';
		if (is_file($versionFile)) {
			require_once $versionFile;
		}
		return defined('APP_VERSION') ? APP_VERSION : '0.0.0';
	}


	/**
	 * Uloží verzi do cache souboru.
	 */
	private static function saveCache(string $cacheFile, string $version): void
	{
		$dir = dirname($cacheFile);
		if (is_dir($dir) && is_writable($dir)) {
			@file_put_contents($cacheFile, $version);
		}
	}
}
