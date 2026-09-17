<?php

// Canvas dimensions as RDF, read from canvas.sqlite instead of a scandata.xml file.
//
// Same triples as scan2rdf.php — exif width and height for each canvas, and for
// the thumbnail image drawn onto it — but sourced from a database covering every
// BHL item rather than from a scandata file that has to be downloaded first.
//
// canvas.sqlite is built by 04-export-canvas.php in the bhl-scandata repo and is
// not in git (~2 GB). See the README.
//
// Three things the database has already settled, all of which scan2rdf.php has
// to work out from the XML each time:
//
//   - leaves excluded from the access formats (colour cards, targets, deleted
//     leaves) are already gone, so nothing here can emit a canvas for an image
//     the reader is never meant to see;
//   - seq is the 1-based counter over the leaves that remain, which is the
//     number in the _thumb/_large/_full derivative filenames. It is not leafNum,
//     and using leafNum returns a different page's image with HTTP 200;
//   - width and height are the cropBox, which is the size of the image actually
//     served, and NULL where the scan data does not say.
//
// Keyed on barcode: for BHL items that is also the <bookId> in the scandata and
// the Internet Archive identifier, so the canvas URIs come out identical to the
// ones scan2rdf.php produces.
//
// Usage:
//   php iiif/canvas2rdf.php [barcode] > item.nt

error_reporting(E_ALL);

require_once(dirname(dirname(__FILE__)) . '/shared.php');

$config['canvas_db'] = dirname(dirname(__FILE__)) . '/canvas.sqlite';

// Small WEBP derivatives are 150 pixels wide, large ones 930.
$config['thumbnail_width'] = 150;

//----------------------------------------------------------------------------------------
// Canvases for an item, in page order
function get_canvases($barcode)
{
	global $config;
	global $canvas_pdo;

	if (!isset($canvas_pdo))
	{
		if (!file_exists($config['canvas_db']))
		{
			die("Can't find " . $config['canvas_db'] . ", see README\n");
		}

		$canvas_pdo = new PDO('sqlite:' . $config['canvas_db']);
		$canvas_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	$stmt = $canvas_pdo->prepare('SELECT seq, leaf, width, height FROM canvas
		WHERE barcode = ? ORDER BY seq');

	$stmt->execute(array($barcode));

	$canvases = array();

	while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
	{
		$canvas = new stdclass;

		$canvas->id = $barcode . '/canvas/p' . str_pad($row['seq'], 4, '0', STR_PAD_LEFT);
		$canvas->position = (Integer)$row['seq'];
		$canvas->leaf = (Integer)$row['leaf'];

		// NULL means the scan data does not know, which is not the same as zero.
		// Leave them null and let the caller decide; a "0" triple would assert a
		// canvas size that is wrong rather than one that is missing.
		$canvas->width = is_null($row['width']) ? null : (Integer)$row['width'];
		$canvas->height = is_null($row['height']) ? null : (Integer)$row['height'];

		// thumbnail
		$canvas->thumbnail = new stdclass;
		$canvas->thumbnail->url = $config['aws'] . "/web/$barcode/$barcode"
			. "_" . str_pad($row['seq'], 4, '0', STR_PAD_LEFT) . "_thumb.webp";

		if ($canvas->width && $canvas->height)
		{
			$canvas->thumbnail->width = $config['thumbnail_width'];
			$canvas->thumbnail->height = floor($config['thumbnail_width'] * $canvas->height / $canvas->width);
		}

		$canvases[] = $canvas;
	}

	return $canvases;
}

//----------------------------------------------------------------------------------------
// exif dimensions for each canvas and its thumbnail
function canvas_triples($barcode)
{
	global $config;

	$triples = array();

	$skipped = 0;

	foreach (get_canvases($barcode) as $canvas)
	{
		if (!$canvas->width || !$canvas->height)
		{
			// A handful of leaves across BHL have no dimensions at all. Better a
			// canvas with no size than a canvas the size of nothing.
			$skipped++;
			continue;
		}

		$s = $config['ia'] . "/" . $canvas->id;

		$p = 'http://www.w3.org/2003/12/exif/ns#width';
		$o = '"' . $canvas->width . '"^^<http://www.w3.org/2001/XMLSchema#integer>';
		$triples[] = [$s, $p, $o];

		$p = 'http://www.w3.org/2003/12/exif/ns#height';
		$o = '"' . $canvas->height . '"^^<http://www.w3.org/2001/XMLSchema#integer>';
		$triples[] = [$s, $p, $o];

		// thumbnail
		$s = $canvas->thumbnail->url;

		$p = 'http://www.w3.org/2003/12/exif/ns#width';
		$o = '"' . $canvas->thumbnail->width . '"^^<http://www.w3.org/2001/XMLSchema#integer>';
		$triples[] = [$s, $p, $o];

		$s = $canvas->thumbnail->url;

		$p = 'http://www.w3.org/2003/12/exif/ns#height';
		$o = '"' . $canvas->thumbnail->height . '"^^<http://www.w3.org/2001/XMLSchema#integer>';
		$triples[] = [$s, $p, $o];
	}

	if ($skipped > 0)
	{
		// stderr, so that stdout stays valid N-Triples when redirected
		fwrite(STDERR, "$barcode: $skipped canvases have no dimensions, skipped\n");
	}

	return $triples;
}

if (1)
{
	$barcode = 'journalofarach3832010amer';

	if (isset($argv[1]))
	{
		$barcode = $argv[1];
	}

	$triples = canvas_triples($barcode);

	if (count($triples) == 0)
	{
		fwrite(STDERR, "No canvases for '$barcode' — is it a barcode rather than an ItemID?\n");
	}

	echo dump_triples($triples);
}

?>
