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
}
