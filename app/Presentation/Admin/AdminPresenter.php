<?php declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Core\UpdateService;
use App\Model\EventRepository;
use App\Model\RideRepository;
use App\Model\Co2Service;
use App\Model\EmailService;
use App\Model\RideRequestRepository;
use App\Presentation\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;


final class AdminPresenter extends BasePresenter
{
	#[Persistent]
	public string $tab = 'application';

	#[Persistent]
	public string $eventsTab = 'active';

	#[Persistent]
	public string $detailTab = 'there';

	public function __construct(
		private EventRepository $eventRepository,
		private RideRepository $rideRepository,
		private Co2Service $co2Service,
		private EmailService $emailService,
		private UpdateService $updateService,
		private \App\Core\SettingsService $settingsService,
		private RideRequestRepository $rideRequestRepository,
	) {
	}



	public function startup(): void
	{
		parent::startup();

		// Login a logout akce jsou přístupné vždy
		if (!in_array($this->getAction(), ['login', 'logout'], true)) {
			if (!$this->getUser()->isLoggedIn()) {
				$this->redirect('login');
			}
		}
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		if ($this->getUser()->isLoggedIn()) {
			try {
				$updateInfo = $this->updateService->getUpdateInfo();
				$this->template->updateAvailable = $updateInfo['available'];
				$this->template->latestVersion = $updateInfo['latest'];
				$this->template->currentVersion = $updateInfo['current'];
				$this->template->pendingMigrationsCount = count($this->updateService->getPendingMigrations());
			} catch (\Throwable) {
				$this->template->updateAvailable = false;
				$this->template->latestVersion = '';
				$this->template->currentVersion = '0.0.0';
				$this->template->pendingMigrationsCount = 0;
			}
		}
		$this->template->now = new \DateTimeImmutable('today');
	}





	// ── Nástěnka (Dashboard) ──

	public function renderDefault(): void
	{
		$events = $this->eventRepository->findAll()->fetchAll();
		$upcomingEventsPreview = [];
		$today = new \DateTimeImmutable('today');
		$plannedCount = 0;

		foreach ($events as $event) {
			$dateTo = $event->date_to->setTime(0, 0, 0);
			if ($dateTo >= $today) {
				$plannedCount++;
				if (count($upcomingEventsPreview) < 5) {
					$upcomingEventsPreview[] = $event;
				}
			}
		}

		$this->template->upcomingEventsPreview = $upcomingEventsPreview;
		$this->template->eventRepository = $this->eventRepository;
		$this->template->co2Service = $this->co2Service;

		// Statistiky pro dashboard
		$this->template->statsTotalEvents = count($events);
		$this->template->statsPlannedEvents = $plannedCount;
		$this->template->statsTotalPassengers = $this->co2Service->getTotalPassengerCount();
		$this->template->statsTotalSavedCo2 = $this->co2Service->getTotalSavedCo2();
	}


	// ── Správa akcí ──

	public function renderEvents(): void
	{
		$events = $this->eventRepository->findAll()->fetchAll();
		$activeEvents = []; // Plánované akce
		$archivedEvents = []; // Proběhlé akce
		$today = new \DateTimeImmutable('today');

		foreach ($events as $event) {
			$dateTo = $event->date_to->setTime(0, 0, 0);
			if ($dateTo >= $today) {
				$activeEvents[] = $event;
			} else {
				$archivedEvents[] = $event;
			}
		}

		$this->template->activeEvents = $activeEvents;
		$this->template->archivedEvents = $archivedEvents;
		$this->template->eventsTab = $this->eventsTab;
		$this->template->eventRepository = $this->eventRepository;
		$this->template->co2Service = $this->co2Service;
		$this->template->now = new \DateTimeImmutable();
	}


	protected function createComponentTestMailForm(): Form
	{
		$form = new Form;

		$form->addEmail('recipient', 'Příjemce testovacího e-mailu:')
			->setRequired('Zadejte prosím e-mailovou adresu příjemce.')
			->setHtmlAttribute('placeholder', 'např. jan.novak@example.com');

		$form->addSubmit('submit', 'Odeslat test');

		$form->onSuccess[] = [$this, 'testMailFormSucceeded'];

		return $form;
	}


	public function testMailFormSucceeded(Form $form, \stdClass $values): void
	{
		try {
			$this->emailService->sendTestEmail($values->recipient);
			$this->flashMessage('Testovací e-mail byl úspěšně odeslán na adresu: ' . $values->recipient, 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Chyba při odesílání e-mailu: ' . $e->getMessage(), 'danger');
		}
		$this->redirect('this', ['tab' => 'emails']);
	}



	// ── Přihlášení ──

	public function actionLogin(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('default');
		}
	}


	protected function createComponentLoginForm(): Form
	{
		$form = new Form;

		$form->addPassword('password', 'Heslo:')
			->setRequired('Zadejte heslo.')
			->setHtmlAttribute('autofocus');

		$form->addSubmit('submit', 'Přihlásit se');

		$form->onSuccess[] = [$this, 'loginFormSucceeded'];

		return $form;
	}


	public function loginFormSucceeded(Form $form, \stdClass $values): void
	{
		try {
			$this->getUser()->login('admin', $values->password);
			$this->getUser()->setExpiration('2 hours');
			$this->flashMessage('Přihlášení proběhlo úspěšně.', 'success');
			$this->redirect('default');
		} catch (\Nette\Security\AuthenticationException $e) {
			$form->addError('Nesprávné heslo.');
		}
	}


	// ── Odhlášení ──

	public function actionLogout(): void
	{
		$this->getUser()->logout(true);
		$this->flashMessage('Byli jste odhlášeni.', 'success');
		$this->redirect('login');
	}


	// ── Vytvoření akce ──

	protected function createComponentCreateEventForm(): Form
	{
		$form = new Form;

		$form->addText('title', 'Název akce:')
			->setRequired('Zadejte název akce.')
			->setHtmlAttribute('placeholder', 'např. Letní seminář jógy');

		$form->addText('location', 'Místo konání:')
			->setRequired('Zadejte místo konání (cílovou adresu).')
			->setHtmlAttribute('placeholder', 'např. Penzion U Lesa, Horní Planá');

		$form->addText('date_from', 'Začátek:')
			->setRequired('Zadejte datum začátku.')
			->setHtmlType('datetime-local');

		$form->addText('date_to', 'Konec:')
			->setRequired('Zadejte datum konce.')
			->setHtmlType('datetime-local');

		$form->addTextArea('description', 'Popis (volitelný):')
			->setHtmlAttribute('rows', 3)
			->setHtmlAttribute('placeholder', 'Krátký popis akce pro účastníky...');

		$form->addSubmit('submit', 'Vytvořit akci');

		$form->onSuccess[] = [$this, 'createEventFormSucceeded'];

		return $form;
	}


	public function createEventFormSucceeded(Form $form, \stdClass $values): void
	{
		$this->eventRepository->create([
			'title' => $values->title,
			'location' => $values->location,
			'date_from' => $values->date_from,
			'date_to' => $values->date_to,
			'description' => $values->description ?: null,
			'is_active' => 1,
		]);

		$this->flashMessage('Akce byla vytvořena.', 'success');
		$this->redirect('events');
	}


	// ── Detail akce ──

	public function renderDetail(int $id): void
	{
		$event = $this->eventRepository->getById($id);
		if (!$event) {
			$this->error('Akce nenalezena.');
		}

		$this->template->event = $event;
		$this->template->ridesThere = $this->rideRepository->findByEvent($id, 'there')->fetchAll();
		$this->template->ridesBack = $this->rideRepository->findByEvent($id, 'back')->fetchAll();
		$this->template->requests = $this->rideRequestRepository->findAllUnfulfilledByEvent($id)->fetchAll();
		
		$this->template->rideCountThere = count($this->template->ridesThere);
		$this->template->rideCountBack = count($this->template->ridesBack);
		$this->template->requestCount = count($this->template->requests);
		
		$this->template->detailTab = $this->detailTab;
		$this->template->rideRepository = $this->rideRepository;
	}


	// ── Editace akce ──

	public function actionEdit(int $id): void
	{
		$event = $this->eventRepository->getById($id);
		if (!$event) {
			$this->error('Akce nenalezena.');
		}

		$this['editEventForm']->setDefaults([
			'title' => $event->title,
			'location' => $event->location,
			'date_from' => $event->date_from->format('Y-m-d\TH:i'),
			'date_to' => $event->date_to->format('Y-m-d\TH:i'),
			'description' => $event->description,
		]);

		$this->template->event = $event;
	}


	protected function createComponentEditEventForm(): Form
	{
		$form = new Form;

		$form->addText('title', 'Název akce:')
			->setRequired('Zadejte název akce.');

		$form->addText('location', 'Místo konání:')
			->setRequired('Zadejte místo konání.');

		$form->addText('date_from', 'Začátek:')
			->setRequired('Zadejte datum začátku.')
			->setHtmlType('datetime-local');

		$form->addText('date_to', 'Konec:')
			->setRequired('Zadejte datum konce.')
			->setHtmlType('datetime-local');

		$form->addTextArea('description', 'Popis (volitelný):')
			->setHtmlAttribute('rows', 3);

		$form->addSubmit('submit', 'Uložit změny');

		$form->onSuccess[] = [$this, 'editEventFormSucceeded'];

		return $form;
	}


	public function editEventFormSucceeded(Form $form, \stdClass $values): void
	{
		$id = (int) $this->getParameter('id');

		$this->eventRepository->update($id, [
			'title' => $values->title,
			'location' => $values->location,
			'date_from' => $values->date_from,
			'date_to' => $values->date_to,
			'description' => $values->description ?: null,
		]);

		$this->flashMessage('Akce byla aktualizována.', 'success');
		$this->redirect('detail', $id);
	}


	// ── Toggle aktivní ──

	public function handleToggleActive(int $id): void
	{
		$event = $this->eventRepository->getById($id);
		if (!$event) {
			$this->error('Akce nenalezena.');
		}

		if ($event->date_to->format('Y-m-d') < (new \DateTime())->format('Y-m-d')) {
			$this->flashMessage('Již proběhlou akci nelze aktivovat ani deaktivovat.', 'error');
			$this->redirect('this');
		}

		$this->eventRepository->update($id, [
			'is_active' => $event->is_active ? 0 : 1,
		]);

		$status = $event->is_active ? 'deaktivována' : 'aktivována';
		$this->flashMessage("Akce byla {$status}.", 'success');
		$this->redirect('this');
	}


	// ── Smazání akce ──

	public function handleDelete(int $id): void
	{
		$event = $this->eventRepository->getById($id);
		if (!$event) {
			$this->error('Akce nenalezena.');
		}

		$this->eventRepository->delete($id);
		$this->flashMessage('Akce byla smazána.', 'success');
		$this->redirect('events');
	}


	// ── Smazání poptávky ──

	public function handleDeleteRequest(int $requestId): void
	{
		$request = $this->rideRequestRepository->getById($requestId);
		if ($request) {
			$this->rideRequestRepository->delete($requestId);
			$this->flashMessage('Poptávka byla smazána.', 'success');
		}
		$this->redirect('this');
	}


	// ══════════════════════════════════════════════════════════
	// ── Aktualizace systému ──
	// ══════════════════════════════════════════════════════════

	public function renderUpdate(): void
	{
		$this->template->updateInfo = $this->updateService->getUpdateInfo();
		$this->template->requirements = $this->updateService->checkRequirements();
		$this->template->pendingMigrations = $this->updateService->getPendingMigrations();
		$this->template->executedMigrations = $this->updateService->getExecutedMigrationRows();

		// Předat log z session (pokud existuje)
		$session = $this->getSession('update');
		$this->template->updateLog = $session->log ?? null;
		unset($session->log);
	}


	public function handleCheckUpdate(): void
	{
		$info = $this->updateService->getUpdateInfo(forceRefresh: true);

		if ($info['error']) {
			$this->flashMessage('Chyba při kontrole: ' . $info['error'], 'error');
		} elseif ($info['available']) {
			$this->flashMessage('K dispozici je nová verze: ' . $info['latest'], 'success');
		} else {
			$this->flashMessage('Aplikace je aktuální (verze ' . $info['current'] . ').', 'success');
		}

		$this->redirect('update');
	}


	public function handlePerformUpdate(): void
	{
		$result = $this->updateService->performUpdate();

		// Uložit log do session pro zobrazení
		$session = $this->getSession('update');
		$session->log = $result['log'];

		if ($result['success']) {
			$this->flashMessage('Aktualizace proběhla úspěšně!', 'success');
		} else {
			$this->flashMessage('Aktualizace selhala: ' . ($result['error'] ?? 'Neznámá chyba'), 'error');
		}

		$this->redirect('update');
	}


	public function handleRunMigrations(): void
	{
		$result = $this->updateService->runMigrationsOnly();

		$session = $this->getSession('update');
		$session->log = $result['log'];

		if ($result['success']) {
			$this->flashMessage('Migrace proběhly úspěšně.', 'success');
		} else {
			$this->flashMessage('Migrace selhaly: ' . ($result['error'] ?? 'Neznámá chyba'), 'error');
		}

		$this->redirect('update');
	}


	// ══════════════════════════════════════════════════════════
	// ── Nastavení systému ──
	// ══════════════════════════════════════════════════════════

	public function actionSettings(): void
	{
		$settings = $this->settingsService->getMergedSettings();
		$params = $settings['parameters'] ?? [];
		$mail = $settings['mail'] ?? [];

		$this['applicationSettingsForm']->setDefaults([
			'baseUrl' => $params['baseUrl'] ?? '',
			'co2EmissionFactor' => $params['co2EmissionFactor'] ?? 0.150,
			'githubRepo' => $params['githubRepo'] ?? 'owner/repo',
		]);

		// Předat informaci o existenci tokenu do šablony
		$this->template->hasGithubToken = !empty($params['githubToken'] ?? '');

		$this['emailSettingsForm']->setDefaults([
			'mailFromName' => $params['mailFromName'] ?? 'Spolujízda',
			'mailFrom' => $params['mailFrom'] ?? '',
			'smtp' => $mail['smtp'] ?? false,
			'smtpHost' => $mail['host'] ?? '',
			'smtpPort' => $mail['port'] ?? 587,
			'smtpUsername' => $mail['username'] ?? '',
			'smtpSecure' => $mail['secure'] ?? 'tls',
		]);

		$this['gdprSettingsForm']->setDefaults([
			'gdprAuthor' => $params['gdprAuthor'] ?? '',
			'gdprEmail' => $params['gdprEmail'] ?? '',
			'gdprText' => $params['gdprText'] ?? '',
		]);

		$this['donationSettingsForm']->setDefaults([
			'donationAccount' => $params['donationAccount'] ?? '',
			'donationMessage' => $params['donationMessage'] ?? 'Dar Spolujízda',
			'donationUrl' => $params['donationUrl'] ?? '',
			'donationText' => $params['donationText'] ?? '',
		]);
	}


	public function renderSettings(): void
	{
		$this->template->tab = $this->tab;
		$settings = $this->settingsService->getMergedSettings();
		$params = $settings['parameters'] ?? [];
		$this->template->mailFromName = $params['mailFromName'] ?? 'Spolujízda';
		$this->template->mailFrom = $params['mailFrom'] ?? 'spolujizda@example.com';
	}


	protected function createComponentApplicationSettingsForm(): Form
	{
		$form = new Form;

		$form->addText('baseUrl', 'Base URL aplikace:')
			->setRequired('Zadejte URL aplikace.')
			->setHtmlAttribute('placeholder', 'např. https://spolujizda.example.com');

		$form->addText('co2EmissionFactor', 'CO₂ emisní faktor (kg/km):')
			->setRequired('Zadejte emisní faktor.')
			->addRule(Form::Float, 'Musí být platné číslo.');

		$form->addText('githubRepo', 'GitHub repozitář:')
			->setRequired('Zadejte GitHub repozitář pro aktualizace.');

		$form->addPassword('githubToken', 'GitHub token (PAT):')
			->setNullable()
			->setHtmlAttribute('placeholder', 'Ponechte prázdné pro zachování stávajícího tokenu');

		$form->addCheckbox('removeGithubToken', 'Odebrat token');

		$form->addSubmit('submit', 'Uložit nastavení aplikace');

		$form->onSuccess[] = [$this, 'applicationSettingsFormSucceeded'];

		return $form;
	}


	public function applicationSettingsFormSucceeded(Form $form, \stdClass $values): void
	{
		try {
			$this->settingsService->saveSettings((array) $values);
			$this->flashMessage('Nastavení aplikace bylo úspěšně uloženo.', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Nepodařilo se uložit nastavení: ' . $e->getMessage(), 'danger');
		}
		$this->redirect('this', ['tab' => 'application']);
	}


	protected function createComponentEmailSettingsForm(): Form
	{
		$form = new Form;

		$form->addText('mailFromName', 'Jméno odesílatele e-mailů:')
			->setRequired('Zadejte jméno odesílatele.');

		$form->addEmail('mailFrom', 'E-mail odesílatele:')
			->setRequired('Zadejte e-mail odesílatele.');

		// SMTP nastavení
		$form->addCheckbox('smtp', 'Použít vlastní SMTP server pro odesílání e-mailů');

		$form->addText('smtpHost', 'SMTP server (host):')
			->setNullable();
		
		$form->addInteger('smtpPort', 'SMTP port:')
			->setNullable()
			->addConditionOn($form['smtp'], Form::Equal, true)
				->setRequired('Zadejte port SMTP.');

		$form->addText('smtpUsername', 'SMTP uživatelské jméno:')
			->setNullable();

		$form->addPassword('smtpPassword', 'SMTP heslo:')
			->setNullable()
			->setHtmlAttribute('placeholder', 'Ponechte prázdné pro zachování stávajícího hesla');

		$form->addSelect('smtpSecure', 'Zabezpečení SMTP:', [
			'' => 'Žádné',
			'ssl' => 'SSL (Implicitní)',
			'tls' => 'TLS (Explicitní)',
		]);

		$form->addSubmit('submit', 'Uložit nastavení e-mailů');

		$form->onSuccess[] = [$this, 'emailSettingsFormSucceeded'];

		return $form;
	}


	public function emailSettingsFormSucceeded(Form $form, \stdClass $values): void
	{
		try {
			$this->settingsService->saveSettings((array) $values);
			$this->flashMessage('Nastavení e-mailů bylo úspěšně uloženo.', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Nepodařilo se uložit nastavení: ' . $e->getMessage(), 'danger');
		}
		$this->redirect('this', ['tab' => 'emails']);
	}


	protected function createComponentChangePasswordForm(): Form
	{
		$form = new Form;

		$form->addPassword('currentPassword', 'Aktuální heslo:')
			->setRequired('Zadejte prosím aktuální heslo pro ověření identity.');

		$form->addPassword('newPassword', 'Nové heslo:')
			->setRequired('Zadejte prosím nové heslo.')
			->addRule(Form::MinLength, 'Nové heslo musí mít alespoň %d znaků.', 6);

		$form->addPassword('newPasswordConfirm', 'Potvrzení nového hesla:')
			->setRequired('Zadejte prosím potvrzení nového hesla.')
			->addRule(Form::Equal, 'Hesla se neshodují.', $form['newPassword']);

		$form->addSubmit('submit', 'Změnit heslo');

		$form->onSuccess[] = [$this, 'changePasswordFormSucceeded'];

		return $form;
	}


	public function changePasswordFormSucceeded(Form $form, \stdClass $values): void
	{
		$settings = $this->settingsService->getSettings();
		$currentHash = $settings['parameters']['adminPasswordHash'] ?? '';

		if (!password_verify($values->currentPassword, $currentHash)) {
			$form['currentPassword']->addError('Zadané aktuální heslo není správné.');
			return;
		}

		try {
			$this->settingsService->saveSettings([
				'newPassword' => $values->newPassword
			]);
			$this->flashMessage('Heslo bylo úspěšně změněno.', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Nepodařilo se uložit nové heslo: ' . $e->getMessage(), 'danger');
			return;
		}
		$this->redirect('this', ['tab' => 'password']);
	}


	protected function createComponentGdprSettingsForm(): Form
	{
		$form = new Form;

		$form->addText('gdprAuthor', 'Správce osobních údajů (organizace):')
			->setNullable()
			->setHtmlAttribute('placeholder', 'např. Spolujízda, z.s.');

		$form->addText('gdprEmail', 'E-mail pro dotazy k GDPR:')
			->setNullable()
			->setHtmlAttribute('placeholder', 'např. spolujizda@example.com');

		$form->addTextArea('gdprText', 'Vlastní text ochrany osobních údajů (volitelně):')
			->setNullable()
			->setHtmlAttribute('rows', 10);

		$form->addSubmit('submit', 'Uložit nastavení GDPR');

		$form->onSuccess[] = [$this, 'gdprSettingsFormSucceeded'];

		return $form;
	}


	public function gdprSettingsFormSucceeded(Form $form, \stdClass $values): void
	{
		try {
			$this->settingsService->saveSettings((array) $values);
			$this->flashMessage('Nastavení GDPR bylo úspěšně uloženo.', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Nepodařilo se uložit nastavení: ' . $e->getMessage(), 'danger');
		}
		$this->redirect('this', ['tab' => 'gdpr']);
	}


	protected function createComponentDonationSettingsForm(): Form
	{
		$form = new Form;

		$form->addText('donationAccount', 'Bankovní účet pro dary:')
			->setNullable()
			->setHtmlAttribute('placeholder', 'např. 2900000000/2010');

		$form->addText('donationMessage', 'Zpráva pro příjemce:')
			->setNullable()
			->setHtmlAttribute('placeholder', 'např. Dar Spolujízda');

		$form->addText('donationUrl', 'Odkaz na PayPal / Ko-fi / atd.:')
			->setNullable()
			->setHtmlAttribute('placeholder', 'např. https://paypal.me/vasejmeno');

		$form->addTextArea('donationText', 'Vlastní doprovodný text (volitelně):')
			->setNullable()
			->setHtmlAttribute('rows', 10);

		$form->addSubmit('submit', 'Uložit nastavení podpory');

		$form->onSuccess[] = [$this, 'donationSettingsFormSucceeded'];

		return $form;
	}


	public function donationSettingsFormSucceeded(Form $form, \stdClass $values): void
	{
		try {
			$this->settingsService->saveSettings((array) $values);
			$this->flashMessage('Nastavení podpory a darování bylo úspěšně uloženo.', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Nepodařilo se uložit nastavení: ' . $e->getMessage(), 'danger');
		}
		$this->redirect('this', ['tab' => 'donation']);
	}
}
