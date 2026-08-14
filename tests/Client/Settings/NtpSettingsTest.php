<?php

declare(strict_types=1);

namespace App\Tests\Client\Settings;

use App\Client\Settings\NtpSettings;
use PHPUnit\Framework\TestCase;

final class NtpSettingsTest extends TestCase
{
    public function testToArrayEmitsBothFieldsWhenProvided(): void
    {
        $ntp = NtpSettings::create(server: 'pool.ntp.org', timezonePosix: 'CET-1CEST,M3.5.0,M10.5.0/3');

        self::assertSame(
            ['server' => 'pool.ntp.org', 'tz_posix' => 'CET-1CEST,M3.5.0,M10.5.0/3'],
            $ntp->toArray(),
        );
    }

    public function testToArrayEmitsTheServerAlone(): void
    {
        $ntp = NtpSettings::create(server: 'pool.ntp.org');

        self::assertSame(['server' => 'pool.ntp.org'], $ntp->toArray());
    }

    public function testToArrayEmitsTheTimezoneAlone(): void
    {
        $ntp = NtpSettings::create(timezonePosix: 'UTC0');

        self::assertSame(['tz_posix' => 'UTC0'], $ntp->toArray());
    }

    public function testCreateRejectsAnEmptyNode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An NTP update must carry a server, a POSIX timezone, or both.');

        NtpSettings::create();
    }

    public function testCreateRejectsAnEmptyServer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An NTP server must not be empty.');

        NtpSettings::create(server: '');
    }

    public function testCreateAcceptsATimezoneOfMaximumLength(): void
    {
        $timezonePosix = str_repeat('T', 63);

        $ntp = NtpSettings::create(timezonePosix: $timezonePosix);

        self::assertSame($timezonePosix, $ntp->timezonePosix);
    }

    public function testCreateRejectsATooLongTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A POSIX timezone holds at most 63 characters, got 64.');

        NtpSettings::create(timezonePosix: str_repeat('T', 64));
    }
}
