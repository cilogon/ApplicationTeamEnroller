<?php
/**
 * U2: the plugin's tables, models, associations, and validation.
 *
 * Covers the unique rules (one research team per CoGroup, one mapping row per
 * application and team), enum validation on the audit records, the
 * cmPluginHasMany declarations, and the rule that core deletes never erase
 * audit history (KTD3, "Data model additions").
 */

class SchemaAndAssociationsTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  /** @var Integer CO seeded for this test */
  protected $coId = null;

  /** @var String Tag for this test's fixture rows */
  protected $tag = null;

  public function setUp() {
    $this->fx = new AteFixtures();
    $this->tag = AteFixtures::tag('ate-u2');
    $this->coId = $this->fx->co($this->tag);
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor($this->coId));
    }
  }

  /**
   * Every plugin model loads and reads its own cm_ate_* table.
   */
  public function testEveryModelReadsItsTable() {
    $tables = array(
      'AteApplication' => 'cm_ate_applications',
      'AteResearchTeam' => 'cm_ate_research_teams',
      'AteApplicationTeam' => 'cm_ate_application_teams',
      'AteInvitation' => 'cm_ate_invitations',
      'AteEnrollmentRequest' => 'cm_ate_enrollment_requests',
      'AteEnrollmentRequestTeam' => 'cm_ate_enrollment_request_teams',
      'AteSetting' => 'cm_ate_settings'
    );

    foreach($tables as $name => $table) {
      $m = $this->model('ApplicationTeamEnroller.' . $name);
      $this->assertTrue($m instanceof $name, "$name should load as its own class");
      $this->assertEqual($table, $m->tablePrefix . $m->table);
      $this->assertTrue(is_int($m->find('count')), "$name should count rows from the real DB");
    }
  }

  /**
   * Co owns the settings and applications; CoGroup references research teams
   * without cascading; the audit models are never listed, so core deletes
   * never reach them.
   */
  public function testCmPluginHasManyDeclaresCoAndCoGroupOnly() {
    $plugin = $this->model('ApplicationTeamEnroller.ApplicationTeamEnroller');
    $has = $plugin->cmPluginHasMany;

    $this->assertEqual(array('Co', 'CoGroup'), array_keys($has));
    $this->assertEqual(array('AteSetting', 'AteApplication'), $has['Co']);

    $this->assertEqual(1, count($has['CoGroup']));
    $cfg = reset($has['CoGroup']);
    $this->assertEqual('AteResearchTeam', $cfg['className']);
    $this->assertEqual('co_group_id', $cfg['foreignKey']);
    $this->assertFalse($cfg['dependent'], 'a CoGroup delete must not cascade into research teams');

    $flat = json_encode($has);
    foreach(array('AteInvitation', 'AteEnrollmentRequest', 'AteEnrollmentRequestTeam', 'AteApplicationTeam') as $m) {
      $this->assertFalse(strpos($flat, '"' . $m . '"') !== false, "$m must not be in cmPluginHasMany");
    }
  }

  /**
   * Changelog columns on configuration tables only; audit tables are plain
   * rows that are written once per transition.
   */
  public function testChangelogColumnsOnlyOnConfigurationTables() {
    $config = array(
      'cm_ate_applications' => 'ate_application_id',
      'cm_ate_research_teams' => 'ate_research_team_id',
      'cm_ate_application_teams' => 'ate_application_team_id',
      'cm_ate_settings' => 'ate_setting_id'
    );
    foreach($config as $table => $parentfk) {
      foreach(array($parentfk, 'revision', 'deleted', 'actor_identifier') as $col) {
        $this->assertEqual(1, $this->columnCount($table, $col), "$table should have $col");
      }
    }

    foreach(array('cm_ate_invitations', 'cm_ate_enrollment_requests', 'cm_ate_enrollment_request_teams') as $table) {
      foreach(array('revision', 'deleted', 'actor_identifier') as $col) {
        $this->assertEqual(0, $this->columnCount($table, $col), "$table must not have $col");
      }
    }
  }

  /**
   * Audit foreign keys are nullable, so history survives its referents.
   */
  public function testAuditForeignKeysAreNullable() {
    $cols = array(
      'cm_ate_invitations' => array('co_id', 'inviter_co_person_id', 'invitee_co_person_id',
                                    'link_target_co_person_id', 'co_petition_id',
                                    'revoked_by_co_person_id'),
      'cm_ate_enrollment_requests' => array('ate_invitation_id', 'ate_application_id',
                                            'decider_co_person_id', 'withdrawn_by_co_person_id'),
      'cm_ate_enrollment_request_teams' => array('ate_enrollment_request_id', 'ate_research_team_id')
    );

    foreach($cols as $table => $list) {
      foreach($list as $col) {
        $nullable = $this->fx->scalar("SELECT is_nullable FROM information_schema.columns
                                        WHERE table_name = '$table' AND column_name = '$col'");
        $this->assertEqual('YES', $nullable, "$table.$col should be a nullable column");
      }
    }
  }

  /**
   * One research team per CoGroup.
   */
  public function testSecondResearchTeamForSameCoGroupFailsValidation() {
    $groupId = $this->fx->group($this->coId, 'team ' . $this->tag);
    $Team = $this->model('ApplicationTeamEnroller.AteResearchTeam');

    $Team->create();
    $this->assertNotEmpty($Team->save(array('co_group_id' => $groupId,
                                            'status' => AteConfigStatusEnum::Active)),
      'the first team for a group should save');

    $Team->create();
    $this->assertFalse($Team->save(array('co_group_id' => $groupId,
                                         'status' => AteConfigStatusEnum::Active)),
      'a second team for the same group must fail');
    $this->assertTrue(isset($Team->validationErrors['co_group_id']),
      'the failure should be reported on co_group_id');
    $this->assertEqual(1, $this->fx->count('cm_ate_research_teams', 'co_group_id = ' . $groupId));
  }

  /**
   * The unique rules are validation rules, not database indexes, because
   * ChangelogBehavior archives an edit as a copy of the row with the same
   * values. Editing twice must work, and a soft-deleted team must not block
   * designating the group again.
   */
  public function testResearchTeamEditAndRedesignateAfterDelete() {
    $groupId = $this->fx->group($this->coId, 'team ' . $this->tag);
    $Team = $this->model('ApplicationTeamEnroller.AteResearchTeam');

    $Team->create();
    $this->assertNotEmpty($Team->save(array('co_group_id' => $groupId, 'name' => 'one',
                                            'status' => AteConfigStatusEnum::Active)));
    $id = $Team->id;

    foreach(array('two', 'three') as $name) {
      $Team->clear();
      $this->assertNotEmpty($Team->save(array('id' => $id, 'co_group_id' => $groupId, 'name' => $name,
                                              'status' => AteConfigStatusEnum::Active)),
        "editing the team to $name should save");
    }
    $this->assertEqual(3, $this->fx->count('cm_ate_research_teams', 'co_group_id = ' . $groupId),
      'the current row plus two archive copies');

    $this->assertTrue($Team->delete($id), 'the team should soft-delete');

    $Team->create();
    $this->assertNotEmpty($Team->save(array('co_group_id' => $groupId,
                                            'status' => AteConfigStatusEnum::Active)),
      'a deleted team must not block designating the group again');
  }

  /**
   * One mapping row per application and team.
   */
  public function testDuplicateApplicationTeamFailsValidation() {
    $groupId = $this->fx->group($this->coId, 'team ' . $this->tag);
    $teamId = $this->fx->researchTeam($groupId);
    $appId = $this->fx->application($this->coId, 'app ' . $this->tag);
    $Map = $this->model('ApplicationTeamEnroller.AteApplicationTeam');

    $row = array('ate_application_id' => $appId, 'ate_research_team_id' => $teamId);

    $Map->create();
    $this->assertNotEmpty($Map->save($row), 'the first mapping row should save');

    $Map->create();
    $this->assertFalse($Map->save($row), 'a duplicate mapping row must fail');
    $this->assertTrue(isset($Map->validationErrors['ate_research_team_id']),
      'the failure should be reported on ate_research_team_id');

    // The same team for another application is fine (R3).
    $otherAppId = $this->fx->application($this->coId, 'other app ' . $this->tag);
    $Map->create();
    $this->assertNotEmpty($Map->save(array('ate_application_id' => $otherAppId,
                                           'ate_research_team_id' => $teamId)),
      'a team may be authorized for any number of applications');
  }

  /**
   * Registry soft-deletes CoGroups and does not null plugin foreign keys. The
   * team row and its request-team history stay, and the team reports that
   * its group is gone.
   */
  public function testDeletedCoGroupKeepsTeamAndRequestTeamRows() {
    $groupId = $this->fx->group($this->coId, 'team ' . $this->tag);
    $teamId = $this->fx->researchTeam($groupId);
    $appId = $this->fx->application($this->coId, 'app ' . $this->tag);
    $invId = $this->fx->invitation($this->coId);
    $reqId = $this->fx->enrollmentRequest($invId, $appId);
    $rtId = $this->fx->enrollmentRequestTeam($reqId, $teamId);

    $Team = $this->model('ApplicationTeamEnroller.AteResearchTeam');
    $this->assertFalse($Team->groupDeleted($teamId), 'the group is live before the delete');

    $CoGroup = $this->model('CoGroup');
    $this->assertTrue($CoGroup->delete($groupId), 'the CoGroup should (soft) delete');
    $this->assertEqual(1, $this->fx->count('cm_co_groups', 'id = ' . $groupId . ' AND deleted = true'));

    $this->assertEqual(1, $this->fx->count('cm_ate_research_teams',
      'id = ' . $teamId . ' AND co_group_id = ' . $groupId . ' AND deleted IS NOT TRUE'));
    $this->assertEqual(1, $this->fx->count('cm_ate_enrollment_request_teams',
      'id = ' . $rtId . ' AND ate_research_team_id = ' . $teamId));

    ConnectionManager::getDataSource('default')->flushQueryCache();
    $this->assertTrue($Team->groupDeleted($teamId), 'the team should report its group as deleted');
  }

  /**
   * Status values outside the enumeration never reach the audit tables.
   */
  public function testInvitationWithUnknownStatusFailsValidation() {
    $Inv = $this->model('ApplicationTeamEnroller.AteInvitation');
    $row = array(
      'co_id' => $this->coId,
      'invited_email' => 'someone@example.org',
      'status' => 'bogus',
      'expires' => date('Y-m-d H:i:s', time() + 86400),
      'token_hash' => hash('sha256', 'u2-' . $this->tag)
    );

    $Inv->create();
    $this->assertFalse($Inv->save($row), 'an unknown status must fail');
    $this->assertTrue(isset($Inv->validationErrors['status']));

    $row['status'] = AteInvitationStatusEnum::Sent;
    $Inv->create();
    $this->assertNotEmpty($Inv->save($row), 'a known status should save');
  }

  /**
   * token_hash is unique at the database, so two invitations can never share
   * a link (KTD4).
   */
  public function testInvitationTokenHashIsUniqueInDatabase() {
    $hash = hash('sha256', 'dup-' . $this->tag);
    $this->fx->invitation($this->coId, array('token_hash' => $hash));

    $threw = false;
    try {
      $this->fx->invitation($this->coId, array('token_hash' => $hash));
    } catch(Exception $e) {
      $threw = true;
    }
    $this->assertTrue($threw, 'a second invitation with the same token_hash must be rejected');
  }

  /**
   * The enumerations carry the Product Contract's values and KTD12's action
   * codes, and the request and team models validate against them.
   */
  public function testEnumerationsAndRequestValidation() {
    $this->assertEqual(array('sent', 'responded', 'revoked', 'expired'),
                       AteInvitationStatusEnum::$values);
    $this->assertEqual(array('offered', 'declined_by_enrollee', 'pending_decision', 'approved',
                             'denied', 'revoked', 'expired'),
                       AteRequestStatusEnum::$values);
    $this->assertEqual(array('approval', 'mismatch', 'link_required'), AtePendingReasonEnum::$values);
    $this->assertEqual(array('approver', 'inviting_admin', 'co_admin', 'automatic'),
                       AteDecidedByRoleEnum::$values);
    $this->assertEqual(array('added', 'already_present', 'skipped'), AteTeamOutcomeEnum::$values);
    $this->assertEqual('pAPD', AteNotificationActionEnum::PendingDecision);
    $this->assertEqual('pADC', AteNotificationActionEnum::Decided);
    $this->assertEqual('pAEX', AteNotificationActionEnum::Expired);

    $appId = $this->fx->application($this->coId, 'app ' . $this->tag);
    $invId = $this->fx->invitation($this->coId);
    $Req = $this->model('ApplicationTeamEnroller.AteEnrollmentRequest');

    $bad = array(
      'status' => array('status' => 'maybe'),
      'pending_reason' => array('status' => AteRequestStatusEnum::PendingDecision, 'pending_reason' => 'whim'),
      'decided_by_role' => array('status' => AteRequestStatusEnum::Approved, 'decided_by_role' => 'janitor')
    );
    foreach($bad as $field => $fields) {
      $Req->create();
      $this->assertFalse($Req->save($fields + array('ate_invitation_id' => $invId,
                                                    'ate_application_id' => $appId)),
        "an unknown $field must fail");
      $this->assertTrue(isset($Req->validationErrors[$field]), "the failure should be on $field");
    }

    $Req->create();
    $this->assertNotEmpty($Req->save(array('ate_invitation_id' => $invId,
                                           'ate_application_id' => $appId,
                                           'status' => AteRequestStatusEnum::PendingDecision,
                                           'pending_reason' => AtePendingReasonEnum::LinkRequired)));
    $reqId = $Req->id;

    $groupId = $this->fx->group($this->coId, 'team ' . $this->tag);
    $teamId = $this->fx->researchTeam($groupId);
    $RT = $this->model('ApplicationTeamEnroller.AteEnrollmentRequestTeam');
    $RT->create();
    $this->assertFalse($RT->save(array('ate_enrollment_request_id' => $reqId,
                                       'ate_research_team_id' => $teamId,
                                       'outcome' => 'teleported')),
      'an unknown team outcome must fail');
    $RT->create();
    $this->assertNotEmpty($RT->save(array('ate_enrollment_request_id' => $reqId,
                                          'ate_research_team_id' => $teamId,
                                          'outcome' => AteTeamOutcomeEnum::AlreadyPresent)));
  }

  /**
   * The request reads its invitation, application, and offered teams through
   * the declared associations.
   */
  public function testRequestAssociationsResolve() {
    $groupId = $this->fx->group($this->coId, 'team ' . $this->tag);
    $teamId = $this->fx->researchTeam($groupId);
    $appId = $this->fx->application($this->coId, 'app ' . $this->tag);
    $invId = $this->fx->invitation($this->coId);
    $reqId = $this->fx->enrollmentRequest($invId, $appId);
    $this->fx->enrollmentRequestTeam($reqId, $teamId);

    $Req = $this->model('ApplicationTeamEnroller.AteEnrollmentRequest');
    $row = $Req->find('first', array(
      'conditions' => array('AteEnrollmentRequest.id' => $reqId),
      'contain' => array('AteInvitation', 'AteApplication',
                         'AteEnrollmentRequestTeam' => array('AteResearchTeam'))
    ));

    $this->assertEqual($invId, (int)$row['AteInvitation']['id']);
    $this->assertEqual($appId, (int)$row['AteApplication']['id']);
    $this->assertEqual(1, count($row['AteEnrollmentRequestTeam']));
    $this->assertEqual($groupId, (int)$row['AteEnrollmentRequestTeam'][0]['AteResearchTeam']['co_group_id']);
  }

  /** Count columns named $col in $table (0 or 1). */
  protected function columnCount($table, $col) {
    return $this->fx->count('information_schema.columns',
      "table_name = '$table' AND column_name = '$col'");
  }
}
