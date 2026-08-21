<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory;

use Rasuvaeff\QualityLedger\Badge;
use Rasuvaeff\QualityLedger\BadgeSvg;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;

/**
 * The `bin/mutation-history` entry point: `digest` appends one Infection log
 * to the ledger, `trend` prints a metric across recorded runs, `badge`
 * renders the metric's latest value as an SVG, and `diff` prints the
 * newBad/fixed/stillBad classification between two runs and exits `1` when
 * the ratchet gate would fail. Deliberately dependency-free (plain
 * `$argv` parsing, no console framework) — this is a thin wrapper over the
 * library, not a product of its own.
 *
 * `$stdout`/`$stderr` are threaded through as plain method **parameters**,
 * never stored on `$this` — a `resource`-typed constructor-promoted property
 * on a class Testo autoloads from `src/` corrupts PHP's own output-buffering
 * stack at shutdown ("Cannot use output buffering in output buffering
 * display handlers"), reproduced by merely *constructing* such an instance
 * inside a test, before `run()` is ever called. The same two-resource shape
 * defined directly in a test file (not under `src/`) does not trigger it —
 * this is specific to Testo's handling of `src/`-loaded classes, not to
 * holding a resource as such. Passing the streams as parameters avoids ever
 * creating that property.
 *
 * @internal Driven by `bin/mutation-history`.
 */
final readonly class Cli
{
    /**
     * @param list<string> $argv Without the script name (index 0 of the real `$argv`).
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $command = $argv[0] ?? null;
        // array_slice($argv, 1): dropping the command word before parsing
        // options is belt-and-braces, not load-bearing — parseOptions()
        // already skips anything that does not start with "--", so passing
        // the whole $argv (command word included) would behave identically.
        // Mutation-tested and confirmed equivalent; kept because relying on
        // that skip alone reads as an accident to the next person, not a
        // decision.
        $options = $this->parseOptions(\array_slice($argv, 1));

        return match ($command) {
            'digest' => $this->digest($options, $stdout, $stderr),
            'diff' => $this->diff($options, $stdout, $stderr),
            'trend' => $this->trend($options, $stdout, $stderr),
            'badge' => $this->badge($options, $stdout, $stderr),
            default => $this->usage($stderr),
        };
    }

    /**
     * @param array<string, string> $options
     * @param resource $stdout
     * @param resource $stderr
     */
    private function digest(array $options, $stdout, $stderr): int
    {
        $scope = $this->required($options, 'scope', $stderr);
        $run = $this->required($options, 'run', $stderr);
        $logPath = $this->required($options, 'log', $stderr);

        if ($scope === null || $run === null || $logPath === null) {
            return 1;
        }

        // is_file() first: file_get_contents() on a missing path emits a PHP
        // "failed to open stream" warning to the process's real stderr
        // before this method ever gets to write its own message to $stderr.
        if (!is_file($logPath)) {
            fwrite($stderr, "Could not read --log={$logPath}\n");

            return 1;
        }

        $json = file_get_contents($logPath);

        if ($json === false) {
            fwrite($stderr, "Could not read --log={$logPath}\n");

            return 1;
        }

        $metrics = [];

        if (isset($options['msi'])) {
            $msi = $options['msi'];

            // A typo'd --msi used to be cast to 0.0 and appended to an
            // append-only ledger: the headline trend then shows a
            // catastrophic drop that never happened and cannot be retracted.
            if (!is_numeric($msi)) {
                fwrite($stderr, "--msi must be a number, got \"{$msi}\"\n");

                return 1;
            }

            $metrics['msi'] = (float) $msi;
        }

        // The log is another tool's output, possibly from another Infection
        // version, possibly truncated by a killed CI job. The parser names
        // the offending field; this turns that into the exit code and the
        // stderr line the rest of the CLI promises, instead of an uncaught
        // exception and a stack trace.
        try {
            $report = (new InfectionLogParser())->parse($json, $run, time(), $scope, $metrics);
        } catch (\InvalidArgumentException $e) {
            fwrite($stderr, $e->getMessage() . "\n");

            return 1;
        }

        $this->ledger($options)->append($report);

        fwrite($stdout, \sprintf("Recorded run \"%s\" for scope \"%s\": %d mutants.\n", $run, $scope, \count($report->data)));

        return 0;
    }

    /**
     * @param array<string, string> $options
     * @param resource $stdout
     * @param resource $stderr
     */
    private function diff(array $options, $stdout, $stderr): int
    {
        $scope = $this->required($options, 'scope', $stderr);
        $base = $this->required($options, 'base', $stderr);
        $head = $this->required($options, 'head', $stderr);

        if ($scope === null || $base === null || $head === null) {
            return 1;
        }

        // optionOrDefault(), not `?? 'escaped'`: a bare `--bad-status=`
        // parses to '' and `??` does not fire on it, so no status was ever
        // "bad", the gate always passed, and this command — whose whole job
        // is to fail CI on a regression — exited 0 forever.
        $badStatus = $this->optionOrDefault($options, 'bad-status', 'escaped');

        $diff = $this->ledger($options)->diff($scope, $base, $head, static fn(string $status): bool => $status === $badStatus);
        $gate = (new RatchetGate())->evaluate($diff);

        foreach ($diff->newBad as $entry) {
            fwrite($stdout, 'NEW   ' . $this->describe($entry->meta, $entry->kind) . "\n");
        }

        foreach ($diff->stillBad as $entry) {
            fwrite($stdout, 'OLD   ' . $this->describe($entry->meta, $entry->kind) . \sprintf(', %d run(s)', $entry->ageRuns) . "\n");
        }

        foreach ($diff->fixed as $entry) {
            fwrite($stdout, 'FIXED ' . $this->describe($entry->meta, $entry->kind) . "\n");
        }

        fwrite($stdout, \sprintf(
            "newBad=%d fixed=%d stillBad=%d unchanged=%d\n",
            \count($diff->newBad),
            \count($diff->fixed),
            \count($diff->stillBad),
            $diff->unchangedCount,
        ));

        return $gate->ok ? 0 : 1;
    }

    /**
     * @param array<string, string> $options
     * @param resource $stdout
     * @param resource $stderr
     */
    private function trend(array $options, $stdout, $stderr): int
    {
        $scope = $this->required($options, 'scope', $stderr);

        if ($scope === null) {
            return 1;
        }

        $metric = $this->optionOrDefault($options, 'metric', 'msi');
        $window = null;

        if (isset($options['window'])) {
            $raw = $options['window'];

            // Only non-negative decimals: `--window=abc` cast to 0, which
            // `Ledger::trend()` reads as "zero runs" and prints nothing while
            // exiting 0, and `--window=-1` threw an uncaught
            // InvalidArgumentException from inside the library.
            if (preg_match('/^\d+\z/', $raw) !== 1) {
                fwrite($stderr, "--window must be a non-negative integer, got \"{$raw}\"\n");

                return 1;
            }

            $window = (int) $raw;
        }

        $trend = $this->ledger($options)->trend($scope, $metric, $window);

        foreach ($trend->points as $point) {
            fwrite($stdout, \sprintf("%s\t%s\n", $point->run, $point->value));
        }

        return 0;
    }

    /**
     * Renders the scope's latest value of a metric as an SVG badge. Writes to
     * `--out` when given and to stdout otherwise, so it composes with a shell
     * redirect as readily as with a path.
     *
     * A scope with no runs yet renders `n/a` in red rather than failing: the
     * first CI job of a repository is exactly that state, and a badge step
     * that breaks the build before the first digest would be useless.
     *
     * @param array<string, string> $options
     * @param resource $stdout
     * @param resource $stderr
     */
    private function badge(array $options, $stdout, $stderr): int
    {
        $scope = $this->required($options, 'scope', $stderr);

        if ($scope === null) {
            return 1;
        }

        $metric = $this->optionOrDefault($options, 'metric', 'msi');
        $badge = Badge::fromTrend(
            $this->ledger($options)->trend($scope, $metric),
            $this->optionOrDefault($options, 'label', $metric),
        );
        $svg = (new BadgeSvg())->render($badge);
        $out = $options['out'] ?? '';

        if ($out === '') {
            fwrite($stdout, $svg . "\n");

            return 0;
        }

        // Same reason as the is_file() guard in digest(): without it PHP
        // writes its own "failed to open stream" warning to the process's
        // real stderr before this method reports the failure to $stderr.
        if (!is_dir(\dirname($out)) || file_put_contents($out, $svg) === false) {
            fwrite($stderr, "Could not write --out={$out}\n");

            return 1;
        }

        fwrite($stdout, \sprintf("Wrote %s (%s: %s).\n", $out, $badge->label, $badge->message));

        return 0;
    }

    /**
     * @param array<string, string> $options
     */
    private function ledger(array $options): Ledger
    {
        $cwd = getcwd();
        // The `false` branch is a documented mutation survivor: getcwd() only
        // fails when the process's working directory has been removed or made
        // unreadable underneath it, which no test can arrange portably. The
        // mutation that swaps the arms therefore also survives — with a live
        // cwd, './build/mutation-history' and "{$cwd}/build/mutation-history"
        // name the same directory.
        $default = ($cwd === false ? '.' : $cwd) . '/build/mutation-history';
        $storageDir = $this->optionOrDefault($options, 'storage', $default);

        return new Ledger(id: new MutantId(), storage: new LocalFileStorage($storageDir));
    }

    /**
     * @param array<array-key, mixed> $meta The shape `DiffEntry::$meta` actually has: a digit-only key comes back from the ledger's JSON as an `int`, so this cannot be narrowed to `array<string, mixed>`. Every read below goes through `??` and a type check anyway.
     */
    private function describe(array $meta, string $kind): string
    {
        return \sprintf(
            '%s:%s (%s)',
            $this->text($meta['file'] ?? null, '?'),
            $this->text($meta['line'] ?? null, '?'),
            $this->text($meta['mutator'] ?? null, $kind),
        );
    }

    /**
     * One `meta` value as display text, or `$fallback` when it is neither a
     * string nor an int.
     *
     * Taking `mixed` as a parameter rather than assigning it to a local first
     * is what keeps this file free of `@var mixed` annotations: Psalm's
     * MixedAssignment fires on the assignment, not on the argument, and those
     * annotations in turn needed a `RemoveUselessVarTagRector` skip in
     * `rector.php` because Rector reads them as useless. Neither is needed
     * now.
     *
     */
    private function text(mixed $value, string $fallback): string
    {
        if (\is_string($value)) {
            return $value === '' ? $fallback : $value;
        }

        // (string) on an int, not sprintf's own "%s" coercion: the return
        // type is the point, and a caller reading this method's name expects
        // text back.
        if (\is_int($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    /**
     * @param array<string, string> $options
     * @param non-empty-string $default
     * @return non-empty-string
     */
    private function optionOrDefault(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    /**
     * A missing required option is reported and answered with `null`, not
     * with `exit(1)`: a library class must not take the process down. The
     * exit code travels back up through `run()` like every other outcome —
     * which is also what makes this branch testable at all.
     *
     * @param array<string, string> $options
     * @param resource $stderr
     * @return non-empty-string|null
     */
    private function required(array $options, string $key, $stderr): ?string
    {
        $value = $options[$key] ?? '';

        if ($value === '') {
            fwrite($stderr, "Missing required --{$key}\n");

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $args
     * @return array<string, string>
     */
    private function parseOptions(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $pair = explode('=', substr($arg, 2), 2);
            $options[$pair[0]] = $pair[1] ?? '';
        }

        return $options;
    }

    /**
     * @param resource $stderr
     */
    private function usage($stderr): int
    {
        fwrite($stderr, <<<'TXT'
            Usage:
              mutation-history digest --scope=<scope> --run=<run> --log=<path/to/infection-log.json> [--msi=<n>] [--storage=<dir>]
              mutation-history diff   --scope=<scope> --base=<run> --head=<run> [--bad-status=escaped] [--storage=<dir>]
              mutation-history trend  --scope=<scope> [--metric=msi] [--window=<n>] [--storage=<dir>]
              mutation-history badge  --scope=<scope> [--metric=msi] [--label=<text>] [--out=<path/to/badge.svg>] [--storage=<dir>]

            --storage defaults to ./build/mutation-history.
            badge writes to stdout unless --out is given.

            TXT);

        return 1;
    }
}
