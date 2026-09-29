<?php
/**
 * COmanage Registry Application Team Enroller Invitation View
 *
 * One invitation with each request's application, offered teams, and status
 * (R7, R8, R34). The inviting admin or a CO administrator can revoke a sent
 * invitation and withdraw a request awaiting a decision (R18).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  $inv = $vv_invitation['AteInvitation'];

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = 'application_team_enroller';
  $args['controller'] = 'ate_invitations';
  $args['action'] = 'index';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('ct.ate_invitations.pl'), $args);

  $this->Html->addCrumb($inv['invited_email']);

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;
  $params['topLinks'] = array();

  if($vv_may_revoke) {
    $params['topLinks'][] = $this->Form->postLink(
      _txt('pl.applicationteamenroller.invitation.revoke'),
      array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_invitations',
        'action'     => 'revoke',
        $inv['id']
      ),
      array('class' => 'deletebutton',
            'confirm' => _txt('pl.applicationteamenroller.invitation.revoke.confirm'))
    );
  }

  print $this->element("pageTitleAndButtons", $params);

  // A CoPerson's primary name, or nothing
  $personName = function($p) {
    return !empty($p['PrimaryName']) ? filter_var(generateCn($p['PrimaryName']), FILTER_SANITIZE_SPECIAL_CHARS) : '';
  };
?>
<ul id="view_ate_invitations" class="fields form-list">
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.invited_email'); ?></div>
    <div class="field-info"><?php print filter_var($inv['invited_email'], FILTER_SANITIZE_SPECIAL_CHARS); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.inviter'); ?></div>
    <div class="field-info"><?php print $personName($vv_invitation['InviterCoPerson'] ?? array()); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.sent'); ?></div>
    <div class="field-info"><?php print $this->Time->format($inv['created'], "%c $vv_tz", false, $vv_tz); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.expires'); ?></div>
    <div class="field-info"><?php print $this->Time->format($inv['expires'], "%c $vv_tz", false, $vv_tz); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('fd.status'); ?></div>
    <div class="field-info">
      <?php print filter_var($vv_invitation_status[$inv['status']] ?? $inv['status'], FILTER_SANITIZE_SPECIAL_CHARS); ?>
    </div>
  </li>
  <?php if(!empty($inv['revoked_at'])): ?>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.revoked'); ?></div>
    <div class="field-info">
      <?php
        print $this->Time->format($inv['revoked_at'], "%c $vv_tz", false, $vv_tz);
        $by = $personName($vv_invitation['RevokedByCoPerson'] ?? array());
        if($by !== '') {
          print ' (' . $by . ')';
        }
      ?>
    </div>
  </li>
  <?php endif; ?>
</ul>

<h2><?php print _txt('pl.applicationteamenroller.fd.invitation.requests'); ?></h2>

<div class="table-container">
  <table id="ate_enrollment_requests">
    <thead>
      <tr>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.application'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.request.teams'); ?></th>
        <th><?php print _txt('fd.status'); ?></th>
        <th><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 0; ?>
      <?php foreach($vv_invitation['AteEnrollmentRequest'] as $r): ?>
      <tr class="line<?php print ($i % 2)+1; ?>">
        <td><?php print filter_var($r['AteApplication']['name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS); ?></td>
        <td>
          <?php
            $names = array();
            foreach($r['AteEnrollmentRequestTeam'] as $t) {
              $names[] = filter_var($t['AteResearchTeam']['name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
            }
            print implode(', ', $names);
          ?>
        </td>
        <td><?php print filter_var($vv_request_status[$r['status']] ?? $r['status'], FILTER_SANITIZE_SPECIAL_CHARS); ?></td>
        <td>
          <?php
            if(in_array((int)$r['id'], $vv_withdrawable, true)) {
              print $this->Form->postLink(
                _txt('pl.applicationteamenroller.request.withdraw'),
                array(
                  'plugin'     => 'application_team_enroller',
                  'controller' => 'ate_invitations',
                  'action'     => 'withdraw',
                  $r['id']
                ),
                array('class' => 'deletebutton',
                      'confirm' => _txt('pl.applicationteamenroller.request.withdraw.confirm'))
              );
            }
          ?>
        </td>
      </tr>
      <?php $i++; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
