<?php

// Extract data from SQLite and convert to RDF

error_reporting(E_ALL);

require_once(dirname(dirname(__FILE__)) . '/shared.php');
require_once (dirname(__FILE__) . '/sqlite.php');


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
// Get item
function get_item ($ItemID )
{
	global $config;
	
	// get individual item
	$sql = 'SELECT DISTINCT ItemID, VolumeInfo, TitleID, ThumbnailPageID, Year, InstitutionName, CopyrightStatus, BarCode
	FROM item';
	$sql .= ' WHERE ItemID='. $ItemID;
	
	$data = db_get($sql);
	
	print_r($data);
	
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
			if (!is_array($item->isPartOf))
			{
				$item->isPartOf = [$item->isPartOf];
			}
			$item->isPartOf[] = $row->TitleID;		
		}
	}
	
	print_r($item);	
	
	// triples
		
	$triples = [];
	
	// creative work
	$s = $item->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/CreativeWork';		
	$triples[] = [$s, $p, $o];	

	// name
	$s = $item->id;
	$p = 'https://schema.org/name';
	$o = '"' . nice_literal($item->name) . '"';		
	$triples[] = [$s, $p, $o];	
	
	$s = $item->id;
	$p = 'https://schema.org/sameAs';
	$o = 'https://archive.org/details/' . $item->barcode;		
	$triples[] = [$s, $p, $o];	
	
	$s = $item->id;
	$p = 'https://schema.org/isPartOf';
	$o = $item->isPartOf;		
	$triples[] = [$s, $p, $o];	
	

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
	$sql = 'SELECT PageID, ItemID, BarCode, SequenceOrder, PagePrefix, PageNumber, PageTypeName FROM page';
	$sql .= ' INNER JOIN item USING(ItemID)';
	
	if ($ItemID)
	{
		$sql .= ' WHERE ItemID='. $ItemID;
	}
	$sql .= ' ORDER BY ItemID, CAST(SequenceOrder AS INTEGER)';
	
	$data = db_get($sql);
	
	//print_r($data);
	
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
	
	// print_r($pages);
	
	$triples = [];
	
	foreach ($pages as $page)
	{
		// a page is a creative work
		$s = $page->id;
		$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
		$o = 'https://schema.org/CreativeWork';		
		$triples[] = [$s, $p, $o];	
		
		// to do: link to parent item

		// isPartOf an item, think about whether we want datafeed as well
		$s = $page->id;
		$p = 'https://schema.org/isPartOf';
		$o = $config['bhl'] . '/item/' . $ItemID;
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
		
		/*		
		// canvas has a position (they are ordered)
		$s = $page->canvas;
		$p = 'https://schema.org/position';
		$o = '"' . $page->position . '"^^<http://www.w3.org/2001/XMLSchema#integer>';		
		$triples[] = [$s, $p, $o];	
		*/
						
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
		
		create_encoding_triples($triples, $page->id, $page->text, "text/plain");
			
	}
	
	$output = dump_triples($triples);			
	echo $output . "\n";
}

$ItemID = 281611; // Monograph/Icones P no or little OCR in BHL!
$ItemID = 199416; // frogs peru

get_item($ItemID );
//get_item_pages($ItemID );

?>

