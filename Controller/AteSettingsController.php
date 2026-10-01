<?php
/**
 * COmanage Registry Application Team Enroller Settings Controller
 *
 * Per-CO plugin settings (R5, KTD2). index creates the CO's row with its
 * defaults on first visit and sends the administrator to edit it
 * (SponsorManager pattern). Saving validates the newcomer enrollment flow
 * (AteSetting::newcomerFlowProblem()).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("StandardController", "Controller");

class AteSettingsController extends StandardController {
  // Class name, used by Cake
  public $name = "AteSettings";

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'co_id' => 'asc'
    )
  );

  // This controller needs a CO to be set
  public $requires_co = true;

  /**
   * Callback after controller methods are invoked but before views are rendered.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function beforeRender() {
    parent::beforeRender();

    if(!$this->request->is('restful') && !empty($this->cur_co['Co']['id'])) {
      $coId = $this->cur_co['Co']['id'];

      $this->set('vv_available_flows', $this->AteSetting->availableFlows($coId));
      $this->set('vv_identifier_types', $this->AteSetting->Co->CoPerson->Identifier->types($coId, 'type'));
    }
  }

  /**
   * Edit the CO's settings. The CO comes from the settings row, never from
   * the form.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteSetting ID
   */

  public function edit($id) {
    if(!$this->request->is('get')) {
      $this->request->data['AteSetting']['co_id'] = $this->cur_co['Co']['id'];
    }

    parent::edit($id);
  }

  /**
   * Obtain the CO's settings, creating them with defaults on first visit,
   * and redirect to edit them.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function index() {
    $row = $this->AteSetting->getOrCreateForCo($this->cur_co['Co']['id']);

    $this->redirect(array(
      'action' => 'edit',
      $row['AteSetting']['id']
    ));
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

    // Edit the CO's settings?
    $p['edit'] = $configure;

    // Open the CO's settings (index redirects to edit)?
    $p['index'] = $configure;

    // View the CO's settings?
    $p['view'] = $configure;

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }
}
