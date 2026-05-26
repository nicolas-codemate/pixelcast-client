<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP85Migration' => true,
        'declare_strict_types' => true,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/cache/php-cs-fixer.cache');
