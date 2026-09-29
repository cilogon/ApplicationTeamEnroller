<?php
/**
 * COmanage Registry Application Team Enroller Enrollment Request Model
 *
 * One application offered by an invitation, with the researcher's response
 * and the decision (R8). An audit record: no changelog, nullable foreign
 * keys, and never cascade-deleted.
 *
 * pending_reason records why a request is pending, set once at response time
 * (KTD7). draft_choice holds a newcomer's accept (true) or decline (false)
 * until the enrollment flow completes (KTD11); null means no draft.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteEnrollmentRequest extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteEnrollmentRequest";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    "AteInvitation" => array(
      'className' => 'ApplicationTeamEnroller.AteInvitation',
      'foreignKey' => 'ate_invitation_id'
    ),
    "AteApplication" => array(
      'className' => 'ApplicationTeamEnroller.AteApplication',
      'foreignKey' => 'ate_application_id'
    ),
    "DeciderCoPerson" => array(
      'className' => 'CoPerson',
      'foreignKey' => 'decider_co_person_id'
    ),
    "WithdrawnByCoPerson" => array(
      'className' => 'CoPerson',
      'foreignKey' => 'withdrawn_by_co_person_id'
    )
  );

  public $hasMany = array(
    "AteEnrollmentRequestTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteEnrollmentRequestTeam',
      'foreignKey' => 'ate_enrollment_request_id',
      'dependent' => false
    )
  );

  // Default display field for cake generated views
  public $displayField = "id";

  // Validation rules for table elements
  public $validate = array(
    'ate_invitation_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'ate_application_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'status' => array(
      'rule' => array('validateEnum', 'AteRequestStatusEnum'),
      'required' => true,
      'allowEmpty' => false
    ),
    'draft_choice' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    ),
    'pending_reason' => array(
      'rule' => array('validateEnum', 'AtePendingReasonEnum'),
      'required' => false,
      'allowEmpty' => true
    ),
    'decided_by_role' => array(
      'rule' => array('validateEnum', 'AteDecidedByRoleEnum'),
      'required' => false,
      'allowEmpty' => true
    ),
    'decider_co_person_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'decided_at' => array(
      'rule' => array('validateTimestamp'),
      'required' => false,
      'allowEmpty' => true
    ),
    // Free text kept for audit and never sent to the researcher (R36)
    'comment' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'withdrawn_by_co_person_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    )
  );
}
