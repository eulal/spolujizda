<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;


final class RideRequestRepository
{
	public function __construct(
		private Explorer $database,
	) {
	}


	public function getTable(): Selection
	{
		return $this->database->table('ride_request');
	}


	public function getById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}


	/**
	 * Vrátí nesplněné poptávky pro danou akci a směr.
	 */
	public function findByEvent(int $eventId, string $direction = 'there'): Selection
	{
		return $this->getTable()
			->where('event_id', $eventId)
			->where('direction', $direction)
			->where('is_fulfilled', 0)
			->order('created_at ASC');
	}


	/**
	 * Vrátí všechny nesplněné poptávky pro danou akci.
	 */
	public function findAllUnfulfilledByEvent(int $eventId): Selection
	{
		return $this->getTable()
			->where('event_id', $eventId)
			->where('is_fulfilled', 0)
			->order('created_at ASC');
	}


	/**
	 * Vrátí poptávky s e-mailem pro notifikaci o nové jízdě.
	 */
	public function findNotifiableByEvent(int $eventId, string $direction): Selection
	{
		return $this->getTable()
			->where('event_id', $eventId)
			->where('direction', $direction)
			->where('is_fulfilled', 0)
			->where('email IS NOT NULL')
			->where('email != ?', '');
	}


	public function create(array $data): ActiveRow
	{
		return $this->getTable()->insert($data);
	}


	public function markFulfilled(int $id): void
	{
		$this->getTable()->where('id', $id)->update(['is_fulfilled' => 1]);
	}


	public function delete(int $id): void
	{
		$this->getTable()->where('id', $id)->delete();
	}


	/**
	 * Ověří, zda token odpovídá dané poptávce.
	 */
	public function verifyToken(int $requestId, string $token): bool
	{
		$request = $this->getById($requestId);
		return $request && hash_equals($request->edit_token, $token);
	}
}
