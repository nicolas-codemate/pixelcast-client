<?php

declare(strict_types=1);

namespace App\Tests\Tui\Reachability;

use App\Tui\Reachability\DeviceReachabilityProbe;
use App\Tui\Reachability\DeviceReachabilityResult;
use App\Tui\Reachability\DeviceReachabilityStatus;
use PHPUnit\Framework\TestCase;

final class DeviceReachabilityProbeTest extends TestCase
{
    public function testProbeWithNullBaseUrlReturnsUnknown(): void
    {
        $probe = new DeviceReachabilityProbe();

        $result = $probe->probe(null);

        self::assertSame(DeviceReachabilityStatus::Unknown, $result->status);
        self::assertSame('Unknown', $result->displayLabel);
    }

    public function testProbeWithEmptyBaseUrlReturnsUnknown(): void
    {
        $probe = new DeviceReachabilityProbe();

        $result = $probe->probe('');

        self::assertSame(DeviceReachabilityStatus::Unknown, $result->status);
    }

    public function testProbeWithMalformedUrlReturnsUnknown(): void
    {
        $probe = new DeviceReachabilityProbe();

        $result = $probe->probe('not a url');

        self::assertSame(DeviceReachabilityStatus::Unknown, $result->status);
    }

    public function testProbeWithUnreachableHostReturnsUnreachable(): void
    {
        $probe = new DeviceReachabilityProbe();

        // TEST-NET-1 (RFC 5737): 192.0.2.0/24 is reserved for documentation and
        // is guaranteed not to route, so the connect attempt always fails fast
        // within the default 0.5s timeout regardless of the runner's network.
        $result = $probe->probe('http://192.0.2.1:80');

        self::assertSame(DeviceReachabilityStatus::Unreachable, $result->status);
        self::assertSame('Unreachable', $result->displayLabel);
    }

    public function testDefaultDisplayLabelsMatchEachStatus(): void
    {
        $expectedLabels = [
            DeviceReachabilityStatus::Reachable->value => 'Reachable',
            DeviceReachabilityStatus::Unreachable->value => 'Unreachable',
            DeviceReachabilityStatus::Unknown->value => 'Unknown',
        ];

        foreach (DeviceReachabilityStatus::cases() as $status) {
            $result = DeviceReachabilityResult::fromStatus($status);

            self::assertSame($status, $result->status);
            self::assertSame($expectedLabels[$status->value], $result->displayLabel);
        }
    }
}
