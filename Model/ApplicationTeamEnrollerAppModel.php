<?php
/**
 * COmanage Registry Application Team Enroller App Model
 *
 * Base class for the plugin's Ate* models.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('AppModel', 'Model');

class ApplicationTeamEnrollerAppModel extends AppModel {

  /**
   * Validate that a value is one of a plugin enumeration's values.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array  $check     Field being validated
   * @param  String $enumClass Enumeration class with a static $values list
   * @return Boolean           True if the value is in the enumeration
   */

  public function validateEnum($check, $enumClass) {
    $value = reset($check);

    return in_array($value, $enumClass::$values, true);
  }

  /**
   * Validate that no other current row has the same values in $fields.
   *
   * The configuration tables use ChangelogBehavior, which archives an edit as
   * a copy of the row and soft-deletes by flag, so their unique rules cannot
   * be database indexes. Changelog's beforeFind limits this count to current,
   * undeleted rows, and the row being edited is excluded by id.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array  $fields  Column names that together must be unique
   * @param  String $textKey Language key of the error message
   * @return Mixed           True if unique, otherwise the error message
   */

  protected function validateUniqueCurrent($fields, $textKey) {
    $args = array();

    foreach($fields as $f) {
      if(!isset($this->data[$this->alias][$f])) {
        // The field's own rule reports a missing value
        return true;
      }

      $args['conditions'][$this->alias . '.' . $f] = $this->data[$this->alias][$f];
    }

    $id = !empty($this->data[$this->alias]['id']) ? $this->data[$this->alias]['id'] : $this->id;

    if(!empty($id)) {
      $args['conditions'][$this->alias . '.id !='] = $id;
    }

    $args['contain'] = false;

    return ($this->find('count', $args) == 0) ? true : _txt($textKey);
  }
}
