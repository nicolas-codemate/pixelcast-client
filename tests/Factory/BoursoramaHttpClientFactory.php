<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every asset costs two calls to the same endpoint, the daily bars then the running session, so the
 * responses are served by kind rather than in one queue: a test about the bars keeps its fixtures in
 * the order of the assets, and a session nobody prepared answers like the endpoint does to a length
 * it does not serve.
 */
final class BoursoramaHttpClientFactory
{
    public const string BOURSORAMA_BASE_URI = 'https://www.boursorama.com/';

    private const string SESSION_REQUEST_LENGTH = '1';
    private const string UNSERVED_LENGTH_BODY = '[]';

    /**
     * @param array<MockResponse> $barResponses
     * @param array<MockResponse> $sessionResponses
     */
    public static function serving(array $barResponses, array $sessionResponses = []): MockHttpClient
    {
        $respondByKind = static function (string $method, string $url) use (&$barResponses, &$sessionResponses): MockResponse {
            if (self::SESSION_REQUEST_LENGTH !== self::queryParameterOf($url, 'length')) {
                return array_shift($barResponses) ?? throw new \LogicException(\sprintf('No bar response left for %s.', $url));
            }

            return array_shift($sessionResponses) ?? new MockResponse(self::UNSERVED_LENGTH_BODY);
        };

        return new MockHttpClient($respondByKind, self::BOURSORAMA_BASE_URI);
    }

    private static function queryParameterOf(string $url, string $parameterName): ?string
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $queryParameters);
        $parameterValue = $queryParameters[$parameterName] ?? null;

        return \is_string($parameterValue) ? $parameterValue : null;
    }
}
