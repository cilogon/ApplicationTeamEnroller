<?php
/**
 * COmanage Registry Application Team Enroller Decide Request
 *
 * One pending request the user may decide, with R25's details, the
 * researcher's CO Person status (KTD6), and for link_required the person the
 * login would be linked to. Approve and deny each take an optional comment,
 * which is kept for audit and never sent to the researcher (R36). This page
 * is the source of the decider notification (KTD12).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  $r = $vv_request;

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = 'application_team_enroller';
  $args['controller'] = 'ate_enrollment_requests';
  $args['action'] = 'index';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('pl.applicationteamenroller.queue'), $args);

  $this->Html->addCrumb(_txt('pl.applicationteamenroller.queue.request'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;
  $params['topLinks'] = array();

  print $this->element("pageTitleAndButtons", $params);

  // Escape for HTML
  $h = function($s) {
    return filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS);
  };

  // A person's name, or the fallback
  $name = function($p, $fallback = '') use ($h) {
    if(empty($p)) {
      return $h($fallback);
    }

    return $h(($p['name'] !== '') ? $p['name'] : ('#' . $p['co_person_id']));
  };

  // One decision form: approve or deny, each with its own optional comment
  $form = function($action, $label, $formId) use ($r) {
    $out = $this->Form->create('AteEnrollmentRequest', array(
      'id'  => $formId,
      'url' => array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_enrollment_requests',
        'action'     => $action,
        $r['id']
      )
    ));

    $out .= '<div class="field-title">' . _txt('pl.applicationteamenroller.fd.request.comment') . '</div>';
    $out .= $this->Form->textarea('comment', array('id' => $formId . 'Comment', 'rows' => 3));
    $out .= '<div class="field-desc">' . _txt('pl.applicationteamenroller.fd.request.comment.desc') . '</div>';
    $out .= $this->Form->submit($label);
    $out .= $this->Form->end();

    return $out;
  };
?>
<ul id="view_ate_enrollment_requests" class="fields form-list">
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.application'); ?></div>
    <div class="field-info"><?php print $h($r['application']['name']); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.teams'); ?></div>
    <div class="field-info"><?php print implode(', ', array_map($h, $r['teams'])); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.researcher'); ?></div>
    <div class="field-info">
      <?php print $name($r['researcher'], $r['responder_name'] ?: _txt('pl.applicationteamenroller.fd.request.researcher.none')); ?>
    </div>
  </li>
  <?php if(!empty($r['researcher'])): ?>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.person_status'); ?></div>
    <div class="field-info"><?php print $h(_txt('en.status', null, $r['researcher']['status'])); ?></div>
  </li>
  <?php endif; ?>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.login'); ?></div>
    <div class="field-info"><?php print $h($r['responder_identifier']); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.invited_email'); ?></div>
    <div class="field-info"><?php print $h($r['invited_email']); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.login_emails'); ?></div>
    <div class="field-info">
      <?php
        print empty($r['identity_emails'])
              ? '<em>' . _txt('pl.applicationteamenroller.fd.request.login_emails.none') . '</em>'
              : implode('<br />', array_map($h, $r['identity_emails']));
      ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title"><?php print _txt('pl.applicationteamenroller.fd.request.mismatch'); ?></div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.request.mismatch.desc'); ?></div>
    </div>
    <div class="field-info"><?php print $r['mismatch'] ? _txt('fd.yes') : _txt('fd.no'); ?></div>
  </li>
  <?php if(!empty($r['link_target'])): ?>
  <li>
    <div class="field-name">
      <div class="field-title"><?php print _txt('pl.applicationteamenroller.fd.request.link_target'); ?></div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.request.link_target.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        print $name($r['link_target']) . ' (' . $h(_txt('en.status', null, $r['link_target']['status'])) . ')';
      ?>
    </div>
  </li>
  <?php endif; ?>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.invitation.inviter'); ?></div>
    <div class="field-info"><?php print $name($r['inviter']); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.responded'); ?></div>
    <div class="field-info">
      <?php
        if(!empty($r['responded_at'])) {
          print $this->Time->format($r['responded_at'], "%c $vv_tz", false, $vv_tz);
        }
      ?>
    </div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.reason'); ?></div>
    <div class="field-info"><?php print $h($vv_pending_reasons[$r['pending_reason']] ?? $r['pending_reason']); ?></div>
  </li>
  <li>
    <div class="field-name"><?php print _txt('pl.applicationteamenroller.fd.request.deciding_role'); ?></div>
    <div class="field-info"><?php print $h($vv_deciding_roles[$r['deciding_role']] ?? $r['deciding_role']); ?></div>
  </li>
</ul>

<div class="ate-decision">
  <h2><?php print _txt('pl.applicationteamenroller.queue.approve'); ?></h2>
  <?php print $form('approve', _txt('pl.applicationteamenroller.queue.approve'), 'AteApproveForm'); ?>
</div>

<div class="ate-decision">
  <h2><?php print _txt('pl.applicationteamenroller.queue.deny'); ?></h2>
  <?php print $form('deny', _txt('pl.applicationteamenroller.queue.deny'), 'AteDenyForm'); ?>
</div>

<script type="text/javascript">
  // Ask before denying: a denial cannot be undone from this page.
  $(function() {
    var denyConfirm = <?php print json_encode(_txt('pl.applicationteamenroller.queue.deny.confirm')); ?>;

    $('#AteDenyForm').on('submit', function() {
      return confirm(denyConfirm);
    });
  });
</script>
