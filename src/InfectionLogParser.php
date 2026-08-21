<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory;

use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\RunReport;

/**
 * Turns Infection's per-mutant JSON log (`logs: { json: "…" }` in
 * `infection.json5` — there is no `--logger-json` CLI flag) into a
 * {@see RunReport}. The schema below is Infection 0.34.x's, confirmed by
 * running it against a real package rather than assumed from documentation:
 * top-level buckets `stats`/`escaped`/`killed`/`timeouted`/
 * `killedByStaticAnalysis`/`errored`/`syntaxErrors`/`uncovered`/`ignored`,
 * each a list of `{mutator: {mutatorName, originalFilePath,
 * originalStartLine, …}, diff, processOutput}`. There is no `killedBy`/
 * `coveredByTests` field — which test killed a mutant is not recoverable
 * from this file at all (see `KillerExtractorInterface`, planned for a
 * later minor, for the `processOutput`-parsing best-effort path).
 *
 * `syntaxErrors` mutants are dropped, not turned into a `Datum`: a syntax
 * error means Infection could not even generate valid PHP for that mutation
 * attempt — it is a tooling artifact, not a signal about the code under
 * test, and its `originalStartLine`/mutator identity is not meaningfully
 * "the same mutant" across runs the way a real one is.
 *
 * @api
 */
final readonly class InfectionLogParser
{
    private const array STATUS_BY_BUCKET = [
        'killed' => 'killed',
        'killedByStaticAnalysis' => 'killed',
        'escaped' => 'escaped',
        'timeouted' => 'timeout',
        'errored' => 'error',
        'uncovered' => 'uncovered',
        'ignored' => 'ignored',
    ];

    /**
     * @param non-empty-string $run
     * @param non-empty-string $scope
     * @param array<non-empty-string, int|float> $metrics
     */
    public function parse(string $json, string $run, int $ts, string $scope, array $metrics = []): RunReport
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Wrapped, not propagated: every other rejection in this class is
            // an InvalidArgumentException naming what is wrong with the log,
            // and a caller catching that contract must not have a truncated
            // file slip past it as a different exception type.
            throw new \InvalidArgumentException('Infection log is not valid JSON: ' . $e->getMessage(), $e->getCode(), previous: $e);
        }

        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('Infection log is not a JSON object');
        }

        $data = [];

        foreach (self::STATUS_BY_BUCKET as $bucket => $status) {
            $mutants = $this->objectAt(
                $payload[$bucket] ?? [],
                \sprintf('Infection log bucket "%s" is not a list', $bucket),
            );
            $index = 0;

            /** @var mixed $mutant */
            foreach ($mutants as $mutant) {
                $entry = $this->entry($mutant, $bucket, $index);
                ++$index;

                $data[] = new Datum(
                    kind: 'mutant',
                    signature: MutantId::signature(
                        file: $entry['file'],
                        line: $entry['line'],
                        mutatorName: $entry['mutator'],
                        diff: $entry['diff'],
                    ),
                    status: $status,
                    meta: ['file' => $entry['file'], 'line' => $entry['line'], 'mutator' => $entry['mutator'], 'diff' => $entry['diff']],
                );
            }
        }

        return new RunReport(run: $run, ts: $ts, scope: $scope, data: $data, metrics: $metrics);
    }

    /**
     * Narrows one entry of an Infection bucket field by field, naming the
     * offending field when it does not hold up.
     *
     * The log is untrusted input: it is a file on disk, written by another
     * tool, possibly from a different Infection version, possibly truncated
     * by a killed CI job. Asserting its shape with a `@var` annotation (what
     * this used to do) is a suppression in everything but name — it made
     * `composer psalm` green over code that answered a missing `mutator` key
     * with PHP warnings and a `TypeError` naming an internal argument. The
     * display boundary in `Cli::describe()` re-checks every value it reads;
     * the ingest boundary has to do at least as much.
     *
     * @return array{file: non-empty-string, line: int, mutator: non-empty-string, diff: string}
     */
    private function entry(mixed $mutant, string $bucket, int $index): array
    {
        $at = \sprintf('%s[%d]', $bucket, $index);
        $fields = $this->objectAt($mutant, \sprintf('Infection log entry %s is not an object', $at));
        $mutator = $this->objectAt(
            $fields['mutator'] ?? null,
            \sprintf('Infection log entry %s.mutator is missing or not an object', $at),
        );

        $file = $this->nonEmptyStringAt($mutator['originalFilePath'] ?? null, $at . '.mutator.originalFilePath');
        $mutatorName = $this->nonEmptyStringAt($mutator['mutatorName'] ?? null, $at . '.mutator.mutatorName');
        $line = $mutator['originalStartLine'] ?? null;

        if (!\is_int($line)) {
            throw new \InvalidArgumentException(\sprintf('Infection log entry %s.mutator.originalStartLine must be an int', $at));
        }

        $diff = $fields['diff'] ?? null;

        if (!\is_string($diff)) {
            throw new \InvalidArgumentException(\sprintf('Infection log entry %s.diff must be a string', $at));
        }

        return ['file' => $file, 'line' => $line, 'mutator' => $mutatorName, 'diff' => $diff];
    }

    /**
     * The value as an array, or `$message`.
     *
     * These two helpers take `mixed` as a **parameter** rather than letting
     * the caller assign the raw value to a local first. That is not style:
     * Psalm's MixedAssignment fires on the assignment, and silencing it
     * needs a `@var mixed` on every line — annotations Rector then removes as
     * useless, which is what a `RemoveUselessVarTagRector` skip in
     * `rector.php` used to paper over. Narrowing behind a typed signature
     * leaves both tools happy and the check real.
     *
     * @return array<array-key, mixed>
     */
    private function objectAt(mixed $value, string $message): array
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * @return non-empty-string
     */
    private function nonEmptyStringAt(mixed $value, string $field): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(\sprintf('Infection log entry %s must be a non-empty string', $field));
        }

        return $value;
    }
}
