<?php
/**
 * COmanage Registry Application Team Enroller Application Model
 *
 * An application researchers are invited to (R1, R4). Its admin and approver
 * groups are existing CO groups the plugin only references; its access group
 * is created and maintained by the plugin (KTD10, R31).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('ApplicationTeamEnrollerAppModel', 'ApplicationTeamEnroller.Model');

class AteApplication extends ApplicationTeamEnrollerAppModel {
  // Define class name for cake
  public $name = "AteApplication";

  // Add behaviors
  public $actsAs = array('Containable', 'Changelog' => array('priority' => 5));

  // Association rules from this model to other models
  public $belongsTo = array(
    "Co",
    "AdminCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'admin_co_group_id'
    ),
    "ApproverCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'approver_co_group_id'
    ),
    "AccessCoGroup" => array(
      'className' => 'CoGroup',
      'foreignKey' => 'access_co_group_id'
    )
  );

  public $hasMany = array(
    // Mapping rows are configuration and go with the application
    "AteApplicationTeam" => array(
      'className' => 'ApplicationTeamEnroller.AteApplicationTeam',
      'foreignKey' => 'ate_application_id',
      'dependent' => true
    ),
    // Requests are audit history and are never deleted with the application
    "AteEnrollmentRequest" => array(
      'className' => 'ApplicationTeamEnroller.AteEnrollmentRequest',
      'foreignKey' => 'ate_application_id',
      'dependent' => false
    )
  );

  // Default display field for cake generated views
  public $displayField = "name";

  // Validation rules for table elements
  public $validate = array(
    'co_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'allowEmpty' => false
    ),
    'name' => array(
      'rule' => array('validateInput'),
      'required' => true,
      'allowEmpty' => false
    ),
    'client_identifier' => array(
      'rule' => array('validateInput'),
      'required' => false,
      'allowEmpty' => true
    ),
    // The admin and approver groups are existing groups of the
    // application's CO, and may be the same group (R4)
    'admin_co_group_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false,
        'last' => true
      ),
      'co' => array(
        'rule' => array('validateCoGroup')
      )
    ),
    'approver_co_group_id' => array(
      'numeric' => array(
        'rule' => 'numeric',
        'required' => true,
        'allowEmpty' => false,
        'last' => true
      ),
      'co' => array(
        'rule' => array('validateCoGroup')
      )
    ),
    'access_co_group_id' => array(
      'rule' => 'numeric',
      'required' => false,
      'allowEmpty' => true
    ),
    'approval_required' => array(
      'rule' => 'boolean',
      'required' => false,
      'allowEmpty' => true
    ),
    'status' => array(
      'rule' => array('validateEnum', 'AteConfigStatusEnum'),
      'required' => true,
      'allowEmpty' => false
    )
  );

  /**
   * Callback before a save: a new application gets its access group (KTD10).
   *
   * ChangelogBehavior (priority 5) has already opened its transaction when
   * this runs, and a save through saveAll() (StandardController::add) runs
   * inside saveAll's own, so the group and the application are written
   * together. Validation has already passed, so a rejected application
   * creates no group. A posted access group is never used: the plugin always
   * creates its own.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array   $options Save options
   * @return Boolean          True to continue the save
   * @throws Exception        If the access group cannot be created
   */

  public function beforeSave($options = array()) {
    // An add, by the same test ChangelogBehavior uses
    if(empty($this->data[$this->alias]['id'])) {
      try {
        $this->data[$this->alias]['access_co_group_id'] =
          $this->createAccessGroup($this->data[$this->alias]['co_id'],
                                   $this->data[$this->alias]['name']);
      }
      catch(Exception $e) {
        // Close the transaction ChangelogBehavior opened (CO-2829)
        $this->abortChangelogTxn();
        throw $e;
      }
    }

    return parent::beforeSave($options);
  }

  /**
   * Create an application's access group: a Standard, non-open, non-automatic,
   * active group with any-of nesting, named after the application (KTD10).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId    CO ID
   * @param  String  $appName Application name
   * @return Integer          CoGroup ID of the new access group
   * @throws RuntimeException If the group cannot be saved
   */

  public function createAccessGroup($coId, $appName) {
    $CoGroup = ClassRegistry::init('CoGroup');
    $name = $this->accessGroupName($coId, $appName);

    $data = array(
      'CoGroup' => array(
        'co_id'            => $coId,
        'name'             => $name,
        'description'      => mb_substr(_txt('pl.applicationteamenroller.access_group.desc', array($appName)), 0, 256),
        'open'             => false,
        'status'           => SuspendableStatusEnum::Active,
        'group_type'       => GroupEnum::Standard,
        'auto'             => false,
        'nesting_mode_all' => false
      )
    );

    $CoGroup->clear();

    if(!$CoGroup->save($data)) {
      throw new RuntimeException(_txt('er.db.save-a', array('CoGroup ' . $name)));
    }

    return (int)$CoGroup->id;
  }

  /**
   * A name for a new access group: the application's name, or that name with
   * the lowest free numeric suffix (-2, -3, ...) if a current group of the
   * CO already has it. Registry requires group names to be unique per CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId    CO ID
   * @param  String  $appName Application name
   * @return String           Group name not in use in the CO
   * @throws RuntimeException If no free name is found
   */

  public function accessGroupName($coId, $appName) {
    // cm_co_groups.name holds 128 characters; leave room for a suffix
    $base = mb_substr(trim((string)$appName), 0, 120);
    $CoGroup = ClassRegistry::init('CoGroup');

    for($i = 1; $i <= 1000; $i++) {
      $name = ($i == 1) ? $base : $base . '-' . $i;

      $args = array();
      $args['conditions']['CoGroup.co_id'] = $coId;
      $args['conditions']['CoGroup.name'] = $name;
      $args['contain'] = false;

      if($CoGroup->find('count', $args) == 0) {
        return $name;
      }
    }

    throw new RuntimeException(_txt('er.gr.exists', array($base)));
  }

  /**
   * Bring the nestings of an application's access group in line with its
   * application-to-team mapping (KTD10), for the given team groups or, with
   * no list, for every group nested into the access group or mapped to the
   * application.
   *
   * A group should be nested (not negated) exactly when a current mapping
   * row authorizes a current research team whose group is a current CoGroup.
   * Retired applications and teams count: retiring leaves nestings in place.
   * Registry's CoGroupNesting callbacks reconcile the derived memberships.
   *
   * Nothing is done if the application has no current access group.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $appId    AteApplication ID
   * @param  Array   $groupIds CoGroup IDs to check, or null for all
   * @return Array             'added' and 'removed': lists of CoGroup IDs
   */

  public function syncAccessGroupNestings($appId, $groupIds = null) {
    $ret = array('added' => array(), 'removed' => array());

    $accessId = $this->currentAccessGroupId($appId);

    if(!$accessId) {
      return $ret;
    }

    $wanted = $this->mappedTeamGroupIds($appId);
    $Nesting = ClassRegistry::init('CoGroupNesting');

    // Changelog limits this search to current nestings
    $args = array();
    $args['conditions']['CoGroupNesting.target_co_group_id'] = $accessId;
    if($groupIds !== null) {
      $args['conditions']['CoGroupNesting.co_group_id'] = array_map('intval', $groupIds);
    }
    $args['order'] = array('CoGroupNesting.id' => 'asc');
    $args['contain'] = false;

    $kept = array();

    foreach($Nesting->find('all', $args) as $n) {
      $gid = (int)$n['CoGroupNesting']['co_group_id'];

      if(in_array($gid, $wanted, true) && empty($n['CoGroupNesting']['negate']) && !isset($kept[$gid])) {
        $kept[$gid] = true;
        continue;
      }

      // Not mapped, negated, or a duplicate
      $Nesting->clear();
      if(!$Nesting->delete($n['CoGroupNesting']['id'])) {
        throw new RuntimeException(_txt('er.delete'));
      }

      $ret['removed'][] = $gid;
    }

    $check = ($groupIds === null) ? $wanted : array_intersect($wanted, array_map('intval', $groupIds));

    foreach($check as $gid) {
      if(isset($kept[$gid])) {
        continue;
      }

      $Nesting->clear();

      $data = array(
        'CoGroupNesting' => array(
          'co_group_id'        => $gid,
          'target_co_group_id' => $accessId,
          'negate'             => false
        )
      );

      if(!$Nesting->save($data)) {
        throw new RuntimeException(_txt('er.db.save-a', array('CoGroupNesting')));
      }

      $ret['added'][] = $gid;
    }

    $ret['removed'] = array_values(array_unique($ret['removed']));

    return $ret;
  }

  /**
   * Repair an application's access group (KTD10): create it if the
   * application has none, then add missing nestings and remove extra ones.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $appId AteApplication ID
   * @return Array          'created' (Boolean), 'added' and 'removed' (CoGroup IDs)
   * @throws InvalidArgumentException If the application does not exist
   * @throws RuntimeException         If the access group was deleted
   */

  public function resyncAccessGroup($appId) {
    $app = $this->find('first', array(
      'conditions' => array('AteApplication.id' => $appId),
      'fields' => array('AteApplication.id', 'AteApplication.co_id', 'AteApplication.name',
                        'AteApplication.access_co_group_id', 'AteApplication.deleted',
                        'AteApplication.ate_application_id'),
      'contain' => false
    ));

    if(empty($app['AteApplication']['id'])
       || !empty($app['AteApplication']['deleted'])
       || !empty($app['AteApplication']['ate_application_id'])) {
      throw new InvalidArgumentException(_txt('er.notfound', array(_txt('ct.ate_applications.1'), $appId)));
    }

    $created = false;

    if(empty($app['AteApplication']['access_co_group_id'])) {
      $dataSource = $this->getDataSource();
      $dataSource->begin();

      try {
        $accessId = $this->createAccessGroup($app['AteApplication']['co_id'], $app['AteApplication']['name']);

        $this->clear();
        $data = array('AteApplication' => array('id' => $appId, 'access_co_group_id' => $accessId));
        if(!$this->save($data, array('validate' => false))) {
          throw new RuntimeException(_txt('er.db.save-a', array('AteApplication')));
        }
      }
      catch(Exception $e) {
        $dataSource->rollback();
        throw $e;
      }

      $dataSource->commit();
      $created = true;
    } elseif(!$this->currentAccessGroupId($appId)) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.access_group.deleted'));
    }

    return array('created' => $created) + $this->syncAccessGroupNestings($appId);
  }

  /**
   * The access group of an application, if it is a current CoGroup.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $appId AteApplication ID
   * @return Integer        CoGroup ID, or null if none or not current
   */

  public function currentAccessGroupId($appId) {
    $accessId = $this->field('access_co_group_id', array('AteApplication.id' => $appId));

    if(!$accessId) {
      return null;
    }

    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived groups here.
    $args = array();
    $args['conditions']['AccessCoGroup.id'] = $accessId;
    $args['conditions'][] = self::currentRowConditions('AccessCoGroup', 'co_group_id');
    $args['contain'] = false;

    return ($this->AccessCoGroup->find('count', $args) > 0) ? (int)$accessId : null;
  }

  /**
   * The CoGroups an application's access group should nest: the groups of
   * the research teams its current mapping rows authorize, where both the
   * team and its group are current. Team and application status do not
   * matter (KTD10).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $appId AteApplication ID
   * @return Array          CoGroup IDs
   */

  public function mappedTeamGroupIds($appId) {
    // Changelog limits this search to current mapping rows
    $args = array();
    $args['conditions']['AteApplicationTeam.ate_application_id'] = $appId;
    $args['fields'] = array('AteApplicationTeam.id', 'AteApplicationTeam.ate_research_team_id');
    $args['contain'] = false;

    $teamIds = array_map('intval', array_values($this->AteApplicationTeam->find('list', $args)));

    if(empty($teamIds)) {
      return array();
    }

    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived teams and groups here.
    $Team = $this->AteApplicationTeam->AteResearchTeam;

    $args = array();
    $args['conditions']['AteResearchTeam.id'] = $teamIds;
    $args['conditions'][] = self::currentRowConditions('AteResearchTeam', 'ate_research_team_id');
    $args['fields'] = array('AteResearchTeam.id', 'AteResearchTeam.co_group_id');
    $args['contain'] = false;

    $groupIds = array_map('intval', array_values($Team->find('list', $args)));

    if(empty($groupIds)) {
      return array();
    }

    $args = array();
    $args['conditions']['CoGroup.id'] = $groupIds;
    $args['conditions'][] = self::currentRowConditions('CoGroup', 'co_group_id');
    $args['fields'] = array('CoGroup.id', 'CoGroup.id');
    $args['order'] = array('CoGroup.id' => 'asc');
    $args['contain'] = false;

    return array_map('intval', array_keys($Team->CoGroup->find('list', $args)));
  }

  /**
   * The groups of a CO, for the admin and approver group pickers.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $coId CO ID
   * @return Array         Group names, keyed by CoGroup ID
   */

  public function availableGroups($coId) {
    $args = array();
    $args['conditions']['AdminCoGroup.co_id'] = $coId;
    $args['order'] = 'AdminCoGroup.name ASC';
    $args['contain'] = false;

    return $this->AdminCoGroup->find('list', $args);
  }

  /**
   * Validate that a group is a current group of the application's CO.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $check Field being validated
   * @return Mixed        True if valid, otherwise an error message
   */

  public function validateCoGroup($check) {
    $coId = $this->validationCoId();

    // Changelog does not filter a lookup by id, so exclude deleted and
    // archived groups here.
    $args = array();
    $args['conditions']['AdminCoGroup.id'] = reset($check);
    $args['conditions']['AdminCoGroup.co_id'] = $coId;
    $args['conditions'][] = self::currentRowConditions('AdminCoGroup', 'co_group_id');
    $args['contain'] = false;

    if(empty($coId) || $this->AdminCoGroup->find('count', $args) < 1) {
      return _txt('pl.applicationteamenroller.er.application.group');
    }

    return true;
  }
}
