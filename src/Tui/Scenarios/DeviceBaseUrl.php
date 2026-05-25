<?php

declare(strict_types=1);

namespace App\Tui\Scenarios;

final class DeviceBaseUrl
{
    public const string DEFAULT = 'http://simulator:8080';

    public static function resolve(?string $configured): string
    {
        if (null === $configured || '' === $configured) {
            return self::DEFAULT;
        }

        return $configured;
    }
}
