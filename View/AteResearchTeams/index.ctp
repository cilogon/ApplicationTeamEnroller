<?php
/**
 * COmanage Registry Application Team Enroller Research Teams Index
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

  $this->Html->addCrumb(_txt('ct.ate_research_teams.pl'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  // Add top links
  $params['topLinks'] = array();

  if($permissions['add']) {
    $params['topLinks'][] = $this->Html->link(
      _txt('op.add-a', array(_txt('ct.ate_research_teams.1'))),
      array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_research_teams',
        'action'     => 'add',
        'co'         => $cur_co['Co']['id']
      ),
      array('class' => 'addbutton')
    );
  }

  print $this->element("pageTitleAndButtons", $params);
?>

<div class="table-container">
  <table id="ate_research_teams">
    <thead>
      <tr>
        <th><?php print $this->Paginator->sort('name', _txt('pl.applicationteamenroller.fd.research_team.name')); ?></th>
        <th><?php print _txt('pl.applicationteamenroller.fd.research_team.co_group_id'); ?></th>
        <th><?php print $this->Paginator->sort('status', _txt('fd.status')); ?></th>
        <th><?php print _txt('fd.actions'); ?></th>
      </tr>
    </thead>

    <tbody>
      <?php $i = 0; ?>
      <?php foreach($ate_research_teams as $t): ?>
      <tr class="line<?php print ($i % 2)+1; ?>">
        <td>
          <?php
            print $this->Html->link($t['AteResearchTeam']['name'],
                                    array('plugin' => 'application_team_enroller',
                                          'controller' => 'ate_research_teams',
                                          'action' => 'view',
                                          $t['AteResearchTeam']['id']));
          ?>
        </td>
        <td>
          <?php
            print $this->Html->link($t['CoGroup']['name'],
                                    array('plugin' => null,
                                          'controller' => 'co_groups',
                                          'action' => 'view',
                                          $t['CoGroup']['id']));
          ?>
        </td>
        <td>
          <?php
            print _txt('pl.applicationteamenroller.en.status.'
                       . ($t['AteResearchTeam']['status'] == AteConfigStatusEnum::Retired ? 'retired' : 'active'));
          ?>
        </td>
        <td>
          <?php
            if($permissions['edit']) {
              print $this->Html->link(_txt('op.edit'),
                                      array('plugin' => 'application_team_enroller',
                                            'controller' => 'ate_research_teams',
                                            'action' => 'edit',
                                            $t['AteResearchTeam']['id']),
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
