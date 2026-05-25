<?php

declare(strict_types=1);

namespace App\Tui\SyncNow;

final readonly class SyncNowResult
{
    private function __construct(
        public SyncNowResultKind $kind,
        public string $message,
    ) {
    }

    public static function dispatched(string $messageClass): self
    {
        return new self(SyncNowResultKind::Dispatched, $messageClass);
    }

    public static function notWired(string $messageClass): self
    {
        return new self(SyncNowResultKind::NotWired, $messageClass);
    }

    public static function dispatchError(string $errorMessage): self
    {
        return new self(SyncNowResultKind::DispatchError, $errorMessage);
    }
}
