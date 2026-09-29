<?php
/**
 * COmanage Registry Application Team Enroller Enrollment Request Team Model
 *
 * A team offered on a request, and what approval did for it: membership
 * added, already present, or skipped because the application no longer
 * authorizes the team (R8, R26, R29). An audit record: no changelog, nullable
 * foreign keys, and never cascade-deleted.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteEnrollmentRequestTeam extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteEnrollmentRequestTeam";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    "AteEnrollmentRequest" => array(
      'className' => 'ApplicationTeamEnroller.AteEnrollmentRequest',
      'foreignKey' => 'ate_enrollment_request_id'
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
    'ate_enrollment_request_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'ate_research_team_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    // Empty until the request is approved
    'outcome' => array(
      'rule' => array('validateEnum', 'AteTeamOutcomeEnum'),
      'required' => false,
      'allowEmpty' => true
    )
  );
}
