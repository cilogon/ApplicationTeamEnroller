<?php
/**
 * U6: composing, sending, revoking, and withdrawing invitations (R7, R9-R18,
 * F1, AE1, AE2, AE12, KTD4, KTD8, KTD13), at the model level.
 *
 * Email goes through CakeEmail with the recording transport in
 * Test/lib/AteMailTransport.php. Router::url() has no host in the console,
 * so link checks assert on the path and token only.
 */

class AteInvitationTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  /** @var AteInvitation */
  protected $Invitation = null;

  protected $coId = null;
  protected $otherCoId = null;

  // Fixture ids by short name
  protected $p = array();
  protected $g = array();
  protected $app = array();
  protected $team = array();

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u6-inv');

    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    foreach(array('x', 'y') as $name) {
      $this->p[$name] = $this->fx->person($this->coId);
    }

    foreach(array('adminsA', 'adminsB', 'approvers', 't1', 't2', 't3', 't4', 'tr') as $name) {
      $this->g[$name] = $this->fx->group($this->coId, $name . ' ' . $tag);
    }

    // A and C are administered by adminsA, B by adminsB
    $groups = array('admin_co_group_id' => $this->g['adminsA'], 'approver_co_group_id' => $this->g['approvers']);
    $this->app['A'] = $this->fx->application($this->coId, 'App A ' . $tag, $groups);
    $this->app['C'] = $this->fx->application($this->coId, 'App C ' . $tag, $groups);
    $this->app['B'] = $this->fx->application($this->coId, 'App B ' . $tag,
      array('admin_co_group_id' => $this->g['adminsB']) + $groups);

    foreach(array('t1', 't2', 't3', 't4') as $name) {
      $this->team[$name] = $this->fx->researchTeam($this->g[$name], array('name' => 'Team ' . $name));
    }
    $this->team['tr'] = $this->fx->researchTeam($this->g['tr'], array('name' => 'Team tr', 'status' => 'retired'));

    // A authorizes T1, T2 and the retired team; B authorizes T3; C authorizes T4
    $this->fx->applicationTeam($this->app['A'], $this->team['t1']);
    $this->fx->applicationTeam($this->app['A'], $this->team['t2']);
    $this->fx->applicationTeam($this->app['A'], $this->team['tr']);
    $this->fx->applicationTeam($this->app['B'], $this->team['t3']);
    $this->fx->applicationTeam($this->app['C'], $this->team['t4']);

    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $this->fx->insert('cm_ate_settings', array(
      'co_id' => $this->coId,
      'invitation_lifetime_days' => 7,
      'email_subject' => 'Join (@CO_NAME)',
      'email_body' => $Setting->defaults($this->coId)['email_body'],
      'revision' => 0,
      'deleted' => false,
      'ate_setting_id' => null
    ));

    $this->Invitation = $this->model('ApplicationTeamEnroller.AteInvitation');
    $this->Invitation->emailConfig = AteRecordingTransport::emailConfig();
    AteRecordingTransport::reset();
  }

  public function tearDown() {
    AteRecordingTransport::reset();

    if($this->Invitation) {
      $this->Invitation->emailConfig = 'default';
    }

    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /** The applications admin X may invite for: A and C. */
  private function permittedForX() {
    return array($this->app['A'], $this->app['C']);
  }

  /** Rows of the invitation tables for the test CO. */
  private function countRows() {
    $inv = 'SELECT id FROM cm_ate_invitations WHERE co_id = ' . $this->coId;
    $req = 'SELECT id FROM cm_ate_enrollment_requests WHERE ate_invitation_id IN (' . $inv . ')';

    return array(
      'invitations' => $this->fx->count('cm_ate_invitations', 'co_id = ' . $this->coId),
      'requests' => $this->fx->count('cm_ate_enrollment_requests', 'ate_invitation_id IN (' . $inv . ')'),
      'teams' => $this->fx->count('cm_ate_enrollment_request_teams', 'ate_enrollment_request_id IN (' . $req . ')')
    );
  }

  /** Assert that $fn throws $class and that nothing was recorded or sent. */
  private function assertRejected($fn, $class, $label) {
    $before = $this->countRows();
    $thrown = null;

    try {
      $fn();
    } catch(Exception $e) {
      $thrown = $e;
    }

    $this->assertTrue($thrown instanceof $class,
      "$label: expected $class, got " . ($thrown ? get_class($thrown) . ': ' . $thrown->getMessage() : 'no exception'));
    $this->assertEqual($before, $this->countRows(), "$label: no rows may be written");
    $this->assertEqual(0, count(AteRecordingTransport::$sent), "$label: no email may be sent");
    $this->assertFalse(ConnectionManager::getDataSource('default')->inTransaction(),
      "$label: the transaction must be closed");

    return $thrown;
  }

  /** The token in the one link of an email body. */
  private function tokenFrom($body) {
    $m = array();
    $this->assertTrue((bool)preg_match('#/application_team_enroller/ate_responses/landing/([0-9a-f]+)#', $body, $m),
      'the email must carry a response link: ' . $body);
    $this->assertEqual(1, substr_count($body, '/ate_responses/landing/'), 'exactly one link (R15)');

    return $m[1];
  }

  /**
   * Covers AE2, R7, R10, R14, R15, KTD4. An invitation for A, B, and C
   * creates one sent invitation, three offered requests with their teams, and
   * one email whose link carries a token whose SHA-256 is the stored hash.
   * No CoPerson, OrgIdentity, or petition is created.
   */
  public function testCreateAndSendRecordsRequestsAndSendsOneEmail() {
    $email = 'researcher@uni.example.org';
    $people = $this->fx->count('cm_co_people', 'true');
    $orgs = $this->fx->count('cm_org_identities', 'true');
    $petitions = $this->fx->count('cm_co_petitions', 'true');

    $selections = array(
      $this->app['A'] => array($this->team['t1'], $this->team['t2']),
      $this->app['B'] => array($this->team['t3']),
      $this->app['C'] => array($this->team['t4'])
    );

    $before = time();
    $id = $this->Invitation->createAndSend($this->coId, $this->p['x'], $email, $selections,
                                           array($this->app['A'], $this->app['B'], $this->app['C']));
    $this->assertTrue($id > 0, 'createAndSend returns the invitation id');

    $inv = $this->fx->rows('SELECT * FROM cm_ate_invitations WHERE id = ' . (int)$id);
    $this->assertEqual(1, count($inv));
    $inv = $inv[0];
    $this->assertEqual($this->coId, (int)$inv['co_id']);
    $this->assertEqual($this->p['x'], (int)$inv['inviter_co_person_id']);
    $this->assertEqual($email, $inv['invited_email']);
    $this->assertEqual('sent', $inv['status']);
    $this->assertNull($inv['invitee_co_person_id']);

    // Expiry follows the CO's lifetime setting (7 days)
    $expires = strtotime($inv['expires'] . ' UTC');
    $this->assertTrue(abs($expires - ($before + 7 * 86400)) < 120, 'expires 7 days out: ' . $inv['expires']);

    // One offered request per application, each with exactly its teams
    $reqs = $this->fx->rows('SELECT * FROM cm_ate_enrollment_requests WHERE ate_invitation_id = ' . (int)$id
                            . ' ORDER BY ate_application_id');
    $this->assertEqual(3, count($reqs));
    foreach($reqs as $r) {
      $this->assertEqual('offered', $r['status']);
      $teams = array_map('intval', array_column($this->fx->rows(
        'SELECT ate_research_team_id FROM cm_ate_enrollment_request_teams WHERE ate_enrollment_request_id = '
        . (int)$r['id'] . ' ORDER BY ate_research_team_id'), 'ate_research_team_id'));
      $expected = $selections[(int)$r['ate_application_id']];
      sort($expected);
      $this->assertEqual($expected, $teams, 'teams of application ' . $r['ate_application_id']);
    }

    // Exactly one email, to the invited address, with one link
    $this->assertEqual(1, count(AteRecordingTransport::$sent));
    $mail = AteRecordingTransport::$sent[0];
    $this->assertEqual(array($email), $mail['to']);
    $this->assertContains('CO ', $mail['subject'], 'the subject substitutes the CO name');
    $this->assertFalse(strpos($mail['subject'] . $mail['body'], '(@') !== false, 'every placeholder is replaced');
    foreach(array('A', 'B', 'C') as $a) {
      $this->assertContains('App ' . $a, $mail['body'], 'the body lists application ' . $a);
    }
    $this->assertContains('Team t1', $mail['body'], 'the body lists the offered teams');

    // KTD4: 32 random bytes, only the hash is stored
    $token = $this->tokenFrom($mail['body']);
    $this->assertEqual(64, strlen($token), 'the token is 32 bytes, hex encoded');
    $this->assertEqual(hash('sha256', $token), $inv['token_hash']);
    $this->assertEqual($inv['token_hash'], AteInvitation::hashToken($token));
    $this->assertFalse(strpos(json_encode($inv), $token) !== false, 'the raw token is not stored');

    // R14
    $this->assertEqual($people, $this->fx->count('cm_co_people', 'true'), 'no CoPerson is created');
    $this->assertEqual($orgs, $this->fx->count('cm_org_identities', 'true'), 'no OrgIdentity is created');
    $this->assertEqual($petitions, $this->fx->count('cm_co_petitions', 'true'), 'no petition is created');
  }

  /**
   * R11: an application outside the sender's permitted set is rejected by
   * the server even though the form never offered it. So is an application
   * of another CO or a retired one.
   */
  public function testRejectsApplicationTheSenderMayNotInviteFor() {
    $inv = $this->Invitation;
    $coId = $this->coId;
    $x = $this->p['x'];

    $this->assertRejected(function() use ($inv, $coId, $x) {
      $inv->createAndSend($coId, $x, 'r@example.org', array(
        $this->app['A'] => array($this->team['t1']),
        $this->app['B'] => array($this->team['t3'])
      ), $this->permittedForX());
    }, 'InvalidArgumentException', 'non-administered application');

    // Even when the caller's permitted list names it, a retired application
    // or one of another CO is not invitable.
    $retired = $this->fx->application($this->coId, 'Retired app', array('status' => 'retired'));
    $this->fx->applicationTeam($retired, $this->team['t1']);
    $foreign = $this->fx->application($this->otherCoId, 'Foreign app');

    foreach(array('retired' => $retired, 'foreign' => $foreign) as $label => $appId) {
      $this->assertRejected(function() use ($inv, $coId, $x, $appId) {
        $inv->createAndSend($coId, $x, 'r@example.org', array($appId => array($this->team['t1'])), array($appId));
      }, 'InvalidArgumentException', "$label application");
    }
  }

  /**
   * R12: a team the application does not authorize is rejected, as is a
   * retired team that is still mapped, and an application with no team.
   */
  public function testRejectsTeamTheApplicationDoesNotAuthorize() {
    $inv = $this->Invitation;
    $coId = $this->coId;
    $x = $this->p['x'];

    $cases = array(
      'team of another application' => array($this->team['t1'], $this->team['t3']),
      'retired team' => array($this->team['tr']),
      'no team' => array(),
      'unknown team' => array(999999999)
    );

    foreach($cases as $label => $teams) {
      $this->assertRejected(function() use ($inv, $coId, $x, $teams) {
        $inv->createAndSend($coId, $x, 'r@example.org', array($this->app['A'] => $teams), $this->permittedForX());
      }, 'InvalidArgumentException', $label);
    }

    // No application at all, and an invalid address
    $this->assertRejected(function() use ($inv, $coId, $x) {
      $inv->createAndSend($coId, $x, 'r@example.org', array(), $this->permittedForX());
    }, 'InvalidArgumentException', 'no application');

    $this->assertRejected(function() use ($inv, $coId, $x) {
      $inv->createAndSend($coId, $x, 'not an address', array($this->app['A'] => array($this->team['t1'])),
                          $this->permittedForX());
    }, 'InvalidArgumentException', 'invalid address');
  }

  /**
   * KTD13: a mail transport failure rolls back the invitation, its requests,
   * and their teams, so no unreachable invitation exists.
   */
  public function testMailFailureLeavesNoRows() {
    AteRecordingTransport::$fail = true;

    $inv = $this->Invitation;
    $coId = $this->coId;
    $x = $this->p['x'];

    $this->assertRejected(function() use ($inv, $coId, $x) {
      $inv->createAndSend($coId, $x, 'r@example.org', array(
        $this->app['A'] => array($this->team['t1']),
        $this->app['C'] => array($this->team['t4'])
      ), $this->permittedForX());
    }, 'RuntimeException', 'mail failure');

    $this->assertEqual(array('invitations' => 0, 'requests' => 0, 'teams' => 0), $this->countRows());
  }

  /**
   * Covers AE12, R18, KTD8. Revoking a sent invitation records who revoked it
   * and when and marks its offered requests revoked; a second revoke reports
   * that it was already handled and changes nothing.
   */
  public function testRevokeIsAConditionalTransitionFromSent() {
    $id = $this->Invitation->createAndSend($this->coId, $this->p['x'], 'r@example.org', array(
      $this->app['A'] => array($this->team['t1']),
      $this->app['C'] => array($this->team['t4'])
    ), $this->permittedForX());

    $this->assertTrue($this->Invitation->revoke($this->coId, $id, $this->p['x']), 'the first revoke wins');

    $inv = $this->fx->rows('SELECT * FROM cm_ate_invitations WHERE id = ' . (int)$id)[0];
    $this->assertEqual('revoked', $inv['status']);
    $this->assertEqual($this->p['x'], (int)$inv['revoked_by_co_person_id']);
    $this->assertNotEmpty($inv['revoked_at']);
    $this->assertEqual(2, $this->fx->count('cm_ate_enrollment_requests',
      'ate_invitation_id = ' . (int)$id . " AND status = 'revoked'"));

    // Second revoke, by someone else: already handled, nothing changes
    $this->assertFalse($this->Invitation->revoke($this->coId, $id, $this->p['y']), 'a second revoke is already handled');
    $this->assertEqual($this->p['x'], (int)$this->fx->scalar(
      'SELECT revoked_by_co_person_id FROM cm_ate_invitations WHERE id = ' . (int)$id));

    // A responded invitation cannot be revoked, and only offered requests move
    $other = $this->fx->invitation($this->coId, array('status' => 'responded'));
    $req = $this->fx->enrollmentRequest($other, $this->app['A'], array('status' => 'pending_decision'));
    $this->assertFalse($this->Invitation->revoke($this->coId, $other, $this->p['x']));
    $this->assertEqual('pending_decision', $this->fx->scalar(
      'SELECT status FROM cm_ate_enrollment_requests WHERE id = ' . $req));

    // An invitation of another CO is not found from this CO
    $foreign = $this->fx->invitation($this->otherCoId);
    $this->assertFalse($this->Invitation->revoke($this->coId, $foreign, $this->p['x']));
    $this->assertEqual('sent', $this->fx->scalar('SELECT status FROM cm_ate_invitations WHERE id = ' . $foreign));
  }

  /**
   * R16, AE12. Two live invitations to the same address coexist with
   * distinct tokens, and revoking one leaves the other live.
   */
  public function testTwoLiveInvitationsToTheSameAddressCoexist() {
    $sel = array($this->app['A'] => array($this->team['t1']));
    $first = $this->Invitation->createAndSend($this->coId, $this->p['x'], 'same@example.org', $sel, $this->permittedForX());
    $second = $this->Invitation->createAndSend($this->coId, $this->p['x'], 'same@example.org', $sel, $this->permittedForX());

    $this->assertTrue($first !== $second);
    $this->assertEqual(2, $this->fx->count('cm_ate_invitations',
      "co_id = " . $this->coId . " AND invited_email = 'same@example.org' AND status = 'sent'"));
    $this->assertEqual(2, count(AteRecordingTransport::$sent));

    $t1 = $this->tokenFrom(AteRecordingTransport::$sent[0]['body']);
    $t2 = $this->tokenFrom(AteRecordingTransport::$sent[1]['body']);
    $this->assertFalse($t1 === $t2, 'each invitation has its own token');

    $this->assertTrue($this->Invitation->revoke($this->coId, $first, $this->p['x']));
    $this->assertEqual('sent', $this->fx->scalar('SELECT status FROM cm_ate_invitations WHERE id = ' . (int)$second),
      'revoking one invitation leaves the other live');
  }

  /**
   * R18, AE17, KTD8. Withdraw moves a single pending_decision request to
   * revoked and records who withdrew it; a second withdraw, a request in
   * another state, and a request of another CO are refused.
   */
  public function testWithdrawMovesOnlyAPendingDecisionRequest() {
    $inv = $this->fx->invitation($this->coId, array('status' => 'responded', 'inviter_co_person_id' => $this->p['x']));
    $pending = $this->fx->enrollmentRequest($inv, $this->app['A'], array('status' => 'pending_decision',
                                                                          'pending_reason' => 'approval'));
    $sibling = $this->fx->enrollmentRequest($inv, $this->app['C'], array('status' => 'pending_decision',
                                                                          'pending_reason' => 'approval'));
    $offered = $this->fx->enrollmentRequest($this->fx->invitation($this->coId), $this->app['A']);

    $this->assertTrue($this->Invitation->withdrawRequest($this->coId, $pending, $this->p['x']));
    $row = $this->fx->rows('SELECT * FROM cm_ate_enrollment_requests WHERE id = ' . $pending)[0];
    $this->assertEqual('revoked', $row['status']);
    $this->assertEqual($this->p['x'], (int)$row['withdrawn_by_co_person_id']);
    $this->assertEqual('pending_decision', $this->fx->scalar(
      'SELECT status FROM cm_ate_enrollment_requests WHERE id = ' . $sibling), 'only the one request moves (R30)');

    $this->assertFalse($this->Invitation->withdrawRequest($this->coId, $pending, $this->p['y']), 'already handled');
    $this->assertFalse($this->Invitation->withdrawRequest($this->coId, $offered, $this->p['x']), 'not pending');
    $this->assertEqual('offered', $this->fx->scalar('SELECT status FROM cm_ate_enrollment_requests WHERE id = ' . $offered));

    $foreign = $this->fx->enrollmentRequest($this->fx->invitation($this->otherCoId), $this->app['A'],
                                            array('status' => 'pending_decision'));
    $this->assertFalse($this->Invitation->withdrawRequest($this->coId, $foreign, $this->p['x']), 'another CO');
    $this->assertEqual('pending_decision', $this->fx->scalar(
      'SELECT status FROM cm_ate_enrollment_requests WHERE id = ' . $foreign));
  }

  /**
   * Covers AE1, R12. The compose options list only the applications passed
   * in and, for each, only its active, currently mapped research teams.
   */
  public function testComposeOptionsListOnlyActiveMappedTeams() {
    // A mapping removed through Changelog (flagged deleted) no longer offers the team
    $this->fx->applicationTeam($this->app['A'], $this->team['t4'], array('deleted' => true));

    $opts = $this->Invitation->composeOptions($this->coId, $this->permittedForX());

    $this->assertEqual(array($this->app['A'], $this->app['C']), array_values(array_map('intval', array_keys($opts))));
    $this->assertEqual(array($this->team['t1'] => 'Team t1', $this->team['t2'] => 'Team t2'), $opts[$this->app['A']]['teams']);
    $this->assertEqual(array($this->team['t4'] => 'Team t4'), $opts[$this->app['C']]['teams']);
    $this->assertContains('App A', $opts[$this->app['A']]['name']);

    // Applications of another CO are never listed
    $foreign = $this->fx->application($this->otherCoId, 'Foreign app');
    $this->assertEmpty($this->Invitation->composeOptions($this->coId, array($foreign)));
  }

  /**
   * The response link is built in one place, for U8 to reuse: the plugin's
   * ate_responses landing action with the raw token as the first argument.
   */
  public function testResponseUrlCarriesTheRawToken() {
    $token = AteInvitation::generateToken();

    $this->assertTrue((bool)preg_match('/^[0-9a-f]{64}$/', $token), 'token: ' . $token);
    $this->assertFalse($token === AteInvitation::generateToken(), 'tokens are random');

    $url = $this->Invitation->responseUrl($token);
    $this->assertContains('/application_team_enroller/ate_responses/landing/' . $token, $url);
  }
}
