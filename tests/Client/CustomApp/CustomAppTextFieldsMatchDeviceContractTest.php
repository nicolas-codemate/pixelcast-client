<?php

declare(strict_types=1);

namespace App\Tests\Client\CustomApp;

use App\Client\CustomApp\CustomAppPayload;
use App\Client\CustomApp\Zone;
use App\Tests\Factory\SchemaPropertyReader;
use PHPUnit\Framework\TestCase;

/**
 * The text fields and the zone count are bounded twice: by the constants the client refuses a push
 * over, and by the device contract sync/schemas/ copies from the firmware repository. A firmware bump
 * re-fetched by `make sync-api` must not leave the constants behind, since a segment sent over the cap
 * is dropped by the panel without any error coming back.
 */
final class CustomAppTextFieldsMatchDeviceContractTest extends TestCase
{
    private const string CUSTOM_APP_CONTRACT_FILE = 'custom-app.yaml';
    private const string ZONE_CONTRACT_FILE = 'zone.yaml';

    public function testTheDeviceContractBoundsTheTextToTheCharactersAndSegmentsTheClientEnforces(): void
    {
        $textSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::CUSTOM_APP_CONTRACT_FILE, 'CustomAppRequest')['text'];

        self::assertSame(CustomAppPayload::MAXIMUM_TEXT_LENGTH, SchemaPropertyReader::plainStringLengthBoundOf($textSchema));
        self::assertSame(CustomAppPayload::MAXIMUM_TEXT_SEGMENTS, SchemaPropertyReader::segmentCountBoundOf($textSchema));
    }

    public function testTheDeviceContractBoundsTheLabelToTheCharactersAndSegmentsTheClientEnforces(): void
    {
        $labelSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::CUSTOM_APP_CONTRACT_FILE, 'CustomAppRequest')['label'];

        self::assertSame(CustomAppPayload::MAXIMUM_LABEL_LENGTH, SchemaPropertyReader::plainStringLengthBoundOf($labelSchema));
        self::assertSame(CustomAppPayload::MAXIMUM_LABEL_SEGMENTS, SchemaPropertyReader::segmentCountBoundOf($labelSchema));
    }

    public function testTheDeviceContractBoundsTheZoneTextToTheCharactersAndSegmentsTheClientEnforces(): void
    {
        $textSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::ZONE_CONTRACT_FILE, 'Zone')['text'];

        self::assertSame(Zone::MAXIMUM_TEXT_LENGTH, SchemaPropertyReader::plainStringLengthBoundOf($textSchema));
        self::assertSame(Zone::MAXIMUM_TEXT_SEGMENTS, SchemaPropertyReader::segmentCountBoundOf($textSchema));
    }

    public function testTheDeviceContractBoundsTheZoneLabelToTheCharactersAndSegmentsTheClientEnforces(): void
    {
        $labelSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::ZONE_CONTRACT_FILE, 'Zone')['label'];

        self::assertSame(Zone::MAXIMUM_LABEL_LENGTH, SchemaPropertyReader::plainStringLengthBoundOf($labelSchema));
        self::assertSame(Zone::MAXIMUM_LABEL_SEGMENTS, SchemaPropertyReader::segmentCountBoundOf($labelSchema));
    }

    public function testTheDeviceContractBoundsTheZoneCountToTheRangeTheClientEnforces(): void
    {
        $zonesSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::CUSTOM_APP_CONTRACT_FILE, 'CustomAppRequest')['zones'];

        self::assertSame(
            [CustomAppPayload::MINIMUM_ZONES, CustomAppPayload::MAXIMUM_ZONES],
            SchemaPropertyReader::itemCountBoundsOf($zonesSchema),
        );
    }

    public function testTheDeviceContractStalesTheCustomAppTheWayTheClientAnnouncesIt(): void
    {
        $customAppProperties = SchemaPropertyReader::deviceContractPropertiesOf(self::CUSTOM_APP_CONTRACT_FILE, 'CustomAppRequest');

        self::assertSame(
            CustomAppPayload::DEVICE_DEFAULT_STALE_AFTER_IN_SECONDS,
            SchemaPropertyReader::declaredDefaultOf($customAppProperties['staleAfter']),
        );
        self::assertSame(
            CustomAppPayload::DEVICE_DEFAULT_STALE_BEHAVIOR->value,
            SchemaPropertyReader::declaredDefaultOf($customAppProperties['staleBehavior']),
        );
    }
}
