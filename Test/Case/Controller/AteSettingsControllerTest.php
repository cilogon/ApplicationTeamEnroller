<?php
/**
 * U4: the settings screen (R5, KTD2). index persists the CO's defaults and
 * sends the administrator to edit; edit rejects a newcomer flow that does not
 * meet the Planning Contract's Assumptions, with a message naming the
 * problem, and saves a qualifying one in place.
 *
 * The controller is driven through Test/lib/AteControllerHarness.php, so the
 * page itself is not rendered; the form is checked by hand in a Registry.
 */

App::uses('AteSettingsController', 'ApplicationTeamEnroller.Controller');

class AteSettingsControllerHarness extends AteSettingsController {
  use AteControllerHarness;
}

class AteSettingsControllerTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  protected $coId = null;
  protected $otherCoId = null;

  public function setUp() {
    $this->fx = new AteFixtures();
    $tag = AteFixtures::tag('ate-u4-settings');
    $this->coId = $this->fx->co($tag);
    $this->otherCoId = $this->fx->co($tag . '-other');
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor(array($this->coId, $this->otherCoId)));
    }
  }

  /** A harness for the test CO acting as a CO administrator. */
  private function harness($data = array()) {
    return AteSettingsControllerHarness::harnessBuild('ate_settings', $this->coId,
                                                      array('coadmin' => true, 'comember' => true),
                                                      $data);
  }

  /** A newcomer flow with this plugin's wedge in $coId, with $overrides. */
  private function flow($coId, $overrides = array()) {
    $flowId = $this->fx->flow($coId, AteFixtures::tag('ate-u4-flow'), $overrides);
    $this->fx->wedge($flowId);

    return $flowId;
  }

  /** Post the settings edit form for the test CO with $flowId chosen. */
  private function postEdit($flowId, $overrides = array()) {
    $row = $this->model('ApplicationTeamEnroller.AteSetting')->getOrCreateForCo($this->coId);
    $id = (int)$row['AteSetting']['id'];

    $h = $this->harness(array('AteSetting' => $overrides + array(
      'id' => $id,
      'co_id' => $this->coId,
      'invitation_lifetime_days' => 21,
      'email_subject' => $row['AteSetting']['email_subject'],
      'email_body' => $row['AteSetting']['email_body'],
      'newcomer_co_enrollment_flow_id' => $flowId,
      'email_env_vars' => $row['AteSetting']['email_env_vars'],
      'login_identifier_type' => $row['AteSetting']['login_identifier_type']
    )));
    $h->harnessInvoke('edit', array($id), 'POST');

    return $h;
  }

  /** Whether the harness flashed exactly $message among its messages. */
  private function flashed($h, $message) {
    foreach($h->Flash->messages as $m) {
      if($m['message'] === $message) {
        return true;
      }
    }

    return false;
  }

  /** The current settings row of $coId, read directly. */
  private function current($coId, $column) {
    return $this->fx->scalar('SELECT ' . $column . ' FROM cm_ate_settings WHERE co_id = ' . (int)$coId
                             . ' AND ate_setting_id IS NULL AND deleted IS NOT TRUE');
  }

  /**
   * The first visit persists the defaults and redirects to edit that row
   * (SponsorManager pattern); a second visit reuses it.
   */
  public function testIndexCreatesSettingsAndRedirectsToEdit() {
    $h = $this->harness();
    $target = $h->harnessInvoke('index');

    $id = (int)$this->current($this->coId, 'id');
    $this->assertTrue($id > 0, 'index must persist the settings row');
    $this->assertEqual('edit', $target['action']);
    $this->assertEqual($id, (int)$target[0]);

    $h = $this->harness();
    $target = $h->harnessInvoke('index');
    $this->assertEqual($id, (int)$target[0], 'a second visit reuses the row');
    $this->assertEqual(1, $this->fx->count('cm_ate_settings', 'co_id = ' . $this->coId));
  }

  /**
   * A flow that requires approval is rejected with a message naming the
   * problem, and nothing is saved.
   */
  public function testEditRejectsFlowRequiringApproval() {
    $flowId = $this->flow($this->coId, array('approval_required' => true));

    $h = $this->postEdit($flowId);

    $this->assertTrue($this->flashed($h, _txt('pl.applicationteamenroller.er.newcomer_flow.approval')),
      'the flash must name the approval problem: ' . json_encode($h->Flash->messages));
    $this->assertFalse($h->harnessStopped, 'a rejected save stays on the form');
    $this->assertNull($this->current($this->coId, 'newcomer_co_enrollment_flow_id'));
    $this->assertEqual(14, (int)$this->current($this->coId, 'invitation_lifetime_days'), 'no field was saved');
  }

  /**
   * A flow of another CO is rejected.
   */
  public function testEditRejectsFlowFromAnotherCo() {
    $flowId = $this->flow($this->otherCoId);

    $h = $this->postEdit($flowId);

    $this->assertTrue($this->flashed($h, _txt('pl.applicationteamenroller.er.newcomer_flow.co')),
      json_encode($h->Flash->messages));
    $this->assertNull($this->current($this->coId, 'newcomer_co_enrollment_flow_id'));
  }

  /**
   * A flow whose match policy is Self is rejected.
   */
  public function testEditRejectsSelfMatchPolicy() {
    $flowId = $this->flow($this->coId, array('match_policy' => 'S'));

    $h = $this->postEdit($flowId);

    $this->assertTrue($this->flashed($h, _txt('pl.applicationteamenroller.er.newcomer_flow.match')),
      json_encode($h->Flash->messages));
    $this->assertNull($this->current($this->coId, 'newcomer_co_enrollment_flow_id'));
  }

  /**
   * A flow that collects no CO Person Role attribute is rejected: Registry
   * would leave the new CoPerson Pending.
   */
  public function testEditRejectsFlowWithoutRoleAttribute() {
    $flowId = $this->fx->flow($this->coId, AteFixtures::tag('ate-u4-flow'), array(), false);
    $this->fx->wedge($flowId);

    $h = $this->postEdit($flowId);

    $this->assertTrue($this->flashed($h, _txt('pl.applicationteamenroller.er.newcomer_flow.role')),
      json_encode($h->Flash->messages));
    $this->assertNull($this->current($this->coId, 'newcomer_co_enrollment_flow_id'));
  }

  /**
   * A qualifying flow saves, in place: one current row, still for this CO
   * even when the form claims another CO.
   */
  public function testEditSavesQualifyingFlowInPlace() {
    $flowId = $this->flow($this->coId);

    $h = $this->postEdit($flowId, array('co_id' => $this->otherCoId));

    $this->assertTrue($h->harnessStopped, 'a successful save redirects: ' . json_encode($h->Flash->messages));
    $this->assertEqual($flowId, (int)$this->current($this->coId, 'newcomer_co_enrollment_flow_id'));
    $this->assertEqual(21, (int)$this->current($this->coId, 'invitation_lifetime_days'));
    $this->assertEqual(1, $this->fx->count('cm_ate_settings',
      'co_id = ' . $this->coId . ' AND ate_setting_id IS NULL AND deleted IS NOT TRUE'));
    $this->assertEqual(0, $this->fx->count('cm_ate_settings', 'co_id = ' . $this->otherCoId),
      'the posted co_id must not move the row to another CO');
  }

  /**
   * The flow picker lists this CO's flows only.
   */
  public function testFlowPickerListsThisCosFlowsOnly() {
    $mine = $this->flow($this->coId);
    $theirs = $this->flow($this->otherCoId);

    $flows = $this->model('ApplicationTeamEnroller.AteSetting')->availableFlows($this->coId);

    $this->assertTrue(isset($flows[$mine]));
    $this->assertFalse(isset($flows[$theirs]));
  }
}
