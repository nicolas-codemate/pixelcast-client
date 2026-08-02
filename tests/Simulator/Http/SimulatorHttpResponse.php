<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Simulator\State\PersistedStateReader;

final readonly class SimulatorHttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedBody(): array
    {
        $payload = PersistedStateReader::payload(json_decode($this->body, true));

        if (null === $payload) {
            throw new \RuntimeException('The simulator did not answer with a JSON object: '.$this->body);
        }

        return $payload;
    }
}
