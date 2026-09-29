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
    'newcomer_co_enrollment_flow_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
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
