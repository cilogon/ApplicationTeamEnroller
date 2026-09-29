<?php
/**
 * COmanage Registry Application Team Enrollers Controller
 *
 * Configuration of the enrollment flow wedge. The wedge has no settings of its
 * own; per-CO settings live in AteSetting.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("SEWController", "Controller");

class ApplicationTeamEnrollersController extends SEWController {
  // Class name, used by Cake
  public $name = "ApplicationTeamEnrollers";

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'co_enrollment_flow_wedge_id' => 'asc'
    )
  );

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

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();

    // Determine what operations this user can perform. Only CO and platform
    // administrators may configure the wedge.
    $admin = ($roles['cmadmin'] || $roles['coadmin']);

    // Delete an existing Application Team Enroller?
    $p['delete'] = $admin;

    // Edit an existing Application Team Enroller?
    $p['edit'] = $admin;

    // View all existing Application Team Enrollers?
    $p['index'] = $admin;

    // View an existing Application Team Enroller?
    $p['view'] = $admin;

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }
}
