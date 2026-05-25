<?php

declare(strict_types=1);

namespace App\Tests\Tui\Inspector;

use App\Tui\Inspector\RequestLogFormatter;
use PHPUnit\Framework\TestCase;

final class RequestLogFormatterTest extends TestCase
{
    public function testFormatReturnsNoDataWhenRequestsIsNull(): void
    {
        self::assertSame('No data', RequestLogFormatter::format(null));
    }

    public function testFormatReturnsNoDataWhenRequestsIsEmpty(): void
    {
        self::assertSame('No data', RequestLogFormatter::format([]));
    }

    public function testFormatRendersNewestFirst(): void
    {
        $requests = [
            [
                'method' => 'GET',
                'path' => '/api/oldest',
                'timestamp' => '2026-05-25T10:00:00+00:00',
                'validation' => ['valid' => true],
            ],
            [
                'method' => 'POST',
                'path' => '/api/middle',
                'timestamp' => '2026-05-25T10:00:01+00:00',
                'validation' => ['valid' => true],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/newest',
                'timestamp' => '2026-05-25T10:00:02+00:00',
                'validation' => ['valid' => true],
            ],
        ];

        $output = RequestLogFormatter::format($requests);
        $lines = explode("\n", $output);

        self::assertCount(3, $lines);
        self::assertStringContainsString('/api/newest', $lines[0]);
        self::assertStringContainsString('/api/middle', $lines[1]);
        self::assertStringContainsString('/api/oldest', $lines[2]);
    }

    public function testFormatRendersOkValidation(): void
    {
        $requests = [
            [
                'method' => 'GET',
                'path' => '/api/health',
                'timestamp' => '2026-05-25T09:15:30+00:00',
                'validation' => ['valid' => true],
            ],
        ];

        $output = RequestLogFormatter::format($requests);

        self::assertSame('09:15:30  GET  /api/health  OK', $output);
    }

    public function testFormatRendersFailValidationWithTruncatedError(): void
    {
        $longErrorMessage = str_repeat('x', 120);
        $requests = [
            [
                'method' => 'POST',
                'path' => '/api/broken',
                'timestamp' => '2026-05-25T09:15:30+00:00',
                'validation' => ['valid' => false, 'error' => $longErrorMessage],
            ],
        ];

        $output = RequestLogFormatter::format($requests);

        self::assertSame(
            '09:15:30  POST  /api/broken  FAIL: '.str_repeat('x', 60),
            $output,
        );
    }

    public function testFormatHandlesMalformedTimestampGracefully(): void
    {
        $requests = [
            [
                'method' => 'GET',
                'path' => '/api/x',
                'timestamp' => 'not-a-date',
                'validation' => ['valid' => true],
            ],
        ];

        $output = RequestLogFormatter::format($requests);

        self::assertSame('??:??:??  GET  /api/x  OK', $output);
    }
}
