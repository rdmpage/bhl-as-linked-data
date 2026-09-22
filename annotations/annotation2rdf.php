<?php

// Web Annotations as RDF.
//
// One annotation per thing found in the OCR text of a BHL page. The shape is the W3C Web
// Annotation model, which is the same vocabulary the IIIF manifests already use for
// painting an image onto a canvas and for hanging the OCR text off it, so these drop into
// a manifest as a further AnnotationPage without a second vocabulary:
//
//   <anno> a oa:Annotation
//          oa:motivatedBy oa:tagging
//          oa:hasBody     <geo:-23.76551,30.00253>     the place
//          oa:hasTarget   <anno/target>                the words
//
//   <anno/target> a oa:SpecificResource
//                 oa:hasSource   <....txt>             the OCR text the offsets are in
//                 oa:hasScope    <bhl/page/57579616>   the page those words are printed on
//                 oa:hasSelector <anno/position>, <anno/quote>
//
// Note the two namespaces. sparql2iiif.php writes oa properties as
// https://www.w3.org/ns/oa# and the oa:Annotation class as http://www.w3.org/ns/oa#; only
// the http form is the real namespace, and those https properties appear solely in that
// script's CONSTRUCT template, where they are matched by its own JSON-LD context and never
// queried. Stored data uses http throughout -- a store cannot join the two.

error_reporting(E_ALL);

//----------------------------------------------------------------------------------------
// Escape a string for an N-Triples literal, keeping it character-for-character intact.
//
// Not nice_literal(), which is for bibliographic strings and is lossy in ways that matter
// here: it flattens every newline to a space and collapses runs of whitespace. A
// TextQuoteSelector is a locator -- a consumer finds the annotation again by searching the
// OCR text for prefix + exact + suffix -- and coordinates are split across a line break
// often enough ("23.98298° S, \n30.07696° E" on page 57579616) that rewriting the
// whitespace would break exactly the quotes that need it most. So escape the four
// characters N-Triples cannot carry raw and change nothing else.
function nt_literal($text)
{
	$text = str_replace('\\', '\\\\', $text);
	$text = str_replace('"', '\\"', $text);
	$text = str_replace("\n", '\\n', $text);
	$text = str_replace("\r", '\\r', $text);
	$text = str_replace("\t", '\\t', $text);

	// Any other C0 control character is still illegal raw in an N-Triples literal, and OCR
	// text does carry the odd stray one. \uXXXX them rather than drop them, so the offsets
	// in the TextPositionSelector keep pointing where they point.
	$text = preg_replace_callback('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
		function ($m) { return sprintf('\\u%04X', ord($m[0])); },
		$text);

	return $text;
}

//----------------------------------------------------------------------------------------
// Format a decimal coordinate for a geo: URI and for WKT.
//
// Six decimal places is roughly 0.1 m, well past anything a printed coordinate in a BHL
// page actually knows, and fixing the precision matters for more than tidiness: a geo: URI
// is an identifier, so two readings of the same point have to produce the same string or
// the store sees two places. Trailing zeros go for the same reason -- RFC 5870 compares
// coordinates as numbers, but a triple store compares IRIs as strings.
function format_coordinate($value)
{
	$text = number_format((float)$value, 6, '.', '');

	if (strpos($text, '.') !== false)
	{
		$text = rtrim($text, '0');
		$text = rtrim($text, '.');
	}

	// -0 and 0 are the same meridian or equator
	if ($text == '-0')
	{
		$text = '0';
	}

	return $text;
}

//----------------------------------------------------------------------------------------
// Triples for the body of a geotag: a geo: URI (RFC 5870) and its geometry.
//
// The URI is the body because the place is what the annotation says the text means, and a
// geo: URI says that in the identifier itself -- no minted URI, no lookup, and two pages
// that report the same coordinates converge on the same node without any reconciliation
// step. Coordinate order is latitude,longitude, per RFC 5870.
//
// The one triple hung off it is geosparql:asWKT, because a geo: URI is opaque to a triple
// store: nothing can filter on it, and GeoSPARQL's geof: functions and Oxigraph's spatial
// support both work from a wktLiteral. WKT is x y, so longitude comes first -- the opposite
// of the URI, and of the IIIF/GeoJSON habit of reading coordinates as a pair. A bare
// wktLiteral is CRS84 by definition, which is what WGS 84 degrees are, so there is no CRS
// prefix to add.
function geo_body_triples(&$triples, $geojson)
{
	$longitude = format_coordinate($geojson->geometry->coordinates[0]);
	$latitude  = format_coordinate($geojson->geometry->coordinates[1]);

	$body = 'geo:' . $latitude . ',' . $longitude;

	$s = $body;
	$p = 'http://www.opengis.net/ont/geosparql#asWKT';
	$o = '"POINT(' . $longitude . ' ' . $latitude . ')"^^<http://www.opengis.net/ont/geosparql#wktLiteral>';
	$triples[] = [$s, $p, $o];

	return $body;
}

//----------------------------------------------------------------------------------------
// Triples for one selector, either kind. Returns the selector's URI.
function selector_triples(&$triples, $selector, $selector_uri)
{
	$s = $selector_uri;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'http://www.w3.org/ns/oa#' . $selector->type;
	$triples[] = [$s, $p, $o];

	switch ($selector->type)
	{
		case 'TextPositionSelector':
			$s = $selector_uri;
			$p = 'http://www.w3.org/ns/oa#start';
			$o = '"' . $selector->start . '"^^<http://www.w3.org/2001/XMLSchema#nonNegativeInteger>';
			$triples[] = [$s, $p, $o];

			$s = $selector_uri;
			$p = 'http://www.w3.org/ns/oa#end';
			$o = '"' . $selector->end . '"^^<http://www.w3.org/2001/XMLSchema#nonNegativeInteger>';
			$triples[] = [$s, $p, $o];
			break;

		case 'TextQuoteSelector':
			foreach (array('exact', 'prefix', 'suffix') as $property)
			{
				// prefix and suffix are empty at the very start and end of a page's text
				if (!isset($selector->$property) || $selector->$property === '')
				{
					continue;
				}

				$s = $selector_uri;
				$p = 'http://www.w3.org/ns/oa#' . $property;
				$o = '"' . nt_literal($selector->$property) . '"';
				$triples[] = [$s, $p, $o];
			}
			break;

		default:
			break;
	}

	return $selector_uri;
}

//----------------------------------------------------------------------------------------
// The two selectors together are what makes the annotation survive its text being
// re-OCRed: the position is exact but brittle, the quote is fuzzy but relocatable, and the
// model expects a target to carry both so a consumer can fall back from one to the other.
//
// Both of them select within the OCR text FILE, not within the page, which is why
// oa:hasSource is the .txt URL: an offset of 136 means nothing until you say 136 characters
// into what, and the day BHL publishes a second transcription of the same page the offsets
// will differ between the two. oa:hasScope then carries the page -- that property exists
// for exactly this, a source that is only meaningful inside some larger context. It is
// recoverable anyway, since the page already has schema:encoding to this same text file,
// but asserting it turns "which pages mention a coordinate" from a reverse join into one
// pattern.
function target_triples(&$triples, $annotation, $page, $annotation_uri)
{
	$target_uri = $annotation_uri . '/target';

	$s = $annotation_uri;
	$p = 'http://www.w3.org/ns/oa#hasTarget';
	$o = $target_uri;
	$triples[] = [$s, $p, $o];

	$s = $target_uri;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'http://www.w3.org/ns/oa#SpecificResource';
	$triples[] = [$s, $p, $o];

	// the text the offsets count into
	$s = $target_uri;
	$p = 'http://www.w3.org/ns/oa#hasSource';
	$o = $page->text_url;
	$triples[] = [$s, $p, $o];

	// the BHL page that text was read off
	$s = $target_uri;
	$p = 'http://www.w3.org/ns/oa#hasScope';
	$o = $page->id;
	$triples[] = [$s, $p, $o];

	// one selector of each kind per target, so the kind can name it
	$selector_names = array(
		'TextPositionSelector' => 'position',
		'TextQuoteSelector'    => 'quote',
	);

	foreach ($annotation->target->selector as $selector)
	{
		if (!isset($selector_names[$selector->type]))
		{
			continue;
		}

		$selector_uri = $annotation_uri . '/' . $selector_names[$selector->type];

		$s = $target_uri;
		$p = 'http://www.w3.org/ns/oa#hasSelector';
		$o = selector_triples($triples, $selector, $selector_uri);
		$triples[] = [$s, $p, $o];
	}

	return $target_uri;
}

//----------------------------------------------------------------------------------------
// The position of the match within the page's text, as {start}-{end}.
//
// An annotation needs a URI that is the same next time the tagger runs, and the obvious
// candidate -- a counter over the matches on the page -- is not: adding one regex to
// geotag.php renumbers every annotation after the first new match, so a store loaded twice
// ends up holding both numberings. The character offsets are a property of the text rather
// than of the run that found it, so they hold still.
//
// Both ends, not just the start: two patterns in geotag.php can match at the same offset
// and stop in different places ("29.6° N" inside a longer degrees-minutes match), and those
// are genuinely different annotations. Where they match the same span they are the same
// annotation, and colliding on one URI is the right answer.
function annotation_uri($page, $annotation, $kind = 'geo')
{
	$position = null;

	foreach ($annotation->target->selector as $selector)
	{
		if ($selector->type == 'TextPositionSelector')
		{
			$position = $selector;
		}
	}

	if (!$position)
	{
		return null;
	}

	return $page->id . '/annotation/' . $kind . '/' . $position->start . '-' . $position->end;
}

//----------------------------------------------------------------------------------------
// One geotag as triples, appended to $triples. $page has ->id (the BHL page URI) and
// ->text_url (the OCR text on S3). Returns the annotation's URI, or null if it had no
// position selector to be named after.
function annotation_to_triples(&$triples, $annotation, $page)
{
	$annotation_uri = annotation_uri($page, $annotation);

	if (!$annotation_uri)
	{
		return null;
	}

	$s = $annotation_uri;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'http://www.w3.org/ns/oa#Annotation';
	$triples[] = [$s, $p, $o];

	// tagging, not identifying: the body says what place the words denote, and does not
	// claim to be naming the page or the passage as a whole
	$s = $annotation_uri;
	$p = 'http://www.w3.org/ns/oa#motivatedBy';
	$o = 'http://www.w3.org/ns/oa#tagging';
	$triples[] = [$s, $p, $o];

	$s = $annotation_uri;
	$p = 'http://www.w3.org/ns/oa#hasBody';
	$o = geo_body_triples($triples, $annotation->geojson);
	$triples[] = [$s, $p, $o];

	target_triples($triples, $annotation, $page, $annotation_uri);

	return $annotation_uri;
}

?>
