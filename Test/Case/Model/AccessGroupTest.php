<?php
/**
 * U5: access group maintenance (R3, R31, AE14, KTD10). Every application has
 * a plugin-created access group whose nested team groups match its
 * application-to-team mapping, and Registry's own CoGroupNesting logic turns
 * that nesting into derived memberships.
 *
 * Everything here runs against real cm_co_groups, cm_co_group_nestings, and
 * cm_co_group_members rows, through Registry's own models.
 */

App::uses('AteApplicationsController', 'ApplicationTeamEnroller.Controller');
App::uses('AteApplicationTeamsController', 'ApplicationTeamEnroller.Controller');

class AccessGroupApplicationsHarness extends AteApplicationsController {
  use AteControllerHarness;
}

class AccessGroupApplicationTeamsHarness extends AteApplicationTeamsController {
  use AteControllerHarness;
}

class AccessGroupTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $tag = null;
  protected $coId = null;
  protected $otherCoId = null;
  // Group ids by name: admins, approvers, t1, t2, t3
  protected $g = array();
  // Research team ids by name: t1, t2, t3
  protected $t = array();

  public function setUp() {
    $this->fx = new AteFixtures();
    $this->tag = AteFixtures::tag('ate-u5');
    $this->coId = $this->fx->co($this->tag);
    $this->otherCoId = $this->fx->co($this->tag . '-other');

    foreach(array('admins', 'approvers', 't1', 't2', 't3') as $name) {
      $this->g[$name] = $this->fx->group($this->coId, $name . ' ' . $this->tag);
    }

    foreach(array('t1', 't2', 't3') as $name) {
      $this->t[$name] = $this->fx->researchTeam($this->g[$name], array('name' => 'Team ' . $name));
    }
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /** Create an application named $name through the model; return its id. */
  private function createApplication($name) {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $App->clear();

    $saved = $App->save(array('AteApplication' => array(
      'co_id' => $this->coId,
      'name' => $name,
      'admin_co_group_id' => $this->g['admins'],
      'approver_co_group_id' => $this->g['approvers'],
      'approval_required' => true,
      'status' => AteConfigStatusEnum::Active
    )));
    $this->assertNotEmpty($saved, 'application should save: ' . json_encode($App->validationErrors));

    return (int)$App->id;
  }

  /** The access group id of application $appId, read from the database. */
  private function accessGroupOf($appId) {
    return (int)$this->fx->scalar('SELECT access_co_group_id FROM cm_ate_applications WHERE id = ' . (int)$appId);
  }

  /** Authorize research team $team for $appId through the model; return the row id. */
  private function map($appId, $team) {
    $Map = $this->model('ApplicationTeamEnroller.AteApplicationTeam');
    $Map->clear();

    $saved = $Map->save(array('AteApplicationTeam' => array(
      'ate_application_id' => $appId,
      'ate_research_team_id' => $this->t[$team]
    )));
    $this->assertNotEmpty($saved, "mapping $team should save: " . json_encode($Map->validationErrors));

    return (int)$Map->id;
  }

  /** Remove a mapping row through the model, as the controller's delete does. */
  private function unmap($mapId) {
    $Map = $this->model('ApplicationTeamEnroller.AteApplicationTeam');
    $Map->clear();
    $this->assertTrue((bool)$Map->delete($mapId), "mapping $mapId should delete");
  }

  /** Current nestings into group $targetId, as source group id => negate. */
  private function nestingsInto($targetId) {
    $rows = $this->fx->rows('SELECT co_group_id, negate FROM cm_co_group_nestings WHERE target_co_group_id = '
                            . (int)$targetId . ' AND co_group_nesting_id IS NULL AND deleted IS NOT TRUE'
                            . ' ORDER BY co_group_id');
    $ret = array();

    foreach($rows as $r) {
      $ret[ (int)$r['co_group_id'] ] = (bool)$r['negate'];
    }

    return $ret;
  }

  /** Current members of group $groupId, as co_person_id => co_group_nesting_id (null if direct). */
  private function membersOf($groupId) {
    $rows = $this->fx->rows('SELECT co_person_id, co_group_nesting_id FROM cm_co_group_members WHERE co_group_id = '
                            . (int)$groupId . ' AND co_group_member_id IS NULL AND deleted IS NOT TRUE'
                            . ' ORDER BY co_person_id');
    $ret = array();

    foreach($rows as $r) {
      $ret[ (int)$r['co_person_id'] ] = ($r['co_group_nesting_id'] === null) ? null : (int)$r['co_group_nesting_id'];
    }

    return $ret;
  }

  /** Add $personId to group $groupId through Registry's model, so nestings sync. */
  private function addMember($groupId, $personId) {
    $Member = $this->model('CoGroupMember');
    $Member->clear();

    $saved = $Member->save(array('CoGroupMember' => array(
      'co_group_id' => $groupId,
      'co_person_id' => $personId,
      'member' => true,
      'owner' => false
    )));
    $this->assertNotEmpty($saved, 'membership should save: ' . json_encode($Member->validationErrors));
  }

  /** A CO administrator's roles. */
  private function admin() {
    return array('coadmin' => true, 'comember' => true, 'user' => true, 'copersonid' => 1);
  }

  /**
   * Creating an application creates exactly one access group with the KTD10
   * settings: Standard, not open, not automatic, any-of nesting, active, and
   * with no members and no nestings.
   */
  public function testCreatingApplicationCreatesAccessGroup() {
    $name = 'Genomics ' . $this->tag;
    $groupsBefore = $this->fx->count('cm_co_groups', 'co_id = ' . $this->coId);

    $appId = $this->createApplication($name);
    $accessId = $this->accessGroupOf($appId);

    $this->assertTrue($accessId > 0, 'the application records its access group');
    $this->assertEqual($groupsBefore + 1, $this->fx->count('cm_co_groups', 'co_id = ' . $this->coId),
      'exactly one group is created');

    $g = $this->fx->rows('SELECT * FROM cm_co_groups WHERE id = ' . $accessId);
    $g = $g[0];

    $this->assertEqual($this->coId, (int)$g['co_id']);
    $this->assertEqual($name, $g['name'], 'named after the application');
    $this->assertEqual('S', $g['group_type'], 'a standard group');
    $this->assertEqual('A', $g['status'], 'active');
    $this->assertFalse((bool)$g['open'], 'not open');
    $this->assertFalse((bool)$g['auto'], 'not automatic');
    $this->assertFalse((bool)$g['nesting_mode_all'], 'any-of nesting');
    $this->assertFalse((bool)$g['deleted']);
    $this->assertNull($g['co_group_id'], 'a current group');

    $this->assertEqual(array(), $this->membersOf($accessId), 'no members');
    $this->assertEqual(array(), $this->nestingsInto($accessId), 'no nestings yet');

    // The group was set on the insert itself, so there is no archive copy
    $this->assertEqual(0, $this->fx->count('cm_ate_applications', 'ate_application_id = ' . $appId),
      'no changelog archive row from creating the access group');
  }

  /**
   * The U4 add screen goes through the same path, and a posted access group
   * is ignored: the plugin always creates its own.
   */
  public function testCreatingApplicationThroughControllerCreatesAccessGroup() {
    $name = 'Portal ' . $this->tag;
    $h = AccessGroupApplicationsHarness::harnessBuild('ate_applications', $this->coId, $this->admin(), array(
      'AteApplication' => array(
        'name' => $name,
        'admin_co_group_id' => $this->g['admins'],
        'approver_co_group_id' => $this->g['approvers'],
        'access_co_group_id' => $this->g['t1'],
        'approval_required' => true,
        'status' => AteConfigStatusEnum::Active
      )
    ));
    $h->harnessInvoke('add', array(), 'POST');
    $this->assertTrue($h->harnessStopped, 'the application should save: ' . json_encode($h->Flash->messages));

    $appId = (int)$this->fx->scalar("SELECT id FROM cm_ate_applications WHERE co_id = " . $this->coId
                                    . " AND name = " . ConnectionManager::getDataSource('default')->value($name));
    $accessId = $this->accessGroupOf($appId);

    $this->assertTrue($accessId > 0, 'an access group is created');
    $this->assertFalse(in_array($accessId, $this->g, true), 'not one of the existing groups');
    $this->assertEqual($name, $this->fx->scalar('SELECT name FROM cm_co_groups WHERE id = ' . $accessId));
  }

  /**
   * A name collision with an existing group of the CO yields a numeric
   * suffix, not a failure, and the next free suffix is used.
   */
  public function testAccessGroupNameCollisionGetsSuffix() {
    $name = 'Clinical ' . $this->tag;
    $this->fx->group($this->coId, $name);
    $this->fx->group($this->coId, $name . '-2');
    // A same-named group in another CO is no collision for this one
    $this->fx->group($this->otherCoId, $name . '-3');

    $first = $this->createApplication($name);
    $this->assertEqual($name . '-3', $this->fx->scalar('SELECT name FROM cm_co_groups WHERE id = '
                                                       . $this->accessGroupOf($first)));

    // A second application of the same name collides with the first's group
    $second = $this->createApplication($name);
    $this->assertEqual($name . '-4', $this->fx->scalar('SELECT name FROM cm_co_groups WHERE id = '
                                                       . $this->accessGroupOf($second)));
  }

  /**
   * Editing an application keeps its access group and creates no other, and
   * a failed save creates none.
   */
  public function testEditingApplicationKeepsAccessGroup() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $appId = $this->createApplication('Keep ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);
    $groups = $this->fx->count('cm_co_groups', 'co_id = ' . $this->coId);

    $App->clear();
    $this->assertNotEmpty($App->save(array('AteApplication' => array(
      'id' => $appId,
      'co_id' => $this->coId,
      'name' => 'Renamed ' . $this->tag,
      'admin_co_group_id' => $this->g['admins'],
      'approver_co_group_id' => $this->g['approvers'],
      'status' => AteConfigStatusEnum::Active
    ))), json_encode($App->validationErrors));

    $this->assertEqual($accessId, $this->accessGroupOf($appId), 'same access group after an edit');

    // An invalid application (no admin group) creates no group
    $App->clear();
    $this->assertFalse($App->save(array('AteApplication' => array(
      'co_id' => $this->coId,
      'name' => 'Invalid ' . $this->tag,
      'approver_co_group_id' => $this->g['approvers'],
      'status' => AteConfigStatusEnum::Active
    ))));

    $this->assertEqual($groups, $this->fx->count('cm_co_groups', 'co_id = ' . $this->coId), 'no group created');
  }

  /**
   * Covers AE14. Mapping T1 to A nests T1's group in A's access group, so a
   * member of T1 -- existing or added later -- is a derived member of the
   * access group, and a member of an unmapped T2 is not. Removing the
   * mapping removes the nesting and the derived memberships.
   */
  public function testMappingDerivesMembershipAndUnmappingRemovesIt() {
    $appId = $this->createApplication('AE14 ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);

    $before = $this->fx->person($this->coId);
    $after = $this->fx->person($this->coId);
    $onlyT2 = $this->fx->person($this->coId);
    $this->addMember($this->g['t1'], $before);
    $this->addMember($this->g['t2'], $before);
    $this->addMember($this->g['t2'], $onlyT2);

    $mapId = $this->map($appId, 't1');

    $this->assertEqual(array($this->g['t1'] => false), $this->nestingsInto($accessId), 'T1 is nested, not negated');
    $nestingId = (int)$this->fx->scalar('SELECT id FROM cm_co_group_nestings WHERE target_co_group_id = '
                                        . $accessId . ' AND co_group_nesting_id IS NULL AND deleted IS NOT TRUE');

    $this->assertEqual(array($before => $nestingId), $this->membersOf($accessId),
      'an existing member of T1 becomes a derived member; a member of T2 alone does not');

    $this->addMember($this->g['t1'], $after);
    $this->assertEqual(array($before => $nestingId, $after => $nestingId), $this->membersOf($accessId),
      'a later member of T1 becomes a derived member');

    $this->unmap($mapId);

    $this->assertEqual(array(), $this->nestingsInto($accessId), 'the nesting is gone');
    $this->assertEqual(array(), $this->membersOf($accessId), 'the derived memberships are gone');
    // The team groups themselves are untouched
    $this->assertEqual(2, count($this->membersOf($this->g['t1'])));
  }

  /**
   * The U4 mapping screens go through the same path: adding a mapping
   * through the controller nests the team, and deleting it through the
   * controller removes the nesting.
   */
  public function testMappingThroughControllerMaintainsNesting() {
    $appId = $this->createApplication('Screens ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);

    $h = AccessGroupApplicationTeamsHarness::harnessBuild('ate_application_teams', $this->coId, $this->admin(), array(
      'AteApplicationTeam' => array(
        'ate_application_id' => $appId,
        'ate_research_team_id' => $this->t['t2']
      )
    ));
    $h->request->params['named'] = array('appid' => $appId);
    $h->harnessInvoke('add', array(), 'POST');
    $this->assertTrue($h->harnessStopped, 'mapping should save: ' . json_encode($h->Flash->messages));

    $this->assertEqual(array($this->g['t2'] => false), $this->nestingsInto($accessId));

    $mapId = (int)$this->fx->scalar('SELECT id FROM cm_ate_application_teams WHERE ate_application_id = '
                                    . $appId . ' AND ate_application_team_id IS NULL AND deleted IS NOT TRUE');
    $h = AccessGroupApplicationTeamsHarness::harnessBuild('ate_application_teams', $this->coId, $this->admin());
    $h->harnessInvoke('delete', array($mapId), 'POST');

    $this->assertEqual(array(), $this->nestingsInto($accessId), 'deleting the mapping removes the nesting');
  }

  /**
   * An edit of a mapping row leaves a changelog archive copy of the old
   * value; the nestings follow the current row only.
   */
  public function testEditingMappingMovesNestingToCurrentTeam() {
    $appId = $this->createApplication('Moved ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);
    $mapId = $this->map($appId, 't1');

    $Map = $this->model('ApplicationTeamEnroller.AteApplicationTeam');
    $Map->clear();
    $this->assertNotEmpty($Map->save(array('AteApplicationTeam' => array(
      'id' => $mapId,
      'ate_application_id' => $appId,
      'ate_research_team_id' => $this->t['t2']
    ))), json_encode($Map->validationErrors));

    $this->assertEqual(1, $this->fx->count('cm_ate_application_teams', 'ate_application_team_id = ' . $mapId),
      'the edit left an archive copy');
    $this->assertEqual(array($this->g['t2'] => false), $this->nestingsInto($accessId),
      'only the current team is nested');
  }

  /**
   * Resync re-creates a nesting someone deleted by hand and removes one
   * someone added by hand, reports both, and then has nothing left to do.
   */
  public function testResyncRepairsDeletedAndExtraNestings() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $appId = $this->createApplication('Drift ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);
    $this->map($appId, 't1');
    $this->map($appId, 't2');

    $p1 = $this->fx->person($this->coId);
    $p3 = $this->fx->person($this->coId);
    $this->addMember($this->g['t1'], $p1);
    $this->addMember($this->g['t3'], $p3);

    // Someone deletes T1's nesting and nests the unmapped T3, in Registry's UI
    $Nesting = $this->model('CoGroupNesting');
    $t1Nesting = (int)$this->fx->scalar('SELECT id FROM cm_co_group_nestings WHERE target_co_group_id = '
                                        . $accessId . ' AND co_group_id = ' . $this->g['t1']
                                        . ' AND co_group_nesting_id IS NULL AND deleted IS NOT TRUE');
    $Nesting->clear();
    $this->assertTrue((bool)$Nesting->delete($t1Nesting));
    $Nesting->clear();
    $this->assertNotEmpty($Nesting->save(array('CoGroupNesting' => array(
      'co_group_id' => $this->g['t3'],
      'target_co_group_id' => $accessId,
      'negate' => false
    ))));

    $this->assertEqual(array($this->g['t2'] => false, $this->g['t3'] => false), $this->nestingsInto($accessId));
    $this->assertEqual(array($p3), array_keys($this->membersOf($accessId)), 'drifted membership');

    $report = $App->resyncAccessGroup($appId);

    $this->assertEqual(array($this->g['t1']), $report['added'], 'T1 is re-nested');
    $this->assertEqual(array($this->g['t3']), $report['removed'], 'T3 is removed');
    $this->assertFalse($report['created'], 'the access group already existed');
    $this->assertEqual(array($this->g['t1'] => false, $this->g['t2'] => false), $this->nestingsInto($accessId));
    $this->assertEqual(array($p1), array_keys($this->membersOf($accessId)), 'memberships match the mapping again');

    $report = $App->resyncAccessGroup($appId);
    $this->assertEqual(array(), $report['added'], 'nothing more to add');
    $this->assertEqual(array(), $report['removed'], 'nothing more to remove');
  }

  /**
   * A negated nesting of a mapped team does not grant access, so resync
   * replaces it with an ordinary one.
   */
  public function testResyncReplacesNegatedNesting() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $appId = $this->createApplication('Negated ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);
    $mapId = $this->map($appId, 't1');

    // Swap the plugin's nesting for a negated one, directly in the database
    $this->unmap($mapId);
    $this->fx->applicationTeam($appId, $this->t['t1']);
    $this->fx->nesting($this->g['t1'], $accessId, array('negate' => true));
    $this->assertEqual(array($this->g['t1'] => true), $this->nestingsInto($accessId));

    $report = $App->resyncAccessGroup($appId);

    $this->assertEqual(array($this->g['t1']), $report['added']);
    $this->assertEqual(array($this->g['t1']), $report['removed']);
    $this->assertEqual(array($this->g['t1'] => false), $this->nestingsInto($accessId));
  }

  /**
   * An application saved before the plugin maintained access groups (here,
   * seeded without one) gets one from resync.
   */
  public function testResyncCreatesMissingAccessGroup() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $name = 'Legacy ' . $this->tag;
    $appId = $this->fx->application($this->coId, $name, array(
      'admin_co_group_id' => $this->g['admins'],
      'approver_co_group_id' => $this->g['approvers']
    ));
    $this->fx->applicationTeam($appId, $this->t['t1']);

    $report = $App->resyncAccessGroup($appId);
    $accessId = $this->accessGroupOf($appId);

    $this->assertTrue($report['created'], 'the access group is created');
    $this->assertTrue($accessId > 0);
    $this->assertEqual($name, $this->fx->scalar('SELECT name FROM cm_co_groups WHERE id = ' . $accessId));
    $this->assertEqual(array($this->g['t1']), $report['added']);
    $this->assertEqual(array($this->g['t1'] => false), $this->nestingsInto($accessId));
  }

  /**
   * Retiring a team or the application stops it being offered but leaves the
   * nesting and the derived memberships in place (KTD10).
   */
  public function testRetiringTeamOrApplicationKeepsNesting() {
    $appId = $this->createApplication('Retire ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);
    $this->map($appId, 't1');
    $p = $this->fx->person($this->coId);
    $this->addMember($this->g['t1'], $p);

    $Team = $this->model('ApplicationTeamEnroller.AteResearchTeam');
    $Team->clear();
    $this->assertNotEmpty($Team->save(array('AteResearchTeam' => array(
      'id' => $this->t['t1'],
      'co_group_id' => $this->g['t1'],
      'status' => AteConfigStatusEnum::Retired
    ))), json_encode($Team->validationErrors));

    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $App->clear();
    $this->assertNotEmpty($App->save(array('AteApplication' => array(
      'id' => $appId,
      'co_id' => $this->coId,
      'name' => 'Retire ' . $this->tag,
      'admin_co_group_id' => $this->g['admins'],
      'approver_co_group_id' => $this->g['approvers'],
      'status' => AteConfigStatusEnum::Retired
    ))), json_encode($App->validationErrors));

    $this->assertEqual(array($this->g['t1'] => false), $this->nestingsInto($accessId), 'the nesting stays');
    $this->assertEqual(array($p), array_keys($this->membersOf($accessId)), 'the derived membership stays');

    // Resync treats a retired team's mapping as current too
    $report = $App->resyncAccessGroup($appId);
    $this->assertEqual(array(), $report['removed'], 'resync keeps the retired team nested');
  }

  /**
   * The resync action is for CO administrators only, repairs the access
   * group, reports what changed, and returns to the application's page. It
   * refuses an application of another CO.
   */
  public function testResyncActionIsAdminOnlyAndReports() {
    $appId = $this->createApplication('Action ' . $this->tag);
    $accessId = $this->accessGroupOf($appId);
    $this->map($appId, 't1');
    $this->fx->query('UPDATE cm_co_group_nestings SET deleted = true WHERE target_co_group_id = ' . $accessId);

    $member = array('comember' => true, 'user' => true, 'copersonid' => 1);
    $h = AccessGroupApplicationsHarness::harnessBuild('ate_applications', $this->coId, $member);
    $h->action = 'resync';
    $this->assertFalse((bool)$h->isAuthorized(), 'a CO member may not resync');

    $h = AccessGroupApplicationsHarness::harnessBuild('ate_applications', $this->coId, $this->admin());
    $h->action = 'resync';
    $this->assertTrue((bool)$h->isAuthorized(), 'a CO administrator may resync');

    $target = $h->harnessInvoke('resync', array($appId), 'POST');
    $this->assertEqual('view', $target['action'], 'back to the application');
    $this->assertEqual($appId, (int)$target[0]);
    $this->assertEqual(array($this->g['t1'] => false), $this->nestingsInto($accessId), 'repaired');
    $this->assertContains('t1 ' . $this->tag, $h->Flash->last(), 'the report names the re-nested team group');

    // Another CO's application is refused and left alone
    $otherAdmins = $this->fx->group($this->otherCoId, 'admins other ' . $this->tag);
    $otherApp = $this->fx->application($this->otherCoId, 'Other ' . $this->tag, array(
      'admin_co_group_id' => $otherAdmins,
      'approver_co_group_id' => $otherAdmins
    ));
    $h = AccessGroupApplicationsHarness::harnessBuild('ate_applications', $this->coId, $this->admin());
    $h->harnessInvoke('resync', array($otherApp), 'POST');
    $this->assertNull($this->fx->scalar('SELECT access_co_group_id FROM cm_ate_applications WHERE id = ' . $otherApp),
      "another CO's application is not touched");
    $this->assertContains('error', json_encode($h->Flash->messages));
  }
}
