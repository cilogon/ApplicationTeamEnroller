---
title: CakePHP 2 updateAll() on MySQL - ambiguous keys and changed-row counts
date: 2026-11-02
category: database-issues
module: Compare-and-set writes (ApplicationTeamEnrollerAppModel::conditionalUpdate)
problem_type: database_issue
component: database
symptoms:
  - "On a MySQL Registry, accepting an invitation shows 'Your response could not be recorded because of an error'"
  - "Log line: SQLSTATE[23000] Integrity constraint violation 1052 Column 'id' in where clause is ambiguous"
  - Declines, approvals, denials, and routing fail the same way; the hermetic suite stays green on Postgres
root_cause: wrong_api
resolution_type: code_fix
severity: high
framework_version: cakephp 2.10.24 (COmanage Registry 4.6.x), MySQL 8.4
tags: [cakephp, mysql, updateall, compare-and-set, repeatable-read, affected-rows, registry-dev]
---

# CakePHP 2 updateAll() on MySQL - ambiguous keys and changed-row counts

## Problem

registry-dev runs MySQL; the hermetic suite ran only on Postgres. Three MySQL
behaviors broke the plugin's compare-and-set writes there. All three were
invisible on Postgres. Fixed in cilogon/ApplicationTeamEnroller#7.

## Symptoms

- A newcomer's accept ended on the "could not be recorded" page, with
  `1052 Column 'id' in where clause is ambiguous` in the error log. The MySQL
  wording is "in where clause"; MariaDB 10.11 says "in WHERE", which is how
  the log identified registry-dev's engine.
- Every other `conditionalUpdate()` caller with bare keys (decline,
  approve, deny, routing) failed the same way.

## What Didn't Work

- Running the suite as it was: every path passed on Postgres, so nothing
  pointed at the engine until the log line arrived.
- A first MySQL fix that recounted with a plain `find('count')` after a
  zero-row update. A two-session probe on MySQL 8.4 showed it reporting a lost
  claim as won (plain count 1, locking count 0); see the third trap below.

## Solution

`conditionalUpdate()` (`Model/ApplicationTeamEnrollerAppModel.php:35`) now
does three things:

```php
// 1. Qualify bare condition keys with the model alias
$where[(is_string($col) && strpos($col, '.') === false) ? $Model->alias . '.' . $col : $col] = $v;

// 2. and 3. On MySQL, recount a zero-row update with a locking read
if($affected === 0 && $dbc instanceof Mysql) {
  $rows = $dbc->fetchAll('SELECT COUNT(*) AS matched FROM ' . $dbc->fullTableName($Model)
                         . ' AS ' . $dbc->name($Model->alias)
                         . $dbc->conditions($where, true, true, $Model) . ' FOR UPDATE',
                         array(), array('cache' => false));
}
```

Direct `updateAll()` calls qualify their keys themselves, for example
`array('AteEnrollmentRequestTeam.id' => $t['id'])` (`Model/AteEnrollmentRequest.php`).

## Why This Works

1. **updateAll() joins associations on MySQL only.** With conditions present,
   `Mysql::update()` renders `UPDATE <table> AS <Alias> LEFT JOIN ...` over the
   model's belongsTo tables (`~/comanage-registry/lib/Cake/Model/Datasource/Database/Mysql.php`,
   `update()` from line 397). It qualifies the SET columns
   (`DboSource.php:2202-2203`, `escapeField()` when `$alias` is set) but not
   the condition keys, so `id`, `status`, or `modified` are ambiguous.
   `DboSource::update()`, which Postgres uses, joins nothing.
2. **MySQL reports changed rows, not matched rows.** Cake does not set
   `PDO::MYSQL_ATTR_FOUND_ROWS`, so `rowCount()` counts only rows whose values
   changed. Re-saving an identical draft within the same second (same
   `modified` stamp) reports 0, which `conditionalUpdate()` read as a lost race.
3. **A plain read inside a transaction sees a stale snapshot.** Under InnoDB's
   default REPEATABLE READ, the first plain SELECT in a transaction fixes its
   view; an UPDATE uses the current row. In a transaction that read the row
   earlier (an approval does), another connection can claim the row, our UPDATE
   matches nothing, and a plain recount still sees the old status and returns
   1. `FOR UPDATE` makes the recount a current read. A claim always changes a
   column of its own conditions, so a lost claim still counts 0.

## Prevention

- **The check:** the MySQL leg, `ATE_TEST_DB=mysql Test/run.sh`, and CI's
  "Hermetic suite (MySQL)" job. It only covers paths a test reaches, so a new
  write path needs a test, run on both legs.
- **Route writes through `conditionalUpdate()`.** Any direct `updateAll()` or
  `deleteAll()` must qualify every condition key with the model alias.
- **Never decide a race from a plain SELECT inside a transaction on MySQL.**
  Use a locking read (`FOR UPDATE`) or the UPDATE's own result.
- **Tests:** `ConditionalUpdateTest::testConditionKeysReachUpdateAllQualified`,
  `testClaimTakenByAnotherConnectionIsLost` (two connections), and
  `AteResponsesControllerTest::testSaveDraftAgainWithSameChoicesSucceeds`.
- Test SQL must stay portable too: `AteFixtures` quotes through the
  datasource, avoids `RETURNING` on MySQL, and teardown wraps deletes in
  `relaxForeignKeys()`, because MySQL enforces foreign keys on every
  statement (`Test/README.md`).

## Related Issues

- cilogon/ApplicationTeamEnroller#7 (the fix and the MySQL leg)
