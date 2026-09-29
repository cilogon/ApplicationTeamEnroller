<?php
/**
 * U3: the authorization matrix in
 * Controller/Component/AteAuthzComponent.php (KTD16, KTD17).
 *
 * Every answer comes from the $roles array plus real cm_co_group_members
 * rows, resolved through Registry's real RoleComponent, so the tests exercise
 * the membership chain rather than a mocked answer. The CO admin flag in
 * $roles is itself computed by RoleComponent::isCoAdmin() against a real CO
 * admin group, the way calculateCMRoles() computes it.
 *
 * Seeded world:
 *   A (approval required)  admin group gA {adminA}, approver group pA {approverA}
 *   B (approval required)  admin group gB {adminB}, approver group pB {approverB}
 *   C (approval off)       admin group gC {x, y},   approver group pC {approverC}
 *   Team T is authorized for both B and C; team TA for A.
 *   The CO admin group holds coAdmin and coAdmin2.
 *   stranger is a plain CO member; responder is an invitee's CoPerson.
 */

App::uses('Component', 'Controller');
App::uses('ComponentCollection', 'Controller');
App::uses('CakeSession', 'Model/Datasource');
App::uses('RoleComponent', 'Controller/Component');
App::uses('AteAuthzComponent', 'ApplicationTeamEnroller.Controller/Component');

class AteAuthzComponentTest extends AteTestCase {

  /** @var AteFixtures */
  private $fx = null;

  private $coId;
  private $otherCoId;
  private $p = array();     // person name => CoPerson ID
  private $g = array();     // group name => CoGroup ID
  private $app = array();   // application name => AteApplication ID
  private $team = array();  // team name => AteResearchTeam ID

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u3-authz');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    foreach(array('coAdmin', 'coAdmin2', 'adminA', 'adminB', 'approverA', 'approverB',
                  'approverC', 'x', 'y', 'stranger', 'responder', 'target') as $who) {
      $this->p[$who] = $this->fx->person($this->coId);
    }

    // The CO admin group is the one RoleComponent::isCoAdmin() looks for:
    // group_type 'A', no COU.
    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . $tag,
                                            array('group_type' => 'A'));
    $this->fx->member($this->g['coadmins'], $this->p['coAdmin']);
    $this->fx->member($this->g['coadmins'], $this->p['coAdmin2']);

    foreach(array('gA', 'pA', 'gB', 'pB', 'gC', 'pC', 'tA', 'tT') as $name) {
      $this->g[$name] = $this->fx->group($this->coId, $name . ' ' . $tag);
    }

    $this->fx->member($this->g['gA'], $this->p['adminA']);
    $this->fx->member($this->g['pA'], $this->p['approverA']);
    $this->fx->member($this->g['gB'], $this->p['adminB']);
    $this->fx->member($this->g['pB'], $this->p['approverB']);
    $this->fx->member($this->g['gC'], $this->p['x']);
    $this->fx->member($this->g['gC'], $this->p['y']);
    $this->fx->member($this->g['pC'], $this->p['approverC']);

    $this->app['A'] = $this->fx->application($this->coId, 'A ' . $tag, array(
      'admin_co_group_id' => $this->g['gA'],
      'approver_co_group_id' => $this->g['pA'],
      'approval_required' => true
    ));
    $this->app['B'] = $this->fx->application($this->coId, 'B ' . $tag, array(
      'admin_co_group_id' => $this->g['gB'],
      'approver_co_group_id' => $this->g['pB'],
      'approval_required' => true
    ));
    $this->app['C'] = $this->fx->application($this->coId, 'C ' . $tag, array(
      'admin_co_group_id' => $this->g['gC'],
      'approver_co_group_id' => $this->g['pC'],
      'approval_required' => false
    ));

    $this->team['TA'] = $this->fx->researchTeam($this->g['tA'], array('name' => 'TA'));
    $this->team['T'] = $this->fx->researchTeam($this->g['tT'], array('name' => 'T'));
    $this->fx->applicationTeam($this->app['A'], $this->team['TA']);
    $this->fx->applicationTeam($this->app['B'], $this->team['T']);
    $this->fx->applicationTeam($this->app['C'], $this->team['T']);
  }

  public function tearDown() {
    CakeSession::delete('Auth.User.username');

    if($this->fx === null) {
      return;
    }
    $this->fx->cleanup(array_merge($this->fx->pluginRowsFor($this->coId),
                                   $this->fx->pluginRowsFor($this->otherCoId)));
    $this->fx = null;
  }

  /**
   * A fresh component, and so a fresh RoleComponent membership cache, per
   * call, so one scenario cannot answer for another.
   */
  private function authz() {
    return new AteAuthzComponent(new ComponentCollection());
  }

  /**
   * The $roles array calculateCMRoles() would produce for $who in the test CO.
   * coadmin is computed by the real RoleComponent from group membership.
   * $who null means a login with no CoPerson in the CO.
   */
  private function roles($who, $overrides = array()) {
    $roles = array(
      'cmadmin' => false,
      'coadmin' => false,
      'comember' => false,
      'copersonid' => false
    );

    if($who !== null) {
      $role = new RoleComponent(new ComponentCollection());
      $roles['copersonid'] = $this->p[$who];
      $roles['comember'] = true;
      $roles['coadmin'] = $role->isCoAdmin($this->p[$who]);
    }

    return $overrides + $roles;
  }

  /** Seed an invitation sent by $inviter offering $apps. Returns its ID. */
  private function invitation($inviter, $overrides = array()) {
    return $this->fx->invitation($this->coId, $overrides + array(
      'inviter_co_person_id' => $this->p[$inviter]
    ));
  }

  /** Seed a pending request for $appName on $invitationId with $reason. */
  private function pending($invitationId, $appName, $reason) {
    return $this->fx->enrollmentRequest($invitationId, $this->app[$appName], array(
      'status' => AteRequestStatusEnum::PendingDecision,
      'pending_reason' => $reason
    ));
  }

  /** Invitation by $inviter, responded to by the responder CoPerson. */
  private function respondedInvitation($inviter, $overrides = array()) {
    return $this->invitation($inviter, $overrides + array(
      'status' => AteInvitationStatusEnum::Responded,
      'invitee_co_person_id' => $this->p['responder'],
      'responder_identifier' => 'responder-sub-' . uniqid(),
      'responder_identifier_type' => 'oidcsub'
    ));
  }

  /** Assert which named people may decide $requestId. */
  private function assertDeciders($requestId, $allowed, $denied, $msg) {
    foreach($allowed as $who) {
      $this->assertTrue($this->authz()->mayDecideRequest($this->roles($who), $this->coId, $requestId),
        "$who must be able to decide. $msg");
    }
    foreach($denied as $who) {
      $this->assertFalse($this->authz()->mayDecideRequest($this->roles($who), $this->coId, $requestId),
        "$who must not be able to decide. $msg");
    }
  }

  /** Configuration belongs to CO and platform admins only (A4). */
  public function testOnlyCoAndPlatformAdminsMayConfigure() {
    $this->assertTrue($this->authz()->mayConfigure($this->roles('coAdmin')), 'CO admin configures');
    $this->assertTrue($this->authz()->mayConfigure($this->roles(null, array('cmadmin' => true))),
      'platform admin configures');
    $this->assertFalse($this->authz()->mayConfigure($this->roles('adminA')), 'an application admin does not');
    $this->assertFalse($this->authz()->mayConfigure($this->roles('approverA')), 'an approver does not');
    $this->assertFalse($this->authz()->mayConfigure($this->roles('stranger')), 'a plain member does not');
  }

  /** AE1 (R11): an admin of A but not B may invite for A only. */
  public function testApplicationAdminMayInviteOnlyForAdministeredApplication() {
    $roles = $this->roles('adminA');

    $this->assertTrue($this->authz()->mayInvite($roles, $this->coId, $this->app['A']), 'admin of A invites for A');
    $this->assertFalse($this->authz()->mayInvite($roles, $this->coId, $this->app['B']), 'admin of A not for B');
    $this->assertEqual(array($this->app['A']), $this->authz()->invitableApplicationIds($roles, $this->coId),
      'only A is selectable for admin of A');
  }

  /** AE1 (R11): a CO admin may invite for every application. */
  public function testCoAdminMayInviteForEveryApplication() {
    $roles = $this->roles('coAdmin');

    foreach(array('A', 'B', 'C') as $name) {
      $this->assertTrue($this->authz()->mayInvite($roles, $this->coId, $this->app[$name]),
        "CO admin invites for $name");
    }

    $ids = $this->authz()->invitableApplicationIds($roles, $this->coId);
    sort($ids);
    $expected = array($this->app['A'], $this->app['B'], $this->app['C']);
    sort($expected);
    $this->assertEqual($expected, $ids, 'every application is selectable for a CO admin');
  }

  /** A retired application or one in another CO cannot be invited for. */
  public function testInviteDeniedForRetiredOrForeignApplication() {
    $retired = $this->fx->application($this->coId, 'retired', array(
      'admin_co_group_id' => $this->g['gA'],
      'status' => AteConfigStatusEnum::Retired
    ));
    $foreign = $this->fx->application($this->otherCoId, 'foreign', array(
      'admin_co_group_id' => $this->g['gA']
    ));

    $this->assertFalse($this->authz()->mayInvite($this->roles('adminA'), $this->coId, $retired),
      'a retired application is not offered');
    $this->assertFalse($this->authz()->mayInvite($this->roles('coAdmin'), $this->coId, $foreign),
      'an application of another CO is never invitable from this CO');
    $this->assertFalse(in_array($retired, $this->authz()->invitableApplicationIds($this->roles('coAdmin'), $this->coId)),
      'a retired application is not selectable');
  }

  /** AE13 (R24, R25): A's approvers decide A's approval request and not B's. */
  public function testApproverMayDecideOnlyOwnApplicationsApprovalRequests() {
    $inv = $this->respondedInvitation('adminA');
    $reqA = $this->pending($inv, 'A', AtePendingReasonEnum::Approval);
    $inv2 = $this->respondedInvitation('adminB');
    $reqB = $this->pending($inv2, 'B', AtePendingReasonEnum::Approval);

    $this->assertDeciders($reqA, array('approverA', 'coAdmin'),
      array('approverB', 'adminA', 'stranger'), 'approval request for A');
    $this->assertDeciders($reqB, array('approverB', 'coAdmin'),
      array('approverA', 'adminB'), 'approval request for B');
  }

  /**
   * AE7 (R24): a mismatch request on approval-off C is decided by the
   * inviting admin X (or a CO admin), not by another admin of C while X
   * remains in C's admin group, and not by C's approvers.
   */
  public function testMismatchRequestDecidedByInvitingAdminOnly() {
    $inv = $this->respondedInvitation('x');
    $req = $this->pending($inv, 'C', AtePendingReasonEnum::Mismatch);

    $this->assertDeciders($req, array('x', 'coAdmin'),
      array('y', 'approverC', 'adminA', 'stranger'), 'mismatch request on C');
  }

  /** R24: once X leaves C's admin group, any remaining admin of C decides. */
  public function testMismatchFallsBackToAdminGroupWhenInviterLeaves() {
    $inv = $this->respondedInvitation('x');
    $req = $this->pending($inv, 'C', AtePendingReasonEnum::Mismatch);

    $this->fx->query('DELETE FROM cm_co_group_members WHERE co_group_id = ' . $this->g['gC']
      . ' AND co_person_id = ' . $this->p['x']);

    $this->assertDeciders($req, array('y', 'coAdmin'),
      array('x', 'approverC'), 'mismatch request after the inviter left');
  }

  /**
   * R24: a CO admin who sent the invitation is its inviting admin, so the
   * mismatch request stays with them (and other CO admins), not with C's
   * admin group.
   */
  public function testMismatchSentByCoAdminStaysWithCoAdmins() {
    $inv = $this->respondedInvitation('coAdmin');
    $req = $this->pending($inv, 'C', AtePendingReasonEnum::Mismatch);

    $this->assertDeciders($req, array('coAdmin', 'coAdmin2'),
      array('x', 'y', 'approverC'), 'mismatch request sent by a CO admin');
  }

  /**
   * KTD7 + routing diagram: a mismatch on C whose team T is shared with
   * approval-required B is stored as `approval`, so C's approvers decide it
   * and the inviting admin X does not, even though C has approval off.
   */
  public function testStoredApprovalReasonGovernsEvenWhenApprovalIsOff() {
    $inv = $this->respondedInvitation('x', array('mismatch' => true));
    $req = $this->pending($inv, 'C', AtePendingReasonEnum::Approval);
    $this->fx->enrollmentRequestTeam($req, $this->team['T']);

    $this->assertDeciders($req, array('approverC', 'coAdmin'),
      array('x', 'y', 'approverB'), 'shared-team mismatch routed as approval');
  }

  /**
   * AE18 (R39): the responder's own CoPerson may not decide their request,
   * even as an approver of the application or as a CO admin.
   */
  public function testResponderMayNotDecideOwnRequest() {
    // Responder is an approver of A.
    $this->fx->member($this->g['pA'], $this->p['responder']);
    $inv = $this->respondedInvitation('adminA');
    $req = $this->pending($inv, 'A', AtePendingReasonEnum::Approval);
    $this->assertDeciders($req, array('approverA', 'coAdmin'), array('responder'),
      'responder in the approver group');

    // Responder is a CO admin.
    $inv2 = $this->respondedInvitation('adminA', array('invitee_co_person_id' => $this->p['coAdmin2']));
    $req2 = $this->pending($inv2, 'A', AtePendingReasonEnum::Approval);
    $this->assertDeciders($req2, array('approverA', 'coAdmin'), array('coAdmin2'),
      'responder is a CO admin');

    // Responder is the inviting admin of an approval-off application.
    $inv3 = $this->respondedInvitation('x', array('invitee_co_person_id' => $this->p['x']));
    $req3 = $this->pending($inv3, 'C', AtePendingReasonEnum::Mismatch);
    $this->assertDeciders($req3, array('coAdmin'), array('x', 'y'),
      'the inviting admin who responded with a login linked to their own CoPerson;'
      . ' removing X leaves only CO admins, it does not hand the request to C\'s admin group');
  }

  /**
   * AE18 (R39): a link_required request is decided only by CO admins, and
   * not by a CO admin who is the link target.
   */
  public function testLinkRequiredOnlyCoAdminsAndNeverTheLinkTarget() {
    $inv = $this->invitation('x', array(
      'status' => AteInvitationStatusEnum::Responded,
      'link_target_co_person_id' => $this->p['target'],
      'responder_identifier' => 'unlinked-sub',
      'responder_identifier_type' => 'oidcsub'
    ));
    $req = $this->pending($inv, 'C', AtePendingReasonEnum::LinkRequired);
    $this->assertDeciders($req, array('coAdmin', 'coAdmin2'),
      array('x', 'y', 'approverC', 'target', 'stranger'), 'link_required request');

    $inv2 = $this->invitation('adminA', array(
      'status' => AteInvitationStatusEnum::Responded,
      'link_target_co_person_id' => $this->p['coAdmin2']
    ));
    $req2 = $this->pending($inv2, 'A', AtePendingReasonEnum::LinkRequired);
    $this->assertDeciders($req2, array('coAdmin'), array('coAdmin2', 'approverA', 'adminA'),
      'link_required request whose link target is a CO admin');
  }

  /** Only a pending_decision request in this CO is decidable. */
  public function testNonPendingOrForeignRequestIsNotDecidable() {
    $inv = $this->respondedInvitation('adminA');
    $req = $this->fx->enrollmentRequest($inv, $this->app['A'], array(
      'status' => AteRequestStatusEnum::Approved,
      'pending_reason' => AtePendingReasonEnum::Approval
    ));
    $this->assertFalse($this->authz()->mayDecideRequest($this->roles('coAdmin'), $this->coId, $req),
      'an already approved request is not decidable');

    $foreignApp = $this->fx->application($this->otherCoId, 'foreign', array(
      'approver_co_group_id' => $this->g['pA']
    ));
    $foreignInv = $this->fx->invitation($this->otherCoId, array(
      'inviter_co_person_id' => $this->p['adminA']
    ));
    $foreignReq = $this->fx->enrollmentRequest($foreignInv, $foreignApp, array(
      'status' => AteRequestStatusEnum::PendingDecision,
      'pending_reason' => AtePendingReasonEnum::Approval
    ));
    $this->assertFalse($this->authz()->mayDecideRequest($this->roles('coAdmin'), $this->coId, $foreignReq),
      'a CO admin of this CO cannot decide a request of another CO');
    $this->assertFalse($this->authz()->mayDecideRequest($this->roles('approverA'), $this->coId, $foreignReq),
      'group membership cannot reach a request of another CO');
  }

  /** A decider needs a CoPerson in the CO, so R39 can be checked and recorded. */
  public function testPlatformAdminWithoutCoPersonMayNotDecide() {
    $inv = $this->respondedInvitation('adminA');
    $req = $this->pending($inv, 'A', AtePendingReasonEnum::Approval);

    $this->assertFalse($this->authz()->mayDecideRequest($this->roles(null, array('cmadmin' => true)),
      $this->coId, $req), 'a platform admin with no CoPerson in the CO cannot decide');
  }

  /** The role reported for a decision matches the eligibility rule used. */
  public function testDecidingRoleNamesTheRuleThatGrantedIt() {
    $inv = $this->respondedInvitation('x');
    $approval = $this->pending($inv, 'C', AtePendingReasonEnum::Approval);
    $mismatch = $this->pending($inv, 'C', AtePendingReasonEnum::Mismatch);

    $this->assertEqual(AteDecidedByRoleEnum::Approver,
      $this->authz()->decidingRole($this->roles('approverC'), $this->coId, $approval));
    $this->assertEqual(AteDecidedByRoleEnum::InvitingAdmin,
      $this->authz()->decidingRole($this->roles('x'), $this->coId, $mismatch));
    $this->assertEqual(AteDecidedByRoleEnum::CoAdmin,
      $this->authz()->decidingRole($this->roles('coAdmin'), $this->coId, $mismatch));
    $this->assertNull($this->authz()->decidingRole($this->roles('y'), $this->coId, $mismatch));
  }

  /**
   * R25, KTD17: the decidable set lists only what the user may decide, and
   * hides link_required requests from everyone but CO admins.
   */
  public function testDecidableRequestIdsMatchesEligibility() {
    $invA = $this->respondedInvitation('adminA');
    $reqA = $this->pending($invA, 'A', AtePendingReasonEnum::Approval);
    $invB = $this->respondedInvitation('adminB');
    $reqB = $this->pending($invB, 'B', AtePendingReasonEnum::Approval);
    $invC = $this->respondedInvitation('x');
    $reqC = $this->pending($invC, 'C', AtePendingReasonEnum::Mismatch);
    $invL = $this->invitation('adminA', array(
      'status' => AteInvitationStatusEnum::Responded,
      'link_target_co_person_id' => $this->p['coAdmin2']
    ));
    $reqL = $this->pending($invL, 'A', AtePendingReasonEnum::LinkRequired);
    $this->fx->enrollmentRequest($invA, $this->app['B'], array('status' => AteRequestStatusEnum::Denied));

    $this->assertEqual(array($reqA), $this->authz()->decidableRequestIds($this->roles('approverA'), $this->coId),
      'A approver sees only the approval request for A');
    $this->assertEqual(array($reqC), $this->authz()->decidableRequestIds($this->roles('x'), $this->coId),
      'X sees only their mismatch request');
    $this->assertEqual(array(), $this->authz()->decidableRequestIds($this->roles('y'), $this->coId),
      'another admin of C sees nothing while X is in the group');
    $this->assertEqual(array($reqA, $reqB, $reqC, $reqL),
      $this->authz()->decidableRequestIds($this->roles('coAdmin'), $this->coId),
      'a CO admin sees every pending request');
    $this->assertEqual(array($reqA, $reqB, $reqC),
      $this->authz()->decidableRequestIds($this->roles('coAdmin2'), $this->coId),
      'a CO admin who is the link target does not see that request');
    $this->assertEqual(array(), $this->authz()->decidableRequestIds($this->roles('stranger'), $this->coId));
  }

  /** R34: inviter, admin of an included application, or CO admin may view. */
  public function testViewInvitation() {
    // X of C invites for both A and C (X is not an admin of A).
    $inv = $this->invitation('x');
    $this->fx->enrollmentRequest($inv, $this->app['A']);
    $this->fx->enrollmentRequest($inv, $this->app['C']);

    foreach(array('x', 'y', 'adminA', 'coAdmin') as $who) {
      $this->assertTrue($this->authz()->mayViewInvitation($this->roles($who), $this->coId, $inv),
        "$who may view the invitation");
    }
    foreach(array('adminB', 'approverA', 'approverC', 'stranger', 'responder') as $who) {
      $this->assertFalse($this->authz()->mayViewInvitation($this->roles($who), $this->coId, $inv),
        "$who may not view the invitation");
    }

    $foreign = $this->fx->invitation($this->otherCoId, array('inviter_co_person_id' => $this->p['x']));
    $this->assertFalse($this->authz()->mayViewInvitation($this->roles('x'), $this->coId, $foreign),
      'an invitation of another CO is not viewable from this CO');
  }

  /** R18, AE17: the inviting admin or a CO admin may revoke and withdraw. */
  public function testRevokeAndWithdraw() {
    $inv = $this->respondedInvitation('x');
    $req = $this->pending($inv, 'C', AtePendingReasonEnum::Mismatch);

    foreach(array('x', 'coAdmin') as $who) {
      $this->assertTrue($this->authz()->mayRevokeInvitation($this->roles($who), $this->coId, $inv),
        "$who may revoke");
      $this->assertTrue($this->authz()->mayWithdrawRequest($this->roles($who), $this->coId, $req),
        "$who may withdraw");
    }
    foreach(array('y', 'approverC', 'stranger', 'responder') as $who) {
      $this->assertFalse($this->authz()->mayRevokeInvitation($this->roles($who), $this->coId, $inv),
        "$who may not revoke");
      $this->assertFalse($this->authz()->mayWithdrawRequest($this->roles($who), $this->coId, $req),
        "$who may not withdraw");
    }
  }

  /**
   * KTD16: a login with no CoPerson in the CO may respond, but may not view
   * the queue or the invitations.
   */
  public function testLoginWithoutCoPersonMayRespondOnly() {
    CakeSession::write('Auth.User.username', 'first-time-user@example.org');
    $roles = $this->roles(null);

    $this->assertTrue($this->authz()->mayRespond(), 'an authenticated login may respond');
    $this->assertFalse($this->authz()->mayViewQueue($roles, $this->coId), 'no queue without a CoPerson');
    $this->assertFalse($this->authz()->mayListInvitations($roles, $this->coId), 'no invitation list');
    $this->assertFalse($this->authz()->mayInvite($roles, $this->coId, $this->app['A']), 'no invite');
  }

  /** KTD16: responding requires an authenticated login. */
  public function testRespondRequiresLogin() {
    CakeSession::delete('Auth.User.username');

    $this->assertFalse($this->authz()->mayRespond(), 'no login, no response');
  }

  /** A plain CO member may not invite, view invitations, or view the queue. */
  public function testPlainMemberHasNoAccess() {
    $roles = $this->roles('stranger');
    $inv = $this->invitation('adminA');
    $this->fx->enrollmentRequest($inv, $this->app['A']);

    $this->assertEqual(array(), $this->authz()->invitableApplicationIds($roles, $this->coId));
    foreach(array('A', 'B', 'C') as $name) {
      $this->assertFalse($this->authz()->mayInvite($roles, $this->coId, $this->app[$name]), "no invite for $name");
    }
    $this->assertFalse($this->authz()->mayListInvitations($roles, $this->coId), 'no invitation list');
    $this->assertFalse($this->authz()->mayViewInvitation($roles, $this->coId, $inv), 'no invitation view');
    $this->assertFalse($this->authz()->mayViewQueue($roles, $this->coId), 'no queue');
  }

  /**
   * R25, R34: admins and approvers of some application see the queue; only
   * admins (and CO admins) see the invitation list.
   */
  public function testQueueAndInvitationListGates() {
    foreach(array('adminA', 'approverA', 'x', 'coAdmin') as $who) {
      $this->assertTrue($this->authz()->mayViewQueue($this->roles($who), $this->coId), "$who sees the queue");
    }
    $this->assertTrue($this->authz()->mayViewQueue($this->roles(null, array('cmadmin' => true)), $this->coId),
      'a platform admin sees the queue');

    foreach(array('adminA', 'x', 'coAdmin') as $who) {
      $this->assertTrue($this->authz()->mayListInvitations($this->roles($who), $this->coId),
        "$who lists invitations");
    }
    $this->assertFalse($this->authz()->mayListInvitations($this->roles('approverA'), $this->coId),
      'an approver who administers nothing does not list invitations (R34)');

    $this->assertEqual(array($this->app['C']), $this->authz()->administeredApplicationIds($this->roles('x'), $this->coId),
      'X administers C only');
  }
}
