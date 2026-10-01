<!--
/**
 * COmanage Registry Application Team Enroller Settings Fields
 *
 * Per-CO plugin settings (R5, KTD2). The form posts the settings row's id and
 * co_id, so a save updates the CO's one row rather than inserting another.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */
-->
<?php
  // Determine if fields are editable
  $e = ($this->action == "edit" && $permissions['edit']);

  // We shouldn't get here if we don't have at least read permission, but check just in case
  if(!$e && !$permissions['view'])
    return false;

  $s = $ate_settings[0]['AteSetting'];

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_dashboards';
  $args['action'] = 'configuration';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('me.configuration'), $args);

  $this->Html->addCrumb(_txt('ct.ate_settings.pl'));

  if($e) {
    print $this->Form->hidden('id', array('default' => $s['id'])) . "\n";
    print $this->Form->hidden('co_id', array('default' => $s['co_id'])) . "\n";
  }

  // Print a plain field's value or its input
  $plain = function($field, $inputArgs = array()) use ($e, $s) {
    if($e) {
      return $this->Form->input($field, $inputArgs);
    }

    return filter_var((string)$s[$field], FILTER_SANITIZE_SPECIAL_CHARS);
  };

  // Print a select's current label or the select
  $choice = function($field, $options) use ($e, $s) {
    if($e) {
      $ret = $this->Form->select($field, $options, array('empty' => true));

      if($this->Form->isFieldError($field)) {
        $ret .= $this->Form->error($field);
      }

      return $ret;
    }

    return (!empty($s[$field]) && isset($options[ $s[$field] ]))
           ? filter_var($options[ $s[$field] ], FILTER_SANITIZE_SPECIAL_CHARS)
           : '';
  };
?>
<ul id="<?php print $this->action; ?>_ate_settings" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.setting.invitation_lifetime_days'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.setting.invitation_lifetime_days.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php print $plain('invitation_lifetime_days', array('type' => 'number', 'min' => 1)); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.setting.email_subject'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php print $plain('email_subject', array('class' => 'focusFirst')); ?>
    </div>
  </li>
  <li>
    <div class="field-name vtop">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.setting.email_body'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.setting.email_body.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->textarea('email_body', array('rows' => 14));

          if($this->Form->isFieldError('email_body')) {
            print $this->Form->error('email_body');
          }
        } else {
          print nl2br(filter_var((string)$s['email_body'], FILTER_SANITIZE_SPECIAL_CHARS));
        }
      ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.setting.newcomer_co_enrollment_flow_id'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.setting.newcomer_co_enrollment_flow_id.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php print $choice('newcomer_co_enrollment_flow_id', $vv_available_flows); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.setting.email_env_vars'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.setting.email_env_vars.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php print $plain('email_env_vars'); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.setting.login_identifier_type'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.setting.login_identifier_type.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php print $choice('login_identifier_type', $vv_identifier_types); ?>
    </div>
  </li>
  <?php if($e): ?>
  <li class="fields-submit">
    <div class="field-name">
      <span class="required"><?php print _txt('fd.req'); ?></span>
    </div>
    <div class="field-info">
      <?php print $this->Form->submit($submit_label); ?>
    </div>
  </li>
  <?php endif; ?>
</ul>
<?php
  print $this->element("changelog");
