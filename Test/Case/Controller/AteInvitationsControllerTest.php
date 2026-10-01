<?php
/**
 * U6: the invitation screens (R9-R18, R34, F1, AE1, AE12, AE17). The compose
 * form offers only the applications the user may invite for and their active
 * mapped teams; the server re-checks every submitted application and team;
 * the list shows an application admin only invitations that include their
 * applications; revoke and withdraw are conditional.
 *
 * Driven through Test/lib/AteControllerHarness.php without rendering, with
 * email recorded by Test/lib/AteMailTransport.php. Menu entries in comain are
 * shown to every CO member, so each action is authorized here.
 *
 * Seeded world:
 *   A (admin group adminsA {x}), T1 and T2 authorized
 *   B (admin group adminsB {y}), T3 authorized
 *   The CO admin group holds co. stranger is a plain CO member.
 */

App::uses('AteInvitationsController', 'ApplicationTeamEnroller.Controller');

class AteInvitationsHarness extends AteInvitationsController {
  use AteControllerHarness;
}

class AteInvitationsControllerTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $coId = null;
  protected $otherCoId = null;
  protected $p = array();
  protected $g = array();
  protected $app = array();
  protected $team = array();

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u6-ctl');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    foreach(array('co', 'x', 'y', 'stranger') as $who) {
      $this->p[$who] = $this->fx->person($this->coId);
    }

    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . $tag, array('group_type' => 'A'));
    $this->fx->member($this->g['coadmins'], $this->p['co']);

    foreach(array('adminsA', 'adminsB', 'approvers', 't1', 't2', 't3') as $name) {
      $this->g[$name] = $this->fx->group($this->coId, $name . ' ' . $tag);
    }
    $this->fx->member($this->g['adminsA'], $this->p['x']);
    $this->fx->member($this->g['adminsB'], $this->p['y']);

    $this->app['A'] = $this->fx->application($this->coId, 'App A ' . $tag, array(
      'admin_co_group_id' => $this->g['adminsA'], 'approver_co_group_id' => $this->g['approvers']));
    $this->app['B'] = $this->fx->application($this->coId, 'App B ' . $tag, array(
      'admin_co_group_id' => $this->g['adminsB'], 'approver_co_group_id' => $this->g['approvers']));

    foreach(array('t1', 't2', 't3') as $name) {
      $this->team[$name] = $this->fx->researchTeam($this->g[$name], array('name' => 'Team ' . $name));
    }
    $this->fx->applicationTeam($this->app['A'], $this->team['t1']);
    $this->fx->applicationTeam($this->app['A'], $this->team['t2']);
    $this->fx->applicationTeam($this->app['B'], $this->team['t3']);

    AteRecordingTransport::reset();
  }

  public function tearDown() {
    AteRecordingTransport::reset();
    ClassRegistry::init('ApplicationTeamEnroller.AteInvitation')->emailConfig = 'default';

    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /** Roles for person $who; 'co' is a CO administrator. */
  private function roles($who) {
    $roles = array('comember' => true, 'user' => true, 'copersonid' => $this->p[$who]);

    if($who === 'co') {
      $roles['coadmin'] = true;
    }

    return $roles;
  }

  /** A harness acting as $who, with email going to the recording transport. */
  private function harness($who, $data = array()) {
    $h = AteInvitationsHarness::harnessBuild('ate_invitations', $this->coId, $this->roles($who), $data);
    $h->AteInvitation->emailConfig = AteRecordingTransport::emailConfig();

    return $h;
  }

  /** Whether $who may run $action with $pass. */
  private function allowed($who, $action, $pass = array()) {
    $h = $this->harness($who);
    $h->action = $action;
    $h->request->params['action'] = $action;
    $h->request->params['pass'] = $pass;

    return (bool)$h->isAuthorized();
  }

  /** Compose form data. */
  private function form($email, $selections) {
    return array('AteInvitation' => array(
      'invited_email' => $email,
      'application_ids' => array_fill_keys(array_keys($selections), '1'),
      'teams' => array_map(function($ids) { return array_map('strval', $ids); }, $selections)
    ));
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

  private function invitationCount() {
    return $this->fx->count('cm_ate_invitations', 'co_id = ' . $this->coId);
  }

  /**
   * KTD16: the comain menu is shown to every member, so each action checks
   * access. A plain member may do nothing; an application admin may compose
   * and list, view only invitations that include their applications, and
   * revoke only their own; a CO admin may do everything.
   */
  public function testActionsAreAuthorizedPerUser() {
    $invA = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['co']));
    $this->fx->enrollmentRequest($invA, $this->app['A']);
    $reqA = $this->fx->enrollmentRequest($invA, $this->app['A'], array('status' => 'pending_decision'));
    $invB = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['y']));
    $this->fx->enrollmentRequest($invB, $this->app['B']);
    $foreign = $this->fx->invitation($this->otherCoId);

    foreach(array('add', 'index') as $action) {
      $this->assertFalse($this->allowed('stranger', $action), "stranger $action");
      $this->assertTrue($this->allowed('x', $action), "app admin $action");
      $this->assertTrue($this->allowed('co', $action), "co admin $action");
    }

    $this->assertFalse($this->allowed('stranger', 'view', array($invA)));
    $this->assertTrue($this->allowed('x', 'view', array($invA)), 'A admin views an invitation for A');
    $this->assertFalse($this->allowed('x', 'view', array($invB)), 'A admin may not view an invitation for B only');
    $this->assertTrue($this->allowed('y', 'view', array($invB)));
    $this->assertTrue($this->allowed('co', 'view', array($invB)));
    $this->assertFalse($this->allowed('co', 'view', array($foreign)), 'another CO');

    $this->assertFalse($this->allowed('x', 'revoke', array($invA)), 'only the inviter or a CO admin revokes');
    $this->assertTrue($this->allowed('y', 'revoke', array($invB)), 'the inviter revokes');
    $this->assertTrue($this->allowed('co', 'revoke', array($invB)));

    $this->assertFalse($this->allowed('x', 'withdraw', array($reqA)));
    $this->assertTrue($this->allowed('co', 'withdraw', array($reqA)), 'the inviter (a CO admin) withdraws');

    foreach(array('edit', 'delete', 'search') as $action) {
      $this->assertFalse($this->allowed('co', $action, array($invA)), "unlisted action $action");
    }
  }

  /**
   * Covers AE1, R11, R12. Admin X of A (not B) sees only A, with only T1 and
   * T2 and no administrative group; a CO admin sees both applications.
   */
  public function testComposeFormOffersOnlyInvitableApplicationsAndTheirTeams() {
    $h = $this->harness('x');
    $h->harnessInvoke('add');

    $opts = $h->viewVars['vv_applications'];
    $this->assertEqual(array($this->app['A']), array_map('intval', array_keys($opts)));
    $this->assertEqual(array($this->team['t1'] => 'Team t1', $this->team['t2'] => 'Team t2'),
                       $opts[$this->app['A']]['teams']);

    $h = $this->harness('co');
    $h->harnessInvoke('add');
    $this->assertEqual(array($this->app['A'], $this->app['B']),
                       array_map('intval', array_keys($h->viewVars['vv_applications'])));
  }

  /**
   * R11: a posted application the sender does not administer is rejected by
   * the server even though the form did not offer it, and nothing is sent.
   */
  public function testPostWithNonAdministeredApplicationIsRejected() {
    $h = $this->harness('x', $this->form('r@example.org', array(
      $this->app['A'] => array($this->team['t1']),
      $this->app['B'] => array($this->team['t3'])
    )));
    $h->harnessInvoke('add', array(), 'POST');

    $this->assertFalse($h->harnessStopped, 'the form is shown again');
    $this->assertTrue($this->flashed($h, 'error'), 'an error is flashed: ' . json_encode($h->Flash->messages));
    $this->assertEqual(0, $this->invitationCount());
    $this->assertEqual(0, count(AteRecordingTransport::$sent));
  }

  /** R12: a posted team the application does not authorize is rejected. */
  public function testPostWithUnauthorizedTeamIsRejected() {
    $h = $this->harness('x', $this->form('r@example.org', array(
      $this->app['A'] => array($this->team['t1'], $this->team['t3'])
    )));
    $h->harnessInvoke('add', array(), 'POST');

    $this->assertFalse($h->harnessStopped);
    $this->assertTrue($this->flashed($h, 'error'), json_encode($h->Flash->messages));
    $this->assertEqual(0, $this->invitationCount());
    $this->assertEqual(0, count(AteRecordingTransport::$sent));
  }

  /**
   * F1: a valid submission records the invitation with X as the inviting
   * admin, emails the researcher, and opens the invitation's page. Teams
   * ticked under an application that was not selected are ignored.
   */
  public function testPostCreatesInvitationAndOpensIt() {
    $data = $this->form(' r@example.org ', array($this->app['A'] => array($this->team['t2'])));
    $data['AteInvitation']['application_ids'][$this->app['B']] = '0';
    $data['AteInvitation']['teams'][$this->app['B']] = array((string)$this->team['t3']);

    $h = $this->harness('x', $data);
    $target = $h->harnessInvoke('add', array(), 'POST');

    $this->assertTrue($h->harnessStopped, 'redirects on success: ' . json_encode($h->Flash->messages));
    $id = (int)$this->fx->scalar('SELECT id FROM cm_ate_invitations WHERE co_id = ' . $this->coId);
    $this->assertTrue($id > 0);
    $this->assertEqual('view', $target['action']);
    $this->assertEqual($id, (int)$target[0]);
    $this->assertTrue($this->flashed($h, 'success'));

    $this->assertEqual($this->p['x'], (int)$this->fx->scalar(
      'SELECT inviter_co_person_id FROM cm_ate_invitations WHERE id = ' . $id));
    $this->assertEqual('r@example.org', $this->fx->scalar('SELECT invited_email FROM cm_ate_invitations WHERE id = ' . $id));
    $this->assertEqual(array((string)$this->app['A']), array_map('strval', array_column($this->fx->rows(
      'SELECT ate_application_id FROM cm_ate_enrollment_requests WHERE ate_invitation_id = ' . $id), 'ate_application_id')));
    $this->assertEqual(1, count(AteRecordingTransport::$sent));
  }

  /**
   * Covers R34. An application admin lists only invitations that include
   * their applications (and ones they sent); a CO admin lists every
   * invitation of the CO and none of another CO.
   */
  public function testIndexFiltersForApplicationAdminAndShowsAllToCoAdmin() {
    $onlyA = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['co']));
    $this->fx->enrollmentRequest($onlyA, $this->app['A']);
    $onlyB = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['y']));
    $this->fx->enrollmentRequest($onlyB, $this->app['B']);
    $both = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['co']));
    $this->fx->enrollmentRequest($both, $this->app['A']);
    $this->fx->enrollmentRequest($both, $this->app['B']);
    $foreign = $this->fx->invitation($this->otherCoId);

    $listed = function($who) {
      $h = $this->harness($who);
      $h->harnessInvoke('index');
      $ids = array_map('intval', Hash::extract($h->viewVars['ate_invitations'], '{n}.AteInvitation.id'));
      sort($ids);
      return $ids;
    };

    $this->assertEqual(array($onlyA, $both), $listed('x'), 'admin of A');
    $this->assertEqual(array($onlyB, $both), $listed('y'), 'admin of B');
    $this->assertEqual(array($onlyA, $onlyB, $both), $listed('co'), 'CO admin');
    $this->assertFalse(in_array($foreign, $listed('co'), true));
  }

  /**
   * The invitation page shows each request's status and teams, and offers
   * revoke only while the invitation is sent.
   */
  public function testViewShowsEachRequestStatus() {
    $inv = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['x'], 'status' => 'responded'));
    $ra = $this->fx->enrollmentRequest($inv, $this->app['A'], array('status' => 'pending_decision'));
    $this->fx->enrollmentRequestTeam($ra, $this->team['t1']);
    $rb = $this->fx->enrollmentRequest($inv, $this->app['B'], array('status' => 'declined_by_enrollee'));

    $h = $this->harness('co');
    $h->harnessInvoke('view', array($inv));

    $v = $h->viewVars['vv_invitation'];
    $this->assertEqual($inv, (int)$v['AteInvitation']['id']);
    $status = array();
    foreach($v['AteEnrollmentRequest'] as $r) {
      $status[(int)$r['id']] = $r['status'];
    }
    $this->assertEqual(array($ra => 'pending_decision', $rb => 'declined_by_enrollee'), $status);
    $this->assertEqual('Team t1', $v['AteEnrollmentRequest'][0]['AteEnrollmentRequestTeam'][0]['AteResearchTeam']['name']);
    $this->assertFalse($h->viewVars['vv_may_revoke'], 'a responded invitation cannot be revoked');
    $this->assertEqual(array($ra), $h->viewVars['vv_withdrawable'], 'only the pending request can be withdrawn');
  }

  /**
   * Covers AE12, R18. Revoke through the screen marks the invitation and its
   * requests revoked; posting it again reports that it was already handled.
   */
  public function testRevokeActionRevokesOnceThenReportsAlreadyHandled() {
    $inv = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['x']));
    $req = $this->fx->enrollmentRequest($inv, $this->app['A']);

    $h = $this->harness('x');
    $target = $h->harnessInvoke('revoke', array($inv), 'POST');
    $this->assertEqual('view', $target['action']);
    $this->assertEqual($inv, (int)$target[0]);
    $this->assertTrue($this->flashed($h, 'success'), json_encode($h->Flash->messages));
    $this->assertEqual('revoked', $this->fx->scalar('SELECT status FROM cm_ate_invitations WHERE id = ' . $inv));
    $this->assertEqual($this->p['x'], (int)$this->fx->scalar(
      'SELECT revoked_by_co_person_id FROM cm_ate_invitations WHERE id = ' . $inv));
    $this->assertEqual('revoked', $this->fx->scalar('SELECT status FROM cm_ate_enrollment_requests WHERE id = ' . $req));

    $h = $this->harness('co');
    $h->harnessInvoke('revoke', array($inv), 'POST');
    $this->assertTrue($this->flashed($h, 'error', _txt('pl.applicationteamenroller.er.invitation.handled')),
      json_encode($h->Flash->messages));
  }

  /**
   * Covers AE17, R18. The inviting admin withdraws one pending request; a
   * second withdraw reports that it was already handled.
   */
  public function testWithdrawActionRevokesOnePendingRequest() {
    $inv = $this->fx->invitation($this->coId, array('inviter_co_person_id' => $this->p['x'], 'status' => 'responded'));
    $req = $this->fx->enrollmentRequest($inv, $this->app['A'], array('status' => 'pending_decision',
                                                                      'pending_reason' => 'approval'));

    $h = $this->harness('x');
    $target = $h->harnessInvoke('withdraw', array($req), 'POST');
    $this->assertEqual('view', $target['action']);
    $this->assertEqual($inv, (int)$target[0]);
    $this->assertTrue($this->flashed($h, 'success'), json_encode($h->Flash->messages));
    $this->assertEqual('revoked', $this->fx->scalar('SELECT status FROM cm_ate_enrollment_requests WHERE id = ' . $req));

    $h = $this->harness('x');
    $h->harnessInvoke('withdraw', array($req), 'POST');
    $this->assertTrue($this->flashed($h, 'error', _txt('pl.applicationteamenroller.er.request.handled')),
      json_encode($h->Flash->messages));
  }
}
