<?php

declare(strict_types=1);

namespace App\Client\Settings;

final readonly class NtpSettings
{
    public const int MAXIMUM_TIMEZONE_LENGTH = 63;

    private function __construct(
        public ?string $server,
        public ?string $timezonePosix,
    ) {
    }

    public static function create(?string $server = null, ?string $timezonePosix = null): self
    {
        if (null === $server && null === $timezonePosix) {
            throw new \InvalidArgumentException('An NTP update must carry a server, a POSIX timezone, or both.');
        }

        if ('' === $server) {
            throw new \InvalidArgumentException('An NTP server must not be empty.');
        }

        if (null !== $timezonePosix && mb_strlen($timezonePosix) > self::MAXIMUM_TIMEZONE_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A POSIX timezone holds at most %d characters, got %d.', self::MAXIMUM_TIMEZONE_LENGTH, mb_strlen($timezonePosix)));
        }

        return new self(
            server: $server,
            timezonePosix: $timezonePosix,
        );
    }

    /**
     * @return array{server?: string, tz_posix?: string}
     */
    public function toArray(): array
    {
        $payload = [];

        if (null !== $this->server) {
            $payload['server'] = $this->server;
        }

        if (null !== $this->timezonePosix) {
            $payload['tz_posix'] = $this->timezonePosix;
        }

        return $payload;
    }
}
