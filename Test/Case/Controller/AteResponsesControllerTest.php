<?php
/**
 * U8: the response pages (F2, R14, R17-R23, AE2, AE4, AE5, AE12, KTD4, KTD5,
 * KTD6, KTD14, KTD16).
 *
 * landing() is reachable without a login: it looks the token up by hash,
 * expires a lapsed invitation on access, explains an unusable link, and
 * otherwise keeps the token's hash in the session and sends the user to
 * respond(). respond() needs only a login: it re-validates the session's
 * token on every GET and POST, builds the identity snapshot into the
 * session, and commits a response only for the login the snapshot was built
 * for, with the form the snapshot was built for.
 *
 * Driven through Test/lib/AteControllerHarness.php without rendering, in the
 * engine world (Test/lib/AteEngineTestCase.php):
 *   A  approval required   teams T1, T2
 *   B  approval required   team  TS
 *   C  approval off        teams T4, TS   (TS is shared with B)
 *   D  approval off        team  T5
 * The login's email claim is set in $_SERVER, where Cake's env() reads it.
 */

App::uses('AteResponsesController', 'ApplicationTeamEnroller.Controller');
App::uses('AteInvitation', 'ApplicationTeamEnroller.Model');
App::uses('ComponentCollection', 'Controller');
App::uses('SessionComponent', 'Controller/Component');
App::uses('CakeSession', 'Model/Datasource');

class AteResponsesHarness extends AteResponsesController {
  use AteControllerHarness;

  public $Session = null;

  /** @var Array Arguments of each hand-off to the newcomer flow */
  public $harnessNewcomer = array();

  protected function redirectToNewcomerFlow($coId, $invitationId) {
    $this->harnessNewcomer[] = array('co_id' => (int)$coId, 'invitation_id' => (int)$invitationId);

    return parent::redirectToNewcomerFlow($coId, $invitationId);
  }
}

class AteResponsesControllerTest extends AteEngineTestCase {

  public function setUp() {
    parent::setUp();
    $this->resetRequestState();
    AteRecordingTransport::reset();
  }

  public function tearDown() {
    $this->resetRequestState();
    AteRecordingTransport::reset();
    ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest')->emailConfig = 'default';
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

  /** A harness with no CO roles at all: a first-time CILogon login has none. */
  private function harness($data = array()) {
    $h = AteResponsesHarness::harnessBuild('ate_responses', $this->coId, array(), $data);
    $h->Session = new SessionComponent(new ComponentCollection());
    $h->AteEnrollmentRequest->emailConfig = AteRecordingTransport::emailConfig();

    return $h;
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

  /** Follow the link. */
  private function land($token) {
    $h = $this->harness();
    $h->harnessInvoke('landing', array($token));

    return $h;
  }

  /** Open the respond page. */
  private function show() {
    $h = $this->harness();
    $h->harnessInvoke('respond', array(), 'GET');

    return $h;
  }

  /** Submit the respond form: $choices is application key => accept, $nonce from the page shown. */
  private function submit($inv, $choices, $nonce, $extra = array()) {
    $posted = array();

    foreach($choices as $appKey => $accept) {
      $posted[$inv['req'][$appKey]] = $accept ? '1' : '0';
    }

    $h = $this->harness(array('AteResponse' => $extra + array('nonce' => $nonce, 'choices' => $posted)));
    $h->harnessInvoke('respond', array(), 'POST');

    return $h;
  }

  /** Link, page, submit. Returns the submit harness. */
  private function respondTo($inv, $choices) {
    $this->land($inv['token']);
    $page = $this->show();
    $this->assertEqual('respond', $page->view, 'the respond page is shown');

    return $this->submit($inv, $choices, $page->viewVars['vv_nonce']);
  }

  /** The explanation reason, or null if the explanation page was not shown. */
  private function explained($h) {
    return ($h->view === 'explanation') ? $h->viewVars['vv_reason'] : null;
  }

  /** Whether $h redirected to this controller's $action. */
  private function redirectedTo($h, $action) {
    return is_array($h->harnessRedirect)
           && isset($h->harnessRedirect['action'])
           && $h->harnessRedirect['action'] === $action
           && $h->harnessRedirect['controller'] === 'ate_responses';
  }

  private function invStatus($inv) {
    return $this->invitationRow($inv['id'])['status'];
  }

  private function reqStatus($inv, $appKey) {
    return $this->requestRow($inv['req'][$appKey])['status'];
  }

  private function petitions() {
    return $this->fx->count('cm_co_petitions', 'co_id = ' . (int)$this->coId);
  }

  // ---------------------------------------------------------------------
  // landing()

  /** An unknown token explains itself and never reaches respond(). */
  public function testUnknownTokenShowsExplanation() {
    $h = $this->land(AteInvitation::generateToken());

    $this->assertEqual('unknown', $this->explained($h), 'unknown token explained');
    $this->assertNull($h->harnessRedirect, 'no redirect toward respond');
    $this->assertNull(CakeSession::read('ApplicationTeamEnroller.Response.token_hash'), 'nothing stored');

    $this->loginAs($this->sub('unknown'), array(self::Invited));
    $page = $this->show();

    $this->assertEqual('no_invitation', $this->explained($page), 'respond has no invitation to show');
    $this->assertFalse(isset($page->viewVars['vv_requests']), 'no applications shown');
  }

  /** A revoked invitation explains itself, also when revoked after the link was followed. */
  public function testRevokedTokenShowsExplanation() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')), array('status' => 'revoked'));

    $h = $this->land($inv['token']);
    $this->assertEqual('revoked', $this->explained($h), 'revoked explained');
    $this->assertNull(CakeSession::read('ApplicationTeamEnroller.Response.token_hash'), 'nothing stored');

    // Followed while live, revoked before the page or the submit
    $live = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('revoked'), array(self::Invited));
    $this->land($live['token']);
    $page = $this->show();
    $nonce = $page->viewVars['vv_nonce'];

    $this->fx->query("UPDATE cm_ate_invitations SET status = 'revoked' WHERE id = " . (int)$live['id']);
    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'revoked' WHERE ate_invitation_id = "
                     . (int)$live['id']);

    $this->assertEqual('revoked', $this->explained($this->show()), 'page re-validates');
    $this->assertEqual('revoked', $this->explained($this->submit($live, array('A' => false), $nonce)),
                       'submit re-validates');
    $this->assertEqual('revoked', $this->reqStatus($live, 'A'), 'nothing committed');
  }

  /** KTD14: an invitation past its expiry, before the job ran, is expired on access. */
  public function testLapsedInvitationExpiresOnAccess() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')),
                                  array('expires' => date('Y-m-d H:i:s', time() - 60)));

    $h = $this->land($inv['token']);

    $this->assertEqual('expired', $this->explained($h), 'expired explained');
    $this->assertEqual('expired', $this->invStatus($inv), 'invitation marked expired');
    $this->assertEqual('expired', $this->reqStatus($inv, 'A'), 'request A expired');
    $this->assertEqual('expired', $this->reqStatus($inv, 'C'), 'request C expired');
    $this->assertFalse((bool)$this->invitationRow($inv['id'])['expiry_notified'],
                       'left for the expiry job to notify the inviter');

    // A new invitation to the same address works normally (AE12)
    $again = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->assertTrue($this->redirectedTo($this->land($again['token']), 'respond'), 'new invitation works');
  }

  /** KTD14: a bound newcomer petition keeps the invitation live until 24 hours past expiry. */
  public function testBoundPetitionGraceWindow() {
    $flow = $this->fx->flow($this->coId, 'Newcomer ' . uniqid());
    $petition = $this->fx->insert('cm_co_petitions', array(
      'co_enrollment_flow_id' => $flow,
      'co_id' => $this->coId,
      'status' => 'PA'
    ));

    $inGrace = $this->tokenInvitation(self::Invited, array('A' => array('t1')), array(
      'expires' => date('Y-m-d H:i:s', time() - 3600),
      'co_petition_id' => $petition
    ));

    $h = $this->land($inGrace['token']);
    $this->assertTrue($this->redirectedTo($h, 'respond'), 'within the grace window the link still works');
    $this->assertEqual('sent', $this->invStatus($inGrace), 'not expired');
    $this->assertEqual('offered', $this->reqStatus($inGrace, 'A'), 'request still offered');

    $pastGrace = $this->tokenInvitation(self::Invited, array('A' => array('t1')), array(
      'expires' => date('Y-m-d H:i:s', time() - 25 * 3600),
      'co_petition_id' => $petition
    ));

    $this->assertEqual('expired', $this->explained($this->land($pastGrace['token'])), 'past the grace window');
    $this->assertEqual('expired', $this->invStatus($pastGrace), 'expired');
    $this->assertEqual('expired', $this->reqStatus($pastGrace, 'A'), 'request expired');
  }

  /** A live link stores only the token's hash and sends the user to respond(). */
  public function testLandingStoresTokenHashAndSendsToRespond() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));

    $h = $this->land($inv['token']);

    $this->assertTrue($this->redirectedTo($h, 'respond'), 'sent to respond');
    $this->assertEqual(AteInvitation::hashToken($inv['token']),
                       CakeSession::read('ApplicationTeamEnroller.Response.token_hash'), 'hash stored');
    $this->assertFalse(strpos(json_encode(CakeSession::read('ApplicationTeamEnroller')), $inv['token']) !== false,
                       'the raw token is not stored in the session');
    $this->assertEqual('sent', $this->invStatus($inv), 'following the link changes nothing');
  }

  // ---------------------------------------------------------------------
  // respond(): authorization and the page

  /** KTD16: a login with no CoPerson and no `user` role may respond; no login may not. */
  public function testRespondAdmitsLoginWithoutCoPersonOrUserRole() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1', 't2'), 'C' => array('t4')));

    $authorized = function($action) {
      $h = $this->harness();
      $h->action = $action;
      $h->request->params['action'] = $action;

      return (bool)$h->isAuthorized();
    };

    $this->assertTrue($authorized('landing'), 'landing needs nothing');
    $this->assertFalse($authorized('respond'), 'respond needs a login');
    $this->assertFalse($authorized('confirmation'), 'confirmation needs a login');

    $sub = $this->sub('first-time');
    $this->loginAs($sub, array(self::Invited));

    $this->assertTrue($authorized('respond'), 'a first-time login may respond');
    $this->assertTrue($authorized('confirmation'), 'and see its confirmation');
    $this->assertFalse($authorized('index'), 'no other action');

    $this->land($inv['token']);
    $page = $this->show();

    $this->assertEqual('respond', $page->view, 'the respond page is shown');
    $this->assertEqual(2, count($page->viewVars['vv_requests']), 'each offered application');

    $names = array();
    foreach($page->viewVars['vv_requests'] as $r) {
      $names[$r['id']] = $r['teams'];
    }
    $this->assertEqual(array('Team t1', 'Team t2'), $names[$inv['req']['A']], 'with its teams');

    $snap = CakeSession::read('ApplicationTeamEnroller.Response.snapshot');
    $this->assertEqual($sub, $snap['snapshot']['identifier'], 'snapshot built into the session');
    $this->assertEqual('eppn', $snap['snapshot']['identifier_type'], 'with the configured type');
    $this->assertEqual(array(self::Invited), $snap['snapshot']['emails'], 'and the claimed email');
    $this->assertNull($this->invitationRow($inv['id'])['responder_identifier'],
                      'the snapshot reaches the invitation only on commit');
  }

  // ---------------------------------------------------------------------
  // respond(): committing

  /** AE4: an existing member's accept is committed with no petition and no new CoPerson. */
  public function testExistingMemberAcceptCommits() {
    $sub = $this->sub('member');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $before = $this->peopleAndOrgIdentities();
    $petitions = $this->petitions();

    $this->loginAs($sub, array(self::Invited));
    $h = $this->respondTo($inv, array('A' => true));

    $this->assertTrue($this->redirectedTo($h, 'confirmation'), 'confirmation shown');
    $this->assertEqual('responded', $this->invStatus($inv), 'committed');
    $this->assertEqual($this->p['p1'], (int)$this->invitationRow($inv['id'])['invitee_co_person_id'],
                       'recorded against the member');
    $this->assertEqual('pending_decision', $this->reqStatus($inv, 'A'), 'A awaits its approver');
    $this->assertEqual(array(), $this->directRows('t1', $this->p['p1']), 'no membership before approval');
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'no CoPerson or OrgIdentity created');
    $this->assertEqual($petitions, $this->petitions(), 'no petition started');
    $this->assertEqual(array(), $h->harnessNewcomer, 'not handed to the newcomer flow');
    $this->assertTrue($this->fx->count('cm_co_notifications',
                        'recipient_co_group_id = ' . (int)$this->g['approversA']) >= 1,
                      'afterResponse notified the approvers');
    $this->assertNull(CakeSession::read('ApplicationTeamEnroller.Response.snapshot'), 'snapshot cleared');
  }

  /** AE5 (decline half): a newcomer who declines everything is committed and no CoPerson exists. */
  public function testNewcomerDeclineAllCommitsWithoutPerson() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $before = $this->peopleAndOrgIdentities();
    $petitions = $this->petitions();

    $sub = $this->sub('newcomer-decline');
    $this->loginAs($sub, array(self::Invited));
    $h = $this->respondTo($inv, array('A' => false, 'C' => false));

    $this->assertTrue($this->redirectedTo($h, 'confirmation'), 'confirmation shown');
    $this->assertEqual('responded', $this->invStatus($inv), 'committed');
    $this->assertEqual('declined_by_enrollee', $this->reqStatus($inv, 'A'), 'A declined');
    $this->assertEqual('declined_by_enrollee', $this->reqStatus($inv, 'C'), 'C declined');
    $this->assertNull($this->invitationRow($inv['id'])['invitee_co_person_id'], 'no responder person');
    $this->assertEqual($sub, $this->invitationRow($inv['id'])['responder_identifier'], 'login recorded');
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'no CoPerson or OrgIdentity created');
    $this->assertEqual($petitions, $this->petitions(), 'no petition started');
    $this->assertEqual(array(), $h->harnessNewcomer, 'not handed to the newcomer flow');
  }

  /** R21, KTD11: a newcomer who accepts anything gets a draft and is handed to the newcomer flow. */
  public function testNewcomerAcceptSavesDraftAndHandsOff() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $before = $this->peopleAndOrgIdentities();

    $sub = $this->sub('newcomer-accept');
    $this->loginAs($sub, array(self::Invited));
    $h = $this->respondTo($inv, array('A' => true, 'C' => false));

    $this->assertEqual(array(array('co_id' => $this->coId, 'invitation_id' => $inv['id'])), $h->harnessNewcomer,
                       'handed to the newcomer flow once');
    $this->assertEqual('sent', $this->invStatus($inv), 'not committed: the link can be used again');
    $this->assertEqual('offered', $this->reqStatus($inv, 'A'), 'A still offered');
    $this->assertEqual('offered', $this->reqStatus($inv, 'C'), 'C still offered');
    $this->assertTrue((bool)$this->requestRow($inv['req']['A'])['draft_choice'], 'A drafted as accepted');
    $this->assertFalse((bool)$this->requestRow($inv['req']['C'])['draft_choice'], 'C drafted as declined');
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'no CoPerson created here');

    $binding = CakeSession::read('ApplicationTeamEnroller.Newcomer');
    $this->assertEqual($inv['id'], $binding['invitation_id'], 'session bound to the invitation');
    $this->assertEqual($this->coId, $binding['co_id'], 'and the CO');
    $this->assertEqual($sub, $binding['identifier'], 'and the login');
    $this->assertEqual($sub, $binding['snapshot']['identifier'], 'with the snapshot');

    // The link can be used again, and a new answer replaces the draft
    $h = $this->respondTo($inv, array('A' => false, 'C' => true));
    $this->assertFalse((bool)$this->requestRow($inv['req']['A'])['draft_choice'], 'A redrafted');
    $this->assertTrue((bool)$this->requestRow($inv['req']['C'])['draft_choice'], 'C redrafted');
  }

  /** R21: invited address on an existing CoPerson, login linked to no one: committed, no CoPerson created. */
  public function testLinkRequiredResponderCommitsWithoutPerson() {
    $this->fx->emailAddress(self::Invited, array('co_person_id' => $this->p['p2'], 'verified' => true));
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $before = $this->peopleAndOrgIdentities();

    $this->loginAs($this->sub('link'), array(self::Invited));
    $h = $this->respondTo($inv, array('A' => true));

    $this->assertTrue($this->redirectedTo($h, 'confirmation'), 'confirmation shown');
    $this->assertEqual(array(), $h->harnessNewcomer, 'not handed to the newcomer flow');
    $this->assertEqual('responded', $this->invStatus($inv), 'committed');
    $this->assertEqual('pending_decision', $this->reqStatus($inv, 'A'), 'pending');
    $this->assertEqual('link_required', $this->requestRow($inv['req']['A'])['pending_reason'], 'link required');
    $this->assertEqual($this->p['p2'], (int)$this->invitationRow($inv['id'])['link_target_co_person_id'],
                       'link target recorded');
    $this->assertEqual($before, $this->peopleAndOrgIdentities(), 'no CoPerson or OrgIdentity created');
  }

  /** AE12: after a response the same link shows the explanation page. */
  public function testAnsweredLinkShowsExplanation() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('answered'), array(self::Invited));
    $this->respondTo($inv, array('A' => false));

    $this->assertEqual('answered', $this->explained($this->land($inv['token'])), 'answered explained');
  }

  /** KTD8: a double-submitted response commits once; the second sees "already answered". */
  public function testDoubleSubmitCommitsOnce() {
    $sub = $this->sub('double');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $inv = $this->tokenInvitation(self::Invited, array('D' => array('t5')));
    $this->loginAs($sub, array(self::Invited));

    $this->land($inv['token']);
    $nonce = $this->show()->viewVars['vv_nonce'];

    $first = $this->submit($inv, array('D' => true), $nonce);
    $this->assertTrue($this->redirectedTo($first, 'confirmation'), 'first commits');
    $this->assertEqual('approved', $this->reqStatus($inv, 'D'), 'approved automatically');
    $this->assertEqual(1, count(AteRecordingTransport::$sent), 'one decision email');

    $second = $this->submit($inv, array('D' => true), $nonce);
    $this->assertEqual('answered', $this->explained($second), 'second sees already answered');
    $this->assertEqual(1, count(AteRecordingTransport::$sent), 'announced once');
    $this->assertEqual(1, count($this->directRows('t5', $this->p['p1'])), 'one membership');
  }

  /** R22, KTD5: an unverified OrgIdentity email equal to the invited address still yields a mismatch. */
  public function testUnverifiedEmailYieldsMismatch() {
    $sub = $this->sub('unverified');
    $this->login($this->p['p1'], $sub, array(), array(self::Invited));
    $inv = $this->tokenInvitation(self::Invited, array('C' => array('t4')));

    $this->loginAs($sub, array(self::Other));
    $this->respondTo($inv, array('C' => true));

    $row = $this->invitationRow($inv['id']);
    $this->assertTrue((bool)$row['mismatch'], 'flagged as a mismatch');
    $this->assertEqual(array(self::Other), json_decode($row['identity_emails'], true),
                       'only the claimed address counted');
    $this->assertEqual('pending_decision', $this->reqStatus($inv, 'C'), 'not approved automatically');
    $this->assertEqual('mismatch', $this->requestRow($inv['req']['C'])['pending_reason'], 'pending for the mismatch');

    // R19: the confirmation page says nothing about the mismatch
    $conf = $this->harness();
    $conf->harnessInvoke('confirmation');
    $this->assertEqual('confirmation', $conf->view, 'confirmation page');
    $this->assertFalse(stripos(json_encode($conf->viewVars), 'mismatch') !== false, 'no mismatch details');
  }

  /** KTD5: login A opens the page, login B opens the same link; A's submit never commits as B. */
  public function testLoginSwitchCannotCommitAgainstOtherIdentity() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $subA = $this->sub('login-a');
    $subB = $this->sub('login-b');

    // A opens the page, then the session's login becomes B without a new page
    $this->loginAs($subA, array(self::Invited));
    $this->land($inv['token']);
    $nonceA = $this->show()->viewVars['vv_nonce'];

    $this->loginAs($subB, array(self::Other));
    $h = $this->submit($inv, array('A' => false), $nonceA);
    $this->assertTrue($this->redirectedTo($h, 'respond'), 'refused and sent back to the page');
    $this->assertEqual('sent', $this->invStatus($inv), 'nothing committed');

    // B opens the same link; A's stale form is refused
    $this->land($inv['token']);
    $nonceB = $this->show()->viewVars['vv_nonce'];
    $this->assertFalse($nonceA === $nonceB, 'a new form for B');

    $h = $this->submit($inv, array('A' => false), $nonceA);
    $this->assertTrue($this->redirectedTo($h, 'respond'), 'A\'s form refused');
    $this->assertEqual('sent', $this->invStatus($inv), 'nothing committed');

    // B's own form commits B's identity only
    $h = $this->submit($inv, array('A' => false), $nonceB);
    $this->assertTrue($this->redirectedTo($h, 'confirmation'), 'B commits');
    $row = $this->invitationRow($inv['id']);
    $this->assertEqual($subB, $row['responder_identifier'], 'B\'s login recorded');
    $this->assertEqual(array(self::Other), json_decode($row['identity_emails'], true), 'B\'s emails recorded');
  }

  /** A logged-in user posting another invitation's id without a valid token is refused. */
  public function testPostWithoutValidTokenIsRefused() {
    $mine = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $other = $this->tokenInvitation(self::Third, array('C' => array('t4')));
    $this->loginAs($this->sub('forger'), array(self::Third));

    // No link followed at all
    $h = $this->submit($other, array('C' => false), 'x', array('invitation_id' => $other['id']));
    $this->assertEqual('no_invitation', $this->explained($h), 'refused without a token');
    $this->assertEqual('sent', $this->invStatus($other), 'other invitation untouched');

    // Own link followed, other invitation's requests posted
    $this->land($mine['token']);
    $nonce = $this->show()->viewVars['vv_nonce'];
    $h = $this->submit($other, array('C' => false), $nonce, array('invitation_id' => $other['id']));

    $this->assertEqual('respond', $h->view, 'the page is shown again');
    $this->assertEqual('sent', $this->invStatus($other), 'other invitation untouched');
    $this->assertEqual('offered', $this->reqStatus($other, 'C'), 'its request untouched');
    $this->assertEqual('sent', $this->invStatus($mine), 'own invitation not committed either');
  }

  /** Every offered application needs an answer. */
  public function testEveryApplicationNeedsAnAnswer() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $this->loginAs($this->sub('partial'), array(self::Invited));

    $h = $this->respondTo($inv, array('A' => false));

    $this->assertEqual('respond', $h->view, 'the page is shown again');
    $this->assertNotEmpty($h->Flash->last(), 'with an error');
    $this->assertEqual('sent', $this->invStatus($inv), 'nothing committed');
  }

  // ---------------------------------------------------------------------
  // confirmation()

  /** R19, AE2: approved, awaiting a decision, and declined, and never "mismatch". */
  public function testConfirmationShowsEachState() {
    $sub = $this->sub('confirm');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1'), 'B' => array('ts'), 'D' => array('t5')));
    $this->loginAs($sub, array(self::Invited));

    $this->respondTo($inv, array('A' => true, 'B' => false, 'D' => true));
    $this->assertEqual(1, count(AteRecordingTransport::$sent), 'one email for the automatic approval (AE2)');

    $h = $this->harness();
    $h->harnessInvoke('confirmation');
    $this->assertEqual('confirmation', $h->view, 'confirmation page');

    $states = array();
    foreach($h->viewVars['vv_requests'] as $r) {
      $states[$r['id']] = $r['state'];
    }

    $this->assertEqual(_txt('pl.applicationteamenroller.response.state.pending_decision'),
                       $states[$inv['req']['A']], 'A awaiting a decision');
    $this->assertEqual(_txt('pl.applicationteamenroller.response.state.declined_by_enrollee'),
                       $states[$inv['req']['B']], 'B declined');
    $this->assertEqual(_txt('pl.applicationteamenroller.response.state.approved'),
                       $states[$inv['req']['D']], 'D approved');
    $this->assertContains('awaiting a decision', strtolower($states[$inv['req']['A']]), 'wording');
    $this->assertFalse(stripos(json_encode($h->viewVars), 'mismatch') !== false, 'no mismatch in the page data');

    $ctp = file_get_contents(App::pluginPath('ApplicationTeamEnroller') . 'View' . DS . 'AteResponses'
                             . DS . 'confirmation.ctp');
    $this->assertFalse(stripos($ctp, 'mismatch') !== false || stripos($ctp, 'pending_reason') !== false,
                       'the confirmation view never mentions a mismatch');
  }

  /** The confirmation page shows only a response this session committed. */
  public function testConfirmationNeedsOwnResponse() {
    $this->loginAs($this->sub('nosy'), array(self::Invited));
    $h = $this->harness();
    $h->harnessInvoke('confirmation');

    $this->assertEqual('no_invitation', $this->explained($h), 'nothing to confirm');
  }
}
