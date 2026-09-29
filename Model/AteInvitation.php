<?php
/**
 * COmanage Registry Application Team Enroller Invitation Model
 *
 * One email invitation covering one or more applications (R7, R10). An audit
 * record: no changelog, nullable foreign keys, and never cascade-deleted.
 * The link token is stored only as its SHA-256 hash (KTD4).
 *
 * Sending creates the invitation, its requests, and their offered teams and
 * emails the link in one transaction (KTD13). Revoking an invitation and
 * withdrawing a request are conditional transitions (KTD8).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');
App::uses('CakeEmail', 'Network/Email');
App::uses('Validation', 'Utility');

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

  // The CakeEmail configuration used to send invitations: a config name from
  // app/Config/email.php, or a config array. Tests point it at a recording
  // transport.
  public $emailConfig = 'default';

  /**
   * Create an invitation and email its link, in one transaction (F1, R10,
   * R14, R15, KTD4, KTD13).
   *
   * Every application must be in $permittedApplicationIds (the caller's
   * AteAuthzComponent::invitableApplicationIds()), belong to the CO, and be
   * active; every team must be one of that application's offerable teams
   * right now (R11, R12). The invitation expires after the CO's configured
   * lifetime. A send failure rolls everything back, so no invitation exists
   * that its researcher cannot reach.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId                    CO ID
   * @param  Integer $inviterCoPersonId       Inviting admin's CoPerson ID
   * @param  String  $invitedEmail            Address to invite
   * @param  Array   $selections              AteResearchTeam IDs, keyed by AteApplication ID
   * @param  Array   $permittedApplicationIds AteApplication IDs the inviter may invite for
   * @return Integer                          The new AteInvitation ID
   * @throws InvalidArgumentException If the address, an application, or a team is not acceptable
   * @throws RuntimeException         If a save or the email fails
   */

  public function createAndSend($coId, $inviterCoPersonId, $invitedEmail, $selections, $permittedApplicationIds) {
    $invitedEmail = trim((string)$invitedEmail);

    if(!Validation::email($invitedEmail)) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.invitation.email'));
    }

    if(empty($inviterCoPersonId)) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.invitation.inviter'));
    }

    if(empty($selections) || !is_array($selections)) {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.invitation.none'));
    }

    $Setting = ClassRegistry::init('ApplicationTeamEnroller.AteSetting');
    $settings = $Setting->getOrCreateForCo($coId);

    $dbc = $this->getDataSource();
    $dbc->begin();

    try {
      // Re-check every application and team inside the transaction, against
      // the inviter's permissions and the current mapping (R11, R12).
      $permitted = array_map('intval', (array)$permittedApplicationIds);
      $offer = array();

      foreach($selections as $appId => $teamIds) {
        $appId = (int)$appId;
        $options = $this->composeOptions($coId, array($appId));

        if(!in_array($appId, $permitted, true) || empty($options[$appId])) {
          throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.invitation.application'));
        }

        $teamIds = array_unique(array_map('intval', (array)$teamIds));

        if(empty($teamIds)) {
          throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.invitation.teams',
                                                  array($options[$appId]['name'])));
        }

        foreach($teamIds as $teamId) {
          if(!isset($options[$appId]['teams'][$teamId])) {
            throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.invitation.team',
                                                    array($options[$appId]['name'])));
          }
        }

        $offer[$appId] = array(
          'name'  => $options[$appId]['name'],
          'teams' => array_intersect_key($options[$appId]['teams'], array_flip($teamIds))
        );
      }

      $token = self::generateToken();
      $expires = date('Y-m-d H:i:s',
                      time() + 86400 * (int)$settings['AteSetting']['invitation_lifetime_days']);

      $this->clear();

      if(!$this->save(array(
        'co_id'                => $coId,
        'invited_email'        => $invitedEmail,
        'inviter_co_person_id' => $inviterCoPersonId,
        'status'               => AteInvitationStatusEnum::Sent,
        'expires'              => $expires,
        'token_hash'           => self::hashToken($token),
        'mismatch'             => false,
        'expiry_notified'      => false
      ))) {
        throw new RuntimeException(_txt('er.db.save-a', array('AteInvitation')));
      }

      $invitationId = (int)$this->id;
      $Request = $this->AteEnrollmentRequest;
      $RequestTeam = $Request->AteEnrollmentRequestTeam;

      foreach($offer as $appId => $o) {
        $Request->clear();

        if(!$Request->save(array(
          'ate_invitation_id'  => $invitationId,
          'ate_application_id' => $appId,
          'status'             => AteRequestStatusEnum::Offered
        ))) {
          throw new RuntimeException(_txt('er.db.save-a', array('AteEnrollmentRequest')));
        }

        $requestId = (int)$Request->id;

        foreach(array_keys($o['teams']) as $teamId) {
          $RequestTeam->clear();

          if(!$RequestTeam->save(array(
            'ate_enrollment_request_id' => $requestId,
            'ate_research_team_id'      => $teamId
          ))) {
            throw new RuntimeException(_txt('er.db.save-a', array('AteEnrollmentRequestTeam')));
          }
        }
      }

      $this->sendInvitationEmail($coId, $inviterCoPersonId, $invitedEmail, $offer, $expires, $token,
                                 $settings['AteSetting']);

      $dbc->commit();
    } catch(Exception $e) {
      $dbc->rollback();
      throw $e;
    }

    return $invitationId;
  }

  /**
   * The compose form's choices (AE1, R11, R12): each application of $appIds
   * that belongs to the CO and is active, with its offerable teams.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId   CO ID
   * @param  Array   $appIds AteApplication IDs the user may invite for
   * @return Array           Keyed by AteApplication ID, each 'name' and 'teams' (team names keyed by AteResearchTeam ID), by application name
   */

  public function composeOptions($coId, $appIds) {
    $appIds = array_map('intval', (array)$appIds);

    if(empty($appIds)) {
      return array();
    }

    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived applications here.
    $Application = ClassRegistry::init('ApplicationTeamEnroller.AteApplication');

    $args = array();
    $args['conditions']['AteApplication.id'] = $appIds;
    $args['conditions']['AteApplication.co_id'] = $coId;
    $args['conditions']['AteApplication.status'] = AteConfigStatusEnum::Active;
    $args['conditions']['AteApplication.ate_application_id'] = null;
    $args['conditions'][] = 'AteApplication.deleted IS NOT true';
    $args['order'] = array('AteApplication.name' => 'asc', 'AteApplication.id' => 'asc');
    $args['contain'] = false;

    $ret = array();

    foreach($Application->find('all', $args) as $a) {
      $ret[ (int)$a['AteApplication']['id'] ] = array(
        'name'  => $a['AteApplication']['name'],
        'teams' => $this->offerableTeams($a['AteApplication']['id'])
      );
    }

    return $ret;
  }

  /**
   * The research teams an invitation may offer for an application (R12):
   * active teams with a current mapping row to the application, whose group
   * is a current, undeleted CoGroup. Never a plain CoGroup.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $applicationId AteApplication ID
   * @return Array                  Team names, keyed by AteResearchTeam ID, by name
   */

  public function offerableTeams($applicationId) {
    $Map = ClassRegistry::init('ApplicationTeamEnroller.AteApplicationTeam');

    // Changelog limits this search to current mapping rows
    $args = array();
    $args['conditions']['AteApplicationTeam.ate_application_id'] = $applicationId;
    $args['fields'] = array('AteApplicationTeam.id', 'AteApplicationTeam.ate_research_team_id');
    $args['contain'] = false;

    $teamIds = array_map('intval', array_values($Map->find('list', $args)));

    if(empty($teamIds)) {
      return array();
    }

    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived teams and groups here.
    $args = array();
    $args['conditions']['AteResearchTeam.id'] = $teamIds;
    $args['conditions']['AteResearchTeam.status'] = AteConfigStatusEnum::Active;
    $args['conditions']['AteResearchTeam.ate_research_team_id'] = null;
    $args['conditions'][] = 'AteResearchTeam.deleted IS NOT true';
    $args['conditions']['CoGroup.co_group_id'] = null;
    $args['conditions'][] = 'CoGroup.deleted IS NOT true';
    $args['order'] = array('AteResearchTeam.name' => 'asc', 'AteResearchTeam.id' => 'asc');
    $args['contain'] = array('CoGroup');

    $ret = array();

    foreach($Map->AteResearchTeam->find('all', $args) as $t) {
      $ret[ (int)$t['AteResearchTeam']['id'] ] = !empty($t['AteResearchTeam']['name'])
                                                 ? $t['AteResearchTeam']['name']
                                                 : $t['CoGroup']['name'];
    }

    return $ret;
  }

  /**
   * Revoke a sent invitation (R18, AE12, KTD8): the invitation becomes
   * revoked, recording who revoked it and when, and its offered requests
   * become revoked. Only an invitation of the CO that is still sent moves,
   * so a second revoke, or one racing a response or expiry, loses.
   *
   * The caller checks AteAuthzComponent::mayRevokeInvitation() first.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId            CO ID
   * @param  Integer $invitationId    AteInvitation ID
   * @param  Integer $actorCoPersonId Revoking CoPerson ID
   * @return Boolean                  True if this call revoked it, false if it was already handled
   * @throws RuntimeException         If an update fails
   */

  public function revoke($coId, $invitationId, $actorCoPersonId) {
    $dbc = $this->getDataSource();
    $now = date('Y-m-d H:i:s');

    $dbc->begin();

    try {
      $ok = $this->updateAll(
        array(
          'AteInvitation.status'                  => $dbc->value(AteInvitationStatusEnum::Revoked),
          'AteInvitation.revoked_by_co_person_id' => (int)$actorCoPersonId,
          'AteInvitation.revoked_at'              => $dbc->value($now),
          'AteInvitation.modified'                => $dbc->value($now)
        ),
        array(
          'AteInvitation.id'     => $invitationId,
          'AteInvitation.co_id'  => $coId,
          'AteInvitation.status' => AteInvitationStatusEnum::Sent
        )
      );

      if(!$ok) {
        throw new RuntimeException(_txt('er.db.save-a', array('AteInvitation')));
      }

      if($this->getAffectedRows() !== 1) {
        $dbc->rollback();
        return false;
      }

      $Request = $this->AteEnrollmentRequest;

      $ok = $Request->updateAll(
        array(
          'AteEnrollmentRequest.status'   => $dbc->value(AteRequestStatusEnum::Revoked),
          'AteEnrollmentRequest.modified' => $dbc->value($now)
        ),
        array(
          'AteEnrollmentRequest.ate_invitation_id' => $invitationId,
          'AteEnrollmentRequest.status'            => AteRequestStatusEnum::Offered
        )
      );

      if(!$ok) {
        throw new RuntimeException(_txt('er.db.save-a', array('AteEnrollmentRequest')));
      }

      $dbc->commit();
    } catch(Exception $e) {
      $dbc->rollback();
      throw $e;
    }

    return true;
  }

  /**
   * Withdraw one pending_decision request (R18, AE17, KTD8): it becomes
   * revoked and records who withdrew it. Other requests on the invitation
   * are unaffected (R30). Only a request of the CO still pending a decision
   * moves, so a second withdraw, or one racing a decision, loses.
   *
   * The caller checks AteAuthzComponent::mayWithdrawRequest() first.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId            CO ID
   * @param  Integer $requestId       AteEnrollmentRequest ID
   * @param  Integer $actorCoPersonId Withdrawing CoPerson ID
   * @return Boolean                  True if this call withdrew it, false if it was already handled
   * @throws RuntimeException         If the update fails
   */

  public function withdrawRequest($coId, $requestId, $actorCoPersonId) {
    $dbc = $this->getDataSource();
    $Request = $this->AteEnrollmentRequest;
    $now = date('Y-m-d H:i:s');

    // Only a request whose invitation belongs to the CO. A request never
    // moves between invitations, so checking this before the conditional
    // update is enough.
    $args = array();
    $args['conditions']['AteEnrollmentRequest.id'] = $requestId;
    $args['conditions']['AteInvitation.co_id'] = $coId;
    $args['contain'] = array('AteInvitation');

    if(!$Request->find('count', $args)) {
      return false;
    }

    $ok = $Request->updateAll(
      array(
        'AteEnrollmentRequest.status'                    => $dbc->value(AteRequestStatusEnum::Revoked),
        'AteEnrollmentRequest.withdrawn_by_co_person_id' => (int)$actorCoPersonId,
        'AteEnrollmentRequest.modified'                  => $dbc->value($now)
      ),
      array(
        'AteEnrollmentRequest.id'     => $requestId,
        'AteEnrollmentRequest.status' => AteRequestStatusEnum::PendingDecision
      )
    );

    if(!$ok) {
      throw new RuntimeException(_txt('er.db.save-a', array('AteEnrollmentRequest')));
    }

    return $Request->getAffectedRows() === 1;
  }

  /**
   * Generate a new invitation token: 32 random bytes, hex encoded (KTD4).
   *
   * @since  COmanage Registry v4.6.0
   * @return String 64 hexadecimal characters
   */

  public static function generateToken() {
    return bin2hex(random_bytes(32));
  }

  /**
   * The value stored for a token, and looked up by: its SHA-256 (KTD4).
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $token Token as carried in the link
   * @return String        64 hexadecimal characters
   */

  public static function hashToken($token) {
    return hash('sha256', (string)$token);
  }

  /**
   * The absolute URL of the response page for a token: the plugin's
   * ate_responses landing action with the raw token as its first argument
   * (U8). The only place the link is built.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $token Token as carried in the link
   * @return String        Absolute URL
   */

  public function responseUrl($token) {
    return Router::url(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_responses',
      'action'     => 'landing',
      $token
    ), true);
  }

  /**
   * Email the invitation link (R15, KTD13), following CoInvite::send(): the
   * CO's subject and body with (@...) substitutions, sent with CakeEmail.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId              CO ID
   * @param  Integer $inviterCoPersonId Inviting admin's CoPerson ID
   * @param  String  $invitedEmail      Address to send to
   * @param  Array   $offer             Keyed by AteApplication ID, each 'name' and 'teams'
   * @param  String  $expires           Expiry timestamp (UTC)
   * @param  String  $token             Raw token
   * @param  Array   $settings          AteSetting row
   * @throws RuntimeException If the email cannot be sent
   */

  protected function sendInvitationEmail($coId, $inviterCoPersonId, $invitedEmail, $offer, $expires, $token, $settings) {
    $lines = array();

    foreach($offer as $o) {
      $lines[] = _txt('pl.applicationteamenroller.invitation.email.application',
                      array($o['name'], implode(', ', $o['teams'])));
    }

    $substitutions = array(
      'CO_NAME'      => $this->Co->field('name', array('Co.id' => $coId)),
      'INVITER_NAME' => $this->inviterName($inviterCoPersonId),
      'APPLICATIONS' => implode("\n", $lines),
      'EXPIRES'      => _txt('pl.applicationteamenroller.invitation.email.expires',
                             array(date('Y-m-d H:i', strtotime($expires)))),
      'INVITE_URL'   => $this->responseUrl($token)
    );

    $subject = !empty($settings['email_subject'])
               ? $settings['email_subject']
               : _txt('pl.applicationteamenroller.setting.email_subject.default');
    $body = !empty($settings['email_body'])
            ? $settings['email_body']
            : _txt('pl.applicationteamenroller.setting.email_body.default');

    try {
      $email = new CakeEmail($this->emailConfig);

      $email->template('custom', 'basic')
            ->emailFormat(MessageFormatEnum::Plaintext)
            ->to($invitedEmail)
            ->viewVars(array(MessageFormatEnum::Plaintext => processTemplate($body, $substitutions)))
            ->subject(processTemplate($subject, $substitutions));

      $email->send();
    } catch(Exception $e) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.invitation.send',
                                      array($invitedEmail, $e->getMessage())));
    }
  }

  /**
   * The inviting admin's name for the email: their primary name, or a
   * generic description if they have none.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coPersonId CoPerson ID
   * @return String
   */

  protected function inviterName($coPersonId) {
    $Name = ClassRegistry::init('Name');

    $args = array();
    $args['conditions']['Name.co_person_id'] = $coPersonId;
    $args['conditions']['Name.primary_name'] = true;
    $args['contain'] = false;

    $name = $Name->find('first', $args);

    if(!empty($name['Name'])) {
      $cn = generateCn($name['Name']);

      if($cn !== '') {
        return $cn;
      }
    }

    return _txt('pl.applicationteamenroller.invitation.email.inviter');
  }
}
