<?php


require_once(dirname(__FILE__) . '/vendor/autoload.php');
require_once(dirname(__FILE__) . '/lib.php');

use ML\JsonLD\JsonLD;
use ML\JsonLD\NQuads;

//----------------------------------------------------------------------------------------
// A JSON-LD term that always compacts to an array.
//
// Framing collapses a single-valued property to a bare object, but IIIF v3 requires
// these properties to be arrays, and viewers index them directly — TIFY does
// canvas.thumbnail[0] and canvas.annotations[0] — so an object makes them silently
// fall back or show nothing. Pinning @container to @set keeps the array.
function set_term($iri)
{
	$term = new stdclass;
	$term->{'@id'} = $iri;
	$term->{'@container'} = "@set";
	return $term;
}

//----------------------------------------------------------------------------------------
// Reshape a framed value into an IIIF v3 language map, e.g. {"none": ["Page 1"]}.
//
// ml/json-ld is a JSON-LD 1.0 processor and cannot produce this shape itself: a
// @container of ["@language", "@set"] is rejected outright, a plain "@language" container
// gives string values rather than the arrays IIIF requires, and there is no @none to put
// untagged literals under. So labels are emitted as ordinary literals and converted here.
function language_map($value)
{
	$map = array();

	foreach ((is_array($value) ? $value : array($value)) as $item)
	{
		if (is_object($item) && isset($item->{'@value'}))
		{
			// a language-tagged literal left expanded by framing
			$language = isset($item->{'@language'}) ? $item->{'@language'} : 'none';
			$map[$language][] = $item->{'@value'};
		}
		else if (is_object($item))
		{
			// already a language map, e.g. from an @language container
			foreach ($item as $language => $strings)
			{
				foreach ((array)$strings as $string)
				{
					$map[$language][] = $string;
				}
			}
		}
		else
		{
			// a plain literal; IIIF files untagged strings under "none"
			$map['none'][] = (string)$item;
		}
	}

	return (object)$map;
}

//----------------------------------------------------------------------------------------
// Convert every label (and metadata value) in the manifest into a language map
function fix_language_maps($node)
{
	if (is_array($node))
	{
		foreach ($node as $item)
		{
			fix_language_maps($item);
		}
		return;
	}

	if (!is_object($node))
	{
		return;
	}

	foreach ($node as $key => $value)
	{
		if ($key == 'label' || $key == 'value')
		{
			$node->$key = language_map($value);
		}
		else
		{
			fix_language_maps($value);
		}
	}
}

//----------------------------------------------------------------------------------------
// Fetch the parts of an item as IIIF ranges, i.e. the table of contents.
//
// A SELECT of its own rather than more patterns in the manifest CONSTRUCT. Joined into that
// query the parts multiply against the pages: on item 223011 that is 220 pages x 211 part
// pages = 52,117 solutions to produce 6,447 distinct triples, and the waste grows with the
// product of the two, not their sum. Asked separately the cost is just the number of part
// pages.
//
// Building the ranges here rather than through framing also keeps the page canvases out of
// the framed document. Framing embeds a node under every property that points at it, so a
// range reached that way carries a complete second copy of each of its pages -- painting
// annotation, thumbnail, OCR and all -- which doubled the manifest on item 223011 once every
// page of every part was included.
function construct_ranges($item, $manifest_id)
{
	$sparql = '
	SELECT ?part_position ?part_name ?part_canvas
	WHERE {
	  VALUES ?item { <ITEM> }

	  ?part <https://schema.org/isPartOf> ?item .
	  ?part <https://schema.org/position> ?part_position .
	  ?part <https://schema.org/name> ?part_name .

	  ?part <https://schema.org/dataFeedElement> ?part_dataFeedElement .
	  ?part_dataFeedElement <https://schema.org/position> ?page_position .
	  ?part_dataFeedElement <https://schema.org/item> ?part_page .
	  ?part_page <https://schema.org/sameAs> ?part_canvas .
	}
	ORDER BY ?part_position ?page_position
	';

	$sparql = str_replace('<ITEM>', '<' . $item . '>', $sparql);

	$structures = array();

	// ORDER BY is honoured here, unlike in the CONSTRUCT that builds the rest of the manifest
	// -- a SELECT returns a sequence, so the parts and the pages within each part arrive in
	// position order and the array can just be built up as the rows come in. That is why this
	// needs none of the sorting the canvases need.
	foreach (query($sparql) as $row)
	{
		$position = $row->part_position->value;

		if (!isset($structures[$position]))
		{
			$range = new stdclass;

			$range->id   = $manifest_id . '/range/' . $position;
			$range->type = 'Range';

			// a plain string, converted to a language map by fix_language_maps() along with
			// every other label in the manifest
			$range->label = $row->part_name->value;
			$range->items = array();

			$structures[$position] = $range;
		}

		// a range references its canvases, it does not carry them
		$canvas = new stdclass;

		$canvas->id   = $row->part_canvas->value;
		$canvas->type = 'Canvas';

		$structures[$position]->items[] = $canvas;
	}

	// drop the part positions, which were only ever keys for the grouping above
	return array_values($structures);
}

//----------------------------------------------------------------------------------------
// Generate a IIIF manifest from a SPARQL query
function construct_iiif($item = 'https://www.biodiversitylibrary.org/item/223011')
{
	global $config;
	
	$sparql = '
	CONSTRUCT
	{
	  # manifest 
	  ?manifest <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://iiif.io/api/presentation/3#Manifest> .

	  ?manifest <http://www.w3.org/2000/01/rdf-schema#label> ?title .

	  # behavior: omitted for now, so each viewer uses its own default (both TIFY and Mirador
	  # open a single page). Uncomment pagedHint for facing-page spreads, or swap it for
	  # individualsHint to enforce single pages and hide the TIFY double-page toggle. The
	  # "behavior" context term already has @vocab + @set, so either compacts to a bare string.
	  # ?manifest <http://iiif.io/api/presentation/3#behavior> <http://iiif.io/api/presentation/3#pagedHint> .
	  # ?manifest <http://iiif.io/api/presentation/3#behavior> <http://iiif.io/api/presentation/3#individualsHint> .

	  # canvases
	  ?manifest <http://www.w3.org/ns/activitystreams#items> ?canvas .

	  # canvas
	  ?canvas <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://iiif.io/api/presentation/3#Canvas> .
	  
	  # dimensions
	  ?canvas <http://www.w3.org/2003/12/exif/ns#width> ?width .
	  ?canvas <http://www.w3.org/2003/12/exif/ns#height> ?height .

	  # label
	  ?canvas <http://www.w3.org/2000/01/rdf-schema#label> ?label .
	  	  
	  # annotation page
	  ?canvas <http://www.w3.org/ns/activitystreams#items> ?ap .
	  ?ap <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/activitystreams#OrderedCollectionPage> .

	  # items
	  ?ap <http://www.w3.org/ns/activitystreams#items> ?a .

	  # item
	  ?a <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/oa#Annotation>  .

	  # paint image on page
	  ?a <https://www.w3.org/ns/oa#motivatedBy> <http://iiif.io/api/presentation/3#painting> .
	  ?a <https://www.w3.org/ns/oa#hasBody> ?image .
	  ?image <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/dc/dcmitype/StillImage> .
	  ?image <http://purl.org/dc/elements/1.1/format> "image/webp" .	  
	  ?a <https://www.w3.org/ns/oa#hasTarget> ?canvas .
	  
	  # thumbnail
	  ?canvas <http://iiif.io/api/presentation/3#thumbnail> ?thumbnail .
	  ?thumbnail <http://www.w3.org/2003/12/exif/ns#width> ?thumbnail_width .
	  ?thumbnail <http://www.w3.org/2003/12/exif/ns#height> ?thumbnail_height .
	  ?thumbnail <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/dc/dcmitype/StillImage> .
	  ?thumbnail <http://purl.org/dc/elements/1.1/format> "image/webp" .	
	  
	  # OCR text
	  #
	  # A second AnnotationPage, hung off the canvas with "annotations" rather than "items"
	  # (items is for the painting annotation that draws the image). The body is the URL of
	  # the text file on S3 — viewers fetch it lazily, so the OCR never enters the manifest.
	  ?canvas <http://iiif.io/api/presentation/3#annotations> ?ocr_ap .
	  ?ocr_ap <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/activitystreams#OrderedCollectionPage> .
	  ?ocr_ap <http://www.w3.org/ns/activitystreams#items> ?ocr_anno .
	  ?ocr_anno <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/oa#Annotation> .
	  ?ocr_anno <https://www.w3.org/ns/oa#motivatedBy> <http://iiif.io/api/presentation/3#supplementing> .
	  ?ocr_anno <https://www.w3.org/ns/oa#hasBody> ?ocr .
	  ?ocr <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/dc/dcmitype/Text> .
	  ?ocr <http://purl.org/dc/elements/1.1/format> "text/plain" .
	  ?ocr_anno <https://www.w3.org/ns/oa#hasTarget> ?canvas .
	  
	  # ranges (the table of contents) are fetched separately, see construct_ranges()

	}
	WHERE {
	  VALUES ?item { <ITEM> }
	  
	  ?item <https://schema.org/encoding> ?manifest .
	  ?manifest <https://schema.org/encodingFormat> "application/ld+json" .
	  
	  ?item <https://schema.org/name> ?title .
	  	  
	  ?page <https://schema.org/isPartOf> ?item .
	  ?page <https://schema.org/sameAs> ?canvas .
	  	
	  ?canvas <http://www.w3.org/1999/02/22-rdf-syntax-ns#type>  <http://iiif.io/api/presentation/3#Canvas> .
	  ?canvas <http://www.w3.org/2003/12/exif/ns#width> ?width .
	  ?canvas <http://www.w3.org/2003/12/exif/ns#height> ?height .

	  OPTIONAL { ?page <https://schema.org/name> ?label . }
	  
	  ?page <https://schema.org/image> ?image .
	  
	  ?page <https://schema.org/thumbnailUrl> ?thumbnail .
	  ?thumbnail <http://www.w3.org/2003/12/exif/ns#width> ?thumbnail_width .
	  ?thumbnail <http://www.w3.org/2003/12/exif/ns#height> ?thumbnail_height .	  
	  
	  
	  BIND(IRI(CONCAT(STR(?canvas), "/ap1")) AS ?ap)
	  BIND(IRI(CONCAT(STR(?canvas), "/ap1/a1")) AS ?a)

	  # OCR, if this page has any. The BINDs sit inside the OPTIONAL on purpose: if there is
	  # no OCR then ?ocr_ap stays unbound and the canvas gets no "annotations" property at
	  # all. An AnnotationPage with an id but no items would make TIFY treat it as an
	  # external page and fire a doomed fetch at it.
	  OPTIONAL {
	    # ?canvas has to be re-bound in here: an OPTIONAL group is evaluated on its own before
	    # the left join, so ?canvas from the outer pattern is not visible to the BINDs below and
	    # CONCAT would silently error, leaving ?ocr_ap unbound.
	    ?page <https://schema.org/sameAs> ?canvas .
	    ?page <https://schema.org/encoding> ?ocr .
	    ?ocr <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <https://schema.org/MediaObject> .
	    ?ocr <https://schema.org/encodingFormat> "text/plain" .
	    BIND(IRI(CONCAT(STR(?canvas), "/ocr")) AS ?ocr_ap)
	    BIND(IRI(CONCAT(STR(?canvas), "/ocr/a1")) AS ?ocr_anno)
	  }
	  
	}
	';
	
	$sparql = str_replace('<ITEM>', '<' . $item . '>', $sparql);
	
	$triples = construct($sparql);
	
	
	// JSON-LD context to create manifest
	$context = new stdclass;
	
	$context->Manifest = "http://iiif.io/api/presentation/3#Manifest";
	$context->Canvas = "http://iiif.io/api/presentation/3#Canvas";
	
	// thumbnail is array
	$context->thumbnail = set_term("http://iiif.io/api/presentation/3#thumbnail");

	// dimensions (this only works if we set 'useNativeTypes' = true, see below)
	$context->width = "http://www.w3.org/2003/12/exif/ns#width";	
	$context->height = "http://www.w3.org/2003/12/exif/ns#height";	
	
	$context->AnnotationPage = "http://www.w3.org/ns/activitystreams#OrderedCollectionPage";
	
	// items is array
	$context->items = set_term("http://www.w3.org/ns/activitystreams#items");

	// The remaining IIIF v3 properties that are arrays even when they hold one value.
	// IRIs follow the official Presentation 3 context (vocabularies/iiif-presentation3.json),
	// which uses @list for annotations/structures/metadata; @set is used here to match how
	// items is handled, i.e. plain repeated triples ordered by the query rather than an
	// rdf:List.
	$context->annotations = set_term("http://iiif.io/api/presentation/3#annotations");
	$context->structures  = set_term("http://iiif.io/api/presentation/3#structures");
	$context->metadata    = set_term("http://iiif.io/api/presentation/3#metadataEntries");
	$context->seeAlso     = set_term("http://www.w3.org/2000/01/rdf-schema#seeAlso");
	$context->rendering   = set_term("http://purl.org/dc/terms/hasFormat");
	$context->partOf      = set_term("http://purl.org/dc/terms/isPartOf");
	$context->service     = set_term("https://schema.org/potentialAction");
	$context->provider    = set_term("https://schema.org/provider");
	$context->homepage    = set_term("http://xmlns.com/foaf/0.1/homepage");
	$context->logo        = set_term("http://xmlns.com/foaf/0.1/logo");
	$context->language    = set_term("http://purl.org/dc/elements/1.1/language");

	// label is left as a plain literal here and turned into a language map after framing,
	// see language_map() — a JSON-LD 1.0 processor cannot emit the IIIF shape directly.
	$context->label = "http://www.w3.org/2000/01/rdf-schema#label";

	// annotation
	$context->oa = "https://www.w3.org/ns/oa#";
	$context->Annotation = "http://www.w3.org/ns/oa#Annotation";
	$context->body = "oa:hasBody";
	$context->motivation = "oa:motivatedBy";
	
	// need to use @vocab to ensure we get "painting" as a string rather than a URI
	$context->motivation = (object) array(
		"@id"   => "oa:motivatedBy",
		"@type" => "@vocab"
	);	
	$context->painting = "http://iiif.io/api/presentation/3#painting";
	$context->supplementing = "http://iiif.io/api/presentation/3#supplementing";
	
	// behavior needs @vocab AND @set in the *same* term definition: @vocab so pagedHint
	// compacts to "paged" rather than a node object, @set so a single value still comes out
	// as an array. Two terms sharing one IRI does not work — compaction picks exactly one of
	// them for the property and the other's coercion is silently ignored.
	//
	// Note the US spelling: IIIF (and TIFY, which reads manifest.behavior) has no "behaviour".
	$context->behavior = (object) array(
		"@id"        => "http://iiif.io/api/presentation/3#behavior",
		"@type"      => "@vocab",
		"@container" => "@set"
	);
	$context->paged = "http://iiif.io/api/presentation/3#pagedHint";
	$context->{'non-paged'} = "http://iiif.io/api/presentation/3#nonPagedHint";

	$target = new stdclass;
	$target->{"@type"} = "@id";
	$target->{"@id"} = "oa:hasTarget";	
	$context->target = $target;
	
	// image	
	// dc:format from the 1.1 element set, not dcterms:format. The official IIIF context maps
	// "format" to http://purl.org/dc/elements/1.1/format, and since the manifest declares that
	// context (see below) anyone expanding it reads the property as that IRI. Emitting
	// dcterms:format here would make the manifest say one thing and mean another; the JSON is
	// identical either way, so this only shows up on a round trip back to RDF.
	$context->format = "http://purl.org/dc/elements/1.1/format";
	$context->Image = "http://purl.org/dc/dcmitype/StillImage";
	$context->Text = "http://purl.org/dc/dcmitype/Text";
	
	// make @id and @type JSON-friendly
	$context->id = "@id";
	$context->type = "@type";
		
	// Frame document using manifest
	$frame = (object)array(
		'@context' => $context,
		'@type' => 'http://iiif.io/api/presentation/3#Manifest'
	);	
	
	// Use same libary as EasyRDF but access directly to output ordered list of authors
	$nquads = new NQuads();
	
	// And parse them again to a JSON-LD document
	$quads = $nquads->parse($triples);		
	
	// ensure that integer values are output as integers
	$options = array(
		'useNativeTypes' => true
	);
	
	$doc = JsonLD::fromRdf($quads, $options);
	
	$result  = JsonLD::frame($doc, $frame);
	
	// just grab manifest, ignore @context
	$manifest = $result->{'@graph'}[0];

	// Put the canvases in page order.
	//
	// An RDF graph is unordered, so the array order here is just the order the triples
	// happened to arrive in — the endpoint is under no obligation to preserve an ORDER BY
	// when serialising a CONSTRUCT. Canvas URIs end in a zero-padded page number
	// (.../canvas/p0001), so sorting them as strings gives page order, and we don't have to
	// carry schema:position through the CONSTRUCT to get it.
	usort($manifest->items, function ($a, $b) { return strcmp($a->id, $b->id); });

	// The table of contents, from a query of its own.
	//
	// Omitted entirely when the item has no parts: an empty "structures" array would have
	// viewers offer a table of contents and then show nothing in it.
	$structures = construct_ranges($item, $manifest->id);

	if (count($structures) > 0)
	{
		$manifest->structures = $structures;
	}

	fix_language_maps($manifest);

	// Declare the IIIF Presentation 3 context.
	//
	// Written straight into the output rather than handed to the processor: ml/json-ld 1.2.1
	// is a JSON-LD 1.0 implementation and the official context is @version 1.1 -- scoped
	// contexts, an ["@language", "@set"] container on label, @none -- so compacting against
	// that file throws outright. Nothing fetches this URL: it is only a string in the JSON,
	// and the $context above is what actually did the compaction.
	//
	// The two agree on the IRI of every term this manifest emits. They differ only in the
	// containers -- the official context declares items, structures and annotations as @list
	// where $context uses @set -- which changes nothing in the JSON, both being arrays, and
	// shows only if a consumer expands the manifest back to RDF. There @list also asserts the
	// page order that construct_ranges() and the usort above already put the arrays in, so the
	// official reading is the stronger of the two rather than a contradiction.
	//
	// array_merge, rather than assigning the property, so @context comes out first as IIIF
	// manifests conventionally have it.
	$manifest = (object)array_merge(
		array('@context' => 'http://iiif.io/api/presentation/3/context.json'),
		(array)$manifest
	);

	echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

construct_iiif('https://www.biodiversitylibrary.org/item/223011');

?>
