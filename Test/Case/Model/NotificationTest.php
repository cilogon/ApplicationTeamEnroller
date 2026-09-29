<?php
/**
 * U10: decider notifications, inviting-admin notifications, and researcher
 * decision emails (R35, R36, R37, KTD12, KTD13).
 *
 * The hooks are AteEnrollmentRequest::afterResponse() (U8 calls it after
 * commitResponse()), afterDecision() (the decision queue calls it after
 * approve() and deny()), and resolvePendingNotification(), which
 * AteInvitation::withdrawRequest() calls itself.
 *
 * Registry's CoNotification mails through CakeEmail('default'), which the
 * test image does not configure, so no recipient here has an email address;
 * the tests assert on the cm_co_notifications rows. Researcher emails go
 * through AteEnrollmentRequest::$emailConfig to the recording transport.
 *
 * World: see AteEngineTestCase, plus a CO admins group holding coAdmin.
 */

class NotificationTest extends AteEngineTestCase {

  public function setUp() {
    parent::setUp();

    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . AteFixtures::tag('ate-u10'),
                                            array('group_type' => 'A'));
    $this->fx->member($this->g['coadmins'], $this->p['coAdmin']);

    $this->Req->emailConfig = AteRecordingTransport::emailConfig();
    AteRecordingTransport::reset();
  }

  public function tearDown() {
    AteRecordingTransport::reset();
    ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest')->emailConfig = 'default';

    parent::tearDown();
  }

  /** An existing member P1 whose login reports the invited address. */
  private function matchingMember() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    return $this->snapshot($sub, array(self::Invited));
  }

  /** Commit an accept of every request as P1 and run the U8 hook. */
  private function respond($inv, $snapshot, $coPersonId) {
    $res = $this->Req->commitResponse($this->coId, $inv['id'], $snapshot, $this->acceptAll($inv), $coPersonId);
    $this->assertTrue($res['handled'], 'the response commits');

    return $this->Req->afterResponse($this->coId, $res);
  }

  /** Notification rows with an action code about a request's decision page. */
  private function notificationsFor($requestId, $action = 'pAPD') {
    return $this->fx->rows("SELECT * FROM cm_co_notifications WHERE action = '" . $action . "'"
                           . " AND source_url LIKE '%/ate_enrollment_requests/view/" . (int)$requestId . "'"
                           . ' ORDER BY id');
  }

  /** Notification rows with an action code sent to a CoPerson. */
  private function notificationsTo($coPersonId, $action) {
    return $this->fx->rows("SELECT * FROM cm_co_notifications WHERE action = '" . $action . "'"
                           . ' AND recipient_co_person_id = ' . (int)$coPersonId . ' ORDER BY id');
  }

  /**
   * R35, KTD12. An accepted request on an approval-required application
   * becomes pending and registers exactly one notification, to A's approver
   * group, that must be resolved; running the hook again adds none. Deciding
   * it resolves the notification.
   */
  public function testPendingRegistersOneNotificationToApproverGroupAndDecidingResolvesIt() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $reqId = $inv['req']['A'];

    $out = $this->respond($inv, $this->matchingMember(), $this->p['p1']);

    $rows = $this->notificationsFor($reqId);
    $this->assertEqual(1, count($rows), 'one decider notification');
    $n = $rows[0];
    $this->assertEqual((int)$this->g['approversA'], (int)$n['recipient_co_group_id']);
    $this->assertNull($n['recipient_co_person_id']);
    $this->assertEqual(NotificationStatusEnum::PendingResolution, $n['status']);
    $this->assertEqual((int)$this->p['p1'], (int)$n['subject_co_person_id'], 'the researcher is the subject');
    $this->assertEqual(array((int)$n['id']), $out['pending_notifications'][$reqId]);
    $this->assertEqual(0, count(AteRecordingTransport::$sent), 'no researcher email while pending');

    // The same URL string resolves it (KTD12)
    $this->assertEqual($this->Req->decisionUrl($reqId), $n['source_url']);

    // Idempotent: registering again adds nothing
    $this->assertEqual(array(), $this->Req->notifyPending($this->coId, $reqId));
    $this->assertEqual(1, count($this->notificationsFor($reqId)));

    $res = $this->Req->approve($this->coId, $reqId, $this->p['approverA'], 'approver');
    $after = $this->Req->afterDecision($this->coId, $res, $this->p['approverA']);

    $this->assertTrue($after['resolved']);
    $rows = $this->notificationsFor($reqId);
    $this->assertEqual(NotificationStatusEnum::Resolved, $rows[0]['status']);
    $this->assertEqual((int)$this->p['approverA'], (int)$rows[0]['resolver_co_person_id']);
  }

  /**
   * KTD12, R24. The decider set follows pending_reason: a mismatch on an
   * application with approval off notifies the inviting admin as a person;
   * if the inviter is no longer in the admin group (and not a CO admin), the
   * admin group; link_required notifies the CO admins group.
   */
  public function testDeciderSetFollowsPendingReason() {
    // Mismatch on C (approval off): the inviting admin
    $sub = $this->sub('p3');
    $this->login($this->p['p3'], $sub, array(self::Other));
    $inv = $this->invitation(self::Invited, array('C' => array('t4')));
    $this->respond($inv, $this->snapshot($sub, array(self::Other)), $this->p['p3']);

    $this->assertEqual('mismatch', $this->requestRow($inv['req']['C'])['pending_reason']);
    $rows = $this->notificationsFor($inv['req']['C']);
    $this->assertEqual(1, count($rows));
    $this->assertEqual((int)$this->p['inviter'], (int)$rows[0]['recipient_co_person_id']);
    $this->assertNull($rows[0]['recipient_co_group_id']);
    $this->assertEqual(NotificationStatusEnum::PendingResolution, $rows[0]['status']);

    // Mismatch where the inviter (P2) is not in C's admin group: the group
    $sub2 = $this->sub('p3b');
    $this->login($this->p['p3'], $sub2, array(self::Other));
    $inv2 = $this->invitation(self::Invited, array('D' => array('t5')),
                              array('inviter_co_person_id' => $this->p['p2']));
    $this->respond($inv2, $this->snapshot($sub2, array(self::Other)), $this->p['p3']);

    $rows = $this->notificationsFor($inv2['req']['D']);
    $this->assertEqual(1, count($rows));
    $this->assertEqual((int)$this->g['admins'], (int)$rows[0]['recipient_co_group_id']);

    // link_required: the invited address is P2's and the login is unlinked
    $this->fx->emailAddress(self::Third, array('co_person_id' => $this->p['p2'], 'verified' => true));
    $unlinked = $this->sub('unlinked');
    $inv3 = $this->invitation(self::Third, array('A' => array('t1')));
    $res = $this->Req->commitResponse($this->coId, $inv3['id'], $this->snapshot($unlinked, array(self::Third)),
                                      $this->acceptAll($inv3));
    $this->Req->afterResponse($this->coId, $res);

    $this->assertEqual('link_required', $this->requestRow($inv3['req']['A'])['pending_reason']);
    $rows = $this->notificationsFor($inv3['req']['A']);
    $this->assertEqual(1, count($rows));
    $this->assertEqual((int)$this->g['coadmins'], (int)$rows[0]['recipient_co_group_id']);
    $this->assertEqual((int)$this->p['p2'], (int)$rows[0]['subject_co_person_id'], 'the link target is the subject');
  }

  /**
   * R27, R36, R37. An automatic approval sends the researcher one email at
   * the invited address and registers no decider notification; the inviting
   * admin hears of the decision.
   */
  public function testAutomaticApprovalSendsOneEmailAndNoDeciderNotification() {
    $inv = $this->invitation(self::Invited, array('D' => array('t5')));
    $reqId = $inv['req']['D'];

    $out = $this->respond($inv, $this->matchingMember(), $this->p['p1']);

    $this->assertEqual('approved', $this->requestRow($reqId)['status']);
    $this->assertEqual(0, count($this->notificationsFor($reqId)), 'no decider notification');
    $this->assertEqual(0, count($this->fx->rows("SELECT id FROM cm_co_notifications WHERE action = 'pAPD'"
                                                . ' AND subject_co_person_id = ' . (int)$this->p['p1'])));
    $this->assertEqual(array(), $out['pending_notifications']);

    $this->assertEqual(1, count(AteRecordingTransport::$sent), 'one researcher email');
    $mail = AteRecordingTransport::$sent[0];
    $this->assertEqual(array(self::Invited), $mail['to']);
    $this->assertContains('App D', $mail['subject']);
    $this->assertContains('Team t5', $mail['body']);

    $this->assertEqual(1, count($this->notificationsTo($this->p['inviter'], 'pADC')), 'R37: the inviter hears');
  }

  /**
   * R36, R37. A denial emails the researcher once, without the decider's
   * comment, which is kept on the request for audit. The inviting admin is
   * notified of the decision; a decider who is the inviting admin is not
   * notified of their own decision.
   */
  public function testDenialEmailOmitsTheCommentAndTheInviterIsNotified() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'B' => array('ts')));
    $this->respond($inv, $this->matchingMember(), $this->p['p1']);
    $secret = 'Decider-only remark 4711 about Pat';

    $res = $this->Req->deny($this->coId, $inv['req']['A'], $this->p['approverA'], 'approver', $secret);
    $after = $this->Req->afterDecision($this->coId, $res, $this->p['approverA']);

    $this->assertEqual('denied', $this->requestRow($inv['req']['A'])['status']);
    $this->assertEqual($secret, $this->requestRow($inv['req']['A'])['comment'], 'kept for audit');
    $this->assertTrue($after['researcher_emailed']);
    $this->assertEqual(1, count(AteRecordingTransport::$sent));

    $mail = AteRecordingTransport::$sent[0];
    $this->assertEqual(array(self::Invited), $mail['to']);
    $this->assertContains('App A', $mail['subject']);
    $this->assertFalse(strpos($mail['subject'] . $mail['body'], '4711') !== false, 'the comment is never sent');
    $this->assertFalse(strpos($mail['body'], 'Decider-only') !== false, 'the comment is never sent');

    $decided = $this->notificationsTo($this->p['inviter'], 'pADC');
    $this->assertEqual(1, count($decided), 'R37');
    $this->assertEqual(NotificationStatusEnum::PendingAcknowledgment, $decided[0]['status']);
    $this->assertFalse(strpos($decided[0]['comment'], '4711') !== false);

    // The inviter decides B as a CO admin: no notification to themselves
    $res = $this->Req->deny($this->coId, $inv['req']['B'], $this->p['inviter'], 'co_admin');
    $this->Req->afterDecision($this->coId, $res, $this->p['inviter']);
    $this->assertEqual(1, count($this->notificationsTo($this->p['inviter'], 'pADC')));
    $this->assertEqual(2, count(AteRecordingTransport::$sent), 'one email per decided application');
  }

  /**
   * R18, R35. Withdrawing a pending request through the invitation model
   * resolves its decider notification and emails no one.
   */
  public function testWithdrawResolvesTheDeciderNotification() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $reqId = $inv['req']['A'];
    $this->respond($inv, $this->matchingMember(), $this->p['p1']);
    $this->assertEqual(1, count($this->notificationsFor($reqId)));

    $Invitation = ClassRegistry::init('ApplicationTeamEnroller.AteInvitation');
    $this->assertTrue($Invitation->withdrawRequest($this->coId, $reqId, $this->p['inviter']));

    $rows = $this->notificationsFor($reqId);
    $this->assertEqual(NotificationStatusEnum::Resolved, $rows[0]['status']);
    $this->assertEqual((int)$this->p['inviter'], (int)$rows[0]['resolver_co_person_id']);
    $this->assertEqual(0, count(AteRecordingTransport::$sent));
  }

  /**
   * KTD8. The losing call of a race (handled false) notifies and emails no
   * one, so each decision is announced exactly once.
   */
  public function testUnhandledDecisionAnnouncesNothing() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $reqId = $inv['req']['A'];
    $this->respond($inv, $this->matchingMember(), $this->p['p1']);

    $first = $this->Req->approve($this->coId, $reqId, $this->p['approverA'], 'approver');
    $second = $this->Req->approve($this->coId, $reqId, $this->p['coAdmin'], 'co_admin');
    $this->assertFalse($second['handled']);

    $this->Req->afterDecision($this->coId, $first, $this->p['approverA']);
    $out = $this->Req->afterDecision($this->coId, $second, $this->p['coAdmin']);

    $this->assertFalse($out['resolved']);
    $this->assertFalse($out['researcher_emailed']);
    $this->assertEqual(1, count(AteRecordingTransport::$sent));
    $this->assertEqual(1, count($this->notificationsTo($this->p['inviter'], 'pADC')));
  }

  /**
   * A mail failure after a decision is logged; the decision stands and the
   * decider notification is still resolved.
   */
  public function testEmailFailureIsLoggedAndTheDecisionStands() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $reqId = $inv['req']['A'];
    $this->respond($inv, $this->matchingMember(), $this->p['p1']);

    AteRecordingTransport::$fail = true;
    $res = $this->Req->approve($this->coId, $reqId, $this->p['approverA'], 'approver');
    $out = $this->Req->afterDecision($this->coId, $res, $this->p['approverA']);

    $this->assertFalse($out['researcher_emailed']);
    $this->assertEqual('approved', $this->requestRow($reqId)['status']);
    $this->assertEqual(NotificationStatusEnum::Resolved, $this->notificationsFor($reqId)[0]['status']);
    $this->assertContains('hermetic mail transport failure', implode("\n", AteRecordingLog::$lines));
  }
}
