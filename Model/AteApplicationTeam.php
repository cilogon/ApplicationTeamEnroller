<?php
/**
 * COmanage Registry Application Team Enroller Application Team Model
 *
 * Authorizes a research team for an application (R3). It controls which
 * teams an admin may offer and which team groups are nested into the
 * application's access group (R31). It grants no membership to anyone.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteApplicationTeam extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteApplicationTeam";

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array(
    "AteApplication" => array(
      'className' => 'ApplicationTeamEnroller.AteApplication',
      'foreignKey' => 'ate_application_id'
    ),
    "AteResearchTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteResearchTeam',
      'foreignKey' => 'ate_research_team_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "ate_research_team_id";

  // Validation rules for table elements
  public $validate = array(
    'ate_application_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'ate_research_team_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false
      ),
      'unique' => array(
        'rule' => array('validateUniqueMapping')
      )
    )
  );

  /**
   * Validate that the team is not already authorized for the application.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateUniqueMapping($check) {
    return $this->validateUniqueCurrent(array('ate_application_id', 'ate_research_team_id'),
                                        'pl.applicationteamenroller.er.application_team.unique');
  }
}
