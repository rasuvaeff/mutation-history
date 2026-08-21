<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Tests;

use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\MutationHistory\Tests\Support\InMemoryStorage;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\RatchetGate;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The whole point of `mutation-history`: a real Infection log, fed through
 * `Ledger`, produces a trend and a ratchet gate that fails on a genuinely
 * new regression and nothing else. This is the integration proof the unit
 * tests of the two classes individually cannot give.
 */
#[Test]
#[Covers(InfectionLogParser::class)]
#[Covers(MutantId::class)]
final class EndToEndTest
{
    private const string SCOPE = 'acme/widgets';

    public function aMutantThatStartsEscapedAndStaysEscapedIsStillBadNotNewBad(): void
    {
        $ledger = new Ledger(new MutantId(), new InMemoryStorage());
        $parser = new InfectionLogParser();
        $json = (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json');

        $ledger->append($parser->parse($json, 'r1', 1_700_000_000, self::SCOPE, ['msi' => 40.0]));
        $ledger->append($parser->parse($json, 'r2', 1_700_003_600, self::SCOPE, ['msi' => 40.0]));

        $isBad = static fn(string $status): bool => $status === 'escaped';
        $diff = $ledger->diff(self::SCOPE, 'r1', 'r2', $isBad);
        $gate = (new RatchetGate())->evaluate($diff);

        Assert::true($gate->ok);
        Assert::same(\count($diff->stillBad), 1);
    }

    public function aFixedMutantAndAFreshRegressionAreClassifiedCorrectly(): void
    {
        $ledger = new Ledger(new MutantId(), new InMemoryStorage());
        $parser = new InfectionLogParser();
        $json = (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json');
        $decoded = json_decode($json, associative: true);

        // r1: the fixture as-is (one escaped mutant, one killed).
        $ledger->append($parser->parse($json, 'r1', 1_700_000_000, self::SCOPE, ['msi' => 40.0]));

        // r2: swap the two mutants' outcomes — the one that was escaped is
        // now killed (fixed), the one that was killed now escapes (a fresh
        // regression). Same identity (file/line/mutator/diff) per mutant, so
        // both keep the same MutantId across runs.
        $killedEntry = $decoded['killed'][0];
        $escapedEntry = $decoded['escaped'][0];
        $decoded['killed'] = [$escapedEntry];
        $decoded['escaped'] = [$killedEntry];

        $ledger->append($parser->parse((string) json_encode($decoded), 'r2', 1_700_003_600, self::SCOPE, ['msi' => 40.0]));

        $isBad = static fn(string $status): bool => $status === 'escaped';
        $diff = $ledger->diff(self::SCOPE, 'r1', 'r2', $isBad);
        $gate = (new RatchetGate())->evaluate($diff);

        Assert::same(\count($diff->newBad), 1);
        Assert::same(\count($diff->fixed), 1);
        Assert::false($gate->ok);
        Assert::same(\count($gate->regressions), 1);
    }

    public function trendCarriesTheMsiAcrossRuns(): void
    {
        $ledger = new Ledger(new MutantId(), new InMemoryStorage());
        $parser = new InfectionLogParser();
        $json = (string) file_get_contents(__DIR__ . '/fixtures/sample-logs.json');

        $ledger->append($parser->parse($json, 'r1', 1_700_000_000, self::SCOPE, ['msi' => 40.0]));
        $ledger->append($parser->parse($json, 'r2', 1_700_003_600, self::SCOPE, ['msi' => 55.5]));

        $trend = $ledger->trend(self::SCOPE, 'msi');

        Assert::same(\count($trend->points), 2);
        Assert::same($trend->points[0]->value, 40.0);
        Assert::same($trend->points[1]->value, 55.5);
    }
}
