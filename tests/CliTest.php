<?php

declare(strict_types=1);

namespace Rasuvaeff\MutationHistory\Tests;

use Rasuvaeff\MutationHistory\Cli;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Cli::class)]
final class CliTest
{
    private string $storage;
    private string $logPath;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/mutation-history-cli-' . bin2hex(random_bytes(8));
        $this->logPath = __DIR__ . '/fixtures/sample-logs.json';
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (!is_dir($this->storage)) {
            return;
        }

        foreach (glob($this->storage . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->storage);
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $argv): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        \assert($stdout !== false && $stderr !== false);

        $exit = (new Cli())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        $out = (string) stream_get_contents($stdout);
        $err = (string) stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        return [$exit, $out, $err];
    }

    public function digestRecordsARunAndReportsTheMutantCount(): void
    {
        [$exit, $out] = $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);

        Assert::same($exit, 0);
        Assert::string($out)->contains('7 mutants');
    }

    public function digestPassesTheMsiOptionThroughAsAMetric(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=77.5', "--storage={$this->storage}"]);
        [, $trendOut] = $this->run(['trend', '--scope=s', "--storage={$this->storage}"]);

        Assert::string($trendOut)->contains('77.5');
    }

    public function digestWithoutMsiRecordsNoMetric(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);
        [, $trendOut] = $this->run(['trend', '--scope=s', "--storage={$this->storage}"]);

        Assert::same($trendOut, '');
    }

    public function trendDefaultsToTheMsiMetric(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=50', "--storage={$this->storage}"]);
        [, $out] = $this->run(['trend', '--scope=s', "--storage={$this->storage}"]);

        Assert::string($out)->contains("r1\t50");
    }

    public function trendWindowLimitsToTheMostRecentRuns(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=1', "--storage={$this->storage}"]);
        $this->run(['digest', '--scope=s', '--run=r2', "--log={$this->logPath}", '--msi=2', "--storage={$this->storage}"]);
        [, $out] = $this->run(['trend', '--scope=s', '--window=1', "--storage={$this->storage}"]);

        Assert::false(str_contains($out, 'r1'));
        Assert::true(str_contains($out, 'r2'));
    }

    public function diffExitsZeroWhenTheGateIsOk(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);
        $this->run(['digest', '--scope=s', '--run=r2', "--log={$this->logPath}", "--storage={$this->storage}"]);

        [$exit] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);

        Assert::same($exit, 0);
    }

    public function newBadLineHasTheExactFormat(): void
    {
        $before = (string) json_encode(['killed' => [[
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 7, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $after = (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 7, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $beforePath = $this->storage . '-nb-before.json';
        $afterPath = $this->storage . '-nb-after.json';
        file_put_contents($beforePath, $before);
        file_put_contents($afterPath, $after);

        try {
            $this->run(['digest', '--scope=s', '--run=r1', "--log={$beforePath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r2', "--log={$afterPath}", "--storage={$this->storage}"]);
            [, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);

            Assert::string($out)->contains("NEW   src/A.php:7 (TrueValue)\n");
        } finally {
            @unlink($beforePath);
            @unlink($afterPath);
        }
    }

    public function fixedLineHasTheExactFormat(): void
    {
        $bad = (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 7, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $good = (string) json_encode(['killed' => [[
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 7, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $badPath = $this->storage . '-f-bad.json';
        $goodPath = $this->storage . '-f-good.json';
        file_put_contents($badPath, $bad);
        file_put_contents($goodPath, $good);

        try {
            $this->run(['digest', '--scope=s', '--run=r1', "--log={$badPath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r2', "--log={$goodPath}", "--storage={$this->storage}"]);
            [, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);

            Assert::string($out)->contains("FIXED src/A.php:7 (TrueValue)\n");
        } finally {
            @unlink($badPath);
            @unlink($goodPath);
        }
    }

    public function oldLineHasTheExactFormatIncludingAgeRuns(): void
    {
        $bad = (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 7, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $badPath = $this->storage . '-old.json';
        file_put_contents($badPath, $bad);

        try {
            $this->run(['digest', '--scope=s', '--run=r1', "--log={$badPath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r2', "--log={$badPath}", "--storage={$this->storage}"]);
            [, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);

            Assert::string($out)->contains("OLD   src/A.php:7 (TrueValue), 1 run(s)\n");
        } finally {
            @unlink($badPath);
        }
    }

    public function theSummaryLineHasTheExactFormat(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);
        $this->run(['digest', '--scope=s', '--run=r2', "--log={$this->logPath}", "--storage={$this->storage}"]);
        [, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);

        Assert::string($out)->contains('newBad=0 fixed=0 stillBad=1 unchanged=6');
    }

    public function digestReportLineHasTheExactFormat(): void
    {
        [, $out] = $this->run(['digest', '--scope=acme/widgets', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);

        Assert::same($out, "Recorded run \"r1\" for scope \"acme/widgets\": 7 mutants.\n");
    }

    public function trendLineIsRunTabValue(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=91.4', "--storage={$this->storage}"]);
        [$exit, $out] = $this->run(['trend', '--scope=s', "--storage={$this->storage}"]);

        Assert::same($exit, 0);
        Assert::same($out, "r1\t91.4\n");
    }

    /**
     * `explode('=', …, 2)`'s limit of 2 keeps everything after the first `=`
     * together, so an option value that itself contains `=` is not truncated
     * or further split.
     */
    public function anOptionValueContainingAnEqualsSignIsKeptWhole(): void
    {
        [$exit, $out] = $this->run(['digest', '--scope=s', '--run=v1=weird', "--log={$this->logPath}", "--storage={$this->storage}"]);

        Assert::same($exit, 0);
        Assert::string($out)->contains('run "v1=weird"');
    }

    public function anExplicitBadStatusChangesTheClassificationNotJustTheExitCode(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);
        $this->run(['digest', '--scope=s', '--run=r2', "--log={$this->logPath}", "--storage={$this->storage}"]);

        [, $defaultOut] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);
        [, $killedOut] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', '--bad-status=killed', "--storage={$this->storage}"]);

        // With --bad-status=escaped (the default) the fixture's one escaped
        // mutant is stillBad; with --bad-status=killed it is the killed one
        // that counts as bad instead — a different summary line, proving the
        // option is actually read, not merely accepted.
        Assert::false($defaultOut === $killedOut);
    }

    public function diffExitsOneAndPrintsNewWhenAMutantStartsEscaping(): void
    {
        $before = (string) json_encode(['killed' => [[
            'mutator' => ['mutatorName' => 'X', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 1, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $after = (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'X', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 1, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);

        $beforePath = $this->storage . '-before.json';
        $afterPath = $this->storage . '-after.json';
        file_put_contents($beforePath, $before);
        file_put_contents($afterPath, $after);

        try {
            $this->run(['digest', '--scope=s', '--run=r1', "--log={$beforePath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r2', "--log={$afterPath}", "--storage={$this->storage}"]);

            [$exit, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);

            Assert::same($exit, 1);
            Assert::string($out)->contains('NEW');
            Assert::string($out)->contains('src/A.php:1');
            Assert::string($out)->contains('newBad=1');
        } finally {
            @unlink($beforePath);
            @unlink($afterPath);
        }
    }

    public function diffPrintsStillBadWithItsAgeAndFixedEntries(): void
    {
        $bad = (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'X', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 1, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);

        $badPath = $this->storage . '-bad.json';
        file_put_contents($badPath, $bad);

        try {
            $this->run(['digest', '--scope=s', '--run=r1', "--log={$badPath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r2', "--log={$badPath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r3', "--log={$this->logPath}", "--storage={$this->storage}"]); // the mutant is fixed here

            [, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', "--storage={$this->storage}"]);
            Assert::string($out)->contains('OLD');
            Assert::string($out)->contains('run(s)');

            [, $outFixed] = $this->run(['diff', '--scope=s', '--base=r2', '--head=r3', "--storage={$this->storage}"]);
            Assert::string($outFixed)->contains('FIXED');
        } finally {
            @unlink($badPath);
        }
    }

    public function diffRespectsACustomBadStatus(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);
        [$exit] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r1', '--bad-status=killed', "--storage={$this->storage}"]);

        // Comparing a run to itself is never newBad regardless of the
        // predicate — this only exercises that --bad-status reaches diff().
        Assert::same($exit, 0);
    }

    public function aMissingRequiredOptionIsReportedAndExitsOneWithoutKillingTheProcess(): void
    {
        // This used to be exit(1) from inside the library: the branch could
        // not be tested at all, because a test that reached it took the test
        // runner down with it.
        [$exit, , $err] = $this->run(['digest', '--run=r1', "--log={$this->logPath}", "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --scope');
    }

    public function digestReportsAMissingRunOnItsOwn(): void
    {
        [$exit, , $err] = $this->run(['digest', '--scope=s', "--log={$this->logPath}", "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --run');
    }

    public function digestReportsAMissingLogOnItsOwn(): void
    {
        [$exit, , $err] = $this->run(['digest', '--scope=s', '--run=r1', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --log');
    }

    public function diffReportsTheFirstMissingRequiredOption(): void
    {
        [$exit, , $err] = $this->run(['diff', '--scope=s', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --base');
    }

    /**
     * Each required option is checked on its own, not only in combination:
     * an `||` chain that a mutation turns into `&&` still returns 1 when
     * *every* option is missing, and only a one-at-a-time case catches it.
     */
    public function diffReportsAMissingHeadWhenScopeAndBaseAreThere(): void
    {
        [$exit, , $err] = $this->run(['diff', '--scope=s', '--base=r1', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --head');
    }

    public function diffReportsAMissingBaseWhenScopeAndHeadAreThere(): void
    {
        [$exit, , $err] = $this->run(['diff', '--scope=s', '--head=r2', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --base');
    }

    public function trendReportsAMissingScope(): void
    {
        [$exit, , $err] = $this->run(['trend', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --scope');
    }

    /**
     * `--bad-status=` parses to `''`, which `?? 'escaped'` did not replace —
     * so no status counted as bad, the ratchet gate always passed, and the
     * one command whose job is to fail CI exited 0 forever.
     */
    public function anEmptyBadStatusFallsBackToTheDefaultInsteadOfPassingEverything(): void
    {
        $before = (string) json_encode(['killed' => [[
            'mutator' => ['mutatorName' => 'X', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 1, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $after = (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'X', 'originalFilePath' => 'src/A.php', 'originalStartLine' => 1, 'originalSourceCode' => ''],
            'diff' => 'd',
            'processOutput' => '',
        ]]]);
        $beforePath = $this->storage . '-empty-bad-before.json';
        $afterPath = $this->storage . '-empty-bad-after.json';
        file_put_contents($beforePath, $before);
        file_put_contents($afterPath, $after);

        try {
            $this->run(['digest', '--scope=s', '--run=r1', "--log={$beforePath}", "--storage={$this->storage}"]);
            $this->run(['digest', '--scope=s', '--run=r2', "--log={$afterPath}", "--storage={$this->storage}"]);

            [$exit, $out] = $this->run(['diff', '--scope=s', '--base=r1', '--head=r2', '--bad-status=', "--storage={$this->storage}"]);

            Assert::same($exit, 1);
            Assert::string($out)->contains('newBad=1');
        } finally {
            @unlink($beforePath);
            @unlink($afterPath);
        }
    }

    /**
     * A typo'd --msi used to be cast to 0.0 and appended to an append-only
     * ledger: an MSI collapse that never happened, and not retractable.
     */
    public function aNonNumericMsiIsRejectedBeforeAnythingIsRecorded(): void
    {
        [$exit, , $err] = $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=typo', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('--msi must be a number');
        Assert::false(is_dir($this->storage));
    }

    public function aNumericStringMsiIsStillAccepted(): void
    {
        [$exit] = $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=91.4', "--storage={$this->storage}"]);

        Assert::same($exit, 0);
    }

    public function aNonNumericWindowIsRejectedInsteadOfSilentlyPrintingNothing(): void
    {
        [$exit, , $err] = $this->run(['trend', '--scope=s', '--window=abc', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('--window must be a non-negative integer');
    }

    public function aNegativeWindowIsRejectedWithTheSameMessageInsteadOfThrowing(): void
    {
        [$exit, , $err] = $this->run(['trend', '--scope=s', '--window=-1', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('--window must be a non-negative integer');
    }

    public function aZeroWindowIsAcceptedAndYieldsNoPoints(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=50', "--storage={$this->storage}"]);
        [$exit, $out] = $this->run(['trend', '--scope=s', '--window=0', "--storage={$this->storage}"]);

        Assert::same($exit, 0);
        Assert::same($out, '');
    }

    /**
     * Exactly one line, not "contains": the `is_file()` guard exists so the
     * message is written once, by this class, instead of PHP first emitting
     * its own "failed to open stream" warning and the fallthrough reporting
     * the same failure a second time.
     */
    public function badgeRendersTheLatestValueOfTheMetricToStdout(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=40', "--storage={$this->storage}"]);
        $this->run(['digest', '--scope=s', '--run=r2', "--log={$this->logPath}", '--msi=98.65', "--storage={$this->storage}"]);

        [$exit, $out] = $this->run(['badge', '--scope=s', "--storage={$this->storage}"]);

        Assert::same($exit, 0);
        Assert::true(str_starts_with($out, '<svg xmlns="http://www.w3.org/2000/svg"'));
        // Newline-terminated: the badge goes to stdout so `> file` produces a
        // sane file, and a shell prompt does not land mid-document.
        Assert::true(str_ends_with($out, "</svg>\n"));
        Assert::string($out)->contains('>98.7%<');
        Assert::string($out)->contains('>msi<');
        Assert::string($out)->contains('fill="#4c1"');
    }

    public function badgeWritesToTheGivenPathAndReportsIt(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=61', "--storage={$this->storage}"]);
        $out = $this->storage . '-badge.svg';

        try {
            [$exit, $stdout] = $this->run(['badge', '--scope=s', "--out={$out}", "--storage={$this->storage}"]);

            Assert::same($exit, 0);
            Assert::same($stdout, "Wrote {$out} (msi: 61.0%).\n");
            Assert::string((string) file_get_contents($out))->contains('>61.0%<');
        } finally {
            @unlink($out);
        }
    }

    public function badgeLabelsWithTheMetricUnlessToldOtherwise(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=61', "--storage={$this->storage}"]);

        [, $default] = $this->run(['badge', '--scope=s', "--storage={$this->storage}"]);
        [, $labelled] = $this->run(['badge', '--scope=s', '--label=mutation score', "--storage={$this->storage}"]);

        Assert::string($default)->contains('>msi<');
        Assert::string($labelled)->contains('>mutation score<');
    }

    public function badgeReadsTheMetricItIsAskedFor(): void
    {
        $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}", '--msi=61', "--storage={$this->storage}"]);

        [$exit, $out] = $this->run(['badge', '--scope=s', '--metric=coverage', "--storage={$this->storage}"]);

        // No `coverage` metric was ever recorded, so this is the empty-trend
        // badge — labelled with the metric that was asked for.
        Assert::same($exit, 0);
        Assert::string($out)->contains('>coverage<');
        Assert::string($out)->contains('>n/a<');
    }

    /**
     * The first CI job of a repository has no runs yet. A badge step that
     * failed the build there would be useless, so an empty scope renders
     * `n/a` in red and exits 0.
     */
    public function badgeOnAScopeWithNoRunsIsNotAvailableInRed(): void
    {
        [$exit, $out] = $this->run(['badge', '--scope=never-digested', "--storage={$this->storage}"]);

        Assert::same($exit, 0);
        Assert::string($out)->contains('>n/a<');
        Assert::string($out)->contains('fill="#e05d44"');
    }

    public function badgeReportsAnUnwritableOutPath(): void
    {
        [$exit, , $err] = $this->run(['badge', '--scope=s', '--out=/no/such/dir/badge.svg', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Could not write --out=/no/such/dir/badge.svg');
    }

    public function badgeReportsAMissingScope(): void
    {
        [$exit, , $err] = $this->run(['badge', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Missing required --scope');
    }

    public function usageListsEveryCommand(): void
    {
        [, , $err] = $this->run(['bogus']);

        Assert::string($err)->contains('mutation-history digest');
        Assert::string($err)->contains('mutation-history diff');
        Assert::string($err)->contains('mutation-history trend');
        Assert::string($err)->contains('mutation-history badge');
    }

    /**
     * The parser rejects a bad log with an exception; the CLI has to turn
     * that into the exit code and the stderr line it promises everywhere
     * else, not into an uncaught exception with a stack trace.
     */
    public function aMalformedLogIsReportedAndExitsOne(): void
    {
        $path = $this->storage . '-broken.json';
        file_put_contents($path, '{"escaped": [');

        try {
            [$exit, $out, $err] = $this->run(['digest', '--scope=s', '--run=r1', "--log={$path}", "--storage={$this->storage}"]);

            Assert::same($exit, 1);
            Assert::same($out, '');
            // Exactly the parser's message and a newline: no stack trace, and
            // no second line from PHP's own error handler.
            Assert::same($err, "Infection log is not valid JSON: Syntax error\n");
        } finally {
            unlink($path);
        }
    }

    public function AnEntryWithAWrongFieldTypeIsReportedWithItsFieldName(): void
    {
        $path = $this->storage . '-badfield.json';
        file_put_contents($path, (string) json_encode(['escaped' => [[
            'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/A.php', 'originalStartLine' => '7'],
            'diff' => 'd',
        ]]]));

        try {
            [$exit, , $err] = $this->run(['digest', '--scope=s', '--run=r1', "--log={$path}", "--storage={$this->storage}"]);

            Assert::same($exit, 1);
            Assert::string($err)->contains('escaped[0].mutator.originalStartLine must be an int');
        } finally {
            unlink($path);
        }
    }

    public function anUnreadableLogPathIsAnError(): void
    {
        [$exit, , $err] = $this->run(['digest', '--scope=s', '--run=r1', '--log=/no/such/file.json', "--storage={$this->storage}"]);

        Assert::same($exit, 1);
        Assert::same($err, "Could not read --log=/no/such/file.json\n");
    }

    public function anUnknownCommandPrintsUsageAndExitsOne(): void
    {
        [$exit, , $err] = $this->run(['bogus']);

        Assert::same($exit, 1);
        Assert::string($err)->contains('Usage:');
    }

    public function noCommandPrintsUsageAndExitsOne(): void
    {
        [$exit] = $this->run([]);

        Assert::same($exit, 1);
    }

    public function digestReportsTheStorageDirectoryDefaultViaCwd(): void
    {
        // No --storage: the ledger falls back to getcwd() . '/build/mutation-history'.
        // chdir() into a directory this test creates for itself — not into
        // sys_get_temp_dir() itself, whose './build/mutation-history' is
        // shared with every other process on the machine, so a concurrent run
        // or a leftover file from an aborted one would break the count below
        // (and the cleanup would rmdir() a directory it does not own).
        $cwd = getcwd();
        \assert($cwd !== false);
        $root = sys_get_temp_dir() . '/mutation-history-cwd-' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, recursive: true);
        chdir($root);

        $default = $root . '/build/mutation-history';

        try {
            [$exit] = $this->run(['digest', '--scope=s', '--run=r1', "--log={$this->logPath}"]);
            Assert::same($exit, 0);

            // The default really is getcwd() . '/build/mutation-history',
            // not some other path or a literal '.' fallback: exactly one
            // file must have landed there.
            Assert::true(is_dir($default));
            Assert::same(\count(glob($default . '/*') ?: []), 1);
        } finally {
            chdir($cwd);

            foreach (glob($default . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($default);
            @rmdir(\dirname($default));
            @rmdir($root);
        }
    }
}
