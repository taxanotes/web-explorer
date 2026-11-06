#!/usr/bin/env bash
#
# Creates (or brings up to date) a local Taxanotes instance from its
# definition, as a Drupal multisite of the ddev project in this checkout.
#
#   scripts/new-instance.sh <slug> [--reinstall]
#
# reads instances/<slug>/instance.yml (committed, no secrets) and
# instances/<slug>/.env (gitignored secrets; created with a random hash salt
# when missing) and then, step by step and only where something is missing:
# registers the hostnames with ddev, maps them in web/sites/sites.php, creates
# web/sites/<subdir>/ with the generic settings.php and a generated
# settings.local.php, creates the database, installs Drupal, applies the
# taxanotes_front recipe (which applies taxanotes_base: taxon type, roles,
# literature, tree, import module, site identity; then the front page, the
# About page, the menu and the taxon page blocks), sets the themes and applies
# the theme's own recipe when it has one (recipes/taxanotes_classic: the
# classic view's blocks and accent colour), installs the instance's logo, runs the eleven Scratchpads migrations (taxa, literature, then the
# species pages and images), rebuilds caches and prints the URL. Re-running it on an existing instance changes nothing that is already
# in place (migrations only import rows that changed in Drupal 7; the recipe
# creates blocks and views only when they are missing and re-seeds the site
# texts from instance.yml).
#
# It never installs over an existing database: Drupal is installed only when
# the instance's database holds no table at all. A database with tables that
# does not bootstrap (db container down, broken settings, PHP fatal, partial
# install) stops the run; --reinstall is the one way to drop it and install
# again. Before any drush write the script checks that drush resolves the
# instance's URL to web/sites/<sites_subdir>, so a hostname that is not (yet)
# mapped can never make the run act on another instance's site directory.
#
# This is the ddev (local) implementation: hostnames come from
# `hostnames.ddev` of instance.yml and settings.local.php is generated for
# the ddev environment. See instances/README.md for the production layout.
#
# Needs: ddev (running project), bash 3.2+ (macOS's /bin/bash is enough). Everything else runs inside the
# web container (php, drush, composer's vendor/).
set -euo pipefail

usage() {
  echo "usage: $0 <slug> [--reinstall]   (instances/<slug>/instance.yml must exist)" >&2
  echo "  --reinstall  drop the instance's existing database tables and install Drupal again" >&2
  exit 2
}

log() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die() { printf 'new-instance: %s\n' "$*" >&2; exit 1; }

slug=""
reinstall=0
for arg in "$@"; do
  case "$arg" in
    --reinstall) reinstall=1 ;;
    -*) usage ;;
    *) [ -z "$slug" ] || usage; slug="$arg" ;;
  esac
done
[ -n "$slug" ] || usage
# The environment this script builds for (hostnames.<environment> in
# instance.yml, trusted hosts of the generated settings.local.php).
environment=ddev
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
inst_dir="instances/$slug"
yml="$inst_dir/instance.yml"
env_file="$inst_dir/.env"
[ -f "$yml" ] || die "$yml does not exist"
command -v ddev > /dev/null || die "ddev is not installed"

# Runs a command inside the web container with its arguments intact (ddev
# exec re-quotes arguments through a shell, which breaks on quotes; feeding
# bash a %q-quoted line on stdin does not).
in_web() {
  { printf '%q ' "$@"; echo; } | ddev exec -s web bash -s
}

# One value (or list, one item per line) from instance.yml, defaults applied
# (scripts/lib/instance.php is the one place that knows them).
inst() {
  in_web php scripts/lib/instance.php get "$yml" "$1"
}

# One value from .env ('' when unset).
env_get() {
  [ -f "$env_file" ] || return 0
  sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed -e "s/^['\"]//" -e "s/['\"]\$//"
}

# ---------------------------------------------------------------------------
log "Instance $slug: reading $yml"
site_name="$(inst site.name)"
site_slogan="$(inst site.slogan)"
front_title="$(inst site.front_title)"
front_intro="$(inst site.front_intro)"
about_body="$(inst site.about_body)"
footer_text="$(inst site.footer_text)"
theme="$(inst site.theme)"
admin_theme="$(inst site.admin_theme)"
colour="$(inst site.colour)"
front_first_column="$(inst site.front_first_column)"
subdir="$(inst drupal.sites_subdir)"
profile="$(inst drupal.profile)"
db_name="$(inst drupal.database)"
hostnames=()
while IFS= read -r host; do
  [ -n "$host" ] && hostnames+=("$host")
done < <(inst "hostnames.$environment")
[ -n "$site_name" ] || die "site.name is missing in $yml"
[ "${#hostnames[@]}" -gt 0 ] || die "hostnames.$environment is empty in $yml"
[ -n "$subdir" ] && [ -n "$profile" ] && [ -n "$db_name" ] || die "instance.php returned no drupal.sites_subdir/profile/database for $yml"
primary_host="${hostnames[0]}"
url="https://$primary_host"
site_dir="web/sites/$subdir"
project="$(sed -n 's/^name: *//p' .ddev/config.yaml | head -n 1)"
note "site: $site_name"
note "hostnames: ${hostnames[*]}"
note "site directory: $site_dir, database: $db_name, profile: $profile"

# ---------------------------------------------------------------------------
log "Secrets: $env_file"
if [ ! -f "$env_file" ]; then
  sed "s/^HASH_SALT=.*/HASH_SALT=$(openssl rand -hex 32)/" instances/.env.example > "$env_file"
  note "created from instances/.env.example with a fresh HASH_SALT (gitignored)"
  note "MIGRATE_DB_USER/PASSWORD are empty: fill them in and re-run to import from Drupal 7"
else
  note "exists, kept"
fi
# Credentials: owner-only, also for a hand-copied file.
chmod 600 "$env_file"
[ -n "$(env_get HASH_SALT)" ] || die "HASH_SALT is empty in $env_file"
site_mail="$(env_get SITE_MAIL)"
site_mail="${site_mail:-webmaster@example.com}"
db_user="$(env_get DB_USER)"
db_user="${db_user:-db}"
db_host="$(env_get DB_HOST)"
db_host="${db_host:-db}"
migrate_user="$(env_get MIGRATE_DB_USER)"
admin_pass="$(env_get ADMIN_PASSWORD)"
# The D7 public files directory as PHP reaches it in this environment: .env
# SOURCE_FILES_DIR, else instance.yml source.files.<environment>. Empty: the
# files and the images are not on this machine and their migrations are
# skipped (the references import without attachments, the species pages
# without images; the taxon pages show no gallery).
source_files_dir="$(env_get SOURCE_FILES_DIR)"
[ -n "$source_files_dir" ] || source_files_dir="$(inst source.files)"

# ---------------------------------------------------------------------------
log "ddev hostnames: .ddev/config.instances.yaml (all instances)"
# ddev merges every .ddev/config.*.yaml into config.yaml. The file lists the
# ddev hostnames of every instance except the project's own <name>.ddev.site;
# *.ddev.site names go to additional_hostnames (suffix stripped), others to
# additional_fqdns.
gen_ddev_hostnames() {
  local f host
  echo "# Generated by scripts/new-instance.sh from instances/*/instance.yml: the"
  echo "# hostnames of every instance besides the project's primary one. ddev merges"
  echo "# this file into config.yaml; re-run the script instead of editing it."
  local names=() fqdns=()
  for f in instances/*/instance.yml; do
    while IFS= read -r host; do
      [ -n "$host" ] || continue
      [ "$host" = "$project.ddev.site" ] && continue
      case "$host" in
        *.ddev.site) names+=("${host%.ddev.site}") ;;
        *) fqdns+=("$host") ;;
      esac
    done < <(in_web php scripts/lib/instance.php get "$f" hostnames.ddev)
  done
  if [ "${#names[@]}" -gt 0 ]; then
    echo "additional_hostnames:"
    printf '  - %s\n' "${names[@]}"
  fi
  if [ "${#fqdns[@]}" -gt 0 ]; then
    echo "additional_fqdns:"
    printf '  - %s\n' "${fqdns[@]}"
  fi
}
tmp="$(mktemp)"
gen_ddev_hostnames > "$tmp"
if [ -f .ddev/config.instances.yaml ] && cmp -s "$tmp" .ddev/config.instances.yaml; then
  note "unchanged"
  rm -f "$tmp"
else
  mv "$tmp" .ddev/config.instances.yaml
  note "written; restarting ddev so the router and certificates know the hostnames"
  ddev restart
fi

# ---------------------------------------------------------------------------
log "Multisite map: web/sites/sites.php (ddev hostnames of every instance)"
# Production runs one checkout per instance on sites/default and needs no
# entry (instances/README.md), so the map carries the ddev hostnames only.
gen_sites_block() {
  local f sub host
  for f in instances/*/instance.yml; do
    sub="$(in_web php scripts/lib/instance.php get "$f" drupal.sites_subdir)"
    [ -n "$sub" ] || die "$f: instance.php returned no drupal.sites_subdir"
    [ "$sub" != "default" ] || continue
    while IFS= read -r host; do
      [ -n "$host" ] || continue
      printf "\$sites['%s'] = '%s';\n" "$host" "$sub"
    done < <(in_web php scripts/lib/instance.php get "$f" hostnames.ddev)
  done
}
begin='// BEGIN taxanotes instances (generated; do not edit inside this block)'
end='// END taxanotes instances'
# The block goes through a file: macOS awk rejects a newline in a -v value
# ("newline in string"), which a block of two or more lines has.
block_file="$(mktemp)"
gen_sites_block > "$block_file"
tmp="$(mktemp)"
awk -v begin="$begin" -v end="$end" -v block_file="$block_file" '
  $0 == begin { print; while ((getline line < block_file) > 0) print line; close(block_file); skip = 1; next }
  $0 == end { skip = 0 }
  !skip { print }
' web/sites/sites.php > "$tmp"
rm -f "$block_file"
if cmp -s "$tmp" web/sites/sites.php; then
  note "unchanged"
  rm -f "$tmp"
else
  mv "$tmp" web/sites/sites.php
  note "updated"
fi

# ---------------------------------------------------------------------------
log "Site directory: $site_dir"
mkdir -p "$site_dir/files"
# shellcheck disable=SC2016  # the grep pattern is a regex, not an expansion
if [ ! -f "$site_dir/settings.php" ]; then
  cp scripts/templates/settings.php "$site_dir/settings.php"
  note "settings.php copied from scripts/templates/settings.php"
elif cmp -s scripts/templates/settings.php "$site_dir/settings.php"; then
  note "settings.php is the generic template"
elif grep -Eq "^\\\$databases\\[|^\\\$settings\\['hash_salt'\\] *= *'[^']" "$site_dir/settings.php"; then
  # The installer's own writes (see the restore after site:install below): a
  # credential or salt in a file that is committed. Never carried on with.
  # (An empty salt, `$settings['hash_salt'] = '';` as the hand-built
  # Myriatrix site's committed settings.php has it, is not one.)
  die "$site_dir/settings.php holds database credentials or a hash salt: restore it with 'cp scripts/templates/settings.php $site_dir/settings.php' (both come from .env through settings.local.php) and run again"
else
  note "settings.php exists and is not the generic template: left alone"
fi
marker='Generated by scripts/new-instance.sh'
tmp="$(mktemp)"
in_web php scripts/lib/instance.php settings-local "$yml" "$env_file" "$environment" > "$tmp"
if [ -f "$site_dir/settings.local.php" ] && ! grep -q "$marker" "$site_dir/settings.local.php"; then
  note "settings.local.php exists and was not generated by this script: left alone"
  rm -f "$tmp"
elif [ -f "$site_dir/settings.local.php" ] && cmp -s "$tmp" "$site_dir/settings.local.php"; then
  note "settings.local.php unchanged"
  rm -f "$tmp"
else
  mv "$tmp" "$site_dir/settings.local.php"
  chmod 640 "$site_dir/settings.local.php"
  note "settings.local.php generated from instance.yml + .env"
fi

# ---------------------------------------------------------------------------
log "Site resolution: $url -> $site_dir"
# Drush resolves the URL through web/sites/sites.php; a hostname that is not
# mapped lands on sites/default (another instance). Nothing below writes
# through drush until the resolved site directory is the instance's own.
resolved_site="$(in_web drush --uri="$url" core:status --field=site 2> /dev/null || true)"
[ "$resolved_site" = "sites/$subdir" ] || die "drush resolves $url to '${resolved_site:-?}', not sites/$subdir: check hostnames.$environment and web/sites/sites.php"
note "drush resolves $url to sites/$subdir"

# ---------------------------------------------------------------------------
log "Database: $db_name on $db_host"
if [ "$db_host" = "db" ]; then
  # ddev's db container; its `db` user only owns the `db` database, so the
  # extra database is created and granted as root (ddev's root/root).
  ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL ON \`$db_name\`.* TO '$db_user'@'%'; FLUSH PRIVILEGES;"
  note "exists (created if it was missing), granted to $db_user"
  table_count="$(ddev mysql -uroot -proot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db_name'")"
else
  note "not the ddev db container: expected to exist already"
  table_count="$(in_web drush --uri="$url" sql:query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
fi
table_count="$(printf '%s' "$table_count" | tr -dc '0-9')"
[ -n "$table_count" ] || die "could not count the tables of database $db_name"
note "$table_count tables"

# ---------------------------------------------------------------------------
log "Drupal: $url"
# Installed = the database has tables, Drupal bootstraps on this site and the
# installer finished (install_finished() records install_time in state).
# Anything else with tables present is an error, never an install: an empty
# database is the only state that installs by itself, --reinstall the only
# way to install over tables (site:install drops them).
install=0
if [ "$table_count" -eq 0 ]; then
  note "database is empty"
  install=1
else
  bootstrap="$(in_web drush --uri="$url" core:status --field=bootstrap 2> /dev/null || true)"
  install_time=""
  if [ "$bootstrap" = "Successful" ]; then
    install_time="$(in_web drush --uri="$url" state:get install_time 2> /dev/null || true)"
  fi
  if [ -n "$install_time" ]; then
    note "already installed"
  elif [ "$reinstall" -eq 1 ]; then
    note "database has $table_count tables but no finished install (bootstrap: ${bootstrap:-failed})"
  elif [ "$bootstrap" = "Successful" ]; then
    die "database $db_name has $table_count tables and Drupal bootstraps, but the install never finished (no install_time): fix it or pass --reinstall to drop it and install again"
  else
    die "database $db_name has $table_count tables but Drupal does not bootstrap on $url (db down? broken settings.local.php? PHP fatal?): fix it, or pass --reinstall to drop it and install again"
  fi
  if [ "$reinstall" -eq 1 ]; then
    note "--reinstall: dropping the $table_count tables of $db_name and installing again"
    install=1
  fi
fi
if [ "$install" -eq 1 ]; then
  note "installing profile $profile into $site_dir"
  install_args=(site:install "$profile" --sites-subdir="$subdir" --site-name="$site_name" --site-mail="$site_mail" --account-name=admin --yes)
  if [ -n "$admin_pass" ]; then
    install_args+=(--account-pass="$admin_pass")
  fi
  in_web drush --uri="$url" "${install_args[@]}"
  # The installer appends the database credentials and a fresh hash salt to
  # settings.php after the settings.local.php include (so they would override
  # it). Both already come from .env through settings.local.php: put the
  # generic file back so no credential sits in a committed file.
  if ! cmp -s scripts/templates/settings.php "$site_dir/settings.php"; then
    cp scripts/templates/settings.php "$site_dir/settings.php"
    note "settings.php restored to the generic template (the installer had written credentials into it)"
  fi
fi

# ---------------------------------------------------------------------------
log "Recipe: taxanotes_front (applies taxanotes_base: taxon type, roles, tree, import module, site identity; then front page, About page, menu, taxon page blocks)"
croot="$(in_web pwd)"
recipe_inputs=(
  --input="taxanotes_base.site_name=$site_name"
  --input="taxanotes_base.site_slogan=$site_slogan"
  --input="taxanotes_base.site_mail=$site_mail"
)
# A text left out of instance.yml keeps the site's current value (the recipe
# input defaults to the existing config, or to a generic text on first apply).
[ -n "$front_title" ] && recipe_inputs+=(--input="taxanotes_front.front_title=$front_title")
[ -n "$front_intro" ] && recipe_inputs+=(--input="taxanotes_front.front_intro=$front_intro")
[ -n "$about_body" ] && recipe_inputs+=(--input="taxanotes_front.about_body=$about_body")
[ -n "$footer_text" ] && recipe_inputs+=(--input="taxanotes_front.footer_text=$footer_text")
# Pending database and post-update hooks of the platform first: they carry
# the changes to configuration the recipes create only once (a shipped
# view's layout) to an instance that already has it. Nothing to do on a
# fresh install.
in_web drush --uri="$url" updatedb --yes
in_web drush --uri="$url" recipe "$croot/recipes/taxanotes_front" "${recipe_inputs[@]}"

# ---------------------------------------------------------------------------
log "Themes: $theme (default), $admin_theme (admin)"
# After the front recipe, on purpose: the recipe places its blocks in the
# theme that is the default when it runs (Olivero on a fresh install, as the
# standard profile leaves it). Installing another theme then copies every
# block of the default theme into it (block_theme_initialize(), ids
# <theme>_<block id>, regions of the same name), so an instance that moves
# from Olivero to the classic view, and one built fresh with it, end up with
# the same blocks, and the Olivero ones stay as they were. A second run is a
# no-op: an installed theme is not copied into again.
if [ -n "$theme" ]; then
  in_web drush --uri="$url" theme:enable "$theme" --yes
  in_web drush --uri="$url" config:set system.theme default "$theme" --yes
fi
if [ -n "$admin_theme" ]; then
  in_web drush --uri="$url" theme:enable "$admin_theme" --yes
  in_web drush --uri="$url" config:set system.theme admin "$admin_theme" --yes
fi

# ---------------------------------------------------------------------------
# A theme that ships a recipe of its own name (recipes/taxanotes_classic for
# the classic view) gets it applied now: its blocks, its switch-offs of the
# Olivero front page blocks, and the accent colour from instance.yml
# (site.colour, default 047DB8) as the input <theme>.colour, the first front
# page column's node type (site.front_first_column, default page) as
# <theme>.first_column.
if [ -n "$theme" ] && [ -f "recipes/$theme/recipe.yml" ]; then
  log "Recipe: $theme (the theme's blocks and settings)"
  in_web drush --uri="$url" recipe "$croot/recipes/$theme" --input="$theme.colour=$colour" --input="$theme.first_column=$front_first_column"
else
  log "Recipe: none for theme ${theme:-?} (recipes/$theme/recipe.yml does not exist): skipped"
fi

# ---------------------------------------------------------------------------
log "Logo: $inst_dir/assets/logo.png"
# instances/<slug>/assets/ is gitignored (image rights are not cleared for
# committing); a logo.png there is copied into the site's files directory and
# set as the theme's logo. No file: the theme keeps its own logo.
logo_src="$inst_dir/assets/logo.png"
if [ -f "$logo_src" ] && [ -n "$theme" ]; then
  mkdir -p "$site_dir/files/instance"
  if cmp -s "$logo_src" "$site_dir/files/instance/logo.png"; then
    note "$site_dir/files/instance/logo.png is up to date"
  else
    cp "$logo_src" "$site_dir/files/instance/logo.png"
    note "copied to $site_dir/files/instance/logo.png"
  fi
  in_web drush --uri="$url" config:set "$theme.settings" logo.use_default false --input-format=yaml --yes
  in_web drush --uri="$url" config:set "$theme.settings" logo.path "sites/$subdir/files/instance/logo.png" --yes
  note "$theme.settings logo.path = sites/$subdir/files/instance/logo.png"
else
  note "no logo file: the theme's own logo stays"
fi

# ---------------------------------------------------------------------------
log "Footer logos: $inst_dir/assets/footer/"
# The partner logos of the footer (the Scratchpads footer row): every png
# and svg in instances/<slug>/assets/footer/ (gitignored, the rights are the
# instance's) is copied into the site's files directory and listed in
# taxanotes_front.settings:footer_logos with the link and alt text of
# instance.yml site.footer_logos ({file, url, alt}); files no longer there
# are removed. No file: an empty list, the footer shows no row.
footer_src="$inst_dir/assets/footer"
footer_dir="$site_dir/files/instance/footer"
mkdir -p "$footer_dir"
footer_count=0
if [ -d "$footer_src" ]; then
  for f in "$footer_src"/*.png "$footer_src"/*.svg "$footer_src"/*.PNG "$footer_src"/*.SVG; do
    [ -f "$f" ] || continue
    footer_count=$((footer_count + 1))
    if ! cmp -s "$f" "$footer_dir/$(basename "$f")"; then
      cp "$f" "$footer_dir/$(basename "$f")"
      note "copied $(basename "$f")"
    fi
  done
fi
for f in "$footer_dir"/*; do
  [ -f "$f" ] || continue
  if [ ! -f "$footer_src/$(basename "$f")" ]; then
    rm -f "$f"
    note "removed $(basename "$f") (no longer in assets/footer/)"
  fi
done
footer_yaml="$(php scripts/lib/instance.php footer-logos "$yml" "$footer_src")"
in_web drush --uri="$url" config:set taxanotes_front.settings footer_logos --input-format=yaml "$footer_yaml" --yes
note "$footer_count logo(s): taxanotes_front.settings footer_logos set"

# ---------------------------------------------------------------------------
log "Scratchpads (Drupal 7) import"
# Taxa first (users, taxa, parents, revisions), then the literature
# (contributors, keywords, the files, the references with their links to
# taxa, the pass that points each taxon at its protologue reference entity),
# then the images as media items and the species pages. The two file-based
# migrations (files, media) run only when the D7 files directory is
# configured for this environment (source_files_dir above).
if [ -n "$migrate_user" ] && [ -n "$(inst source.vocabulary)" ]; then
  migrations=(taxanotes_d7_user taxanotes_d7_taxon taxanotes_d7_taxon_parent taxanotes_d7_taxon_revision
    taxanotes_d7_biblio_contributor taxanotes_d7_biblio_keyword)
  if [ -n "$source_files_dir" ]; then
    note "D7 files: $source_files_dir (attachments and images are copied from it)"
    migrations+=(taxanotes_d7_file taxanotes_d7_biblio taxanotes_d7_taxon_reference taxanotes_d7_media_image)
  else
    note "D7 files: not on this machine (no SOURCE_FILES_DIR in .env, no source.files.$environment in instance.yml): the attachments and the images are skipped (taxanotes_d7_file, taxanotes_d7_media_image); references and species pages import without them"
    migrations+=(taxanotes_d7_biblio taxanotes_d7_taxon_reference)
  fi
  migrations+=(taxanotes_d7_spm taxanotes_d7_page taxanotes_d7_blog taxanotes_d7_menu_link)
  for m in "${migrations[@]}"; do
    note "migrate:import $m"
    in_web drush --uri="$url" migrate:import "$m" --feedback=2000
  done
else
  note "no source (MIGRATE_DB_USER in .env or source.vocabulary in instance.yml is empty): skipped"
fi

# ---------------------------------------------------------------------------
log "Cache rebuild"
in_web drush --uri="$url" cache:rebuild

log "Done: $url"
for host in "${hostnames[@]}"; do
  note "https://$host"
done
note "log in with: ddev drush --uri=$url user:login"
