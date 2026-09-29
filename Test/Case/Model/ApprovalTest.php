<?php
/**
 * U7: approve, deny, and withdraw (R18, R21, R26, R28, R29, R30, R39, AE3,
 * AE4, AE10, AE11, AE16, AE17, KTD8, KTD9).
 *
 * Memberships are checked against real cm_co_group_members rows: direct rows
 * (no co_group_nesting_id) are the ones approval writes.
 */

class ApprovalTest extends AteEngineTestCase {

  /**
   * A responded invitation with one pending request for $appKey offering
   * $teamKeys. The responder is P1 unless $invOverrides says otherwise.
   *
   * @return Array 'id' (invitation) and 'req' (request ID)
   */
  private function pending($appKey, $teamKeys, $reason = 'approval', $invOverrides = array()) {
    $inv = $this->invitation(self::Invited, array($appKey => $teamKeys), $invOverrides + array(
      'status' => 'responded',
      'invitee_co_person_id' => $this->p['p1'],
      'responder_identifier' => $this->sub('p1'),
      'responder_identifier_type' => 'oidcsub'
    ));

    $this->fx->query('UPDATE cm_ate_enrollment_requests SET status = \'pending_decision\', pending_reason = '
                     . "'" . $reason . "' WHERE id = " . (int)$inv['req'][$appKey]);

    return array('id' => $inv['id'], 'req' => $inv['req'][$appKey]);
  }

  /** Approve as A's approver. */
  private function approveAsApprover($requestId, $comment = null) {
    return $this->Req->approve($this->coId, $requestId, $this->p['approverA'], 'approver', $comment);
  }

  /**
   * Covers AE4. An existing member with no T1 membership is approved: exactly
   * one direct row (member, not owner), the history record core writes, and
   * the decision recorded.
   */
  public function testApproveAddsOneDirectMembership() {
    $req = $this->pending('A', array('t1'));
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])), 'no membership before approval');

    $res = $this->approveAsApprover($req['req'], 'looks good');

    $this->assertTrue($res['handled']);
    $this->assertEqual('approved', $res['status']);
    $this->assertEqual($this->p['p1'], $res['co_person_id']);
    $this->assertEqual(array($this->team['t1'] => 'added'), $res['teams']);

    $rows = $this->directRows('t1', $this->p['p1']);
    $this->assertEqual(1, count($rows));
    $this->assertTrue((bool)$rows[0]['member']);
    $this->assertFalse((bool)$rows[0]['owner']);

    $r = $this->requestRow($req['req']);
    $this->assertEqual('approved', $r['status']);
    $this->assertEqual('approver', $r['decided_by_role']);
    $this->assertEqual($this->p['approverA'], (int)$r['decider_co_person_id']);
    $this->assertEqual('looks good', $r['comment']);
    $this->assertNotEmpty($r['decided_at']);
    $this->assertEqual('approval', $r['pending_reason'], 'the reason stays for audit');
    $this->assertEqual(array('t1' => 'added'), $this->outcomes($req['req']));

    $this->assertEqual(1, $this->fx->count('cm_history_records',
      'co_person_id = ' . $this->p['p1'] . ' AND co_group_id = ' . $this->g['t1']
      . " AND action = 'ACGM' AND actor_co_person_id = " . $this->p['approverA']));

    $this->assertEqual(array(array('co_person_id' => $this->p['p1'], 'in_transaction' => false)),
                       $this->Req->provisioned, 'provisioned once, after the commit');
  }

  /**
   * Covers AE10. A researcher already directly in T2 as an owner: outcome
   * already_present, no second row, and the owner flag is kept.
   */
  public function testExistingDirectRowIsAlreadyPresent() {
    $existing = $this->fx->member($this->g['t2'], $this->p['p1'], array('owner' => true));
    $req = $this->pending('A', array('t1', 't2'));

    $res = $this->approveAsApprover($req['req']);

    $this->assertEqual(array($this->team['t1'] => 'added', $this->team['t2'] => 'already_present'), $res['teams']);
    $rows = $this->directRows('t2', $this->p['p1']);
    $this->assertEqual(1, count($rows));
    $this->assertEqual($existing, (int)$rows[0]['id']);
    $this->assertTrue((bool)$rows[0]['owner']);
    $this->assertEqual(array('t1' => 'added', 't2' => 'already_present'), $this->outcomes($req['req']));
  }

  /**
   * A researcher in T2 only through nesting (a nested source group) still
   * gets a direct T2 row, because the derived row goes away with the source
   * membership (KTD9).
   */
  public function testNestingOnlyMemberGetsDirectRow() {
    $src = $this->fx->group($this->coId, 'source ' . uniqid());
    $nesting = $this->fx->nesting($src, $this->g['t2']);
    $this->fx->member($src, $this->p['p1']);
    $this->fx->member($this->g['t2'], $this->p['p1'], array('co_group_nesting_id' => $nesting));
    $req = $this->pending('A', array('t2'));

    $res = $this->approveAsApprover($req['req']);

    $this->assertEqual(array($this->team['t2'] => 'added'), $res['teams']);
    $this->assertEqual(1, count($this->directRows('t2', $this->p['p1'])));
    $this->assertEqual(2, count($this->allRows('t2', $this->p['p1'])), 'the derived row is left alone');
  }

  /**
   * A direct T2 row that expired is reactivated in place: member, no past
   * valid_through, owner unchanged, outcome added.
   */
  public function testExpiredDirectRowIsReactivatedKeepingOwner() {
    $expired = $this->fx->member($this->g['t2'], $this->p['p1'], array(
      'owner' => true,
      'valid_through' => date('Y-m-d H:i:s', time() - 86400)
    ));
    $req = $this->pending('A', array('t2'));

    $res = $this->approveAsApprover($req['req']);

    $this->assertEqual(array($this->team['t2'] => 'added'), $res['teams']);
    $rows = $this->directRows('t2', $this->p['p1']);
    $this->assertEqual(1, count($rows));
    $this->assertEqual($expired, (int)$rows[0]['id'], 'the same row, not a new one');
    $this->assertTrue((bool)$rows[0]['member']);
    $this->assertTrue((bool)$rows[0]['owner'], 'owner unchanged');
    $this->assertNull($rows[0]['valid_through']);
  }

  /** A direct row that is owner only (member false) is made a member. */
  public function testOwnerOnlyRowIsMadeMember() {
    $row = $this->fx->member($this->g['t1'], $this->p['p1'], array('member' => false, 'owner' => true));
    $req = $this->pending('A', array('t1'));

    $res = $this->approveAsApprover($req['req']);

    $this->assertEqual(array($this->team['t1'] => 'added'), $res['teams']);
    $rows = $this->directRows('t1', $this->p['p1']);
    $this->assertEqual(1, count($rows));
    $this->assertEqual($row, (int)$rows[0]['id']);
    $this->assertTrue((bool)$rows[0]['member']);
    $this->assertTrue((bool)$rows[0]['owner']);
  }

  /**
   * Covers AE11. T2 is removed from A before approval: T1 is added and T2
   * is skipped.
   */
  public function testUnmappedTeamIsSkipped() {
    $req = $this->pending('A', array('t1', 't2'));
    $this->fx->query('UPDATE cm_ate_application_teams SET deleted = true WHERE ate_application_id = '
                     . $this->app['A'] . ' AND ate_research_team_id = ' . $this->team['t2']);

    $res = $this->approveAsApprover($req['req']);

    $this->assertEqual(array($this->team['t1'] => 'added', $this->team['t2'] => 'skipped'), $res['teams']);
    $this->assertEqual(1, count($this->directRows('t1', $this->p['p1'])));
    $this->assertEqual(0, count($this->allRows('t2', $this->p['p1'])));
  }

  /** A research team whose CoGroup was deleted before approval is skipped. */
  public function testDeletedTeamGroupIsSkipped() {
    $req = $this->pending('A', array('t1', 't2'));
    $this->fx->query('UPDATE cm_co_groups SET deleted = true WHERE id = ' . $this->g['t2']);

    $res = $this->approveAsApprover($req['req']);

    $this->assertEqual(array($this->team['t1'] => 'added', $this->team['t2'] => 'skipped'), $res['teams']);
    $this->assertEqual(0, count($this->allRows('t2', $this->p['p1'])));
  }

  /**
   * Covers AE16. link_required: approval by a CO admin creates an
   * OrgIdentity carrying the login as an Active login identifier of the
   * configured type, links it to P1 with a history record, and adds the
   * memberships to P1. No CoPerson is created.
   */
  public function testLinkRequiredApprovalLinksLoginToTarget() {
    $sub = $this->sub('new');
    $req = $this->pending('A', array('t1'), 'link_required', array(
      'invitee_co_person_id' => null,
      'link_target_co_person_id' => $this->p['p1'],
      'responder_identifier' => $sub
    ));
    $before = $this->peopleAndOrgIdentities();

    $res = $this->Req->approve($this->coId, $req['req'], $this->p['coAdmin'], 'co_admin');

    $this->assertTrue($res['handled']);
    $this->assertEqual($this->p['p1'], $res['co_person_id']);
    $this->assertNotEmpty($res['linked_org_identity_id']);

    $after = $this->peopleAndOrgIdentities();
    $this->assertEqual($before['people'], $after['people'], 'no CoPerson is created');
    $this->assertEqual($before['org_identities'] + 1, $after['org_identities']);

    $ids = $this->fx->rows('SELECT i.* FROM cm_identifiers i WHERE i.org_identity_id = '
                           . (int)$res['linked_org_identity_id']);
    $this->assertEqual(1, count($ids));
    $this->assertEqual($sub, $ids[0]['identifier']);
    $this->assertEqual('eppn', $ids[0]['type'], 'the configured login identifier type');
    $this->assertTrue((bool)$ids[0]['login']);
    $this->assertEqual('A', $ids[0]['status']);

    $this->assertEqual(1, $this->fx->count('cm_co_org_identity_links',
      'co_person_id = ' . $this->p['p1'] . ' AND org_identity_id = ' . (int)$res['linked_org_identity_id']));
    $this->assertEqual(1, $this->fx->count('cm_history_records',
      'co_person_id = ' . $this->p['p1'] . ' AND org_identity_id = ' . (int)$res['linked_org_identity_id']
      . " AND action = 'LOCP'"));

    $this->assertEqual($this->p['p1'], $this->Req->existingMemberCoPersonId($this->coId, $sub),
      'the login now resolves to P1 (KTD6)');
    $this->assertEqual(1, count($this->directRows('t1', $this->p['p1'])));
    $this->assertEqual($this->p['p1'], (int)$this->invitationRow($req['id'])['invitee_co_person_id']);
  }

  /**
   * A second link_required request on the same invitation does not link the
   * login a second time.
   */
  public function testLinkIsSkippedWhenAlreadyLinked() {
    $sub = $this->sub('new');
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')), array(
      'status' => 'responded',
      'link_target_co_person_id' => $this->p['p1'],
      'responder_identifier' => $sub,
      'responder_identifier_type' => 'oidcsub'
    ));
    $this->fx->query('UPDATE cm_ate_enrollment_requests SET status = \'pending_decision\','
                     . " pending_reason = 'link_required' WHERE ate_invitation_id = " . (int)$inv['id']);

    $first = $this->Req->approve($this->coId, $inv['req']['A'], $this->p['coAdmin'], 'co_admin');
    $second = $this->Req->approve($this->coId, $inv['req']['C'], $this->p['coAdmin'], 'co_admin');

    $this->assertNotEmpty($first['linked_org_identity_id']);
    $this->assertEqual($first['linked_org_identity_id'], $second['linked_org_identity_id']);
    $this->assertEqual(1, $this->fx->count('cm_identifiers i',
      "i.identifier = '" . $sub . "' AND i.org_identity_id IS NOT NULL"));
    $this->assertEqual(1, count($this->directRows('t4', $this->p['p1'])));
  }

  /**
   * All teams skipped: the request is approved with every team skipped, and
   * no identity link is made for link_required.
   */
  public function testAllSkippedApprovesWithoutLink() {
    $sub = $this->sub('new');
    $req = $this->pending('A', array('t1', 't2'), 'link_required', array(
      'invitee_co_person_id' => null,
      'link_target_co_person_id' => $this->p['p1'],
      'responder_identifier' => $sub
    ));
    $this->fx->query('UPDATE cm_ate_application_teams SET deleted = true WHERE ate_application_id = '
                     . $this->app['A']);
    $before = $this->peopleAndOrgIdentities();

    $res = $this->Req->approve($this->coId, $req['req'], $this->p['coAdmin'], 'co_admin');

    $this->assertTrue($res['handled']);
    $this->assertEqual('approved', $this->requestRow($req['req'])['status']);
    $this->assertEqual(array('t1' => 'skipped', 't2' => 'skipped'), $this->outcomes($req['req']));
    $this->assertNull($res['linked_org_identity_id']);
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'no identity link');
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $sub));
    $this->assertEqual(array(), $this->Req->provisioned, 'nothing changed, nothing provisioned');
  }

  /**
   * Covers AE3, R30. On one invitation, a declined, an approved, and a
   * denied request keep distinct statuses.
   */
  public function testDeclinedApprovedDeniedStayDistinct() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'B' => array('ts'), 'C' => array('ts')));

    $this->Req->commitResponse($this->coId, $inv['id'], $this->snapshot($sub, array(self::Invited)),
      array($inv['req']['A'] => true, $inv['req']['B'] => false, $inv['req']['C'] => true), $this->p['p1']);

    $this->approveAsApprover($inv['req']['A']);
    $deny = $this->Req->deny($this->coId, $inv['req']['C'], $this->p['coAdmin'], 'co_admin', 'not this one');

    $this->assertTrue($deny['handled']);
    $this->assertEqual('approved', $this->requestRow($inv['req']['A'])['status']);
    $this->assertEqual('declined_by_enrollee', $this->requestRow($inv['req']['B'])['status']);
    $c = $this->requestRow($inv['req']['C']);
    $this->assertEqual('denied', $c['status']);
    $this->assertEqual('co_admin', $c['decided_by_role']);
    $this->assertEqual('not this one', $c['comment']);
    $this->assertEqual(0, count($this->allRows('ts', $this->p['p1'])), 'R28: denial adds nothing');
  }

  /**
   * Two approvals of one request: exactly one wins, the other reports it was
   * already decided, and nothing is written twice (KTD8).
   */
  public function testTwoApprovalsExactlyOneWins() {
    $req = $this->pending('A', array('t1'));

    $first = $this->approveAsApprover($req['req'], 'first');
    $second = $this->Req->approve($this->coId, $req['req'], $this->p['coAdmin'], 'co_admin', 'second');

    $this->assertTrue($first['handled']);
    $this->assertFalse($second['handled']);
    $this->assertEqual('approved', $second['status'], 'the loser sees the current status');

    $r = $this->requestRow($req['req']);
    $this->assertEqual($this->p['approverA'], (int)$r['decider_co_person_id']);
    $this->assertEqual('first', $r['comment']);
    $this->assertEqual(1, count($this->directRows('t1', $this->p['p1'])));
    $this->assertEqual(1, $this->fx->count('cm_history_records',
      'co_person_id = ' . $this->p['p1'] . ' AND co_group_id = ' . $this->g['t1'] . " AND action = 'ACGM'"));
    $this->assertEqual(1, count($this->Req->provisioned));
  }

  /** Approve against withdraw: whichever runs first wins, the other loses. */
  public function testApproveVersusWithdrawOneWins() {
    $Invitation = $this->model('ApplicationTeamEnroller.AteInvitation');

    $req = $this->pending('A', array('t1'));
    $this->assertTrue($Invitation->withdrawRequest($this->coId, $req['req'], $this->p['inviter']));
    $lost = $this->approveAsApprover($req['req']);
    $this->assertFalse($lost['handled']);
    $this->assertEqual('revoked', $this->requestRow($req['req'])['status']);
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])));

    $req2 = $this->pending('A', array('t2'));
    $won = $this->approveAsApprover($req2['req']);
    $this->assertTrue($won['handled']);
    $w = $this->Req->withdraw($this->coId, $req2['req'], $this->p['inviter']);
    $this->assertFalse($w['handled']);
    $this->assertEqual('approved', $this->requestRow($req2['req'])['status']);
  }

  /** withdraw() reports success in the decision shape. */
  public function testWithdrawReturnsDecisionShape() {
    $req = $this->pending('A', array('t1'));

    $w = $this->Req->withdraw($this->coId, $req['req'], $this->p['inviter']);

    $this->assertTrue($w['handled']);
    $this->assertEqual('revoked', $w['status']);
    $this->assertEqual($req['id'], $w['invitation_id']);
    $this->assertEqual($this->app['A'], $w['application_id']);
    $this->assertEqual($this->p['inviter'], (int)$this->requestRow($req['req'])['withdrawn_by_co_person_id']);
  }

  /**
   * Deny moves only a pending request, records the decider and comment, and
   * adds nothing (R28); a second deny loses.
   */
  public function testDenyIsConditional() {
    $req = $this->pending('A', array('t1'));

    $first = $this->Req->deny($this->coId, $req['req'], $this->p['approverA'], 'approver', 'no');
    $second = $this->Req->deny($this->coId, $req['req'], $this->p['coAdmin'], 'co_admin', 'again');

    $this->assertTrue($first['handled']);
    $this->assertEqual('denied', $first['status']);
    $this->assertFalse($second['handled']);
    $r = $this->requestRow($req['req']);
    $this->assertEqual($this->p['approverA'], (int)$r['decider_co_person_id']);
    $this->assertEqual('no', $r['comment']);
    $this->assertEqual(array('t1' => null), $this->outcomes($req['req']));
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])));
  }

  /**
   * KTD7. A's approval setting changes after its request is pending: the
   * request and its reason are unchanged, nothing is approved automatically,
   * and the approver still decides it.
   */
  public function testApprovalSettingChangeLeavesPendingRequest() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $this->Req->commitResponse($this->coId, $inv['id'], $this->snapshot($sub, array(self::Invited)),
                               $this->acceptAll($inv), $this->p['p1']);
    $this->assertEqual('approval', $this->requestRow($inv['req']['A'])['pending_reason']);

    $this->fx->query('UPDATE cm_ate_applications SET approval_required = false WHERE id = ' . $this->app['A']);

    $r = $this->requestRow($inv['req']['A']);
    $this->assertEqual('pending_decision', $r['status']);
    $this->assertEqual('approval', $r['pending_reason']);
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])));

    $res = $this->approveAsApprover($inv['req']['A']);
    $this->assertTrue($res['handled']);
    $this->assertEqual('approver', $this->requestRow($inv['req']['A'])['decided_by_role']);
    $this->assertEqual('approval', $this->requestRow($inv['req']['A'])['pending_reason']);
  }

  /**
   * A failure part way through approval rolls everything back: no link, no
   * membership, the request still pending, and nothing provisioned.
   */
  public function testFailedApprovalRollsBackEverything() {
    $sub = $this->sub('new');
    $req = $this->pending('A', array('t1', 't2'), 'link_required', array(
      'invitee_co_person_id' => null,
      'link_target_co_person_id' => $this->p['p1'],
      'responder_identifier' => $sub
    ));
    $before = $this->peopleAndOrgIdentities();
    $this->Req->failMembershipForGroup = $this->g['t2'];

    $this->assertThrows(function() use ($req) {
      $this->Req->approve($this->coId, $req['req'], $this->p['coAdmin'], 'co_admin');
    }, 'RuntimeException', 'failing membership write');

    $this->assertEqual('pending_decision', $this->requestRow($req['req'])['status']);
    $this->assertEqual(array('t1' => null, 't2' => null), $this->outcomes($req['req']));
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])), 'T1 was rolled back');
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'the link was rolled back');
    $this->assertEqual(array(), $this->Req->provisioned, 'a rolled-back approval is never provisioned');
  }

  /**
   * R39 defense in depth: the model refuses a decision by the responder or
   * the link target, and a public decision cannot claim to be automatic.
   */
  public function testSelfDecisionIsRefused() {
    $req = $this->pending('A', array('t1'));

    $this->assertThrows(function() use ($req) {
      $this->Req->approve($this->coId, $req['req'], $this->p['p1'], 'co_admin');
    }, 'InvalidArgumentException', 'responder approves');

    $link = $this->pending('C', array('t4'), 'link_required', array(
      'invitee_co_person_id' => null,
      'link_target_co_person_id' => $this->p['p2'],
      'responder_identifier' => $this->sub('new')
    ));

    $this->assertThrows(function() use ($link) {
      $this->Req->deny($this->coId, $link['req'], $this->p['p2'], 'co_admin');
    }, 'InvalidArgumentException', 'link target denies');

    $this->assertThrows(function() use ($req) {
      $this->Req->approve($this->coId, $req['req'], $this->p['approverA'], 'automatic');
    }, 'InvalidArgumentException', 'automatic role');

    $this->assertEqual('pending_decision', $this->requestRow($req['req'])['status']);
    $this->assertEqual('pending_decision', $this->requestRow($link['req'])['status']);
  }

  /** A request of another CO is not decided. */
  public function testRequestOfAnotherCoIsNotDecided() {
    $req = $this->pending('A', array('t1'));

    $res = $this->Req->approve($this->otherCoId, $req['req'], $this->p['approverA'], 'approver');

    $this->assertFalse($res['handled']);
    $this->assertEqual('pending_decision', $this->requestRow($req['req'])['status']);
  }

  /**
   * Approval with provisioning suspended still reaches the application's
   * access group through nesting (R31, KTD10): core's CoGroupMember
   * afterSave derives the access-group row, and provisioning is restored
   * afterwards.
   */
  public function testApprovalReachesAccessGroupThroughNesting() {
    $access = $this->fx->group($this->coId, 'access ' . uniqid());
    $this->fx->nesting($this->g['t1'], $access);
    $req = $this->pending('A', array('t1'));

    $this->approveAsApprover($req['req']);

    $this->assertEqual(1, $this->fx->count('cm_co_group_members',
      'co_group_id = ' . (int)$access . ' AND co_person_id = ' . $this->p['p1']
      . ' AND co_group_nesting_id IS NOT NULL AND co_group_member_id IS NULL AND deleted IS NOT true'),
      'the derived access-group membership exists');
    $this->assertTrue(ClassRegistry::init('CoGroupMember')->Behaviors->enabled('Provisioner'),
      'CoGroupMember provisioning is restored');
  }

  /**
   * The real model (not the probe) approves and provisions without error
   * when the CO has no provisioning targets.
   */
  public function testRealProvisioningPathRuns() {
    $req = $this->pending('A', array('t1'));
    $Real = $this->model('ApplicationTeamEnroller.AteEnrollmentRequest');

    $res = $Real->approve($this->coId, $req['req'], $this->p['approverA'], 'approver');

    $this->assertTrue($res['handled']);
    $this->assertTrue($res['provisioned']);
    $this->assertEqual(1, count($this->directRows('t1', $this->p['p1'])));
    $this->assertEqual('', implode("\n", AteRecordingLog::$lines), 'no provisioning error was logged');
  }
}
