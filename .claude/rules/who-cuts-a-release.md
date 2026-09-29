# Rule — only the release seat cuts a release, and only at a checkpoint

A release is cut by **one seat**, at **one of three times a day**, under **one lock**. Every other seat merges pull requests and leaves the tag alone. The mechanics of cutting one (the notes, the changelog pull request, the tag) are [`writing-release-notes`](../skills/writing-release-notes/SKILL.md); this file says who may start them, and when.

## Why this is a standing order

**The release rule used to live only in the coordinator's reference and in directives**, and a directive reaches only the seats running when it is sent. On 2026-09-25 seats were told to release after every merged pull request. The eight-hour checkpoint replaced that the same evening, but only by a relayed directive, so a seat that started afterwards had nothing telling it the older instruction was gone. It could follow that instruction and release off-schedule.

**The lock does not prevent that.** It stops two releases racing for one version number, not a release at the wrong time. A seat that takes the lock at 11:00 holds it legitimately and cuts a release nobody scheduled.

## Per repository

This file is shared, byte for byte, by `robot-council/core` and `robot-council/cli`. Everything that differs between them lives in this table.

| | `robot-council/core` | `robot-council/cli` |
| --- | --- | --- |
| Lock | `release:robot-council/core` | `release:robot-council/cli` |
| After the release is published | the same seat updates the app, deploys, and runs doctor | per-machine upgrades are placed as separate tasks; installing stays with each machine's operator |

## How to apply

1. **Who: this repository's release seat, working in slot `a`.** The coordinator names the seat. Any seat may merge a pull request that is green and checked, per [`pre-merge-check`](pre-merge-check.md), but no other seat tags or publishes a release.

2. **When: at a release checkpoint, on the coordinator's checkpoint directive.** The checkpoints are **08:00, 16:00 and 00:00 Central**. Any merge since the latest tag qualifies, including a docs-only one. **The one exception is an urgent fix for the running fleet, or a security fix.** Either may be released at any time, once the coordinator confirms it. A release seat that thinks something qualifies asks the coordinator. The seat does not decide that alone.

3. **How: hold the lock for the whole cut.**
   - Take the lock from the table **before reading the latest tag**, and release it only once the GitHub release is published. The version number is decided by that read, so a lock taken after it guards nothing.
   - **Tag only after `ci-passed` succeeds on the changelog pull request's merge commit.** The tag goes on that commit, per the skill.
   - Every release is flagged as a pre-release until `1.0.0`.
   - **The wiki step waits for the coordinator's approval**, and is pushed only after the release is published.
   - Then the step after publishing, from the table.

4. **Before acting: read whether the release already exists.** A checkpoint directive is a state to reach, not a trigger. Delivery is at least once, and a directive can arrive both through `events_read` and through the stop hook, with nothing marking the repeat. So before cutting, read the latest tag and release, and list what has merged since. If a release covering those merges already exists, do nothing.

   ```bash
   git fetch --tags origin &&
     git describe --tags --abbrev=0 origin/main &&
     git log --oneline "$(git describe --tags --abbrev=0 origin/main)"..origin/main
   ```

   Empty output from the last command means nothing merged since the tag, so there is nothing to release. Read the tag the second command printed, which proves the read ran, before believing that empty result, per [`an-empty-result-is-not-evidence`](an-empty-result-is-not-evidence.md).

5. **Superseded: "release after every merged pull request" (2026-09-25) no longer applies.** A directive, note, or message that says it is older than this file.

## The DRY line

This file owns **who cuts a release, and when**. How to write and publish one is [`writing-release-notes`](../skills/writing-release-notes/SKILL.md); whether a pull request may merge is [`pre-merge-check`](pre-merge-check.md); where the release seat works is [`worktrees`](worktrees.md). None of those are restated here.
