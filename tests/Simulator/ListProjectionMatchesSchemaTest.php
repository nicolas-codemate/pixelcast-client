<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Controller\CustomAppController;
use App\Simulator\Controller\GaugeController;
use App\Simulator\Controller\TrackerController;
use App\Tests\Factory\SchemaPropertyReader;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OpenAPI validator checks the type of what a list endpoint projects, never whether a property
 * is missing, since the device contract declares no `required`. Reading the property list out of
 * the contract is what catches a projection falling behind the next firmware re-sync.
 */
final class ListProjectionMatchesSchemaTest extends SimulatorWebTestCase
{
    /** AppResponse declares zones as "Present only when zoneCount >= 2", so a single-zone push cannot carry it. */
    private const array PRESENT_ONLY_IN_A_MULTI_ZONE_APP = ['zones'];

    public function testTheTrackerListItemCarriesExactlyTheTrackerSummaryProperties(): void
    {
        $this->postJson('/api/tracker?name=BTC', [
            'symbol' => 'BTC',
            'currency' => 'USD',
            'value' => 98452.30,
            'change' => 2.14,
        ]);

        $declaredProperties = SchemaPropertyReader::deviceContractPropertiesOf('tracker.yaml', 'TrackerSummary');

        self::assertSame(
            self::sortedKeysOf($declaredProperties),
            self::sortedKeysOf($this->firstItemOfList('/api/trackers', 'trackers')),
        );
    }

    public function testTheGaugeListItemCarriesExactlyThePropertiesGaugeListResponseDeclares(): void
    {
        $this->postJson('/api/gauge?name=disks', [
            'title' => 'Disks',
            'rows' => [['label' => 'root', 'percent' => 42]],
        ]);

        $gaugeListProperties = SchemaPropertyReader::deviceContractPropertiesOf('gauge.yaml', 'GaugeListResponse');
        $declaredProperties = SchemaPropertyReader::arrayItemPropertiesOf($gaugeListProperties['gauges']);

        self::assertSame(
            self::sortedKeysOf($declaredProperties),
            self::sortedKeysOf($this->firstItemOfList('/api/gauges', 'gauges')),
        );
    }

    public function testTheAppListItemCarriesExactlyTheAppResponseProperties(): void
    {
        $this->postJson('/api/custom?name=foo', [
            'text' => 'hello',
            'icon' => 'smiley',
            'label' => 'HELLO',
            'color' => '#FF8800',
            'duration' => 10_000,
            'lifetime' => 0,
        ]);

        $declaredProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse');
        foreach (self::PRESENT_ONLY_IN_A_MULTI_ZONE_APP as $multiZoneOnlyProperty) {
            unset($declaredProperties[$multiZoneOnlyProperty]);
        }

        self::assertSame(
            self::sortedKeysOf($declaredProperties),
            self::sortedKeysOf($this->firstItemOfList('/api/apps', 'apps')),
        );
    }

    public function testTheProjectedFreshnessDefaultsAreTheOnesTheRequestSchemasDeclare(): void
    {
        $trackerRequestProperties = SchemaPropertyReader::deviceContractPropertiesOf('tracker.yaml', 'TrackerUpdateRequest');
        self::assertSame(
            TrackerController::DEFAULT_STALE_AFTER_SECONDS,
            SchemaPropertyReader::declaredDefaultOf($trackerRequestProperties['staleAfter']),
        );
        self::assertSame(
            TrackerController::DEFAULT_STALE_BEHAVIOR,
            SchemaPropertyReader::declaredDefaultOf($trackerRequestProperties['staleBehavior']),
        );

        $gaugeRequestProperties = SchemaPropertyReader::deviceContractPropertiesOf('gauge.yaml', 'GaugeUpdateRequest');
        self::assertSame(
            GaugeController::DEFAULT_STALE_AFTER_SECONDS,
            SchemaPropertyReader::declaredDefaultOf($gaugeRequestProperties['staleAfter']),
        );
        self::assertSame(
            GaugeController::DEFAULT_STALE_BEHAVIOR,
            SchemaPropertyReader::declaredDefaultOf($gaugeRequestProperties['staleBehavior']),
        );

        $customAppRequestProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'CustomAppRequest');
        self::assertSame(
            CustomAppController::DEFAULT_STALE_AFTER_SECONDS,
            SchemaPropertyReader::declaredDefaultOf($customAppRequestProperties['staleAfter']),
        );
        self::assertSame(
            CustomAppController::DEFAULT_STALE_BEHAVIOR,
            SchemaPropertyReader::declaredDefaultOf($customAppRequestProperties['staleBehavior']),
        );
    }

    /**
     * @return array<mixed>
     */
    private function firstItemOfList(string $path, string $listKey): array
    {
        $this->client->request('GET', $path);
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $listedItems = $this->jsonResponse()[$listKey] ?? null;
        self::assertIsArray($listedItems);

        $firstItem = $listedItems[0] ?? null;
        self::assertIsArray($firstItem);

        return $firstItem;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function sortedKeysOf(array $values): array
    {
        $keys = [];
        foreach (array_keys($values) as $key) {
            $keys[] = (string) $key;
        }
        sort($keys);

        return $keys;
    }
}
