<?php
/**
 * COmanage Registry Application Team Enroller Authorization Component
 *
 * Answers every "may this user do X" question for the plugin (KTD16), from
 * the $roles array RoleComponent::calculateCMRoles() produces plus current
 * CoGroupMember rows. The plugin's own records say which application,
 * invitation, or request is in question; they never grant access themselves.
 *
 * The acting CoPerson is always $roles['copersonid'], which Registry sets
 * only for a CoPerson with an active role in the current CO, never the
 * session's co_person_id. Every object-level check also takes the current CO
 * ID and denies an object that belongs to another CO.
 *
 * Deciding a request follows its stored pending_reason (KTD7), then removes
 * the responder's CoPerson and the link target from the decider set, even for
 * CO administrators (R39, KTD17).
 *
 * These methods answer who may act. Whether the object is in a state that
 * allows the action (an invitation still `sent`, a request still
 * `pending_decision`) is the calling controller's check, except for deciding,
 * where eligibility is only defined for a pending request.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('Component', 'Controller');

class AteAuthzComponent extends Component {
  public $components = array('Session', 'Role');

  /**
   * Determine whether the user may configure the plugin: applications,
   * research teams, the application-to-team mapping, and settings (A4).
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @return Boolean
   */

  public function mayConfigure($roles) {
    return $this->isAdmin($roles);
  }

  /**
   * Determine whether the user may invite researchers to an application
   * (R11): a member of its admin group, or a CO administrator. Only an
   * active application of the current CO can be invited for.
   *
   * @param  Array   $roles         Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId          Current CO ID
   * @param  Integer $applicationId AteApplication ID
   * @return Boolean
   */

  public function mayInvite($roles, $coId, $applicationId) {
    return in_array((int)$applicationId, $this->invitableApplicationIds($roles, $coId), true);
  }

  /**
   * The applications the user may select when composing an invitation (R11).
   * An inviter must have a CoPerson in the CO, since the invitation records
   * them as its inviting admin (R24).
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId  Current CO ID
   * @return Array          AteApplication IDs, ascending
   */

  public function invitableApplicationIds($roles, $coId) {
    if(!$this->actor($roles)) {
      return array();
    }

    $ids = array();

    foreach($this->applications($coId, true) as $app) {
      if($this->isAdmin($roles)
         || $this->isMember($this->actor($roles), $app['admin_co_group_id'])) {
        $ids[] = (int)$app['id'];
      }
    }

    return $ids;
  }

  /**
   * The applications of the CO whose admin group the user belongs to, active
   * or retired. A CO administrator administers every application. Use it to
   * filter the invitation list (R34).
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId  Current CO ID
   * @return Array          AteApplication IDs, ascending
   */

  public function administeredApplicationIds($roles, $coId) {
    $ids = array();

    foreach($this->applications($coId, false) as $app) {
      if($this->isAdmin($roles)
         || $this->isMember($this->actor($roles), $app['admin_co_group_id'])) {
        $ids[] = (int)$app['id'];
      }
    }

    return $ids;
  }

  /**
   * Determine whether the user may see the invitation list (R34): an
   * administrator of at least one application, or a CO administrator.
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId  Current CO ID
   * @return Boolean
   */

  public function mayListInvitations($roles, $coId) {
    return $this->isAdmin($roles) || !empty($this->administeredApplicationIds($roles, $coId));
  }

  /**
   * Determine whether the user may see the decision queue (R25): an
   * administrator or approver of at least one application, or a CO
   * administrator. The queue itself shows only decidableRequestIds().
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId  Current CO ID
   * @return Boolean
   */

  public function mayViewQueue($roles, $coId) {
    if($this->isAdmin($roles)) {
      return true;
    }

    $actor = $this->actor($roles);

    foreach($this->applications($coId, false) as $app) {
      if($this->isMember($actor, $app['admin_co_group_id'])
         || $this->isMember($actor, $app['approver_co_group_id'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Determine whether the user may view an invitation (R34): its inviter, an
   * administrator of any application it includes, or a CO administrator.
   *
   * @param  Array   $roles        Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId         Current CO ID
   * @param  Integer $invitationId AteInvitation ID
   * @return Boolean
   */

  public function mayViewInvitation($roles, $coId, $invitationId) {
    $inv = $this->invitation($coId, $invitationId);

    if(!$inv) {
      return false;
    }

    if($this->isAdmin($roles)) {
      return true;
    }

    $actor = $this->actor($roles);

    if(!$actor) {
      return false;
    }

    if((int)$inv['inviter_co_person_id'] === $actor) {
      return true;
    }

    $Request = ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest');

    $args = array();
    $args['conditions']['AteEnrollmentRequest.ate_invitation_id'] = $inv['id'];
    $args['fields'] = array('AteEnrollmentRequest.ate_application_id');
    $args['contain'] = false;

    $appIds = array();
    foreach($Request->find('all', $args) as $r) {
      $appIds[] = (int)$r['AteEnrollmentRequest']['ate_application_id'];
    }

    return (bool)array_intersect($appIds, $this->administeredApplicationIds($roles, $coId));
  }

  /**
   * Determine whether the user may revoke an invitation (R18): its inviting
   * admin or a CO administrator.
   *
   * @param  Array   $roles        Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId         Current CO ID
   * @param  Integer $invitationId AteInvitation ID
   * @return Boolean
   */

  public function mayRevokeInvitation($roles, $coId, $invitationId) {
    $inv = $this->invitation($coId, $invitationId);

    return $inv && $this->isInviterOrCoAdmin($roles, $inv);
  }

  /**
   * Determine whether the user may withdraw a request (R18): the inviting
   * admin of its invitation or a CO administrator.
   *
   * @param  Array   $roles     Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId      Current CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Boolean
   */

  public function mayWithdrawRequest($roles, $coId, $requestId) {
    $req = $this->request($coId, $requestId);

    return $req && $this->isInviterOrCoAdmin($roles, $req['AteInvitation']);
  }

  /**
   * Determine whether the user may approve or deny a request (R24, R39).
   *
   * @param  Array   $roles     Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId      Current CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Boolean
   */

  public function mayDecideRequest($roles, $coId, $requestId) {
    return $this->decidingRole($roles, $coId, $requestId) !== null;
  }

  /**
   * The role under which the user may decide a request, for recording in
   * decided_by_role (R8), or null if they may not decide it.
   *
   * An application role is preferred over CO administrator when both apply.
   * A member of the admin group deciding a mismatch request after the
   * inviting admin left the group is recorded as the inviting admin, the role
   * they stand in for (R24).
   *
   * @param  Array   $roles     Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId      Current CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return String|null        AteDecidedByRoleEnum value, or null
   */

  public function decidingRole($roles, $coId, $requestId) {
    $req = $this->request($coId, $requestId);

    if(!$req) {
      return null;
    }

    return $this->decidingRoleFor($roles, $coId, $req);
  }

  /**
   * The pending requests of the CO the user may decide (R25), for the
   * decision queue. link_required requests appear only for CO administrators
   * who are not their link target (KTD17).
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId  Current CO ID
   * @return Array          AteEnrollmentRequest IDs, ascending
   */

  public function decidableRequestIds($roles, $coId) {
    if(!$this->actor($roles)) {
      return array();
    }

    $Request = ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest');

    $args = array();
    $args['conditions']['AteInvitation.co_id'] = $coId;
    $args['conditions']['AteEnrollmentRequest.status'] = AteRequestStatusEnum::PendingDecision;
    $args['order'] = array('AteEnrollmentRequest.id' => 'asc');
    $args['contain'] = array('AteInvitation', 'AteApplication');

    $ids = array();

    foreach($Request->find('all', $args) as $req) {
      if($this->decidingRoleFor($roles, $coId, $req) !== null) {
        $ids[] = (int)$req['AteEnrollmentRequest']['id'];
      }
    }

    return $ids;
  }

  /**
   * Determine whether the current login may respond to an invitation. A
   * first-time CILogon user has no CoPerson and no `user` role, so only an
   * authenticated username is required (KTD16). The invitation token is the
   * responding controller's check.
   *
   * @return Boolean
   */

  public function mayRespond() {
    $username = $this->Session->read('Auth.User.username');

    return is_string($username) && $username !== '';
  }

  /**
   * Compute the deciding role for a loaded request (see decidingRole()).
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Integer $coId  Current CO ID
   * @param  Array   $req   Request with AteInvitation and AteApplication
   * @return String|null    AteDecidedByRoleEnum value, or null
   */

  protected function decidingRoleFor($roles, $coId, $req) {
    $actor = $this->actor($roles);
    $r = $req['AteEnrollmentRequest'];
    $inv = $req['AteInvitation'];
    $app = $req['AteApplication'];

    // A decision records its decider, and R39 is checked by CoPerson, so a
    // decider needs a CoPerson in this CO.
    if(!$actor
       || (int)$inv['co_id'] !== (int)$coId
       || $r['status'] !== AteRequestStatusEnum::PendingDecision) {
      return null;
    }

    // R39: no one decides a request they responded to, or one whose approval
    // would link a login to their own CoPerson. This applies to CO
    // administrators too.
    if($actor === (int)$inv['invitee_co_person_id']
       || $actor === (int)$inv['link_target_co_person_id']) {
      return null;
    }

    $coAdmin = $this->isAdmin($roles);

    switch($r['pending_reason']) {
      case AtePendingReasonEnum::Approval:
        if($this->isMember($actor, $app['approver_co_group_id'])) {
          return AteDecidedByRoleEnum::Approver;
        }
        break;
      case AtePendingReasonEnum::Mismatch:
        $inviter = (int)$inv['inviter_co_person_id'];

        if($inviter && $this->inviterStillHolds($coId, $inviter, $app['admin_co_group_id'])) {
          if($actor === $inviter) {
            return AteDecidedByRoleEnum::InvitingAdmin;
          }
        } elseif($this->isMember($actor, $app['admin_co_group_id'])) {
          return AteDecidedByRoleEnum::InvitingAdmin;
        }
        break;
      case AtePendingReasonEnum::LinkRequired:
        // CO administrators only, handled below.
        break;
      default:
        // An unknown reason routes to no one but CO administrators.
        break;
    }

    return $coAdmin ? AteDecidedByRoleEnum::CoAdmin : null;
  }

  /**
   * Whether the inviting admin still holds the inviting-admin decision for a
   * mismatch request (R24): they are still in the application's admin group,
   * or they are a CO administrator (a CO administrator who sent an invitation
   * is its inviting admin).
   *
   * @param  Integer $coId         Current CO ID
   * @param  Integer $inviterId    Inviter CoPerson ID
   * @param  Integer $adminGroupId Application admin CoGroup ID
   * @return Boolean
   */

  protected function inviterStillHolds($coId, $inviterId, $adminGroupId) {
    if($this->isMember($inviterId, $adminGroupId)) {
      return true;
    }

    try {
      $coAdminGroupId = ClassRegistry::init('CoGroup')->adminCoGroupId($coId);
    }
    catch(InvalidArgumentException $e) {
      return false;
    }

    return $this->isMember($inviterId, $coAdminGroupId);
  }

  /**
   * Whether the user is the invitation's inviting admin or a CO
   * administrator, acting with a CoPerson in the CO.
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @param  Array   $inv   AteInvitation row
   * @return Boolean
   */

  protected function isInviterOrCoAdmin($roles, $inv) {
    $actor = $this->actor($roles);

    if(!$actor) {
      return false;
    }

    return $this->isAdmin($roles) || (int)$inv['inviter_co_person_id'] === $actor;
  }

  /**
   * Whether the user is a CO or platform administrator.
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @return Boolean
   */

  protected function isAdmin($roles) {
    return !empty($roles['cmadmin']) || !empty($roles['coadmin']);
  }

  /**
   * The acting CoPerson ID, only when it has an active role in the current
   * CO.
   *
   * @param  Array   $roles Roles from RoleComponent::calculateCMRoles()
   * @return Integer        CoPerson ID, or 0 if none
   */

  protected function actor($roles) {
    return empty($roles['copersonid']) ? 0 : (int)$roles['copersonid'];
  }

  /**
   * Whether a CoPerson is a current member of a CoGroup.
   *
   * @param  Integer $coPersonId CoPerson ID, or 0
   * @param  Integer $coGroupId  CoGroup ID, or null
   * @return Boolean
   */

  protected function isMember($coPersonId, $coGroupId) {
    if(empty($coPersonId) || empty($coGroupId)) {
      return false;
    }

    return (bool)$this->Role->isCoGroupMember($coPersonId, $coGroupId);
  }

  /**
   * The current applications of a CO.
   *
   * @param  Integer $coId       CO ID
   * @param  Boolean $activeOnly Only active (not retired) applications
   * @return Array               AteApplication rows, by ascending ID
   */

  protected function applications($coId, $activeOnly) {
    $Application = ClassRegistry::init('ApplicationTeamEnroller.AteApplication');

    $args = array();
    $args['conditions']['AteApplication.co_id'] = $coId;
    if($activeOnly) {
      $args['conditions']['AteApplication.status'] = AteConfigStatusEnum::Active;
    }
    $args['order'] = array('AteApplication.id' => 'asc');
    $args['contain'] = false;

    return Hash::extract($Application->find('all', $args), '{n}.AteApplication');
  }

  /**
   * An invitation of the current CO.
   *
   * @param  Integer $coId         CO ID
   * @param  Integer $invitationId AteInvitation ID
   * @return Array|null            AteInvitation row, or null
   */

  protected function invitation($coId, $invitationId) {
    $Invitation = ClassRegistry::init('ApplicationTeamEnroller.AteInvitation');

    $args = array();
    $args['conditions']['AteInvitation.id'] = $invitationId;
    $args['conditions']['AteInvitation.co_id'] = $coId;
    $args['contain'] = false;

    $inv = $Invitation->find('first', $args);

    return empty($inv) ? null : $inv['AteInvitation'];
  }

  /**
   * A request of the current CO, with its invitation and application.
   *
   * @param  Integer $coId      CO ID
   * @param  Integer $requestId AteEnrollmentRequest ID
   * @return Array|null         Request with AteInvitation and AteApplication
   */

  protected function request($coId, $requestId) {
    $Request = ClassRegistry::init('ApplicationTeamEnroller.AteEnrollmentRequest');

    $args = array();
    $args['conditions']['AteEnrollmentRequest.id'] = $requestId;
    $args['conditions']['AteInvitation.co_id'] = $coId;
    $args['contain'] = array('AteInvitation', 'AteApplication');

    $req = $Request->find('first', $args);

    return empty($req['AteApplication']['id']) ? null : $req;
  }
}
