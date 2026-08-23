<?php

declare(strict_types=1);

namespace App\Tests\Device;

use App\Client\Settings\BrightnessLevel;
use App\Device\LastPushedBrightnessStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class LastPushedBrightnessStoreTest extends TestCase
{
    public function testAStoreThatWasNeverWrittenKnowsNoLevel(): void
    {
        self::assertNull(new LastPushedBrightnessStore(new ArrayAdapter())->lastPushedLevel());
    }

    public function testTheLastRecordedLevelIsRead(): void
    {
        $store = new LastPushedBrightnessStore(new ArrayAdapter());

        $store->recordPushedLevel(BrightnessLevel::create(20));

        self::assertSame(20, $store->lastPushedLevel());
    }

    public function testASecondRecordReplacesTheFirst(): void
    {
        $store = new LastPushedBrightnessStore(new ArrayAdapter());

        $store->recordPushedLevel(BrightnessLevel::create(20));
        $store->recordPushedLevel(BrightnessLevel::create(120));

        self::assertSame(120, $store->lastPushedLevel());
    }

    public function testTwoWritersOnTheSamePoolSeeOneAnother(): void
    {
        $sharedPool = new ArrayAdapter();
        $theScheduler = new LastPushedBrightnessStore($sharedPool);
        $theSettingsCommand = new LastPushedBrightnessStore($sharedPool);

        $theSettingsCommand->recordPushedLevel(BrightnessLevel::create(120));

        self::assertSame(120, $theScheduler->lastPushedLevel());
    }
}
