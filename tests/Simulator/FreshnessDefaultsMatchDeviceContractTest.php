<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Controller\CustomAppController;
use App\Simulator\Controller\GaugeController;
use App\Simulator\Controller\TrackerController;
use App\Tests\Factory\SchemaPropertyReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A payload that carries no staleAfter or staleBehavior is projected with the controller default,
 * which only tells the truth as long as it is the default the device applies.
 */
final class FreshnessDefaultsMatchDeviceContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, int|string}>
     */
    public static function freshnessDefaultProvider(): iterable
    {
        yield 'tracker staleAfter' => ['tracker.yaml', 'TrackerUpdateRequest', 'staleAfter', TrackerController::DEFAULT_STALE_AFTER_SECONDS];
        yield 'tracker staleBehavior' => ['tracker.yaml', 'TrackerUpdateRequest', 'staleBehavior', TrackerController::DEFAULT_STALE_BEHAVIOR];
        yield 'gauge staleAfter' => ['gauge.yaml', 'GaugeUpdateRequest', 'staleAfter', GaugeController::DEFAULT_STALE_AFTER_SECONDS];
        yield 'gauge staleBehavior' => ['gauge.yaml', 'GaugeUpdateRequest', 'staleBehavior', GaugeController::DEFAULT_STALE_BEHAVIOR];
        yield 'custom app staleAfter' => ['custom-app.yaml', 'CustomAppRequest', 'staleAfter', CustomAppController::DEFAULT_STALE_AFTER_SECONDS];
        yield 'custom app staleBehavior' => ['custom-app.yaml', 'CustomAppRequest', 'staleBehavior', CustomAppController::DEFAULT_STALE_BEHAVIOR];
    }

    #[DataProvider('freshnessDefaultProvider')]
    public function testTheProjectedDefaultIsTheOneTheRequestSchemaDeclares(
        string $contractFileName,
        string $requestDefinition,
        string $propertyName,
        int|string $controllerDefault,
    ): void {
        $requestProperties = SchemaPropertyReader::deviceContractPropertiesOf($contractFileName, $requestDefinition);

        self::assertSame($controllerDefault, SchemaPropertyReader::declaredDefaultOf($requestProperties[$propertyName]));
    }
}
