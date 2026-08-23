<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Cache\CacheItemPoolInterface;

final class OpenApiValidatorFactory
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function create(string $specPath): ServerRequestValidator
    {
        if (!is_file($specPath)) {
            throw OpenApiSpecNotFoundException::forPath($specPath);
        }

        $validator = new ValidatorBuilder()
            ->fromYamlFile($specPath)
            ->setCache($this->cache)
            ->overrideCacheKey($this->cacheKeyFor($specPath))
            ->getServerRequestValidator();

        // The resolved schema graph is self-referencing (a $ref target points back at its parent),
        // so a cache hit rebuilds it through unserialize() every call. PHP's cycle collector does not
        // reclaim those cycles on its own fast enough here, and memory grows by roughly 2 MB per call
        // across the test suite's 57 kernel boots until it exhausts the process memory_limit. Forcing
        // a collection after each call keeps memory flat, matching the uncached code's behavior.
        gc_collect_cycles();

        return $validator;
    }

    public function createResponseValidator(ServerRequestValidator $requestValidator): ResponseValidator
    {
        return new ValidatorBuilder()
            ->fromSchema($requestValidator->getSchema())
            ->getResponseValidator();
    }

    // sync/openapi.yaml and its referenced schema files are only ever overwritten in place by
    // `make sync-api`, so a path-based cache key would keep serving a stale schema forever; hashing
    // the file contents makes any change a cache miss on the very next request.
    private function cacheKeyFor(string $specPath): string
    {
        $globResult = glob(\dirname($specPath).'/schemas/*.yaml');
        $schemaFiles = false !== $globResult ? $globResult : [];
        sort($schemaFiles);

        $digest = hash_init('xxh128');
        foreach ([$specPath, ...$schemaFiles] as $file) {
            hash_update_file($digest, $file);
        }

        return 'openapi_spec.'.hash_final($digest);
    }
}
