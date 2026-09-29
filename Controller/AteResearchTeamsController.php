<?php
/**
 * COmanage Registry Application Team Enroller Research Teams Controller
 *
 * CO administrators designate existing CoGroups as research teams and retire
 * them (R2, R12, A4). A team keeps the group it was designated with, and
 * membership is only ever CoGroupMember rows on that group.
 *
 * Research teams are retired rather than deleted, so the requests that
 * offered them keep their history.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses("StandardController", "Controller");

class AteResearchTeamsController extends StandardController {
  // Class name, used by Cake
  public $name = "AteResearchTeams";

  // The plugin's authorization component (U3)
  public $components = array('ApplicationTeamEnroller.AteAuthz');

  // Establish pagination parameters for HTML views
  public $paginate = array(
    'limit' => 25,
    'order' => array(
      'AteResearchTeam.name' => 'asc'
    )
  );

  // This controller needs a CO to be set
  public $requires_co = true;

  // Edit and view show the group name
  public $edit_contains = array(
    'CoGroup'
  );

  public $view_contains = array(
    'CoGroup'
  );

  /**
   * Callback after controller methods are invoked but before views are rendered.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function beforeRender() {
    parent::beforeRender();

    if(!$this->request->is('restful') && !empty($this->cur_co['Co']['id'])) {
      $this->set('vv_available_groups', $this->AteResearchTeam->availableGroups($this->cur_co['Co']['id']));
      $this->set('vv_status_types', array(
        AteConfigStatusEnum::Active  => _txt('pl.applicationteamenroller.en.status.active'),
        AteConfigStatusEnum::Retired => _txt('pl.applicationteamenroller.en.status.retired')
      ));
    }
  }

  /**
   * Determine the CO ID based on some attribute of the request.
   *
   * A research team row carries no co_id, so Registry cannot take the CO
   * from the form on add. The CO configuration menu links index with the CO,
   * and index links add the same way. Other actions find the CO through the
   * team's group (AteResearchTeam::findCoForRecord()).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $data Request data
   * @return Integer CO ID, or null if not implemented or not applicable.
   * @throws InvalidArgumentException
   */

  protected function calculateImpliedCoId($data = null) {
    if(in_array($this->action, array('add', 'index'), true)
       && !empty($this->request->params['named']['co'])) {
      return $this->request->params['named']['co'];
    }

    return parent::calculateImpliedCoId($data);
  }

  /**
   * Perform any dependency checks required prior to a write (add/edit) operation.
   * On add, the group must be one the picker offers (R12), whatever was
   * posted.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $reqdata Request data
   * @param  Array $curdata Current data, on edit
   * @return Boolean True if the write may proceed
   */

  function checkWriteDependencies($reqdata, $curdata = null) {
    if(empty($curdata)) {
      $groupId = isset($reqdata['AteResearchTeam']['co_group_id'])
                 ? $reqdata['AteResearchTeam']['co_group_id']
                 : null;

      if(!$this->AteResearchTeam->isEligibleGroup($this->cur_co['Co']['id'], $groupId)) {
        $this->Flash->set(_txt('pl.applicationteamenroller.er.research_team.group'), array('key' => 'error'));
        return false;
      }
    }

    return true;
  }

  /**
   * Edit a research team. Its group cannot change (R2): whatever group was
   * posted, the team keeps the one it has.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteResearchTeam ID
   */

  public function edit($id) {
    if(!$this->request->is('get')) {
      $this->request->data['AteResearchTeam']['co_group_id'] =
        $this->AteResearchTeam->field('co_group_id', array('AteResearchTeam.id' => $id));
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

    // Designate a group as a research team?
    $p['add'] = $configure;

    // Research teams are retired, not deleted
    $p['delete'] = false;

    // Edit an existing research team?
    $p['edit'] = $configure;

    // View all existing research teams?
    $p['index'] = $configure;

    // View an existing research team?
    $p['view'] = $configure;

    $this->set('permissions', $p);

    // An action not listed above is denied.
    return !empty($p[$this->action]);
  }

  /**
   * Determine the conditions for pagination of the index view, when rendered via the UI.
   * A team belongs to the CO of its group.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array An array suitable for use in $this->paginate
   */

  public function paginationConditions() {
    $ret = array();
    $ret['conditions']['CoGroup.co_id'] = $this->cur_co['Co']['id'];
    $ret['contain'] = array('CoGroup');

    return $ret;
  }
}
