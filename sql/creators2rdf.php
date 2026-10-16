<?php

// Take extracted creators and generate RDF
// creators come from  export_creators.php

error_reporting(E_ALL);

ini_set('memory_limit', '-1');

require_once(dirname(dirname(__FILE__)) . '/shared.php');

$json = file_get_contents(dirname(__FILE__) . '/creators.json');

$obj = json_decode($json);

//print_r($obj);

$triples = [];

foreach ($obj as $creator)
{
	// full id
	$creator->id = $config['bhl'] . '/creator/' . $creator->id;

	// type
	$s = $creator->id;
	$p = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
	$o = 'https://schema.org/' . str_replace('schema:', '', $creator->rdf_class);		
	$triples[] = [$s, $p, $o];	

	// cleaned name
	$s = $creator->id;
	$p = 'https://schema.org/name';
	$o = '"' . nice_literal($creator->name) . '"';		
	$triples[] = [$s, $p, $o];	

	// name as it is in BHL
	if (isset($creator->disambiguatingDescription))
	{
		$s = $creator->id;
		$p = 'https://schema.org/disambiguatingDescription';
		$o = '"' . nice_literal($creator->disambiguatingDescription) . '"';		
		$triples[] = [$s, $p, $o];	
	}
	
	// structured parts of name
	if (isset($creator->parsed))
	{
		if ($creator->parsed->familyName != "")
		{
			$s = $creator->id;
			$p = 'https://schema.org/familyName';
			$o = '"' . nice_literal($creator->parsed->familyName) . '"';		
			$triples[] = [$s, $p, $o];	
		}

		if ($creator->parsed->givenName != "")
		{
			$s = $creator->id;
			$p = 'https://schema.org/givenName';
			$o = '"' . nice_literal($creator->parsed->givenName) . '"';		
			$triples[] = [$s, $p, $o];	
		}

		if ($creator->parsed->additionalName != "")
		{
			$s = $creator->id;
			$p = 'https://schema.org/additionalName';
			$o = '"' . nice_literal($creator->parsed->additionalName) . '"';		
			$triples[] = [$s, $p, $o];	
		}

		if ($creator->parsed->honorificPrefix != "")
		{
			$s = $creator->id;
			$p = 'https://schema.org/honorificPrefix';
			$o = '"' . nice_literal($creator->parsed->honorificPrefix) . '"';		
			$triples[] = [$s, $p, $o];	
		}

		if ($creator->parsed->honorificSuffix != "")
		{
			$s = $creator->id;
			$p = 'https://schema.org/honorificSuffix';
			$o = '"' . nice_literal($creator->parsed->honorificSuffix) . '"';		
			$triples[] = [$s, $p, $o];	
		}

	}
	
	// dates
	if (isset($creator->dates))
	{
		if ($creator->dates->birthDate != "")
		{
			create_date($triples, $creator->dates->birthDate, $creator->id, 'https://schema.org/birthDate');
		}

		if ($creator->dates->deathDate != "")
		{
			create_date($triples, $creator->dates->deathDate, $creator->id, 'https://schema.org/deathDate');
		}
	}	
	
	// variations on names
	if (isset($creator->alternatives))
	{
		foreach ($creator->alternatives as $alternateName)
		{
			$s = $creator->id;
			$p = 'https://schema.org/alternateName';
			$o = '"' . nice_literal($alternateName) . '"';		
			$triples[] = [$s, $p, $o];	
		}
	}
	
	// identifiers
	foreach ($creator->identifiers as $key => $values)
	{
		switch ($key)
		{
			case 'DLC':
				foreach ($values as $value)
				{
					$s = $creator->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('https://lccn.loc.gov/' . $value);
					$triples[] = [$s, $p, $o];		
				}
				break;
				
			case 'ORCID':
				foreach ($values as $value)
				{
					$s = $creator->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri($value);
					$triples[] = [$s, $p, $o];		
				}
				break;
						
				// VIAF's own RDF is HTTP
			case 'VIAF':
				foreach ($values as $value)
				{
					$s = $creator->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('http://viaf.org/viaf/' . $value);
					$triples[] = [$s, $p, $o];		
				}
				break;

			case 'Wikidata':
				foreach ($values as $value)
				{
					$s = $creator->id;
					$p = 'https://schema.org/sameAs';
					$o = nice_uri('http://www.wikidata.org/entity/' . $value);
					$triples[] = [$s, $p, $o];		
				}
				break;
				
			default:
				break;		
		}
	}
}

$output = dump_triples($triples);			
echo $output . "\n";




?>
