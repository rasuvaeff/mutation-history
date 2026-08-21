<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Tests;

use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\QualityLedger\Datum;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MutantId::class)]
final class MutantIdTest
{
    public function signatureIsLengthPrefixedFileLineMutatorAndNormalizedDiffInThatOrder(): void
    {
        Assert::same(
            MutantId::signature('src/X.php', 42, 'TrueValue', 'a  b'),
            '9:src/X.php:42:9:TrueValue:a b',
        );
    }

    /**
     * The encoding is injective: these two mutants share every byte once the
     * fields are concatenated, and used to share an id because `"\0"` was
     * both the delimiter and a legal field byte. A crafted log could
     * therefore merge two mutants' histories into one.
     */
    public function twoMutantsThatDifferOnlyInWhereAFieldEndsGetDifferentIds(): void
    {
        $id = new MutantId();
        $a = new Datum(kind: 'mutant', signature: MutantId::signature("src/A.php\0" . '1', 2, 'M', 'd'), status: 'escaped');
        $b = new Datum(kind: 'mutant', signature: MutantId::signature('src/A.php', 1, "2\0M", 'd'), status: 'escaped');

        Assert::false($id->id($a) === $id->id($b));
    }

    public function idIsSha256OfTheSignature(): void
    {
        $sig = MutantId::signature('src/X.php', 42, 'TrueValue', 'diff');
        $datum = new Datum(kind: 'mutant', signature: $sig, status: 'escaped');

        Assert::same((new MutantId())->id($datum), hash('sha256', $sig));
    }

    public function normalizeDiffTrimsLeadingAndTrailingWhitespace(): void
    {
        Assert::same(MutantId::normalizeDiff("  x  "), 'x');
    }

    public function sameFileLineMutatorAndDiffGiveTheSameId(): void
    {
        $sig = MutantId::signature('src/X.php', 42, 'TrueValue', '-a;+b');
        $a = new Datum(kind: 'mutant', signature: $sig, status: 'escaped');
        $b = new Datum(kind: 'mutant', signature: $sig, status: 'killed');

        Assert::same((new MutantId())->id($a), (new MutantId())->id($b));
    }

    public function aDifferentLineGivesADifferentId(): void
    {
        $id = new MutantId();
        $a = new Datum(kind: 'mutant', signature: MutantId::signature('src/X.php', 42, 'TrueValue', 'diff'), status: 'escaped');
        $b = new Datum(kind: 'mutant', signature: MutantId::signature('src/X.php', 43, 'TrueValue', 'diff'), status: 'escaped');

        Assert::false($id->id($a) === $id->id($b));
    }

    public function normalizeDiffStripsHunkHeaders(): void
    {
        $normalized = MutantId::normalizeDiff("@@ -1,3 +1,3 @@\n-old\n+new");

        Assert::false(str_contains($normalized, '@@'));
        Assert::true(str_contains($normalized, '-old'));
        Assert::true(str_contains($normalized, '+new'));
    }

    public function normalizeDiffCollapsesWhitespaceRuns(): void
    {
        Assert::same(MutantId::normalizeDiff("a\t\t  b"), 'a b');
    }

    public function normalizeDiffNormalizesLineEndings(): void
    {
        Assert::same(MutantId::normalizeDiff("a\r\nb"), MutantId::normalizeDiff("a\nb"));
    }

    public function normalizeDiffDoesNotTouchAddedOrRemovedContent(): void
    {
        $normalized = MutantId::normalizeDiff('-return true;+return false;');

        Assert::true(str_contains($normalized, '-return true;'));
        Assert::true(str_contains($normalized, '+return false;'));
    }

    /**
     * `normalize()` is idempotent — applying it twice is the same as once,
     * which is what lets two diffs differing only in hunk-header noise
     * collapse to the same normalized form regardless of how many times a
     * caller happens to normalize along the way.
     */
    #[Property(runs: 500, timeoutMs: 1000)]
    public function normalizeDiffIsIdempotent(string $diff): void
    {
        Classify::cover(preg_match('/@@ .*? @@/', $diff) === 1, 'carries a well-formed hunk header', 40.0);
        Classify::cover(strpbrk($diff, "\0\x0B") !== false, 'carries a NUL or a vertical tab', 20.0);
        Classify::when(str_contains($diff, "\r"), 'carries a carriage return');

        $once = MutantId::normalizeDiff($diff);
        $twice = MutantId::normalizeDiff($once);

        Assert::same($once, $twice);
    }

    /**
     * Two real counterexamples this property found before the fix (see the
     * comments in {@see MutantId::normalizeDiff()}), pinned so they run
     * every time regardless of the random phase's seed.
     *
     * @return iterable<string, array{string}>
     */
    public static function normalizeDiffIsIdempotentExamples(): iterable
    {
        yield 'a run of \r before \n' => ["@@\r\r\n@"];
        yield 'a tab-formatted hunk header' => ["\t@@\t-\t@@\n-a@+ +"];
        yield 'a vertical tab before a hunk header' => ["\x0B@@ - @@"];
        yield 'a vertical tab after a hunk header' => ["@@ - @@\x0B"];
        yield 'a NUL before a hunk header' => ["\0@@ - @@"];
        yield 'a NUL after a hunk header' => ["@@ - @@\0"];
    }

    /**
     * Diffs are **constructed**, not drawn character by character from an
     * alphabet: the bug class this property exists to catch lives in how a
     * well-formed `@@ … @@` header interacts with the whitespace around it,
     * and a random string over `"@-+ \t\n\ra1"` practically never contains
     * one — 400 000 draws from that alphabet found zero counterexamples
     * while an eight-character hand-built string broke idempotence. The
     * alphabet also has to carry `\0` and `\x0B`, which `trim()` counts as
     * whitespace and the collapse patterns did not.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function normalizeDiffIsIdempotentGenerators(): array
    {
        return ['diff' => Gen::map(
            Gen::arrayOf(self::diffLineGenerator(), minSize: 0, maxSize: 5),
            static fn(array $lines): string => implode('', $lines),
        )];
    }

    /**
     * One line of a synthetic diff, terminator included: either a hunk
     * header wrapped in whitespace a real `git diff` never emits but a
     * normalizer has to survive, or a `-`/`+`/context line of noise.
     */
    private static function diffLineGenerator(): ArbitraryInterface
    {
        $pad = Gen::elements(['', ' ', '  ', "\t", "\0", "\x0B", " \0", "\x0B\t"]);
        $gap = Gen::elements([' ', '  ', "\t", " \t"]);
        $range = Gen::map(
            Gen::tuple(Gen::intBetween(0, 9), Gen::intBetween(0, 9)),
            static fn(array $pair): string => $pair[0] . ',' . $pair[1],
        );
        $terminator = Gen::elements(["\n", "\r\n", "\r", "\r\r\n", '']);

        $header = Gen::map(
            Gen::tuple($pad, $gap, $range, $range, $pad, $terminator),
            static fn(array $parts): string => \sprintf(
                '%s@@%s-%s%s+%s%s@@%s%s',
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[1],
                $parts[3],
                $parts[1],
                $parts[4],
                $parts[5],
            ),
        );

        $content = Gen::map(
            Gen::tuple(
                Gen::elements(['-', '+', ' ', '']),
                Gen::stringFrom("ab@-+ \t\0\x0B1,", minLength: 0, maxLength: 8),
                $terminator,
            ),
            static fn(array $parts): string => $parts[0] . $parts[1] . $parts[2],
        );

        return Gen::frequency([[3, $header], [2, $content]]);
    }
}
