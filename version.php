<?php declare(strict_types=1);

/**
 * Spolujízda – fallback definice verze aplikace.
 *
 * Primárně se verze čte automaticky z Git tagu (viz App\Core\Version).
 * Tento soubor slouží pouze jako záloha pro prostředí bez Gitu.
 *
 * NEMUSÍTE tento soubor ručně upravovat – stačí vytvořit Git tag:
 *   git tag v1.1.0
 *   git push origin v1.1.0
 */

const APP_VERSION = '1.0.3';
