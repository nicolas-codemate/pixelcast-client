<?php

declare(strict_types=1);

namespace App\Tui\Scenarios;

final readonly class ScenarioResult
{
    private function __construct(
        public bool $success,
        public ?int $httpStatus,
        public string $message,
        public ScenarioResultKind $kind,
    ) {
    }

    public static function success(int $httpStatus, string $message = 'OK'): self
    {
        return new self(success: true, httpStatus: $httpStatus, message: $message, kind: ScenarioResultKind::Success);
    }

    public static function validationFailure(string $message): self
    {
        return new self(success: false, httpStatus: null, message: $message, kind: ScenarioResultKind::ValidationFailure);
    }

    public static function transportFailure(string $message, ?int $httpStatus = null): self
    {
        return new self(success: false, httpStatus: $httpStatus, message: $message, kind: ScenarioResultKind::TransportFailure);
    }

    public static function unreachable(string $message): self
    {
        return new self(success: false, httpStatus: null, message: $message, kind: ScenarioResultKind::Unreachable);
    }
}
