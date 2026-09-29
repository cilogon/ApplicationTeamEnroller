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
 * This model is also the routing and approval engine (U7): identity
 * evaluation (R22, KTD5, KTD6), committing a response and routing each
 * accepted request (KTD7, the "Response routing" diagram), and approve, deny,
 * and withdraw (KTD8, KTD9). Every status change is a conditional update in a
 * transaction. Memberships and identity links are written with provisioning
 * off, and the researcher is provisioned once after the commit, so a
 * rolled-back approval never reaches a provisioner.
 *
 * It also carries U10's announcement hooks (R35-R37, KTD12, KTD13), which
 * run after a status change has committed and never undo it:
 * afterResponse() after commitResponse() (U8 and the newcomer wedge),
 * afterDecision() after approve() and deny() (the decision queue), and
 * resolvePendingNotification(), which AteInvitation::withdrawRequest() calls.
 * The engine methods themselves send nothing.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');
App::uses('AteSetting', 'ApplicationTeamEnroller.Model');
App::uses('CakeEmail', 'Network/Email');

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

  // Why an identity evaluation is not a plain match (evaluateIdentity())
  const ReasonNoEmails        = 'no_emails';
  const ReasonEmailNotReported = 'email_not_reported';
  const ReasonOtherPerson     = 'address_on_other_person';
  const ReasonAmbiguousOwner  = 'ambiguous_owner';
  const ReasonLookupError     = 'lookup_error';

  // The CakeEmail configuration used for decision emails to the researcher
  // (R36, KTD13): a config name from app/Config/email.php, or a config
  // array. Tests point it at a recording transport.
  public $emailConfig = 'default';

  // How many callers have CoGroupMember provisioning suspended, and whether
  // it was enabled before the first did (suspendMembershipProvisioning())
  protected $provisioningSuspended = 0;
  protected $provisioningWasEnabled = false;

  /**
   * Normalize an identity snapshot (KTD5). U8 builds the snapshot on the
   * response page; this is its one shape:
   *
   *   identifier      String, the login (Auth.User.username), required
   *   identifier_type String or null
   *   emails          Array of Strings: the configured email claims plus
   *                   verifiedLoginEmails(), in the order found
   *   name            String or null
   *   errors          Array of Strings: any lookup that failed while the
   *                   snapshot was built (each one makes it a mismatch)
   *
   * Emails are trimmed and deduplicated case-insensitively; non-strings and
   * empty strings are dropped. The identifier is kept exactly as given.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $snapshot Snapshot as built by the caller
   * @return Array           Normalized snapshot
   * @throws InvalidArgumentException If there is no identifier
   */

  public static function normalizeSnapshot($snapshot) {
    if(!is_array($snapshot)
       || !isset($snapshot['identifier'])
       || !is_string($snapshot['identifier'])
       || trim($snapshot['identifier']) === '') {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.snapshot.identifier'));
    }

    $emails = array();
    $seen = array();

    foreach((array)($snapshot['emails'] ?? array()) as $e) {
      if(!is_string($e) || trim($e) === '') {
        continue;
      }

      $e = trim($e);
      $k = strtolower($e);

      if(!isset($seen[$k])) {
        $seen[$k] = true;
        $emails[] = $e;
      }
    }

    $errors = array();

    foreach((array)($snapshot['errors'] ?? array()) as $err) {
      if(is_scalar($err) && (string)$err !== '') {
        $errors[] = (string)$err;
      }
    }

    $type = $snapshot['identifier_type'] ?? null;
    $name = $snapshot['name'] ?? null;

    return array(
      'identifier'      => $snapshot['identifier'],
      'identifier_type' => (is_string($type) && $type !== '') ? $type : null,
      'emails'          => $emails,
      'name'            => (is_string($name) && trim($name) !== '') ? trim($name) : null,
      'errors'          => $errors
    );
  }

  /**
   * Evaluate a login identity against an invited address (R22, KTD5). Uses
   * only the snapshot and the database, never the request environment.
   *
   * - link_required: the invited address belongs (a verified EmailAddress on
   *   the CoPerson or on an OrgIdentity linked to it) to an existing CoPerson
   *   of the CO, and the responder has no CoPerson. With more than one such
   *   CoPerson there is no link target, and approval is refused.
   * - mismatch: the snapshot has no emails, does not report the invited
   *   address (case-insensitively), reports a lookup error, or the invited
   *   address belongs to a CoPerson other than the responder. Any lookup
   *   error here is logged and makes the result a mismatch with no link.
   * - match: otherwise.
   *
   * 'mismatch' is the R22 flag stored on the invitation: true for a
   * mismatch, and for link_required when the login did not report the
   * invited address.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId                CO ID
   * @param  String  $invitedEmail        Invited address
   * @param  Array   $snapshot            Identity snapshot (normalizeSnapshot())
   * @param  Integer $responderCoPersonId Responder's CoPerson, or null to look up the login (KTD6)
   * @return Array                        'result' (AteIdentityResultEnum), 'mismatch' (Boolean),
   *                                      'reasons' (Array of Reason* codes), 'responder_co_person_id',
   *                                      'link_target_co_person_id' (Integer or null), 'snapshot'
   * @throws InvalidArgumentException If the snapshot has no identifier
   */

  public function evaluateIdentity($coId, $invitedEmail, $snapshot, $responderCoPersonId = null) {
    $snap = self::normalizeSnapshot($snapshot);
    $invited = strtolower(trim((string)$invitedEmail));
    $reasons = array();
    $lookupError = false;
    $responder = $responderCoPersonId ? (int)$responderCoPersonId : null;
    $owners = array();

    foreach($snap['errors'] as $err) {
      $this->log('ApplicationTeamEnroller identity snapshot for ' . $snap['identifier']
                 . ' has a lookup error: ' . $err, LOG_ERROR);
      $lookupError = true;
    }

    try {
      if($responder === null) {
        $responder = $this->existingMemberCoPersonId($coId, $snap['identifier']);
      }

      $owners = $this->emailOwnerCoPersonIds($coId, $invited);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller identity lookup for ' . $snap['identifier']
                 . ' failed: ' . $e->getMessage(), LOG_ERROR);
      $lookupError = true;
    }

    if($lookupError) {
      $reasons[] = self::ReasonLookupError;
    }

    $reported = in_array($invited, array_map('strtolower', $snap['emails']), true);

    if(empty($snap['emails'])) {
      $reasons[] = self::ReasonNoEmails;
    } elseif(!$reported) {
      $reasons[] = self::ReasonEmailNotReported;
    }

    $target = null;
    $link = false;

    if(!$lookupError && !empty($owners)) {
      if($responder === null) {
        $link = true;

        if(count($owners) === 1) {
          $target = $owners[0];
        } else {
          $reasons[] = self::ReasonAmbiguousOwner;
        }
      } elseif(array_diff($owners, array($responder))) {
        $reasons[] = self::ReasonOtherPerson;
      }
    }

    if($lookupError) {
      $result = AteIdentityResultEnum::Mismatch;
    } elseif($link) {
      $result = AteIdentityResultEnum::LinkRequired;
    } elseif(!empty($reasons)) {
      $result = AteIdentityResultEnum::Mismatch;
    } else {
      $result = AteIdentityResultEnum::Match;
    }

    return array(
      'result'                   => $result,
      'mismatch'                 => ($result === AteIdentityResultEnum::Mismatch) || !$reported,
      'reasons'                  => $reasons,
      'responder_co_person_id'   => $responder,
      'link_target_co_person_id' => $target,
      'snapshot'                 => $snap
    );
  }

  /**
   * The CoPerson a login belongs to in a CO (KTD6, R20): the login is an
   * Active, login = true Identifier on a current OrgIdentity linked to a
   * current CoPerson of the CO, whatever that CoPerson's status.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId       CO ID
   * @param  String  $identifier Login identifier
   * @return Integer             CoPerson ID, or null if none
   * @throws RuntimeException If the login belongs to more than one CoPerson of the CO
   */

  public function existingMemberCoPersonId($coId, $identifier) {
    $ids = $this->loginCoPersonIds($coId, $identifier);

    if(count($ids) > 1) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.login.ambiguous'));
    }

    return empty($ids) ? null : $ids[0];
  }

  /**
   * The verified email addresses on the OrgIdentities that carry a login as
   * an Active, login = true Identifier, in the CO or pooled (KTD5).
   * Unverified rows never count. U8 adds these to the snapshot.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId       CO ID
   * @param  String  $identifier Login identifier
   * @return Array               Addresses, deduplicated case-insensitively, oldest row first
   */

  public function verifiedLoginEmails($coId, $identifier) {
    $p = $this->tablePrefix;

    $rows = $this->sqlRows(
      'SELECT e.id AS id, e.mail AS mail'
      . ' FROM ' . $p . 'email_addresses e'
      . ' JOIN ' . $p . 'org_identities oi ON oi.id = e.org_identity_id'
      . ' JOIN ' . $p . 'identifiers i ON i.org_identity_id = oi.id'
      . ' WHERE i.identifier = ? AND i.login = true AND i.status = ?'
      . ' AND i.identifier_id IS NULL AND i.deleted IS NOT true'
      . ' AND oi.org_identity_id IS NULL AND oi.deleted IS NOT true'
      . ' AND (oi.co_id = ? OR oi.co_id IS NULL)'
      . ' AND e.verified = true AND e.email_address_id IS NULL AND e.deleted IS NOT true'
      . ' ORDER BY e.id',
      array((string)$identifier, SuspendableStatusEnum::Active, (int)$coId)
    );

    $ret = array();
    $seen = array();

    foreach($rows as $r) {
      $k = strtolower(trim($r['mail']));

      if($k !== '' && !isset($seen[$k])) {
        $seen[$k] = true;
        $ret[] = trim($r['mail']);
      }
    }

    return $ret;
  }

  /**
   * The current CoPeople of a CO an address belongs to: a verified
   * EmailAddress on the CoPerson, or on a current OrgIdentity linked to it,
   * compared case-insensitively (R22).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId  CO ID
   * @param  String  $email Address
   * @return Array          CoPerson IDs, ascending
   */

  public function emailOwnerCoPersonIds($coId, $email) {
    $p = $this->tablePrefix;
    $email = trim((string)$email);

    if($email === '') {
      return array();
    }

    $rows = $this->sqlRows(
      'SELECT cp.id AS id FROM ' . $p . 'co_people cp'
      . ' WHERE cp.co_id = ? AND cp.co_person_id IS NULL AND cp.deleted IS NOT true AND ('
      . ' EXISTS (SELECT 1 FROM ' . $p . 'email_addresses e WHERE e.co_person_id = cp.id'
      . '   AND lower(e.mail) = lower(?) AND e.verified = true'
      . '   AND e.email_address_id IS NULL AND e.deleted IS NOT true)'
      . ' OR EXISTS (SELECT 1 FROM ' . $p . 'co_org_identity_links l'
      . '   JOIN ' . $p . 'org_identities oi ON oi.id = l.org_identity_id'
      . '   JOIN ' . $p . 'email_addresses e ON e.org_identity_id = oi.id'
      . '   WHERE l.co_person_id = cp.id'
      . '   AND l.co_org_identity_link_id IS NULL AND l.deleted IS NOT true'
      . '   AND oi.org_identity_id IS NULL AND oi.deleted IS NOT true'
      . '   AND lower(e.mail) = lower(?) AND e.verified = true'
      . '   AND e.email_address_id IS NULL AND e.deleted IS NOT true))'
      . ' ORDER BY cp.id',
      array((int)$coId, $email, $email)
    );

    return array_map('intval', Hash::extract($rows, '{n}.id'));
  }

  /**
   * Commit a researcher's response to an invitation (F2, R19-R24, R27, KTD7,
   * KTD8) and route each accepted request per the "Response routing"
   * diagram:
   *
   * - link_required identity: pending_decision, reason link_required.
   * - otherwise, "approval free" means the application and every current
   *   application authorizing any of its offered teams have approval off.
   *   A mismatch is pending with reason mismatch if approval free, else
   *   approval. A match is approved automatically (decided_by_role
   *   automatic, through the same path as approve()) if approval free, else
   *   pending with reason approval.
   * - a decline becomes declined_by_enrollee.
   *
   * The invitation moves from sent to responded in one conditional update,
   * with the snapshot, mismatch flag, responder, and link target, so only one
   * response ever commits. The caller checks that the current login equals
   * the snapshot identifier (KTD5) and that the invitation has not lapsed
   * (KTD14); this method honors an invitation still sent.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId                 CO ID
   * @param  Integer $invitationId         AteInvitation ID
   * @param  Array   $snapshot             Identity snapshot (normalizeSnapshot())
   * @param  Array   $choices              True (accept) or false (decline), keyed by AteEnrollmentRequest ID; one per offered request
   * @param  Integer $respondingCoPersonId The responder's CoPerson, or null to use the login's (KTD6)
   * @return Array                         See responseResult()
   * @throws InvalidArgumentException If the choices, responder, or snapshot are not acceptable
   * @throws RuntimeException         If a write fails
   */

  public function commitResponse($coId, $invitationId, $snapshot, $choices, $respondingCoPersonId = null) {
    $snap = self::normalizeSnapshot($snapshot);
    $inv = $this->invitationRow($coId, $invitationId);

    if(!$inv || $inv['status'] !== AteInvitationStatusEnum::Sent) {
      return $this->responseResult($coId, $invitationId, false);
    }

    // One boolean choice per offered request of this invitation, no more
    $offered = array();

    foreach($this->sqlRows('SELECT id, ate_application_id, status FROM ' . $this->tablePrefix
                           . 'ate_enrollment_requests WHERE ate_invitation_id = ? ORDER BY id',
                           array((int)$invitationId)) as $r) {
      if($r['status'] === AteRequestStatusEnum::Offered) {
        $offered[(int)$r['id']] = (int)$r['ate_application_id'];
      }
    }

    $accept = array();

    foreach((array)$choices as $reqId => $choice) {
      $b = filter_var($choice, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

      if(!isset($offered[(int)$reqId]) || $b === null) {
        throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.response.choices'));
      }

      $accept[(int)$reqId] = $b;
    }

    if(empty($offered) || count($accept) !== count($offered)) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.response.choices'));
    }

    // The responder: the caller's CoPerson must agree with the login's (KTD6)
    $loginPerson = $this->existingMemberCoPersonId($coId, $snap['identifier']);
    $responder = $loginPerson;

    if($respondingCoPersonId) {
      if(!$this->isCurrentCoPerson($coId, $respondingCoPersonId)
         || ($loginPerson && $loginPerson !== (int)$respondingCoPersonId)) {
        throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.response.responder'));
      }

      $responder = (int)$respondingCoPersonId;
    }

    $eval = $this->evaluateIdentity($coId, $inv['invited_email'], $snap, $responder);
    $linkRequired = ($eval['result'] === AteIdentityResultEnum::LinkRequired);

    if(!$responder && !$linkRequired && in_array(true, $accept, true)) {
      // A newcomer who accepts goes through the enrollment flow first (R21)
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.response.person'));
    }

    $dbc = $this->getDataSource();
    $now = date('Y-m-d H:i:s');
    $toProvision = null;

    $dbc->begin();
    $this->suspendMembershipProvisioning();

    try {
      $claimed = $this->conditionalUpdate($this->AteInvitation, array(
        'status'                    => AteInvitationStatusEnum::Responded,
        'responded_at'              => $now,
        'modified'                  => $now,
        'responder_identifier'      => $snap['identifier'],
        'responder_identifier_type' => $snap['identifier_type'],
        'responder_name'            => ($snap['name'] !== null) ? mb_substr($snap['name'], 0, 256) : null,
        'identity_emails'           => json_encode($snap['emails']),
        'mismatch'                  => (bool)$eval['mismatch'],
        'invitee_co_person_id'      => $responder,
        'link_target_co_person_id'  => $linkRequired ? $eval['link_target_co_person_id'] : null
      ), array(
        'id'     => (int)$invitationId,
        'co_id'  => (int)$coId,
        'status' => AteInvitationStatusEnum::Sent
      ));

      if(!$claimed) {
        $dbc->rollback();
        $this->resumeMembershipProvisioning();
        return $this->responseResult($coId, $invitationId, false, $eval);
      }

      foreach($offered as $reqId => $appId) {
        if(!$accept[$reqId]) {
          $this->moveOffered($reqId, AteRequestStatusEnum::DeclinedByEnrollee, null, $now);
          continue;
        }

        if($linkRequired) {
          $reason = AtePendingReasonEnum::LinkRequired;
        } else {
          $free = $this->approvalFree($appId, $this->offeredTeamIds($reqId));

          if($eval['result'] === AteIdentityResultEnum::Mismatch) {
            $reason = $free ? AtePendingReasonEnum::Mismatch : AtePendingReasonEnum::Approval;
          } else {
            $reason = $free ? null : AtePendingReasonEnum::Approval;
          }
        }

        if($reason !== null) {
          $this->moveOffered($reqId, AteRequestStatusEnum::PendingDecision, $reason, $now);
          continue;
        }

        // Approved automatically, through the approve() path (R27)
        $out = $this->applyApproval($coId, $reqId, AteRequestStatusEnum::Offered,
                                    null, AteDecidedByRoleEnum::Automatic, null);

        if(!$out['handled']) {
          throw new RuntimeException(_txt('er.db.save-a', array('AteEnrollmentRequest')));
        }

        if($out['changed']) {
          $toProvision = $out['co_person_id'];
        }
      }

      $this->commitOrFail($dbc);
    } catch(Exception $e) {
      $dbc->rollback();
      $this->resumeMembershipProvisioning();
      throw $e;
    }

    $this->resumeMembershipProvisioning();
    $provisioned = $toProvision ? $this->provisionAfterCommit($toProvision) : false;

    return array_merge($this->responseResult($coId, $invitationId, true, $eval),
                       array('provisioned' => $provisioned));
  }

  /**
   * Approve a pending request (F3, R26, R29, KTD8, KTD9). In one
   * transaction: move it from pending_decision to approved, re-check each
   * offered team (a team no longer mapped to the application, no longer a
   * current research team, or whose CoGroup is deleted is skipped), link the
   * login to the target CoPerson for link_required (only if a team remains),
   * add the memberships, and record per-team outcomes. The researcher is
   * provisioned once after the commit.
   *
   * The caller checks AteAuthzComponent::decidingRole() first; this method
   * still refuses a decision by the responder or the link target (R39).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId              CO ID
   * @param  Integer $requestId         AteEnrollmentRequest ID
   * @param  Integer $deciderCoPersonId Deciding CoPerson ID
   * @param  String  $decidedByRole     AteDecidedByRoleEnum value, not Automatic
   * @param  String  $comment           Optional comment, kept for audit only (R36)
   * @return Array                      See decisionResult()
   * @throws InvalidArgumentException If the decider or role is not acceptable
   * @throws RuntimeException         If a write fails or there is no one to approve for
   */

  public function approve($coId, $requestId, $deciderCoPersonId, $decidedByRole, $comment = null) {
    if(!$this->checkDecision($coId, $requestId, $deciderCoPersonId, $decidedByRole)) {
      return $this->decisionResult($coId, $requestId, false);
    }

    $dbc = $this->getDataSource();

    $dbc->begin();
    $this->suspendMembershipProvisioning();

    try {
      $out = $this->applyApproval($coId, $requestId, AteRequestStatusEnum::PendingDecision,
                                  (int)$deciderCoPersonId, $decidedByRole, $comment);

      if(!$out['handled']) {
        $dbc->rollback();
        $this->resumeMembershipProvisioning();
        return $this->decisionResult($coId, $requestId, false);
      }

      $this->commitOrFail($dbc);
    } catch(Exception $e) {
      $dbc->rollback();
      $this->resumeMembershipProvisioning();
      throw $e;
    }

    $this->resumeMembershipProvisioning();
    $provisioned = $out['changed'] ? $this->provisionAfterCommit($out['co_person_id']) : false;

    return array_merge($this->decisionResult($coId, $requestId, true), array(
      'linked_org_identity_id' => $out['linked_org_identity_id'],
      'provisioned'            => $provisioned
    ));
  }

  /**
   * Deny a pending request (F3, R28, KTD8): a conditional transition from
   * pending_decision to denied that records the decider, role, time, and
   * comment, and creates nothing.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId              CO ID
   * @param  Integer $requestId         AteEnrollmentRequest ID
   * @param  Integer $deciderCoPersonId Deciding CoPerson ID
   * @param  String  $decidedByRole     AteDecidedByRoleEnum value, not Automatic
   * @param  String  $comment           Optional comment, kept for audit only (R36)
   * @return Array                      See decisionResult()
   * @throws InvalidArgumentException If the decider or role is not acceptable
   * @throws RuntimeException         If the update fails
   */

  public function deny($coId, $requestId, $deciderCoPersonId, $decidedByRole, $comment = null) {
    if(!$this->checkDecision($coId, $requestId, $deciderCoPersonId, $decidedByRole)) {
      return $this->decisionResult($coId, $requestId, false);
    }

    $now = date('Y-m-d H:i:s');

    $ok = $this->conditionalUpdate($this, array(
      'status'               => AteRequestStatusEnum::Denied,
      'decided_by_role'      => $decidedByRole,
      'decider_co_person_id' => (int)$deciderCoPersonId,
      'decided_at'           => $now,
      'comment'              => $this->commentValue($comment),
      'modified'             => $now
    ), array(
      'id'     => (int)$requestId,
      'status' => AteRequestStatusEnum::PendingDecision
    ));

    return $this->decisionResult($coId, $requestId, $ok);
  }

  /**
   * Withdraw a pending request (R18, AE17), through
   * AteInvitation::withdrawRequest(), returning the decision shape.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId            CO ID
   * @param  Integer $requestId       AteEnrollmentRequest ID
   * @param  Integer $actorCoPersonId Withdrawing CoPerson ID
   * @return Array                    See decisionResult()
   * @throws RuntimeException If the update fails
   */

  public function withdraw($coId, $requestId, $actorCoPersonId) {
    $ok = $this->AteInvitation->withdrawRequest($coId, $requestId, $actorCoPersonId);

    return $this->decisionResult($coId, $requestId, $ok);
  }

  /**
   * Announce what a committed response did (R35, R36, R37): register the
   * decider notification for each request that became pending_decision, and
   * for each request approved automatically email the researcher and notify
   * the inviting admin. No decider notification is registered for an
   * automatic approval. U8 and the newcomer wedge call this after
   * commitResponse(), once their own transaction (if any) has committed; it
   * does nothing for a response this call did not commit.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId   CO ID
   * @param  Array   $result commitResponse() result
   * @return Array           'pending_notifications' (CoNotification IDs, keyed by request ID; only
   *                         requests that got one) and 'decisions' (announceDecision() result, keyed
   *                         by the ID of each automatically approved request)
   */

  public function afterResponse($coId, $result) {
    $ret = array('pending_notifications' => array(), 'decisions' => array());

    if(empty($result['handled']) || empty($result['requests'])) {
      return $ret;
    }

    foreach($result['requests'] as $reqId => $r) {
      if($r['status'] === AteRequestStatusEnum::PendingDecision) {
        $ids = $this->notifyPending($coId, $reqId);

        if(!empty($ids)) {
          $ret['pending_notifications'][(int)$reqId] = $ids;
        }
      } elseif($r['status'] === AteRequestStatusEnum::Approved
               && $r['decided_by_role'] === AteDecidedByRoleEnum::Automatic) {
        $ret['decisions'][(int)$reqId] = $this->announceDecision($coId, $reqId, null);
      }
    }

    return $ret;
  }

  /**
   * Announce a decision (R35, R36, R37): resolve the request's decider
   * notification and, for an approval or denial, email the researcher and
   * notify the inviting admin. The decision queue calls this after approve()
   * or deny(); it does nothing for a call that lost the race (handled false),
   * so each decision is announced once.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId            CO ID
   * @param  Array   $result          approve(), deny(), or withdraw() result
   * @param  Integer $actorCoPersonId Deciding CoPerson ID
   * @return Array                    'resolved' (Boolean, a decider notification was resolved by this
   *                                  call), 'researcher_emailed' (Boolean), 'inviter_notifications'
   *                                  (CoNotification IDs)
   */

  public function afterDecision($coId, $result, $actorCoPersonId) {
    $ret = array('resolved' => false, 'researcher_emailed' => false, 'inviter_notifications' => array());

    if(empty($result['handled']) || empty($result['request_id'])) {
      return $ret;
    }

    $ret['resolved'] = $this->resolvePendingNotification($result['request_id'], $actorCoPersonId);

    if(in_array($result['status'], array(AteRequestStatusEnum::Approved, AteRequestStatusEnum::Denied), true)) {
      $ret = array_merge($ret, $this->announceDecision($coId, $result['request_id'], $actorCoPersonId));
    }

    return $ret;
  }

  /**
   * Register the decider notification for a pending request (R35, KTD12),
   * addressed by its pending_reason (KTD7, R24):
   *
   * - approval: the application's approver group;
   * - mismatch: the inviting admin while they still hold that role (in the
   *   application's admin group, or a CO administrator), else the admin group;
   * - link_required, or anything else: the CO admins group.
   *
   * The notification must be resolved, and its source is the request's
   * decisionUrl() string, which resolvePendingNotification() matches. CO
   * administrators may decide any request but are notified only for
   * link_required. A request that is not pending, or already has an open
   * decider notification, gets none. A failure is logged, never thrown.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId      CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Array              CoNotification IDs registered (empty if none)
   */

  public function notifyPending($coId, $requestId) {
    try {
      $d = $this->announcementDetails($coId, $requestId);

      if(!$d || $d['status'] !== AteRequestStatusEnum::PendingDecision) {
        return array();
      }

      $url = $this->decisionUrl($requestId);

      if($this->openNotificationCount($url) > 0) {
        return array();
      }

      $recipient = $this->deciderRecipient($coId, $d);

      if(!$recipient) {
        $this->log('ApplicationTeamEnroller found no decider to notify for request ' . $requestId, LOG_ERROR);
        return array();
      }

      // The researcher is the subject. With none (an ambiguous link target),
      // a recipient group stands in, so Registry can still find the CO.
      $subjectGroup = (!$d['researcher_co_person_id'] && $recipient[0] === 'cogroup') ? $recipient[1] : null;

      $ids = ClassRegistry::init('CoNotification')->register(
        $d['researcher_co_person_id'],
        $subjectGroup,
        $d['invitee_co_person_id'],
        $recipient[0],
        $recipient[1],
        AteNotificationActionEnum::PendingDecision,
        _txt('pl.applicationteamenroller.notification.pending', array($d['application_name'], $d['invited_email'])),
        $url,
        true
      );

      return array_map('intval', (array)$ids);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not notify the deciders of request ' . $requestId . ': '
                 . $e->getMessage(), LOG_ERROR);
      return array();
    }
  }

  /**
   * Resolve a request's open decider notification (R35, KTD12), by the same
   * decisionUrl() string it was registered with. Called on decision and on
   * withdrawal. A failure is logged, never thrown.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $requestId       AteEnrollmentRequest ID
   * @param  Integer $actorCoPersonId Resolving CoPerson ID, or null
   * @return Boolean                  True if an open notification was resolved
   */

  public function resolvePendingNotification($requestId, $actorCoPersonId) {
    $url = $this->decisionUrl($requestId);

    try {
      if($this->openNotificationCount($url) === 0) {
        return false;
      }

      ClassRegistry::init('CoNotification')->resolveFromSource($url, $actorCoPersonId ? (int)$actorCoPersonId : null);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not resolve the notification for request ' . $requestId . ': '
                 . $e->getMessage(), LOG_ERROR);
      return false;
    }

    return $this->openNotificationCount($url) === 0;
  }

  /**
   * Notify an invitation's inviting admin (R37): an informational
   * notification to acknowledge, pointing at the invitation. Used for
   * decisions (AteNotificationActionEnum::Decided); U11 uses it for expiry
   * (AteNotificationActionEnum::Expired). A failure is logged, never thrown.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId              CO ID
   * @param  Integer $invitationId      AteInvitation ID
   * @param  String  $action            AteNotificationActionEnum value
   * @param  String  $comment           Notification text
   * @param  Integer $subjectCoPersonId Researcher's CoPerson ID, or null
   * @param  Integer $actorCoPersonId   Acting CoPerson ID, or null
   * @return Array                      CoNotification IDs registered (empty if none)
   */

  public function notifyInviter($coId, $invitationId, $action, $comment, $subjectCoPersonId, $actorCoPersonId) {
    try {
      $rows = $this->sqlRows('SELECT inviter_co_person_id FROM ' . $this->tablePrefix
                             . 'ate_invitations WHERE id = ? AND co_id = ?',
                             array((int)$invitationId, (int)$coId));

      if(empty($rows[0]['inviter_co_person_id'])) {
        return array();
      }

      $ids = ClassRegistry::init('CoNotification')->register(
        $subjectCoPersonId ? (int)$subjectCoPersonId : null,
        null,
        $actorCoPersonId ? (int)$actorCoPersonId : null,
        'coperson',
        (int)$rows[0]['inviter_co_person_id'],
        $action,
        $comment,
        Router::url(array(
          'plugin'     => 'application_team_enroller',
          'controller' => 'ate_invitations',
          'action'     => 'view',
          (int)$invitationId
        ), true),
        false
      );

      return array_map('intval', (array)$ids);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not notify the inviter of invitation ' . $invitationId . ': '
                 . $e->getMessage(), LOG_ERROR);
      return array();
    }
  }

  /**
   * The absolute URL of a request's decision page: the source of its decider
   * notification (KTD12). Registering and resolving use this one string.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return String             Absolute URL
   */

  public function decisionUrl($requestId) {
    return Router::url(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_enrollment_requests',
      'action'     => 'view',
      (int)$requestId
    ), true);
  }

  /**
   * The decision queue's entries for requests (R25): each with the
   * researcher and their CoPerson status (KTD6), the invited address, the
   * emails the login reported, the mismatch flag, the inviting admin, the
   * application, the offered teams, and, for link_required, the person the
   * login would be linked to. The caller chooses the requests (only those
   * the user may decide).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId          CO ID
   * @param  Array   $requestIds    AteEnrollmentRequest IDs
   * @param  Array   $decidingRoles AteDecidedByRoleEnum values, keyed by request ID
   * @return Array                  Entries, by ascending request ID. Each has 'id', 'application'
   *                                ('id', 'name'), 'teams' (names), 'invited_email', 'identity_emails',
   *                                'mismatch', 'pending_reason', 'inviter', 'researcher', 'link_target'
   *                                (each null or 'co_person_id', 'name', 'status'),
   *                                'responder_identifier', 'responder_name', 'responded_at',
   *                                'deciding_role'
   */

  public function queueEntries($coId, $requestIds, $decidingRoles = array()) {
    $requestIds = array_map('intval', (array)$requestIds);

    if(empty($requestIds)) {
      return array();
    }

    $args = array();
    $args['conditions']['AteEnrollmentRequest.id'] = $requestIds;
    $args['conditions']['AteInvitation.co_id'] = $coId;
    $args['order'] = array('AteEnrollmentRequest.id' => 'asc');
    $args['contain'] = array(
      'AteApplication',
      'AteEnrollmentRequestTeam' => array(
        'order' => array('AteEnrollmentRequestTeam.id' => 'asc'),
        'AteResearchTeam' => array('CoGroup')
      ),
      'AteInvitation' => array(
        'InviterCoPerson' => array('PrimaryName'),
        'InviteeCoPerson' => array('PrimaryName'),
        'LinkTargetCoPerson' => array('PrimaryName')
      )
    );

    $person = function($p) {
      if(empty($p['id'])) {
        return null;
      }

      return array(
        'co_person_id' => (int)$p['id'],
        'name'         => !empty($p['PrimaryName']) ? generateCn($p['PrimaryName']) : '',
        'status'       => $p['status']
      );
    };

    $ret = array();

    foreach($this->find('all', $args) as $r) {
      $req = $r['AteEnrollmentRequest'];
      $inv = $r['AteInvitation'];
      $teams = array();

      foreach($r['AteEnrollmentRequestTeam'] as $t) {
        $teams[] = !empty($t['AteResearchTeam']['name'])
                   ? $t['AteResearchTeam']['name']
                   : ($t['AteResearchTeam']['CoGroup']['name'] ?? '');
      }

      $emails = json_decode((string)$inv['identity_emails'], true);

      $ret[] = array(
        'id'                   => (int)$req['id'],
        'application'          => array('id' => (int)$r['AteApplication']['id'],
                                        'name' => $r['AteApplication']['name']),
        'teams'                => $teams,
        'invited_email'        => $inv['invited_email'],
        'identity_emails'      => is_array($emails) ? $emails : array(),
        'mismatch'             => self::truthy($inv['mismatch']),
        'pending_reason'       => $req['pending_reason'],
        'inviter'              => $person($inv['InviterCoPerson'] ?? array()),
        'researcher'           => $person($inv['InviteeCoPerson'] ?? array()),
        'link_target'          => ($req['pending_reason'] === AtePendingReasonEnum::LinkRequired)
                                  ? $person($inv['LinkTargetCoPerson'] ?? array()) : null,
        'responder_identifier' => $inv['responder_identifier'],
        'responder_name'       => $inv['responder_name'],
        'responded_at'         => $inv['responded_at'],
        'deciding_role'        => $decidingRoles[(int)$req['id']] ?? null
      );
    }

    return $ret;
  }

  /**
   * Email the researcher and notify the inviting admin of a decided request
   * (R36, R37). The researcher's email goes to the invited address, with text
   * from Lib/lang.php and never the decider's comment. The inviting admin is
   * not notified of a decision they made themselves.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId            CO ID
   * @param  Integer $requestId       AteEnrollmentRequest ID
   * @param  Integer $actorCoPersonId Deciding CoPerson ID, or null (automatic)
   * @return Array                    'researcher_emailed' and 'inviter_notifications'
   */

  protected function announceDecision($coId, $requestId, $actorCoPersonId) {
    $ret = array('researcher_emailed' => false, 'inviter_notifications' => array());

    try {
      $d = $this->announcementDetails($coId, $requestId);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not read request ' . $requestId . ' to announce its decision: '
                 . $e->getMessage(), LOG_ERROR);
      return $ret;
    }

    if(!$d || !in_array($d['status'], array(AteRequestStatusEnum::Approved, AteRequestStatusEnum::Denied), true)) {
      return $ret;
    }

    $ret['researcher_emailed'] = $this->sendDecisionEmail($d);

    if($d['inviter_co_person_id'] && (int)$d['inviter_co_person_id'] !== (int)$actorCoPersonId) {
      $ret['inviter_notifications'] = $this->notifyInviter(
        $coId,
        $d['ate_invitation_id'],
        AteNotificationActionEnum::Decided,
        _txt('pl.applicationteamenroller.notification.decided', array(
          $d['application_name'],
          $d['invited_email'],
          _txt('pl.applicationteamenroller.en.request_status.' . $d['status'])
        )),
        $d['researcher_co_person_id'],
        $actorCoPersonId
      );
    }

    return $ret;
  }

  /**
   * Email the researcher the decision on one application (R36, KTD13), at
   * the invited address. The text never includes the decider's comment. A
   * send failure is logged, never thrown: the decision stands.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $d announcementDetails() row
   * @return Boolean    True if the email was sent
   */

  protected function sendDecisionEmail($d) {
    $approved = ($d['status'] === AteRequestStatusEnum::Approved);
    $key = $approved ? 'approved' : 'denied';

    $teams = array();

    foreach($this->sqlRows(
      'SELECT rt.name AS team_name, g.name AS group_name, ert.outcome AS outcome'
      . ' FROM ' . $this->tablePrefix . 'ate_enrollment_request_teams ert'
      . ' LEFT JOIN ' . $this->tablePrefix . 'ate_research_teams rt ON rt.id = ert.ate_research_team_id'
      . ' LEFT JOIN ' . $this->tablePrefix . 'co_groups g ON g.id = rt.co_group_id'
      . ' WHERE ert.ate_enrollment_request_id = ? ORDER BY ert.id',
      array((int)$d['id'])
    ) as $t) {
      // A skipped team was not granted (R29), so it is not announced
      if($approved && $t['outcome'] !== AteTeamOutcomeEnum::Skipped) {
        $teams[] = ($t['team_name'] !== null && $t['team_name'] !== '') ? $t['team_name'] : $t['group_name'];
      }
    }

    $args = array($d['application_name'], $d['co_name'], implode(', ', $teams));

    try {
      $email = new CakeEmail($this->emailConfig);

      $email->emailFormat(MessageFormatEnum::Plaintext)
            ->to($d['invited_email'])
            ->subject(_txt('pl.applicationteamenroller.decision.email.subject.' . $key, $args));

      $email->send(_txt('pl.applicationteamenroller.decision.email.body.' . $key, $args));
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not email the decision on request ' . $d['id'] . ' to '
                 . $d['invited_email'] . ': ' . $e->getMessage(), LOG_ERROR);
      return false;
    }

    return true;
  }

  /**
   * Who is notified of a pending request (see notifyPending()).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @param  Array   $d    announcementDetails() row
   * @return Array         Recipient type ('coperson' or 'cogroup') and ID, or null
   */

  protected function deciderRecipient($coId, $d) {
    switch($d['pending_reason']) {
      case AtePendingReasonEnum::Approval:
        return $d['approver_co_group_id'] ? array('cogroup', (int)$d['approver_co_group_id']) : null;
      case AtePendingReasonEnum::Mismatch:
        $inviter = (int)$d['inviter_co_person_id'];
        $CoGroupMember = ClassRegistry::init('CoGroupMember');

        if($inviter) {
          $holds = $d['admin_co_group_id'] && $CoGroupMember->isMember($d['admin_co_group_id'], $inviter);

          if(!$holds) {
            try {
              $holds = $CoGroupMember->isMember(ClassRegistry::init('CoGroup')->adminCoGroupId($coId), $inviter);
            } catch(InvalidArgumentException $e) {
              $holds = false;
            }
          }

          if($holds) {
            return array('coperson', $inviter);
          }
        }

        return $d['admin_co_group_id'] ? array('cogroup', (int)$d['admin_co_group_id']) : null;
      default:
        // link_required, or an unknown reason: CO administrators only (R39)
        try {
          return array('cogroup', (int)ClassRegistry::init('CoGroup')->adminCoGroupId($coId));
        } catch(InvalidArgumentException $e) {
          return null;
        }
    }
  }

  /**
   * What the announcement hooks need about a request of the CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId      CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Array              Flat row with 'researcher_co_person_id' added, or null
   */

  protected function announcementDetails($coId, $requestId) {
    $p = $this->tablePrefix;

    $rows = $this->sqlRows(
      'SELECT r.id AS id, r.ate_invitation_id AS ate_invitation_id, r.status AS status,'
      . ' r.pending_reason AS pending_reason,'
      . ' i.invited_email AS invited_email, i.inviter_co_person_id AS inviter_co_person_id,'
      . ' i.invitee_co_person_id AS invitee_co_person_id,'
      . ' i.link_target_co_person_id AS link_target_co_person_id,'
      . ' a.name AS application_name, a.admin_co_group_id AS admin_co_group_id,'
      . ' a.approver_co_group_id AS approver_co_group_id, c.name AS co_name'
      . ' FROM ' . $p . 'ate_enrollment_requests r'
      . ' JOIN ' . $p . 'ate_invitations i ON i.id = r.ate_invitation_id'
      . ' JOIN ' . $p . 'ate_applications a ON a.id = r.ate_application_id'
      . ' JOIN ' . $p . 'cos c ON c.id = i.co_id'
      . ' WHERE r.id = ? AND i.co_id = ?',
      array((int)$requestId, (int)$coId)
    );

    if(empty($rows)) {
      return null;
    }

    $d = $rows[0];
    $researcher = ($d['pending_reason'] === AtePendingReasonEnum::LinkRequired)
                  ? $d['link_target_co_person_id']
                  : $d['invitee_co_person_id'];
    $d['researcher_co_person_id'] = $researcher ? (int)$researcher : null;

    return $d;
  }

  /**
   * How many open (pending resolution) notifications have a source URL.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String  $url Source URL
   * @return Integer
   */

  protected function openNotificationCount($url) {
    $rows = $this->sqlRows('SELECT count(*) AS n FROM ' . $this->tablePrefix . 'co_notifications'
                           . ' WHERE source_url = ? AND status = ?',
                           array((string)$url, NotificationStatusEnum::PendingResolution));

    return (int)$rows[0]['n'];
  }

  /**
   * Attach a login to a CoPerson (R21, AE16; reused by the newcomer wedge's
   * finalize, KTD11): create an OrgIdentity in the CO (or pooled, if the
   * platform pools them) carrying the login as an Active, login = true
   * Identifier of the given type, link it to the CoPerson, and write the
   * CoPersonOrgIdLinked history record, following CoPetition. Nothing is
   * created if the login already belongs to the CoPerson (KTD6).
   *
   * Provisioning is off for every save; the caller provisions the CoPerson
   * after its transaction commits. Runs in its own (possibly nested)
   * transaction.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId            CO ID
   * @param  Integer $coPersonId      CoPerson to attach the login to
   * @param  String  $identifier      Login identifier
   * @param  String  $identifierType  Identifier type (AteSetting login_identifier_type)
   * @param  Integer $actorCoPersonId Acting CoPerson ID, or null
   * @return Array                    'org_identity_id' (Integer) and 'created' (Boolean)
   * @throws RuntimeException If the login belongs to another CoPerson of the CO, or a save fails
   */

  public function attachLogin($coId, $coPersonId, $identifier, $identifierType, $actorCoPersonId) {
    if(!is_string($identifier) || $identifier === '' || empty($coPersonId)) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.snapshot.identifier'));
    }

    $dbc = $this->getDataSource();
    $dbc->begin();

    try {
      $owners = $this->loginCoPersonIds($coId, $identifier);

      if(in_array((int)$coPersonId, $owners, true)) {
        $oid = $this->loginOrgIdentityId($coId, $identifier, $coPersonId);
        $dbc->commit();

        return array('org_identity_id' => $oid, 'created' => false);
      }

      if(!empty($owners)) {
        throw new RuntimeException(_txt('pl.applicationteamenroller.er.login.linked'));
      }

      $OrgIdentity = ClassRegistry::init('OrgIdentity');
      $pooled = ClassRegistry::init('CmpEnrollmentConfiguration')->orgIdentitiesPooled();

      $OrgIdentity->clear();

      if(!$OrgIdentity->save(array('OrgIdentity' => array('co_id' => $pooled ? null : (int)$coId)))) {
        throw new RuntimeException(_txt('er.db.save-a', array('OrgIdentity')));
      }

      $oid = (int)$OrgIdentity->id;

      // Accept the CO's extended identifier types, as core's CO-context
      // controllers do
      $Identifier = ClassRegistry::init('Identifier');
      $typeRule = $Identifier->validate['type']['content']['rule'];
      $Identifier->validate['type']['content']['rule'][1]['coid'] = (int)$coId;

      try {
        $Identifier->clear();

        $saved = $Identifier->save(array('Identifier' => array(
          'identifier'      => $identifier,
          'type'            => $identifierType,
          'login'           => true,
          'status'          => SuspendableStatusEnum::Active,
          'org_identity_id' => $oid
        )), array('provision' => false));
      } finally {
        $Identifier->validate['type']['content']['rule'] = $typeRule;
      }

      if(!$saved) {
        throw new RuntimeException(_txt('er.db.save-a', array('Identifier')));
      }

      $Link = ClassRegistry::init('CoOrgIdentityLink');
      $Link->clear();

      // CoOrgIdentityLink is not provisioner-enabled; core disables it anyway
      if(!$Link->save(array('CoOrgIdentityLink' => array(
        'co_person_id'    => (int)$coPersonId,
        'org_identity_id' => $oid
      )), array('provision' => false))) {
        throw new RuntimeException(_txt('er.db.save-a', array('CoOrgIdentityLink')));
      }

      ClassRegistry::init('HistoryRecord')->record((int)$coPersonId, null, $oid,
        $actorCoPersonId ? (int)$actorCoPersonId : null,
        ActionEnum::CoPersonOrgIdLinked,
        _txt('pl.applicationteamenroller.rs.login.linked', array($identifier, $identifierType)));

      $this->commitOrFail($dbc);
    } catch(Exception $e) {
      $dbc->rollback();
      throw $e;
    }

    return array('org_identity_id' => $oid, 'created' => true);
  }

  /**
   * Whether an accepted request may be approved automatically (R27): its
   * application and every current application that authorizes any of the
   * offered teams have approval off. A retired application counts, since
   * its access group still nests the team (KTD10). An unset setting counts
   * as approval required.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $applicationId AteApplication ID
   * @param  Array   $teamIds       Offered AteResearchTeam IDs
   * @return Boolean
   */

  public function approvalFree($applicationId, $teamIds) {
    $p = $this->tablePrefix;

    $rows = $this->sqlRows('SELECT approval_required FROM ' . $p . 'ate_applications WHERE id = ?',
                           array((int)$applicationId));

    $teamIds = array_map('intval', (array)$teamIds);

    if(!empty($teamIds)) {
      $rows = array_merge($rows, $this->sqlRows(
        'SELECT a.approval_required AS approval_required'
        . ' FROM ' . $p . 'ate_application_teams m'
        . ' JOIN ' . $p . 'ate_applications a ON a.id = m.ate_application_id'
        . ' WHERE m.ate_research_team_id IN (' . implode(', ', $teamIds) . ')'
        . ' AND m.ate_application_team_id IS NULL AND m.deleted IS NOT true'
        . ' AND a.ate_application_id IS NULL AND a.deleted IS NOT true',
        array()
      ));
    }

    if(empty($rows)) {
      return false;
    }

    foreach($rows as $r) {
      if(self::truthy($r['approval_required']) || $r['approval_required'] === null) {
        return false;
      }
    }

    return true;
  }

  /**
   * Approve a request inside the caller's transaction: the shared path of
   * approve() and automatic approval. Moves the request from $fromStatus to
   * approved, then links the login if needed and writes memberships.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId              CO ID
   * @param  Integer $requestId         AteEnrollmentRequest ID
   * @param  String  $fromStatus        Status the request must still have
   * @param  Integer $deciderCoPersonId Deciding CoPerson ID, or null (automatic)
   * @param  String  $decidedByRole     AteDecidedByRoleEnum value
   * @param  String  $comment           Comment, or null
   * @return Array                      'handled', and if handled 'co_person_id', 'teams', 'linked_org_identity_id', 'changed'
   * @throws RuntimeException If there is no one to approve for, or a write fails
   */

  protected function applyApproval($coId, $requestId, $fromStatus, $deciderCoPersonId, $decidedByRole, $comment) {
    $now = date('Y-m-d H:i:s');

    $claimed = $this->conditionalUpdate($this, array(
      'status'               => AteRequestStatusEnum::Approved,
      'decided_by_role'      => $decidedByRole,
      'decider_co_person_id' => $deciderCoPersonId,
      'decided_at'           => $now,
      'comment'              => $this->commentValue($comment),
      'draft_choice'         => null,
      'modified'             => $now
    ), array(
      'id'     => (int)$requestId,
      'status' => $fromStatus
    ));

    if(!$claimed) {
      return array('handled' => false);
    }

    $req = $this->requestWithInvitation($coId, $requestId);

    if(!$req) {
      throw new RuntimeException(_txt('er.notfound', array('AteEnrollmentRequest', $requestId)));
    }

    $linkRequired = ($req['pending_reason'] === AtePendingReasonEnum::LinkRequired);
    $researcher = $linkRequired ? $req['link_target_co_person_id'] : $req['invitee_co_person_id'];

    if(empty($researcher)) {
      throw new RuntimeException(_txt($linkRequired ? 'pl.applicationteamenroller.er.approve.target'
                                                    : 'pl.applicationteamenroller.er.approve.person'));
    }

    $researcher = (int)$researcher;
    $teams = $this->teamsForApproval($coId, $requestId, (int)$req['ate_application_id']);
    $usable = array_filter($teams, function($t) { return $t['usable']; });
    $linkedOid = null;
    $changed = false;

    if($linkRequired && !empty($usable)) {
      $settings = ClassRegistry::init('ApplicationTeamEnroller.AteSetting')->getOrCreateForCo($coId);
      $type = !empty($settings['AteSetting']['login_identifier_type'])
              ? $settings['AteSetting']['login_identifier_type']
              : AteSetting::DefaultLoginIdentifierType;

      $link = $this->attachLogin($coId, $researcher, (string)$req['responder_identifier'], $type,
                                 $deciderCoPersonId);
      $linkedOid = $link['org_identity_id'];
      $changed = $changed || $link['created'];

      // The researcher is now known (R7)
      $this->conditionalUpdate($this->AteInvitation, array(
        'invitee_co_person_id' => $researcher,
        'modified'             => $now
      ), array(
        'id'                   => (int)$req['ate_invitation_id'],
        'invitee_co_person_id' => null
      ));
    }

    $outcomes = array();

    foreach($teams as $t) {
      if($t['usable']) {
        $outcome = $this->writeMembership($t['co_group_id'], $researcher, $deciderCoPersonId);
      } else {
        $outcome = AteTeamOutcomeEnum::Skipped;
      }

      $changed = $changed || ($outcome === AteTeamOutcomeEnum::Added);

      $ok = $this->AteEnrollmentRequestTeam->updateAll(
        array('outcome' => $this->getDataSource()->value($outcome),
              'modified' => $this->getDataSource()->value($now)),
        array('id' => $t['id'])
      );

      if(!$ok) {
        throw new RuntimeException(_txt('er.db.save-a', array('AteEnrollmentRequestTeam')));
      }

      $outcomes[$t['ate_research_team_id']] = $outcome;
    }

    return array(
      'handled'                => true,
      'co_person_id'           => $researcher,
      'teams'                  => $outcomes,
      'linked_org_identity_id' => $linkedOid,
      'changed'                => $changed
    );
  }

  /**
   * Make a CoPerson a direct member of a team's CoGroup (KTD9), never through
   * CoGroupMember::setMembership():
   *   1. an active direct row (no nesting, member, within its validity
   *      window) means already_present;
   *   2. otherwise an inactive direct row is reactivated: member true, a past
   *      valid_through or future valid_from cleared, owner kept;
   *   3. otherwise a new direct row is inserted (member true, owner false).
   * Every save is checked, provisioning is off, and the CoGroupMemberAdded
   * history record core writes is written for 2 and 3.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coGroupId       CoGroup ID
   * @param  Integer $coPersonId      CoPerson ID
   * @param  Integer $actorCoPersonId Acting CoPerson ID, or null
   * @return String                   AteTeamOutcomeEnum Added or AlreadyPresent
   * @throws RuntimeException If a save fails
   */

  protected function writeMembership($coGroupId, $coPersonId, $actorCoPersonId) {
    $p = $this->tablePrefix;
    $now = time();

    $rows = $this->sqlRows(
      'SELECT id, member, owner, valid_from, valid_through FROM ' . $p . 'co_group_members'
      . ' WHERE co_group_id = ? AND co_person_id = ? AND co_group_nesting_id IS NULL'
      . ' AND co_group_member_id IS NULL AND deleted IS NOT true ORDER BY id FOR UPDATE',
      array((int)$coGroupId, (int)$coPersonId)
    );

    foreach($rows as $r) {
      if(self::truthy($r['member'])
         && (empty($r['valid_from']) || strtotime($r['valid_from']) <= $now)
         && (empty($r['valid_through']) || strtotime($r['valid_through']) > $now)) {
        return AteTeamOutcomeEnum::AlreadyPresent;
      }
    }

    $CoGroupMember = ClassRegistry::init('CoGroupMember');
    $CoGroupMember->clear();

    $data = array(
      'co_group_id'  => (int)$coGroupId,
      'co_person_id' => (int)$coPersonId,
      'member'       => true,
      'owner'        => false
    );

    if(!empty($rows)) {
      $r = $rows[0];
      $data['id'] = (int)$r['id'];
      $data['owner'] = self::truthy($r['owner']);
      $data['valid_from'] = (!empty($r['valid_from']) && strtotime($r['valid_from']) <= $now)
                            ? $r['valid_from'] : null;
      $data['valid_through'] = (!empty($r['valid_through']) && strtotime($r['valid_through']) > $now)
                               ? $r['valid_through'] : null;
    }

    if(!$CoGroupMember->save(array('CoGroupMember' => $data), array('provision' => false))) {
      throw new RuntimeException(_txt('er.db.save-a', array('CoGroupMember')));
    }

    $groupName = $CoGroupMember->CoGroup->field('name', array('CoGroup.id' => $coGroupId));

    ClassRegistry::init('HistoryRecord')->record((int)$coPersonId, null, null,
      $actorCoPersonId ? (int)$actorCoPersonId : null,
      ActionEnum::CoGroupMemberAdded,
      _txt('rs.grm.added', array($groupName, $coGroupId, _txt('fd.yes'),
                                 $data['owner'] ? _txt('fd.yes') : _txt('fd.no'))),
      (int)$coGroupId);

    return AteTeamOutcomeEnum::Added;
  }

  /**
   * Provision a CoPerson after an approval committed (the researcher's
   * person record and every group they belong to, including access groups
   * reached through nesting). A provisioner failure is logged, never thrown:
   * the approval stands.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coPersonId CoPerson ID
   */

  protected function provisionCoPerson($coPersonId) {
    try {
      ClassRegistry::init('CoPerson')->requestToProvision(null, (int)$coPersonId, null,
                                                          ProvisioningActionEnum::CoPersonPetitionProvisioned);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not provision CoPerson ' . $coPersonId
                 . ' after approval: ' . $e->getMessage(), LOG_ERROR);
    }
  }

  /**
   * Provision a CoPerson, but only once no transaction is open: if the
   * caller wrapped this model's call in its own transaction, provisioning
   * waits for the caller (the result says 'provisioned' => false).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coPersonId CoPerson ID
   * @return Boolean             True if provisioning was requested
   */

  protected function provisionAfterCommit($coPersonId) {
    if($this->getDataSource()->inTransaction()) {
      return false;
    }

    $this->provisionCoPerson($coPersonId);
    return true;
  }

  /**
   * Turn off provisioning for CoGroupMember saves, including the nested
   * access-group rows core's CoGroupMember::afterSave() writes, until the
   * matching resumeMembershipProvisioning().
   *
   * @since  COmanage Registry v4.6.0
   */

  protected function suspendMembershipProvisioning() {
    $CoGroupMember = ClassRegistry::init('CoGroupMember');

    if($this->provisioningSuspended++ === 0) {
      $this->provisioningWasEnabled = $CoGroupMember->Behaviors->enabled('Provisioner');

      if($this->provisioningWasEnabled) {
        $CoGroupMember->Behaviors->disable('Provisioner');
      }
    }
  }

  /**
   * Undo suspendMembershipProvisioning().
   *
   * @since  COmanage Registry v4.6.0
   */

  protected function resumeMembershipProvisioning() {
    if($this->provisioningSuspended > 0 && --$this->provisioningSuspended === 0
       && $this->provisioningWasEnabled) {
      ClassRegistry::init('CoGroupMember')->Behaviors->enable('Provisioner');
    }
  }

  /**
   * Check a public decision (approve, deny): a known role other than
   * automatic, a decider, and R39 (not the responder or the link target).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId              CO ID
   * @param  Integer $requestId         AteEnrollmentRequest ID
   * @param  Integer $deciderCoPersonId Deciding CoPerson ID
   * @param  String  $decidedByRole     AteDecidedByRoleEnum value
   * @return Boolean                    False if the request is not in the CO
   * @throws InvalidArgumentException If the decider or role is not acceptable
   */

  protected function checkDecision($coId, $requestId, $deciderCoPersonId, $decidedByRole) {
    if(!in_array($decidedByRole, AteDecidedByRoleEnum::$values, true)
       || $decidedByRole === AteDecidedByRoleEnum::Automatic
       || empty($deciderCoPersonId)) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.decision.role'));
    }

    $req = $this->requestWithInvitation($coId, $requestId);

    if(!$req) {
      return false;
    }

    if((int)$deciderCoPersonId === (int)$req['invitee_co_person_id']
       || (int)$deciderCoPersonId === (int)$req['link_target_co_person_id']) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.decision.self'));
    }

    return true;
  }

  /**
   * What a decision returns, for U10's notifications and emails.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId      CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @param  Boolean $handled   Whether this call made the change
   * @return Array              'handled', 'request_id', 'invitation_id', 'application_id',
   *                            'co_person_id' (the researcher, or null), 'status' (current),
   *                            'pending_reason', 'decided_by_role', 'teams' (outcome by
   *                            AteResearchTeam ID), 'linked_org_identity_id' (approve only),
   *                            'provisioned' (True if the researcher was provisioned after the
   *                            commit; false if nothing changed or the caller's own transaction
   *                            is still open, in which case the caller provisions)
   */

  protected function decisionResult($coId, $requestId, $handled) {
    $req = $this->requestWithInvitation($coId, $requestId);

    $ret = array(
      'handled'                => (bool)$handled,
      'request_id'             => (int)$requestId,
      'invitation_id'          => null,
      'application_id'         => null,
      'co_person_id'           => null,
      'status'                 => null,
      'pending_reason'         => null,
      'decided_by_role'        => null,
      'teams'                  => array(),
      'linked_org_identity_id' => null,
      'provisioned'            => false
    );

    if(!$req) {
      return $ret;
    }

    $researcher = ($req['pending_reason'] === AtePendingReasonEnum::LinkRequired)
                  ? $req['link_target_co_person_id']
                  : $req['invitee_co_person_id'];

    return array(
      'invitation_id'   => (int)$req['ate_invitation_id'],
      'application_id'  => (int)$req['ate_application_id'],
      'co_person_id'    => $researcher ? (int)$researcher : null,
      'status'          => $req['status'],
      'pending_reason'  => $req['pending_reason'],
      'decided_by_role' => $req['decided_by_role'],
      'teams'           => $this->teamOutcomes($requestId)
    ) + $ret;
  }

  /**
   * What commitResponse() returns, for U8's confirmation page and U10's
   * notifications (R19, R35, R36).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId         CO ID
   * @param  Integer $invitationId AteInvitation ID
   * @param  Boolean $handled      Whether this call committed the response
   * @param  Array   $eval         evaluateIdentity() result, if computed
   * @return Array                 'handled', 'invitation_id', 'status' (current invitation status),
   *                               'identity' (evaluateIdentity() result or null),
   *                               'responder_co_person_id', 'link_target_co_person_id',
   *                               'requests': keyed by AteEnrollmentRequest ID, each 'application_id',
   *                               'status', 'pending_reason', 'decided_by_role', 'teams',
   *                               'provisioned' (see decisionResult())
   */

  protected function responseResult($coId, $invitationId, $handled, $eval = null) {
    $inv = $this->invitationRow($coId, $invitationId);
    $requests = array();

    if($inv) {
      foreach($this->sqlRows('SELECT id, ate_application_id, status, pending_reason, decided_by_role FROM '
                             . $this->tablePrefix . 'ate_enrollment_requests WHERE ate_invitation_id = ?'
                             . ' ORDER BY id', array((int)$invitationId)) as $r) {
        $requests[(int)$r['id']] = array(
          'application_id'  => (int)$r['ate_application_id'],
          'status'          => $r['status'],
          'pending_reason'  => $r['pending_reason'],
          'decided_by_role' => $r['decided_by_role'],
          'teams'           => $this->teamOutcomes($r['id'])
        );
      }
    }

    return array(
      'handled'                  => (bool)$handled,
      'invitation_id'            => (int)$invitationId,
      'status'                   => $inv ? $inv['status'] : null,
      'identity'                 => $eval,
      'responder_co_person_id'   => ($inv && $inv['invitee_co_person_id']) ? (int)$inv['invitee_co_person_id'] : null,
      'link_target_co_person_id' => ($inv && $inv['link_target_co_person_id'])
                                    ? (int)$inv['link_target_co_person_id'] : null,
      'requests'                 => $requests,
      'provisioned'              => false
    );
  }

  /**
   * The offered teams of a request with whether approval may still use each
   * (R29): the team is a current research team, its CoGroup is a current
   * CoGroup of the CO, and a current mapping row links it to the current
   * application.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId          CO ID
   * @param  Integer $requestId     AteEnrollmentRequest ID
   * @param  Integer $applicationId AteApplication ID
   * @return Array                  Each 'id' (request team row), 'ate_research_team_id', 'co_group_id', 'usable'
   */

  protected function teamsForApproval($coId, $requestId, $applicationId) {
    $p = $this->tablePrefix;

    $rows = $this->sqlRows(
      'SELECT ert.id AS id, ert.ate_research_team_id AS team_id, rt.co_group_id AS co_group_id,'
      . ' CASE WHEN rt.id IS NOT NULL AND rt.ate_research_team_id IS NULL AND rt.deleted IS NOT true'
      . '   AND g.id IS NOT NULL AND g.co_group_id IS NULL AND g.deleted IS NOT true AND g.co_id = ?'
      . '   AND EXISTS (SELECT 1 FROM ' . $p . 'ate_application_teams m'
      . '     JOIN ' . $p . 'ate_applications a ON a.id = m.ate_application_id'
      . '     WHERE m.ate_application_id = ? AND m.ate_research_team_id = rt.id'
      . '     AND m.ate_application_team_id IS NULL AND m.deleted IS NOT true'
      . '     AND a.ate_application_id IS NULL AND a.deleted IS NOT true)'
      . ' THEN 1 ELSE 0 END AS usable'
      . ' FROM ' . $p . 'ate_enrollment_request_teams ert'
      . ' LEFT JOIN ' . $p . 'ate_research_teams rt ON rt.id = ert.ate_research_team_id'
      . ' LEFT JOIN ' . $p . 'co_groups g ON g.id = rt.co_group_id'
      . ' WHERE ert.ate_enrollment_request_id = ? ORDER BY ert.id',
      array((int)$coId, (int)$applicationId, (int)$requestId)
    );

    $ret = array();

    foreach($rows as $r) {
      $ret[] = array(
        'id'                   => (int)$r['id'],
        'ate_research_team_id' => (int)$r['team_id'],
        'co_group_id'          => (int)$r['co_group_id'],
        'usable'               => ((int)$r['usable'] === 1)
      );
    }

    return $ret;
  }

  /**
   * The team outcomes of a request, keyed by AteResearchTeam ID.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Array
   */

  protected function teamOutcomes($requestId) {
    $ret = array();

    foreach($this->sqlRows('SELECT ate_research_team_id, outcome FROM ' . $this->tablePrefix
                           . 'ate_enrollment_request_teams WHERE ate_enrollment_request_id = ? ORDER BY id',
                           array((int)$requestId)) as $r) {
      $ret[(int)$r['ate_research_team_id']] = $r['outcome'];
    }

    return $ret;
  }

  /**
   * The offered AteResearchTeam IDs of a request.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Array
   */

  protected function offeredTeamIds($requestId) {
    return array_keys($this->teamOutcomes($requestId));
  }

  /**
   * Move an offered request to a response status (declined or pending),
   * clearing any draft; it must still be offered.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @param  String  $status    New AteRequestStatusEnum value
   * @param  String  $reason    AtePendingReasonEnum value, or null
   * @param  String  $now       Timestamp
   * @throws RuntimeException If the request is no longer offered or the update fails
   */

  protected function moveOffered($requestId, $status, $reason, $now) {
    $ok = $this->conditionalUpdate($this, array(
      'status'         => $status,
      'pending_reason' => $reason,
      'draft_choice'   => null,
      'modified'       => $now
    ), array(
      'id'     => (int)$requestId,
      'status' => AteRequestStatusEnum::Offered
    ));

    if(!$ok) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.invitation.handled'));
    }
  }

  /**
   * A request joined with its invitation's responder fields, if the
   * invitation belongs to the CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId      CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Array              Flat row, or null
   */

  protected function requestWithInvitation($coId, $requestId) {
    $p = $this->tablePrefix;

    $rows = $this->sqlRows(
      'SELECT r.id AS id, r.ate_invitation_id AS ate_invitation_id, r.ate_application_id AS ate_application_id,'
      . ' r.status AS status, r.pending_reason AS pending_reason, r.decided_by_role AS decided_by_role,'
      . ' i.invitee_co_person_id AS invitee_co_person_id,'
      . ' i.link_target_co_person_id AS link_target_co_person_id,'
      . ' i.responder_identifier AS responder_identifier'
      . ' FROM ' . $p . 'ate_enrollment_requests r'
      . ' JOIN ' . $p . 'ate_invitations i ON i.id = r.ate_invitation_id'
      . ' WHERE r.id = ? AND i.co_id = ?',
      array((int)$requestId, (int)$coId)
    );

    return empty($rows) ? null : $rows[0];
  }

  /**
   * An invitation row, if it belongs to the CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId         CO ID
   * @param  Integer $invitationId AteInvitation ID
   * @return Array                 Flat row, or null
   */

  protected function invitationRow($coId, $invitationId) {
    $rows = $this->sqlRows('SELECT id, invited_email, status, invitee_co_person_id, link_target_co_person_id FROM '
                           . $this->tablePrefix . 'ate_invitations WHERE id = ? AND co_id = ?',
                           array((int)$invitationId, (int)$coId));

    return empty($rows) ? null : $rows[0];
  }

  /**
   * The current CoPeople of a CO a login belongs to (see
   * existingMemberCoPersonId()).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId       CO ID
   * @param  String  $identifier Login identifier
   * @return Array               CoPerson IDs, ascending
   */

  protected function loginCoPersonIds($coId, $identifier) {
    $rows = $this->sqlRows(
      'SELECT DISTINCT cp.id AS id' . $this->loginJoinSql() . ' ORDER BY cp.id',
      array((string)$identifier, SuspendableStatusEnum::Active, (int)$coId)
    );

    return array_map('intval', Hash::extract($rows, '{n}.id'));
  }

  /**
   * The OrgIdentity carrying a login that is linked to a CoPerson.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId       CO ID
   * @param  String  $identifier Login identifier
   * @param  Integer $coPersonId CoPerson ID
   * @return Integer             OrgIdentity ID
   */

  protected function loginOrgIdentityId($coId, $identifier, $coPersonId) {
    $rows = $this->sqlRows(
      'SELECT oi.id AS id' . $this->loginJoinSql() . ' AND cp.id = ? ORDER BY oi.id',
      array((string)$identifier, SuspendableStatusEnum::Active, (int)$coId, (int)$coPersonId)
    );

    return (int)$rows[0]['id'];
  }

  /**
   * FROM and WHERE for a login's current CoPeople in a CO (KTD6). Parameters:
   * identifier, Active status, CO ID.
   *
   * @since  COmanage Registry v4.6.0
   * @return String
   */

  protected function loginJoinSql() {
    $p = $this->tablePrefix;

    return ' FROM ' . $p . 'identifiers i'
      . ' JOIN ' . $p . 'org_identities oi ON oi.id = i.org_identity_id'
      . ' JOIN ' . $p . 'co_org_identity_links l ON l.org_identity_id = oi.id'
      . ' JOIN ' . $p . 'co_people cp ON cp.id = l.co_person_id'
      . ' WHERE i.identifier = ? AND i.login = true AND i.status = ?'
      . ' AND i.identifier_id IS NULL AND i.deleted IS NOT true'
      . ' AND oi.org_identity_id IS NULL AND oi.deleted IS NOT true'
      . ' AND l.co_org_identity_link_id IS NULL AND l.deleted IS NOT true'
      . ' AND cp.co_id = ? AND cp.co_person_id IS NULL AND cp.deleted IS NOT true';
  }

  /**
   * Whether a CoPerson is a current CoPerson of the CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId       CO ID
   * @param  Integer $coPersonId CoPerson ID
   * @return Boolean
   */

  protected function isCurrentCoPerson($coId, $coPersonId) {
    return !empty($this->sqlRows('SELECT id FROM ' . $this->tablePrefix . 'co_people WHERE id = ? AND co_id = ?'
                                 . ' AND co_person_id IS NULL AND deleted IS NOT true',
                                 array((int)$coPersonId, (int)$coId)));
  }

  /**
   * One conditional UPDATE (KTD8): set $fields on the rows of $Model that
   * match $conditions, which must name only $Model's own columns so Cake
   * issues a single UPDATE rather than a SELECT first.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Model   $Model      Model to update
   * @param  Array   $fields     Column => PHP value (null, Boolean, Integer, or String)
   * @param  Array   $conditions Column => value
   * @return Boolean             True if exactly one row changed
   * @throws RuntimeException If the update fails
   */

  protected function conditionalUpdate($Model, $fields, $conditions) {
    $dbc = $Model->getDataSource();
    $set = array();

    foreach($fields as $col => $v) {
      if($v === null) {
        $set[$col] = 'NULL';
      } elseif(is_bool($v)) {
        $set[$col] = $dbc->value($v, 'boolean');
      } elseif(is_int($v)) {
        $set[$col] = $v;
      } else {
        $set[$col] = $dbc->value((string)$v);
      }
    }

    if(!$Model->updateAll($set, $conditions)) {
      throw new RuntimeException(_txt('er.db.save-a', array($Model->alias)));
    }

    return $Model->getAffectedRows() === 1;
  }

  /**
   * Commit, first checking that the transaction is still open: if code
   * below rolled it back, writes since then were not transactional, and the
   * caller must hear about it.
   *
   * @since  COmanage Registry v4.6.0
   * @param  DboSource $dbc Datasource
   * @throws RuntimeException If no transaction is open
   */

  protected function commitOrFail($dbc) {
    if(!$dbc->inTransaction()) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.transaction'));
    }

    $dbc->commit();
  }

  /**
   * A decision comment for storage: trimmed, or null if empty.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $comment Comment
   * @return String          Comment, or null
   */

  protected function commentValue($comment) {
    $comment = is_string($comment) ? trim($comment) : '';

    return ($comment === '') ? null : $comment;
  }

  /**
   * Run a parameterized SELECT without the query cache and return flat rows.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $sql    SQL with ? placeholders
   * @param  Array  $params Parameters
   * @return Array          Rows as column => value
   * @throws RuntimeException If the query fails
   */

  protected function sqlRows($sql, $params) {
    $result = $this->getDataSource()->fetchAll($sql, $params, array('cache' => false));

    if($result === false) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.query'));
    }

    $rows = array();

    foreach((array)$result as $row) {
      $flat = array();

      foreach($row as $part) {
        $flat = array_merge($flat, (array)$part);
      }

      $rows[] = $flat;
    }

    return $rows;
  }

  /**
   * Whether a database boolean is true, whatever form the driver returns.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Mixed $v Value
   * @return Boolean
   */

  protected static function truthy($v) {
    return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
  }
}
