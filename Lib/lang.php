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
  'pl.applicationteamenroller.er.setting.unique' => 'Settings already exist for this CO.'
);
