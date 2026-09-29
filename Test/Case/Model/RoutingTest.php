<?php
/**
 * U7: committing a response and routing each accepted request (R19-R24,
 * R27, KTD7, KTD8, the "Response routing" diagram).
 *
 * Applications (see AteEngineTestCase): A and B require approval, C and D do
 * not; team TS is authorized for both B and C.
 */

class RoutingTest extends AteEngineTestCase {

  /** An existing member P1 whose login reports the invited address. */
  private function matchingMember() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    return $this->snapshot($sub, array(self::Invited));
  }

  /** An existing member P3 whose login reports only another address. */
  private function mismatchedMember() {
    $sub = $this->sub('p3');
    $this->login($this->p['p3'], $sub, array(self::Other));
    return $this->snapshot($sub, array(self::Other));
  }

  /**
   * Covers AE6. C has approval off and T4 is authorized only for C; a
   * matching existing member's accept is approved automatically and the
   * membership exists.
   */
  public function testApprovalOffMatchIsApprovedAutomatically() {
    $inv = $this->invitation(self::Invited, array('C' => array('t4')));
    $this->assertEqual(0, count($this->directRows('t4', $this->p['p1'])));

    $res = $this->Req->commitResponse($this->coId, $inv['id'], $this->matchingMember(), $this->acceptAll($inv),
                                      $this->p['p1']);

    $this->assertTrue($res['handled']);
    $r = $this->requestRow($inv['req']['C']);
    $this->assertEqual('approved', $r['status']);
    $this->assertEqual('automatic', $r['decided_by_role']);
    $this->assertNull($r['decider_co_person_id']);
    $this->assertNotEmpty($r['decided_at']);
    $this->assertNull($r['pending_reason']);
    $this->assertEqual(array('t4' => 'added'), $this->outcomes($inv['req']['C']));
    $this->assertEqual(1, count($this->directRows('t4', $this->p['p1'])));

    $this->assertEqual('approved', $res['requests'][$inv['req']['C']]['status']);
    $this->assertEqual('automatic', $res['requests'][$inv['req']['C']]['decided_by_role']);
    $this->assertEqual(array($this->team['t4'] => 'added'), $res['requests'][$inv['req']['C']]['teams']);

    // Provisioned once, after the commit
    $this->assertEqual(array(array('co_person_id' => $this->p['p1'], 'in_transaction' => false)),
                       $this->Req->provisioned);
  }

  /**
   * Covers AE15. TS is authorized for C (approval off) and B (approval
   * required), so a matching accept of C offering TS waits for approval.
   */
  public function testSharedTeamWithApprovalAppIsPendingApproval() {
    $inv = $this->invitation(self::Invited, array('C' => array('ts')));

    $this->Req->commitResponse($this->coId, $inv['id'], $this->matchingMember(), $this->acceptAll($inv),
                               $this->p['p1']);

    $r = $this->requestRow($inv['req']['C']);
    $this->assertEqual('pending_decision', $r['status']);
    $this->assertEqual('approval', $r['pending_reason']);
    $this->assertEqual(0, count($this->allRows('ts', $this->p['p1'])), 'no membership before a decision');
    $this->assertEqual(array(), $this->Req->provisioned);
  }

  /**
   * Covers AE7. Only another address reported, C with approval off and T4
   * not shared: pending with reason mismatch, and the invitation is flagged.
   */
  public function testMismatchOnApprovalOffIsPendingMismatch() {
    $inv = $this->invitation(self::Invited, array('C' => array('t4')));

    $res = $this->Req->commitResponse($this->coId, $inv['id'], $this->mismatchedMember(), $this->acceptAll($inv),
                                      $this->p['p3']);

    $r = $this->requestRow($inv['req']['C']);
    $this->assertEqual('pending_decision', $r['status']);
    $this->assertEqual('mismatch', $r['pending_reason']);
    $this->assertEqual('mismatch', $res['requests'][$inv['req']['C']]['pending_reason']);
    $this->assertTrue((bool)$this->invitationRow($inv['id'])['mismatch']);
    $this->assertEqual(0, count($this->allRows('t4', $this->p['p3'])));
  }

  /**
   * A mismatch on C (approval off) offering TS, which approval-required B
   * also authorizes: reason approval, not mismatch.
   */
  public function testMismatchOnSharedTeamIsPendingApproval() {
    $inv = $this->invitation(self::Invited, array('C' => array('ts')));

    $this->Req->commitResponse($this->coId, $inv['id'], $this->mismatchedMember(), $this->acceptAll($inv),
                               $this->p['p3']);

    $this->assertEqual('approval', $this->requestRow($inv['req']['C'])['pending_reason']);
  }

  /**
   * Covers AE8. A flagged invitation for A (approval required) and C
   * (approval off): A goes to its approvers, C to the inviting admin.
   */
  public function testMismatchRoutesEachApplicationOnItsOwn() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));

    $this->Req->commitResponse($this->coId, $inv['id'], $this->mismatchedMember(), $this->acceptAll($inv),
                               $this->p['p3']);

    $this->assertEqual('approval', $this->requestRow($inv['req']['A'])['pending_reason']);
    $this->assertEqual('mismatch', $this->requestRow($inv['req']['C'])['pending_reason']);
  }

  /** A matching accept of approval-required A waits for approval. */
  public function testApprovalRequiredIsPendingApproval() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));

    $this->Req->commitResponse($this->coId, $inv['id'], $this->matchingMember(), $this->acceptAll($inv),
                               $this->p['p1']);

    $r = $this->requestRow($inv['req']['A']);
    $this->assertEqual('pending_decision', $r['status']);
    $this->assertEqual('approval', $r['pending_reason']);
    $this->assertFalse((bool)$this->invitationRow($inv['id'])['mismatch']);
  }

  /**
   * Covers AE16 (routing). The invited address is on P1 and the login is
   * linked to no one: each accepted request is pending link_required even on
   * approval-off D, the link target is P1, and no CoPerson or OrgIdentity is
   * created.
   */
  public function testUnlinkedLoginOnExistingAddressIsLinkRequired() {
    $this->login($this->p['p1'], $this->sub('p1'), array(self::Invited));
    $sub = $this->sub('new');
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'D' => array('t5')));
    $before = $this->peopleAndOrgIdentities();

    $res = $this->Req->commitResponse($this->coId, $inv['id'], $this->snapshot($sub, array(self::Invited)),
                                      $this->acceptAll($inv), null);

    foreach(array('A', 'D') as $k) {
      $r = $this->requestRow($inv['req'][$k]);
      $this->assertEqual('pending_decision', $r['status'], $k);
      $this->assertEqual('link_required', $r['pending_reason'], $k);
    }

    $i = $this->invitationRow($inv['id']);
    $this->assertEqual($this->p['p1'], (int)$i['link_target_co_person_id']);
    $this->assertNull($i['invitee_co_person_id']);
    $this->assertEqual($this->p['p1'], $res['link_target_co_person_id']);
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'nothing is created at response time');
    $this->assertEqual(0, count($this->allRows('t5', $this->p['p1'])));
  }

  /**
   * Declines become declined_by_enrollee, distinct from a denial, and a
   * newcomer who declines everything is committed with no CoPerson (AE5
   * decline half at the model level).
   */
  public function testDeclineAllByNewcomerCreatesNothing() {
    $sub = $this->sub('newcomer');
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $before = $this->peopleAndOrgIdentities();

    $res = $this->Req->commitResponse($this->coId, $inv['id'], $this->snapshot($sub, array(self::Invited)),
                                      array_fill_keys(array_values($inv['req']), false), null);

    $this->assertTrue($res['handled']);
    foreach(array('A', 'C') as $k) {
      $this->assertEqual('declined_by_enrollee', $this->requestRow($inv['req'][$k])['status'], $k);
      $this->assertNull($this->requestRow($inv['req'][$k])['pending_reason'], $k);
    }
    $i = $this->invitationRow($inv['id']);
    $this->assertEqual('responded', $i['status']);
    $this->assertNull($i['invitee_co_person_id']);
    $this->assertEqual($before, $this->peopleAndOrgIdentities());
  }

  /**
   * The response is one conditional transition (KTD8): a second commit of
   * the same invitation reports it was already handled and changes nothing.
   */
  public function testSecondCommitIsAlreadyHandled() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $snap = $this->matchingMember();

    $first = $this->Req->commitResponse($this->coId, $inv['id'], $snap,
                                        array($inv['req']['A'] => true, $inv['req']['C'] => false),
                                        $this->p['p1']);
    $this->assertTrue($first['handled']);

    $second = $this->Req->commitResponse($this->coId, $inv['id'], $snap,
                                         array($inv['req']['A'] => false, $inv['req']['C'] => true),
                                         $this->p['p1']);

    $this->assertFalse($second['handled']);
    $this->assertEqual('pending_decision', $this->requestRow($inv['req']['A'])['status']);
    $this->assertEqual('declined_by_enrollee', $this->requestRow($inv['req']['C'])['status']);
    $this->assertEqual(0, count($this->allRows('t4', $this->p['p1'])), 'the losing commit added nothing');
    $this->assertFalse(ConnectionManager::getDataSource('default')->inTransaction());
  }

  /** A revoked invitation cannot be committed. */
  public function testRevokedInvitationIsAlreadyHandled() {
    $inv = $this->invitation(self::Invited, array('C' => array('t4')), array('status' => 'revoked'));

    $res = $this->Req->commitResponse($this->coId, $inv['id'], $this->matchingMember(), $this->acceptAll($inv),
                                      $this->p['p1']);

    $this->assertFalse($res['handled']);
    $this->assertEqual('revoked', $this->invitationRow($inv['id'])['status']);
    $this->assertEqual('offered', $this->requestRow($inv['req']['C'])['status']);
    $this->assertEqual(0, count($this->allRows('t4', $this->p['p1'])));
  }

  /**
   * An accept with no CoPerson and no link to make is refused: a newcomer
   * who accepts goes through the enrollment flow first (R21).
   */
  public function testAcceptWithoutPersonIsRefused() {
    $inv = $this->invitation(self::Invited, array('C' => array('t4')));
    $snap = $this->snapshot($this->sub('newcomer'), array(self::Invited));

    $this->assertThrows(function() use ($inv, $snap) {
      $this->Req->commitResponse($this->coId, $inv['id'], $snap, $this->acceptAll($inv), null);
    }, 'InvalidArgumentException', 'accept without a CoPerson');

    $this->assertEqual('sent', $this->invitationRow($inv['id'])['status']);
    $this->assertEqual('offered', $this->requestRow($inv['req']['C'])['status']);
  }

  /** Every offered request needs a choice, and only offered requests count. */
  public function testChoicesMustCoverEveryRequest() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $other = $this->invitation(self::Invited, array('D' => array('t5')));
    $snap = $this->matchingMember();

    $this->assertThrows(function() use ($inv, $snap) {
      $this->Req->commitResponse($this->coId, $inv['id'], $snap, array($inv['req']['A'] => true), $this->p['p1']);
    }, 'InvalidArgumentException', 'a missing choice');

    $this->assertThrows(function() use ($inv, $other, $snap) {
      $this->Req->commitResponse($this->coId, $inv['id'], $snap,
        $this->acceptAll($inv) + array($other['req']['D'] => true), $this->p['p1']);
    }, 'InvalidArgumentException', 'a request of another invitation');

    $this->assertEqual('sent', $this->invitationRow($inv['id'])['status']);
  }

  /**
   * A responder CoPerson that contradicts the login's own CoPerson is
   * refused, so a caller cannot commit one person's login against another.
   */
  public function testResponderMustMatchLogin() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $snap = $this->matchingMember();

    $this->assertThrows(function() use ($inv, $snap) {
      $this->Req->commitResponse($this->coId, $inv['id'], $snap, $this->acceptAll($inv), $this->p['p2']);
    }, 'InvalidArgumentException', 'responder P2 with P1 login');

    $this->assertEqual('sent', $this->invitationRow($inv['id'])['status']);
  }

  /**
   * The snapshot is persisted with the response (KTD5, R7): identifier,
   * type, name, emails, mismatch flag, responder, and response time.
   */
  public function testSnapshotIsPersisted() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));

    $this->Req->commitResponse($this->coId, $inv['id'],
      $this->snapshot($sub, array(self::Invited, self::Third), array('name' => 'Pat Q. Researcher')),
      $this->acceptAll($inv), $this->p['p1']);

    $i = $this->invitationRow($inv['id']);
    $this->assertEqual('responded', $i['status']);
    $this->assertEqual($sub, $i['responder_identifier']);
    $this->assertEqual('oidcsub', $i['responder_identifier_type']);
    $this->assertEqual('Pat Q. Researcher', $i['responder_name']);
    $this->assertEqual(array(self::Invited, self::Third), json_decode($i['identity_emails'], true));
    $this->assertEqual($this->p['p1'], (int)$i['invitee_co_person_id']);
    $this->assertFalse((bool)$i['mismatch']);
    $this->assertNotEmpty($i['responded_at']);
    $this->assertNull($i['link_target_co_person_id']);
  }
}
