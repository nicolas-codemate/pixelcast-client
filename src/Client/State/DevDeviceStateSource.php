<?php

declare(strict_types=1);

namespace App\Client\State;

use App\Client\Inspector\InspectorSnapshot;
use App\Client\Inspector\InspectorTransport;
use App\Domain\AppDomain;

final class DevDeviceStateSource implements DeviceStateSource
{
    private ?InspectorSnapshot $fetchedSnapshot = null;

    public function __construct(
        private readonly InspectorTransport $transport,
        private readonly ?string $baseUrl,
    ) {
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
        $snapshot = $this->inspectorSnapshot();
        if (false === $snapshot->reachable || null === $snapshot->state) {
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

    public function reachabilityError(): ?string
    {
        $snapshot = $this->inspectorSnapshot();

        return $snapshot->reachable ? null : ($snapshot->errorMessage ?? 'unreachable');
    }

    private function inspectorSnapshot(): InspectorSnapshot
    {
        return $this->fetchedSnapshot ??= $this->transport->fetch($this->baseUrl);
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
