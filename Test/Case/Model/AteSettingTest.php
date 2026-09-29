<?php
/**
 * U2: per-CO settings (KTD2). The helper returns the CO's settings row and
 * creates it with persisted defaults on first use.
 */

class AteSettingTest extends AteTestCase {

  /** @var AteFixtures */
  protected $fx = null;

  /** @var Integer CO seeded for this test */
  protected $coId = null;

  public function setUp() {
    $this->fx = new AteFixtures();
    $this->coId = $this->fx->co(AteFixtures::tag('ate-u2-setting'));
  }

  public function tearDown() {
    if($this->fx) {
      $this->fx->cleanup($this->fx->pluginRowsFor($this->coId));
    }
  }

  /**
   * A CO with no settings gets a persisted row carrying every default.
   */
  public function testHelperCreatesRowWithDefaults() {
    $this->assertEqual(0, $this->fx->count('cm_ate_settings', 'co_id = ' . $this->coId));

    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $row = $Setting->getOrCreateForCo($this->coId);

    $this->assertEqual(1, $this->fx->count('cm_ate_settings',
      'co_id = ' . $this->coId . ' AND ate_setting_id IS NULL AND deleted IS NOT TRUE'),
      'the defaults must be persisted, not only returned');

    $s = $row['AteSetting'];
    $this->assertEqual($this->coId, (int)$s['co_id']);
    $this->assertEqual(14, (int)$s['invitation_lifetime_days']);
    $this->assertEqual(_txt('pl.applicationteamenroller.setting.email_subject.default'), $s['email_subject']);
    $this->assertEqual(_txt('pl.applicationteamenroller.setting.email_body.default'), $s['email_body']);
    $this->assertEqual('oidcsub', $s['login_identifier_type']);
    $this->assertEqual('OIDC_CLAIM_email', $s['email_env_vars']);
    $this->assertNull($s['newcomer_co_enrollment_flow_id'], 'no newcomer flow is chosen by default');
  }

  /**
   * The default email text is real text from Lib/lang.php, with a link
   * placeholder, not an unresolved key (R38).
   */
  public function testDefaultEmailTextComesFromLangFile() {
    $subject = _txt('pl.applicationteamenroller.setting.email_subject.default');
    $body = _txt('pl.applicationteamenroller.setting.email_body.default');

    $this->assertFalse($subject === 'pl.applicationteamenroller.setting.email_subject.default',
      'the default subject is missing from Lib/lang.php');
    $this->assertFalse($body === 'pl.applicationteamenroller.setting.email_body.default',
      'the default body is missing from Lib/lang.php');
    $this->assertContains('(@INVITE_URL)', $body, 'the default body must carry the response link');
  }

  /**
   * A second call returns the same row rather than creating another.
   */
  public function testHelperSecondCallReturnsSameRow() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');

    $first = $Setting->getOrCreateForCo($this->coId);
    ConnectionManager::getDataSource('default')->flushQueryCache();
    $second = $Setting->getOrCreateForCo($this->coId);

    $this->assertEqual((int)$first['AteSetting']['id'], (int)$second['AteSetting']['id']);
    $this->assertEqual(1, $this->fx->count('cm_ate_settings', 'co_id = ' . $this->coId));
  }

  /**
   * An existing row is returned as it is; defaults never overwrite an
   * administrator's choices.
   */
  public function testHelperKeepsExistingValues() {
    $id = $this->fx->insert('cm_ate_settings', array(
      'co_id' => $this->coId,
      'invitation_lifetime_days' => 30,
      'email_subject' => 'Custom subject',
      'email_body' => 'Custom body (@INVITE_URL)',
      'login_identifier_type' => 'eppn',
      'email_env_vars' => 'MAIL',
      'revision' => 0,
      'deleted' => false,
      'ate_setting_id' => null
    ));

    $row = $this->model('ApplicationTeamEnroller.AteSetting')->getOrCreateForCo($this->coId);

    $this->assertEqual($id, (int)$row['AteSetting']['id']);
    $this->assertEqual(30, (int)$row['AteSetting']['invitation_lifetime_days']);
    $this->assertEqual('Custom subject', $row['AteSetting']['email_subject']);
    $this->assertEqual('eppn', $row['AteSetting']['login_identifier_type']);
  }

  /**
   * One settings row per CO, and a lifetime of at least one day.
   */
  public function testSettingValidation() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $Setting->getOrCreateForCo($this->coId);

    $Setting->create();
    $this->assertFalse($Setting->save(array('co_id' => $this->coId, 'invitation_lifetime_days' => 7)),
      'a second settings row for the same CO must fail');
    $this->assertTrue(isset($Setting->validationErrors['co_id']));

    $row = $Setting->getOrCreateForCo($this->coId);
    $Setting->clear();
    $this->assertFalse($Setting->save(array('id' => $row['AteSetting']['id'],
                                            'co_id' => $this->coId,
                                            'invitation_lifetime_days' => 0)),
      'a zero-day lifetime must fail');
    $this->assertTrue(isset($Setting->validationErrors['invitation_lifetime_days']));
  }
}
