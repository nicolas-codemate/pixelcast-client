<?php

declare(strict_types=1);

namespace App\Scenario\Validation;

use App\Client\DeviceBaseUrl;
use App\Simulator\Validation\OpenApiSpecNotFoundException;
use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Server;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Uri;
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

        // The /api prefix belongs to the firmware and stays as declared in the spec.
        $specServerUrl = $openApi->servers[0]->url;
        $openApi->servers = [new Server(['url' => $this->replaceServerAuthority($specServerUrl)])];

        return new ValidatorBuilder()
            ->fromSchema($openApi)
            ->getRequestValidator();
    }

    private function replaceServerAuthority(string $specServerUrl): string
    {
        $resolvedBaseUrl = DeviceBaseUrl::resolve($this->deviceBaseUrl);
        $deviceBaseUri = new Uri($resolvedBaseUrl);

        if ('' === $deviceBaseUri->getScheme() || '' === $deviceBaseUri->getAuthority()) {
            throw new \InvalidArgumentException(\sprintf('Device base URL "%s" is not a valid absolute URL.', $resolvedBaseUrl));
        }

        return (string) $deviceBaseUri->withPath(new Uri($specServerUrl)->getPath());
    }
}
