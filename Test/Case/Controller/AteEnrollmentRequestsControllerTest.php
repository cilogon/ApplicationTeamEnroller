<?php
/**
 * U10: the decision queue (F3, R25, R24, R39, AE13, AE17, AE18, KTD17).
 * The queue lists only the pending requests the user may decide, with R25's
 * fields, the researcher's CoPerson status (KTD6), and for link_required the
 * person the login would be linked to. Approve and deny are POST actions
 * that re-check eligibility, call the U7 engine with the deciding role from
 * AteAuthzComponent, resolve the decider notification, and email the
 * researcher without the comment.
 *
 * Driven through Test/lib/AteControllerHarness.php without rendering. World:
 * see AteEngineTestCase, plus a CO admins group holding coAdmin. stranger
 * (P3) is a plain CO member.
 */

App::uses('AteEnrollmentRequestsController', 'ApplicationTeamEnroller.Controller');
App::uses('AteInvitationsController', 'ApplicationTeamEnroller.Controller');

class AteEnrollmentRequestsHarness extends AteEnrollmentRequestsController {
  use AteControllerHarness;
}

class AteDecisionWithdrawHarness extends AteInvitationsController {
  use AteControllerHarness;
}

class AteEnrollmentRequestsControllerTest extends AteEngineTestCase {

  public function setUp() {
    parent::setUp();

    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . AteFixtures::tag('ate-u10-ctl'),
                                            array('group_type' => 'A'));
    $this->fx->member($this->g['coadmins'], $this->p['coAdmin']);

    AteRecordingTransport::reset();
  }

  public function tearDown() {
    AteRecordingTransport::reset();
    ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest')->emailConfig = 'default';

    parent::tearDown();
  }

  /** Roles for person $who; $coAdmin adds the coadmin role. */
  private function roles($who, $coAdmin = false) {
    $roles = array('comember' => true, 'user' => true, 'copersonid' => $this->p[$who]);

    if($coAdmin || $who === 'coAdmin') {
      $roles['coadmin'] = true;
    }

    return $roles;
  }

  /** A queue harness acting as $who, with email going to the recording transport. */
  private function harness($who, $data = array(), $coAdmin = false) {
    $h = AteEnrollmentRequestsHarness::harnessBuild('ate_enrollment_requests', $this->coId,
                                                    $this->roles($who, $coAdmin), $data);
    $h->AteEnrollmentRequest->emailConfig = AteRecordingTransport::emailConfig();

    return $h;
  }

  /** Whether $who may run $action with $pass. */
  private function allowed($who, $action, $pass = array(), $coAdmin = false) {
    $h = $this->harness($who, array(), $coAdmin);
    $h->action = $action;
    $h->request->params['action'] = $action;
    $h->request->params['pass'] = $pass;

    return (bool)$h->isAuthorized();
  }

  /** The request IDs in $who's queue. */
  private function queue($who, $coAdmin = false) {
    $h = $this->harness($who, array(), $coAdmin);
    $h->harnessInvoke('index');

    return array_map(function($e) { return $e['id']; }, $h->viewVars['vv_requests']);
  }

  /**
   * A responded invitation to the invited address with one pending request
   * for application $appKey, reason $reason, offering $teamKeys. By default
   * P1 responded with a login reporting the invited address.
   *
   * @return Integer AteEnrollmentRequest ID
   */
  private function pending($appKey, $reason, $teamKeys, $invOverrides = array()) {
    $inv = $this->invitation(self::Invited, array($appKey => $teamKeys), $invOverrides + array(
      'status' => 'responded',
      'responded_at' => date('Y-m-d H:i:s'),
      'invitee_co_person_id' => $this->p['p1'],
      'responder_identifier' => $this->sub('p1'),
      'responder_name' => 'Pat Researcher',
      'identity_emails' => json_encode(array(self::Invited)),
      'mismatch' => ($reason === 'mismatch')
    ));

    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'pending_decision', pending_reason = '"
                     . $reason . "' WHERE id = " . (int)$inv['req'][$appKey]);

    return (int)$inv['req'][$appKey];
  }

  /** Whether any flash message with key $key contains $text. */
  private function flashed($h, $key, $text = '') {
    foreach($h->Flash->messages as $m) {
      if(isset($m['options']['key']) && $m['options']['key'] === $key
         && ($text === '' || strpos($m['message'], $text) !== false)) {
        return true;
      }
    }

    return false;
  }

  /** Decider notification rows about a request. */
  private function notifications($requestId) {
    return $this->fx->rows("SELECT * FROM cm_co_notifications WHERE action = 'pAPD'"
                           . " AND source_url LIKE '%/ate_enrollment_requests/view/" . (int)$requestId . "'"
                           . ' ORDER BY id');
  }

  /**
   * KTD16. Registry shows comain entries to every CO member, so the queue
   * checks access itself: a plain member may not see it; an approver, an
   * application admin, and a CO admin may. Approve and deny follow
   * mayDecideRequest, and an unlisted action is denied.
   */
  public function testActionsAreAuthorizedPerUser() {
    $reqA = $this->pending('A', 'approval', array('t1'));

    $this->assertFalse($this->allowed('stranger', 'index'), 'a plain member has no queue');
    $this->assertTrue($this->allowed('approverA', 'index'));
    $this->assertTrue($this->allowed('inviter', 'index'), 'an application admin');
    $this->assertTrue($this->allowed('coAdmin', 'index'));

    foreach(array('approve', 'deny') as $action) {
      $this->assertTrue($this->allowed('approverA', $action, array($reqA)), "A approver $action");
      $this->assertTrue($this->allowed('coAdmin', $action, array($reqA)), "co admin $action");
      $this->assertFalse($this->allowed('approverB', $action, array($reqA)), "B approver $action");
      $this->assertFalse($this->allowed('stranger', $action, array($reqA)), "stranger $action");
    }

    $this->assertTrue($this->allowed('approverA', 'view', array($reqA)));
    $this->assertFalse($this->allowed('stranger', 'view', array($reqA)));

    foreach(array('add', 'edit', 'delete') as $action) {
      $this->assertFalse($this->allowed('coAdmin', $action, array($reqA)), "unlisted action $action");
    }
  }

  /**
   * Covers AE13, R25. With A and B having different approver groups, A's
   * approver sees only A's pending request and B's approver only B's; a CO
   * admin sees both. Requests that are not pending are never listed.
   */
  public function testQueueListsOnlyRequestsTheUserMayDecide() {
    $reqA = $this->pending('A', 'approval', array('t1'));
    $reqB = $this->pending('B', 'approval', array('ts'));
    $decided = $this->pending('A', 'approval', array('t2'));
    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'denied' WHERE id = " . $decided);

    $this->assertEqual(array($reqA), $this->queue('approverA'));
    $this->assertEqual(array($reqB), $this->queue('approverB'));
    $this->assertEqual(array($reqA, $reqB), $this->queue('coAdmin'));
    $this->assertEqual(array(), $this->queue('inviter'), 'an application admin decides only mismatches');
  }

  /**
   * R25, KTD6. Each entry shows the researcher and their CoPerson status, the
   * invited address, the login's emails, the mismatch flag, the inviting
   * admin, the application, and the offered teams.
   */
  public function testQueueEntryShowsR25FieldsAndResearcherStatus() {
    $this->fx->insert('cm_names', array('co_person_id' => $this->p['inviter'], 'given' => 'Ines',
                                        'family' => 'Inviter', 'type' => 'official', 'primary_name' => true,
                                        'name_id' => null, 'deleted' => false, 'revision' => 0));
    $this->fx->query("UPDATE cm_co_people SET status = 'S' WHERE id = " . (int)$this->p['p1']);

    $reqId = $this->pending('C', 'mismatch', array('t4', 'ts'), array(
      'identity_emails' => json_encode(array(self::Other, self::Third))
    ));

    $h = $this->harness('inviter');
    $h->harnessInvoke('index');

    $this->assertEqual(1, count($h->viewVars['vv_requests']));
    $e = $h->viewVars['vv_requests'][0];

    $this->assertEqual($reqId, $e['id']);
    $this->assertEqual(self::Invited, $e['invited_email']);
    $this->assertEqual(array(self::Other, self::Third), $e['identity_emails']);
    $this->assertTrue($e['mismatch']);
    $this->assertEqual('mismatch', $e['pending_reason']);
    $this->assertEqual((int)$this->p['inviter'], $e['inviter']['co_person_id']);
    $this->assertEqual('Ines Inviter', $e['inviter']['name']);
    $this->assertEqual((int)$this->app['C'], $e['application']['id']);
    $this->assertContains('App C', $e['application']['name']);
    $this->assertEqual(array('Team t4', 'Team ts'), $e['teams']);
    $this->assertEqual((int)$this->p['p1'], $e['researcher']['co_person_id']);
    $this->assertEqual('S', $e['researcher']['status'], 'KTD6: the CoPerson status is shown');
    $this->assertEqual('Pat Researcher', $e['responder_name']);
    $this->assertNull($e['link_target']);
    $this->assertEqual('inviting_admin', $e['deciding_role']);
  }

  /**
   * Covers AE18, R39, KTD17. A link_required request appears only in CO
   * admins' queues, with the person the login would be linked to. A CO
   * admin who is the link target does not see it and cannot decide it; nor
   * can the application's approver or its inviting admin.
   */
  public function testLinkRequiredOnlyInCoAdminQueuesAndNotForItsTarget() {
    $reqId = $this->pending('C', 'link_required', array('t4'), array(
      'invitee_co_person_id' => null,
      'link_target_co_person_id' => $this->p['p2']
    ));

    $this->assertEqual(array($reqId), $this->queue('coAdmin'));
    $this->assertEqual(array(), $this->queue('inviter'));
    $this->assertEqual(array(), $this->queue('approverA'));
    $this->assertEqual(array(), $this->queue('p2', true), 'the link target, even as a CO admin');

    $this->assertFalse($this->allowed('p2', 'approve', array($reqId), true));
    $this->assertFalse($this->allowed('inviter', 'approve', array($reqId)));

    $h = $this->harness('coAdmin');
    $h->harnessInvoke('index');
    $e = $h->viewVars['vv_requests'][0];
    $this->assertEqual((int)$this->p['p2'], $e['link_target']['co_person_id']);
    $this->assertEqual('A', $e['link_target']['status']);
    $this->assertNull($e['researcher']);
    $this->assertEqual('co_admin', $e['deciding_role']);
  }

  /**
   * R25, R39. A user who may not decide a request and posts a decision
   * anyway (the action run directly, past isAuthorized) is refused; the
   * request stays pending, no membership is written, nothing is emailed,
   * and its decider notification stays open.
   */
  public function testIneligibleDirectPostIsRefusedAndRequestStaysPending() {
    $reqId = $this->pending('A', 'approval', array('t1'));
    ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest')->notifyPending($this->coId, $reqId);

    foreach(array('approverB', 'stranger', 'p1') as $who) {
      foreach(array('approve', 'deny') as $action) {
        $h = $this->harness($who, array('AteEnrollmentRequest' => array('comment' => 'x')));
        $h->harnessInvoke($action, array($reqId), 'POST');

        $this->assertTrue($h->harnessStopped, "$who $action redirects");
        $this->assertTrue($this->flashed($h, 'error'), "$who $action is refused");
        $this->assertFalse($this->flashed($h, 'success'), "$who $action");
      }
    }

    $r = $this->requestRow($reqId);
    $this->assertEqual('pending_decision', $r['status']);
    $this->assertNull($r['decider_co_person_id']);
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])));
    $this->assertEqual(0, count(AteRecordingTransport::$sent));
    $this->assertEqual(NotificationStatusEnum::PendingResolution, $this->notifications($reqId)[0]['status']);
  }

  /**
   * Covers AE17, R18, R24. Request 1 has sat pending with no action from A's
   * approvers; a CO admin approves it from the queue, with a comment. The
   * inviting admin withdraws request 2, which becomes revoked. Both decider
   * notifications are resolved; the researcher is emailed only about the
   * decision, and without the comment.
   */
  public function testCoAdminDecidesIgnoredRequestAndInviterWithdrawsAnother() {
    $req1 = $this->pending('A', 'approval', array('t1'));
    $req2 = $this->pending('B', 'approval', array('ts'));
    $Req = ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest');
    $Req->notifyPending($this->coId, $req1);
    $Req->notifyPending($this->coId, $req2);

    $h = $this->harness('coAdmin', array('AteEnrollmentRequest' => array('comment' => 'Approved on behalf 4711')));
    $h->harnessInvoke('approve', array($req1), 'POST');

    $this->assertTrue($this->flashed($h, 'success'));
    $this->assertEqual('index', $h->harnessRedirect['action']);
    $r = $this->requestRow($req1);
    $this->assertEqual('approved', $r['status']);
    $this->assertEqual('co_admin', $r['decided_by_role']);
    $this->assertEqual((int)$this->p['coAdmin'], (int)$r['decider_co_person_id']);
    $this->assertEqual('Approved on behalf 4711', $r['comment']);
    $this->assertEqual(1, count($this->directRows('t1', $this->p['p1'])));
    $this->assertEqual(NotificationStatusEnum::Resolved, $this->notifications($req1)[0]['status']);

    $this->assertEqual(1, count(AteRecordingTransport::$sent));
    $this->assertEqual(array(self::Invited), AteRecordingTransport::$sent[0]['to']);
    $this->assertFalse(strpos(AteRecordingTransport::$sent[0]['body'], '4711') !== false);

    $w = AteDecisionWithdrawHarness::harnessBuild('ate_invitations', $this->coId, $this->roles('inviter'));
    $w->harnessInvoke('withdraw', array($req2), 'POST');

    $this->assertTrue($this->flashed($w, 'success'));
    $this->assertEqual('revoked', $this->requestRow($req2)['status']);
    $this->assertEqual((int)$this->p['inviter'], (int)$this->requestRow($req2)['withdrawn_by_co_person_id']);
    $n = $this->notifications($req2);
    $this->assertEqual(NotificationStatusEnum::Resolved, $n[0]['status']);
    $this->assertEqual((int)$this->p['inviter'], (int)$n[0]['resolver_co_person_id']);
    $this->assertEqual(1, count(AteRecordingTransport::$sent), 'a withdrawal emails no one');
  }

  /**
   * F3, R8. A's approver denies from the queue: the request records the
   * approver role and the comment, and a second post reports that the
   * request was already handled without a second email.
   */
  public function testApproverDeniesAndASecondPostIsAlreadyHandled() {
    $reqId = $this->pending('A', 'approval', array('t1'));

    $h = $this->harness('approverA', array('AteEnrollmentRequest' => array('comment' => 'Not on the project')));
    $h->harnessInvoke('deny', array($reqId), 'POST');

    $this->assertTrue($this->flashed($h, 'success'));
    $r = $this->requestRow($reqId);
    $this->assertEqual('denied', $r['status']);
    $this->assertEqual('approver', $r['decided_by_role']);
    $this->assertEqual('Not on the project', $r['comment']);
    $this->assertEqual(0, count($this->allRows('t1', $this->p['p1'])));
    $this->assertEqual(1, count(AteRecordingTransport::$sent));

    // The second post: isAuthorized() would refuse it, and the action does too
    $this->assertFalse($this->allowed('approverA', 'deny', array($reqId)));
    $h = $this->harness('approverA');
    $h->harnessInvoke('deny', array($reqId), 'POST');
    $this->assertTrue($this->flashed($h, 'error'));
    $this->assertEqual(1, count(AteRecordingTransport::$sent));
  }

  /**
   * Approve and deny change state, so they accept only POST.
   */
  public function testDecisionsRequirePost() {
    $reqId = $this->pending('A', 'approval', array('t1'));

    foreach(array('approve', 'deny') as $action) {
      $thrown = null;

      try {
        $this->harness('approverA')->harnessInvoke($action, array($reqId), 'GET');
      } catch(MethodNotAllowedException $e) {
        $thrown = $e;
      }

      $this->assertTrue($thrown instanceof MethodNotAllowedException, "$action by GET");
    }

    $this->assertEqual('pending_decision', $this->requestRow($reqId)['status']);
  }

  /**
   * The notification link opens one request with its decision form; once it
   * is no longer decidable by the user, the link returns to the queue with
   * an explanation.
   */
  public function testViewShowsADecidableRequestOrReturnsToTheQueue() {
    $reqId = $this->pending('A', 'approval', array('t1'));

    $h = $this->harness('approverA');
    $h->harnessInvoke('view', array($reqId));
    $this->assertFalse($h->harnessStopped);
    $this->assertEqual($reqId, $h->viewVars['vv_request']['id']);

    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'approved' WHERE id = " . $reqId);

    $h = $this->harness('approverA');
    $h->harnessInvoke('view', array($reqId));
    $this->assertTrue($h->harnessStopped);
    $this->assertEqual('index', $h->harnessRedirect['action']);
    $this->assertTrue($this->flashed($h, 'error'));
  }
}
