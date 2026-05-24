<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Server;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;

final class OpenApiValidatorFactory
{
    private const string SIMULATOR_SERVER_URL = 'http://simulator:8080/';

    public function create(string $specPath): ServerRequestValidator
    {
        if (!is_file($specPath)) {
            throw OpenApiSpecNotFoundException::forPath($specPath);
        }

        $openApi = Reader::readFromYamlFile(
            $specPath,
            OpenApi::class,
            ReferenceContext::RESOLVE_MODE_ALL,
        );

        // Spec advertises http://pixelcast.local/api; the simulator serves bare paths, so override servers.
        $openApi->servers = [new Server(['url' => self::SIMULATOR_SERVER_URL])];

        return new ValidatorBuilder()
            ->fromSchema($openApi)
            ->getServerRequestValidator();
    }
}
