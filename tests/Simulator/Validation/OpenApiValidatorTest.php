<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Validation;

use App\Simulator\Validation\OpenApiValidator;
use App\Simulator\Validation\OpenApiValidatorFactory;
use App\Tests\Factory\SyncsConfigLoaderFactory;
use League\OpenAPIValidation\PSR7\OperationAddress;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

final class OpenApiValidatorTest extends TestCase
{
    private const string SPEC_FILE = 'sync/openapi.yaml';

    // Parsing the spec and the schema files it references costs about 140 ms, so it is done
    // once for the whole class rather than once per test method.
    private static ?OpenApiValidator $validator = null;

    public function testTrackerListMatchingItsSchemaIsAccepted(): void
    {
        $validationResult = self::validator()->validateResponse(
            new OperationAddress('/trackers', 'get'),
            new JsonResponse(['trackers' => [self::trackerSummary()], 'count' => 1]),
        );

        self::assertTrue($validationResult->valid, (string) $validationResult->errorMessage);
    }

    public function testTrackerCountSentAsAStringIsRejected(): void
    {
        $validationResult = self::validator()->validateResponse(
            new OperationAddress('/trackers', 'get'),
            new JsonResponse(['trackers' => [self::trackerSummary()], 'count' => '1']),
        );

        self::assertFalse($validationResult->valid);
        self::assertStringContainsString('integer', (string) $validationResult->errorMessage);
        self::assertStringContainsString('string', (string) $validationResult->errorMessage);
    }

    /**
     * @return array<string, mixed>
     */
    private static function trackerSummary(): array
    {
        return [
            'name' => 'BTC',
            'symbol' => 'BTC',
            'value' => 98452.30,
            'change' => 2.14,
            'age' => 12,
            'stale' => false,
            'staleAfter' => 3600,
            'staleBehavior' => 'dim',
        ];
    }

    private static function validator(): OpenApiValidator
    {
        if (null !== self::$validator) {
            return self::$validator;
        }

        $factory = new OpenApiValidatorFactory();
        $requestValidator = $factory->create(SyncsConfigLoaderFactory::projectFilePath(self::SPEC_FILE));

        return self::$validator = new OpenApiValidator(
            $requestValidator,
            $factory->createResponseValidator($requestValidator),
            new PsrHttpFactory(),
        );
    }
}
