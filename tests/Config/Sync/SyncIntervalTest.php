<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncIntervalTest extends TestCase
{
    private const string PARENT_PATH = 'syncs.weather';
    private const string OPTION_PATH = self::PARENT_PATH.'.interval';

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedIntervals(): iterable
    {
        yield 'minutes' => ['30 minutes'];
        yield 'hours' => ['1 hour'];
        yield 'ISO 8601 duration' => ['PT30M'];
        yield 'bare number of seconds' => ['900'];
        yield 'exactly the minimum' => ['60 seconds'];
    }

    #[DataProvider('provideAcceptedIntervals')]
    public function testAnIntervalTheSchedulerUnderstandsIsKeptAsWritten(string $rawInterval): void
    {
        $interval = SyncInterval::fromOptions(['interval' => $rawInterval], self::PARENT_PATH);

        self::assertSame($rawInterval, $interval->expression);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRejectedIntervals(): iterable
    {
        yield 'unknown expression' => ['every fortnight', 'expected an interval the scheduler understands'];
        yield 'empty string' => ['', 'must not be empty'];
        yield 'bare zero' => ['0', 'expected an interval the scheduler understands'];
        yield 'zero seconds' => ['0 seconds', 'expected an interval the scheduler understands'];
        yield 'zero minutes' => ['0 minutes', 'expected an interval the scheduler understands'];
        yield 'one second' => ['1 second', 'expected an interval of at least 60 seconds'];
        yield 'below the minimum' => ['30 seconds', 'expected an interval of at least 60 seconds'];
    }

    #[DataProvider('provideRejectedIntervals')]
    public function testARejectedIntervalNamesTheOptionAndTheReason(string $rawInterval, string $expectedReason): void
    {
        try {
            SyncInterval::fromOptions(['interval' => $rawInterval], self::PARENT_PATH);
        } catch (PixelCastConfigException $rejection) {
            self::assertStringContainsString(self::OPTION_PATH, $rejection->getMessage());
            self::assertStringContainsString($expectedReason, $rejection->getMessage());
            self::assertStringContainsString($rawInterval, $rejection->getMessage());

            return;
        }

        self::fail(\sprintf('The interval "%s" was accepted.', $rawInterval));
    }

    public function testAnIntervalTheSchedulerFailsOnLateKeepsItsOriginalError(): void
    {
        try {
            SyncInterval::fromOptions(['interval' => '0 seconds'], self::PARENT_PATH);
        } catch (PixelCastConfigException $rejection) {
            self::assertInstanceOf(\Error::class, $rejection->getPrevious());

            return;
        }

        self::fail('The interval "0 seconds" was accepted.');
    }
}
