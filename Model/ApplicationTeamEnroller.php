<?php
/**
 * COmanage Registry Application Team Enroller Model
 *
 * The plugin's main model. It is both an enroller (the wedge that carries a
 * newcomer through an enrollment flow) and a job (invitation expiry).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

class ApplicationTeamEnroller extends AppModel {
  // Required by COmanage Plugins. Enroller must stay first: Registry's CO
  // duplication reads only the first type (app/Model/AppModel.php).
  public $cmPluginType = array("enroller", "job");

  // Document foreign keys. A CO delete removes the CO's settings and
  // applications (and, through AteApplication, its mapping rows). A CoGroup
  // delete never cascades into research teams: Registry deletes CoGroups
  // softly and leaves the team pointing at the deleted group. The audit
  // models (AteInvitation, AteEnrollmentRequest, AteEnrollmentRequestTeam)
  // are deliberately absent, so no core delete reaches them.
  public $cmPluginHasMany = array(
    "Co" => array("AteSetting", "AteApplication"),
    "CoGroup" => array(
      "AteResearchTeamCoGroup" => array(
        'className' => 'AteResearchTeam',
        'foreignKey' => 'co_group_id',
        'dependent' => false
      )
    )
  );

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array("CoEnrollmentFlowWedge");

  // Default display field for cake generated views
  public $displayField = "co_enrollment_flow_wedge_id";

  // Validation rules for table elements
  public $validate = array(
    'co_enrollment_flow_wedge_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    )
  );

  /**
   * Expose menu items.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array with menu location type as key and array of labels, controllers, actions as values.
   */

  public function cmPluginMenus() {
    // CO configuration screens, for CO administrators (A4)
    return array(
      "coconfig" => array(
        _txt('pl.applicationteamenroller.menu.applications') =>
          array('icon'       => 'apps',
                'controller' => 'ate_applications',
                'action'     => 'index'),
        _txt('pl.applicationteamenroller.menu.research_teams') =>
          array('icon'       => 'group',
                'controller' => 'ate_research_teams',
                'action'     => 'index'),
        _txt('pl.applicationteamenroller.menu.settings') =>
          array('icon'       => 'tune',
                'controller' => 'ate_settings',
                'action'     => 'index')
      ),
      // Invitations, for application administrators (A2) and CO
      // administrators. Registry shows comain entries to every CO member, so
      // AteInvitationsController checks access itself (KTD16).
      "comain" => array(
        _txt('pl.applicationteamenroller.menu.invite') =>
          array('icon'       => 'person_add',
                'controller' => 'ate_invitations',
                'action'     => 'add'),
        _txt('pl.applicationteamenroller.menu.invitations') =>
          array('icon'       => 'mail',
                'controller' => 'ate_invitations',
                'action'     => 'index')
      )
    );
  }

  /**
   * Obtain the list of jobs implemented by this plugin.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Array of job names and help texts
   */

  public function getAvailableJobs() {
    return array();
  }
}
