#!/usr/bin/env bash
#
# Single entry command for the ApplicationTeamEnroller hermetic test suite.
#
# Brings up a real COmanage Registry + its database with the plugin-under-test
# mounted at app/Plugin/ApplicationTeamEnroller, creates the schema via
# Registry's native `cake database` (which applies the plugin's schema.xml),
# runs the thin-runner test suite, and exits with the suite's status. Usable by
# a developer, in CI, and by an agent.
#
# Usage: Test/run.sh                  (Postgres, the default)
#        ATE_TEST_DB=mysql Test/run.sh (MySQL)
#
# Registry deployments run on either database, and CakePHP 2 renders some
# statements differently on each (for example updateAll() joins associated
# tables on MySQL only), so a change is not verified until both legs pass.
set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$TEST_DIR/docker"

ate_test_db="${ATE_TEST_DB:-postgres}"
case "$ate_test_db" in
  postgres) compose=(docker compose -f docker-compose.yml) ;;
  mysql)    compose=(docker compose -f docker-compose.yml -f docker-compose.mysql.yml) ;;
  *)
    echo "==> ERROR: ATE_TEST_DB must be postgres or mysql, not '$ate_test_db'." >&2
    exit 2
    ;;
esac

# Captured suite output, so the sentinel check below has something to grep.
suite_log="$(mktemp)"

cleanup() {
  rm -f "$suite_log"
  "${compose[@]}" down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Bringing up Registry + $ate_test_db..."
"${compose[@]}" up -d

echo "==> Creating schema (applies the plugin schema via cake database)..."
# `cake database`'s exit code is not trustworthy: it can return success while
# emitting a non-fatal "Possibly failed to update database schema" warning.
# Print its output instead of discarding it, and prove the plugin's tables
# actually exist below rather than trusting this exit code alone.
"${compose[@]}" exec -T comanage-registry bash -c '
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
plugin_tables_sql="SELECT count(*) FROM information_schema.tables
    WHERE table_schema = %s
      AND (table_name = 'cm_application_team_enrollers'
           OR table_name LIKE 'cm\\_ate\\_%%')"
if [ "$ate_test_db" = mysql ]; then
  # shellcheck disable=SC2059
  plugin_table_count="$("${compose[@]}" exec -T comanage-registry-database \
    mysql -uregistry_user -ppassword -N -B registry \
    -e "$(printf "$plugin_tables_sql" "'registry'")" 2>/dev/null)"
else
  # shellcheck disable=SC2059
  plugin_table_count="$("${compose[@]}" exec -T comanage-registry-database \
    psql -U registry_user -d registry -tAc "$(printf "$plugin_tables_sql" "'public'")")"
fi
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
"${compose[@]}" exec -T comanage-registry bash -c '
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
#  241  Code-review fixes -- containment of a bound petition whose finalize the
#       wedge refused (revoked, answered elsewhere, session overwritten), revoke
#       retiring the bound petition, the grace window open only to the bound
#       newcomer, no draft past expiry, the default login identifier type,
#       HTTP_ email variables rejected and ignored, PHP Errors rolling back
#       approve and commitResponse, retired and unset-approval routing.
#  243  Issue #2 -- the rendered respond form's choices reach
#       submitResponse() (AteResponsesControllerTest), and every dotted Form
#       field name in View/ names its model (ViewFieldNamesTest).
#  245  Issue #3 -- no CO main menu entries for a login in no CO
#       (HarnessSelfTest) or, on the respond page, not in the invitation's CO
#       (AteResponsesControllerTest).
#  248  MySQL -- conditionalUpdate() qualifies its condition keys and
#       reports a claim another connection took as lost
#       (ConditionalUpdateTest), and saving the same draft twice at once
#       succeeds (AteResponsesControllerTest); both legs must pass.
#  252  Registry's record check finds a request's CO for the request pages
#       and withdraw, and refuses unknown requests and a missing CO
#       (AteEnrollmentRequestsControllerTest); every controller's model can
#       find its CO (SchemaAndAssociationsTest).
#  254  A newcomer flow must name the CO Person, by a name attribute or an
#       Authenticate-mode source (AteSettingTest), and an EnvSource in
#       Authenticate mode gives the newcomer the claimed identity
#       (ApplicationTeamEnrollerCoPetitionsControllerTest).
min_tests_run=254
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
