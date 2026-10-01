<?php
/**
 * COmanage Registry Application Team Enroller Response Confirmation
 *
 * Each application of the invitation with its state after the researcher's
 * response: approved, awaiting a decision, or declined (R19). The page
 * carries no identity check details.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  print $this->element("pageTitleAndButtons", $params);

  // Escape for HTML
  $h = function($s) {
    return filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS);
  };
?>
<p><?php print _txt('pl.applicationteamenroller.response.confirmation.desc'); ?></p>

<table id="ate_response_confirmation" class="common-table">
  <thead>
    <tr>
      <th><?php print _txt('pl.applicationteamenroller.fd.request.application'); ?></th>
      <th><?php print _txt('pl.applicationteamenroller.fd.request.teams'); ?></th>
      <th><?php print _txt('pl.applicationteamenroller.response.state'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($vv_requests as $r): ?>
    <tr class="line1">
      <td><?php print $h($r['application']); ?></td>
      <td><?php print implode(', ', array_map($h, $r['teams'])); ?></td>
      <td><?php print $h($r['state']); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
