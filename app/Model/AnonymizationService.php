<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Utils\DateTime;

final class AnonymizationService
{
	public function __construct(
		private Explorer $database,
	) {
	}


	/**
	 * Anonymizuje osobní údaje u akcí, které již skončily.
	 */
	public function anonymizePastEvents(): void
	{
		$now = new DateTime();

		// 1. Anonymizace spolujezdců u minulých akcí
		$this->database->query('
			UPDATE passenger p
			JOIN ride r ON p.ride_id = r.id
			JOIN event e ON r.event_id = e.id
			SET p.name = ?, p.email = ?, p.phone = ?, p.pickup_note = NULL, p.edit_token = ?
			WHERE e.date_to < ? AND p.email != ?
		', 'Anonymní spolujezdec', 'anonymizováno', '', '', $now, 'anonymizováno');

		// 2. Anonymizace řidičů u minulých akcí
		$this->database->query('
			UPDATE ride r
			JOIN event e ON r.event_id = e.id
			SET r.driver_name = ?, r.driver_email = ?, r.driver_phone = NULL, r.note = NULL, r.edit_token = ?
			WHERE e.date_to < ? AND r.driver_email != ?
		', 'Anonymní řidič', 'anonymizováno', '', $now, 'anonymizováno');

		// 3. Anonymizace poptávek po spolujízdě u minulých akcí
		$this->database->query('
			UPDATE ride_request rr
			JOIN event e ON rr.event_id = e.id
			SET rr.name = NULL, rr.email = ?, rr.phone = NULL, rr.note = NULL, rr.edit_token = ?
			WHERE e.date_to < ? AND rr.email != ?
		', 'anonymizováno', '', $now, 'anonymizováno');
	}
}
