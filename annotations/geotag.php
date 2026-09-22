<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/annotation.php');

//----------------------------------------------------------------------------------------
/**
 * @brief Convert degrees, minutes, seconds to a decimal value
 *
 * @param degrees Degrees
 * @param minutes Minutes
 * @param seconds Seconds
 * @param hemisphere Hemisphere (optional)
 *
 * @result Decimal coordinates
 */
function degrees2decimal($degrees, $minutes=0, $seconds=0, $hemisphere='N')
{
	// echo "Input=$degrees • $minutes • $seconds • $hemisphere\n";

	// ensure decimal point (if any) is a point, not a comma
	$degrees = str_replace(',', '.', $degrees);
	$minutes = str_replace(',', '.', $minutes);
	$seconds = str_replace(',', '.', $seconds);

	$result = $degrees;
	$result += $minutes/60.0;
	$result += $seconds/3600.0;
		
	//echo "seconds=$seconds|<br/>";
	
	if ($hemisphere == 'S')
	{
		$result *= -1.0;
	}
	if ($hemisphere == 'W')
	{
		$result *= -1.0;
	}
	// Spanish
	if ($hemisphere == 'O')
	{
		$result *= -1.0;
	}
	// Spainish OCR error
	if ($hemisphere == '0')
	{
		$result *= -1.0;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
function toPoint($matches)
{
	$feature = new stdclass;
	$feature->type = "Feature";
	$feature->geometry = new stdclass;
	$feature->geometry->type = "Point";
	$feature->geometry->coordinates = array();
			
	$degrees = $minutes = $seconds = 0;		
		
	if (isset($matches['latitude_seconds']))
	{
		$seconds = $matches['latitude_seconds'];
		
		if ($seconds == '')
		{
			$seconds = 0;
		}
		
	}
	
	if (isset($matches['latitude_minutes']))
	{
		$minutes = $matches['latitude_minutes'];
	}
	$degrees = $matches['latitude_degrees'];
		
	$feature->geometry->coordinates[1] = degrees2decimal($degrees, $minutes, $seconds, $matches['latitude_hemisphere']);

	$degrees = $minutes = $seconds = 0;	
	
	if (isset($matches['longitude_seconds']))
	{
		$seconds = $matches['longitude_seconds'];
		
		if ($seconds == '')
		{
			$seconds = 0;
		}
	}
	if (isset($matches['longitude_minutes']))
	{
		$minutes = $matches['longitude_minutes'];
	}
	$degrees = $matches['longitude_degrees'];
	
	$feature->geometry->coordinates[0] = degrees2decimal($degrees, $minutes, $seconds, $matches['longitude_hemisphere']);
	
	// ensures that JSON export treats coordinates as an array
	ksort($feature->geometry->coordinates);

	// Refuse anything off the globe.
	//
	// Several of the patterns below take $FLOAT for the degrees, which is any run of digits,
	// so a printed coordinate that has lost its decimal point is read whole: page 56186825
	// carries "0289562°S, 02950221°E" and that parsed to a latitude of -289562. The true
	// reading is 02.89562°S, 029.50221°E -- Burundi, which is where the INECN collection it
	// sits in actually is.
	//
	// Checking the range here rather than tightening each pattern catches the same class of
	// error from all of them at once, including the ones with a bounded degree field where
	// stray minutes or seconds can still push the total past the pole. Returning null means
	// no annotation is made at all, which is the right answer: we found something that looks
	// like a coordinate and cannot say where it is, and a body naming an impossible place is
	// worse in the store than no body.
	$latitude = $feature->geometry->coordinates[1];
	$longitude = $feature->geometry->coordinates[0];

	if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)
	{
		return null;
	}
	
	return $feature;
}

//----------------------------------------------------------------------------------------

function add_geo_match_to_annotation($matches, $text, &$annotations)
{
	$last_pos = 0;
	
	foreach ($matches as $match)
	{
		$annotation = new stdclass;			
		$annotation->text = $match[0];			
		$annotation->target = new stdclass;

		// before the toPoint() test, because it advances $last_pos: a rejected match still
		// has to be stepped over, or the next occurrence of the same string is found at the
		// rejected one's position
		$annotation->target->selector = annotation_selector($text, $match[0], $last_pos);

		$annotation->geojson = toPoint($match);

		if (is_null($annotation->geojson))
		{
			continue;
		}

		$annotations[] = $annotation;
	}
}

//----------------------------------------------------------------------------------------
// tag coordinates in text
function tag_geo($text)
{
	$annotations = array();

	$DEGREES_SYMBOL 		=  '(?:˚|°|º)';
	$MINUTES_SYMBOL			= '(\'|’|\′|\´)';
	$SECONDS_SYMBOL			= '("|\'\'|’’|”|\′\′|\´\´|″|\′\′)';
	
	$INTEGER				= '\d+';
	$FLOAT					= '\d+(?:[.,]\d+)?';
	
	$LATITUDE_DEGREES 		= '[0-9]{1,2}';
	$LONGITUDE_DEGREES 		= '[0-9]{1,3}';
	
	$LATITUDE_HEMISPHERE 	= '[N|S]';
	$LONGITUDE_HEMISPHERE 	= '[W|E]';
	
	$ES_LATITUDE_HEMISPHERE 	= '[NS]';
	$ES_LONGITUDE_HEMISPHERE 	= '[OE]';
		
	$flanking_length = 50;
	
	$results = array();
		
	if (preg_match_all("/
		(?<latitude_degrees>$LATITUDE_DEGREES)
		$DEGREES_SYMBOL
		\s*
		(?<latitude_minutes>$FLOAT)
		\s*
		$MINUTES_SYMBOL?
		\s*
		(
		(?<latitude_seconds>$FLOAT)
		$SECONDS_SYMBOL
		)?
		\s*
		(?<latitude_hemisphere>$LATITUDE_HEMISPHERE)
		,?
		(\s+-)?
		;?
		\s*
		(?<longitude_degrees>$LONGITUDE_DEGREES)
		$DEGREES_SYMBOL
		\s*
		(?<longitude_minutes>$FLOAT)
		\s*
		$MINUTES_SYMBOL?
		\s*
		(
		(?<longitude_seconds>$FLOAT)
		$SECONDS_SYMBOL
		)?
		\s*
		(?<longitude_hemisphere>$LONGITUDE_HEMISPHERE)
		
	/xu",  $text, $matches, PREG_SET_ORDER))
	{
		// this was add_geo_match_to_annotation()'s body written out again, character for
		// character; calling it keeps the out-of-range test in one place
		add_geo_match_to_annotation($matches, $text, $annotations);
	}
	
	// 29.6° N, 101.8° E
	if (preg_match_all("/
		(?<latitude_degrees>$FLOAT)
		$DEGREES_SYMBOL
		\s*
		(?<latitude_hemisphere>$LATITUDE_HEMISPHERE)
		,
		\s+
		(?<longitude_degrees>$FLOAT)
		$DEGREES_SYMBOL
		\s*
		(?<longitude_hemisphere>$LONGITUDE_HEMISPHERE)		
	/xu",  $text, $matches, PREG_SET_ORDER))
	{
		add_geo_match_to_annotation($matches, $text, $annotations);	
	}
	
	
	// N27.21234º, E098.69601º
	if (preg_match_all("/
		(?<latitude_hemisphere>$LATITUDE_HEMISPHERE)
		(?<latitude_degrees>$FLOAT)
		$DEGREES_SYMBOL
		,
		\s+
		(?<longitude_hemisphere>$LONGITUDE_HEMISPHERE)
		(?<longitude_degrees>$FLOAT)
		$DEGREES_SYMBOL		
	/xu",  $text, $matches, PREG_SET_ORDER))
	{
		add_geo_match_to_annotation($matches, $text, $annotations);	
	}
	
	// N25°59', E98°40'
	if (preg_match_all("/
		(?<latitude_hemisphere>$LATITUDE_HEMISPHERE)
		\s*
		(?<latitude_degrees>$LATITUDE_DEGREES)
		$DEGREES_SYMBOL
		(?<latitude_minutes>$INTEGER)
		$MINUTES_SYMBOL
		(
		(?<latitude_seconds>$FLOAT)
		$SECONDS_SYMBOL
		)?		
		,
		\s+
		(?<longitude_hemisphere>$LONGITUDE_HEMISPHERE)
		\s*
		(?<longitude_degrees>$LONGITUDE_DEGREES)
		$DEGREES_SYMBOL		
		(?<longitude_minutes>$INTEGER)
		$MINUTES_SYMBOL
		(
		(?<longitude_seconds>$FLOAT)
		$SECONDS_SYMBOL
		)?		
	/xu",  $text, $matches, PREG_SET_ORDER))
	{
		//print_r($matches);
		add_geo_match_to_annotation($matches, $text, $annotations);
	}
	
	// Spanish https://doi.org/10.21068/c2018.v19s1a11
	// 4°19´44”N y 71°43´54.1”O
	if (preg_match_all("/
		(?<latitude_degrees>$LATITUDE_DEGREES)
		$DEGREES_SYMBOL
		(?<latitude_minutes>$INTEGER)
		$MINUTES_SYMBOL
		\s*
		(
		(?<latitude_seconds>$FLOAT)
		$SECONDS_SYMBOL
		)?
		\s*
		(?<latitude_hemisphere>$ES_LATITUDE_HEMISPHERE)		
		\s*y\s*
		(?<longitude_degrees>$LONGITUDE_DEGREES)
		$DEGREES_SYMBOL		
		(?<longitude_minutes>$INTEGER)
		$MINUTES_SYMBOL
		\s*
		(
		(?<longitude_seconds>$FLOAT)
		$SECONDS_SYMBOL
		)?		
		\s*
		(?<longitude_hemisphere>$ES_LONGITUDE_HEMISPHERE)
	/xu",  $text, $matches, PREG_SET_ORDER))
	{
		add_geo_match_to_annotation($matches, $text, $annotations);
	}	
	

	return $annotations;
}

?>
