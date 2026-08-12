<?php

declare(strict_types=1);

namespace App\Config\Sleep;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncOptionReader;

/**
 * A stretch of the day the device keeps its panel off, both bounds written as HH:MM.
 *
 * A "to" earlier than "from" is accepted here and runs past midnight, the opposite of ActiveWindow:
 * the firmware reads a slot ending before it starts as spilling into the next day, whereas an
 * active window is meant to be written in the timezone of the market, where it never crosses midnight.
 */
final readonly class SleepWindow implements \Stringable
{
    private const string FROM_OPTION_KEY = 'from';
    private const string TO_OPTION_KEY = 'to';

    private function __construct(
        public string $fromTimeOfDay,
        public string $toTimeOfDay,
    ) {
    }

    /**
     * @param array<string, mixed> $options the options of the single window entry
     */
    public static function fromOptions(array $options, string $parentPath): self
    {
        $fromTimeOfDay = SyncOptionReader::requireTimeOfDay($options, self::FROM_OPTION_KEY, $parentPath);
        $toTimeOfDay = SyncOptionReader::requireTimeOfDay($options, self::TO_OPTION_KEY, $parentPath);

        if ($fromTimeOfDay === $toTimeOfDay) {
            throw PixelCastConfigException::invalidValue($parentPath.'.'.self::TO_OPTION_KEY, \sprintf('expected a time other than "%s": a window opening and closing at the same minute covers nothing', $fromTimeOfDay));
        }

        return new self($fromTimeOfDay, $toTimeOfDay);
    }

    public function __toString(): string
    {
        return $this->fromTimeOfDay.'-'.$this->toTimeOfDay;
    }
}
