<?php

// Output examples of objects

require_once(dirname(__FILE__) . '/vendor/autoload.php');
require_once(dirname(__FILE__) . '/lib.php');

use ML\JsonLD\JsonLD;
use ML\JsonLD\NQuads;

//----------------------------------------------------------------------------------------
// title
function construct_title($TitleID)
{
	global $config;
	
	$sparql = '
	CONSTRUCT
	{
	  ?title <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?type .
	  ?title <https://schema.org/name> ?name .
	  ?title <https://schema.org/dataFeedElement> ?item .
	  ?item <https://schema.org/name> ?item_name .
	  #?item <https://schema.org/position> ?item_position .
	}
	WHERE {
	  VALUES ?title { <https://www.biodiversitylibrary.org/bibliography/' . $TitleID . '> }
	  ?title <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?type .
	  ?title <https://schema.org/name> ?name .
	  
	  ?title <https://schema.org/dataFeedElement> ?dataFeedElement .
	  ?dataFeedElement <https://schema.org/position> ?item_position .
	  ?dataFeedElement <https://schema.org/item> ?item .
	  OPTIONAL {
	  ?item <https://schema.org/name> ?item_name .
	  }
	}
	ORDER BY ?item_position
	';
	
	//$sparql = str_replace('URI', $uri, $sparql);
	
	$triples = construct($sparql);
	
	// echo $triples;
	
	// JSON-LD context
	$context = new stdclass;
	$context->{'@vocab'} = "https://schema.org/";

	// make @id and @type JSON-friendly
	$context->id = "@id";
	$context->type = "@type";
		
	// Frame document
	$frame = (object)array(
		'@context' => $context,
		'@type' => 'https://schema.org/DataFeed'
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
	
	$title = $result->{'@graph'}[0];
	echo json_encode($title, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

}

//----------------------------------------------------------------------------------------
// item
function construct_item($ItemID)
{
	global $config;
	
	$sparql = '
	CONSTRUCT
	{
	  ?item <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?type .
	  ?item <https://schema.org/name> ?name .
	  
	  ?item <https://schema.org/isPartOf> ?title .
	  ?title <https://schema.org/name> ?title_name .	  
	  
	  ?item <https://schema.org/dataFeedElement> ?page .
	  ?page <https://schema.org/name> ?page_name .
	  #?page <https://schema.org/position> ?page_position .
	}
	WHERE {
	  VALUES ?item { <https://www.biodiversitylibrary.org/item/' . $ItemID . '> }
	  ?item <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?type .
	  ?item <https://schema.org/name> ?name .
	  
	  ?item <https://schema.org/isPartOf> ?title .
	  # optional because as we debug and add triples we might not have added all the titles
	  OPTIONAL 
	  {
	  	?title <https://schema.org/name> ?title_name .
	  }
	  
	  ?item <https://schema.org/dataFeedElement> ?dataFeedElement .
	  ?dataFeedElement <https://schema.org/position> ?page_position .
	  ?dataFeedElement <https://schema.org/item> ?page .
	  ?page <https://schema.org/name> ?page_name .
	}
	ORDER BY ?page_position
	';
	
	//$sparql = str_replace('URI', $uri, $sparql);
	
	$triples = construct($sparql);
	
	// echo $triples;
	
	// JSON-LD context
	$context = new stdclass;
	$context->{'@vocab'} = "https://schema.org/";

	// make @id and @type JSON-friendly
	$context->id = "@id";
	$context->type = "@type";
		
	// Frame document
	$frame = (object)array(
		'@context' => $context,
		'@type' => 'https://schema.org/DataFeed'
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
	
	$item = $result->{'@graph'}[0];
	echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

}

//----------------------------------------------------------------------------------------
// part
function construct_part($PartID)
{
	global $config;
	
	$sparql = '
	CONSTRUCT
	{
	  ?part <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?type .
	  ?part <https://schema.org/name> ?name .
	  
	  ?part <https://schema.org/isPartOf> ?item .
	  ?item <https://schema.org/name> ?item_name .	  
	  
	  ?part <https://schema.org/dataFeedElement> ?page .
	  ?page <https://schema.org/name> ?page_name .
	  #?page <https://schema.org/position> ?page_position .
	}
	WHERE {
	  VALUES ?part { <https://www.biodiversitylibrary.org/part/' . $PartID . '> }
	  ?part <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?type .
	  ?part <https://schema.org/name> ?name .
	  
	  ?part <https://schema.org/isPartOf> ?item .
	  # optional because as we debug and add triples we might not have added all the titles
	  OPTIONAL 
	  {
	  	?item <https://schema.org/name> ?item_name .
	  }
	  
	  ?part <https://schema.org/dataFeedElement> ?dataFeedElement .
	  ?dataFeedElement <https://schema.org/position> ?page_position .
	  ?dataFeedElement <https://schema.org/item> ?page .
	  OPTIONAL 
	  {
	  ?page <https://schema.org/name> ?page_name .
	  }
	}
	ORDER BY ?page_position
	';
	
	//$sparql = str_replace('URI', $uri, $sparql);
	
	$triples = construct($sparql);
	
	//echo $triples;
	
	// JSON-LD context
	$context = new stdclass;
	$context->{'@vocab'} = "https://schema.org/";

	// make @id and @type JSON-friendly
	$context->id = "@id";
	$context->type = "@type";
		
	// Frame document
	$frame = (object)array(
		'@context' => $context,
		'@type' => 'https://schema.org/DataFeed'
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
	
	$item = $result->{'@graph'}[0];
	echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

}

//construct_title(57881);

//construct_item(334238);

construct_part(229257);


?>
