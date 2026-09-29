<?php
/**
 * COmanage Registry Application Team Enroller Enrollment Requests Controller
 *
 * The decision queue (F3, R25): deciders see the pending requests they may
 * decide and approve or deny each, with an optional comment. Who may decide
 * comes from AteAuthzComponent (R24, R39, KTD7, KTD17), and the decision
 * itself from the U7 engine, which records the deciding role (R8). After a
 * decision the engine's announcement hook resolves the decider notification,
 * emails the researcher, and notifies the inviting admin (R35-R37).
 *
 * The queue is linked from the CO main menu, which Registry shows to every
 * CO member, so every action is authorized here (KTD16), and approve and deny
 * re-check eligibility themselves.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("StandardController", "Controller");

class AteEnrollmentRequestsController extends StandardController {
  // Class name, used by Cake
  public $name = "AteEnrollmentRequests";

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // This controller needs a CO to be set
  public $requires_co = true;

  /**
   * Approve a pending request (F3, R26).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteEnrollmentRequest ID
   */

  public function approve($id) {
    $this->decide('approve', $id);
  }

  /**
   * Determine the CO for an action. view, approve, and deny take a request
   * ID, whose CO is its invitation's.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $data Array of data for calculating implied CO ID
   * @return Integer       The CO ID if found, or null
   * @throws InvalidArgumentException If the request does not exist
   */

  protected function calculateImpliedCoId($data = null) {
    if(in_array($this->action, array('view', 'approve', 'deny'), true)
       && !empty($this->request->params['pass'][0])) {
      $args = array();
      $args['conditions']['AteEnrollmentRequest.id'] = $this->request->params['pass'][0];
      $args['contain'] = array('AteInvitation');

      $req = $this->AteEnrollmentRequest->find('first', $args);

      if(empty($req['AteInvitation']['co_id'])) {
        throw new InvalidArgumentException(_txt('er.notfound',
                                                array(_txt('ct.ate_enrollment_requests.1'),
                                                      filter_var($this->request->params['pass'][0], FILTER_SANITIZE_SPECIAL_CHARS))));
      }

      return $req['AteInvitation']['co_id'];
    }

    return parent::calculateImpliedCoId($data);
  }

  /**
   * Deny a pending request (F3, R28).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteEnrollmentRequest ID
   */

  public function deny($id) {
    $this->decide('deny', $id);
  }

  /**
   * The decision queue (R25): the pending requests of the CO the user may
   * decide, and nothing else.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function index() {
    $coId = $this->cur_co['Co']['id'];
    $roles = $this->Role->calculateCMRoles();

    $decidingRoles = array();

    foreach($this->AteAuthz->decidableRequestIds($roles, $coId) as $reqId) {
      $decidingRoles[$reqId] = $this->AteAuthz->decidingRole($roles, $coId, $reqId);
    }

    $this->set('title_for_layout', _txt('pl.applicationteamenroller.queue'));
    $this->set('vv_requests', $this->AteEnrollmentRequest->queueEntries($coId, array_keys($decidingRoles),
                                                                        $decidingRoles));
    $this->setDisplayNames();
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry v4.6.0
   * @return Boolean True if the current action is permitted
   */

  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();
    $coId = $this->cur_co['Co']['id'];
    $id = !empty($this->request->params['pass'][0]) ? (int)$this->request->params['pass'][0] : null;

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();

    // See the decision queue (R25)? The queue shows only decidable requests.
    $p['index'] = $this->AteAuthz->mayViewQueue($roles, $coId);

    // Open one request from the queue or a notification? The action returns
    // to the queue if the user may not decide it.
    $p['view'] = $id && $p['index'];

    // Approve or deny a request (R24, R39)?
    $p['approve'] = $id && $this->AteAuthz->mayDecideRequest($roles, $coId, $id);
    $p['deny'] = $p['approve'];

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }

  /**
   * One request with its decision forms: the target of the decider
   * notification (KTD12). A request the user may not decide, including one
   * already decided, returns to the queue with an explanation.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteEnrollmentRequest ID
   */

  public function view($id) {
    $coId = $this->cur_co['Co']['id'];
    $roles = $this->Role->calculateCMRoles();
    $role = $this->AteAuthz->decidingRole($roles, $coId, $id);
    $entries = ($role !== null)
               ? $this->AteEnrollmentRequest->queueEntries($coId, array($id), array((int)$id => $role))
               : array();

    if(empty($entries)) {
      $this->Flash->set(_txt('pl.applicationteamenroller.er.request.not_decidable'), array('key' => 'error'));
      $this->redirect($this->queueUrl());
    }

    $this->set('title_for_layout', _txt('pl.applicationteamenroller.queue.request'));
    $this->set('vv_request', $entries[0]);
    $this->setDisplayNames();
  }

  /**
   * Carry out a decision. Eligibility is checked again here, not only in
   * isAuthorized(), and the deciding role recorded is the one the
   * authorization component computes (R8, R39). A request no longer pending,
   * or one the user may not decide, is left as it is.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String  $decision 'approve' or 'deny'
   * @param  Integer $id       AteEnrollmentRequest ID
   */

  protected function decide($decision, $id) {
    $this->request->allowMethod('post');

    $coId = $this->cur_co['Co']['id'];
    $roles = $this->Role->calculateCMRoles();
    $role = $this->AteAuthz->decidingRole($roles, $coId, $id);

    if($role === null) {
      $this->Flash->set(_txt('pl.applicationteamenroller.er.request.not_decidable'), array('key' => 'error'));
      $this->redirect($this->queueUrl());
    }

    $actor = (int)$roles['copersonid'];
    $comment = $this->request->data['AteEnrollmentRequest']['comment'] ?? null;

    try {
      $result = $this->AteEnrollmentRequest->$decision($coId, $id, $actor, $role,
                                                       is_string($comment) ? $comment : null);

      if($result['handled']) {
        $this->AteEnrollmentRequest->afterDecision($coId, $result, $actor);
        $this->Flash->set(_txt('pl.applicationteamenroller.rs.request.' . ($decision === 'approve' ? 'approved' : 'denied')),
                          array('key' => 'success'));
      } else {
        $this->Flash->set(_txt('pl.applicationteamenroller.er.request.handled'), array('key' => 'error'));
      }
    }
    catch(InvalidArgumentException $e) {
      $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
    }
    catch(RuntimeException $e) {
      $this->log($e->getMessage());
      $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
    }

    $this->redirect($this->queueUrl());
  }

  /**
   * The decision queue's URL.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array
   */

  protected function queueUrl() {
    return array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_enrollment_requests',
      'action'     => 'index',
      'co'         => $this->cur_co['Co']['id']
    );
  }

  /**
   * Set the display names the queue views use.
   *
   * @since  COmanage Registry v4.6.0
   */

  protected function setDisplayNames() {
    $reasons = array();

    foreach(AtePendingReasonEnum::$values as $v) {
      $reasons[$v] = _txt('pl.applicationteamenroller.en.pending_reason.' . $v);
    }

    $roles = array();

    foreach(AteDecidedByRoleEnum::$values as $v) {
      $roles[$v] = _txt('pl.applicationteamenroller.en.decided_by_role.' . $v);
    }

    $this->set('vv_pending_reasons', $reasons);
    $this->set('vv_deciding_roles', $roles);
  }
}
