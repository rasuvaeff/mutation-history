# AGENTS.md — mutation-history

Guidance for AI agents working on this package. Read before changing code.

## What this is

Parses Infection's per-mutant JSON log into
[`rasuvaeff/quality-ledger`](https://github.com/rasuvaeff/quality-ledger)'s
`RunReport`, giving Infection — which is one-shot by design — a cross-run
history: an MSI trend and a diff that separates a genuinely new regression
from background debt. Public namespace `Rasuvaeff\MutationHistory`
(`InfectionLogParser`, `MutantId`). Everything else — the ledger, the diff
partition, the ratchet gate — lives in `quality-ledger`; this package is
deliberately just a parser plus an id function on top of it.

`quality-ledger` resolves from Packagist (`^0.2`, since the badge port landed
in `v0.2.0` on 2026-08-21). Until its first release it came through a path
repository pointing at `../quality-ledger`; that block is gone, and it must not come back — a path
repository always satisfies its own constraint, so with one in place nothing
here notices a version mismatch until an install from Packagist. That is
why the first tag had to be `0.1.0` and not something else: `require` here
said `^0.1`, and the path repository had been inventing that version to
satisfy it. The constraint moved to `^0.2` with the badge command, which
needs `BadgeSvg`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **`killedBy`/`coveredByTests` do not exist in Infection's JSON log** —
   confirmed by running Infection 0.34.x against a real package and reading
   the actual output, not assumed from documentation. Per-test killing power
   is not something `InfectionLogParser` can give you; do not add a
   `killer`/`killedBy` field to a `Datum`'s `meta` without a
   `processOutput`-parsing extractor behind it, and mark that extractor's
   output `confidence: parsed|unknown` when it lands.
4. **Preserve the public contract.** Update README (both languages) + tests
   with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

`composer.lock` is gitignored (library).

## Invariants & gotchas

- **`MutantId::normalizeDiff()`'s three operations run in exactly this
  order: newline-normalize → whitespace-collapse → hunk-header-strip →
  trim — not the more "obvious" strip-then-normalize order.** A property
  test (`normalizeDiffIsIdempotent`) found two real bugs in the original
  order, both now pinned as `Examples()`:
  - a run of consecutive `\r` before a `\n` (`"@@\r\r\n@"`) is not fully
    collapsed by a single `str_replace("\r\n", "\n", …)` pass — the fix is
    two sequential `str_replace` calls (`\r\n`→`\n`, then any remaining lone
    `\r`→`\n`), not one;
  - a tab-formatted hunk header (`"\t@@\t-\t@@\t"`) does not match a
    space-only hunk pattern on the first call, gets reshaped into a
    space-formatted one by whitespace-collapse, and only then matches — one
    call later than a header that was already space-formatted. The fix
    tolerates `[ \t]*` around the header in the regex itself, not by
    reordering when whitespace-collapse runs relative to trim().

  - a `\0` or `\x0B` at either edge (`"\x0B@@ - @@"`): `trim()`'s default
    charlist counts both as whitespace, the collapse and header patterns did
    not, so the header was masked on call 1 and exposed on call 2. The fix is
    one shared character class — `MutantId::BLANK` — used by every pattern in
    the method. Do not inline it back into the individual patterns: them
    drifting apart *is* this bug.

  If you touch `normalizeDiff()`, re-run the property test with a raised
  `runs` count (or brute-force a few million random strings from the same
  alphabet in a throwaway script) before trusting a "looks fine" read —
  both bugs needed a shrunk counterexample or a large sample to surface;
  neither was visible from inspection or from the 6 deterministic unit
  tests that existed before the property test was added.
- **Two `(string)` casts in `normalizeDiff()` on `preg_replace()`'s result
  are mutation-tested and confirmed equivalent** (manually removing both
  leaves every test passing unchanged) — they satisfy Psalm's
  `string|array|null` return type, not a real null-handling path (the
  pattern is fixed and always compiles, the subject is always a plain
  string). Keep them for Psalm; do not read their presence in an Infection
  report as a real gap.
- **`MutantId::signature()` is length-prefixed, and that is not the same
  judgement as `quality-ledger`'s `DefaultStableId`.** There, `kind` is owned
  by one analyzer, so the prefix is constant and a collision reduces to equal
  signatures — non-injectivity is harmless and documented. Here all four
  fields come from the same untrusted JSON log, which can carry `\u0000` in
  either name, so a crafted log could merge two mutants' histories. Keep the
  `strlen()` prefixes; a plain separator would have to be a byte no field can
  contain, and no such byte exists.
- **The Infection log is untrusted input and is narrowed field by field**
  (`InfectionLogParser::entry()`), never asserted with a `@var`. An
  annotation over `json_decode()` output is a suppression in everything but
  name — `bin/package-audit` greps for the literal `@psalm-suppress` and
  cannot see it — and it is what let a truncated log reach a `TypeError`
  naming an internal argument. The display boundary (`Cli::describe()`)
  re-checks every value it reads; the ingest boundary does at least as much.
- **`badge` renders through `quality-ledger`'s `BadgeSvg`; this package owns
  no drawing code.** Which metric belongs on a badge is a decision the CLI
  makes (`--metric`, defaulting to `msi`); how a badge looks is the core's.
  If a shields.io endpoint document is ever wanted, it is a `BadgeRenderer`
  implementation over there, not a second renderer here.
- **`badge` on a scope with no runs exits `0`.** It renders `n/a` in red.
  The first CI job of a repository has no digested run yet, and a badge step
  that broke the build there would be useless — this is deliberate, and
  `badgeOnAScopeWithNoRunsIsNotAvailableInRed` pins it.
- **The CLI never degrades silently.** A missing option, a non-numeric
  `--msi`, a non-integer `--window`, an empty `--bad-status`, an unreadable
  `--log`: each is an exit `1` with a message on stderr. For a gating tool
  the dangerous failure is not a crash, it is a green run — `--bad-status=`
  once made `diff` exit `0` forever. `Cli::required()` returns `null` rather
  than calling `exit()`: a library class must not take the process down, and
  the branch has to stay testable.
- **Six mutants survive and are documented equivalents:** the two
  `array_slice($argv, 1)` variants (`parseOptions()` skips anything not
  starting with `--`, so passing the command word changes nothing), the
  `getcwd() === false` ternary (that branch needs a working directory deleted
  underneath the process, and with a live cwd `'.'` and `$cwd` name the same
  directory), and three `(string)` casts kept for Psalm's return types.
  `minMsi` is 97 against an actual 97.0 — verified by applying each mutation
  by hand and re-running `composer test`; don't re-litigate without doing the
  same.
- **`syntaxErrors` entries are dropped, not parsed into a `Datum`.** A
  syntax error means Infection could not generate valid PHP for that
  mutation attempt — a tooling artifact, not a signal about the code under
  test, and not meaningfully "the same mutant" across runs.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, `non-empty-string` on the values a caller is expected to
  validate before calling in (`file`, `mutatorName`, `run`, `scope`).
- `examples/` is part of the public contract: `examples/basic-history.php`
  is a real, runnable script. Keep it green and update
  `examples/README.md` when usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the
  comment. Workflows also carry `permissions: { contents: read }` at
  workflow level and `persist-credentials: false` on every
  `actions/checkout` step. Verify with `zizmor --persona=auditor .github/` —
  must report no `unpinned-uses`, `excessive-permissions`, or `artipacked`
  findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release
  safety, also run `make release-check`. Paste the output.
