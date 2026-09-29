<?php
/**
 * COmanage Registry Application Team Enroller Responses Controller
 *
 * The researcher's side of an invitation (F2, R17-R23):
 *
 * - landing: the invitation link. Reachable without a login. It looks the
 *   token up by its hash (KTD4), expires a lapsed invitation on access
 *   (KTD14), explains an unusable link, and otherwise keeps the token's hash
 *   in the session and sends the user to respond, which requires a login,
 *   so Registry's login brings them back there.
 * - respond: needs only a login (KTD16). It re-validates the session's token
 *   on every GET and POST, and never takes an invitation from the request.
 *   The page builds the identity snapshot (KTD5) into the session along with
 *   a one-time form nonce. A submit commits only when the current login is
 *   the snapshot's and the form is the one the snapshot was built for, so
 *   one login can never commit against another's snapshot. Past expiry,
 *   in a bound petition's grace window (KTD14), only that petition's
 *   newcomer may answer; everyone else is told the invitation expired.
 * - confirmation: the state of each application after a response this
 *   session committed, with no mismatch details (R19).
 * - explanation: why a newcomer's enrollment flow was stopped, for the
 *   enrollment flow wedge (U9), which can only redirect.
 *
 * Existing members (KTD6), newcomers who decline everything, and logins
 * whose approval would link them to an existing person (R21, link_required)
 * are committed here through AteEnrollmentRequest::commitResponse(). A
 * newcomer who accepts anything has their choices saved as a draft and is
 * handed to the newcomer enrollment flow (KTD11), whose wedge
 * (ApplicationTeamEnrollerCoPetitionsController) commits the draft once the
 * flow has created their CoPerson.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppController', 'ApplicationTeamEnroller.Controller');
App::uses('AteIdentitySnapshot', 'ApplicationTeamEnroller.Lib');
App::uses('AteInvitation', 'ApplicationTeamEnroller.Model');

class AteResponsesController extends ApplicationTeamEnrollerAppController {
  // Class name, used by Cake
  public $name = "AteResponses";

  // The first model is the controller's modelClass, which Registry's
  // AppController uses in beforeFilter()
  public $uses = array(
    'ApplicationTeamEnroller.AteInvitation',
    'ApplicationTeamEnroller.AteEnrollmentRequest',
    'ApplicationTeamEnroller.AteSetting'
  );

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // The link carries no CO; each action takes the CO from the invitation
  public $requires_co = false;

  // Session keys. The token's hash, never the token, is kept (KTD4).
  const SessionTokenHash    = 'ApplicationTeamEnroller.Response.token_hash';
  // invitation_id, co_id, nonce, snapshot: built by the respond page (KTD5)
  const SessionSnapshot     = 'ApplicationTeamEnroller.Response.snapshot';
  // co_id, invitation_id: the response this session committed
  const SessionConfirmation = 'ApplicationTeamEnroller.Response.confirmation';
  // co_id, invitation_id, identifier, snapshot: the newcomer binding (KTD11)
  const SessionNewcomer     = 'ApplicationTeamEnroller.Newcomer';

  // Reasons the enrollment flow wedge may send to explanation()
  public static $flowReasons = array(
    'unknown', 'revoked', 'expired', 'answered', 'ambiguous', 'error',
    'newcomer_session', 'newcomer_incomplete'
  );

  /**
   * Callback before other controller methods are invoked or views are
   * rendered. The landing page is reachable without a login, like
   * CoInvitesController's confirm.
   *
   * @since  COmanage Registry v4.6.0
   */

  function beforeFilter() {
    parent::beforeFilter();

    $this->Auth->allow('landing');
  }

  /**
   * Determine the CO for an action from the invitation it concerns, so the
   * page renders in the CO's context. The actions themselves never rely on
   * it: each takes the CO from the invitation.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $data Array of data for calculating implied CO ID
   * @return Integer       The CO ID if found, or null
   */

  protected function calculateImpliedCoId($data = null) {
    $inv = null;

    if($this->action == 'landing' && !empty($this->request->params['pass'][0])) {
      $inv = $this->AteInvitation->findByToken($this->request->params['pass'][0]);
    } elseif($this->action == 'respond') {
      $inv = $this->AteInvitation->findByTokenHash($this->Session->read(self::SessionTokenHash));
    } elseif($this->action == 'confirmation') {
      $c = $this->Session->read(self::SessionConfirmation);

      return !empty($c['co_id']) ? (int)$c['co_id'] : null;
    }

    return !empty($inv['co_id']) ? (int)$inv['co_id'] : null;
  }

  /**
   * Registry's check that a record ID in the URL belongs to the current CO.
   * The only argument these actions take is a link token, which is not a
   * record ID; each action validates the token itself.
   *
   * @since  COmanage Registry v4.6.0
   * @return Boolean True
   */

  public function verifyRequestedId() {
    return true;
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * A first-time CILogon user has no CoPerson and no `user` role, so
   * responding requires only a login (KTD16). Which invitation may be
   * answered is decided by the token, in the actions.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Permissions
   */

  function isAuthorized() {
    $p = array();

    // Follow an invitation link? Anyone; the action checks the token.
    $p['landing'] = true;

    // Respond to the invitation in the session, and see the result?
    $mayRespond = $this->AteAuthz->mayRespond();
    $p['respond'] = $mayRespond;
    $p['confirmation'] = $mayRespond;
    $p['explanation'] = $mayRespond;

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }

  /**
   * Follow an invitation link (F2, R18, AE12). An unknown, revoked,
   * expired, or answered invitation shows the explanation page; a lapsed one
   * is expired first (KTD14). Otherwise the token's hash goes into the
   * session and the user is sent to respond, logging in on the way.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $token Token as carried in the link
   */

  public function landing($token = null) {
    $inv = $this->AteInvitation->findByToken($token);
    $problem = $this->invitationProblem($inv);

    if($problem !== null) {
      $this->explain($problem);
      return;
    }

    $this->Session->write(self::SessionTokenHash, AteInvitation::hashToken($token));
    $this->Session->delete(self::SessionSnapshot);

    $this->redirect(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_responses',
      'action'     => 'respond'
    ));
  }

  /**
   * Show the offered applications and record the response (F2, R19-R23).
   * The invitation is always the one whose token hash the session holds,
   * re-validated on every request.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function respond() {
    $hash = $this->Session->read(self::SessionTokenHash);

    if(empty($hash)) {
      $this->explain('no_invitation');
      return;
    }

    $inv = $this->AteInvitation->findByTokenHash($hash);
    $problem = $this->responseProblem($inv, $this->Session->read('Auth.User.username'));

    if($problem !== null) {
      $this->explain($problem);
      return;
    }

    if($this->request->is('post')) {
      $this->submitResponse($inv);
      return;
    }

    $coId = (int)$inv['co_id'];
    $username = $this->Session->read('Auth.User.username');
    $settings = $this->AteSetting->getOrCreateForCo($coId);

    $snapshot = AteIdentitySnapshot::build($coId, $username, $settings['AteSetting'], $this->AteEnrollmentRequest,
                                           null, AteIdentitySnapshot::displayName($this->Session->read('Auth.User.name')));

    foreach($snapshot['errors'] as $err) {
      $this->log('ApplicationTeamEnroller response page for ' . $username . ': ' . $err);
    }

    $nonce = bin2hex(random_bytes(16));

    $this->Session->write(self::SessionSnapshot, array(
      'invitation_id' => (int)$inv['id'],
      'co_id'         => $coId,
      'nonce'         => $nonce,
      'snapshot'      => $snapshot
    ));

    $this->showForm($inv, $nonce);
  }

  /**
   * Show the result of the response this session committed (R19): each
   * application with its state, and nothing about the identity checks.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function confirmation() {
    $c = $this->Session->read(self::SessionConfirmation);
    $inv = empty($c['invitation_id']) ? null : $this->AteInvitation->find('first', array(
      'conditions' => array(
        'AteInvitation.id'    => (int)$c['invitation_id'],
        'AteInvitation.co_id' => (int)$c['co_id']
      ),
      'contain' => false
    ));

    // Only the login that responded sees the result
    if(empty($inv['AteInvitation'])
       || $inv['AteInvitation']['responder_identifier'] !== $this->Session->read('Auth.User.username')) {
      $this->explain('no_invitation');
      return;
    }

    $requests = array();

    foreach($this->requestsFor($inv['AteInvitation']['id'], false) as $r) {
      $requests[] = array(
        'id'          => $r['id'],
        'application' => $r['application'],
        'teams'       => $r['teams'],
        'state'       => _txt('pl.applicationteamenroller.response.state.' . $r['status'])
      );
    }

    $this->set('title_for_layout', _txt('pl.applicationteamenroller.response.confirmation'));
    $this->set('vv_requests', $requests);
    $this->view = 'confirmation';
  }

  /**
   * Explain why the newcomer enrollment flow stopped (U9). The wedge in the
   * flow can only redirect, so it names a reason here; only the reasons it
   * uses are accepted.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $reason Explanation reason
   */

  public function explanation($reason = null) {
    $this->explain(in_array($reason, self::$flowReasons, true) ? $reason : 'no_invitation');
  }

  /**
   * Record a submitted response. The form must be the one built for the
   * session's snapshot, the current login must be the snapshot's (KTD5), and
   * there must be one answer per offered application of the session's
   * invitation.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $inv Invitation, validated live
   */

  protected function submitResponse($inv) {
    $coId = (int)$inv['co_id'];
    $data = isset($this->request->data['AteResponse']) ? (array)$this->request->data['AteResponse'] : array();
    $stored = $this->Session->read(self::SessionSnapshot);
    $username = $this->Session->read('Auth.User.username');
    $nonce = isset($data['nonce']) ? $data['nonce'] : null;

    if(empty($stored['snapshot']['identifier'])
       || empty($stored['nonce'])
       || (int)$stored['invitation_id'] !== (int)$inv['id']
       || !is_string($nonce)
       || !hash_equals($stored['nonce'], $nonce)
       || !is_string($username)
       || $username !== $stored['snapshot']['identifier']) {
      // Show the page again, built for the current login
      $this->Flash->set(_txt('pl.applicationteamenroller.er.response.stale'), array('key' => 'error'));
      $this->redirect(array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_responses',
        'action'     => 'respond'
      ));
      return;
    }

    $snapshot = $stored['snapshot'];
    $choices = $this->parseChoices($inv, isset($data['choices']) ? $data['choices'] : null);

    if($choices === null) {
      $this->Flash->set(_txt('pl.applicationteamenroller.er.response.choices'), array('key' => 'error'));
      $this->showForm($inv, $stored['nonce']);
      return;
    }

    try {
      // A newcomer continuing an interrupted enrollment is recognized by the
      // invitation's bound petition, not by the login mapping (KTD11): its
      // enrollee may already carry this login. An unfinished petition goes
      // back through the flow; a finalized one (the flow completed but the
      // response did not commit) commits against its enrollee.
      $continuing = $this->AteEnrollmentRequest->boundNewcomer($coId, $inv['id'], $username);

      if($continuing !== null
         && $continuing['status'] !== PetitionStatusEnum::Finalized
         && in_array(true, $choices, true)) {
        $this->continueAsNewcomer($coId, $inv, $snapshot, $choices);
        return;
      }

      // KTD6: a login already linked to a CoPerson in the CO is an existing member
      $responder = ($continuing !== null)
                   ? $continuing['co_person_id']
                   : $this->AteEnrollmentRequest->existingMemberCoPersonId($coId, $username);

      if(!$responder && in_array(true, $choices, true)) {
        $eval = $this->AteEnrollmentRequest->evaluateIdentity($coId, $inv['invited_email'], $snapshot);

        if($eval['result'] !== AteIdentityResultEnum::LinkRequired) {
          // A newcomer who accepts anything goes through the enrollment flow (R21)
          $this->continueAsNewcomer($coId, $inv, $snapshot, $choices);
          return;
        }
      }

      // An existing member, a newcomer who declined everything, or a login
      // that approval would link to an existing person (no CoPerson created)
      $result = $this->AteEnrollmentRequest->commitResponse($coId, $inv['id'], $snapshot, $choices, $responder);
    }
    catch(InvalidArgumentException $e) {
      $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
      $this->showForm($inv, $stored['nonce']);
      return;
    }
    catch(RuntimeException $e) {
      $this->log('ApplicationTeamEnroller response to invitation ' . $inv['id'] . ' by ' . $username
                 . ' failed: ' . $e->getMessage());
      $this->explain(($e->getMessage() === _txt('pl.applicationteamenroller.er.login.ambiguous'))
                     ? 'ambiguous' : 'error');
      return;
    }

    if(empty($result['handled'])) {
      // Another submit, a revocation, or expiry got there first (KTD8)
      $this->explainAlreadyHandled($inv);
      return;
    }

    $this->finishResponse($coId, $result);
  }

  /**
   * After a committed response: announce it (U10), which never undoes it,
   * and show the confirmation page.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId   CO ID
   * @param  Array   $result AteEnrollmentRequest::commitResponse() result
   */

  protected function finishResponse($coId, $result) {
    try {
      $this->AteEnrollmentRequest->afterResponse($coId, $result);
    }
    catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not announce the response to invitation '
                 . $result['invitation_id'] . ': ' . $e->getMessage());
    }

    $this->Session->delete(self::SessionSnapshot);
    $this->Session->write(self::SessionConfirmation, array(
      'co_id'         => (int)$coId,
      'invitation_id' => (int)$result['invitation_id']
    ));

    $this->redirect(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_responses',
      'action'     => 'confirmation'
    ));
  }

  /**
   * A newcomer accepted at least one application (R21, KTD11): save the
   * choices as a draft on the requests, bind the session to the invitation
   * and the login, and hand over to the newcomer enrollment flow. Nothing is
   * committed, so the invitation stays sent and the link can be used again
   * until the flow completes.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId     CO ID
   * @param  Array   $inv      Invitation, validated live
   * @param  Array   $snapshot Identity snapshot of the current login
   * @param  Array   $choices  True (accept) or false (decline), keyed by AteEnrollmentRequest ID
   */

  protected function continueAsNewcomer($coId, $inv, $snapshot, $choices) {
    if(!$this->AteEnrollmentRequest->saveDraft($coId, $inv['id'], $choices)) {
      $this->explainAlreadyHandled($inv);
      return;
    }

    $this->Session->write(self::SessionNewcomer, array(
      'co_id'         => (int)$coId,
      'invitation_id' => (int)$inv['id'],
      'identifier'    => $snapshot['identifier'],
      'snapshot'      => $snapshot
    ));

    $this->redirectToNewcomerFlow($coId, $inv['id']);
  }

  /**
   * Send a newcomer into the CO's newcomer enrollment flow (KTD11): the
   * start of AteSetting.newcomer_co_enrollment_flow_id, whose wedge checks
   * the SessionNewcomer binding and the draft. If the CO has no usable
   * newcomer flow, the draft stays saved, the invitation stays sent, and the
   * researcher is told so.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId         CO ID
   * @param  Integer $invitationId AteInvitation ID
   */

  protected function redirectToNewcomerFlow($coId, $invitationId) {
    $settings = $this->AteSetting->getOrCreateForCo($coId);
    $flowId = $settings['AteSetting']['newcomer_co_enrollment_flow_id'];
    $problem = empty($flowId) ? 'no newcomer enrollment flow is configured'
                              : $this->AteSetting->newcomerFlowProblem($coId, $flowId);

    if($problem !== null) {
      $this->log('ApplicationTeamEnroller cannot hand invitation ' . (int)$invitationId
                 . ' to the newcomer enrollment flow: ' . $problem . '; draft saved');
      $this->explain('newcomer_unavailable');
      return;
    }

    $this->redirect(array(
      'plugin'     => null,
      'controller' => 'co_petitions',
      'action'     => 'start',
      'coef'       => (int)$flowId
    ));
  }

  /**
   * The answer to each offered application of an invitation, from the
   * posted form: '1' accepts and '0' declines. Every offered application
   * needs an answer, and nothing else may be answered.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $inv    Invitation
   * @param  Mixed $posted Posted choices, keyed by AteEnrollmentRequest ID
   * @return Array         True or false keyed by AteEnrollmentRequest ID, or null if not acceptable
   */

  protected function parseChoices($inv, $posted) {
    if(!is_array($posted)) {
      return null;
    }

    $offered = array();

    foreach($this->requestsFor($inv['id'], true) as $r) {
      $offered[(int)$r['id']] = true;
    }

    $choices = array();

    foreach($posted as $reqId => $v) {
      if(!isset($offered[(int)$reqId]) || !in_array($v, array('0', '1'), true)) {
        return null;
      }

      $choices[(int)$reqId] = ($v === '1');
    }

    if(empty($offered) || count($choices) !== count($offered)) {
      return null;
    }

    return $choices;
  }

  /**
   * Show the respond form.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array  $inv   Invitation
   * @param  String $nonce Form nonce stored with the snapshot
   */

  protected function showForm($inv, $nonce) {
    $this->set('title_for_layout', _txt('pl.applicationteamenroller.response.title'));
    $this->set('vv_invitation', array(
      'invited_email' => $inv['invited_email'],
      'expires'       => $inv['expires']
    ));
    $this->set('vv_requests', $this->requestsFor($inv['id'], true));
    $this->set('vv_nonce', $nonce);
    $this->view = 'respond';
  }

  /**
   * The requests of an invitation with their application and team names.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $invitationId AteInvitation ID
   * @param  Boolean $offeredOnly  Only requests still offered
   * @return Array                 Each 'id', 'application', 'teams' (names), 'status', 'draft_choice'
   */

  protected function requestsFor($invitationId, $offeredOnly) {
    $args = array();
    $args['conditions']['AteEnrollmentRequest.ate_invitation_id'] = (int)$invitationId;
    if($offeredOnly) {
      $args['conditions']['AteEnrollmentRequest.status'] = AteRequestStatusEnum::Offered;
    }
    $args['order'] = array('AteEnrollmentRequest.id' => 'asc');
    $args['contain'] = array(
      'AteApplication',
      'AteEnrollmentRequestTeam' => array('AteResearchTeam')
    );

    $this->AteEnrollmentRequest->getDataSource()->flushQueryCache();

    $ret = array();

    foreach($this->AteEnrollmentRequest->find('all', $args) as $r) {
      $teams = array();

      foreach($r['AteEnrollmentRequestTeam'] as $t) {
        $teams[] = isset($t['AteResearchTeam']['name']) ? $t['AteResearchTeam']['name'] : '';
      }

      sort($teams);

      $ret[] = array(
        'id'           => (int)$r['AteEnrollmentRequest']['id'],
        'application'  => $r['AteApplication']['name'],
        'teams'        => $teams,
        'status'       => $r['AteEnrollmentRequest']['status'],
        'draft_choice' => $r['AteEnrollmentRequest']['draft_choice']
      );
    }

    return $ret;
  }

  /**
   * Why an invitation cannot be answered, after expiring it if it has
   * lapsed (KTD14). A sent invitation in the grace window of a bound
   * petition passes here; responseProblem() decides who may use it.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array  $inv Invitation, or null
   * @return String      Explanation reason, or null if it can be answered
   */

  protected function invitationProblem($inv) {
    if(empty($inv['id'])) {
      return 'unknown';
    }

    if($inv['status'] === AteInvitationStatusEnum::Sent
       && $this->AteInvitation->expireIfLapsed($inv['id'])) {
      return 'expired';
    }

    return AteInvitation::reasonForStatus($inv['status']);
  }

  /**
   * Why the current login cannot answer an invitation: invitationProblem(),
   * and past expiry, anyone but the bound newcomer (KTD11, KTD14). The grace
   * window keeps a sent invitation open only so the petition bound while it
   * was live can finish; for everyone else it has expired (R17). The link
   * itself (landing()) still leads here, where the login is known.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array  $inv        Invitation, or null
   * @param  String $identifier Current login, or null
   * @return String             Explanation reason, or null if it can be answered
   */

  protected function responseProblem($inv, $identifier) {
    $problem = $this->invitationProblem($inv);

    if($problem !== null) {
      return $problem;
    }

    if($inv['expires'] < date('Y-m-d H:i:s')
       && (!is_string($identifier) || $identifier === ''
           || $this->AteEnrollmentRequest->boundNewcomer((int)$inv['co_id'], $inv['id'], $identifier) === null)) {
      return 'expired';
    }

    return null;
  }

  /**
   * Show the explanation page for an invitation that another submit, a
   * revocation, or expiry has already moved on (KTD8), reading its current
   * status.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $inv Invitation, as validated before the write
   */

  protected function explainAlreadyHandled($inv) {
    $this->explain($this->responseProblem($this->AteInvitation->findByTokenHash($inv['token_hash']), null)
                   ?: 'answered');
  }

  /**
   * Show the explanation page.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $reason Reason, the last part of a response.explanation text key
   */

  protected function explain($reason) {
    $this->set('title_for_layout', _txt('pl.applicationteamenroller.response.explanation'));
    $this->set('vv_reason', $reason);
    $this->view = 'explanation';
  }
}
