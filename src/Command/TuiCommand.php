<?php

declare(strict_types=1);

namespace App\Command;

use App\Tui\Menu\TuiMenuFactory;
use App\Tui\Reachability\DeviceReachabilityProbe;
use App\Tui\Reachability\DeviceReachabilityResult;
use App\Tui\TuiMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

#[AsCommand(name: 'app:tui', description: 'Launches the PixelCast unified terminal interface.')]
final class TuiCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnvironment,
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private readonly ?string $deviceBaseUrl,
        private readonly DeviceReachabilityProbe $deviceReachabilityProbe,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = TuiMode::fromAppEnvironment($this->appEnvironment);
        $reachabilityResult = $this->deviceReachabilityProbe->probe($this->deviceBaseUrl);

        $tui = new Tui();
        $tui->add($this->buildMainMenuWidget($mode));
        $tui->add($this->buildStatusBarWidget($mode, $reachabilityResult));

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

    private function buildMainMenuWidget(TuiMode $mode): AbstractWidget
    {
        $menuItems = TuiMenuFactory::buildForMode($mode);
        $selectListItems = TuiMenuFactory::toSelectListItems($menuItems);

        $selectList = new SelectListWidget($selectListItems, maxVisible: \count($selectListItems));

        $mainPanel = new ContainerWidget();
        $mainPanel->expandVertically(true);
        $mainPanel->add($selectList);

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

        // The URL is sourced from an env var (untrusted by contract) and
        // TextWidget renders its content verbatim including ANSI escapes.
        return StringUtils::stripControlBytes($baseUrl);
    }
}
