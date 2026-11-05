#!/usr/bin/env bash
#
# Loads a Scratchpads (Drupal 7) database dump on the hosting server as the
# migration source of a Taxanotes instance: database s_<slug>, which
# scripts/hosting/new-instance.sh reads through a SELECT-only user.
#
#   scripts/hosting/load-source-db.sh <slug> <dump.sql|dump.sql.gz> [--replace]
#
# Runs on the server as a user with passwordless sudo (root MariaDB access is
# the unix socket). The database is created when missing and loaded only
# while it is empty; --replace drops and reloads it. The dump holds the D7
# users table (names, mail addresses, password hashes): keep the file 0600,
# delete it after loading, never put it under the web root.
#
# A mysqldump from MySQL 5.6/5.7 loads into MariaDB as is. The stream passes
# through sed for the two things a MySQL 8 dump adds that MariaDB rejects:
# the utf8mb4_0900_* collations (-> utf8mb4_unicode_ci) and the
# /*!80016 DEFAULT ENCRYPTION='N' */ clause (dropped). NO_AUTO_CREATE_USER in
# a dump's SQL_MODE line is accepted by MariaDB and left alone.
set -euo pipefail

usage() {
  echo "usage: $0 <slug> <dump.sql|dump.sql.gz> [--replace]" >&2
  exit 2
}
log() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die() { printf 'load-source-db: %s\n' "$*" >&2; exit 1; }

slug=""
dump=""
replace=0
for arg in "$@"; do
  case "$arg" in
    --replace) replace=1 ;;
    -*) usage ;;
    *) if [ -z "$slug" ]; then slug="$arg"; elif [ -z "$dump" ]; then dump="$arg"; else usage; fi ;;
  esac
done
[ -n "$slug" ] && [ -n "$dump" ] || usage
[[ "$slug" =~ ^[a-z][a-z0-9_]*$ ]] || die "slug '$slug' must be lowercase letters, digits and underscores"
[ -f "$dump" ] || die "$dump does not exist"
command -v mariadb > /dev/null || die "mariadb is not on PATH"
db="s_$slug"

log "Source database $db from $dump"
tables="$(sudo mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db'" | tr -dc '0-9')"
if [ "${tables:-0}" -gt 0 ]; then
  if [ "$replace" -eq 1 ]; then
    note "--replace: dropping $db ($tables tables)"
    sudo mariadb -e "DROP DATABASE \`$db\`"
  else
    die "$db already holds $tables tables; pass --replace to drop and reload it"
  fi
fi
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
note "database created (utf8mb4_unicode_ci)"

start="$(date +%s)"
case "$dump" in
  *.gz) reader=(gzip -dc "$dump") ;;
  *) reader=(cat "$dump") ;;
esac
"${reader[@]}" \
  | sed -E -e 's/utf8mb4_0900_[a-z_]+/utf8mb4_unicode_ci/g' -e 's#/\*!80016 DEFAULT ENCRYPTION=[^*]*\*/##g' \
  | sudo mariadb --default-character-set=utf8mb4 "$db"
note "loaded in $(( $(date +%s) - start )) s"

log "Contents of $db"
tables="$(sudo mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db'" | tr -dc '0-9')"
note "$tables tables"
sudo mariadb -e "SELECT v.machine_name AS vocabulary, COUNT(t.tid) AS terms FROM \`$db\`.taxonomy_vocabulary v LEFT JOIN \`$db\`.taxonomy_term_data t ON t.vid = v.vid GROUP BY v.vid ORDER BY terms DESC LIMIT 12"
sudo mariadb -N -e "SELECT CONCAT('    users: ', COUNT(*)) FROM \`$db\`.users WHERE uid > 0"
note "next: scripts/hosting/new-instance.sh $slug <version> ... creates the read-only user and runs the migrations"
note "then delete the dump: rm $dump"
