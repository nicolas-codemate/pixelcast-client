<?php

declare(strict_types=1);

namespace App\Tests\Config\Sleep;

use App\Config\Sleep\DeviceSleepConfig;
use PHPUnit\Framework\TestCase;

final class DeviceSleepConfigTest extends TestCase
{
    private const array DAY_CARRYING_THE_WINDOW = ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]];
    private const array DAY_WITHOUT_ANY_WINDOW = ['all_day' => false, 'slots' => []];

    public function testTheDaysLeftOutOfTheSectionAreStillSentWithoutAnyWindow(): void
    {
        $schedule = self::sleepConfig(['days' => ['mon', 'tue']])->toSleepPayload()->toArray()['schedule'];

        self::assertSame([
            'monday' => self::DAY_CARRYING_THE_WINDOW,
            'tuesday' => self::DAY_CARRYING_THE_WINDOW,
            'wednesday' => self::DAY_WITHOUT_ANY_WINDOW,
            'thursday' => self::DAY_WITHOUT_ANY_WINDOW,
            'friday' => self::DAY_WITHOUT_ANY_WINDOW,
            'saturday' => self::DAY_WITHOUT_ANY_WINDOW,
            'sunday' => self::DAY_WITHOUT_ANY_WINDOW,
        ], $schedule);
    }

    public function testWithoutDeclaredDaysEverySevenDayCarriesTheWindows(): void
    {
        $schedule = self::sleepConfig([])->toSleepPayload()->toArray()['schedule'];

        self::assertSame(
            array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], self::DAY_CARRYING_THE_WINDOW),
            $schedule,
        );
    }

    public function testThePushedPayloadSpeaksTheFirmwareVocabulary(): void
    {
        $payload = self::sleepConfig(['displayMode' => 'clock', 'days' => ['mon']])->toSleepPayload();

        self::assertTrue($payload->enabled);
        self::assertSame('clock', $payload->displayMode);
        self::assertSame(self::DAY_CARRYING_THE_WINDOW, $payload->toArray()['schedule']['monday']);
        self::assertSame(self::DAY_WITHOUT_ANY_WINDOW, $payload->toArray()['schedule']['sunday']);
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
}
