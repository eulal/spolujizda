<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


final class PassengerRepository
{
	public function __construct(
		private Explorer $database,
	) {
	}


	public function getTable(): Selection
	{
		return $this->database->table('passenger');
	}


	public function getById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}


	/**
	 * Vrátí spolujezdce pro danou jízdu.
	 */
	public function findByRide(int $rideId): Selection
	{
		return $this->getTable()
			->where('ride_id', $rideId)
			->order('created_at ASC');
	}


	public function create(array $data): ActiveRow
	{
		return $this->getTable()->insert($data);
	}


	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}


	/**
	 * Ověří, zda token odpovídá danému spolujezdci.
	 */
	public function verifyToken(int $passengerId, string $token): bool
	{
		$passenger = $this->getById($passengerId);
		return $passenger && hash_equals($passenger->edit_token, $token);
	}
}
