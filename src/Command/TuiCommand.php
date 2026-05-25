<?php

declare(strict_types=1);

namespace App\Command;

use App\Tui\Inspector\InspectorPoller;
use App\Tui\Inspector\InspectorTransport;
use App\Tui\Inspector\RequestLogPanel;
use App\Tui\Inspector\StateInspectorPanel;
use App\Tui\Menu\TuiMenuFactory;
use App\Tui\Reachability\DeviceReachabilityProbe;
use App\Tui\Reachability\DeviceReachabilityResult;
use App\Tui\Scenarios\Panel\ScenariosPanel;
use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDispatcher;
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
use Symfony\Component\Tui\Widget\TextWidget;

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

        $viewContainer = new ContainerWidget();
        $viewContainer->expandVertically(true);
        $viewContainer->add($mainBodyContainer);

        $tui->add($viewContainer);
        $tui->add($this->buildStatusBarWidget($mode, $reachabilityResult));

        if (null !== $inspectorPoller) {
            $tui->onTick($this->buildInspectorTickListener(
                $tui,
                $inspectorPoller,
                $stateInspectorPanel,
                $requestLogPanel,
            ));
        }

        $tui->addListener(function (SelectEvent $event) use ($tui, $menuSelectList, $viewContainer, $mainBodyContainer, $scenariosPanel): void {
            if ($event->getTarget() !== $menuSelectList) {
                return;
            }
            if ('scenarios' !== $event->getValue()) {
                return;
            }

            $this->switchView($tui, $viewContainer, $mainBodyContainer, $scenariosPanel, TuiView::Scenarios);
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

        $tui->addListener(function (CancelEvent $event) use ($tui, $viewContainer, $mainBodyContainer, $scenariosPanel): void {
            if ($event->getTarget() !== $scenariosPanel->selectListWidget()) {
                return;
            }

            $scenariosPanel->clearResult();
            $this->switchView($tui, $viewContainer, $mainBodyContainer, $scenariosPanel, TuiView::Main);
        });

        // Registered on the Tui (not on a widget) so Q quits before focus
        // routing, regardless of which sub-panel currently has focus.
        $tui->addListener(static function (InputEvent $event) use ($tui): void {
            $rawInput = $event->getData();
            if ('q' === $rawInput || 'Q' === $rawInput) {
                $event->stopPropagation();
                $tui->stop();
            }
        });

        $tui->run();

        return Command::SUCCESS;
    }

    private function switchView(
        Tui $tui,
        ContainerWidget $viewContainer,
        ContainerWidget $mainBodyContainer,
        ScenariosPanel $scenariosPanel,
        TuiView $next,
    ): void {
        if ($this->currentView === $next) {
            return;
        }

        $this->currentView = $next;
        $viewContainer->clear();
        $viewContainer->add(match ($next) {
            TuiView::Main => $mainBodyContainer,
            TuiView::Scenarios => $scenariosPanel->widget(),
        });

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

    private function buildStatusBarWidget(TuiMode $mode, DeviceReachabilityResult $reachabilityResult): AbstractWidget
    {
        $targetLabel = $this->formatTargetLabel($this->deviceBaseUrl);

        $statusLine = \sprintf(
            'MODE: %s   TARGET: %s (%s)   [Q] quit',
            $mode->displayLabel(),
            $targetLabel,
            $reachabilityResult->displayLabel,
        );

        return new TextWidget($statusLine);
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
