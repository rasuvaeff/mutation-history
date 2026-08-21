# rasuvaeff/mutation-history

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/mutation-history/v)](https://packagist.org/packages/rasuvaeff/mutation-history)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/mutation-history/downloads)](https://packagist.org/packages/rasuvaeff/mutation-history)
[![Build](https://github.com/rasuvaeff/mutation-history/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/mutation-history/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/mutation-history/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/mutation-history/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/mutation-history/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/mutation-history/php)](https://packagist.org/packages/rasuvaeff/mutation-history)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Cross-run history for [Infection](https://infection.github.io/) mutation
testing. Infection is one-shot: run it, read the MSI, the log is gone. This
package parses Infection's per-mutant JSON log into
[`quality-ledger`](https://github.com/rasuvaeff/quality-ledger), giving you an
MSI trend across builds and a diff between any two runs that tells apart a
mutant that **just started escaping** (a real regression) from one that has
**always escaped** (background debt an unrelated PR did not create).

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Why not just `--min-msi`?

`infection --min-msi=85` blocks any PR that drops the aggregate below a fixed
number — including one that touches nothing near the debt. `--git-diff-lines`
only compares the current diff, not history. Neither remembers "this exact
mutant has been escaping for 40 runs" versus "this one just started escaping
today." `mutation-history` adds exactly that memory on top, via
[`quality-ledger`](https://github.com/rasuvaeff/quality-ledger)'s
`RatchetGate`, which fails only on a genuinely new regression.

Infection has no `killedBy`/`coveredByTests` field in its log, so per-test
killing power (which test killed a mutant, which test never kills anything)
is not something this package can give you from the log alone — it is future
work behind a `processOutput`-parsing extractor, not part of the core.

## Requirements

- PHP 8.3+
- `rasuvaeff/quality-ledger`
- Infection configured to write its JSON log: `"logs": { "json":
  "build/infection-log.json" }` in `infection.json5` (there is no
  `--logger-json` CLI flag — it is config-only)

## Installation

```bash
composer require rasuvaeff/mutation-history
```

## Usage

```php doc-exec
use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;

// Two runs of the same mutant: killed first, escaping afterwards. In real
// use these are the bytes of Infection's own JSON log.
$mutant = [
    'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/RetryPolicy.php', 'originalStartLine' => 42],
    'diff' => "@@ -42,1 +42,1 @@\n-        return \$this->attempts < \$this->max;\n+        return true;",
];
$before = json_encode(['killed' => [$mutant]]);
$after = json_encode(['escaped' => [$mutant]]);

$parser = new InfectionLogParser();
$ledger = new Ledger(
    id: new MutantId(),
    storage: new LocalFileStorage(sys_get_temp_dir() . '/mutation-history-readme'),
);

$ledger->append($parser->parse(json: $before, run: 'run-1', ts: 1_700_000_000, scope: 'acme/widgets', metrics: ['msi' => 100.0]));
$ledger->append($parser->parse(json: $after, run: 'run-2', ts: 1_700_003_600, scope: 'acme/widgets', metrics: ['msi' => 50.0]));

$isBad = static fn(string $status): bool => $status === 'escaped';
$diff = $ledger->diff(scope: 'acme/widgets', base: 'run-1', head: 'run-2', isBad: $isBad);
$gate = (new RatchetGate())->evaluate($diff);

count($diff->newBad);                // => 1
$gate->ok;                           // => false
$gate->regressions[0]->meta['file']; // => 'src/RetryPolicy.php'

$ledger->trend(scope: 'acme/widgets', metric: 'msi')->points[1]->value; // => 50.0
```

The block above is executed on every build (`composer docs`, via
[doc-exec](https://github.com/rasuvaeff/doc-exec)) — the `// =>` comments are
assertions, not decoration, so this sample cannot drift from the code.

In a real CI job the log comes from disk and the run identity from the
environment, and a failed gate is what ends the job:

```php
$report = $parser->parse(
    json: file_get_contents('build/infection-log.json'),
    run: getenv('GITHUB_SHA') ?: 'local',
    ts: time(),
    scope: 'acme/widgets',
);
$ledger->append($report);

$diff = $ledger->diff(scope: 'acme/widgets', base: $previousSha, head: $currentSha, isBad: $isBad);

if (!(new RatchetGate())->evaluate($diff)->ok) {
    exit(1);
}
```

### `bin/mutation-history`

The package ships a CLI — `vendor/bin/mutation-history` — which is how a CI
job normally consumes it. It is a thin wrapper over the API above: plain
`$argv` parsing, no console framework, no configuration file.

| Command | Does | Exit code |
|---|---|---|
| `digest --scope=<s> --run=<id> --log=<path>` | Parses one Infection JSON log and appends it as a run | `0`; `1` on a missing option, an unreadable log or a malformed value |
| `diff --scope=<s> --base=<run> --head=<run>` | Prints `NEW`/`OLD`/`FIXED` lines plus a summary line | `1` when the ratchet gate fails (a mutant became bad), else `0` |
| `trend --scope=<s>` | Prints `run<TAB>value` for each recorded run | `0` |
| `badge --scope=<s>` | Renders the metric's latest value as an SVG badge — to `--out`, or to stdout | `0`; `1` on an unwritable `--out` |

| Option | Applies to | Default | Notes |
|---|---|---|---|
| `--storage=<dir>` | all | `<cwd>/build/mutation-history` | Where the ledger file lives |
| `--msi=<number>` | `digest` | not recorded | Must be numeric — a typo is an error, not a `0.0` written into append-only history |
| `--bad-status=<status>` | `diff` | `escaped` | An empty value falls back to the default rather than classifying nothing as bad |
| `--metric=<name>` | `trend` | `msi` | |
| `--window=<n>` | `trend` | all runs | Non-negative integer; `0` yields no points |
| `--metric=<name>` | `badge` | `msi` | |
| `--label=<text>` | `badge` | the metric name | Left half of the badge |
| `--out=<path>` | `badge` | stdout | The SVG is self-contained: no shields.io, no network |

Every malformed option value is refused with a message on stderr and exit
`1`. That is deliberate for a gating tool: the failure mode to avoid is not a
crash, it is a silent pass.

```yaml
- name: Mutation history
  run: |
    vendor/bin/infection --threads=max
    vendor/bin/mutation-history digest --scope=acme/widgets --run="$GITHUB_SHA" \
      --log=build/infection-log.json --msi="$(jq .stats.msi build/infection-log.json)"
    vendor/bin/mutation-history diff --scope=acme/widgets --base="$BASE_SHA" --head="$GITHUB_SHA"
```

The ledger is a plain file: keep `build/mutation-history/` between runs (a
cache, an artifact, a committed directory — the package does not care) and
`diff` has history to compare against.

`badge` renders `quality-ledger`'s `BadgeSvg`, so the file is the whole
artifact — publish it wherever the README that shows it can reach:

```bash
vendor/bin/mutation-history badge --scope=acme/widgets --out=build/msi.svg
```

A scope with no runs yet renders `n/a` in red and still exits `0`: the first
job of a repository is exactly that state, and a badge step that broke the
build there would be useless.

### `InfectionLogParser`

Turns the JSON log into a `Rasuvaeff\QualityLedger\RunReport`. Every mutant
across `killed`, `killedByStaticAnalysis`, `escaped`, `timeouted`, `errored`,
`uncovered` and `ignored` becomes a `Datum` carrying `meta: ['file', 'line',
'mutator', 'diff']` — `killedByStaticAnalysis` under the status `killed`, the
rest under a status of the same name (`timeouted` → `timeout`, `errored` →
`error`). Any of them is a valid `--bad-status`. `syntaxErrors` entries are dropped (tooling artifacts, not
mutants).

### `MutantId`

`sha256(len(file) + ":" + file + ":" + line + ":" + len(mutatorName) + ":" +
mutatorName + ":" + normalizedDiff)`. The lengths are what make the encoding
injective: a plain separator would have to be a byte no field can contain,
and a JSON log can carry any byte in either name. `normalizedDiff` strips hunk-header line numbers (already carried separately)
and collapses whitespace, so a cosmetic reformat of the surrounding code does
not mint a new id — the actual `+`/`-` mutation content is never touched.

## Security

The library reads no environment: you supply the JSON log's bytes and the run
identity. The log is treated as untrusted input — `json_decode` runs with
`JSON_THROW_ON_ERROR`, and every field of every entry is checked before use,
so a truncated or foreign-version log raises an `InvalidArgumentException`
naming the offending field instead of a `TypeError` from somewhere inside.

The CLI does read `getcwd()` (for the default `--storage`) and the path given
to `--log`. It loads that log **whole**: `file_get_contents()` plus
`json_decode()` on a large Infection log needs several times the file's size
in memory, so a job gating a package with tens of thousands of mutants should
budget a `memory_limit` well above the log's size. There is no streaming mode
and no size cap.

## Examples

See [examples/](examples/).

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

## License

[BSD-3-Clause](LICENSE.md)
