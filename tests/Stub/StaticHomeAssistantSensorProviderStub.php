<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Client\CustomApp\CustomAppPayload;
use App\Provider\HomeAssistant\HomeAssistantSensorProviderInterface;

final class StaticHomeAssistantSensorProviderStub implements HomeAssistantSensorProviderInterface
{
    public function __construct(
        private readonly ?CustomAppPayload $customApp = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function fetchSensorsCustomApp(): ?CustomAppPayload
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->customApp;
    }
}
