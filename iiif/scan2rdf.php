<?php

error_reporting(E_ALL);

require_once(dirname(dirname(__FILE__)) . '/shared.php');

$filename = 'scandata/europeanjournal4muse_scandata.xml';
$filename = 'scandata/generainsectorum9810wyts_scandata.xml';
$filename = dirname(__FILE__) . '/scandata/Amphibianreptil9A_scandata.xml';
//$filename = 'scandata/bihangtillkongls284kung_scandata.xml';

{
	$xml = file_get_contents($filename);

	$dom = new DOMDocument;
	$dom->loadXML($xml);
	$xpath = new DOMXPath($dom);
	
	$id = '';
	
	foreach($xpath->query ('//bookData/bookId') as $node)
	{
		$id = $node->firstChild->nodeValue;
	}
	
	$canvases = [];
		
	$leaf_counter = 0;
		
	foreach($xpath->query ('//pageData/page') as $page)
	{
		$attrs = $page->attributes; 		
		foreach ($attrs as $i => $attr)
		{
			$attributes[$attr->name] = $attr->value; 
		}
	
		$include = true; // only true if scan data says include the page
		
		// include?
		foreach($xpath->query ('addToAccessFormats', $page) as $node)
		{
			$include = ($node->firstChild->nodeValue == 'true' ? true : false);
		}	
		
		if ($include)
		{
			$leaf_counter++;
						
			// canvas (virtual page)
			$canvas = new stdclass;
			$canvas->id = $id . "/canvas/p" . str_pad($leaf_counter, 4, '0', STR_PAD_LEFT);

			$canvas->width = (Integer)0;
			$canvas->height = (Integer)0;	
			
			$canvas->position = $leaf_counter;

			// Page labels come from the BHL data dump tables instead, see sql2rdf.php.
			// Left here in case the scan data is ever the better source: the printed page
			// number if there is one ("Page 1"), otherwise the leaf number, with
			// altPageNumber carrying the prefix the book itself uses.
			/*
			$canvas->label = '';
			foreach($xpath->query ('altPageNumbers/altPageNumber', $page) as $node)
			{
				$canvas->label = trim($node->getAttribute('prefix') . ' ' . $node->firstChild->nodeValue);
			}
			if ($canvas->label == '')
			{
				foreach($xpath->query ('pageNumber', $page) as $node)
				{
					$canvas->label = $node->firstChild->nodeValue;
				}
			}
			if ($canvas->label == '')
			{
				$canvas->label = (string)$leaf_counter;
			}
			*/

			// cropbox w and h correspond to dimensions of the image
			foreach($xpath->query ('cropBox/w', $page) as $node)
			{
				$canvas->width = (Integer)$node->firstChild->nodeValue;
			}
			foreach($xpath->query ('cropBox/h', $page) as $node)
			{
				$canvas->height = (Integer)$node->firstChild->nodeValue;
			}
			
			// thumbnail
			$canvas->thumbnail = new stdclass;
			$canvas->thumbnail->url = $config['aws'] . "/web/$id/$id" . "_" . str_pad($leaf_counter, 4, '0', STR_PAD_LEFT) . "_thumb.webp";
			$canvas->thumbnail->width = 150; // small webp are 150 pixels wide
			$canvas->thumbnail->height = floor(150 * $canvas->height / $canvas->width);
			
			$canvases[] = $canvas;
		}
	}
	
	$triples = [];
	
	foreach ($canvases as $canvas)
	{
		$s = $config['ia'] . "/" . $canvas->id;
		$p = 'http://www.w3.org/2003/12/exif/ns#width';
		$o = '"' . $canvas->width . '"^^<http://www.w3.org/2001/XMLSchema#integer>';		
		$triples[] = [$s, $p, $o];	

		$p = 'http://www.w3.org/2003/12/exif/ns#height';
		$o = '"' . $canvas->height . '"^^<http://www.w3.org/2001/XMLSchema#integer>';
		$triples[] = [$s, $p, $o];

		// label (plain literal, no language tag — it is a page number, not prose)
		// Emitted by sql2rdf.php from the BHL data dump instead.
		/*
		$p = 'http://www.w3.org/2000/01/rdf-schema#label';
		$o = '"' . addcslashes($canvas->label, "\"\\\n\r\t") . '"';
		$triples[] = [$s, $p, $o];
		*/

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
	
	$output = dump_triples($triples);			
	echo $output . "\n";
	

	
}


?>

