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
        $decodedResponse = $this->boursoramaClient->request('GET', self::TICKS_PATH, [
            // Without both of these the endpoint answers 200 with an empty quote
            // list instead of an error, so a missing header reads as an unknown code.
            'headers' => [
                'User-Agent' => self::BROWSER_USER_AGENT,
                'X-Requested-With' => self::AJAX_REQUESTED_WITH,
            ],
            'query' => [
                'symbol' => $boursoramaCode,
                'length' => $requestedBarCount,
                'period' => self::END_OF_DAY_PERIOD,
                'guid' => '',
            ],
            ...$clientOptions,
        ])->toArray();

        return BoursoramaQuoteSeries::readBars($decodedResponse);
    }
}
