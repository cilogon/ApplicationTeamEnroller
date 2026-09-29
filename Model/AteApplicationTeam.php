<?php
/**
 * COmanage Registry Application Team Enroller Application Team Model
 *
 * Authorizes a research team for an application (R3). It controls which
 * teams an admin may offer and which team groups are nested into the
 * application's access group (R31). It grants no membership to anyone.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteApplicationTeam extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteApplicationTeam";

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array(
    "AteApplication" => array(
      'className' => 'ApplicationTeamEnroller.AteApplication',
      'foreignKey' => 'ate_application_id'
    ),
    "AteResearchTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteResearchTeam',
      'foreignKey' => 'ate_research_team_id'
    )
  );

  // Default display field for cake generated views
  public $displayField = "ate_research_team_id";

  // Validation rules for table elements
  public $validate = array(
    'ate_application_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'ate_research_team_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false
      ),
      'unique' => array(
        'rule' => array('validateUniqueMapping')
      ),
      // Only an active research team of the application's CO (R12)
      'team' => array(
        'rule' => array('validateTeamForApplication')
      )
    )
  );

  // The row as it was before an edit, for afterSave()
  protected $priorMapping = null;

  /**
   * Callback after a delete: the team's group leaves the application's
   * access group (KTD10). ChangelogBehavior only flags the row as deleted,
   * and AppModel::delete() fires this callback after it, so the row can still
   * be read here.
   *
   * @since  COmanage Registry v4.6.0
   */

  public function afterDelete() {
    $this->syncNestingFor($this->mappingRow($this->id));
  }

  /**
   * Callback after a save: nest the team's group into the application's
   * access group (KTD10). An edit leaves an archived copy of the old row
   * behind, so the old team's nesting is checked against the current
   * mapping as well.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Boolean $created True if a new row was saved
   * @param  Array   $options Save options
   * @return Boolean          True
   */

  public function afterSave($created, $options = array()) {
    $current = $this->mappingRow($this->id);

    $this->syncNestingFor($current);

    if(!empty($this->priorMapping) && $this->priorMapping != $current) {
      $this->syncNestingFor($this->priorMapping);
    }

    $this->priorMapping = null;

    return true;
  }

  /**
   * Callback before a save: on an edit, remember the row as it was.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $options Save options
   * @return Boolean          True to continue the save
   */

  public function beforeSave($options = array()) {
    $this->priorMapping = !empty($this->data[$this->alias]['id'])
                          ? $this->mappingRow($this->data[$this->alias]['id'])
                          : null;

    return parent::beforeSave($options);
  }

  /**
   * The research teams that may be authorized for an application: active
   * teams whose group is a current group of the application's CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $applicationId AteApplication ID
   * @return Array                  Team names, keyed by AteResearchTeam ID
   */

  public function eligibleTeams($applicationId) {
    $coId = $this->AteApplication->field('co_id', array('AteApplication.id' => $applicationId));

    if(!$coId) {
      return array();
    }

    $args = array();
    $args['conditions']['AteResearchTeam.status'] = AteConfigStatusEnum::Active;
    $args['conditions']['CoGroup.co_id'] = $coId;
    $args['conditions']['CoGroup.co_group_id'] = null;
    $args['conditions'][] = 'CoGroup.deleted IS NOT true';
    $args['order'] = 'AteResearchTeam.name ASC';
    $args['contain'] = array('CoGroup');

    $ret = array();

    foreach($this->AteResearchTeam->find('all', $args) as $t) {
      $ret[ (int)$t['AteResearchTeam']['id'] ] = !empty($t['AteResearchTeam']['name'])
                                                 ? $t['AteResearchTeam']['name']
                                                 : $t['CoGroup']['name'];
    }

    return $ret;
  }

  /**
   * The research teams the mapping screen offers for an application: the
   * eligible teams not already authorized for it.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $applicationId AteApplication ID
   * @return Array                  Team names, keyed by AteResearchTeam ID
   */

  public function availableTeams($applicationId) {
    $args = array();
    $args['conditions']['AteApplicationTeam.ate_application_id'] = $applicationId;
    $args['fields'] = array('AteApplicationTeam.id', 'AteApplicationTeam.ate_research_team_id');
    $args['contain'] = false;

    $mapped = array_map('intval', array_values($this->find('list', $args)));

    return array_diff_key($this->eligibleTeams($applicationId), array_flip($mapped));
  }

  /**
   * Find the CO of a mapping row, through its application.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteApplicationTeam ID
   * @return Integer     CO ID
   * @throws InvalidArgumentException If the row or its application does not exist
   */

  public function findCoForRecord($id) {
    $appId = $this->field('ate_application_id', array('AteApplicationTeam.id' => $id));
    $coId = $appId ? $this->AteApplication->field('co_id', array('AteApplication.id' => $appId)) : null;

    if(!$coId) {
      throw new InvalidArgumentException(_txt('er.notfound', array(_txt('ct.ate_application_teams.1'), $id)));
    }

    return $coId;
  }

  /**
   * A mapping row's application and its team's CoGroup, read directly so a
   * deleted or archived row can still be read.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id AteApplicationTeam ID
   * @return Array       'app' and 'group' IDs, or null if not found
   */

  protected function mappingRow($id) {
    if(empty($id)) {
      return null;
    }

    $row = $this->find('first', array(
      'conditions' => array('AteApplicationTeam.id' => $id),
      'fields' => array('AteApplicationTeam.ate_application_id', 'AteApplicationTeam.ate_research_team_id'),
      'callbacks' => false,
      'recursive' => -1
    ));

    if(empty($row['AteApplicationTeam']['ate_application_id'])) {
      return null;
    }

    $groupId = $this->AteResearchTeam->field('co_group_id',
                                             array('AteResearchTeam.id' => $row['AteApplicationTeam']['ate_research_team_id']));

    return array(
      'app'   => (int)$row['AteApplicationTeam']['ate_application_id'],
      'group' => (int)$groupId
    );
  }

  /**
   * Bring the nesting of one team group in one application's access group in
   * line with the current mapping.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $mapping As returned by mappingRow(), or null
   */

  protected function syncNestingFor($mapping) {
    if(!empty($mapping['app']) && !empty($mapping['group'])) {
      $this->AteApplication->syncAccessGroupNestings($mapping['app'], array($mapping['group']));
    }
  }

  /**
   * Validate that the team may be authorized for the application.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateTeamForApplication($check) {
    $appId = isset($this->data[$this->alias]['ate_application_id'])
             ? $this->data[$this->alias]['ate_application_id']
             : null;

    if(!array_key_exists((int)reset($check), $this->eligibleTeams($appId))) {
      return _txt('pl.applicationteamenroller.er.application_team.team');
    }

    return true;
  }

  /**
   * Validate that the team is not already authorized for the application.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateUniqueMapping($check) {
    return $this->validateUniqueCurrent(array('ate_application_id', 'ate_research_team_id'),
                                        'pl.applicationteamenroller.er.application_team.unique');
  }
}
