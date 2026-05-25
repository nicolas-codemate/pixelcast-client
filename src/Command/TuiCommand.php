<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\PixelCastConfigLoader;
use App\Config\PixelCastConfigWriter;
use App\Tui\Configuration\ConfigurationFieldValidator;
use App\Tui\Configuration\Panel\ConfigurationPanel;
use App\Tui\Configuration\SaveOutcome;
use App\Tui\DeviceStatus\Panel\DeviceStatusPanel;
use App\Tui\DeviceStatus\StatsPoller;
use App\Tui\DeviceStatus\StatsTransport;
use App\Tui\Inspector\InspectorPoller;
use App\Tui\Inspector\InspectorTransport;
use App\Tui\Inspector\RequestLogPanel;
use App\Tui\Inspector\StateInspectorPanel;
use App\Tui\Menu\TuiMenuFactory;
use App\Tui\Reachability\DeviceReachabilityProbe;
use App\Tui\Reachability\DeviceReachabilityResult;
use App\Tui\ResetSim\Panel\ResetSimPanel;
use App\Tui\ResetSim\ResetSimulatorAction;
use App\Tui\Scenarios\Panel\ScenariosPanel;
use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDispatcher;
use App\Tui\StatusBar\StatusBarWidget;
use App\Tui\SyncNow\Panel\SyncNowPanel;
use App\Tui\SyncNow\SyncNowDispatcher;
use App\Tui\SyncNow\SyncNowResultKind;
use App\Tui\SyncNow\SyncTarget;
use App\Tui\TerminalSafeText;
use App\Tui\TuiMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

#[AsCommand(name: 'app:tui', description: 'Opens the PixelCast unified terminal interface')]
final class TuiCommand extends Command
{
    private TuiView $currentView = TuiView::Main;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnvironment,
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private readonly ?string $deviceBaseUrl,
        private readonly DeviceReachabilityProbe $deviceReachabilityProbe,
        private readonly InspectorTransport $inspectorHttpClient,
        private readonly ScenarioCatalog $scenarioCatalog,
        private readonly ScenarioDispatcher $scenarioDispatcher,
        private readonly SyncNowDispatcher $syncNowDispatcher,
        private readonly ResetSimulatorAction $resetSimulatorAction,
        private readonly PixelCastConfigLoader $pixelCastConfigLoader,
        private readonly PixelCastConfigWriter $pixelCastConfigWriter,
        private readonly ConfigurationFieldValidator $configurationFieldValidator,
        private readonly StatsTransport $statsHttpClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = TuiMode::fromAppEnvironment($this->appEnvironment);
        $reachabilityResult = $this->deviceReachabilityProbe->probe($this->deviceBaseUrl);

        $tui = new Tui();

        $menuSelectList = $this->buildMainMenuSelectList($mode);
        $mainBodyContainer = $this->buildMainBodyContainer($menuSelectList);

        $inspectorPoller = null;
        $stateInspectorPanel = null;
        $requestLogPanel = null;

        if (TuiMode::Dev === $mode) {
            $inspectorPoller = new InspectorPoller(
                $this->inspectorHttpClient,
                $this->deviceBaseUrl,
            );
            $stateInspectorPanel = new StateInspectorPanel();
            $requestLogPanel = new RequestLogPanel();

            $initialSnapshot = $inspectorPoller->poll();
            $stateInspectorPanel->update($initialSnapshot, busy: false);
            $requestLogPanel->update($initialSnapshot, busy: false);

            $mainBodyContainer->add($stateInspectorPanel->widget());
            $mainBodyContainer->add($requestLogPanel->widget());
        }

        $scenariosPanel = new ScenariosPanel($this->scenarioCatalog, $mode);

        $syncNowPanel = null;
        $resetSimPanel = null;
        if (TuiMode::Dev === $mode) {
            $syncNowPanel = new SyncNowPanel();
            $resetSimPanel = new ResetSimPanel();
        }

        $configurationPanel = null;
        $statsPoller = null;
        $deviceStatusPanel = null;
        if (TuiMode::Prod === $mode) {
            $configurationPanel = new ConfigurationPanel(
                $this->pixelCastConfigLoader,
                $this->pixelCastConfigWriter,
                $this->configurationFieldValidator,
            );

            $effectiveDeviceUrl = '' !== $configurationPanel->currentDeviceUrl()
                ? $configurationPanel->currentDeviceUrl()
                : $this->deviceBaseUrl;
            $statsPoller = new StatsPoller($this->statsHttpClient, $effectiveDeviceUrl);
            $deviceStatusPanel = new DeviceStatusPanel();
            $deviceStatusPanel->update($statsPoller->poll(), busy: false);
        }

        $viewContainer = new ContainerWidget();
        $viewContainer->expandVertically(true);
        $viewContainer->add($mainBodyContainer);

        $statusBar = new StatusBarWidget();
        $statusBar->setBaseLine($this->buildStatusBarBaseLine(
            $mode,
            $reachabilityResult,
            $configurationPanel?->currentDeviceUrl(),
        ));

        $tui->add($viewContainer);
        $tui->add($statusBar->widget());

        $viewWidgets = [
            TuiView::Main->value => $mainBodyContainer,
            TuiView::Scenarios->value => $scenariosPanel->widget(),
        ];
        if (null !== $syncNowPanel) {
            $viewWidgets[TuiView::SyncNow->value] = $syncNowPanel->widget();
        }
        if (null !== $resetSimPanel) {
            $viewWidgets[TuiView::ResetSim->value] = $resetSimPanel->widget();
        }
        if (null !== $configurationPanel) {
            $viewWidgets[TuiView::Configuration->value] = $configurationPanel->widget();
            $statusBar->setUnsavedChanges($configurationPanel->hasUnsavedChanges());
            $configurationPanel->onUnsavedChangesChanged(static function (bool $hasUnsaved) use ($tui, $statusBar): void {
                $statusBar->setUnsavedChanges($hasUnsaved);
                $tui->requestRender();
            });
        }
        if (null !== $statsPoller) {
            $viewWidgets[TuiView::DeviceStatus->value] = $deviceStatusPanel->widget();
            $tui->onTick($this->buildStatsTickListener(
                $tui,
                $statsPoller,
                $deviceStatusPanel,
            ));
        }

        if (null !== $inspectorPoller) {
            $tui->onTick($this->buildInspectorTickListener(
                $tui,
                $inspectorPoller,
                $stateInspectorPanel,
                $requestLogPanel,
            ));
        }

        $tui->addListener(function (SelectEvent $event) use ($tui, $menuSelectList, $viewContainer, $viewWidgets): void {
            if ($event->getTarget() !== $menuSelectList) {
                return;
            }

            $targetView = TuiView::tryFrom($event->getValue());
            if (null === $targetView || !isset($viewWidgets[$targetView->value])) {
                return;
            }

            $this->switchView($tui, $viewContainer, $targetView, $viewWidgets);
        });

        $tui->addListener(function (SelectEvent $event) use ($tui, $scenariosPanel, $mode): void {
            if ($event->getTarget() !== $scenariosPanel->selectListWidget()) {
                return;
            }

            $scenario = $this->scenarioCatalog->findById($event->getValue(), $mode);
            if (null === $scenario) {
                return;
            }

            $result = $this->scenarioDispatcher->dispatch($scenario);
            $scenariosPanel->showResult($result);
            $tui->requestRender();
        });

        $tui->addListener(function (CancelEvent $event) use ($tui, $viewContainer, $viewWidgets, $scenariosPanel): void {
            if ($event->getTarget() !== $scenariosPanel->selectListWidget()) {
                return;
            }

            $scenariosPanel->clearResult();
            $this->switchView($tui, $viewContainer, TuiView::Main, $viewWidgets);
        });

        if (null !== $syncNowPanel) {
            $tui->addListener(function (SelectEvent $event) use ($tui, $syncNowPanel): void {
                if ($event->getTarget() !== $syncNowPanel->selectListWidget()) {
                    return;
                }

                $target = SyncTarget::tryFrom($event->getValue());
                if (null === $target) {
                    return;
                }

                $result = $this->syncNowDispatcher->dispatch($target);
                if (SyncNowResultKind::Dispatched === $result->kind) {
                    $syncNowPanel->recordDispatch($target, new \DateTimeImmutable());
                }
                $syncNowPanel->showResult($result);
                $tui->requestRender();
            });

            $tui->addListener(function (CancelEvent $event) use ($tui, $viewContainer, $viewWidgets, $syncNowPanel): void {
                if ($event->getTarget() !== $syncNowPanel->selectListWidget()) {
                    return;
                }

                $syncNowPanel->clearResult();
                $this->switchView($tui, $viewContainer, TuiView::Main, $viewWidgets);
            });
        }

        if (null !== $resetSimPanel) {
            $tui->addListener(function (SelectEvent $event) use ($tui, $resetSimPanel): void {
                if ($event->getTarget() !== $resetSimPanel->selectListWidget()) {
                    return;
                }

                $result = $this->resetSimulatorAction->reset();
                $resetSimPanel->showResult($result);
                $tui->requestRender();
            });

            $tui->addListener(function (CancelEvent $event) use ($tui, $viewContainer, $viewWidgets, $resetSimPanel): void {
                if ($event->getTarget() !== $resetSimPanel->selectListWidget()) {
                    return;
                }

                $resetSimPanel->clearResult();
                $this->switchView($tui, $viewContainer, TuiView::Main, $viewWidgets);
            });
        }

        if (null !== $configurationPanel) {
            $tui->addListener(function (CancelEvent $event) use ($tui, $viewContainer, $viewWidgets, $configurationPanel): void {
                if ($event->getTarget() !== $configurationPanel->selectListWidget()) {
                    return;
                }

                $configurationPanel->discardChanges();
                $this->switchView($tui, $viewContainer, TuiView::Main, $viewWidgets);
            });
        }

        if (null !== $deviceStatusPanel) {
            // DeviceStatusPanel is read-only and exposes no focusable widget, so the
            // cancel handler keys off the active view instead of an event target.
            $tui->addListener(function (CancelEvent $event) use ($tui, $viewContainer, $viewWidgets): void {
                if (TuiView::DeviceStatus !== $this->currentView) {
                    return;
                }

                $this->switchView($tui, $viewContainer, TuiView::Main, $viewWidgets);
            });
        }

        $tui->addListener(function (InputEvent $event) use ($tui, $statusBar, $configurationPanel, $mode, $reachabilityResult): void {
            $rawInput = $event->getData();
            if ('q' === $rawInput || 'Q' === $rawInput) {
                $event->stopPropagation();
                $tui->stop();

                return;
            }

            if (null !== $configurationPanel
                && TuiView::Configuration === $this->currentView
                && !$configurationPanel->isEditingField()
                && ('s' === $rawInput || 'S' === $rawInput)
            ) {
                $event->stopPropagation();
                $outcome = $configurationPanel->commitSave();
                if (SaveOutcome::Saved === $outcome) {
                    $statusBar->setBaseLine($this->buildStatusBarBaseLine(
                        $mode,
                        $reachabilityResult,
                        $configurationPanel->currentDeviceUrl(),
                    ));
                }
                $tui->requestRender();
            }
        });

        $tui->run();

        return Command::SUCCESS;
    }

    /**
     * @param array<string, AbstractWidget> $viewWidgets keyed by TuiView::value
     */
    private function switchView(
        Tui $tui,
        ContainerWidget $viewContainer,
        TuiView $next,
        array $viewWidgets,
    ): void {
        if ($this->currentView === $next) {
            return;
        }

        if (!isset($viewWidgets[$next->value])) {
            return;
        }

        $this->currentView = $next;
        $viewContainer->clear();
        $viewContainer->add($viewWidgets[$next->value]);

        $tui->requestRender();
    }

    private function buildInspectorTickListener(
        Tui $tui,
        InspectorPoller $inspectorPoller,
        StateInspectorPanel $stateInspectorPanel,
        RequestLogPanel $requestLogPanel,
    ): callable {
        return static function (TickEvent $event) use (
            $tui,
            $inspectorPoller,
            $stateInspectorPanel,
            $requestLogPanel,
        ): void {
            if (!$inspectorPoller->pollIfDue($event->getDeltaTime())) {
                return;
            }

            $event->setBusy(true);
            $snapshot = $inspectorPoller->getLatestSnapshot();
            $stateInspectorPanel->update($snapshot, busy: false);
            $requestLogPanel->update($snapshot, busy: false);
            $tui->requestRender();
        };
    }

    private function buildStatsTickListener(
        Tui $tui,
        StatsPoller $statsPoller,
        DeviceStatusPanel $deviceStatusPanel,
    ): callable {
        return static function (TickEvent $event) use (
            $tui,
            $statsPoller,
            $deviceStatusPanel,
        ): void {
            if (!$statsPoller->pollIfDue($event->getDeltaTime())) {
                return;
            }

            $event->setBusy(true);
            $deviceStatusPanel->update($statsPoller->getLatestSnapshot(), busy: false);
            $tui->requestRender();
        };
    }

    private function buildMainMenuSelectList(TuiMode $mode): SelectListWidget
    {
        $menuItems = TuiMenuFactory::buildForMode($mode);
        $selectListItems = TuiMenuFactory::toSelectListItems($menuItems);

        return new SelectListWidget($selectListItems, maxVisible: \count($selectListItems));
    }

    private function buildMainBodyContainer(SelectListWidget $menuSelectList): ContainerWidget
    {
        $mainPanel = new ContainerWidget();
        $mainPanel->expandVertically(true);
        $mainPanel->add($menuSelectList);

        return $mainPanel;
    }

    private function buildStatusBarBaseLine(
        TuiMode $mode,
        DeviceReachabilityResult $reachabilityResult,
        ?string $configurationDeviceUrl,
    ): string {
        $effectiveDeviceUrl = null !== $configurationDeviceUrl && '' !== $configurationDeviceUrl
            ? $configurationDeviceUrl
            : $this->deviceBaseUrl;

        $targetLabel = $this->formatTargetLabel($effectiveDeviceUrl);

        return \sprintf(
            'MODE: %s   TARGET: %s (%s)   [Q] quit',
            $mode->displayLabel(),
            $targetLabel,
            $reachabilityResult->displayLabel,
        );
    }

    private function formatTargetLabel(?string $baseUrl): string
    {
        if (null === $baseUrl || '' === $baseUrl) {
            return 'n/a';
        }

        // URL comes from an env var rendered verbatim by TextWidget; strip
        // C0/C1 controls to block terminal-escape injection.
        return TerminalSafeText::stripControlBytes($baseUrl);
    }
}
