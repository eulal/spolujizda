<?php declare(strict_types=1);

namespace App\Core;

use Nette;
use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	use Nette\StaticClass;

	public static function createRouter(): RouteList
	{
		$router = new RouteList;

		// Admin routes
		$router->addRoute('admin/<action=default>[/<id \d+>]', 'Admin:default');

		// Event detail + nested actions
		$router->addRoute('akce/<id \d+>[-<slug [a-z0-9-]+>][/<action=detail>]', 'Event:detail');

		// Ride routes
		$router->addRoute('jizda/<id \d+>/<action=detail>', 'Ride:detail');

		// Static pages
		$router->addRoute('co2-metodologie', 'Home:co2Metodologie');

		// Home
		$router->addRoute('<presenter>/<action=default>[/<id>]', 'Home:default');

		return $router;
	}
}
