<?php

declare(strict_types=1);

namespace App\Tui\Reachability;

final class DeviceReachabilityProbe
{
    public function __construct(
        private readonly float $timeoutSeconds = 0.5,
    ) {
    }

    public function probe(?string $baseUrl): DeviceReachabilityResult
    {
        if (null === $baseUrl || '' === $baseUrl) {
            return DeviceReachabilityResult::fromStatus(DeviceReachabilityStatus::Unknown);
        }

        $parsedUrl = parse_url($baseUrl);
        if (false === $parsedUrl || !isset($parsedUrl['host']) || '' === $parsedUrl['host']) {
            return DeviceReachabilityResult::fromStatus(DeviceReachabilityStatus::Unknown);
        }

        $host = $parsedUrl['host'];
        $port = $parsedUrl['port'] ?? ('https' === ($parsedUrl['scheme'] ?? '') ? 443 : 80);

        $errorNumber = 0;
        $errorMessage = '';

        // stream_socket_client emits a warning on connection failure; swap the
        // error handler instead of relying on @ so PHPStan strict rules stay happy.
        set_error_handler(static fn (): bool => true);
        try {
            $connection = stream_socket_client(
                \sprintf('tcp://%s:%d', $host, $port),
                $errorNumber,
                $errorMessage,
                $this->timeoutSeconds,
                \STREAM_CLIENT_CONNECT,
            );
        } finally {
            restore_error_handler();
        }

        if (false === $connection) {
            return DeviceReachabilityResult::fromStatus(DeviceReachabilityStatus::Unreachable);
        }

        fclose($connection);

        return DeviceReachabilityResult::fromStatus(DeviceReachabilityStatus::Reachable);
    }
}
