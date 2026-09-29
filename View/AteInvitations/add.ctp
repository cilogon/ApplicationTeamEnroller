<?php
/**
 * COmanage Registry Application Team Enroller Compose Invitation
 *
 * Offers only the applications the user may invite for and, for each, only
 * its active mapped research teams (F1, AE1). The controller and model
 * re-check the submission.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add breadcrumbs
  print $this->element("coCrumb");

  if(!empty($permissions['index'])) {
    $args = array();
    $args['plugin'] = 'application_team_enroller';
    $args['controller'] = 'ate_invitations';
    $args['action'] = 'index';
    $args['co'] = $cur_co['Co']['id'];
    $this->Html->addCrumb(_txt('ct.ate_invitations.pl'), $args);
  }

  $this->Html->addCrumb(_txt('pl.applicationteamenroller.invitation.compose'));

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  print $this->element("pageTitleAndButtons", $params);

  if(empty($vv_applications)) {
    print '<div class="co-info-topbox"><em class="material-icons">info</em>'
          . _txt('pl.applicationteamenroller.invitation.compose.none') . '</div>';
    return;
  }

  print $this->Form->create('AteInvitation', array(
    'url' => array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_invitations',
      'action'     => 'add',
      'co'         => $cur_co['Co']['id']
    ),
    'inputDefaults' => array('label' => false, 'div' => false)
  ));
?>
<p><?php print _txt('pl.applicationteamenroller.invitation.compose.desc'); ?></p>

<ul id="add_ate_invitations" class="fields form-list">
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.invitation.invited_email'); ?>
        <span class="required">*</span>
      </div>
    </div>
    <div class="field-info">
      <?php print $this->Form->input('invited_email', array('type' => 'email', 'required' => true)); ?>
    </div>
  </li>
  <li>
    <div class="field-name">
      <div class="field-title">
        <?php print _txt('pl.applicationteamenroller.fd.invitation.applications'); ?>
        <span class="required">*</span>
      </div>
    </div>
    <div class="field-info">
      <?php foreach($vv_applications as $appId => $app): ?>
      <div class="ate-application" data-application="<?php print (int)$appId; ?>">
        <label>
          <?php
            // An application with no team to offer cannot be chosen
            if(!empty($app['teams'])) {
              print $this->Form->checkbox('AteInvitation.application_ids.' . (int)$appId, array(
                'class' => 'ate-application-toggle'
              )) . ' ';
            }
            print filter_var($app['name'], FILTER_SANITIZE_SPECIAL_CHARS);
          ?>
        </label>
        <div class="ate-teams" id="ate-teams-<?php print (int)$appId; ?>">
          <?php
            if(empty($app['teams'])) {
              print '<em>' . _txt('pl.applicationteamenroller.invitation.compose.no_teams') . '</em>';
            } else {
              $teamOptions = array();
              foreach($app['teams'] as $teamId => $teamName) {
                $teamOptions[$teamId] = filter_var($teamName, FILTER_SANITIZE_SPECIAL_CHARS);
              }

              print $this->Form->input('AteInvitation.teams.' . (int)$appId, array(
                'type'     => 'select',
                'multiple' => 'checkbox',
                'options'  => $teamOptions,
                'escape'   => false
              ));
            }
          ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </li>
  <li class="fields-submit">
    <div class="field-name">
      <span class="required"><?php print _txt('fd.req'); ?></span>
    </div>
    <div class="field-info">
      <?php print $this->Form->submit(_txt('pl.applicationteamenroller.invitation.send')); ?>
    </div>
  </li>
</ul>

<?php print $this->Form->end(); ?>

<script type="text/javascript">
  // Show an application's teams only while the application is chosen. The
  // team inputs stay enabled, so the posted fields match the form token;
  // the server ignores teams under an application that is not chosen.
  function ateToggleTeams(box) {
    var appId = $(box).closest('.ate-application').data('application');
    var teams = $('#ate-teams-' + appId);

    if($(box).is(':checked')) {
      teams.show();
    } else {
      teams.hide();
    }
  }

  $(function() {
    $('.ate-application-toggle').each(function() {
      ateToggleTeams(this);
    });

    $('.ate-application-toggle').change(function() {
      ateToggleTeams(this);
    });
  });
</script>
