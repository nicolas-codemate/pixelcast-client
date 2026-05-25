<?php

declare(strict_types=1);

namespace App\Tui\Inspector;

final readonly class InspectorSnapshot
{
    /**
     * @param array<string, mixed>|null $state
     * @param list<array<string, mixed>>|null $requests
     */
    public function __construct(
        public ?array $state,
        public ?array $requests,
        public bool $reachable,
        public ?string $errorMessage = null,
    ) {
    }

    public static function unreachable(string $errorMessage): self
    {
        return new self(null, null, false, $errorMessage);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromInspectPayload(array $payload): self
    {
        $state = null;
        if (isset($payload['state']) && \is_array($payload['state'])) {
            /** @var array<string, mixed> $stateValue */
            $stateValue = $payload['state'];
            $state = $stateValue;
        }

        $requests = null;
        if (isset($payload['requests']) && \is_array($payload['requests'])) {
            /** @var list<array<string, mixed>> $requestsValue */
            $requestsValue = array_values(array_filter(
                $payload['requests'],
                static fn (mixed $entry): bool => \is_array($entry),
            ));
            $requests = $requestsValue;
        }

        return new self($state, $requests, true);
    }
}
