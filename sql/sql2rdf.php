<?php

// Extract data from SQLite and convert to RDF

error_reporting(E_ALL);

require_once(dirname(dirname(__FILE__)) . '/shared.php');
require_once (dirname(__FILE__) . '/sqlite.php');

// canvas_triples(), for canvas dimensions out of canvas.sqlite. Including it emits nothing;
// its own driver runs only when that file is the script being executed.
require_once (dirname(dirname(__FILE__)) . '/iiif/canvas2rdf.php');

//----------------------------------------------------------------------------------------
// take ISO date and convert to typed date
function create_date(&$triples, $date_string, $subject_uri, $predicate_uri = 'https://schema.org/datePublished')
{
	$datetype = 'date';
	
	$d = explode("-", $date_string);

	if ( count($d) > 0 ) $year = $d[0] ;
	if ( count($d) > 1 ) $month = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[1] ) ;
	if ( count($d) > 2 ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[2] ) ;
	if ( isset($month) and isset($day) )
	{
		$date     = "$year-$month-$day";
		$datetype = 'date';
	}
	else if ( isset($month) )
	{
		$date     = "$year-$month";
		$datetype = 'gYearMonth';				
	}
	else if ( isset($year) ) 
	{
		$date     = "$year";
		$datetype = 'gYear';	
	}
	
	$s = $subject_uri;
	$p = $predicate_uri;	
	$o = '"' . $date . '"^^<http://www.w3.org/2001/XMLSchema#' . $datetype . '>';	
	$triples[] = [$s, $p, $o];	
}

//----------------------------------------------------------------------------------------
function create_encoding_triples(&$triples, $work, $encoding, $mime_type)
{
	$s = $work;
	$p = 'https://schema.org/encoding';
	$o = $encoding;		
	$triples[] = [$s, $p, $o];	
	
	$s = $encoding;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/MediaObject';		
	$triples[] = [$s, $p, $o];	

	$s = $encoding;
	$p = 'https://schema.org/contentUrl';	
	$o = $encoding;		
	$triples[] = [$s, $p, $o];	

	$s = $encoding;
	$p = 'https://schema.org/encodingFormat';	
	$o = '"' . nice_literal($mime_type) . '"';		
	$triples[] = [$s, $p, $o];	
}

//----------------------------------------------------------------------------------------
// Get list of items for a title
function get_items_for_title($TitleID)
{
	// list of items for a title
	$sql = 'SELECT DISTINCT ItemID FROM item 
	WHERE TitleID='. $TitleID . '
	ORDER BY item.year, item.VolumeInfo';
		
	$data = db_get($sql);
	
	//print_r($data);
	
	$items = array();
	
	$position = 1;
	
	foreach ($data as $row)
	{
		$items[$row->ItemID] = $position++;
	}
	
	return $items;
}

//----------------------------------------------------------------------------------------
// Get details of a title
function get_title($TitleID)
{
	global $config;
	
	// title and identifiers
	$sql = 'SELECT * FROM title 
	LEFT OUTER JOIN titleidentifier USING(TitleID)
	WHERE TitleID='. $TitleID;
	
	$data = db_get($sql);
	
	$title = new stdclass;
	$title->items = [];
	$title->identifier = [];
	
	foreach ($data as $row)
	{
		$title->id = $config['bhl'] . '/bibliography/' . $row->TitleID;
		$title->name = $row->FullTitle;
		
		if (isset($row->ShortTitle) && strcmp($row->ShortTitle, $row->FullTitle) !== 0)
		{
			$title->alternateName = $row->ShortTitle;
		}
			
		if (isset($row->IdentifierName))
		{			
			if (!isset($title->identifier[$row->IdentifierName]))
			{
				$title->identifier[$row->IdentifierName] = [];
			}
			
			$title->identifier[$row->IdentifierName][] = $row->IdentifierValue;
		}
	}
	
	// DOI?
	$sql = 'SELECT * FROM doi WHERE EntityID='. $TitleID . ' AND EntityType="Title"';

	$data = db_get($sql);
	
	foreach ($data as $row)
	{
		if (!isset($title->doi))
		{
			$title->doi = [];
		}
		$title->doi[] = $row->DOI;
	}
	
	// Creators?
	$sql = 'SELECT CreatorID, CreatorType FROM creator WHERE TitleID=' . $TitleID;	

	$data = db_get($sql);
	
	foreach ($data as $row)
	{
		if (preg_match('/^Main/', $row->CreatorType))
		{
			$title->creator[] = $row->CreatorID;
		}
		else
		{
			$title->contributor[] = $row->CreatorID;
		}
	}
	
	
	// list of items
	//$title->items = get_items_for_title($TitleID);	
	
	// stderr, not stdout: the triples go to stdout, so print_r() there lands in the middle
	// of the .nt file and the whole thing stops parsing. Same reason shared.php sends
	// warnings to stderr.
	fwrite(STDERR, print_r($title, true));
	
	$triples = [];
	
	// creative work
	$s = $title->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/CreativeWork';		
	$triples[] = [$s, $p, $o];	
	
	// DataFeed
	$s = $title->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/DataFeed';		
	$triples[] = [$s, $p, $o];	

	// name
	$s = $title->id;
	$p = 'https://schema.org/name';
	$o = '"' . nice_literal($title->name) . '"';		
	$triples[] = [$s, $p, $o];	
	
	if (isset($title->alternateName))
	{
		$s = $title->id;
		$p = 'https://schema.org/alternateName';
		$o = '"' . nice_literal($title->alternateName) . '"';		
		$triples[] = [$s, $p, $o];		
	}
	
	// identifiers
	foreach ($title->identifier as $key => $values)
	{
		switch ($key)
		{
			case 'DLC':
				foreach ($values as $value)
				{
					$s = $title->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('https://lccn.loc.gov/' . $value);
					$triples[] = [$s, $p, $o];		
				}
				break;
		
			case 'ISSN':
				foreach ($values as $value)
				{
					//schema.org property
					$s = $title->id;
					$p = 'https://schema.org/issn';
					$o = '"' . nice_literal($value) . '"';		
					$triples[] = [$s, $p, $o];	
					
					// URI
					$s = $title->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('https://portal.issn.org/resource/ISSN/' . $value);
					$triples[] = [$s, $p, $o];							
				}
				break;
				
			case 'OCLC':
				foreach ($values as $value)
				{
					$s = $title->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('https://www.worldcat.org/oclc/' . $value);
					$triples[] = [$s, $p, $o];		
				}
				break;

			case 'Wikidata':
				foreach ($values as $value)
				{
					$s = $title->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('http://www.wikidata.org/entity/' . $value);
					$triples[] = [$s, $p, $o];		
				}
				break;
				
			default:
				break;
		}
	}
	
	
	// DOI
	if (isset($title->doi))
	{
		foreach ($title->doi as $doi)
		{
			$s = $title->id;
			$p = 'https://schema.org/sameAs';
			$o = nice_uri('https://doi.org/' . strtolower($doi));		
			$triples[] = [$s, $p, $o];		
		}
	}
	
	
	foreach ($title->items as $ItemID => $position)
	{
		$list_item_id = $title->id . '/item/' . str_pad($position, 4, '0', STR_PAD_LEFT);
		
		$item = $config['bhl'] . '/item/' . $ItemID;
	
		// DataFeedItem
		$s = $title->id;
		$p = 'https://schema.org/dataFeedElement';
		$o = $list_item_id;
		$triples[] = [$s, $p, $o];
		
		// position
		$s = $list_item_id;
		$p = 'https://schema.org/position';
		$o = '"' . $position . '"^^<http://www.w3.org/2001/XMLSchema#integer>';		
		$triples[] = [$s, $p, $o];		
		
		// item
		$s = $list_item_id;
		$p = 'https://schema.org/item';
		$o = $item;		
		$triples[] = [$s, $p, $o];	
	}
	
	$output = dump_triples($triples);			
	echo $output . "\n";
	
	return $title;
}
//----------------------------------------------------------------------------------------
// Get item
function get_item ($ItemID )
{
	global $config;
	
	// get individual item
	$sql = 'SELECT DISTINCT ItemID, VolumeInfo, TitleID, ThumbnailPageID, Year, InstitutionName, CopyrightStatus, BarCode
	FROM item';
	$sql .= ' WHERE ItemID='. $ItemID;
	
	$data = db_get($sql);
	
	$item = null;
	
	foreach ($data as $row)
	{	
		if (!$item)
		{
			$item = new stdclass;
		
			$item->id = $config['bhl'] . '/item/' . $row->ItemID;
			
			if (isset($row->VolumeInfo))
			{		
				$item->name = $row->VolumeInfo;
				
				// to do: can we parse this more accurately than BHL?
			}
			else
			{
				$item->name = '[' . $row->BarCode . ']';
			}
				
			$item->isPartOf = $config['bhl'] . '/bibliography/' . $row->TitleID;
			
			if (isset($row->ThumbnailPageID))
			{
				$item->thumbnail = $row->ThumbnailPageID;
			}
			
			if (isset($row->Year))
			{
				$item->year = $row->Year;
			}
		
			if (isset($row->InstitutionName))
			{
				$item->provider = $row->InstitutionName;
			}
			
			if (isset($row->CopyrightStatus))
			{
				$item->copyrightNotice = $row->CopyrightStatus;
			}
		
			// to do, maybe change this?
			if (isset($row->BarCode))
			{
				$item->barcode = $row->BarCode;
			}
		}
		else
		{
			// link to any other items it is a part of
			//
			// 9,050 items are attached to more than one TitleID, which is legitimate -- a
			// volume can sit in both a series and a standalone bibliography. The URI has to
			// be built the same way as the first one above; appending the bare TitleID left
			// the array holding a URI and an integer.
			if (!is_array($item->isPartOf))
			{
				$item->isPartOf = [$item->isPartOf];
			}
			$item->isPartOf[] = $config['bhl'] . '/bibliography/' . $row->TitleID;		
		}
	}
	
	// triples		
	$triples = [];
	
	// creative work
	$s = $item->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/CreativeWork';		
	$triples[] = [$s, $p, $o];	
	
	// DataFeed
	$s = $item->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/DataFeed';		
	$triples[] = [$s, $p, $o];	

	// name
	$s = $item->id;
	$p = 'https://schema.org/name';
	$o = '"' . nice_literal($item->name) . '"';		
	$triples[] = [$s, $p, $o];	
	
	// Internet Archive
	$s = $item->id;
	$p = 'https://schema.org/sameAs';
	$o = 'https://archive.org/details/' . $item->barcode;		
	$triples[] = [$s, $p, $o];	
	
	// item is part of a title, or of more than one
	//
	// One triple per title. Handing dump_triples() the array itself wrote the literal text
	// "Array" into the output as the object, which is not valid N-Triples and lost the link
	// for every one of the 9,050 items that have more than one.
	$s = $item->id;
	$p = 'https://schema.org/isPartOf';

	foreach (array_unique((array)$item->isPartOf) as $o)
	{
		$triples[] = [$s, $p, $o];
	}
	
	if (isset($item->provider))
	{
		$s = $item->id;
		$p = 'https://schema.org/provider';
		$o = '"' . nice_literal($item->provider) . '"';		
		$triples[] = [$s, $p, $o];	
	}

	if (isset($item->copyrightNotice))
	{
		$s = $item->id;
		$p = 'https://schema.org/copyrightNotice';
		$o = '"' . nice_literal($item->copyrightNotice) . '"';		
		$triples[] = [$s, $p, $o];	
	}
	
	// content
	// IIIF	
	$canvas = 'https://archive.org/details/' . $item->barcode . "/manifest";	
	create_encoding_triples($triples, $item->id, $canvas, "application/ld+json");

	$pdf = $config['bhl'] . '/itempdf/' . $ItemID;
	create_encoding_triples($triples, $item->id, $pdf, "application/pdf");
			
	$output = dump_triples($triples);			
	echo $output . "\n";
}

//----------------------------------------------------------------------------------------
// Get pages for item
function get_item_pages($ItemID = null)
{
	global $config;
	
	// Item pages
	//
	// The join is against a deduplicated item rather than item itself. 9,050 items have two
	// rows there, one per title they belong to, and joining the table raw fetches every page
	// of those items twice. The output is unaffected -- pages are collected into $pages by
	// PageID below, so the duplicate rows collapse before any triple is emitted -- but there
	// is no reason to carry them through the query. Deduplicating the small side is 324,356
	// rows down to 315,211, and every ItemID has exactly one BarCode, so it cannot drop a
	// row.
	//
	// Not a DISTINCT on the result: that would sort all 68.7 million pages, and it would also
	// be wrong, since 4,773,027 pages legitimately appear several times carrying different
	// PageTypeName values, which is what the keywords below collect.
	$sql = 'SELECT PageID, ItemID, BarCode, SequenceOrder, PagePrefix, PageNumber, PageTypeName FROM page';
	$sql .= ' INNER JOIN (SELECT DISTINCT ItemID, BarCode FROM item) AS item USING(ItemID)';
	
	if ($ItemID)
	{
		$sql .= ' WHERE ItemID='. $ItemID;
	}
	
	$sql .= ' ORDER BY ItemID, CAST(SequenceOrder AS INTEGER)';
	
	$data = db_get($sql);
	
	//print_r($data);
	
	$page_counter = 1;
	
	$pages = array();
	
	foreach ($data as $row)
	{
		if (!isset($pages[$row->PageID]))
		{
			$pages[$row->PageID] = new stdclass;
			$pages[$row->PageID]->id = $config['bhl'] . '/page/' . $row->PageID;	
		}
		
		$pages[$row->PageID]->position = (Integer)$row->SequenceOrder;
		
		if (isset($row->PagePrefix))
		{
			$pages[$row->PageID]->name = $row->PagePrefix;
		}

		if (isset($row->PageNumber))
		{
			if (isset($pages[$row->PageID]->name))
			{
				$pages[$row->PageID]->name .= ' ';
				$pages[$row->PageID]->name .= $row->PageNumber;
			}
			else
			{
				$pages[$row->PageID]->name = $row->PageNumber;
			}			
		}
		
		// Canvas
		$pages[$row->PageID]->canvas = $config['ia'] . "/" . $row->BarCode . "/canvas/p" . str_pad($row->SequenceOrder, 4, '0', STR_PAD_LEFT);
		
		// OCR text
		$pages[$row->PageID]->text = $config['aws'] . "/ocr/item-" . str_pad($row->ItemID, 6, '0', STR_PAD_LEFT) 
			. '/item-' . str_pad($row->ItemID, 6, '0', STR_PAD_LEFT)
			. '-' . str_pad($row->PageID, 8, '0', STR_PAD_LEFT) . '-' . str_pad($row->SequenceOrder, 4, '0', STR_PAD_LEFT) . '.txt';

		// Image
		$pages[$row->PageID]->image = $config['aws'] . "/web/" . $row->BarCode . "/" . $row->BarCode . "_" . str_pad($row->SequenceOrder, 4, '0', STR_PAD_LEFT) . "_full.webp";
		
		// Thumbnail
		$pages[$row->PageID]->thumbnailUrl = $config['aws'] . "/web/" . $row->BarCode . "/" . $row->BarCode . "_" . str_pad($row->SequenceOrder, 4, '0', STR_PAD_LEFT) . "_thumb.webp";
						
		// need to be cleverer about this
		/*
		if (isset($row->PageTypeName))
		{
			if (!isset($pages[$row->PageID]->keywords))
			{
				$pages[$row->PageID]->keywords = array();
			}
			$pages[$row->PageID]->keywords[] = $row->PageTypeName;
		}
		*/
		
	}
	
	//print_r($pages);
	
	$triples = [];
	
	$item_id = $config['bhl'] . '/item/' . $ItemID;	

	foreach ($pages as $page)
	{		
		// page is part of a datafeed for the (BHL) item
		$list_item_id = $item_id . '/page/' . str_pad($page->position, 4, '0', STR_PAD_LEFT);
	
		// DataFeedItem
		$s = $item_id;
		$p = 'https://schema.org/dataFeedElement';
		$o = $list_item_id;
		$triples[] = [$s, $p, $o];
		
		// position
		$s = $list_item_id;
		$p = 'https://schema.org/position';
		$o = '"' . $page->position . '"^^<http://www.w3.org/2001/XMLSchema#integer>';		
		$triples[] = [$s, $p, $o];		
		
		// datafeed (schema)item
		$s = $list_item_id;
		$p = 'https://schema.org/item';
		$o = $page->id;	
		$triples[] = [$s, $p, $o];	
						
		// isPartOf an item
		$s = $page->id;
		$p = 'https://schema.org/isPartOf';
		$o = $item_id;
		$triples[] = [$s, $p, $o];	
	
		// page name
		if (isset($page->name))
		{
			$s = $page->id;
			$p = 'https://schema.org/name';
			$o = '"' . nice_literal($page->name) . '"';
			$triples[] = [$s, $p, $o];	
		}
						
		// a page is the same as an IIIF canvas
		$s = $page->id;
		$p = 'https://schema.org/sameAs';
		$o = $page->canvas;
		$triples[] = [$s, $p, $o];	
		
		$s = $page->canvas;
		$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
		$o = 'http://iiif.io/api/presentation/3#Canvas';		
		$triples[] = [$s, $p, $o];	
						
		// store media links
		$s = $page->id;
		$p = 'https://schema.org/thumbnailUrl';
		$o = $page->thumbnailUrl;		
		$triples[] = [$s, $p, $o];	

		$s = $page->id;
		$p = 'https://schema.org/image';
		$o = $page->image;		
		$triples[] = [$s, $p, $o];	
		
		/*
		// according the schema.org this should really be text, not a URL, but what can you do...?
		$s = $page->id;
		$p = 'https://schema.org/text';
		$o = $page->text;		
		$triples[] = [$s, $p, $o];
		*/
		
		// text as encoding, with link to text URL
		create_encoding_triples($triples, $page->id, $page->text, "text/plain");	
	}
	
	$output = dump_triples($triples);			
	echo $output . "\n";
}

//----------------------------------------------------------------------------------------
// Get list of parts for an item
function get_item_parts($ItemID)
{
	// list of items for a title
	$sql = 'SELECT PartID FROM part 
	WHERE ItemID='. $ItemID . '
	ORDER BY CAST(part.SequenceOrder AS INTEGER)';
	
	$data = db_get($sql);
	
	// print_r($data);
	
	$parts = array();
	
	foreach ($data as $row)
	{
		$part = get_part($row->PartID);
		if ($part)
		{
			$parts[] = $part;
		}
	}
	
	return $parts;
}


//----------------------------------------------------------------------------------------
// Get part
function get_part($PartID)
{
	global $config;
	
	// part and identifiers
	$sql = 'SELECT * FROM part 
	LEFT OUTER JOIN partidentifier USING(PartID)
	WHERE PartID='. $PartID;
	
	$data = db_get($sql);
	
	// print_r($data);
	
	$part = new stdclass;
	$part->creator = [];
	
	foreach ($data as $row)
	{
		$part->id = $config['bhl'] . '/part/' . $row->PartID;
		$part->name = $row->Title;
		$part->SegmentType = $row->SegmentType;
		$part->position = $row->SequenceOrder;
		
		$part->isPartOf = $row->ItemID;
		
		// StartPageID for thumbnail
		
		if (isset($row->Volume))
		{
			$part->volume = $row->Volume;
		}

		if (isset($row->Issue))
		{
			$part->issue = $row->Issue;
		}

		if (isset($row->PageRange))
		{
			$part->pagination = $row->PageRange;
			$part->pagination = str_replace('--', '-', $part->pagination);
		}
		
		// more precise pages
		
		// represent journal relationship (e.g., ISSN)
		
		
		if (isset($row->LicenseUrl))
		{
			$part->license = $row->LicenseUrl;
		}

		if (isset($row->Date))
		{
			$part->date = $row->Date;
		}
		
		// identifiers
		if (isset($row->IdentifierName))
		{	
		
			if (!isset($part->identifier))
			{
				$part->identifier = array();
			}
		
			if (!isset($part->identifier[$row->IdentifierName]))
			{
				$part->identifier[$row->IdentifierName] = [];
			}
			
			$part->identifier[$row->IdentifierName][] = $row->IdentifierValue;
		}
	}
	
	// DOI?
	$sql = 'SELECT * FROM doi WHERE EntityID='. $PartID . ' AND EntityType="Part"';

	$data = db_get($sql);
	
	foreach ($data as $row)
	{
		if (!isset($part->doi))
		{
			$part->doi = [];
		}
		$part->doi[] = strtolower($row->DOI);
	}
	
	// Creator
	$sql = 'SELECT CreatorID FROM partcreator WHERE PartID=' . $PartID;	

	$data = db_get($sql);
	
	foreach ($data as $row)
	{
		$part->creator[] = $row->CreatorID;
	}
	
	// part pages
	$sql = 'SELECT PageID, SequenceOrder FROM partpage WHERE PartID=' . $PartID;	
	$sql .= ' ORDER BY CAST(SequenceOrder AS INTEGER)';
	
	$data = db_get($sql);
	
	//print_r($data);
	
	$pages = [];
	
	foreach ($data as $row)
	{
		$pages[$row->SequenceOrder] = $row->PageID;
	}

	$triples = [];
	
	/*
375377,Article x
363,Book x
584,Chapter x
2,Conference
26532,Correspondence
17,Issue x
1342,List
55,Manuscript x
96,Notes
423,Review
1,Treatment
1,Unknown
*/
	// map part types on to schema.org
	switch ($part->SegmentType)
	{
		case 'Article':
			$part->type = 'ScholarlyArticle';
			break;
			
		case 'Book':
		case 'Chapter':
		case 'Manuscript':
		case 'Review':	
			$part->type = $part->SegmentType;
			break;
	
		case 'Issue':
			$part->type = 'PublicationIssue';
			break;
	
		case 'Conference':	
		case 'Correspondence':
		case 'List':	
		case 'Notes':	
		case 'Treatment':	
		case 'Unknown':	
		default:
			$part->type = 'CreativeWork';
			break;
	}
	
	$s = $part->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/' . $part->type;		
	$triples[] = [$s, $p, $o];	
	
	// Part is a DataFeed (for list of pages)
	$s = $part->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/DataFeed';		
	$triples[] = [$s, $p, $o];	
	
	// position in item
	if (isset($part->position))
	{
		$s = $part->id;
		$p = 'https://schema.org/position';
		$o = '"' . $part->position . '"^^<http://www.w3.org/2001/XMLSchema#integer>';		
		$triples[] = [$s, $p, $o];	
	}		
	
	// title
	$s = $part->id;
	$p = 'https://schema.org/name';
	$o = '"' . nice_literal($part->name) . '"';
	$triples[] = [$s, $p, $o];	
	
	// other bibliographic details...? careful, we sorta break schema's model 
	// if we have volume and issue
	if (isset($part->pagination))
	{
		$s = $part->id;
		$p = 'https://schema.org/pagination';
		$o = '"' . nice_literal($part->pagination) . '"';
		$triples[] = [$s, $p, $o];		
	}
	
	// part of item
	$s = $part->id;
	$p = 'https://schema.org/isPartOf';
	$o = $config['bhl'] . '/item/' . $part->isPartOf;		
	$triples[] = [$s, $p, $o];	
	
	// IIIF manifest
	//
	// Minted rather than looked up: unlike an item, whose manifest is Internet Archive's and
	// is named after the barcode, a part has no manifest anywhere to point at. This URI is
	// the one sparql2iiif.php builds a part manifest under, and saying so in the graph is
	// what lets a consumer discover that the part has one at all.
	$manifest = $config['bhl'] . '/part/' . $PartID . '/manifest';
	create_encoding_triples($triples, $part->id, $manifest, "application/ld+json");

	// PDF as encoding
	$pdf = $config['bhl'] . '/partpdf/' . $PartID;
	create_encoding_triples($triples, $part->id, $pdf, "application/pdf");

	// DOI
	if (isset($part->doi))
	{
		foreach ($part->doi as $doi)
		{
			$s = $part->id;
			$p = 'https://schema.org/sameAs';
			$o = nice_uri('https://doi.org/' . $doi);
			$triples[] = [$s, $p, $o];	
		}
	}
	
	// license
	if (isset($part->license))
	{
		$s = $part->id;
		$p = 'https://schema.org/license';
		$o = nice_uri($part->license);
		$triples[] = [$s, $p, $o];			
	}
	
	// date
	if (isset($part->date))
	{
		create_date($triples, $part->date, $part->id, 'https://schema.org/datePublished');
	}
	
	// link to creator id
	foreach ($part->creator as $creator)
	{
		$s = $part->id;
		$p = 'https://schema.org/creator';
		$o = $config['bhl'] . '/creator/' . $creator;
		$triples[] = [$s, $p, $o];			
	}
	
	// note that we need DataFeedItems as list elements because
	// the position of a page in the list is a property of the list element,
	// not the page, otherwise we end up with multiple positions assigned to
	// the same page.
	foreach ($pages as $position => $PageID)
	{
		$list_item_id = $part->id . '/page/' . str_pad($position, 4, '0', STR_PAD_LEFT);
	
		$s = $part->id;
		$p = 'https://schema.org/dataFeedElement';
		$o = $list_item_id;
		$triples[] = [$s, $p, $o];

		$s = $list_item_id;
		$p = 'https://schema.org/position';
		$o = '"' . $position . '"^^<http://www.w3.org/2001/XMLSchema#integer>';		
		$triples[] = [$s, $p, $o];
		
		$s = $list_item_id;
		$p = 'https://schema.org/item';
		$o = $config['bhl'] . '/page/' . $PageID;		
		$triples[] = [$s, $p, $o];
	}

	//print_r($triples);

	$output = dump_triples($triples);			
	echo $output . "\n";
}



$ItemID = 281611; // Monograph/Icones P no or little OCR in BHL!
$ItemID = 199416; // frogs peru

//get_item($ItemID );
//get_item_pages($ItemID );


//----------------------------------------------------------------------------------------
// Read the BHL Lite CouchDB view, returning its item and title ids.
//
// One row per item: {"id": "item/105897", "key": "bibliography/10088", "value":
// "item/105897"}. 314 of the 6,557 rows carry a list of bibliographies rather than one,
// because the item belongs to several. Returns null if the file is not a view.
function read_bhl_lite($path)
{
	$json = json_decode(file_get_contents($path));

	if (!$json || !isset($json->rows))
	{
		fwrite(STDERR, "Can't read a CouchDB view from $path\n");
		return null;
	}

	$items = array();
	$titles = array();

	foreach ($json->rows as $row)
	{
		$items[(int)preg_replace('/^item\//', '', $row->value)] = true;

		foreach ((is_array($row->key) ? $row->key : array($row->key)) as $key)
		{
			$titles[(int)preg_replace('/^bibliography\//', '', $key)] = true;
		}
	}

	$items = array_keys($items);
	$titles = array_keys($titles);

	sort($items);
	sort($titles);

	return array('items' => $items, 'titles' => $titles);
}

//----------------------------------------------------------------------------------------
// Generate RDF for the titles of BHL Lite, and nothing else.
//
// get_title() emits only triples about the bibliography and its own list elements -- it
// names its items but does not describe them -- so this output can be loaded over an
// existing BHL Lite graph to replace the title triples without touching the 38 million
// others. That is the point of having it separately: trying richer modelling for titles
// costs one reload of a few hundred thousand triples rather than a regeneration of
// everything.
function get_bhl_lite_titles($path)
{
	$view = read_bhl_lite($path);

	if ($view === null)
	{
		return;
	}

	fwrite(STDERR, "BHL Lite titles: " . count($view['titles']) . "\n");

	$n = 0;

	foreach ($view['titles'] as $TitleID)
	{
		get_title($TitleID);

		if (++$n % 100 == 0)
		{
			fwrite(STDERR, "  $n titles\n");
		}
	}

	fwrite(STDERR, "done, $n titles\n");
}

//----------------------------------------------------------------------------------------
// Generate RDF for BHL Lite, the subset listed in couchdb-items-titles.json: every title,
// then every item with its pages, parts and canvases.
//
// Canvas dimensions come from canvas.sqlite via canvas_triples(), keyed on barcode rather
// than ItemID. Without them a manifest has no canvas sizes and the IIIF CONSTRUCT matches
// nothing, since it requires exif width and height on every canvas.
//
// Writes N-Triples to stdout and progress to stderr, so the two can be separated with a
// plain redirect.
function get_bhl_lite($path)
{
	global $config, $pdo;

	$view = read_bhl_lite($path);

	if ($view === null)
	{
		return;
	}

	$items = $view['items'];
	$titles = $view['titles'];

	// Barcodes for the canvas lookup, and the set of items that actually exist. The view is
	// not necessarily the same vintage as bhl.db -- one item is in it and not in the
	// database -- and get_item() on a missing ItemID would read properties off null rather
	// than saying so.
	$barcode = array();

	foreach (db_get('SELECT DISTINCT ItemID, BarCode FROM item WHERE ItemID IN ('
		. join(',', $items) . ')') as $row)
	{
		$barcode[$row->ItemID] = isset($row->BarCode) ? $row->BarCode : null;
	}

	$missing = array_diff($items, array_keys($barcode));

	if (count($missing) > 0)
	{
		fwrite(STDERR, "skipping " . count($missing) . " item(s) not in the database: "
			. join(', ', $missing) . "\n");
	}

	fwrite(STDERR, "BHL Lite: " . count($titles) . " titles, "
		. (count($items) - count($missing)) . " items\n");

	foreach ($titles as $TitleID)
	{
		get_title($TitleID);
	}

	fwrite(STDERR, "titles done\n");

	$n = 0;

	foreach ($items as $ItemID)
	{
		if (!isset($barcode[$ItemID]))
		{
			continue;
		}

		get_item($ItemID);
		get_item_pages($ItemID);

		foreach (get_item_parts($ItemID) as $PartID)
		{
			get_part($PartID);
		}

		// canvas dimensions, from canvas.sqlite rather than from BHL
		if ($barcode[$ItemID] !== null)
		{
			echo dump_triples(canvas_triples($barcode[$ItemID]));
		}

		if (++$n % 250 == 0)
		{
			fwrite(STDERR, "  $n items\n");
		}
	}

	fwrite(STDERR, "done, $n items\n");
}

if (1)
{
	$TitleID = 57881;
	$TitleID = 149317;
	//$TitleID = 40366;
	
	$title = get_title($TitleID);
	
	/*
	foreach ($title->items as $ItemID => $position)
	{
		echo "\n\n";
		
		get_item($ItemID);
		
		echo "\n\n";
		
		get_item_pages($ItemID);
		
		echo "\n\n";
	}
	*/
	
}

if (0)
{
	$PartID = 178769;
	$PartID = 175696;
	
	
	
	$PartID = 229238;
	$PartID = 229257;
	
	
	get_part($PartID);
	
}	

if (0)
{
	$ItemID = 223011;
	
	$ItemID = 19421; // Magazine of natural history and journal of zoology, botany, mineralog v. 1
	get_item($ItemID);
	
	echo "\n\n";
	
	get_item_pages($ItemID);
	
	echo "\n\n";
	
	$parts = get_item_parts($ItemID);
	
	foreach ($parts as $PartID)
	{
		get_part($PartID);
		echo "\n\n";
	}
	
	
}

// BHL Lite -- every title and item in the CouchDB view, canvases included.
//
//   php sql/sql2rdf.php > bhl-lite.nt 2> bhl-lite.log
//
// Set the block above to 0 first; both write to stdout.
if (0)
{
	get_bhl_lite(dirname(dirname(__FILE__)) . '/couchdb-items-titles.json');
}

// BHL Lite titles only, for working on how titles are modelled.
//
//   php sql/sql2rdf.php > bhl-lite-titles.nt 2> bhl-lite-titles.log
//
// get_title() emits nothing outside the bibliography and its own list elements, so this
// can be reloaded on its own to replace the title triples without regenerating the 38
// million others. Set the other blocks to 0 first; they all write to stdout.
if (0)
{
	get_bhl_lite_titles(dirname(dirname(__FILE__)) . '/couchdb-items-titles.json');
}

?>
