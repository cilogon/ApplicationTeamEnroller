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
    // The admin and approver groups are existing groups of the
    // application's CO, and may be the same group (R4)
    'admin_co_group_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false,
        'last' => true
      ),
      'co' => array(
        'rule' => array('validateCoGroup')
      )
    ),
    'approver_co_group_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false,
        'last' => true
      ),
      'co' => array(
        'rule' => array('validateCoGroup')
      )
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

  /**
   * The groups of a CO, for the admin and approver group pickers.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Group names, keyed by CoGroup ID
   */

  public function availableGroups($coId) {
    $args = array();
    $args['conditions']['AdminCoGroup.co_id'] = $coId;
    $args['order'] = 'AdminCoGroup.name ASC';
    $args['contain'] = false;

    return $this->AdminCoGroup->find('list', $args);
  }

  /**
   * Validate that a group is a current group of the application's CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateCoGroup($check) {
    $coId = isset($this->data[$this->alias]['co_id']) ? $this->data[$this->alias]['co_id'] : null;

    if(empty($coId)) {
      $id = !empty($this->data[$this->alias]['id']) ? $this->data[$this->alias]['id'] : $this->id;

      if(!empty($id)) {
        $coId = $this->field('co_id', array($this->alias . '.id' => $id));
      }
    }

    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived groups here.
    $args = array();
    $args['conditions']['AdminCoGroup.id'] = reset($check);
    $args['conditions']['AdminCoGroup.co_id'] = $coId;
    $args['conditions']['AdminCoGroup.co_group_id'] = null;
    $args['conditions'][] = 'AdminCoGroup.deleted IS NOT true';
    $args['contain'] = false;

    if(empty($coId) || $this->AdminCoGroup->find('count', $args) < 1) {
      return _txt('pl.applicationteamenroller.er.application.group');
    }

    return true;
  }
}
