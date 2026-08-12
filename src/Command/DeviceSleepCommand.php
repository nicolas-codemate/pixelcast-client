<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Exception\PixelcastClientException;
use App\Client\PixelcastClientInterface;
use App\Client\Sleep\SleepScheduleDay;
use App\Client\Sleep\SleepSlot;
use App\Client\Sleep\SleepState;
use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfigLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The device stores the schedule itself and goes on applying it, so this is run once, when the
 * section is written or changed, and not on a cycle like a sync group. Run it again if the device
 * ever comes back without its schedule.
 */
#[AsCommand(
    name: 'app:device:sleep',
    description: 'Pushes the sleep schedule of pixelcast.yaml to the device',
)]
final class DeviceSleepCommand extends Command
{
    public function __construct(
        private readonly SyncsConfigLoader $syncsConfigLoader,
        private readonly PixelcastClientInterface $pixelcastClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $sleepConfig = $this->syncsConfigLoader->load()->deviceSleep;
        } catch (PixelCastConfigException $unusableConfig) {
            $io->error($unusableConfig->getMessage());

            return Command::INVALID;
        }

        if (null === $sleepConfig) {
            $io->error([
                'pixelcast.yaml declares no "sleep" section, so there is no schedule to push.',
                'pixelcast.yaml.dist carries the shape of the section.',
            ]);

            return Command::INVALID;
        }

        try {
            $this->pixelcastClient->pushSleepConfiguration($sleepConfig->toSleepPayload());
        } catch (PixelcastClientException $pushFailure) {
            $io->error(\sprintf('The device did not take the sleep schedule: %s', $pushFailure->getMessage()));

            return Command::FAILURE;
        }

        try {
            $sleepState = $this->pixelcastClient->fetchSleepState();
        } catch (PixelcastClientException $readBackFailure) {
            $io->warning([
                \sprintf('The schedule was pushed, but reading the device back failed: %s', $readBackFailure->getMessage()),
                'Nothing says the device did not take it. Run the command again to see what it holds.',
            ]);

            return Command::SUCCESS;
        }

        $this->reportSleepState($io, $sleepState);

        return Command::SUCCESS;
    }

    private function reportSleepState(SymfonyStyle $io, SleepState $sleepState): void
    {
        $io->success([
            \sprintf('The sleep schedule of pixelcast.yaml is now on the device, %s.', self::describeSchedule($sleepState)),
            \sprintf('The panel is %s right now.', self::describePanel($sleepState)),
            'The sync groups are untouched: they keep pushing while the panel is off.',
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
