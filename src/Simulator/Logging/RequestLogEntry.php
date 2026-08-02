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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $method = $data['method'] ?? null;
        $path = $data['path'] ?? null;

        if (!\is_string($method) || !\is_string($path)) {
            throw new \InvalidArgumentException('A persisted request log entry needs a string method and path.');
        }

        $timestamp = $data['timestamp'] ?? null;
        $parsedTimestamp = \is_string($timestamp)
            ? \DateTimeImmutable::createFromFormat('!'.\DateTimeInterface::ATOM, $timestamp)
            : false;

        if (false === $parsedTimestamp) {
            throw new \InvalidArgumentException('A persisted request log entry needs an ATOM timestamp.');
        }

        $body = $data['body'] ?? null;
        /** @var array<string, mixed>|null $restoredBody */
        $restoredBody = \is_array($body) ? $body : null;

        $validation = $data['validation'] ?? null;
        /** @var array<string, mixed> $restoredValidation */
        $restoredValidation = \is_array($validation) ? $validation : [];

        return new self(
            method: $method,
            path: $path,
            body: $restoredBody,
            timestamp: $parsedTimestamp,
            validationResult: ValidationResult::fromArray($restoredValidation),
        );
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
