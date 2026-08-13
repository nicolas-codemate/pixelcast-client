<?php

declare(strict_types=1);

namespace App\Tests\Client\CustomApp;

use App\Client\Color;
use App\Client\CustomApp\CustomAppPayload;
use App\Client\CustomApp\Zone;
use App\Client\StaleBehavior;
use App\Client\Text\PolymorphicTextField;
use App\Client\Text\TextSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomAppPayloadTest extends TestCase
{
    public function testTheSingleZoneFormSerializesEveryFieldInTheOrderTheDeviceReads(): void
    {
        $customApp = CustomAppPayload::createSingleZone(
            name: 'demo',
            text: 'Hello World',
            iconName: 'smiley',
            label: 'Greeting',
            color: Color::fromHexCode('#FF8800'),
            displayDurationMilliseconds: 10000,
            staleAfterInSeconds: 5400,
            staleBehavior: StaleBehavior::Hide,
        );

        self::assertSame([
            'text' => 'Hello World',
            'icon' => 'smiley',
            'label' => 'Greeting',
            'color' => '#FF8800',
            'duration' => 10000,
            'staleAfter' => 5400,
            'staleBehavior' => 'hide',
        ], $customApp->toArray());
    }

    public function testTheSingleZoneFormOmitsEveryUnsetField(): void
    {
        $customApp = CustomAppPayload::createSingleZone(name: 'demo', iconName: 'smiley');

        self::assertSame(['icon' => 'smiley'], $customApp->toArray());
    }

    public function testTheSingleZoneFormNeverCarriesTheZonesKey(): void
    {
        $customApp = CustomAppPayload::createSingleZone(name: 'demo', text: 'Hello World');

        self::assertArrayNotHasKey('zones', $customApp->toArray());
    }

    public function testTheMultiZoneFormSerializesItsZonesInOrder(): void
    {
        $customApp = CustomAppPayload::createMultiZone(
            name: 'rooms',
            zones: [
                Zone::create(text: '22.5C', iconName: 'thermo', label: 'Salon'),
                Zone::create(text: '19.1C', iconName: 'thermo', label: 'Chambre'),
            ],
            displayDurationMilliseconds: 10000,
        );

        self::assertSame([
            'zones' => [
                ['text' => '22.5C', 'icon' => 'thermo', 'label' => 'Salon'],
                ['text' => '19.1C', 'icon' => 'thermo', 'label' => 'Chambre'],
            ],
            'duration' => 10000,
        ], $customApp->toArray());
    }

    public function testTheMultiZoneFormNeverCarriesASingleZoneField(): void
    {
        $customApp = CustomAppPayload::createMultiZone(name: 'rooms', zones: self::buildZones(CustomAppPayload::MINIMUM_ZONES));

        $body = $customApp->toArray();

        self::assertArrayNotHasKey('text', $body);
        self::assertArrayNotHasKey('icon', $body);
        self::assertArrayNotHasKey('label', $body);
        self::assertArrayNotHasKey('color', $body);
    }

    public function testTheNameStaysOutOfTheBody(): void
    {
        $singleZoneApp = CustomAppPayload::createSingleZone(name: 'demo', text: 'Hello World');
        $multiZoneApp = CustomAppPayload::createMultiZone(name: 'rooms', zones: self::buildZones(CustomAppPayload::MINIMUM_ZONES));

        self::assertArrayNotHasKey('name', $singleZoneApp->toArray());
        self::assertArrayNotHasKey('name', $multiZoneApp->toArray());
    }

    public function testAnEmptyNameIsRejectedOnTheSingleZoneForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty name/');

        CustomAppPayload::createSingleZone(name: '', text: 'Hello World');
    }

    public function testAnEmptyNameIsRejectedOnTheMultiZoneForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty name/');

        CustomAppPayload::createMultiZone(name: '', zones: self::buildZones(CustomAppPayload::MINIMUM_ZONES));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideZoneCountsOutsideTheBounds(): iterable
    {
        yield 'one zone below the minimum' => [CustomAppPayload::MINIMUM_ZONES - 1];
        yield 'one zone above the maximum' => [CustomAppPayload::MAXIMUM_ZONES + 1];
    }

    #[DataProvider('provideZoneCountsOutsideTheBounds')]
    public function testAZoneCountOutsideTheBoundsIsRejected(int $zoneCount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/between 2 and 4 zones/');

        CustomAppPayload::createMultiZone(name: 'rooms', zones: self::buildZones($zoneCount));
    }

    public function testAsManyZonesAsTheBoundsAllowAreAccepted(): void
    {
        $customApp = CustomAppPayload::createMultiZone(name: 'rooms', zones: self::buildZones(CustomAppPayload::MAXIMUM_ZONES));

        self::assertCount(CustomAppPayload::MAXIMUM_ZONES, $customApp->toArray()['zones'] ?? []);
    }

    public function testATextOneCharacterOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/custom app text holds at most '.CustomAppPayload::MAXIMUM_TEXT_LENGTH.' characters, got 64/');

        CustomAppPayload::createSingleZone(name: 'demo', text: str_repeat('a', CustomAppPayload::MAXIMUM_TEXT_LENGTH + 1));
    }

    public function testALabelOneSegmentOverTheLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/custom app label holds at most '.CustomAppPayload::MAXIMUM_LABEL_SEGMENTS.' colored segments, got 9/');

        CustomAppPayload::createSingleZone(name: 'demo', label: self::buildSegmentedField(CustomAppPayload::MAXIMUM_LABEL_SEGMENTS + 1));
    }

    /**
     * @return iterable<string, array{StaleBehavior}>
     */
    public static function provideStaleBehaviorsTheCustomAppNeverRenders(): iterable
    {
        yield 'dim' => [StaleBehavior::Dim];
        yield 'badge' => [StaleBehavior::Badge];
    }

    #[DataProvider('provideStaleBehaviorsTheCustomAppNeverRenders')]
    public function testAStaleBehaviorTheCustomAppNeverRendersIsRejectedOnTheSingleZoneForm(StaleBehavior $staleBehavior): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/renders no "'.$staleBehavior->value.'" stale behavior/');

        CustomAppPayload::createSingleZone(name: 'demo', text: 'Hello World', staleBehavior: $staleBehavior);
    }

    #[DataProvider('provideStaleBehaviorsTheCustomAppNeverRenders')]
    public function testAStaleBehaviorTheCustomAppNeverRendersIsRejectedOnTheMultiZoneForm(StaleBehavior $staleBehavior): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/renders no "'.$staleBehavior->value.'" stale behavior/');

        CustomAppPayload::createMultiZone(
            name: 'rooms',
            zones: self::buildZones(CustomAppPayload::MINIMUM_ZONES),
            staleBehavior: $staleBehavior,
        );
    }

    public function testTheStaleBehaviorLeavingTheAppUntouchedIsAccepted(): void
    {
        $customApp = CustomAppPayload::createSingleZone(name: 'demo', text: 'Hello World', staleBehavior: StaleBehavior::None);

        self::assertSame('none', $customApp->toArray()['staleBehavior'] ?? null);
    }

    private static function buildSegmentedField(int $segmentCount): PolymorphicTextField
    {
        $segment = TextSegment::create('a', Color::fromHexCode('#FFFFFF'));

        return PolymorphicTextField::fromSegments(...array_fill(0, $segmentCount, $segment));
    }

    /**
     * @return list<Zone>
     */
    private static function buildZones(int $zoneCount): array
    {
        return array_map(
            static fn (int $index): Zone => Zone::create(text: 'zone'.$index, iconName: 'thermo'),
            range(1, $zoneCount),
        );
    }
}
