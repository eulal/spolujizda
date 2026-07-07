<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Nette\Utils\DateTime;


final class EventRepository
{
	public function __construct(
		private Explorer $database,
	) {
	}


	public function getTable(): Selection
	{
		return $this->database->table('event');
	}


	public function getById(int $id): ?ActiveRow
	{
		return $this->getTable()->get($id);
	}


	/**
	 * Vrátí aktivní akce seřazené od nejbližší.
	 */
	public function findActive(): Selection
	{
		return $this->getTable()
			->where('is_active', 1)
			->order('date_from ASC');
	}


	/**
	 * Vrátí nadcházející akce (date_to >= dnes).
	 */
	public function findUpcoming(): Selection
	{
		return $this->findActive()
			->where('date_to >= ?', new DateTime('today'));
	}


	/**
	 * Vrátí minulé akce.
	 */
	public function findPast(): Selection
	{
		return $this->findActive()
			->where('date_to < ?', new DateTime('today'))
			->order('date_from DESC');
	}


	/**
	 * Vrátí všechny akce pro admin přehled.
	 */
	public function findAll(): Selection
	{
		return $this->getTable()
			->order('date_from DESC');
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
	 * Počet jízd pro danou akci.
	 */
	public function getRideCount(int $eventId, ?string $direction = null): int
	{
		$query = $this->database->table('ride')
			->where('event_id', $eventId)
			->where('is_active', 1);
		if ($direction !== null) {
			$query->where('direction', $direction);
		}
		return $query->count('*');
	}


	/**
	 * Celkový počet volných míst pro danou akci.
	 */
	public function getAvailableSeatsCount(int $eventId, ?string $direction = null): int
	{
		$query = $this->database->table('ride')
			->where('event_id', $eventId)
			->where('is_active', 1);
		if ($direction !== null) {
			$query->where('direction', $direction);
		}
		$rides = $query->fetchAll();

		$total = 0;
		foreach ($rides as $ride) {
			$occupied = $this->database->table('passenger')
				->where('ride_id', $ride->id)
				->count('*');
			$total += max(0, $ride->total_seats - $occupied);
		}

		return $total;
	}


	/**
	 * Celkový počet nabízených míst pro danou akci.
	 */
	public function getTotalSeatsCount(int $eventId, ?string $direction = null): int
	{
		$query = $this->database->table('ride')
			->where('event_id', $eventId)
			->where('is_active', 1);
		if ($direction !== null) {
			$query->where('direction', $direction);
		}
		return (int) $query->sum('total_seats');
	}


	/**
	 * Počet poptávek pro danou akci.
	 */
	public function getRequestCount(int $eventId, ?string $direction = null): int
	{
		$query = $this->database->table('ride_request')
			->where('event_id', $eventId)
			->where('is_fulfilled', 0);
		if ($direction !== null) {
			$query->where('direction', $direction);
		}
		return $query->count('*');
	}


	/**
	 * Celkový počet spolujezdců pro danou akci.
	 */
	public function getPassengerCount(int $eventId): int
	{
		$result = $this->database->query('
			SELECT COUNT(*) AS cnt
			FROM passenger p
			INNER JOIN ride r ON p.ride_id = r.id
			WHERE r.event_id = ? AND r.is_active = 1
		', $eventId)->fetch();

		return (int) ($result->cnt ?? 0);
	}
}

