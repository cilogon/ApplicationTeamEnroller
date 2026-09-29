<?php
/**
 * COmanage Registry Application Team Enroller Setting Model
 *
 * Per-CO plugin settings (KTD2, R5). There is one row per CO, keyed on co_id
 * rather than on the wedge row, because Registry creates one wedge row per
 * enrollment flow.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteSetting extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteSetting";

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array(
    "Co",
    "NewcomerCoEnrollmentFlow" => array(
      'className' => 'CoEnrollmentFlow',
      'foreignKey' => 'newcomer_co_enrollment_flow_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "co_id";

  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false
      ),
      'unique' => array(
        'rule' => array('validateUniqueCo')
      )
    ),
    'invitation_lifetime_days' => array(
      'rule' => array('comparison', '>=', 1),
      'required' => true,
      'allowEmpty' => false
    ),
    'email_subject' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    // Plain-text email body, so angle brackets are allowed
    'email_body' => array(
      'rule' => 'notBlank',
      'required' => false,
      'allowEmpty' => true
    ),
    // The flow must meet the newcomer-flow assumptions (U4), checked by
    // newcomerFlowProblem()
    'newcomer_co_enrollment_flow_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => false,
        'allowEmpty' => true,
        'last' => true
      ),
      'flow' => array(
        'rule' => array('validateNewcomerFlow'),
        'required' => false,
        'allowEmpty' => true
      )
    ),
    'email_env_vars' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'login_identifier_type' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    )
  );

  // Defaults persisted on first use. The email text defaults come from
  // Lib/lang.php (R38) and are filled in by defaults().
  const DefaultLifetimeDays = 14;
  const DefaultLoginIdentifierType = 'oidcsub';
  const DefaultEmailEnvVars = 'OIDC_CLAIM_email';

  /**
   * Obtain the default settings for a CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         AteSetting fields
   */

  public function defaults($coId) {
    return array(
      'co_id'                    => $coId,
      'invitation_lifetime_days' => self::DefaultLifetimeDays,
      'email_subject'            => _txt('pl.applicationteamenroller.setting.email_subject.default'),
      'email_body'               => _txt('pl.applicationteamenroller.setting.email_body.default'),
      'login_identifier_type'    => self::DefaultLoginIdentifierType,
      'email_env_vars'           => self::DefaultEmailEnvVars
    );
  }

  /**
   * Obtain the settings row for a CO, creating it with persisted defaults if
   * the CO has none.
   *
   * The CO row is locked while checking, so two first visits cannot both
   * create a row.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         The settings row, as array('AteSetting' => ...)
   * @throws RuntimeException If the row cannot be created
   */

  public function getOrCreateForCo($coId) {
    $dbc = $this->getDataSource();
    $dbc->begin();

    try {
      // Serialize concurrent creators on the CO row
      $this->query('SELECT id FROM ' . $this->tablePrefix . 'cos WHERE id = '
                   . (int)$coId . ' FOR UPDATE', false);

      $row = $this->findForCo($coId);

      if(empty($row['AteSetting']['id'])) {
        $this->clear();

        if(!$this->save($this->defaults($coId))) {
          throw new RuntimeException(_txt('er.db.save-a', array('AteSetting')));
        }

        $row = $this->findForCo($coId);
      }

      $dbc->commit();
    } catch(Exception $e) {
      $dbc->rollback();
      throw $e;
    }

    return $row;
  }

  /**
   * Find the current settings row for a CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         The settings row, or an empty array
   */

  protected function findForCo($coId) {
    $args = array();
    $args['conditions']['AteSetting.co_id'] = $coId;
    $args['contain'] = false;

    return $this->find('first', $args);
  }

  /**
   * The enrollment flows of a CO, for the newcomer flow picker.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Flow names, keyed by CoEnrollmentFlow ID
   */

  public function availableFlows($coId) {
    $args = array();
    $args['conditions']['NewcomerCoEnrollmentFlow.co_id'] = $coId;
    $args['order'] = 'NewcomerCoEnrollmentFlow.name ASC';
    $args['contain'] = false;

    return $this->NewcomerCoEnrollmentFlow->find('list', $args);
  }

  /**
   * Check that an enrollment flow can serve as a CO's newcomer flow.
   *
   * The newcomer flow must admit the researcher's fresh login and hand them
   * back to the plugin without a Registry decision in between (Planning
   * Contract Assumptions, KTD11): it belongs to the CO, is authorized for any
   * authenticated user, requires no approval and no email verification, uses
   * a match policy other than Self or Select (either would run selectEnrollee
   * before petitionerAttributes), and carries an active wedge of this plugin.
   * Unset approval, verification, and match fields count as off, as Registry
   * treats them.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId   CO ID the settings belong to
   * @param  Integer $flowId CoEnrollmentFlow ID
   * @return String|null     A message naming the first problem found, or null if the flow qualifies
   */

  public function newcomerFlowProblem($coId, $flowId) {
    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived rows here.
    $args = array();
    $args['conditions']['NewcomerCoEnrollmentFlow.id'] = $flowId;
    $args['contain'] = false;

    $flow = $this->NewcomerCoEnrollmentFlow->find('first', $args);

    if(empty($flow['NewcomerCoEnrollmentFlow']['id'])
       || !empty($flow['NewcomerCoEnrollmentFlow']['deleted'])
       || !empty($flow['NewcomerCoEnrollmentFlow']['co_enrollment_flow_id'])) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.notfound');
    }

    $f = $flow['NewcomerCoEnrollmentFlow'];

    if((int)$f['co_id'] !== (int)$coId) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.co');
    }

    if($f['authz_level'] !== EnrollmentAuthzEnum::AuthUser) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.authz');
    }

    if(!empty($f['approval_required'])) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.approval');
    }

    if(!empty($f['email_verification_mode'])
       && $f['email_verification_mode'] !== VerificationModeEnum::None) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.verification');
    }

    if(in_array($f['match_policy'], array(EnrollmentMatchPolicyEnum::Self,
                                          EnrollmentMatchPolicyEnum::Select), true)) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.match');
    }

    // Changelog filters this search to current, undeleted wedges.
    $Wedge = ClassRegistry::init('CoEnrollmentFlowWedge');

    $args = array();
    $args['conditions']['CoEnrollmentFlowWedge.co_enrollment_flow_id'] = $flowId;
    $args['conditions']['CoEnrollmentFlowWedge.plugin'] = 'ApplicationTeamEnroller';
    $args['conditions']['CoEnrollmentFlowWedge.status'] = SuspendableStatusEnum::Active;
    $args['contain'] = false;

    if($Wedge->find('count', $args) < 1) {
      return _txt('pl.applicationteamenroller.er.newcomer_flow.wedge');
    }

    return null;
  }

  /**
   * Validate the newcomer enrollment flow (see newcomerFlowProblem()).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateNewcomerFlow($check) {
    $coId = isset($this->data[$this->alias]['co_id']) ? $this->data[$this->alias]['co_id'] : null;

    if(empty($coId)) {
      $id = !empty($this->data[$this->alias]['id']) ? $this->data[$this->alias]['id'] : $this->id;

      if(!empty($id)) {
        $coId = $this->field('co_id', array($this->alias . '.id' => $id));
      }
    }

    $problem = $this->newcomerFlowProblem($coId, reset($check));

    return ($problem === null) ? true : $problem;
  }

  /**
   * Validate that the CO has no other settings row.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateUniqueCo($check) {
    return $this->validateUniqueCurrent(array('co_id'), 'pl.applicationteamenroller.er.setting.unique');
  }
}
