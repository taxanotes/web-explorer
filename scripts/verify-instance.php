<?php

/**
 * @file
 * Verifies a migrated instance against its Scratchpads (Drupal 7) source.
 *
 * Run with drush on the instance site (both database connections are read):
 *
 *   drush --uri=https://<hostname> php:script \
 *     scripts/verify-instance.php -- <vocabulary> [nochanged]
 *
 * Prints JSON with three parts:
 * - counts: the D7 and D11 counts that must agree (terms, parent links,
 *   orphans, roots, users, roles, revisions, accepted, unit and vernacular
 *   names, weights, ranks, usages);
 * - fields: a comparison of EVERY term's current values with the D7
 *   field_data_* tables (what the Scratchpads site renders), one entry per
 *   mismatching (term, field), so an empty list is a value-by-value proof;
 * - checksums: a sha256 per content table over the TAXON nodes (node_field_data
 *   without vid and, with the argument "nochanged", without changed; every
 *   node__field_* table without revision_id) to compare an instance before
 *   and after a platform change, e.g. the Myriatrix control while another
 *   instance is built; other content types (species pages) are left out so
 *   their import does not move the taxon checksums;
 * - literature (when the literature recipe is applied and the D7 site has
 *   biblio): the D7 and D11 counts of references, contributors, keywords,
 *   author rows, taxon links, protologue links and attachments, a
 *   reference-by-reference comparison of title, year, type and authors for
 *   every D7 biblio node, and the /literature page rendered as anonymous
 *   (rows, rows with an author shown, broken field handlers);
 * - species (when the species recipe is applied and the D7 site has spm
 *   nodes): the D7 and D11 counts of species pages and images (with the
 *   rule the image import applies), their taxon links and licences, and a
 *   page-by-page comparison of title, status, taxon and every text section
 *   for every D7 spm node.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Site\Settings;
use Drupal\views\Plugin\views\field\Broken;
use Drupal\views\Views;

$vocab = $extra[0] ?? NULL;
if (!$vocab) {
  fwrite(STDERR, "usage: drush php:script scripts/verify-instance.php -- <vocabulary> [nochanged]\n");
  return;
}
$no_changed = in_array('nochanged', $extra, TRUE);
$d7 = Database::getConnection('default', 'migrate');
$d11 = Database::getConnection();

$vid = (int) $d7->query('SELECT vid FROM {taxonomy_vocabulary} WHERE machine_name = :m', [':m' => $vocab])->fetchField();
if (!$vid) {
  fwrite(STDERR, "vocabulary $vocab not found in the migrate database\n");
  return;
}

// ---------------------------------------------------------------------------
// Counts.
$c = [];
$c['d7 vocabulary vid'] = $vid;
$c['d7 terms'] = (int) $d7->query('SELECT COUNT(*) FROM {taxonomy_term_data} WHERE vid = :v', [':v' => $vid])->fetchField();
$c['d11 taxon nodes'] = (int) $d11->query("SELECT COUNT(*) FROM {node_field_data} WHERE type = 'taxon'")->fetchField();
$c['d11 distinct guids'] = (int) $d11->query('SELECT COUNT(DISTINCT field_guid_value) FROM {node__field_guid}')->fetchField();
$c['d7 hierarchy rows parent<>0'] = (int) $d7->query('SELECT COUNT(*) FROM {taxonomy_term_hierarchy} h JOIN {taxonomy_term_data} t ON t.tid = h.tid WHERE t.vid = :v AND h.parent <> 0', [':v' => $vid])->fetchField();
$c['d11 parent references'] = (int) $d11->query('SELECT COUNT(*) FROM {node__field_parent_taxon_reference}')->fetchField();
$c['d11 orphans (parent guid without reference)'] = (int) $d11->query("SELECT COUNT(*) FROM {node__field_parent_guid} g LEFT JOIN {node__field_parent_taxon_reference} p ON p.entity_id = g.entity_id WHERE g.field_parent_guid_value <> '' AND p.entity_id IS NULL")->fetchField();
$c['d11 reference/guid disagreements'] = (int) $d11->query('SELECT COUNT(*) FROM {node__field_parent_taxon_reference} p JOIN {node__field_parent_guid} pg ON pg.entity_id = p.entity_id JOIN {node__field_guid} g ON g.entity_id = p.field_parent_taxon_reference_target_id WHERE g.field_guid_value <> pg.field_parent_guid_value')->fetchField();
$c['d11 roots (no parent reference)'] = $d11->query("SELECT n.title FROM {node_field_data} n LEFT JOIN {node__field_parent_taxon_reference} p ON p.entity_id = n.nid WHERE n.type = 'taxon' AND p.entity_id IS NULL ORDER BY n.title")->fetchCol();
$c['d11 status counts'] = $d11->query("SELECT status, COUNT(*) c FROM {node_field_data} WHERE type = 'taxon' GROUP BY status")->fetchAllKeyed();
$c['d7 users uid>1'] = (int) $d7->query('SELECT COUNT(*) FROM {users} WHERE uid > 1')->fetchField();
$c['d11 users uid>1'] = (int) $d11->query('SELECT COUNT(*) FROM {users_field_data} WHERE uid > 1')->fetchField();
$c['d11 users total'] = (int) $d11->query('SELECT COUNT(*) FROM {users_field_data}')->fetchField();
$c['d11 roles'] = $d11->query('SELECT roles_target_id, COUNT(*) c FROM {user__roles} GROUP BY roles_target_id ORDER BY roles_target_id')->fetchAllKeyed();
$c['d11 phpass hashes'] = (int) $d11->query("SELECT COUNT(*) FROM {users_field_data} WHERE pass LIKE '\$S\$%'")->fetchField();
// users.changed is a Scratchpads addition; a site without it (the 2022
// Anthophila dump) counts as "changed = 0" for every account, and the
// migration then sets changed to the registration time.
$users_changed = $d7->schema()->fieldExists('users', 'changed');
$c['d7 users with changed = 0'] = (int) $d7->query($users_changed
  ? 'SELECT COUNT(*) FROM {users} WHERE uid > 1 AND changed = 0'
  : 'SELECT COUNT(*) FROM {users} WHERE uid > 1')->fetchField();
$c['d11 users whose changed differs from D7 (changed, or created when 0)'] = 0;
foreach ($d7->query($users_changed
  ? 'SELECT uid, created, changed FROM {users} WHERE uid > 1'
  : 'SELECT uid, created, 0 AS changed FROM {users} WHERE uid > 1') as $u) {
  $expected = (int) $u->changed ?: (int) $u->created;
  $actual = (int) $d11->query('SELECT changed FROM {users_field_data} WHERE uid = :u', [':u' => $u->uid])->fetchField();
  if ($expected !== $actual) {
    $c['d11 users whose changed differs from D7 (changed, or created when 0)']++;
  }
}
$c['d7 revisions (vocabulary)'] = (int) $d7->query('SELECT COUNT(*) FROM {taxonomy_term_data_revision} r JOIN {taxonomy_term_data} t ON t.tid = r.tid WHERE t.vid = :v', [':v' => $vid])->fetchField();
$c['d11 node revisions'] = (int) $d11->query('SELECT COUNT(*) FROM {node_revision}')->fetchField();
$d7_counts = $d7->query('SELECT t.tid, COUNT(*) c FROM {taxonomy_term_data_revision} r JOIN {taxonomy_term_data} t ON t.tid = r.tid WHERE t.vid = :v GROUP BY t.tid', [':v' => $vid])->fetchAllKeyed();
$d11_counts = $d11->query('SELECT l.field_legacy_tid_value tid, COUNT(*) c FROM {node_revision} r JOIN {node__field_legacy_tid} l ON l.entity_id = r.nid GROUP BY l.field_legacy_tid_value')->fetchAllKeyed();
$diff = 0;
foreach ($d7_counts as $tid => $n) {
  if ((int) ($d11_counts[$tid] ?? 0) !== (int) $n) {
    $diff++;
  }
}
$c['nodes whose revision count differs from D7'] = $diff;
$c['terms without node'] = count(array_diff_key($d7_counts, $d11_counts));
$c['placeholder revisions left'] = (int) $d11->query("SELECT COUNT(*) FROM {node_revision} WHERE revision_log LIKE 'Migrated from Scratchpads%' OR revision_log LIKE 'Synced from Scratchpads%' OR revision_log LIKE 'Scratchpads revision history rolled back%'")->fetchField();
// Default revision = the D7 current revision (two databases, so in PHP).
$current = $d7->query('SELECT tid, revision_id FROM {taxonomy_term_data} WHERE vid = :v', [':v' => $vid])->fetchAllKeyed();
// A partial build (the revision migration not run yet) has no map table:
// reported, not fatal.
if ($d11->schema()->tableExists('migrate_map_taxanotes_d7_taxon_revision')) {
  $map = $d11->query('SELECT sourceid1, destid1 FROM {migrate_map_taxanotes_d7_taxon_revision}')->fetchAllKeyed();
  $default_vid = $d11->query("SELECT l.field_legacy_tid_value, n.vid FROM {node_field_data} n JOIN {node__field_legacy_tid} l ON l.entity_id = n.nid WHERE n.type = 'taxon'")->fetchAllKeyed();
  $ok = 0;
  foreach ($current as $tid => $rid) {
    if (isset($map[$rid], $default_vid[$tid]) && (int) $map[$rid] === (int) $default_vid[$tid]) {
      $ok++;
    }
  }
  $c['default revision = D7 current'] = $ok;
}
else {
  $c['default revision = D7 current'] = 'not checked: taxanotes_d7_taxon_revision has not run';
}
$c['d7 revisions by uid 0 (vocabulary)'] = (int) $d7->query('SELECT COUNT(*) FROM {taxonomy_term_data_revision} r JOIN {taxonomy_term_data} t ON t.tid = r.tid WHERE t.vid = :v AND r.uid = 0', [':v' => $vid])->fetchField();
$c['d11 revisions by uid 0'] = (int) $d11->query('SELECT COUNT(*) FROM {node_revision} WHERE revision_uid = 0')->fetchField();
$c['d11 accepted names'] = (int) $d11->query('SELECT COUNT(*) FROM {node__field_accepted_name}')->fetchField();
$c['d7 accepted names (distinct terms)'] = $d7->schema()->tableExists("field_data_field_aan_$vid")
  ? (int) $d7->query("SELECT COUNT(DISTINCT entity_id) FROM {field_data_field_aan_$vid} WHERE entity_type = 'taxonomy_term' AND deleted = 0")->fetchField()
  : 0;
$c['d11 vernacular rows'] = (int) $d11->query('SELECT COUNT(*) FROM {node__field_vernacular_names}')->fetchField();
$c['d7 vernacular rows'] = $d7->schema()->tableExists('field_data_field_vernacular_name_collection')
  ? (int) $d7->query("SELECT COUNT(*) FROM {field_data_field_vernacular_name_collection} c JOIN {taxonomy_term_data} t ON t.tid = c.entity_id WHERE c.entity_type = 'taxonomy_term' AND c.deleted = 0 AND t.vid = :v", [':v' => $vid])->fetchField()
  : 0;
$c['d11 non-zero weights'] = (int) $d11->query('SELECT COUNT(*) FROM {node__field_weight} WHERE field_weight_value <> 0')->fetchField();
$c['d7 non-zero weights'] = (int) $d7->query('SELECT COUNT(*) FROM {taxonomy_term_data} WHERE vid = :v AND weight <> 0', [':v' => $vid])->fetchField();
$c['d11 ranks'] = $d11->query('SELECT field_rank_value, COUNT(*) c FROM {node__field_rank} GROUP BY field_rank_value ORDER BY field_rank_value')->fetchAllKeyed();
$c['d11 usage'] = $d11->query('SELECT field_usage_value, COUNT(*) c FROM {node__field_usage} GROUP BY field_usage_value ORDER BY field_usage_value')->fetchAllKeyed();

// ---------------------------------------------------------------------------
// Every term, every current value, against the D7 data tables.
//
// D7 field rows: one per language; the migration picks the term's own
// language, else und, else the alphabetically first (joinFieldTable()). A
// site without entity_translation has no taxonomy_term_data.language column
// (the 2022 Anthophila dump): no own language, und first.
$term_lang = $d7->schema()->fieldExists('taxonomy_term_data', 'language')
  ? $d7->query('SELECT tid, language FROM {taxonomy_term_data} WHERE vid = :v', [':v' => $vid])->fetchAllKeyed()
  : [];

/**
 * One value per term of a D7 data field, with the migration's language rule.
 */
$d7_field = function (string $field, string $column) use ($d7, $vid, $term_lang): array {
  $values = [];
  if (!$d7->schema()->tableExists('field_data_' . $field)) {
    return $values;
  }
  $rows = $d7->query("SELECT f.entity_id, f.language, f.$column AS v FROM {field_data_$field} f JOIN {taxonomy_term_data} t ON t.tid = f.entity_id WHERE f.entity_type = 'taxonomy_term' AND f.deleted = 0 AND f.delta = 0 AND t.vid = :v ORDER BY f.entity_id, f.language", [':v' => $vid]);
  $best = [];
  foreach ($rows as $r) {
    $tid = (int) $r->entity_id;
    $rank = ($r->language === ($term_lang[$tid] ?? NULL)) ? '0' : ($r->language === 'und' ? '1' : '2');
    $key = $rank . $r->language;
    if (!isset($best[$tid]) || strcmp($key, $best[$tid]) < 0) {
      $best[$tid] = $key;
      $values[$tid] = $r->v;
    }
  }
  return $values;
};

/**
 * Loads one value per term from a D11 field table, keyed by legacy tid.
 */
$d11_field = function (string $table, string $column) use ($d11): array {
  if (!$d11->schema()->tableExists($table)) {
    return [];
  }
  return $d11->query("SELECT l.field_legacy_tid_value tid, f.$column v FROM {{$table}} f JOIN {node__field_legacy_tid} l ON l.entity_id = f.entity_id WHERE f.delta = 0")->fetchAllKeyed();
};

$scalar = [
  'field_authors' => 'node__field_authors',
  'field_page_number' => 'node__field_page_number',
  'field_rank' => 'node__field_rank',
  'field_unacceptability_reason' => 'node__field_unacceptability_reason',
  'field_usage' => 'node__field_usage',
];

// Expected (D7) and actual (D11) values per comparison, keyed by tid.
$compare = [];
foreach ($scalar as $field => $table) {
  $compare[$field] = [$d7_field($field, $field . '_value'), $d11_field($table, substr($table, 6) . '_value')];
}
// Unit names: the four D7 name/indicator pairs against the items of
// field_unit_names, with the migration's rule (ScratchpadsD7Base::unitNames):
// one item per position whose name is non-empty after trimming, in position
// order (so the delta is the unit's order), a blank indicator as NULL. Each
// term is compared as one string, "indicator|name" per unit joined with ";".
$unit_expected = [];
for ($i = 1; $i <= 4; $i++) {
  $names = $d7_field("field_unit_name$i", "field_unit_name{$i}_value");
  $indicators = $d7_field("field_unit_indicator$i", "field_unit_indicator{$i}_value");
  foreach ($names as $tid => $name) {
    $name = is_string($name) ? trim($name) : '';
    if ($name === '') {
      continue;
    }
    $indicator = $indicators[$tid] ?? NULL;
    $indicator = is_string($indicator) ? trim($indicator) : '';
    $unit_expected[$tid][] = ($indicator === '' ? '' : $indicator) . '|' . $name;
  }
}
$unit_actual = [];
$unit_rows = 0;
if ($d11->schema()->tableExists('node__field_unit_names')) {
  foreach ($d11->query('SELECT l.field_legacy_tid_value tid, f.delta, f.field_unit_names_indicator indicator, f.field_unit_names_name name FROM {node__field_unit_names} f JOIN {node__field_legacy_tid} l ON l.entity_id = f.entity_id ORDER BY f.entity_id, f.delta') as $r) {
    $unit_actual[(int) $r->tid][] = (string) $r->indicator . '|' . $r->name;
    $unit_rows++;
  }
}
$compare['unit_names'] = [
  array_map(fn($units) => implode(';', $units), $unit_expected),
  array_map(fn($units) => implode(';', $units), $unit_actual),
];
$c['d7 unit names (non-empty positions)'] = array_sum(array_map('count', $unit_expected));
$c['d11 unit name rows'] = $unit_rows;
$c['d11 unit name rows with an indicator'] = $d11->schema()->tableExists('node__field_unit_names')
  ? (int) $d11->query("SELECT COUNT(*) FROM {node__field_unit_names} WHERE field_unit_names_indicator IS NOT NULL AND field_unit_names_indicator <> ''")->fetchField()
  : 0;
$compare['accepted_tid'] = [
  $d7_field("field_aan_$vid", "field_aan_{$vid}_tid"),
  $d11_field('node__field_accepted_tid', 'field_accepted_tid_value'),
];
$compare['reference_nid'] = [
  $d7_field('field_reference', 'field_reference_nid'),
  $d11_field('node__field_reference_nid', 'field_reference_nid_value'),
];
$compare['page_url'] = [
  $d7_field('field_page_url', 'field_page_url_url'),
  $d11_field('node__field_page_url', 'field_page_url_uri'),
];
$terms = $d7->query('SELECT t.tid, t.name, t.weight, t.description, h.parent FROM {taxonomy_term_data} t LEFT JOIN {taxonomy_term_hierarchy} h ON h.tid = t.tid WHERE t.vid = :v', [':v' => $vid])->fetchAllAssoc('tid');
$nodes = $d11->query("SELECT l.field_legacy_tid_value tid, n.nid, n.title, w.field_weight_value weight, pl.field_legacy_tid_value parent, d.field_term_description_value description FROM {node_field_data} n JOIN {node__field_legacy_tid} l ON l.entity_id = n.nid LEFT JOIN {node__field_weight} w ON w.entity_id = n.nid LEFT JOIN {node__field_parent_taxon_reference} p ON p.entity_id = n.nid LEFT JOIN {node__field_legacy_tid} pl ON pl.entity_id = p.field_parent_taxon_reference_target_id LEFT JOIN {node__field_term_description} d ON d.entity_id = n.nid WHERE n.type = 'taxon'")->fetchAllAssoc('tid');
$compare['name'] = [array_map(fn($t) => $t->name, $terms), array_map(fn($n) => $n->title, $nodes)];
$compare['weight'] = [array_map(fn($t) => (int) $t->weight, $terms), array_map(fn($n) => (int) $n->weight, $nodes)];
$compare['parent_tid'] = [
  array_map(fn($t) => (int) $t->parent ?: NULL, $terms),
  array_map(fn($n) => (int) $n->parent ?: NULL, $nodes),
];
$compare['description'] = [array_map(fn($t) => $t->description, $terms), array_map(fn($n) => $n->description, $nodes)];
$vern_d7 = $d7->schema()->tableExists('field_data_field_vernacular_name_collection')
  ? $d7->query("SELECT c.entity_id, COUNT(*) n FROM {field_data_field_vernacular_name_collection} c JOIN {taxonomy_term_data} t ON t.tid = c.entity_id WHERE c.entity_type = 'taxonomy_term' AND c.deleted = 0 AND t.vid = :v GROUP BY c.entity_id", [':v' => $vid])->fetchAllKeyed()
  : [];
$vern_d11 = $d11->query('SELECT l.field_legacy_tid_value tid, COUNT(*) n FROM {node__field_vernacular_names} v JOIN {node__field_legacy_tid} l ON l.entity_id = v.entity_id GROUP BY l.field_legacy_tid_value')->fetchAllKeyed();
$compare['vernacular_count'] = [array_map('intval', $vern_d7), array_map('intval', $vern_d11)];

$mismatches = [];
$compared = 0;
$empty = fn($v) => $v === NULL || $v === '' || $v === FALSE;
foreach ($compare as $what => [$expected, $actual]) {
  foreach (array_keys($terms) as $tid) {
    $compared++;
    $e = $expected[$tid] ?? NULL;
    $a = $actual[$tid] ?? NULL;
    if ($empty($e) && $empty($a)) {
      continue;
    }
    if ((string) $e !== (string) $a) {
      $mismatches[] = [
        'tid' => (int) $tid,
        'nid' => isset($nodes[$tid]) ? (int) $nodes[$tid]->nid : NULL,
        'field' => $what,
        'd7' => $e,
        'd11' => $a,
      ];
    }
  }
}
$f = [
  'terms compared' => count($terms),
  'comparisons (term x value)' => $compared,
  'mismatches' => count($mismatches),
  'mismatch list' => $mismatches,
];

// ---------------------------------------------------------------------------
// Content checksums.
$skip = ['vid', 'revision_id'];
if ($no_changed) {
  $skip[] = 'changed';
}
$tables = ['node_field_data'];
foreach ($d11->schema()->findTables('node__field_%') as $t) {
  $tables[] = $t;
}
sort($tables);
$sums = [];
foreach ($tables as $t) {
  // Taxon nodes only (node_field_data.type, or the owning node's type).
  $sql = $t === 'node_field_data'
    ? "SELECT * FROM {node_field_data} WHERE type = 'taxon' ORDER BY nid, langcode"
    : "SELECT f.* FROM {{$t}} f JOIN {node_field_data} n ON n.nid = f.entity_id AND n.type = 'taxon' ORDER BY f.entity_id, f.langcode, f.delta";
  $h = hash_init('sha256');
  $n = 0;
  foreach ($d11->query($sql) as $row) {
    $row = (array) $row;
    foreach ($skip as $col) {
      unset($row[$col]);
    }
    hash_update($h, json_encode($row) . "\n");
    $n++;
  }
  $sums[$t] = "$n " . hash_final($h);
}

// ---------------------------------------------------------------------------
// Literature: bibcite references against the D7 biblio nodes.
$lit = NULL;
if ($d11->schema()->tableExists('bibcite_reference') && $d7->schema()->tableExists('biblio')) {
  $lit = [];
  // The same D7 biblio type -> bibcite type map as taxanotes_d7_biblio.yml.
  $type_map = [
    100 => 'book',
    101 => 'book_chapter',
    102 => 'journal_article',
    103 => 'conference_paper',
    104 => 'conference_proceedings',
    105 => 'newspaper_article',
    106 => 'magazine_article',
    108 => 'thesis',
    109 => 'report',
    120 => 'personal',
    121 => 'manuscript',
    124 => 'unpublished',
    125 => 'database',
    129 => 'miscellaneous',
  ];
  $lit['d7 biblio nodes'] = (int) $d7->query("SELECT COUNT(*) FROM {node} WHERE type = 'biblio'")->fetchField();
  $lit['d11 references'] = (int) $d11->query('SELECT COUNT(*) FROM {bibcite_reference}')->fetchField();
  $lit['d11 references by type'] = $d11->query('SELECT type, COUNT(*) c FROM {bibcite_reference} GROUP BY type ORDER BY type')->fetchAllKeyed();
  $lit['d7 biblio nodes by type'] = $d7->query('SELECT b.biblio_type, COUNT(*) c FROM {biblio} b JOIN {node} n ON n.vid = b.vid GROUP BY b.biblio_type ORDER BY b.biblio_type')->fetchAllKeyed();
  $lit['d7 contributors (biblio_contributor_data)'] = (int) $d7->query('SELECT COUNT(*) FROM {biblio_contributor_data}')->fetchField();
  $lit['d11 contributors'] = (int) $d11->query('SELECT COUNT(*) FROM {bibcite_contributor}')->fetchField();
  $lit['d7 keywords (biblio_keyword_data)'] = $d7->schema()->tableExists('biblio_keyword_data')
    ? (int) $d7->query('SELECT COUNT(*) FROM {biblio_keyword_data}')->fetchField() : 0;
  $lit['d11 keywords'] = (int) $d11->query('SELECT COUNT(*) FROM {bibcite_keyword}')->fetchField();
  $lit['d7 author rows (current revisions)'] = (int) $d7->query('SELECT COUNT(*) FROM {biblio_contributor} c JOIN {node} n ON n.vid = c.vid')->fetchField();
  $lit['d11 author rows'] = (int) $d11->query('SELECT COUNT(*) FROM {bibcite_reference__author}')->fetchField();
  $lit['d11 author rows by role'] = $d11->query('SELECT author_role, COUNT(*) c FROM {bibcite_reference__author} GROUP BY author_role ORDER BY author_role')->fetchAllKeyed();
  $lit['d7 keyword rows (current revisions)'] = $d7->schema()->tableExists('biblio_keyword')
    ? (int) $d7->query('SELECT COUNT(DISTINCT k.nid, k.kid) FROM {biblio_keyword} k JOIN {node} n ON n.vid = k.vid')->fetchField() : 0;
  $lit['d11 keyword rows'] = (int) $d11->query('SELECT COUNT(*) FROM {bibcite_reference__keywords}')->fetchField();
  // Taxon links: field_taxonomic_name rows of biblio nodes whose term is in
  // the vocabulary, one row set per language (entity_translation) of which
  // the node's own, else und, else the first is taken (as the source plugin
  // does), one link per distinct (node, term).
  $lit['d7 taxon link rows (all languages, vocabulary terms)'] = 0;
  $lit['d7 taxon links (node language rule, distinct)'] = 0;
  if ($d7->schema()->tableExists('field_data_field_taxonomic_name')) {
    $lit['d7 taxon link rows (all languages, vocabulary terms)'] = (int) $d7->query("SELECT COUNT(*) FROM {field_data_field_taxonomic_name} f JOIN {taxonomy_term_data} t ON t.tid = f.field_taxonomic_name_tid WHERE f.entity_type = 'node' AND f.bundle = 'biblio' AND f.deleted = 0 AND t.vid = :v", [':v' => $vid])->fetchField();
    $lit['d7 taxon links (node language rule, distinct)'] = (int) $d7->query("SELECT COUNT(DISTINCT f.entity_id, f.field_taxonomic_name_tid) FROM {field_data_field_taxonomic_name} f JOIN (SELECT f2.entity_id, SUBSTRING(MIN(CONCAT(CASE WHEN f2.language = n2.language THEN '0' WHEN f2.language = 'und' THEN '1' ELSE '2' END, f2.language)), 2) lang FROM {field_data_field_taxonomic_name} f2 JOIN {node} n2 ON n2.nid = f2.entity_id WHERE f2.entity_type = 'node' AND f2.bundle = 'biblio' AND f2.deleted = 0 GROUP BY f2.entity_id) best ON best.entity_id = f.entity_id AND best.lang = f.language JOIN {taxonomy_term_data} t ON t.tid = f.field_taxonomic_name_tid WHERE f.entity_type = 'node' AND f.bundle = 'biblio' AND f.deleted = 0 AND t.vid = :v", [':v' => $vid])->fetchField();
  }
  $lit['d11 taxon links'] = (int) $d11->query('SELECT COUNT(*) FROM {bibcite_reference__field_taxonomic_name}')->fetchField();
  $lit['d11 references with taxa'] = (int) $d11->query('SELECT COUNT(DISTINCT entity_id) FROM {bibcite_reference__field_taxonomic_name}')->fetchField();
  // Protologue: the taxon's field_reference against the D7 field_reference
  // of its term (the language rule of the taxon import), counted where the
  // D7 nid is a biblio node; and every link must point at the reference
  // imported from the shadow GUID.
  $ref_nids = $d7_field('field_reference', 'field_reference_nid');
  $biblio_nids = $d7->query("SELECT nid FROM {node} WHERE type = 'biblio'")->fetchCol();
  $biblio_nids = array_flip(array_map('intval', $biblio_nids));
  $lit['d7 terms with a protologue reference (existing biblio node)'] = count(array_filter($ref_nids, fn($nid) => isset($biblio_nids[(int) $nid])));
  $lit['d11 taxa with field_reference'] = $d11->schema()->tableExists('node__field_reference')
    ? (int) $d11->query('SELECT COUNT(*) FROM {node__field_reference}')->fetchField() : 0;
  $lit['d11 field_reference not matching the shadow GUID'] = $d11->schema()->tableExists('node__field_reference')
    ? (int) $d11->query('SELECT COUNT(*) FROM {node__field_reference} r JOIN {node__field_reference_guid} g ON g.entity_id = r.entity_id LEFT JOIN {bibcite_reference__field_scratchpads_uuid} u ON u.entity_id = r.field_reference_target_id WHERE u.field_scratchpads_uuid_value IS NULL OR u.field_scratchpads_uuid_value <> g.field_reference_guid_value')->fetchField() : 0;
  // Attachments.
  $lit['d7 attachment rows (current, file exists in file_managed, node language rule)'] = 0;
  if ($d7->schema()->tableExists('field_data_field_file')) {
    $lit['d7 attachment rows (current, file exists in file_managed, node language rule)'] = (int) $d7->query("SELECT COUNT(*) FROM {field_data_field_file} f JOIN (SELECT f2.entity_id, SUBSTRING(MIN(CONCAT(CASE WHEN f2.language = n2.language THEN '0' WHEN f2.language = 'und' THEN '1' ELSE '2' END, f2.language)), 2) lang FROM {field_data_field_file} f2 JOIN {node} n2 ON n2.nid = f2.entity_id WHERE f2.entity_type = 'node' AND f2.bundle = 'biblio' AND f2.deleted = 0 GROUP BY f2.entity_id) best ON best.entity_id = f.entity_id AND best.lang = f.language JOIN {file_managed} fm ON fm.fid = f.field_file_fid WHERE f.entity_type = 'node' AND f.bundle = 'biblio' AND f.deleted = 0")->fetchField();
  }
  $lit['d11 attachment rows'] = $d11->schema()->tableExists('bibcite_reference__field_file')
    ? (int) $d11->query('SELECT COUNT(*) FROM {bibcite_reference__field_file}')->fetchField() : 0;
  $lit['d11 file entities under public://scratchpads/'] = (int) $d11->query("SELECT COUNT(*) FROM {file_managed} WHERE uri LIKE 'public://scratchpads/%'")->fetchField();
  $lit['d11 attachment rows whose file is missing on disk'] = 0;
  if ($d11->schema()->tableExists('bibcite_reference__field_file')) {
    foreach ($d11->query('SELECT DISTINCT fm.uri FROM {bibcite_reference__field_file} f JOIN {file_managed} fm ON fm.fid = f.field_file_target_id') as $r) {
      if (!is_file(\Drupal::service('file_system')->realpath($r->uri))) {
        $lit['d11 attachment rows whose file is missing on disk']++;
      }
    }
  }
  $lit['files skipped by taxanotes_d7_file (not available locally)'] = $d11->schema()->tableExists('migrate_message_taxanotes_d7_file')
    ? (int) $d11->query('SELECT COUNT(*) FROM {migrate_message_taxanotes_d7_file}')->fetchField() : 0;

  // Reference by reference: title (D7 HTML tags stripped), year (9999 ->
  // NULL), type and the author list (last name|first name in rank order)
  // of every D7 biblio node against the reference imported from its uuid.
  $d7_refs = $d7->query('SELECT n.nid, n.uuid, n.title, b.biblio_type, b.biblio_year FROM {node} n JOIN {biblio} b ON b.vid = n.vid WHERE n.type = :t', [':t' => 'biblio'])->fetchAllAssoc('uuid');
  $d7_authors = [];
  foreach ($d7->query('SELECT n.uuid, d.lastname, d.firstname FROM {biblio_contributor} c JOIN {node} n ON n.vid = c.vid JOIN {biblio_contributor_data} d ON d.cid = c.cid ORDER BY n.nid, c.rank, c.cid') as $r) {
    $d7_authors[$r->uuid][] = trim((string) $r->lastname) . '|' . trim((string) $r->firstname);
  }
  $d11_refs = $d11->query('SELECT u.field_scratchpads_uuid_value uuid, r.id, r.title, r.type, r.bibcite_year FROM {bibcite_reference} r JOIN {bibcite_reference__field_scratchpads_uuid} u ON u.entity_id = r.id')->fetchAllAssoc('uuid');
  $d11_authors = [];
  foreach ($d11->query('SELECT u.field_scratchpads_uuid_value uuid, c.last_name, c.first_name FROM {bibcite_reference__author} a JOIN {bibcite_reference__field_scratchpads_uuid} u ON u.entity_id = a.entity_id JOIN {bibcite_contributor} c ON c.id = a.author_target_id ORDER BY a.entity_id, a.delta') as $r) {
    $d11_authors[$r->uuid][] = trim((string) $r->last_name) . '|' . trim((string) $r->first_name);
  }
  $lit_mismatches = [];
  $lit_compared = 0;
  foreach ($d7_refs as $uuid => $ref) {
    $expected = [
      'title' => strip_tags((string) $ref->title),
      'year' => ((int) $ref->biblio_year === 9999 || (int) $ref->biblio_year === 0) ? NULL : (int) $ref->biblio_year,
      'type' => $type_map[(int) $ref->biblio_type] ?? 'miscellaneous',
      'authors' => implode(';', $d7_authors[$uuid] ?? []),
    ];
    $got = isset($d11_refs[$uuid]) ? [
      'title' => (string) $d11_refs[$uuid]->title,
      'year' => $d11_refs[$uuid]->bibcite_year === NULL ? NULL : (int) $d11_refs[$uuid]->bibcite_year,
      'type' => (string) $d11_refs[$uuid]->type,
      'authors' => implode(';', $d11_authors[$uuid] ?? []),
    ] : NULL;
    foreach ($expected as $what => $e) {
      $lit_compared++;
      $a = $got[$what] ?? NULL;
      if ($got === NULL || (string) $e !== (string) $a) {
        $lit_mismatches[] = [
          'nid' => (int) $ref->nid,
          'reference id' => isset($d11_refs[$uuid]) ? (int) $d11_refs[$uuid]->id : NULL,
          'field' => $what,
          'd7' => $e,
          'd11' => $a,
        ];
      }
    }
  }
  $lit['references compared'] = count($d7_refs);
  $lit['comparisons (reference x value)'] = $lit_compared;
  $lit['mismatches'] = count($lit_mismatches);
  $lit['mismatch list'] = $lit_mismatches;

  // The /literature page as rendered: the first page of rows, and how many
  // of them have a non-empty Authors cell (a Views field whose handler is
  // broken renders every cell empty while the data is there, as happened
  // with the author field of this view), rendered as the anonymous user.
  if (\Drupal::moduleHandler()->moduleExists('views') && ($view = Views::getView('taxanotes_literature'))) {
    $account_switcher = \Drupal::service('account_switcher');
    $account_switcher->switchTo(new AnonymousUserSession());
    try {
      $view->setDisplay('page');
      $view->preExecute();
      $view->execute();
      $view->render();
      $broken = [];
      foreach ($view->field as $id => $handler) {
        if ($handler instanceof Broken) {
          $broken[] = $id;
        }
      }
      $with_author = 0;
      foreach (array_keys($view->result) as $i) {
        if (trim(strip_tags((string) $view->style_plugin->getField($i, 'author'))) !== '') {
          $with_author++;
        }
      }
      $lit['/literature rows rendered (first page)'] = count($view->result);
      $lit['/literature rows with an author shown'] = $with_author;
      $lit['/literature broken field handlers'] = $broken;
    }
    finally {
      $account_switcher->switchBack();
    }
  }
}

// ---------------------------------------------------------------------------
// Species pages (taxon_description <- spm) and images (media <- image files).
$spm = NULL;
if ($d11->schema()->tableExists('node__field_general_description') && $d7->schema()->tableExists('node') && $d7->schema()->tableExists('field_config_instance')) {
  $spm = [];
  $spm['d7 spm nodes'] = (int) $d7->query("SELECT COUNT(*) FROM {node} WHERE type = 'spm'")->fetchField();
  $spm['d7 spm nodes by status'] = $d7->query("SELECT status, COUNT(*) c FROM {node} WHERE type = 'spm' GROUP BY status")->fetchAllKeyed();
  $spm['d11 species pages'] = (int) $d11->query("SELECT COUNT(*) FROM {node_field_data} WHERE type = 'taxon_description'")->fetchField();
  $spm['d11 species pages by status'] = $d11->query("SELECT status, COUNT(*) c FROM {node_field_data} WHERE type = 'taxon_description' GROUP BY status")->fetchAllKeyed();
  $spm['d11 species page revisions'] = (int) $d11->query("SELECT COUNT(*) FROM {node_revision} r JOIN {node_field_data} n ON n.nid = r.nid WHERE n.type = 'taxon_description'")->fetchField();
  $spm['d7 spm revisions (not migrated)'] = (int) $d7->query("SELECT COUNT(*) FROM {node_revision} r JOIN {node} n ON n.nid = r.nid WHERE n.type = 'spm'")->fetchField();

  // One row per spm node of a node field (the node language rule), by nid.
  $d7_node_field = function (string $field, array $columns, string $bundle = 'spm', bool $all_deltas = FALSE) use ($d7): array {
    $values = [];
    if (!$d7->schema()->tableExists('field_data_' . $field)) {
      return $values;
    }
    $cols = implode(', ', array_map(fn($c) => "f.$c", $columns));
    $delta = $all_deltas ? '' : 'AND f.delta = 0';
    $rows = $d7->query("SELECT f.entity_id, f.delta, $cols FROM {field_data_$field} f JOIN (SELECT f2.entity_id, SUBSTRING(MIN(CONCAT(CASE WHEN f2.language = n2.language THEN '0' WHEN f2.language = 'und' THEN '1' ELSE '2' END, f2.language)), 2) lang FROM {field_data_$field} f2 JOIN {node} n2 ON n2.nid = f2.entity_id WHERE f2.entity_type = 'node' AND f2.bundle = :b AND f2.deleted = 0 GROUP BY f2.entity_id) best ON best.entity_id = f.entity_id AND best.lang = f.language WHERE f.entity_type = 'node' AND f.bundle = :b AND f.deleted = 0 $delta ORDER BY f.entity_id, f.delta", [':b' => $bundle]);
    foreach ($rows as $r) {
      $r = (array) $r;
      $nid = (int) $r['entity_id'];
      if ($all_deltas) {
        $values[$nid][] = $r;
      }
      else {
        $values[$nid] = $r;
      }
    }
    return $values;
  };

  // The taxon: the lowest-delta vocabulary term of field_taxonomic_name.
  $vocab_tids = $d7->query('SELECT tid FROM {taxonomy_term_data} WHERE vid = :v', [':v' => $vid])->fetchCol();
  $vocab_tids = array_flip(array_map('intval', $vocab_tids));
  $spm_taxon = [];
  foreach ($d7_node_field('field_taxonomic_name', ['field_taxonomic_name_tid'], 'spm', TRUE) as $nid => $items) {
    foreach ($items as $item) {
      $tid = (int) $item['field_taxonomic_name_tid'];
      if (isset($vocab_tids[$tid])) {
        $spm_taxon[$nid] = $tid;
        break;
      }
    }
  }
  $d7_spm = $d7->query("SELECT nid, uuid, title, status FROM {node} WHERE type = 'spm'")->fetchAllAssoc('nid');
  $spm['d7 spm nodes with a vocabulary taxon'] = count($spm_taxon);
  $spm['d7 spm nodes without (skipped by the import)'] = count($d7_spm) - count($spm_taxon);
  $spm['d7 spm taxonomic_name rows beyond the first vocabulary term (dropped)'] = (int) $d7->query("SELECT COUNT(*) FROM {field_data_field_taxonomic_name} f WHERE f.entity_type = 'node' AND f.bundle = 'spm' AND f.deleted = 0")->fetchField() - count($spm_taxon);
  $spm['d11 species pages with a taxon'] = (int) $d11->query("SELECT COUNT(*) FROM {node__field_taxonomic_name} f JOIN {node_field_data} n ON n.nid = f.entity_id WHERE n.type = 'taxon_description'")->fetchField();
  $spm['d11 species pages whose taxon is not a taxon node'] = (int) $d11->query("SELECT COUNT(*) FROM {node__field_taxonomic_name} f JOIN {node_field_data} n ON n.nid = f.entity_id LEFT JOIN {node_field_data} t ON t.nid = f.field_taxonomic_name_target_id AND t.type = 'taxon' WHERE n.type = 'taxon_description' AND t.nid IS NULL")->fetchField();
  $spm['d11 taxa with more than one species page'] = (int) $d11->query("SELECT COUNT(*) FROM (SELECT f.field_taxonomic_name_target_id FROM {node__field_taxonomic_name} f JOIN {node_field_data} n ON n.nid = f.entity_id WHERE n.type = 'taxon_description' GROUP BY f.field_taxonomic_name_target_id HAVING COUNT(*) > 1) x")->fetchField();

  // Page by page: title, status, taxon (legacy tid) and every text section
  // of the D7 node type (value and the mapped format), by uuid.
  $text_fields = $d7->query("SELECT DISTINCT fci.field_name FROM {field_config_instance} fci JOIN {field_config} fc ON fc.id = fci.field_id WHERE fci.entity_type = 'node' AND fci.bundle = 'spm' AND fci.deleted = 0 AND fc.type = 'text_long' ORDER BY fci.field_name")->fetchCol();
  $format_map = ['filtered_html' => 'basic_html', 'full_html' => 'full_html', 'plain_text' => 'plain_text'];
  $d11_pages = $d11->query("SELECT nb.uuid, n.nid, n.title, n.status, l.field_legacy_tid_value taxon_tid FROM {node_field_data} n JOIN {node} nb ON nb.nid = n.nid LEFT JOIN {node__field_taxonomic_name} f ON f.entity_id = n.nid LEFT JOIN {node__field_legacy_tid} l ON l.entity_id = f.field_taxonomic_name_target_id WHERE n.type = 'taxon_description'")->fetchAllAssoc('uuid');
  $spm_mismatches = [];
  $spm_compared = 0;
  $spm['d7 text sections not in the species page type'] = [];
  $d11_texts = [];
  foreach ($text_fields as $field) {
    if (!$d11->schema()->tableExists("node__$field")) {
      $spm['d7 text sections not in the species page type'][] = $field;
      continue;
    }
    $d11_texts[$field] = $d11->query("SELECT nb.uuid, f.{$field}_value v, f.{$field}_format fmt FROM {node__$field} f JOIN {node_field_data} n ON n.nid = f.entity_id JOIN {node} nb ON nb.nid = n.nid WHERE n.type = 'taxon_description'")->fetchAllAssoc('uuid');
  }
  $d7_texts = [];
  foreach (array_keys($d11_texts) as $field) {
    $d7_texts[$field] = $d7_node_field($field, ["{$field}_value", "{$field}_format"]);
  }
  foreach ($d7_spm as $nid => $node) {
    if (!isset($spm_taxon[$nid])) {
      // Skipped by the import (no vocabulary taxon): must not exist.
      if (isset($d11_pages[$node->uuid])) {
        $spm_mismatches[] = [
          'nid' => (int) $nid,
          'd11 nid' => (int) $d11_pages[$node->uuid]->nid,
          'field' => 'imported without a taxon',
          'd7' => NULL,
          'd11' => $d11_pages[$node->uuid]->taxon_tid,
        ];
      }
      continue;
    }
    $page = $d11_pages[$node->uuid] ?? NULL;
    $expected = [
      'title' => (string) $node->title,
      'status' => (int) $node->status,
      'taxon_tid' => (int) $spm_taxon[$nid],
    ];
    foreach (array_keys($d11_texts) as $field) {
      $t = $d7_texts[$field][$nid] ?? NULL;
      $value = $t["{$field}_value"] ?? NULL;
      $expected["$field value"] = ($value === NULL || $value === '') ? NULL : $value;
      $expected["$field format"] = ($value === NULL || $value === '') ? NULL : ($format_map[$t["{$field}_format"] ?? ''] ?? 'basic_html');
    }
    $got = NULL;
    if ($page) {
      $got = ['title' => (string) $page->title, 'status' => (int) $page->status, 'taxon_tid' => (int) $page->taxon_tid];
      foreach (array_keys($d11_texts) as $field) {
        $got["$field value"] = $d11_texts[$field][$node->uuid]->v ?? NULL;
        $got["$field format"] = $d11_texts[$field][$node->uuid]->fmt ?? NULL;
      }
    }
    foreach ($expected as $what => $e) {
      $spm_compared++;
      $a = $got[$what] ?? NULL;
      if ($got === NULL || (string) $e !== (string) $a) {
        $spm_mismatches[] = [
          'nid' => (int) $nid,
          'd11 nid' => $page ? (int) $page->nid : NULL,
          'field' => $what,
          'd7' => $e,
          'd11' => $a,
        ];
      }
    }
  }
  $spm['pages compared'] = count($spm_taxon);
  $spm['comparisons (page x value)'] = $spm_compared;
  $spm['mismatches'] = count($spm_mismatches);
  $spm['mismatch list'] = $spm_mismatches;

  // Images: the rule of the import. Used = placed on an spm node (field_media)
  // or tagged with a vocabulary term (field_taxonomic_name on the file);
  // renderable = extension the image toolkit handles; present = under
  // $settings['taxanotes_import']['source_files_dir'] on this machine.
  $used = [];
  if ($d7->schema()->tableExists('field_data_field_media')) {
    foreach ($d7->query("SELECT DISTINCT m.field_media_fid fid FROM {field_data_field_media} m JOIN {file_managed} fm ON fm.fid = m.field_media_fid WHERE m.entity_type = 'node' AND m.bundle = 'spm' AND m.deleted = 0 AND fm.type = 'image'")->fetchCol() as $fid) {
      $used[(int) $fid] = TRUE;
    }
  }
  if ($d7->schema()->tableExists('field_data_field_taxonomic_name')) {
    foreach ($d7->query("SELECT DISTINCT t.entity_id FROM {field_data_field_taxonomic_name} t JOIN {taxonomy_term_data} td ON td.tid = t.field_taxonomic_name_tid JOIN {file_managed} fm ON fm.fid = t.entity_id WHERE t.entity_type = 'file' AND t.deleted = 0 AND td.vid = :v AND fm.type = 'image'", [':v' => $vid])->fetchCol() as $fid) {
      $used[(int) $fid] = TRUE;
    }
  }
  $toolkit_ext = array_map('strtolower', \Drupal::service('image.toolkit.manager')->getDefaultToolkit()->getSupportedExtensions());
  $files_dir = Settings::get('taxanotes_import', [])['source_files_dir'] ?? NULL;
  $spm['d7 image files'] = (int) $d7->query("SELECT COUNT(*) FROM {file_managed} WHERE type = 'image'")->fetchField();
  $spm['d7 images used (placed on an spm node or tagged with a vocabulary taxon)'] = count($used);
  $renderable = 0;
  $present = 0;
  $present_fids = [];
  if ($used) {
    foreach ($d7->query('SELECT fid, uri FROM {file_managed} WHERE fid IN (:fids[])', [':fids[]' => array_keys($used)]) as $file) {
      $ext = strtolower(pathinfo($file->uri, PATHINFO_EXTENSION));
      if (!in_array($ext, $toolkit_ext, TRUE)) {
        continue;
      }
      $renderable++;
      $relative = str_starts_with($file->uri, 'public://') ? substr($file->uri, 9) : NULL;
      if ($relative !== NULL && is_string($files_dir) && $files_dir !== '' && is_file(rtrim($files_dir, '/') . '/' . $relative)) {
        $present++;
        $present_fids[(int) $file->fid] = TRUE;
      }
    }
  }
  $spm['d7 images used and renderable by the image toolkit'] = $renderable;
  $spm['d7 images used, renderable and present under source_files_dir'] = $present;
  $spm['source_files_dir configured'] = is_string($files_dir) && $files_dir !== '';
  $spm['d11 media items (image)'] = (int) $d11->query("SELECT COUNT(*) FROM {media_field_data} WHERE bundle = 'image'")->fetchField();
  $spm['d11 media items whose file is missing on disk'] = 0;
  $spm['d11 media items whose id is not the D7 fid of their file'] = (int) $d11->query('SELECT COUNT(*) FROM {media__field_media_image} i WHERE i.entity_id <> i.field_media_image_target_id')->fetchField();
  foreach ($d11->query('SELECT fm.uri FROM {media__field_media_image} i JOIN {file_managed} fm ON fm.fid = i.field_media_image_target_id') as $r) {
    if (!is_file(\Drupal::service('file_system')->realpath($r->uri))) {
      $spm['d11 media items whose file is missing on disk']++;
    }
  }
  // Taxon links of the imported images (distinct vocabulary terms, und
  // first, else the first language, as the source plugin does).
  $d7_image_links = 0;
  if ($present_fids && $d7->schema()->tableExists('field_data_field_taxonomic_name')) {
    $sql = "SELECT COUNT(DISTINCT t.entity_id, t.field_taxonomic_name_tid) FROM {field_data_field_taxonomic_name} t"
      . " JOIN (SELECT f2.entity_id, SUBSTRING(MIN(CONCAT(CASE WHEN f2.language = 'und' THEN '1' ELSE '2' END, f2.language)), 2) lang"
      . " FROM {field_data_field_taxonomic_name} f2 WHERE f2.entity_type = 'file' AND f2.deleted = 0 GROUP BY f2.entity_id) best"
      . " ON best.entity_id = t.entity_id AND best.lang = t.language"
      . " JOIN {taxonomy_term_data} td ON td.tid = t.field_taxonomic_name_tid"
      . " WHERE t.entity_type = 'file' AND t.deleted = 0 AND td.vid = :v AND t.entity_id IN (:fids[])";
    $d7_image_links = (int) $d7->query($sql, [
      ':v' => $vid,
      ':fids[]' => array_keys($present_fids),
    ])->fetchField();
  }
  $spm['d7 image taxon links (imported images, vocabulary terms, distinct)'] = $d7_image_links;
  $spm['d11 media taxon links'] = (int) $d11->query('SELECT COUNT(*) FROM {media__field_taxonomic_name}')->fetchField();
  $spm['d11 media with taxa'] = (int) $d11->query('SELECT COUNT(DISTINCT entity_id) FROM {media__field_taxonomic_name}')->fetchField();
  $spm['d11 media links whose target is not a taxon node'] = (int) $d11->query("SELECT COUNT(*) FROM {media__field_taxonomic_name} f LEFT JOIN {node_field_data} t ON t.nid = f.field_taxonomic_name_target_id AND t.type = 'taxon' WHERE t.nid IS NULL")->fetchField();
  $licence_map = [
    1 => 'all_rights_reserved',
    2 => 'cc_by',
    3 => 'cc_by_sa',
    4 => 'cc_by_nd',
    5 => 'cc_by_nc',
    6 => 'cc_by_nc_sa',
    7 => 'cc_by_nc_nd',
    8 => 'cc0',
    9 => 'public_domain',
  ];
  $d7_licences = [];
  if ($present_fids && $d7->schema()->tableExists('field_data_field_cc_licence')) {
    $sql = "SELECT field_cc_licence_licence l, COUNT(*) c FROM {field_data_field_cc_licence}"
      . " WHERE entity_type = 'file' AND deleted = 0 AND delta = 0 AND entity_id IN (:fids[]) GROUP BY field_cc_licence_licence";
    foreach ($d7->query($sql, [':fids[]' => array_keys($present_fids)]) as $r) {
      $d7_licences[$licence_map[(int) $r->l] ?? "unknown {$r->l}"] = (int) $r->c;
    }
  }
  ksort($d7_licences);
  $spm['d7 licences of the imported images'] = $d7_licences;
  $spm['d11 media by licence'] = $d11->query('SELECT field_cc_licence_value, COUNT(*) c FROM {media__field_cc_licence} GROUP BY field_cc_licence_value ORDER BY field_cc_licence_value')->fetchAllKeyed();
  $spm['d11 media with a credit'] = (int) $d11->query('SELECT COUNT(*) FROM {media__field_credit}')->fetchField();
  $spm['d11 media with a description'] = (int) $d11->query('SELECT COUNT(*) FROM {media__field_description}')->fetchField();
  // Images placed on pages.
  $placed = 0;
  foreach ($d7_node_field('field_media', ['field_media_fid'], 'spm', TRUE) as $nid => $items) {
    if (!isset($spm_taxon[$nid])) {
      continue;
    }
    foreach ($items as $item) {
      if (isset($present_fids[(int) $item['field_media_fid']])) {
        $placed++;
      }
    }
  }
  $spm['d7 spm field_media rows whose image was imported'] = $placed;
  $spm['d11 species page media rows'] = (int) $d11->query('SELECT COUNT(*) FROM {node__field_media}')->fetchField();
  $spm['d11 files under public://scratchpads/ (count, bytes)'] = $d11->query("SELECT COUNT(*) c, COALESCE(SUM(filesize), 0) b FROM {file_managed} WHERE uri LIKE 'public://scratchpads/%'")->fetchAssoc();
  $spm['d11 image files under public://scratchpads/ (count, bytes)'] = $d11->query("SELECT COUNT(*) c, COALESCE(SUM(filesize), 0) b FROM {file_managed} WHERE uri LIKE 'public://scratchpads/%' AND filemime LIKE 'image/%'")->fetchAssoc();
  $spm['files skipped by taxanotes_d7_file: not available locally'] = $d11->schema()->tableExists('migrate_message_taxanotes_d7_file')
    ? (int) $d11->query("SELECT COUNT(*) FROM {migrate_message_taxanotes_d7_file} WHERE message LIKE '%Source file is not available%'")->fetchField() : 0;
  $spm['files skipped by taxanotes_d7_file: image type not renderable'] = $d11->schema()->tableExists('migrate_message_taxanotes_d7_file')
    ? (int) $d11->query("SELECT COUNT(*) FROM {migrate_message_taxanotes_d7_file} WHERE message LIKE '%Image type%'")->fetchField() : 0;
  $spm['media skipped by taxanotes_d7_media_image (file not imported)'] = $d11->schema()->tableExists('migrate_message_taxanotes_d7_media_image')
    ? (int) $d11->query('SELECT COUNT(*) FROM {migrate_message_taxanotes_d7_media_image}')->fetchField() : 0;
}

echo json_encode(['counts' => $c, 'fields' => $f, 'checksums' => $sums, 'literature' => $lit, 'species' => $spm], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
