<?php

declare(strict_types=1);

namespace App\Tests\Client\Indicator;

use App\Client\Color;
use App\Client\Indicator\IndicatorMode;
use App\Client\Indicator\IndicatorPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndicatorPayloadTest extends TestCase
{
    /**
     * @return iterable<string, array{IndicatorMode, ?int, ?int}>
     */
    public static function rhythmOutsideItsModeProvider(): iterable
    {
        yield 'blink interval on a solid indicator' => [IndicatorMode::Solid, 500, null];
        yield 'blink interval on a fading indicator' => [IndicatorMode::Fade, 500, null];
        yield 'fade period on a solid indicator' => [IndicatorMode::Solid, null, 2000];
        yield 'fade period on a blinking indicator' => [IndicatorMode::Blink, null, 2000];
    }

    /**
     * @return iterable<string, array{IndicatorMode, ?int, ?int}>
     */
    public static function rhythmOutOfRangeProvider(): iterable
    {
        yield 'blink interval at zero' => [IndicatorMode::Blink, 0, null];
        yield 'negative blink interval' => [IndicatorMode::Blink, -500, null];
        yield 'fade period at zero' => [IndicatorMode::Fade, null, 0];
        yield 'negative fade period' => [IndicatorMode::Fade, null, -2000];
    }

    public function testSolidIndicatorCarriesOnlyItsModeAndColor(): void
    {
        $payload = IndicatorPayload::create(IndicatorMode::Solid, Color::fromHexCode('#00FF00'));

        self::assertSame(['mode' => 'solid', 'color' => '#00FF00'], $payload->toArray());
    }

    public function testBlinkingIndicatorCarriesItsInterval(): void
    {
        $payload = IndicatorPayload::create(IndicatorMode::Blink, Color::fromHexCode('#FF0000'), blinkIntervalMilliseconds: 250);

        self::assertSame(['mode' => 'blink', 'color' => '#FF0000', 'blinkInterval' => 250], $payload->toArray());
    }

    public function testFadingIndicatorCarriesItsPeriod(): void
    {
        $payload = IndicatorPayload::create(IndicatorMode::Fade, Color::fromHexCode('#00D4FF'), fadePeriodMilliseconds: 3000);

        self::assertSame(['mode' => 'fade', 'color' => '#00D4FF', 'fadePeriod' => 3000], $payload->toArray());
    }

    public function testOmittedRhythmLetsTheDeviceApplyItsOwnDefault(): void
    {
        $payload = IndicatorPayload::create(IndicatorMode::Blink, Color::fromHexCode('#FF0000'));

        self::assertSame(['mode' => 'blink', 'color' => '#FF0000'], $payload->toArray());
    }

    public function testTurnedOffIndicatorCarriesOnlyItsMode(): void
    {
        $payload = IndicatorPayload::create(IndicatorMode::Off);

        self::assertSame(['mode' => 'off'], $payload->toArray());
    }

    #[DataProvider('rhythmOutsideItsModeProvider')]
    public function testRhythmForeignToTheModeIsRefused(IndicatorMode $mode, ?int $blinkIntervalMilliseconds, ?int $fadePeriodMilliseconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only belongs to the/');

        IndicatorPayload::create(
            $mode,
            Color::fromHexCode('#FFFFFF'),
            blinkIntervalMilliseconds: $blinkIntervalMilliseconds,
            fadePeriodMilliseconds: $fadePeriodMilliseconds,
        );
    }

    #[DataProvider('rhythmOutOfRangeProvider')]
    public function testRhythmThatIsNotPositiveIsRefused(IndicatorMode $mode, ?int $blinkIntervalMilliseconds, ?int $fadePeriodMilliseconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/positive number of milliseconds/');

        IndicatorPayload::create(
            $mode,
            Color::fromHexCode('#FFFFFF'),
            blinkIntervalMilliseconds: $blinkIntervalMilliseconds,
            fadePeriodMilliseconds: $fadePeriodMilliseconds,
        );
    }

    public function testColorOnATurnedOffIndicatorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries no color/');

        IndicatorPayload::create(IndicatorMode::Off, Color::fromHexCode('#00FF00'));
    }

    public function testLitIndicatorWithoutAColorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a color/');

        IndicatorPayload::create(IndicatorMode::Solid);
    }
}
