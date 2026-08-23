<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use App\Simulator\Controller\GaugeController;
use Symfony\Component\HttpFoundation\Response;

final class GaugeControllerTest extends SimulatorWebTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validGaugePayload(): array
    {
        return [
            'title' => 'Disks',
            'rows' => [
                ['label' => 'root', 'percent' => 42],
                ['label' => 'data', 'percent' => 71],
            ],
        ];
    }

    public function testPostThenListIncludesGauge(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $first = $this->firstItemOfList('/api/gauges', 'gauges');

        self::assertSame(1, $this->jsonResponse()['count'] ?? null);
        self::assertSame('disks', $first['name'] ?? null);
        self::assertSame('Disks', $first['title'] ?? null);
        self::assertSame(2, $first['rowCount'] ?? null);
    }

    public function testAColoredTitleIsListedAsPlainTextAndStoredAsSent(): void
    {
        $coloredTitle = [
            ['t' => 'Claude ', 'c' => '#FFFFFF'],
            ['t' => 'Max', 'c' => '#D97757'],
        ];
        $this->postJson('/api/gauge?name=claude', [
            'title' => $coloredTitle,
            'rows' => [['label' => '5h', 'percent' => 41]],
        ]);

        self::assertSame('Claude Max', $this->firstItemOfList('/api/gauges', 'gauges')['title'] ?? null);

        $this->client->request('GET', '/api/gauge?name=claude');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame($coloredTitle, $this->jsonResponse()['title'] ?? null);
    }

    public function testARowColorPushedAsAnRgbArrayIsReadBackAsAHexString(): void
    {
        $this->postJson('/api/gauge?name=disks', [
            'title' => 'Disks',
            'rows' => [['label' => 'root', 'percent' => 42, 'color' => [255, 136, 0]]],
        ]);

        self::assertSame('#FF8800', $this->firstItemOfList('/api/gauge?name=disks', 'rows')['color'] ?? null);
    }

    public function testARowNoteColorPushedAsAPackedIntegerIsReadBackAsAHexString(): void
    {
        $this->postJson('/api/gauge?name=disks', [
            'title' => 'Disks',
            'rows' => [['label' => 'root', 'percent' => 42, 'noteColor' => 16_746_496]],
        ]);

        self::assertSame('#FF8800', $this->firstItemOfList('/api/gauge?name=disks', 'rows')['noteColor'] ?? null);
    }

    public function testARowCarriesTheContractDefaultsForWhatThePushOmitted(): void
    {
        $this->postJson('/api/gauge?name=disks', ['rows' => [['label' => 'root']]]);

        $row = $this->firstItemOfList('/api/gauge?name=disks', 'rows');

        self::assertSame(GaugeController::DEFAULT_ROW_PERCENT, $row['percent'] ?? null);
        self::assertSame(GaugeController::DEFAULT_ROW_COLOR, $row['color'] ?? null);
        self::assertSame(GaugeController::DEFAULT_ROW_NOTE_COLOR, $row['noteColor'] ?? null);
    }

    public function testAKeyTheRowSchemaDoesNotDeclareIsNotReadBack(): void
    {
        $this->postJson('/api/gauge?name=disks', [
            'rows' => [['label' => 'root', 'percent' => 42, 'extra' => 'not declared']],
        ]);

        self::assertArrayNotHasKey('extra', $this->firstItemOfList('/api/gauge?name=disks', 'rows'));
    }

    public function testGetExistingReturnsPayload(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());

        $this->client->request('GET', '/api/gauge?name=disks');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('disks', $payload['name'] ?? null);
        self::assertSame('Disks', $payload['title'] ?? null);
    }

    public function testGetExistingAnnouncesTheContractFreshnessDefaults(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());

        $this->client->request('GET', '/api/gauge?name=disks');
        $payload = $this->jsonResponse();

        self::assertSame(0, $payload['age'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
        self::assertSame(GaugeController::DEFAULT_STALE_AFTER_SECONDS, $payload['staleAfter'] ?? null);
        self::assertSame(GaugeController::DEFAULT_STALE_BEHAVIOR, $payload['staleBehavior'] ?? null);
    }

    public function testThePushedFreshnessPolicyIsReadBackFromTheSingleGauge(): void
    {
        $this->postJson('/api/gauge?name=disks', [
            ...self::validGaugePayload(),
            'staleAfter' => 7200,
            'staleBehavior' => 'badge',
        ]);

        $this->client->request('GET', '/api/gauge?name=disks');
        $payload = $this->jsonResponse();

        self::assertSame(7200, $payload['staleAfter'] ?? null);
        self::assertSame('badge', $payload['staleBehavior'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }

    public function testAStaleAfterOfZeroIsReadBackAsNeverGoingStale(): void
    {
        $this->postJson('/api/gauge?name=disks', [...self::validGaugePayload(), 'staleAfter' => 0]);

        $this->client->request('GET', '/api/gauge?name=disks');
        $payload = $this->jsonResponse();

        self::assertSame(0, $payload['staleAfter'] ?? null);
        self::assertFalse($payload['stale'] ?? null);
    }

    public function testGetUnknownReturns404(): void
    {
        $this->client->request('GET', '/api/gauge?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheGauge(): void
    {
        $this->postJson('/api/gauge?name=disks', self::validGaugePayload());

        $this->deleteRequest('/api/gauge?name=disks');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/gauges');
        self::assertSame(0, $this->jsonResponse()['count'] ?? null);
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/api/gauge?name=NONEXISTENT');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('not found', $payload['error'] ?? null);
    }
}
