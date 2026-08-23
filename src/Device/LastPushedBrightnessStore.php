<?php

declare(strict_types=1);

namespace App\Device;

use App\Client\Settings\BrightnessLevel;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The level the panel is known to hold, shared by every writer of the brightness so that none of
 * them leaves the others believing the panel is somewhere it no longer is. The scheduler consumer
 * and app:device:settings run in two PHP processes, hence a cache pool rather than a property.
 */
final readonly class LastPushedBrightnessStore
{
    private const string CACHE_KEY = 'last_pushed_brightness';

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function recordPushedLevel(BrightnessLevel $brightness): void
    {
        $recordedLevel = $this->cache->getItem(self::CACHE_KEY);
        $recordedLevel->set($brightness->level);

        $this->cache->save($recordedLevel);
    }

    /**
     * @return int|null null when no level was ever pushed, so nothing is known of the panel
     */
    public function lastPushedLevel(): ?int
    {
        $recordedLevel = $this->cache->getItem(self::CACHE_KEY)->get();

        return \is_int($recordedLevel) ? $recordedLevel : null;
    }
}
