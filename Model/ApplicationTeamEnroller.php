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

  // Document foreign keys
  public $cmPluginHasMany = array();

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
    return array();
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
