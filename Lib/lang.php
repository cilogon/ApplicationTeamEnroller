<?php
/**
 * COmanage Registry Application Team Enroller Plugin Language File
 *
 * Registry merges this array into its translation table from
 * _bootstrap_plugin_txt(), which it finds by the variable name
 * $cm_<plugin name underscored>_texts.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

global $cm_lang, $cm_texts;

// When localizing, the number in format specifications (eg: %1$s) indicates the argument
// position as passed to _txt.  This can be used to process the arguments in
// a different order than they were passed.

$cm_application_team_enroller_texts['en_US'] = array(
  // Titles, per-controller
  'ct.application_team_enrollers.1'  => 'Application Team Enroller',
  'ct.application_team_enrollers.pl' => 'Application Team Enrollers',
  'ct.ate_applications.1'            => 'Application',
  'ct.ate_applications.pl'           => 'Applications',
  'ct.ate_application_teams.1'       => 'Authorized Research Team',
  'ct.ate_application_teams.pl'      => 'Authorized Research Teams',
  'ct.ate_enrollment_requests.1'     => 'Enrollment Request',
  'ct.ate_enrollment_requests.pl'    => 'Enrollment Requests',
  'ct.ate_invitations.1'             => 'Invitation',
  'ct.ate_invitations.pl'            => 'Invitations',
  'ct.ate_research_teams.1'          => 'Research Team',
  'ct.ate_research_teams.pl'         => 'Research Teams',
  'ct.ate_settings.1'                => 'Application Team Enroller Settings',
  'ct.ate_settings.pl'               => 'Application Team Enroller Settings',

  // Enumerations
  'pl.applicationteamenroller.en.status.active'  => 'Active',
  'pl.applicationteamenroller.en.status.retired' => 'Retired',

  'pl.applicationteamenroller.en.invitation_status.sent'      => 'Sent',
  'pl.applicationteamenroller.en.invitation_status.responded' => 'Responded',
  'pl.applicationteamenroller.en.invitation_status.revoked'   => 'Revoked',
  'pl.applicationteamenroller.en.invitation_status.expired'   => 'Expired',

  'pl.applicationteamenroller.en.request_status.offered'              => 'Offered',
  'pl.applicationteamenroller.en.request_status.declined_by_enrollee' => 'Declined by Researcher',
  'pl.applicationteamenroller.en.request_status.pending_decision'     => 'Awaiting Decision',
  'pl.applicationteamenroller.en.request_status.approved'             => 'Approved',
  'pl.applicationteamenroller.en.request_status.denied'               => 'Denied',
  'pl.applicationteamenroller.en.request_status.revoked'              => 'Revoked',
  'pl.applicationteamenroller.en.request_status.expired'              => 'Expired',

  'pl.applicationteamenroller.en.pending_reason.approval'      => 'Approval required',
  'pl.applicationteamenroller.en.pending_reason.mismatch'      => 'Login does not match the invitation',
  'pl.applicationteamenroller.en.pending_reason.link_required' => 'Login would be linked to an existing person',

  'pl.applicationteamenroller.en.decided_by_role.approver'       => 'Approver',
  'pl.applicationteamenroller.en.decided_by_role.inviting_admin' => 'Inviting Administrator',
  'pl.applicationteamenroller.en.decided_by_role.co_admin'       => 'CO Administrator',
  'pl.applicationteamenroller.en.decided_by_role.automatic'      => 'Automatic',

  // CO configuration menu (cmPluginMenus)
  'pl.applicationteamenroller.menu.applications'   => 'Team Enroller: Applications',
  'pl.applicationteamenroller.menu.research_teams' => 'Team Enroller: Research Teams',
  'pl.applicationteamenroller.menu.settings'       => 'Team Enroller: Settings',

  // CO main menu (cmPluginMenus). Shown to every CO member; the screens
  // check access themselves (KTD16).
  'pl.applicationteamenroller.menu.invite'      => 'Invite Researcher',
  'pl.applicationteamenroller.menu.invitations' => 'Invitations',
  'pl.applicationteamenroller.menu.queue'       => 'Decision Queue',

  // Application fields
  'pl.applicationteamenroller.fd.application.name' => 'Name',
  'pl.applicationteamenroller.fd.application.client_identifier' => 'OIDC Client Identifier',
  'pl.applicationteamenroller.fd.application.client_identifier.desc' => 'Optional. The CILogon client this application logs in with, for reference.',
  'pl.applicationteamenroller.fd.application.admin_co_group_id' => 'Admin Group',
  'pl.applicationteamenroller.fd.application.admin_co_group_id.desc' => 'Members of this group may invite researchers to this application.',
  'pl.applicationteamenroller.fd.application.approver_co_group_id' => 'Approver Group',
  'pl.applicationteamenroller.fd.application.approver_co_group_id.desc' => 'Members of this group decide accepted requests for this application. It may be the same group as the admin group.',
  'pl.applicationteamenroller.fd.application.access_co_group_id' => 'Access Group',
  'pl.applicationteamenroller.fd.application.access_co_group_id.desc' => 'Maintained by this plugin. Its members are the members of the authorized research teams.',
  'pl.applicationteamenroller.fd.application.approval_required' => 'Approval Required',
  'pl.applicationteamenroller.fd.application.approval_required.desc' => 'If set, an approver decides each accepted request. If not, an accepted request is approved at once unless the login does not match the invited address.',
  'pl.applicationteamenroller.fd.status.desc' => 'A retired record is no longer offered to administrators or researchers, but keeps its history.',
  'pl.applicationteamenroller.application.teams' => 'Authorized Research Teams',
  'pl.applicationteamenroller.application.teams.none' => 'No research teams are authorized for this application yet.',
  'pl.applicationteamenroller.application.teams.add' => 'Authorize Research Team',
  'pl.applicationteamenroller.application.teams.none_available' => 'There are no active research teams left to authorize. Designate a group as a research team first.',

  // Access groups (KTD10)
  'pl.applicationteamenroller.access_group.desc' => 'Login access to application %1$s. Maintained by the Application Team Enroller plugin; do not edit.',
  'pl.applicationteamenroller.application.resync' => 'Resync Access Group',
  'pl.applicationteamenroller.application.resync.desc' => 'Nest every authorized research team in the access group and remove any other nested group.',
  'pl.applicationteamenroller.rs.resync.created' => 'Created the access group.',
  'pl.applicationteamenroller.rs.resync.added' => 'Nested in the access group: %1$s.',
  'pl.applicationteamenroller.rs.resync.removed' => 'Removed from the access group: %1$s.',
  'pl.applicationteamenroller.rs.resync.none' => 'The access group already matches the authorized research teams.',

  // Research team fields
  'pl.applicationteamenroller.fd.research_team.co_group_id' => 'Group',
  'pl.applicationteamenroller.fd.research_team.co_group_id.desc' => 'An existing standard group of this CO. Team membership is membership in this group. It cannot be changed after the team is created.',
  'pl.applicationteamenroller.fd.research_team.name' => 'Name',
  'pl.applicationteamenroller.fd.research_team.name.desc' => 'Shown to administrators and researchers. Defaults to the group name.',
  'pl.applicationteamenroller.research_team.none_available' => 'There are no groups available to designate. A research team must be a standard group of this CO that is not already a research team.',

  // Settings fields
  'pl.applicationteamenroller.fd.setting.invitation_lifetime_days' => 'Invitation Lifetime (Days)',
  'pl.applicationteamenroller.fd.setting.invitation_lifetime_days.desc' => 'How long an invitation link stays valid.',
  'pl.applicationteamenroller.fd.setting.email_subject' => 'Invitation Email Subject',
  'pl.applicationteamenroller.fd.setting.email_body' => 'Invitation Email Body',
  'pl.applicationteamenroller.fd.setting.email_body.desc' => 'Plain text. (@CO_NAME), (@INVITER_NAME), (@APPLICATIONS), (@EXPIRES), and (@INVITE_URL) are replaced when the invitation is sent.',
  'pl.applicationteamenroller.fd.setting.newcomer_co_enrollment_flow_id' => 'Newcomer Enrollment Flow',
  'pl.applicationteamenroller.fd.setting.newcomer_co_enrollment_flow_id.desc' => 'The enrollment flow a researcher with no record in this CO completes. It must be authorized for any authenticated user, require no approval and no email confirmation, use a matching policy other than Self or Select, and have an Application Team Enroller wedge attached.',
  'pl.applicationteamenroller.fd.setting.email_env_vars' => 'Login Email Variables',
  'pl.applicationteamenroller.fd.setting.email_env_vars.desc' => 'Comma-separated names of the web server environment variables that carry the login\'s email address.',
  'pl.applicationteamenroller.fd.setting.login_identifier_type' => 'Login Identifier Type',
  'pl.applicationteamenroller.fd.setting.login_identifier_type.desc' => 'The Identifier type used when the plugin attaches a login to a CO Person.',

  // Invitations (U6)
  'pl.applicationteamenroller.invitation.compose' => 'Invite Researcher',
  'pl.applicationteamenroller.invitation.compose.desc' => 'Choose one or more applications and, for each, the research teams to offer. The researcher receives one email with a link to accept or decline each application.',
  'pl.applicationteamenroller.invitation.compose.none' => 'There are no applications you can invite researchers to.',
  'pl.applicationteamenroller.invitation.compose.no_teams' => 'No research teams are authorized for this application.',
  'pl.applicationteamenroller.invitation.send' => 'Send Invitation',
  'pl.applicationteamenroller.fd.invitation.invited_email' => 'Researcher Email Address',
  'pl.applicationteamenroller.fd.invitation.applications' => 'Applications and Research Teams',
  'pl.applicationteamenroller.fd.invitation.inviter' => 'Invited By',
  'pl.applicationteamenroller.fd.invitation.expires' => 'Expires',
  'pl.applicationteamenroller.fd.invitation.sent' => 'Sent',
  'pl.applicationteamenroller.fd.invitation.requests' => 'Requests',
  'pl.applicationteamenroller.fd.invitation.revoked' => 'Revoked',
  'pl.applicationteamenroller.fd.request.application' => 'Application',
  'pl.applicationteamenroller.fd.request.teams' => 'Research Teams',
  'pl.applicationteamenroller.invitation.none' => 'There are no invitations to show.',
  'pl.applicationteamenroller.invitation.revoke' => 'Revoke Invitation',
  'pl.applicationteamenroller.invitation.revoke.confirm' => 'Revoke this invitation? Its link will stop working and every request not yet answered will be revoked.',
  'pl.applicationteamenroller.request.withdraw' => 'Withdraw',
  'pl.applicationteamenroller.request.withdraw.confirm' => 'Withdraw this request? It will not be decided.',
  'pl.applicationteamenroller.rs.invitation.sent' => 'Invitation sent to %1$s.',
  'pl.applicationteamenroller.rs.invitation.revoked' => 'Invitation revoked.',
  'pl.applicationteamenroller.rs.request.withdrawn' => 'Request withdrawn.',
  // One line of (@APPLICATIONS) in the invitation email: application, teams
  'pl.applicationteamenroller.invitation.email.application' => '- %1$s (research teams: %2$s)',
  // (@EXPIRES) in the invitation email
  'pl.applicationteamenroller.invitation.email.expires' => '%1$s UTC',
  // (@INVITER_NAME) when the inviting admin has no name on record
  'pl.applicationteamenroller.invitation.email.inviter' => 'An administrator',

  // Plugin texts
  'pl.applicationteamenroller.wedge.info' => 'This enrollment flow wedge has no settings of its own. The Application Team Enroller settings apply to the whole CO and are configured separately.',

  // Default invitation email, persisted into the CO's settings on first use
  // (KTD2) and editable there. (@...) placeholders are substituted when the
  // invitation is sent (KTD13).
  'pl.applicationteamenroller.setting.email_subject.default' => 'Invitation to applications in (@CO_NAME)',
  'pl.applicationteamenroller.setting.email_body.default' => 'Hello,

(@INVITER_NAME) has invited you to the following applications in (@CO_NAME):

(@APPLICATIONS)

Please follow the link below, log in, and accept or decline each application.
The link can be used for one response and expires on (@EXPIRES).

(@INVITE_URL)',

  // Validation messages
  'pl.applicationteamenroller.er.research_team.unique' => 'This group is already a research team.',
  'pl.applicationteamenroller.er.application_team.unique' => 'This research team is already authorized for this application.',
  'pl.applicationteamenroller.er.setting.unique' => 'Settings already exist for this CO.',
  'pl.applicationteamenroller.er.setting.email_env_vars.header' => '%1$s is set from a client request header, so it cannot identify a login\'s email address.',
  'pl.applicationteamenroller.er.application.group' => 'Choose a group of this CO.',
  'pl.applicationteamenroller.er.research_team.group' => 'This group cannot be a research team. Choose a standard group of this CO that is not already a research team or an application access group.',
  'pl.applicationteamenroller.er.application_team.team' => 'Only an active research team of this CO can be authorized for an application.',
  'pl.applicationteamenroller.er.application_team.application' => 'The application was not found in this CO.',
  'pl.applicationteamenroller.er.access_group.deleted' => 'The access group of this application has been deleted in Registry, so it cannot be resynchronized.',

  'pl.applicationteamenroller.er.invitation.email' => 'Enter a valid email address for the researcher.',
  'pl.applicationteamenroller.er.invitation.inviter' => 'Only a CO Person of this CO can send an invitation.',
  'pl.applicationteamenroller.er.invitation.none' => 'Choose at least one application.',
  'pl.applicationteamenroller.er.invitation.application' => 'You cannot invite researchers to one of the chosen applications.',
  'pl.applicationteamenroller.er.invitation.teams' => 'Choose at least one research team for %1$s.',
  'pl.applicationteamenroller.er.invitation.team' => 'A chosen research team is not authorized for %1$s.',
  'pl.applicationteamenroller.er.invitation.send' => 'The invitation email to %1$s could not be sent, so no invitation was created: %2$s',
  'pl.applicationteamenroller.er.invitation.handled' => 'This invitation was already responded to, revoked, or expired.',
  'pl.applicationteamenroller.er.request.handled' => 'This request is no longer awaiting a decision.',

  // Routing and approval (U7)
  'pl.applicationteamenroller.er.snapshot.identifier' => 'The login identity has no identifier.',
  'pl.applicationteamenroller.er.response.choices' => 'Accept or decline each offered application.',
  'pl.applicationteamenroller.er.response.person' => 'Accepting an application requires a CO Person record for this login.',
  'pl.applicationteamenroller.er.response.responder' => 'The responding CO Person does not match the login.',
  'pl.applicationteamenroller.er.login.ambiguous' => 'This login is linked to more than one CO Person in this CO.',
  'pl.applicationteamenroller.er.login.linked' => 'This login is already linked to another CO Person in this CO.',
  'pl.applicationteamenroller.er.decision.role' => 'The decision needs a deciding CO Person and a valid role.',
  'pl.applicationteamenroller.er.decision.self' => 'You cannot decide a request you responded to, or one that would link a login to you.',
  'pl.applicationteamenroller.er.approve.person' => 'This request has no CO Person to add to its research teams.',
  'pl.applicationteamenroller.er.approve.target' => 'The invited address belongs to more than one CO Person, so the login cannot be linked. Deny this request and send a new invitation.',
  'pl.applicationteamenroller.er.transaction' => 'The database transaction ended unexpectedly, so the change was not completed.',
  'pl.applicationteamenroller.er.query' => 'A database query failed.',
  // History record comment when approval links a login: identifier, type
  'pl.applicationteamenroller.rs.login.linked' => 'Linked login %1$s (%2$s) by Application Team Enroller approval',
  'pl.applicationteamenroller.rs.petition.retired' => 'Retired by Application Team Enroller: petition %1$s replaced it for invitation %2$s',

  // Response pages (U8). The researcher never sees mismatch details (R19).
  'pl.applicationteamenroller.response.title' => 'Respond to Invitation',
  'pl.applicationteamenroller.response.desc' => 'You have been invited to the applications below. Accept or decline each one. An accepted application may need to be approved before you can use it, and you will receive an email when it is decided.',
  'pl.applicationteamenroller.response.invited' => 'Invitation sent to %1$s, valid until %2$s UTC.',
  'pl.applicationteamenroller.response.accept' => 'Accept',
  'pl.applicationteamenroller.response.decline' => 'Decline',
  'pl.applicationteamenroller.response.submit' => 'Submit Response',
  'pl.applicationteamenroller.response.confirmation' => 'Response Recorded',
  'pl.applicationteamenroller.response.confirmation.desc' => 'Thank you. Your response has been recorded. The status of each application is shown below. You will receive an email when an application awaiting a decision is decided.',
  'pl.applicationteamenroller.response.state' => 'Status',
  'pl.applicationteamenroller.response.state.approved' => 'Approved',
  'pl.applicationteamenroller.response.state.pending_decision' => 'Awaiting a decision',
  'pl.applicationteamenroller.response.state.declined_by_enrollee' => 'Declined',
  'pl.applicationteamenroller.response.state.denied' => 'Not approved',
  'pl.applicationteamenroller.response.state.revoked' => 'Withdrawn',
  'pl.applicationteamenroller.response.state.expired' => 'Expired',
  'pl.applicationteamenroller.response.state.offered' => 'Not yet answered',
  'pl.applicationteamenroller.response.explanation' => 'Invitation',
  'pl.applicationteamenroller.response.explanation.unknown' => 'This invitation link is not valid. Please check that you used the complete link from your invitation email.',
  'pl.applicationteamenroller.response.explanation.revoked' => 'This invitation has been revoked. If you still need access, please ask the person who invited you to send a new invitation.',
  'pl.applicationteamenroller.response.explanation.expired' => 'This invitation has expired. If you still need access, please ask the person who invited you to send a new invitation.',
  'pl.applicationteamenroller.response.explanation.answered' => 'This invitation has already been answered. Each invitation link can be used for one response.',
  'pl.applicationteamenroller.response.explanation.no_invitation' => 'There is no invitation to respond to. Please follow the link in your invitation email.',
  'pl.applicationteamenroller.response.explanation.ambiguous' => 'Your response cannot be recorded because your login is linked to more than one person here. Please contact the person who invited you.',
  'pl.applicationteamenroller.response.explanation.error' => 'Your response could not be recorded because of an error. Please try again later, or contact the person who invited you.',
  'pl.applicationteamenroller.response.explanation.newcomer_unavailable' => 'Your choices have been saved, but enrollment for new researchers is not set up here yet. Please contact the person who invited you, and follow your invitation link again later.',
  'pl.applicationteamenroller.response.explanation.newcomer_session' => 'Enrollment can only continue from your invitation link, with the same login you responded with. Please follow the link in your invitation email again.',
  'pl.applicationteamenroller.response.explanation.newcomer_incomplete' => 'Your enrollment could not be completed. Please follow the link in your invitation email again, or contact the person who invited you.',
  'pl.applicationteamenroller.er.response.stale' => 'Your login or this page changed since it was shown. Please review your choices and submit again.',

  // Decision queue (U10)
  'pl.applicationteamenroller.queue' => 'Decision Queue',
  'pl.applicationteamenroller.queue.desc' => 'Requests awaiting a decision that you may decide. Approving adds the researcher to the listed research teams.',
  'pl.applicationteamenroller.queue.none' => 'There are no requests awaiting your decision.',
  'pl.applicationteamenroller.queue.request' => 'Decide Request',
  'pl.applicationteamenroller.queue.review' => 'Review',
  'pl.applicationteamenroller.queue.approve' => 'Approve',
  'pl.applicationteamenroller.queue.deny' => 'Deny',
  'pl.applicationteamenroller.queue.deny.confirm' => 'Deny this request? The researcher will not be added to its research teams.',
  'pl.applicationteamenroller.fd.request.researcher' => 'Researcher',
  'pl.applicationteamenroller.fd.request.researcher.none' => 'No CO Person yet',
  'pl.applicationteamenroller.fd.request.person_status' => 'CO Person Status',
  'pl.applicationteamenroller.fd.request.login' => 'Login',
  'pl.applicationteamenroller.fd.request.login_emails' => 'Emails From Login',
  'pl.applicationteamenroller.fd.request.login_emails.none' => 'None reported',
  'pl.applicationteamenroller.fd.request.mismatch' => 'Login Mismatch',
  'pl.applicationteamenroller.fd.request.mismatch.desc' => 'The login did not report the invited address, or the address belongs to another person.',
  'pl.applicationteamenroller.fd.request.link_target' => 'Would Link To',
  'pl.applicationteamenroller.fd.request.link_target.desc' => 'Approving links this login to the existing person who owns the invited address.',
  'pl.applicationteamenroller.fd.request.reason' => 'Why Pending',
  'pl.applicationteamenroller.fd.request.responded' => 'Responded',
  'pl.applicationteamenroller.fd.request.deciding_role' => 'You Decide As',
  'pl.applicationteamenroller.fd.request.comment' => 'Comment (optional)',
  'pl.applicationteamenroller.fd.request.comment.desc' => 'Kept for audit. It is not sent to the researcher.',
  'pl.applicationteamenroller.rs.request.approved' => 'Request approved.',
  'pl.applicationteamenroller.rs.request.denied' => 'Request denied.',
  'pl.applicationteamenroller.er.request.not_decidable' => 'You cannot decide this request, or it is no longer awaiting a decision.',

  // Notifications (KTD12). Pending: application, invited address. Decided:
  // application, invited address, outcome.
  'pl.applicationteamenroller.notification.pending' => 'A request for %1$s from %2$s awaits a decision',
  'pl.applicationteamenroller.notification.decided' => 'The request for %1$s from %2$s was decided: %3$s',
  'pl.applicationteamenroller.notification.expired' => 'The invitation to %1$s expired before it was answered',
  'pl.applicationteamenroller.notification.contained' => 'CO Person %1$s enrolled through the newcomer enrollment flow without an invitation (petition %2$s) and was suspended',

  // The expiry job (U11, KTD14, KTD18)
  'pl.applicationteamenroller.job.expire_invitations' => 'Expire lapsed invitations, notify their inviting administrators, and suspend people who enrolled through the newcomer enrollment flow without an invitation',
  'pl.applicationteamenroller.job.expire_invitations.requeue' => 'Run again this many minutes after each run (omit or 0 to run once)',
  'pl.applicationteamenroller.job.expire_invitations.done' => 'Expired %1$s invitations, retired %2$s petitions, notified %3$s inviting administrators, suspended %4$s people, %5$s errors',
  'pl.applicationteamenroller.job.expire_invitations.expired' => 'Invitation %1$s expired',
  'pl.applicationteamenroller.job.expire_invitations.retired' => 'Petition %1$s of expired invitation %2$s retired',
  'pl.applicationteamenroller.job.expire_invitations.notified' => 'Inviting administrator of invitation %1$s notified of its expiry',
  'pl.applicationteamenroller.job.expire_invitations.contained' => 'CO Person %1$s from petition %2$s suspended',
  'pl.applicationteamenroller.job.expire_invitations.error' => 'Error: %1$s',
  'pl.applicationteamenroller.rs.petition.retired.expired' => 'Retired by Application Team Enroller: invitation %1$s expired',
  'pl.applicationteamenroller.rs.petition.retired.revoked' => 'Retired by Application Team Enroller: invitation %1$s was revoked',
  'pl.applicationteamenroller.rs.contained' => 'Suspended by Application Team Enroller: enrolled through the newcomer enrollment flow by petition %1$s, which no invitation is bound to',
  'pl.applicationteamenroller.rs.contained.petition' => 'Application Team Enroller suspended the enrollee: no invitation is bound to this petition',

  // Decision emails to the researcher (R36): application, CO, research
  // teams. The decider's comment is never included.
  'pl.applicationteamenroller.decision.email.subject.approved' => 'Access to %1$s in %2$s approved',
  'pl.applicationteamenroller.decision.email.body.approved' => 'Hello,

Your request for access to %1$s in %2$s has been approved.

Research teams: %3$s

You can now log in to %1$s.',
  'pl.applicationteamenroller.decision.email.subject.denied' => 'Access to %1$s in %2$s not approved',
  'pl.applicationteamenroller.decision.email.body.denied' => 'Hello,

Your request for access to %1$s in %2$s was not approved.

If you have questions, please contact the person who invited you.',

  // Newcomer enrollment flow checks. Each names one problem, so the
  // administrator knows what to change on the flow.
  'pl.applicationteamenroller.er.newcomer_flow.notfound' => 'The selected newcomer enrollment flow was not found.',
  'pl.applicationteamenroller.er.newcomer_flow.co' => 'The newcomer enrollment flow must belong to this CO.',
  'pl.applicationteamenroller.er.newcomer_flow.authz' => 'The newcomer enrollment flow must be authorized for any authenticated user (Petitioner Enrollment Authorization "Authenticated User").',
  'pl.applicationteamenroller.er.newcomer_flow.approval' => 'The newcomer enrollment flow must not require approval (turn off Require Approval For Enrollment).',
  'pl.applicationteamenroller.er.newcomer_flow.verification' => 'The newcomer enrollment flow must not confirm email addresses (set Email Confirmation Mode to "None").',
  'pl.applicationteamenroller.er.newcomer_flow.match' => 'The newcomer enrollment flow must not use the Self or Select Identity Matching policy.',
  'pl.applicationteamenroller.er.newcomer_flow.role' => 'The newcomer enrollment flow must collect a CO Person Role attribute (for example Affiliation), because Registry makes a new CO Person Active only through a CO Person Role.',
  'pl.applicationteamenroller.er.newcomer_flow.wedge' => 'The newcomer enrollment flow must have an active Application Team Enroller wedge attached.'
);
