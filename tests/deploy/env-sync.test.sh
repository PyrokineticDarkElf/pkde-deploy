#!/usr/bin/env bash
#
# Test for bin/deploy/env-pull.sh and env-seed.sh (WP-87) with a fake `op` and a fake `ssh` (it runs the REAL remote command in a temp directory): no network, no real
# secrets. The values are canary strings, so a leak into the output or onto a command line is caught. Checks: a dry run writes nothing and prints names only; an
# incomplete note is refused; --check; --apply keeps a backup, changes in place, appends new variables, keeps server-only variables unless --prune; quotes and ${...}
# round-trip; production needs --production-go; seed sends the item on stdin only and refuses an existing item.

set -uo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PULL="$REPO_ROOT/bin/deploy/env-pull.sh"; SEED="$REPO_ROOT/bin/deploy/env-seed.sh"
T="$(mktemp -d)" || { echo "mktemp failed" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/srv/shared"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (expected '$3', got '$2')"; fi; }
has() { if grep -qF -- "$3" <<<"$2"; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $1 (missing '$3')"; fi; }
lacks() { if grep -qF -- "$3" <<<"$2"; then fail=$((fail + 1)); echo "FAIL: $1 (leaked '$3')"; else pass=$((pass + 1)); fi; }

cat > "$T/bin/op" <<'SH'
#!/usr/bin/env bash
echo "op $*" >> "$FAKE_DIR/op.log"
if [ "$1 $2" = "item get" ]; then [ -f "$FAKE_DIR/item.json" ] && cat "$FAKE_DIR/item.json" && exit 0; exit 1; fi
if [ "$1 $2" = "item create" ]; then [ -t 0 ] && exit 5; cat > "$FAKE_DIR/created.json"; exit 0; fi
exit 1
SH
cat > "$T/bin/ssh" <<'SH'
#!/usr/bin/env bash
echo "ssh $*" >> "$FAKE_DIR/ssh.log"
cmd="${@: -1}"
exec bash -c "$cmd"
SH
chmod +x "$T/bin/op" "$T/bin/ssh"
export FAKE_DIR="$T" PATH="$T/bin:$PATH" STAGING_SSH_HOST=h STAGING_SSH_PORT=22 STAGING_SSH_USER=u STAGING_DEPLOY_PATH="$T/srv" PROD_SSH_HOST=h PROD_SSH_PORT=22 PROD_SSH_USER=u PROD_DEPLOY_PATH="$T/srv"

SALT='canary-salt-value'; DBPW='canary-db-password'; SMTP='canary-smtp-pw'
SRV="$T/srv/shared/.env"
cat > "$SRV" <<EOF
# server file
DB_NAME=wp
DB_USER=wpuser
DB_PASSWORD='old-db-password'
DB_HOST=localhost
WP_ENV=staging
WP_HOME=http://localhost
WP_SITEURL="\${WP_HOME}/wp"
SERVER_ONLY=keepme
AUTH_KEY='$SALT'
SECURE_AUTH_KEY='$SALT'
LOGGED_IN_KEY='$SALT'
NONCE_KEY='$SALT'
AUTH_SALT='$SALT'
SECURE_AUTH_SALT='$SALT'
LOGGED_IN_SALT='$SALT'
NONCE_SALT='$SALT'
EOF
cat > "$T/example" <<'EOF'
# Database (used by both)
DB_NAME=wordpress
DB_USER=wordpress

# Outgoing mail through Post SMTP (only if the site sends mail)
SMTP_PASSWORD=
EOF

# the note: the same values except DB_PASSWORD (changed) and SMTP_PASSWORD (added); no SERVER_ONLY
python3 - "$T" "$SALT" "$DBPW" "$SMTP" <<'PY'
import json, sys
t, salt, dbpw, smtp = sys.argv[1:]
f = lambda k, v, s='Other': {'id': k, 'label': k, 'type': 'CONCEALED', 'value': v, 'section': {'id': 's', 'label': s}}
fields = [f('DB_NAME', 'wp'), f('DB_USER', 'wpuser'), f('DB_PASSWORD', dbpw), f('DB_HOST', 'localhost'), f('WP_ENV', 'staging'),
          f('WP_HOME', 'http://localhost'), f('WP_SITEURL', '"${WP_HOME}/wp"'), f('SMTP_PASSWORD', smtp)]
fields += [f(k, "'" + salt + "'") for k in ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT']]
fields.append({'id': 'notesPlain', 'label': 'notesPlain', 'type': 'STRING', 'value': 'ignore me'})
json.dump({'title': 'site.test .env', 'fields': fields}, open(t + '/item.json', 'w'))
PY
cp "$T/item.json" "$T/item.good.json"
before="$(cat "$SRV")"
ARGS=(site.test --env staging --vault V --account A --example "$T/example")

# 1. dry run: names only, nothing written
out="$("$PULL" "${ARGS[@]}" 2>&1)"; rc=$?
check "dry run exit" "$rc" "0"
has "dry run lists the change" "$out" "to change (1): DB_PASSWORD"
has "dry run lists the addition" "$out" "to add (1): SMTP_PASSWORD"
has "dry run keeps server-only" "$out" "on the server only, kept (use --prune to remove) (1): SERVER_ONLY"
has "dry run says nothing was written" "$out" "Dry run: nothing was written"
check "dry run left the file alone" "$(cat "$SRV")" "$before"
for canary in "$SALT" "$DBPW" "$SMTP" old-db-password; do lacks "no value in output ($canary)" "$out" "$canary"; done
lacks "no value on a command line (op)" "$(cat "$T/op.log")" "$DBPW"
lacks "no value on a command line (ssh)" "$(cat "$T/ssh.log")" "$DBPW"
has "account is passed to op" "$(cat "$T/op.log")" "--account A"

# 2. an incomplete note is refused
python3 - "$T" <<'PY'
import json, sys
p = sys.argv[1] + '/item.json'; d = json.load(open(p))
for f in d['fields']:
    if f['label'] == 'DB_PASSWORD': f['value'] = ''
json.dump(d, open(p, 'w'))
PY
out="$("$PULL" "${ARGS[@]}" --apply 2>&1)"; rc=$?
check "empty required: refused" "$rc" "1"
has "names the empty variable" "$out" "required and empty or missing in the note (1): DB_PASSWORD"
check "refused run left the file alone" "$(cat "$SRV")" "$before"
cp "$T/item.good.json" "$T/item.json"

# 3. --check differs; flags that conflict
out="$("$PULL" "${ARGS[@]}" --check 2>&1)"; rc=$?
check "check differs: exit 1" "$rc" "1"
has "check says DIFFERS" "$out" "DIFFERS"
"$PULL" "${ARGS[@]}" --check --apply >/dev/null 2>&1; check "check with apply: usage error" "$?" "2"

# 4. production needs --production-go
"$PULL" site.test --env prod --vault V --example "$T/example" --apply >/dev/null 2>&1; check "prod apply without --production-go" "$?" "2"
check "prod refusal left the file alone" "$(cat "$SRV")" "$before"

# 5. apply
out="$("$PULL" "${ARGS[@]}" --apply 2>&1)"; rc=$?
check "apply exit" "$rc" "0"
has "apply says written" "$out" "Written. The previous file is"
has "changed value written" "$(cat "$SRV")" "DB_PASSWORD=$DBPW"
has "added variable appended" "$(cat "$SRV")" "SMTP_PASSWORD=$SMTP"
has "interpolation kept verbatim" "$(cat "$SRV")" 'WP_SITEURL="${WP_HOME}/wp"'
has "server-only variable kept" "$(cat "$SRV")" "SERVER_ONLY=keepme"
has "comment kept" "$(cat "$SRV")" "# server file"
check "one backup" "$(ls -a "$T/srv/shared" | grep -c '^\.env\.bak-')" "1"
has "backup is the old file" "$(cat "$T"/srv/shared/.env.bak-*)" "old-db-password"
for canary in "$SALT" "$DBPW" "$SMTP"; do lacks "apply output has no value ($canary)" "$out" "$canary"; done
lacks "apply: no value on the ssh command line" "$(cat "$T/ssh.log")" "$DBPW"

# 6. SERVER_ONLY is still only on the server, so check differs until pruned; then it matches
"$PULL" "${ARGS[@]}" --check >/dev/null 2>&1; check "check after apply still sees SERVER_ONLY" "$?" "1"
"$PULL" "${ARGS[@]}" --apply --prune >/dev/null 2>&1; check "prune apply exit" "$?" "0"
lacks "pruned" "$(cat "$SRV")" "SERVER_ONLY"
out="$("$PULL" "${ARGS[@]}" --check 2>&1)"; rc=$?
has "check says MATCHES" "$out" "MATCHES"; check "check matches: exit 0" "$rc" "0"
out="$("$PULL" "${ARGS[@]}" --apply 2>&1)"; has "second apply writes nothing" "$out" "Nothing to write"

# 7. a variable in .env.example but not in the note is reported
printf 'MISSING_ONE=\n' >> "$T/example"
out="$("$PULL" "${ARGS[@]}" --check 2>&1)"; rc=$?
has "reports a variable the note lacks" "$out" "in .env.example but missing from the note (1): MISSING_ONE"; check "that makes check fail" "$rc" "1"

# 8. a multi-line value is refused
python3 - "$T" <<'PY'
import json, sys
p = sys.argv[1] + '/item.json'; d = json.load(open(p))
for f in d['fields']:
    if f['label'] == 'SMTP_PASSWORD': f['value'] = 'a\nb'
json.dump(d, open(p, 'w'))
PY
out="$("$PULL" "${ARGS[@]}" --apply 2>&1)"; check "multi-line refused" "$?" "1"; has "names it" "$out" "value spans several lines (1): SMTP_PASSWORD"

# 9. seed: dry run, create (stdin only), refuse an existing item
rm -f "$T/item.json"; : > "$T/op.log"
out="$("$SEED" "${ARGS[@]}" --dry-run 2>&1)"; rc=$?
check "seed dry run exit" "$rc" "0"
has "seed dry run counts" "$out" "site.test .env: "
has "seed dry run says nothing created" "$out" "Dry run: nothing was created."
check "seed dry run created nothing" "$([ -f "$T/created.json" ] && echo yes || echo no)" "no"
out="$("$SEED" "${ARGS[@]}" 2>&1)"; rc=$?
check "seed exit" "$rc" "0"
has "seed names the section from .env.example" "$(cat "$T/created.json")" '"label": "Database"'
has "seed item is a secure note" "$(cat "$T/created.json")" '"category": "SECURE_NOTE"'
has "seed carries the literal right-hand side" "$(cat "$T/created.json")" '\"${WP_HOME}/wp\"'
has "seed value reached op through stdin" "$(cat "$T/created.json")" "$DBPW"
for canary in "$SALT" "$DBPW" "$SMTP"; do lacks "seed output has no value ($canary)" "$out" "$canary"; lacks "seed: op command line has no value ($canary)" "$(cat "$T/op.log")" "$canary"; done
cp "$T/created.json" "$T/item.json"
"$SEED" "${ARGS[@]}" >/dev/null 2>&1; check "seed refuses an existing item" "$?" "1"

# 10. a missing ssh name is a usage error
(unset STAGING_SSH_HOST; "$PULL" "${ARGS[@]}" >/dev/null 2>&1; check "missing STAGING_SSH_HOST" "$?" "2")

echo "env-sync: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
