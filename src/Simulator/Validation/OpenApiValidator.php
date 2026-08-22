<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OpenApiValidator
{
    public function __construct(
        private readonly ServerRequestValidator $serverRequestValidator,
        private readonly ResponseValidator $responseValidator,
        private readonly PsrHttpFactory $psrHttpFactory,
    ) {
    }

    public function validate(Request $symfonyRequest): RequestValidationOutcome
    {
        $psrRequest = $this->psrHttpFactory->createRequest($symfonyRequest);

        try {
            $matchedOperation = $this->serverRequestValidator->validate($psrRequest);
        } catch (ValidationFailed $validationFailed) {
            return RequestValidationOutcome::rejected($this->formatErrorMessage($validationFailed));
        }

        return RequestValidationOutcome::matched($matchedOperation);
    }

    public function validateResponse(OperationAddress $matchedOperation, Response $symfonyResponse): ValidationResult
    {
        $psrResponse = $this->psrHttpFactory->createResponse($symfonyResponse);

        try {
            $this->responseValidator->validate($matchedOperation, $psrResponse);
        } catch (ValidationFailed $validationFailed) {
            return ValidationResult::failure($this->formatErrorMessage($validationFailed));
        }

        return ValidationResult::success();
    }

    private function formatErrorMessage(ValidationFailed $validationFailed): string
    {
        $message = $validationFailed->getMessage();
        $previous = $validationFailed->getPrevious();

        if ($previous instanceof \Throwable) {
            return $message.': '.$previous->getMessage();
        }

        return $message;
    }
}
