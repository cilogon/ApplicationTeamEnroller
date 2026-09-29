<!--
/**
 * COmanage Registry Application Team Enroller Application Fields
 *
 * An application's settings (R1, R4). The view page also lists the research
 * teams authorized for the application and links to authorize or remove them
 * (R3); the edit page points there, because the Standard edit view wraps
 * these fields in its own form.
 *
 * The edit form posts the application's id, so a save updates the row rather
 * than inserting another.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */
-->
<?php
  // Determine if fields are editable
  $e = false;

  if(($this->action == "add" && $permissions['add']) || ($this->action == "edit" && $permissions['edit']))
    $e = true;

  // We shouldn't get here if we don't have at least read permission, but check just in case
  if(!$e && !$permissions['view'])
    return false;

  $d = !empty($ate_applications[0]) ? $ate_applications[0] : array();
  $a = !empty($d['AteApplication']) ? $d['AteApplication'] : array();

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_dashboards';
  $args['action'] = 'configuration';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('me.configuration'), $args);

  $args = array();
  $args['plugin'] = 'application_team_enroller';
  $args['controller'] = 'ate_applications';
  $args['action'] = 'index';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('ct.ate_applications.pl'), $args);

  $this->Html->addCrumb(_txt('op.' . $this->action . '-a', array(_txt('ct.ate_applications.1'))));

  if($e) {
    if($this->action == 'edit') {
      print $this->Form->hidden('id', array('default' => $a['id'])) . "\n";
    }
    print $this->Form->hidden('co_id', array('default' => $cur_co['Co']['id'])) . "\n";
  }

  // The display name of a group of the CO, or '' if unset
  $groupName = function($groupId) use ($vv_available_groups) {
    return (!empty($groupId) && isset($vv_available_groups[$groupId]))
           ? filter_var($vv_available_groups[$groupId], FILTER_SANITIZE_SPECIAL_CHARS)
           : '';
  };

  // A group select, or the group's name
  $groupField = function($field) use ($e, $a, $groupName, $vv_available_groups) {
    if(!$e) {
      return $groupName(isset($a[$field]) ? $a[$field] : null);
    }

    $ret = $this->Form->select($field, $vv_available_groups, array('empty' => true));

    if($this->Form->isFieldError($field)) {
      $ret .= $this->Form->error($field);
    }

    return $ret;
  };
?>
<ul id="<?php print $this->action; ?>_ate_applications" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.application.name'); ?>
        <span class="required">*</span>
      </div>
    </div>
    <div class="field-info">
      <?php
        print ($e
               ? $this->Form->input('name', array('class' => 'focusFirst'))
               : filter_var($a['name'], FILTER_SANITIZE_SPECIAL_CHARS));
      ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.application.client_identifier'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.application.client_identifier.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        print ($e
               ? $this->Form->input('client_identifier')
               : filter_var((string)$a['client_identifier'], FILTER_SANITIZE_SPECIAL_CHARS));
      ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.application.admin_co_group_id'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.application.admin_co_group_id.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php print $groupField('admin_co_group_id'); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.application.approver_co_group_id'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.application.approver_co_group_id.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php print $groupField('approver_co_group_id'); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.application.approval_required'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.application.approval_required.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->input('approval_required', array('type' => 'checkbox', 'default' => true));
        } else {
          print _txt(!empty($a['approval_required']) ? 'fd.yes' : 'fd.no');
        }
      ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('fd.status'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.status.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($e) {
          print $this->Form->select('status', $vv_status_types,
                                    array('empty' => false, 'default' => AteConfigStatusEnum::Active));

          if($this->Form->isFieldError('status')) {
            print $this->Form->error('status');
          }
        } else {
          print filter_var($vv_status_types[ $a['status'] ], FILTER_SANITIZE_SPECIAL_CHARS);
        }
      ?>
    </div>
  </li>
  <?php if(!empty($a['access_co_group_id'])): ?>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.application.access_co_group_id'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.application.access_co_group_id.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        print $this->Html->link($d['AccessCoGroup']['name'],
                                array('plugin' => null,
                                      'controller' => 'co_groups',
                                      'action' => 'view',
                                      $a['access_co_group_id']));
      ?>
    </div>
  </li>
  <?php endif; ?>
  <?php if($this->action == 'edit'): ?>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.application.teams'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        print $this->Html->link(_txt('op.view-a', array(_txt('pl.applicationteamenroller.application.teams'))),
                                array('plugin' => 'application_team_enroller',
                                      'controller' => 'ate_applications',
                                      'action' => 'view',
                                      $a['id']));
      ?>
    </div>
  </li>
  <?php endif; ?>
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

<?php if($this->action == 'view'): ?>
<h2 class="subtitle"><?php print _txt('pl.applicationteamenroller.application.teams'); ?></h2>
<?php
  if($permissions['edit'] && !empty($vv_available_teams)) {
    print $this->Html->link(_txt('pl.applicationteamenroller.application.teams.add'),
                            array('plugin' => 'application_team_enroller',
                                  'controller' => 'ate_application_teams',
                                  'action' => 'add',
                                  'appid' => $a['id']),
                            array('class' => 'addbutton'));
  } elseif($permissions['edit']) {
    print '<p>' . _txt('pl.applicationteamenroller.application.teams.none_available') . '</p>';
  }
?>
<?php if(empty($vv_application_teams)): ?>
<p><?php print _txt('pl.applicationteamenroller.application.teams.none'); ?></p>
<?php else: ?>
<div class="table-container">
  <table id="ate_application_teams">
    <thead>
      <tr>
        <th><?php print _txt('ct.ate_research_teams.1'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.research_team.co_group_id'); ?></th>
        <th><?php print _txt('fd.status'); ?></th>
        <th><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($vv_application_teams as $m): ?>
      <tr>
        <td>
          <?php
            print $this->Html->link($m['AteResearchTeam']['name'],
                                    array('plugin' => 'application_team_enroller',
                                          'controller' => 'ate_research_teams',
                                          'action' => 'view',
                                          $m['AteResearchTeam']['id']));
          ?>
        </td>
        <td>
          <?php
            if(!empty($m['AteResearchTeam']['CoGroup']['id'])) {
              print $this->Html->link($m['AteResearchTeam']['CoGroup']['name'],
                                      array('plugin' => null,
                                            'controller' => 'co_groups',
                                            'action' => 'view',
                                            $m['AteResearchTeam']['CoGroup']['id']));
            }
          ?>
        </td>
        <td>
          <?php print filter_var($vv_status_types[ $m['AteResearchTeam']['status'] ], FILTER_SANITIZE_SPECIAL_CHARS); ?>
        </td>
        <td>
          <?php
            if($permissions['edit']) {
              print $this->element('buttonToConfirmDialog', array(
                'data' => array(
                  'btnTitle' => _txt('op.remove'),
                  'action' => _txt('op.remove'),
                  'dialogTitle' => _txt('op.remove'),
                  'bodyText' => _txt('js.remove'),
                  'bodyTxtReplacementStrings' => array($m['AteResearchTeam']['name']),
                  'confirmUrl' => array(
                    'plugin' => 'application_team_enroller',
                    'controller' => 'ate_application_teams',
                    'action' => 'delete',
                    $m['AteApplicationTeam']['id']
                  )
                )
              ));
            }
          ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
  print $this->element("changelog");
