#!/usr/bin/env bash
#
# Single entry command for the ApplicationTeamEnroller hermetic test suite.
#
# Brings up a real COmanage Registry + Postgres with the plugin-under-test
# mounted at app/Plugin/ApplicationTeamEnroller, creates the schema via
# Registry's native `cake database` (which applies the plugin's schema.xml),
# runs the thin-runner test suite, and exits with the suite's status. Usable by
# a developer, in CI, and by an agent.
#
# Usage: Test/run.sh
set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$TEST_DIR/docker"

# Captured suite output, so the sentinel check below has something to grep.
suite_log="$(mktemp)"

cleanup() {
  rm -f "$suite_log"
  docker compose down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Bringing up Registry + Postgres..."
docker compose up -d

echo "==> Creating schema (applies the plugin schema via cake database)..."
# `cake database`'s exit code is not trustworthy: it can return success while
# emitting a non-fatal "Possibly failed to update database schema" warning.
# Print its output instead of discarding it, and prove the plugin's tables
# actually exist below rather than trusting this exit code alone.
docker compose exec -T comanage-registry bash -c '
  mkdir -p /srv/comanage-registry/local/Config
  source /usr/local/lib/comanage_utils.sh
  comanage_utils::prepare_database_config
  cd /srv/comanage-registry/app && ./Console/cake database'

echo "==> Verifying the plugin's tables actually exist..."
# Gate 0. The plugin's tables are the wedge table (cm_application_team_enrollers,
# named by Registry's plugin convention after the main model) plus every
# cm_ate_* table (KTD3). The floor equals the number of <table> elements in
# Config/Schema/schema.xml. Adding a table there means raising this number in
# the same change; HarnessSelfTest::testRunShTableFloorMatchesSchema fails
# until the two agree.
min_plugin_tables=8
plugin_table_count="$(docker compose exec -T comanage-registry-database \
  psql -U registry_user -d registry -tAc \
  "SELECT count(*) FROM information_schema.tables
    WHERE table_schema = 'public'
      AND (table_name = 'cm_application_team_enrollers'
           OR table_name LIKE 'cm\_ate\_%')")"
plugin_table_count="${plugin_table_count//[[:space:]]/}"
if [ -z "$plugin_table_count" ] || [ "$plugin_table_count" -lt "$min_plugin_tables" ]; then
  echo "==> ERROR: expected at least $min_plugin_tables plugin tables" \
    "(cm_application_team_enrollers, cm_ate_*), found ${plugin_table_count:-0}." \
    "The plugin schema likely failed to apply -- see the cake database output" \
    "above." >&2
  exit 1
fi

echo "==> Running the thin-runner test suite..."
# Three independent gates, because none is sufficient alone:
#
#   1. The exec exit status, which catches a failing assertion.
#   2. The runner's ALL_TESTS_PASSED sentinel, which it prints only after every
#      discovered test has run and passed.
#   3. A floor on how many tests actually ran, because 2 says nothing about how
#      many tests discovery found in the first place.
#
# The exit status alone is not a backstop: a test that reaches exit(0) -- its
# own, or one inside the code under test, for example Controller::redirect()'s
# _stop() -- ends the whole process mid-run with a success status, and a run
# that stopped after three of a hundred tests is indistinguishable from a
# completed one. Requiring the sentinel closes that hole mechanically.
suite_status=0
docker compose exec -T comanage-registry bash -c '
  cd /srv/comanage-registry/app && ./Console/cake ApplicationTeamEnroller.Ate_test' 2>&1 \
  | tee "$suite_log" || suite_status=$?

if [ "$suite_status" -ne 0 ]; then
  echo "==> ERROR: the test suite exited with status $suite_status." >&2
  exit 1
fi

# The runner's verdict block: everything from its "N tests run, M failed."
# summary onward. Both gates below read it rather than the whole log. Test
# cases read this script and assert on its text, so the sentinel literal
# appears in the suite's own test data; a failing assertion that dumped its
# haystack, or any test that echoed this file, would satisfy an unanchored
# grep. The verdict block cannot be forged that way: the runner prints its
# summary only after the last test has run, so nothing a test emits lands
# inside it. (Trailing Cake shutdown warnings can follow the sentinel, so it is
# the runner's last *statement*, not reliably the log's last line.)
suite_tail="$(tr -d '\r' < "$suite_log" \
  | sed -n '/^[0-9][0-9]* tests run, [0-9][0-9]* failed\.$/,$p')"

# Gate 2: the sentinel as a line of its own inside that block, which the runner
# prints only after every discovered test has run and passed.
if ! grep -q '^ALL_TESTS_PASSED$' <<< "$suite_tail"; then
  echo "==> ERROR: the suite exited 0 but never reached the runner's" \
    "ALL_TESTS_PASSED verdict, so it ended early rather than passing." >&2
  exit 1
fi

echo "==> Verifying the suite ran the expected number of tests..."
# Gate 3. The sentinel above proves the runner finished the tests it
# DISCOVERED, not that discovery found the suite. Discovery is silent about
# what it misses: a `test*` method that acquires a `private` keyword drops out
# of get_class_methods() (which returns public methods only), and a file
# renamed off the *Test.php glob drops out of the scan. Either one retires a
# test while both gates above stay green.
#
# The floor equals the number of public test* methods under Test/Case (the
# plan's Definition of Done). Raise it in the same change that adds tests;
# lower it only together with a deliberate removal, never to make a red run
# green. HarnessSelfTest::testRunShTestFloorMatchesSuite counts the tree
# independently and fails when this number and the tree disagree.
#
# History:
#   12  U1 -- HarnessSelfTest (plugin model, lang bootstrap, fixtures, the
#       controller harness, the wedge permissions, the assertion helpers, and
#       these two floors).
#   29  U2 -- SchemaAndAssociationsTest (tables, cmPluginHasMany, changelog vs
#       audit columns, unique and enum validation, deleted-CoGroup history)
#       and AteSettingTest (settings helper defaults and validation).
#   50  U3 -- AteAuthzComponentTest (configure, invite, view, revoke and
#       withdraw, respond, queue and list gates, and decider eligibility
#       under KTD7 with the R39 exclusions).
#   81  U4 -- AteSettingTest newcomer-flow checks, AteApplicationTest,
#       AteResearchTeamTest, AteApplicationTeamTest, AteSettingsControllerTest
#       and AteConfigControllersTest (configuration permissions, pickers,
#       in-place edits, and creating an application, teams, and mapping).
#   93  U5 -- AccessGroupTest (access group creation and naming, nesting
#       and derived membership following the mapping, resync, and retiring).
#  111  U6 -- AteInvitationTest (create and send in one transaction, server-side
#       re-checks, token hash, mail-failure rollback, revoke and withdraw
#       transitions, compose options) and AteInvitationsControllerTest
#       (permissions, compose form, list filtering, view, revoke, withdraw).
#  160  U7 -- MismatchTest (identity evaluation, verified-email collection,
#       existing-member lookup), RoutingTest (committing a response and routing
#       each request), and ApprovalTest (approve, deny, withdraw, memberships
#       per KTD9, login linking, races, rollback, provisioning after commit).
#  176  U10 -- NotificationTest (decider notifications by pending reason,
#       resolution on decision and withdrawal, inviter notices, researcher
#       emails without the comment) and AteEnrollmentRequestsControllerTest
#       (queue scoping, R25 fields, link_required, refused direct posts,
#       AE17, deny, POST only, the notification link).
#  200  U8 -- AteResponsesControllerTest (landing, on-access expiry and the
#       petition grace window, respond authorization, existing member,
#       newcomer decline-all and draft hand-off, link_required, double
#       submit, login switch, forged posts, confirmation states) and
#       AteIdentitySnapshotTest (env and REDIRECT_ emails, verified login
#       emails, lookup errors).
#  214  U9 -- ApplicationTeamEnrollerCoPetitionsControllerTest (real petitions
#       through Registry's CoPetitionsController and the wedge: step order,
#       one CoPerson and the committed response, start refusals, done: bypass
#       left unbound, expiry after binding, existing member next time,
#       abandonment, retiring an earlier petition, continuing newcomers,
#       login switch at finalize, no usable flow, a flow without a role).
#  227  U11 -- ExpireInvitationsJobTest (JobShell registration, expiry with one
#       inviter notice, no second notice, pending_decision untouched, the
#       answer-between-read-and-update race, the bound-petition grace window
#       and retirement, containment of a done: bypass, a bound newcomer never
#       suspended, requeue, App.base).
min_tests_run=227
tests_run="$(sed -n 's/^\([0-9][0-9]*\) tests run, [0-9][0-9]* failed\.$/\1/p' \
  <<< "$suite_tail" | head -n 1)"
if [ -z "$tests_run" ]; then
  echo "==> ERROR: the suite printed no 'N tests run, M failed.' line, so how" \
    "many tests actually ran cannot be established." >&2
  exit 1
fi
if [ "$tests_run" -lt "$min_tests_run" ]; then
  echo "==> ERROR: only $tests_run tests ran, expected at least" \
    "$min_tests_run. Tests have gone missing from discovery -- e.g. a test*" \
    "method turned private, or a test file renamed off the *Test.php glob." >&2
  exit 1
fi

echo "==> Suite passed (ALL_TESTS_PASSED, $tests_run tests)."
