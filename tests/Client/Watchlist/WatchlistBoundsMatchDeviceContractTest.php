<?php

declare(strict_types=1);

namespace App\Tests\Client\Watchlist;

use App\Client\Watchlist\WatchlistPayload;
use App\Client\Watchlist\WatchlistRow;
use App\Tests\Factory\SchemaPropertyReader;
use PHPUnit\Framework\TestCase;

/**
 * The bounds the client refuses a push over are the ones sync/schemas/watchlist.yaml copies from
 * the firmware repository, so a firmware bump re-fetched by `make sync-api` cannot leave them behind.
 */
final class WatchlistBoundsMatchDeviceContractTest extends TestCase
{
    private const string WATCHLIST_CONTRACT_FILE = 'watchlist.yaml';

    public function testTheDeviceContractBoundsTheNameAndTheRowCountTheClientEnforces(): void
    {
        $requestProperties = SchemaPropertyReader::deviceContractPropertiesOf(self::WATCHLIST_CONTRACT_FILE, 'WatchlistUpdateRequest');

        self::assertSame(WatchlistPayload::MAXIMUM_NAME_LENGTH, $requestProperties['name']['maxLength'] ?? null);
        self::assertSame(WatchlistPayload::MAXIMUM_ROWS, $requestProperties['rows']['maxItems'] ?? null);
    }

    public function testTheDeviceContractBoundsTheRowTextsTheClientEnforces(): void
    {
        $rowProperties = SchemaPropertyReader::deviceContractPropertiesOf(self::WATCHLIST_CONTRACT_FILE, 'WatchlistRow');

        self::assertSame(WatchlistRow::MAXIMUM_SYMBOL_LENGTH, $rowProperties['symbol']['maxLength'] ?? null);
        self::assertSame(WatchlistRow::MAXIMUM_CURRENCY_LENGTH, $rowProperties['currency']['maxLength'] ?? null);
    }
}
