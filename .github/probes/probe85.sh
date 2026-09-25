#!/usr/bin/env bash
# cli#85: can `secret-tool lookup` tell a missing item from a failure? Prints exit codes, never secrets.
set -u
A=robot-council
N="probe85-$(date +%s)-$RANDOM"
rec() { local label=$1; shift; out=$("$@" 2>/tmp/p85.err); rc=$?; printf '%-38s rc=%-3s stdout_bytes=%-3s stderr=%s\n' "$label" "$rc" "${#out}" "$(tr '\n' ' ' </tmp/p85.err | cut -c1-160)"; }
echo "secret-tool: $(dpkg-query -W -f='${Version}' libsecret-tools 2>/dev/null) gnome-keyring: $(dpkg-query -W -f='${Version}' gnome-keyring 2>/dev/null) $(. /etc/os-release; echo "$PRETTY_NAME")"
printf 'probe-token-%s' "$N" | secret-tool store --label=robot-council $A "$N-present"; echo "store rc=$?"
rec "1 control: lookup present"            secret-tool lookup $A "$N-present"
rec "2 lookup absent"                      secret-tool lookup $A "$N-absent"
rec "3a bus unreachable (dead address)"    env DBUS_SESSION_BUS_ADDRESS=unix:path=/nonexistent/bus secret-tool lookup $A "$N-present"
rec "3b bus unset"                         env -u DBUS_SESSION_BUS_ADDRESS secret-tool lookup $A "$N-present"
rec "3c usage error (unknown subcommand)"  secret-tool frobnicate
# A reachable service that refuses: lock the default collection, then look up
if secret-tool lock --collection=login >/dev/null 2>&1 || secret-tool lock --collection=default >/dev/null 2>&1; then
  rec "3d locked collection, present item" secret-tool lookup $A "$N-present"
  rec "3e locked collection, absent item"  secret-tool lookup $A "$N-absent"
else
  echo "3d/3e: secret-tool lock unavailable ($(secret-tool lock 2>&1 | head -1))"
fi
# A reachable bus with no Secret Service on it: stop the keyring daemon
pkill -f gnome-keyring-daemon; sleep 1
rec "3f service gone, bus alive"           secret-tool lookup $A "$N-present"
echo "keyring after 3f: $(pgrep -fa gnome-keyring-daemon | head -1 || echo none)"
