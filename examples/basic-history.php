<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;

$dir = sys_get_temp_dir() . '/mutation-history-example-' . bin2hex(random_bytes(4));
$parser = new InfectionLogParser();
$ledger = new Ledger(id: new MutantId(), storage: new LocalFileStorage($dir));

$isBad = static fn(string $status): bool => $status === 'escaped';

// Run 1: two mutants, one escaped, one killed.
$logR1 = json_encode([
    'escaped' => [[
        'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/Foo.php', 'originalStartLine' => 12, 'originalSourceCode' => ''],
        'diff' => '@@ @@\n-return $x;\n+return true;',
        'processOutput' => '',
    ]],
    'killed' => [[
        'mutator' => ['mutatorName' => 'Identical', 'originalFilePath' => 'src/Foo.php', 'originalStartLine' => 20, 'originalSourceCode' => ''],
        'diff' => '@@ @@\n-if ($a === $b)\n+if ($a !== $b)',
        'processOutput' => 'FAILURES!',
    ]],
], \JSON_THROW_ON_ERROR);

$ledger->append($parser->parse($logR1, 'run-1', 1_700_000_000, 'acme/widgets', ['msi' => 50.0]));

// Run 2: the killed mutant now escapes too — a real regression.
$logR2 = json_encode([
    'escaped' => [
        [
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/Foo.php', 'originalStartLine' => 12, 'originalSourceCode' => ''],
            'diff' => '@@ @@\n-return $x;\n+return true;',
            'processOutput' => '',
        ],
        [
            'mutator' => ['mutatorName' => 'Identical', 'originalFilePath' => 'src/Foo.php', 'originalStartLine' => 20, 'originalSourceCode' => ''],
            'diff' => '@@ @@\n-if ($a === $b)\n+if ($a !== $b)',
            'processOutput' => '',
        ],
    ],
    'killed' => [],
], \JSON_THROW_ON_ERROR);

$ledger->append($parser->parse($logR2, 'run-2', 1_700_003_600, 'acme/widgets', ['msi' => 0.0]));

$diff = $ledger->diff(scope: 'acme/widgets', base: 'run-1', head: 'run-2', isBad: $isBad);
$gate = (new RatchetGate())->evaluate($diff);

printf("newBad: %d, fixed: %d, stillBad: %d\n", \count($diff->newBad), \count($diff->fixed), \count($diff->stillBad));
printf("gate ok: %s\n", $gate->ok ? 'yes' : 'no');

foreach ($gate->regressions as $entry) {
    printf("  regression: %s:%s (%s)\n", $entry->meta['file'], $entry->meta['line'], $entry->meta['mutator']);
}
