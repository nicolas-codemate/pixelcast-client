<?php

declare(strict_types=1);

namespace App\Tests\Tui\Inspector;

use App\Tui\Inspector\StateFormatter;
use PHPUnit\Framework\TestCase;

final class StateFormatterTest extends TestCase
{
    public function testFormatReturnsNoDataWhenStateIsNull(): void
    {
        self::assertSame('No data', StateFormatter::format(null));
    }

    public function testFormatReturnsNoDataWhenStateIsEmpty(): void
    {
        self::assertSame('No data', StateFormatter::format([]));
    }

    public function testFormatRendersAllKnownDomainsInDeterministicOrder(): void
    {
        $state = [
            'icons' => ['count' => 3],
            'settings' => ['theme' => 'dark'],
            'brightness' => ['level' => 80],
            'indicators' => ['battery' => true],
            'customApps' => ['installed' => ['weather', 'clock']],
            'notifications' => ['unread' => 0],
            'trackers' => ['active' => 2],
            'weather' => ['city' => 'Paris'],
        ];

        $output = StateFormatter::format($state);

        $expected = <<<EOT
            [weather]
              city: Paris
            [trackers]
              active: 2
            [notifications]
              unread: 0
            [customApps]
              installed: ["weather","clock"]
            [indicators]
              battery: true
            [brightness]
              level: 80
            [settings]
              theme: dark
            [icons]
              count: 3
            EOT;

        self::assertSame($expected, $output);
    }

    public function testFormatRendersUnknownDomainAfterKnownOnes(): void
    {
        $state = [
            'weather' => ['city' => 'Paris'],
            'zebra' => ['stripes' => 12],
            'alpha' => ['value' => 'a'],
        ];

        $output = StateFormatter::format($state);
        $outputLines = explode("\n", $output);

        $alphaPosition = array_search('[alpha]', $outputLines, true);
        $zebraPosition = array_search('[zebra]', $outputLines, true);
        $weatherPosition = array_search('[weather]', $outputLines, true);

        self::assertIsInt($alphaPosition);
        self::assertIsInt($zebraPosition);
        self::assertIsInt($weatherPosition);
        self::assertLessThan($alphaPosition, $weatherPosition);
        self::assertLessThan($zebraPosition, $alphaPosition);
    }

    public function testFormatShowsEmptyPlaceholderWhenDomainIsMissing(): void
    {
        $state = [
            'weather' => ['city' => 'Paris'],
        ];

        $output = StateFormatter::format($state);
        $outputLines = explode("\n", $output);

        $trackersHeaderPosition = array_search('[trackers]', $outputLines, true);
        self::assertIsInt($trackersHeaderPosition);
        self::assertSame('  (empty)', $outputLines[$trackersHeaderPosition + 1]);
    }
}
