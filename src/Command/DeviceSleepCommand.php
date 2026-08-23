<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Sleep\SleepPayload;
use App\Client\Sleep\SleepScheduleDay;
use App\Client\Sleep\SleepSlot;
use App\Client\Sleep\SleepState;
use App\Config\Sleep\DeviceSleepConfig;
use App\Config\SyncsConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The device stores the schedule itself and goes on applying it, so this is run once, when the
 * section is written or changed, and not on a cycle like a sync group. Run it again if the device
 * ever comes back without its schedule.
 *
 * @extends AbstractDevicePushCommand<DeviceSleepConfig, SleepPayload, SleepState>
 */
#[AsCommand(
    name: 'app:device:sleep',
    description: 'Pushes the sleep schedule of pixelcast.yaml to the device',
)]
final class DeviceSleepCommand extends AbstractDevicePushCommand
{
    protected function sectionKey(): string
    {
        return 'sleep';
    }

    protected function missingSectionNoun(): string
    {
        return 'schedule';
    }

    protected function pushedThingName(): string
    {
        return 'sleep schedule';
    }

    protected function sectionFromConfig(SyncsConfig $syncsConfig): ?DeviceSleepConfig
    {
        return $syncsConfig->deviceSleep;
    }

    /**
     * @param DeviceSleepConfig $section
     */
    protected function toPayload(object $section): SleepPayload
    {
        return $section->toSleepPayload();
    }

    /**
     * @param SleepPayload $payload
     */
    protected function pushPayload(object $payload): void
    {
        $this->pixelcastClient->pushSleepConfiguration($payload);
    }

    protected function readBack(): SleepState
    {
        return $this->pixelcastClient->fetchSleepState();
    }

    /**
     * @param SleepState $snapshot
     */
    protected function report(SymfonyStyle $io, object $snapshot): void
    {
        $this->reportSleepState($io, $snapshot);
    }

    private function reportSleepState(SymfonyStyle $io, SleepState $sleepState): void
    {
        $io->success([
            \sprintf('The sleep schedule of pixelcast.yaml is now on the device, %s.', self::describeSchedule($sleepState)),
            \sprintf('The panel is %s right now.', self::describePanel($sleepState)),
            'The sync groups follow the same hours: they stop pushing while the panel is off and push again as it comes back on.',
        ]);

        $rows = [];

        foreach ($sleepState->sleepScheduleByDayName as $dayName => $scheduleDay) {
            $panelOff = self::describeWhenThePanelIsOff($scheduleDay);

            if (null !== $panelOff) {
                $rows[] = [$dayName, $panelOff];
            }
        }

        if ([] === $rows) {
            $io->note('The device reports no window on any day.');

            return;
        }

        $io->table(['Day', 'Panel off'], $rows);
    }

    /**
     * Null for a day the device never turns its panel off, which then carries no row.
     */
    private static function describeWhenThePanelIsOff(SleepScheduleDay $scheduleDay): ?string
    {
        if ($scheduleDay->allDay) {
            return 'all day';
        }

        if ([] === $scheduleDay->sleepSlots) {
            return null;
        }

        return implode(', ', array_map(
            static fn (SleepSlot $sleepSlot): string => $sleepSlot->start.'-'.$sleepSlot->end,
            $scheduleDay->sleepSlots,
        ));
    }

    private static function describeSchedule(SleepState $sleepState): string
    {
        $scheduleState = match ($sleepState->scheduleEnabled) {
            true => 'enabled',
            false => 'disabled',
            null => 'in a state the device did not report',
        };

        return \sprintf('%s, display mode "%s"', $scheduleState, $sleepState->displayMode ?? 'unknown');
    }

    private static function describePanel(SleepState $sleepState): string
    {
        // An awake device still names a reason when it refuses to apply the schedule, which is the
        // only way to learn the panel will stay lit tonight.
        $panelState = $sleepState->sleeping ? 'asleep' : 'awake';

        return null === $sleepState->reason
            ? $panelState
            : \sprintf('%s, reason "%s"', $panelState, $sleepState->reason);
    }
}
