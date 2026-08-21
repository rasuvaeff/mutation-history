# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-08-21

- Initial release: `InfectionLogParser` (parses Infection's per-mutant JSON
  log into a `quality-ledger` `RunReport`) and `MutantId` (a
  `StableIdInterface` keyed on file/line/mutator/normalized-diff), plus the
  `bin/mutation-history` CLI (`digest`/`diff`/`trend`).
- `quality-ledger` is resolved from Packagist (`^0.2`); the path repository
  that stood in for it before its release is gone.
- `bin/mutation-history badge` renders the latest value of a metric as a
  self-contained SVG (`quality-ledger`'s `BadgeSvg`), to `--out` or to
  stdout. A scope with no runs yet renders `n/a` in red and still exits `0`.
- The README usage sample is executable in both languages: `doc-exec` joined
  the build gate, so `// =>` comments are assertions.
- Fixed: a third break of `normalizeDiff()`'s idempotence, of the same family
  as the two already documented. `trim()`'s charlist counts `\0` and `\x0B`
  as whitespace while the collapse and hunk-header patterns knew only space
  and tab, so `"\x0B@@ - @@"` normalized to `"@@ - @@"` on the first call and
  to `""` on the second. All three patterns now share one character class.
- Fixed: `MutantId::signature()` is length-prefixed. `"\0"` was both the
  field separator and a byte a field may legally contain, so
  `signature("src/A.php\0" . '1', 2, 'M', $d)` and `signature('src/A.php', 1,
  "2\0M", $d)` produced the same id — two distinct mutants collapsing onto one
  history entry. Ids from before this change do not carry over.
- Fixed: `InfectionLogParser` validates every field of every entry and raises
  `InvalidArgumentException` naming it. The shape used to be asserted with a
  `@var` annotation, so a truncated or foreign-version log surfaced as PHP
  warnings plus a `TypeError` from an internal argument.
- Fixed: `diff --bad-status=` (empty value) no longer classifies nothing as
  bad, which made the ratchet gate exit `0` forever — in the one command whose
  job is to fail CI. `--msi=<not a number>` is refused instead of writing
  `0.0` into append-only history, and `--window=<not an integer>` is refused
  instead of silently printing nothing (or throwing from inside the library).
- Fixed: a missing required option returns an exit code up through `run()`
  instead of calling `exit(1)` from inside a library class.
- Fixed: an unreadable `--log` path is reported once, by the CLI, instead of
  after a PHP "failed to open stream" warning on the real stderr.
- The property test that guards `normalizeDiff()` now *constructs* hunk
  headers instead of drawing characters from an alphabet, with
  `Classify::cover()` gates proving the random phase reaches both a
  well-formed header and a `\0`/`\x0B`; the three cases the old generator
  could not reach are pinned as examples.
- `benchmarks/ParserBench.php` measures `parse()` and `normalizeDiff()`
  against a quarter of the input, so the ratio shows a regression to
  quadratic scaling.
- `infection/infection` widened to `^0.33 || ^0.34` (the log schema this
  package targets is 0.34's), the mutation gate raised from 90 to 96, and
  Infection's own JSON log enabled so the package can be run against itself.
