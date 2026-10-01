<?php
/**
 * U4: authorizing research teams for an application (R3, R12). Only active
 * research teams of the application's CO are offered or accepted.
 */

class AteApplicationTeamTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $coId = null;
  protected $otherCoId = null;
  protected $appId = null;
  protected $t = array();

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u4-map');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    $this->appId = $this->fx->application($this->coId, 'App ' . $tag);
    $otherAppId = $this->fx->application($this->coId, 'Other App ' . $tag);

    $this->t['active'] = $this->fx->researchTeam($this->fx->group($this->coId, 'active ' . $tag),
                                                 array('name' => 'Active'));
    $this->t['sharedElsewhere'] = $this->fx->researchTeam($this->fx->group($this->coId, 'shared ' . $tag),
                                                          array('name' => 'Shared'));
    $this->t['retired'] = $this->fx->researchTeam($this->fx->group($this->coId, 'retired ' . $tag),
                                                  array('name' => 'Retired', 'status' => 'retired'));
    $this->t['mapped'] = $this->fx->researchTeam($this->fx->group($this->coId, 'mapped ' . $tag),
                                                 array('name' => 'Mapped'));
    $this->t['groupDeleted'] = $this->fx->researchTeam(
      $this->fx->group($this->coId, 'gone ' . $tag, array('deleted' => true)), array('name' => 'Gone'));
    $this->t['foreign'] = $this->fx->researchTeam($this->fx->group($this->otherCoId, 'foreign ' . $tag),
                                                  array('name' => 'Foreign'));

    $this->fx->applicationTeam($this->appId, $this->t['mapped']);
    // A team may serve any number of applications (R3)
    $this->fx->applicationTeam($otherAppId, $this->t['sharedElsewhere']);
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /**
   * The mapping picker offers only active research teams of the CO that are
   * not already authorized for this application.
   */
  public function testOnlyActiveUnmappedTeamsOfTheCoAreOffered() {
    $teams = $this->model('ApplicationTeamEnroller.AteApplicationTeam')->availableTeams($this->appId);

    $this->assertTrue(isset($teams[$this->t['active']]), 'an active team is offered');
    $this->assertTrue(isset($teams[$this->t['sharedElsewhere']]), 'a team mapped to another application is offered');

    foreach(array('retired', 'mapped', 'groupDeleted', 'foreign') as $name) {
      $this->assertFalse(isset($teams[$this->t[$name]]), "the $name team must not be offered");
    }
  }

  /**
   * Saving a mapping row enforces the same rule, so a crafted form cannot
   * authorize a retired team or another CO's team.
   */
  public function testMappingRejectsRetiredOrForeignTeam() {
    $Map = $this->model('ApplicationTeamEnroller.AteApplicationTeam');
    $expected = _txt('pl.applicationteamenroller.er.application_team.team');

    foreach(array('retired', 'foreign', 'groupDeleted') as $name) {
      $Map->clear();
      $this->assertFalse($Map->save(array('ate_application_id' => $this->appId,
                                          'ate_research_team_id' => $this->t[$name])), "$name must fail");
      $this->assertEqual(array($expected), $Map->validationErrors['ate_research_team_id'], "$name message");
    }

    $Map->clear();
    $this->assertNotEmpty($Map->save(array('ate_application_id' => $this->appId,
                                           'ate_research_team_id' => $this->t['active'])),
      json_encode($Map->validationErrors));
  }

  /**
   * findCoForRecord maps a mapping row to its CO through its application.
   */
  public function testFindCoForRecord() {
    $id = $this->fx->applicationTeam($this->appId, $this->t['active']);

    $this->assertEqual($this->coId,
      (int)$this->model('ApplicationTeamEnroller.AteApplicationTeam')->findCoForRecord($id));
  }
}
