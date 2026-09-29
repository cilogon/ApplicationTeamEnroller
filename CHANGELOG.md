# Changelog

All notable changes to this plugin are listed here.

## [1.0.0] - Unreleased

First release, for COmanage Registry 4.6.x. Built first for SD4H; not specific
to it.

### Added

- Configuration screens for CO administrators: research teams (existing CO
  groups designated as teams), applications (admin group, approver group,
  approval required, optional OIDC client identifier), the research teams
  authorized for each application, and per-CO settings (invitation lifetime,
  invitation email subject and body with `(@...)` placeholders, newcomer
  enrollment flow, login email environment variables, login identifier type).
- A plugin-maintained access group per application, whose members arrive only
  by nesting the authorized teams' groups, with a resync action that repairs
  drift. It is meant to be the application's Oa4mpClient authorization group.
- Invitations: an application administrator composes one invitation covering
  several applications and teams; the researcher receives one email with a
  single-use link, stored only as a SHA-256 hash. Inviters and CO
  administrators can list, view, and revoke invitations and withdraw pending
  requests.
- Response pages: the link works without a login, then sends the researcher
  through CILogon login to accept or decline each application. The login's
  identity is snapshotted and compared with the invited address; any missing
  email or lookup error is treated as a mismatch.
- Routing and approval: each accepted application is approved at once, or
  waits for its approvers, the inviting administrator (mismatch with approval
  off), or CO administrators (identity-link requests). Approval adds direct
  team group memberships and, where needed, links the login to an existing
  CO Person. No one may decide their own request or one that links a login to
  them. Every status change is a conditional update, so double submits and
  concurrent decisions resolve to one winner.
- Newcomer enrollment: an enrollment flow wedge carries a researcher with no
  CO Person through a configured newcomer flow, attaches their login, and
  records their response. The settings screen rejects flows that cannot
  serve this.
- Decision queue for deciders, Registry notifications for pending, decided,
  expired, and contained items, and decision emails to the researcher.
- The `ApplicationTeamEnroller.ExpireInvitations` job: expires lapsed
  invitations (with a 24-hour grace period for an in-flight newcomer
  enrollment), retires their unfinished petitions, notifies each inviting
  administrator once, and suspends people who finished the newcomer flow
  without an invitation, notifying the CO administrators. It can requeue
  itself.
- Menu entries: CO Configuration (Team Enroller: Applications, Research Teams,
  Settings) and CO main menu (Invite Researcher, Invitations, Decision Queue).
- An automated test suite run in Docker against a pinned Registry 4.6.x image
  (`Test/run.sh`).
- Operator documentation (`README.md`), including a manual end-to-end
  checklist.
