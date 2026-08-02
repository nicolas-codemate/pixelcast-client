<?php

declare(strict_types=1);

namespace App\Simulator\Logging;

use App\Simulator\State\PersistedStateReader;
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

        $timestamp = PersistedStateReader::atomDate($data['timestamp'] ?? null);

        if (null === $timestamp) {
            throw new \InvalidArgumentException('A persisted request log entry needs an ATOM timestamp.');
        }

        return new self(
            method: $method,
            path: $path,
            body: PersistedStateReader::payload($data['body'] ?? null),
            timestamp: $timestamp,
            validationResult: ValidationResult::fromArray(PersistedStateReader::payload($data['validation'] ?? null) ?? []),
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
