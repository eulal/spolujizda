<?php declare(strict_types=1);

namespace App\Model;

use Nette\Database\Table\ActiveRow;
use Nette\Mail\Message;
use Nette\Mail\Mailer;


final class EmailService
{
	public function __construct(
		private Mailer $mailer,
		private string $baseUrl,
		private string $mailFrom,
		private string $mailFromName,
	) {
	}


	/**
	 * Notifikace řidiče, že se přihlásil nový spolujezdec.
	 */
	public function notifyDriverNewPassenger(ActiveRow $ride, ActiveRow $passenger): void
	{
		if (empty($ride->driver_email)) {
			return;
		}

		$event = $ride->ref('event', 'event_id');

		$mail = new Message;
		$mail->setFrom($this->mailFrom, $this->mailFromName)
			->addTo($ride->driver_email, $ride->driver_name)
			->setSubject("Nový spolujezdec – {$event->title}")
			->setHtmlBody($this->buildNewPassengerHtml($ride, $passenger, $event));

		$this->mailer->send($mail);
	}


	/**
	 * Notifikace poptávajících, že byla vytvořena nová jízda.
	 */
	public function notifyRequestersNewRide(ActiveRow $ride, array $requesters): void
	{
		if (empty($requesters)) {
			return;
		}

		$event = $ride->ref('event', 'event_id');
		$directionLabel = $ride->direction === 'there' ? 'tam' : 'zpět';
		$rideUrl = $this->baseUrl . '/jizda/' . $ride->id;

		foreach ($requesters as $requester) {
			$mail = new Message;
			$mail->setFrom($this->mailFrom, $this->mailFromName)
				->addTo($requester->email, $requester->name)
				->setSubject("Nová jízda ({$directionLabel}) – {$event->title}")
				->setHtmlBody($this->buildNewRideHtml($ride, $requester, $event, $rideUrl));

			try {
				$this->mailer->send($mail);
			} catch (\Exception $e) {
				// Log but don't fail – notification is best-effort
				\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
			}
		}
	}


	/**
	 * Odeslání testovacího e-mailu pro ověření SMTP konfigurace.
	 */
	public function sendTestEmail(string $recipientEmail): void
	{
		$mail = new Message;
		$mail->setFrom($this->mailFrom, $this->mailFromName)
			->addTo($recipientEmail)
			->setSubject("Testovací e-mail – Spolujízda")
			->setHtmlBody("<p>Tento e-mail byl odeslán pro ověření správného nastavení SMTP ve vaší aplikaci Spolujízda.</p>");

		$this->mailer->send($mail);
	}



	private function buildNewPassengerHtml(ActiveRow $ride, ActiveRow $passenger, ActiveRow $event): string
	{
		$rideUrl = $this->baseUrl . '/jizda/' . $ride->id . '/detail';
		$time = $ride->departure_time->format('j. n. Y H:i');

		if ($ride->direction === 'back') {
			$route = "<strong>{$event->location}</strong>";
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
		} else {
			$route = "<strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$event->location}</strong>";
		}

		$noteHtml = $passenger->pickup_note ? '<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Poznámka spolujezdce:</td><td style="padding: 8px 0; color: #0f172a;">' . htmlspecialchars($passenger->pickup_note) . '</td></tr>' : '';

		$contentHtml = <<<HTML
			<p style="margin-top: 0; font-size: 17px;">Ahoj <strong>{$ride->driver_name}</strong>,</p>
			<p>do vaší jízdy na akci <strong>{$event->title}</strong> se přihlásil nový spolujezdec.</p>
			
			<div style="background-color: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0; margin: 24px 0;">
				<h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 16px; text-transform: uppercase; letter-spacing: 0.05em;">Údaje spolujezdce</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b; width: 150px;">Jméno:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$passenger->name}</strong></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">E-mail:</td><td style="padding: 8px 0; color: #0f172a;"><a href="mailto:{$passenger->email}" style="color: #2563eb;">{$passenger->email}</a></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Telefon:</td><td style="padding: 8px 0; color: #0f172a;"><a href="tel:{$passenger->phone}" style="color: #2563eb;">{$passenger->phone}</a></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Místo nastoupení:</td><td style="padding: 8px 0; color: #0f172a;">{$passenger->departure_city}</td></tr>
					{$noteHtml}
					<tr><td style="padding: 8px 0; color: #64748b;">Vaše jízda:</td><td style="padding: 8px 0; color: #0f172a;">{$route}, {$time}</td></tr>
				</table>
			</div>

			<p style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 4px; color: #1e3a8a; margin: 24px 0; font-size: 15px; line-height: 1.5;">
				<strong>Důležité:</strong> Spolujezdec nezná váš e-mail a nemůže vás kontaktovat sám. <strong>Prosím, spojte se s ním co nejdříve</strong> (e-mailem nebo telefonicky) a domluvte se na podrobnostech (přesné místo setkání, zavazadla apod.).
			</p>

			<div style="text-align: center; margin: 32px 0 16px 0;">
				<a href="{$rideUrl}" style="display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Zobrazit detail jízdy na webu</a>
			</div>
		HTML;

		return $this->buildEmailWrapper("Nový spolujezdec v autě 🚗", $contentHtml);
	}


	private function buildNewRideHtml(ActiveRow $ride, ActiveRow $requester, ActiveRow $event, string $rideUrl): string
	{
		$time = $ride->departure_time->format('j. n. Y H:i');
		$directionLabel = $ride->direction === 'there' ? 'tam' : 'zpět';

		if ($ride->direction === 'back') {
			$route = "<strong>{$event->location}</strong>";
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
		} else {
			$route = "<strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$event->location}</strong>";
		}

		$noteHtml = $ride->note ? '<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Poznámka řidiče:</td><td style="padding: 8px 0; color: #0f172a;">' . htmlspecialchars($ride->note) . '</td></tr>' : '';

		$contentHtml = <<<HTML
			<p style="margin-top: 0; font-size: 17px;">Ahoj <strong>{$requester->name}</strong>,</p>
			<p>pro akci <strong>{$event->title}</strong> byla vytvořena nová nabídka jízdy, která by vás mohla zajímat:</p>
			
			<div style="background-color: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0; margin: 24px 0;">
				<h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 16px; text-transform: uppercase; letter-spacing: 0.05em;">Detaily nabídky</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b; width: 120px;">Řidič:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$ride->driver_name}</strong></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Trasa:</td><td style="padding: 8px 0; color: #0f172a;">{$route}</td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Čas odjezdu:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$time}</strong></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Směr:</td><td style="padding: 8px 0; color: #0f172a;">{$directionLabel}</td></tr>
					{$noteHtml}
					<tr><td style="padding: 8px 0; color: #64748b;">Volná místa:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$ride->total_seats}</strong></td></tr>
				</table>
			</div>

			<p>Pokud máte o jízdu zájem, přihlaste se co nejdříve, dokud jsou ještě volná místa.</p>

			<div style="text-align: center; margin: 32px 0 16px 0;">
				<a href="{$rideUrl}" style="display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Zobrazit detail a přihlásit se →</a>
			</div>
		HTML;

		return $this->buildEmailWrapper("Nová nabídka jízdy 🚗", $contentHtml);
	}


	/**
	 * Potvrzení nabídky jízdy (řidiči).
	 */
	public function sendRideOfferConfirmation(ActiveRow $ride): void
	{
		if (empty($ride->driver_email)) {
			return;
		}

		$event = $ride->ref('event', 'event_id');
		$detailUrl = $this->baseUrl . '/jizda/' . $ride->id . '/detail?token=' . $ride->edit_token;
		$editUrl = $this->baseUrl . '/jizda/' . $ride->id . '/edit?token=' . $ride->edit_token;

		$mail = new Message;
		$mail->setFrom($this->mailFrom, $this->mailFromName)
			->addTo($ride->driver_email, $ride->driver_name)
			->setSubject("Potvrzení nabídky jízdy – {$event->title}")
			->setHtmlBody($this->buildRideOfferConfirmationHtml($ride, $event, $detailUrl, $editUrl));

		$this->mailer->send($mail);
	}


	/**
	 * Potvrzení odběru upozornění (poptávajícímu).
	 */
	public function sendRideRequestConfirmation(ActiveRow $request): void
	{
		if (empty($request->email)) {
			return;
		}

		$event = $request->ref('event', 'event_id');
		$cancelUrl = $this->baseUrl . '/event/cancel-request/' . $request->id . '?token=' . $request->edit_token;

		$mail = new Message;
		$mail->setFrom($this->mailFrom, $this->mailFromName)
			->addTo($request->email, $request->name ?: '')
			->setSubject("Potvrzení odběru upozornění – {$event->title}")
			->setHtmlBody($this->buildRideRequestConfirmationHtml($request, $event, $cancelUrl));

		$this->mailer->send($mail);
	}


	/**
	 * Potvrzení přihlášení ke spolujízdě (spolujezdci).
	 */
	public function sendPassengerJoinConfirmation(ActiveRow $ride, ActiveRow $passenger): void
	{
		if (empty($passenger->email)) {
			return;
		}

		$event = $ride->ref('event', 'event_id');
		$detailUrl = $this->baseUrl . '/jizda/' . $ride->id . '/detail';
		$cancelUrl = $this->baseUrl . '/ride/cancel-passenger/' . $passenger->id . '?token=' . $passenger->edit_token;

		$mail = new Message;
		$mail->setFrom($this->mailFrom, $this->mailFromName)
			->addTo($passenger->email, $passenger->name)
			->setSubject("Potvrzení přihlášení ke spolujízdě – {$event->title}")
			->setHtmlBody($this->buildPassengerJoinConfirmationHtml($ride, $passenger, $event, $detailUrl, $cancelUrl));

		$this->mailer->send($mail);
	}


	private function buildRideOfferConfirmationHtml(ActiveRow $ride, ActiveRow $event, string $detailUrl, string $editUrl): string
	{
		$time = $ride->departure_time->format('j. n. Y H:i');
		$directionLabel = $ride->direction === 'there' ? 'tam' : 'zpět';

		if ($ride->direction === 'back') {
			$route = "<strong>{$event->location}</strong>";
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
		} else {
			$route = "<strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$event->location}</strong>";
		}

		$contentHtml = <<<HTML
			<p style="margin-top: 0; font-size: 17px;">Ahoj <strong>{$ride->driver_name}</strong>,</p>
			<p>Vaše nabídka jízdy na akci <strong>{$event->title}</strong> byla úspěšně uložena do systému. Spolujezdci se nyní mohou přihlašovat.</p>
			
			<div style="background-color: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0; margin: 24px 0;">
				<h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 16px; text-transform: uppercase; letter-spacing: 0.05em;">Detaily jízdy</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b; width: 120px;">Trasa:</td><td style="padding: 8px 0; color: #0f172a;">{$route}</td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Čas odjezdu:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$time}</strong></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Směr:</td><td style="padding: 8px 0; color: #0f172a;">{$directionLabel}</td></tr>
					<tr><td style="padding: 8px 0; color: #64748b;">Volná místa:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$ride->total_seats}</strong></td></tr>
				</table>
			</div>

			<p>Jakmile se k jízdě přihlásí nový spolujezdec, zašleme vám upozornění e-mailem.</p>

			<div style="text-align: center; margin: 32px 0 16px 0;">
				<a href="{$editUrl}" style="display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Upravit / Spravovat jízdu</a>
			</div>
			
			<p style="text-align: center; margin: 0;"><a href="{$detailUrl}" style="color: #2563eb; text-decoration: underline; font-size: 14px;">Zobrazit detail jízdy na webu</a></p>

			<p style="color: #64748b; font-size: 14px; margin-top: 32px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
				<strong>Užitečný tip:</strong> Výše uvedený odkaz slouží pro úpravu nebo smazání jízdy z jakéhokoli zařízení (třeba z mobilu). Odkaz nikomu dalšímu neposílejte, umožňuje plnou správu vaší nabídky.
			</p>
		HTML;

		return $this->buildEmailWrapper("Nabídka jízdy potvrzena 🚗", $contentHtml);
	}


	private function buildRideRequestConfirmationHtml(ActiveRow $request, ActiveRow $event, string $cancelUrl): string
	{
		$directionLabel = $request->direction === 'there' ? 'tam (na akci)' : 'zpět (z akce)';

		$contentHtml = <<<HTML
			<p style="margin-top: 0; font-size: 17px;">Ahoj,</p>
			<p>byl spuštěn odběr upozornění na nové nabídky spolujízdy na akci <strong>{$event->title}</strong>.</p>
			
			<div style="background-color: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0; margin: 24px 0;">
				<h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 16px; text-transform: uppercase; letter-spacing: 0.05em;">Hlídaná akce</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b; width: 120px;">Akce:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$event->title}</strong></td></tr>
					<tr><td style="padding: 8px 0; color: #64748b;">Hlídaný směr:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$directionLabel}</strong></td></tr>
				</table>
			</div>

			<p>Jakmile některý řidič vytvoří novou nabídku jízdy v tomto směru, pošleme vám o tom e-mail, abyste se mohli včas přihlásit.</p>

			<div style="text-align: center; margin: 32px 0 16px 0;">
				<a href="{$cancelUrl}" style="display: inline-block; padding: 10px 24px; background-color: #ef4444; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;">Zrušit odběr upozornění</a>
			</div>
		HTML;

		return $this->buildEmailWrapper("Hlídání jízd aktivováno 🔔", $contentHtml);
	}


	private function buildPassengerJoinConfirmationHtml(ActiveRow $ride, ActiveRow $passenger, ActiveRow $event, string $detailUrl, string $cancelUrl): string
	{
		$time = $ride->departure_time->format('j. n. Y H:i');
		$directionLabel = $ride->direction === 'there' ? 'tam' : 'zpět';

		if ($ride->direction === 'back') {
			$route = "<strong>{$event->location}</strong>";
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
		} else {
			$route = "<strong>{$ride->departure_city}</strong>";
			if ($ride->departure_place) {
				$route .= " <small style='color:#64748b;'>({$ride->departure_place})</small>";
			}
			if ($ride->route_via) {
				$route .= " <span style='color:#94a3b8;'>přes {$ride->route_via}</span>";
			}
			$route .= " → <strong>{$event->location}</strong>";
		}

		$noteHtml = $passenger->pickup_note ? '<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Vaše poznámka:</td><td style="padding: 8px 0; color: #0f172a;">' . htmlspecialchars($passenger->pickup_note) . '</td></tr>' : '';

		$contentHtml = <<<HTML
			<p style="margin-top: 0; font-size: 17px;">Ahoj <strong>{$passenger->name}</strong>,</p>
			<p>byli jste úspěšně přihlášeni jako spolujezdec na akci <strong>{$event->title}</strong>.</p>
			
			<div style="background-color: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0; margin: 24px 0;">
				<h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 16px; text-transform: uppercase; letter-spacing: 0.05em;">Detaily spolujízdy</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b; width: 120px;">Řidič:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$ride->driver_name}</strong></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Kontakt na řidiče:</td><td style="padding: 8px 0; color: #0f172a;"><a href="mailto:{$ride->driver_email}" style="color: #2563eb;">{$ride->driver_email}</a></td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Trasa:</td><td style="padding: 8px 0; color: #0f172a;">{$route}</td></tr>
					<tr style="border-bottom: 1px solid #f1f5f9;"><td style="padding: 8px 0; color: #64748b;">Čas odjezdu:</td><td style="padding: 8px 0; color: #0f172a;"><strong>{$time}</strong></td></tr>
					{$noteHtml}
					<tr><td style="padding: 8px 0; color: #64748b;">Směr:</td><td style="padding: 8px 0; color: #0f172a;">{$directionLabel}</td></tr>
				</table>
			</div>

			<p>Řidič byl o vašem přihlášení informován a brzy se s vámi spojí pro domluvení podrobností cesty.</p>

			<div style="text-align: center; margin: 32px 0 16px 0;">
				<a href="{$detailUrl}" style="display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Detail jízdy na webu</a>
			</div>
			
			<p style="text-align: center; margin: 0;"><a href="{$cancelUrl}" style="color: #ef4444; text-decoration: underline; font-size: 14px;">Zrušit přihlášení ke spolujízdě</a></p>
		HTML;

		return $this->buildEmailWrapper("Spolujízda potvrzena 🎉", $contentHtml);
	}


	private function buildEmailWrapper(string $title, string $contentHtml): string
	{
		return <<<HTML
		<div style="background-color: #f8fafc; padding: 32px 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; color: #334155; line-height: 1.6;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0;">
				<div style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); padding: 32px 24px; text-align: center;">
					<h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.025em;">{$title}</h1>
				</div>
				<div style="padding: 32px 24px;">
					{$contentHtml}
				</div>
				<div style="background-color: #f1f5f9; padding: 24px; text-align: center; font-size: 14px; color: #64748b; border-top: 1px solid #e2e8f0;">
					<p style="margin: 0;">Toto je automaticky generovaný e-mail ze systému Spolujízda.</p>
				</div>
			</div>
		</div>
		HTML;
	}
}
