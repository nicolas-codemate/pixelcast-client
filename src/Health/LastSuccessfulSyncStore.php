<?php

declare(strict_types=1);

namespace App\Health;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

final readonly class LastSuccessfulSyncStore
{
    private const string CACHE_KEY_PREFIX = 'last_successful_sync.';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function recordSuccess(string $syncType): void
    {
        $recordedSuccess = $this->cache->getItem(self::CACHE_KEY_PREFIX.$syncType);
        $recordedSuccess->set($this->clock->now()->getTimestamp());

        $this->cache->save($recordedSuccess);
    }

    public function lastSuccessAt(string $syncType): ?\DateTimeImmutable
    {
        $recordedTimestamp = $this->cache->getItem(self::CACHE_KEY_PREFIX.$syncType)->get();

        return \is_int($recordedTimestamp) ? new \DateTimeImmutable('@'.$recordedTimestamp) : null;
    }
}
