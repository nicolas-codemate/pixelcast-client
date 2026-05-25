<?php

declare(strict_types=1);

namespace App\Tui\ResetSim;

use App\Tui\Scenarios\DeviceBaseUrl;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\Transport\ScenarioTransport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ResetSimulatorAction
{
    private const string RESET_PATH = '/__reset';

    public function __construct(
        private ScenarioTransport $transport,
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private ?string $deviceBaseUrl = null,
    ) {
    }

    public function reset(): ScenarioResult
    {
        $baseUrl = DeviceBaseUrl::resolve($this->deviceBaseUrl);
        $url = rtrim($baseUrl, '/').self::RESET_PATH;

        try {
            return $this->transport->send('POST', $url, null);
        } catch (\Throwable $transportError) {
            return ScenarioResult::transportFailure('Transport error: '.$transportError->getMessage());
        }
    }
}
