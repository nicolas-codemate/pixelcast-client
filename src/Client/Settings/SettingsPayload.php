<?php

declare(strict_types=1);

namespace App\Client\Settings;

final readonly class SettingsPayload
{
    private const int MINIMUM_WEATHER_DURATION_MILLISECONDS = 3000;
    private const int MAXIMUM_WEATHER_DURATION_MILLISECONDS = 60000;

    private function __construct(
        public ?BrightnessLevel $brightness,
        public ?bool $autoRotate,
        public ?int $defaultDurationMilliseconds,
        public ?int $weatherDurationMilliseconds,
        public ?NtpSettings $ntp,
    ) {
    }

    public static function create(
        ?BrightnessLevel $brightness = null,
        ?bool $autoRotate = null,
        ?int $defaultDurationMilliseconds = null,
        ?int $weatherDurationMilliseconds = null,
        ?NtpSettings $ntp = null,
    ): self {
        $everyFieldIsAbsent = null === $brightness
            && null === $autoRotate
            && null === $defaultDurationMilliseconds
            && null === $weatherDurationMilliseconds
            && null === $ntp;

        if ($everyFieldIsAbsent) {
            throw new \InvalidArgumentException('A settings update must carry at least one field.');
        }

        self::assertWeatherDurationWithinBounds($weatherDurationMilliseconds);

        return new self(
            brightness: $brightness,
            autoRotate: $autoRotate,
            defaultDurationMilliseconds: $defaultDurationMilliseconds,
            weatherDurationMilliseconds: $weatherDurationMilliseconds,
            ntp: $ntp,
        );
    }

    /**
     * @return array{brightness?: int, autoRotate?: bool, defaultDuration?: int, weatherDuration?: int, ntp?: array{server?: string, tz_posix?: string}}
     */
    public function toArray(): array
    {
        $payload = [];

        if (null !== $this->brightness) {
            $payload['brightness'] = $this->brightness->level;
        }

        if (null !== $this->autoRotate) {
            $payload['autoRotate'] = $this->autoRotate;
        }

        if (null !== $this->defaultDurationMilliseconds) {
            $payload['defaultDuration'] = $this->defaultDurationMilliseconds;
        }

        if (null !== $this->weatherDurationMilliseconds) {
            $payload['weatherDuration'] = $this->weatherDurationMilliseconds;
        }

        if (null !== $this->ntp) {
            $payload['ntp'] = $this->ntp->toArray();
        }

        return $payload;
    }

    private static function assertWeatherDurationWithinBounds(?int $weatherDurationMilliseconds): void
    {
        if (null === $weatherDurationMilliseconds) {
            return;
        }

        // The weather app splits this duration between its own carousel pages, so it needs more
        // room than a plain app and refuses a value that would make its pages unreadable.
        if ($weatherDurationMilliseconds < self::MINIMUM_WEATHER_DURATION_MILLISECONDS || $weatherDurationMilliseconds > self::MAXIMUM_WEATHER_DURATION_MILLISECONDS) {
            throw new \InvalidArgumentException(\sprintf('The weather duration must be between %d and %d milliseconds, got %d.', self::MINIMUM_WEATHER_DURATION_MILLISECONDS, self::MAXIMUM_WEATHER_DURATION_MILLISECONDS, $weatherDurationMilliseconds));
        }
    }
}
