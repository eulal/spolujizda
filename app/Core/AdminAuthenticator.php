<?php declare(strict_types=1);

namespace App\Core;

use Nette;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\SimpleIdentity;


final class AdminAuthenticator implements Authenticator
{
	public function __construct(
		private string $adminPasswordHash,
	) {
	}


	public function authenticate(string $user, string $password): SimpleIdentity
	{
		if (!password_verify($password, $this->adminPasswordHash)) {
			throw new AuthenticationException('Nesprávné heslo.', self::Failure);
		}

		return new SimpleIdentity(1, 'admin', ['name' => 'Administrátor']);
	}
}
