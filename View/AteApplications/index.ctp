<?php
/**
 * COmanage Registry Application Team Enroller Applications Index
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add breadcrumbs
  print $this->element("coCrumb");

  $args = array();
  $args['plugin'] = null;
  $args['controller'] = 'co_dashboards';
  $args['action'] = 'configuration';
  $args['co'] = $cur_co['Co']['id'];
  $this->Html->addCrumb(_txt('me.configuration'), $args);

  $this->Html->addCrumb(_txt('ct.ate_applications.pl'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  if($permissions['add']) {
    $params['topLinks'][] = $this->Html->link(
      _txt('op.add-a', array(_txt('ct.ate_applications.1'))),
      array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_applications',
        'action'     => 'add',
        'co'         => $cur_co['Co']['id']
      ),
      array('class' => 'addbutton')
    );
  }

  print $this->element("pageTitleAndButtons", $params);
?>

<div class="table-container">
  <table id="ate_applications">
    <thead>
      <tr>
        <th><?php print $this->Paginator->sort('name', _txt('pl.applicationteamenroller.fd.application.name')); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.application.admin_co_group_id'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.application.approver_co_group_id'); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.application.approval_required'); ?></th>
        <th><?php print $this->Paginator->sort('status', _txt('fd.status')); ?></th>
        <th><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>

    <tbody>
      <?php $i = 0; ?>
      <?php foreach($ate_applications as $a): ?>
      <tr class="line<?php print ($i % 2)+1; ?>">
        <td>
          <?php
            print $this->Html->link($a['AteApplication']['name'],
                                    array('plugin' => 'application_team_enroller',
                                          'controller' => 'ate_applications',
                                          'action' => 'view',
                                          $a['AteApplication']['id']));
          ?>
        </td>
        <td><?php print filter_var((string)($a['AdminCoGroup']['name'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS); ?></td>
        <td><?php print filter_var((string)($a['ApproverCoGroup']['name'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS); ?></td>
        <td><?php print _txt(!empty($a['AteApplication']['approval_required']) ? 'fd.yes' : 'fd.no'); ?></td>
        <td>
          <?php
            print _txt('pl.applicationteamenroller.en.status.'
                       . ($a['AteApplication']['status'] == AteConfigStatusEnum::Retired ? 'retired' : 'active'));
          ?>
        </td>
        <td>
          <?php
            print $this->Html->link(_txt('op.view'),
                                    array('plugin' => 'application_team_enroller',
                                          'controller' => 'ate_applications',
                                          'action' => 'view',
                                          $a['AteApplication']['id']),
                                    array('class' => 'viewbutton')) . "\n";

            if($permissions['edit']) {
              print $this->Html->link(_txt('op.edit'),
                                      array('plugin' => 'application_team_enroller',
                                            'controller' => 'ate_applications',
                                            'action' => 'edit',
                                            $a['AteApplication']['id']),
                                      array('class' => 'editbutton')) . "\n";
            }
          ?>
        </td>
      </tr>
      <?php $i++; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
  print $this->element("pagination");
