<?php

declare(strict_types=1);

namespace App\Tui\Scenarios;

final class ScenarioResultFormatter
{
    public static function format(ScenarioResult $result): string
    {
        return match ($result->kind) {
            ScenarioResultKind::Success => self::formatSuccess($result),
            ScenarioResultKind::ValidationFailure => 'VALIDATION '.$result->message,
            ScenarioResultKind::TransportFailure => null !== $result->httpStatus
                ? \sprintf('FAIL HTTP %d: %s', $result->httpStatus, $result->message)
                : 'FAIL '.$result->message,
            ScenarioResultKind::Unreachable => 'UNREACHABLE '.$result->message,
        };
    }

    private static function formatSuccess(ScenarioResult $result): string
    {
        $statusOnly = 'OK '.($result->httpStatus ?? 0);

        if ('' === $result->message || 'OK' === $result->message) {
            return $statusOnly;
        }

        return $statusOnly.': '.$result->message;
    }
}
