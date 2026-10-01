<?php
/**
 * COmanage Registry Application Team Enroller Respond to Invitation
 *
 * Each offered application with its research teams, accepted or declined
 * independently (R13, R19). The form carries only a nonce and the answers;
 * the invitation comes from the session, never from the form (KTD5).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

  // Add page title
  $params = array();
  $params['title'] = $title_for_layout;

  print $this->element("pageTitleAndButtons", $params);

  // Escape for HTML
  $h = function($s) {
    return filter_var((string)$s, FILTER_SANITIZE_SPECIAL_CHARS);
  };

  print $this->Form->create('AteResponse', array(
    'url' => array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_responses',
      'action'     => 'respond'
    ),
    'inputDefaults' => array('label' => false, 'div' => false)
  ));

  print $this->Form->hidden('nonce', array('value' => $vv_nonce));
?>
<p><?php print _txt('pl.applicationteamenroller.response.desc'); ?></p>
<p>
  <?php
    print $h(_txt('pl.applicationteamenroller.response.invited',
                  array($vv_invitation['invited_email'], $vv_invitation['expires'])));
  ?>
</p>

<ul id="respond_ate_invitation" class="fields form-list">
  <?php foreach($vv_requests as $r): ?>
  <li>
    <div class="field-name">
      <div class="field-title"><?php print $h($r['application']); ?></div>
      <div class="field-desc">
        <?php print _txt('pl.applicationteamenroller.fd.request.teams') . ': ' . implode(', ', array_map($h, $r['teams'])); ?>
      </div>
    </div>
    <div class="field-info">
      <?php
        // A newcomer coming back sees their saved draft
        $args = array(
          'type'      => 'radio',
          'legend'    => false,
          'separator' => '<br />',
          'required'  => true,
          'options'   => array(
            '1' => _txt('pl.applicationteamenroller.response.accept'),
            '0' => _txt('pl.applicationteamenroller.response.decline')
          )
        );

        if($r['draft_choice'] !== null) {
          $args['value'] = $r['draft_choice'] ? '1' : '0';
        }

        print $this->Form->input('choices.' . $r['id'], $args);
      ?>
    </div>
  </li>
  <?php endforeach; ?>
  <li class="fields-submit">
    <div class="field-name"></div>
    <div class="field-info">
      <?php print $this->Form->submit(_txt('pl.applicationteamenroller.response.submit')); ?>
    </div>
  </li>
</ul>
<?php
  print $this->Form->end();
