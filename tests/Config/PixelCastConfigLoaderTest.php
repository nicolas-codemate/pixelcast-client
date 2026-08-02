<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Exception\PixelCastConfigException;
use App\Config\PixelCastConfig;
use App\Config\PixelCastConfigLoader;
use App\Config\WeatherLocale;
use App\Config\WeatherUnits;
use App\Tests\Factory\PixelCastConfigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PixelCastConfigLoaderTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';

    public function testLoadReturnsConfigFromValidFile(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/valid.yaml');

        $config = $loader->load();

        self::assertSame(120, $config->weatherInterval);
        self::assertSame(30, $config->trackerInterval);
        self::assertSame('openmeteo', $config->weatherSource);
        self::assertSame('yahoo-finance', $config->trackerSource);
        self::assertSame(48.8566, $config->weatherLatitude);
        self::assertSame(2.3522, $config->weatherLongitude);
        self::assertSame(WeatherUnits::Metric, $config->weatherUnits);
        self::assertSame(WeatherLocale::French, $config->weatherLocale);
    }

    public function testLoadThrowsWhenFileMissing(): void
    {
        $missingPath = self::FIXTURES_DIR.'/does-not-exist.yaml';
        $loader = new PixelCastConfigLoader($missingPath);

        self::assertFalse($loader->exists());
        self::assertSame($missingPath, $loader->filePath());

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('not found');

        $loader->load();
    }

    public function testLoadThrowsOnSyntaxError(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/invalid-syntax.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('Failed to parse PixelCast config');

        $loader->load();
    }

    public function testLoadThrowsOnMissingKey(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/missing-key.yaml');

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage('tracked_assets');

        $loader->load();
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function provideRejectedValueCases(): iterable
    {
        yield 'latitude out of bounds' => ['weather_latitude', 120.5, 'weather_latitude'];
        yield 'unknown weather units' => ['weather_units', 'kelvin', 'expected one of: metric, imperial'];
        yield 'unknown weather locale' => ['weather_locale', 'de', 'expected one of: fr, en'];
    }

    #[DataProvider('provideRejectedValueCases')]
    public function testFromArrayRejectsAnInvalidValue(string $key, mixed $invalidValue, string $expectedMessageFragment): void
    {
        $raw = self::validRawMap();
        $raw[$key] = $invalidValue;

        $this->expectException(PixelCastConfigException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        PixelCastConfig::fromArray($raw);
    }

    public function testLoadParsesTrackedAssetsAsTrimmedList(): void
    {
        $loader = new PixelCastConfigLoader(self::FIXTURES_DIR.'/valid.yaml');

        $config = $loader->load();

        self::assertSame(['BTC', 'AAPL', 'SPY', 'ETH'], $config->trackedAssets);
    }

    public function testToRawMapJoinsTrackedAssetsWithCommaSpace(): void
    {
        $rawMap = PixelCastConfigFactory::validConfig()->toRawMap();

        self::assertSame('BTC, AAPL, SPY, ETH', $rawMap['tracked_assets']);
        self::assertSame(120, $rawMap['weather_interval']);
        self::assertSame('openmeteo', $rawMap['weather_source']);
        self::assertSame(48.8566, $rawMap['weather_latitude']);
        self::assertSame('fr', $rawMap['weather_locale']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validRawMap(): array
    {
        $validFixturePath = self::FIXTURES_DIR.'/valid.yaml';

        return PixelCastConfig::asStringKeyedMap(Yaml::parseFile($validFixturePath), $validFixturePath);
    }
}
