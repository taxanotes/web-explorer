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
 *   orphans, roots, users, roles, revisions, accepted and vernacular names,
 *   weights, ranks, usages);
 * - fields: a comparison of EVERY term's current values with the D7
 *   field_data_* tables (what the Scratchpads site renders), one entry per
 *   mismatching (term, field), so an empty list is a value-by-value proof;
 * - checksums: a sha256 per content table (node_field_data without vid and,
 *   with the argument "nochanged", without changed; every node__field_*
 *   table without revision_id) to compare an instance before and after a
 *   platform change, e.g. the Myriatrix control while another instance is
 *   built.
 */

use Drupal\Core\Database\Database;

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
$c['d7 users with changed = 0'] = (int) $d7->query('SELECT COUNT(*) FROM {users} WHERE uid > 1 AND changed = 0')->fetchField();
$c['d11 users whose changed differs from D7 (changed, or created when 0)'] = 0;
foreach ($d7->query('SELECT uid, created, changed FROM {users} WHERE uid > 1') as $u) {
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
$map = $d11->query('SELECT sourceid1, destid1 FROM {migrate_map_taxanotes_d7_taxon_revision}')->fetchAllKeyed();
$default_vid = $d11->query("SELECT l.field_legacy_tid_value, n.vid FROM {node_field_data} n JOIN {node__field_legacy_tid} l ON l.entity_id = n.nid WHERE n.type = 'taxon'")->fetchAllKeyed();
$ok = 0;
foreach ($current as $tid => $rid) {
  if (isset($map[$rid], $default_vid[$tid]) && (int) $map[$rid] === (int) $default_vid[$tid]) {
    $ok++;
  }
}
$c['default revision = D7 current'] = $ok;
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
// language, else und, else the alphabetically first (joinFieldTable()).
$term_lang = $d7->query('SELECT tid, language FROM {taxonomy_term_data} WHERE vid = :v', [':v' => $vid])->fetchAllKeyed();

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
  'field_unit_indicator1' => 'node__field_unit_indicator_1',
  'field_unit_indicator2' => 'node__field_unit_indicator_2',
  'field_unit_indicator3' => 'node__field_unit_indicator_3',
  'field_unit_indicator4' => 'node__field_unit_indicator_4',
  'field_unit_name1' => 'node__field_unit_name_1',
  'field_unit_name2' => 'node__field_unit_name_2',
  'field_unit_name3' => 'node__field_unit_name_3',
  'field_unit_name4' => 'node__field_unit_name_4',
  'field_usage' => 'node__field_usage',
];
$trimmed = ['field_unit_name1', 'field_unit_name2', 'field_unit_name3', 'field_unit_name4'];

// Expected (D7) and actual (D11) values per comparison, keyed by tid.
$compare = [];
foreach ($scalar as $field => $table) {
  $expected = $d7_field($field, $field . '_value');
  if (in_array($field, $trimmed, TRUE)) {
    $expected = array_map(fn($v) => is_string($v) ? trim($v) : $v, $expected);
  }
  $compare[$field] = [$expected, $d11_field($table, substr($table, 6) . '_value')];
}
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
  $order = $t === 'node_field_data' ? 'nid, langcode' : 'entity_id, langcode, delta';
  $h = hash_init('sha256');
  $n = 0;
  foreach ($d11->query("SELECT * FROM {{$t}} ORDER BY $order") as $row) {
    $row = (array) $row;
    foreach ($skip as $col) {
      unset($row[$col]);
    }
    hash_update($h, json_encode($row) . "\n");
    $n++;
  }
  $sums[$t] = "$n " . hash_final($h);
}

echo json_encode(['counts' => $c, 'fields' => $f, 'checksums' => $sums], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
