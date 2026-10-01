<?php
/**
 * COmanage Registry Application Team Enroller Invitations Controller
 *
 * Application administrators compose invitations, list and view them, revoke
 * an invitation, and withdraw a pending request (F1, R9-R18, R34). The
 * screens are linked from the CO main menu, which Registry shows to every CO
 * member, so every action is authorized here through AteAuthzComponent
 * (KTD16). The invitation model re-checks every submitted application and
 * team on the server.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("StandardController", "Controller");

class AteInvitationsController extends StandardController {
  // Class name, used by Cake
  public $name = "AteInvitations";

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // Establish pagination parameters for HTML views: newest first, with each
  // invitation's requests and their applications (R34)
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'AteInvitation.id' => 'desc'
    ),
    'contain' => array(
      'InviterCoPerson' => array('PrimaryName'),
      'AteEnrollmentRequest' => array('AteApplication')
    )
  );

  // This controller needs a CO to be set
  public $requires_co = true;

  /**
   * Compose and send an invitation (F1). The form offers only the
   * applications the user may invite for and, for each, only its active
   * mapped research teams (AE1); the model re-checks the submission.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function add() {
    $coId = $this->cur_co['Co']['id'];
    $roles = $this->Role->calculateCMRoles();
    $invitable = $this->AteAuthz->invitableApplicationIds($roles, $coId);

    if($this->request->is('post')) {
      $data = isset($this->request->data['AteInvitation']) ? $this->request->data['AteInvitation'] : array();
      $email = isset($data['invited_email']) ? trim((string)$data['invited_email']) : '';

      // application_ids holds one checkbox per application (1 or 0), and
      // teams one multiple-checkbox list per application. Only the teams
      // under a checked application count. The model re-checks both.
      $selections = array();

      foreach((array)($data['application_ids'] ?? array()) as $appId => $checked) {
        if(empty($checked)) {
          continue;
        }

        $teams = isset($data['teams'][$appId]) ? (array)$data['teams'][$appId] : array();

        // A multiple checkbox list posts an empty hidden value when nothing is ticked
        $selections[(int)$appId] = array_values(array_filter($teams, function($t) { return $t !== ''; }));
      }

      try {
        $id = $this->AteInvitation->createAndSend($coId, $roles['copersonid'], $email, $selections, $invitable);

        $this->Flash->set(_txt('pl.applicationteamenroller.rs.invitation.sent',
                               array(filter_var($email, FILTER_SANITIZE_SPECIAL_CHARS))),
                          array('key' => 'success'));
        $this->redirect(array(
          'plugin'     => 'application_team_enroller',
          'controller' => 'ate_invitations',
          'action'     => 'view',
          $id
        ));
      }
      catch(InvalidArgumentException $e) {
        $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
      }
      catch(RuntimeException $e) {
        $this->log($e->getMessage());
        $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
      }
    }

    $this->set('title_for_layout', _txt('pl.applicationteamenroller.invitation.compose'));
    $this->set('vv_applications', $this->AteInvitation->composeOptions($coId, $invitable));
  }

  /**
   * Determine the CO for an action. withdraw() takes a request ID, whose CO
   * is its invitation's.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $data Array of data for calculating implied CO ID
   * @return Integer       The CO ID if found, or null
   * @throws InvalidArgumentException If the request does not exist
   */

  protected function calculateImpliedCoId($data = null) {
    if($this->action == 'withdraw' && !empty($this->request->params['pass'][0])) {
      $args = array();
      $args['conditions']['AteEnrollmentRequest.id'] = $this->request->params['pass'][0];
      $args['contain'] = array('AteInvitation');

      $req = $this->AteInvitation->AteEnrollmentRequest->find('first', $args);

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
   * List invitations (R34): every invitation of the CO for a CO
   * administrator; otherwise those that include an application the user
   * administers, and those the user sent.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function index() {
    $coId = $this->cur_co['Co']['id'];
    $roles = $this->Role->calculateCMRoles();

    $scope = array('AteInvitation.co_id' => $coId);

    if(!$this->AteAuthz->mayConfigure($roles)) {
      $or = array();

      if(!empty($roles['copersonid'])) {
        $or['AteInvitation.inviter_co_person_id'] = (int)$roles['copersonid'];
      }

      $appIds = $this->AteAuthz->administeredApplicationIds($roles, $coId);

      if(!empty($appIds)) {
        $Request = $this->AteInvitation->AteEnrollmentRequest;

        $or[] = 'AteInvitation.id IN (SELECT ate_invitation_id FROM '
                . $Request->tablePrefix . $Request->table
                . ' WHERE ate_application_id IN (' . implode(',', array_map('intval', $appIds)) . '))';
      }

      // isAuthorized() admits only users with something to see, but never
      // list the whole CO if that changes.
      $scope['OR'] = empty($or) ? array('AteInvitation.id' => null) : $or;
    }

    $this->set('title_for_layout', _txt('ct.ate_invitations.pl'));
    $this->set('ate_invitations', $this->paginate('AteInvitation', $scope));
    $this->set('vv_invitation_status', $this->invitationStatusNames());
    $this->set('vv_request_status', $this->requestStatusNames());
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

    // Compose an invitation? Only for users with an application to offer (R11).
    $p['add'] = !empty($this->AteAuthz->invitableApplicationIds($roles, $coId));

    // List invitations (R34)?
    $p['index'] = $this->AteAuthz->mayListInvitations($roles, $coId);

    // View an invitation and its requests (R34)?
    $p['view'] = $id && $this->AteAuthz->mayViewInvitation($roles, $coId, $id);

    // Revoke an invitation (R18)?
    $p['revoke'] = $id && $this->AteAuthz->mayRevokeInvitation($roles, $coId, $id);

    // Withdraw a pending request (R18)? The ID is a request's.
    $p['withdraw'] = $id && $this->AteAuthz->mayWithdrawRequest($roles, $coId, $id);

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }

  /**
   * Revoke a sent invitation (R18, AE12). A second revoke, or one after the
   * researcher responded or the invitation expired, reports that it was
   * already handled.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteInvitation ID
   */

  public function revoke($id) {
    $this->request->allowMethod('post');

    $roles = $this->Role->calculateCMRoles();

    try {
      if($this->AteInvitation->revoke($this->cur_co['Co']['id'], $id, $roles['copersonid'])) {
        $this->Flash->set(_txt('pl.applicationteamenroller.rs.invitation.revoked'), array('key' => 'success'));
      } else {
        $this->Flash->set(_txt('pl.applicationteamenroller.er.invitation.handled'), array('key' => 'error'));
      }
    }
    catch(RuntimeException $e) {
      $this->log($e->getMessage());
      $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
    }

    $this->redirect(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_invitations',
      'action'     => 'view',
      $id
    ));
  }

  /**
   * View an invitation with each request's status and teams (R34).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteInvitation ID
   */

  public function view($id) {
    $coId = $this->cur_co['Co']['id'];
    $roles = $this->Role->calculateCMRoles();

    $args = array();
    $args['conditions']['AteInvitation.id'] = $id;
    $args['conditions']['AteInvitation.co_id'] = $coId;
    $args['contain'] = array(
      'InviterCoPerson' => array('PrimaryName'),
      'RevokedByCoPerson' => array('PrimaryName'),
      'AteEnrollmentRequest' => array(
        'order' => array('AteEnrollmentRequest.id' => 'asc'),
        'AteApplication',
        'AteEnrollmentRequestTeam' => array(
          'order' => array('AteEnrollmentRequestTeam.id' => 'asc'),
          'AteResearchTeam'
        )
      )
    );

    $inv = $this->AteInvitation->find('first', $args);

    if(empty($inv)) {
      $this->Flash->set(_txt('er.notfound', array(_txt('ct.ate_invitations.1'), filter_var($id, FILTER_SANITIZE_SPECIAL_CHARS))),
                        array('key' => 'error'));
      $this->redirect(array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_invitations',
        'action'     => 'index',
        'co'         => $coId
      ));
    }

    $withdrawable = array();

    foreach($inv['AteEnrollmentRequest'] as $r) {
      if($r['status'] === AteRequestStatusEnum::PendingDecision
         && $this->AteAuthz->mayWithdrawRequest($roles, $coId, $r['id'])) {
        $withdrawable[] = (int)$r['id'];
      }
    }

    // pageTitleAndButtons escapes the title
    $this->set('title_for_layout', _txt('ct.ate_invitations.1') . ': ' . $inv['AteInvitation']['invited_email']);
    $this->set('vv_invitation', $inv);
    $this->set('vv_may_revoke', $inv['AteInvitation']['status'] === AteInvitationStatusEnum::Sent
                                && $this->AteAuthz->mayRevokeInvitation($roles, $coId, $id));
    $this->set('vv_withdrawable', $withdrawable);
    $this->set('vv_invitation_status', $this->invitationStatusNames());
    $this->set('vv_request_status', $this->requestStatusNames());
  }

  /**
   * Withdraw one pending_decision request (R18, AE17): it becomes revoked.
   * A request no longer pending reports that it was already handled.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteEnrollmentRequest ID
   */

  public function withdraw($id) {
    $this->request->allowMethod('post');

    $roles = $this->Role->calculateCMRoles();
    $invitationId = $this->AteInvitation->AteEnrollmentRequest->field('ate_invitation_id',
                                                                      array('AteEnrollmentRequest.id' => $id));

    try {
      if($this->AteInvitation->withdrawRequest($this->cur_co['Co']['id'], $id, $roles['copersonid'])) {
        $this->Flash->set(_txt('pl.applicationteamenroller.rs.request.withdrawn'), array('key' => 'success'));
      } else {
        $this->Flash->set(_txt('pl.applicationteamenroller.er.request.handled'), array('key' => 'error'));
      }
    }
    catch(RuntimeException $e) {
      $this->log($e->getMessage());
      $this->Flash->set(filter_var($e->getMessage(), FILTER_SANITIZE_SPECIAL_CHARS), array('key' => 'error'));
    }

    $this->redirect(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_invitations',
      'action'     => 'view',
      $invitationId
    ));
  }

  /**
   * Display names of invitation statuses.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Names, keyed by AteInvitationStatusEnum value
   */

  protected function invitationStatusNames() {
    $ret = array();

    foreach(AteInvitationStatusEnum::$values as $v) {
      $ret[$v] = _txt('pl.applicationteamenroller.en.invitation_status.' . $v);
    }

    return $ret;
  }

  /**
   * Display names of request statuses.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Names, keyed by AteRequestStatusEnum value
   */

  protected function requestStatusNames() {
    $ret = array();

    foreach(AteRequestStatusEnum::$values as $v) {
      $ret[$v] = _txt('pl.applicationteamenroller.en.request_status.' . $v);
    }

    return $ret;
  }
}
