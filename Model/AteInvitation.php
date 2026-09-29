<?php
/**
 * COmanage Registry Application Team Enroller Invitation Model
 *
 * One email invitation covering one or more applications (R7, R10). An audit
 * record: no changelog, nullable foreign keys, and never cascade-deleted.
 * The link token is stored only as its SHA-256 hash (KTD4).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteInvitation extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteInvitation";

  // Add behaviors
  public $actsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    "Co",
    "InviterCoPerson" => array(
      'className' => 'CoPerson',
      'foreignKey' => 'inviter_co_person_id'
    ),
    "InviteeCoPerson" => array(
      'className' => 'CoPerson',
      'foreignKey' => 'invitee_co_person_id'
    ),
    "LinkTargetCoPerson" => array(
      'className' => 'CoPerson',
      'foreignKey' => 'link_target_co_person_id'
    ),
    "RevokedByCoPerson" => array(
      'className' => 'CoPerson',
      'foreignKey' => 'revoked_by_co_person_id'
    ),
    "CoPetition"
  );

  public $hasMany = array(
    "AteEnrollmentRequest" => array(
      'className' => 'ApplicationTeamEnroller.AteEnrollmentRequest',
      'foreignKey' => 'ate_invitation_id',
      'dependent' => false
    )
  );

  // Default display field for cake generated views
  public $displayField = "invited_email";

  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'invited_email' => array(
      'rule' => array('email'),
      'required' => true,
      'allowEmpty' => false
    ),
    'inviter_co_person_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'invitee_co_person_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'status' => array(
      'rule' => array('validateEnum', 'AteInvitationStatusEnum'),
      'required' => true,
      'allowEmpty' => false
    ),
    'expires' => array(
      'rule' => array('validateTimestamp'),
      'required' => true,
      'allowEmpty' => false
    ),
    'token_hash' => array(
      'rule' => array('custom', '/^[0-9a-f]{64}$/'),
      'required' => true,
      'allowEmpty' => false
    ),
    'identity_emails' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'mismatch' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    ),
    'responder_identifier' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'responder_identifier_type' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'responder_name' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    'link_target_co_person_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'co_petition_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'responded_at' => array(
      'rule' => array('validateTimestamp'),
      'required' => false,
      'allowEmpty' => true
    ),
    'revoked_by_co_person_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'revoked_at' => array(
      'rule' => array('validateTimestamp'),
      'required' => false,
      'allowEmpty' => true
    ),
    'expiry_notified' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    )
  );
}
