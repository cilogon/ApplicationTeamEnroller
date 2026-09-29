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
  'pl.applicationteamenroller.wedge.info' => 'This enrollment flow wedge has no settings of its own. The Application Team Enroller settings apply to the whole CO and are configured separately.'
);
