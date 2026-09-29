<?php
/**
 * COmanage Registry Application Team Enroller Expire Invitations Job Model
 *
 * The plugin's Registry job (KTD1, KTD14, KTD18), run by JobShell as
 * ApplicationTeamEnroller.ExpireInvitations for one CO:
 *
 * 1. Lapsed sent invitations become expired, with their offered requests
 *    (R17, AE12). An invitation with a bound newcomer petition is left until
 *    24 hours past its expiry (KTD11, KTD14). pending_decision requests
 *    never expire. Every change is AteInvitation::expireIfLapsed()'s
 *    conditional update, the same one opening the link applies.
 * 2. The bound petition of an expired invitation, if unfinished, is retired
 *    (Declined), whichever path expired the invitation.
 * 3. The inviting admin of each expired invitation is notified once (R37),
 *    tracked by expiry_notified, including invitations expired on access.
 * 4. Containment (KTD18): a petition finalized on the CO's newcomer flow that
 *    no invitation is bound to (for example one run through the
 *    done:<wedge id> skip URL) has its enrollee suspended, a history record
 *    written, and the CO admins group notified (an informational
 *    notification, which Registry registers for each Active member). The petition then carries an
 *    AteHistoryActionEnum::Contained record, so it is handled once.
 *
 * With the requeue parameter the job runs again that many minutes after
 * each run, through CoJob's requeue.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('CoJobBackend', 'Model');
App::uses('AteInvitation', 'ApplicationTeamEnroller.Model');

class ExpireInvitationsJob extends CoJobBackend {
  // Validation rules for table elements
  public $validate = array();

  // CoPerson and CoPersonRole statuses containment moves to Suspended. The
  // rest (Suspended, Expired, Declined, Denied, Deleted, Duplicate, Locked)
  // already grant nothing or were set deliberately.
  protected $suspendable = array(
    StatusEnum::Active,
    StatusEnum::Approved,
    StatusEnum::Confirmed,
    StatusEnum::GracePeriod,
    StatusEnum::Invited,
    StatusEnum::Pending,
    StatusEnum::PendingApproval,
    StatusEnum::PendingConfirmation,
    StatusEnum::PendingVetting
  );

  /**
   * Execute the requested Job.
   *
   * @since  COmanage Registry v4.6.0
   * @param  int   $coId    CO ID
   * @param  CoJob $CoJob   CO Job Object, id available at $CoJob->id
   * @param  array $params  Array of parameters, as requested via parameterFormat()
   */

  public function execute($coId, $CoJob, $params) {
    try {
      $this->scheduleRequeue($CoJob, $params);

      // Links in notifications need the platform's base path, which a web
      // request supplies and a console run does not (KTD13; JobShell::main())
      Configure::write('App.base', ClassRegistry::init('CmpEnrollmentConfiguration')->getAppBase());

      $counts = $this->run((int)$coId, $CoJob);

      $CoJob->finish($CoJob->id, _txt('pl.applicationteamenroller.job.expire_invitations.done',
                                      array($counts['expired'], $counts['retired'], $counts['notified'],
                                            $counts['contained'], $counts['errors'])));
    } catch(Exception $e) {
      $CoJob->finish($CoJob->id, $e->getMessage(), JobStatusEnum::Failed);
    }
  }

  /**
   * Obtain the list of parameters supported by this Job.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Array of supported parameters.
   */

  public function parameterFormat() {
    return array(
      'requeue' => array(
        'help'     => _txt('pl.applicationteamenroller.job.expire_invitations.requeue'),
        'type'     => 'int',
        'required' => false
      )
    );
  }

  /**
   * Run every step for one CO. A failure on one invitation or petition is
   * logged and recorded on the job, and the run goes on; the next run
   * retries it.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId  CO ID
   * @param  CoJob   $CoJob CO Job Object, or null
   * @param  Integer $now   Current Unix time, or null for time()
   * @return Array          Counts: 'expired', 'retired', 'notified', 'contained', 'errors'
   */

  public function run($coId, $CoJob = null, $now = null) {
    $now = ($now === null) ? time() : (int)$now;
    $counts = array('expired' => 0, 'retired' => 0, 'notified' => 0, 'contained' => 0, 'errors' => 0);
    $Invitation = ClassRegistry::init('ApplicationTeamEnroller.AteInvitation');

    // 1. Expire (a conditional update each, so an invitation answered or
    // revoked since the read is left alone)
    foreach($this->lapsedInvitations($coId, $now) as $id) {
      try {
        if($Invitation->expireIfLapsed($id, $now)) {
          $counts['expired']++;
          $this->jobRecord($CoJob, 'AteInvitation ' . $id,
                           _txt('pl.applicationteamenroller.job.expire_invitations.expired', array($id)));
        }
      } catch(Exception $e) {
        $counts['errors'] += $this->failure($CoJob, 'AteInvitation ' . $id, $e);
      }
    }

    // 2. Retire the bound petitions of expired invitations
    foreach($this->unfinishedExpiredPetitions($coId) as $row) {
      try {
        if($Invitation->retirePetition($row['co_petition_id'],
                                       _txt('pl.applicationteamenroller.rs.petition.retired.expired',
                                            array($row['id'])))) {
          $counts['retired']++;
          $this->jobRecord($CoJob, 'CoPetition ' . $row['co_petition_id'],
                           _txt('pl.applicationteamenroller.job.expire_invitations.retired',
                                array($row['co_petition_id'], $row['id'])));
        }
      } catch(Exception $e) {
        $counts['errors'] += $this->failure($CoJob, 'CoPetition ' . $row['co_petition_id'], $e);
      }
    }

    // 3. Tell each inviting admin once
    foreach($this->unannouncedExpiries($coId) as $row) {
      try {
        if($this->notifyExpiry($coId, $row)) {
          $counts['notified']++;
          $this->jobRecord($CoJob, 'AteInvitation ' . $row['id'],
                           _txt('pl.applicationteamenroller.job.expire_invitations.notified', array($row['id'])));
        }
      } catch(Exception $e) {
        $counts['errors'] += $this->failure($CoJob, 'AteInvitation ' . $row['id'], $e);
      }
    }

    // 4. Contain newcomer-flow enrollments no invitation is bound to
    foreach($this->unboundNewcomerPetitions($coId) as $row) {
      try {
        $this->contain($coId, (int)$row['id'], (int)$row['enrollee_co_person_id']);
        $counts['contained']++;
        $this->jobRecord($CoJob, 'CoPetition ' . $row['id'],
                         _txt('pl.applicationteamenroller.job.expire_invitations.contained',
                              array($row['enrollee_co_person_id'], $row['id'])),
                         (int)$row['enrollee_co_person_id']);
      } catch(Exception $e) {
        $counts['errors'] += $this->failure($CoJob, 'CoPetition ' . $row['id'], $e);
      }
    }

    return $counts;
  }

  /**
   * The CO's sent invitations past their expiry, excluding those with a
   * bound petition still inside the grace window (KTD14).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @param  Integer $now  Current Unix time
   * @return Array         AteInvitation IDs
   */

  protected function lapsedInvitations($coId, $now) {
    $rows = $this->sqlRows('SELECT id FROM ' . $this->table('ate_invitations')
                           . ' WHERE co_id = ? AND status = ? AND expires < ?'
                           . ' AND (co_petition_id IS NULL OR expires < ?) ORDER BY id',
                           array((int)$coId, AteInvitationStatusEnum::Sent, date('Y-m-d H:i:s', $now),
                                 date('Y-m-d H:i:s', $now - AteInvitation::PetitionGraceSeconds)));

    return array_map(function($r) { return (int)$r['id']; }, $rows);
  }

  /**
   * The CO's expired invitations whose bound petition has not finished.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Rows with 'id' (AteInvitation) and 'co_petition_id'
   */

  protected function unfinishedExpiredPetitions($coId) {
    return $this->sqlRows('SELECT i.id, i.co_petition_id FROM ' . $this->table('ate_invitations') . ' i'
                          . ' JOIN ' . $this->table('co_petitions') . ' p ON p.id = i.co_petition_id'
                          . ' WHERE i.co_id = ? AND i.status = ? AND p.status NOT IN ('
                          . implode(', ', array_fill(0, count(AteInvitation::PetitionFinishedStatuses), '?'))
                          . ') ORDER BY i.id',
                          array_merge(array((int)$coId, AteInvitationStatusEnum::Expired),
                                      AteInvitation::PetitionFinishedStatuses));
  }

  /**
   * The CO's expired invitations whose inviting admin has not been told.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Rows with 'id', 'invited_email', 'inviter_co_person_id', 'invitee_co_person_id'
   */

  protected function unannouncedExpiries($coId) {
    return $this->sqlRows('SELECT id, invited_email, inviter_co_person_id, invitee_co_person_id FROM '
                          . $this->table('ate_invitations')
                          . ' WHERE co_id = ? AND status = ? AND expiry_notified IS NOT TRUE ORDER BY id',
                          array((int)$coId, AteInvitationStatusEnum::Expired));
  }

  /**
   * Notify an expired invitation's inviting admin, once (R37). The flag is
   * claimed first with a conditional update, so two runs cannot both
   * notify; if the notification fails the claim is released for the next
   * run.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @param  Array   $row  Row from unannouncedExpiries()
   * @return Boolean       True if a notification was registered
   * @throws RuntimeException If the notification or an update fails
   */

  protected function notifyExpiry($coId, $row) {
    if(!$this->setExpiryNotified((int)$row['id'], true)) {
      return false;
    }

    if(empty($row['inviter_co_person_id'])) {
      // No one to tell
      return false;
    }

    $ids = ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest')->notifyInviter(
      $coId,
      (int)$row['id'],
      AteNotificationActionEnum::Expired,
      _txt('pl.applicationteamenroller.notification.expired', array($row['invited_email'])),
      empty($row['invitee_co_person_id']) ? null : (int)$row['invitee_co_person_id'],
      null
    );

    if(empty($ids)) {
      $this->setExpiryNotified((int)$row['id'], false);
      throw new RuntimeException(_txt('pl.applicationteamenroller.job.expire_invitations.error',
                                      array('notification for invitation ' . (int)$row['id'])));
    }

    return true;
  }

  /**
   * Set an expired invitation's expiry_notified flag, if it is not already
   * set that way.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $invitationId AteInvitation ID
   * @param  Boolean $value        New value
   * @return Boolean               True if this call changed it
   * @throws RuntimeException If the update fails
   */

  protected function setExpiryNotified($invitationId, $value) {
    $dbc = $this->getDataSource();

    // The boolean is literal SQL: a bound PHP false reaches Postgres as ''
    $ok = $dbc->fetchAll('UPDATE ' . $this->table('ate_invitations')
                         . ' SET expiry_notified = ' . ($value ? 'true' : 'false') . ', modified = ?'
                         . ' WHERE id = ? AND status = ? AND expiry_notified IS ' . ($value ? 'NOT TRUE' : 'TRUE'),
                         array(date('Y-m-d H:i:s'), (int)$invitationId, AteInvitationStatusEnum::Expired),
                         array('cache' => false));

    if($ok === false) {
      throw new RuntimeException(_txt('er.db.save-a', array('AteInvitation')));
    }

    return $dbc->lastAffected() === 1;
  }

  /**
   * Petitions finalized on the CO's newcomer flow that no invitation is
   * bound to and that the job has not handled (KTD18). An enrollee who is
   * the invitee of an invitation of the CO is left out too: that person was
   * committed through the plugin.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Rows with 'id' (CoPetition) and 'enrollee_co_person_id'
   */

  protected function unboundNewcomerPetitions($coId) {
    $flow = $this->sqlRows('SELECT newcomer_co_enrollment_flow_id FROM ' . $this->table('ate_settings')
                           . ' WHERE co_id = ? AND ate_setting_id IS NULL AND deleted IS NOT TRUE',
                           array((int)$coId));

    if(empty($flow[0]['newcomer_co_enrollment_flow_id'])) {
      return array();
    }

    return $this->sqlRows('SELECT pt.id, pt.enrollee_co_person_id FROM ' . $this->table('co_petitions') . ' pt'
                          . ' WHERE pt.co_id = ? AND pt.co_enrollment_flow_id = ? AND pt.status = ?'
                          . ' AND pt.enrollee_co_person_id IS NOT NULL'
                          . ' AND pt.co_petition_id IS NULL AND pt.deleted IS NOT TRUE'
                          . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('ate_invitations') . ' i'
                          . '   WHERE i.co_petition_id = pt.id)'
                          . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('ate_invitations') . ' i'
                          . '   WHERE i.co_id = pt.co_id AND i.invitee_co_person_id = pt.enrollee_co_person_id)'
                          . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->table('co_petition_history_records') . ' h'
                          . '   WHERE h.co_petition_id = pt.id AND h.action = ?)'
                          . ' ORDER BY pt.id',
                          array((int)$coId, (int)$flow[0]['newcomer_co_enrollment_flow_id'],
                                PetitionStatusEnum::Finalized, AteHistoryActionEnum::Contained));
  }

  /**
   * Contain one enrollee (KTD18): suspend their roles and the CoPerson,
   * record it on the CoPerson and on the petition (which marks the petition
   * handled), then notify the CO admins group. Suspending is idempotent, so
   * a run that fails before the petition record is retried whole.
   *
   * A notification failure is logged, not retried: the suspension and its
   * history record already tell administrators what happened.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId       CO ID
   * @param  Integer $petitionId CoPetition ID
   * @param  Integer $coPersonId Enrollee CoPerson ID
   * @throws RuntimeException If a save fails
   */

  protected function contain($coId, $petitionId, $coPersonId) {
    $CoPerson = ClassRegistry::init('CoPerson');
    $dbc = $CoPerson->getDataSource();

    // Each role save recalculates the CoPerson's status from its roles
    foreach($this->sqlRows('SELECT id, status FROM ' . $this->table('co_person_roles')
                           . ' WHERE co_person_id = ? AND co_person_role_id IS NULL AND deleted IS NOT TRUE'
                           . ' ORDER BY id', array($coPersonId)) as $role) {
      if(in_array($role['status'], $this->suspendable, true)) {
        $CoPerson->CoPersonRole->clear();
        $CoPerson->CoPersonRole->id = (int)$role['id'];

        if(!$CoPerson->CoPersonRole->saveField('status', StatusEnum::Suspended, array('provision' => true))) {
          throw new RuntimeException(_txt('er.db.save-a', array('CoPersonRole')));
        }
      }
    }

    // A CoPerson with no suspendable role is suspended directly
    $dbc->flushQueryCache();
    $CoPerson->clear();
    $CoPerson->id = $coPersonId;
    $status = $CoPerson->field('status');

    if(in_array($status, $this->suspendable, true)
       && !$CoPerson->saveField('status', StatusEnum::Suspended, array('provision' => true))) {
      throw new RuntimeException(_txt('er.db.save-a', array('CoPerson')));
    }

    $CoPerson->HistoryRecord->record($coPersonId, null, null, null, AteHistoryActionEnum::Contained,
                                     _txt('pl.applicationteamenroller.rs.contained', array($petitionId)));

    ClassRegistry::init('CoPetitionHistoryRecord')->record($petitionId, null, AteHistoryActionEnum::Contained,
                                                           _txt('pl.applicationteamenroller.rs.contained.petition'));

    try {
      $adminGroupId = ClassRegistry::init('CoGroup')->adminCoGroupId($coId);

      ClassRegistry::init('CoNotification')->register(
        $coPersonId,
        null,
        null,
        'cogroup',
        $adminGroupId,
        AteNotificationActionEnum::Contained,
        _txt('pl.applicationteamenroller.notification.contained', array($coPersonId, $petitionId)),
        Router::url(array(
          'plugin'     => null,
          'controller' => 'co_people',
          'action'     => 'canvas',
          $coPersonId
        ), true),
        false
      );
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not notify the CO admins of CO ' . $coId . ' that CO Person '
                 . $coPersonId . ' was suspended: ' . $e->getMessage(), LOG_ERROR);
    }
  }

  /**
   * With the requeue parameter, make this job requeue itself: CoJob::finish()
   * registers the next run requeue minutes later when the job's
   * requeue_interval is set (and, on failure, when retry_interval is). A job
   * that already carries an interval (a requeued run) keeps it.
   *
   * @since  COmanage Registry v4.6.0
   * @param  CoJob $CoJob  CO Job Object
   * @param  Array $params Job parameters
   * @throws RuntimeException If the update fails
   */

  protected function scheduleRequeue($CoJob, $params) {
    $minutes = isset($params['requeue']) ? (int)$params['requeue'] : 0;

    if($minutes <= 0 || empty($CoJob->id)) {
      return;
    }

    $current = $CoJob->field('requeue_interval', array('CoJob.id' => $CoJob->id));

    if(!empty($current)) {
      return;
    }

    $ok = $CoJob->updateAll(
      array('CoJob.requeue_interval' => $minutes * 60, 'CoJob.retry_interval' => $minutes * 60),
      array('CoJob.id' => $CoJob->id)
    );

    if(!$ok) {
      throw new RuntimeException(_txt('er.db.save-a', array('CoJob')));
    }
  }

  /**
   * Record a step on the job's history, if there is a job.
   *
   * @since  COmanage Registry v4.6.0
   * @param  CoJob   $CoJob      CO Job Object, or null
   * @param  String  $key        Record key
   * @param  String  $comment    Comment
   * @param  Integer $coPersonId CoPerson ID, or null
   * @param  String  $status     JobStatusEnum
   */

  protected function jobRecord($CoJob, $key, $comment, $coPersonId = null, $status = JobStatusEnum::Complete) {
    if($CoJob && !empty($CoJob->id)) {
      $CoJob->CoJobHistoryRecord->record($CoJob->id, $key, $comment, $coPersonId, null, $status);
    }
  }

  /**
   * Log and record a failed step. Any transaction the step left open is
   * rolled back first.
   *
   * @since  COmanage Registry v4.6.0
   * @param  CoJob     $CoJob CO Job Object, or null
   * @param  String    $key   Record key
   * @param  Exception $e     Failure
   * @return Integer          1, for the error count
   */

  protected function failure($CoJob, $key, $e) {
    $dbc = $this->getDataSource();

    while($dbc->inTransaction()) {
      $dbc->rollback();
    }

    $this->log('ApplicationTeamEnroller expiry job: ' . $key . ': ' . $e->getMessage(), LOG_ERROR);

    try {
      $this->jobRecord($CoJob, $key, _txt('pl.applicationteamenroller.job.expire_invitations.error',
                                          array($e->getMessage())), null, JobStatusEnum::Failed);
    } catch(Exception $ignored) {
      // Already logged
    }

    return 1;
  }

  /**
   * A physical table name, with the datasource's prefix. This model has no
   * table of its own (CoJobBackend), so it has no tablePrefix.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $name Table name without prefix
   * @return String
   */

  protected function table($name) {
    $config = $this->getDataSource()->config;

    return (isset($config['prefix']) ? $config['prefix'] : '') . $name;
  }

  /**
   * Run a parameterized SELECT without the query cache and return flat rows.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $sql    SQL with ? placeholders
   * @param  Array  $params Parameters
   * @return Array          Rows as column => value
   * @throws RuntimeException If the query fails
   */

  protected function sqlRows($sql, $params) {
    $result = $this->getDataSource()->fetchAll($sql, $params, array('cache' => false));

    if($result === false) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.query'));
    }

    $rows = array();

    foreach((array)$result as $row) {
      $flat = array();

      foreach($row as $part) {
        $flat = array_merge($flat, (array)$part);
      }

      $rows[] = $flat;
    }

    return $rows;
  }
}
