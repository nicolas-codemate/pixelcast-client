<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

final readonly class ValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $errorMessage,
    ) {
    }

    public static function success(): self
    {
        return new self(valid: true, errorMessage: null);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(valid: false, errorMessage: $errorMessage);
    }

    /**
     * @return array{valid: bool, error?: string}
     */
    public function toArray(): array
    {
        if ($this->valid) {
            return ['valid' => true];
        }

        return ['valid' => false, 'error' => $this->errorMessage ?? 'unknown error'];
    }
}
