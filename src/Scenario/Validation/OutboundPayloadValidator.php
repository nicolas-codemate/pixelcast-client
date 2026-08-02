<?php

declare(strict_types=1);

namespace App\Scenario\Validation;

use App\Client\DeviceBaseUrl;
use App\Scenario\ScenarioDefinition;
use App\Simulator\Validation\ValidationResult;
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
        return $this->validateRequest($scenario->httpMethod, $scenario->path, $scenario->queryParams, $scenario->body);
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, mixed>|null $body
     */
    public function validateRequest(string $httpMethod, string $path, array $queryParams = [], ?array $body = null): ValidationResult
    {
        $psrRequest = $this->buildPsrRequest($httpMethod, $path, $queryParams, $body);

        try {
            $this->validator->validate($psrRequest);
        } catch (ValidationFailed $validationFailed) {
            return ValidationResult::failure($this->formatErrorMessage($validationFailed));
        }

        return ValidationResult::success();
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, mixed>|null $body
     */
    private function buildPsrRequest(string $httpMethod, string $path, array $queryParams, ?array $body): Request
    {
        $baseUrl = DeviceBaseUrl::resolve($this->deviceBaseUrl);
        $uri = rtrim($baseUrl, '/').$path;

        if ([] !== $queryParams) {
            $uri .= '?'.http_build_query($queryParams);
        }

        $headers = [];
        $requestBodyStream = null;

        if (null !== $body) {
            $headers['Content-Type'] = 'application/json';
            $requestBodyStream = $this->streamFactory->createStream(
                json_encode($body, \JSON_THROW_ON_ERROR),
            );
        }

        return new Request($httpMethod, $uri, $headers, $requestBodyStream);
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
