<?php

declare(strict_types=1);

namespace App\Tests\Provider\Tracker;

use App\Provider\Tracker\SparklinePeriodLabel;
use PHPUnit\Framework\TestCase;

final class SparklinePeriodLabelTest extends TestCase
{
    public function testADayCountIsWrittenAsAShortLabel(): void
    {
        self::assertSame('33d', SparklinePeriodLabel::forDayCount(33));
        self::assertSame('1d', SparklinePeriodLabel::forDayCount(1));
        self::assertSame('999d', SparklinePeriodLabel::forDayCount(999));
    }

    public function testAnUnreadableOrImplausibleDayCountHidesTheLabel(): void
    {
        self::assertSame('', SparklinePeriodLabel::forDayCount(null));
        self::assertSame('', SparklinePeriodLabel::forDayCount(0));
        self::assertSame('', SparklinePeriodLabel::forDayCount(-1));
        self::assertSame('', SparklinePeriodLabel::forDayCount(1000));
    }

    public function testEveryLabelFitsTheDeviceLimit(): void
    {
        foreach ([1, 9, 10, 99, 100, 999] as $dayCount) {
            self::assertLessThanOrEqual(4, mb_strlen(SparklinePeriodLabel::forDayCount($dayCount)));
        }
    }
}
