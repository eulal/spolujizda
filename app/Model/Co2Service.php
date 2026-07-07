<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;


/**
 * Služba pro výpočet ušetřených emisí CO2 díky spolujízdě.
 *
 * Metodologie:
 * - Každý spolujezdec, který sdílí jízdu, by jinak jel vlastním autem.
 * - Ušetřené CO2 = počet_spolujezdců × vzdálenost_km × emisní_faktor.
 * - Výchozí emisní faktor: 150 g CO2/km (průměr osobního auta v ČR).
 */
final class Co2Service
{
	/** Výchozí emisní faktor v kg CO2 na km */
	private const float DEFAULT_EMISSION_FACTOR = 0.150;

	/** Výchozí vzdálenost v km, pokud řidič nevyplní */
	private const int DEFAULT_DISTANCE_KM = 150;


	public function __construct(
		private Explorer $database,
		private float $co2EmissionFactor = self::DEFAULT_EMISSION_FACTOR,
	) {
	}


	/**
	 * Vrátí emisní faktor v kg CO2/km.
	 */
	public function getEmissionFactor(): float
	{
		return $this->co2EmissionFactor;
	}


	/**
	 * Výchozí vzdálenost v km.
	 */
	public function getDefaultDistanceKm(): int
	{
		return self::DEFAULT_DISTANCE_KM;
	}


	public function getTotalSavedCo2(): float
	{
		$result = $this->database->query('
			SELECT COALESCE(SUM(sub.saved_co2), 0) AS total
			FROM (
				SELECT
					(SELECT COUNT(*) FROM passenger WHERE ride_id = r.id)
					* COALESCE(r.distance_km, ?)
					* ?
					AS saved_co2
				FROM ride r
				INNER JOIN event e ON r.event_id = e.id
				WHERE r.is_active = 1 AND DATE(e.date_to) < DATE(?)
			) sub
		', self::DEFAULT_DISTANCE_KM, $this->co2EmissionFactor, (new \DateTime())->format('Y-m-d'))->fetch();

		return round((float) $result->total, 1);
	}


	/**
	 * Celkové ušetřené CO2 pro konkrétní akci (v kg).
	 */
	public function getEventSavedCo2(int $eventId): float
	{
		$result = $this->database->query('
			SELECT COALESCE(SUM(sub.saved_co2), 0) AS total
			FROM (
				SELECT
					(SELECT COUNT(*) FROM passenger WHERE ride_id = r.id)
					* COALESCE(r.distance_km, ?)
					* ?
					AS saved_co2
				FROM ride r
				WHERE r.event_id = ? AND r.is_active = 1
			) sub
		', self::DEFAULT_DISTANCE_KM, $this->co2EmissionFactor, $eventId)->fetch();

		return round((float) $result->total, 1);
	}


	/**
	 * Ušetřené CO2 pro konkrétní jízdu (v kg).
	 */
	public function getRideSavedCo2(int $rideId, ?int $distanceKm = null, ?int $passengerCount = null): float
	{
		if ($passengerCount === null) {
			$passengerCount = $this->database->table('passenger')
				->where('ride_id', $rideId)
				->count('*');
		}

		$distance = $distanceKm ?? self::DEFAULT_DISTANCE_KM;

		return round($passengerCount * $distance * $this->co2EmissionFactor, 1);
	}


	/**
	 * Celkový počet spolujezdců.
	 */
	public function getTotalPassengerCount(): int
	{
		$result = $this->database->query('
			SELECT COUNT(*) AS cnt
			FROM passenger p
			INNER JOIN ride r ON p.ride_id = r.id
			INNER JOIN event e ON r.event_id = e.id
			WHERE r.is_active = 1 AND DATE(e.date_to) < DATE(?)
		', (new \DateTime())->format('Y-m-d'))->fetch();

		return (int) $result->cnt;
	}


	/**
	 * Celkový počet jízd s alespoň jedním spolujezdcem.
	 */
	public function getSharedRideCount(): int
	{
		$result = $this->database->query('
			SELECT COUNT(DISTINCT r.id) AS cnt
			FROM ride r
			INNER JOIN passenger p ON p.ride_id = r.id
			INNER JOIN event e ON r.event_id = e.id
			WHERE r.is_active = 1 AND DATE(e.date_to) < DATE(?)
		', (new \DateTime())->format('Y-m-d'))->fetch();

		return (int) $result->cnt;
	}
}
