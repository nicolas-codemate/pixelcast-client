<?php

declare(strict_types=1);

namespace App\Tui\Scenarios\Validation;

use App\Simulator\Validation\ValidationResult;
use App\Tui\Scenarios\DeviceBaseUrl;
use App\Tui\Scenarios\ScenarioDefinition;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\RequestValidator;
use Nyholm\Psr7\Request;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OutboundPayloadValidator
{
    public function __construct(
        private RequestValidator $validator,
        private StreamFactoryInterface $streamFactory,
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private ?string $deviceBaseUrl = null,
    ) {
    }

    public function validate(ScenarioDefinition $scenario): ValidationResult
    {
        $psrRequest = $this->buildPsrRequest($scenario);

        try {
            $this->validator->validate($psrRequest);
        } catch (ValidationFailed $validationFailed) {
            return ValidationResult::failure($this->formatErrorMessage($validationFailed));
        }

        return ValidationResult::success();
    }

    private function buildPsrRequest(ScenarioDefinition $scenario): Request
    {
        $baseUrl = DeviceBaseUrl::resolve($this->deviceBaseUrl);
        $uri = rtrim($baseUrl, '/').$scenario->path;

        if ([] !== $scenario->queryParams) {
            $uri .= '?'.http_build_query($scenario->queryParams);
        }

        $headers = [];
        $body = null;

        if (null !== $scenario->body) {
            $headers['Content-Type'] = 'application/json';
            $body = $this->streamFactory->createStream(
                json_encode($scenario->body, \JSON_THROW_ON_ERROR),
            );
        }

        return new Request($scenario->httpMethod, $uri, $headers, $body);
    }

    private function formatErrorMessage(ValidationFailed $validationFailed): string
    {
        $message = $validationFailed->getMessage();
        $previous = $validationFailed->getPrevious();

        if ($previous instanceof \Throwable) {
            return $message.'; cause: '.$previous->getMessage();
        }

        return $message;
    }
}
