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

class ApplicationTeamEnrollerCoPetitionsControllerTest extends AtePetitionTestCase {

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
