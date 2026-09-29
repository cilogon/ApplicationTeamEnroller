<?php
/**
 * COmanage Registry Application Team Enroller Application Teams Controller
 *
 * Authorizes research teams for an application and removes them again (R3,
 * R12). There is no list of its own: the application's view page lists the
 * mapping and links here, and every action returns there.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("StandardController", "Controller");

class AteApplicationTeamsController extends StandardController {
  // Class name, used by Cake
  public $name = "AteApplicationTeams";

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // This controller needs a CO to be set
  public $requires_co = true;

  // The application the current action works on, for performRedirect()
  protected $appId = null;

  /**
   * Callback after controller methods are invoked but before views are rendered.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function beforeRender() {
    parent::beforeRender();

    if(!$this->request->is('restful') && $this->action == 'add') {
      $appId = $this->requestedApplicationId();

      $args = array();
      $args['conditions']['AteApplication.id'] = $appId;
      $args['conditions']['AteApplication.co_id'] = $this->cur_co['Co']['id'];
      $args['contain'] = false;

      $this->set('vv_application', $this->AteApplicationTeam->AteApplication->find('first', $args));
      $this->set('vv_available_teams', $this->AteApplicationTeam->availableTeams($appId));
    }
  }

  /**
   * Determine the CO ID based on some attribute of the request.
   *
   * On add, the CO is the application's: from the posted form, or from the
   * appid named parameter the application's page links with. On delete, it
   * is found through the mapping row (AteApplicationTeam::findCoForRecord()).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $data Request data
   * @return Integer CO ID, or null if not implemented or not applicable.
   * @throws InvalidArgumentException
   */

  protected function calculateImpliedCoId($data = null) {
    if($this->action == 'add') {
      $appId = $this->requestedApplicationId();

      if($appId) {
        $coId = $this->AteApplicationTeam->AteApplication->field('co_id', array('AteApplication.id' => $appId));

        if(!$coId) {
          throw new InvalidArgumentException(_txt('er.notfound', array(_txt('ct.ate_applications.1'), $appId)));
        }

        return $coId;
      }
    }

    return parent::calculateImpliedCoId($data);
  }

  /**
   * Perform any dependency checks required prior to a delete operation.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $curdata Current data
   * @return Boolean True if the delete may proceed
   */

  function checkDeleteDependencies($curdata) {
    $this->appId = (int)$curdata['AteApplicationTeam']['ate_application_id'];

    return true;
  }

  /**
   * Perform any dependency checks required prior to a write (add/edit) operation.
   * The application must belong to the current CO; the model checks the team.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $reqdata Request data
   * @param  Array $curdata Current data, on edit
   * @return Boolean True if the write may proceed
   */

  function checkWriteDependencies($reqdata, $curdata = null) {
    $appId = isset($reqdata['AteApplicationTeam']['ate_application_id'])
             ? (int)$reqdata['AteApplicationTeam']['ate_application_id']
             : 0;

    $coId = $appId
            ? $this->AteApplicationTeam->AteApplication->field('co_id', array('AteApplication.id' => $appId))
            : null;

    if(!$coId || (int)$coId !== (int)$this->cur_co['Co']['id']) {
      $this->Flash->set(_txt('pl.applicationteamenroller.er.application_team.application'), array('key' => 'error'));
      return false;
    }

    $this->appId = $appId;

    return true;
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

    // Authorize a research team for an application?
    $p['add'] = $configure;

    // Remove a research team from an application?
    $p['delete'] = $configure;

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }

  /**
   * Redirect to the application's page, which lists the mapping.
   * - postcondition: Redirect generated
   *
   * @since  COmanage Registry v4.6.0
   */

  function performRedirect() {
    $appId = $this->appId ?: $this->requestedApplicationId();

    if($appId) {
      $this->redirect(array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_applications',
        'action'     => 'view',
        $appId
      ));
    }

    $this->redirect(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_applications',
      'action'     => 'index',
      'co'         => $this->cur_co['Co']['id']
    ));
  }

  /**
   * The application an add request is for: the posted form's, or else the
   * appid named parameter.
   *
   * @since  COmanage Registry v4.6.0
   * @return Integer AteApplication ID, or 0 if none was given
   */

  protected function requestedApplicationId() {
    if(!empty($this->request->data['AteApplicationTeam']['ate_application_id'])) {
      return (int)$this->request->data['AteApplicationTeam']['ate_application_id'];
    }

    if(!empty($this->request->params['named']['appid'])) {
      return (int)$this->request->params['named']['appid'];
    }

    return 0;
  }
}
