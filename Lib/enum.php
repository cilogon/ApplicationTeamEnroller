<?php
/**
 * COmanage Registry Application Team Enroller Plugin Enumerations
 *
 * Registry includes this file for every plugin from its own Lib/enum.php.
 * Values are the literal names the Product Contract uses, so the audit tables
 * read without a lookup. Each class's $values lists every value, in the
 * order the Product Contract gives them, for inList validation.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

// Status of a configuration record (application, research team). A retired
// record is no longer offered but keeps its history and nestings (KTD10).
class AteConfigStatusEnum
{
  const Active  = 'active';
  const Retired = 'retired';

  public static $values = array(self::Active, self::Retired);
}

// Invitation.status (Data Model, R7)
class AteInvitationStatusEnum
{
  const Sent      = 'sent';
  const Responded = 'responded';
  const Revoked   = 'revoked';
  const Expired   = 'expired';

  public static $values = array(self::Sent, self::Responded, self::Revoked, self::Expired);
}

// EnrollmentRequest.status (Data Model, R8, R17, R18)
class AteRequestStatusEnum
{
  const Offered            = 'offered';
  const DeclinedByEnrollee = 'declined_by_enrollee';
  const PendingDecision    = 'pending_decision';
  const Approved           = 'approved';
  const Denied             = 'denied';
  const Revoked            = 'revoked';
  const Expired            = 'expired';

  public static $values = array(self::Offered, self::DeclinedByEnrollee, self::PendingDecision,
                                self::Approved, self::Denied, self::Revoked, self::Expired);
}

// EnrollmentRequest.pending_reason, set once at response time (KTD7)
class AtePendingReasonEnum
{
  const Approval     = 'approval';
  const Mismatch     = 'mismatch';
  const LinkRequired = 'link_required';

  public static $values = array(self::Approval, self::Mismatch, self::LinkRequired);
}

// EnrollmentRequest.decided_by_role (R8)
class AteDecidedByRoleEnum
{
  const Approver      = 'approver';
  const InvitingAdmin = 'inviting_admin';
  const CoAdmin       = 'co_admin';
  const Automatic     = 'automatic';

  public static $values = array(self::Approver, self::InvitingAdmin, self::CoAdmin, self::Automatic);
}

// EnrollmentRequestTeam.outcome (R26, R29)
class AteTeamOutcomeEnum
{
  const Added          = 'added';
  const AlreadyPresent = 'already_present';
  const Skipped        = 'skipped';

  public static $values = array(self::Added, self::AlreadyPresent, self::Skipped);
}

// CoNotification action codes. Plugin codes take the 'p' prefix (KTD12).
class AteNotificationActionEnum
{
  const PendingDecision = 'pAPD';
  const Decided         = 'pADC';
  const Expired         = 'pAEX';
}
