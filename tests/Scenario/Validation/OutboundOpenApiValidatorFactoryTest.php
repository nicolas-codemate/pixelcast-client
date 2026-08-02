<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Validation;

use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundOpenApiValidatorFactoryTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__.'/../../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function deviceBaseUrlProvider(): iterable
    {
        yield 'base url carrying the api prefix' => ['http://simulator:8080/api'];
        yield 'base url without the api prefix' => ['http://simulator:8080'];
    }

    #[DataProvider('deviceBaseUrlProvider')]
    public function testSpecPathPrefixIsKeptWhateverTheConfiguredBaseUrl(string $deviceBaseUrl): void
    {
        $validator = new OutboundOpenApiValidatorFactory(self::PROJECT_DIR, $deviceBaseUrl)->create();

        $matchedOperation = $validator->validate(new Request('GET', 'http://simulator:8080/api/weather'));

        self::assertSame('/weather', $matchedOperation->path());
    }

    public function testMalformedDeviceBaseUrlIsRejected(): void
    {
        $factory = new OutboundOpenApiValidatorFactory(self::PROJECT_DIR, 'not-a-url');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not-a-url');

        $factory->create();
    }
}
