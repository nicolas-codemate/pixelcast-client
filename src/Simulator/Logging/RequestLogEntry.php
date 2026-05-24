<?php

declare(strict_types=1);

namespace App\Simulator\Logging;

use App\Simulator\Validation\ValidationResult;

final readonly class RequestLogEntry
{
    /**
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?array $body,
        public \DateTimeImmutable $timestamp,
        public ValidationResult $validationResult,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'body' => $this->body,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'validation' => $this->validationResult->toArray(),
        ];
    }
}
