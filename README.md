# ApplicationTeamEnroller

An enroller plugin for COmanage Registry 4.6.x that lets an application
administrator invite a researcher by email to one or more applications, and to
research teams within each application.

The researcher follows the emailed link, logs in through CILogon, and accepts
or declines each application. Each accepted application is decided by that
application's approvers, or approved at once when the application does not
require approval. Approval adds the researcher to the chosen research teams,
which are ordinary CO groups. Each application has an access group, maintained
by the plugin, that nests the groups of its authorized teams. Registry's
existing group provisioning then delivers application login and the ID Token
team claim (`isMemberOf`).

Every invitation, response, and decision is recorded for audit.

The plugin was built first for the SecureData4Health (SD4H) collaboration in
the Digital Research Alliance of Canada (DRAC) Registry, but nothing in it is
specific to SD4H.

This README is for CO administrators and operators deploying the plugin.
Developers should also read [Development](#development).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [The newcomer enrollment flow](#the-newcomer-enrollment-flow)
- [Apache and OIDC: protecting the response page](#apache-and-oidc-protecting-the-response-page)
- [Access groups and application login](#access-groups-and-application-login)
- [Scheduling the ExpireInvitations job](#scheduling-the-expireinvitations-job)
- [Who can do what](#who-can-do-what)
- [SD4H defaults and open questions](#sd4h-defaults-and-open-questions)
- [Known limitations](#known-limitations)
- [Manual end-to-end checklist](#manual-end-to-end-checklist)
- [Development](#development)

## Requirements

- COmanage Registry 4.6.x. The automated tests run against Registry 4.6.1.
- PHP 8.3 or later. The automated tests run on PHP 8.4.
- Login through CILogon, with Apache `mod_auth_openidc` in front of Registry.
  The plugin reads the login's email claim from a web server environment
  variable (see [Apache and OIDC](#apache-and-oidc-protecting-the-response-page)).
- An outbound email configuration for Registry (`CakeEmail` `default`
  transport). Invitation and decision emails use it.
- A way to run Registry jobs on a schedule, normally cron on the Registry host
  (see [Scheduling](#scheduling-the-expireinvitations-job)).
- For application login: the Oa4mpClient plugin (or equivalent) releasing an
  `isMemberOf` claim, and an LDAP (or other) provisioner that exports CO
  groups and the login identifiers CILogon matches on.

## Installation

1. Place this repository in the Registry's local plugin directory, named
   exactly `ApplicationTeamEnroller`:

   ```bash
   cd /srv/comanage-registry/local/Plugin
   git clone https://github.com/cilogon/ApplicationTeamEnroller.git ApplicationTeamEnroller
   ```

   Adjust `/srv/comanage-registry` to your Registry installation. The view
   files include relative symlinks into `app/View/Standard`, so keep the
   standard layout (`local/Plugin/<name>` next to `app/`).

2. Create the plugin's tables (all prefixed `cm_ate_`). As the web server
   user, from the Registry `app/` directory:

   ```bash
   cd /srv/comanage-registry/app
   ./Console/cake database
   ```

   Registry's `database` shell reads each plugin's `Config/Schema/schema.xml`.
   Run it again after upgrading the plugin.

3. Clear Registry's cache (for example the files under `app/tmp/cache/`) if
   the new menu entries or models do not appear, then reload Registry.

The plugin is both an enroller (a wedge on the newcomer enrollment flow) and a
job (`ApplicationTeamEnroller.ExpireInvitations`). It adds these menu
entries:

- CO Configuration menu: **Team Enroller: Applications**,
  **Team Enroller: Research Teams**, **Team Enroller: Settings**. For CO
  administrators.
- CO main menu: **Invite Researcher**, **Invitations**, **Decision Queue**.
  Registry shows these to every CO member; each screen checks access itself
  and refuses users who have no role in it.

## Configuration

Configure a CO in this order. All of these screens are for CO administrators.

### 1. Research teams

**CO Configuration > Team Enroller: Research Teams > Add**

A research team is an existing CO group designated as a team. Membership in the
team is membership in that group; the plugin keeps no separate list.

- **Group:** a Standard group of this CO that is not already a research team
  and is not an application access group. It cannot be changed after the team
  is created. Create the group first in Registry's own group screens.
- **Name:** shown to administrators and researchers. Defaults to the group
  name.
- **Status:** a Retired team is no longer offered in new invitations but keeps
  its history. Teams cannot be deleted.

### 2. Applications

**CO Configuration > Team Enroller: Applications > Add**

- **Name:** the application's display name. The access group is named after it.
- **Admin Group:** members may invite researchers to this application.
- **Approver Group:** members decide accepted requests for this application.
  It may be the same group as the admin group.
- **Approval Required:** checked by default. If set, an approver decides each
  accepted request. If not, an accepted request is approved at once, unless
  the login does not match the invited address (then the inviting admin
  decides), and unless another application that authorizes one of the
  offered teams requires approval.
- **OIDC Client Identifier:** optional, for reference only. The CILogon client
  ID this application logs in with.
- **Status:** a Retired application is no longer offered in new invitations,
  but its access group and nestings stay so existing users can still log in.
  Applications cannot be deleted.

Saving a new application creates its **access group** (see
[Access groups](#access-groups-and-application-login)).

### 3. Authorize research teams for each application

On the application's page, use **Authorize Research Team** to add each team
the application accepts. Only active research teams of the CO can be added.
Adding a team nests its group in the application's access group; removing the
authorization removes the nesting. An invitation to an application can offer
only its authorized teams.

### 4. Per-CO settings

**CO Configuration > Team Enroller: Settings**

The settings row is created with defaults the first time the screen is
opened.

| Setting | Default | Meaning |
| --- | --- | --- |
| Invitation Lifetime (Days) | 14 | How long an invitation link stays valid. |
| Invitation Email Subject | `Invitation to applications in (@CO_NAME)` | Subject of the invitation email. |
| Invitation Email Body | see below | Plain-text body of the invitation email. |
| Newcomer Enrollment Flow | none | The enrollment flow a researcher with no record in this CO completes. See [The newcomer enrollment flow](#the-newcomer-enrollment-flow). |
| Login Email Variables | `OIDC_CLAIM_email` | Comma-separated names of web server environment variables carrying the login's email address. Each is also read with Apache's `REDIRECT_` prefix. |
| Login Identifier Type | `oidcsub` | The Identifier type used when the plugin attaches a login to a CO Person. Match the type your CILogon login uses in Registry. |

The subject and body accept these placeholders, replaced when the invitation
is sent:

- `(@CO_NAME)`: the CO's name.
- `(@INVITER_NAME)`: the inviting administrator's name ("An administrator" if
  none is on record).
- `(@APPLICATIONS)`: one line per application, with its offered research teams.
- `(@EXPIRES)`: the expiry time, in UTC.
- `(@INVITE_URL)`: the invitation link. Always include it in the body.

The default body is:

```
Hello,

(@INVITER_NAME) has invited you to the following applications in (@CO_NAME):

(@APPLICATIONS)

Please follow the link below, log in, and accept or decline each application.
The link can be used for one response and expires on (@EXPIRES).

(@INVITE_URL)
```

Invitations can be sent to existing CO members before a newcomer flow is set.
A newcomer who accepts anything while no newcomer flow is set has their
choices saved and is told enrollment is not yet set up; they can follow the
same link again once it is.

## The newcomer enrollment flow

A researcher whose login has no CO Person in this CO (a newcomer) and who
accepts at least one application is sent from the response page into the CO's
newcomer enrollment flow. The flow creates their CO Person; this plugin's
wedge then attaches the login and records the response.

The Settings screen refuses a flow that does not meet every requirement below,
and names the first one that fails.

| Requirement | Why |
| --- | --- |
| The flow belongs to the same CO. | The CO Person must be created in the CO the invitation belongs to. |
| Petitioner Enrollment Authorization is **Authenticated User**. | A newcomer has no role in the CO yet, only a CILogon login. Any stricter authorization would refuse them. |
| **Require Approval For Enrollment** is off. | Approval of access happens in this plugin, per application. A Registry approval step in between would stop the flow before the plugin can record the response, and would ask for a second, unrelated decision. |
| **Email Confirmation Mode** is **None**. | The plugin compares the login's email with the invited address itself. A confirmation step would interrupt the flow, and Registry attaches the login itself only when confirmation is on, so the plugin attaches it instead. |
| **Identity Matching** policy is not **Self** or **Select**. | Either policy runs a matching step before the one where the plugin binds the petition to the invitation. The plugin decides separately whether the login belongs to an existing person. |
| At least one **CO Person Role** attribute (an `r:` attribute such as Affiliation) is collected and not Not Permitted. | Registry makes a new CO Person Active only through a CO Person Role. Without one the newcomer stays Pending, and a Pending person gets nothing from group provisioning. |
| An **active wedge of this plugin** is attached (Enrollment Flow > Attach Enrollment Flow Wedges, plugin ApplicationTeamEnroller). | The wedge checks that the petition came from a live invitation and the same login, binds the petition to the invitation, attaches the login, commits the response, and sends the researcher to the plugin's confirmation page. Without it, nothing links the new person to their invitation. The wedge has no settings of its own. |

Also make sure the flow is Active.

Use this flow only as the newcomer flow. The wedge's checks are a front door,
not a guarantee: Registry lets a user skip every plugin step (a
`done:<wedge id>` URL parameter). A person who finishes the flow without an
invitation gets no application access, because access comes only through team
groups, and the ExpireInvitations job suspends them on its next run and
notifies the CO administrators (see
[Scheduling](#scheduling-the-expireinvitations-job)). Anyone who uses this flow
for another purpose will be suspended the same way.

## Apache and OIDC: protecting the response page

The response page is
`https://<registry host>/registry/application_team_enroller/ate_responses/respond`
(adjust `/registry` if Registry is served at another base path).

That path must be protected by `mod_auth_openidc`, so that Apache exposes the
login's email claim to PHP as an environment variable (by default
`OIDC_CLAIM_email`, or `REDIRECT_OIDC_CLAIM_email` after an internal
redirect). The plugin compares that address with the invited address.

If the claim does not reach the page, the plugin cannot confirm the login's
email and fails closed: the response is treated as a mismatch, and each
accepted application waits for a person to decide it. For a newcomer this is
every response. For an existing member, verified email addresses on
Organizational Identities already linked to the login also count, so some
responses may still match; do not rely on that.

The invitation link itself
(`.../application_team_enroller/ate_responses/landing/<token>`) is reachable
without a login; it stores the link in the session and sends the user to
`respond`, logging in on the way.

The following is an **illustrative example to adapt**, not a tested
configuration. It assumes `mod_auth_openidc` is already configured for
Registry login (`OIDCProviderMetadataURL`, `OIDCClientID`, `OIDCRedirectURI`,
and so on) and that Registry is served at `/registry`:

```apache
# Example only: adapt to your existing mod_auth_openidc configuration.
<Location /registry/application_team_enroller/ate_responses/respond>
  AuthType openid-connect
  Require valid-user
</Location>
```

The researcher reaches `respond` after logging in to Registry through CILogon,
so the existing OIDC session should satisfy this location without a second
login. **Confirm this on your deployment** (step 7 of the checklist below): if
researchers are asked to log in twice, align the location with the one that
protects Registry's login (`/registry/auth/login`), for example by sharing its
session and cookie settings. Then confirm the claim is visible (step 8).

If your claims arrive under different variable names, list them in the
**Login Email Variables** setting.

## Access groups and application login

Each application gets its own **access group**, created by the plugin when the
application is saved. It is a Standard, closed, non-automatic CO group named
after the application (with a numeric suffix if the name is taken), whose
description says the plugin maintains it.

- Its members arrive **only through nesting**: each authorized research team's
  group is nested in it. It has no direct members.
- Set the access group as the application's **authorization group** in its
  Oa4mpClient (CILogon OIDC client) configuration. That is what grants login
  to the application.
- **Do not edit it by hand.** Registry's own group screens still allow editing
  it, but the plugin owns it. If its nestings drift (added or removed outside
  the plugin), use **Resync Access Group** on the application's page: it nests
  every authorized team and removes any other nested group, and reports what
  it changed. Resync cannot recreate an access group deleted in Registry.
- **CO membership alone grants no application access.** A CO Person reaches an
  application only by being a member of a research team group authorized for
  it. This is also why the plugin never grants anything by itself: its records
  are the audit trail, and `CoGroupMember` rows are the only access primitive.
- Removing a researcher from a team is done in Registry's own group
  management, by removing the group membership. That revokes access to every
  application that authorizes the team at once.
- The team claim (`isMemberOf`) carries **all** of a researcher's groups,
  including teams belonging to other applications. The plugin does not filter
  the claim per client, so **each application must filter `isMemberOf` to its
  own teams**.
- A team can be authorized for several applications. A researcher added to
  that team, through any application's approval, can log into every
  application that authorizes the team.

## Scheduling the ExpireInvitations job

The plugin provides one Registry job, `ApplicationTeamEnroller.ExpireInvitations`.
Each run, for one CO, it:

1. Expires `sent` invitations past their expiry, with their unanswered
   application requests. Requests already awaiting a decision never expire.
   An invitation whose newcomer is part way through the enrollment flow gets
   a 24-hour grace period past its expiry, so an in-flight enrollment can
   finish; after that it expires normally.
2. Retires (declines) the unfinished newcomer petition bound to an expired
   invitation.
3. Notifies the inviting administrator of each expired invitation, once.
   Invitations expired on access (opening a lapsed link expires it too) are
   included.
4. Contains newcomer-flow bypasses: a petition finalized on the CO's newcomer
   flow that no invitation is bound to has its CO Person (and roles)
   suspended, a history record written, and the CO administrators group
   notified. Each such petition is handled once.

A failure on one item is logged and recorded on the job, and the run goes on;
the next run retries it.

Run the job as the web server user, from Registry's `app/` directory, once per
CO (`<CO ID>` is the numeric CO ID). Choose **one** of these two methods; do
not combine them, or the job runs twice as often as intended.

**Option A: cron runs the job directly (synchronous).** Hourly is suggested:

```cron
# m h dom mon dow  command (as the web server user)
0 * * * *  cd /srv/comanage-registry/app && ./Console/cake job ApplicationTeamEnroller.ExpireInvitations -c <CO ID> -s
```

**Option B: the job requeues itself, and cron runs the queue.** Queue it once:

```bash
cd /srv/comanage-registry/app
./Console/cake job ApplicationTeamEnroller.ExpireInvitations -c <CO ID> -s --requeue 60
```

`--requeue 60` makes each run schedule the next one 60 minutes later (and
retry 60 minutes after a failure). Then run Registry's queue runner from cron,
for example every few minutes:

```cron
*/5 * * * *  cd /srv/comanage-registry/app && ./Console/cake job -r -c <CO ID>
```

With either option, job runs and their per-item records appear in Registry's
job history for the CO.

## Who can do what

| Role | Can |
| --- | --- |
| CO administrator (CO admins group, or platform administrator) | Configure research teams, applications, team authorizations, and settings; resync access groups. Invite to any active application. See, revoke, and withdraw from any invitation. Decide any pending request in the CO. Only CO administrators decide identity-link requests. |
| Application administrator (member of an application's Admin Group) | Invite researchers to that application. See invitations that include applications they administer. Revoke their own invitations and withdraw their own requests. Decide mismatch requests on their invitations when the application does not require approval. |
| Approver (member of an application's Approver Group) | Decide accepted requests for that application, including mismatches, when approval is required. |
| Researcher | Follow the invitation link, log in, and accept or decline each application once. Receives an email when each application is decided. |

Rules that apply to everyone, CO administrators included:

- **No one decides their own request**, or a request whose approval would link
  a login to their own CO Person.
- **Identity-link requests go only to CO administrators.** When a newcomer's
  login is used to answer an invitation sent to an address that already
  belongs to an existing CO Person, approving it links the login to that
  person. Such requests (shown as "Login would be linked to an existing
  person") are hidden from every other queue.
- An inviter needs a CO Person in the CO, and a decider needs one too.
- A mismatch on an application with approval off goes to the inviting
  administrator while they still hold the admin role; after that, to any
  member of the application's admin group.
- Deciders are notified through Registry notifications, and the notification
  is cleared when the request is decided.

## SD4H defaults and open questions

SD4H has not confirmed the following defaults. Each is live in v1 and can be
changed without code changes except where noted.

| Default | Where to change it |
| --- | --- |
| Invitation lifetime of 14 days. | Settings: Invitation Lifetime (Days), per CO. |
| An application's admin and approver groups may be the same group. | Application: choose different Admin and Approver groups. |
| An application may have approval turned off. | Application: Approval Required (on by default). |
| For a mismatch on an application with approval off, the inviting admin decides. | Code change, confined to requirement R24 (decider routing in `Controller/Component/AteAuthzComponent.php` and `Model/AteEnrollmentRequest.php`). |
| Removal from a team happens only through Registry's native group management. | Code change, requirement R33 (a plugin removal screen is deferred). |
| English only. | Code change, requirement R38. All user-facing text is in `Lib/lang.php`. |

Open for SD4H:

- What an approver checks beyond the admin's choice of teams.
- Whether approver authority should belong to the research team rather than
  the application. Today, approval through one application grants every
  application that shares the team.

Deferred for a later version: a plugin screen for removing researchers from
teams, French translation, reminder emails, and per-client filtering of the
team claim in CILogon.

## Known limitations

- A newcomer who abandons the enrollment flow and retries can leave an extra
  Pending or Declined CO Person record from the abandoned attempt. The retry
  itself completes normally.
- If the invited address belongs to several existing CO People, a newcomer's
  response becomes an identity-link request with no link target. It cannot be
  approved; a CO administrator can only deny it (or the inviter can withdraw
  it) and send a new invitation.
- Team memberships created by group-sync pipelines (for example group
  provisioning or sync jobs that write direct memberships) count as already
  present, and approval leaves them as they are.
- The Organizational Identity created when approval links a login carries no
  name.
- The plugin's notification action codes (`pAPD` pending, `pADC` decided,
  `pAEX` expired, `pACN` contained) have no action label text in Registry's
  notification screens.
- Deleting a whole CO that has invitation history is not supported: the
  plugin's audit tables reference the CO and are deliberately not removed.
- Revoked invitations with a bound newcomer petition are not retired by the
  job; only expired invitations' petitions are.
- Notifications are resolved by their exact URL. If Registry's host name or
  base path changes, notifications created before the change may not clear
  when their request is decided.

## Manual end-to-end checklist

Run this on a Registry 4.6.x test deployment behind CILogon. It walks the
second Success Criterion: configure applications and teams, send an invitation
covering several applications, respond as an existing member and as a
newcomer, decide requests including a mismatch, and log into an application
that receives the expected team claim.

You need: a CO administrator login; a second login for an application
administrator (App Admin); a third for an approver (Approver); a plain CO
member; an existing member with a CILogon login (Existing); one or more fresh
CILogon logins that have never used this Registry (Newcomer); mailboxes you
can read; and a test application registered as a CILogon OIDC client through
Oa4mpClient.

**Setup**

1. Install the plugin and run `./Console/cake database`. As CO administrator,
   confirm the menus appear: CO Configuration shows **Team Enroller:
   Applications**, **Team Enroller: Research Teams**, **Team Enroller:
   Settings**; the main menu shows **Invite Researcher**, **Invitations**,
   **Decision Queue**. Log in as the plain member and confirm each of those
   screens refuses access.
2. Create groups for two research teams and two applications' admin and
   approver groups. Designate the teams (use a name with an apostrophe, such
   as `O'Brien Lab`). Create two applications, App One (approval required)
   and App Two (approval off), and authorize teams for each. Confirm every
   configuration page renders and that names with apostrophes appear escaped
   once (no `&#039;` or doubled escaping).
3. Confirm each application's access group was created and nests exactly its
   authorized teams. Remove a nesting in Registry's group screen, then run
   **Resync Access Group** and confirm the message reports the repair.
4. Open Settings, set the newcomer flow (built per
   [The newcomer enrollment flow](#the-newcomer-enrollment-flow)), and save.
   Try a flow that violates one requirement and confirm the error names it.
   Set each application's access group as its Oa4mpClient authorization group.

**Invitations and responses**

5. As App Admin, open **Invite Researcher**, choose both applications and
   teams, and send to Existing's address. Confirm the form posts without a
   Security component (black-hole) error, the invitation appears in
   **Invitations**, and the email arrives with the configured text and a link
   on the correct host and base path.
6. In a fresh browser with no Registry session, open a truncated or altered
   invitation link and confirm the explanation page appears without a login.
   Also check a revoked link.
7. Open a valid link without being logged in. Confirm the login round trip
   through CILogon returns to the respond page with the session preserved,
   and without a second login prompt from the protected `respond` location.
8. **Check this first, before relying on any match result:** confirm the OIDC
   email claim is visible on the respond page (for example by temporarily
   logging `OIDC_CLAIM_email` or checking that a matching login produces no
   mismatch). If it is missing, every response will be a mismatch.
9. As Existing, accept App One and decline App Two. Confirm the confirmation
   page shows App One awaiting a decision and App Two declined. Repeat with a
   new invitation to App Two only (approval off) and accept: it is approved at
   once and Existing is added to the team groups.
10. Invite a Newcomer's address to both applications. As Newcomer, accept.
    Confirm you are sent through the newcomer flow, exactly one new CO Person
    is created and is **Active**, it carries the login identifier of the
    configured type, and the flow ends on the plugin's confirmation page.
11. With another invitation, start as a Newcomer, close the browser part way
    through the flow, then follow the link again and finish. Confirm the
    response commits once (an extra Pending or Declined record from the
    abandoned attempt is a known limitation).
12. Invite an address and respond with a login whose email does not match it.
    Confirm the researcher sees no mismatch details, and that the request goes
    to the right decider: the approver group for App One, the inviting admin
    for App Two.

**Decisions**

13. As Approver, confirm the pending notification appears, open **Decision
    Queue**, and approve or deny. Confirm the notification clears after
    deciding, and a second decider sees "already handled". Confirm a decider
    cannot decide their own request.
14. Confirm the researcher receives the approval or denial email for each
    decided application.
15. Send an invitation to Existing's address and respond with a fresh Newcomer
    login, producing an identity-link request. Confirm it appears only in a CO
    administrator's queue. Approve it as a CO administrator, then log in with
    that login and confirm it lands on Existing's CO Person. Check that the
    LDAP provisioner exports the linked login identifier, so CILogon matches
    it.

**Application login**

16. As an approved researcher, log into App One through CILogon. Confirm login
    is granted through the access group, and that the application receives the
    team in `isMemberOf`. Confirm a CO member in no authorized team is refused.

**Expiry and containment**

17. Set the invitation lifetime to 1 day, send an invitation, and wait past
    expiry (or adjust `expires` in a test database). Run the job from cron (or
    by hand with the Option A command). Confirm the invitation is Expired, its
    link shows the expired explanation, and the inviting admin receives one
    expiry notification (not a second on the next run).
18. Start the newcomer flow directly with the `done:<wedge id>` parameter, so
    the wedge is skipped, and complete it. Run the job again and confirm the
    resulting CO Person is Suspended, a history record names the plugin, and
    the CO administrators are notified.

## Development

The automated test suite runs in Docker against a pinned Registry 4.6.x image
and Postgres. One command brings up the environment, creates the schema, runs
the suite, and tears it down:

```bash
Test/run.sh
```

See [Test/README.md](Test/README.md) for the harness, its gates, fixtures,
and how to write tests. Design and requirements are in
`docs/plans/2026-09-29-0856-feat-application-team-enroller-plan.md`. Changes
are listed in [CHANGELOG.md](CHANGELOG.md).
