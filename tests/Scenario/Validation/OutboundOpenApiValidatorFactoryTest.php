<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Validation;

use App\Scenario\Validation\OutboundOpenApiValidatorFactory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboundOpenApiValidatorFactoryTest extends KernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        /** @var string $projectDir */
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->projectDir = $projectDir;
    }

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
        $validator = new OutboundOpenApiValidatorFactory($this->projectDir, $deviceBaseUrl)->create();

        $matchedOperation = $validator->validate(new Request('GET', 'http://simulator:8080/api/weather'));

        self::assertSame('/weather', $matchedOperation->path());
    }

    public function testMalformedDeviceBaseUrlIsRejected(): void
    {
        $factory = new OutboundOpenApiValidatorFactory($this->projectDir, 'not-a-url');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not-a-url');

        $factory->create();
    }
}
