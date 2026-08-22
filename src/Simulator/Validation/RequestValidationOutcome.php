<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use League\OpenAPIValidation\PSR7\OperationAddress;

final readonly class RequestValidationOutcome
{
    private function __construct(
        public ValidationResult $result,
        public ?OperationAddress $matchedOperation,
    ) {
    }

    public static function matched(OperationAddress $matchedOperation): self
    {
        return new self(ValidationResult::success(), $matchedOperation);
    }

    public static function rejected(string $errorMessage): self
    {
        return new self(ValidationResult::failure($errorMessage), null);
    }
}
