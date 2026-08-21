<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\QualityLedger\Badge;
use Rasuvaeff\QualityLedger\BadgeSvg;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;

/**
 * What `bin/mutation-history badge` does, spelled out through the public API:
 * record a run, read the metric's trend, render its latest value as a
 * self-contained SVG. No shields.io and no network — the file is the whole
 * artifact.
 *
 * The two CLI lines this replaces, for a CI job:
 *
 *   vendor/bin/mutation-history digest --scope=acme/widgets --run="$GITHUB_SHA" \
 *     --log=build/infection-log.json --msi=98.65
 *   vendor/bin/mutation-history badge --scope=acme/widgets --out=build/msi.svg
 */
$dir = sys_get_temp_dir() . '/mutation-history-badge-example-' . bin2hex(random_bytes(4));
$ledger = new Ledger(id: new MutantId(), storage: new LocalFileStorage($dir));

$log = (string) json_encode([
    'escaped' => [[
        'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/RetryPolicy.php', 'originalStartLine' => 42],
        'diff' => '-        return $this->attempts < $this->max;' . "\n" . '+        return true;',
    ]],
    'killed' => [[
        'mutator' => ['mutatorName' => 'Identical', 'originalFilePath' => 'src/RetryPolicy.php', 'originalStartLine' => 12],
        'diff' => '-        if ($a === $b) {' . "\n" . '+        if ($a !== $b) {',
    ]],
]);

$ledger->append((new InfectionLogParser())->parse(
    json: $log,
    run: 'r1',
    ts: time(),
    scope: 'acme/widgets',
    metrics: ['msi' => 98.65],
));

$badge = Badge::fromTrend($ledger->trend(scope: 'acme/widgets', metric: 'msi'));

printf("label: %s, message: %s, colour: %s\n\n", $badge->label, $badge->message, $badge->color->value);
echo (new BadgeSvg())->render($badge), "\n\n";

// A scope nobody has digested yet: "n/a" in red rather than an exception —
// the first CI job of a repository is exactly that state.
$empty = Badge::fromTrend($ledger->trend(scope: 'brand-new', metric: 'msi'), 'mutation score');

printf("empty scope -> %s (%s)\n", $empty->message, $empty->color->value);

foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}

@rmdir($dir);
