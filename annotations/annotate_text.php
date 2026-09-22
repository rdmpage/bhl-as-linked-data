<?php

// experiment with annotating text

error_reporting(E_ALL);

require_once (dirname(dirname(__FILE__)) . '/shared.php');
require_once (dirname(dirname(__FILE__)) . '/sql/sqlite.php');

require_once (dirname(__FILE__) . '/geotag.php');
require_once (dirname(__FILE__) . '/annotation2rdf.php');

//----------------------------------------------------------------------------------------
// Fetch a URL. Returns the body on HTTP 200, or null on anything else.
//
// Three things here exist because of what a bulk run does to the old version of this
// function, none of which a thirty page item can show you.
//
// It returns null rather than calling die(). A run over every item in the store is 2 million
// fetches; a single connection reset eleven hours in should not end it.
//
// It checks the status code. S3 answers a missing key with 200 lines of XML and a 404, and
// the caller used to write whatever came back straight into the text cache -- where
// file_exists() then reports it as cached for good, and the tagger reads <?xml version...
// as if it were the page. One wrong sequence number and that page is quietly poisoned
// forever, which is the failure I would least want to find after a ten day run.
//
// And the transport check is === false, not == false. An empty string is == false in PHP,
// so a page that genuinely OCRed to nothing -- a blank leaf, and BHL is full of them --
// used to take the whole run down with it.
function get($url, $format = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);

	if ($format != '')
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: " . $format));	
	}
	
	$response = curl_exec($ch);

	if ($response === false)
	{
		fwrite(STDERR, "curl: " . curl_error($ch) . " for $url\n");
		curl_close($ch);
		return null;
	}
	
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	
	curl_close($ch);

	if ($http_code != 200)
	{
		fwrite(STDERR, "HTTP $http_code for $url\n");
		return null;
	}
	
	return $response;
}

//----------------------------------------------------------------------------------------
// Where a page's OCR text lives in the cache.
//
// Sharded on the last three digits of the page id. The store has 2,055,200 pages with OCR,
// and while the filesystem will take all of them in one directory, nothing else will -- a
// glob() over the cache is what checks how far a run got, and it has to build the whole list
// first. A thousand buckets of ~2,000 files each keeps that usable.
function text_cache_path($page_id)
{
	$shard = substr(str_pad($page_id, 3, '0', STR_PAD_LEFT), -3);

	$directory = dirname(__FILE__) . '/textcache/' . $shard;

	if (!file_exists($directory))
	{
		mkdir($directory, 0755, true);
	}

	return $directory . '/' . $page_id . '.txt';
}

//----------------------------------------------------------------------------------------
// OCR text for a page, from the cache if it is there. Returns null if it cannot be had,
// and caches only what actually arrived -- a miss is left uncached so that a page lost to
// a transient error is retried next run rather than remembered as empty.
function get_page_text($url, $page_id)
{
	$text_filename = text_cache_path($page_id);

	if (file_exists($text_filename))
	{
		return file_get_contents($text_filename);
	}

	$text = get($url);

	if (is_null($text))
	{
		return null;
	}

	file_put_contents($text_filename, $text);

	return $text;
}

//----------------------------------------------------------------------------------------
// Annotate the OCR text of every page of an item, writing N-Triples to stdout as it goes.
//
// Streaming rather than returning an array. Over one item the difference is nothing; over
// the store it is the difference between finishing and not, since the triples for 2 million
// pages will not fit in memory and nothing downstream needs them all at once anyway.
//
// Returns a small summary so a caller driving many items can report progress.
function annotate_item($ItemID = null)
{
	global $config;

	// DISTINCT, and only the four columns this script actually uses.
	//
	// The page table carries one row per page TYPE, so selecting PageTypeName multiplies
	// pages that carry more than one: item 262715 is 29 pages but 48 rows. The version this
	// replaced collected into an array keyed on PageID, which hid the duplication; streaming
	// does not, and without DISTINCT every such page is fetched, tagged and emitted twice.
	// PagePrefix, PageNumber and PageTypeName are all unused here -- page names are
	// sql2rdf.php's business -- so dropping them costs nothing and collapses the duplicates.
	$sql = 'SELECT DISTINCT PageID, ItemID, BarCode, SequenceOrder FROM page';
	$sql .= ' INNER JOIN (SELECT DISTINCT ItemID, BarCode FROM item) AS item USING(ItemID)';
	
	if ($ItemID)
	{
		$sql .= ' WHERE ItemID='. $ItemID;
	}
	
	$sql .= ' ORDER BY ItemID, CAST(SequenceOrder AS INTEGER)';
	
	$data = db_get($sql);

	$summary = new stdclass;
	$summary->pages = 0;
	$summary->missing = 0;
	$summary->annotations = 0;

	foreach ($data as $row)
	{
		$page = new stdclass;
		$page->id = $config['bhl'] . '/page/' . $row->PageID;

		$page->text_url = $config['aws'] . "/ocr/item-" . str_pad($row->ItemID, 6, '0', STR_PAD_LEFT) 
			. '/item-' . str_pad($row->ItemID, 6, '0', STR_PAD_LEFT)
			. '-' . str_pad($row->PageID, 8, '0', STR_PAD_LEFT) . '-' . str_pad($row->SequenceOrder, 4, '0', STR_PAD_LEFT) . '.txt';

		$text = get_page_text($page->text_url, $row->PageID);

		if (is_null($text))
		{
			$summary->missing++;
			continue;
		}

		$summary->pages++;

		$annotations = tag_geo($text);

		if (count($annotations) == 0)
		{
			continue;
		}

		$triples = array();

		foreach ($annotations as $annotation)
		{
			annotation_to_triples($triples, $annotation, $page);
		}

		$summary->annotations += count($annotations);

		echo dump_triples($triples);
	}

	return $summary;
}

//----------------------------------------------------------------------------------------
// Only when run directly, so a driver script can require this file for annotate_item()
// without it tagging item 262715 as a side effect of the include.
if (isset($argv[0]) && realpath($argv[0]) === __FILE__)
{
	$item = 262715;

	if (isset($argv[1]))
	{
		$item = $argv[1];
	}

	$summary = annotate_item($item);

	fwrite(STDERR, "item $item: $summary->pages pages, $summary->missing without text, $summary->annotations annotations\n");
}

?>
