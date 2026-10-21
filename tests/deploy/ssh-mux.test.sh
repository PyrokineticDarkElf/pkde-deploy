#!/usr/bin/env bash
#
# Test for the deploy workflow's "Deploy to the server" step: the REAL step script (cut out of deploy.yml) runs
# against a throwaway sshd on 127.0.0.1 with a stub activate-release.sh, and the sshd log is counted. The whole
# step (keyscan aside) must use ONE accepted SSH connection (mkdir, rsync, scp and the activation share a
# ControlMaster), the payload and the activation call must arrive, and no master may be left running.
# Skips (exit 0, says so) where sshd, ssh, rsync or a free port are not available. Nothing leaves the machine.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
skip() { echo "SKIP: $1"; exit 0; }
refuse() { echo "REFUSING TO RUN: $1 (nothing was changed)" >&2; exit 2; }

SSHD="$(command -v sshd || ls /usr/sbin/sshd 2>/dev/null | head -1)"
for tool in ssh ssh-keygen ssh-keyscan rsync scp; do command -v "$tool" >/dev/null 2>&1 || skip "$tool is not installed"; done
[ -n "$SSHD" ] && [ -x "$SSHD" ] || skip "sshd is not installed"

T="$(mktemp -d)" || refuse "mktemp failed"
[ -n "$T" ] && [ -d "$T" ] || refuse "mktemp did not create a sandbox"
case "$(cd "$T" && pwd -P)/" in "$REPO_ROOT"/*) refuse "the sandbox is inside the repository" ;; esac
T="$(cd "$T" && pwd -P)"
SSHD_PID=""
cleanup() { [ -z "$SSHD_PID" ] || kill "$SSHD_PID" 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT

# ssh and scp read their config, keys and known_hosts from the PASSWD home (not $HOME) and expand `~` in ControlPath there
# too, and scp runs /usr/bin/ssh directly, so a faithful test cannot be sandboxed: it uses the real ~/.ssh. So it only runs
# where that is disposable (CI, or a container: `docker run --rm -v "$PWD":/r:ro -w /r ubuntu:24.04 bash -c 'apt-get update
# -qq && apt-get install -y -qq rsync openssh-server openssh-client && mkdir -p /run/sshd && bash tests/deploy/ssh-mux.test.sh'`).
[ -n "${CI:-}" ] || [ -f /.dockerenv ] || [ -n "${SSH_MUX_TEST_DISPOSABLE_HOME:-}" ] || skip "uses the real ~/.ssh; run it in CI or a container (see the header)"
SSH_USER="$(id -un)"
[ "$(cd "$HOME" && pwd -P)" = "$(cd "$(eval echo "~$SSH_USER")" && pwd -P)" ] || skip "HOME is not the account's own home"
mkdir -p "$HOME/.ssh" "$T/server" "$T/work/bin/deploy" "$T/work/dist"
chmod 700 "$HOME/.ssh"
[ ! -e "$HOME/.ssh/id_ed25519" ] && [ ! -e "$HOME/.ssh/known_hosts" ] || skip "~/.ssh already has a key or known_hosts; not touching it"
CREATED_SSH_FILES="$HOME/.ssh/id_ed25519 $HOME/.ssh/id_ed25519.pub $HOME/.ssh/known_hosts"
cleanup() { [ -z "$SSHD_PID" ] || kill "$SSHD_PID" 2>/dev/null; rm -f $CREATED_SSH_FILES; rm -rf "$T"; }
ssh-keygen -q -t ed25519 -N '' -f "$HOME/.ssh/id_ed25519" || refuse "ssh-keygen failed"
ssh-keygen -q -t ed25519 -N '' -f "$T/server/host_key" || refuse "ssh-keygen failed"
cp "$HOME/.ssh/id_ed25519.pub" "$T/server/authorized_keys"; chmod 600 "$T/server/authorized_keys"

PORT=""
for candidate in $(seq 22500 22540); do
    (exec 3<>"/dev/tcp/127.0.0.1/$candidate") 2>/dev/null || { PORT="$candidate"; break; }
done
[ -n "$PORT" ] || skip "no free port"

cat > "$T/server/sshd_config" <<EOF
Port $PORT
ListenAddress 127.0.0.1
HostKey $T/server/host_key
PidFile $T/server/sshd.pid
AuthorizedKeysFile $T/server/authorized_keys
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
UsePAM no
StrictModes no
LogLevel VERBOSE
Subsystem sftp internal-sftp
EOF
"$SSHD" -D -e -f "$T/server/sshd_config" > "$T/server/sshd.log" 2>&1 &
SSHD_PID=$!
for _ in $(seq 1 50); do (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null && break; sleep 0.1; done
(exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null || { cat "$T/server/sshd.log" >&2; skip "a throwaway sshd could not be started here (needs a user that may run sshd)"; }

# The step's script, cut out of the workflow: everything under the first `run: |` of the "Deploy to the server" step.
awk '/- name: Deploy to the server/{f=1} f&&/^        run: \|/{r=1; next} r{print}' "$REPO_ROOT/.github/workflows/deploy.yml" | sed 's/^          //' > "$T/step.sh"
[ -s "$T/step.sh" ] || refuse "could not find the deploy step in deploy.yml"

# Stubs so nothing real runs: the activation script just records that it was called.
cp "$REPO_ROOT/bin/deploy/retry.sh" "$T/work/bin/deploy/retry.sh"
cat > "$T/work/bin/deploy/activate-release.sh" <<'EOF'
#!/usr/bin/env bash
echo "activated $*" >> "$(dirname "$0")/activated.log"
case "$*" in *" post") [ -f "$(dirname "$0")/fail-post" ] && exit 7 ;; esac
exit 0
EOF
echo payload > "$T/work/dist/index.php"

pass=0; fail=0
check() { if eval "$2"; then pass=$((pass + 1)); echo "  PASS  $1"; else fail=$((fail + 1)); echo "  FAIL  $1"; fi; }

export TOOLS="$T/work" SSH_HOST=127.0.0.1 SSH_PORT="$PORT" SSH_USER DEPLOY_PATH="$T/server/deploy" RETRY_PAUSE=0
( cd "$T/work" && bash "$T/step.sh" > "$T/step.log" 2>&1 )
rc=$?

echo "=== deploy step against a local sshd ==="
check "the step exits 0" "[ $rc -eq 0 ] || { tail -20 '$T/step.log' >&2; false; }"
check "the payload arrived in a new release directory" "[ \"\$(cat \"$T/server/deploy/releases\"/*/index.php 2>/dev/null)\" = payload ]"
check "the activation script was copied and called twice (activate, then post) with the path, the release and 5" "[ \"\$(grep -c '^activated ' \"$T/server/deploy/activated.log\" 2>/dev/null)\" = 2 ] && grep -Eq '^activated .*/deploy [0-9]{14} 5 activate\$' \"$T/server/deploy/activated.log\" && grep -Eq '^activated .*/deploy [0-9]{14} 5 post\$' \"$T/server/deploy/activated.log\""
check "each exec's duration is logged by the step" "grep -q 'Activation exec took [0-9]*s' '$T/step.log' && grep -q 'Post-steps exec took [0-9]*s' '$T/step.log'"
accepted="$(grep -c 'Accepted publickey' "$T/server/sshd.log")"
check "BOTH execs and the whole step used ONE accepted SSH connection (got $accepted)" "[ \"$accepted\" = 1 ]"
check "no master connection is left running" "! ssh -o ControlPath=~/.ssh/cm-%C -O check -p $PORT $SSH_USER@127.0.0.1 2>/dev/null"
check "the step multiplexes: ControlMaster=auto, ControlPath ~/.ssh/cm-%C, ControlPersist" "grep -q 'ControlMaster=auto' '$T/step.sh' && grep -q 'ControlPath=~/.ssh/cm-%C' '$T/step.sh' && grep -q 'ControlPersist=' '$T/step.sh'"

# a failing post exec warns and does not fail the deploy (the release is live after the first exec); a failing activate DOES fail it
touch "$T/server/deploy/fail-post"
( cd "$T/work" && bash "$T/step.sh" > "$T/step2.log" 2>&1 ); rc2=$?
check "a failing post exec only warns: the step still exits 0 and names the command to re-run" "[ $rc2 -eq 0 ] && grep -q '::warning::The post-steps exec failed' '$T/step2.log' && grep -q 'activate-release.sh .* post' '$T/step2.log'"

echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
