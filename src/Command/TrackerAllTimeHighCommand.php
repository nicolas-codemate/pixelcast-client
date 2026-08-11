<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Sync\CoinGeckoSyncConfig;
use App\Config\Sync\TrackerItem;
use App\Config\Sync\TwelveDataSyncConfig;
use App\Config\SyncsConfigLoader;
use App\Tracker\AllTimeHigh;
use App\Tracker\AllTimeHighStore;
use App\Tracker\History\AllTimeHighSourceInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs outside the scheduler: the deep history a source serves weighs a few hundred kilobytes per
 * asset, which has no place in a five-minute cycle.
 *
 * What it writes only rises, exactly like the scheduler: end-of-day bars know nothing of the high of
 * the running session, so a catch-up launched at 15:00 must not crush what a sync observed at 10:00.
 * Bringing a wrong value down is therefore two deliberate steps, --reset then a catch-up.
 */
#[AsCommand(
    name: 'app:tracker:ath',
    description: 'Fetches the deepest history each source serves and stores the all-time high of the tracked assets',
)]
final class TrackerAllTimeHighCommand extends Command
{
    /**
     * @param iterable<AllTimeHighSourceInterface> $allTimeHighSources
     */
    public function __construct(
        #[AutowireIterator('app.tracker_all_time_high_source')]
        private readonly iterable $allTimeHighSources,
        private readonly SyncsConfigLoader $configLoader,
        private readonly AllTimeHighStore $allTimeHighStore,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::OPTIONAL, 'Tracked symbol to catch up; omit to pick one interactively')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Catch up every tracked asset')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Forget the stored all-time high instead of fetching it, so that the next syncs rebuild it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $trackedAssets = $this->trackedAssets();

        /** @var bool $catchUpEveryAsset */
        $catchUpEveryAsset = $input->getOption('all');
        /** @var bool $forgetInsteadOfFetching */
        $forgetInsteadOfFetching = $input->getOption('reset');
        /** @var string|null $requestedSymbol */
        $requestedSymbol = $input->getArgument('symbol');

        if ($catchUpEveryAsset && null !== $requestedSymbol) {
            $io->error('Pass either a symbol or --all, not both.');

            return Command::INVALID;
        }

        if (null !== $requestedSymbol && [] === self::assetsCarrying($trackedAssets, $requestedSymbol)) {
            $io->error(\sprintf(
                'Unknown symbol "%s". Configured: %s.',
                $requestedSymbol,
                [] === $trackedAssets ? 'none' : implode(', ', self::configuredSymbols($trackedAssets)),
            ));

            return Command::INVALID;
        }

        if (!$catchUpEveryAsset && null === $requestedSymbol && !$input->isInteractive()) {
            $io->error('No symbol given. Pass a symbol argument or --all when running non-interactively.');

            return Command::INVALID;
        }

        if ([] === $trackedAssets) {
            $io->warning('No tracker item is configured, nothing to catch up.');

            return Command::SUCCESS;
        }

        if ($catchUpEveryAsset) {
            $assetsToProcess = $trackedAssets;
        } elseif (null !== $requestedSymbol) {
            $assetsToProcess = self::assetsCarrying($trackedAssets, $requestedSymbol);
        } else {
            /** @var string $chosenAssetLabel */
            $chosenAssetLabel = $io->choice('Which tracked asset do you want to catch up?', array_keys($trackedAssets));
            $assetsToProcess = [$chosenAssetLabel => $trackedAssets[$chosenAssetLabel]];
        }

        $observedAt = $this->clock->now();
        $assetsThatFailed = [];

        foreach ($assetsToProcess as $assetLabel => $trackedAsset) {
            $assetWasProcessed = $forgetInsteadOfFetching
                ? $this->forgetAndReport($io, $assetLabel, $trackedAsset['syncType'], $trackedAsset['item'])
                : $this->catchUpAndReport($io, $assetLabel, $trackedAsset['syncType'], $trackedAsset['item'], $observedAt);

            if (!$assetWasProcessed) {
                $assetsThatFailed[] = $assetLabel;
            }
        }

        if ([] !== $assetsThatFailed) {
            $io->error(\sprintf('The stored all-time high was left as it was for: %s.', implode(', ', $assetsThatFailed)));

            return Command::FAILURE;
        }

        $io->success($forgetInsteadOfFetching
            ? 'Stored all-time highs forgotten, the next syncs rebuild them.'
            : 'All-time high catch-up completed.');

        return Command::SUCCESS;
    }

    private function catchUpAndReport(SymfonyStyle $io, string $assetLabel, string $syncType, TrackerItem $item, \DateTimeImmutable $observedAt): bool
    {
        $source = $this->sourceServing($syncType);
        if (null === $source) {
            $io->writeln(\sprintf('  %s: nothing to catch up, %s', $assetLabel, self::whyThisGroupHasNoSource($syncType)));

            return true;
        }

        $storedHigh = $this->allTimeHighStore->readAllTimeHigh($syncType, $item->symbol, $item->currency);

        $fetchedHigh = $source->fetchAllTimeHigh($item, $observedAt);
        if (null === $fetchedHigh) {
            $io->writeln(\sprintf('  %s: the source served no all-time high, see the logs', $assetLabel));

            return false;
        }

        $resultingHigh = $this->allTimeHighStore->raiseTo($fetchedHigh);
        if (null === $resultingHigh) {
            $io->writeln(\sprintf('  %s: the fetched all-time high could not be written, see the logs', $assetLabel));

            return false;
        }

        $io->writeln(\sprintf(
            '  %s: was %s, fetched %s, now %s',
            $assetLabel,
            self::describeHigh($storedHigh),
            self::describeHigh($fetchedHigh),
            self::describeHigh($resultingHigh),
        ));

        return true;
    }

    private function forgetAndReport(SymfonyStyle $io, string $assetLabel, string $syncType, TrackerItem $item): bool
    {
        $storedHigh = $this->allTimeHighStore->readAllTimeHigh($syncType, $item->symbol, $item->currency);

        if (!$this->allTimeHighStore->forget($syncType, $item->symbol, $item->currency)) {
            $io->writeln(\sprintf('  %s: the stored all-time high could not be forgotten, see the logs', $assetLabel));

            return false;
        }

        $io->writeln(\sprintf(
            '  %s: %s',
            $assetLabel,
            null === $storedHigh ? 'nothing was stored' : 'forgotten, was '.self::describeHigh($storedHigh),
        ));

        return true;
    }

    /**
     * @return array<string, array{syncType: string, item: TrackerItem}> keyed by the label that names an item, e.g. coingecko bitcoin (EUR)
     */
    private function trackedAssets(): array
    {
        $trackedAssets = [];

        foreach ($this->configLoader->load()->trackerSyncGroups() as $syncType => $trackerSyncGroup) {
            foreach ($trackerSyncGroup->items as $item) {
                $trackedAssets[self::labelOf($syncType, $item)] = ['syncType' => $syncType, 'item' => $item];
            }
        }

        return $trackedAssets;
    }

    private function sourceServing(string $syncType): ?AllTimeHighSourceInterface
    {
        foreach ($this->allTimeHighSources as $allTimeHighSource) {
            if ($allTimeHighSource->syncType() === $syncType) {
                return $allTimeHighSource;
            }
        }

        return null;
    }

    /**
     * A symbol alone does not name an item, since the same asset can be tracked in two currencies.
     */
    private static function labelOf(string $syncType, TrackerItem $item): string
    {
        return \sprintf('%s %s (%s)', $syncType, $item->symbol, mb_strtoupper($item->currency));
    }

    /**
     * @param array<string, array{syncType: string, item: TrackerItem}> $trackedAssets
     *
     * @return array<string, array{syncType: string, item: TrackerItem}>
     */
    private static function assetsCarrying(array $trackedAssets, string $symbol): array
    {
        return array_filter(
            $trackedAssets,
            static fn (array $trackedAsset): bool => $trackedAsset['item']->symbol === $symbol,
        );
    }

    /**
     * @param array<string, array{syncType: string, item: TrackerItem}> $trackedAssets
     *
     * @return list<string>
     */
    private static function configuredSymbols(array $trackedAssets): array
    {
        $symbols = [];

        foreach ($trackedAssets as $trackedAsset) {
            $symbols[] = $trackedAsset['item']->symbol;
        }

        return array_values(array_unique($symbols));
    }

    private static function whyThisGroupHasNoSource(string $syncType): string
    {
        return match ($syncType) {
            CoinGeckoSyncConfig::syncType() => 'this group serves its own all-time high on every sync',
            TwelveDataSyncConfig::syncType() => 'this group serves no history and offers no ath bottom line yet',
            default => 'no source serves the deep history of this group',
        };
    }

    private static function describeHigh(?AllTimeHigh $allTimeHigh): string
    {
        if (null === $allTimeHigh) {
            return 'nothing stored';
        }

        if (null === $allTimeHigh->reachedAt) {
            return (string) $allTimeHigh->price;
        }

        return \sprintf('%s reached on %s', $allTimeHigh->price, $allTimeHigh->reachedAt->format('Y-m-d'));
    }
}
