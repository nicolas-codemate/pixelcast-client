<?php

declare(strict_types=1);

namespace App\Tests\Config\Sync;

use App\Config\Exception\PixelCastConfigException;
use App\Config\Sync\SyncOptionReader;
use App\Config\WeatherUnits;
use PHPUnit\Framework\TestCase;

final class SyncOptionReaderTest extends TestCase
{
    private const string PARENT_PATH = 'syncs.weather';

    public function testAStringIsReadAndTrimmed(): void
    {
        $units = SyncOptionReader::requireString(['units' => '  metric  '], 'units', self::PARENT_PATH);

        self::assertSame('metric', $units);
    }

    public function testANumberIsReadAsAFloatWhetherItIsWrittenAsAnIntegerOrNot(): void
    {
        self::assertSame(48.8566, SyncOptionReader::requireFloat(['latitude' => 48.8566], 'latitude', self::PARENT_PATH));
        self::assertSame(48.0, SyncOptionReader::requireFloat(['latitude' => 48], 'latitude', self::PARENT_PATH));
    }

    public function testABooleanIsRead(): void
    {
        self::assertTrue(SyncOptionReader::requireBool(['enabled' => true], 'enabled', self::PARENT_PATH));
        self::assertFalse(SyncOptionReader::requireBool(['enabled' => false], 'enabled', self::PARENT_PATH));
    }

    public function testAnOptionalStringIsNullWhenAbsentOrNull(): void
    {
        self::assertNull(SyncOptionReader::optionalString([], 'icon', self::PARENT_PATH));
        self::assertNull(SyncOptionReader::optionalString(['icon' => null], 'icon', self::PARENT_PATH));
        self::assertSame('54326', SyncOptionReader::optionalString(['icon' => '54326'], 'icon', self::PARENT_PATH));
    }

    public function testAnOptionalColorIsNullWhenAbsentOrNullAndIsUppercasedOtherwise(): void
    {
        self::assertNull(SyncOptionReader::optionalColor([], 'labelColor', self::PARENT_PATH));
        self::assertNull(SyncOptionReader::optionalColor(['labelColor' => null], 'labelColor', self::PARENT_PATH));
        self::assertSame('#4CAF50', SyncOptionReader::optionalColor(['labelColor' => '#4caf50'], 'labelColor', self::PARENT_PATH)?->hexCode);
    }

    public function testAnEnumCaseIsRead(): void
    {
        $units = SyncOptionReader::requireEnum(['units' => 'imperial'], 'units', self::PARENT_PATH, WeatherUnits::class);

        self::assertSame(WeatherUnits::Imperial, $units);
    }

    public function testAListOfMapsIsRead(): void
    {
        $items = SyncOptionReader::requireListOfMaps(
            ['items' => [['symbol' => 'BTC'], ['symbol' => 'ETH']]],
            'items',
            'syncs.coingecko',
        );

        self::assertSame([['symbol' => 'BTC'], ['symbol' => 'ETH']], $items);
    }

    public function testAMissingOptionNamesItsFullPath(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.weather.latitude');

        SyncOptionReader::requireFloat([], 'latitude', self::PARENT_PATH);
    }

    public function testAnEmptyStringIsRejected(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('must not be empty');

        SyncOptionReader::requireString(['interval' => '   '], 'interval', self::PARENT_PATH);
    }

    public function testAValueOfTheWrongTypeIsRejected(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('expected a boolean');

        SyncOptionReader::requireBool(['enabled' => 'yes'], 'enabled', self::PARENT_PATH);
    }

    public function testAnUnknownEnumCaseListsTheAcceptedValues(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('expected one of: metric, imperial');

        SyncOptionReader::requireEnum(['units' => 'kelvin'], 'units', self::PARENT_PATH, WeatherUnits::class);
    }

    public function testAColorThatIsNotAHexCodeIsRejected(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('expected a hex color like "#00D4FF"');

        SyncOptionReader::optionalColor(['labelColor' => 'green'], 'labelColor', self::PARENT_PATH);
    }

    public function testAMapWhereAListIsExpectedIsRejected(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('expected a list');

        SyncOptionReader::requireListOfMaps(['items' => ['symbol' => 'BTC']], 'items', 'syncs.coingecko');
    }

    public function testAListEntryThatIsNotAMapNamesItsIndex(): void
    {
        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('syncs.coingecko.items[1]');

        SyncOptionReader::requireListOfMaps(['items' => [['symbol' => 'BTC'], 'ETH']], 'items', 'syncs.coingecko');
    }
}
