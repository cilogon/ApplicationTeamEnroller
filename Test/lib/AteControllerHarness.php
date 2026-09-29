<?php
/**
 * Test-only harness for driving a plugin controller action from the thin
 * runner.
 *
 * No test in this suite instantiates a controller through Cake's dispatcher:
 * that would need a Session, an Auth component, a Security component and a
 * rendered view. A test instead declares a subclass of the controller under
 * test that uses this trait, and the trait constructs it directly and
 * hand-assigns only what an action touches (a request, the CO context, a flash
 * recorder, a role stub), without constructClasses():
 *
 *   class FooHarness extends AteFoosController {
 *     use AteControllerHarness;
 *   }
 *
 *   $h = FooHarness::harnessBuild('ate_foos', $coId);
 *   $h->harnessInvoke('edit', array($id), 'POST');
 *
 * redirect() records its target and THROWS. It must not merely return: callers
 * assume redirect() terminates the action, and a redirect that returned would
 * let the action run on past a guard. Throwing also keeps the production
 * _stop()/exit() out of the process, which would otherwise end the whole suite
 * mid-run with a success status (Test/run.sh gate 2 catches that, but a test
 * should never get there).
 *
 * Loaded by Console/Command/AteTestShell.php along with every other Test/lib
 * file, so this file must have no side effects at load time.
 *
 * Adapted from the Oa4mpClient plugin's Oa4mpClaimsControllerHarness (KTD15),
 * made generic over the controller class.
 */

App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('ConnectionManager', 'Model');
App::uses('ComponentCollection', 'Controller');
App::uses('AteAuthzComponent', 'ApplicationTeamEnroller.Controller/Component');

/**
 * Thrown by the harness' redirect() so the driven action stops exactly where
 * the production _stop()/exit() would have stopped it.
 */
class AteHarnessRedirect extends Exception {

  /** @var mixed The url argument redirect() was called with. */
  public $url;

  public function __construct($url) {
    $this->url = $url;
    parent::__construct('harness redirect');
  }
}

/**
 * Stand-in for the Flash component. Records instead of writing to a session.
 */
class AteHarnessFlash {

  /** @var array List of array('message' => ..., 'options' => ...). */
  public $messages = array();

  public function set($message, $options = array()) {
    $this->messages[] = array('message' => $message, 'options' => $options);
  }

  /** The most recent flash message, or '' if none was set. */
  public function last() {
    if(empty($this->messages)) {
      return '';
    }
    $last = $this->messages[count($this->messages) - 1];
    return (string)$last['message'];
  }
}

/**
 * Stand-in for Registry's RoleComponent. Returns the role set the test
 * configures from calculateCMRoles(), with every role Registry reports
 * defaulting to false.
 */
class AteHarnessRole {

  /** @var array The role set calculateCMRoles() returns. */
  public $roles = array();

  /**
   * @param Array $roles Roles to set true, e.g. array('coadmin' => true)
   */
  public function __construct($roles = array()) {
    // The defaults RoleComponent::calculateCMRoles() starts from in 4.6.
    $this->roles = $roles + array(
      'cmadmin' => false,
      'coadmin' => false,
      'coapprover' => false,
      'couadmin' => false,
      'couapprover' => false,
      'admincous' => null,
      'comember' => false,
      'admin' => false,
      'subadmin' => false,
      'user' => false,
      'apiuser' => false,
      'orgidentityid' => false,
      'copersonid' => false,
      'orgidentities' => null
    );
  }

  public function calculateCMRoles() {
    return $this->roles;
  }
}

trait AteControllerHarness {

  // Declared so assigning the stand-ins does not create dynamic properties
  // (deprecated since PHP 8.2; the pinned image runs PHP 8.4). The real ones
  // are components the component collection would create; the harness never
  // runs constructClasses(), so it supplies its own.
  public $Flash = null;
  public $Role = null;

  // The plugin's authorization component (U3). Configuration controllers
  // answer isAuthorized() through it; the harness supplies a real one, which
  // decides from the role stub's roles.
  public $AteAuthz = null;

  /** @var mixed The url of the last recorded redirect, or null if none. */
  public $harnessRedirect = null;

  /** @var integer How many times redirect() was called. */
  public $harnessRedirectCount = 0;

  /** @var boolean Whether the last driven action ended in a redirect. */
  public $harnessStopped = false;

  /** @var array Every message log() was handed, in order. */
  public $harnessLogged = array();

  /**
   * Build a harness for the using controller.
   *
   * @param  String  $controller Underscored controller name, e.g. 'application_team_enrollers'
   * @param  Integer $coId       CO id for the hand-assigned CO context
   * @param  Array   $roles      Roles to set true on the role stub
   * @param  Array   $data       Posted request data
   * @return static
   */
  public static function harnessBuild($controller, $coId, $roles = array(), $data = array()) {
    // DboSource::fetchAll() caches every SELECT by its exact SQL text and
    // nothing invalidates that cache on a write, so start every harness from
    // the real current state of the database.
    ConnectionManager::getDataSource('default')->flushQueryCache();

    $harness = new static(new CakeRequest('/', false), new CakeResponse());

    $harness->Flash = new AteHarnessFlash();
    $harness->Role = new AteHarnessRole($roles);
    $harness->AteAuthz = new AteAuthzComponent(new ComponentCollection());

    // The dispatcher's request carries the plugin when the controller is
    // constructed, and Controller::setRequest() copies it here. The harness
    // builds with a bare request, so set it the same way: lazy model loading
    // (Controller::__isset) needs it to find the plugin's model.
    $harness->plugin = 'ApplicationTeamEnroller';

    $harness->request->params['plugin'] = 'application_team_enroller';
    $harness->request->params['controller'] = $controller;
    $harness->request->params['named'] = array();
    $harness->request->data = $data;

    $harness->cur_co = array('Co' => array('id' => $coId));

    return $harness;
  }

  /**
   * Drive one action.
   *
   * $method is the HTTP verb the request should report; CakeRequest resolves
   * its post/put detectors through env(), so it is set on $_SERVER for the
   * duration of the call and restored afterwards.
   *
   * @param  String $action Action name, e.g. 'add', 'edit', 'delete', 'index'
   * @param  Array  $args   Positional arguments, e.g. array($id)
   * @param  String $method HTTP method the request should report
   * @return mixed  The recorded redirect target, or null if the action returned
   */
  public function harnessInvoke($action, $args = array(), $method = 'GET') {
    $prior = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = $method;

    $this->action = $action;
    $this->request->params['action'] = $action;
    $this->harnessRedirect = null;
    $this->harnessStopped = false;

    try {
      call_user_func_array(array($this, $action), $args);
    } catch(AteHarnessRedirect $e) {
      $this->harnessStopped = true;
    } finally {
      if($prior === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $prior;
      }
    }

    return $this->harnessRedirect;
  }

  /**
   * Capture instead of writing to a log file the suite cannot read back.
   */
  public function log($msg, $type = LOG_ERR, $scope = null) {
    $this->harnessLogged[] = (string)$msg;

    return true;
  }

  /**
   * Record the target and stop the action.
   *
   * Signature matches Controller::redirect() and Registry AppController's
   * override of it, both public.
   */
  public function redirect($url, $status = null, $exit = true) {
    $this->autoRender = false;
    $this->harnessRedirect = $url;
    $this->harnessRedirectCount++;

    throw new AteHarnessRedirect($url);
  }
}
