<?php

declare(strict_types=1);

namespace App\Tests\Provider\Claude;

use App\Provider\Claude\ClaudeUsageColors;
use App\Provider\Claude\UsagePace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ClaudeUsageColorsTest extends TestCase
{
    private const string READING_INSTANT = '2026-01-05T12:00:00+00:00';
    private const int WEEKLY_WINDOW_IN_SECONDS = 604800;
    private const int HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS = 302400;

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideBarColorCases(): iterable
    {
        yield 'an untouched window' => [0, ClaudeUsageColors::GREEN_HEX_CODE];
        yield 'just under the yellow threshold' => [49, ClaudeUsageColors::GREEN_HEX_CODE];
        yield 'exactly on the yellow threshold' => [50, ClaudeUsageColors::YELLOW_HEX_CODE];
        yield 'just under the red threshold' => [79, ClaudeUsageColors::YELLOW_HEX_CODE];
        yield 'exactly on the red threshold' => [80, ClaudeUsageColors::RED_HEX_CODE];
        yield 'a spent window' => [100, ClaudeUsageColors::RED_HEX_CODE];
    }

    /**
     * The percent is read at half a weekly window, where the multiplier is the percentage divided
     * by fifty: 50 gives 1.0, 55 gives 1.1, 65 gives 1.3.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function provideNoteColorCases(): iterable
    {
        yield 'under budget' => [40, ClaudeUsageColors::GREEN_HEX_CODE];
        yield 'exactly on budget' => [50, ClaudeUsageColors::GREEN_HEX_CODE];
        yield 'just over budget' => [51, ClaudeUsageColors::YELLOW_HEX_CODE];
        yield 'on the steady arrow yet already yellow' => [55, ClaudeUsageColors::YELLOW_HEX_CODE];
        yield 'just under the red threshold' => [64, ClaudeUsageColors::YELLOW_HEX_CODE];
        yield 'exactly on the red threshold' => [65, ClaudeUsageColors::RED_HEX_CODE];
        yield 'far over budget' => [90, ClaudeUsageColors::RED_HEX_CODE];
    }

    #[DataProvider('provideBarColorCases')]
    public function testTheBarColorIsDrawnFromThePercentageAlone(int $percent, string $expectedHexCode): void
    {
        self::assertSame($expectedHexCode, ClaudeUsageColors::barColorFor($percent)->hexCode);
    }

    #[DataProvider('provideNoteColorCases')]
    public function testTheNoteColorIsDrawnFromThePaceAlone(int $percent, string $expectedHexCode): void
    {
        self::assertSame($expectedHexCode, ClaudeUsageColors::noteColorFor(self::paceAtHalfOfTheWeeklyWindow($percent))->hexCode);
    }

    public function testTheColorLadderAndTheArrowLadderDisagreeJustAboveBudget(): void
    {
        $pace = self::paceAtHalfOfTheWeeklyWindow(55);

        self::assertSame(1.1, $pace->multiplier);
        self::assertSame('x1.1>', $pace->note());
        self::assertSame(ClaudeUsageColors::YELLOW_HEX_CODE, ClaudeUsageColors::noteColorFor($pace)->hexCode);
    }

    private static function paceAtHalfOfTheWeeklyWindow(int $percent): UsagePace
    {
        $now = new MockClock(self::READING_INSTANT)->now();

        $pace = UsagePace::compute(
            $percent,
            $now->modify(\sprintf('+%d seconds', self::HALF_OF_THE_WEEKLY_WINDOW_IN_SECONDS)),
            self::WEEKLY_WINDOW_IN_SECONDS,
            $now,
        );

        if (null === $pace) {
            self::fail(\sprintf('A pace is computable at %d%% of a half-spent weekly window.', $percent));
        }

        return $pace;
    }
}
