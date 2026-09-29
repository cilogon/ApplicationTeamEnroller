<?php
/**
 * Row-level fixture helper for the ApplicationTeamEnroller thin runner.
 *
 * CakePHP 2.x's PHPUnit fixture machinery does not run on this stack, so
 * database-backed tests seed the rows they need directly and drop them again
 * in tearDown. Every insert is recorded so cleanup() can delete in reverse
 * order and satisfy the schema's foreign keys without the test having to
 * track ids itself.
 *
 * The hermetic environment is Postgres (Test/docker/docker-compose.yml), so
 * INSERT ... RETURNING id is used to recover generated ids.
 *
 * Copied from the Oa4mpClient plugin's Oa4mpFixtures (KTD15).
 */

App::uses('ConnectionManager', 'Model');

class AteFixtures {

  /** @var DboSource */
  protected $db;

  /** @var array List of array('table' => ..., 'id' => ...) in insertion order. */
  protected $rows = array();

  public function __construct() {
    $this->db = ConnectionManager::getDataSource('default');
  }

  /**
   * Insert one row and return its generated id.
   *
   * @param  String $table  Physical table name (with the cm_ prefix)
   * @param  Array  $fields Column => value. A null value is written as NULL.
   * @return Integer
   */
  public function insert($table, $fields) {
    $cols = array();
    $vals = array();
    foreach($fields as $col => $val) {
      $cols[] = '"' . $col . '"';
      $vals[] = ($val === null) ? 'NULL' : $this->db->value($val);
    }

    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
      . implode(', ', $vals) . ') RETURNING id';

    $id = $this->scalar($sql);
    if($id === null) {
      throw new Exception("fixture insert into $table returned no id");
    }

    $id = (int)$id;
    $this->rows[] = array('table' => $table, 'id' => $id);
    return $id;
  }

  /** Run arbitrary SQL and return the raw CakePHP result set. */
  public function query($sql) {
    return $this->db->query($sql);
  }

  /**
   * Run a query expected to yield a single value and return it (null if none).
   * Tolerates both result shapes CakePHP 2 produces for aliased and bare
   * columns.
   *
   * CakePHP 2's DboSource caches every SELECT result in-process, keyed by the
   * literal SQL text, and nothing in application code flushes it on a write.
   * The cache is flushed first, so reading the same count before and after a
   * write sees the write.
   */
  public function scalar($sql) {
    $this->db->flushQueryCache();
    $result = $this->db->query($sql);
    if(empty($result)) {
      return null;
    }
    $row = array_shift($result);
    while(is_array($row)) {
      $next = array_shift($row);
      if(!is_array($next)) {
        return $next;
      }
      $row = $next;
    }
    return $row;
  }

  /**
   * Run a SELECT and return its rows as flat column => value arrays. Like
   * scalar(), it flushes the query cache first.
   */
  public function rows($sql) {
    $this->db->flushQueryCache();
    $ret = array();
    foreach((array)$this->db->query($sql) as $row) {
      $flat = array();
      foreach($row as $part) {
        $flat = array_merge($flat, (array)$part);
      }
      $ret[] = $flat;
    }
    return $ret;
  }

  /** Convenience: count rows matching a WHERE clause. */
  public function count($table, $where) {
    return (int)$this->scalar('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . $where);
  }

  /**
   * Register a row this helper did not insert (for example one created by the
   * code under test) so cleanup() removes it too.
   */
  public function track($table, $id) {
    $this->rows[] = array('table' => $table, 'id' => (int)$id);
  }

  /**
   * Seed a CO. $tag makes the unique name, so a leaked fixture is traceable
   * to the test that made it.
   */
  public function co($tag) {
    return $this->insert('cm_cos', array(
      'name' => 'CO ' . $tag,
      'description' => 'hermetic test CO',
      'status' => 'A'
    ));
  }

  /**
   * Seed a CO person in $coId. $overrides sets or adds columns.
   *
   * co_person_id is the ChangelogBehavior self-reference and must be NULL for
   * a current (non-historical) row.
   */
  public function person($coId, $overrides = array()) {
    return $this->insert('cm_co_people', $overrides + array(
      'co_id' => $coId,
      'status' => 'A',
      'deleted' => false,
      'co_person_id' => null
    ));
  }

  /**
   * Seed a CO group in $coId named $name. $overrides sets or adds columns.
   *
   * co_group_id is the ChangelogBehavior self-reference and must be NULL for
   * a current (non-historical) row.
   */
  public function group($coId, $name, $overrides = array()) {
    return $this->insert('cm_co_groups', $overrides + array(
      'co_id' => $coId,
      'name' => $name,
      'description' => 'hermetic test group',
      'open' => false,
      'status' => 'A',
      'group_type' => 'S',
      'auto' => false,
      'nesting_mode_all' => false,
      'deleted' => false,
      'co_group_id' => null
    ));
  }

  /**
   * Seed a membership of $coPersonId in $groupId. $overrides sets or adds
   * columns.
   *
   * co_group_member_id is the ChangelogBehavior self-reference and must be
   * NULL for a current (non-historical) row.
   */
  public function member($groupId, $coPersonId, $overrides = array()) {
    return $this->insert('cm_co_group_members', $overrides + array(
      'co_group_id' => $groupId,
      'co_person_id' => $coPersonId,
      'member' => true,
      'owner' => false,
      'deleted' => false,
      'co_group_member_id' => null
    ));
  }

  /**
   * Seed a nesting of group $groupId into group $targetGroupId, directly in
   * the database: Registry's CoGroupNesting callbacks do not run, so no
   * derived membership is reconciled. $overrides sets or adds columns.
   *
   * co_group_nesting_id is the ChangelogBehavior self-reference and must be
   * NULL for a current (non-historical) row.
   */
  public function nesting($groupId, $targetGroupId, $overrides = array()) {
    return $this->insert('cm_co_group_nestings', $overrides + array(
      'co_group_id' => $groupId,
      'target_co_group_id' => $targetGroupId,
      'negate' => false,
      'revision' => 0,
      'deleted' => false,
      'co_group_nesting_id' => null
    ));
  }

  /**
   * Seed an enrollment flow in $coId that qualifies as the newcomer flow
   * (U4): any authenticated user, no approval, no email verification, no
   * Self or Select match policy. $overrides sets or adds columns.
   *
   * co_enrollment_flow_id is the ChangelogBehavior self-reference and must be
   * NULL for a current (non-historical) row.
   */
  public function flow($coId, $name, $overrides = array()) {
    return $this->insert('cm_co_enrollment_flows', $overrides + array(
      'co_id' => $coId,
      'name' => $name,
      'status' => 'A',
      'authz_level' => 'AU',
      'approval_required' => false,
      'email_verification_mode' => 'X',
      'match_policy' => 'N',
      'revision' => 0,
      'deleted' => false,
      'co_enrollment_flow_id' => null
    ));
  }

  /**
   * Seed an enrollment flow wedge on $flowId, by default an active wedge of
   * this plugin. $overrides sets or adds columns.
   */
  public function wedge($flowId, $overrides = array()) {
    return $this->insert('cm_co_enrollment_flow_wedges', $overrides + array(
      'co_enrollment_flow_id' => $flowId,
      'description' => 'hermetic test wedge',
      'plugin' => 'ApplicationTeamEnroller',
      'status' => 'A',
      'revision' => 0,
      'deleted' => false,
      'co_enrollment_flow_wedge_id' => null
    ));
  }

  /**
   * Seed an application (cm_ate_applications) in $coId. $overrides sets or
   * adds columns, for example the admin, approver, or access group ids.
   *
   * ate_application_id is the ChangelogBehavior self-reference and must be
   * NULL for a current (non-historical) row.
   */
  public function application($coId, $name, $overrides = array()) {
    return $this->insert('cm_ate_applications', $overrides + array(
      'co_id' => $coId,
      'name' => $name,
      'approval_required' => true,
      'status' => 'active',
      'revision' => 0,
      'deleted' => false,
      'ate_application_id' => null
    ));
  }

  /**
   * Seed a research team (cm_ate_research_teams) backed by CoGroup $groupId.
   *
   * ate_research_team_id is the ChangelogBehavior self-reference and must be
   * NULL for a current (non-historical) row.
   */
  public function researchTeam($groupId, $overrides = array()) {
    return $this->insert('cm_ate_research_teams', $overrides + array(
      'co_group_id' => $groupId,
      'status' => 'active',
      'revision' => 0,
      'deleted' => false,
      'ate_research_team_id' => null
    ));
  }

  /**
   * Seed an application-to-team mapping row (cm_ate_application_teams).
   *
   * ate_application_team_id is the ChangelogBehavior self-reference and must
   * be NULL for a current (non-historical) row.
   */
  public function applicationTeam($applicationId, $researchTeamId, $overrides = array()) {
    return $this->insert('cm_ate_application_teams', $overrides + array(
      'ate_application_id' => $applicationId,
      'ate_research_team_id' => $researchTeamId,
      'revision' => 0,
      'deleted' => false,
      'ate_application_team_id' => null
    ));
  }

  /**
   * Seed an invitation (cm_ate_invitations) in $coId. The token hash is
   * random so several invitations never collide on its unique index.
   */
  public function invitation($coId, $overrides = array()) {
    return $this->insert('cm_ate_invitations', $overrides + array(
      'co_id' => $coId,
      'invited_email' => 'invitee-' . substr(uniqid(), -6) . '@example.org',
      'status' => 'sent',
      'expires' => date('Y-m-d H:i:s', time() + 14 * 86400),
      'token_hash' => hash('sha256', random_bytes(32)),
      'mismatch' => false,
      'expiry_notified' => false
    ));
  }

  /** Seed a per-application request (cm_ate_enrollment_requests). */
  public function enrollmentRequest($invitationId, $applicationId, $overrides = array()) {
    return $this->insert('cm_ate_enrollment_requests', $overrides + array(
      'ate_invitation_id' => $invitationId,
      'ate_application_id' => $applicationId,
      'status' => 'offered'
    ));
  }

  /** Seed an offered team on a request (cm_ate_enrollment_request_teams). */
  public function enrollmentRequestTeam($requestId, $researchTeamId, $overrides = array()) {
    return $this->insert('cm_ate_enrollment_request_teams', $overrides + array(
      'ate_enrollment_request_id' => $requestId,
      'ate_research_team_id' => $researchTeamId
    ));
  }

  /**
   * Seed an OrgIdentity in $coId. $overrides sets or adds columns.
   *
   * org_identity_id is the ChangelogBehavior self-reference and must be NULL
   * for a current (non-historical) row.
   */
  public function orgIdentity($coId, $overrides = array()) {
    return $this->insert('cm_org_identities', $overrides + array(
      'co_id' => $coId,
      'revision' => 0,
      'deleted' => false,
      'org_identity_id' => null
    ));
  }

  /**
   * Seed an Identifier. $fields names the owner (org_identity_id or
   * co_person_id) and anything else; by default it is an Active login
   * identifier of type oidcsub.
   *
   * identifier_id is the ChangelogBehavior self-reference and must be NULL
   * for a current (non-historical) row.
   */
  public function identifier($identifier, $fields = array()) {
    return $this->insert('cm_identifiers', $fields + array(
      'identifier' => $identifier,
      'type' => 'oidcsub',
      'login' => true,
      'status' => 'A',
      'revision' => 0,
      'deleted' => false,
      'identifier_id' => null
    ));
  }

  /**
   * Seed an EmailAddress. $fields names the owner (org_identity_id or
   * co_person_id) and anything else; by default it is verified.
   *
   * email_address_id is the ChangelogBehavior self-reference and must be
   * NULL for a current (non-historical) row.
   */
  public function emailAddress($mail, $fields = array()) {
    return $this->insert('cm_email_addresses', $fields + array(
      'mail' => $mail,
      'type' => 'official',
      'verified' => true,
      'revision' => 0,
      'deleted' => false,
      'email_address_id' => null
    ));
  }

  /**
   * Seed a CoOrgIdentityLink between $coPersonId and $orgIdentityId.
   *
   * co_org_identity_link_id is the ChangelogBehavior self-reference and must
   * be NULL for a current (non-historical) row.
   */
  public function orgIdentityLink($coPersonId, $orgIdentityId, $overrides = array()) {
    return $this->insert('cm_co_org_identity_links', $overrides + array(
      'co_person_id' => $coPersonId,
      'org_identity_id' => $orgIdentityId,
      'revision' => 0,
      'deleted' => false,
      'co_org_identity_link_id' => null
    ));
  }

  /**
   * The cleanup() $alsoPurge map that removes every plugin row belonging to
   * the COs, including rows the code under test created and
   * ChangelogBehavior archive copies, children before parents.
   *
   * It also removes the CO's groups and the Registry rows that hang off them
   * (nestings, memberships, identifiers, history records), because the
   * plugin creates an access group for every application (KTD10) and
   * Registry derives memberships and history from its nestings. It removes
   * the CO's OrgIdentities and their identifiers, email addresses, and links
   * too, because approving a request can link a login (U7).
   *
   * Pass every CO a test seeded in one call. The map is keyed by table, so
   * array_merge() of two maps keeps only the second CO's clauses.
   *
   * @param  Integer|Array $coIds CO ID, or a list of CO IDs
   * @return Array                table => WHERE clause
   */
  public function pluginRowsFor($coIds) {
    $cos = implode(', ', array_map('intval', (array)$coIds));
    $inv = 'SELECT id FROM cm_ate_invitations WHERE co_id IN (' . $cos . ')';
    $req = 'SELECT id FROM cm_ate_enrollment_requests WHERE ate_invitation_id IN (' . $inv . ')';
    $app = 'SELECT id FROM cm_ate_applications WHERE co_id IN (' . $cos . ')';
    $grp = 'SELECT id FROM cm_co_groups WHERE co_id IN (' . $cos . ')';
    $ppl = 'SELECT id FROM cm_co_people WHERE co_id IN (' . $cos . ')';
    $oid = 'SELECT id FROM cm_org_identities WHERE co_id IN (' . $cos . ')';

    return array(
      'cm_ate_enrollment_request_teams' => 'ate_enrollment_request_id IN (' . $req . ')',
      'cm_ate_enrollment_requests' => 'ate_invitation_id IN (' . $inv . ')',
      'cm_ate_invitations' => 'co_id IN (' . $cos . ')',
      'cm_ate_application_teams' => 'ate_application_id IN (' . $app . ')',
      'cm_ate_applications' => 'co_id IN (' . $cos . ')',
      'cm_ate_research_teams' => 'co_group_id IN (' . $grp . ')',
      'cm_ate_settings' => 'co_id IN (' . $cos . ')',
      'cm_history_records' => 'co_group_id IN (' . $grp . ') OR co_person_id IN (' . $ppl . ')'
                              . ' OR org_identity_id IN (' . $oid . ')',
      'cm_co_group_members' => 'co_group_id IN (' . $grp . ')',
      'cm_co_group_nestings' => 'co_group_id IN (' . $grp . ') OR target_co_group_id IN (' . $grp . ')',
      'cm_identifiers' => 'co_group_id IN (' . $grp . ') OR org_identity_id IN (' . $oid . ')'
                          . ' OR co_person_id IN (' . $ppl . ')',
      'cm_email_addresses' => 'org_identity_id IN (' . $oid . ') OR co_person_id IN (' . $ppl . ')',
      'cm_co_org_identity_links' => 'org_identity_id IN (' . $oid . ') OR co_person_id IN (' . $ppl . ')',
      'cm_org_identities' => 'co_id IN (' . $cos . ')',
      'cm_co_groups' => 'co_id IN (' . $cos . ')'
    );
  }

  /** A unique, traceable tag for one test's fixture rows. */
  public static function tag($prefix) {
    return $prefix . '-' . getmypid() . '-' . substr(uniqid(), -6);
  }

  /**
   * Delete every tracked row plus any rows the code under test created in the
   * tables named, newest first, so foreign keys are satisfied.
   *
   * @param Array $alsoPurge table => WHERE clause, deleted before tracked rows
   */
  public function cleanup($alsoPurge = array()) {
    foreach($alsoPurge as $table => $where) {
      $this->db->query('DELETE FROM ' . $table . ' WHERE ' . $where);
    }

    foreach(array_reverse($this->rows) as $row) {
      $this->db->query('DELETE FROM ' . $row['table'] . ' WHERE id = ' . (int)$row['id']);
    }

    $this->rows = array();
  }
}
