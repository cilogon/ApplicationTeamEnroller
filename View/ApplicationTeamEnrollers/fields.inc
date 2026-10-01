<!--
/**
 * COmanage Registry Application Team Enrollers Plugin Fields
 *
 * The wedge has no settings of its own, so this form only places the wedge in
 * its breadcrumb trail and says where the plugin's settings live.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */
-->
<?php
  // We shouldn't get here without at least read permission, but check just in case
  if(!$permissions['edit'] && !$permissions['view'])
    return false;

  $wedge = $application_team_enrollers[0]['CoEnrollmentFlowWedge'];

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_enrollment_flows';
  $args['action'] = 'index';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('ct.co_enrollment_flows.pl'), $args);

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_enrollment_flows';
  $args['action'] = 'edit';
  $args[] = $wedge['co_enrollment_flow_id'];
  $this->Html->addCrumb($wedge['CoEnrollmentFlow']['name'], $args);

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_enrollment_flow_wedges';
  $args['action'] = 'index';
  $args['coef'] = $wedge['co_enrollment_flow_id'];
  $args[] = $wedge['id'];
  $this->Html->addCrumb(_txt('ct.co_enrollment_flow_wedges.pl'), $args);

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_enrollment_flow_wedges';
  $args['action'] = 'edit';
  $args[] = $wedge['id'];
  $this->Html->addCrumb($wedge['description'], $args);

  $this->Html->addCrumb(_txt('op.config'));
?>
<ul id="<?php print $this->action; ?>_application_team_enrollers" class="fields form-list form-list-admin">
  <li>
    <div class="field-info">
      <?php print _txt('pl.applicationteamenroller.wedge.info'); ?>
    </div>
  </li>
</ul>
