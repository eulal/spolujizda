<?php declare(strict_types=1);

namespace App\Presentation\Home;

use App\Model\Co2Service;
use App\Model\EventRepository;
use App\Presentation\BasePresenter;


final class HomePresenter extends BasePresenter
{
	public function __construct(
		private EventRepository $eventRepository,
		private Co2Service $co2Service,
		private \App\Core\SettingsService $settingsService,
	) {
	}


	public function renderDefault(): void
	{
		$this->template->upcomingEvents = $this->eventRepository->findUpcoming()->fetchAll();
		$this->template->pastEvents = $this->eventRepository->findPast()->limit(10)->fetchAll();
		$this->template->eventRepository = $this->eventRepository;

		// CO2 statistiky
		$this->template->totalSavedCo2 = $this->co2Service->getTotalSavedCo2();
		$this->template->totalPassengers = $this->co2Service->getTotalPassengerCount();
		$this->template->sharedRideCount = $this->co2Service->getSharedRideCount();
		$this->template->co2Service = $this->co2Service;
	}


	/**
	 * Stránka s popisem metodologie výpočtu CO2.
	 */
	public function renderCo2Metodologie(): void
	{
		$this->template->emissionFactor = $this->co2Service->getEmissionFactor();
		$this->template->defaultDistanceKm = $this->co2Service->getDefaultDistanceKm();
		$this->template->totalSavedCo2 = $this->co2Service->getTotalSavedCo2();
		$this->template->totalPassengers = $this->co2Service->getTotalPassengerCount();
		$this->template->sharedRideCount = $this->co2Service->getSharedRideCount();
	}


	/**
	 * Stránka s informacemi o ochraně osobních údajů (GDPR).
	 */
	public function renderGdpr(): void
	{
		$settings = $this->settingsService->getMergedSettings();
		$params = $settings['parameters'] ?? [];

		$this->template->gdprAuthor = !empty($params['gdprAuthor']) ? $params['gdprAuthor'] : ($params['mailFromName'] ?? 'Spolujízda');
		$this->template->gdprEmail = !empty($params['gdprEmail']) ? $params['gdprEmail'] : ($params['mailFrom'] ?? 'spolujizda@example.com');
		$this->template->gdprText = $params['gdprText'] ?? '';
	}


	// Detaily pro podporu autora projektu (fixní)
	private const AUTHOR_DONATION_ACCOUNT = '670100-2202698530/6210';
	private const AUTHOR_DONATION_MESSAGE = 'Dar Spolujízda';
	private const AUTHOR_EMAIL = 'eulal@riseup.net';


	/**
	 * Stránka "Líbí se vám Spolujízda?" (O projektu a podpoře).
	 */
	public function renderAbout(): void
	{
		// 1. Fixní podpora autora
		$this->template->authorAccount = self::AUTHOR_DONATION_ACCOUNT;
		$this->template->authorMessage = self::AUTHOR_DONATION_MESSAGE;
		$this->template->authorEmail = self::AUTHOR_EMAIL;
		
		$authorPrefix = '';
		$authorNumber = '';
		$authorBank = '';
		if (preg_match('~^(?:(\d+)-)?(\d+)/(\d{4})$~', self::AUTHOR_DONATION_ACCOUNT, $matches)) {
			$authorPrefix = $matches[1] ?: '';
			$authorNumber = $matches[2];
			$authorBank = $matches[3];
		}
		$this->template->authorPrefix = $authorPrefix;
		$this->template->authorNumber = $authorNumber;
		$this->template->authorBank = $authorBank;

		// 2. Volitelná podpora provozovatele instance
		$settings = $this->settingsService->getMergedSettings();
		$params = $settings['parameters'] ?? [];

		$this->template->githubRepo = !empty($params['githubRepo']) ? $params['githubRepo'] : 'eulal/spolujizda';
		$donationAccount = $params['donationAccount'] ?? '';
		$this->template->donationAccount = $donationAccount;
		$this->template->donationMessage = $params['donationMessage'] ?? 'Podpora provozu Spolujízdy';
		$this->template->donationUrl = $params['donationUrl'] ?? '';
		$this->template->donationText = $params['donationText'] ?? '';
		$this->template->gdprAuthor = !empty($params['gdprAuthor']) ? $params['gdprAuthor'] : ($params['mailFromName'] ?? '');

		$payliboPrefix = '';
		$payliboNumber = '';
		$payliboBank = '';
		if (preg_match('~^(?:(\d+)-)?(\d+)/(\d{4})$~', trim($donationAccount), $matches)) {
			$payliboPrefix = $matches[1] ?: '';
			$payliboNumber = $matches[2];
			$payliboBank = $matches[3];
		}
		$this->template->payliboPrefix = $payliboPrefix;
		$this->template->payliboNumber = $payliboNumber;
		$this->template->payliboBank = $payliboBank;
	}
}
