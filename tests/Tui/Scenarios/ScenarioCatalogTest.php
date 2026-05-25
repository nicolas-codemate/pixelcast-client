<?php

declare(strict_types=1);

namespace App\Tests\Tui\Scenarios;

use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDefinition;
use App\Tui\TuiMode;
use PHPUnit\Framework\TestCase;

final class ScenarioCatalogTest extends TestCase
{
    public function testDevModeReturnsFourteenScenarios(): void
    {
        $catalog = new ScenarioCatalog();

        self::assertCount(14, $catalog->all(TuiMode::Dev));
    }

    public function testProdModeOmitsResetScenarioAndReturnsThirteen(): void
    {
        $catalog = new ScenarioCatalog();

        $scenarios = $catalog->all(TuiMode::Prod);

        self::assertCount(13, $scenarios);
        $ids = array_map(static fn (ScenarioDefinition $scenario): string => $scenario->id, $scenarios);
        self::assertNotContains('reset-simulator', $ids);
    }

    public function testAllScenarioIdsAreUnique(): void
    {
        $catalog = new ScenarioCatalog();

        $ids = array_map(
            static fn (ScenarioDefinition $scenario): string => $scenario->id,
            $catalog->all(TuiMode::Dev),
        );

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testOrderMatchesMockupForDevAndProd(): void
    {
        $catalog = new ScenarioCatalog();

        $devIds = array_map(
            static fn (ScenarioDefinition $scenario): string => $scenario->id,
            $catalog->all(TuiMode::Dev),
        );
        $prodIds = array_map(
            static fn (ScenarioDefinition $scenario): string => $scenario->id,
            $catalog->all(TuiMode::Prod),
        );

        self::assertSame('weather', $devIds[0]);
        self::assertSame('reset-simulator', end($devIds));

        self::assertSame('weather', $prodIds[0]);
        self::assertSame('icon-register', end($prodIds));
    }

    public function testResetScenarioHasNoBody(): void
    {
        $catalog = new ScenarioCatalog();

        $resetScenario = $catalog->findById('reset-simulator', TuiMode::Dev);

        self::assertNotNull($resetScenario);
        self::assertNull($resetScenario->body);
        self::assertSame('/__reset', $resetScenario->path);
    }

    public function testFindByIdReturnsMatchingScenarioInDev(): void
    {
        $catalog = new ScenarioCatalog();

        $scenario = $catalog->findById('weather', TuiMode::Dev);

        self::assertNotNull($scenario);
        self::assertSame('weather', $scenario->id);
        self::assertSame('Weather', $scenario->label);
    }

    public function testFindByIdReturnsNullForResetInProdMode(): void
    {
        $catalog = new ScenarioCatalog();

        self::assertNull($catalog->findById('reset-simulator', TuiMode::Prod));
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $catalog = new ScenarioCatalog();

        self::assertNull($catalog->findById('nope', TuiMode::Dev));
    }
}
