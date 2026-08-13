<?php

declare(strict_types=1);

namespace App\Tests\Scenario;

use App\Scenario\ScenarioCatalog;
use App\Scenario\ScenarioDefinition;
use PHPUnit\Framework\TestCase;

final class ScenarioCatalogTest extends TestCase
{
    public function testDevModeReturnsSixteenScenarios(): void
    {
        $catalog = new ScenarioCatalog();

        self::assertCount(16, $catalog->all(true));
    }

    public function testProdModeOmitsResetScenarioAndReturnsFifteen(): void
    {
        $catalog = new ScenarioCatalog();

        $scenarios = $catalog->all(false);

        self::assertCount(15, $scenarios);
        $ids = array_map(static fn (ScenarioDefinition $scenario): string => $scenario->id, $scenarios);
        self::assertNotContains('reset-simulator', $ids);
    }

    public function testAllScenarioIdsAreUnique(): void
    {
        $catalog = new ScenarioCatalog();

        $ids = array_map(
            static fn (ScenarioDefinition $scenario): string => $scenario->id,
            $catalog->all(true),
        );

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testOrderMatchesMockupForDevAndProd(): void
    {
        $catalog = new ScenarioCatalog();

        $devIds = array_map(
            static fn (ScenarioDefinition $scenario): string => $scenario->id,
            $catalog->all(true),
        );
        $prodIds = array_map(
            static fn (ScenarioDefinition $scenario): string => $scenario->id,
            $catalog->all(false),
        );

        self::assertSame('weather', $devIds[0]);
        self::assertSame('reset-simulator', end($devIds));

        self::assertSame('weather', $prodIds[0]);
        self::assertSame('icon-register', end($prodIds));
    }

    public function testResetScenarioHasNoBody(): void
    {
        $catalog = new ScenarioCatalog();

        $resetScenario = $catalog->findById('reset-simulator', true);

        self::assertNotNull($resetScenario);
        self::assertNull($resetScenario->body);
        self::assertSame('/__reset', $resetScenario->path);
    }

    public function testFindByIdReturnsMatchingScenarioInDev(): void
    {
        $catalog = new ScenarioCatalog();

        $scenario = $catalog->findById('weather', true);

        self::assertNotNull($scenario);
        self::assertSame('weather', $scenario->id);
        self::assertSame('Weather', $scenario->label);
    }

    public function testFindByIdReturnsNullForResetInProdMode(): void
    {
        $catalog = new ScenarioCatalog();

        self::assertNull($catalog->findById('reset-simulator', false));
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $catalog = new ScenarioCatalog();

        self::assertNull($catalog->findById('nope', true));
    }
}
