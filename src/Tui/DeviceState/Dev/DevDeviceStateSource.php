<?php

declare(strict_types=1);

namespace App\Tui\DeviceState\Dev;

use App\Domain\AppDomain;
use App\Tui\DeviceState\DeviceDomainState;
use App\Tui\DeviceState\DeviceStateSource;
use App\Tui\Inspector\InspectorPoller;

final class DevDeviceStateSource implements DeviceStateSource
{
    public function __construct(private readonly InspectorPoller $poller)
    {
    }

    public function refresh(float $deltaSeconds): bool
    {
        return $this->poller->pollIfDue($deltaSeconds);
    }

    public function snapshot(): array
    {
        $result = [];
        foreach (AppDomain::cases() as $domain) {
            $result[$domain->value] = $this->getDomainState($domain);
        }

        return $result;
    }

    public function getDomainState(AppDomain $domain): DeviceDomainState
    {
        $snapshot = $this->poller->getLatestSnapshot();
        if (null === $snapshot || false === $snapshot->reachable || null === $snapshot->state) {
            return new DeviceDomainState(false, null);
        }

        $payload = $snapshot->state[$domain->value] ?? null;
        if (null === $payload) {
            return new DeviceDomainState(false, null);
        }

        $hasData = match ($domain) {
            AppDomain::Weather => $this->hasWeatherData($payload),
            AppDomain::Trackers => $this->hasNonEmptyCollection($payload, 'trackers'),
            AppDomain::Notifications => $this->hasNonEmptyCollection($payload, 'queue'),
            AppDomain::CustomApps => $this->hasNonEmptyCollection($payload, 'apps'),
            AppDomain::Indicators => $this->hasAnyIndicatorSlot($payload),
            AppDomain::Icons => $this->hasNonEmptyCollection($payload, 'icons'),
        };

        return new DeviceDomainState($hasData, $payload);
    }

    private function hasWeatherData(mixed $payload): bool
    {
        return \is_array($payload) && null !== ($payload['current'] ?? null);
    }

    private function hasNonEmptyCollection(mixed $payload, string $collectionKey): bool
    {
        if (!\is_array($payload)) {
            return false;
        }
        $collection = $payload[$collectionKey] ?? null;

        return \is_array($collection) && [] !== $collection;
    }

    private function hasAnyIndicatorSlot(mixed $payload): bool
    {
        if (!\is_array($payload)) {
            return false;
        }
        foreach (['slot1', 'slot2', 'slot3'] as $slotKey) {
            if (null !== ($payload[$slotKey] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
