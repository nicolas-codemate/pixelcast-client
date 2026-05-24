<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;

final class OpenApiValidator
{
    public function __construct(
        private readonly ServerRequestValidator $serverRequestValidator,
        private readonly PsrHttpFactory $psrHttpFactory,
    ) {
    }

    public function validate(Request $symfonyRequest): ValidationResult
    {
        $psrRequest = $this->psrHttpFactory->createRequest($symfonyRequest);

        try {
            $this->serverRequestValidator->validate($psrRequest);
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
