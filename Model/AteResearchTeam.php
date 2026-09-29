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
