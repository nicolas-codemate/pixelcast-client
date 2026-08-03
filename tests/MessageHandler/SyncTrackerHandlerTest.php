<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use App\MessageHandler\SyncTrackerHandler;
use App\Tests\Stub\RecordingLoggerStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class SyncTrackerHandlerTest extends TestCase
{
    public function testTrackerSyncIsSkippedAndReportedAsANotice(): void
    {
        $logger = new RecordingLoggerStub();
        $handler = new SyncTrackerHandler($logger);

        $outcome = $handler(new SyncTrackerMessage('coingecko'));

        self::assertSame(SyncOutcome::Skipped, $outcome);
        self::assertSame(LogLevel::NOTICE, $logger->records[0]['level'] ?? null);
        self::assertSame('coingecko', $logger->records[0]['context']['sync_type'] ?? null);
    }

    public function testTheLoggedSyncTypeComesFromTheMessage(): void
    {
        $logger = new RecordingLoggerStub();
        $handler = new SyncTrackerHandler($logger);

        $handler(new SyncTrackerMessage('twelvedata'));

        self::assertSame('twelvedata', $logger->records[0]['context']['sync_type'] ?? null);
    }
}
