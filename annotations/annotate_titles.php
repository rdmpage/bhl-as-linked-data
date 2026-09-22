<?php

// Geotag every page of every item of one or more BHL titles.
//
// Usage:
//   php annotations/annotate_titles.php 149841 144642 > geo.nt
//
// N-Triples to stdout, progress to stderr, so the output stays loadable while you watch it.
//
// Per-page fetching, one request per page, because the per-item OCR that S3 also publishes
// at ocr/item-NNNNNN/item-NNNNNN.txt is a different rendering of the text rather than a
// concatenation of the page files -- page 57579616's text does not occur in item 262715's
// item-level file at all, even ignoring line endings. Offsets taken from it would not point
// into the file the annotation names as its source, so the item file cannot stand in here
// however much cheaper it is.

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/annotate_text.php');

//----------------------------------------------------------------------------------------
// Items of a title, in the order BHL numbers them
function get_title_items($TitleID)
{
	$sql = 'SELECT DISTINCT ItemID FROM item WHERE TitleID=' . (Integer)$TitleID . ' ORDER BY ItemID';

	$items = array();

	foreach (db_get($sql) as $row)
	{
		$items[] = $row->ItemID;
	}

	return $items;
}

//----------------------------------------------------------------------------------------

$titles = array_slice($argv, 1);

if (count($titles) == 0)
{
	die("usage: php annotate_titles.php <TitleID> [TitleID ...] > geo.nt\n");
}

$started = time();

// Count the work up front, so progress reads against the whole run. Reporting
// count($items) instead makes the last line of a two title run say "249/238".
$total_items = 0;

foreach ($titles as $TitleID)
{
	$total_items += count(get_title_items($TitleID));
}

$totals = new stdclass;
$totals->items = 0;
$totals->pages = 0;
$totals->missing = 0;
$totals->annotations = 0;

foreach ($titles as $TitleID)
{
	$items = get_title_items($TitleID);

	fwrite(STDERR, "title $TitleID: " . count($items) . " items\n");

	foreach ($items as $ItemID)
	{
		$summary = annotate_item($ItemID);

		$totals->items++;
		$totals->pages += $summary->pages;
		$totals->missing += $summary->missing;
		$totals->annotations += $summary->annotations;

		// flush, so a run that is killed part way still leaves complete triples on disk
		flush();

		$elapsed = max(1, time() - $started);

		fwrite(STDERR, sprintf("  item %s: %d pages, %d missing, %d annotations"
			. "   [%d/%d items, %d pages, %d annotations, %.1f pages/s]\n",
			$ItemID, $summary->pages, $summary->missing, $summary->annotations,
			$totals->items, $total_items, $totals->pages, $totals->annotations,
			$totals->pages / $elapsed));
	}
}

fwrite(STDERR, sprintf("\ndone: %d items, %d pages (%d without text), %d annotations in %d minutes\n",
	$totals->items, $totals->pages, $totals->missing, $totals->annotations,
	round((time() - $started) / 60)));

?>
