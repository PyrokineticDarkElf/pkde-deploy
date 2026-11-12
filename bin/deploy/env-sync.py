#!/usr/bin/env python3
"""
Keeps a site's server .env and its 1Password secure note in step (WP-87). Called by env-pull.sh and env-seed.sh; see those for usage.

The note: one SECURE_NOTE per site and environment, titled "<host> .env", one field per variable, the field's label is the variable name and its value is
the LITERAL right-hand side of the .env line (quotes and ${...} included), so a pull and a seed lose nothing. Sections group the fields like .env.example.

Rules (all of them are about not breaking a live site or leaking a value):
  * Values are never printed, never put on a command line, never written to disk here. They travel op -> this process -> ssh stdin (pull) or
    ssh stdout -> this process -> op stdin (seed). Output names variables only.
  * pull is a DRY RUN unless --apply. It refuses (nothing written) when a required variable (database, URLs, salts) is empty or missing in the note, or a value
    spans lines. It adds and changes; it removes a server variable only with --prune. --check changes nothing and exits 1 when the server and the note differ (variables only in .env.example are listed as INFO and do not count).
  * --apply keeps the previous file as .env.bak-<time>, writes a new one with umask 077 and moves it into place, then requests WP_HOME: a 5xx or no answer
    is reported with the one-line rollback. Production needs --production-go on top (the owner's go is a human step, this is only the speed bump).
"""
import argparse
import datetime
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

REQUIRED = ['WP_ENV', 'WP_HOME', 'WP_SITEURL', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST',
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT']
LINE_RE = re.compile(r'^(export\s+)?([A-Z][A-Z0-9_]*)=(.*)$')
KEY_RE = re.compile(r'^[A-Z][A-Z0-9_]*$')
SAFE_PATH = re.compile(r'^[A-Za-z0-9_./~-]+$')


def die(msg, code=1):
    print(f'env-sync: {msg}', file=sys.stderr)
    sys.exit(code)


def parse_env(text):
    """[(key, rhs)] in file order (comments and blank lines skipped)."""
    out = []
    for line in text.splitlines():
        m = LINE_RE.match(line)
        if m:
            out.append((m.group(2), m.group(3)))
    return out


def example_sections(text):
    """key -> section label: the first comment line of the comment block above the key in .env.example."""
    sections, heading, prev_blank = {}, 'Other', True
    for line in text.splitlines():
        s = line.strip()
        if s == '':
            prev_blank = True
            continue
        if s.startswith('#'):
            if prev_blank:
                heading = re.sub(r'\s*[(:—-].*$', '', s.lstrip('# ').strip())[:60].rstrip('. ') or 'Other'
            prev_blank = False
            continue
        prev_blank = False
        m = LINE_RE.match(s)
        if m:
            sections[m.group(2)] = heading
    return sections


def note_vars(item):
    """(ordered {label: value}, {label: section}) from `op item get --format json`."""
    values, sections = {}, {}
    for f in item.get('fields', []):
        label = f.get('label') or ''
        if KEY_RE.match(label):
            values[label] = f.get('value') or ''
            sections[label] = (f.get('section') or {}).get('label') or ''
    return values, sections


def note_problems(values):
    """Names that stop a pull: required and empty/missing, or a value over several lines."""
    empty = [k for k in REQUIRED if values.get(k, '') == '']
    multiline = [k for k, v in values.items() if '\n' in v or '\r' in v]
    return empty, multiline


def diff(server, note, prune):
    added = [k for k in note if k not in server]
    changed = [k for k in note if k in server and server[k] != note[k]]
    extra = [k for k in server if k not in note]
    return {'added': added, 'changed': changed, 'removed': extra if prune else [], 'kept': [] if prune else extra}


def render(server_text, note, prune, stamp):
    """The new .env: the server's file with changed values replaced in place, new variables appended, removed ones dropped only with prune."""
    out, seen = [], set()
    for line in server_text.splitlines():
        m = LINE_RE.match(line)
        if not m:
            out.append(line)
            continue
        key = m.group(2)
        seen.add(key)
        if key in note:
            out.append(f'{m.group(1) or ""}{key}={note[key]}')
        elif not prune:
            out.append(line)
    added = [k for k in note if k not in seen]
    if added:
        out += ['', f'# Added from 1Password on {stamp}'] + [f'{k}={note[k]}' for k in added]
    return '\n'.join(out) + '\n'


def ssh_base(env):
    prefix = {'staging': 'STAGING', 'prod': 'PROD'}[env]
    cfg = {}
    for name in ('SSH_HOST', 'SSH_PORT', 'SSH_USER', 'DEPLOY_PATH'):
        v = os.environ.get(f'{prefix}_{name}', '')
        if not v:
            die(f'set {prefix}_{name} in your shell first (same value as the GitHub secret)', 2)
        cfg[name] = v
    if not SAFE_PATH.match(cfg['DEPLOY_PATH']):
        die(f'{prefix}_DEPLOY_PATH has characters this tool will not put in a remote command', 2)
    cmd = ['ssh', '-p', cfg['SSH_PORT'], '-o', 'ControlMaster=auto', '-o', 'ControlPath=~/.ssh/cm-%C', '-o', 'ControlPersist=10m']
    cmd += os.environ.get('PKDE_SSH_OPTS', '').split()
    cmd.append(f"{cfg['SSH_USER']}@{cfg['SSH_HOST']}")
    return cmd, cfg['DEPLOY_PATH']


def run(cmd, data=None):
    p = subprocess.run(cmd, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return p.returncode, p.stdout, p.stderr


def op_cmd(args, *rest):
    cmd = ['op'] + list(rest)
    if args.account:
        cmd += ['--account', args.account]
    return cmd


def title_of(args):
    return args.item or f'{args.host} .env'


def read_server_env(args):
    base, path = ssh_base(args.env)
    rc, out, err = run(base + [f"cat -- '{path}/shared/.env'"])
    if rc != 0:
        die(f"could not read {path}/shared/.env over ssh (exit {rc}): {err.decode('utf-8', 'replace').strip()[:200]}")
    return out.decode('utf-8')


def read_example(args):
    try:
        return open(args.example, encoding='utf-8').read()
    except OSError:
        return ''


def names(label, items):
    if items:
        print(f'  {label} ({len(items)}): {", ".join(items)}')


def pull(args):
    rc, out, err = run(op_cmd(args, 'item', 'get', title_of(args), '--vault', args.vault, '--format', 'json'))
    if rc != 0:
        die(f'could not read the 1Password item "{title_of(args)}" in vault "{args.vault}": {err.decode("utf-8", "replace").strip()[:200]}')
    values, _ = note_vars(json.loads(out))
    empty, multiline = note_problems(values)
    if empty or multiline:
        print('REFUSED: nothing was written. The note is not complete enough to become a live .env.')
        names('required and empty or missing in the note', empty)
        names('value spans several lines', multiline)
        sys.exit(1)

    server_text = read_server_env(args)
    server = dict(parse_env(server_text))
    d = diff(server, values, args.prune)
    example = [k for k, _ in parse_env(read_example(args))]
    not_in_note = [k for k in example if k not in values]

    mode = 'check' if args.check else ('apply' if args.apply else 'dry run')
    print(f'{args.host} ({args.env}), {mode}: {len(values)} variables in the note, {len(server)} on the server')
    names('to add', d['added'])
    names('to change', d['changed'])
    names('to remove (--prune)', d['removed'])
    names('on the server only, kept (use --prune to remove)', d['kept'])
    names('INFO: in .env.example but not in the note (not a difference: a site need not use every example variable)', not_in_note)
    differs = bool(d['added'] or d['changed'] or d['removed'] or d['kept'])

    if args.check:
        print('DIFFERS' if differs else 'MATCHES')
        sys.exit(1 if differs else 0)
    if not (d['added'] or d['changed'] or d['removed']):
        print('Nothing to write: the server .env already has every value of the note.')
        return
    if not args.apply:
        print('Dry run: nothing was written. Re-run with --apply to write it.')
        return
    if args.env == 'prod' and not args.production_go:
        die('production also needs --production-go (the owner\'s go for this production write)', 2)

    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    new_text = render(server_text, values, args.prune, stamp)
    base, path = ssh_base(args.env)
    bak = f'.env.bak-{stamp}'
    remote = (f"umask 077; cd '{path}/shared' && test -f .env && cp -p .env {bak} && cat > .env.new && "
              f"{{ chmod --reference=.env .env.new 2>/dev/null || true; }} && mv .env.new .env")
    rc, _, err = run(base + [remote], new_text.encode('utf-8'))
    if rc != 0:
        die(f'the write failed (exit {rc}); the old .env is untouched or restorable from {bak}: {err.decode("utf-8", "replace").strip()[:200]}')
    print(f'Written. The previous file is {path}/shared/{bak}.')
    probe(values.get('WP_HOME', ''), f"cp -p '{path}/shared/{bak}' '{path}/shared/.env'")


def probe(home, rollback):
    url = home.strip().strip('"\'')
    if not url.startswith('http') or '${' in url or 'localhost' in url:
        print('No probe (WP_HOME is not a public URL).')
        return
    try:
        code = urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'pkde-env-pull'}), timeout=25).status
    except urllib.error.HTTPError as e:
        code = e.code
    except Exception:
        code = 0
    if code and code < 500:
        print(f'Probe: {url} answered {code}.')
        return
    print(f'PROBE FAILED: {url} answered {code or "nothing"}. Roll back on the server with: {rollback}')
    sys.exit(1)


def seed(args):
    title = title_of(args)
    rc, _, _ = run(op_cmd(args, 'item', 'get', title, '--vault', args.vault, '--format', 'json'))
    if rc == 0:
        die(f'the item "{title}" already exists in vault "{args.vault}": this tool only creates (edit the note in 1Password)')
    pairs = parse_env(read_server_env(args))
    if not pairs:
        die('the server .env has no variables')
    sections = example_sections(read_example(args))
    section_ids, fields, values = {}, [], dict(pairs)
    for key, rhs in values.items():
        label = sections.get(key, 'Other')
        sid = section_ids.setdefault(label, f's{len(section_ids) + 1}')
        fields.append({'id': key, 'label': key, 'type': 'CONCEALED', 'value': rhs, 'section': {'id': sid, 'label': label}})
    template = {
        'title': title,
        'category': 'SECURE_NOTE',
        'tags': ['wordpress', 'env', args.host, 'staging' if args.env == 'staging' else 'production'],
        'sections': [{'id': sid, 'label': label} for label, sid in section_ids.items()],
        'fields': [{'id': 'notesPlain', 'type': 'STRING', 'purpose': 'NOTES', 'label': 'notesPlain',
                    'value': 'Created from the server .env by env-seed.sh. Edit the fields here, then run env-pull.sh. One field per variable; the value is the literal right-hand side of the .env line.'}] + fields,
    }
    print(f'{title}: {len(fields)} variables in {len(section_ids)} sections: {", ".join(section_ids)}')
    names('required and empty on the server', [k for k in REQUIRED if values.get(k, '') == ''])
    if args.dry_run:
        print('Dry run: nothing was created.')
        return
    rc, _, err = run(op_cmd(args, 'item', 'create', '--vault', args.vault), json.dumps(template).encode('utf-8'))
    if rc != 0:
        die(f'op could not create the item (exit {rc}): {err.decode("utf-8", "replace").strip()[:200]}')
    print(f'Created "{title}" in vault "{args.vault}". Check it with: env-pull.sh {args.host} --env {args.env} --vault "{args.vault}" --check')


def main():
    ap = argparse.ArgumentParser(prog='env-sync')
    ap.add_argument('cmd', choices=['pull', 'seed'])
    ap.add_argument('host')
    ap.add_argument('--env', required=True, choices=['staging', 'prod'])
    ap.add_argument('--vault', required=True)
    ap.add_argument('--account')
    ap.add_argument('--item')
    ap.add_argument('--example', default='.env.example')
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('--prune', action='store_true')
    ap.add_argument('--check', action='store_true')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--production-go', action='store_true')
    args = ap.parse_args()
    if args.check and (args.apply or args.prune):
        die('--check changes nothing: it cannot be combined with --apply or --prune', 2)
    {'pull': pull, 'seed': seed}[args.cmd](args)


if __name__ == '__main__':
    main()
