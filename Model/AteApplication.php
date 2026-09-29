<?php
/**
 * COmanage Registry Application Team Enroller Application Model
 *
 * An application researchers are invited to (R1, R4). Its admin and approver
 * groups are existing CO groups the plugin only references; its access group
 * is created and maintained by the plugin (KTD10, R31).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteApplication extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteApplication";

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array(
    "Co",
    "AdminCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'admin_co_group_id'
    ),
    "ApproverCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'approver_co_group_id'
    ),
    "AccessCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'access_co_group_id'
    )
  );

  public $hasMany = array(
    // Mapping rows are configuration and go with the application
    "AteApplicationTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteApplicationTeam',
      'foreignKey' => 'ate_application_id',
      'dependent' => true
    ),
    // Requests are audit history and are never deleted with the application
    "AteEnrollmentRequest" => array(
      'className' => 'ApplicationTeamEnroller.AteEnrollmentRequest',
      'foreignKey' => 'ate_application_id',
      'dependent' => false
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'name' => array(
      'rule' => array('validateInput'),
      'required' => true,
      'allowEmpty' => false
    ),
    'client_identifier' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'admin_co_group_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'approver_co_group_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'access_co_group_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'approval_required' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    ),
    'status' => array(
      'rule' => array('validateEnum', 'AteConfigStatusEnum'),
      'required' => true,
      'allowEmpty' => false
    )
  );
}
