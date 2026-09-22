<?php

error_reporting(E_ALL);

// Send warnings and notices to stderr, not stdout.
//
// These scripts write N-Triples to stdout, and PHP's default display_errors puts errors on
// the same stream -- so a single notice mid-run lands in the middle of the .nt file and the
// whole thing stops parsing. A full page-level pass is ~800 million triples, far too much to
// eyeball, so the corruption would only show up when the load failed. Only meaningful under
// the CLI SAPI; elsewhere 'stderr' is not a valid value.
if (php_sapi_name() === 'cli')
{
	ini_set('display_errors', 'stderr');
}

$config['aws'] = 'https://bhl-open-data.s3.us-east-2.amazonaws.com';
$config['bhl']  = 'https://www.biodiversitylibrary.org';
$config['ia']  = 'https://archive.org/details';

//----------------------------------------------------------------------------------------
// Make a URI play nice with triple store
function nice_uri($uri)
{
	// known errors 
	
	// <https://doi.org/10.3398/064.079.0101> <http://schema.org/citation> <https://doi.org/10.1899/0887-3593(2005)024\%5B0508:biomeu\%5D2.0.co;2> .
	// 10.1899/0887-3593(2005)024\[0508:BIOMEU\]2.0.CO;2
	$uri = str_replace('\[', '[', $uri);
	$uri = str_replace('\]', ']', $uri);
	
	// <https://doi.org/10.1139/gen-2015-0168> <http://schema.org/citation> <https://doi.org/10.1111/j.1471-8286.2007.01678.x. pmid:> .
	$uri = preg_replace('/\.\s+pmid:/', '', $uri);	
	
	// <https://doi.org/10.1007/s10531-023-02686-9> <http://schema.org/citation> <https://doi.org/10.1139/gen-2019-0226%m32502367> .
	$uri = preg_replace('/%m\d+/', '', $uri);
	
	// <https://doi.org/10.1071/is24059> <http://schema.org/citation> <https://doi.org/10.11646/zoosymposia. 2.1.21> .
	$uri = preg_replace('/\s+/', '', $uri);


	$uri = str_replace('[', urlencode('['), $uri);
	$uri = str_replace(']', urlencode(']'), $uri);
	$uri = str_replace('<', urlencode('<'), $uri);
	$uri = str_replace('>', urlencode('>'), $uri);
	
	$uri = str_replace('|', urlencode('|'), $uri);
	
	// cray cray
	// 3234R
	// http://https://www.indexfungorum.org/Names/NamesRecord.asp?RecordID=560001
	$uri = str_replace('http://https://', 'http://', $uri);

	return $uri;
}

//----------------------------------------------------------------------------------------
// Clean up text to play nice with triple stire
function nice_literal($text)
{
	// escape backslashes 
	$text = str_replace('\\', '\\\\', $text);
	
	// remove HTML/XML tags
	$text = strip_tags($text);
	
	// replace newlines
	$text = preg_replace('/\R/u', ' ', $text);	
	
	// clean up spaces
	$text = preg_replace('/\s\s+/', ' ', $text);	
	
	
	// escape double quotes
	$text = str_replace('"', '\"', $text);
	
	return $text;
}


//----------------------------------------------------------------------------------------
// triple stores can do date queries much faster if we use date type.
// $date_value is either a CSL-JSON style array [year, month, day], or
// some variation on a YYYY-MM-DD ISO date string (i.e., could just be a year)
function nice_date($date_value)
{
	if (!is_array($date_value))
	{
		$date_value = explode('-', $date_value);	
	}
	
	// sanity check
	if (is_numeric($date_value[0]))
	{
		$datetype = 'date';
	
		if ( count($date_value ) > 0 ) $year = $date_value [0] ;
		if ( count($date_value ) > 1 ) $month = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$date_value[1] ) ;
		if ( count($date_value ) > 2 ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$date_value[2] ) ;
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
		
		return '"' . $date . '"^^<http://www.w3.org/2001/XMLSchema#' . $datetype . '>';
	}
	else
	{
		// fall back on treating date as a literal string
		return '"' . $date_value . '"';
	}
}

//----------------------------------------------------------------------------------------
// Dump array of triples (each triple is itself an array)
function dump_triples($triples)
{
	$output = '';

	foreach ($triples as $t)
	{	
		$row = array();
		foreach ($t as $element)
		{
			// Is this a URI?
			//
			// geo: is here for the geotag annotations, whose body is an RFC 5870 URI rather
			// than a minted http one. Without it the body is emitted bare, which is not a
			// valid N-Triples object at all and takes the rest of the file down with it.
			if (preg_match('/^(https?|urn|geo):/', $element))
			{
				$element = '<' . $element . '>';
			}
		
			$row[] = $element;
		}
	
		$output .= join(" ", $row) . " .\n";
	}		
	
	return $output;
}

//----------------------------------------------------------------------------------------
// take ISO date and convert to typed date
function create_date(&$triples, $date_string, $subject_uri, $predicate_uri = 'https://schema.org/datePublished')
{
	// Only a single calendar date, as YYYY, YYYY-MM or YYYY-MM-DD. Anything else is bad
	// input and gets no triple at all.
	//
	// This used to split on "-" and take the second field as a month whatever it was, so a
	// year range -- which has the same shape once split -- became a month of four digits:
	//
	//   "1893-1908"  ->  "1893-001908"^^xsd:gYearMonth
	//
	// That is outside gYearMonth's lexical space, so it is an ill-typed literal: it does not
	// error, it silently fails every comparison, and a row simply vanishes from a range
	// filter or an aggregate. 1,584 of BHL's 404,792 dated parts carry a year range and
	// would be mangled this way. Emitting nothing is worse data but honest, and a caller
	// that wants to represent a span should use startDate and endDate as get_item() does.
	//
	// Month and day may be given with one or two digits and are normalised to two; the year
	// must be four. Returns true when a triple was added.
	if (!preg_match('/^([0-9]{4})(?:-([0-9]{1,2})(?:-([0-9]{1,2}))?)?$/', trim($date_string), $m))
	{
		return false;
	}

	$year = $m[1];

	$month = isset($m[2]) && $m[2] !== '' ? str_pad($m[2], 2, '0', STR_PAD_LEFT) : null;
	$day   = isset($m[3]) && $m[3] !== '' ? str_pad($m[3], 2, '0', STR_PAD_LEFT) : null;

	// a month of 00 or 13, or a day of 00 or 32, is not a date however it is spelt
	if ($month !== null && ((int)$month < 1 || (int)$month > 12))
	{
		return false;
	}

	if ($day !== null && ((int)$day < 1 || (int)$day > 31))
	{
		return false;
	}

	if ($day !== null)
	{
		$date     = "$year-$month-$day";
		$datetype = 'date';
	}
	else if ($month !== null)
	{
		$date     = "$year-$month";
		$datetype = 'gYearMonth';
	}
	else
	{
		$date     = "$year";
		$datetype = 'gYear';
	}

	$s = $subject_uri;
	$p = $predicate_uri;
	$o = '"' . $date . '"^^<http://www.w3.org/2001/XMLSchema#' . $datetype . '>';
	$triples[] = [$s, $p, $o];

	return true;
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



?>