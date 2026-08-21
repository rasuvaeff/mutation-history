<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Benchmarks;

use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Testo\Bench;

/**
 * The two CPU-bound paths on the real workload: `normalizeDiff()` runs three
 * PCRE passes per mutant, and `parse()` decodes and narrows a whole log —
 * tens of thousands of mutants on a package worth gating.
 *
 * Both are shaped as a comparison against the same work at a quarter of the
 * size, because the absolute number says nothing: the *ratio* is the signal.
 * Roughly 4× for 4× the input is linear; roughly 16× means something in the
 * loop started scaling with the square of the input.
 */
final class ParserBench
{
    #[Bench(
        callables: [
            'a quarter of the mutants' => [self::class, 'parseOneThousandMutants'],
        ],
        calls: 3,
        iterations: 3,
    )]
    public static function parseFourThousandMutants(): void
    {
        (new InfectionLogParser())->parse(self::log(4_000), 'r1', 0, 'acme/widgets');
    }

    public static function parseOneThousandMutants(): void
    {
        (new InfectionLogParser())->parse(self::log(1_000), 'r1', 0, 'acme/widgets');
    }

    #[Bench(
        callables: [
            'a quarter of the lines' => [self::class, 'normalizeOneThousandLineDiff'],
        ],
        calls: 5,
        iterations: 5,
    )]
    public static function normalizeFourThousandLineDiff(): void
    {
        MutantId::normalizeDiff(self::diff(4_000));
    }

    public static function normalizeOneThousandLineDiff(): void
    {
        MutantId::normalizeDiff(self::diff(1_000));
    }

    private static function log(int $mutants): string
    {
        $escaped = [];

        for ($i = 0; $i < $mutants; ++$i) {
            $escaped[] = [
                'mutator' => [
                    'mutatorName' => 'TrueValue',
                    'originalFilePath' => 'src/File' . ($i % 50) . '.php',
                    'originalStartLine' => $i,
                    'originalSourceCode' => '<?php',
                ],
                'diff' => self::diff(4),
                'processOutput' => '',
            ];
        }

        return (string) json_encode(['escaped' => $escaped]);
    }

    private static function diff(int $lines): string
    {
        $out = "@@ -1,{$lines} +1,{$lines} @@\n";

        for ($i = 0; $i < $lines; ++$i) {
            $out .= "-        return \$a === \$b;\n+        return \$a !== \$b;\n";
        }

        return $out;
    }
}
