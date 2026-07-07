<?php declare(strict_types=1);

// Maintenance mode – během aktualizace zobrazit statickou stránku
$maintenanceFile = __DIR__ . '/../temp/.maintenance';
if (is_file($maintenanceFile) && !isset($_GET['bypass_maintenance'])) {
	$maintenance = json_decode((string) file_get_contents($maintenanceFile), true);
	$startedAt = $maintenance['started_at'] ?? null;

	// Automaticky vypnout po 10 minutách (ochrana proti zaseknutí)
	if ($startedAt && strtotime($startedAt) < time() - 600) {
		@unlink($maintenanceFile);
	} else {
		http_response_code(503);
		header('Retry-After: 30');
		readfile(__DIR__ . '/maintenance.html');
		exit;
	}
}

require __DIR__ . '/../vendor/autoload.php';

$bootstrap = new App\Bootstrap;
$container = $bootstrap->bootWebApplication();
$application = $container->getByType(Nette\Application\Application::class);
$application->run();
