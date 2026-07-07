<?php declare(strict_types=1);

namespace App\Presentation\Ride;

use App\Core\TokenGenerator;
use App\Model\Co2Service;
use App\Model\EmailService;
use App\Model\PassengerRepository;
use App\Model\RideRepository;
use App\Presentation\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Http\IResponse;


final class RidePresenter extends BasePresenter
{
	public function __construct(
		private RideRepository $rideRepository,
		private PassengerRepository $passengerRepository,
		private EmailService $emailService,
		private Co2Service $co2Service,
	) {
	}





	// ── Detail jízdy ──

	public function renderDetail(int $id, ?string $token = null): void
	{
		$ride = $this->rideRepository->getById($id);
		if (!$ride || !$ride->is_active) {
			$this->error('Jízda nenalezena.', IResponse::S404_NotFound);
		}

		$this->checkEventActive($ride);

		if ($token !== null) {
			if (hash_equals($ride->edit_token, $token)) {
				$this->saveToken('ride', $id, $token);
				$this->redirect('this', ['id' => $id, 'token' => null]);
			}
		}

		$event = $ride->ref('event', 'event_id');
		$passengers = $this->passengerRepository->findByRide($id)->fetchAll();
		$available = $this->rideRepository->getAvailableSeats($ride);

		$this->template->ride = $ride;
		$this->template->event = $event;
		$this->template->passengers = $passengers;
		$this->template->availableSeats = $available;
		$this->template->passengerCount = count($passengers);
		$this->template->savedCo2 = $this->co2Service->getRideSavedCo2(
			$ride->id,
			$ride->distance_km,
			count($passengers),
		);

		// Tokeny z cookies
		$this->template->myRideTokens = $this->getMyTokens('ride');
		$this->template->myPassengerTokens = $this->getMyTokens('passenger');

		$this->template->isPastRide = $ride->departure_time < new \DateTime();
	}


	// ── Přihlášení spolujezdce ──

	public function actionJoin(int $id): void
	{
		$ride = $this->rideRepository->getById($id);
		if (!$ride || !$ride->is_active) {
			$this->error('Jízda nenalezena.', IResponse::S404_NotFound);
		}

		$this->checkEventActive($ride);

		if ($ride->departure_time < new \DateTime()) {
			$this->flashMessage('Tato jízda již proběhla, nelze se k ní přihlásit.', 'error');
			$this->redirect('detail', $id);
		}

		$available = $this->rideRepository->getAvailableSeats($ride);
		if ($available <= 0) {
			$this->flashMessage('Tato jízda je již plná.', 'error');
			$this->redirect('detail', $id);
		}

		$this->template->ride = $ride;
		$this->template->event = $ride->ref('event', 'event_id');
		$this->template->availableSeats = $available;
	}


	protected function createComponentJoinRideForm(): Form
	{
		$form = new Form;

		$form->addText('name', 'Vaše jméno:')
			->setRequired('Zadejte své jméno.')
			->setHtmlAttribute('placeholder', 'Jan Novák');

		$form->addEmail('email', 'E-mail:')
			->setRequired('Zadejte svůj e-mail.')
			->setHtmlAttribute('placeholder', 'jan@email.cz')
			->setOption('description', 'Slouží pro komunikaci s řidičem (nebude veřejně zobrazen).');

		$form->addText('phone', 'Telefon:')
			->setRequired('Zadejte telefonní číslo.')
			->setHtmlAttribute('placeholder', '+420 777 123 456')
			->setOption('description', 'Slouží pro komunikaci s řidičem (nebude veřejně zobrazen).');

		$form->addText('departure_city', 'Odkud jste / Místo nastoupení:')
			->setRequired('Zadejte místo odkud jedete.')
			->setHtmlAttribute('placeholder', 'např. Kuřim');

		$form->addText('pickup_note', 'Poznámka k vyzvednutí (volitelná):')
			->setRequired(false)
			->setHtmlAttribute('placeholder', 'Můžu být na hlavním nádraží, mám velký kufr...');

		$form->addSubmit('submit', 'Přihlásit se jako spolujezdec');

		$form->onSuccess[] = [$this, 'joinRideFormSucceeded'];

		return $form;
	}


	public function joinRideFormSucceeded(Form $form, \stdClass $values): void
	{
		$rideId = (int) $this->getParameter('id');
		$ride = $this->rideRepository->getById($rideId);

		if (!$ride || !$ride->is_active) {
			$this->error('Jízda nenalezena.');
		}

		$event = $ride->ref('event', 'event_id');
		if (!$event || !$event->is_active) {
			$this->error('Akce byla deaktivována.', IResponse::S400_BadRequest);
		}

		if ($ride->departure_time < new \DateTime()) {
			$this->error('Tato jízda již proběhla.', IResponse::S400_BadRequest);
		}

		$available = $this->rideRepository->getAvailableSeats($ride);
		if ($available <= 0) {
			$this->flashMessage('Tato jízda je již plná.', 'error');
			$this->redirect('detail', $rideId);
		}

		$editToken = TokenGenerator::generate();

		$passenger = $this->passengerRepository->create([
			'ride_id' => $rideId,
			'name' => $values->name,
			'email' => $values->email,
			'phone' => $values->phone,
			'departure_city' => $values->departure_city,
			'pickup_note' => $values->pickup_note ?: null,
			'edit_token' => $editToken,
		]);

		// Uložit token do cookie
		$this->saveToken('passenger', $passenger->id, $editToken);

		// Odeslat potvrzení spolujezdci
		try {
			$this->emailService->sendPassengerJoinConfirmation($ride, $passenger);
		} catch (\Exception $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		// Notifikovat řidiče
		$this->emailService->notifyDriverNewPassenger($ride, $passenger);

		$this->flashMessage('Byli jste přihlášeni k jízdě! Řidič byl informován.', 'success');
		$this->redirect('detail', $rideId);
	}


	// ── Editace jízdy ──

	public function actionEdit(int $id, ?string $token = null): void
	{
		$ride = $this->rideRepository->getById($id);
		if (!$ride) {
			$this->error('Jízda nenalezena.');
		}

		$this->checkEventActive($ride);

		if ($token !== null) {
			if (hash_equals($ride->edit_token, $token)) {
				$this->saveToken('ride', $id, $token);
				$this->redirect('this', ['id' => $id, 'token' => null]);
			} else {
				$this->error('Neplatný editační token.', IResponse::S403_Forbidden);
			}
		}

		$tokens = $this->getMyTokens('ride');
		$isOwner = isset($tokens[$id]) && hash_equals($ride->edit_token, $tokens[$id]);
		$isAdmin = $this->getUser()->isLoggedIn();

		if (!$isOwner && !$isAdmin) {
			$this->error('Nemáte oprávnění upravit tuto jízdu.', IResponse::S403_Forbidden);
		}

		$this['editRideForm']->setDefaults([
			'driver_name' => $ride->driver_name,
			'driver_email' => $ride->driver_email,
			'departure_city' => $ride->departure_city,
			'departure_place' => $ride->departure_place,
			'route_via' => $ride->route_via,
			'departure_time' => $ride->departure_time->format('Y-m-d\TH:i'),
			'total_seats' => $ride->total_seats,
			'note' => $ride->note,
			'distance_km' => $ride->distance_km,
		]);

		$this->template->ride = $ride;
		$this->template->event = $ride->ref('event', 'event_id');
	}


	protected function createComponentEditRideForm(): Form
	{
		$form = new Form;

		$form->addText('driver_name', 'Vaše jméno:')
			->setRequired('Zadejte své jméno.');

		$form->addEmail('driver_email', 'E-mail:')
			->setRequired('Zadejte svůj e-mail.');

		$form->addText('departure_city', 'Odkud jedete:')
			->setRequired('Zadejte místo odjezdu.');

		$form->addText('departure_place', 'Přesné místo odjezdu (volitelné):')
			->setRequired(false);

		$form->addText('route_via', 'Přes (místa na trase):')
			->setRequired(false)
			->setHtmlAttribute('placeholder', 'např. Kuřim, Tišnov, Olší')
			->setOption('description', 'Místa, kterými projíždíte – oddělte čárkou.');

		$form->addText('departure_time', 'Datum a čas odjezdu:')
			->setRequired('Zadejte čas odjezdu.')
			->setHtmlType('datetime-local');

		$form->addInteger('total_seats', 'Počet volných míst:')
			->setRequired('Zadejte počet míst.')
			->addRule($form::Range, 'Počet míst musí být 1–8.', [1, 8]);

		$form->addTextArea('note', 'Poznámka (volitelná):')
			->setHtmlAttribute('rows', 3);

		$form->addInteger('distance_km', 'Přibližná vzdálenost (km):')
			->setRequired(false)
			->addRule($form::Range, 'Vzdálenost musí být 1–1000 km.', [1, 1000])
			->setHtmlAttribute('placeholder', 'např. 180')
			->setOption('description', 'Jednosměrná vzdálenost – slouží pro výpočet ušetřených emisí CO₂.');

		$form->addSubmit('submit', 'Uložit změny');

		$form->onSuccess[] = [$this, 'editRideFormSucceeded'];

		return $form;
	}


	public function editRideFormSucceeded(Form $form, \stdClass $values): void
	{
		$rideId = (int) $this->getParameter('id');
		$ride = $this->rideRepository->getById($rideId);
		if (!$ride) {
			$this->error('Jízda nenalezena.');
		}

		$event = $ride->ref('event', 'event_id');
		if (!$event || !$event->is_active) {
			$this->error('Akce byla deaktivována.', IResponse::S400_BadRequest);
		}

		$this->rideRepository->update($rideId, [
			'driver_name' => $values->driver_name,
			'driver_phone' => null,
			'driver_email' => $values->driver_email,
			'departure_city' => $values->departure_city,
			'departure_place' => $values->departure_place ?: null,
			'route_via' => $values->route_via ?: null,
			'departure_time' => $values->departure_time,
			'total_seats' => $values->total_seats,
			'note' => $values->note ?: null,
			'distance_km' => $values->distance_km ?: null,
		]);

		$this->flashMessage('Jízda byla aktualizována.', 'success');
		$this->redirect('detail', $rideId);
	}


	// ── Smazání jízdy ──

	public function handleDeleteRide(int $id): void
	{
		$ride = $this->rideRepository->getById($id);
		if (!$ride) {
			$this->error('Jízda nenalezena.');
		}

		$this->checkEventActive($ride);

		$tokens = $this->getMyTokens('ride');
		$isOwner = isset($tokens[$id]) && hash_equals($ride->edit_token, $tokens[$id]);
		$isAdmin = $this->getUser()->isLoggedIn();

		if (!$isOwner && !$isAdmin) {
			$this->error('Nemáte oprávnění smazat tuto jízdu.', IResponse::S403_Forbidden);
		}

		$eventId = $ride->event_id;
		$event = $ride->ref('event', 'event_id');
		$this->rideRepository->delete($id);
		$this->flashMessage('Jízda byla smazána.', 'success');
		$this->redirect('Event:detail', [
			'id' => $eventId,
			'slug' => \Nette\Utils\Strings::webalize($event->title),
		]);
	}


	// ── Zrušení přihlášení spolujezdce ──

	public function handleCancelPassenger(int $passengerId): void
	{
		$passenger = $this->passengerRepository->getById($passengerId);
		if (!$passenger) {
			$this->error('Spolujezdec nenalezen.');
		}

		$ride = $this->rideRepository->getById($passenger->ride_id);
		if ($ride) {
			$this->checkEventActive($ride);
		}

		$tokens = $this->getMyTokens('passenger');
		$isOwner = isset($tokens[$passengerId]) && hash_equals($passenger->edit_token, $tokens[$passengerId]);
		$isAdmin = $this->getUser()->isLoggedIn();

		if (!$isOwner && !$isAdmin) {
			$this->error('Nemáte oprávnění zrušit toto přihlášení.', IResponse::S403_Forbidden);
		}

		$rideId = $passenger->ride_id;
		$this->passengerRepository->delete($passengerId);
		$this->flashMessage('Přihlášení bylo zrušeno.', 'success');
		$this->redirect('detail', $rideId);
	}


	// ── Zrušení přihlášení spolujezdce přes odkaz v e-mailu ──

	public function actionCancelPassenger(int $id, string $token): void
	{
		$passenger = $this->passengerRepository->getById($id);
		if (!$passenger) {
			$this->flashMessage('Toto přihlášení ke spolujízdě již bylo zrušeno nebo neexistuje.', 'info');
			$this->redirect('Home:default');
		}

		if (!hash_equals($passenger->edit_token, $token)) {
			$this->error('Neplatný odkaz pro odhlášení.', IResponse::S403_Forbidden);
		}

		$ride = $this->rideRepository->getById($passenger->ride_id);
		if ($ride) {
			$event = $ride->ref('event', 'event_id');
			if (!$event || !$event->is_active) {
				$this->flashMessage('Nelze zrušit přihlášení, protože akce byla deaktivována.', 'error');
				$this->redirect('Home:default');
			}
		}

		$rideId = $passenger->ride_id;
		$this->passengerRepository->delete($id);

		$this->flashMessage('Vaše přihlášení ke spolujízdě bylo úspěšně zrušeno.', 'success');
		$this->redirect('detail', $rideId);
	}


	// ── Cookie token management ──

	private function saveToken(string $type, int $id, string $token): void
	{
		$cookieName = "spolujizda_{$type}_tokens";
		$existing = $this->getHttpRequest()->getCookie($cookieName);
		$tokens = $existing ? json_decode($existing, true) : [];
		$tokens[$id] = $token;

		$this->getHttpResponse()->setCookie(
			$cookieName,
			json_encode($tokens),
			'365 days',
		);
	}


	/**
	 * @return array<int, string>
	 */
	private function getMyTokens(string $type): array
	{
		$cookieName = "spolujizda_{$type}_tokens";
		$cookie = $this->getHttpRequest()->getCookie($cookieName);
		if (!$cookie) {
			return [];
		}
		$tokens = json_decode($cookie, true);
		return is_array($tokens) ? $tokens : [];
	}


	private function checkEventActive(\Nette\Database\Table\ActiveRow $ride): void
	{
		$event = $ride->ref('event', 'event_id');
		if (!$event || !$event->is_active) {
			$this->flashMessage('Akce, ke které tato jízda patří, byla deaktivována.', 'info');
			$this->redirect('Event:detail', $ride->event_id);
		}
	}
}
