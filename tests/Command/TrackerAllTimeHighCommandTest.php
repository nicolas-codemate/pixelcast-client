<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TrackerAllTimeHighCommand;
use App\Tests\Factory\AllTimeHighStoreFactory;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use App\Tests\Stub\RecordingAllTimeHighSourceStub;
use App\Tracker\AllTimeHigh;
use App\Tracker\AllTimeHighStore;
use App\Tracker\History\AllTimeHighSourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TrackerAllTimeHighCommandTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../Config/Fixtures';
    private const string CATCH_UP_CONFIG_FILE = 'syncs-tracker-ath-catch-up.yaml';
    private const string CONFIG_FILE_WITHOUT_TRACKER = 'syncs-claude-enabled.yaml';
    private const string BOURSORAMA = 'boursorama';
    private const string ETF_CODE = '1rTDCAM';
    private const string WORLD_ETF_CODE = '1rTCW8';
    private const string CATCH_UP_INSTANT = '2026-08-11T15:00:00+00:00';

    private string $databaseFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFilePath = AllTimeHighStoreFactory::temporaryDatabasePath();
    }

    public function testASymbolAndTheAllOptionTogetherAreRefused(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source);

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE, '--all' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('not both', $tester->getDisplay());
        self::assertSame([], $source->itemsAskedFor);
    }

    public function testAnUnknownSymbolIsRefusedAndTheConfiguredSymbolsAreListed(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source);

        $exitCode = $tester->execute(['symbol' => 'NOPE']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('1rTCW8, 1rTDCAM, bitcoin, AAPL', $tester->getDisplay());
        self::assertSame([], $source->itemsAskedFor);
    }

    public function testWithoutASymbolAndWithoutAllTheCommandRefusesToRunNonInteractively(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('No symbol given', $tester->getDisplay());
    }

    public function testWithoutASymbolTheCommandAsksWhichAssetToCatchUp(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source);
        $tester->setInputs(['boursorama 1rTDCAM (EUR)']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $source->itemsAskedFor);
        self::assertSame(self::ETF_CODE, $source->itemsAskedFor[0]->symbol);
    }

    public function testNoTrackerItemConfiguredIsReportedAndSucceeds(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source, self::CONFIG_FILE_WITHOUT_TRACKER);

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No tracker item is configured', $tester->getDisplay());
        self::assertSame([], $source->itemsAskedFor);
    }

    public function testTheFetchedAllTimeHighIsStored(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8, new \DateTimeImmutable('2022-02-07T00:00:00+00:00'));
        $tester = $this->createTester($source);

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $storedHigh = $this->readStoredHigh(self::ETF_CODE, 'EUR');
        self::assertSame(742.8, $storedHigh?->price);
        self::assertSame('2022-02-07', $storedHigh->reachedAt?->format('Y-m-d'));
        self::assertSame(self::CATCH_UP_INSTANT, $storedHigh->observedAt->format(\DateTimeInterface::RFC3339));
        self::assertStringContainsString(
            'boursorama 1rTDCAM (EUR): was nothing stored, fetched 742.8 reached on 2022-02-07, now 742.8 reached on 2022-02-07',
            $tester->getDisplay(),
        );
    }

    public function testAFetchedAllTimeHighBelowTheStoredOneLeavesItUntouched(): void
    {
        $this->storeHigh(self::ETF_CODE, 'EUR', 620.0);
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(
            self::BOURSORAMA,
            612.4,
            new \DateTimeImmutable('2024-03-18T00:00:00+00:00'),
        ));

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(620.0, $this->readStoredHigh(self::ETF_CODE, 'EUR')?->price);
        self::assertStringContainsString(
            'boursorama 1rTDCAM (EUR): was 620, fetched 612.4 reached on 2024-03-18, now 620',
            $tester->getDisplay(),
        );
    }

    public function testResettingThenCatchingUpIsHowAWrongHighIsBroughtDown(): void
    {
        $this->storeHigh(self::ETF_CODE, 'EUR', 9999.0);

        $resetExitCode = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 612.4))
            ->execute(['symbol' => self::ETF_CODE, '--reset' => true]);
        $catchUpExitCode = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 612.4))
            ->execute(['symbol' => self::ETF_CODE]);

        self::assertSame(Command::SUCCESS, $resetExitCode);
        self::assertSame(Command::SUCCESS, $catchUpExitCode);
        self::assertSame(612.4, $this->readStoredHigh(self::ETF_CODE, 'EUR')?->price);
    }

    public function testTheAllOptionCatchesUpEveryTrackedAsset(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source);

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $source->itemsAskedFor);
        self::assertSame(742.8, $this->readStoredHigh(self::WORLD_ETF_CODE, 'EUR')?->price);
        self::assertSame(742.8, $this->readStoredHigh(self::WORLD_ETF_CODE, 'USD')?->price);
        self::assertSame(742.8, $this->readStoredHigh(self::ETF_CODE, 'EUR')?->price);
    }

    public function testTheDisabledGroupsAreCaughtUpToo(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $tester->execute(['--all' => true]);

        self::assertStringContainsString('coingecko bitcoin (EUR):', $tester->getDisplay());
        self::assertStringContainsString('twelvedata AAPL (USD):', $tester->getDisplay());
    }

    public function testASymbolTrackedInTwoCurrenciesIsCaughtUpInBoth(): void
    {
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source);

        $exitCode = $tester->execute(['symbol' => self::WORLD_ETF_CODE]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $source->itemsAskedFor);
        self::assertSame(742.8, $this->readStoredHigh(self::WORLD_ETF_CODE, 'EUR')?->price);
        self::assertSame(742.8, $this->readStoredHigh(self::WORLD_ETF_CODE, 'USD')?->price);
    }

    public function testAGroupWhoseSourceServesItsAllTimeHighOnEverySyncIsReportedAndDoesNotFailTheCommand(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $exitCode = $tester->execute(['symbol' => 'bitcoin']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString(
            'coingecko bitcoin (EUR): nothing to catch up, this group serves its own all-time high on every sync',
            $tester->getDisplay(),
        );
    }

    public function testAGroupWithoutAnyHistorySourceIsReportedAndDoesNotFailTheCommand(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $exitCode = $tester->execute(['symbol' => 'AAPL']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString(
            'twelvedata AAPL (USD): nothing to catch up, no source serves the deep history of this group',
            $tester->getDisplay(),
        );
    }

    public function testASourceThatServesNothingFailsTheCommand(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA));

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('the source served no all-time high', $tester->getDisplay());
        self::assertStringContainsString('left as it was for', $tester->getDisplay());
    }

    public function testAnUnwritableStoreFailsTheCommand(): void
    {
        $tester = $this->createTester(
            new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8),
            allTimeHighStore: AllTimeHighStoreFactory::unreachableStore(),
        );

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('could not be written', $tester->getDisplay());
    }

    public function testTheResetOptionForgetsTheStoredAllTimeHighWithoutAskingAnySource(): void
    {
        $this->storeHigh(self::ETF_CODE, 'EUR', 620.0);
        $source = new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8);
        $tester = $this->createTester($source);

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE, '--reset' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNull($this->readStoredHigh(self::ETF_CODE, 'EUR'));
        self::assertSame([], $source->itemsAskedFor);
        self::assertStringContainsString('boursorama 1rTDCAM (EUR): forgotten, was 620', $tester->getDisplay());
    }

    public function testTheResetOptionCombinedWithAllForgetsEveryStoredAllTimeHigh(): void
    {
        $this->storeHigh(self::ETF_CODE, 'EUR', 620.0);
        $this->storeHigh(self::WORLD_ETF_CODE, 'EUR', 742.8);
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $exitCode = $tester->execute(['--all' => true, '--reset' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNull($this->readStoredHigh(self::ETF_CODE, 'EUR'));
        self::assertNull($this->readStoredHigh(self::WORLD_ETF_CODE, 'EUR'));
    }

    public function testTheResetOptionWithoutASymbolAndWithoutAllIsRefusedNonInteractively(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $exitCode = $tester->execute(['--reset' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('No symbol given', $tester->getDisplay());
    }

    public function testTheResetOptionOnAnAssetThatWasNeverStoredIsNotAFailure(): void
    {
        $tester = $this->createTester(new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8));

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE, '--reset' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('boursorama 1rTDCAM (EUR): nothing was stored', $tester->getDisplay());
    }

    public function testAnUnwritableStoreFailsTheResetToo(): void
    {
        $tester = $this->createTester(
            new RecordingAllTimeHighSourceStub(self::BOURSORAMA, 742.8),
            allTimeHighStore: AllTimeHighStoreFactory::unreachableStore(),
        );

        $exitCode = $tester->execute(['symbol' => self::ETF_CODE, '--reset' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('could not be forgotten', $tester->getDisplay());
    }

    private function createTester(
        AllTimeHighSourceInterface $allTimeHighSource,
        string $configFileName = self::CATCH_UP_CONFIG_FILE,
        ?AllTimeHighStore $allTimeHighStore = null,
    ): CommandTester {
        return new CommandTester(new TrackerAllTimeHighCommand(
            [$allTimeHighSource],
            SyncsConfigLoaderFactory::forConfigFile(self::FIXTURES_DIR.'/'.$configFileName),
            $allTimeHighStore ?? $this->createStore(),
            new MockClock(new \DateTimeImmutable(self::CATCH_UP_INSTANT)),
        ));
    }

    private function createStore(): AllTimeHighStore
    {
        return AllTimeHighStoreFactory::storeAt($this->databaseFilePath);
    }

    private function storeHigh(string $symbol, string $currency, float $price): void
    {
        $this->createStore()->raiseTo(new AllTimeHigh(
            self::BOURSORAMA,
            $symbol,
            $currency,
            $price,
            null,
            new \DateTimeImmutable('2026-08-11T10:00:00+00:00'),
        ));
    }

    private function readStoredHigh(string $symbol, string $currency): ?AllTimeHigh
    {
        return $this->createStore()->readAllTimeHigh(self::BOURSORAMA, $symbol, $currency);
    }
}
