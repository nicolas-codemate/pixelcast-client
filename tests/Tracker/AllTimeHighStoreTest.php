<?php

declare(strict_types=1);

namespace App\Tests\Tracker;

use App\Tests\Stub\RecordingLoggerStub;
use App\Tracker\AllTimeHigh;
use App\Tracker\AllTimeHighStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Filesystem\Filesystem;

final class AllTimeHighStoreTest extends TestCase
{
    private const string COINGECKO = 'coingecko';
    private const string BOURSORAMA = 'boursorama';
    private const string BITCOIN = 'bitcoin';
    private const string FIRST_SYNC = '2026-08-11T10:00:00+00:00';
    private const string SECOND_SYNC = '2026-08-11T10:05:00+00:00';

    private string $temporaryDirectory;
    private string $databaseFilePath;
    private RecordingLoggerStub $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/pixelcast-tracker-ath-'.bin2hex(random_bytes(6));
        $this->databaseFilePath = $this->temporaryDirectory.'/tracker-all-time-high.sqlite';
        $this->logger = new RecordingLoggerStub();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testAnUnknownAssetHasNoStoredAllTimeHigh(): void
    {
        $store = $this->createStore();

        self::assertNull($store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR'));
    }

    public function testTheFirstObservedPriceBecomesTheAllTimeHigh(): void
    {
        $store = $this->createStore();

        $raisedHigh = $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 107662.0));

        self::assertSame(107662.0, $raisedHigh?->price);
        self::assertSame(107662.0, $store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR')?->price);
    }

    public function testAHigherObservedPriceRaisesTheStoredAllTimeHigh(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 107662.0));

        $raisedHigh = $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0, observedAt: self::SECOND_SYNC));

        self::assertSame(108400.0, $raisedHigh?->price);
        self::assertSame(self::SECOND_SYNC, $raisedHigh->observedAt->format(\DateTimeInterface::RFC3339));
    }

    public function testAnAllTimeHighBelowTheStoredOneLeavesItUntouched(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0));

        $keptHigh = $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 107662.0, observedAt: self::SECOND_SYNC));

        self::assertSame(108400.0, $keptHigh?->price);
        self::assertSame(self::FIRST_SYNC, $keptHigh->observedAt->format(\DateTimeInterface::RFC3339));
    }

    public function testAnEqualObservedPriceLeavesTheStoredObservationDateUntouched(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0));

        $keptHigh = $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0, observedAt: self::SECOND_SYNC));

        self::assertSame(self::FIRST_SYNC, $keptHigh?->observedAt->format(\DateTimeInterface::RFC3339));
    }

    public function testTheStoredHighKeepsTheDateTheCallerSaysThePriceWasReachedOn(): void
    {
        $store = $this->createStore();

        $raisedHigh = $store->raiseTo($this->highOf(
            self::BOURSORAMA,
            '1rTCW8',
            'EUR',
            6.312,
            reachedAt: '2026-07-06T00:00:00+00:00',
            observedAt: self::FIRST_SYNC,
        ));

        self::assertSame('2026-07-06T00:00:00+00:00', $raisedHigh?->reachedAt?->format(\DateTimeInterface::RFC3339));
        self::assertSame(
            '2026-07-06T00:00:00+00:00',
            $store->readAllTimeHigh(self::BOURSORAMA, '1rTCW8', 'EUR')?->reachedAt?->format(\DateTimeInterface::RFC3339),
        );
    }

    public function testAStoredAllTimeHighNeverDecreasesAcrossTwoSyncs(): void
    {
        $observingSync = $this->createStore();
        $observingSync->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0));

        $laterSync = $this->createStore();
        $laterSync->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 107662.0, observedAt: self::SECOND_SYNC));

        self::assertSame(108400.0, $laterSync->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR')?->price);
    }

    public function testForgettingAnAssetRemovesItsStoredAllTimeHigh(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0));

        self::assertTrue($store->forget(self::COINGECKO, self::BITCOIN, 'EUR'));
        self::assertNull($store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR'));
    }

    public function testForgettingAnAssetThatWasNeverStoredIsNotAFailure(): void
    {
        $store = $this->createStore();

        self::assertTrue($store->forget(self::COINGECKO, self::BITCOIN, 'EUR'));
    }

    public function testTheSameAssetInTwoCurrenciesIsStoredTwice(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 92800.0));
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'USD', 108400.0));

        self::assertSame(92800.0, $store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR')?->price);
        self::assertSame(108400.0, $store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'USD')?->price);
    }

    public function testTheSameSymbolInTwoSyncTypesIsStoredTwice(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, 'CW8', 'EUR', 620.0));
        $store->raiseTo($this->highOf(self::BOURSORAMA, 'CW8', 'EUR', 612.4));

        self::assertSame(620.0, $store->readAllTimeHigh(self::COINGECKO, 'CW8', 'EUR')?->price);
        self::assertSame(612.4, $store->readAllTimeHigh(self::BOURSORAMA, 'CW8', 'EUR')?->price);
    }

    public function testTheCurrencyIsNormalisedSoThatEurAndEURAreOneRow(): void
    {
        $store = $this->createStore();
        $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'eur', 92800.0));

        $raisedHigh = $store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 93500.0, observedAt: self::SECOND_SYNC));

        self::assertSame('EUR', $raisedHigh?->currency);
        self::assertSame(93500.0, $store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'eur')?->price);
    }

    public function testNoDatabaseFileIsCreatedBeforeTheFirstUse(): void
    {
        $store = $this->createStore();
        self::assertDirectoryDoesNotExist($this->temporaryDirectory);

        self::assertNull($store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR'));
        self::assertFileExists($this->databaseFilePath);
    }

    public function testAnUnwritableDatabasePathReadsAsNoAllTimeHighAndLogsAWarning(): void
    {
        $store = $this->createStore($this->unwritableDatabasePath());

        self::assertNull($store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR'));
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level'] ?? null);
    }

    public function testAnUnreachableDatabaseIsReportedOncePerProcess(): void
    {
        $store = $this->createStore($this->unwritableDatabasePath());

        self::assertNull($store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR'));
        self::assertNull($store->raiseTo($this->highOf(self::COINGECKO, self::BITCOIN, 'EUR', 108400.0)));
        self::assertFalse($store->forget(self::COINGECKO, self::BITCOIN, 'EUR'));
        self::assertNull($store->readAllTimeHigh(self::COINGECKO, self::BITCOIN, 'EUR'));

        self::assertCount(1, $this->logger->records);
    }

    private function createStore(?string $databaseFilePath = null): AllTimeHighStore
    {
        return new AllTimeHighStore($databaseFilePath ?? $this->databaseFilePath, $this->logger);
    }

    /**
     * A regular file cannot hold a directory, so neither the parent nor the database can be created.
     */
    private function unwritableDatabasePath(): string
    {
        $blockingFile = $this->temporaryDirectory.'/not-a-directory';
        new Filesystem()->dumpFile($blockingFile, '');

        return $blockingFile.'/tracker-all-time-high.sqlite';
    }

    private function highOf(
        string $syncType,
        string $symbol,
        string $currency,
        float $price,
        ?string $reachedAt = null,
        string $observedAt = self::FIRST_SYNC,
    ): AllTimeHigh {
        return new AllTimeHigh(
            $syncType,
            $symbol,
            $currency,
            $price,
            null === $reachedAt ? null : new \DateTimeImmutable($reachedAt),
            new \DateTimeImmutable($observedAt),
        );
    }
}
