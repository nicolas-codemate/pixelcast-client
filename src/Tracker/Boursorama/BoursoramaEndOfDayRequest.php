<?php

declare(strict_types=1);

namespace App\Tracker\Boursorama;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only place the end-of-day endpoint is called from: it is scraped, so what makes it answer has
 * to be corrected in one place the day it changes.
 */
final readonly class BoursoramaEndOfDayRequest
{
    private const string TICKS_PATH = 'bourse/action/graph/ws/GetTicksEOD';
    private const int END_OF_DAY_PERIOD = 0;
    private const string BROWSER_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    private const string AJAX_REQUESTED_WITH = 'XMLHttpRequest';
    private const string CACHE_BYPASS_PARAMETER = '_';
    private const int CACHE_BYPASS_TOKEN_BYTES = 8;
    private const int SESSION_CLOSES_LENGTH = 1;

    public function __construct(
        #[Target('boursorama.client')]
        private HttpClientInterface $boursoramaClient,
    ) {
    }

    /**
     * @param array<string, mixed> $clientOptions widens the bounds of the scoped client for a heavier request
     *
     * @return array<array-key, mixed>
     *
     * @throws HttpClientExceptionInterface
     */
    public function fetchBars(string $boursoramaCode, int $requestedBarCount, array $clientOptions = []): array
    {
        return BoursoramaQuoteSeries::readBars($this->requestTicks($boursoramaCode, $requestedBarCount, $clientOptions));
    }

    /**
     * Asked for a single bar, the endpoint switches to the intraday ticks of the last days and adds
     * the running session and the one before it, which is where the close of the eve can be trusted.
     *
     * @throws HttpClientExceptionInterface
     */
    public function fetchSessionCloses(string $boursoramaCode): ?BoursoramaSessionCloses
    {
        return BoursoramaQuoteSeries::readSessionCloses($this->requestTicks($boursoramaCode, self::SESSION_CLOSES_LENGTH));
    }

    /**
     * @param array<string, mixed> $clientOptions
     *
     * @return array<array-key, mixed>
     *
     * @throws HttpClientExceptionInterface
     */
    private function requestTicks(string $boursoramaCode, int $length, array $clientOptions = []): array
    {
        return $this->boursoramaClient->request('GET', self::TICKS_PATH, [
            // Without both of these the endpoint answers 200 with an empty quote
            // list instead of an error, so a missing header reads as an unknown code.
            'headers' => [
                'User-Agent' => self::BROWSER_USER_AGENT,
                'X-Requested-With' => self::AJAX_REQUESTED_WITH,
            ],
            'query' => [
                'symbol' => $boursoramaCode,
                'length' => $length,
                'period' => self::END_OF_DAY_PERIOD,
                'guid' => '',
                // A cache in front of the endpoint ignores its own no-cache header and serves
                // copies hours old, whose running session is frozen. A query that never repeats
                // is the only way past it.
                self::CACHE_BYPASS_PARAMETER => bin2hex(random_bytes(self::CACHE_BYPASS_TOKEN_BYTES)),
            ],
            ...$clientOptions,
        ])->toArray();
    }
}
