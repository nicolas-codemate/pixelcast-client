<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Exception\PixelCastConfigException;
use App\Health\SyncGroupFreshness;
use App\Health\SyncHealthChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:health',
    description: 'Reports whether every enabled sync group pushed to the device recently enough',
)]
final class HealthCommand extends Command
{
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        private readonly SyncHealthChecker $syncHealthChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $freshnessPerSyncGroup = $this->syncHealthChecker->checkEnabledSyncGroups();
        } catch (PixelCastConfigException $unusableConfig) {
            $io->error(\sprintf('The configuration cannot be read, so no sync can run: %s', $unusableConfig->getMessage()));

            return Command::FAILURE;
        }

        if ([] === $freshnessPerSyncGroup) {
            $io->warning('No sync group is enabled, nothing to watch.');

            return Command::SUCCESS;
        }

        $staleSyncTypes = [];

        foreach ($freshnessPerSyncGroup as $freshness) {
            $io->writeln(\sprintf('%s: %s', $freshness->syncType, self::describeFreshness($freshness)));

            if ($freshness->isStale()) {
                $staleSyncTypes[] = $freshness->syncType;
            }
        }

        if ([] !== $staleSyncTypes) {
            $io->error(\sprintf('No recent push for: %s.', implode(', ', $staleSyncTypes)));

            return Command::FAILURE;
        }

        $io->success('Every enabled sync group pushed to the device recently enough.');

        return Command::SUCCESS;
    }

    private static function describeFreshness(SyncGroupFreshness $freshness): string
    {
        if (!$freshness->insideActiveWindow) {
            return 'outside its active window, not watched';
        }

        $secondsSinceWindowOpened = $freshness->secondsSinceWindowOpened;

        if (null === $freshness->ageInSeconds) {
            if (null === $secondsSinceWindowOpened) {
                return 'never pushed to the device';
            }

            return \sprintf('never pushed to the device, window reopened %d min ago', self::inMinutes($secondsSinceWindowOpened));
        }

        if (null !== $secondsSinceWindowOpened && $freshness->ageInSeconds > $secondsSinceWindowOpened) {
            return \sprintf(
                'last push %d min ago, window reopened %d min ago, stale after %d min',
                self::inMinutes($freshness->ageInSeconds),
                self::inMinutes($secondsSinceWindowOpened),
                self::inMinutes($freshness->staleAfterInSeconds),
            );
        }

        return \sprintf(
            'last push %d min ago, stale after %d min',
            self::inMinutes($freshness->ageInSeconds),
            self::inMinutes($freshness->staleAfterInSeconds),
        );
    }

    private static function inMinutes(int $seconds): int
    {
        return intdiv($seconds, self::SECONDS_PER_MINUTE);
    }
}
