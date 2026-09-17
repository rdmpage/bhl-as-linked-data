#!/usr/bin/env php
<?php

// Export every BHL creator as one record per CreatorID, ready for RDF modelling.
//
//   php export_creators.php --db bhl.db > creators.json
//   php export_creators.php --db bhl.db --ndjson > creators.ndjson
//   php export_creators.php --db bhl.db --limit 50 --pretty
//
// Needs creator_names.php beside it - copy both files together.
//
// Creators come from BOTH credit tables, because there is no master creator table in
// BHL: `creator` holds title-level credits (79,892 creators) and `partcreator` holds
// article-level ones (171,732), overlapping by only 10,149. Either table alone misses
// most of the people. No CreatorID carries more than one spelling of its name, so the
// name is a clean function of the id and the two tables cannot disagree.
//
// Output is a JSON object keyed by CreatorID, written as a stream rather than built in
// memory - the full export is ~240k records. --ndjson gives one record per line instead,
// which is easier to pipe through jq or process a line at a time.

require_once(dirname(__FILE__) . '/creator_names.php');

//----------------------------------------------------------------------------------------
$opt = array('db' => 'bhl.db', 'out' => null, 'ndjson' => false, 'pretty' => false,
	'limit' => 0);

$args = array_slice($argv, 1);

for ($i = 0; $i < count($args); $i++)
{
	switch ($args[$i])
	{
		case '--db':     $opt['db']     = $args[++$i]; break;
		case '--out':    $opt['out']    = $args[++$i]; break;
		case '--limit':  $opt['limit']  = (int)$args[++$i]; break;
		case '--ndjson': $opt['ndjson'] = true; break;
		case '--pretty': $opt['pretty'] = true; break;

		case '-h':
		case '--help':
			fwrite(STDERR,
				"usage: export_creators.php [--db bhl.db] [--out FILE] [--ndjson] [--pretty] [--limit N]\n" .
				"       writes to stdout unless --out is given; progress goes to stderr\n");
			exit(0);

		default:
			fwrite(STDERR, "unknown option: " . $args[$i] . "\n");
			exit(1);
	}
}

if (!file_exists($opt['db']))
{
	fwrite(STDERR, "no database at " . $opt['db'] . "\n");
	exit(1);
}

//----------------------------------------------------------------------------------------
// One row per creator, with the multi-valued bits pre-joined by SQLite. The separators
// are ASCII unit (31) and record (30) separators, checked absent from the source data,
// so nothing has to be escaped or guessed at when splitting them apart again.
$sql = "
WITH names AS (
	SELECT CreatorID, MIN(CreatorName) AS CreatorName
	FROM (SELECT CreatorID, CreatorName FROM creator
	      UNION ALL
	      SELECT CreatorID, CreatorName FROM partcreator)
	GROUP BY CreatorID),
titles AS (
	SELECT CreatorID, COUNT(DISTINCT TitleID) AS n FROM creator GROUP BY CreatorID),
parts AS (
	SELECT CreatorID, COUNT(DISTINCT PartID) AS n FROM partcreator GROUP BY CreatorID),
types AS (
	SELECT CreatorID, group_concat(t || char(31) || c, char(30)) AS s
	FROM (SELECT CreatorID, CreatorType AS t, COUNT(*) AS c
	      FROM creator GROUP BY CreatorID, CreatorType)
	GROUP BY CreatorID),
ids AS (
	SELECT CreatorID,
	       group_concat(IdentifierName || char(31) || IdentifierValue, char(30)) AS s
	FROM creatoridentifier GROUP BY CreatorID)
SELECT names.CreatorID AS id, names.CreatorName AS name,
	titles.n AS titles, parts.n AS parts, types.s AS types, ids.s AS identifiers
FROM names
LEFT JOIN titles ON titles.CreatorID = names.CreatorID
LEFT JOIN parts  ON parts.CreatorID  = names.CreatorID
LEFT JOIN types  ON types.CreatorID  = names.CreatorID
LEFT JOIN ids    ON ids.CreatorID    = names.CreatorID
ORDER BY names.CreatorID";

if ($opt['limit'] > 0)
{
	$sql .= ' LIMIT ' . $opt['limit'];
}

//----------------------------------------------------------------------------------------
// Split a group_concat'd list back into pairs.
function unpack_pairs($s)
{
	$out = array();

	if ($s === null || $s === '')
	{
		return $out;
	}

	foreach (explode(chr(30), $s) as $item)
	{
		$bits = explode(chr(31), $item, 2);
		$out[] = array($bits[0], isset($bits[1]) ? $bits[1] : '');
	}

	return $out;
}

//----------------------------------------------------------------------------------------
$pdo = new PDO('sqlite:' . $opt['db']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$fh = ($opt['out'] === null) ? STDOUT : fopen($opt['out'], 'w');

if ($fh === false)
{
	fwrite(STDERR, "cannot write to " . $opt['out'] . "\n");
	exit(1);
}

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if ($opt['pretty'])
{
	$flags |= JSON_PRETTY_PRINT;
}

$rows = $pdo->query($sql);

if (!$opt['ndjson'])
{
	fwrite($fh, "{\n");
}

$n = 0;
$kinds = array();
$t0 = microtime(true);

while ($row = $rows->fetch(PDO::FETCH_ASSOC))
{
	$types = unpack_pairs($row['types']);

	// The kind belongs to the creator, so it is resolved once across every row BHL
	// typed - 229 creators are typed inconsistently and get the majority verdict.
	// Where BHL never typed them (everyone who appears only in partcreator) the name
	// itself decides.
	$stated = array();

	foreach ($types as $t)
	{
		$stated[] = $t[0];
	}

	$kind = creator_kind_from_types($stated);
	$kind_stated = ($kind !== null);

	$p = parse_creator_name($row['name'], $kind);

	$labels = array('personal' => 'person', 'corporate' => 'organization',
		'meeting' => 'conference');
	$classes = array('personal' => 'foaf:Person', 'corporate' => 'foaf:Organization',
		'meeting' => 'bibo:Conference');

	// Main/Added is a property of the CREDIT, not of the creator - 10,007 creators are
	// a main entry on one title and an added entry on another - so it is reported per
	// type and counted, never flattened into a single role for the person.
	$type_out = array();
	$main = 0;
	$added = 0;

	foreach ($types as $t)
	{
		$facets = parse_creator_type($t[0]);
		$count = (int)$t[1];

		$type_out[] = array(
			'type'  => $t[0],
			'entry' => $facets['entry'],
			'role'  => $facets['role'],
			'count' => $count
		);

		if ($facets['entry'] === 'main')
		{
			$main += $count;
		}
		else if ($facets['entry'] === 'added')
		{
			$added += $count;
		}
	}

	// External identifiers, the starting point for owl:sameAs. Grouped by scheme
	// because a creator can carry more than one value for the same one.
	$identifiers = array();

	foreach (unpack_pairs($row['identifiers']) as $pair)
	{
		if (!isset($identifiers[$pair[0]]))
		{
			$identifiers[$pair[0]] = array();
		}

		if (!in_array($pair[1], $identifiers[$pair[0]], true))
		{
			$identifiers[$pair[0]][] = $pair[1];
		}
	}

	$record = array(
		'id'           => (int)$row['id'],
		'name'         => $row['name'],
		'kind'         => $p['kind'],
		'kind_stated'  => $kind_stated,
		'label'        => isset($labels[$p['kind']]) ? $labels[$p['kind']] : null,
		'rdf_class'    => isset($classes[$p['kind']]) ? $classes[$p['kind']] : null,
		'parsed'       => array(
			'name'      => $p['name'],
			'family'    => $p['family'],
			'given'     => $p['given'],
			'initials'  => $p['initials'],
			'honorific' => $p['honorific'],
			'suffix'    => $p['suffix'],
			'note'      => isset($p['note']) ? $p['note'] : null
		),
		'alternatives' => $p['alternatives'],
		'dates'        => $p['dates'],
		'types'        => $type_out,
		'credits'      => array(
			'titles' => (int)$row['titles'],
			'parts'  => (int)$row['parts'],
			'main'   => $main,
			'added'  => $added
		),
		// cast so an empty map encodes as {} and not [] - without this the field
		// changes JSON type between records and breaks anything typed reading it
		'identifiers'  => (object)$identifiers
	);

	$json = json_encode($record, $flags);

	if ($opt['ndjson'])
	{
		fwrite($fh, $json . "\n");
	}
	else
	{
		fwrite($fh, ($n > 0 ? ",\n" : '') . json_encode((string)$record['id'])
			. ': ' . $json);
	}

	$n++;
	$kinds[$p['kind']] = (isset($kinds[$p['kind']]) ? $kinds[$p['kind']] : 0) + 1;

	if ($n % 25000 === 0)
	{
		fwrite(STDERR, "  " . number_format($n) . " creators\n");
	}
}

if (!$opt['ndjson'])
{
	fwrite($fh, "\n}\n");
}

if ($fh !== STDOUT)
{
	fclose($fh);
}

fwrite(STDERR, "exported " . number_format($n) . " creators in "
	. round(microtime(true) - $t0, 1) . "s\n");

foreach ($kinds as $k => $v)
{
	fwrite(STDERR, "  " . str_pad($k, 12) . number_format($v) . "\n");
}
