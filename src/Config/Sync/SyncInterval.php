<?php

declare(strict_types=1);

namespace App\Config\Sync;

use App\Config\Exception\PixelCastConfigException;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final readonly class SyncInterval
{
    private const int MINIMUM_INTERVAL_IN_SECONDS = 60;
    private const string MEASUREMENT_REFERENCE_DATE = '2000-01-01 00:00:00 UTC';
    private const string UNUSABLE_INTERVAL_REASON = 'expected an interval the scheduler understands, got "%s"';

    private function __construct(
        public string $expression,
    ) {
    }

    public static function fromOption(string $rawInterval, string $optionPath): self
    {
        $reference = new \DateTimeImmutable(self::MEASUREMENT_REFERENCE_DATE);

        try {
            $trigger = new PeriodicalTrigger($rawInterval);
            $nextRun = $trigger->getNextRunDate($reference);
        } catch (\Throwable $unusableInterval) {
            // "0 seconds" is accepted at construction and only fails as a raw Error on getNextRunDate(),
            // so both calls must be guarded to always name the faulty key instead of leaking that error.
            throw PixelCastConfigException::invalidValue($optionPath, \sprintf(self::UNUSABLE_INTERVAL_REASON, $rawInterval), $unusableInterval);
        }

        if (null === $nextRun) {
            throw PixelCastConfigException::invalidValue($optionPath, \sprintf(self::UNUSABLE_INTERVAL_REASON, $rawInterval));
        }

        // PeriodicalTrigger does not expose its interval: the distance to the next run is the only way to measure it.
        $intervalInSeconds = (float) $nextRun->format('U.u') - (float) $reference->format('U.u');
        if ($intervalInSeconds < self::MINIMUM_INTERVAL_IN_SECONDS) {
            throw PixelCastConfigException::invalidValue($optionPath, \sprintf('expected an interval of at least %d seconds, got "%s"', self::MINIMUM_INTERVAL_IN_SECONDS, $rawInterval));
        }

        return new self($rawInterval);
    }
}
