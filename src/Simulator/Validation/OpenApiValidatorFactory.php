<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;

final class OpenApiValidatorFactory
{
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

        return new ValidatorBuilder()
            ->fromSchema($openApi)
            ->getServerRequestValidator();
    }

    public function createResponseValidator(ServerRequestValidator $requestValidator): ResponseValidator
    {
        return new ValidatorBuilder()
            ->fromSchema($requestValidator->getSchema())
            ->getResponseValidator();
    }
}
