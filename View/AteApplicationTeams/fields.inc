<!--
/**
 * COmanage Registry Application Team Enroller Application Team Fields
 *
 * Authorize a research team for an application (R3). Only active research
 * teams of the CO not already authorized are offered (R12). Reached from the
 * application's page, which also lists and removes authorized teams.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */
-->
<?php
  // Only add is offered
  if(!$permissions['add'] || empty($vv_application['AteApplication']['id']))
    return false;

  $app = $vv_application['AteApplication'];

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

  $args = array();
  $args['plugin'] = 'application_team_enroller';
  $args['controller'] = 'ate_applications';
  $args['action'] = 'view';
  $args[] = $app['id'];
  $this->Html->addCrumb($app['name'], $args);

  $this->Html->addCrumb(_txt('pl.applicationteamenroller.application.teams.add'));

  print $this->Form->hidden('ate_application_id', array('default' => $app['id'])) . "\n";
?>
<ul id="<?php print $this->action; ?>_ate_application_teams" class="fields form-list form-list-admin">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('ct.ate_applications.1'); ?>
      </div>
    </div>
    <div class="field-info">
      <?php print filter_var($app['name'], FILTER_SANITIZE_SPECIAL_CHARS); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('ct.ate_research_teams.1'); ?>
        <span class="required">*</span>
      </div>
    </div>
    <div class="field-info">
      <?php
        if(empty($vv_available_teams)) {
          print _txt('pl.applicationteamenroller.application.teams.none_available');
        } else {
          print $this->Form->select('ate_research_team_id', $vv_available_teams, array('empty' => true));

          if($this->Form->isFieldError('ate_research_team_id')) {
            print $this->Form->error('ate_research_team_id');
          }
        }
      ?>
    </div>
  </li>
  <?php if(!empty($vv_available_teams)): ?>
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
