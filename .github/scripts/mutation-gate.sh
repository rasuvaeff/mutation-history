#!/usr/bin/env sh
#
# Records one Infection run in the mutation ledger and fails when a mutant
# that used to be killed now escapes.
#
# This is the ratchet `minMsi` cannot express. MSI is one number: it does not
# move when one mutant gets killed and another starts escaping in the same
# commit, which is exactly the trade a refactor makes. `diff` compares the
# per-mutant sets and exits 1 on a new escape.
#
# Run by `composer mutation:gate`, after `composer mutation`. Standalone on
# purpose: a gate that only exists inside `build.yml` cannot be tried locally
# before it is pushed.

set -eu

SCOPE="${MUTATION_SCOPE:-rasuvaeff/mutation-history}"
RUN="${MUTATION_RUN:-${GITHUB_SHA:-local}}"
LOG="${MUTATION_LOG:-build/infection-log.json}"
STORAGE="${MUTATION_STORAGE:-build/mutation-history}"
SUMMARY="${MUTATION_SUMMARY:-build/infection-summary.log}"

if [ ! -f "$LOG" ] || [ ! -f "$SUMMARY" ]; then
    echo "mutation-gate: $LOG or $SUMMARY is missing — run \`composer mutation\` first." >&2

    exit 1
fi

# Infection's own MSI, recomputed from the summary log because Infection
# publishes it nowhere a script can read exactly: the console prints
# floor()-ed whole percents, and neither the JSON nor the summary logger
# carries the figure. Formula and labels are
# `Metrics/Calculator::getMutationScoreIndicator()` and `SummaryFileLogger`:
#
#   100 * (killedByTests + killedByStaticAnalysis + errored + syntaxErrors
#          + timedOut) / (total - skipped - ignored)
#
# Duplicated arithmetic writing into append-only history is a real liability,
# tracked as the pilot's first finding: `digest` should derive this from the
# JSON log it already parses, and then this block goes away.
msi=$(awk -F': *' '
    $1 == "Total"                     { total = $2 }
    $1 == "Killed by Test Framework"  { detected += $2 }
    $1 == "Killed by Static Analysis" { detected += $2 }
    $1 == "Errored"                   { detected += $2 }
    $1 == "Syntax Errors"             { detected += $2 }
    $1 == "Timed Out"                 { detected += $2 }
    $1 == "Skipped"                   { skipped = $2 }
    $1 == "Ignored"                   { ignored = $2 }
    END {
        tested = total - skipped - ignored

        if (tested <= 0) {
            exit 1
        }

        printf "%.2f", 100 * detected / tested
    }
' "$SUMMARY") || msi=''

if [ -z "$msi" ]; then
    echo "mutation-gate: could not read a mutant count out of $SUMMARY." >&2

    exit 1
fi

# Read the baseline before appending, and never let the current run be its own
# base: on a re-run the ledger already holds this SHA (append is idempotent on
# the run id), and `diff --base=X --head=X` compares a run with itself.
#
# There is no `--base=previous`; `trend` is the only way to enumerate runs, and
# it only lists runs carrying the metric — which is why `--msi` above is not
# optional. The pilot's second finding.
prev=$(./bin/mutation-history trend --scope="$SCOPE" --storage="$STORAGE" \
    | awk -F'\t' -v run="$RUN" '$1 != run { last = $1 } END { if (last != "") print last }')

./bin/mutation-history digest \
    --scope="$SCOPE" --run="$RUN" --log="$LOG" --msi="$msi" --storage="$STORAGE"

if [ -z "$prev" ]; then
    echo "mutation-gate: no earlier run for scope \"$SCOPE\" — \"$RUN\" is the baseline (MSI $msi)."

    exit 0
fi

echo "mutation-gate: comparing \"$prev\" -> \"$RUN\" (MSI $msi)"

./bin/mutation-history diff --scope="$SCOPE" --base="$prev" --head="$RUN" --storage="$STORAGE"
