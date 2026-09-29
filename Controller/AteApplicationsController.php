<?php
/**
 * COmanage Registry Application Team Enroller Applications Controller
 *
 * CO administrators create, edit, and retire applications (R1, R4, A4). An
 * application's view page also lists and manages the research teams
 * authorized for it (R3), through AteApplicationTeamsController.
 *
 * Applications are retired rather than deleted, so the requests that name
 * them keep their history.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("StandardController", "Controller");

class AteApplicationsController extends StandardController {
  // Class name, used by Cake
  public $name = "AteApplications";

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'AteApplication.name' => 'asc'
    )
  );

  // This controller needs a CO to be set
  public $requires_co = true;

  // Edit and view (and index, through view_contains) show the group names
  public $edit_contains = array(
    'AdminCoGroup',
    'ApproverCoGroup',
    'AccessCoGroup'
  );

  public $view_contains = array(
    'AdminCoGroup',
    'ApproverCoGroup',
    'AccessCoGroup'
  );

  /**
   * Add an application. The CO comes from the request context, never from
   * the form, and the access group is the plugin's to maintain (KTD10).
   *
   * @since  COmanage Registry v4.6.0
   */

  public function add() {
    if(!$this->request->is('get')) {
      $this->pinPostedFields();
    }

    parent::add();
  }

  /**
   * Callback after controller methods are invoked but before views are rendered.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function beforeRender() {
    parent::beforeRender();

    if(!$this->request->is('restful') && !empty($this->cur_co['Co']['id'])) {
      $coId = $this->cur_co['Co']['id'];

      $this->set('vv_available_groups', $this->AteApplication->availableGroups($coId));
      $this->set('vv_status_types', array(
        AteConfigStatusEnum::Active  => _txt('pl.applicationteamenroller.en.status.active'),
        AteConfigStatusEnum::Retired => _txt('pl.applicationteamenroller.en.status.retired')
      ));

      if(in_array($this->action, array('edit', 'view'), true)
         && !empty($this->request->params['pass'][0])) {
        $appId = (int)$this->request->params['pass'][0];
        $Map = $this->AteApplication->AteApplicationTeam;

        $args = array();
        $args['conditions']['AteApplicationTeam.ate_application_id'] = $appId;
        $args['contain'] = array('AteResearchTeam' => array('CoGroup'));

        $this->set('vv_application_teams', $Map->find('all', $args));
        $this->set('vv_available_teams', $Map->availableTeams($appId));
      }
    }
  }

  /**
   * Edit an application. See add() for the fields the form cannot set.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteApplication ID
   */

  public function edit($id) {
    if(!$this->request->is('get')) {
      $this->pinPostedFields();
    }

    parent::edit($id);
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry v4.6.0
   * @return Boolean True if the current action is permitted
   */

  function isAuthorized() {
    $roles = $this->Role->calculateCMRoles();

    // Only CO and platform administrators configure the plugin (A4)
    $configure = $this->AteAuthz->mayConfigure($roles);

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();

    // Add a new application?
    $p['add'] = $configure;

    // Applications are retired, not deleted (R1)
    $p['delete'] = false;

    // Edit an existing application?
    $p['edit'] = $configure;

    // View all existing applications?
    $p['index'] = $configure;

    // View an existing application? Its page also authorizes and removes
    // research teams, through AteApplicationTeamsController.
    $p['view'] = $configure;

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }

  /**
   * Set the posted fields the form may not choose: the CO is the current CO,
   * and the access group is left as it is.
   *
   * @since  COmanage Registry v4.6.0
   */

  protected function pinPostedFields() {
    $this->request->data['AteApplication']['co_id'] = $this->cur_co['Co']['id'];
    unset($this->request->data['AteApplication']['access_co_group_id']);
  }
}
