<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Exception\PixelCastConfigException;
use App\Config\PixelCastConfig;
use App\Config\PixelCastConfigLoader;
use App\Config\PixelCastConfigWriter;
use PHPUnit\Framework\TestCase;

final class PixelCastConfigWriterTest extends TestCase
{
    private const string FIXTURES_DIR = __DIR__.'/Fixtures';

    private string $temporaryConfigPath;

    protected function setUp(): void
    {
        $this->temporaryConfigPath = \sprintf(
            '%s/pixelcast-config-writer-%s.yaml',
            sys_get_temp_dir(),
            bin2hex(random_bytes(8)),
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->temporaryConfigPath, $this->temporaryConfigPath.'.tmp'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testSaveKeepsCommentsAndBlankLines(): void
    {
        $originalContent = $this->copyFixtureToTemp('with-comments.yaml');
        $config = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        $writer = new PixelCastConfigWriter($this->temporaryConfigPath);

        $writer->save($config);

        self::assertSame($originalContent, file_get_contents($this->temporaryConfigPath));
    }

    public function testSaveUpdatesChangedValueInPlace(): void
    {
        $this->copyFixtureToTemp('with-comments.yaml');
        $originalLines = file($this->temporaryConfigPath);
        self::assertIsArray($originalLines);

        $loaded = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        $updated = new PixelCastConfig(
            deviceUrl: $loaded->deviceUrl,
            weatherInterval: 600,
            trackerInterval: $loaded->trackerInterval,
            trackedAssets: $loaded->trackedAssets,
            weatherSource: $loaded->weatherSource,
            trackerSource: $loaded->trackerSource,
        );

        new PixelCastConfigWriter($this->temporaryConfigPath)->save($updated);

        $rewrittenLines = file($this->temporaryConfigPath);
        self::assertIsArray($rewrittenLines);
        self::assertCount(\count($originalLines), $rewrittenLines);

        foreach ($originalLines as $index => $originalLine) {
            if (str_starts_with($originalLine, 'weather_interval:')) {
                self::assertSame("weather_interval: 600\n", $rewrittenLines[$index]);

                continue;
            }
            self::assertSame($originalLine, $rewrittenLines[$index], \sprintf('Line %d changed unexpectedly.', $index + 1));
        }
    }

    public function testSaveAppendsNewKeyAtEndOfFile(): void
    {
        $this->copyFixtureToTemp('missing-tracked-assets.yaml');

        $config = new PixelCastConfig(
            deviceUrl: 'http://pixelcast.test/api',
            weatherInterval: 120,
            trackerInterval: 30,
            trackedAssets: ['BTC', 'AAPL', 'SPY', 'ETH'],
            weatherSource: 'openmeteo',
            trackerSource: 'yahoo-finance',
        );

        new PixelCastConfigWriter($this->temporaryConfigPath)->save($config);

        $rewrittenLines = file($this->temporaryConfigPath);
        self::assertIsArray($rewrittenLines);

        $lastLine = $rewrittenLines[\count($rewrittenLines) - 1];
        $beforeLast = $rewrittenLines[\count($rewrittenLines) - 2];

        self::assertSame("tracked_assets: 'BTC, AAPL, SPY, ETH'\n", $lastLine);
        self::assertSame("\n", $beforeLast);

        $reloaded = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        self::assertSame(['BTC', 'AAPL', 'SPY', 'ETH'], $reloaded->trackedAssets);
    }

    public function testSaveDoesNotTouchUnchangedKeys(): void
    {
        $originalContent = $this->copyFixtureToTemp('with-comments.yaml');
        $loader = new PixelCastConfigLoader($this->temporaryConfigPath);
        $config = $loader->load();

        new PixelCastConfigWriter($this->temporaryConfigPath)->save($config);

        self::assertSame($originalContent, file_get_contents($this->temporaryConfigPath));
    }

    public function testSaveFailsBeforeWriteWhenResultWouldBeInvalid(): void
    {
        $originalContent = $this->copyFixtureToTemp('with-comments.yaml');
        $originalMtime = filemtime($this->temporaryConfigPath);
        self::assertNotFalse($originalMtime);

        $invalidConfig = $this->buildInvalidConfigViaReflection();

        $this->expectException(PixelCastConfigException::class);

        try {
            new PixelCastConfigWriter($this->temporaryConfigPath)->save($invalidConfig);
        } finally {
            clearstatcache(true, $this->temporaryConfigPath);
            self::assertSame($originalContent, file_get_contents($this->temporaryConfigPath));
            self::assertSame($originalMtime, filemtime($this->temporaryConfigPath));
            self::assertFalse(is_file($this->temporaryConfigPath.'.tmp'));
        }
    }

    public function testSaveCreatesFileWhenAbsent(): void
    {
        self::assertFalse(is_file($this->temporaryConfigPath));

        $distConfig = new PixelCastConfigLoader(\dirname(__DIR__, 2).'/pixelcast.yaml.dist')->load();

        new PixelCastConfigWriter($this->temporaryConfigPath)->save($distConfig);

        self::assertTrue(is_file($this->temporaryConfigPath));

        $reloaded = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        self::assertEquals($distConfig, $reloaded);
    }

    public function testSaveQuotesValuesContainingColons(): void
    {
        $this->copyFixtureToTemp('with-comments.yaml');

        $loaded = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        $updated = new PixelCastConfig(
            deviceUrl: 'http://example.test/api',
            weatherInterval: $loaded->weatherInterval,
            trackerInterval: $loaded->trackerInterval,
            trackedAssets: $loaded->trackedAssets,
            weatherSource: $loaded->weatherSource,
            trackerSource: $loaded->trackerSource,
        );

        new PixelCastConfigWriter($this->temporaryConfigPath)->save($updated);

        $reloaded = new PixelCastConfigLoader($this->temporaryConfigPath)->load();
        self::assertSame('http://example.test/api', $reloaded->deviceUrl);
    }

    private function copyFixtureToTemp(string $fixtureName): string
    {
        $sourcePath = self::FIXTURES_DIR.'/'.$fixtureName;
        $content = file_get_contents($sourcePath);
        self::assertNotFalse($content);

        $written = file_put_contents($this->temporaryConfigPath, $content);
        self::assertNotFalse($written);

        return $content;
    }

    private function buildInvalidConfigViaReflection(): PixelCastConfig
    {
        $reflection = new \ReflectionClass(PixelCastConfig::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'deviceUrl' => 'http://pixelcast.test/api',
            'weatherInterval' => -1,
            'trackerInterval' => 30,
            'trackedAssets' => ['BTC'],
            'weatherSource' => 'openmeteo',
            'trackerSource' => 'yahoo-finance',
        ] as $propertyName => $value) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue($instance, $value);
        }

        return $instance;
    }
}
