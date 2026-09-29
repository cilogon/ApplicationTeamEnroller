<?php
/**
 * Shared world for the U7 routing and approval engine tests (MismatchTest,
 * RoutingTest, ApprovalTest).
 *
 * Every fixture is built so that fixed and broken code disagree (the U7
 * Execution note): the invited address, the login's other address, and a
 * second person's address are genuinely different, and each address that a
 * test says "belongs" to someone is a verified row on a different CoPerson.
 *
 * Applications and teams:
 *   A  approval required   teams T1, T2
 *   B  approval required   team  TS
 *   C  approval off        teams T4, TS   (TS is shared with B)
 *   D  approval off        team  T5
 *
 * The CO's login identifier type is set to 'eppn', not the default
 * 'oidcsub', so a test can tell the configured type from the snapshot's.
 */

App::uses('CakeLog', 'Log');
App::uses('BaseLog', 'Log/Engine');
App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');
App::uses('AteEnrollmentRequest', 'ApplicationTeamEnroller.Model');

/**
 * A CakeLog engine that records every message, so a test can assert that a
 * lookup error was logged. Configured as engine 'AteRecording'.
 */
class AteRecordingLog extends BaseLog {
  public static $lines = array();

  public function write($type, $message) {
    self::$lines[] = $type . ': ' . $message;
    return true;
  }
}

/**
 * The engine model with its seams observable: provisioning is recorded
 * (with whether a transaction was still open), a membership write can be
 * made to fail for one group, and a lookup can be made to throw.
 */
class AteEnrollmentRequestProbe extends AteEnrollmentRequest {
  public $provisioned = array();
  public $failMembershipForGroup = null;
  public $failLookup = false;

  protected function provisionCoPerson($coPersonId) {
    $this->provisioned[] = array(
      'co_person_id' => (int)$coPersonId,
      'in_transaction' => $this->getDataSource()->inTransaction()
    );
  }

  protected function writeMembership($coGroupId, $coPersonId, $actorCoPersonId) {
    if($this->failMembershipForGroup !== null && (int)$coGroupId === (int)$this->failMembershipForGroup) {
      throw new RuntimeException('probe: membership write failed');
    }

    return parent::writeMembership($coGroupId, $coPersonId, $actorCoPersonId);
  }

  public function existingMemberCoPersonId($coId, $identifier) {
    if($this->failLookup) {
      throw new RuntimeException('probe: login lookup failed');
    }

    return parent::existingMemberCoPersonId($coId, $identifier);
  }
}

class AteEngineTestCase extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  /** @var AteEnrollmentRequestProbe */
  protected $Req = null;

  protected $coId = null;
  protected $otherCoId = null;

  // Fixture ids by short name
  protected $p = array();
  protected $g = array();
  protected $app = array();
  protected $team = array();

  // Genuinely different addresses
  const Invited = 'pat@uni.example';
  const Other   = 'pat@gmail.example';
  const Third   = 'sam@lab.example';

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u7');

    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    foreach(array('inviter', 'approverA', 'approverB', 'coAdmin', 'p1', 'p2', 'p3') as $name) {
      $this->p[$name] = $this->fx->person($this->coId);
    }
    $this->p['otherCo'] = $this->fx->person($this->otherCoId);

    foreach(array('admins', 'approversA', 'approversB', 'approversC', 'approversD',
                  't1', 't2', 't4', 'ts', 't5') as $name) {
      $this->g[$name] = $this->fx->group($this->coId, $name . ' ' . $tag);
    }

    $this->fx->member($this->g['admins'], $this->p['inviter']);
    $this->fx->member($this->g['approversA'], $this->p['approverA']);
    $this->fx->member($this->g['approversB'], $this->p['approverB']);

    foreach(array('A' => true, 'B' => true, 'C' => false, 'D' => false) as $name => $approval) {
      $this->app[$name] = $this->fx->application($this->coId, 'App ' . $name . ' ' . $tag, array(
        'admin_co_group_id' => $this->g['admins'],
        'approver_co_group_id' => $this->g['approvers' . $name],
        'approval_required' => $approval
      ));
    }

    foreach(array('t1', 't2', 't4', 'ts', 't5') as $name) {
      $this->team[$name] = $this->fx->researchTeam($this->g[$name], array('name' => 'Team ' . $name));
    }

    $this->fx->applicationTeam($this->app['A'], $this->team['t1']);
    $this->fx->applicationTeam($this->app['A'], $this->team['t2']);
    $this->fx->applicationTeam($this->app['B'], $this->team['ts']);
    $this->fx->applicationTeam($this->app['C'], $this->team['t4']);
    $this->fx->applicationTeam($this->app['C'], $this->team['ts']);
    $this->fx->applicationTeam($this->app['D'], $this->team['t5']);

    $this->fx->insert('cm_ate_settings', array(
      'co_id' => $this->coId,
      'invitation_lifetime_days' => 14,
      'login_identifier_type' => 'eppn',
      'email_env_vars' => 'OIDC_CLAIM_email',
      'revision' => 0,
      'deleted' => false,
      'ate_setting_id' => null
    ));

    $this->Req = new AteEnrollmentRequestProbe(array(
      'alias' => 'AteEnrollmentRequest',
      'table' => 'ate_enrollment_requests',
      'ds' => 'default'
    ));

    AteRecordingLog::$lines = array();
    CakeLog::config('ate_recording', array('engine' => 'AteRecording'));
  }

  public function tearDown() {
    CakeLog::drop('ate_recording');
    AteRecordingLog::$lines = array();

    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /**
   * A login: an OrgIdentity in the CO carrying $identifier as an Active
   * login Identifier, with verified and unverified email rows, linked to
   * $coPersonId unless it is null.
   *
   * @return Integer OrgIdentity ID
   */
  protected function login($coPersonId, $identifier, $verified = array(), $unverified = array(),
                           $identifierFields = array(), $coId = null) {
    $oid = $this->fx->orgIdentity($coId ?: $this->coId);

    $this->fx->identifier($identifier, $identifierFields + array('org_identity_id' => $oid));

    foreach($verified as $mail) {
      $this->fx->emailAddress($mail, array('org_identity_id' => $oid, 'verified' => true));
    }

    foreach($unverified as $mail) {
      $this->fx->emailAddress($mail, array('org_identity_id' => $oid, 'verified' => false));
    }

    if($coPersonId) {
      $this->fx->orgIdentityLink($coPersonId, $oid);
    }

    return $oid;
  }

  /** A unique login identifier for this test. */
  protected function sub($name) {
    return 'http://cilogon.org/serverT/users/' . $name . '-' . substr(uniqid(), -6);
  }

  /** An identity snapshot as U8 builds it (KTD5). */
  protected function snapshot($identifier, $emails, $extra = array()) {
    return $extra + array(
      'identifier' => $identifier,
      'identifier_type' => 'oidcsub',
      'emails' => $emails,
      'name' => 'Pat Researcher',
      'errors' => array()
    );
  }

  /**
   * A sent invitation from the inviter to $email, with one offered request
   * per application key and its team keys.
   *
   * @return Array 'id' and 'req' (request IDs keyed by application key)
   */
  protected function invitation($email, $offers, $overrides = array()) {
    $inv = $this->fx->invitation($this->coId, $overrides + array(
      'invited_email' => $email,
      'inviter_co_person_id' => $this->p['inviter']
    ));

    $req = array();

    foreach($offers as $appKey => $teamKeys) {
      $req[$appKey] = $this->fx->enrollmentRequest($inv, $this->app[$appKey]);

      foreach($teamKeys as $teamKey) {
        $this->fx->enrollmentRequestTeam($req[$appKey], $this->team[$teamKey]);
      }
    }

    return array('id' => $inv, 'req' => $req);
  }

  /** Accept every request of an invitation. */
  protected function acceptAll($inv) {
    return array_fill_keys(array_values($inv['req']), true);
  }

  /** The request row. */
  protected function requestRow($requestId) {
    $rows = $this->fx->rows('SELECT * FROM cm_ate_enrollment_requests WHERE id = ' . (int)$requestId);
    return $rows[0];
  }

  /** The invitation row. */
  protected function invitationRow($invitationId) {
    $rows = $this->fx->rows('SELECT * FROM cm_ate_invitations WHERE id = ' . (int)$invitationId);
    return $rows[0];
  }

  /** Per-team outcomes of a request, keyed by team key. */
  protected function outcomes($requestId) {
    $ret = array();
    $keys = array_flip($this->team);

    foreach($this->fx->rows('SELECT ate_research_team_id, outcome FROM cm_ate_enrollment_request_teams'
                            . ' WHERE ate_enrollment_request_id = ' . (int)$requestId) as $r) {
      $ret[ $keys[(int)$r['ate_research_team_id']] ] = $r['outcome'];
    }

    ksort($ret);
    return $ret;
  }

  /** Current direct (not nesting-derived) membership rows. */
  protected function directRows($groupKey, $coPersonId) {
    return $this->fx->rows('SELECT * FROM cm_co_group_members WHERE co_group_id = ' . (int)$this->g[$groupKey]
                           . ' AND co_person_id = ' . (int)$coPersonId
                           . ' AND co_group_nesting_id IS NULL AND co_group_member_id IS NULL'
                           . ' AND deleted IS NOT true ORDER BY id');
  }

  /** Current membership rows of any kind. */
  protected function allRows($groupKey, $coPersonId) {
    return $this->fx->rows('SELECT * FROM cm_co_group_members WHERE co_group_id = ' . (int)$this->g[$groupKey]
                           . ' AND co_person_id = ' . (int)$coPersonId
                           . ' AND co_group_member_id IS NULL AND deleted IS NOT true ORDER BY id');
  }

  /** How many CoPeople and OrgIdentities the CO has. */
  protected function peopleAndOrgIdentities() {
    return array(
      'people' => $this->fx->count('cm_co_people', 'co_id = ' . (int)$this->coId),
      'org_identities' => $this->fx->count('cm_org_identities', 'co_id = ' . (int)$this->coId)
    );
  }

  /** Assert $fn throws $class; returns the exception. */
  protected function assertThrows($fn, $class, $label) {
    $thrown = null;

    try {
      $fn();
    } catch(Exception $e) {
      $thrown = $e;
    }

    $this->assertTrue($thrown instanceof $class,
      "$label: expected $class, got " . ($thrown ? get_class($thrown) . ': ' . $thrown->getMessage() : 'no exception'));
    $this->assertFalse(ConnectionManager::getDataSource('default')->inTransaction(),
      "$label: the transaction must be closed");

    return $thrown;
  }
}
