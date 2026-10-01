<?php
/**
 * COmanage Registry Application Team Enroller Decision Queue
 *
 * The pending requests the user may decide, and only those (R25). The
 * controller filters the list through AteAuthzComponent. Each row shows the
 * researcher and their CO Person status (KTD6), the invited address, the
 * emails the login reported, the mismatch flag, the inviting admin, the
 * application, the teams, and for link_required the person the login would
 * be linked to. Review opens the decision forms.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add breadcrumbs
  print $this->element("coCrumb");
  $this->Html->addCrumb(_txt('pl.applicationteamenroller.queue'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;
  $params['topLinks'] = array();

  print $this->element("pageTitleAndButtons", $params);

  // Escape for HTML
  $h = function($s) {
    return filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS);
  };

  // A person with their CO Person status, or the fallback
  $person = function($p, $fallback = '') use ($h) {
    if(empty($p)) {
      return $h($fallback);
    }

    $name = ($p['name'] !== '') ? $p['name'] : ('#' . $p['co_person_id']);

    return $h($name) . ' (' . $h(_txt('en.status', null, $p['status'])) . ')';
  };
?>

<?php if(empty($vv_requests)): ?>
<div class="co-info-topbox">
  <em class="material-icons">info</em>
  <?php print _txt('pl.applicationteamenroller.queue.none'); ?>
</div>
<?php else: ?>
<p><?php print _txt('pl.applicationteamenroller.queue.desc'); ?></p>

<div class="table-container">
  <table id="ate_enrollment_requests">
    <thead>
      <tr>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.researcher'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.invitation.invited_email'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.login_emails'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.mismatch'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.invitation.inviter'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.application'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.teams'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.reason'); ?></th>
        <th><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>

    <tbody>
      <?php $i = 0; ?>
      <?php foreach($vv_requests as $r): ?>
      <tr class="line<?php print ($i % 2)+1; ?>">
        <td>
          <?php
            print $person($r['researcher'], $r['responder_name'] ?: _txt('pl.applicationteamenroller.fd.request.researcher.none'));

            if(!empty($r['link_target'])) {
              print '<br />' . _txt('pl.applicationteamenroller.fd.request.link_target') . ': ' . $person($r['link_target']);
            }
          ?>
        </td>
        <td><?php print $h($r['invited_email']); ?></td>
        <td>
          <?php
            print empty($r['identity_emails'])
                  ? '<em>' . _txt('pl.applicationteamenroller.fd.request.login_emails.none') . '</em>'
                  : implode('<br />', array_map($h, $r['identity_emails']));
          ?>
        </td>
        <td><?php print $r['mismatch'] ? _txt('fd.yes') : _txt('fd.no'); ?></td>
        <td><?php print $person($r['inviter']); ?></td>
        <td><?php print $h($r['application']['name']); ?></td>
        <td><?php print implode(', ', array_map($h, $r['teams'])); ?></td>
        <td><?php print $h($vv_pending_reasons[$r['pending_reason']] ?? $r['pending_reason']); ?></td>
        <td>
          <?php
            print $this->Html->link(
              _txt('pl.applicationteamenroller.queue.review'),
              array(
                'plugin'     => 'application_team_enroller',
                'controller' => 'ate_enrollment_requests',
                'action'     => 'view',
                $r['id']
              ),
              array('class' => 'viewbutton')
            );
          ?>
        </td>
      </tr>
      <?php $i++; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
