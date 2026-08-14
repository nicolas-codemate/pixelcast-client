<?php

declare(strict_types=1);

namespace App\Tests\Client\Settings;

use App\Client\Settings\SettingsSnapshot;
use PHPUnit\Framework\TestCase;

final class SettingsSnapshotTest extends TestCase
{
    public function testAFullBodyIsReadIncludingItsNtpNode(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody([
            'brightness' => 128,
            'autoRotate' => true,
            'defaultDuration' => 10000,
            'weatherDuration' => 12000,
            'display' => ['width' => 64, 'height' => 8],
            'ntp' => ['server' => 'pool.ntp.org', 'tz_posix' => 'CET-1CEST,M3.5.0,M10.5.0/3'],
            'mqtt' => ['enabled' => false, 'prefix' => 'pixelcast'],
        ]);

        self::assertSame(128, $settingsSnapshot->brightness);
        self::assertTrue($settingsSnapshot->autoRotate);
        self::assertSame(10000, $settingsSnapshot->defaultDurationMilliseconds);
        self::assertSame(12000, $settingsSnapshot->weatherDurationMilliseconds);
        self::assertSame('pool.ntp.org', $settingsSnapshot->ntpServer);
        self::assertSame('CET-1CEST,M3.5.0,M10.5.0/3', $settingsSnapshot->ntpTimezonePosix);
    }

    public function testTheThreeKeysTheSimulatorReallyServesAreEnough(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody([
            'brightness' => 128,
            'autoRotate' => true,
            'defaultDuration' => 10000,
        ]);

        self::assertSame(128, $settingsSnapshot->brightness);
        self::assertTrue($settingsSnapshot->autoRotate);
        self::assertSame(10000, $settingsSnapshot->defaultDurationMilliseconds);
        self::assertNull($settingsSnapshot->weatherDurationMilliseconds);
        self::assertNull($settingsSnapshot->ntpServer);
        self::assertNull($settingsSnapshot->ntpTimezonePosix);
    }

    public function testAnEmptyBodyLeavesEveryFieldEmpty(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody([]);

        self::assertNull($settingsSnapshot->brightness);
        self::assertNull($settingsSnapshot->autoRotate);
        self::assertNull($settingsSnapshot->defaultDurationMilliseconds);
        self::assertNull($settingsSnapshot->weatherDurationMilliseconds);
        self::assertNull($settingsSnapshot->ntpServer);
        self::assertNull($settingsSnapshot->ntpTimezonePosix);
    }

    public function testAutoRotateDisabledIsKeptAndNotReadAsAnAbsence(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody(['autoRotate' => false, 'brightness' => 0]);

        self::assertFalse($settingsSnapshot->autoRotate);
        self::assertSame(0, $settingsSnapshot->brightness);
    }

    public function testFieldsOfAnUnexpectedTypeAreDroppedWithoutLosingTheOthers(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody([
            'brightness' => '128',
            'autoRotate' => 1,
            'defaultDuration' => 10000,
            'weatherDuration' => null,
        ]);

        self::assertNull($settingsSnapshot->brightness);
        self::assertNull($settingsSnapshot->autoRotate);
        self::assertSame(10000, $settingsSnapshot->defaultDurationMilliseconds);
        self::assertNull($settingsSnapshot->weatherDurationMilliseconds);
    }

    public function testAnNtpNodeOfAnUnexpectedTypeLeavesBothNtpFieldsEmpty(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody([
            'brightness' => 200,
            'ntp' => 'pool.ntp.org',
        ]);

        self::assertSame(200, $settingsSnapshot->brightness);
        self::assertNull($settingsSnapshot->ntpServer);
        self::assertNull($settingsSnapshot->ntpTimezonePosix);
    }

    public function testAPartialNtpNodeKeepsWhatItCarries(): void
    {
        $settingsSnapshot = SettingsSnapshot::fromResponseBody([
            'ntp' => ['tz_posix' => 'UTC0'],
        ]);

        self::assertNull($settingsSnapshot->ntpServer);
        self::assertSame('UTC0', $settingsSnapshot->ntpTimezonePosix);
    }
}
