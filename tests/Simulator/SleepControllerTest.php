<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class SleepControllerTest extends SimulatorWebTestCase
{
    private const array NIGHT_SCHEDULE_PAYLOAD = [
        'enabled' => true,
        'display_mode' => 'black',
        'schedule' => [
            'monday' => ['all_day' => false, 'slots' => [['start' => '00:00', 'end' => '07:00']]],
            'tuesday' => ['all_day' => false, 'slots' => []],
        ],
    ];

    private const int FAR_FUTURE_EPOCH = 4_102_444_800;

    public function testGetBeforeAnyPushReportsAnAwakeDeviceAndADisabledSchedule(): void
    {
        $this->client->request('GET', '/api/sleep');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertFalse($payload['sleeping'] ?? null);
        self::assertArrayNotHasKey('reason', $payload);

        $config = $payload['config'] ?? null;
        self::assertIsArray($config);
        self::assertFalse($config['enabled'] ?? null);
        self::assertSame([], $config['schedule'] ?? null);
    }

    public function testAPushedScheduleIsReadBack(): void
    {
        $this->postJson('/api/sleep', self::NIGHT_SCHEDULE_PAYLOAD);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/sleep');
        $config = $this->jsonResponse()['config'] ?? null;
        self::assertIsArray($config);

        self::assertTrue($config['enabled'] ?? null);
        self::assertSame('black', $config['display_mode'] ?? null);
        self::assertSame(self::NIGHT_SCHEDULE_PAYLOAD['schedule'], $config['schedule'] ?? null);
    }

    public function testAPushCarryingOnlyEnabledLeavesTheScheduleIntact(): void
    {
        $this->postJson('/api/sleep', self::NIGHT_SCHEDULE_PAYLOAD);

        $this->postJson('/api/sleep', ['enabled' => false]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/sleep');
        $config = $this->jsonResponse()['config'] ?? null;
        self::assertIsArray($config);

        self::assertFalse($config['enabled'] ?? null);
        self::assertSame(self::NIGHT_SCHEDULE_PAYLOAD['schedule'], $config['schedule'] ?? null);
    }

    public function testAScheduleCoveringEveryDayReportsASleepingDevice(): void
    {
        $this->postJson('/api/sleep', [
            'enabled' => true,
            'schedule' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['all_day' => true, 'slots' => []],
            ),
        ]);

        $this->client->request('GET', '/api/sleep');
        $payload = $this->jsonResponse();

        self::assertTrue($payload['sleeping'] ?? null);
        self::assertSame('schedule', $payload['reason'] ?? null);
    }

    public function testAManualOverrideIsReportedWithItsExpiry(): void
    {
        $this->postJson('/api/sleep', ['sleep_until' => self::FAR_FUTURE_EPOCH]);

        $this->client->request('GET', '/api/sleep');
        $payload = $this->jsonResponse();

        self::assertTrue($payload['sleeping'] ?? null);
        self::assertSame('override', $payload['reason'] ?? null);
        self::assertSame(self::FAR_FUTURE_EPOCH, $payload['until'] ?? null);
    }

    public function testADisplayModeOutsideTheSpecIsRejected(): void
    {
        $this->postJson('/api/sleep', ['display_mode' => 'rainbow']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertArrayHasKey('error', $payload);
    }
}
