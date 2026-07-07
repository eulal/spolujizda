<?php declare(strict_types=1);

namespace App\Presentation\Event;

use App\Core\TokenGenerator;
use App\Model\EmailService;
use App\Model\EventRepository;
use App\Model\RideRepository;
use App\Model\RideRequestRepository;
use App\Presentation\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Http\IResponse;


final class EventPresenter extends BasePresenter
{
	private ?\Nette\Database\Table\ActiveRow $event = null;


	public function __construct(
		private EventRepository $eventRepository,
		private RideRepository $rideRepository,
		private RideRequestRepository $rideRequestRepository,
		private EmailService $emailService,
	) {
	}





	/**
	 * Načte akci a ověří, že existuje a je aktivní.
	 */
	private function loadEvent(int $id): \Nette\Database\Table\ActiveRow
	{
		$event = $this->eventRepository->getById($id);
		if (!$event) {
			$this->error('Akce nenalezena.', IResponse::S404_NotFound);
		}
		if (!$event->is_active) {
			$this->flashMessage('Tato akce byla deaktivována administrátorem.', 'info');
			$this->redirect('detail', $id);
		}
		return $event;
	}


	// ── Detail akce s přehledem jízd ──

	public function renderDetail(int $id, ?string $slug = null, string $smer = 'there'): void
	{
		$event = $this->eventRepository->getById($id);
		if (!$event) {
			$this->error('Akce nenalezena.', IResponse::S404_NotFound);
		}

		if (!$event->is_active) {
			$this->setView('deactivated');
			$this->template->event = $event;
			return;
		}

		// SEO slug redirection
		$correctSlug = \Nette\Utils\Strings::webalize($event->title);
		if ($slug !== $correctSlug) {
			$this->redirectPermanent('this', ['id' => $id, 'slug' => $correctSlug, 'smer' => $smer]);
		}

		$this->template->event = $event;

		// Směr – výchozí "tam"
		$this->template->direction = $smer;

		$this->template->rides = $this->rideRepository->findByEvent($id, $smer)->fetchAll();
		$this->template->requests = $this->rideRequestRepository->findByEvent($id, $smer)->fetchAll();
		$this->template->rideRepository = $this->rideRepository;

		// Edit tokeny z cookies pro zvýraznění vlastních záznamů
		$this->template->myRideTokens = $this->getMyTokens('ride');
		$this->template->myRequestTokens = $this->getMyTokens('request');

		$this->template->isPastEvent = $event->date_to < new \DateTime('today');
		$this->template->now = new \DateTime();
	}


	// ── Nabídka jízdy ──

	public function actionOffer(int $id, string $smer = 'there'): void
	{
		$this->event = $this->loadEvent($id);
		if ($this->event->date_to < new \DateTime('today')) {
			$this->flashMessage('Tato akce již proběhla, nelze k ní nabízet jízdy.', 'error');
			$this->redirect('detail', $id);
		}

		$this->template->event = $this->event;
		$this->template->direction = $smer;

		// Přednastavit směr ve formuláři
		$this['offerRideForm']->setDefaults(['direction' => $smer]);
	}


	protected function createComponentOfferRideForm(): Form
	{
		$form = new Form;
		$direction = $this->getParameter('smer') ?? 'there';
		$isBack = $direction === 'back';

		$form->addText('driver_name', 'Vaše jméno:')
			->setRequired('Zadejte své jméno.')
			->setHtmlAttribute('placeholder', 'Jan Novák');

		$form->addEmail('driver_email', 'E-mail:')
			->setRequired('Zadejte svůj e-mail.')
			->setHtmlAttribute('placeholder', 'jan@email.cz')
			->setOption('description', 'Slouží pro zaslání notifikací o spolujezdcích (nebude veřejně zobrazen).');

		$form->addText('departure_city', $isBack ? 'Kam jedete:' : 'Odkud jedete:')
			->setRequired($isBack ? 'Zadejte cíl cesty.' : 'Zadejte místo odjezdu.')
			->setHtmlAttribute('placeholder', $isBack ? 'Brno' : 'Praha');

		$form->addText('departure_place', $isBack ? 'Přesné místo příjezdu (volitelné):' : 'Přesné místo odjezdu (volitelné):')
			->setRequired(false)
			->setHtmlAttribute('placeholder', $isBack ? 'např. Hlavní nádraží, parkoviště...' : 'např. Hlavní nádraží, parkoviště u metra...');

		$form->addText('route_via', 'Přes (místa na trase):')
			->setRequired(false)
			->setHtmlAttribute('placeholder', 'např. Kuřim, Tišnov, Olší')
			->setOption('description', 'Místa, kterými projíždíte – oddělte čárkou.');

		$form->addText('departure_time', $isBack ? 'Datum a čas odjezdu z akce:' : 'Datum a čas odjezdu:')
			->setRequired('Zadejte čas odjezdu.')
			->setHtmlType('datetime-local');

		$form->addInteger('total_seats', 'Počet volných míst:')
			->setRequired('Zadejte počet míst.')
			->setDefaultValue(3)
			->addRule($form::Range, 'Počet míst musí být 1–8.', [1, 8]);

		$form->addHidden('direction', $direction);

		$form->addTextArea('note', 'Poznámka (volitelná):')
			->setHtmlAttribute('rows', 3)
			->setHtmlAttribute('placeholder', 'Typ auta, prostor v kufru, zvířata, kouření...');

		$form->addInteger('distance_km', 'Přibližná vzdálenost (km):')
			->setRequired(false)
			->addRule($form::Range, 'Vzdálenost musí být 1–1000 km.', [1, 1000])
			->setHtmlAttribute('placeholder', 'např. 180')
			->setOption('description', 'Jednosměrná vzdálenost – slouží pro výpočet ušetřených emisí CO₂.');

		$form->addSubmit('submit', 'Nabídnout jízdu');

		$form->onSuccess[] = [$this, 'offerRideFormSucceeded'];

		return $form;
	}


	public function offerRideFormSucceeded(Form $form, \stdClass $values): void
	{
		$eventId = (int) $this->getParameter('id');
		$event = $this->loadEvent($eventId);

		if ($event->date_to < new \DateTime('today')) {
			$this->error('Tato akce již proběhla.', IResponse::S400_BadRequest);
		}

		$editToken = TokenGenerator::generate();

		$ride = $this->rideRepository->create([
			'event_id' => $eventId,
			'driver_name' => $values->driver_name,
			'driver_phone' => null,
			'driver_email' => $values->driver_email,
			'departure_city' => $values->departure_city,
			'departure_place' => $values->departure_place ?: null,
			'route_via' => $values->route_via ?: null,
			'departure_time' => $values->departure_time,
			'total_seats' => $values->total_seats,
			'note' => $values->note ?: null,
			'direction' => $values->direction,
			'distance_km' => $values->distance_km ?: null,
			'edit_token' => $editToken,
		]);

		// Uložit token do cookie pro pozdější editaci
		$this->saveToken('ride', $ride->id, $editToken);

		// Odeslat potvrzení řidiči
		try {
			$this->emailService->sendRideOfferConfirmation($ride);
		} catch (\Exception $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		// Notifikovat poptávající s e-mailem
		$requesters = $this->rideRequestRepository
			->findNotifiableByEvent($eventId, $values->direction)
			->fetchAll();

		if ($requesters) {
			$this->emailService->notifyRequestersNewRide($ride, $requesters);
		}

		$this->flashMessage('Vaše nabídka jízdy byla vytvořena!', 'success');
		$this->redirect('detail', $eventId, $values->direction);
	}


	// ── Poptávka svezení ──

	public function actionRequest(int $id, string $smer = 'there'): void
	{
		$this->event = $this->loadEvent($id);
		if ($this->event->date_to < new \DateTime('today')) {
			$this->flashMessage('Tato akce již proběhla, nelze k ní poptávat jízdy.', 'error');
			$this->redirect('detail', $id);
		}

		$this->template->event = $this->event;
		$this->template->direction = $smer;

		// Přednastavit směr ve formuláři
		$this['requestRideForm']->setDefaults(['direction' => $smer]);
	}


	protected function createComponentRequestRideForm(): Form
	{
		$form = new Form;
		$direction = $this->getParameter('smer') ?? 'there';

		$form->addEmail('email', 'E-mail:')
			->setRequired('Zadejte svůj e-mail.')
			->setHtmlAttribute('placeholder', 'jan@email.cz')
			->setOption('description', 'Budeme vás informovat, když se vytvoří nová nabídka jízdy na tuto akci.');

		$form->addHidden('direction', $direction);

		$form->addSubmit('submit', 'Chci dostávat upozornění');

		$form->onSuccess[] = [$this, 'requestRideFormSucceeded'];

		return $form;
	}


	public function requestRideFormSucceeded(Form $form, \stdClass $values): void
	{
		$eventId = (int) $this->getParameter('id');
		$event = $this->loadEvent($eventId);

		if ($event->date_to < new \DateTime('today')) {
			$this->error('Tato akce již proběhla.', IResponse::S400_BadRequest);
		}

		$editToken = TokenGenerator::generate();

		$request = $this->rideRequestRepository->create([
			'event_id' => $eventId,
			'name' => null,
			'phone' => null,
			'email' => $values->email,
			'departure_city' => null,
			'preferred_time' => null,
			'direction' => $values->direction,
			'note' => null,
			'edit_token' => $editToken,
		]);

		// Uložit token do cookie
		$this->saveToken('request', $request->id, $editToken);

		// Odeslat potvrzení poptávajícímu
		try {
			$this->emailService->sendRideRequestConfirmation($request);
		} catch (\Exception $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		$this->flashMessage('Byli jste přihlášeni k odběru upozornění na nové nabídky jízd.', 'success');
		$this->redirect('detail', $eventId, $values->direction);
	}


	// ── Smazání poptávky ──

	public function handleDeleteRequest(int $requestId): void
	{
		$tokens = $this->getMyTokens('request');
		$request = $this->rideRequestRepository->getById($requestId);

		if (!$request) {
			$this->error('Poptávka nenalezena.');
		}

		$isOwner = isset($tokens[$requestId]) && hash_equals($request->edit_token, $tokens[$requestId]);
		$isAdmin = $this->getUser()->isLoggedIn();

		if (!$isOwner && !$isAdmin) {
			$this->error('Nemáte oprávnění smazat tuto poptávku.', IResponse::S403_Forbidden);
		}

		$eventId = $request->event_id;
		$this->rideRequestRepository->delete($requestId);
		$this->flashMessage('Poptávka byla smazána.', 'success');
		$this->redirect('detail', $eventId);
	}


	// ── Zrušení poptávky přes odkaz v e-mailu ──

	public function actionCancelRequest(int $id, string $token): void
	{
		$request = $this->rideRequestRepository->getById($id);
		if (!$request) {
			$this->flashMessage('Tato poptávka již byla smazána nebo neexistuje.', 'info');
			$this->redirect('Home:default');
		}

		if (!hash_equals($request->edit_token, $token)) {
			$this->error('Neplatný odkaz pro odhlášení.', IResponse::S403_Forbidden);
		}

		$eventId = $request->event_id;
		$event = $request->ref('event', 'event_id');
		$this->rideRequestRepository->delete($id);

		$this->flashMessage('Odběr upozornění na nové jízdy byl úspěšně zrušen.', 'success');
		$this->redirect('detail', [
			'id' => $eventId,
			'slug' => \Nette\Utils\Strings::webalize($event->title),
		]);
	}


	// ── Cookie token management ──

	/**
	 * Uloží edit token do cookie.
	 */
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
	 * Načte edit tokeny z cookie.
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
}
