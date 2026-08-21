<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Tests;

use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\RunReport;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(InfectionLogParser::class)]
final class InfectionLogParserTest
{
    private const string SCOPE = 'acme/widgets';

    public function parsesEveryBucketIntoADatumWithTheRightStatus(): void
    {
        $report = $this->parseFixture();

        Assert::same(\count($report->data), 7);

        $byStatus = [];

        foreach ($report->data as $datum) {
            $byStatus[$datum->status] = ($byStatus[$datum->status] ?? 0) + 1;
        }

        ksort($byStatus);

        // killed = 2: `killedByStaticAnalysis` collapses into `killed`. All
        // seven STATUS_BY_BUCKET rows are exercised here — three of them
        // (`timeouted`, `killedByStaticAnalysis`, `errored`) used to be empty
        // arrays in this fixture and were never covered by any test.
        Assert::same($byStatus, ['error' => 1, 'escaped' => 1, 'ignored' => 1, 'killed' => 2, 'timeout' => 1, 'uncovered' => 1]);
    }

    public function eachDatumIsKindMutantAndCarriesFileLineMutatorMeta(): void
    {
        $report = $this->parseFixture();
        $escaped = $this->findByStatus($report->data, 'escaped');

        Assert::same($escaped->kind, 'mutant');
        Assert::same($escaped->meta['file'], 'src/RetryPolicy.php');
        Assert::same($escaped->meta['line'], 42);
        Assert::same($escaped->meta['mutator'], 'TrueValue');
    }

    public function syntaxErrorsAreDroppedNotTurnedIntoData(): void
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json');
        $decoded = json_decode($json, associative: true);
        $decoded['syntaxErrors'] = [[
            'mutator' => ['mutatorName' => 'Whatever', 'originalFilePath' => 'src/X.php', 'originalStartLine' => 1, 'originalSourceCode' => ''],
            'diff' => 'garbage',
            'processOutput' => '',
        ]];

        $report = (new InfectionLogParser())->parse((string) json_encode($decoded), 'run-1', 1_700_000_000, self::SCOPE);

        Assert::same(\count($report->data), 7);
    }

    public function theRunAndTsAndScopeAndMetricsAreCarriedThrough(): void
    {
        $report = (new InfectionLogParser())->parse(
            (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json'),
            'abc123',
            1_700_000_000,
            self::SCOPE,
            ['msi' => 40.0],
        );

        Assert::same($report->run, 'abc123');
        Assert::same($report->ts, 1_700_000_000);
        Assert::same($report->scope, self::SCOPE);
        Assert::same($report->metrics, ['msi' => 40.0]);
    }

    public function idsOfTwoMutantsAtDifferentLinesDiffer(): void
    {
        $report = $this->parseFixture();
        $ids = array_map(static fn(Datum $datum): string => (new MutantId())->id($datum), $report->data);

        Assert::same(\count($ids), \count(array_unique($ids)));
    }

    public function parsingTheSameLogTwiceGivesTheSameIds(): void
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json');
        $parser = new InfectionLogParser();
        $mutantId = new MutantId();

        $first = array_map($mutantId->id(...), $parser->parse($json, 'r1', 0, self::SCOPE)->data);
        $second = array_map($mutantId->id(...), $parser->parse($json, 'r2', 1, self::SCOPE)->data);

        sort($first);
        sort($second);

        Assert::same($first, $second);
    }

    /**
     * Every one of these used to reach a `TypeError` (or PHP warnings plus a
     * `TypeError`) naming an internal argument, because the entry shape was
     * asserted with a `@var` annotation instead of checked. The log is a
     * file written by another tool, possibly a different Infection version,
     * possibly truncated by a killed CI job — the error has to name the
     * field that is wrong.
     *
     * @param array<string, mixed> $log
     */
    #[DataProvider('malformedEntryProvider')]
    public function aMalformedEntryIsRejectedNamingTheField(array $log, string $expectedMessageFragment): void
    {
        try {
            (new InfectionLogParser())->parse((string) json_encode($log), 'r1', 0, self::SCOPE);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains($expectedMessageFragment);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedEntryProvider(): iterable
    {
        $mutator = ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/X.php', 'originalStartLine' => 1, 'originalSourceCode' => ''];

        yield 'bucket is not a list' => [
            ['escaped' => 'oops'],
            'bucket "escaped" is not a list',
        ];

        yield 'entry is a string' => [
            ['escaped' => ['oops']],
            'escaped[0] is not an object',
        ];

        yield 'mutator key missing' => [
            ['escaped' => [['diff' => 'x']]],
            'escaped[0].mutator is missing or not an object',
        ];

        yield 'file path is an int' => [
            ['escaped' => [['mutator' => [...$mutator, 'originalFilePath' => 12], 'diff' => 'x']]],
            'originalFilePath must be a non-empty string',
        ];

        yield 'file path is empty' => [
            ['escaped' => [['mutator' => [...$mutator, 'originalFilePath' => ''], 'diff' => 'x']]],
            'originalFilePath must be a non-empty string',
        ];

        yield 'start line is a numeric string' => [
            ['escaped' => [['mutator' => [...$mutator, 'originalStartLine' => '7'], 'diff' => 'x']]],
            'originalStartLine must be an int',
        ];

        yield 'start line is null' => [
            ['escaped' => [['mutator' => [...$mutator, 'originalStartLine' => null], 'diff' => 'x']]],
            'originalStartLine must be an int',
        ];

        yield 'mutator name is empty' => [
            ['escaped' => [['mutator' => [...$mutator, 'mutatorName' => ''], 'diff' => 'x']]],
            'mutatorName must be a non-empty string',
        ];

        yield 'diff is an array' => [
            ['escaped' => [['mutator' => $mutator, 'diff' => []]]],
            'escaped[0].diff must be a string',
        ];

        yield 'diff key missing' => [
            ['escaped' => [['mutator' => $mutator]]],
            'escaped[0].diff must be a string',
        ];

        yield 'the index names the offending entry, not the first' => [
            ['escaped' => [['mutator' => $mutator, 'diff' => 'x'], ['mutator' => $mutator, 'diff' => 7]]],
            'escaped[1].diff must be a string',
        ];
    }

    public function aNonObjectPayloadIsRejected(): void
    {
        try {
            (new InfectionLogParser())->parse('42', 'r1', 0, self::SCOPE);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not a JSON object');
        }
    }

    private function parseFixture(): RunReport
    {
        return (new InfectionLogParser())->parse(
            (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json'),
            'run-1',
            1_700_000_000,
            self::SCOPE,
        );
    }

    /**
     * @param list<Datum> $data
     */
    private function findByStatus(array $data, string $status): Datum
    {
        foreach ($data as $datum) {
            if ($datum->status === $status) {
                return $datum;
            }
        }

        Assert::fail(sprintf('no datum with status "%s"', $status));
    }
}
