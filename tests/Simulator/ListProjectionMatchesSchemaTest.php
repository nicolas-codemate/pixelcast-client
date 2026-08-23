<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Tests\Factory\SchemaPropertyReader;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI validator checks the type of what a list endpoint projects, never whether a property
 * is missing, since the device contract declares no `required`. Reading the property list out of
 * the contract is what catches a projection falling behind the next firmware re-sync.
 */
final class ListProjectionMatchesSchemaTest extends SimulatorWebTestCase
{
    private const string SPEC_FILE = 'sync/openapi.yaml';
    private const string CONTRACT_REFERENCE_DIRECTORY = 'schemas/';

    /**
     * Endpoints returning a list of objects whose items no projection test pins to the contract.
     * Each one is recorded as a follow-up rather than fixed here. None is open right now: this is
     * where the next list endpoint arriving through `make sync-api` lands until it gets covered.
     *
     * @var list<string>
     */
    private const array LISTS_NO_PROJECTION_TEST_COVERS = [];

    /**
     * @return iterable<string, array{string, string, string, string, \Closure(self): mixed, list<string>, string}>
     */
    public static function coveredListEndpointProvider(): iterable
    {
        yield '/trackers' => [
            '/trackers',
            'trackers',
            'tracker.yaml',
            'TrackerListResponse',
            static fn (self $test) => $test->postJson('/api/tracker?name=BTC', self::trackerPushPayload()),
            [],
            '',
        ];

        yield '/gauges' => [
            '/gauges',
            'gauges',
            'gauge.yaml',
            'GaugeListResponse',
            static fn (self $test) => $test->postJson('/api/gauge?name=disks', self::gaugePushPayload()),
            [],
            '',
        ];

        // The seeded row carries only the four properties the row schema gives no default, so the
        // exact key equality also proves the three defaulted ones are filled in.
        yield '/gauge' => [
            '/gauge',
            'rows',
            'gauge.yaml',
            'GaugeResponse',
            static fn (self $test) => $test->postJson('/api/gauge?name=disks', self::gaugePushPayload()),
            [],
            '?name=disks',
        ];

        yield '/weather' => [
            '/weather',
            'forecast',
            'weather.yaml',
            'WeatherResponse',
            static fn (self $test) => $test->postJson('/api/weather', self::weatherPushPayload()),
            [],
            '',
        ];

        yield '/apps' => [
            '/apps',
            'apps',
            'custom-app.yaml',
            'AppListResponse',
            static fn (self $test) => $test->postJson('/api/custom?name=foo', [
                ...self::customAppPushPayload(),
                'label' => 'HELLO',
                'lifetime' => 0,
            ]),
            // AppResponse declares zones as "Present only when zoneCount >= 2".
            ['zones'],
            '',
        ];

        yield '/notify/list' => [
            '/notify/list',
            'notifications',
            'notification.yaml',
            'NotificationListResponse',
            static fn (self $test) => $test->postJson('/api/notify', self::notificationPushPayload()),
            [],
            '',
        ];

        yield '/icons' => [
            '/icons',
            'icons',
            'icon.yaml',
            'IconListResponse',
            static fn (self $test) => $test->uploadIcon('bitcoin'),
            [],
            '',
        ];
    }

    /**
     * @param \Closure(self): mixed $seedTheList
     * @param list<string> $propertiesASinglePushCannotCarry
     */
    #[DataProvider('coveredListEndpointProvider')]
    public function testTheListItemCarriesExactlyThePropertiesTheContractDeclares(
        string $specPath,
        string $listKey,
        string $contractFileName,
        string $listResponseDefinition,
        \Closure $seedTheList,
        array $propertiesASinglePushCannotCarry,
        string $readQueryString,
    ): void {
        $seedTheList($this);

        $declaredProperties = self::declaredItemPropertiesOf($contractFileName, $listResponseDefinition, $listKey);
        foreach ($propertiesASinglePushCannotCarry as $absentProperty) {
            unset($declaredProperties[$absentProperty]);
        }

        self::assertSame(
            self::sortedKeysOf($declaredProperties),
            self::sortedKeysOf($this->firstItemOfList('/api'.$specPath.$readQueryString, $listKey)),
        );
    }

    public function testEveryListEndpointOfTheSpecIsCoveredOrNamedAsAKnownGap(): void
    {
        $coveredPaths = [];
        foreach (self::coveredListEndpointProvider() as [$specPath]) {
            $coveredPaths[] = $specPath;
        }

        $knownPaths = [...$coveredPaths, ...self::LISTS_NO_PROJECTION_TEST_COVERS];
        sort($knownPaths);

        $listReturningPaths = self::listReturningPathsOfTheSpec();
        sort($listReturningPaths);

        self::assertSame($knownPaths, $listReturningPaths);
    }

    /**
     * Every GET whose 200 response declares a property holding an array of objects: that item
     * shape is what a projection can silently fall behind.
     *
     * @return list<string>
     */
    private static function listReturningPathsOfTheSpec(): array
    {
        $spec = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath(self::SPEC_FILE));
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);

        $listReturningPaths = [];
        foreach ($spec['paths'] as $specPath => $operations) {
            $responseReference = self::nestedValue($operations, ['get', 'responses', 200, 'content', 'application/json', 'schema', '$ref']);
            if (!\is_string($responseReference)) {
                continue;
            }

            [$contractFileName, $definitionName] = self::splitContractReference($responseReference);
            foreach (SchemaPropertyReader::deviceContractPropertiesOf($contractFileName, $definitionName) as $property) {
                if (null !== self::itemDefinitionOf($contractFileName, $property)) {
                    $listReturningPaths[] = (string) $specPath;
                    break;
                }
            }
        }

        return $listReturningPaths;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function declaredItemPropertiesOf(string $contractFileName, string $listResponseDefinition, string $listKey): array
    {
        $listProperties = SchemaPropertyReader::deviceContractPropertiesOf($contractFileName, $listResponseDefinition);
        self::assertArrayHasKey($listKey, $listProperties);

        $itemProperties = self::itemDefinitionOf($contractFileName, $listProperties[$listKey]);
        self::assertIsArray($itemProperties);

        return $itemProperties;
    }

    /**
     * A list item is either spelled out under the array field or pointed at by a same-file
     * reference; anything else is not an array of objects.
     *
     * @param array<mixed> $fieldSchema
     *
     * @return array<string, array<mixed>>|null
     */
    private static function itemDefinitionOf(string $contractFileName, array $fieldSchema): ?array
    {
        if ('array' !== ($fieldSchema['type'] ?? null) || !\is_array($fieldSchema['items'] ?? null)) {
            return null;
        }

        $itemSchema = $fieldSchema['items'];

        if (\is_array($itemSchema['properties'] ?? null)) {
            return SchemaPropertyReader::arrayItemPropertiesOf($fieldSchema);
        }

        $itemReference = $itemSchema['$ref'] ?? null;
        if (!\is_string($itemReference)) {
            return null;
        }

        [$referencedFileName, $definitionName] = self::splitContractReference($itemReference);

        return SchemaPropertyReader::deviceContractPropertiesOf(
            '' === $referencedFileName ? $contractFileName : $referencedFileName,
            $definitionName,
        );
    }

    /**
     * @param list<string|int> $keys
     */
    private static function nestedValue(mixed $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array{string, string}
     */
    private static function splitContractReference(string $reference): array
    {
        $separatorPosition = strpos($reference, '#/');
        self::assertIsInt($separatorPosition);

        $fileName = substr($reference, 0, $separatorPosition);

        return [
            str_starts_with($fileName, self::CONTRACT_REFERENCE_DIRECTORY) ? substr($fileName, \strlen(self::CONTRACT_REFERENCE_DIRECTORY)) : $fileName,
            substr($reference, $separatorPosition + 2),
        ];
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function sortedKeysOf(array $values): array
    {
        $keys = array_map(strval(...), array_keys($values));
        sort($keys);

        return $keys;
    }
}
