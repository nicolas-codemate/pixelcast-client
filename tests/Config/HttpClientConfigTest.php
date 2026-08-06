<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Tests\Factory\SyncsConfigLoaderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Boursorama answers 200 with an empty quote list when these headers are missing, so losing
 * them would read as every configured code having become unknown.
 */
final class HttpClientConfigTest extends TestCase
{
    private const string HTTP_CLIENT_FILE = 'config/packages/http_client.yaml';

    public function testTheBoursoramaClientCarriesTheHeadersTheEndpointRequires(): void
    {
        $headers = self::boursoramaClientHeaders();

        self::assertSame('XMLHttpRequest', $headers['X-Requested-With'] ?? null);

        $userAgent = $headers['User-Agent'] ?? null;
        self::assertIsString($userAgent);
        self::assertStringStartsWith('Mozilla/', $userAgent);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function boursoramaClientHeaders(): array
    {
        $httpClientTree = Yaml::parseFile(SyncsConfigLoaderFactory::projectFilePath(self::HTTP_CLIENT_FILE));
        self::assertIsArray($httpClientTree);

        $httpClientSection = $httpClientTree['framework'];
        self::assertIsArray($httpClientSection);
        self::assertIsArray($httpClientSection['http_client']);

        $scopedClients = $httpClientSection['http_client']['scoped_clients'] ?? null;
        self::assertIsArray($scopedClients);

        $boursoramaClient = $scopedClients['boursorama.client'] ?? null;
        self::assertIsArray($boursoramaClient);

        $headers = $boursoramaClient['headers'] ?? null;
        self::assertIsArray($headers);

        return $headers;
    }
}
