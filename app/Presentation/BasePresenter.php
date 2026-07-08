<?php declare(strict_types=1);

namespace App\Presentation;

use Nette\Application\UI\Presenter;
use Nette\Forms\Form;


/**
 * Základní presenter – nastaví Bootstrap rendering pro formuláře.
 */
abstract class BasePresenter extends Presenter
{
	/** @inject */
	public \App\Model\AnonymizationService $anonymizationService;

	/** @inject */
	public \App\Core\SettingsService $settingsService;


	protected function startup(): void
	{
		parent::startup();

		// Automatická anonymizace dat minulých akcí (max 1x denně)
		$tempDir = dirname(__DIR__, 2) . '/temp';
		$lockFile = $tempDir . '/anonymization.lock';
		if (!is_file($lockFile) || (time() - filemtime($lockFile)) > 86400) {
			try {
				$this->anonymizationService->anonymizePastEvents();
				@touch($lockFile);
			} catch (\Throwable $e) {
				\Tracy\Debugger::log($e, \Tracy\ILogger::ERROR);
			}
		}
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		$settings = $this->settingsService->getMergedSettings();
		$params = $settings['parameters'] ?? [];
		$this->template->appName = !empty($params['appName']) ? $params['appName'] : 'Spolujízda';
		$this->template->appIcon = !empty($params['appIcon']) ? $params['appIcon'] : 'car-front-fill';
	}


	protected function createComponent(string $name): ?\Nette\ComponentModel\IComponent
	{
		$component = parent::createComponent($name);
		if ($component instanceof Form) {
			$this->makeBootstrap5($component);
		}
		return $component;
	}


	/**
	 * Aplikuje Bootstrap 5 třídy na Nette formulář.
	 */
	protected function makeBootstrap5(Form $form): void
	{
		$renderer = $form->getRenderer();
		$renderer->wrappers['controls']['container'] = null;
		$renderer->wrappers['pair']['container'] = 'div class="mb-3"';
		$renderer->wrappers['pair']['.error'] = 'has-danger';
		$renderer->wrappers['control']['container'] = null;
		$renderer->wrappers['label']['container'] = null;
		$renderer->wrappers['control']['description'] = 'small class="form-text text-muted"';
		$renderer->wrappers['control']['errorcontainer'] = 'div class="invalid-feedback d-block"';
		$renderer->wrappers['control']['.error'] = 'is-invalid';
		$renderer->wrappers['error']['container'] = null;
		$renderer->wrappers['error']['item'] = 'div class="alert alert-danger mb-3"';

		foreach ($form->getControls() as $control) {
			$type = $control->getOption('type');

			if ($type === 'button') {
				$control->getControlPrototype()
					->addClass(empty($googClass) ? 'btn btn-primary' : null);
			} elseif (in_array($type, ['text', 'textarea', 'select', 'datetime'], true)) {
				$control->getControlPrototype()->addClass('form-control');
			} elseif ($type === 'checkbox' || $type === 'radio') {
				$control->getControlPrototype()->addClass('form-check-input');
				$control->getLabelPrototype()->addClass('form-check-label');
			}
		}
	}
}
