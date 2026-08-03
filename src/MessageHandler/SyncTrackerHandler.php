<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncOutcome;
use App\Message\SyncTrackerMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncTrackerHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncTrackerMessage $message): SyncOutcome
    {
        $this->logger->notice(
            'Tracker sync skipped, no provider is implemented for this sync type yet',
            ['sync_type' => $message->syncType],
        );

        return SyncOutcome::Skipped;
    }
}
