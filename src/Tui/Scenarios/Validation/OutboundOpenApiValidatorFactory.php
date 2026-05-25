<?php

declare(strict_types=1);

namespace App\Tui\Scenarios\Validation;

use App\Simulator\Validation\OpenApiSpecNotFoundException;
use App\Tui\Scenarios\DeviceBaseUrl;
use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Server;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OutboundOpenApiValidatorFactory
{
    private const string OPENAPI_SPEC_RELATIVE_PATH = '/sync/openapi.yaml';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default::PIXELCAST_DEVICE_BASE_URL)%')]
        private readonly ?string $deviceBaseUrl = null,
    ) {
    }

    public function create(): RequestValidator
    {
        $specPath = $this->projectDir.self::OPENAPI_SPEC_RELATIVE_PATH;

        if (!is_file($specPath)) {
            throw OpenApiSpecNotFoundException::forPath($specPath);
        }

        $openApi = Reader::readFromYamlFile(
            $specPath,
            OpenApi::class,
            ReferenceContext::RESOLVE_MODE_ALL,
        );

        // Spec advertises http://pixelcast.local/api; outbound calls target the configured device, so override servers.
        $openApi->servers = [new Server(['url' => DeviceBaseUrl::resolve($this->deviceBaseUrl)])];

        return new ValidatorBuilder()
            ->fromSchema($openApi)
            ->getRequestValidator();
    }
}
