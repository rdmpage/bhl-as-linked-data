<?php

// Extract data from SQLite and convert to RDF

error_reporting(E_ALL);

require_once(dirname(dirname(__FILE__)) . '/shared.php');
require_once (dirname(__FILE__) . '/sqlite.php');

//----------------------------------------------------------------------------------------
// Get item
function get_item ($ItemID )
{
	// get individual item
	$sql = 'SELECT DISTINCT ItemID, VolumeInfo, TitleID, ThumbnailPageID, Year, InstitutionName, CopyrightStatus, BarCode
	FROM item';
	$sql .= ' WHERE ItemID='. $ItemID;
	
	$data = db_get($sql);
	
	print_r($data);
	
	$obj = null;
	
	foreach ($data as $row)
	{	
		if (!$obj)
		{
			$obj = new stdclass;
		
			$obj->id = $row->ItemID;
			
			if (isset($row->VolumeInfo))
			{		
				$obj->name = $row->VolumeInfo;
				
				// to do: can we parse this more accurately than BHL?
			}
			else
			{
				$obj->name = '[Untitled]';
			}
				
			$obj->isPartOf = $row->TitleID;
			
			if (isset($row->ThumbnailPageID))
			{
				$obj->thumbnail = $row->ThumbnailPageID;
			}
			
			if (isset($row->Year))
			{
				$obj->year = $row->Year;
			}
		
			if (isset($row->InstitutionName))
			{
				$obj->provider = $row->InstitutionName;
			}
			
			if (isset($row->CopyrightStatus))
			{
				$obj->copyrightNotice = $row->CopyrightStatus;
			}
		
			// to do, maybe change this?
			if (isset($row->BarCode))
			{
				$obj->barcode = $row->BarCode;
			}
		}
		else
		{
			if (!is_array($obj->isPartOf))
			{
				$obj->isPartOf = [$obj->isPartOf];
			}
			$obj->isPartOf[] = $row->TitleID;
		
		}
	}
	
	print_r($obj);	
	
	// triples
	
	// consier creating sameas or encoding lin to IIIF manifest, 
	// and link name of item to manifest
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
		
		// according the schema.org this should really be text, not a URL, but what can you do...?
		$s = $page->id;
		$p = 'https://schema.org/text';
		$o = $page->text;		
		$triples[] = [$s, $p, $o];	
	}
	
	$output = dump_triples($triples);			
	echo $output . "\n";
}

$ItemID = 281611; // Monograph/Icones P no or little OCR in BHL!
$ItemID = 199416; // frogs peru

//get_item($ItemID );
get_item_pages($ItemID );

?>

