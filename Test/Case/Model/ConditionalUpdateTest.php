<?php
/**
 * ApplicationTeamEnrollerAppModel::conditionalUpdate(), the compare-and-set
 * write behind every claim and draft (KTD8).
 *
 * On MySQL, Cake's updateAll() renders UPDATE ... LEFT JOIN over the model's
 * belongsTo tables and qualifies only the SET columns, so an unqualified
 * condition key such as 'id' is ambiguous there and the write fails. Every
 * key that names a column must reach updateAll() qualified by the model's
 * alias. A Postgres run cannot see the ambiguity itself, so this test checks
 * what conditionalUpdate() hands to updateAll(); the MySQL leg of
 * Test/run.sh runs the real statements.
 *
 * MySQL also reports only changed rows, so conditionalUpdate() recounts a
 * zero-row update there. That recount must see the row as it is now, not as
 * the caller's transaction first read it, or a claim another connection
 * already took looks won (the race test below).
 */

App::uses('ConnectionManager', 'Model');

/**
 * Stands in for the model conditionalUpdate() writes through, recording the
 * conditions it is given.
 */
class AteConditionCapturingModel {

  public $alias = 'AteEnrollmentRequest';

  /** @var Array The conditions updateAll() was called with */
  public $captured = null;

  public function getDataSource() {
    return ConnectionManager::getDataSource('default');
  }

  public function updateAll($fields, $conditions) {
    $this->captured = $conditions;
    return true;
  }

  public function getAffectedRows() {
    return 1;
  }
}

class ConditionalUpdateTest extends AteEngineTestCase {

  /** Bare column keys are qualified by the alias; qualified keys and operators are kept. */
  public function testConditionKeysReachUpdateAllQualified() {
    $model = $this->model('ApplicationTeamEnroller.AteEnrollmentRequest');
    $target = new AteConditionCapturingModel();

    $method = new ReflectionMethod($model, 'conditionalUpdate');
    $method->setAccessible(true);

    $ok = $method->invoke($model, $target, array('draft_choice' => true, 'modified' => '2026-11-02 00:00:00'), array(
      'id'                           => 5,
      'AteEnrollmentRequest.status'  => 'offered',
      'ate_invitation_id'            => 7,
      'pending_reason'               => null
    ));

    $this->assertTrue($ok, 'one affected row is a successful claim');

    $keys = array_keys($target->captured);
    sort($keys);

    $this->assertEqual(array(
      'AteEnrollmentRequest.ate_invitation_id',
      'AteEnrollmentRequest.id',
      'AteEnrollmentRequest.pending_reason',
      'AteEnrollmentRequest.status'
    ), $keys, 'every condition key names its table, so MySQL cannot find it ambiguous');
    $this->assertEqual(5, $target->captured['AteEnrollmentRequest.id'], 'values are passed unchanged');
  }

  /**
   * KTD8: inside a transaction that has already read the row, as an approval
   * does, a claim that another connection took first is reported lost.
   */
  public function testClaimTakenByAnotherConnectionIsLost() {
    $inv = $this->invitation(self::Invited, array('A' => array('t1')));
    $reqId = (int)$inv['req']['A'];

    $method = new ReflectionMethod($this->Req, 'conditionalUpdate');
    $method->setAccessible(true);

    $dbc = $this->Req->getDataSource();
    $name = 'ate_second_' . uniqid();
    $other = ConnectionManager::create($name, $dbc->config);
    $won = null;

    $dbc->begin();

    try {
      // The first read fixes the transaction's view on MySQL
      $dbc->fetchAll('SELECT status FROM cm_ate_enrollment_requests WHERE id = ?', array($reqId),
                     array('cache' => false));

      // Another connection takes the request and commits
      $other->query('UPDATE cm_ate_enrollment_requests SET status = '
                    . $other->value(AteRequestStatusEnum::Revoked) . ' WHERE id = ' . $reqId);

      $won = $method->invoke($this->Req, $this->Req, array(
        'status'   => AteRequestStatusEnum::DeclinedByEnrollee,
        'modified' => date('Y-m-d H:i:s')
      ), array(
        'id'     => $reqId,
        'status' => AteRequestStatusEnum::Offered
      ));
    } finally {
      $dbc->rollback();
      $other->disconnect();
      ConnectionManager::drop($name);
    }

    $this->assertFalse($won, 'a claim another connection already took is lost');
    $this->assertEqual(AteRequestStatusEnum::Revoked, $this->requestRow($reqId)['status'], 'the winner\'s write stands');
  }
}
