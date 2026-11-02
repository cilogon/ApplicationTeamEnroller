---
title: CakePHP 2 form field 'x.<id>' posts outside data[Model]
date: 2026-11-02
category: ui-bugs
module: Response pages (AteResponses)
problem_type: ui_bug
component: frontend
symptoms:
  - "Submitting the respond form with every application answered shows the error flash 'Accept or decline each offered application.'"
  - The form re-renders and no response is ever committed, for members and newcomers alike
root_cause: wrong_api
resolution_type: code_fix
severity: high
framework_version: cakephp 2.10.24 (COmanage Registry 4.6.x)
tags: [cakephp, formhelper, field-name, model-scope, request-data, view-testing]
---

# CakePHP 2 form field 'x.<id>' posts outside data[Model]

## Problem

The response page (`View/AteResponses/respond.ctp`) named each Accept/Decline
radio `'choices.' . $r['id']` inside `Form->create('AteResponse')`. CakePHP
posted those radios as `data[choices][<id>]`, while
`AteResponsesController::submitResponse()` reads only
`$this->request->data['AteResponse']`. Every submit was therefore refused.
Reported as cilogon/ApplicationTeamEnroller#2 and fixed in
cilogon/ApplicationTeamEnroller#4.

## Symptoms

- A red flash, "Accept or decline each offered application." (the
  `pl.applicationteamenroller.er.response.choices` text), after a fully
  answered form is submitted.
- The issue reported it for a newcomer with one application, but it hit every
  responder and every number of applications: `parseChoices()` runs before
  anything branches on who is responding.

## What Didn't Work

- Reading the validators first. `parseChoices()` in the controller and
  `normalizeChoices()` in the model both accept one `'1'`/`'0'` per offered
  request, and a single accept passes both. The data never reached them.
- The suite was green throughout. The controller tests built the POST array by
  hand in the shape the controller expects
  (`array('AteResponse' => array('nonce' => ..., 'choices' => ...))`) and never
  rendered a view, so the contract between the form and the controller was
  never exercised.

## Solution

Name the model in every dotted field name:

```php
// Before: posts data[choices][5]
print $this->Form->input('choices.' . $r['id'], $args);

// After: posts data[AteResponse][choices][5]
print $this->Form->input('AteResponse.choices.' . $r['id'], $args);
```

The quickest way to see what a form posts is to render it and read the `name=`
attributes. A standalone script that bootstraps `~/comanage-registry/lib/Cake`
and calls `FormHelper::create()`/`input()` showed `data[AteResponse][nonce]`
next to `data[choices][5]` within minutes.

## Why This Works

`Helper::setEntity()` (in the Registry checkout, not this repo:
`~/comanage-registry/lib/Cake/View/Helper.php`, lines 583-603 in CakePHP
2.10.24) adds the form's model scope only in three cases:

- a one-part name (`'nonce'`);
- a two-part name whose last part is a date or time suffix (`'date.month'`);
- a name whose first part is numeric (`'0.name'`).

`'choices.5'` is none of these, so its entity stays `choices.5` and the name
becomes `data[choices][5]`. A name that starts with the model
(`'AteResponse.choices.5'`) is used as written.

## Prevention

- `Test/Case/View/ViewFieldNamesTest.php` scans `View/` and fails on any
  `->Form->...('...')` literal that contains a dot but does not start with an
  uppercase model name. Its failure message names the fix. It cannot see a
  name built entirely from a variable (`$this->Form->input($field)`), so a
  dotted name assembled that way still needs the model prefix by hand.
- `AteResponsesControllerTest::testRenderedFormAcceptCommits` renders
  `respond.ctp` through a real `View` (`renderView()`) and posts what a browser
  would (`browserPost()`: every hidden input plus the chosen radio), then
  asserts that the response commits. For any new form whose controller reads
  `request->data`, add the same round trip. A hand-built POST tests only what
  the controller expects, never what the view sends.

## Related Issues

- cilogon/ApplicationTeamEnroller#2 (Responding to Invitation causes warning)
- cilogon/ApplicationTeamEnroller#4 (the fix)
