## What this changes

<!-- One or two sentences on the change and why it is needed. -->

## Checklist

- [ ] The hermetic suite passes (`Test/run.sh`).
- [ ] `min_tests_run` in `Test/run.sh` equals the number of tests in the suite,
      and `min_plugin_tables` equals the number of tables in
      `Config/Schema/schema.xml`.
- [ ] **If this pull request fixes a bug:** it carries a regression test that
      fails against the pre-fix behavior, and links the `docs/solutions/`
      learning that documents the bug.
- [ ] Behavior changes are covered by tests; removed behavior has its tests
      removed or updated.
- [ ] New rule tests were shown red: the rule was broken temporarily and only
      its test failed.
- [ ] All user-facing text is in `Lib/lang.php`.
- [ ] Behavior the hermetic suite cannot reach (enrollment flows, email,
      CILogon login, group provisioning) was checked on a Registry 4.6.x test
      deployment, or the pull request says it was not.

<!--
Reviewer expectation: a bug-fix pull request without a regression test is asked
for one before approval. See Test/README.md, "The compounding norm".
-->
