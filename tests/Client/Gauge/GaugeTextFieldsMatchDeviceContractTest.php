<?php

declare(strict_types=1);

namespace App\Tests\Client\Gauge;

use App\Client\Gauge\GaugePayload;
use App\Client\Gauge\GaugeRow;
use App\Tests\Factory\SchemaPropertyReader;
use PHPUnit\Framework\TestCase;

/**
 * The title and the row label are bounded twice: by the constants the client refuses a push over,
 * and by the device contract sync/schemas/gauge.yaml copies from the firmware repository. A firmware
 * bump re-fetched by `make sync-api` must not leave the constants behind, since a segment sent over
 * the cap is dropped by the panel without any error coming back.
 */
final class GaugeTextFieldsMatchDeviceContractTest extends TestCase
{
    private const string GAUGE_CONTRACT_FILE = 'gauge.yaml';

    public function testTheDeviceContractBoundsTheTitleToTheCharactersAndSegmentsTheClientEnforces(): void
    {
        $titleSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::GAUGE_CONTRACT_FILE, 'GaugeUpdateRequest')['title'];

        self::assertSame(GaugePayload::MAXIMUM_TITLE_LENGTH, SchemaPropertyReader::plainStringLengthBoundOf($titleSchema));
        self::assertSame(GaugePayload::MAXIMUM_TITLE_SEGMENTS, SchemaPropertyReader::segmentCountBoundOf($titleSchema));
    }

    public function testTheDeviceContractBoundsTheRowLabelToTheCharactersAndSegmentsTheClientEnforces(): void
    {
        $labelSchema = SchemaPropertyReader::deviceContractPropertiesOf(self::GAUGE_CONTRACT_FILE, 'GaugeRow')['label'];

        self::assertSame(GaugeRow::MAXIMUM_LABEL_LENGTH, SchemaPropertyReader::plainStringLengthBoundOf($labelSchema));
        self::assertSame(GaugeRow::MAXIMUM_LABEL_SEGMENTS, SchemaPropertyReader::segmentCountBoundOf($labelSchema));
    }
}
