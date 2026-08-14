<?php

declare(strict_types=1);

namespace App\Client\Settings;

/**
 * The settings GET /settings says the device currently holds.
 */
final readonly class SettingsSnapshot
{
    private function __construct(
        public ?int $brightness,
        public ?bool $autoRotate,
        public ?int $defaultDurationMilliseconds,
        public ?int $weatherDurationMilliseconds,
        public ?string $ntpServer,
        public ?string $ntpTimezonePosix,
    ) {
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    public static function fromResponseBody(array $decodedBody): self
    {
        $brightness = $decodedBody['brightness'] ?? null;
        $autoRotate = $decodedBody['autoRotate'] ?? null;
        $defaultDurationMilliseconds = $decodedBody['defaultDuration'] ?? null;
        $weatherDurationMilliseconds = $decodedBody['weatherDuration'] ?? null;

        $declaredNtp = $decodedBody['ntp'] ?? null;
        $ntp = \is_array($declaredNtp) ? $declaredNtp : [];
        $ntpServer = $ntp['server'] ?? null;
        $ntpTimezonePosix = $ntp['tz_posix'] ?? null;

        return new self(
            brightness: \is_int($brightness) ? $brightness : null,
            autoRotate: \is_bool($autoRotate) ? $autoRotate : null,
            defaultDurationMilliseconds: \is_int($defaultDurationMilliseconds) ? $defaultDurationMilliseconds : null,
            weatherDurationMilliseconds: \is_int($weatherDurationMilliseconds) ? $weatherDurationMilliseconds : null,
            ntpServer: \is_string($ntpServer) ? $ntpServer : null,
            ntpTimezonePosix: \is_string($ntpTimezonePosix) ? $ntpTimezonePosix : null,
        );
    }
}
