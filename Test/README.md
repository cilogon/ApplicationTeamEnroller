# ApplicationTeamEnroller plugin test harness

This directory holds the plugin's automated test suite. The harness is copied
from the Oa4mpClient plugin (`github.com/cilogon/Oa4mpClient`, `Test/`), without
its live-server tier. See KTD15 and U1 in
`docs/plans/2026-09-29-0856-feat-application-team-enroller-plan.md`.

## Environment

The hermetic environment is a real COmanage Registry plus a Postgres database,
with this checkout mounted as the plugin. Validated on 2026-09-29:

- **Image:** `ghcr.io/cilogon/comanage-registry@sha256:e41c82a9148757bd310351ffdac8f3bdd10a6a37c8865f9c98ceb7bc319279d9`,
  a public GHCR copy of `public.ecr.aws/cilogon/comanage-registry`. It runs
  **COmanage Registry 4.6.1** (`app/Config/VERSION`), PHP 8.4.24, CakePHP
  2.10.24, app root `/srv/comanage-registry/app`.
- **Mount target:** `app/Plugin/ApplicationTeamEnroller`. The image does not
  bundle this plugin, so the mount adds it. In production the plugin lives at
  `local/Plugin/ApplicationTeamEnroller`; the view symlinks
  (`../../../../../app/View/Standard/...`) resolve from both places.
- **Database:** Postgres, a companion container named
  `comanage-registry-database`.
- **Schema:** Registry's own `./Console/cake database` loads each plugin's
  `Config/Schema/schema.xml`, so the checkout's tables are created by
  Registry's native mechanism.
- **Test runner:** the image ships no PHPUnit, and CakePHP 2.x's
  PHPUnit-based `cake test` does not run on PHP 8. The suite uses a thin
  runner instead, `Console/Command/AteTestShell.php`, run as
  `./Console/cake ApplicationTeamEnroller.Ate_test`. It calls
  `_bootstrap_plugin_txt()` first, because Registry merges plugin texts only
  from `AppController` and a console context never reaches it.

## Running the suite

One command brings up the environment, creates the schema, runs the suite, and
tears everything down, exiting non-zero if any gate fails:

```bash
Test/run.sh
```

It needs Docker with the compose plugin. The compose project is named
`ate-hermetic-tests`, so it does not collide with another plugin's suite on the
same machine.

## The gates

`Test/run.sh` reports success only when all of these hold. None is sufficient
alone, and each covers a failure the others cannot see:

0. **The plugin's tables exist** after `cake database`, whose exit code cannot
   be trusted: it can report success while printing "Possibly failed to update
   database schema". The gate counts `cm_application_team_enrollers` (the
   wedge table) plus every `cm_ate_*` table (KTD3) and requires at least
   `min_plugin_tables`, which equals the number of `<table>` elements in
   `Config/Schema/schema.xml`.
1. **The exec exit status**, which catches a failing assertion. It is not a
   backstop on its own: a test that reaches `exit(0)` -- its own, or one inside
   the code under test such as `Controller::redirect()`'s `_stop()` -- ends the
   process mid-run with a success status.
2. **The runner's `ALL_TESTS_PASSED` sentinel**, which it prints only after
   every discovered test has run and passed. It is matched as a line of its own
   inside the runner's verdict block -- everything from the
   `N tests run, M failed.` summary onward -- not as a substring of the whole
   log, so a test that merely prints the word cannot satisfy it.
3. **A floor on how many tests ran** (`min_tests_run`), because gate 2 says
   nothing about how many tests discovery found. A `test*` method that turns
   `private` drops out of `get_class_methods()`, and a file renamed off the
   `*Test.php` glob drops out of the scan; either retires a test while gates 1
   and 2 stay green.

**Both floors equal the current size**, not a number below it (the plan's
Definition of Done). Raise `min_tests_run` in the same change that adds tests,
and `min_plugin_tables` in the same change that adds a table. Lower either only
together with a deliberate removal, never to make a red run green.
`HarnessSelfTest::testRunShTestFloorMatchesSuite` and
`HarnessSelfTest::testRunShTableFloorMatchesSchema` count the tree
independently and fail when a floor and the tree disagree.

### Gate failures demonstrated (U1, 2026-09-29)

Each gate was shown red against a mutated copy of the checkout:

| Mutation | Result |
|---|---|
| `Config/Schema/schema.xml` removed | gate 0: "expected at least 1 plugin tables ... found 0" |
| A test that calls `exit(0)` | gate 2: "never reached the runner's ALL_TESTS_PASSED verdict" |
| A test that prints `ALL_TESTS_PASSED` and then calls `exit(0)` | gate 2, same message: the unanchored word is not accepted |
| `min_tests_run` raised to 13 | gate 1: `testRunShTestFloorMatchesSuite` fails |
| `min_tests_run` raised to 13, that self-test disabled | gate 3: "only 12 tests ran, expected at least 13" |

**Known limit of gate 2.** A test that prints a forged
`0 tests run, 0 failed.` line followed by `ALL_TESTS_PASSED` and then calls
`exit(0)` starts a fake verdict block and passes gate 2. Gate 3 still catches it
(0 tests ran), and it takes a deliberate forgery rather than an accident, but
gate 2 alone does not stop it. Closing it fully would need a per-run token that
`run.sh` hands to the runner and checks in the verdict line.

## Writing tests

A test case extends `AteTestCase` (`Test/lib/AteTestCase.php`) and defines
public `test*` methods; put it under `Test/Case/` at any depth, mirroring
`Model/`, `Controller/`, and `Lib/`. The runner loads every file in `Test/lib/`
before any test case.

The class name must match the file name. A file whose class is named
differently is reported as a failure, not skipped, so a whole test file cannot
retire unnoticed. `Test/Case/HarnessSelfTest.php` is the reference example.

## Fixtures

Database-backed tests seed the rows they need with `Test/lib/AteFixtures.php`
(`co()`, `person()`, `group()`, `member()`, or `insert()` for any table) and call
`cleanup()` in `tearDown()`, which deletes every tracked row newest first.
`track()` registers a row the code under test created so cleanup removes it too.
`pluginRowsFor()` builds the purge map for plugin rows the code under test
created; pass every CO the test seeded in one call
(`pluginRowsFor(array($coId, $otherCoId))`), because merging two maps keeps only
the second CO's clauses.
CakePHP 2.x's PHPUnit fixture machinery does not run on this stack.

**Read-after-write.** CakePHP 2's `DboSource` caches every `SELECT` result
in-process, keyed by the literal SQL text, and nothing flushes it on a write.
`AteFixtures::scalar()` and `count()` flush that cache before each query, so a
repeated count sees the write in between. A model `find()` in a test does not;
call `ConnectionManager::getDataSource('default')->flushQueryCache()` before
re-reading through a model.

## Driving a controller action

`Test/lib/AteControllerHarness.php` drives a plugin controller without Cake's
dispatcher. Declare a subclass of the controller under test that uses the
`AteControllerHarness` trait, build it with `harnessBuild()`, and call
`harnessInvoke()`:

```php
class FooHarness extends AteFoosController {
  use AteControllerHarness;
}

$h = FooHarness::harnessBuild('ate_foos', $coId, array('coadmin' => true));
$h->harnessInvoke('edit', array($id), 'POST');
```

The harness never calls `constructClasses()`. It supplies a flash recorder
(`AteHarnessFlash`), a role stub (`AteHarnessRole`, which returns the role set
you pass merged over Registry's defaults), a real `AteAuthzComponent` for
`isAuthorized()`, the plugin name (so the controller's model loads lazily, as
under the dispatcher), and a `redirect()` that records its target and throws
`AteHarnessRedirect`. `beforeFilter()` does not run, so `cur_co` is the CO you
pass to `harnessBuild()`, and a Registry `StandardController` action (`add`,
`edit`, `index`) runs against the real database. Throwing matters: callers assume
`redirect()` ends the action, and the production `_stop()` would end the whole
suite with a success status.

## The compounding norm

Every bug fixed in this plugin gets a regression test here, in the same pull
request as the fix, linked to its `docs/solutions/` learning. The pull request
template carries the checklist item.

A good test is verified red: temporarily break the rule it guards, confirm the
new test -- and only it -- fails, then restore. A green run cannot tell a test
that passes from one that is structurally unable to fail. Say in the commit
message that the red proof was done.

## Continuous integration

`.github/workflows/hermetic-tests.yml` runs `Test/run.sh` on every pull request
and on pushes to `main`. It uses no repository secrets, so it also runs on pull
requests from forks. A second job runs gitleaks over the full history.

That scan is config-dependent, and a disarmed scanner looks exactly like a
clean repository. `.gitleaks.toml` keeps `[extend] useDefault = true`, because a
gitleaks config without it *replaces* the built-in rules and detects nothing.
The workflow passes `--config` so a config the container cannot read fails
loudly. The gitleaks image is pinned by digest on GHCR.

"No checks reported" on a pull request is a red result: for a first-time fork
contributor it usually means Actions is waiting for a maintainer's approval.

## Bumping the Registry image

Both images are pinned by digest in `Test/docker/docker-compose.yml`, so a pass
or fail belongs to the pull request's code rather than image drift. Set
`ATE_TEST_REGISTRY_IMAGE` or `ATE_TEST_DATABASE_IMAGE` to try another image
locally without editing the file.

Before bumping the Registry pin, confirm the new image still runs Registry
4.6.x:

```bash
docker run --rm --entrypoint cat <image>@sha256:<digest> \
  /srv/comanage-registry/app/Config/VERSION
```

The default Registry image comes from GHCR because anonymous pulls from ECR
Public on Actions runners intermittently fail with
`toomanyrequests: Data limit exceeded`. Someone with package write access in the
`cilogon` organization copies a new digest to GHCR first; the Oa4mpClient
plugin's `Test/README.md` ("Bumping the Registry image") has the commands. Pin
the `linux/amd64` manifest digest, not a wrapping manifest list's, and check
that an anonymous pull by that digest works.

## Known noise in the output

`cake database` prints `Possibly failed to update database schema` and
`relation "cm_oa4mp_client_dynamo_configs" already exists` for the Oa4mpClient
plugin the image bundles. It does not affect this plugin; gate 0 checks this
plugin's tables directly.
