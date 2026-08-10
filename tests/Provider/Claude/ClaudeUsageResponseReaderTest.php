<?php

declare(strict_types=1);

namespace App\Tests\Provider\Claude;

use App\Provider\Claude\ClaudeUsageResponseReader;
use App\Provider\Claude\ClaudeUsageSnapshot;
use App\Provider\Claude\ClaudeUsageWindowKind;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class ClaudeUsageResponseReaderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';
    private const string USAGE_FIXTURE_FILE = 'claude-usage.json';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideFableDisplayNameCases(): iterable
    {
        yield 'the documented spelling' => ['Fable', true];
        yield 'shouted' => ['FABLE', true];
        yield 'lowercased' => ['fable', true];
        yield 'a longer model name starting with it' => ['Fable Preview', false];
        yield 'another model entirely' => ['Opus', false];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideUnusableLimitsCases(): iterable
    {
        yield 'absent' => [null];
        yield 'a string' => ['none'];
        yield 'an object instead of a list' => [['kind' => 'session']];
    }

    public function testTheFullResponseMapsToTheFourReadings(): void
    {
        $snapshot = self::read(self::decodedFixture());

        self::assertNotNull($snapshot->session);
        self::assertSame(ClaudeUsageWindowKind::Session, $snapshot->session->kind);
        self::assertSame(41, $snapshot->session->percent);
        self::assertSame('2026-01-01T17:50:00+00:00', $snapshot->session->resetsAt?->format(\DateTimeInterface::RFC3339));

        self::assertNotNull($snapshot->weeklyAll);
        self::assertSame(28, $snapshot->weeklyAll->percent);
        self::assertSame('2026-01-06T15:00:00+00:00', $snapshot->weeklyAll->resetsAt?->format(\DateTimeInterface::RFC3339));

        self::assertNotNull($snapshot->fableWeekly);
        self::assertSame(ClaudeUsageWindowKind::WeeklyScoped, $snapshot->fableWeekly->kind);
        self::assertSame(3, $snapshot->fableWeekly->percent);

        self::assertNotNull($snapshot->spend);
        self::assertSame(1, $snapshot->spend->percent());
        self::assertFalse($snapshot->isEmpty());
    }

    public function testAWindowWithoutAResetInstantIsAReadingRatherThanAFault(): void
    {
        $logger = new RecordingLoggerStub();

        $snapshot = self::read(self::decodedFixture(), $logger);

        self::assertNotNull($snapshot->fableWeekly);
        self::assertNull($snapshot->fableWeekly->resetsAt);
        self::assertSame([], $logger->records);
    }

    public function testTheWindowsAreReadFromTheDiscriminatedLimitsArrayRatherThanFromTheTopLevelKeys(): void
    {
        $usageResponse = self::decodedFixture();
        $usageResponse['five_hour'] = ['utilization' => 99.0, 'resets_at' => '2026-01-01T23:59:00.000000+00:00'];
        $usageResponse['seven_day'] = ['utilization' => 99.0, 'resets_at' => '2026-01-06T23:59:00.000000+00:00'];

        $snapshot = self::read($usageResponse);

        self::assertNotNull($snapshot->session);
        self::assertNotNull($snapshot->weeklyAll);
        self::assertSame(41, $snapshot->session->percent);
        self::assertSame(28, $snapshot->weeklyAll->percent);
    }

    public function testAMissingWindowCostsItsOwnReadingAndNothingElse(): void
    {
        $logger = new RecordingLoggerStub();
        $usageResponse = self::decodedFixture();
        $usageResponse['limits'] = array_values(array_filter(
            self::fixtureLimitEntries(),
            static fn (array $limitEntry): bool => ClaudeUsageWindowKind::Session->value !== ($limitEntry['kind'] ?? null),
        ));

        $snapshot = self::read($usageResponse, $logger);

        self::assertNull($snapshot->session);
        self::assertNotNull($snapshot->weeklyAll);
        self::assertNotNull($snapshot->fableWeekly);
        self::assertNotNull($snapshot->spend);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level'] ?? null);
    }

    #[DataProvider('provideFableDisplayNameCases')]
    public function testTheFableWindowIsMatchedOnItsDisplayNameCaseInsensitively(string $displayName, bool $expectsAReading): void
    {
        $snapshot = self::read(self::fixtureWithLimitEntryOverridden(
            ClaudeUsageWindowKind::WeeklyScoped,
            ['scope' => ['model' => ['id' => null, 'display_name' => $displayName], 'surface' => null]],
        ));

        self::assertSame($expectsAReading, null !== $snapshot->fableWeekly);
    }

    #[DataProvider('provideUnusableLimitsCases')]
    public function testAnUnusableLimitsArrayLeavesEveryWindowNullWithoutFailingTheSnapshot(mixed $limits): void
    {
        $logger = new RecordingLoggerStub();
        $usageResponse = self::decodedFixture();
        $usageResponse['limits'] = $limits;

        $snapshot = self::read($usageResponse, $logger);

        self::assertNull($snapshot->session);
        self::assertNull($snapshot->weeklyAll);
        self::assertNull($snapshot->fableWeekly);
        self::assertNotNull($snapshot->spend);
        self::assertNotSame([], $logger->records);
    }

    public function testAScalarLimitsEntryIsSkippedWhileItsSiblingsAreStillRead(): void
    {
        $logger = new RecordingLoggerStub();
        $usageResponse = self::decodedFixture();
        $limitEntries = self::fixtureLimitEntries();
        $usageResponse['limits'] = ['unexpected', ...$limitEntries];

        $snapshot = self::read($usageResponse, $logger);

        self::assertNotNull($snapshot->session);
        self::assertNotNull($snapshot->weeklyAll);
        self::assertNotNull($snapshot->fableWeekly);
        self::assertSame(['Unexpected Claude usage response shape'], array_column($logger->records, 'message'));
    }

    public function testAnEntryWithoutAReadablePercentIsSkipped(): void
    {
        $logger = new RecordingLoggerStub();

        $snapshot = self::read(self::fixtureWithLimitEntryOverridden(ClaudeUsageWindowKind::Session, ['percent' => null]), $logger);

        self::assertNull($snapshot->session);
        self::assertNotNull($snapshot->weeklyAll);
        self::assertCount(1, $logger->records);
    }

    public function testAnUnreadableResetInstantDegradesToNoResetInstant(): void
    {
        $logger = new RecordingLoggerStub();

        $snapshot = self::read(
            self::fixtureWithLimitEntryOverridden(ClaudeUsageWindowKind::WeeklyAll, ['resets_at' => 'the sixth of January']),
            $logger,
        );

        self::assertNotNull($snapshot->weeklyAll);
        self::assertSame(28, $snapshot->weeklyAll->percent);
        self::assertNull($snapshot->weeklyAll->resetsAt);
        self::assertCount(1, $logger->records);
    }

    public function testAResetInstantIsNormalisedToUtc(): void
    {
        $snapshot = self::read(self::fixtureWithLimitEntryOverridden(
            ClaudeUsageWindowKind::Session,
            ['resets_at' => '2026-01-01T18:50:00.000000+01:00'],
        ));

        self::assertNotNull($snapshot->session);
        self::assertSame('2026-01-01T17:50:00+00:00', $snapshot->session->resetsAt?->format(\DateTimeInterface::RFC3339));
    }

    public function testTheCreditBalanceIsReadFromMinorUnitsAndTheExponentOfItsOwnAmount(): void
    {
        $snapshot = self::read(self::decodedFixture());

        self::assertNotNull($snapshot->spend);
        self::assertSame(2.5, $snapshot->spend->used->amount());
        self::assertSame(170.0, $snapshot->spend->limit->amount());
        self::assertSame('EUR', $snapshot->spend->used->currency);
        self::assertSame(1, $snapshot->spend->percent());
    }

    public function testAnAmountWithoutAnExponentIsReadWholeRatherThanDivided(): void
    {
        $snapshot = self::read(self::fixtureWithSpend([
            'used' => ['amount_minor' => 250, 'currency' => 'EUR', 'exponent' => 0],
        ]));

        self::assertNotNull($snapshot->spend);
        self::assertSame(250.0, $snapshot->spend->used->amount());
    }

    public function testACreditBalanceWithoutALimitHasNoPercentage(): void
    {
        $snapshot = self::read(self::fixtureWithSpend([
            'limit' => ['amount_minor' => 0, 'currency' => 'EUR', 'exponent' => 2],
        ]));

        self::assertNotNull($snapshot->spend);
        self::assertNull($snapshot->spend->percent());
    }

    public function testADisabledCreditBalanceIsSkippedWithoutAWarning(): void
    {
        $logger = new RecordingLoggerStub();

        $snapshot = self::read(self::fixtureWithSpend(['enabled' => false]), $logger);

        self::assertNull($snapshot->spend);
        self::assertNotNull($snapshot->session);
        self::assertSame([], $logger->records);
    }

    public function testAnUnreadableAmountCostsTheCreditBalanceAndNothingElse(): void
    {
        $logger = new RecordingLoggerStub();

        $snapshot = self::read(self::fixtureWithSpend([
            'used' => ['amount_minor' => 'two euros fifty', 'currency' => 'EUR', 'exponent' => 2],
        ]), $logger);

        self::assertNull($snapshot->spend);
        self::assertNotNull($snapshot->session);
        self::assertCount(1, $logger->records);
    }

    public function testTheExtraUsageCountersAreNeverReadAsTheCreditBalance(): void
    {
        $usageResponse = self::decodedFixture();
        $usageResponse['extra_usage'] = ['monthly_limit' => 999999, 'used_credits' => 999999];

        $snapshot = self::read($usageResponse);

        self::assertNotNull($snapshot->spend);
        self::assertSame(1, $snapshot->spend->percent());
    }

    public function testAnEmptyResponseYieldsAnEmptySnapshotRatherThanAnException(): void
    {
        $logger = new RecordingLoggerStub();

        $snapshot = self::read([], $logger);

        self::assertTrue($snapshot->isEmpty());
        self::assertNotSame([], $logger->records);
    }

    /**
     * @param array<string, mixed> $usageResponse
     */
    private static function read(array $usageResponse, ?RecordingLoggerStub $logger = null): ClaudeUsageSnapshot
    {
        return new ClaudeUsageResponseReader($logger ?? new RecordingLoggerStub())->read($usageResponse);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function fixtureWithLimitEntryOverridden(ClaudeUsageWindowKind $kind, array $overrides): array
    {
        $limitEntries = self::fixtureLimitEntries();
        foreach ($limitEntries as $entryIndex => $limitEntry) {
            if ($kind->value === ($limitEntry['kind'] ?? null)) {
                $limitEntries[$entryIndex] = array_replace($limitEntry, $overrides);
            }
        }

        $usageResponse = self::decodedFixture();
        $usageResponse['limits'] = $limitEntries;

        return $usageResponse;
    }

    /**
     * @param array<string, mixed> $spendOverrides
     *
     * @return array<string, mixed>
     */
    private static function fixtureWithSpend(array $spendOverrides): array
    {
        $usageResponse = self::decodedFixture();
        $spend = $usageResponse['spend'] ?? null;
        if (!\is_array($spend)) {
            self::fail('The Claude usage fixture carries no spend block.');
        }

        $usageResponse['spend'] = array_replace($spend, $spendOverrides);

        return $usageResponse;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fixtureLimitEntries(): array
    {
        $limits = self::decodedFixture()['limits'] ?? null;
        if (!\is_array($limits)) {
            self::fail('The Claude usage fixture carries no limits array.');
        }

        $limitEntries = [];
        foreach ($limits as $limit) {
            if (!\is_array($limit)) {
                self::fail('The Claude usage fixture carries a limits entry that is not an object.');
            }

            /** @var array<string, mixed> $limit */
            $limitEntries[] = $limit;
        }

        return $limitEntries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedFixture(): array
    {
        $rawJson = file_get_contents(self::FIXTURES_DIR.'/'.self::USAGE_FIXTURE_FILE);
        if (false === $rawJson) {
            self::fail('The Claude usage fixture could not be read.');
        }

        $decoded = json_decode($rawJson, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            self::fail('The Claude usage fixture is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
