# Changelog

All notable changes to `robot-council/cli` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.4.9 — Tags That Composer Resolves (2026-09-24)

The release-notes skill's description now agrees with its body that a tag here is a Composer constraint.

### Maintenance and tooling
- Say in the release-notes skill description that a tag here is a Composer constraint [#252](https://github.com/robot-council/cli/pull/252)

## v0.4.8 — Answering Every Failed Call (2026-09-24)

A tool call the bridge cannot relay now gets an error saying why, instead of leaving the harness waiting on it until its own timeout.

### What's fixed
- Answer a forwarded call the bridge could not relay, rather than leaving the harness waiting [#255](https://github.com/robot-council/cli/pull/255)

## v0.4.7 — Failing on a Mistyped Command (2026-09-24)

`robot-council` now fails, naming the command, when asked for one that does not exist, rather than printing the summary and exiting 0.

### What's fixed
- Fail on a command that does not exist, rather than printing the summary and exiting 0 [#251](https://github.com/robot-council/cli/pull/251)

## v0.4.6 — Installing Beside Other Global Tools (2026-09-24)

The README now recommends installing into a directory of its own, and records why a global install fails beside `statamic/cli` before 3.6.4.

### Maintenance and tooling
- Recommend installing `robot-council/cli` into a directory of its own, and record why a global install fails beside older `statamic/cli` [#250](https://github.com/robot-council/cli/pull/250)

## v0.4.5 — Only Its Own Commands (2026-09-24)

An installed `robot-council` now offers only its own six commands, rather than Laravel Zero's development commands beside them.

### What's fixed
- Run an installed `robot-council` as `production`, so it offers only its own commands [#247](https://github.com/robot-council/cli/pull/247)

## v0.4.4 — Telling an Agent Why Its Calls Stopped (2026-09-24)

When the fleet ends a session, every tool call the bridge could no longer serve now gets an error saying why, instead of failing as a closed connection.

### What's fixed
- Answer every call read after the fleet ended the session with the reason [#246](https://github.com/robot-council/cli/pull/246)

## v0.4.3 — Letting a Stopped Bridge Go (2026-09-24)

A bridge stopped while another process holds its fleet event sink now exits after about two seconds and says why, instead of waiting on the lock indefinitely.

### What's fixed
- Stop the shutdown sink clear holding a bridge open behind a stuck lock [#244](https://github.com/robot-council/cli/pull/244)

## v0.4.2 — Installs That Can Reach the Fleet (2026-09-24)

An install from Packagist can make HTTP requests again: every earlier release failed `enroll`, `mcp` and `api` at their first request, because a package they need arrived only through a development dependency.

### What's fixed
- Require `illuminate/http` in production, and check an install without dev dependencies in CI [#240](https://github.com/robot-council/cli/pull/240)

## v0.4.1 — Repeating a Missed Fleet Notice (2026-09-24)

A channel notice that lands while its session is mid-turn is no longer lost: the bridge announces the waiting fleet events again until they are read.

### What's fixed
- Announce a fleet event again when a channel notice left it undrained [#231](https://github.com/robot-council/cli/pull/231)

### Maintenance and tooling
- Say what a developer actually does to enroll and join [#235](https://github.com/robot-council/cli/pull/235)
- Point the skipped-call note at the open ticket rather than the closed fork [#233](https://github.com/robot-council/cli/pull/233)

## v0.4.0 — Joining on Purpose (2026-09-24)

The bridge no longer joins the fleet on launch: an agent joins when its operator asks it to, and opening an editor stops putting a session on the fleet.

**Breaking change** — review each harness's MCP configuration. A bridge started without `--auto-join` comes up offering one tool, `join`, and puts nothing on the fleet until an agent calls it. And read `robot-council api`'s errors from stderr, where stdout now carries only the response body.

### Breaking changes
- Join the fleet only when asked, instead of on harness launch [#206](https://github.com/robot-council/cli/pull/206). `robot-council mcp` starts with no session and no credential read, answers the handshake itself, and offers only `join`; `--auto-join` restores the old behavior for a checkout that should join at launch. A machine with no credential now comes up and says what is missing, where it used to exit.
- Send every `robot-council api` diagnostic to stderr, so stdout is only the response body [#220](https://github.com/robot-council/cli/pull/220). All six failure paths wrote to stdout, and on a non-2xx the status line landed immediately after the body. A caller that parsed stdout for the error text reads stderr now.

### What's new
- Tell an agent why its session stopped [#219](https://github.com/robot-council/cli/pull/219)
- Wake an idle Claude Code session when fleet events arrive for it [#197](https://github.com/robot-council/cli/pull/197)
- End the bridge when the fleet marks its session gone [#176](https://github.com/robot-council/cli/pull/176)

### What's fixed
- Stop claiming a session hears its own `session.gone` [#229](https://github.com/robot-council/cli/pull/229)
- Say a role request was denied, the word the administration page uses [#225](https://github.com/robot-council/cli/pull/225)
- Stop blaming the enrollment for a refusal the renewal before it disproved [#217](https://github.com/robot-council/cli/pull/217)
- Store the credential when `enroll` runs from a terminal on macOS [#214](https://github.com/robot-council/cli/pull/214)
- List every one of the fleet's tools in one reply, for a harness that reads only the first page [#213](https://github.com/robot-council/cli/pull/213)
- Make the harness re-read the tool list whenever the bridge starts [#212](https://github.com/robot-council/cli/pull/212)
- Keep an idle bridge running past a socket read timeout [#202](https://github.com/robot-council/cli/pull/202)
- Say what a swept session actually hit [#186](https://github.com/robot-council/cli/pull/186)

### Maintenance and tooling
- Add the `fleet-facing` and `housekeeping` priority labels to the issue-writing skill [#216](https://github.com/robot-council/cli/pull/216)
- Run the release generator's tests in CI [#210](https://github.com/robot-council/cli/pull/210)
- Warn when the release generator's pull-request query returns no data or a truncated closing-issue list [#200](https://github.com/robot-council/cli/pull/200)
- Audit the code this repository actually has [#195](https://github.com/robot-council/cli/pull/195)
- Stop the issue skill describing the other repository [#193](https://github.com/robot-council/cli/pull/193)
- Record that Cursor on macOS runs a `stop` hook `command` through `/bin/bash` [#191](https://github.com/robot-council/cli/pull/191)
- Take the duplicate-check fixes the other copy has [#192](https://github.com/robot-council/cli/pull/192)
- Keep merged head branches, so a pull request's file links keep working [#187](https://github.com/robot-council/cli/pull/187)
- Work from two long-lived worktree slots instead of a worktree per ticket [#182](https://github.com/robot-council/cli/pull/182)
- Port three findings that reached `robot-council/core`'s shared rules and not this repository [#183](https://github.com/robot-council/cli/pull/183)
- State the `GH_REPO` bound without naming a repository [#179](https://github.com/robot-council/cli/pull/179)
- Read the linked issue's type in the release cascade [#178](https://github.com/robot-council/cli/pull/178)
- Use the placeholders `gh` actually substitutes in every REST recipe [#177](https://github.com/robot-council/cli/pull/177)
- Take the repository from the checkout in the remaining `gh` recipes [#173](https://github.com/robot-council/cli/pull/173)
- Take the repository from the checkout in the pull-request skill [#168](https://github.com/robot-council/cli/pull/168)
- Stop the release cascade filing a test-heavy feature as maintenance [#163](https://github.com/robot-council/cli/pull/163)

## v0.3.0 — Roles Reach the Bridge (2026-09-24)

Roles reach the bridge: a session renews when its role changes, hears when the sweep marks it stale or gone, and a coordinator now sees the whole fleet's roles.

### What's new
- Let a coordinating session hear another session's role change [#159](https://github.com/robot-council/cli/pull/159)
- Renew when this session's role changes [#149](https://github.com/robot-council/cli/pull/149)
- Let a session holding `coordinator:direct` hear about other sessions [#120](https://github.com/robot-council/cli/pull/120)
- Send the repository and work location read from the checkout [#136](https://github.com/robot-council/cli/pull/136)
- Derive the repository and work location from the checkout [#132](https://github.com/robot-council/cli/pull/132)
- Say when nothing on the fleet can reach a waiting agent [#121](https://github.com/robot-council/cli/pull/121)

### What's fixed
- Tell a session the sweep marked it stale or gone [#158](https://github.com/robot-council/cli/pull/158)
- Stop reading `granted_abilities` from the enrollment response [#152](https://github.com/robot-council/cli/pull/152)
- Say which role a fleet is missing, not which ability [#144](https://github.com/robot-council/cli/pull/144)
- Start the reader without a shell on POSIX too [#145](https://github.com/robot-council/cli/pull/145)
- Count the session ending, and bound stopping the reader [#143](https://github.com/robot-council/cli/pull/143)
- Read stdin in a child so the bridge ticks while an agent is idle [#138](https://github.com/robot-council/cli/pull/138)
- Match the segment shapes core enforces, which tightened before it merged [#135](https://github.com/robot-council/cli/pull/135)
- Align the work identity bounds with the ones core enforces [#134](https://github.com/robot-council/cli/pull/134)
- Bind `advapi32` once per process, so a struct cannot outlive its type [#123](https://github.com/robot-council/cli/pull/123)
- Say why a process could not be started, instead of only that it could not [#119](https://github.com/robot-council/cli/pull/119)
- Read the legacy credential once per refusal, not twice [#110](https://github.com/robot-council/cli/pull/110)
- Answer a multi-key credential read in one `powershell.exe` invocation [#109](https://github.com/robot-council/cli/pull/109)
- Report the installed version, not `unreleased` [#107](https://github.com/robot-council/cli/pull/107)

### Maintenance and tooling
- Take the pull-request skill from `robot-council/core` verbatim [#154](https://github.com/robot-council/cli/pull/154)
- Say that delivery needs somebody to hold `coordinator:direct` [#114](https://github.com/robot-council/cli/pull/114)
- Execute the message `adopt()` gives when the cleanup cannot be checked [#108](https://github.com/robot-council/cli/pull/108)
- Let the mutation script run to completion [#106](https://github.com/robot-council/cli/pull/106)
- Require a batch to key each entry by the key it answers for [#105](https://github.com/robot-council/cli/pull/105)
- Document the published install, and what a pre-release flag does not do [#102](https://github.com/robot-council/cli/pull/102)

## v0.2.0 — The Fleet Follower and the Stop-Hook Handoff (2026-09-22)

The bridge now follows the fleet feed, keeps what concerns this session in a sink, and every supported harness has a documented stop hook that hands those events to the agent at a turn boundary.

### What's new
- Follow the fleet feed and leave what concerns this session [#70](https://github.com/robot-council/cli/pull/70)
- Call advapi32 through FFI where it is available [#64](https://github.com/robot-council/cli/pull/64)

### What's fixed
- Tell a missing Keychain item from a broken `security` [#86](https://github.com/robot-council/cli/pull/86)
- Make --peek read the sink instead of emptying it and writing it back [#80](https://github.com/robot-council/cli/pull/80)
- Refuse a service key Windows Credential Manager will not store [#67](https://github.com/robot-council/cli/pull/67)
- Bound what the helper writes to stderr, not just to stdout [#65](https://github.com/robot-council/cli/pull/65)

### Maintenance and tooling
- Carry the author address robot-council/core already uses [#97](https://github.com/robot-council/cli/pull/97)
- Declare the command line a library, not a project [#96](https://github.com/robot-council/cli/pull/96)
- Read several credentials in one call, behind an optional capability [#95](https://github.com/robot-council/cli/pull/95)
- Run the README's stop-hook script, so it cannot break unnoticed [#92](https://github.com/robot-council/cli/pull/92)
- Make a dead child report its own cause, and record the rate [#89](https://github.com/robot-council/cli/pull/89)
- Say why the stop-hook script tolerates a BOM, and correct which parsers do not [#87](https://github.com/robot-council/cli/pull/87)
- Cover re-enrolling over an existing Keychain credential, and settle four survivors [#82](https://github.com/robot-council/cli/pull/82)
- Run the Cursor stop hook against a live fleet [#81](https://github.com/robot-council/cli/pull/81)
- Stop routing security-area work to the Security heading [#79](https://github.com/robot-council/cli/pull/79)
- Describe this repository in the release-notes skill [#77](https://github.com/robot-council/cli/pull/77)
- Document a stop hook per harness that hands over waiting fleet events [#74](https://github.com/robot-council/cli/pull/74)
- Derive the repository the release notes are about [#68](https://github.com/robot-council/cli/pull/68)

## v0.1.0 — Enrollment, Credential Storage, and the MCP Bridge (2026-09-21)

The first release: enroll a machine once, keep its credential in the operating system's own store, and serve the fleet's coordination tools to an agent harness over stdio.

### What's new
- Start the robot-council command line [`a038fc2`](https://github.com/robot-council/cli/commit/a038fc2c6ba11e9dd3375cb88fbec1677141fe4a)
- Enroll a machine and keep the credential out of every transcript [#8](https://github.com/robot-council/cli/pull/8)
- Perform one API call inside a session the command starts and ends [#9](https://github.com/robot-council/cli/pull/9)
- Bridge MCP over stdio, holding the credential outside the agent [#10](https://github.com/robot-council/cli/pull/10)
- Scaffold a fleet service with `robot-council new` [#15](https://github.com/robot-council/cli/pull/15)
- Key the credential store by service and harness [#26](https://github.com/robot-council/cli/pull/26)
- Choose a credential by harness, refusing rather than inferring [#27](https://github.com/robot-council/cli/pull/27)
- Store the credential in Windows Credential Manager, without putting it in argv [#35](https://github.com/robot-council/cli/pull/35)

### What's fixed
- Fix the secret recipe, which stored the wrong value when pasted [#17](https://github.com/robot-council/cli/pull/17)
- Read the Keychain with -g, which says what the password actually is [#47](https://github.com/robot-council/cli/pull/47)
- Tell a broken mechanism from an absent credential [#55](https://github.com/robot-council/cli/pull/55)

### Security
- Verify that the legacy credential was actually forgotten [#45](https://github.com/robot-council/cli/pull/45)
- Take the interpreter and the script off the environment [#49](https://github.com/robot-council/cli/pull/49)
- Read the credential through a real pipe [#51](https://github.com/robot-council/cli/pull/51)

### Maintenance and tooling
- Point the README at the transferred issue [#2](https://github.com/robot-council/cli/pull/2)
- Document how each harness launches the bridge [#11](https://github.com/robot-council/cli/pull/11)
- Test that an idle bridge heartbeats [#12](https://github.com/robot-council/cli/pull/12)
- Add an install section, and correct the tool count [#14](https://github.com/robot-council/cli/pull/14)
- Say how to give the service its secrets, and why not from here [#16](https://github.com/robot-council/cli/pull/16)
- Document Cursor harness wiring in the README [#18](https://github.com/robot-council/cli/pull/18)
- Point enroll readers at enroll --help [#19](https://github.com/robot-council/cli/pull/19)
- Document multi-harness wiring and the refusal it produces [#28](https://github.com/robot-council/cli/pull/28)
- Say what to put in `--project`, and why not the folder name [#29](https://github.com/robot-council/cli/pull/29)
- Store and check out every text file as LF [#42](https://github.com/robot-council/cli/pull/42)
- Pin that the Keychain does not collapse keys differing only in case [#43](https://github.com/robot-council/cli/pull/43)
- Run the mutation plugin that was installed and never invoked [#44](https://github.com/robot-council/cli/pull/44)
- Close two gaps the blind-instrument rules do not cover [#48](https://github.com/robot-council/cli/pull/48)
- Ask cp1252 whether it can encode the character [#53](https://github.com/robot-council/cli/pull/53)
