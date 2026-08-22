<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\ActiveWindow;
use App\Config\Sync\ActiveWindowDay;
use App\Tests\Factory\ActiveWindowFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActiveWindowTest extends TestCase
{
    private const string PARENT_PATH = 'syncs.boursorama';

    public function testAGroupWithoutTheKeyHasNoWindow(): void
    {
        self::assertNull(ActiveWindow::optionalFromOptions([], self::PARENT_PATH));
        self::assertNull(ActiveWindow::optionalFromOptions(['activeWindow' => null], self::PARENT_PATH));
    }

    public function testADeclaredWindowKeepsItsDaysItsBoundsAndItsTimezone(): void
    {
        $activeWindow = ActiveWindowFactory::marketWindowIn('Europe/Paris');

        self::assertSame([ActiveWindowDay::Monday, ActiveWindowDay::Tuesday, ActiveWindowDay::Wednesday, ActiveWindowDay::Thursday, ActiveWindowDay::Friday], $activeWindow->days);
        self::assertSame(540, $activeWindow->fromMinuteOfDay);
        self::assertSame(1065, $activeWindow->toMinuteOfDay);
        self::assertSame('Europe/Paris', $activeWindow->timezone->getName());
    }

    public function testAWindowWithoutATimezoneFallsBackOnTheDeviceOne(): void
    {
        $activeWindow = self::windowOf(['from' => '09:00', 'to' => '17:45'], new \DateTimeZone('Europe/Paris'));

        self::assertSame('Europe/Paris', $activeWindow->timezone->getName());
    }

    public function testADeclaredTimezoneWinsOverTheDeviceOne(): void
    {
        $activeWindow = self::windowOf(['from' => '09:00', 'to' => '17:45', 'timezone' => 'America/New_York'], new \DateTimeZone('Europe/Paris'));

        self::assertSame('America/New_York', $activeWindow->timezone->getName());
    }

    public function testAWindowWithoutAnyTimezoneAtAllIsRefusedAndNamesBothKeys(): void
    {
        try {
            self::windowOf(['from' => '09:00', 'to' => '17:45']);
        } catch (PixelCastConfigException $rejection) {
            self::assertStringContainsString('syncs.boursorama.activeWindow.timezone', $rejection->getMessage());
            self::assertStringContainsString('device.timezone', $rejection->getMessage());

            return;
        }

        self::fail('A window without any timezone at all was accepted.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideInstantsAgainstAWeekdayWindow(): iterable
    {
        yield 'monday inside' => ['2026-08-03 10:00:00 UTC', true];
        yield 'monday before opening' => ['2026-08-03 08:59:59 UTC', false];
        yield 'monday exactly at the opening bound' => ['2026-08-03 09:00:00 UTC', true];
        yield 'monday exactly at the closing bound' => ['2026-08-03 17:45:00 UTC', true];
        yield 'friday after closing' => ['2026-08-07 18:00:00 UTC', false];
        yield 'saturday' => ['2026-08-08 10:00:00 UTC', false];
    }

    #[DataProvider('provideInstantsAgainstAWeekdayWindow')]
    public function testAnInstantIsInsideTheWindowOnlyOnADeclaredDayBetweenTheTwoBounds(string $rawInstant, bool $expectedToBeInside): void
    {
        $activeWindow = ActiveWindowFactory::marketWindowIn('UTC');

        self::assertSame($expectedToBeInside, $activeWindow->contains(self::instantAt($rawInstant)));
    }

    public function testAnInstantIsJudgedInTheDeclaredTimezoneNotInUtc(): void
    {
        $activeWindow = ActiveWindowFactory::marketWindowIn('Europe/Paris');

        self::assertTrue($activeWindow->contains(self::instantAt('2026-08-03 08:00:00 UTC')));
        self::assertFalse($activeWindow->contains(self::instantAt('2026-08-03 06:00:00 UTC')));
    }

    public function testTheOpeningAfterFridayEveningIsTheFollowingMonday(): void
    {
        $activeWindow = ActiveWindowFactory::marketWindowIn('Europe/Paris');

        $nextOpening = $activeWindow->nextOpeningAfter(self::instantAt('2026-08-07 18:00:00 Europe/Paris'));

        self::assertSame('2026-08-10 09:00:00 Europe/Paris', $nextOpening->format('Y-m-d H:i:s e'));
    }

    public function testTheOpeningAfterAnInstantBeforeTheOpeningIsTheSameDay(): void
    {
        $activeWindow = ActiveWindowFactory::marketWindowIn('Europe/Paris');

        $nextOpening = $activeWindow->nextOpeningAfter(self::instantAt('2026-08-03 07:30:00 Europe/Paris'));

        self::assertSame('2026-08-03 09:00:00 Europe/Paris', $nextOpening->format('Y-m-d H:i:s e'));
    }

    public function testSecondsSinceOpeningCountsFromTheFromTimeOfTheDay(): void
    {
        $activeWindow = ActiveWindowFactory::marketWindowIn('Europe/Paris');

        self::assertSame(300, $activeWindow->secondsSinceOpening(self::instantAt('2026-08-03 09:05:00 Europe/Paris')));
        self::assertSame(0, $activeWindow->secondsSinceOpening(self::instantAt('2026-08-03 07:30:00 Europe/Paris')));
    }

    public function testAWindowWithoutDaysCoversEveryDay(): void
    {
        $activeWindow = self::windowOf(['from' => '09:00', 'to' => '17:45', 'timezone' => 'UTC']);

        self::assertSame(ActiveWindowDay::cases(), $activeWindow->days);
        self::assertTrue($activeWindow->contains(self::instantAt('2026-08-08 10:00:00 UTC')));
        self::assertTrue($activeWindow->contains(self::instantAt('2026-08-09 10:00:00 UTC')));
    }

    public function testTheStringFormNamesTheDaysTheBoundsAndTheTimezone(): void
    {
        self::assertSame('mon,tue,wed,thu,fri 09:00-17:45 Europe/Paris', (string) ActiveWindowFactory::marketWindowIn('Europe/Paris'));
    }

    public function testAWindowSpanningMidnightIsRefusedAndSaysWhy(): void
    {
        try {
            self::windowOf(['from' => '22:00', 'to' => '06:00', 'timezone' => 'Europe/Paris']);
        } catch (PixelCastConfigException $rejection) {
            self::assertStringContainsString('syncs.boursorama.activeWindow.to', $rejection->getMessage());
            self::assertStringContainsString('a window spanning midnight is not supported', $rejection->getMessage());

            return;
        }

        self::fail('A window closing before it opens was accepted.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideRejectedWindows(): iterable
    {
        yield 'opening that is not a time of day' => [['from' => '9h', 'to' => '17:45', 'timezone' => 'UTC'], 'syncs.boursorama.activeWindow.from'];
        yield 'closing beyond the last hour of the day' => [['from' => '09:00', 'to' => '24:00', 'timezone' => 'UTC'], 'syncs.boursorama.activeWindow.to'];
        yield 'closing equal to the opening' => [['from' => '09:00', 'to' => '09:00', 'timezone' => 'UTC'], 'syncs.boursorama.activeWindow.to'];
        yield 'missing timezone' => [['from' => '09:00', 'to' => '17:45'], 'syncs.boursorama.activeWindow.timezone'];
        yield 'unknown timezone' => [['from' => '09:00', 'to' => '17:45', 'timezone' => 'Europe/Atlantis'], 'syncs.boursorama.activeWindow.timezone'];
        yield 'unknown day' => [['days' => ['mon', 'funday'], 'from' => '09:00', 'to' => '17:45', 'timezone' => 'UTC'], 'syncs.boursorama.activeWindow.days[1]'];
        yield 'empty list of days' => [['days' => [], 'from' => '09:00', 'to' => '17:45', 'timezone' => 'UTC'], 'syncs.boursorama.activeWindow.days'];
        yield 'days written as a map' => [['days' => ['mon' => true], 'from' => '09:00', 'to' => '17:45', 'timezone' => 'UTC'], 'syncs.boursorama.activeWindow.days'];
    }

    /**
     * @param array<string, mixed> $windowOptions
     */
    #[DataProvider('provideRejectedWindows')]
    public function testARejectedWindowNamesTheFaultyKey(array $windowOptions, string $expectedMessageFragment): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        self::windowOf($windowOptions);
    }

    /**
     * @param array<string, mixed> $windowOptions
     */
    private static function windowOf(array $windowOptions, ?\DateTimeZone $deviceTimezone = null): ActiveWindow
    {
        $activeWindow = ActiveWindow::optionalFromOptions(['activeWindow' => $windowOptions], self::PARENT_PATH, $deviceTimezone);
        self::assertNotNull($activeWindow);

        return $activeWindow;
    }

    private static function instantAt(string $rawInstant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($rawInstant);
    }
}
