<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Tests\Factory\SchemaPropertyReader;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The simulator knowingly answers a few things the device contract in sync/ does not spell out. Each
 * one rests on a fact of the contract: no null form here, no declared default there. sync/ is
 * rewritten wholesale by `make sync-api`, so the reasoning cannot live in it; it lives here, next to
 * the fact it rests on. A re-sync that takes that fact away fails this test instead of leaving the gap
 * behind as an unexplained special case.
 */
final class KnownGapsRestOnDeviceContractFactsTest extends TestCase
{
    private const string SPEC_FILE = 'sync/openapi.yaml';

    /**
     * Each key names the gap and the fact of the device contract it rests on, and is what a failing
     * re-sync prints. Each closure pins that fact.
     *
     * @return iterable<string, array{\Closure(): void}>
     */
    public static function knownGapProvider(): iterable
    {
        yield 'WeatherController::getWeather() leaves out the keys it has no value for: WeatherResponse requires nothing, declares no null form, and carries exactly the keys the projection answers' => [
            static function (): void {
                self::assertArrayNotHasKey(
                    'required',
                    SchemaPropertyReader::deviceContractDefinitionOf('weather.yaml', 'WeatherResponse'),
                );

                $answeredProperties = SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'WeatherResponse');
                self::assertSame(
                    ['valid', 'age', 'stale', 'staleAfter', 'staleBehavior', 'current', 'forecast', 'today'],
                    array_keys($answeredProperties),
                );

                foreach ($answeredProperties as $propertyName => $propertySchema) {
                    self::assertFalse(self::declaresANullForm($propertySchema), $propertyName.' now holds null.');
                }

                // current and today are bare references, so a null form would be written on the
                // definition they point at rather than on the property itself.
                foreach (['WeatherCurrent', 'WeatherToday'] as $referencedDefinition) {
                    self::assertFalse(
                        self::declaresANullForm(SchemaPropertyReader::deviceContractDefinitionOf('weather.yaml', $referencedDefinition)),
                        $referencedDefinition.' now holds null.',
                    );
                }
            },
        ];

        yield 'WeatherController::projectForecastDay() fills in no default: ForecastDay declares none, and shapes a pushed forecast day too, so an incomplete one never reaches the projection' => [
            static function (): void {
                foreach (SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'ForecastDay') as $propertyName => $propertySchema) {
                    self::assertArrayNotHasKey('default', $propertySchema, $propertyName.' now declares a default.');
                }

                self::assertSame(
                    ['day', 'icon', 'temp_min', 'temp_max'],
                    SchemaPropertyReader::deviceContractDefinitionOf('weather.yaml', 'ForecastDay')['required'] ?? null,
                );

                $pushedForecastDay = SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'WeatherUpdateRequest')['forecast']['items'] ?? null;
                self::assertIsArray($pushedForecastDay);
                self::assertSame('#/ForecastDay', $pushedForecastDay['$ref'] ?? null);
            },
        ];

        yield 'CustomAppController::projectAppResponse() never answers the age of an app: AppResponse declares no age, while its three sibling read schemas do' => [
            static function (): void {
                self::assertArrayNotHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse'));

                self::assertArrayHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'WeatherResponse'));
                self::assertArrayHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('gauge.yaml', 'GaugeResponse'));
                self::assertArrayHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('tracker.yaml', 'TrackerResponse'));
            },
        ];

        yield 'ValidateAgainstSpecListener::validateRequest() lets /__inspect and /__reset through unvalidated: the spec declares neither path' => [
            static function (): void {
                $pathsOfTheSpec = self::pathsOfTheSpec();

                self::assertArrayNotHasKey('/__inspect', $pathsOfTheSpec);
                self::assertArrayNotHasKey('/__reset', $pathsOfTheSpec);
            },
        ];

        yield 'ValidateAgainstSpecListener::forgetMatchedOperation() takes a thrown error out of the response pass: some operations declare no error shape to validate it against' => [
            static function (): void {
                self::assertTrue(self::someOperationDeclaresNoErrorResponse());
            },
        ];

        yield 'ColorProjection::withColorFieldsAsHexCodes() removes an unreadable color instead of replacing it: Color declares no empty form, and a listed app color declares no default' => [
            static function (): void {
                $zoneProperties = SchemaPropertyReader::deviceContractPropertiesOf('zone.yaml', 'Zone');
                self::assertArrayHasKey('color', $zoneProperties);

                foreach (SchemaPropertyReader::acceptedFormsOf($zoneProperties['color']) as $acceptedForm) {
                    self::assertIsArray($acceptedForm);
                    self::assertFalse(self::declaresANullForm($acceptedForm));
                }

                $listedAppProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse');
                self::assertArrayHasKey('color', $listedAppProperties);
                self::assertArrayHasKey('pattern', $listedAppProperties['color']);
                self::assertArrayNotHasKey('default', $listedAppProperties['color']);
            },
        ];

        yield 'CustomAppController::projectAppResponse() answers zones as soon as there is one: the two-zone condition is declared on the way in only' => [
            static function (): void {
                $listedAppProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse');
                self::assertArrayHasKey('zones', $listedAppProperties);
                self::assertArrayNotHasKey('minItems', $listedAppProperties['zones']);

                $pushedAppProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'CustomAppRequest');
                self::assertArrayHasKey('zones', $pushedAppProperties);
                self::assertSame([2, 4], SchemaPropertyReader::itemCountBoundsOf($pushedAppProperties['zones']));
            },
        ];

        yield 'IndicatorState::withTheColorTheSlotKeeps() answers a color no request carried: IndicatorRequest requires nothing, and declares a default for each rhythm but none for the color' => [
            static function (): void {
                self::assertArrayNotHasKey(
                    'required',
                    SchemaPropertyReader::deviceContractDefinitionOf('indicator.yaml', 'IndicatorRequest'),
                );

                $pushedIndicatorProperties = SchemaPropertyReader::deviceContractPropertiesOf('indicator.yaml', 'IndicatorRequest');
                self::assertSame(500, SchemaPropertyReader::declaredDefaultOf($pushedIndicatorProperties['blinkInterval']));
                self::assertSame(2000, SchemaPropertyReader::declaredDefaultOf($pushedIndicatorProperties['fadePeriod']));
                self::assertArrayNotHasKey('default', $pushedIndicatorProperties['color']);
            },
        ];
    }

    /**
     * @param \Closure(): void $pinTheContractFactTheGapRestsOn
     */
    #[DataProvider('knownGapProvider')]
    public function testTheKnownGapRestsOnAFactOfTheDeviceContract(\Closure $pinTheContractFactTheGapRestsOn): void
    {
        $pinTheContractFactTheGapRestsOn();
    }

    /**
     * OpenAPI 3.1 has no `nullable` keyword: a value allowed to be null says so with a null type,
     * either on its own or as one branch of a combined schema.
     *
     * @param array<mixed> $schema
     */
    private static function declaresANullForm(array $schema): bool
    {
        $declaredType = $schema['type'] ?? null;
        if ('null' === $declaredType || (\is_array($declaredType) && \in_array('null', $declaredType, true))) {
            return true;
        }

        foreach (['oneOf', 'anyOf'] as $combinedSchemaKeyword) {
            $branches = $schema[$combinedSchemaKeyword] ?? null;
            if (!\is_array($branches)) {
                continue;
            }

            foreach ($branches as $branch) {
                if (\is_array($branch) && self::declaresANullForm($branch)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<mixed>
     */
    private static function pathsOfTheSpec(): array
    {
        $spec = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath(self::SPEC_FILE));
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);

        return $spec['paths'];
    }

    private static function someOperationDeclaresNoErrorResponse(): bool
    {
        foreach (self::pathsOfTheSpec() as $operationsOfThePath) {
            if (!\is_array($operationsOfThePath)) {
                continue;
            }

            foreach ($operationsOfThePath as $operation) {
                if (!\is_array($operation)) {
                    continue;
                }

                $declaredResponses = $operation['responses'] ?? null;
                if (!\is_array($declaredResponses)) {
                    continue;
                }

                if (!self::declaresAnErrorResponse($declaredResponses)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $declaredResponses
     */
    private static function declaresAnErrorResponse(array $declaredResponses): bool
    {
        foreach (array_keys($declaredResponses) as $statusCode) {
            if ((int) $statusCode >= 400) {
                return true;
            }
        }

        return false;
    }
}
