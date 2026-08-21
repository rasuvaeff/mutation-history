<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    // Cli::describe() reads three `mixed` array values from Datum::$meta and
    // narrows each with is_string()/is_int(). Psalm's MixedAssignment check
    // needs an explicit `@var mixed` on each of the three — including the
    // last one, which this rule calls "useless" because nothing downstream
    // of it happens to trip Psalm on its own. Removing it un-breaks `composer
    // psalm`; rector and psalm disagree here, psalm's build gate wins.
    ->withSkip([RemoveUselessVarTagRector::class => [__DIR__ . '/src/Cli.php']]);
