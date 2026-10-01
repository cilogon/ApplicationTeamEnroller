<!--
/**
 * COmanage Registry Application Team Enroller Research Team Fields
 *
 * Designating a group as a research team (R2, R12). The group is chosen on
 * add from the groups that may be teams, and shown read-only after that. The
 * edit form posts the team's id, so a save updates the row rather than
 * inserting another.
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

  $d = !empty($ate_research_teams[0]) ? $ate_research_teams[0] : array();
  $t = !empty($d['AteResearchTeam']) ? $d['AteResearchTeam'] : array();

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
  $args['controller'] = 'ate_research_teams';
  $args['action'] = 'index';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('ct.ate_research_teams.pl'), $args);

  $this->Html->addCrumb(_txt('op.' . $this->action . '-a', array(_txt('ct.ate_research_teams.1'))));

  if($e && $this->action == 'edit') {
    print $this->Form->hidden('id', array('default' => $t['id'])) . "\n";
  }
?>
<ul id="<?php print $this->action; ?>_ate_research_teams" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.research_team.co_group_id'); ?>
        <span class="required">*</span>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.research_team.co_group_id.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        if($this->action == 'add') {
          if(empty($vv_available_groups)) {
            print _txt('pl.applicationteamenroller.research_team.none_available');
          } else {
            print $this->Form->select('co_group_id', $vv_available_groups, array('empty' => true));

            if($this->Form->isFieldError('co_group_id')) {
              print $this->Form->error('co_group_id');
            }
          }
        } elseif(!empty($d['CoGroup']['id'])) {
          print $this->Html->link($d['CoGroup']['name'],
                                  array('plugin' => null,
                                        'controller' => 'co_groups',
                                        'action' => 'view',
                                        $d['CoGroup']['id']));
        }
      ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.research_team.name'); ?>
      </div>
      <div class="field-desc"><?php print _txt('pl.applicationteamenroller.fd.research_team.name.desc'); ?></div>
    </div>
    <div class="field-info">
      <?php
        print ($e
               ? $this->Form->input('name')
               : filter_var((string)$t['name'], FILTER_SANITIZE_SPECIAL_CHARS));
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
          print filter_var($vv_status_types[ $t['status'] ], FILTER_SANITIZE_SPECIAL_CHARS);
        }
      ?>
    </div>
  </li>
  <?php if($e && ($this->action == 'edit' || !empty($vv_available_groups))): ?>
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
