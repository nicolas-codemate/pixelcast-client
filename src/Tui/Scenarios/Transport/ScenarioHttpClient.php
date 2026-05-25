<?php

declare(strict_types=1);

namespace App\Tui\Scenarios\Transport;

use App\Tui\Scenarios\ScenarioResult;

final readonly class ScenarioHttpClient implements ScenarioTransport
{
    private const int TIMEOUT_SECONDS = 2;
    private const int RESPONSE_SNIPPET_MAX_LENGTH = 200;

    public function send(string $method, string $url, ?array $body): ScenarioResult
    {
        try {
            $encodedBody = null !== $body
                ? json_encode($body, \JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException $jsonException) {
            return ScenarioResult::transportFailure('Payload encoding failed: '.$jsonException->getMessage());
        }

        $streamContext = stream_context_create([
            'http' => $this->buildHttpContextOptions($method, $encodedBody),
        ]);

        // file_get_contents emits a PHP warning on connection failure; we
        // swallow it here because we already detect the failure via false.
        set_error_handler(static fn (): bool => true);
        try {
            $responseBody = file_get_contents($url, false, $streamContext);
            $responseHeaders = http_get_last_response_headers() ?? [];
        } finally {
            restore_error_handler();
        }

        if (false === $responseBody && [] === $responseHeaders) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'unreachable';

            return ScenarioResult::unreachable($reason);
        }

        $httpStatus = $this->parseHttpStatus($responseHeaders);
        if (null === $httpStatus) {
            return ScenarioResult::transportFailure('Unable to parse HTTP status from response');
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return ScenarioResult::success($httpStatus);
        }

        $snippet = $this->trimSnippet(\is_string($responseBody) ? $responseBody : '', self::RESPONSE_SNIPPET_MAX_LENGTH);

        return ScenarioResult::transportFailure($snippet, $httpStatus);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildHttpContextOptions(string $method, ?string $encodedBody): array
    {
        $headers = "Accept: application/json\r\n";
        if (null !== $encodedBody) {
            $headers .= "Content-Type: application/json\r\n";
        }

        $options = [
            'method' => $method,
            'timeout' => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'header' => $headers,
        ];

        if (null !== $encodedBody) {
            $options['content'] = $encodedBody;
        }

        return $options;
    }

    /**
     * @param list<string> $headers
     */
    private function parseHttpStatus(array $headers): ?int
    {
        if ([] === $headers) {
            return null;
        }

        $statusLine = $headers[0];
        if (1 !== preg_match('#^HTTP/\d+\.?\d*\s+(\d{3})#', $statusLine, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function trimSnippet(string $body, int $maxLength): string
    {
        $trimmed = trim($body);
        if (\strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return substr($trimmed, 0, $maxLength).'...';
    }
}
