<?php declare(strict_types=1);

namespace App\Core;

use Nette;


final class TokenGenerator
{
	use Nette\StaticClass;

	/**
	 * Generuje kryptograficky bezpečný náhodný token.
	 */
	public static function generate(int $length = 32): string
	{
		return bin2hex(random_bytes($length));
	}
}
