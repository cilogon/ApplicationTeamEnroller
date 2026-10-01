<?php
/**
 * U4: designating research teams (R2, R12). The picker offers only standard,
 * non-automatic groups of the CO that are not already research teams and are
 * not an application's access group, so CO:admins and the members groups can
 * never become teams.
 */

class AteResearchTeamTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $coId = null;
  protected $otherCoId = null;
  protected $g = array();

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u4-team');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    $this->g['plain'] = $this->fx->group($this->coId, 'plain ' . $tag);
    $this->g['mapped'] = $this->fx->group($this->coId, 'mapped ' . $tag);
    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . $tag, array('group_type' => 'A'));
    $this->g['members'] = $this->fx->group($this->coId, 'CO:members:all ' . $tag,
                                           array('group_type' => 'M', 'auto' => true));
    $this->g['active'] = $this->fx->group($this->coId, 'CO:members:active ' . $tag,
                                          array('group_type' => 'MA', 'auto' => true));
    $this->g['approvers'] = $this->fx->group($this->coId, 'CO:approvers ' . $tag, array('group_type' => 'AP'));
    $this->g['access'] = $this->fx->group($this->coId, 'access ' . $tag);
    $this->g['deleted'] = $this->fx->group($this->coId, 'deleted ' . $tag, array('deleted' => true));
    $this->g['foreign'] = $this->fx->group($this->otherCoId, 'foreign ' . $tag);

    // An existing team, and an application whose access group is g['access']
    $this->fx->researchTeam($this->g['mapped'], array('name' => 'Mapped'));
    $this->fx->application($this->coId, 'App ' . $tag, array('access_co_group_id' => $this->g['access']));
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /**
   * The picker offers neither CO:admins nor a group already mapped to a team,
   * nor any other ineligible group, and does offer a plain group.
   */
  public function testPickerExcludesAdminAndAlreadyMappedGroups() {
    $groups = $this->model('ApplicationTeamEnroller.AteResearchTeam')->availableGroups($this->coId);

    $this->assertTrue(isset($groups[$this->g['plain']]), 'a plain standard group is offered');

    foreach(array('coadmins', 'mapped', 'members', 'active', 'approvers', 'access', 'deleted', 'foreign') as $name) {
      $this->assertFalse(isset($groups[$this->g[$name]]), "the $name group must not be offered");
    }
  }

  /**
   * isEligibleGroup applies the same rule to a posted value, so a crafted
   * form cannot designate a group the picker would not offer.
   */
  public function testEligibilityMatchesThePicker() {
    $Team = $this->model('ApplicationTeamEnroller.AteResearchTeam');

    $this->assertTrue($Team->isEligibleGroup($this->coId, $this->g['plain']));

    foreach(array('coadmins', 'mapped', 'members', 'approvers', 'access', 'deleted', 'foreign') as $name) {
      $this->assertFalse($Team->isEligibleGroup($this->coId, $this->g[$name]), "$name is not eligible");
    }

    $this->assertFalse($Team->isEligibleGroup($this->coId, 999999999), 'an unknown group is not eligible');
  }

  /**
   * A team saved without a name takes its group's name, so pickers and
   * lists always have something to show.
   */
  public function testTeamNameDefaultsToGroupName() {
    $Team = $this->model('ApplicationTeamEnroller.AteResearchTeam');

    $Team->clear();
    $this->assertNotEmpty($Team->save(array('co_group_id' => $this->g['plain'],
                                            'status' => AteConfigStatusEnum::Active)),
      json_encode($Team->validationErrors));

    $name = $this->fx->scalar('SELECT name FROM cm_co_groups WHERE id = ' . $this->g['plain']);
    $this->assertEqual($name, $this->fx->scalar('SELECT name FROM cm_ate_research_teams WHERE id = ' . (int)$Team->id));
  }

  /**
   * findCoForRecord maps a team to its CO through its group, since the team
   * row carries no co_id.
   */
  public function testFindCoForRecord() {
    $id = $this->fx->researchTeam($this->g['plain']);

    $this->assertEqual($this->coId,
      (int)$this->model('ApplicationTeamEnroller.AteResearchTeam')->findCoForRecord($id));
  }
}
