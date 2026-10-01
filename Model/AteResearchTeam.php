<?php
/**
 * COmanage Registry Application Team Enroller Research Team Model
 *
 * A CoGroup designated as a research team (R2). Team membership is only ever
 * CoGroupMember rows on that group.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteResearchTeam extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteResearchTeam";

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array("CoGroup");

  public $hasMany = array(
    // Mapping rows are configuration and go with the team
    "AteApplicationTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteApplicationTeam',
      'foreignKey' => 'ate_research_team_id',
      'dependent' => true
    ),
    // Offered teams are audit history and are never deleted with the team
    "AteEnrollmentRequestTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteEnrollmentRequestTeam',
      'foreignKey' => 'ate_research_team_id',
      'dependent' => false
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'co_group_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false
      ),
      'unique' => array(
        'rule' => array('validateUniqueGroup')
      )
    ),
    'name' => array(
      'rule' => array('validateInput'),
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
   * Callback before a save: a team saved without a name takes its group's
   * name, so lists and pickers always have one to show.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $options Save options
   * @return Boolean          True to continue the save
   */

  public function beforeSave($options = array()) {
    if(empty($this->data[$this->alias]['name'])
       && !empty($this->data[$this->alias]['co_group_id'])) {
      $name = $this->CoGroup->field('name', array('CoGroup.id' => $this->data[$this->alias]['co_group_id']));

      if($name) {
        $this->data[$this->alias]['name'] = $name;
      }
    }

    return parent::beforeSave($options);
  }

  /**
   * The groups of a CO that may be designated as a research team (R2, R12):
   * current, standard, non-automatic groups that are not already a research
   * team and are not an application's access group. CO:admins, the approver
   * groups, and the members groups are never offered.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Group names, keyed by CoGroup ID
   */

  public function availableGroups($coId) {
    // Groups that are already research teams (Changelog limits this search
    // to current, undeleted teams)
    $args = array();
    $args['fields'] = array('AteResearchTeam.id', 'AteResearchTeam.co_group_id');
    $args['contain'] = false;
    $exclude = array_values($this->find('list', $args));

    // Access groups the plugin maintains for applications (KTD10)
    $Application = ClassRegistry::init('ApplicationTeamEnroller.AteApplication');

    $args = array();
    $args['conditions']['AteApplication.co_id'] = $coId;
    $args['conditions'][] = 'AteApplication.access_co_group_id IS NOT NULL';
    $args['fields'] = array('AteApplication.id', 'AteApplication.access_co_group_id');
    $args['contain'] = false;
    $exclude = array_merge($exclude, array_values($Application->find('list', $args)));

    $args = array();
    $args['conditions']['CoGroup.co_id'] = $coId;
    $args['conditions']['CoGroup.group_type'] = GroupEnum::Standard;
    $args['conditions'][] = 'CoGroup.auto IS NOT true';
    if(!empty($exclude)) {
      $args['conditions']['NOT']['CoGroup.id'] = array_map('intval', $exclude);
    }
    $args['order'] = 'CoGroup.name ASC';
    $args['contain'] = false;

    return $this->CoGroup->find('list', $args);
  }

  /**
   * Determine whether a group may be designated as a research team of a CO,
   * by the same rule as availableGroups().
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId      CO ID
   * @param  Integer $coGroupId CoGroup ID
   * @return Boolean
   */

  public function isEligibleGroup($coId, $coGroupId) {
    return array_key_exists((int)$coGroupId, $this->availableGroups($coId));
  }

  /**
   * Find the CO of a research team, through its group.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteResearchTeam ID
   * @return Integer     CO ID
   * @throws InvalidArgumentException If the team or its group does not exist
   */

  public function findCoForRecord($id) {
    $groupId = $this->field('co_group_id', array('AteResearchTeam.id' => $id));
    $coId = $groupId ? $this->CoGroup->field('co_id', array('CoGroup.id' => $groupId)) : null;

    if(!$coId) {
      throw new InvalidArgumentException(_txt('er.notfound', array(_txt('ct.ate_research_teams.1'), $id)));
    }

    return $coId;
  }

  /**
   * Determine whether a research team's CoGroup has been deleted.
   *
   * Registry deletes CoGroups softly and leaves plugin foreign keys pointing
   * at them, so a team can outlive its group. Such a team is no longer a
   * research team (R29).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteResearchTeam ID
   * @return Boolean     True if the group is missing, deleted, or archived
   * @throws InvalidArgumentException If the team does not exist
   */

  public function groupDeleted($id) {
    $groupId = $this->field('co_group_id', array('AteResearchTeam.id' => $id));

    if(!$groupId) {
      throw new InvalidArgumentException(_txt('er.notfound', array('AteResearchTeam', $id)));
    }

    // Changelog's beforeFind does not filter a lookup by id, so read the
    // flags directly.
    $group = $this->CoGroup->find('first', array(
      'conditions' => array('CoGroup.id' => $groupId),
      'fields' => array('CoGroup.id', 'CoGroup.deleted', 'CoGroup.co_group_id'),
      'contain' => false
    ));

    return empty($group['CoGroup']['id'])
           || !empty($group['CoGroup']['deleted'])
           || !empty($group['CoGroup']['co_group_id']);
  }

  /**
   * Validate that the CoGroup is not already a research team.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateUniqueGroup($check) {
    return $this->validateUniqueCurrent(array('co_group_id'),
                                        'pl.applicationteamenroller.er.research_team.unique');
  }
}
