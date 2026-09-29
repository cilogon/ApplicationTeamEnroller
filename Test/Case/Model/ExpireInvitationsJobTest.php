<?php
/**
 * U11: the expiry job (R17, R21, R37, AE12, KTD1, KTD14, KTD18).
 *
 * Each test drives ExpireInvitationsJob::execute() the way JobShell does:
 * with a CoJob registered in the CO and marked started, so the job's own
 * finish() (and any requeue) runs against the real cm_co_jobs table.
 *
 * Registry's CoNotification mails through CakeEmail('default'), which the
 * test image does not configure, so no recipient here has an email address;
 * the tests assert on the cm_co_notifications rows.
 *
 * Petitions are real: the newcomer flow and the petition driver come from
 * AtePetitionTestCase, as in the U9 tests.
 *
 * World: see AteEngineTestCase, plus a CO admins group holding coAdmin and
 * a second admin who is suspended, so is not notified.
 */

App::uses('ExpireInvitationsJob', 'ApplicationTeamEnroller.Model');
App::uses('CoJobBackend', 'Model');

/**
 * The job, with an invitation answered by the researcher after the job has
 * read the lapsed invitations and before it updates them.
 */
class ExpireInvitationsJobRaceProbe extends ExpireInvitationsJob {
  /** @var Integer The invitation to answer between the read and the update */
  public $answerAfterRead = null;

  protected function lapsedInvitations($coId, $now) {
    $ids = parent::lapsedInvitations($coId, $now);

    if($this->answerAfterRead) {
      $db = ConnectionManager::getDataSource('default');
      $db->query("UPDATE cm_ate_invitations SET status = 'responded', responded_at = now() WHERE id = "
                 . (int)$this->answerAfterRead);
      $db->query("UPDATE cm_ate_enrollment_requests SET status = 'pending_decision' WHERE ate_invitation_id = "
                 . (int)$this->answerAfterRead);
    }

    return $ids;
  }
}

class ExpireInvitationsJobTest extends AtePetitionTestCase {

  const JobType = 'ApplicationTeamEnroller.ExpireInvitations';

  /** @var Mixed App.base before the test */
  protected $appBase = null;

  public function setUp() {
    parent::setUp();

    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . AteFixtures::tag('ate-u11'),
                                            array('group_type' => 'A'));
    $this->fx->member($this->g['coadmins'], $this->p['coAdmin']);
    $this->p['suspendedAdmin'] = $this->fx->person($this->coId, array('status' => 'S'));
    $this->fx->member($this->g['coadmins'], $this->p['suspendedAdmin']);

    $this->appBase = Configure::read('App.base');
  }

  public function tearDown() {
    Configure::write('App.base', $this->appBase);

    // Jobs, and their history records (which can name the CO's people)
    $cos = (int)$this->coId . ', ' . (int)$this->otherCoId;
    $this->fx->query('DELETE FROM cm_co_job_history_records WHERE co_job_id IN (SELECT id FROM cm_co_jobs'
                     . ' WHERE co_id IN (' . $cos . '))');
    $this->fx->query('DELETE FROM cm_co_jobs WHERE co_id IN (' . $cos . ')');

    parent::tearDown();
  }

  // ---------------------------------------------------------------------
  // Helpers

  /**
   * Run the job in the CO as JobShell does: register a CoJob, start it, and
   * call execute(). Returns the finished CoJob row.
   */
  protected function runJob($job = null, $params = array()) {
    $job = $job ?: ClassRegistry::init('ApplicationTeamEnroller.ExpireInvitationsJob');
    $CoJob = ClassRegistry::init('CoJob');
    $CoJob->clear();

    $jobId = $CoJob->register($this->coId, self::JobType, null, '', 'hermetic test');
    $CoJob->id = $jobId;
    $CoJob->start($jobId, 'hermetic test');

    $job->execute($this->coId, $CoJob, $params);

    $this->assertFalse(ConnectionManager::getDataSource('default')->inTransaction(), 'no transaction left open');

    return $this->fx->rows('SELECT * FROM cm_co_jobs WHERE id = ' . (int)$jobId)[0];
  }

  /** Move an invitation's expiry $seconds into the past. */
  protected function lapse($inv, $seconds) {
    $this->fx->query("UPDATE cm_ate_invitations SET expires = '" . date('Y-m-d H:i:s', time() - $seconds)
                     . "' WHERE id = " . (int)$inv['id']);
  }

  /** Expiry notices about an invitation. */
  protected function expiryNotices($inv) {
    return $this->fx->rows("SELECT * FROM cm_co_notifications WHERE action = 'pAEX'"
                           . " AND source_url LIKE '%/ate_invitations/view/" . (int)$inv['id'] . "' ORDER BY id");
  }

  /** Containment notices about a CoPerson. */
  protected function containmentNotices($coPersonId) {
    return $this->fx->rows("SELECT * FROM cm_co_notifications WHERE action = 'pACN'"
                           . ' AND subject_co_person_id = ' . (int)$coPersonId . ' ORDER BY id');
  }

  /** Containment history records on a CoPerson. */
  protected function containmentHistory($coPersonId) {
    return $this->fx->rows("SELECT * FROM cm_history_records WHERE action = 'pACN'"
                           . ' AND co_person_id = ' . (int)$coPersonId . ' ORDER BY id');
  }

  /** Current role statuses of a CoPerson. */
  protected function roleStatuses($coPersonId) {
    return array_map(function($r) { return $r['status']; },
                     $this->fx->rows('SELECT status FROM cm_co_person_roles WHERE co_person_id = ' . (int)$coPersonId
                                     . ' AND co_person_role_id IS NULL AND deleted IS NOT true ORDER BY id'));
  }

  /** Run the newcomer flow through the done:<wedge id> skip URL; returns the new CoPerson ID. */
  protected function bypassRun() {
    $inv = $this->tokenInvitation(self::Invited, array('D' => array('t5')));
    $this->loginAs($this->sub('bypass'), array(self::Invited));
    $this->handOff($inv, array('D' => true));
    $before = $this->maxPersonId();
    $wedge = $this->wedgeId;

    $this->follow($this->startUrl(), function($controller, $action, $pass, $named) use ($wedge) {
      if($controller === 'application_team_enroller_co_petitions') {
        unset($named['efwid']);
        $named['done'] = $wedge;
        return array('co_petitions', $action, $pass, $named);
      }
      return null;
    });

    $people = $this->newPeople($before);
    $this->assertEqual(1, count($people), 'the bypass created a CoPerson');
    $this->assertEqual('A', $people[0]['status'], 'Active before the job runs');
    $this->assertNull($this->invitationRow($inv['id'])['co_petition_id'], 'and no invitation is bound');

    return (int)$people[0]['id'];
  }

  // ---------------------------------------------------------------------
  // Registration

  /** KTD1, KTD14: the plugin lists the job, which JobShell can load and parse. */
  public function testJobIsRegisteredWithJobShell() {
    $jobs = ClassRegistry::init('ApplicationTeamEnroller.ApplicationTeamEnroller')->getAvailableJobs();
    $this->assertTrue(isset($jobs['ExpireInvitations']), 'getAvailableJobs lists ExpireInvitations');
    $this->assertEqual(_txt('pl.applicationteamenroller.job.expire_invitations'), $jobs['ExpireInvitations']);

    // JobShell loads "<Plugin>.<Job>Job"
    $job = ClassRegistry::init(self::JobType . 'Job');
    $this->assertTrue($job instanceof CoJobBackend, 'a CoJobBackend');

    $format = $job->parameterFormat();
    $this->assertTrue(isset($format['requeue']), 'takes a requeue interval');
    $this->assertEqual('int', $format['requeue']['type']);
    $this->assertFalse($format['requeue']['required'], 'which is optional');

    $parser = $job->getOptionParser();
    $this->assertTrue(isset($parser->options()['requeue']), 'the option parser has it');
  }

  // ---------------------------------------------------------------------
  // Expiry and the inviter's notice (R17, R37, AE12, KTD14)

  /**
   * A sent invitation one minute past expiry becomes expired, with its
   * offered requests, and its inviter gets one notice; a live invitation is
   * untouched. The job finishes Complete.
   */
  public function testLapsedInvitationExpiresWithOneInviterNotice() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1'), 'C' => array('t4')));
    $this->lapse($inv, 60);
    $live = $this->invitation(self::Third, array('D' => array('t5')));

    $job = $this->runJob();

    $this->assertEqual(JobStatusEnum::Complete, $job['status'], 'job complete: ' . $job['finish_summary']);

    $row = $this->invitationRow($inv['id']);
    $this->assertEqual('expired', $row['status'], 'invitation expired');
    $this->assertEqual('expired', $this->requestRow($inv['req']['A'])['status'], 'A expired');
    $this->assertEqual('expired', $this->requestRow($inv['req']['C'])['status'], 'C expired');
    $this->assertTrue(in_array($row['expiry_notified'], array(true, 't', '1', 1), true), 'expiry_notified set');

    $notices = $this->expiryNotices($inv);
    $this->assertEqual(1, count($notices), 'one inviter notice');
    $this->assertEqual((int)$this->p['inviter'], (int)$notices[0]['recipient_co_person_id'], 'to the inviter');
    $this->assertEqual(_txt('pl.applicationteamenroller.notification.expired', array(self::Invited)),
                       $notices[0]['comment']);

    $this->assertEqual('sent', $this->invitationRow($live['id'])['status'], 'live invitation untouched');
    $this->assertEqual('offered', $this->requestRow($live['req']['D'])['status'], 'its request untouched');
    $this->assertEqual(array(), $this->expiryNotices($live), 'and not announced');
  }

  /**
   * R37: running the job twice sends no second notice, including for an
   * invitation expired on access (U8), which leaves expiry_notified false.
   */
  public function testSecondRunSendsNoSecondNotice() {
    $byJob = $this->invitation(self::Invited, array('A' => array('t1')));
    $this->lapse($byJob, 3600);
    $onAccess = $this->invitation(self::Third, array('C' => array('t4')));
    $this->lapse($onAccess, 3600);

    $this->assertTrue(ClassRegistry::init('ApplicationTeamEnroller.AteInvitation')->expireIfLapsed($onAccess['id']),
                      'expired on access');
    $this->assertEqual(array(), $this->expiryNotices($onAccess), 'with no notice yet');

    $this->runJob();
    $this->assertEqual(1, count($this->expiryNotices($byJob)), 'one notice for the job\'s expiry');
    $this->assertEqual(1, count($this->expiryNotices($onAccess)), 'one notice for the on-access expiry');

    $job = $this->runJob();
    $this->assertEqual(JobStatusEnum::Complete, $job['status']);
    $this->assertEqual(1, count($this->expiryNotices($byJob)), 'no second notice');
    $this->assertEqual(1, count($this->expiryNotices($onAccess)), 'no second notice for it either');
  }

  /**
   * R17: pending_decision requests never expire, whether their invitation
   * was answered long ago or is itself lapsing.
   */
  public function testPendingDecisionRequestIsUntouched() {
    $answered = $this->invitation(self::Invited, array('A' => array('t1')), array('status' => 'responded'));
    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'pending_decision' WHERE id = "
                     . (int)$answered['req']['A']);
    $this->lapse($answered, 30 * 86400);

    $mixed = $this->invitation(self::Third, array('A' => array('t1'), 'C' => array('t4')));
    $this->fx->query("UPDATE cm_ate_enrollment_requests SET status = 'pending_decision' WHERE id = "
                     . (int)$mixed['req']['A']);
    $this->lapse($mixed, 60);

    $this->runJob();

    $this->assertEqual('responded', $this->invitationRow($answered['id'])['status'], 'answered invitation kept');
    $this->assertEqual('pending_decision', $this->requestRow($answered['req']['A'])['status'], 'its request pending');
    $this->assertEqual(array(), $this->expiryNotices($answered), 'no notice for it');

    $this->assertEqual('expired', $this->invitationRow($mixed['id'])['status'], 'lapsed invitation expired');
    $this->assertEqual('pending_decision', $this->requestRow($mixed['req']['A'])['status'], 'pending request kept');
    $this->assertEqual('expired', $this->requestRow($mixed['req']['C'])['status'], 'offered request expired');
  }

  /** KTD8, KTD14: an invitation answered between the job's read and its update stays responded. */
  public function testAnsweredBetweenReadAndUpdateStaysResponded() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $this->lapse($inv, 60);

    $probe = new ExpireInvitationsJobRaceProbe();
    $probe->answerAfterRead = $inv['id'];
    $job = $this->runJob($probe);

    $this->assertEqual(JobStatusEnum::Complete, $job['status'], 'job complete: ' . $job['finish_summary']);
    $row = $this->invitationRow($inv['id']);
    $this->assertEqual('responded', $row['status'], 'left responded');
    $this->assertEqual('pending_decision', $this->requestRow($inv['req']['A'])['status'], 'request left pending');
    $this->assertFalse(in_array($row['expiry_notified'], array(true, 't', '1', 1), true), 'not flagged');
    $this->assertEqual(array(), $this->expiryNotices($inv), 'no notice');
  }

  // ---------------------------------------------------------------------
  // The bound-petition grace window (KTD11, KTD14)

  /**
   * An invitation with a bound newcomer petition one minute past expiry is
   * left sent with no notice; 25 hours past expiry it is expired, its
   * inviter notified, and its petition retired (Declined, with a comment).
   */
  public function testBoundPetitionGraceWindow() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('grace'), array(self::Invited));
    $run = $this->follow($this->handOff($inv, array('A' => true)), $this->stopBefore('core:finalize'));
    $this->assertNotEmpty($run['stopped_at'], 'stopped before finalize');
    $pt = (int)$this->invitationRow($inv['id'])['co_petition_id'];
    $this->assertTrue($pt > 0, 'petition bound');
    $ptStatus = $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $pt);

    $this->lapse($inv, 60);
    $this->runJob();

    $this->assertEqual('sent', $this->invStatus($inv), 'kept sent in the grace window');
    $this->assertEqual('offered', $this->reqStatus($inv, 'A'), 'request still offered');
    $this->assertEqual(array(), $this->expiryNotices($inv), 'no notice');
    $this->assertEqual($ptStatus, $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $pt),
                       'petition untouched');

    $this->lapse($inv, 25 * 3600);
    $job = $this->runJob();

    $this->assertEqual(JobStatusEnum::Complete, $job['status'], 'job complete: ' . $job['finish_summary']);
    $this->assertEqual('expired', $this->invStatus($inv), 'expired after the grace window');
    $this->assertEqual('expired', $this->reqStatus($inv, 'A'), 'request expired');
    $this->assertEqual(1, count($this->expiryNotices($inv)), 'one notice');
    $this->assertEqual('X', $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $pt),
                       'petition retired as Declined');
    $this->assertEqual(1, (int)$this->fx->scalar("SELECT count(*) FROM cm_co_petition_history_records"
                         . " WHERE co_petition_id = " . $pt . " AND action = 'CM' AND comment = "
                         . ConnectionManager::getDataSource('default')->value(
                             _txt('pl.applicationteamenroller.rs.petition.retired.expired', array((int)$inv['id'])))),
                       'the retirement is recorded on it');

    // The retired petition cannot be finished
    $this->follow($this->resumeUrl($pt, 'finalize'));
    $this->assertEqual('X', $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $pt),
                       'core did not finalize it');

    // A later run changes nothing
    $this->runJob();
    $this->assertEqual(1, count($this->expiryNotices($inv)), 'still one notice');
    $this->assertEqual(1, (int)$this->fx->scalar("SELECT count(*) FROM cm_co_petition_history_records"
                         . " WHERE co_petition_id = " . $pt . " AND action = 'CM'"), 'retired once');
  }

  /**
   * KTD14: an invitation expired on access after the grace window (U8 does
   * not retire petitions) has its bound petition retired by the next run.
   */
  public function testPetitionOfInvitationExpiredOnAccessIsRetired() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('late-access'), array(self::Invited));
    $this->follow($this->handOff($inv, array('A' => true)), $this->stopBefore('core:finalize'));
    $pt = (int)$this->invitationRow($inv['id'])['co_petition_id'];
    $this->assertTrue($pt > 0, 'petition bound');

    $this->lapse($inv, 25 * 3600);
    $this->assertTrue(ClassRegistry::init('ApplicationTeamEnroller.AteInvitation')->expireIfLapsed($inv['id']),
                      'expired on access');
    $this->assertFalse($this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $pt) === 'X',
                       'petition not yet retired');

    $this->runJob();

    $this->assertEqual('X', $this->fx->scalar('SELECT status FROM cm_co_petitions WHERE id = ' . $pt),
                       'retired by the job');
    $this->assertEqual(1, count($this->expiryNotices($inv)), 'and the inviter notified once');
  }

  // ---------------------------------------------------------------------
  // Containment (KTD18)

  /**
   * A petition finalized on the newcomer flow through the done:<wedge id>
   * skip URL, with no bound invitation, leaves its CoPerson suspended after
   * the next run, with a history record, and CO admins notified once. Later
   * runs neither notify again nor re-suspend a person an administrator has
   * reinstated.
   */
  public function testDoneSkipPersonIsSuspendedAndAdminsNotifiedOnce() {
    $person = $this->bypassRun();

    $job = $this->runJob();

    $this->assertEqual(JobStatusEnum::Complete, $job['status'], 'job complete: ' . $job['finish_summary']);
    $this->assertEqual('S', $this->personStatus($person), 'CoPerson suspended');
    $this->assertEqual(array('S'), $this->roleStatuses($person), 'and its role');

    $hist = $this->containmentHistory($person);
    $this->assertEqual(1, count($hist), 'one history record');
    $pt = (int)$this->fx->scalar('SELECT id FROM cm_co_petitions WHERE enrollee_co_person_id = ' . $person
                                 . ' AND co_petition_id IS NULL');
    $this->assertEqual(_txt('pl.applicationteamenroller.rs.contained', array($pt)), $hist[0]['comment']);

    // An informational notice to a group reaches each Active member
    // (CoNotification::register())
    $notices = $this->containmentNotices($person);
    $this->assertEqual(1, count($notices), 'one notice');
    $this->assertEqual((int)$this->p['coAdmin'], (int)$notices[0]['recipient_co_person_id'],
                       'to the CO admins group\'s member');
    $this->assertEqual(NotificationStatusEnum::PendingAcknowledgment, $notices[0]['status'], 'to acknowledge');
    $this->assertTrue(strpos($notices[0]['source_url'], '/co_people/canvas/' . $person) !== false,
                      'pointing at the person: ' . $notices[0]['source_url']);

    // Idempotent
    $this->runJob();
    $this->assertEqual(1, count($this->containmentNotices($person)), 'no second notice');
    $this->assertEqual(1, count($this->containmentHistory($person)), 'no second history record');

    // An administrator reinstates the person: the job leaves that decision alone
    $this->fx->query("UPDATE cm_co_people SET status = 'A' WHERE id = " . $person);
    $this->fx->query("UPDATE cm_co_person_roles SET status = 'A' WHERE co_person_id = " . $person);
    $this->runJob();
    $this->assertEqual('A', $this->personStatus($person), 'not re-suspended');
    $this->assertEqual(1, count($this->containmentNotices($person)), 'not re-announced');
  }

  /**
   * KTD18: a legitimately bound newcomer CoPerson is never suspended, and a
   * finalized petition on another flow is not the job's business.
   */
  public function testBoundNewcomerIsNeverSuspended() {
    $inv = $this->tokenInvitation(self::Invited, array('A' => array('t1')));
    $this->loginAs($this->sub('legit'), array(self::Invited));
    $before = $this->maxPersonId();
    $this->completeRun($inv, array('A' => true));
    $person = (int)$this->newPeople($before)[0]['id'];
    $this->assertEqual('A', $this->personStatus($person), 'Active');

    // A finalized petition on another flow, bound to nothing
    $other = $this->fx->flow($this->coId, 'Other ' . AteFixtures::tag('flow'));
    $this->fx->insert('cm_co_petitions', array(
      'co_enrollment_flow_id' => $other,
      'co_id' => $this->coId,
      'enrollee_co_person_id' => $this->p['p1'],
      'status' => 'F',
      'revision' => 0,
      'deleted' => false,
      'co_petition_id' => null
    ));

    $this->runJob();
    $this->runJob();

    $this->assertEqual('A', $this->personStatus($person), 'bound newcomer still Active');
    $this->assertEqual(array('A'), $this->roleStatuses($person), 'and its role');
    $this->assertEqual(array(), $this->containmentNotices($person), 'no notice');
    $this->assertEqual(array(), $this->containmentHistory($person), 'no history record');
    $this->assertEqual(array(), $this->containmentNotices($this->p['p1']), 'other flow ignored');
    $this->assertFalse($this->personStatus($this->p['p1']) === 'S', 'its enrollee not suspended');
  }

  // ---------------------------------------------------------------------
  // Scheduling and links

  /**
   * KTD14: with a requeue interval the job registers its next run through
   * CoJob when it finishes; without one it runs once.
   */
  public function testRequeueSchedulesNextRun() {
    $once = $this->runJob();
    $this->assertEqual(0, $this->fx->count('cm_co_jobs', 'requeued_from_co_job_id = ' . (int)$once['id']),
                       'no requeue by default');

    $job = $this->runJob(null, array('requeue' => '60'));
    $this->assertEqual(JobStatusEnum::Complete, $job['status']);

    $next = $this->fx->rows('SELECT * FROM cm_co_jobs WHERE requeued_from_co_job_id = ' . (int)$job['id']);
    $this->assertEqual(1, count($next), 'next run queued');
    $this->assertEqual(self::JobType, $next[0]['job_type']);
    $this->assertEqual(JobStatusEnum::Queued, $next[0]['status']);
    $this->assertEqual(3600, (int)$next[0]['requeue_interval'], 'and it requeues in turn');
    $delay = strtotime($next[0]['start_after_time']) - time();
    $this->assertTrue($delay > 3500 && $delay <= 3600, 'not before an hour from now: ' . $delay);
  }

  /** KTD13: the job sets App.base from the platform configuration before it builds links. */
  public function testAppBaseIsSetBeforeLinks() {
    $this->fx->insert('cm_cmp_enrollment_configurations', array(
      'name' => 'CMP Enrollment Configuration',
      'app_base' => '/registry-u11',
      'pool_org_identities' => false,
      'status' => 'A'
    ));
    Configure::write('App.base', '');

    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $this->lapse($inv, 60);
    $this->runJob();

    $this->assertEqual('/registry-u11', Configure::read('App.base'), 'App.base set');
    $notices = $this->expiryNotices($inv);
    $this->assertEqual(1, count($notices), 'one notice');
    $this->assertTrue(strpos($notices[0]['source_url'], '/registry-u11/application_team_enroller/ate_invitations/view/')
                      !== false, 'its link carries the base: ' . $notices[0]['source_url']);
  }
}
