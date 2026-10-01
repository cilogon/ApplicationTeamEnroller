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
   * One conditional UPDATE (KTD8): set $fields on the rows of $Model that
   * match $conditions, which must name only $Model's own columns so Cake
   * issues a single UPDATE rather than a SELECT first.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Model   $Model      Model to update
   * @param  Array   $fields     Column => PHP value (null, Boolean, Integer, or String)
   * @param  Array   $conditions Column => value
   * @return Boolean             True if exactly one row changed
   * @throws RuntimeException If the update fails
   */

  protected function conditionalUpdate($Model, $fields, $conditions) {
    $dbc = $Model->getDataSource();
    $set = array();

    foreach($fields as $col => $v) {
      if($v === null) {
        $set[$col] = 'NULL';
      } elseif(is_bool($v)) {
        $set[$col] = $dbc->value($v, 'boolean');
      } elseif(is_int($v)) {
        $set[$col] = $v;
      } else {
        $set[$col] = $dbc->value((string)$v);
      }
    }

    if(!$Model->updateAll($set, $conditions)) {
      throw new RuntimeException(_txt('er.db.save-a', array($Model->alias)));
    }

    return $Model->getAffectedRows() === 1;
  }

  /**
   * The conditions that limit a Changelog model's rows to current ones:
   * not archived (no parent key) and not deleted. Add it to a query's
   * conditions as one element, for a lookup by id that Changelog's
   * beforeFind does not filter.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $alias     Model alias in the query
   * @param  String $parentKey Changelog parent foreign key, such as co_group_id
   * @return Array             Conditions
   */

  protected static function currentRowConditions($alias, $parentKey) {
    return array(
      $alias . '.' . $parentKey => null,
      $alias . '.deleted IS NOT true'
    );
  }

  /**
   * Run a parameterized SELECT without the query cache and return flat rows.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $sql    SQL with ? placeholders
   * @param  Array  $params Parameters
   * @return Array          Rows as column => value
   * @throws RuntimeException If the query fails
   */

  protected function sqlRows($sql, $params) {
    $result = $this->getDataSource()->fetchAll($sql, $params, array('cache' => false));

    if($result === false) {
      throw new RuntimeException(_txt('pl.applicationteamenroller.er.query'));
    }

    $rows = array();

    foreach((array)$result as $row) {
      $flat = array();

      foreach($row as $part) {
        $flat = array_merge($flat, (array)$part);
      }

      $rows[] = $flat;
    }

    return $rows;
  }

  /**
   * A research team's display label: its name, or its CoGroup's name if the
   * team has none.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $teamName  AteResearchTeam name
   * @param  String $groupName CoGroup name
   * @return String
   */

  public static function teamLabel($teamName, $groupName) {
    return !empty($teamName) ? $teamName : $groupName;
  }

  /**
   * The CO a row being validated belongs to: its co_id, or the stored co_id
   * of the row being edited.
   *
   * @since  COmanage Registry v4.6.0
   * @return Integer CO ID, or null if unknown
   */

  protected function validationCoId() {
    $coId = isset($this->data[$this->alias]['co_id']) ? $this->data[$this->alias]['co_id'] : null;

    if(empty($coId)) {
      $id = !empty($this->data[$this->alias]['id']) ? $this->data[$this->alias]['id'] : $this->id;

      if(!empty($id)) {
        $coId = $this->field('co_id', array($this->alias . '.id' => $id));
      }
    }

    return $coId;
  }

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
