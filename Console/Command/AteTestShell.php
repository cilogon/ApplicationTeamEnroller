<?php
/**
 * Thin runner for the ApplicationTeamEnroller test suite.
 *
 * Discovers test cases under the plugin's Test/Case tree, runs their `test*`
 * methods against the real Registry and database, and exits non-zero if any
 * assertion fails. Replaces CakePHP 2.x's PHPUnit TestSuite, which does not
 * run on PHP 8.x. Run with:
 *
 *   ./Console/cake ApplicationTeamEnroller.Ate_test
 *
 * Copied from the Oa4mpClient plugin's Oa4mpTestShell (KTD15), without its
 * live-server tier.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('AppShell', 'Console/Command');
App::uses('CakePlugin', 'Core');
App::uses('ConnectionManager', 'Model');

class AteTestShell extends AppShell {

  /**
   * Discover and run the suite.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function main() {
    if(!CakePlugin::loaded('ApplicationTeamEnroller')) {
      CakePlugin::load('ApplicationTeamEnroller');
    }

    // Merge the plugin's Lib/lang.php texts into the translation table.
    // Registry does this from AppController::beforeFilter(), so it happens on
    // every web request but never in a console context. Without it _txt()
    // returns the key it was given, and any test comparing two _txt() results
    // agrees with itself about the broken value.
    _bootstrap_plugin_txt();

    $testDir = App::pluginPath('ApplicationTeamEnroller') . 'Test';

    // The test-case base first, then every other shared lib (fixture helpers,
    // harnesses) so a test case can rely on them without its own requires.
    require_once $testDir . DS . 'lib' . DS . 'AteTestCase.php';
    foreach(glob($testDir . DS . 'lib' . DS . '*.php') ?: array() as $lib) {
      require_once $lib;
    }

    $files = $this->_discover($testDir . DS . 'Case');

    if(empty($files)) {
      // Discovering nothing is a broken gate, not a pass: exit non-zero so
      // Test/run.sh (and CI with it) goes red instead of silently green.
      $this->out('<error>No test cases found.</error>');
      $this->_stop(1);
    }

    $total = 0;
    $failed = 0;
    $failures = array();

    foreach($files as $file) {
      require_once $file;
      $class = basename($file, '.php');
      if(!class_exists($class)) {
        // The file loaded but defines no class named after it. Skipping in
        // silence would retire a whole test file unnoticed, so fail instead.
        $failed++;
        $failures[] = "$file -> expected class $class is not defined";
        $this->out("  <error>FAIL</error> $class (no such class in $file)");
        continue;
      }
      $case = new $class();
      foreach(get_class_methods($case) as $method) {
        if(strpos($method, 'test') !== 0) {
          continue;
        }
        $total++;
        try {
          $case->setUp();
          $case->$method();
          $case->tearDown();
          $this->out("  <success>PASS</success> $class::$method");
        } catch(Throwable $e) {
          // Throwable, not Exception: a PHP 8 Error (missing class, type
          // error) in one test must fail that test, not abort the suite.
          $failed++;
          $failures[] = "$class::$method -> " . get_class($e) . ': ' . $e->getMessage();
          $this->out("  <error>FAIL</error> $class::$method");
          // A failure inside a transaction leaves it open, and every later
          // test would then run inside it and fail too. Roll it back first.
          $db = ConnectionManager::getDataSource('default');
          for($i = 0; $i < 10 && $db->inTransaction(); $i++) {
            $db->rollback();
          }
          // Best-effort cleanup even on failure.
          try { $case->tearDown(); } catch(Throwable $ignored) {}
        }
      }
    }

    $this->out('');
    $this->out(sprintf('%d tests run, %d failed.', $total, $failed));
    foreach($failures as $f) {
      $this->out('  - ' . $f);
    }

    // Same floor after the run: files may load yet contribute no test method,
    // and a run of zero tests must never be reported as success.
    if($total === 0) {
      $this->out('<error>No tests were executed.</error>');
      $this->_stop(1);
    }

    if($failed > 0) {
      $this->_stop(1);
    }
    $this->out('ALL_TESTS_PASSED');
  }

  /**
   * Return all *Test.php files under $dir, at any depth.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $dir Directory to scan
   * @return Array Sorted list of file paths
   */

  protected function _discover($dir) {
    $files = glob($dir . DS . '*Test.php') ?: array();
    foreach(glob($dir . DS . '*', GLOB_ONLYDIR) ?: array() as $sub) {
      $files = array_merge($files, $this->_discover($sub));
    }
    sort($files);
    return $files;
  }
}
