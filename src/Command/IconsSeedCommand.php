<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Exception\PixelcastClientException;
use App\Client\PixelcastClientInterface;
use App\Config\Exception\PixelCastConfigException;
use App\Config\SyncsConfig;
use App\Config\SyncsConfigLoader;
use App\Tracker\TrackedAsset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:icons:seed',
    description: 'Downloads onto the device the icons the tracker items of pixelcast.yaml name',
)]
final class IconsSeedCommand extends Command
{
    public function __construct(
        private readonly SyncsConfigLoader $syncsConfigLoader,
        private readonly PixelcastClientInterface $pixelcastClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Download the icons again even when the device already carries them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $syncsConfig = $this->syncsConfigLoader->load();
        } catch (PixelCastConfigException $unusableConfig) {
            $io->error($unusableConfig->getMessage());

            return Command::INVALID;
        }

        $expectedIcons = self::iconsExpectedByTheConfiguration($syncsConfig);

        if ([] === $expectedIcons) {
            $io->warning('No tracker item names an icon, nothing to seed.');

            return Command::SUCCESS;
        }

        try {
            $iconsOnTheDevice = $this->pixelcastClient->listIcons();
        } catch (PixelcastClientException $listingFailure) {
            $io->error(\sprintf('The icons already on the device could not be listed: %s', $listingFailure->getMessage()));

            return Command::FAILURE;
        }

        /** @var bool $downloadEvenWhenPresent */
        $downloadEvenWhenPresent = $input->getOption('force');

        /** @var list<string> $downloadedIconNames */
        $downloadedIconNames = [];
        /** @var list<string> $skippedIconNames */
        $skippedIconNames = [];
        /** @var list<string> $iconNamesWithoutMapping */
        $iconNamesWithoutMapping = [];
        /** @var list<string> $failedIconNames */
        $failedIconNames = [];

        foreach ($expectedIcons as $iconName => $trackedAsset) {
            if (!$downloadEvenWhenPresent && $iconsOnTheDevice->hasIcon($iconName)) {
                $io->writeln(\sprintf('  %s: already on the device, skipped', $iconName));
                $skippedIconNames[] = $iconName;

                continue;
            }

            $laMetricIconId = $trackedAsset->item->lametricId;

            if (null === $laMetricIconId) {
                $io->writeln(\sprintf('  %s: no lametricId configured, skipped (upload it with app:icons:upload %s <path>)', $iconName, $iconName));
                $iconNamesWithoutMapping[] = $iconName;

                continue;
            }

            try {
                $this->pixelcastClient->downloadLaMetricIcon($laMetricIconId, $iconName);
            } catch (PixelcastClientException $downloadFailure) {
                $io->writeln(\sprintf('  %s: LaMetric icon %d could not be downloaded: %s', $iconName, $laMetricIconId, $downloadFailure->getMessage()));
                $failedIconNames[] = $iconName;

                continue;
            }

            $io->writeln(\sprintf('  %s: downloaded from LaMetric icon %d, named by %s', $iconName, $laMetricIconId, $trackedAsset->label));
            $downloadedIconNames[] = $iconName;
        }

        if ([] !== $failedIconNames) {
            $io->error(\sprintf('These icons could not be downloaded: %s.', implode(', ', $failedIconNames)));

            return Command::FAILURE;
        }

        if ([] !== $iconNamesWithoutMapping) {
            $io->warning([
                \sprintf('These icons carry no lametricId and are not on the device: %s.', implode(', ', $iconNamesWithoutMapping)),
                'Add a lametricId to the item, or upload the file with app:icons:upload <name> <path>.',
            ]);
        }

        $io->success(\sprintf('%d icon(s) downloaded, %d already on the device.', \count($downloadedIconNames), \count($skippedIconNames)));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, TrackedAsset> keyed by the icon name a tracker item asks the device for
     */
    private static function iconsExpectedByTheConfiguration(SyncsConfig $syncsConfig): array
    {
        $expectedIcons = [];

        foreach ($syncsConfig->trackerSyncGroups() as $syncType => $trackerSyncGroup) {
            foreach ($trackerSyncGroup->items as $item) {
                if (null === $item->icon || '' === $item->icon) {
                    continue;
                }

                $alreadyExpected = $expectedIcons[$item->icon] ?? null;

                // Two items can share one icon name while only one of them carries the mapping, so the
                // item that knows the LaMetric id wins over the one that named the icon first.
                if (null === $alreadyExpected || (null === $alreadyExpected->item->lametricId && null !== $item->lametricId)) {
                    $expectedIcons[$item->icon] = new TrackedAsset($syncType, $item);
                }
            }
        }

        return $expectedIcons;
    }
}
