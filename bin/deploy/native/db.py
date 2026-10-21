"""Database access shared by the native-block tools. Python 3, no dependencies. Everything goes through `docker compose exec db`, so
it works on the local stack only; nothing here ever touches a server.

The table prefix is a site constant, never hard-coded: pass --table-prefix wp_ (a tool's own option), or it is read from DB_PREFIX in
.env, default wp_. Writes keep the previous content as a WordPress revision first (save)."""
import os, re, subprocess, sys
from pathlib import Path

# The SITE's root: where `docker compose` runs and the theme lives. These tools are installed elsewhere (vendor/pkde/deploy), so it is
# the current directory (or PKDE_SITE_ROOT), never a path relative to this file.
ROOT = Path(os.environ.get('PKDE_SITE_ROOT') or Path.cwd()).resolve()
THEME = ROOT / 'web/app/themes/sage'


def option(name, default=None, argv=None):
    """The value after --name in argv (default sys.argv), else default."""
    argv = sys.argv if argv is None else argv
    return argv[argv.index(f'--{name}') + 1] if f'--{name}' in argv else default


def table_prefix(argv=None):
    given = option('table-prefix', argv=argv)
    if given:
        return given
    env = ROOT / '.env'
    found = re.search(r'^DB_PREFIX=["\']?([A-Za-z0-9_]+)', env.read_text(), re.M) if env.is_file() else None
    return found.group(1) if found else 'wp_'


def mysql(query, argv=None):
    return subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c',
                           'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -r -B --default-character-set=utf8mb4 "$MYSQL_DATABASE"'],
                          cwd=ROOT, input=query, capture_output=True, text=True, check=True).stdout


def sql(query):
    return mysql(query).strip()


def source(post_id):
    """The saved post_content of a post, newlines restored (mysql -r prints them escaped)."""
    out = mysql(f'select post_content from {table_prefix()}posts where ID={int(post_id)}')
    return out.rstrip('\n').replace('\\n', '\n')


def save(post_id, new):
    """Keep the current content as a revision, then write the new content."""
    t = table_prefix()
    sql(f"""SET NAMES utf8mb4; SET SESSION sql_mode=NO_ENGINE_SUBSTITUTION;
INSERT INTO {t}posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
SELECT post_author,NOW(),UTC_TIMESTAMP(),post_content,post_title,post_excerpt,'inherit','closed','closed','',CONCAT(ID,'-revision-v1'),'','',NOW(),UTC_TIMESTAMP(),'',ID,'',0,'revision','',0 FROM {t}posts WHERE ID={int(post_id)};
UPDATE {t}posts SET post_content=0x{new.encode('utf-8').hex()}, post_modified=NOW(), post_modified_gmt=UTC_TIMESTAMP() WHERE ID={int(post_id)};""")
