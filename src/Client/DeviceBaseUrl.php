<?php

declare(strict_types=1);

namespace App\Client;

final class DeviceBaseUrl
{
    public const string DEFAULT = 'http://simulator:8080/api';

    public static function resolve(?string $configured): string
    {
        if (null === $configured || '' === $configured) {
            return self::DEFAULT;
        }

        return $configured;
    }
}
