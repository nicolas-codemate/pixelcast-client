<?php

declare(strict_types=1);

namespace App\Tests\Client\CustomApp;

use App\Client\Color;
use App\Client\CustomApp\Zone;
use App\Client\Text\PolymorphicTextField;
use App\Client\Text\TextSegment;
use PHPUnit\Framework\TestCase;

final class ZoneTest extends TestCase
{
    public function testSerializationCarriesEveryFieldInTheOrderTheDeviceReads(): void
    {
        $zone = Zone::create(
            text: '22.5C',
            iconName: 'thermo',
            label: 'Salon',
            color: Color::fromHexCode('#FF8800'),
        );

        self::assertSame([
            'text' => '22.5C',
            'icon' => 'thermo',
            'label' => 'Salon',
            'color' => '#FF8800',
        ], $zone->toArray());
    }

    public function testSerializationOmitsEveryUnsetField(): void
    {
        $zone = Zone::create(iconName: 'thermo');

        self::assertSame(['icon' => 'thermo'], $zone->toArray());
    }

    public function testASegmentedTextIsSerializedAsColoredPairs(): void
    {
        $zone = Zone::create(
            text: PolymorphicTextField::fromSegments(
                TextSegment::create('22.5', Color::fromHexCode('#FF8800')),
                TextSegment::create('C', Color::fromHexCode('#666666')),
            ),
        );

        self::assertSame([
            'text' => [['t' => '22.5', 'c' => '#FF8800'], ['t' => 'C', 'c' => '#666666']],
        ], $zone->toArray());
    }

    public function testATextOneCharacterOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/zone text holds at most '.Zone::MAXIMUM_TEXT_LENGTH.' characters, got 32/');

        Zone::create(text: str_repeat('a', Zone::MAXIMUM_TEXT_LENGTH + 1));
    }

    public function testALabelOneSegmentOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/zone label holds at most '.Zone::MAXIMUM_LABEL_SEGMENTS.' colored segments, got 9/');

        Zone::create(label: self::buildSegmentedField(Zone::MAXIMUM_LABEL_SEGMENTS + 1));
    }

    private static function buildSegmentedField(int $segmentCount): PolymorphicTextField
    {
        $segment = TextSegment::create('a', Color::fromHexCode('#FFFFFF'));

        return PolymorphicTextField::fromSegments(...array_fill(0, $segmentCount, $segment));
    }
}
