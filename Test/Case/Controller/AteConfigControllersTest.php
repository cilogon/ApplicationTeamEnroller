<?php
/**
 * U4: the configuration controllers (A4, R1-R3, R12). Only CO and platform
 * administrators may use them, and a CO administrator can create an
 * application, two research teams, and the mapping between them, through
 * the same controller actions the screens post to.
 *
 * Driven through Test/lib/AteControllerHarness.php without rendering; the
 * screens themselves are checked by hand in a Registry.
 */

App::uses('AteApplicationsController', 'ApplicationTeamEnroller.Controller');
App::uses('AteResearchTeamsController', 'ApplicationTeamEnroller.Controller');
App::uses('AteApplicationTeamsController', 'ApplicationTeamEnroller.Controller');
App::uses('AteSettingsController', 'ApplicationTeamEnroller.Controller');

class AteConfigApplicationsHarness extends AteApplicationsController {
  use AteControllerHarness;
}

class AteConfigResearchTeamsHarness extends AteResearchTeamsController {
  use AteControllerHarness;
}

class AteConfigApplicationTeamsHarness extends AteApplicationTeamsController {
  use AteControllerHarness;
}

class AteConfigSettingsHarness extends AteSettingsController {
  use AteControllerHarness;
}

class AteConfigControllersTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $coId = null;
  protected $otherCoId = null;
  protected $g = array();

  // Harness class => array(controller, actions an administrator may use)
  private $controllers = array(
    'AteConfigApplicationsHarness' => array('ate_applications', array('add', 'edit', 'index', 'view')),
    'AteConfigResearchTeamsHarness' => array('ate_research_teams', array('add', 'edit', 'index', 'view')),
    'AteConfigApplicationTeamsHarness' => array('ate_application_teams', array('add', 'delete')),
    'AteConfigSettingsHarness' => array('ate_settings', array('edit', 'index', 'view'))
  );

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u4-config');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');

    foreach(array('admins', 'approvers', 'team1', 'team2') as $name) {
      $this->g[$name] = $this->fx->group($this->coId, $name . ' ' . $tag);
    }
    $this->g['coadmins'] = $this->fx->group($this->coId, 'CO:admins ' . $tag, array('group_type' => 'A'));
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /** Build $class for the test CO with $roles and posted $data. */
  private function harness($class, $roles, $data = array(), $named = array()) {
    $h = $class::harnessBuild($this->controllers[$class][0], $this->coId, $roles, $data);
    $h->request->params['named'] = $named;

    return $h;
  }

  /** A CO administrator's roles. */
  private function admin() {
    return array('coadmin' => true, 'comember' => true, 'user' => true, 'copersonid' => 1);
  }

  /**
   * A CO member who is not an administrator -- including one who
   * administers or approves an application -- is denied every configuration
   * action; administrators get exactly the listed actions.
   */
  public function testNonAdminIsDeniedEveryConfigurationAction() {
    $member = array('comember' => true, 'user' => true, 'copersonid' => 1);
    $every = array('add', 'edit', 'index', 'view', 'delete', 'search', 'order');

    foreach($this->controllers as $class => $cfg) {
      foreach($every as $action) {
        $h = $this->harness($class, $member);
        $h->action = $action;
        $this->assertFalse((bool)$h->isAuthorized(), "member on $class $action");

        foreach(array('co admin' => $this->admin(), 'platform admin' => array('cmadmin' => true)) as $label => $roles) {
          $h = $this->harness($class, $roles);
          $h->action = $action;
          $this->assertEqual(in_array($action, $cfg[1], true), (bool)$h->isAuthorized(),
            "$label on $class $action");
        }
      }
    }
  }

  /**
   * A CO administrator creates an application, two research teams, and maps
   * both teams to the application.
   */
  public function testCoAdminCreatesApplicationTeamsAndMapping() {
    // The application, with a form that claims another CO
    $h = $this->harness('AteConfigApplicationsHarness', $this->admin(), array('AteApplication' => array(
      'co_id' => $this->otherCoId,
      'name' => 'Genomics Portal',
      'client_identifier' => 'cilogon:/client_id/genomics',
      'admin_co_group_id' => $this->g['admins'],
      'approver_co_group_id' => $this->g['approvers'],
      'approval_required' => true,
      'status' => AteConfigStatusEnum::Active
    )));
    $h->harnessInvoke('add', array(), 'POST');
    $this->assertTrue($h->harnessStopped, 'the application should save: ' . json_encode($h->Flash->messages));

    $appId = (int)$this->fx->scalar("SELECT id FROM cm_ate_applications WHERE name = 'Genomics Portal' AND co_id = "
                                    . $this->coId);
    $this->assertTrue($appId > 0, 'the application belongs to the current CO');
    $this->assertEqual(0, $this->fx->count('cm_ate_applications', 'co_id = ' . $this->otherCoId));

    // Two research teams
    $teamIds = array();
    foreach(array('team1', 'team2') as $name) {
      $h = $this->harness('AteConfigResearchTeamsHarness', $this->admin(), array('AteResearchTeam' => array(
        'co_group_id' => $this->g[$name],
        'name' => 'Team ' . $name,
        'status' => AteConfigStatusEnum::Active
      )), array('co' => $this->coId));
      $h->harnessInvoke('add', array(), 'POST');
      $this->assertTrue($h->harnessStopped, "$name should save: " . json_encode($h->Flash->messages));

      $teamIds[$name] = (int)$this->fx->scalar('SELECT id FROM cm_ate_research_teams WHERE co_group_id = '
                                               . $this->g[$name]);
      $this->assertTrue($teamIds[$name] > 0);
    }

    // Map both, from the application's page
    foreach($teamIds as $name => $teamId) {
      $h = $this->harness('AteConfigApplicationTeamsHarness', $this->admin(), array('AteApplicationTeam' => array(
        'ate_application_id' => $appId,
        'ate_research_team_id' => $teamId
      )), array('appid' => $appId));
      $target = $h->harnessInvoke('add', array(), 'POST');
      $this->assertTrue($h->harnessStopped, "mapping $name should save: " . json_encode($h->Flash->messages));
      $this->assertEqual('ate_applications', $target['controller'], 'back to the application');
      $this->assertEqual('view', $target['action']);
      $this->assertEqual($appId, (int)$target[0]);
    }

    $this->assertEqual(2, $this->fx->count('cm_ate_application_teams',
      'ate_application_id = ' . $appId . ' AND ate_application_team_id IS NULL AND deleted IS NOT TRUE'));

    // Removing a mapping returns to the application too
    $mapId = (int)$this->fx->scalar('SELECT id FROM cm_ate_application_teams WHERE ate_research_team_id = '
                                    . $teamIds['team2']);
    $h = $this->harness('AteConfigApplicationTeamsHarness', $this->admin());
    $target = $h->harnessInvoke('delete', array($mapId), 'POST');
    $this->assertEqual('view', $target['action']);
    $this->assertEqual($appId, (int)$target[0]);
    $this->assertEqual(1, $this->fx->count('cm_ate_application_teams',
      'ate_application_id = ' . $appId . ' AND ate_application_team_id IS NULL AND deleted IS NOT TRUE'));
  }

  /**
   * Editing an application twice through the controller leaves exactly one
   * current row, still in the current CO.
   */
  public function testEditingApplicationTwiceThroughControllerLeavesOneRow() {
    $appId = $this->fx->application($this->coId, 'Edit Me ' . uniqid(), array(
      'admin_co_group_id' => $this->g['admins'],
      'approver_co_group_id' => $this->g['approvers']
    ));

    foreach(array('Renamed once', 'Renamed twice') as $name) {
      $h = $this->harness('AteConfigApplicationsHarness', $this->admin(), array('AteApplication' => array(
        'id' => $appId,
        'co_id' => $this->otherCoId,
        'name' => $name,
        'client_identifier' => '',
        'admin_co_group_id' => $this->g['admins'],
        'approver_co_group_id' => $this->g['admins'],
        'approval_required' => false,
        'status' => AteConfigStatusEnum::Active
      )));
      $h->harnessInvoke('edit', array($appId), 'POST');
      $this->assertTrue($h->harnessStopped, "$name should save: " . json_encode($h->Flash->messages));
    }

    $current = 'ate_application_id IS NULL AND deleted IS NOT TRUE';
    $this->assertEqual(1, $this->fx->count('cm_ate_applications', 'co_id = ' . $this->coId . ' AND ' . $current));
    $this->assertEqual('Renamed twice', $this->fx->scalar('SELECT name FROM cm_ate_applications WHERE id = ' . $appId));
    $this->assertEqual(0, $this->fx->count('cm_ate_applications', 'co_id = ' . $this->otherCoId));
  }

  /**
   * Designating CO:admins as a research team is refused with a message, even
   * when posted directly, and no team is created (R12).
   */
  public function testDesignatingCoAdminsIsRefused() {
    $h = $this->harness('AteConfigResearchTeamsHarness', $this->admin(), array('AteResearchTeam' => array(
      'co_group_id' => $this->g['coadmins'],
      'status' => AteConfigStatusEnum::Active
    )), array('co' => $this->coId));
    $h->harnessInvoke('add', array(), 'POST');

    $this->assertFalse($h->harnessStopped, 'the refused form stays open');
    $this->assertEqual(_txt('pl.applicationteamenroller.er.research_team.group'), $h->Flash->last());
    $this->assertEqual(0, $this->fx->count('cm_ate_research_teams', 'co_group_id = ' . $this->g['coadmins']));
  }

  /**
   * Editing a research team cannot move it to another group: the team keeps
   * the group it was designated with.
   */
  public function testEditingResearchTeamKeepsItsGroup() {
    $teamId = $this->fx->researchTeam($this->g['team1'], array('name' => 'Before'));

    $h = $this->harness('AteConfigResearchTeamsHarness', $this->admin(), array('AteResearchTeam' => array(
      'id' => $teamId,
      'co_group_id' => $this->g['coadmins'],
      'name' => 'After',
      'status' => AteConfigStatusEnum::Retired
    )));
    $h->harnessInvoke('edit', array($teamId), 'POST');

    $this->assertTrue($h->harnessStopped, json_encode($h->Flash->messages));
    $this->assertEqual($this->g['team1'],
      (int)$this->fx->scalar('SELECT co_group_id FROM cm_ate_research_teams WHERE id = ' . $teamId));
    $this->assertEqual('After', $this->fx->scalar('SELECT name FROM cm_ate_research_teams WHERE id = ' . $teamId));
    $this->assertEqual('retired', $this->fx->scalar('SELECT status FROM cm_ate_research_teams WHERE id = ' . $teamId));
  }

  /**
   * A mapping for another CO's application is refused in this CO's context.
   */
  public function testMappingForAnotherCosApplicationIsRefused() {
    $foreignAppId = $this->fx->application($this->otherCoId, 'Foreign ' . uniqid());
    $foreignTeamId = $this->fx->researchTeam($this->fx->group($this->otherCoId, AteFixtures::tag('foreign')));

    $h = $this->harness('AteConfigApplicationTeamsHarness', $this->admin(), array('AteApplicationTeam' => array(
      'ate_application_id' => $foreignAppId,
      'ate_research_team_id' => $foreignTeamId
    )));
    $h->harnessInvoke('add', array(), 'POST');

    $this->assertEqual(0, $this->fx->count('cm_ate_application_teams', 'ate_application_id = ' . $foreignAppId));
    $this->assertEqual(_txt('pl.applicationteamenroller.er.application_team.application'), $h->Flash->last());
  }
}
