<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\LevelSetList;

/*
 * Rector keeps the codebase from drifting behind its declared PHP level.
 *
 * That matters more than usual with agents in the loop: an agent imitates the
 * code it reads, so a stale pattern left in one file propagates into every file
 * written afterwards. `make rector` runs --dry-run and is part of `make check`;
 * `make rector-fix` applies.
 */
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->parallel();
    $rectorConfig->cacheDirectory(__DIR__ . '/var/rector');

    $rectorConfig->paths([
        __DIR__ . '/bin/console',
        __DIR__ . '/config',
        __DIR__ . '/src',
        // __DIR__ . '/migrations',  // migrations are historical artifacts - do not rewrite them
        // __DIR__ . '/tests',       // enable once the suite is stable
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_83,
    ]);

    $rectorConfig->skip([
        // Adds Stringable to anything with __toString; noisy and rarely what you meant.
        StringableForToStringRector::class,
        // #[\Override] on every inherited method; churns diffs without adding safety here.
        AddOverrideAttributeToOverriddenMethodsRector::class,
        // Typed class constants; enable deliberately, not as a mass rewrite.
        AddTypeToConstRector::class,
    ]);
};
