<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


final class RideRepository
{
	public function __construct(
		private Explorer $database,
	) {
	}


	public function getTable(): Selection
	{
		return $this->database->table('ride');
	}


	public function getById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}


	/**
	 * Vrátí aktivní jízdy pro danou akci a směr.
	 */
	public function findByEvent(int $eventId, string $direction = 'there'): Selection
	{
		return $this->getTable()
			->where('event_id', $eventId)
			->where('direction', $direction)
			->where('is_active', 1)
			->order('departure_time ASC');
	}


	/**
	 * Vrátí všechny aktivní jízdy pro danou akci (oba směry).
	 */
	public function findAllByEvent(int $eventId): Selection
	{
		return $this->getTable()
			->where('event_id', $eventId)
			->where('is_active', 1)
			->order('departure_time ASC');
	}


	public function create(array $data): ActiveRow
	{
		return $this->getTable()->insert($data);
	}


	public function update(int $id, array $data): void
	{
		$this->getTable()->where('id', $id)->update($data);
	}


	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}


	/**
	 * Počet obsazených míst v dané jízdě.
	 */
	public function getPassengerCount(int $rideId): int
	{
		return $this->database->table('passenger')
			->where('ride_id', $rideId)
			->count('*');
	}


	/**
	 * Počet volných míst v dané jízdě.
	 */
	public function getAvailableSeats(ActiveRow $ride): int
	{
		return max(0, $ride->total_seats - $this->getPassengerCount($ride->id));
	}


	/**
	 * Ověří, zda token odpovídá dané jízdě.
	 */
	public function verifyToken(int $rideId, string $token): bool
	{
		$ride = $this->getById($rideId);
		return $ride && hash_equals($ride->edit_token, $token);
	}
}
