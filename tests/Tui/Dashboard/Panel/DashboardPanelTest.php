<?php

declare(strict_types=1);

namespace App\Tests\Tui\Dashboard\Panel;

use App\Domain\AppDomain;
use App\Tui\Dashboard\Panel\DashboardPanel;
use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\DeviceState\DeviceStateSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class DashboardPanelTest extends TestCase
{
    public function testConstructionBuildsSixCollapsedBlocksWithWeatherSelected(): void
    {
        $panel = new DashboardPanel();

        foreach (AppDomain::cases() as $domain) {
            $block = $panel->block($domain);
            self::assertFalse($block->isExpanded(), "Expected {$domain->value} block to be collapsed at construction.");
        }

        self::assertSame(AppDomain::Weather, $panel->selectedDomain());
        self::assertStringStartsWith('> ', $panel->block(AppDomain::Weather)->headerText());
    }

    public function testWidgetIsContainerWithSixChildren(): void
    {
        $panel = new DashboardPanel();

        $widget = $panel->widget();

        self::assertInstanceOf(ContainerWidget::class, $widget);
        self::assertCount(6, $widget->all());
    }

    public function testSelectNextMovesSelectionToTrackersAndUpdatesHeaders(): void
    {
        $panel = new DashboardPanel();

        $panel->selectNext();

        self::assertSame(AppDomain::Trackers, $panel->selectedDomain());
        self::assertStringStartsNotWith('> ', $panel->block(AppDomain::Weather)->headerText());
        self::assertStringStartsWith('> ', $panel->block(AppDomain::Trackers)->headerText());
    }

    public function testSixSelectNextCallsWrapAroundToWeather(): void
    {
        $panel = new DashboardPanel();

        for ($i = 0; $i < 6; ++$i) {
            $panel->selectNext();
        }

        self::assertSame(AppDomain::Weather, $panel->selectedDomain());
    }

    public function testSelectPreviousFromWeatherWrapsToLastEnumCase(): void
    {
        $panel = new DashboardPanel();
        $allDomains = AppDomain::cases();
        $expectedLast = $allDomains[\count($allDomains) - 1];

        $panel->selectPrevious();

        self::assertSame($expectedLast, $panel->selectedDomain());
        self::assertStringStartsWith('> ', $panel->block($expectedLast)->headerText());
        self::assertStringStartsNotWith('> ', $panel->block(AppDomain::Weather)->headerText());
    }

    public function testToggleSelectedFlipsOnlySelectedBlockExpansion(): void
    {
        $panel = new DashboardPanel();

        $panel->toggleSelected();

        self::assertTrue($panel->block(AppDomain::Weather)->isExpanded());
        foreach (AppDomain::cases() as $domain) {
            if (AppDomain::Weather === $domain) {
                continue;
            }
            self::assertFalse(
                $panel->block($domain)->isExpanded(),
                "Expected {$domain->value} to remain collapsed after toggling Weather.",
            );
        }
    }

    public function testUpdateAppliesRendererOutputAndStateMarkersPerDomain(): void
    {
        $statesByDomainValue = [
            AppDomain::Weather->value => new DeviceDomainState(true, [
                'current' => ['tempC' => 21, 'condition' => 'sunny'],
            ]),
            AppDomain::Trackers->value => new DeviceDomainState(false, null),
            AppDomain::Notifications->value => new DeviceDomainState(false, null),
            AppDomain::CustomApps->value => new DeviceDomainState(false, null),
            AppDomain::Indicators->value => new DeviceDomainState(false, null),
            AppDomain::Icons->value => new DeviceDomainState(false, null),
        ];
        $source = new StubDeviceStateSource($statesByDomainValue);
        $panel = new DashboardPanel();

        $panel->update($source);

        $weatherBlock = $panel->block(AppDomain::Weather);
        self::assertStringContainsString('[on]', $weatherBlock->headerText());
        self::assertStringContainsString('21', $weatherBlock->bodyText());
        self::assertStringContainsString('sunny', $weatherBlock->bodyText());

        foreach ([AppDomain::Trackers, AppDomain::Notifications, AppDomain::CustomApps, AppDomain::Indicators, AppDomain::Icons] as $domain) {
            $block = $panel->block($domain);
            self::assertStringContainsString('[off]', $block->headerText());
            self::assertSame('no data', $block->bodyText());
        }
    }

    public function testUpdateStripsControlBytesFromRenderedBodyText(): void
    {
        $statesByDomainValue = [
            AppDomain::Weather->value => new DeviceDomainState(true, [
                'current' => ['tempC' => 21, 'condition' => "\x1b[31mhostile\x1b[0m"],
            ]),
        ];
        $source = new StubDeviceStateSource($statesByDomainValue);
        $panel = new DashboardPanel();

        $panel->update($source);

        $weatherBodyText = $panel->block(AppDomain::Weather)->bodyText();
        self::assertStringNotContainsString("\x1b", $weatherBodyText);
    }

    public function testToggleSelectedRevealsOnlySelectedBlockBodyAndKeepsOthersHidden(): void
    {
        $statesByDomainValue = [
            AppDomain::Weather->value => new DeviceDomainState(true, [
                'current' => ['tempC' => 21, 'condition' => 'sunny'],
            ]),
        ];
        $source = new StubDeviceStateSource($statesByDomainValue);
        $panel = new DashboardPanel();

        $panel->update($source);
        $panel->toggleSelected();

        $weatherHidden = $panel->block(AppDomain::Weather)->bodyContainer()->getStyle()?->getHidden();
        self::assertNotTrue($weatherHidden);

        foreach ([AppDomain::Trackers, AppDomain::Notifications, AppDomain::CustomApps, AppDomain::Indicators, AppDomain::Icons] as $domain) {
            $hidden = $panel->block($domain)->bodyContainer()->getStyle()?->getHidden();
            self::assertTrue($hidden, "Expected {$domain->value} body to remain hidden after toggling Weather.");
        }
    }
}

final class StubDeviceStateSource implements DeviceStateSource
{
    /**
     * @param array<string, DeviceDomainState> $statesByDomainValue
     */
    public function __construct(
        private readonly array $statesByDomainValue,
    ) {
    }

    public function getDomainState(AppDomain $domain): DeviceDomainState
    {
        return $this->statesByDomainValue[$domain->value] ?? new DeviceDomainState(false, null);
    }

    public function snapshot(): array
    {
        return $this->statesByDomainValue;
    }

    public function refresh(float $deltaSeconds): bool
    {
        return true;
    }
}
