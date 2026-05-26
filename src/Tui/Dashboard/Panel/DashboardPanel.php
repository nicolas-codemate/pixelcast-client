<?php

declare(strict_types=1);

namespace App\Tui\Dashboard\Panel;

use App\Domain\AppDomain;
use App\Tui\Dashboard\CollapsibleBlock;
use App\Tui\Dashboard\Renderer\CustomAppsRenderer;
use App\Tui\Dashboard\Renderer\DomainRenderer;
use App\Tui\Dashboard\Renderer\IconsRenderer;
use App\Tui\Dashboard\Renderer\IndicatorsRenderer;
use App\Tui\Dashboard\Renderer\NotificationsRenderer;
use App\Tui\Dashboard\Renderer\TrackersRenderer;
use App\Tui\Dashboard\Renderer\WeatherRenderer;
use App\Tui\DeviceState\DeviceStateSource;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class DashboardPanel
{
    /** @var array<string, CollapsibleBlock> */
    private readonly array $blocks;

    /** @var array<string, DomainRenderer> */
    private readonly array $renderers;

    /** @var list<AppDomain> */
    private readonly array $orderedDomains;

    private readonly ContainerWidget $outerContainer;

    private int $selectedBlockIndex = 0;

    public function __construct()
    {
        $blocks = [];
        foreach (AppDomain::cases() as $domain) {
            $blocks[$domain->value] = new CollapsibleBlock(self::titleFor($domain));
        }
        $this->blocks = $blocks;

        $this->renderers = [
            AppDomain::Weather->value => new WeatherRenderer(),
            AppDomain::Trackers->value => new TrackersRenderer(),
            AppDomain::Notifications->value => new NotificationsRenderer(),
            AppDomain::CustomApps->value => new CustomAppsRenderer(),
            AppDomain::Indicators->value => new IndicatorsRenderer(),
            AppDomain::Icons->value => new IconsRenderer(),
        ];

        $this->orderedDomains = AppDomain::cases();

        $this->outerContainer = new ContainerWidget();
        $this->outerContainer->expandVertically(true);
        foreach ($this->orderedDomains as $domain) {
            $this->outerContainer->add($this->blocks[$domain->value]->widget());
        }

        $this->selectedBlock()->setSelected(true);
    }

    public function widget(): ContainerWidget
    {
        return $this->outerContainer;
    }

    public function update(DeviceStateSource $source): void
    {
        foreach ($this->orderedDomains as $domain) {
            $state = $source->getDomainState($domain);
            $body = $this->renderers[$domain->value]->render($state);
            $this->blocks[$domain->value]->setState($state->hasData, $body);
        }
    }

    public function selectedDomain(): AppDomain
    {
        return $this->orderedDomains[$this->selectedBlockIndex];
    }

    public function selectNext(): void
    {
        $this->moveSelection(1);
    }

    public function selectPrevious(): void
    {
        $this->moveSelection(-1);
    }

    public function toggleSelected(): void
    {
        $this->selectedBlock()->toggle();
    }

    public function block(AppDomain $domain): CollapsibleBlock
    {
        return $this->blocks[$domain->value];
    }

    private function moveSelection(int $delta): void
    {
        $totalDomains = \count($this->orderedDomains);
        $this->selectedBlock()->setSelected(false);
        $this->selectedBlockIndex = ($this->selectedBlockIndex + $delta + $totalDomains) % $totalDomains;
        $this->selectedBlock()->setSelected(true);
    }

    private function selectedBlock(): CollapsibleBlock
    {
        return $this->blocks[$this->orderedDomains[$this->selectedBlockIndex]->value];
    }

    private static function titleFor(AppDomain $domain): string
    {
        return match ($domain) {
            AppDomain::Weather => 'Weather',
            AppDomain::Trackers => 'Trackers',
            AppDomain::Notifications => 'Notifications',
            AppDomain::CustomApps => 'Custom Apps',
            AppDomain::Indicators => 'Indicators',
            AppDomain::Icons => 'Icons',
        };
    }
}
