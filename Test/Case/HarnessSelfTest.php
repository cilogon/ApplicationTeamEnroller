<?php
/**
 * Harness self-test: proves the thin runner loads the plugin, resolves its
 * texts in the console context, and can seed and clean database rows and
 * drive a controller; that the assertion helpers can fail; and that the two
 * hand-maintained floors in Test/run.sh match the tree. Not a feature test --
 * it exists so every run exercises the harness itself.
 */

App::uses('ApplicationTeamEnrollersController', 'ApplicationTeamEnroller.Controller');

/**
 * The wedge config controller, driven without the dispatcher.
 */
class AteSelfTestWedgeHarness extends ApplicationTeamEnrollersController {
  use AteControllerHarness;
}

class HarnessSelfTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  public function setUp() {
    $this->fx = new AteFixtures();
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup();
    }
  }

  /**
   * KTD1: the plugin is both an enroller and a job, and enroller comes first
   * because Registry's CO duplication reads only the first type.
   */
  public function testPluginModelLoadsWithEnrollerFirst() {
    $model = $this->model('ApplicationTeamEnroller.ApplicationTeamEnroller');

    $this->assertTrue($model instanceof ApplicationTeamEnroller, 'the plugin model should load');
    $this->assertEqual(array('enroller', 'job'), $model->cmPluginType);
    $this->assertTrue(isset($model->belongsTo['CoEnrollmentFlowWedge']),
      'the wedge model must belong to CoEnrollmentFlowWedge');
  }

  /**
   * The wedge table exists and the model reads it, so the checkout's schema
   * reached the database rather than only passing the table gate by name.
   */
  public function testPluginModelQueriesRealDatabase() {
    $model = $this->model('ApplicationTeamEnroller.ApplicationTeamEnroller');

    $this->assertEqual('cm_application_team_enrollers', $model->tablePrefix . $model->table);
    $this->assertTrue(is_int($model->find('count')), 'a count query should return an int from the real DB');
  }

  /**
   * No jobs yet (U11 adds expiry). U4: the CO configuration page links the
   * applications, research teams, and settings screens, and only those. U6:
   * the CO main menu links composing an invitation and the invitation list;
   * U10 adds the decision queue; and only those.
   */
  public function testPluginDeclaresNoJobsYetAndItsMenus() {
    $model = $this->model('ApplicationTeamEnroller.ApplicationTeamEnroller');

    $this->assertEqual(array(), $model->getAvailableJobs());

    $menus = $model->cmPluginMenus();
    $keys = array_keys($menus);
    sort($keys);
    $this->assertEqual(array('coconfig', 'comain'), $keys);

    $expected = array(
      'coconfig' => array('ate_applications/index', 'ate_research_teams/index', 'ate_settings/index'),
      'comain' => array('ate_enrollment_requests/index', 'ate_invitations/add', 'ate_invitations/index')
    );

    foreach($expected as $location => $want) {
      $targets = array();
      foreach($menus[$location] as $label => $item) {
        $this->assertFalse(strpos($label, 'pl.applicationteamenroller') === 0, "unresolved menu label $label");
        $targets[] = $item['controller'] . '/' . $item['action'];
      }
      sort($targets);
      $this->assertEqual($want, $targets, "menu $location");
    }
  }

  /**
   * The runner must merge the plugin's Lib/lang.php texts, which Registry does
   * only from AppController. Without it every _txt('pl.*') returns its own
   * key, and any test comparing two _txt() results agrees with itself.
   */
  public function testPluginTextsAreLoadedInTheConsoleContext() {
    $key = 'pl.applicationteamenroller.wedge.info';
    $text = _txt($key);

    $this->assertFalse($text === $key,
      '_txt() returned its own key: the plugin texts were never bootstrapped');

    // _bootstrap_plugin_txt() includes the lang file inside a function, so its
    // array is not global. Include it here to read the English string.
    include App::pluginPath('ApplicationTeamEnroller') . 'Lib' . DS . 'lang.php';
    $this->assertEqual($cm_application_team_enroller_texts['en_US'][$key], $text);
  }

  public function testFixturesInsertAndCleanUpCoPersonAndGroup() {
    $tag = AteFixtures::tag('ate-selftest');

    $coId = $this->fx->co($tag);
    $personId = $this->fx->person($coId);
    $groupId = $this->fx->group($coId, 'group ' . $tag);

    $this->assertEqual(1, $this->fx->count('cm_cos', 'id = ' . $coId));
    $this->assertEqual(1, $this->fx->count('cm_co_people', 'id = ' . $personId . ' AND co_id = ' . $coId));
    $this->assertEqual(1, $this->fx->count('cm_co_groups', 'id = ' . $groupId . ' AND co_id = ' . $coId));

    $this->fx->cleanup();

    // The same queries as above: the helper flushes the query cache, so these
    // see the deletes rather than the cached counts.
    $this->assertEqual(0, $this->fx->count('cm_cos', 'id = ' . $coId));
    $this->assertEqual(0, $this->fx->count('cm_co_people', 'id = ' . $personId . ' AND co_id = ' . $coId));
    $this->assertEqual(0, $this->fx->count('cm_co_groups', 'id = ' . $groupId . ' AND co_id = ' . $coId));
  }

  /**
   * redirect() must stop the action by throwing, and record where it was
   * going, rather than reaching the production _stop().
   */
  public function testControllerHarnessCapturesRedirect() {
    $h = AteSelfTestWedgeHarness::harnessBuild('application_team_enrollers', 42);

    $target = $h->harnessInvoke('performRedirect');

    $this->assertTrue($h->harnessStopped, 'the redirect should have stopped the action');
    $this->assertEqual(1, $h->harnessRedirectCount);
    $this->assertEqual('co_enrollment_flows', $target['controller']);
    $this->assertEqual(42, $target['co']);
  }

  /**
   * Only CO and platform administrators may configure the wedge, and an
   * action the permission set does not list is denied.
   */
  public function testWedgeConfigIsAdminOnly() {
    $cases = array(
      'coadmin' => array(array('coadmin' => true), true),
      'cmadmin' => array(array('cmadmin' => true), true),
      'comember' => array(array('comember' => true, 'user' => true), false),
      'none' => array(array(), false)
    );

    foreach($cases as $label => $case) {
      foreach(array('edit', 'view', 'index', 'delete') as $action) {
        $h = AteSelfTestWedgeHarness::harnessBuild('application_team_enrollers', 1, $case[0]);
        $h->action = $action;
        $this->assertEqual($case[1], $h->isAuthorized(), "$label on $action");
      }
    }

    $h = AteSelfTestWedgeHarness::harnessBuild('application_team_enrollers', 1, array('coadmin' => true));
    $h->action = 'add';
    $this->assertFalse($h->isAuthorized(), 'an unlisted action must be denied');
  }

  public function testAssertionHelpersWork() {
    $this->assertEqual(2, 1 + 1);
    $this->assertTrue(true, 'true is true');
    $this->assertFalse(false, 'false is false');
    $this->assertNull(null);
    $this->assertContains('bar', 'foobarbaz');
  }

  /**
   * If a helper silently stopped throwing, every test in the suite would keep
   * reporting PASS while asserting nothing.
   */
  public function testAssertionFailureActuallyThrows() {
    $threw = false;
    try {
      $this->assertEqual(1, 2);
    } catch(AteAssertionError $e) {
      $threw = true;
    }
    $this->assertTrue($threw, 'assertEqual(1, 2) must throw AteAssertionError');
  }

  /**
   * '1' and 1 must stay unequal: a regression to loose comparison would let
   * type-juggled values pass.
   */
  public function testAssertionFailureStaysStrict() {
    $threw = false;
    try {
      $this->assertEqual('1', 1);
    } catch(AteAssertionError $e) {
      $threw = true;
    }
    $this->assertTrue($threw, "assertEqual('1', 1) must throw -- comparison must stay strict");
  }

  /**
   * Gate 3's floor equals the number of runnable test methods in the tree
   * (Definition of Done). Counted from source, independently of the runner,
   * so a floor left behind when tests are added goes red here.
   */
  public function testRunShTestFloorMatchesSuite() {
    $root = App::pluginPath('ApplicationTeamEnroller');
    $floor = $this->runShNumber('min_tests_run');

    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'Test' . DS . 'Case'));
    foreach($it as $file) {
      if(!$file->isFile() || !preg_match('/Test\.php$/', $file->getFilename())) {
        continue;
      }
      // Public methods only, as the runner's get_class_methods() sees them.
      $count += preg_match_all('/^\s*(?:public\s+)?function\s+test\w*\s*\(/m',
                               file_get_contents($file->getPathname()));
    }

    $this->assertEqual($count, $floor,
      'min_tests_run in Test/run.sh must equal the number of test methods under Test/Case');
  }

  /**
   * Gate 0's floor equals the number of tables Config/Schema/schema.xml
   * declares, so adding a table without raising it goes red here.
   */
  public function testRunShTableFloorMatchesSchema() {
    $root = App::pluginPath('ApplicationTeamEnroller');
    $floor = $this->runShNumber('min_plugin_tables');

    $schema = file_get_contents($root . 'Config' . DS . 'Schema' . DS . 'schema.xml');
    preg_match_all('/<table\s+name="([a-z_]+)"/', $schema, $m);
    $tables = array_unique($m[1]);

    foreach($tables as $t) {
      $this->assertTrue($t === 'application_team_enrollers' || strpos($t, 'ate_') === 0,
        "table $t is outside the patterns the run.sh table gate counts");
    }
    $this->assertEqual(count($tables), $floor,
      'min_plugin_tables in Test/run.sh must equal the number of tables in schema.xml');
  }

  /**
   * Read an integer assignment such as min_tests_run=12 out of Test/run.sh.
   */
  protected function runShNumber($name) {
    $runSh = file_get_contents(App::pluginPath('ApplicationTeamEnroller') . 'Test' . DS . 'run.sh');

    if(!preg_match('/^' . $name . '=([0-9]+)$/m', $runSh, $m)) {
      $this->fail("no $name=N line in Test/run.sh");
    }

    return (int)$m[1];
  }
}
