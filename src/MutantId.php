<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\StableIdInterface;

/**
 * `sha256(len(file) + ":" + file + ":" + line + ":" + len(mutatorName) + ":"
 * + mutatorName + ":" + normalize(diff))`. Infection's own
 * mutant hash is not guaranteed stable across environments — this is the
 * package's own, deliberately narrow, notion of "the same mutant across
 * runs": same source location, same mutator, same textual change. A line
 * shifting (an edit above it) or the diff's actual content changing is
 * honestly a different mutant; guessing "it's probably still the same one"
 * would silently misattribute history.
 *
 * @api
 */
final readonly class MutantId implements StableIdInterface
{
    /**
     * The horizontal-whitespace set as a PCRE character class, kept in one
     * place because the bug it once caused was exactly these classes
     * drifting apart: `trim()`'s default charlist is `" \t\n\r\0\x0B"`,
     * while the collapse and hunk-header patterns knew only space and tab.
     * A leading `\0` or `\x0B` therefore masked a hunk header on call 1 and
     * was stripped by `trim()`, exposing the header for call 2 — a third
     * idempotence break of the same family as the two documented in
     * {@see normalizeDiff()}. Any change here changes every pattern below,
     * which is the point.
     */
    private const string BLANK = '[ \t\x00\x0B]';

    #[\Override]
    public function id(Datum $datum): string
    {
        return hash('sha256', $datum->signature);
    }

    /**
     * The `Datum::$signature` a mutant's `MutantId` is derived from.
     *
     * Length-prefixed rather than delimiter-separated. `"\0"` used to be
     * both the field separator and a byte a field may legally contain — JSON
     * carries `\u0000` in `originalFilePath`/`mutatorName` perfectly well —
     * so `signature("src/A.php\0" . "1", 2, 'M', $d)` and
     * `signature('src/A.php', 1, "2\0M", $d)` produced byte-identical
     * signatures: two distinct mutants from one log collapsing onto one
     * history entry. With `strlen()` in front of each variable-length field
     * the encoding is injective, and the delimiter no longer has to be a
     * byte the payload cannot contain.
     *
     * (This is why the analogous non-injectivity in `quality-ledger`'s
     * `DefaultStableId` is defensible there and was not fixed: its `kind` is
     * owned by one analyzer, so its prefix is constant and a collision needs
     * equal signatures. Here every field comes from the same untrusted log.)
     *
     * @param non-empty-string $file
     * @param non-empty-string $mutatorName
     * @return non-empty-string
     */
    public static function signature(string $file, int $line, string $mutatorName, string $diff): string
    {
        return \sprintf(
            '%d:%s:%d:%d:%s:%s',
            \strlen($file),
            $file,
            $line,
            \strlen($mutatorName),
            $mutatorName,
            self::normalizeDiff($diff),
        );
    }

    /**
     * Strips hunk headers (`@@ -a,b +c,d @@`) — their line numbers are
     * already carried by `$line` — and collapses whitespace runs, so a
     * purely cosmetic reformatting of the surrounding code (an extra blank
     * line, re-indentation caught in the hunk context) does not mint a new
     * id for the same mutation. The `-`/`+` content itself, the actual
     * mutation, is never touched.
     */
    public static function normalizeDiff(string $diff): string
    {
        // Order matters and is mutation-tested: whitespace has to be
        // normalized *before* hunk headers are stripped, not after. A
        // tab-formatted header ("@@\t-1,1\t+1,1\t@@") does not match the
        // space-only hunk pattern on a first pass, survives it unstripped,
        // and only starts looking like "@@ -1,1 +1,1 @@" once whitespace is
        // collapsed — stripping it a call *later* than a space-formatted
        // header, which is exactly the non-idempotence a second
        // normalizeDiff() call would otherwise expose (found by the
        // property test on "@@\t-\t@@\t").
        //
        // Two sequential replacements for newlines, not one: a single
        // str_replace("\r\n", "\n", …) pass is not idempotent on a run of
        // consecutive \r's either (found by the same property test on
        // "@@\r\r\n@") — "\r\r\n" collapses to "\r\n" in one left-to-right
        // non-overlapping scan, still containing an unnormalized \r\n a
        // second call would collapse further. Normalizing \r\n first, then
        // any remaining lone \r, mops up exactly that leftover in one call.
        $normalizedNewlines = str_replace("\r", "\n", str_replace("\r\n", "\n", $diff));
        // Both `(string)` casts here and below satisfy Psalm's return type
        // for preg_replace()'s string|array|null — a pattern this fixed and
        // valid cannot fail to compile, and the subject is always a plain
        // string (never an array), so preg_replace() never actually returns
        // null here. Mutation-tested and confirmed equivalent (manually
        // removing both casts leaves every test passing unchanged); kept for
        // Psalm, not for behavior.
        $collapsedWhitespace = (string) preg_replace('/' . self::BLANK . '+/', ' ', $normalizedNewlines);
        // `self::BLANK*` around the header, not a bare `@@ …`: a leading tab that
        // collapsed into a leading space (above) would otherwise mask a
        // hunk header from this pattern on this call, only to be exposed by
        // the trailing trim() below on this same call's *first* line — and
        // matched a call later, once more, than a header with no leading
        // whitespace. Tolerating the whitespace here, once, closes that gap
        // for every line, not just the ones trim() happens to reach (found
        // by the same property test on "\t@@\t-\t@@\n-a@+ +").
        $withoutHunkHeaders = (string) preg_replace('/^' . self::BLANK . '*@@ .*? @@' . self::BLANK . '*$/m', '', $collapsedWhitespace);

        return trim($withoutHunkHeaders);
    }
}
