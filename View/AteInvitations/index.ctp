<?php
/**
 * COmanage Registry Application Team Enroller Invitations Index
 *
 * The invitations the user may see (R34), each with the status of each of
 * its requests. The controller filters the list.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add breadcrumbs
  print $this->element("coCrumb");
  $this->Html->addCrumb(_txt('ct.ate_invitations.pl'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  if(!empty($permissions['add'])) {
    $params['topLinks'][] = $this->Html->link(
      _txt('pl.applicationteamenroller.invitation.compose'),
      array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_invitations',
        'action'     => 'add',
        'co'         => $cur_co['Co']['id']
      ),
      array('class' => 'addbutton')
    );
  }

  print $this->element("pageTitleAndButtons", $params);
?>

<?php if(empty($ate_invitations)): ?>
<div class="co-info-topbox">
  <em class="material-icons">info</em>
  <?php print _txt('pl.applicationteamenroller.invitation.none'); ?>
</div>
<?php else: ?>
<div class="table-container">
  <table id="ate_invitations">
    <thead>
      <tr>
        <th><?php print $this->Paginator->sort('invited_email', _txt('pl.applicationteamenroller.fd.invitation.invited_email')); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.invitation.inviter'); ?></th>
        <th><?php print $this->Paginator->sort('created', _txt('pl.applicationteamenroller.fd.invitation.sent')); ?></th>
        <th><?php print $this->Paginator->sort('expires', _txt('pl.applicationteamenroller.fd.invitation.expires')); ?></th>
        <th><?php print $this->Paginator->sort('status', _txt('fd.status')); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.invitation.requests'); ?></th>
        <th><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>

    <tbody>
      <?php $i = 0; ?>
      <?php foreach($ate_invitations as $inv): ?>
      <?php $viewUrl = array('plugin' => 'application_team_enroller',
                             'controller' => 'ate_invitations',
                             'action' => 'view',
                             $inv['AteInvitation']['id']); ?>
      <tr class="line<?php print ($i % 2)+1; ?>">
        <td>
          <?php print $this->Html->link($inv['AteInvitation']['invited_email'], $viewUrl); ?>
        </td>
        <td>
          <?php
            if(!empty($inv['InviterCoPerson']['PrimaryName'])) {
              print filter_var(generateCn($inv['InviterCoPerson']['PrimaryName']), FILTER_SANITIZE_SPECIAL_CHARS);
            }
          ?>
        </td>
        <td><?php print $this->Time->format($inv['AteInvitation']['created'], "%c $vv_tz", false, $vv_tz); ?></td>
        <td><?php print $this->Time->format($inv['AteInvitation']['expires'], "%c $vv_tz", false, $vv_tz); ?></td>
        <td>
          <?php
            $st = $inv['AteInvitation']['status'];
            print filter_var($vv_invitation_status[$st] ?? $st, FILTER_SANITIZE_SPECIAL_CHARS);
          ?>
        </td>
        <td>
          <?php foreach($inv['AteEnrollmentRequest'] as $r): ?>
          <div>
            <?php
              $st = $r['status'];
              print filter_var(($r['AteApplication']['name'] ?? '') . ': ' . ($vv_request_status[$st] ?? $st),
                               FILTER_SANITIZE_SPECIAL_CHARS);
            ?>
          </div>
          <?php endforeach; ?>
        </td>
        <td>
          <?php print $this->Html->link(_txt('op.view'), $viewUrl, array('class' => 'viewbutton')); ?>
        </td>
      </tr>
      <?php $i++; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
  print $this->element("pagination");
  endif;
