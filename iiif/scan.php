<?php

$filename = 'scandata/europeanjournal4muse_scandata.xml';
$filename = 'scandata/generainsectorum9810wyts_scandata.xml';
$filename = 'scandata/Amphibianreptil9A_scandata.xml';
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
	
	// make a manifest for fun?
	
	$manifest = new stdclass;
	$manifest->{"@context"} = "http://iiif.io/api/presentation/3/context.json";
	$manifest->id = 'https://archive.org/details/' . $id;
	$manifest->type = "Manifest";
	
	$manifest->label = new stdclass;
	$manifest->label->en = ["test"];

	$manifest->behaviour = ["paged"];
	$manifest->seeAlso = [];
	
	$manifest->items = [];
	$manifest->structures = [];
	
	// PDF
	$rendering = new stdclass;
	$rendering->id = "https://archive.org/download/$id/$id.pdf";
    $rendering->type = "Text";
    $rendering->label = "PDF Download";
    $rendering->format = "application/pdf";
    
    $manifest->rendering = [$rendering];
		
	if ($id == 'Amphibianreptil9A_scandata')
	{
		// table of contents (hard-coded for Amphibianreptil9A)
		
		// an article is a range
		$range = new stdclass;
		$range->id = $manifest->id . "/range/1";
		$range->type = "Range";
		$range->label = "Noblella lynchi Duellman 1991 (Anura: Craugastoridae): Geographic range extension, Peru";
	
		// Article starts with this canvas 
		$item = new stdclass;
		$item->id = "https://archive.org/details/Amphibianreptil9A/canvas/p0014";
		$item->type = "Canvas";
	
		// Add canvas to range (could add all pages in article)
		$range->items[] = $item;
		
		// Add this range to list of structures
		$manifest->structures[] = $range;
	}
	
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
			$canvas->id = null;
			$canvas->type = "Canvas";
			$canvas->label = null;
			$canvas->width = (Integer)0;
			$canvas->height = (Integer)0;			
			$canvas->items = [];			
							
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
			$thumbnail = new stdclass;
			$thumbnail->id = "https://bhl-open-data.s3.us-east-2.amazonaws.com/web/" . "$id/$id" . "_" . str_pad($leaf_counter, 4, '0', STR_PAD_LEFT) . "_thumb.webp";
			$thumbnail->type = "Image";
			$thumbnail->width = 150; // small webp are 150 pixels wide
			$thumbnail->height = floor(150 * $canvas->height / $canvas->width);
			$thumbnail->format = "image/webp";
			
			$canvas->thumbnail = [$thumbnail];
			
			// alt page numbers are usually more descriptive
			if (!$canvas->label)
			{
				foreach($xpath->query ('altPageNumbers/altPageNumber', $page) as $node)
				{
					$pagearts = [];
					foreach($xpath->query ('@prefix', $node) as $pagerefix)
					{
						$pagearts[] = $pagerefix->firstChild->nodeValue;	
					}
					$pagearts[] = $node->firstChild->nodeValue;
					
					$canvas->label = new stdclass;
					$canvas->label->none = [];								
					$canvas->label->none[] = join(" ", $pagearts);
				}			
			}

			// if no alt number use page number
			if (!$canvas->label)
			{
				foreach($xpath->query ('pageNumber', $page) as $node)
				{					
					$canvas->label = new stdclass;
					$canvas->label->none = [];								
					$canvas->label->none[] = $node->firstChild->nodeValue;
				}			
			}
			
			if (isset($attributes['leafNum']))
			{
				// if no page numbers use leaf number
				if (!$canvas->label)
				{
					$canvas->label = new stdclass;
					$canvas->label->none = [];								
					$canvas->label->none[] = $attributes['leafNum'];					
				}
			
				// canvas id based on order in manifest
				$canvas->id = $manifest->id . "/canvas/p" . str_pad($leaf_counter, 4, '0', STR_PAD_LEFT);
								
				// list of annotations on this canvas
				$annotation_page = new stdclass;
				$annotation_page->id = $canvas->id . "/ap1";
				$annotation_page->type = "AnnotationPage";
				$annotation_page->items = [];
				
				$canvas->items[] = $annotation_page;
				
				// annotation to paint page image on canvas
				$annotation = new stdclass;
				$annotation->id = $annotation_page->id . "/a1";
				$annotation->type = "Annotation";
				$annotation->motivation = "painting";
				
				// body is direct link to image (more sophisticated manifests support IIIF image servers)
				
				$image_size = "large";
				$image_size = "full";
				
				$annotation->body = new stdclass;
				$annotation->body->id = "https://bhl-open-data.s3.us-east-2.amazonaws.com/web/" . "$id/$id" . "_" . str_pad($leaf_counter, 4, '0', STR_PAD_LEFT) . "_" . $image_size . ".webp";
				$annotation->body->type = "Image";
				
				switch ($image_size)
				{
					case 'large':
						// we rely on the fact that large webp images are 930 pixels wide
						$annotation->body->width  = 930;
						$annotation->body->height = floor(930 * $canvas->height / $canvas->width);
						break;
						
					default:
						$annotation->body->width  = $canvas->width;
						$annotation->body->height = $canvas->height;
						break;					
				}
				
				$annotation->body->format = "image/webp";
									
				// target is the canvas (virtual page)
				$annotation->target = $canvas->id;
				
				// add annotation to the list of annotations for this canvas
				$annotation_page->items[] = $annotation;
			}
					
			$manifest->items[] = $canvas;
		}
	}
	
}

echo json_encode($manifest);


?>

