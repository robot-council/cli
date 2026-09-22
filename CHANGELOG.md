# Changelog

All notable changes to `robot-council/cli` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
