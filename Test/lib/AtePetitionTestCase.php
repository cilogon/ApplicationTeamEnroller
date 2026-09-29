<?php
/**
 * Shared world for tests that drive real petitions through Registry's
 * CoPetitionsController and the plugin's wedge controller (U9, U11).
 *
 * setUp() adds what Registry gives a CO when it creates one (the automatic
 * members groups and the member affiliation) and a qualifying newcomer flow
 * with this plugin's wedge, and points the CO's settings at it. follow()
 * walks a petition the way a browser follows redirects and "next step"
 * pages; see Test/README.md ("Driving a petition").
 *
 * Engine world: see AteEngineTestCase.
 */

App::uses('CoPetitionsController', 'Controller');
App::uses('ApplicationTeamEnrollerCoPetitionsController', 'ApplicationTeamEnroller.Controller');
App::uses('AteResponsesController', 'ApplicationTeamEnroller.Controller');
App::uses('AteInvitation', 'ApplicationTeamEnroller.Model');
App::uses('ComponentCollection', 'Controller');
App::uses('SessionComponent', 'Controller/Component');
App::uses('CakeSession', 'Model/Datasource');

/**
 * Thrown by the petition harnesses' redirect(). It is an Error, not an
 * Exception, because dispatch() catches every Exception a plugin step
 * throws; in production redirect() exits instead, so nothing catches it.
 */
class AtePetitionHarnessStop extends Error {
}

/** The role stub, plus the one Role call dispatch() makes when it creates a petition. */
class AtePetitionHarnessRole extends AteHarnessRole {
  public function isCoPerson($coPersonId, $coId) {
    return false;
  }
}

/**
 * Drive one petition step the way the dispatcher would: the petition id
 * in params['pass'], the named parameters in params['named'], render()
 * recorded instead of rendered.
 */
trait AtePetitionDriver {
  public $Session = null;

  /** @var String The view render() was asked for, or null */
  public $harnessRendered = null;

  public function redirect($url, $status = null, $exit = true) {
    $this->autoRender = false;
    $this->harnessRedirect = $url;
    $this->harnessRedirectCount++;

    throw new AtePetitionHarnessStop('harness redirect');
  }

  public function render($view = null, $layout = null) {
    $this->autoRender = false;
    $this->harnessRendered = ($view === null) ? $this->action : $view;

    return $this->response;
  }

  public function drive($action, $pass, $named, $method = 'GET', $data = array()) {
    $prior = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = $method;

    $this->action = $action;
    $this->request->params['action'] = $action;
    $this->request->params['pass'] = $pass;
    $this->request->params['named'] = $named;
    $this->request->data = $data;
    $this->harnessRedirect = null;
    $this->harnessRendered = null;

    try {
      call_user_func_array(array($this, $action), $pass);
    } catch(AtePetitionHarnessStop $e) {
      $this->harnessStopped = true;
    } finally {
      if($prior === null) {
        unset($_SERVER['REQUEST_METHOD']);
      } else {
        $_SERVER['REQUEST_METHOD'] = $prior;
      }
    }
  }
}

class AteCorePetitionsHarness extends CoPetitionsController {
  use AteControllerHarness, AtePetitionDriver {
    AtePetitionDriver::redirect insteadof AteControllerHarness;
  }
}

class AteWedgePetitionsHarness extends ApplicationTeamEnrollerCoPetitionsController {
  use AteControllerHarness, AtePetitionDriver {
    AtePetitionDriver::redirect insteadof AteControllerHarness;
  }
}

class AteNewcomerResponsesHarness extends AteResponsesController {
  use AteControllerHarness;

  public $Session = null;
}

abstract class AtePetitionTestCase extends AteEngineTestCase {

  /** @var Integer The newcomer flow, its wedge, and its enrollment attributes */
  protected $flowId = null;
  protected $wedgeId = null;
  protected $nameAttrId = null;
  protected $roleAttrId = null;

  public function setUp() {
    parent::setUp();
    $this->resetRequestState();
    AteRecordingTransport::reset();

    // Registry gives every CO its automatic members groups and default
    // extended types when the CO is created. Saving a CoPerson syncs the
    // members groups, and the form submits an affiliation.
    $this->fx->group($this->coId, 'CO:members:active', array('group_type' => 'MA', 'auto' => true));
    $this->fx->group($this->coId, 'CO:members:all', array('group_type' => 'M', 'auto' => true));
    $this->fx->insert('cm_co_extended_types', array(
      'co_id' => $this->coId,
      'attribute' => 'CoPersonRole.affiliation',
      'name' => 'member',
      'display_name' => 'Member',
      'edupersonaffiliation' => 'member',
      'status' => 'A'
    ));

    $this->flowId = $this->newcomerFlow(true);
    $this->fx->query('UPDATE cm_ate_settings SET newcomer_co_enrollment_flow_id = ' . (int)$this->flowId
                     . ' WHERE co_id = ' . (int)$this->coId);
  }

  public function tearDown() {
    $this->resetRequestState();
    AteRecordingTransport::reset();
    ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest')->emailConfig = 'default';

    // Petitions and the people they created are not tracked fixtures:
    // unbind the invitations, remove the petitions, then everything the
    // engine world removes, then the CO's people.
    $cos = (int)$this->coId . ', ' . (int)$this->otherCoId;
    $pts = 'SELECT id FROM cm_co_petitions WHERE co_id IN (' . $cos . ')';
    $ppl = 'SELECT id FROM cm_co_people WHERE co_id IN (' . $cos . ')';

    $this->fx->query('UPDATE cm_ate_invitations SET co_petition_id = NULL WHERE co_id IN (' . $cos . ')');
    $this->fx->query('DELETE FROM cm_co_petition_history_records WHERE co_petition_id IN (' . $pts . ')');
    $this->fx->query('DELETE FROM cm_co_petition_attributes WHERE co_petition_id IN (' . $pts . ')');
    $this->fx->query('DELETE FROM cm_co_petitions WHERE co_id IN (' . $cos . ')');

    $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)) + array(
      'cm_names' => 'co_person_id IN (' . $ppl . ')',
      'cm_co_person_roles' => 'co_person_id IN (' . $ppl . ')',
      'cm_co_people' => 'co_id IN (' . $cos . ')'
    ));

    parent::tearDown();
  }

  /** No login, no plugin session state, no email claim. */
  protected function resetRequestState() {
    CakeSession::delete('ApplicationTeamEnroller');
    CakeSession::delete('Auth.User');

    foreach(array('OIDC_CLAIM_email', 'REDIRECT_OIDC_CLAIM_email') as $v) {
      unset($_SERVER[$v]);
      putenv($v);
    }
  }

  /**
   * A flow that qualifies as the newcomer flow (U4), with this plugin's wedge
   * and a required CO Person name. With $role, also a required CO Person
   * Role affiliation, so finalize activates the new CoPerson.
   */
  protected function newcomerFlow($role) {
    $flow = $this->fx->flow($this->coId, 'Newcomer ' . AteFixtures::tag('flow'), array(), false);
    $this->wedgeId = $this->fx->wedge($flow);

    $attr = array(
      'co_enrollment_flow_id' => $flow,
      'required' => 1,
      'hidden' => false,
      'copy_to_coperson' => false,
      'ignore_authoritative' => false,
      'login' => false,
      'revision' => 0,
      'deleted' => false,
      'co_enrollment_attribute_id' => null
    );

    $this->nameAttrId = $this->fx->insert('cm_co_enrollment_attributes', $attr + array(
      'label' => 'Name', 'attribute' => 'p:name:official', 'ordr' => 1
    ));
    $this->roleAttrId = null;

    if($role) {
      $this->roleAttrId = $this->fx->insert('cm_co_enrollment_attributes', $attr + array(
        'label' => 'Affiliation', 'attribute' => 'r:affiliation', 'ordr' => 2
      ));
    }

    return $flow;
  }

  /** Log in as $identifier, whose IdP reports $emails. */
  protected function loginAs($identifier, $emails = array()) {
    CakeSession::write('Auth.User.username', $identifier);
    unset($_SERVER['OIDC_CLAIM_email']);

    if(!empty($emails)) {
      $_SERVER['OIDC_CLAIM_email'] = implode(',', $emails);
    }
  }

  /** A sent invitation whose raw token the test knows. */
  protected function tokenInvitation($email, $offers, $overrides = array()) {
    $token = AteInvitation::generateToken();
    $inv = $this->invitation($email, $offers, $overrides + array('token_hash' => AteInvitation::hashToken($token)));
    $inv['token'] = $token;

    return $inv;
  }

  protected function responses($data = array()) {
    $h = AteNewcomerResponsesHarness::harnessBuild('ate_responses', $this->coId, array(), $data);
    $h->Session = new SessionComponent(new ComponentCollection());
    $h->AteEnrollmentRequest->emailConfig = AteRecordingTransport::emailConfig();

    return $h;
  }

  /**
   * Follow the link, open the page, submit $choices (application key =>
   * accept). Returns the submit harness.
   */
  protected function respondTo($inv, $choices) {
    $h = $this->responses();
    $h->harnessInvoke('landing', array($inv['token']));

    $page = $this->responses();
    $page->harnessInvoke('respond', array(), 'GET');
    $this->assertEqual('respond', $page->view, 'the respond page is shown');

    $posted = array();
    foreach($choices as $appKey => $accept) {
      $posted[$inv['req'][$appKey]] = $accept ? '1' : '0';
    }

    $h = $this->responses(array('AteResponse' => array('nonce' => $page->viewVars['vv_nonce'], 'choices' => $posted)));
    $h->harnessInvoke('respond', array(), 'POST');

    return $h;
  }

  /** A petition controller for a URL: Registry's own or the plugin's. */
  protected function petitionController($controller) {
    if($controller === 'co_petitions') {
      $h = AteCorePetitionsHarness::harnessBuild('co_petitions', $this->coId);
      $h->plugin = null;
      $h->request->params['plugin'] = null;
    } else {
      $h = AteWedgePetitionsHarness::harnessBuild($controller, $this->coId);
      $h->AteEnrollmentRequest->emailConfig = AteRecordingTransport::emailConfig();
    }

    $h->Role = new AtePetitionHarnessRole();
    $h->Session = new SessionComponent(new ComponentCollection());

    return $h;
  }

  /** The petitioner attributes form as submitted. */
  protected function attributesForm($petitionId, $token) {
    $data = array(
      'CoPetition' => array(
        'id' => $petitionId,
        'co_enrollment_flow_id' => $this->flowId,
        'token' => $token
      ),
      // The form's hidden fields (View/CoPetitions/petition-attributes.inc)
      'EnrolleeCoPerson' => array(
        'co_id' => $this->coId,
        'status' => 'P',
        'Name' => array(
          $this->nameAttrId => array(
            'co_enrollment_attribute_id' => $this->nameAttrId,
            'given' => 'Pat',
            'family' => 'Newcomer',
            'type' => 'official',
            'primary_name' => true,
            'language' => ''
          )
        )
      )
    );

    if($this->roleAttrId) {
      $data['EnrolleeCoPersonRole'] = array('affiliation' => 'member');
    }

    return $data;
  }

  /**
   * Follow a URL through the petition controllers, as a browser follows
   * redirects and the "next step" meta refresh pages, submitting the
   * petitioner attributes form when it is shown.
   *
   * $hook, if given, is called before each step with ($controller, $action,
   * $pass, $named) and may return false to stop there (the browser is
   * closed), or an array of those four to go somewhere else instead.
   *
   * @return Array 'trace' (each step as "core|wedge:action[/done:X]"), 'stopped_at' (the URL not
   *               followed, or null), 'end' (the last URL outside the petition controllers, or
   *               null), 'shown' (a step that rendered and did not move on, or null), 'harness'
   */
  protected function follow($url, $hook = null, $max = 60) {
    $trace = array();
    $last = null;

    for($i = 0; $i < $max; $i++) {
      $controller = $url['controller'];
      $action = $url['action'];
      $pass = array();
      $named = array();

      foreach($url as $k => $v) {
        if(is_int($k)) {
          $pass[] = $v;
        } elseif(!in_array($k, array('plugin', 'controller', 'action'), true)) {
          $named[$k] = $v;
        }
      }

      if(!in_array($controller, array('co_petitions', 'application_team_enroller_co_petitions'), true)) {
        return array('trace' => $trace, 'stopped_at' => null, 'end' => $url, 'shown' => null, 'harness' => $last);
      }

      if($hook) {
        $r = $hook($controller, $action, $pass, $named);

        if($r === false) {
          return array('trace' => $trace, 'stopped_at' => $url, 'end' => null, 'shown' => null, 'harness' => $last);
        }

        if(is_array($r)) {
          list($controller, $action, $pass, $named) = $r;
        }
      }

      $trace[] = (($controller === 'co_petitions') ? 'core' : 'wedge') . ':' . $action
                 . (isset($named['done']) ? '/done:' . (($named['done'] === 'core') ? 'core' : 'wedge') : '');

      $h = $this->petitionController($controller);
      $h->drive($action, $pass, $named);
      $last = $h;

      if($h->harnessRedirect === null && $h->harnessRendered === 'nextStep') {
        $url = $h->viewVars['vv_meta_redirect_target'];
        continue;
      }

      if($h->harnessRedirect === null
         && $controller === 'co_petitions' && $action === 'petitionerAttributes' && !isset($named['done'])) {
        // The form is shown; submit it
        $token = isset($named['token']) ? $named['token'] : null;
        $trace[] = 'core:petitionerAttributes(POST)';

        $h = $this->petitionController('co_petitions');
        $h->drive('petitionerAttributes', $pass, $named, 'POST', $this->attributesForm($pass[0], $token));
        $last = $h;
      }

      if(is_string($h->harnessRedirect)) {
        return array('trace' => $trace, 'stopped_at' => null, 'end' => $h->harnessRedirect, 'shown' => null,
                     'harness' => $h);
      }

      if($h->harnessRedirect === null) {
        return array('trace' => $trace, 'stopped_at' => null, 'end' => null,
                     'shown' => $controller . '/' . $action, 'harness' => $h);
      }

      $url = $h->harnessRedirect;
    }

    $this->fail('the petition did not end within ' . $max . ' steps: ' . implode(' ', $trace));
  }

  /** Where a newcomer's accepted response sends them: the flow's start URL. */
  protected function handOff($inv, $choices) {
    $h = $this->respondTo($inv, $choices);

    $this->assertTrue(is_array($h->harnessRedirect), 'handed off by a redirect: ' . var_export($h->view, true)
                      . ' ' . var_export(isset($h->viewVars['vv_reason']) ? $h->viewVars['vv_reason'] : null, true));

    return $h->harnessRedirect;
  }

  /** The explanation reason a URL points at, or null. */
  protected function explanationReason($url) {
    if(is_array($url) && isset($url['controller']) && $url['controller'] === 'ate_responses'
       && $url['action'] === 'explanation') {
      return isset($url[0]) ? $url[0] : '';
    }

    return null;
  }

  protected function isConfirmation($url) {
    return is_array($url) && isset($url['controller']) && $url['controller'] === 'ate_responses'
           && $url['action'] === 'confirmation';
  }

  protected function petitions() {
    return $this->fx->rows('SELECT * FROM cm_co_petitions WHERE co_id = ' . (int)$this->coId
                           . ' AND co_petition_id IS NULL ORDER BY id');
  }

  protected function newPeople($before) {
    return $this->fx->rows('SELECT * FROM cm_co_people WHERE co_id = ' . (int)$this->coId
                           . ' AND co_person_id IS NULL AND id > ' . (int)$before . ' ORDER BY id');
  }

  protected function maxPersonId() {
    return (int)$this->fx->scalar('SELECT COALESCE(MAX(id), 0) FROM cm_co_people');
  }

  protected function invStatus($inv) {
    return $this->invitationRow($inv['id'])['status'];
  }

  protected function reqStatus($inv, $appKey) {
    return $this->requestRow($inv['req'][$appKey])['status'];
  }

  // ---------------------------------------------------------------------
  // Helpers for the scenarios below

  /** The flow start URL, as the response page builds it. */
  protected function startUrl($flowId = null) {
    return array('plugin' => null, 'controller' => 'co_petitions', 'action' => 'start',
                 'coef' => $flowId ?: $this->flowId);
  }

  /** Respond, hand off, and run the flow to its end; asserts the confirmation page. */
  protected function completeRun($inv, $choices) {
    $run = $this->follow($this->handOff($inv, $choices));
    $this->assertTrue($this->isConfirmation($run['end']), 'ends on the confirmation page: '
                      . var_export($run['end'], true) . ' after ' . implode(' ', $run['trace']));

    return $run;
  }

  /** A hook that stops the browser just before $label ("core:finalize", "wedge:finalize", ...). */
  protected function stopBefore($label) {
    return function($controller, $action, $pass, $named) use ($label) {
      $here = (($controller === 'co_petitions') ? 'core' : 'wedge') . ':' . $action;
      return ($here === $label && !isset($named['done'])) ? false : null;
    };
  }

  /** Continue a petition at a step, with its petitioner token, as a bookmarked URL would. */
  protected function resumeUrl($petitionId, $action) {
    return array('controller' => 'co_petitions', 'action' => $action, $petitionId,
                 'token' => $this->fx->scalar('SELECT petitioner_token FROM cm_co_petitions WHERE id = '
                                              . (int)$petitionId));
  }

  protected function personStatus($coPersonId) {
    return $this->fx->scalar('SELECT status FROM cm_co_people WHERE id = ' . (int)$coPersonId);
  }
}
