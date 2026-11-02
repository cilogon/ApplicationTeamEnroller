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

App::uses('CakeSession', 'Model/Datasource');

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
    $menus = array(
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
                'action'     => 'index'),
        // Deciders (A3), inviting admins deciding mismatches (A2), and CO
        // administrators (A4). AteEnrollmentRequestsController checks access.
        _txt('pl.applicationteamenroller.menu.queue') =>
          array('icon'       => 'fact_check',
                'controller' => 'ate_enrollment_requests',
                'action'     => 'index')
      )
    );

    // Registry shows comain entries whenever a CO is selected, also to a
    // login in no CO, such as a researcher answering an invitation (issue #3).
    // AteResponsesController also hides them from a member of another CO.
    if(empty(self::loginCoIds())) {
      unset($menus['comain']);
    }

    return $menus;
  }

  /**
   * The COs the current login is a member of, from the session Registry
   * builds at login.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array CO IDs (integers)
   */

  public static function loginCoIds() {
    $ids = array();

    foreach((array)CakeSession::read('Auth.User.cos') as $c) {
      if(!empty($c['co_id'])) {
        $ids[] = (int)$c['co_id'];
      }
    }

    return $ids;
  }

  /**
   * Obtain the list of jobs implemented by this plugin.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Array of job names and help texts
   */

  public function getAvailableJobs() {
    // JobShell runs each as ApplicationTeamEnroller.<name>, the model
    // <name>Job (KTD14)
    return array(
      'ExpireInvitations' => _txt('pl.applicationteamenroller.job.expire_invitations')
    );
  }
}
