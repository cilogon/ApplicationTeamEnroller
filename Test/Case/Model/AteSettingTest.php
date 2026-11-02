<?php
/**
 * U2: per-CO settings (KTD2). The helper returns the CO's settings row and
 * creates it with persisted defaults on first use.
 *
 * U4: the newcomer enrollment flow must meet the Planning Contract's
 * Assumptions (same CO, AuthUser, no approval, no email verification, match
 * policy not Self or Select, a CO Person Role attribute, this plugin's wedge
 * attached). The check is AteSetting::newcomerFlowProblem(), used by the
 * field's validation rule, so it is tested here without a controller or a
 * rendered page.
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

  /**
   * Security: an email variable named HTTP_* (or REDIRECT_HTTP_*) is filled
   * from a client request header, so a responder could claim any address.
   * Such names are rejected; ordinary names save.
   */
  public function testHttpEmailVariablesAreRejected() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $row = $Setting->getOrCreateForCo($this->coId);

    foreach(array('HTTP_X_EMAIL', 'OIDC_CLAIM_email, http_mail', 'REDIRECT_HTTP_X_EMAIL') as $bad) {
      $Setting->clear();
      $this->assertFalse($Setting->save(array('id' => $row['AteSetting']['id'],
                                              'co_id' => $this->coId,
                                              'invitation_lifetime_days' => 14,
                                              'email_env_vars' => $bad)),
        'a header-derived variable must be rejected: ' . $bad);
      $this->assertTrue(isset($Setting->validationErrors['email_env_vars']), 'on email_env_vars: ' . $bad);
    }

    $Setting->clear();
    $this->assertTrue((bool)$Setting->save(array('id' => $row['AteSetting']['id'],
                                                 'co_id' => $this->coId,
                                                 'invitation_lifetime_days' => 14,
                                                 'email_env_vars' => 'OIDC_CLAIM_email, MAIL, XHTTP_MAIL')),
      'ordinary names save: ' . json_encode($Setting->validationErrors));
  }

  /**
   * Seed a qualifying newcomer flow in $coId with this plugin's wedge, then
   * apply $overrides to the flow. Returns the flow ID.
   */
  private function newcomerFlow($coId, $overrides = array()) {
    $flowId = $this->fx->flow($coId, AteFixtures::tag('ate-u4-flow'), $overrides);
    $this->fx->wedge($flowId);

    return $flowId;
  }

  /**
   * A qualifying flow has no problem and saves.
   */
  public function testQualifyingNewcomerFlowSaves() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $flowId = $this->newcomerFlow($this->coId);

    $this->assertNull($Setting->newcomerFlowProblem($this->coId, $flowId));

    $row = $Setting->getOrCreateForCo($this->coId);
    $Setting->clear();
    $saved = $Setting->save(array('id' => $row['AteSetting']['id'],
                                  'co_id' => $this->coId,
                                  'invitation_lifetime_days' => 14,
                                  'newcomer_co_enrollment_flow_id' => $flowId));
    $this->assertNotEmpty($saved, 'a qualifying flow must save: ' . json_encode($Setting->validationErrors));
  }

  /**
   * A flow that requires approval is rejected, and the save's validation
   * error names that problem.
   */
  public function testNewcomerFlowRequiringApprovalIsRejected() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $flowId = $this->newcomerFlow($this->coId, array('approval_required' => true));
    $expected = _txt('pl.applicationteamenroller.er.newcomer_flow.approval');

    $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, $flowId));

    $row = $Setting->getOrCreateForCo($this->coId);
    $Setting->clear();
    $this->assertFalse($Setting->save(array('id' => $row['AteSetting']['id'],
                                            'co_id' => $this->coId,
                                            'invitation_lifetime_days' => 14,
                                            'newcomer_co_enrollment_flow_id' => $flowId)));
    $this->assertEqual(array($expected), $Setting->validationErrors['newcomer_co_enrollment_flow_id']);
  }

  /**
   * A flow of another CO is rejected, even a flow that otherwise qualifies.
   */
  public function testNewcomerFlowFromAnotherCoIsRejected() {
    $otherCoId = $this->fx->co(AteFixtures::tag('ate-u4-other'));
    $flowId = $this->newcomerFlow($otherCoId);

    $this->assertEqual(_txt('pl.applicationteamenroller.er.newcomer_flow.co'),
      $this->model('ApplicationTeamEnroller.AteSetting')->newcomerFlowProblem($this->coId, $flowId));
  }

  /**
   * A Self or Select match policy is rejected: selectEnrollee would then run
   * before petitionerAttributes (KTD11). Other policies are accepted.
   */
  public function testNewcomerFlowMatchPolicySelfOrSelectIsRejected() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $expected = _txt('pl.applicationteamenroller.er.newcomer_flow.match');

    foreach(array('S', 'P') as $policy) {
      $flowId = $this->newcomerFlow($this->coId, array('match_policy' => $policy));
      $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, $flowId), "policy $policy");
    }

    foreach(array('N', 'A', 'E', null) as $policy) {
      $flowId = $this->newcomerFlow($this->coId, array('match_policy' => $policy));
      $this->assertNull($Setting->newcomerFlowProblem($this->coId, $flowId), 'policy ' . var_export($policy, true));
    }
  }

  /**
   * The flow must admit any authenticated user and must not verify email.
   * An unset verification mode is treated as None, as Registry treats it.
   */
  public function testNewcomerFlowAuthzAndVerificationAreChecked() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');

    foreach(array('CP', 'CA', 'N') as $authz) {
      $flowId = $this->newcomerFlow($this->coId, array('authz_level' => $authz));
      $this->assertEqual(_txt('pl.applicationteamenroller.er.newcomer_flow.authz'),
        $Setting->newcomerFlowProblem($this->coId, $flowId), "authz $authz");
    }

    foreach(array('A', 'R', 'V') as $mode) {
      $flowId = $this->newcomerFlow($this->coId, array('email_verification_mode' => $mode));
      $this->assertEqual(_txt('pl.applicationteamenroller.er.newcomer_flow.verification'),
        $Setting->newcomerFlowProblem($this->coId, $flowId), "verification $mode");
    }

    $flowId = $this->newcomerFlow($this->coId, array('email_verification_mode' => null));
    $this->assertNull($Setting->newcomerFlowProblem($this->coId, $flowId), 'unset verification mode');
  }

  /**
   * Registry activates a new CoPerson only through a CO Person Role, which a
   * petition creates only from role ("r:") attributes, so the flow must
   * collect one. No attributes, only CO Person attributes, a Not Permitted
   * role attribute, a deleted one, or an archived copy is rejected; any
   * current optional or required role attribute is accepted.
   */
  public function testNewcomerFlowNeedsRoleAttribute() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $expected = _txt('pl.applicationteamenroller.er.newcomer_flow.role');

    $rejected = array(
      'no attributes' => array(),
      'person name only' => array(array('attribute' => 'p:name:official', 'label' => 'Name', 'required' => 1)),
      'not permitted' => array(array('required' => -1)),
      'deleted' => array(array('deleted' => true)),
      'archived copy' => 'archived'
    );

    foreach($rejected as $label => $attrs) {
      $flowId = $this->fx->flow($this->coId, AteFixtures::tag('ate-u4-flow'), array(), false);
      $this->fx->wedge($flowId);

      if($attrs === 'archived') {
        $parent = $this->fx->enrollmentAttribute($flowId, array('deleted' => true));
        $this->fx->enrollmentAttribute($flowId, array('co_enrollment_attribute_id' => $parent));
      } else {
        foreach($attrs as $a) {
          $this->fx->enrollmentAttribute($flowId, $a);
        }
      }

      $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, $flowId), $label);
    }

    $accepted = array(
      'optional affiliation' => array(),
      'required title' => array('attribute' => 'r:title', 'label' => 'Title', 'required' => 1)
    );

    foreach($accepted as $label => $a) {
      $flowId = $this->fx->flow($this->coId, AteFixtures::tag('ate-u4-flow'), array(), false);
      $this->fx->wedge($flowId);
      $this->fx->enrollmentAttribute($flowId, $a);
      $this->fx->enrollmentAttribute($flowId, array('attribute' => 'p:name:official', 'label' => 'Name', 'required' => 1));

      $this->assertNull($Setting->newcomerFlowProblem($this->coId, $flowId), $label);
    }

    // The save's validation error names the problem
    $flowId = $this->fx->flow($this->coId, AteFixtures::tag('ate-u4-flow'), array(), false);
    $this->fx->wedge($flowId);

    $row = $Setting->getOrCreateForCo($this->coId);
    $Setting->clear();
    $this->assertFalse($Setting->save(array('id' => $row['AteSetting']['id'],
                                            'co_id' => $this->coId,
                                            'invitation_lifetime_days' => 14,
                                            'newcomer_co_enrollment_flow_id' => $flowId)));
    $this->assertEqual(array($expected), $Setting->validationErrors['newcomer_co_enrollment_flow_id']);
  }

  /**
   * The flow must give the new CoPerson a name: a current Required name
   * attribute ("p:name", or "i:name" copied to the CO Person), or an
   * active, undeleted Org Identity Source attached in Authenticate mode (an
   * EnvSource for CILogon claims) whose pipeline is active, syncs on add,
   * and syncs Name; without the pipeline the CoPerson gets no name. Without either the CoPerson has no name, and Registry lists it
   * with none, so it looks missing. A source attached in Identify mode does
   * not count, and is named: Registry runs it only when email confirmation
   * is on, which the newcomer flow must not be.
   */
  public function testNewcomerFlowNeedsANameSource() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $name = _txt('pl.applicationteamenroller.er.newcomer_flow.name');
    $identify = _txt('pl.applicationteamenroller.er.newcomer_flow.identify');

    // Each flow already collects a role affiliation
    $cases = array(
      'role only' => array($name, array(), null),
      'deleted name' => array($name, array(array('attribute' => 'p:name:official', 'deleted' => true)), null),
      'not permitted name' => array($name, array(array('attribute' => 'p:name:official', 'required' => -1)), null),
      'optional name' => array($name, array(array('attribute' => 'p:name:official', 'required' => 0)), null),
      'authenticate source without a pipeline' => array($name, array(), 'OA-nopipeline'),
      'authenticate source whose pipeline skips Name' => array($name, array(), 'OA-noname'),
      'authenticate source whose pipeline does not sync on add' => array($name, array(), 'OA-noadd'),
      'identify-mode source' => array($identify, array(), 'OI'),
      'suspended authenticate source' => array($name, array(), 'OA-suspended'),
      'deleted authenticate source' => array($name, array(), 'OA-deleted'),
      'org identity name not copied' => array($name, array(array('attribute' => 'i:name:official', 'required' => 1,
                                                                 'copy_to_coperson' => false)), null),
      'person name' => array(null, array(array('attribute' => 'p:name:official', 'required' => 1)), null),
      'org identity name copied' => array(null, array(array('attribute' => 'i:name:official', 'required' => 1,
                                                            'copy_to_coperson' => true)), null),
      'authenticate-mode source' => array(null, array(), 'OA'),
      'identify source and a name' => array(null, array(array('attribute' => 'p:name:official', 'required' => 1)), 'OI')
    );

    foreach($cases as $label => $c) {
      list($expected, $attrs, $source) = $c;

      $flowId = $this->fx->flow($this->coId, AteFixtures::tag('ate-u4-flow'), array(), false);
      $this->fx->wedge($flowId);
      $this->fx->enrollmentAttribute($flowId);

      foreach($attrs as $a) {
        $this->fx->enrollmentAttribute($flowId, $a + array('label' => 'Name', 'ordr' => 2));
      }

      if($source !== null) {
        $ois = $this->fx->envSourceEnrollment($this->coId, $flowId, substr($source, 0, 2));

        if($source === 'OA-suspended') {
          $this->fx->query("UPDATE cm_org_identity_sources SET status = 'S' WHERE id = " . (int)$ois);
        } elseif($source === 'OA-deleted') {
          $this->fx->query('UPDATE cm_org_identity_sources SET deleted = true WHERE id = ' . (int)$ois);
        }

        $pipeline = 'UPDATE cm_co_pipelines SET %s WHERE id = (SELECT co_pipeline_id FROM cm_org_identity_sources'
                    . ' WHERE id = ' . (int)$ois . ')';

        if($source === 'OA-nopipeline') {
          $this->fx->query('UPDATE cm_org_identity_sources SET co_pipeline_id = NULL WHERE id = ' . (int)$ois);
        } elseif($source === 'OA-noname') {
          $this->fx->query(sprintf($pipeline, "sync_coperson_attributes = 'EmailAddress,Identifier'"));
        } elseif($source === 'OA-noadd') {
          $this->fx->query(sprintf($pipeline, 'sync_on_add = false'));
        }
      }

      $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, $flowId), $label);
    }
  }

  /**
   * The flow must carry an active wedge of this plugin: no wedge, another
   * plugin's wedge, a suspended wedge, or a deleted wedge is rejected.
   */
  public function testNewcomerFlowNeedsThisPluginsActiveWedge() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $expected = _txt('pl.applicationteamenroller.er.newcomer_flow.wedge');

    $cases = array(
      'no wedge' => null,
      'other plugin' => array('plugin' => 'FiddleEnroller'),
      'suspended' => array('status' => 'S'),
      'deleted' => array('deleted' => true)
    );

    foreach($cases as $label => $wedge) {
      $flowId = $this->fx->flow($this->coId, AteFixtures::tag('ate-u4-flow'));
      if($wedge !== null) {
        $this->fx->wedge($flowId, $wedge);
      }
      $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, $flowId), $label);
    }
  }

  /**
   * An unknown or deleted flow is rejected as not found.
   */
  public function testMissingOrDeletedNewcomerFlowIsRejected() {
    $Setting = $this->model('ApplicationTeamEnroller.AteSetting');
    $expected = _txt('pl.applicationteamenroller.er.newcomer_flow.notfound');

    $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, 999999999));

    $flowId = $this->newcomerFlow($this->coId, array('deleted' => true));
    $this->assertEqual($expected, $Setting->newcomerFlowProblem($this->coId, $flowId));
  }
}
