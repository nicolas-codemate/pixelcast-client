<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Tests\Factory\SchemaPropertyReader;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The simulator knowingly answers a few things the device contract in sync/ does not spell out. Each
 * one rests on a fact of the contract: no nullable property here, no declared default there. sync/ is
 * rewritten wholesale by `make sync-api`, so the reasoning cannot live in it; it lives here, next to
 * the fact it rests on. A re-sync that takes that fact away fails this test instead of leaving the gap
 * behind as an unexplained special case.
 */
final class KnownGapsRestOnDeviceContractFactsTest extends TestCase
{
    private const string SPEC_FILE = 'sync/openapi.yaml';
    private const string REGISTRY_GUARD_METHOD = 'testEveryKnownGapIsPinnedToAFactOfTheDeviceContract';

    /**
     * Gaps between what the simulator answers and what the device contract declares. The key is the
     * method pinning the fact the gap rests on, so a gap cannot be recorded without a fact holding it
     * up, nor a fact left behind once the gap it explained is gone.
     *
     * @var array<string, string>
     */
    private const array KNOWN_GAPS = [
        'testWeatherResponseDeclaresNoNullablePropertyAndNoRequiredProperty' => 'WeatherController::getWeather() leaves out the keys it has no value for instead of answering null.',
        'testForecastDayDeclaresNoDefaultAndIsTheSameShapeOnTheWayIn' => 'WeatherController::projectForecastDay() copies the fields the payload carries and fills in no default.',
        'testAppResponseDeclaresNoAgeWhileItsSiblingsDo' => 'CustomAppController::projectAppResponse() computes the age of an app and never answers it.',
        'testTheDiagnosticEndpointsAreAbsentFromTheSpec' => 'ValidateAgainstSpecListener::validateRequest() lets /__inspect and /__reset through without validating them.',
        'testSomeOperationsDeclareNoErrorResponse' => 'ValidateAgainstSpecListener::forgetMatchedOperation() takes a thrown error out of the response validation pass.',
        'testTheContractDeclaresNoEmptyColorToPutInPlaceOfAnUnreadableOne' => 'ColorProjection::withColorFieldsAsHexCodes() removes an unreadable color instead of replacing it.',
        'testTheTwoZoneConditionIsDeclaredOnTheWayInOnly' => 'CustomAppController::projectAppResponse() answers zones as soon as there is one, not two.',
    ];

    /**
     * WeatherResponse leaves every key optional and lets none of them hold null, so the only shape an
     * empty state can answer with is one where the key is absent.
     */
    public function testWeatherResponseDeclaresNoNullablePropertyAndNoRequiredProperty(): void
    {
        self::assertArrayNotHasKey(
            'required',
            SchemaPropertyReader::deviceContractDefinitionOf('weather.yaml', 'WeatherResponse'),
        );

        foreach (SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'WeatherResponse') as $propertyName => $propertySchema) {
            self::assertArrayNotHasKey('nullable', $propertySchema, $propertyName.' now holds null.');
        }
    }

    /**
     * ForecastDay declares no default to fill in, and the same definition shapes a forecast day on the
     * way in, so a day short of a field it requires never reaches the projection.
     */
    public function testForecastDayDeclaresNoDefaultAndIsTheSameShapeOnTheWayIn(): void
    {
        foreach (SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'ForecastDay') as $propertyName => $propertySchema) {
            self::assertArrayNotHasKey('default', $propertySchema, $propertyName.' now declares a default.');
        }

        self::assertSame(
            ['day', 'icon', 'temp_min', 'temp_max'],
            SchemaPropertyReader::deviceContractDefinitionOf('weather.yaml', 'ForecastDay')['required'] ?? null,
        );

        $pushedWeatherProperties = SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'WeatherUpdateRequest');
        self::assertArrayHasKey('forecast', $pushedWeatherProperties);

        $forecastDayOnTheWayIn = $pushedWeatherProperties['forecast']['items'] ?? null;
        self::assertIsArray($forecastDayOnTheWayIn);
        self::assertSame('#/ForecastDay', $forecastDayOnTheWayIn['$ref'] ?? null);
    }

    /**
     * AppResponse never says how old an app is, while the three sibling read schemas do.
     */
    public function testAppResponseDeclaresNoAgeWhileItsSiblingsDo(): void
    {
        self::assertArrayNotHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse'));

        self::assertArrayHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('weather.yaml', 'WeatherResponse'));
        self::assertArrayHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('gauge.yaml', 'GaugeResponse'));
        self::assertArrayHasKey('age', SchemaPropertyReader::deviceContractPropertiesOf('tracker.yaml', 'TrackerResponse'));
    }

    /**
     * The two diagnostic endpoints the simulator adds have nothing in the spec to be validated against.
     */
    public function testTheDiagnosticEndpointsAreAbsentFromTheSpec(): void
    {
        $pathsOfTheSpec = self::pathsOfTheSpec();

        self::assertArrayNotHasKey('/__inspect', $pathsOfTheSpec);
        self::assertArrayNotHasKey('/__reset', $pathsOfTheSpec);
    }

    /**
     * An operation declaring nothing but a 2xx gives an error raised while serving it no shape to be
     * validated against. The count is left free on purpose: a single operation is enough to keep the
     * gap alive, and the day every operation declares an error shape the gap has to be reopened.
     */
    public function testSomeOperationsDeclareNoErrorResponse(): void
    {
        self::assertNotSame([], self::operationsDeclaringNoErrorResponse());
    }

    /**
     * Color declares no empty form, and the color a listed app answers with declares no default, so an
     * unreadable one has nothing it could be replaced with.
     */
    public function testTheContractDeclaresNoEmptyColorToPutInPlaceOfAnUnreadableOne(): void
    {
        $zoneProperties = SchemaPropertyReader::deviceContractPropertiesOf('zone.yaml', 'Zone');
        self::assertArrayHasKey('color', $zoneProperties);

        foreach (SchemaPropertyReader::acceptedFormsOf($zoneProperties['color']) as $acceptedForm) {
            self::assertIsArray($acceptedForm);
            self::assertNotSame('null', $acceptedForm['type'] ?? null);
        }

        $listedAppProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse');
        self::assertArrayHasKey('color', $listedAppProperties);
        self::assertArrayHasKey('pattern', $listedAppProperties['color']);
        self::assertArrayNotHasKey('default', $listedAppProperties['color']);
    }

    /**
     * The two-zone condition lives in a description on the way out, and in a minItems the request
     * validation enforces on the way in, which is what keeps a one-zone app out of the stored state.
     */
    public function testTheTwoZoneConditionIsDeclaredOnTheWayInOnly(): void
    {
        $listedAppProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'AppResponse');
        self::assertArrayHasKey('zones', $listedAppProperties);
        self::assertArrayNotHasKey('minItems', $listedAppProperties['zones']);

        $pushedAppProperties = SchemaPropertyReader::deviceContractPropertiesOf('custom-app.yaml', 'CustomAppRequest');
        self::assertArrayHasKey('zones', $pushedAppProperties);
        self::assertSame([2, 4], SchemaPropertyReader::itemCountBoundsOf($pushedAppProperties['zones']));
    }

    public function testEveryKnownGapIsPinnedToAFactOfTheDeviceContract(): void
    {
        $pinningMethods = [];
        foreach (new \ReflectionClass(self::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();
            if (self::class !== $method->getDeclaringClass()->getName()
                || !str_starts_with($methodName, 'test')
                || self::REGISTRY_GUARD_METHOD === $methodName) {
                continue;
            }

            $pinningMethods[] = $methodName;
        }
        sort($pinningMethods);

        $recordedGaps = array_keys(self::KNOWN_GAPS);
        sort($recordedGaps);

        self::assertSame($recordedGaps, $pinningMethods);
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

    /**
     * @return list<string>
     */
    private static function operationsDeclaringNoErrorResponse(): array
    {
        $operations = [];
        foreach (self::pathsOfTheSpec() as $specPath => $operationsOfThePath) {
            if (!\is_array($operationsOfThePath)) {
                continue;
            }

            foreach ($operationsOfThePath as $httpMethod => $operation) {
                if (!\is_array($operation)) {
                    continue;
                }

                $declaredResponses = $operation['responses'] ?? null;
                if (!\is_array($declaredResponses)) {
                    continue;
                }

                foreach (array_keys($declaredResponses) as $statusCode) {
                    if ((int) $statusCode >= 400) {
                        continue 2;
                    }
                }

                $operations[] = strtoupper((string) $httpMethod).' '.$specPath;
            }
        }

        return $operations;
    }
}
