<?php

$config['triplestore'] = 'oxigraph';
$config['sparql_endpoint'] = 'https://koetai.bionames.org/u/0000-0002-7101-9767/bhl/sparql';

//----------------------------------------------------------------------------------------
function get($url, $format = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	
	if ($format != '')
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: " . $format));	
	}
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
	
	curl_close($ch);
	
	return $response;
}

//----------------------------------------------------------------------------------------
function post($url, $data = '', $content_type = '', $accept = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  
	
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	
	$header = array();
	
	if ($content_type != '')
	{
		$header[] = "Content-type: " . $content_type;
	}
	if ($accept != '')
	{
		$header[] = "Accept: " . $accept;
	}
		
	if (count($header) != 0)
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
	}
		
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
	}
	else
	{
		$info = curl_getinfo($ch);
		$http_code = $info['http_code'];
	}
		
	curl_close($ch);
	
	return $response;
}


//----------------------------------------------------------------------------------------
// Return SPARQL result as the "bindings" array
function query($sparql)
{
	global $config;
	
	$result = array();
	
	$sparql_result = null;
	
	switch ($config['triplestore'])
	{
	
		case 'oxigraph':
		default:
			$sparql_result = oxigraph_query($sparql);
			break;
	}
	
	// return the list of bindings
		
	if ($sparql_result)
	{
		if (isset($sparql_result->results->bindings))
		{
			$result = $sparql_result->results->bindings;
		}
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
function oxigraph_query($sparql)
{
	global $config;
	
	$data = 'query=' . $sparql;
	
	$json = post(
		$config['sparql_endpoint'],
		$data,
		'application/x-www-form-urlencoded',
		'application/sparql-results+json'
		);
		
	$obj = json_decode($json);
	
	return $obj;
}

//----------------------------------------------------------------------------------------
// Return CONSTRUCT result as triples
function construct($sparql)
{
	global $config;
	
	$result = null;
	
	switch ($config['triplestore'])
	{
	
		case 'oxigraph':
		default:
			$result = oxigraph_construct($sparql);
			break;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
function oxigraph_construct($sparql)
{
	global $config;
	
	$data = 'query=' . $sparql;
	
	$triples = post(
		$config['sparql_endpoint'],
		$data,
		'application/x-www-form-urlencoded',
		'application/n-triples'
		);

	// Oxigraph returns triples that might not be unique so remove any duplicates	
	$triple_array = explode("\n", $triples);	
	$triple_array = array_unique($triple_array);
	
	$triples = join("\n", $triple_array);
	
	return $triples;
}

?>
