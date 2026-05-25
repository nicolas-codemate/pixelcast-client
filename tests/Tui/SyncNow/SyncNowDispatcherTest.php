<?php

declare(strict_types=1);

namespace App\Tests\Tui\SyncNow;

use App\Tests\Tui\SyncNow\Stub\CapturingMessageBusStub;
use App\Tests\Tui\SyncNow\Stub\ThrowingMessageBusStub;
use App\Tui\SyncNow\SyncNowDispatcher;
use App\Tui\SyncNow\SyncNowResultKind;
use App\Tui\SyncNow\SyncTarget;
use PHPUnit\Framework\TestCase;

final class SyncNowDispatcherTest extends TestCase
{
    public function testNotWiredWhenMessageClassDoesNotExist(): void
    {
        $bus = new CapturingMessageBusStub();
        $dispatcher = new SyncNowDispatcher($bus);

        $result = $dispatcher->dispatch(SyncTarget::Weather);

        self::assertSame(SyncNowResultKind::NotWired, $result->kind);
        self::assertSame('App\\Message\\SyncWeatherMessage', $result->message);
        self::assertSame([], $bus->dispatched);
    }

    public function testDispatchedWhenMessageClassExists(): void
    {
        $bus = new CapturingMessageBusStub();
        $dispatcher = new SyncNowDispatcher($bus);

        $result = $dispatcher->dispatchClass(DummySyncMessage::class);

        self::assertSame(SyncNowResultKind::Dispatched, $result->kind);
        self::assertSame(DummySyncMessage::class, $result->message);
        self::assertCount(1, $bus->dispatched);
        self::assertInstanceOf(DummySyncMessage::class, $bus->dispatched[0]);
    }

    public function testDispatchErrorWhenBusThrows(): void
    {
        $bus = new ThrowingMessageBusStub(new \RuntimeException('bus down'));
        $dispatcher = new SyncNowDispatcher($bus);

        $result = $dispatcher->dispatchClass(DummySyncMessage::class);

        self::assertSame(SyncNowResultKind::DispatchError, $result->kind);
        self::assertStringContainsString('bus down', $result->message);
    }
}

final class DummySyncMessage
{
}
