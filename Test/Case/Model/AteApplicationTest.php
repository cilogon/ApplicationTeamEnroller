<?php
/**
 * U4: applications (R1, R4). Editing updates the row in place, and the admin
 * and approver groups are existing groups of the application's own CO.
 */

class AteApplicationTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $coId = null;
  protected $otherCoId = null;
  protected $adminGroupId = null;
  protected $approverGroupId = null;

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u4-app');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');
    $this->adminGroupId = $this->fx->group($this->coId, 'admins ' . $tag);
    $this->approverGroupId = $this->fx->group($this->coId, 'approvers ' . $tag);
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /** A valid application row for the test CO, with $overrides applied. */
  private function row($overrides = array()) {
    return $overrides + array(
      'co_id' => $this->coId,
      'name' => 'App ' . uniqid(),
      'client_identifier' => 'cilogon:/client_id/abc',
      'admin_co_group_id' => $this->adminGroupId,
      'approver_co_group_id' => $this->approverGroupId,
      'approval_required' => true,
      'status' => AteConfigStatusEnum::Active
    );
  }

  /**
   * Editing an application twice leaves exactly one current row, with the
   * same id and the last values. ChangelogBehavior keeps the prior versions
   * as archive rows pointing at it.
   */
  public function testEditingApplicationTwiceLeavesOneRow() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');

    $App->clear();
    $this->assertNotEmpty($App->save($this->row(array('name' => 'first'))), json_encode($App->validationErrors));
    $id = (int)$App->id;

    foreach(array('second', 'third') as $name) {
      $App->clear();
      $this->assertNotEmpty($App->save($this->row(array('id' => $id, 'name' => $name))),
        "edit to $name: " . json_encode($App->validationErrors));
    }

    $current = 'co_id = ' . $this->coId . ' AND ate_application_id IS NULL AND deleted IS NOT TRUE';
    $this->assertEqual(1, $this->fx->count('cm_ate_applications', $current), 'exactly one current application row');
    $this->assertEqual($id, (int)$this->fx->scalar('SELECT id FROM cm_ate_applications WHERE ' . $current));
    $this->assertEqual('third', $this->fx->scalar('SELECT name FROM cm_ate_applications WHERE id = ' . $id));
  }

  /**
   * The admin and approver groups are required, and each must be a current
   * group of the application's CO (R4).
   */
  public function testGroupsMustBeCurrentGroupsOfTheCo() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');
    $foreignGroupId = $this->fx->group($this->otherCoId, AteFixtures::tag('foreign'));
    $deletedGroupId = $this->fx->group($this->coId, AteFixtures::tag('deleted'), array('deleted' => true));
    $expected = _txt('pl.applicationteamenroller.er.application.group');

    foreach(array('admin_co_group_id', 'approver_co_group_id') as $field) {
      foreach(array('foreign' => $foreignGroupId, 'deleted' => $deletedGroupId, 'unknown' => 999999999) as $label => $gid) {
        $App->clear();
        $this->assertFalse($App->save($this->row(array($field => $gid))), "$field $label must fail");
        $this->assertEqual(array($expected), $App->validationErrors[$field], "$field $label message");
      }

      $row = $this->row();
      unset($row[$field]);
      $App->clear();
      $this->assertFalse($App->save($row), "$field is required");
      $this->assertTrue(isset($App->validationErrors[$field]), "$field missing is reported");
    }

    $this->assertEqual(0, $this->fx->count('cm_ate_applications', 'co_id = ' . $this->coId));
  }

  /**
   * The admin and approver groups may be the same group (R4), and the OIDC
   * client identifier is optional (R1).
   */
  public function testAdminAndApproverMayBeTheSameGroup() {
    $App = $this->model('ApplicationTeamEnroller.AteApplication');

    $App->clear();
    $this->assertNotEmpty($App->save($this->row(array('approver_co_group_id' => $this->adminGroupId,
                                                      'client_identifier' => null))),
      json_encode($App->validationErrors));
  }

  /**
   * The picker for the admin and approver groups lists the current groups of
   * the CO only.
   */
  public function testGroupPickerListsOnlyThisCosCurrentGroups() {
    $foreignGroupId = $this->fx->group($this->otherCoId, AteFixtures::tag('foreign'));
    $deletedGroupId = $this->fx->group($this->coId, AteFixtures::tag('deleted'), array('deleted' => true));

    $groups = $this->model('ApplicationTeamEnroller.AteApplication')->availableGroups($this->coId);

    $this->assertTrue(isset($groups[$this->adminGroupId]), 'a group of the CO is offered');
    $this->assertTrue(isset($groups[$this->approverGroupId]), 'a group of the CO is offered');
    $this->assertFalse(isset($groups[$foreignGroupId]), "another CO's group is not offered");
    $this->assertFalse(isset($groups[$deletedGroupId]), 'a deleted group is not offered');
  }

  /**
   * findCoForRecord maps an application to its CO, so Registry derives the
   * current CO from the record on edit and view.
   */
  public function testFindCoForRecord() {
    $id = $this->fx->application($this->coId, 'mapped ' . uniqid());

    $this->assertEqual($this->coId,
      (int)$this->model('ApplicationTeamEnroller.AteApplication')->findCoForRecord($id));
  }
}
