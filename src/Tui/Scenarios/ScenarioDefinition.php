<?php

declare(strict_types=1);

namespace App\Tui\Scenarios;

final readonly class ScenarioDefinition
{
    private const array ALLOWED_HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string,string> $queryParams
     * @param array<string,mixed>|null $body
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $httpMethod,
        public string $path,
        public array $queryParams = [],
        public ?array $body = null,
    ) {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('Scenario id must not be empty.');
        }
        if ('' === $this->label) {
            throw new \InvalidArgumentException('Scenario label must not be empty.');
        }
        if ('' === $this->path) {
            throw new \InvalidArgumentException('Scenario path must not be empty.');
        }
        if (!\in_array($this->httpMethod, self::ALLOWED_HTTP_METHODS, true)) {
            throw new \InvalidArgumentException(\sprintf('Scenario httpMethod "%s" is not supported. Allowed: %s.', $this->httpMethod, implode(', ', self::ALLOWED_HTTP_METHODS)));
        }
    }
}
