<?php

declare(strict_types=1);

namespace App\Tests\Simulator;

use Symfony\Component\HttpFoundation\Response;

final class CustomAppControllerTest extends SimulatorWebTestCase
{
    public function testPostThenListIncludesApp(): void
    {
        $this->postJson('/api/custom?name=foo', self::customAppPushPayload());

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );

        $this->client->request('GET', '/api/apps');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        $apps = $payload['apps'] ?? null;
        self::assertIsArray($apps);
        self::assertNotEmpty($apps);

        $ids = array_map(
            static fn (mixed $entry): string => \is_array($entry) && \is_string($entry['id'] ?? null) ? $entry['id'] : '',
            $apps,
        );
        self::assertContains('foo', $ids);
    }

    public function testDeleteUnknownReturns404(): void
    {
        $this->deleteRequest('/api/custom?name=foo');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $payload = $this->jsonResponse();
        self::assertSame('not found', $payload['error'] ?? null);
    }

    public function testAColorPushedAsAnRgbArrayIsListedAsAHexString(): void
    {
        $this->pushCustomApp('rgb', ['text' => 'hi', 'color' => [255, 136, 0]]);

        $listedApp = $this->firstItemOfList('/api/apps', 'apps');

        self::assertSame('rgb', $listedApp['id'] ?? null);
        self::assertSame('#FF8800', $listedApp['color'] ?? null);
    }

    public function testAColorPushedAsAPackedIntegerIsListedAsAHexString(): void
    {
        $this->pushCustomApp('packed', ['text' => 'hi', 'color' => 16746496]);

        $listedApp = $this->firstItemOfList('/api/apps', 'apps');

        self::assertSame('#FF8800', $listedApp['color'] ?? null);
    }

    public function testZoneColorsAreListedAsHexStringsToo(): void
    {
        $this->pushCustomApp('dashboard', [
            'zones' => [
                ['text' => 'left', 'color' => [255, 136, 0]],
                ['text' => 'right', 'color' => 16746496],
            ],
        ]);

        $listedApp = $this->firstItemOfList('/api/apps', 'apps');
        $listedZones = $listedApp['zones'] ?? null;
        self::assertIsArray($listedZones);

        $leftZone = $listedZones[0] ?? null;
        self::assertIsArray($leftZone);
        self::assertSame('#FF8800', $leftZone['color'] ?? null);

        $rightZone = $listedZones[1] ?? null;
        self::assertIsArray($rightZone);
        self::assertSame('#FF8800', $rightZone['color'] ?? null);
    }

    public function testALifetimePushIsProjectedAsStaleAfter(): void
    {
        $this->pushCustomApp('legacy', ['text' => 'hi', 'lifetime' => 300]);

        $listedApp = $this->firstItemOfList('/api/apps', 'apps');

        self::assertSame(300, $listedApp['staleAfter'] ?? null);
    }

    public function testAPushedStaleAfterWinsOverTheDeprecatedLifetime(): void
    {
        $this->pushCustomApp('legacy', ['text' => 'hi', 'staleAfter' => 120, 'lifetime' => 300]);

        $listedApp = $this->firstItemOfList('/api/apps', 'apps');

        self::assertSame(120, $listedApp['staleAfter'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pushCustomApp(string $name, array $payload): void
    {
        $this->postJson('/api/custom?name='.$name, $payload);

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
