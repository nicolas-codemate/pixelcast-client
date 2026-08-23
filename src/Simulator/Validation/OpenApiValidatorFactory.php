<?php

declare(strict_types=1);

namespace App\Simulator\Validation;

use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Cache\CacheItemPoolInterface;

final class OpenApiValidatorFactory
{
    private const int CACHED_SCHEMA_TTL_IN_SECONDS = 31536000;

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
            ->setCache($this->cache, self::CACHED_SCHEMA_TTL_IN_SECONDS)
            ->overrideCacheKey($this->cacheKeyFor($specPath))
            ->getServerRequestValidator();

        // A cache hit rebuilds the schema through unserialize(), and the resolved graph is
        // self-referencing, so the discarded copies form cycles PHP never collects on its own: they
        // pile up at roughly 2 MB per call until a process that builds several hits its memory_limit.
        gc_collect_cycles();

        return $validator;
    }

    public function createResponseValidator(ServerRequestValidator $requestValidator): ResponseValidator
    {
        return new ValidatorBuilder()
            ->fromSchema($requestValidator->getSchema())
            ->getResponseValidator();
    }

    // `make sync-api` overwrites the spec files in place, and the library's default cache key is the
    // file path, so only the contents can tell a stale entry from a fresh one.
    private function cacheKeyFor(string $specPath): string
    {
        $globResult = glob(\dirname($specPath).'/schemas/*.yaml');
        $schemaFiles = false !== $globResult ? $globResult : [];

        $digest = hash_init('xxh128');
        foreach ([$specPath, ...$schemaFiles] as $file) {
            hash_update_file($digest, $file);
        }

        return 'openapi_spec.'.hash_final($digest);
    }
}
