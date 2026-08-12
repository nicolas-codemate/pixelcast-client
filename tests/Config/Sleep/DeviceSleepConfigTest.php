<?php

declare(strict_types=1);

namespace App\Tests\Config\Sleep;

use App\Config\Sleep\DeviceSleepConfig;
use PHPUnit\Framework\TestCase;

final class DeviceSleepConfigTest extends TestCase
{
    public function testTheDaysLeftOutOfTheSectionAreStillSentWithoutAnyWindow(): void
    {
        $sleepConfig = self::sleepConfig(['days' => ['mon', 'tue']]);

        self::assertSame([
            'monday' => ['00:00-07:00'],
            'tuesday' => ['00:00-07:00'],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [],
            'sunday' => [],
        ], self::windowsAsStrings($sleepConfig));
    }

    public function testWithoutDeclaredDaysEverySevenDayCarriesTheWindows(): void
    {
        $sleepConfig = self::sleepConfig([]);

        self::assertSame(
            array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-07:00']),
            self::windowsAsStrings($sleepConfig),
        );
    }

    public function testThePushedPayloadSpeaksTheFirmwareVocabulary(): void
    {
        $payload = self::sleepConfig(['displayMode' => 'clock', 'days' => ['mon']])->toSleepPayload();

        self::assertTrue($payload->enabled);
        self::assertSame('clock', $payload->displayMode);
        self::assertSame(
            ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]],
            $payload->toArray()['schedule']['monday'],
        );
        self::assertSame(['all_day' => false, 'slots' => []], $payload->toArray()['schedule']['sunday']);
    }

    /**
     * @param array<string, mixed> $sleepOptions
     */
    private static function sleepConfig(array $sleepOptions): DeviceSleepConfig
    {
        $sleepConfig = DeviceSleepConfig::optionalFromConfigTree([
            DeviceSleepConfig::OPTION_KEY => array_merge(
                ['enabled' => true, 'windows' => [['from' => '00:00', 'to' => '07:00']]],
                $sleepOptions,
            ),
        ]);

        self::assertNotNull($sleepConfig);

        return $sleepConfig;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function windowsAsStrings(DeviceSleepConfig $sleepConfig): array
    {
        return array_map(
            static fn (array $windows): array => array_map(strval(...), $windows),
            $sleepConfig->sleepWindowsByFirmwareDayName(),
        );
    }
}
