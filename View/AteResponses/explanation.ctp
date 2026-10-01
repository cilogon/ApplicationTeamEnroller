<?php
/**
 * COmanage Registry Application Team Enroller Invitation Explanation
 *
 * Why an invitation link cannot be used (R18, AE12): unknown, revoked,
 * expired, or already answered, or why a response could not be recorded.
 * Shown without a login for the landing page.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  print $this->element("pageTitleAndButtons", $params);
?>
<div class="co-info-topbox">
  <em class="material-icons">info</em>
  <?php print filter_var(_txt('pl.applicationteamenroller.response.explanation.' . $vv_reason), FILTER_SANITIZE_SPECIAL_CHARS); ?>
</div>
