<?php
/**
 * U9: the newcomer enrollment wedge (R21, AE5, KTD11, KTD14, KTD18).
 *
 * These tests run real petitions through Registry's own CoPetitionsController
 * and the plugin's ApplicationTeamEnrollerCoPetitionsController, the way a
 * browser would follow them: every redirect and every "next step" meta
 * refresh page is followed by building the controller it names and invoking
 * the action, with the named parameters (coef, efwid, done, token) and the
 * petition id taken from the URL. Registry's dispatch(), step configuration,
 * wedge hand-off, CoPetition::initialize(), saveAttributes(), updateStatus()
 * and the core finalize and provision steps all run unmodified against the
 * real database. What the harness does not run is beforeFilter() and
 * isAuthorized() (the Auth, token, and read-only petition checks), the views,
 * and a real login; those need a browser run in a real Registry.
 *
 * The newcomer flow is authorized for any authenticated user, has no
 * approval and no email confirmation, match policy None, no Org Identity
 * Source, and asks for a CO Person name and a CO Person Role affiliation.
 * Core's updateStatus() activates a CoPerson only through a role, so a flow
 * without a role attribute would leave the new CoPerson Pending; the plugin
 * refuses such a flow (see testFlowWithoutRoleIsRefused).
 *
 * Engine world (Test/lib/AteEngineTestCase.php):
 *   A  approval required   teams T1, T2
 *   B  approval required   team  TS
 *   C  approval off        teams T4, TS   (TS is shared with B)
 *   D  approval off        team  T5
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

class ApplicationTeamEnrollerCoPetitionsControllerTest extends AteEngineTestCase {

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
  private function resetRequestState() {
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
  private function newcomerFlow($role) {
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
  private function loginAs($identifier, $emails = array()) {
    CakeSession::write('Auth.User.username', $identifier);
    unset($_SERVER['OIDC_CLAIM_email']);

    if(!empty($emails)) {
      $_SERVER['OIDC_CLAIM_email'] = implode(',', $emails);
    }
  }

  /** A sent invitation whose raw token the test knows. */
  private function tokenInvitation($email, $offers, $overrides = array()) {
    $token = AteInvitation::generateToken();
    $inv = $this->invitation($email, $offers, $overrides + array('token_hash' => AteInvitation::hashToken($token)));
    $inv['token'] = $token;

    return $inv;
  }

  private function responses($data = array()) {
    $h = AteNewcomerResponsesHarness::harnessBuild('ate_responses', $this->coId, array(), $data);
    $h->Session = new SessionComponent(new ComponentCollection());
    $h->AteEnrollmentRequest->emailConfig = AteRecordingTransport::emailConfig();

    return $h;
  }

  /**
   * Follow the link, open the page, submit $choices (application key =>
   * accept). Returns the submit harness.
   */
  private function respondTo($inv, $choices) {
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
  private function petitionController($controller) {
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
  private function attributesForm($petitionId, $token) {
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
  private function follow($url, $hook = null, $max = 60) {
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
  private function handOff($inv, $choices) {
    $h = $this->respondTo($inv, $choices);

    $this->assertTrue(is_array($h->harnessRedirect), 'handed off by a redirect: ' . var_export($h->view, true)
                      . ' ' . var_export(isset($h->viewVars['vv_reason']) ? $h->viewVars['vv_reason'] : null, true));

    return $h->harnessRedirect;
  }

  /** The explanation reason a URL points at, or null. */
  private function explanationReason($url) {
    if(is_array($url) && isset($url['controller']) && $url['controller'] === 'ate_responses'
       && $url['action'] === 'explanation') {
      return isset($url[0]) ? $url[0] : '';
    }

    return null;
  }

  private function isConfirmation($url) {
    return is_array($url) && isset($url['controller']) && $url['controller'] === 'ate_responses'
           && $url['action'] === 'confirmation';
  }

  private function petitions() {
    return $this->fx->rows('SELECT * FROM cm_co_petitions WHERE co_id = ' . (int)$this->coId
                           . ' AND co_petition_id IS NULL ORDER BY id');
  }

  private function newPeople($before) {
    return $this->fx->rows('SELECT * FROM cm_co_people WHERE co_id = ' . (int)$this->coId
                           . ' AND co_person_id IS NULL AND id > ' . (int)$before . ' ORDER BY id');
  }

  private function maxPersonId() {
    return (int)$this->fx->scalar('SELECT COALESCE(MAX(id), 0) FROM cm_co_people');
  }

  private function invStatus($inv) {
    return $this->invitationRow($inv['id'])['status'];
  }

  private function reqStatus($inv, $appKey) {
    return $this->requestRow($inv['req'][$appKey])['status'];
  }

  // ---------------------------------------------------------------------
  // The full run

  /**
   * Execution note and AE5 (accept half): a newcomer who accepts goes from
   * the response page through Registry's flow and the wedge to the
   * confirmation page, with exactly one CoPerson created, bound to the
   * invitation, and the response committed and routed.
   */
  public function testFullRunCreatesOnePersonAndCommits() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'C' => array('t4'), 'D' => array('t5')));
    $sub = $this->sub('newcomer');
    $this->loginAs($sub, array(self::Invited));
    $before = $this->maxPersonId();

    $start = $this->handOff($inv, array('A' => true, 'C' => false, 'D' => true));

    $this->assertEqual(array('plugin' => null, 'controller' => 'co_petitions', 'action' => 'start',
                             'coef' => $this->flowId), $start, 'the response page redirects into the flow');
    $this->assertEqual('sent', $this->invStatus($inv), 'not committed at hand-off');

    $seenAtWedge = array();
    $run = $this->follow($start, function($controller, $action, $pass, $named) use (&$seenAtWedge, $before) {
      if($controller !== 'co_petitions') {
        $seenAtWedge[$action] = array(
          'petitions' => count($this->petitions()),
          'people' => count($this->newPeople($before)),
          'status' => empty($pass) ? null : $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = '
                                                              . (int)$pass[0])
        );
      }
      return null;
    });

    $this->assertTrue($this->isConfirmation($run['end']), 'ends on the confirmation page: '
                      . var_export($run['end'], true) . ' after ' . implode(' ', $run['trace'])
                      . ' flash: ' . json_encode($run['harness'] ? $run['harness']->Flash->messages : null));

    // The step order Registry actually ran
    $this->assertEqual(array(
      'core:start', 'core:start/done:core', 'wedge:start', 'core:start/done:wedge',
      'core:selectEnrollee', 'core:selectOrgIdentity', 'core:petitionerAttributes',
      'core:petitionerAttributes(POST)', 'core:petitionerAttributes/done:core',
      'wedge:petitionerAttributes', 'core:petitionerAttributes/done:wedge',
      'core:duplicateCheck', 'core:tandcPetitioner', 'core:sendConfirmation', 'core:waitForConfirmation',
      'core:checkEligibility', 'core:tandcAgreement', 'core:establishAuthenticators', 'core:requestVetting',
      'core:sendApproverNotification', 'core:waitForApproval', 'core:finalize', 'core:finalize/done:core',
      'wedge:finalize', 'core:finalize/done:wedge', 'core:provision', 'core:provision/done:core',
      'wedge:provision'
    ), $run['trace'], 'step order');

    // When the petition and the CoPerson appear
    $this->assertEqual(0, $seenAtWedge['start']['petitions'], 'the wedge start runs before any petition exists');
    $this->assertEqual(1, $seenAtWedge['petitionerAttributes']['people'],
                       'the CoPerson exists when the wedge petitionerAttributes runs');
    $this->assertEqual('F', $seenAtWedge['finalize']['status'], 'core finalizes before the wedge finalize');

    // Exactly one CoPerson, Active, bound to the invitation
    $people = $this->newPeople($before);
    $this->assertEqual(1, count($people), 'exactly one CoPerson created');
    $person = (int)$people[0]['id'];
    $this->assertEqual('A', $people[0]['status'], 'and it is Active');

    $pts = $this->petitions();
    $this->assertEqual(1, count($pts), 'one petition');
    $this->assertEqual('F', $pts[0]['status'], 'finalized');
    $this->assertEqual($person, (int)$pts[0]['enrollee_co_person_id'], 'for that CoPerson');

    $row = $this->invitationRow($inv['id']);
    $this->assertEqual('responded', $row['status'], 'committed');
    $this->assertEqual((int)$pts[0]['id'], (int)$row['co_petition_id'], 'petition bound to the invitation');
    $this->assertEqual($person, (int)$row['invitee_co_person_id'], 'CoPerson linked to the invitation');
    $this->assertEqual($sub, $row['responder_identifier'], 'the login recorded');

    // Routed per the draft
    $this->assertEqual('pending_decision', $this->reqStatus($inv, 'A'), 'A awaits its approver');
    $this->assertEqual('declined_by_enrollee', $this->reqStatus($inv, 'C'), 'C declined');
    $this->assertEqual('approved', $this->reqStatus($inv, 'D'), 'D approved automatically');
    $this->assertEqual(1, count($this->directRows('t5', $person)), 'T5 membership for the new CoPerson');
    $this->assertEqual(1, count(AteRecordingTransport::$sent), 'one decision email, for D');

    // The login is attached (KTD11)
    $this->assertEqual($person, $this->Req->existingMemberCoPersonId($this->coId, $sub), 'login attached');
    $this->assertEqual(1, (int)$this->fx->scalar(
      "SELECT count(*) FROM cm_identifiers i JOIN cm_co_org_identity_links l ON l.org_identity_id = i.org_identity_id"
      . " WHERE l.co_person_id = " . $person . " AND i.identifier = '" . $sub . "' AND i.type = 'eppn'"
      . " AND i.login = true AND i.status = 'A' AND i.deleted IS NOT true AND l.deleted IS NOT true"),
      'as an Active login Identifier of the configured type');

    // Session: binding used up, confirmation ready
    $this->assertNull(CakeSession::read('ApplicationTeamEnroller.Newcomer'), 'binding cleared');
    $this->assertNull(CakeSession::read('ApplicationTeamEnroller.Response.snapshot'), 'snapshot cleared');
    $this->assertEqual(array('co_id' => $this->coId, 'invitation_id' => $inv['id']),
                       CakeSession::read('ApplicationTeamEnroller.Response.confirmation'), 'confirmation set');

    $conf = $this->responses();
    $conf->harnessInvoke('confirmation');
    $this->assertEqual('confirmation', $conf->view, 'the confirmation page shows');
    $this->assertEqual(3, count($conf->viewVars['vv_requests']), 'each application');

    // The link is used up
    $land = $this->responses();
    $land->harnessInvoke('landing', array($inv['token']));
    $this->assertEqual('answered', $land->viewVars['vv_reason'], 'link used up');
  }

  // ---------------------------------------------------------------------
  // Helpers for the scenarios below

  /** The flow start URL, as the response page builds it. */
  private function startUrl($flowId = null) {
    return array('plugin' => null, 'controller' => 'co_petitions', 'action' => 'start',
                 'coef' => $flowId ?: $this->flowId);
  }

  /** Respond, hand off, and run the flow to its end; asserts the confirmation page. */
  private function completeRun($inv, $choices) {
    $run = $this->follow($this->handOff($inv, $choices));
    $this->assertTrue($this->isConfirmation($run['end']), 'ends on the confirmation page: '
                      . var_export($run['end'], true) . ' after ' . implode(' ', $run['trace']));

    return $run;
  }

  /** A hook that stops the browser just before $label ("core:finalize", "wedge:finalize", ...). */
  private function stopBefore($label) {
    return function($controller, $action, $pass, $named) use ($label) {
      $here = (($controller === 'co_petitions') ? 'core' : 'wedge') . ':' . $action;
      return ($here === $label && !isset($named['done'])) ? false : null;
    };
  }

  /** Continue a petition at a step, with its petitioner token, as a bookmarked URL would. */
  private function resumeUrl($petitionId, $action) {
    return array('controller' => 'co_petitions', 'action' => $action, $petitionId,
                 'token' => $this->fx->scalar('SELECT petitioner_token FROM cm_co_petitions WHERE id = '
                                              . (int)$petitionId));
  }

  private function personStatus($coPersonId) {
    return $this->fx->scalar('SELECT status FROM cm_co_people WHERE id = ' . (int)$coPersonId);
  }

  // ---------------------------------------------------------------------
  // start refuses petitions without a live invitation (R21, KTD11)

  /** A flow start with no session binding is refused before any petition exists. */
  public function testStartWithoutBindingIsRefused() {
    $this->loginAs($this->sub('walk-in'), array(self::Invited));

    $run = $this->follow($this->startUrl());

    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'explanation page');
    $this->assertEqual(array('core:start', 'core:start/done:core', 'wedge:start'), $run['trace'],
                       'stopped at the wedge start');
    $this->assertEqual(array(), $this->petitions(), 'no petition created');

    // The explanation page itself
    $h = $this->responses();
    $h->harnessInvoke('explanation', array('newcomer_session'));
    $this->assertEqual('explanation', $h->view, 'explanation view');
    $this->assertEqual('newcomer_session', $h->viewVars['vv_reason'], 'with the reason');

    $h = $this->responses();
    $h->harnessInvoke('explanation', array('<b>made up</b>'));
    $this->assertEqual('no_invitation', $h->viewVars['vv_reason'], 'only the wedge\'s reasons are shown');

    $h = $this->responses();
    $h->action = 'explanation';
    $h->request->params['action'] = 'explanation';
    $this->assertTrue((bool)$h->isAuthorized(), 'a login may see it');
  }

  /** KTD5: a flow start whose login differs from the binding's is refused. */
  public function testStartWithOtherLoginIsRefused() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('login-a'), array(self::Invited));
    $start = $this->handOff($inv, array('A' => true));

    $this->loginAs($this->sub('login-b'), array(self::Invited));
    $run = $this->follow($start);

    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'refused');
    $this->assertEqual(array(), $this->petitions(), 'no petition created');
    $this->assertEqual('sent', $this->invStatus($inv), 'invitation untouched');
    $this->assertTrue((bool)$this->requestRow($inv['req']['A'])['draft_choice'], 'draft kept');
  }

  /** A flow start for a revoked invitation is refused. */
  public function testStartForRevokedInvitationIsRefused() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('revoked'), array(self::Invited));
    $start = $this->handOff($inv, array('A' => true));

    $this->fx->query("UPDATE cm_ate_invitations SET status = 'revoked' WHERE id = " . (int)$inv['id']);
    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'revoked' WHERE ate_invitation_id = "
                     . (int)$inv['id']);

    $run = $this->follow($start);

    $this->assertEqual('revoked', $this->explanationReason($run['end']), 'refused as revoked');
    $this->assertEqual(array(), $this->petitions(), 'no petition created');
  }

  /**
   * start also needs this CO's newcomer flow, a draft that accepts
   * something, and an invitation not past its expiry.
   */
  public function testStartNeedsNewcomerFlowAcceptedDraftAndLiveInvitation() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $this->loginAs($this->sub('checks'), array(self::Invited));
    $start = $this->handOff($inv, array('A' => true, 'C' => false));

    // Another flow of the CO carrying this wedge
    $other = $this->fx->flow($this->coId, 'Other ' . AteFixtures::tag('flow'));
    $this->fx->wedge($other);
    $run = $this->follow($this->startUrl($other));
    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'not the newcomer flow');

    // No acceptance in the draft
    $this->fx->query('UPDATE cm_ate_enrollment_requests SET draft_choice = false WHERE ate_invitation_id = '
                     . (int)$inv['id']);
    $run = $this->follow($start);
    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'nothing accepted');

    // Past its expiry, with no bound petition
    $this->fx->query('UPDATE cm_ate_enrollment_requests SET draft_choice = true WHERE ate_invitation_id = '
                     . (int)$inv['id']);
    $this->fx->query("UPDATE cm_ate_invitations SET expires = '" . date('Y-m-d H:i:s', time() - 60)
                     . "' WHERE id = " . (int)$inv['id']);
    $run = $this->follow($start);
    $this->assertEqual('expired', $this->explanationReason($run['end']), 'lapsed');
    $this->assertEqual('expired', $this->invStatus($inv), 'expired on access (KTD14)');

    $this->assertEqual(array(), $this->petitions(), 'no petition created by any of them');
  }

  // ---------------------------------------------------------------------
  // Bypass, expiry, and returning researchers

  /**
   * KTD18: done:<wedge id> on every hop skips the plugin. The petition
   * completes unbound, the invitation stays sent, and the CoPerson it
   * creates has no team membership and no login: the expiry job (U11)
   * contains it. Skipping only the binding step is refused at finalize.
   */
  public function testDoneSkipLeavesPetitionUnbound() {
    $inv = $this->tokenInvitation(self::Invited, array('D' => array('t5')));
    $sub = $this->sub('bypass');
    $this->loginAs($sub, array(self::Invited));
    $this->handOff($inv, array('D' => true));
    $before = $this->maxPersonId();
    $wedge = $this->wedgeId;

    $run = $this->follow($this->startUrl(), function($controller, $action, $pass, $named) use ($wedge) {
      if($controller === 'application_team_enroller_co_petitions') {
        unset($named['efwid']);
        $named['done'] = $wedge;
        return array('co_petitions', $action, $pass, $named);
      }
      return null;
    });

    $this->assertFalse(in_array(true, array_map(function($t) { return strpos($t, 'wedge:') === 0; },
                                                $run['trace']), true), 'no plugin step ran');
    $this->assertNull($this->explanationReason($run['end']), 'not refused: the plugin never ran');

    $pts = $this->petitions();
    $this->assertEqual(1, count($pts), 'the petition exists');
    $this->assertEqual('F', $pts[0]['status'], 'and was finalized');

    $people = $this->newPeople($before);
    $this->assertEqual(1, count($people), 'it created a CoPerson');

    $row = $this->invitationRow($inv['id']);
    $this->assertNull($row['co_petition_id'], 'the petition is not bound to the invitation');
    $this->assertEqual('sent', $row['status'], 'the invitation is not committed');
    $this->assertEqual('offered', $this->reqStatus($inv, 'D'), 'D still offered');
    $this->assertEqual(array(), $this->allRows('t5', (int)$people[0]['id']), 'no team membership');
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $sub), 'no login attached');

    // Skipping only the binding step, with a live session binding, does not
    // let the wedge's finalize commit an unbound petition
    $this->handOff($inv, array('D' => true));
    $run = $this->follow($this->startUrl(), function($controller, $action, $pass, $named) use ($wedge) {
      if($controller === 'application_team_enroller_co_petitions' && $action === 'petitionerAttributes') {
        unset($named['efwid']);
        $named['done'] = $wedge;
        return array('co_petitions', $action, $pass, $named);
      }
      return null;
    });

    $this->assertTrue(in_array('wedge:finalize', $run['trace'], true), 'the wedge finalize ran');
    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'and refused the unbound petition');
    $this->assertEqual('sent', $this->invStatus($inv), 'the invitation is still not committed');
    $this->assertNull($this->invitationRow($inv['id'])['co_petition_id'], 'nor bound');
  }

  /**
   * KTD11, KTD14: an invitation that passes its expiry after the petition was
   * bound is still honored at finalize; a new start is not.
   */
  public function testExpiryAfterBindingIsHonored() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('late'), array(self::Invited));
    $start = $this->handOff($inv, array('A' => true));

    $run = $this->follow($start, $this->stopBefore('core:finalize'));
    $this->assertNotEmpty($run['stopped_at'], 'stopped before finalize');
    $this->assertNotEmpty($this->invitationRow($inv['id'])['co_petition_id'], 'bound');

    $this->fx->query("UPDATE cm_ate_invitations SET expires = '" . date('Y-m-d H:i:s', time() - 3600)
                     . "' WHERE id = " . (int)$inv['id']);

    // A fresh start now is refused, and does not expire the invitation
    $again = $this->follow($this->startUrl());
    $this->assertEqual('expired', $this->explanationReason($again['end']), 'no new petition past expiry');
    $this->assertEqual('sent', $this->invStatus($inv), 'kept by the grace window');
    $this->assertEqual(1, count($this->petitions()), 'no second petition');

    // The bound petition finishes
    $run = $this->follow($run['stopped_at']);
    $this->assertTrue($this->isConfirmation($run['end']), 'honored: ' . var_export($run['end'], true));
    $this->assertEqual('responded', $this->invStatus($inv), 'committed');
    $this->assertEqual('pending_decision', $this->reqStatus($inv, 'A'), 'A routed');
  }

  /**
   * KTD6: after a full run with a flow that has no Org Identity Source, the
   * new CoPerson is an existing member for the next invitation: no hand-off,
   * no second petition, no second CoPerson.
   */
  public function testNewPersonIsExistingMemberNextTime() {
    $sub = $this->sub('returning');
    $this->loginAs($sub, array(self::Invited));
    $before = $this->maxPersonId();

    $first = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->completeRun($first, array('A' => true));
    $person = (int)$this->newPeople($before)[0]['id'];

    CakeSession::delete('ApplicationTeamEnroller');
    $second = $this->tokenInvitation(self::Invited, array('D' => array('t5')));
    $h = $this->respondTo($second, array('D' => true));

    $this->assertTrue($this->isConfirmation($h->harnessRedirect), 'committed on the response page');
    $row = $this->invitationRow($second['id']);
    $this->assertEqual('responded', $row['status'], 'responded');
    $this->assertEqual($person, (int)$row['invitee_co_person_id'], 'as the CoPerson the flow created');
    $this->assertNull($row['co_petition_id'], 'no petition for it');
    $this->assertEqual('approved', $this->reqStatus($second, 'D'), 'D approved');
    $this->assertEqual(1, count($this->directRows('t5', $person)), 'membership on that CoPerson');
    $this->assertEqual(1, count($this->petitions()), 'still one petition');
    $this->assertEqual(1, count($this->newPeople($before)), 'still one CoPerson');
  }

  /**
   * Closing the browser mid-flow leaves the invitation sent, before and after
   * the petition is bound, and the link still works.
   */
  public function testAbandonedFlowLeavesInvitationSent() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('closer'), array(self::Invited));
    $before = $this->maxPersonId();

    // At the attributes form: a petition exists, no CoPerson yet
    $run = $this->follow($this->handOff($inv, array('A' => true)), $this->stopBefore('core:petitionerAttributes'));
    $this->assertNotEmpty($run['stopped_at'], 'closed at the form');
    $this->assertEqual('CR', $this->petitions()[0]['status'], 'petition created');
    $this->assertEqual(array(), $this->newPeople($before), 'no CoPerson yet');
    $this->assertNull($this->invitationRow($inv['id'])['co_petition_id'], 'not bound yet');

    // After binding: the CoPerson exists, still Pending
    $run = $this->follow($this->handOff($inv, array('A' => true)), $this->stopBefore('core:finalize'));
    $this->assertNotEmpty($run['stopped_at'], 'closed before finalize');
    $people = $this->newPeople($before);
    $this->assertEqual(1, count($people), 'the CoPerson exists');
    $this->assertEqual('P', $people[0]['status'], 'Pending');
    $this->assertNotEmpty($this->invitationRow($inv['id'])['co_petition_id'], 'bound');

    $this->assertEqual('sent', $this->invStatus($inv), 'invitation still sent');
    $this->assertEqual('offered', $this->reqStatus($inv, 'A'), 'request still offered');

    $land = $this->responses();
    $land->harnessInvoke('landing', array($inv['token']));
    $this->assertEqual('respond', $land->harnessRedirect['action'], 'the link still works');
  }

  /**
   * Starting the flow again while the first petition is unfinished leaves
   * only the newer petition bound, retires the older one, and ends with one
   * Active CoPerson. The retired petition cannot be finished.
   */
  public function testSecondStartRetiresFirstPetition() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('twice'), array(self::Invited));
    $before = $this->maxPersonId();

    $this->follow($this->handOff($inv, array('A' => true)), $this->stopBefore('core:finalize'));
    $first = (int)$this->invitationRow($inv['id'])['co_petition_id'];
    $firstPerson = (int)$this->newPeople($before)[0]['id'];

    // The login is not attached to the abandoned CoPerson, so this is a
    // newcomer again, not an existing member
    $this->completeRun($inv, array('A' => true));

    $pts = $this->petitions();
    $this->assertEqual(2, count($pts), 'two petitions');
    $second = (int)$pts[1]['id'];
    $this->assertEqual($second, (int)$this->invitationRow($inv['id'])['co_petition_id'], 'the newer one is bound');
    $this->assertEqual('X', $pts[0]['status'], 'the older one is Declined');
    $this->assertEqual('F', $pts[1]['status'], 'the newer one is Finalized');
    $this->assertEqual(1, (int)$this->fx->scalar("SELECT count(*) FROM cm_co_petition_history_records"
                         . " WHERE co_petition_id = " . $first . " AND action = 'CM'"
                         . " AND comment LIKE '%" . $second . "%'"), 'the retirement is recorded on it');

    $people = $this->newPeople($before);
    $this->assertEqual(2, count($people), 'two CoPeople were created');
    $active = array_values(array_filter($people, function($p) { return $p['status'] === 'A'; }));
    $this->assertEqual(1, count($active), 'only one is Active');
    $this->assertEqual((int)$pts[1]['enrollee_co_person_id'], (int)$active[0]['id'], 'the newer petition\'s');
    $this->assertEqual((int)$active[0]['id'], (int)$this->invitationRow($inv['id'])['invitee_co_person_id'],
                       'and it is linked to the invitation');

    // Finishing the retired petition does nothing
    $run = $this->follow($this->resumeUrl($first, 'finalize'));
    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'refused at the wedge');
    $this->assertEqual('X', $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $first),
                       'core did not finalize it');
    $this->assertFalse($this->personStatus($firstPerson) === 'A', 'its CoPerson is not Active');
  }

  /**
   * A CoPerson from an interrupted flow that already carries the login is
   * recognized through the invitation's bound petition: an unfinished
   * petition sends the researcher back through the flow, not in as an
   * existing member; a finalized one commits against its enrollee.
   */
  public function testContinuingNewcomerIsRoutedByBoundPetition() {
    $sub = $this->sub('continuing');
    $this->loginAs($sub, array(self::Invited));
    $before = $this->maxPersonId();
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));

    // Unfinished: bound, Pending CoPerson, and (as an Org Identity Source
    // could do) the login already on it
    $this->follow($this->handOff($inv, array('A' => true)), $this->stopBefore('core:finalize'));
    $person = (int)$this->newPeople($before)[0]['id'];
    $this->Req->attachLogin($this->coId, $person, $sub, 'eppn', null);
    $this->assertEqual($person, $this->Req->existingMemberCoPersonId($this->coId, $sub), 'login maps to it');

    $h = $this->respondTo($inv, array('A' => true));
    $this->assertEqual($this->startUrl(), $h->harnessRedirect, 'sent back through the flow');
    $this->assertEqual('sent', $this->invStatus($inv), 'not committed as an existing member');
    $this->assertEqual('offered', $this->reqStatus($inv, 'A'), 'A still offered');

    // Finalized: the flow completed, the login was attached, the commit did not happen
    $pt = (int)$this->invitationRow($inv['id'])['co_petition_id'];
    $this->fx->query("UPDATE cm_co_petitions SET status = 'F' WHERE id = " . $pt);

    $h = $this->respondTo($inv, array('A' => true));
    $this->assertTrue($this->isConfirmation($h->harnessRedirect), 'committed on the response page');
    $row = $this->invitationRow($inv['id']);
    $this->assertEqual('responded', $row['status'], 'responded');
    $this->assertEqual($person, (int)$row['invitee_co_person_id'], 'against the petition\'s CoPerson');
    $this->assertEqual('pending_decision', $this->reqStatus($inv, 'A'), 'A routed');
    $this->assertEqual(1, count($this->petitions()), 'no new petition');
    $this->assertEqual(1, count($this->newPeople($before)), 'no second CoPerson');
  }

  /** KTD5: finalize commits only for the login that responded. */
  public function testFinalizeRefusesAnotherLogin() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $sub = $this->sub('finalize-a');
    $other = $this->sub('finalize-b');
    $this->loginAs($sub, array(self::Invited));

    $run = $this->follow($this->handOff($inv, array('A' => true)),
      function($controller, $action, $pass, $named) use ($other) {
        if($controller === 'application_team_enroller_co_petitions' && $action === 'finalize') {
          CakeSession::write('Auth.User.username', $other);
        }
        return null;
      });

    $this->assertEqual('newcomer_session', $this->explanationReason($run['end']), 'refused');
    $this->assertEqual('sent', $this->invStatus($inv), 'nothing committed');
    $this->assertEqual('offered', $this->reqStatus($inv, 'A'), 'A still offered');
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $other), 'other login not attached');
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $sub), 'responder login not attached');
  }

  /** With no usable newcomer flow the draft is saved and the researcher is told. */
  public function testNoNewcomerFlowExplains() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('no-flow'), array(self::Invited));

    $this->fx->query('UPDATE cm_ate_settings SET newcomer_co_enrollment_flow_id = NULL WHERE co_id = '
                     . (int)$this->coId);
    $h = $this->respondTo($inv, array('A' => true));

    $this->assertEqual('explanation', $h->view, 'explanation page');
    $this->assertEqual('newcomer_unavailable', $h->viewVars['vv_reason'], 'no flow');
    $this->assertTrue((bool)$this->requestRow($inv['req']['A'])['draft_choice'], 'draft saved');
    $this->assertEqual('sent', $this->invStatus($inv), 'invitation still sent');

    // A configured flow whose wedge was suspended no longer qualifies
    $this->fx->query('UPDATE cm_ate_settings SET newcomer_co_enrollment_flow_id = ' . (int)$this->flowId
                     . ' WHERE co_id = ' . (int)$this->coId);
    $this->fx->query("UPDATE cm_co_enrollment_flow_wedges SET status = 'S' WHERE id = " . (int)$this->wedgeId);
    $h = $this->respondTo($inv, array('A' => true));

    $this->assertEqual('newcomer_unavailable', $h->viewVars['vv_reason'], 'unusable flow');
    $this->assertEqual(array(), $this->petitions(), 'no petition');
  }

  /**
   * Registry activates a new CoPerson only through a CO Person Role:
   * CoPetition::updateStatus() sets the Active status on finalize by saving
   * the petition's role, and CoPersonRole's afterSave recalculates the
   * CoPerson from its roles. A petition creates a role only from role ("r:")
   * attributes, so a flow that collects none finalizes with the new CoPerson
   * left Pending forever. The plugin therefore refuses such a flow as the
   * newcomer flow (AteSetting::newcomerFlowProblem()): even if the setting
   * already names it, the researcher gets the newcomer_unavailable
   * explanation, the draft stays saved, and no petition or CoPerson is made.
   */
  public function testFlowWithoutRoleIsRefused() {
    $this->flowId = $this->newcomerFlow(false);
    $this->fx->query('UPDATE cm_ate_settings SET newcomer_co_enrollment_flow_id = ' . (int)$this->flowId
                     . ' WHERE co_id = ' . (int)$this->coId);

    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('no-role'), array(self::Invited));
    $before = $this->maxPersonId();

    $h = $this->respondTo($inv, array('A' => true));

    $this->assertEqual('explanation', $h->view, 'explanation page');
    $this->assertEqual('newcomer_unavailable', $h->viewVars['vv_reason'], 'role-less flow refused');
    $this->assertTrue((bool)$this->requestRow($inv['req']['A'])['draft_choice'], 'draft saved');
    $this->assertEqual('sent', $this->invStatus($inv), 'invitation still sent');
    $this->assertEqual(array(), $this->petitions(), 'no petition');
    $this->assertEqual(array(), $this->newPeople($before), 'no CoPerson');
  }
}
