#!/usr/bin/env bash
#
# Sync the template's ADRs into a site: copies <template checkout>/docs/decisions/template/ to
# <site>/docs/decisions/template/ and reports site ADRs that duplicate a template ADR (same title), so they can be deleted and
# replaced by a pointer. It NEVER edits or deletes anything under <site>/docs/decisions/ except the template/ folder, and it
# never renumbers (template ADR 0004). Run from the site root:
#
#   bin/deploy/sync-template-adrs.sh /path/to/wordpress-bedrock-template [site-root=.]
#
# Exit 0 when synced (duplicates are only reported); 2 on a usage error.

set -uo pipefail
TEMPLATE="${1:-}"
SITE="${2:-.}"
[ -n "$TEMPLATE" ] && [ -d "$TEMPLATE/docs/decisions/template" ] || { echo "usage: $0 <template checkout> [site root]  (no docs/decisions/template/ there)" >&2; exit 2; }
[ -d "$SITE/docs/decisions" ] || mkdir -p "$SITE/docs/decisions" || exit 2

SRC="$TEMPLATE/docs/decisions/template"
DST="$SITE/docs/decisions/template"
mkdir -p "$DST" || exit 2
[ "$(cd "$SRC" && pwd -P)" != "$(cd "$DST" && pwd -P)" ] || { echo "source and destination are the same folder" >&2; exit 2; }

copied=0
for f in "$SRC"/*.md; do
    [ -e "$f" ] || continue
    if ! cmp -s "$f" "$DST/$(basename "$f")" 2>/dev/null; then cp "$f" "$DST/"; echo "synced   $(basename "$f")"; copied=$((copied + 1)); fi
done
# A template ADR deleted upstream is removed from the copy (the copy is read-only, so this is safe).
for f in "$DST"/*.md; do
    [ -e "$f" ] || continue
    [ -e "$SRC/$(basename "$f")" ] || { rm -f "$f"; echo "removed  $(basename "$f") (no longer in the template)"; }
done
echo "$copied template ADR file(s) updated"

title() { sed -n 's/^# //p' "$1" | head -1; }
dups=0
for t in "$SRC"/*.md; do
    tt="$(title "$t")"
    [ -n "$tt" ] && [ "$(basename "$t")" != 0000-template.md ] || continue
    for s in "$SITE"/docs/decisions/*.md; do
        [ -e "$s" ] || continue
        if [ "$(title "$s")" = "$tt" ]; then
            echo "DUPLICATE  $(basename "$s") repeats template/$(basename "$t"): delete it and leave a one-line pointer to docs/decisions/template/"
            dups=$((dups + 1))
        fi
    done
done
echo "$dups duplicate site ADR(s) found (reported only, nothing deleted)"
exit 0
