<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Logging\RequestLog;
use App\Simulator\Logging\RequestLogEntry;
use App\Simulator\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class RequestLogRingBufferTest extends TestCase
{
    public function testCapAt50(): void
    {
        $log = new RequestLog();
        $now = new \DateTimeImmutable();

        for ($index = 1; $index <= 60; ++$index) {
            $log->record(new RequestLogEntry(
                method: 'POST',
                path: '/p'.$index,
                body: null,
                timestamp: $now,
                validationResult: ValidationResult::success(),
            ));
        }

        self::assertSame(50, $log->count());
        self::assertCount(50, $log->snapshotEntries());
    }

    public function testFirstNRemoved(): void
    {
        $log = new RequestLog();
        $now = new \DateTimeImmutable();

        for ($index = 1; $index <= 60; ++$index) {
            $log->record(new RequestLogEntry(
                method: 'POST',
                path: '/p'.$index,
                body: null,
                timestamp: $now,
                validationResult: ValidationResult::success(),
            ));
        }

        $entries = $log->snapshotEntries();
        $first = $entries[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('/p11', $first['path'] ?? null);

        $last = $entries[49] ?? null;
        self::assertIsArray($last);
        self::assertSame('/p60', $last['path'] ?? null);
    }
}
