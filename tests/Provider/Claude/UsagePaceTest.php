<?php

declare(strict_types=1);

namespace App\Tests\Provider\Claude;

use App\Client\Gauge\GaugeRow;
use App\Provider\Claude\ClaudeUsageWindowKind;
use App\Provider\Claude\UsagePace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UsagePaceTest extends TestCase
{
    private const string READING_INSTANT = '2026-01-05T12:00:00+00:00';
    private const int WEEKLY_WINDOW_IN_SECONDS = 604800;
    private const int SESSION_WINDOW_IN_SECONDS = 18000;
    private const int HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS = 302400;

    /**
     * @return iterable<string, array{int, int|null, int}>
     */
    public static function provideSkippedPaceCases(): iterable
    {
        yield 'no reset instant, as the Fable window answers' => [40, null, self::WEEKLY_WINDOW_IN_SECONDS];
        yield 'nothing consumed yet' => [0, self::HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS, self::WEEKLY_WINDOW_IN_SECONDS];
        yield 'exactly an hour elapsed' => [40, self::WEEKLY_WINDOW_IN_SECONDS - UsagePace::MINIMUM_ELAPSED_IN_SECONDS, self::WEEKLY_WINDOW_IN_SECONDS];
        yield 'the reset falls exactly now' => [40, 0, self::WEEKLY_WINDOW_IN_SECONDS];
        yield 'the reset is already behind us' => [40, -60, self::WEEKLY_WINDOW_IN_SECONDS];
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideComputedPaceCases(): iterable
    {
        yield 'one second past the elapsed floor' => [40, self::WEEKLY_WINDOW_IN_SECONDS - UsagePace::MINIMUM_ELAPSED_IN_SECONDS - 1, self::WEEKLY_WINDOW_IN_SECONDS];
        yield 'one second before the reset' => [40, 1, self::WEEKLY_WINDOW_IN_SECONDS];
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideArrowCases(): iterable
    {
        yield 'well over budget' => [60, UsagePace::ACCELERATING_ARROW];
        yield 'just over the accelerating threshold' => [56, UsagePace::ACCELERATING_ARROW];
        yield 'exactly on the accelerating threshold' => [55, UsagePace::STEADY_ARROW];
        yield 'exactly on budget' => [50, UsagePace::STEADY_ARROW];
        yield 'exactly on the slowing threshold' => [45, UsagePace::STEADY_ARROW];
        yield 'just under the slowing threshold' => [44, UsagePace::SLOWING_ARROW];
        yield 'well under budget' => [40, UsagePace::SLOWING_ARROW];
    }

    #[DataProvider('provideSkippedPaceCases')]
    public function testTheIndicatorIsSkippedWheneverTheReadingWouldBeNoise(int $percent, ?int $remainingInSeconds, int $windowSeconds): void
    {
        self::assertNull(self::computePace($percent, $remainingInSeconds, $windowSeconds));
    }

    #[DataProvider('provideComputedPaceCases')]
    public function testTheIndicatorSurvivesOneSecondInsideEveryGuard(int $percent, int $remainingInSeconds, int $windowSeconds): void
    {
        self::assertNotNull(self::computePace($percent, $remainingInSeconds, $windowSeconds));
    }

    public function testHalfAWeeklyWindowSpentAtHalfTheBudgetLandsExactlyOnEmptyAtResetTime(): void
    {
        $pace = self::computePace(50, self::HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS, self::WEEKLY_WINDOW_IN_SECONDS);

        self::assertNotNull($pace);
        self::assertSame(1.0, $pace->multiplier);
        self::assertSame('x1.0>', $pace->note());
    }

    public function testTwentyPercentAheadOfBudgetReadsAsAnAcceleratingNote(): void
    {
        $pace = self::computePace(60, self::HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS, self::WEEKLY_WINDOW_IN_SECONDS);

        self::assertNotNull($pace);
        self::assertSame(1.2, $pace->multiplier);
        self::assertSame('x1.2^', $pace->note());
    }

    public function testTwentyPercentUnderBudgetReadsAsASlowingNote(): void
    {
        $pace = self::computePace(40, self::HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS, self::WEEKLY_WINDOW_IN_SECONDS);

        self::assertNotNull($pace);
        self::assertSame(0.8, $pace->multiplier);
        self::assertSame('x0.8v', $pace->note());
    }

    #[DataProvider('provideArrowCases')]
    public function testTheArrowLadderPlacesItsBoundariesOnTheSteadyArrow(int $percent, string $expectedArrow): void
    {
        $pace = self::computePace($percent, self::HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS, self::WEEKLY_WINDOW_IN_SECONDS);

        self::assertNotNull($pace);
        self::assertSame($expectedArrow, $pace->arrow());
    }

    public function testTheWindowLengthTravelsWithTheWindowRatherThanBeingAConstant(): void
    {
        $halfOfTheSessionWindowInSeconds = intdiv(self::SESSION_WINDOW_IN_SECONDS, 2);

        $sessionPace = self::computePace(50, $halfOfTheSessionWindowInSeconds, ClaudeUsageWindowKind::Session->secondsInWindow());
        $weeklyPaceOverTheSameRemainder = self::computePace(50, $halfOfTheSessionWindowInSeconds, ClaudeUsageWindowKind::WeeklyAll->secondsInWindow());

        self::assertNotNull($sessionPace);
        self::assertNotNull($weeklyPaceOverTheSameRemainder);
        self::assertSame(1.0, $sessionPace->multiplier);
        self::assertNotSame(1.0, $weeklyPaceOverTheSameRemainder->multiplier);
    }

    public function testAPathologicalMultiplierStaysInsideTheNoteLimit(): void
    {
        $pace = self::computePace(100, self::WEEKLY_WINDOW_IN_SECONDS - UsagePace::MINIMUM_ELAPSED_IN_SECONDS - 1, self::WEEKLY_WINDOW_IN_SECONDS);

        self::assertNotNull($pace);
        self::assertSame('x99.9^', $pace->note());
        self::assertLessThanOrEqual(GaugeRow::MAXIMUM_NOTE_LENGTH, mb_strlen($pace->note()));
    }

    private static function computePace(int $percent, ?int $remainingInSeconds, int $windowSeconds): ?UsagePace
    {
        $now = new MockClock(self::READING_INSTANT)->now();

        return UsagePace::compute(
            $percent,
            null === $remainingInSeconds ? null : $now->modify(\sprintf('%+d seconds', $remainingInSeconds)),
            $windowSeconds,
            $now,
        );
    }
}
