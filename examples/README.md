# Examples

Run examples from the package root after installing dependencies:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-history.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `basic-history.php` | `InfectionLogParser`, `MutantId`, `Ledger::append/diff`, `RatchetGate` end to end | No |
| `cli-badge.php` | What `bin/mutation-history badge` does through the public API: `Badge::fromTrend()` + `BadgeSvg`, including the empty-scope `n/a` badge | No |
