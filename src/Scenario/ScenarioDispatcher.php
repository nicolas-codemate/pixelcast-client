<?php

declare(strict_types=1);

namespace App\Scenario;

use App\Client\DeviceBaseUrl;
use App\Scenario\Transport\ScenarioTransport;
use App\Scenario\Validation\OutboundPayloadValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ScenarioDispatcher
{
    /**
     * Endpoints exposed by the simulator but intentionally absent from
     * sync/openapi.yaml. Calls to these paths bypass schema validation
     * because no schema exists to validate against.
     */
    private const array PATHS_OUTSIDE_OPENAPI_SPEC = ['/__reset'];

    public function __construct(
        private OutboundPayloadValidator $validator,
        private ScenarioTransport $transport,
        private LoggerInterface $logger,
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private ?string $deviceBaseUrl = null,
    ) {
    }

    public function dispatch(ScenarioDefinition $scenario, ?string $baseUrlOverride = null): ScenarioResult
    {
        $result = $this->validateAndSend($scenario, $baseUrlOverride);

        if (!$result->success) {
            $this->logger->warning('Scenario dispatch failed', [
                'scenario' => $scenario->id,
                'kind' => $result->kind->value,
                'error' => $result->message,
            ]);
        }

        return $result;
    }

    private function validateAndSend(ScenarioDefinition $scenario, ?string $baseUrlOverride): ScenarioResult
    {
        if (!\in_array($scenario->path, self::PATHS_OUTSIDE_OPENAPI_SPEC, true)) {
            try {
                $validation = $this->validator->validate($scenario);
            } catch (\Throwable $validatorError) {
                return ScenarioResult::transportFailure('Validator error: '.$validatorError->getMessage());
            }

            if (!$validation->valid) {
                return ScenarioResult::validationFailure($validation->errorMessage ?? 'invalid payload');
            }
        }

        $url = $this->buildRequestUrl($scenario, $baseUrlOverride);

        try {
            return $this->transport->send($scenario->httpMethod, $url, $scenario->body);
        } catch (\Throwable $transportError) {
            return ScenarioResult::transportFailure('Transport error: '.$transportError->getMessage());
        }
    }

    private function buildRequestUrl(ScenarioDefinition $scenario, ?string $baseUrlOverride): string
    {
        $baseUrl = DeviceBaseUrl::resolve($baseUrlOverride ?? $this->deviceBaseUrl);
        $url = rtrim($baseUrl, '/').$scenario->path;

        if ([] !== $scenario->queryParams) {
            $url .= '?'.http_build_query($scenario->queryParams);
        }

        return $url;
    }
}
