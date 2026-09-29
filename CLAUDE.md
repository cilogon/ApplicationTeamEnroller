# CLAUDE.md

## Project Overview
This project is an `enroller`-type plugin for COmanage Registry version 4.6.x.
The COmanage Registry code repository for version 4.x is at
https://github.com/Internet2/comanage-registry and the technical manual is
in the wiki at
https://spaces.at.internet2.edu/spaces/COmanage/pages/17105978/COmanage+Registry+Technical+Manual
Version 4.x of COmanage Registry uses the CakePHP version 2.x model view
controller (MVC) framework. A local checkout of Registry at tag `4.6.0` is at
`~/comanage-registry`; read it for reference patterns (for example
`app/AvailablePlugin/FiddleEnroller` and `app/AvailablePlugin/UnixCluster`).

The plugin was built first for the SecureData4Health (SD4H) collaboration in
the Digital Research Alliance of Canada (DRAC) Registry, but it is not specific
to SD4H. An application administrator invites a researcher by email to one or
more applications and research teams. The researcher logs in via CILogon and
accepts or declines each application, and each accepted application is decided
by that application's approvers. Approval adds the researcher to the research
teams' CoGroups, and Registry's existing group provisioning delivers
application login and the ID Token team claim.

The requirements live in
`docs/plans/2026-09-29-0856-feat-application-team-enroller-plan.md`. Read its
Product Contract before changing behavior, and keep the requirement IDs (R1,
R2, ...) stable when citing them.

## Directory and File Structure
Follow the standard Registry plugin layout:
- `Config/Schema/schema.xml`: database table definitions in AdoDb XML format.
- `Controller`: controllers, including the enroller's
  `ApplicationTeamEnrollerCoPetitionsController` (extends
  `CoPetitionsController`, implements `execute_plugin_<step>` methods).
- `Model`: models, including the enroller model (`cmPluginType`,
  `belongsTo CoEnrollmentFlowWedge`, `cmPluginHasMany`, `cmPluginMenus`).
- `View`: view files, following Registry conventions (symlinked `add.ctp` and
  `edit.ctp`, a shared `fields.inc`).
- `Lib/lang.php`: text localization. Registry does not use the standard
  CakePHP 2.x localization approach.
- `docs/plans/` and `docs/brainstorms/`: planning and requirements artifacts.
- `docs/solutions/`: documented solutions to past problems, with YAML
  frontmatter.

## Coding Style & Conventions
- Language: PHP 8.3 is preferred.
- Naming: follow the conventions COmanage Registry 4.x uses.
- Use jQuery for dynamic HTML in view files. Prefer more, shorter lines of
  jQuery over long ones.
- Prefer double slashes for comments.
- Put all user-facing strings, including default email text, in `Lib/lang.php`
  rather than hard-coding them in controllers or views.

## Testing & Verification
- Lint changed PHP with `php -l <file>` before treating a change as complete.
- Run the plugin's automated test suite before treating a change as complete.
  The suite exists under `Test/`, and `Test/run.sh` (Docker: a pinned Registry
  4.6.x image plus Postgres) is the gate: it must end with the suite passing.
  CakePHP 2.x's PHPUnit-based `cake test` does not run on PHP 8.x, so the suite
  uses a thin CakePHP-shell runner copied from the Oa4mpClient plugin; see
  `Test/README.md` for the harness and how to write tests.
- Behavior that involves enrollment flows, petitions, email delivery, CILogon
  login, or group provisioning cannot be fully verified from this repository
  alone; validate it manually in a running COmanage Registry 4.6.x.

## Do's & Don'ts
- Do: Respect existing code style and patterns but suggest alternatives that
  provide generally cleaner and more maintainable code.
- Do: Treat `CoGroupMember` rows as the only access primitive. The plugin's
  own records are the audit trail, never an access gate.
- Don't: Introduce new dependencies without approval.
- Don't: Make the plugin a `cluster`-type plugin or implement claim release;
  both are outside its scope.

## Git, Remotes, and Pushing
This repository is set up for the GitHub machine account `skoranda-agent`;
the global "Machine account" rules apply. The GitHub repositories are created
by the developer. Until they exist and the remotes below are configured
(confirm with `git remote -v`), there is nothing to push to: commit locally
only.

Expected remotes (all HTTPS):
- `bot` -> `https://github.com/skoranda-agent/ApplicationTeamEnroller.git`,
  the machine account's fork. Agents push feature branches here.
- `upstream` -> `https://github.com/cilogon/ApplicationTeamEnroller.git`, the
  canonical repository. Pull requests target it.
- `origin` -> `https://github.com/skoranda/ApplicationTeamEnroller.git`, the
  developer's personal fork. Agents do not push here.

The machine account has read-only access to `cilogon/ApplicationTeamEnroller`
and write access only to its own fork, and GitHub enforces that. The limit on
agents writing upstream is therefore held by GitHub, not only by these
instructions.

Rules for agents:
- **Never commit to `main`.** Create a branch first and commit there. If a
  commit lands on `main` by mistake, move it onto a branch. (The initial
  commit that creates the repository is the one exception, and only when the
  developer asks for it.)
- Before any push or pull request, verify both: `gh api user --jq .login`
  prints `skoranda-agent`, and `git remote get-url bot` is
  `https://github.com/skoranda-agent/ApplicationTeamEnroller.git`. If either
  check fails, stop and tell the developer; never log in or switch `gh`
  accounts.
- **Shipping flow:** push the feature branch to `bot`, then open a
  ready-for-review pull request on `upstream`:
  `gh pr create --repo cilogon/ApplicationTeamEnroller --base main --head skoranda-agent:<branch>`.
  The developer reviews and merges there. There is no `origin` pull request
  step and no second pull request.
- Agents may manage that pull request: edit its title and body, push follow-up
  commits to its branch, reply to review comments, and read CI results.
  Force-pushing a `bot` branch, closing a pull request, or deleting a `bot`
  branch needs the developer's approval each time.
- **Upstream CI on bot pull requests:** a pull request from a fork may wait for
  the developer's approval before Actions run, and it gets no repository
  secrets. A pull request showing no checks is not green.
- **Never** push to `upstream` or `origin` (by remote name or by URL), push or
  force-push `main` on any remote, or approve or merge any pull request. Those
  stay with the developer.

Recording where work landed:
- When recording where work landed -- in a `docs/solutions/` learning, a plan,
  or a commit message -- cite the **upstream** pull request, owner-qualified
  (`cilogon/ApplicationTeamEnroller#5`), and only once it has merged. While the
  work is still unmerged, name the branch or the pull request and say the merge
  is pending. Recover merged numbers from `git log --merges main`.
