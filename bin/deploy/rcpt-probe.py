#!/usr/bin/env python3
"""SMTP RCPT probe: can each address receive mail? Connects to the domain's MX, goes as far as RCPT TO, then
quits without sending anything. Expect 250. Usage: rcpt-probe.py [--from you@your-domain] [--mx host[:port]] addr..."""
import smtplib, subprocess, sys

args = sys.argv[1:]
opt = {"--from": "postmaster@example.invalid", "--mx": None}
while args and args[0] in opt:
    opt[args[0]], args = args[1], args[2:]

def mx_for(domain):
    try:
        out = subprocess.run(["dig", "+short", "MX", domain], capture_output=True, text=True).stdout
    except FileNotFoundError:
        sys.exit("dig not found (install dnsutils / bind-tools), or pass --mx host[:port]")
    return [h.rstrip(".") for _, h in sorted((int(p), h) for p, h in (l.split() for l in out.splitlines() if l.strip()))]

bad = 0
for addr in args:
    hosts = [opt["--mx"]] if opt["--mx"] else mx_for(addr.rsplit("@", 1)[1])
    if not hosts:
        print(f"{addr}: NO MX RECORDS"); bad += 1; continue
    host, _, port = hosts[0].partition(":")
    try:
        with smtplib.SMTP(host, int(port or 25), timeout=20) as s:
            s.ehlo_or_helo_if_needed(); s.mail(opt["--from"])
            code, msg = s.rcpt(addr); s.rset()
        ok = code == 250
        print(f"{addr}: {code} via {host} {'OK' if ok else 'PROBLEM: ' + msg.decode(errors='replace')}")
        bad += not ok
    except Exception as e:
        print(f"{addr}: could not probe {host}: {e}"); bad += 1
sys.exit(1 if bad else 0)
