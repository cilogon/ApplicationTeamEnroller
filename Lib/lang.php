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
  'ct.ate_research_teams.1'          => 'Research Team',
  'ct.ate_research_teams.pl'         => 'Research Teams',
  'ct.ate_settings.1'                => 'Application Team Enroller Settings',
  'ct.ate_settings.pl'               => 'Application Team Enroller Settings',

  // Enumerations
  'pl.applicationteamenroller.en.status.active'  => 'Active',
  'pl.applicationteamenroller.en.status.retired' => 'Retired',

  // CO configuration menu (cmPluginMenus)
  'pl.applicationteamenroller.menu.applications'   => 'Team Enroller: Applications',
  'pl.applicationteamenroller.menu.research_teams' => 'Team Enroller: Research Teams',
  'pl.applicationteamenroller.menu.settings'       => 'Team Enroller: Settings',

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
  'pl.applicationteamenroller.er.application.group' => 'Choose a group of this CO.',
  'pl.applicationteamenroller.er.research_team.group' => 'This group cannot be a research team. Choose a standard group of this CO that is not already a research team or an application access group.',
  'pl.applicationteamenroller.er.application_team.team' => 'Only an active research team of this CO can be authorized for an application.',
  'pl.applicationteamenroller.er.application_team.application' => 'The application was not found in this CO.',
  'pl.applicationteamenroller.er.access_group.deleted' => 'The access group of this application has been deleted in Registry, so it cannot be resynchronized.',

  // Newcomer enrollment flow checks. Each names one problem, so the
  // administrator knows what to change on the flow.
  'pl.applicationteamenroller.er.newcomer_flow.notfound' => 'The selected newcomer enrollment flow was not found.',
  'pl.applicationteamenroller.er.newcomer_flow.co' => 'The newcomer enrollment flow must belong to this CO.',
  'pl.applicationteamenroller.er.newcomer_flow.authz' => 'The newcomer enrollment flow must be authorized for any authenticated user (Petitioner Enrollment Authorization "Authenticated User").',
  'pl.applicationteamenroller.er.newcomer_flow.approval' => 'The newcomer enrollment flow must not require approval (turn off Require Approval For Enrollment).',
  'pl.applicationteamenroller.er.newcomer_flow.verification' => 'The newcomer enrollment flow must not confirm email addresses (set Email Confirmation Mode to "None").',
  'pl.applicationteamenroller.er.newcomer_flow.match' => 'The newcomer enrollment flow must not use the Self or Select Identity Matching policy.',
  'pl.applicationteamenroller.er.newcomer_flow.wedge' => 'The newcomer enrollment flow must have an active Application Team Enroller wedge attached.'
);
