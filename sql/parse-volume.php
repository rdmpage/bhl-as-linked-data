<?php

//----------------------------------------------------------------------------------------
// Parse BHL volume/item "enumeration and chronology" strings into structured data.
//
// Output is modelled on CSL-JSON, but volume, issue and date are arrays because a single
// BHL item can cover several volumes, issues, or years.
//
//   $result = parse_volume('v.25:no.1-4 (2002-2003)');
//
//   $result->parsed             true if anything useful was extracted
//   $result->volume             array of volume strings
//   $result->part               array of part strings, when the volume is split twice
//                               over ("v.13:pt.5B:no.1" is volume 13, part 5B, issue 1)
//   $result->issue              array of issue strings
//   $result->{'collection-title'} array of series strings
//   $result->issued->{'date-parts'} CSL date-parts, e.g. [[2002],[2003]]
//   $result->note               array of leftover annotations ("Incomplete", "Botany", ...)
//   $result->supplement         true if the item is flagged as a supplement
//   $result->number             designation of a report series ("PA-146")
//   $result->parallel           array of designations, when the item is numbered more than
//                               one way at once (see below)
//
// Parallel numbering. Journals are often numbered twice over: a volume within a series and
// a volume within the whole run, or a volume that is also a range of whole numbers. BHL
// writes these joined by "=". volume and issue hold every value found, so that matching on
// either designation works, while parallel keeps them paired up:
//
//   parse_volume('v.102=ser.3:v.32 (1876)');
//
//   volume     ["102", "32"]
//   parallel   [ { volume: ["102"] },
//                { volume: ["32"], collection-title: ["3"] } ]
//
// Each designation is read the same way as the record as a whole, so once a "v." has been
// seen anywhere, a "no." is an issue wherever it appears:
//
//   parse_volume('v.21=no.61-62 (1908)');
//
//   volume     ["21"]
//   issue      ["61", "62"]
//   parallel   [ { volume: ["21"] }, { issue: ["61", "62"] } ]
//
// The parser works by tokenising the string rather than matching whole-string patterns,
// so unfamiliar combinations of familiar labels still parse.
//----------------------------------------------------------------------------------------

error_reporting(E_ALL);

//----------------------------------------------------------------------------------------
// Label vocabulary.
//
// rank 1 = series, 2 = volume-like, 3 = number-like, 4 = part-like (subdivision of a volume)
//
// The enumeration token with the lowest rank becomes the volume, everything else becomes
// an issue. Tokens joined by "=" are aliases for the same thing, so a volume-like token
// after "=" is another name for the same volume.
//----------------------------------------------------------------------------------------
function bhl_labels()
{
	static $labels = null;
	if ($labels !== null)
	{
		return $labels;
	}

	$labels = array();

	$groups = array(
		// series
		1 => array(
			'ser', 'series', 'serie', 'series', 'ser', 'sery',
			'nouvelle serie', 'nouv serie', 'nouv ser', 'nouvelle ser',
			'new ser', 'new series', 'newser',
			'n s', 'ns', 'n ser',
			'o s', 'os', 'old ser', 'old series',
			'neue folge', 'n f', 'nf', 'neue ser',
			'nuova serie', 'nuova ser',
			'ny serie', 'ny ser', 'nieuwe serie', 'nieuwe reeks',
			'reeks', 'folge', 'decade', 'dec ser',
			'segunda serie', 'nueva serie',
		),
		// volume-like
		2 => array(
			'v', 'vol', 'vols', 'volume', 'volumes', 'volumen', 'voL',
			't', 'tom', 'tome', 'tomes', 'tomo', 'tomos', 'tomus', 'tm',
			'd', 'dl', 'deel', 'delen',
			'bd', 'bnd', 'bde', 'band', 'bandchen', 'baend',
			'dil', 'svazek', 'sv',
			'jahrg', 'jahrgang', 'jg', 'jrg', 'jaarg', 'jaargang', 'jahr', 'jaar',
			'yr', 'year', 'yearbook',
			'an', 'ann', 'anno', 'annee', 'anne', 'annees', 'ano', 'anos', 'año', 'anio',
			'arg', 'aarg', 'argang', 'aargang',
			'per', 'period', 'periode',
			'kot', 'kotet',
			'book', 'bk',
			'sess', 'ses', 'session',
			'meeting', 'congress',
			'cilt',
			'god', 'godina',
			'tetu',
		),
		// number-like
		3 => array(
			'no', 'nos', 'nr', 'nrs', 'num', 'numb', 'number', 'numbers',
			'numero', 'numeros', 'núm', 'num', 'n',
			'issue', 'issues',
			'vyp', 'vypusk',
		),
		// part-like
		4 => array(
			'pt', 'pts', 'ptie', 'pties', 'part', 'parts', 'partie', 'parties', 'parte',
			'heft', 'hefte', 'hft', 'haft', 'h',
			'fasc', 'fasz', 'fascicle', 'fascicles', 'fascicule', 'fascicules', 'fascicolo',
			'livr', 'livraison', 'livraisons',
			'lfg', 'lf', 'lief', 'lieferung', 'lieferungen',
			'abt', 'abth', 'abteilung', 'abtheilung',
			'afd', 'afdeling',
			'sec', 'sect', 'sct', 'section', 'sectio',
			'sem', 'semestre', 'semester',
			'th', 'thl', 'theil', 'teil',
			'stuk', 'stukken',
			'div', 'division',
			'beiheft', 'beihefte', 'bh',
			'pars', 'fuzet', 'füzet',
			'quad', 'quaderno',
			'cuad', 'cuaderno',
			'caderno',
			'ergh', 'erganzungsheft', 'erganzungshefte',
		),
	);

	foreach ($groups as $rank => $words)
	{
		foreach ($words as $w)
		{
			$labels[bhl_label_key($w)] = $rank;
		}
	}

	return $labels;
}

//----------------------------------------------------------------------------------------
// Qualifiers: words that describe a part but carry no enumeration of their own.
function bhl_qualifiers()
{
	return array(
		'suppl' => 'suppl', 'supp' => 'suppl', 'supplement' => 'suppl',
		'supplements' => 'suppl', 'supplemento' => 'suppl', 'supplementband' => 'suppl',
		'suplemento' => 'suppl', 'supplementum' => 'suppl', 'erganzungsband' => 'suppl',
		'index' => 'index', 'indexes' => 'index', 'indices' => 'index',
		'register' => 'index', 'inhalt' => 'index',
		'atlas' => 'atlas', 'text' => 'text', 'texte' => 'text', 'textband' => 'text',
		'plates' => 'plates', 'pl' => 'plates', 'planches' => 'plates', 'taf' => 'plates',
		'tafeln' => 'plates', 'tab' => 'plates', 'tables' => 'plates',
		'maps' => 'maps', 'karten' => 'maps',
		'title page' => 'title page', 'titlepage' => 'title page',
		'cover' => 'cover', 'contents' => 'contents',
	);
}

//----------------------------------------------------------------------------------------
// Words that mean "this item is incomplete/odd" and belong in a note, not the enumeration.
function bhl_note_words()
{
	return array(
		'incomplete', 'lacks', 'lacking', 'wanting', 'missing', 'undated', 'n d', 'nd',
		'copy', 'copy 1', 'copy 2', 'reprint', 'facsim', 'facsimile', 'unbound',
	);
}

//----------------------------------------------------------------------------------------
// Normalise a label for dictionary lookup: lowercase, de-accented, punctuation stripped.
function bhl_label_key($w)
{
	$w = bhl_deaccent(bhl_lower($w));
	$w = str_replace(array('.', "'", '’'), '', $w);
	$w = preg_replace('/[^a-z0-9 ]+/', ' ', $w);
	$w = trim(preg_replace('/\s+/', ' ', $w));
	return $w;
}

//----------------------------------------------------------------------------------------
function bhl_lower($s)
{
	if (function_exists('mb_strtolower'))
	{
		return mb_strtolower($s, 'UTF-8');
	}
	return strtolower($s);
}

//----------------------------------------------------------------------------------------
function bhl_deaccent($s)
{
	$map = array(
		'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ā'=>'a','æ'=>'ae',
		'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
		'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
		'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o','ō'=>'o','œ'=>'oe',
		'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
		'ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y','ß'=>'ss','š'=>'s','ž'=>'z','č'=>'c','ř'=>'r',
	);
	return strtr($s, $map);
}

//----------------------------------------------------------------------------------------
// Month names in the languages BHL actually uses. Returns 1-12, or 0 if not a month.
function bhl_month($word)
{
	static $months = null;

	if ($months === null)
	{
		$months = array();

		$names = array(
			1  => array('january','jan','januar','janvier','janv','gennaio','genn','enero','ene','janeiro','januari','januarius','ianuarius','styczen','leden'),
			2  => array('february','febr','feb','februar','fevrier','fevr','febbraio','febbrero','febrero','fevereiro','februari','februarius'),
			3  => array('march','mar','marz','maerz','mrz','mars','marzo','marco','maart','mrt','martius','marti'),
			4  => array('april','apr','avril','avr','aprile','abril','aprilis'),
			5  => array('may','mai','maggio','magg','mayo','maio','mei','maius'),
			6  => array('june','jun','juni','juin','giugno','giugn','junio','junho','junius','iunius'),
			7  => array('july','jul','juli','juillet','juill','juil','luglio','lugl','julio','julho','julius','iulius'),
			8  => array('august','aug','aout','agosto','augustus','ago','augusti'),
			9  => array('september','sept','sep','septembre','settembre','sett','septiembre','setembro','september','septembris'),
			10 => array('october','oct','okt','oktober','octobre','ottobre','ott','octubre','outubro','octobris'),
			11 => array('november','nov','novembre','noviembre','novembro','novembris'),
			12 => array('december','dec','dez','dezember','decembre','dicembre','dic','diciembre','dezembro','decembris'),
		);

		foreach ($names as $n => $words)
		{
			foreach ($words as $w)
			{
				$months[$w] = $n;
			}
		}
	}

	$key = bhl_label_key($word);

	return isset($months[$key]) ? $months[$key] : 0;
}

//----------------------------------------------------------------------------------------
// CSL season codes: 1 = spring, 2 = summer, 3 = autumn, 4 = winter.
function bhl_season($word)
{
	static $seasons = null;

	if ($seasons === null)
	{
		$seasons = array();
		$names = array(
			1 => array('spring','printemps','primavera','fruhjahr','fruhling','voorjaar','lente'),
			2 => array('summer','ete','estate','verano','verao','sommer','zomer'),
			3 => array('autumn','fall','automne','autunno','otono','outono','herbst','najaar','herfst'),
			4 => array('winter','hiver','inverno','invierno','hibernal'),
		);
		foreach ($names as $n => $words)
		{
			foreach ($words as $w)
			{
				$seasons[$w] = $n;
			}
		}
	}

	$key = bhl_label_key($word);

	return isset($seasons[$key]) ? $seasons[$key] : 0;
}

//----------------------------------------------------------------------------------------
function is_roman($text)
{
	return preg_match('/^(?=[MDCLXVI])M{0,4}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/i', $text) === 1;
}

//----------------------------------------------------------------------------------------
function arabic($roman)
{
	$values = array('i'=>1,'v'=>5,'x'=>10,'l'=>50,'c'=>100,'d'=>500,'m'=>1000);

	$roman = strtolower($roman);
	$total = 0;
	$previous = 0;

	for ($i = strlen($roman) - 1; $i >= 0; $i--)
	{
		$c = $roman[$i];
		if (!isset($values[$c]))
		{
			return $roman;
		}
		$value = $values[$c];
		if ($value < $previous)
		{
			$total -= $value;
		}
		else
		{
			$total += $value;
			$previous = $value;
		}
	}

	return (string)$total;
}

//----------------------------------------------------------------------------------------
// Tidy up the raw string before parsing.
function bhl_normalise($text)
{
	$s = trim($text);

	// CSV exports often leave the quotes on
	if (strlen($s) > 1 && substr($s, 0, 1) == '"' && substr($s, -1) == '"')
	{
		$s = trim(substr($s, 1, -1));
	}

	// unicode dashes, quotes and spaces
	$s = str_replace(
		array("\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\x92", "\xe2\x88\x92", "\xc2\xa0", "\xe2\x80\x99"),
		array('-', '-', '-', '-', ' ', "'"),
		$s);

	$s = preg_replace('/\s+/u', ' ', $s);

	return trim($s);
}

//----------------------------------------------------------------------------------------
// Pull out "( ... )" groups. Contents are returned separately so they can be tried as
// chronology first and as enumeration second.
function bhl_extract_groups($s, $open, $close, &$groups)
{
	$out = '';
	$depth = 0;
	$buffer = '';
	$n = strlen($s);

	for ($i = 0; $i < $n; $i++)
	{
		$c = $s[$i];

		if ($c == $open)
		{
			if ($depth == 0)
			{
				$buffer = '';
			}
			else
			{
				$buffer .= $c;
			}
			$depth++;
		}
		else if ($c == $close && $depth > 0)
		{
			$depth--;
			if ($depth == 0)
			{
				$groups[] = trim($buffer);
				$out .= ' ';
			}
			else
			{
				$buffer .= $c;
			}
		}
		else if ($depth > 0)
		{
			$buffer .= $c;
		}
		else
		{
			$out .= $c;
		}
	}

	// unbalanced opener: keep whatever we buffered as a group
	if ($depth > 0 && trim($buffer) != '')
	{
		$groups[] = trim($buffer);
	}

	return trim(preg_replace('/\s+/', ' ', $out));
}

//----------------------------------------------------------------------------------------
// Does this string look like enumeration (a known label plus a number) rather than a date?
function bhl_looks_like_enumeration($s)
{
	$labels = bhl_labels();

	if (preg_match_all('/([a-zA-Z][a-zA-Z.\' ]*)\s*\.?\s*(?=[0-9IVXLC\[])/', $s, $m))
	{
		foreach ($m[1] as $word)
		{
			if (isset($labels[bhl_label_key($word)]))
			{
				return true;
			}
		}
	}

	return false;
}

//----------------------------------------------------------------------------------------
// Split a string into typed tokens.
//
// Token types: label, qualifier, num, roman, month, season, ordinal, range, alias, sep,
// word.
function bhl_tokenise($s)
{
	$labels = bhl_labels();
	$qualifiers = bhl_qualifiers();

	$tokens = array();
	$n = strlen($s);
	$i = 0;

	while ($i < $n)
	{
		$rest = substr($s, $i);

		if (preg_match('/^\s+/', $rest, $m))
		{
			$tokens[] = array('type' => 'sep', 'value' => ' ');
			$i += strlen($m[0]);
			continue;
		}

		if (preg_match('/^=/', $rest))
		{
			$tokens[] = array('type' => 'alias', 'value' => '=');
			$i++;
			continue;
		}

		if (preg_match('/^[:;]/', $rest, $m))
		{
			$tokens[] = array('type' => 'sep', 'value' => $m[0]);
			$i++;
			continue;
		}

		if (preg_match('/^,/', $rest))
		{
			$tokens[] = array('type' => 'sep', 'value' => ',');
			$i++;
			continue;
		}

		// a range separator, but only between two values
		if (preg_match('/^\s*(--|-|\/|\x{2013})\s*/u', $rest, $m))
		{
			$tokens[] = array('type' => 'range', 'value' => '-');
			$i += strlen($m[0]);
			continue;
		}

		// ordinal: 12th, 3rd, 1st, 22nd, 5e, 2d
		if (preg_match('/^(\d+)(st|nd|rd|th|d|e|er|eme|ste|te)\b\.?/i', $rest, $m))
		{
			$tokens[] = array('type' => 'ordinal', 'value' => $m[1]);
			$i += strlen($m[0]);
			continue;
		}

		// number, optionally with a letter suffix (12a) or a decimal part (1.2)
		if (preg_match('/^(\d+)([a-zA-Z](?![a-zA-Z]))?/', $rest, $m))
		{
			$tokens[] = array('type' => 'num', 'value' => $m[1], 'suffix' => isset($m[2]) ? $m[2] : '');
			$i += strlen($m[0]);
			continue;
		}

		// a word (possibly a multi-word label such as "new ser.")
		if (preg_match('/^([a-zA-Z\x{80}-\x{FFFF}]+\.?)/u', $rest, $m))
		{
			$word = $m[0];
			$len  = strlen($m[0]);

			// greedily extend over following words while that makes a longer known label
			$best = null;
			$bestLen = 0;
			$probe = $word;
			$probeLen = $len;

			if (isset($labels[bhl_label_key($probe)]) || isset($qualifiers[bhl_label_key($probe)]))
			{
				$best = $probe;
				$bestLen = $probeLen;
			}

			$tail = substr($rest, $probeLen);
			$extra = 0;
			while ($extra < 2 && preg_match('/^\s*([a-zA-Z\x{80}-\x{FFFF}]+\.?)/u', $tail, $m2))
			{
				$probe .= ' ' . $m2[1];
				$probeLen += strlen($m2[0]);
				$tail = substr($tail, strlen($m2[0]));
				$extra++;

				if (isset($labels[bhl_label_key($probe)]) || isset($qualifiers[bhl_label_key($probe)]))
				{
					$best = $probe;
					$bestLen = $probeLen;
				}
			}

			if ($best !== null)
			{
				// keep the label as it was written, not as the probe rebuilt it
				$word = substr($rest, 0, $bestLen);
				$len  = $bestLen;
			}

			$key = bhl_label_key($word);

			if (isset($labels[$key]))
			{
				$tokens[] = array('type' => 'label', 'value' => $word, 'rank' => $labels[$key]);
			}
			else if (isset($qualifiers[$key]))
			{
				$tokens[] = array('type' => 'qualifier', 'value' => $qualifiers[$key]);
			}
			else if (($m3 = bhl_month($word)) > 0)
			{
				$tokens[] = array('type' => 'month', 'value' => $m3);
			}
			else if (($m4 = bhl_season($word)) > 0)
			{
				$tokens[] = array('type' => 'season', 'value' => $m4);
			}
			else if (is_roman(rtrim($word, '.')) && preg_match('/^[IVXLCDM]{2,}\.?$/', $word))
			{
				$tokens[] = array('type' => 'roman', 'value' => rtrim($word, '.'));
			}
			else if (preg_match('/^[A-Za-z]\.?$/', $word))
			{
				// a single letter is a designation ("sect.C"), not a roman numeral
				$tokens[] = array('type' => 'letter', 'value' => rtrim($word, '.'));
			}
			else
			{
				$tokens[] = array('type' => 'word', 'value' => $word);
			}

			$i += $len;
			continue;
		}

		// anything else is punctuation we don't care about
		$tokens[] = array('type' => 'word', 'value' => $rest[0]);
		$i++;
	}

	return $tokens;
}

//----------------------------------------------------------------------------------------
// Is this number plausibly a year?
function bhl_is_year($v)
{
	return preg_match('/^\d{4}$/', $v) && $v >= 1450 && $v <= 2100;
}

//----------------------------------------------------------------------------------------
// Parse a chronology fragment such as "1919", "1919-20", "1919:May-Dec.", "May 1919",
// "Spring 1921", "1919:May 15-1920:Jan. 3".
//
// Returns an array of date points: array('y' => .., 'm' => .., 'd' => .., 'season' => ..)
// Returns an empty array if the fragment is not a date.
function bhl_parse_chronology($s)
{
	$s = trim($s);

	if ($s == '')
	{
		return array();
	}

	// "1877 [i.e. 1878]" and friends: prefer the correction
	if (preg_match('/i\.?\s*e\.?\s*([0-9]{4})/i', $s, $m))
	{
		$s = $m[1];
	}

	// strip anything that is clearly not chronology
	$s = preg_replace('/\b(published|publ|printed|issued|dated|circa|ca|approx)\b\.?/i', ' ', $s);

	$tokens = bhl_tokenise($s);

	// a fragment containing an enumeration label is not a date
	foreach ($tokens as $t)
	{
		if ($t['type'] == 'label')
		{
			return array();
		}
	}

	// split into segments on range separators
	$segments = array(array());
	foreach ($tokens as $t)
	{
		if ($t['type'] == 'range')
		{
			$segments[] = array();
		}
		else if ($t['type'] == 'sep' && $t['value'] == ',')
		{
			$segments[] = array();
		}
		else
		{
			$segments[count($segments) - 1][] = $t;
		}
	}

	$points = array();
	$previous = null;

	foreach ($segments as $segment)
	{
		$point = array('y' => null, 'm' => null, 'd' => null, 'season' => null);
		$smallNumbers = array();
		$useful = false;

		foreach ($segment as $t)
		{
			switch ($t['type'])
			{
				case 'num':
					if (bhl_is_year($t['value']))
					{
						if ($point['y'] === null)
						{
							$point['y'] = (int)$t['value'];
							$useful = true;
						}
					}
					else
					{
						$smallNumbers[] = $t['value'];
					}
					break;

				case 'month':
					if ($point['m'] === null)
					{
						$point['m'] = $t['value'];
						$useful = true;
					}
					break;

				case 'season':
					if ($point['season'] === null)
					{
						$point['season'] = $t['value'];
						$useful = true;
					}
					break;

				case 'roman':
					// roman month numbers are common in European serials, e.g. "1930:IV"
					$v = (int)arabic($t['value']);
					if ($point['m'] === null && $v >= 1 && $v <= 12)
					{
						$point['m'] = $v;
						$useful = true;
					}
					break;

				default:
					break;
			}
		}

		// a bare 1 or 2 digit number is a day if we have a month, otherwise it may be the
		// tail of a truncated year range ("1919-20")
		foreach ($smallNumbers as $v)
		{
			if ($point['m'] !== null && $point['d'] === null && $v >= 1 && $v <= 31)
			{
				$point['d'] = (int)$v;
				$useful = true;
			}
			else if ($point['y'] === null && $previous !== null && $previous['y'] !== null
				&& strlen($v) <= 2 && $previous['m'] === null && $previous['d'] === null
				&& bhl_expand_short_year($previous['y'], $v) - $previous['y'] <= 10)
			{
				// "1919-20" -> 1920
				$point['y'] = bhl_expand_short_year($previous['y'], $v);
				$useful = true;
			}
			else if ($point['y'] === null && $previous !== null && $previous['d'] !== null
				&& $v >= 1 && $v <= 31)
			{
				// "1919:May 15-20" -> day 20
				$point['d'] = (int)$v;
				$useful = true;
			}
		}

		if (!$useful)
		{
			continue;
		}

		// inherit the year from the start of a range
		if ($point['y'] === null && $previous !== null && $previous['y'] !== null)
		{
			$point['y'] = $previous['y'];
		}

		$points[] = $point;
		$previous = $point;
	}

	// the year is sometimes only given once, at the end: "(Jul-Aug:2016)"
	$year = null;
	for ($i = count($points) - 1; $i >= 0; $i--)
	{
		if ($points[$i]['y'] !== null)
		{
			$year = $points[$i]['y'];
		}
		else if ($year !== null)
		{
			$points[$i]['y'] = $year;
		}
	}

	return $points;
}

//----------------------------------------------------------------------------------------
function bhl_expand_short_year($fullYear, $short)
{
	$short = (int)$short;
	$century = (int)floor($fullYear / 100) * 100;
	$year = $century + $short;

	if ($year < $fullYear)
	{
		$year += 100;
	}

	return $year;
}

//----------------------------------------------------------------------------------------
// Turn a list of date points into CSL date-parts.
function bhl_date_parts($points)
{
	$parts = array();

	foreach ($points as $p)
	{
		$part = array();

		if ($p['y'] !== null)
		{
			$part[] = (int)$p['y'];

			if ($p['m'] !== null)
			{
				$part[] = (int)$p['m'];

				if ($p['d'] !== null)
				{
					$part[] = (int)$p['d'];
				}
			}
		}

		if (count($part) > 0)
		{
			$parts[] = $part;
		}
	}

	// collapse an identical pair ("1919-1919")
	if (count($parts) == 2 && $parts[0] === $parts[1])
	{
		array_pop($parts);
	}

	return array_slice($parts, 0, 2);
}

//----------------------------------------------------------------------------------------
// Expand "1-4" into 1,2,3,4. Ranges that are not numeric, or absurdly long, are left as
// the two endpoints.
function bhl_expand_range($from, $to)
{
	if (!is_numeric($from) || !is_numeric($to))
	{
		return array($from, $to);
	}

	// anything this long is corrupt data ("900-1987"), not a range of issues
	if ($to <= $from || ($to - $from) > 500)
	{
		return array($from, $to);
	}

	$values = array();
	for ($i = (int)$from; $i <= (int)$to; $i++)
	{
		$values[] = (string)$i;
	}

	return $values;
}

//----------------------------------------------------------------------------------------
// "1899", "1907" -> 1899, 1900, ... 1907. Year ranges are the enumeration of the item, so
// they are expanded more generously than issue ranges.
function bhl_expand_year_range($years)
{
	$from = (int)$years[0];
	$to   = (int)$years[count($years) - 1];

	if ($to <= $from || ($to - $from) > 150)
	{
		return array_values(array_unique($years));
	}

	$values = array();
	for ($y = $from; $y <= $to; $y++)
	{
		$values[] = (string)$y;
	}

	return $values;
}

//----------------------------------------------------------------------------------------
// Walk the tokens of the enumeration part and collect labelled values.
//
// Returns array of array('rank' => .., 'label' => .., 'values' => array(), 'alias' => bool,
// 'sibling' => bool)
function bhl_collect_enumeration($tokens, &$bareYears, &$qualifiers, &$notes, &$yearFirst, &$prose, &$titles)
{
	$items = array();
	$yearFirst = false;
	$prose = false;
	$titles = array();

	$current = null;          // item being filled
	$pendingLabel = null;     // label seen but not yet given a value
	$pendingRange = false;    // last token was a range separator
	$aliasNext = false;       // last separator was "="
	$siblingNext = false;     // last separator was ";", i.e. a separate item
	$lastWasValue = false;
	$unknownLabel = false;    // last word was not a label we recognise
	$aliasGroup = false;      // we are inside an "=" alias group
	$lastWasBareYear = false; // last value was an unlabelled year
	$group = 0;               // designations are separated by "="
	$pendingTitle = array();  // unrecognised words, which may name a sub-series

	$flush = function() use (&$current, &$items)
	{
		if ($current !== null && count($current['values']) > 0)
		{
			$items[] = $current;
		}
		$current = null;
	};

	// "n.F. Bd.17" - a series label that never gets a number of its own still names the
	// designation it introduces
	$stashSeries = function() use (&$pendingLabel, &$pendingTitle)
	{
		if ($pendingLabel !== null && $pendingLabel['rank'] == 1)
		{
			$pendingTitle[] = trim($pendingLabel['value']);
		}
	};

	$n = count($tokens);

	for ($i = 0; $i < $n; $i++)
	{
		$t = $tokens[$i];

		switch ($t['type'])
		{
			case 'label':
				// "no. 1-no. 2" - the label is repeated at both ends of one range, so
				// keep filling the item we already have
				if ($pendingRange && $current !== null && count($current['values']) > 0)
				{
					break;
				}

				// "31.Jahr." - the number came first
				if ($pendingLabel === null && $current !== null && $current['label'] === null
					&& count($current['values']) > 0 && !bhl_next_is_value($tokens, $i))
				{
					$current['label'] = $t['value'];
					$current['rank'] = $t['rank'];
					break;
				}

				$stashSeries();

				if (count($pendingTitle) > 0)
				{
					$titles[$group][] = trim(implode(' ', $pendingTitle), ' ,');
					$pendingTitle = array();
				}

				$flush();
				$pendingLabel = $t;
				$pendingRange = false;
				$lastWasValue = false;
				$unknownLabel = false;
				break;

			case 'qualifier':
				$qualifiers[] = $t['value'];
				$lastWasValue = false;
				break;

			case 'alias':
				$flush();
				$group++;
				$pendingTitle = array();
				$aliasNext = true;
				$aliasGroup = true;
				$siblingNext = false;
				$pendingLabel = null;
				$pendingRange = false;
				$lastWasValue = false;
				$unknownLabel = false;
				break;

			case 'sep':
				if ($t['value'] == ':' || $t['value'] == ';')
				{
					$flush();
					$siblingNext = ($t['value'] == ';');
					if ($t['value'] == ';')
					{
						$aliasGroup = false;
						$unknownLabel = false;
					}
					$stashSeries();
					$aliasNext = $aliasGroup;
					$pendingLabel = null;
					$pendingRange = false;
					$lastWasValue = false;

					if ($t['value'] != ':')
					{
						$pendingTitle = array();
					}
				}
				else if ($t['value'] == ',')
				{
					// a comma either continues a list of values or separates two items
					if (!bhl_next_is_bare_value($tokens, $i))
					{
						$stashSeries();
						$flush();
						$pendingLabel = null;
						$siblingNext = false;
					}
					$pendingRange = false;
					$lastWasValue = false;
				}
				else
				{
					// whitespace: only ends an item if a new label follows
					if (bhl_next_is_label($tokens, $i))
					{
						$stashSeries();
						$flush();
						$pendingLabel = null;
					}
					$lastWasValue = false;
				}
				break;

			case 'range':
				// whitespace clears $lastWasValue, so ask the item directly - otherwise
				// "Vol 26 - Vol 27" would not be read as a range
				$pendingRange = ($current !== null && count($current['values']) > 0)
					|| $lastWasBareYear;
				break;

			case 'ordinal':
			case 'roman':
			case 'num':
				$value = $t['value'];

				if ($t['type'] == 'roman')
				{
					$value = arabic($t['value']);
				}

				if ($t['type'] == 'num' && isset($t['suffix']) && $t['suffix'] != '')
				{
					$value = $value . $t['suffix'];
				}

				// the tail of an unlabelled year range: "1919-20", "1863-1913"
				if ($pendingRange && $lastWasBareYear && $t['type'] == 'num'
					&& count($bareYears) > 0)
				{
					$previousYear = (int)$bareYears[count($bareYears) - 1];

					if (bhl_is_year($value))
					{
						$bareYears[] = $value;
						$pendingRange = false;
						break;
					}
					else if (strlen($value) <= 2)
					{
						$shortYear = bhl_expand_short_year($previousYear, $value);

						if ($shortYear - $previousYear <= 10)
						{
							$bareYears[] = (string)$shortYear;
							$pendingRange = false;
							break;
						}
					}
				}

				// a bare four digit year with no label is chronology, not enumeration
				if ($pendingLabel === null && !$pendingRange && !$lastWasValue
					&& $t['type'] == 'num' && bhl_is_year($value))
				{
					$flush();
					if (count($bareYears) == 0 && count($items) == 0 && $current === null
						&& !$prose)
					{
						$yearFirst = true;
					}
					$bareYears[] = $value;
					$lastWasValue = true;
					$lastWasBareYear = true;
					break;
				}

				// a value introduced by a word we don't recognise tells us nothing
				if ($unknownLabel && $pendingLabel === null)
				{
					$lastWasValue = false;
					break;
				}

				if ($pendingRange && $current !== null && count($current['values']) > 0)
				{
					$from = $current['values'][count($current['values']) - 1];
					array_pop($current['values']);
					$current['values'] = array_merge($current['values'], bhl_expand_range($from, $value));
					$pendingRange = false;
					$lastWasValue = true;
					break;
				}

				if ($current === null)
				{
					$current = array(
						'rank'  => $pendingLabel === null ? ($t['type'] == 'ordinal' ? 2 : 3) : $pendingLabel['rank'],
						'label' => $pendingLabel === null ? null : $pendingLabel['value'],
						'values' => array(),
						'alias' => $aliasNext,
						'sibling' => $siblingNext,
						'group' => $group,
					);
					$pendingLabel = null;
					$aliasNext = false;
					$siblingNext = false;
				}

				$current['values'][] = $value;
				$lastWasValue = true;
				$lastWasBareYear = false;
				$pendingTitle = array();
				break;

			case 'month':
			case 'season':
				// "VOL FEB 1931" - a label followed by a month is not labelling a number
				$flush();
				$pendingLabel = null;
				$pendingRange = false;
				$lastWasValue = false;
				break;

			case 'letter':
			case 'word':
				$word = bhl_label_key($t['value']);
				if ($word != '' && in_array($word, bhl_note_words()))
				{
					$notes[] = trim($t['value'], ' .');
				}
				else if ($t['type'] == 'letter' && $pendingLabel !== null)
				{
					// a designation such as "sect.C" - the letter is the value, unless it
					// is one of the roman numerals serials actually use
					$letter = $t['value'];
					if (preg_match('/^[IVXivx]$/', $letter))
					{
						$letter = arabic($letter);
					}

					$current = array(
						'rank'  => $pendingLabel['rank'],
						'label' => $pendingLabel['value'],
						'values' => array($letter),
						'alias' => $aliasNext,
						'sibling' => $siblingNext,
						'group' => $group,
					);
					$pendingLabel = null;
					$aliasNext = false;
					$siblingNext = false;
					$flush();
					$lastWasValue = false;
					break;
				}
				else if ($t['type'] == 'word' && preg_match('/[a-z]/i', $word))
				{
					// an unrecognised word labels whatever follows it, so we can't use it
					// as a value - but it may be the name of a sub-series
					$flush();
					$pendingLabel = null;
					$unknownLabel = true;
					$prose = true;
					$pendingTitle[] = trim($t['value']);
				}
				else if ($t['type'] == 'word' && count($pendingTitle) > 0
					&& preg_match('/^[&+\/]$/', trim($t['value'])))
				{
					// "Physics & Chemistry"
					$pendingTitle[] = trim($t['value']);
				}
				$lastWasValue = false;
				break;

			default:
				break;
		}
	}

	$flush();

	return $items;
}

//----------------------------------------------------------------------------------------
function bhl_next_is_label($tokens, $i)
{
	$n = count($tokens);
	for ($j = $i + 1; $j < $n; $j++)
	{
		if ($tokens[$j]['type'] == 'sep' && $tokens[$j]['value'] == ' ')
		{
			continue;
		}
		return $tokens[$j]['type'] == 'label';
	}
	return false;
}

//----------------------------------------------------------------------------------------
function bhl_next_is_value($tokens, $i)
{
	$n = count($tokens);
	for ($j = $i + 1; $j < $n; $j++)
	{
		if ($tokens[$j]['type'] == 'sep' && $tokens[$j]['value'] == ' ')
		{
			continue;
		}
		return in_array($tokens[$j]['type'], array('num', 'roman', 'ordinal'));
	}
	return false;
}

//----------------------------------------------------------------------------------------
// After a comma, is the next token a bare number (a list continuation) rather than the
// start of a new labelled item or a year?
function bhl_next_is_bare_value($tokens, $i)
{
	$n = count($tokens);
	for ($j = $i + 1; $j < $n; $j++)
	{
		if ($tokens[$j]['type'] == 'sep' && $tokens[$j]['value'] == ' ')
		{
			continue;
		}
		if ($tokens[$j]['type'] != 'num')
		{
			return false;
		}
		return !bhl_is_year($tokens[$j]['value']);
	}
	return false;
}

//----------------------------------------------------------------------------------------
// Reduce one designation's items to volume, issue and series. The item with the strongest
// label is the volume ("v.", "Bd." and friends beat "no.", which beats "pt."), everything
// else is an issue. This is applied to the record as a whole, and again to each parallel
// designation on its own.
function bhl_designation($items, $titles, $bestRank = null)
{
	$designation = array('volume' => array(), 'part' => array(), 'issue' => array(),
		'collection-title' => $titles);

	// $bestRank is fixed by the caller when the record as a whole already has a volume: a
	// "no." beside a "v." is an issue, even in a designation of its own.
	if ($bestRank === null)
	{
		foreach ($items as $item)
		{
			if ($item['rank'] == 1)
			{
				continue;
			}

			if ($bestRank === null || $item['rank'] < $bestRank)
			{
				$bestRank = $item['rank'];
			}
		}
	}

	$haveVolume = false;
	$subdivisions = array();
	$blocks = array();

	foreach ($items as $item)
	{
		if ($item['rank'] == 1)
		{
			$designation['collection-title'] = array_merge($designation['collection-title'], $item['values']);
			continue;
		}

		// the first item of the strongest rank is the volume, and so is anything aliased to
		// it ("Jahrg.6=Bd.31") or listed beside it ("v.25:no.1-4;v.26:no.1-4"). Anything
		// else is a subdivision of the volume ("Jahrg.51:Bd.2").
		$isVolume = false;

		if ($item['rank'] == $bestRank)
		{
			if (!$haveVolume)
			{
				$isVolume = true;
			}
			else if ($item['alias'] || $item['sibling'])
			{
				$isVolume = true;
			}
		}

		if ($isVolume)
		{
			$designation['volume'] = array_merge($designation['volume'], $item['values']);
			$haveVolume = true;

			// each volume gets its own run of subdivisions, so that the two "no." in
			// "v.25:no.1-4;v.26:no.1-4" are not read as a hierarchy
			if (count($subdivisions) > 0)
			{
				$blocks[] = $subdivisions;
				$subdivisions = array();
			}
		}
		else
		{
			$subdivisions[] = $item;
		}
	}

	if (count($subdivisions) > 0)
	{
		$blocks[] = $subdivisions;
	}

	// One subdivision is the issue, whatever it is labelled: "v.28:pt.3-4" is issue 3-4.
	//
	// Two or more means the volume is split twice over, as in "v.13:pt.5B:no.1" or the
	// German "Bd.6:Abt.4:T.2", and the level between volume and issue is CSL "part". A
	// number-like label ("no.") is the issue wherever it sits, because it can be either
	// coarser ("v.13:pt.2:no.2") or finer ("v.37:no.2:fasc.5-8") than its neighbour;
	// otherwise the innermost subdivision is the issue.
	foreach ($blocks as $block)
	{
		if (count($block) == 1)
		{
			$designation['issue'] = array_merge($designation['issue'], $block[0]['values']);
			continue;
		}

		$issueAt = null;

		foreach ($block as $k => $item)
		{
			if ($item['rank'] == 3)
			{
				$issueAt = $k;
				break;
			}
		}

		if ($issueAt === null)
		{
			$issueAt = count($block) - 1;
		}

		foreach ($block as $k => $item)
		{
			$field = ($k == $issueAt) ? 'issue' : 'part';
			$designation[$field] = array_merge($designation[$field], $item['values']);
		}
	}

	foreach ($designation as $k => $v)
	{
		$designation[$k] = array_values(array_unique($v));
	}

	$designation['rank'] = $bestRank;

	return $designation;
}

//----------------------------------------------------------------------------------------
// Square brackets wrap two different things: real enumeration, as in "[v.12]=[no.45-48]",
// and annotations, as in "(1913) [Botany]". Enumeration is unwrapped where it stands so
// that the surrounding separators still make sense; anything else is handed to the caller
// to try as chronology, and failing that as a note.
function bhl_resolve_brackets($s, &$groups)
{
	$out = '';
	$offset = 0;

	while (preg_match('/\[([^\[\]]*)\]/', $s, $m, PREG_OFFSET_CAPTURE, $offset))
	{
		$start = $m[0][1];
		$content = $m[1][0];

		$out .= substr($s, $offset, $start - $offset);
		$offset = $start + strlen($m[0][0]);

		// a date inside the brackets, e.g. "[1877 (i.e. 1878)]"
		$inner = array();
		$content = bhl_extract_groups($content, '(', ')', $inner);
		$groups = array_merge($groups, $inner);

		$bareNumbers = preg_match('/^([0-9]+|[IVXLC]+)(\s*-\s*([0-9]+|[IVXLC]+))?$/i', $content, $bm)
			&& !bhl_is_year($bm[1]);

		if (bhl_looks_like_enumeration($content) || $bareNumbers)
		{
			// Bracketed enumeration that follows a complete designation is another way of
			// citing the same item, e.g. "v.102=ser.3:v.32 [no.6206-6271]", so mark it as
			// a parallel designation. At the start, or straight after a separator, it is
			// simply part of the designation being built.
			if (preg_match('/[^\s=:;,]\s*$/', $out))
			{
				$out .= '=';
			}

			$out .= $content;
		}
		else
		{
			$groups[] = $content;
			$out .= ' ';
		}
	}

	$out .= substr($s, $offset);

	return trim(preg_replace('/\s+/', ' ', $out));
}

//----------------------------------------------------------------------------------------
// Main entry point.
function parse_volume($text)
{
	$obj = new stdclass;
	$obj->text = $text;
	$obj->parsed = false;

	$s = bhl_normalise($text);

	if ($s == '')
	{
		return $obj;
	}

	$notes = array();
	$qualifiers = array();

	// chronology is normally in parentheses, annotations in square brackets
	$parens = array();
	$s = bhl_extract_groups($s, '(', ')', $parens);

	$s = bhl_resolve_brackets($s, $parens);

	// each parenthesised group is chronology if it parses as a date, enumeration if not
	$points = array();
	foreach ($parens as $p)
	{
		foreach (explode(';', $p) as $chunk)
		{
			$chunkPoints = bhl_parse_chronology($chunk);

			if (count($chunkPoints) > 0)
			{
				if (count($points) == 0)
				{
					$points = $chunkPoints;
				}
				else if (bhl_date_parts($chunkPoints) !== bhl_date_parts($points))
				{
					$notes[] = trim($chunk);
				}
			}
			else if (bhl_looks_like_enumeration($chunk))
			{
				$s .= ' ' . $chunk;
			}
			else if (trim($chunk) != '')
			{
				$notes[] = trim($chunk);
			}
		}
	}

	// now the enumeration
	$bareYears = array();
	$tokens = bhl_tokenise($s);
	$yearFirst = false;
	$prose = false;
	$titles = array();
	$items = bhl_collect_enumeration($tokens, $bareYears, $qualifiers, $notes, $yearFirst, $prose, $titles);

	// months and seasons can appear outside parentheses ("1949:May", "Spring 1921")
	if (count($points) == 0)
	{
		$inline = bhl_parse_chronology($s);
		if (count($inline) > 0 && ($inline[0]['m'] !== null || $inline[0]['season'] !== null))
		{
			$points = $inline;
		}
	}

	// bare years belong to the chronology
	if (count($points) == 0 && count($bareYears) > 0)
	{
		$points = bhl_parse_chronology(implode('-', $bareYears));
	}
	else if (count($points) > 0 && $points[0]['y'] === null && count($bareYears) > 0)
	{
		// "1901:v.2 (May-Dec.)" - the year is outside the parentheses
		$year = (int)$bareYears[0];
		foreach ($points as $k => $p)
		{
			if ($points[$k]['y'] === null)
			{
				$points[$k]['y'] = $year;
			}
		}
	}

	// the record as a whole
	$overall = bhl_designation($items, array());

	$volume   = $overall['volume'];
	$part     = $overall['part'];
	$issue    = $overall['issue'];
	$series   = $overall['collection-title'];
	$bestRank = $overall['rank'];

	// Parallel numbering: the same item carries more than one designation, separated by
	// "=", e.g. "v.102=ser.3:v.32" is volume 102 of the whole run and volume 32 of the
	// third series. The flat volume/issue above keep every value, these keep the pairing.
	$parallel = array();
	$groups = array();

	foreach ($items as $item)
	{
		$groups[$item['group']][] = $item;
	}

	foreach ($titles as $group => $words)
	{
		if (!isset($groups[$group]))
		{
			$groups[$group] = array();
		}
	}

	if (count($groups) > 1)
	{
		ksort($groups);

		foreach ($groups as $group => $groupItems)
		{
			$designation = bhl_designation($groupItems,
				isset($titles[$group]) ? $titles[$group] : array(), $bestRank);

			$entry = new stdclass;

			foreach (array('volume', 'part', 'issue', 'collection-title') as $field)
			{
				if (count($designation[$field]) > 0)
				{
					$entry->{$field} = $designation[$field];
				}
			}

			if (count(get_object_vars($entry)) > 0)
			{
				$parallel[] = $entry;
			}
		}
	}

	// Serials enumerated by year of publication ("1886:pt.1", "1899-1907"). The year is the
	// volume unless something with a volume label (rank 2) outranks it.
	if (count($bareYears) > 0 && (!$prose || $yearFirst)
		&& ($bestRank === null || ($yearFirst && $bestRank > 2)))
	{
		$years = bhl_expand_year_range($bareYears);

		if (count($volume) > 0)
		{
			$issue = array_merge($volume, $issue);
		}

		$volume = $years;
	}

	// "(1983)" - the whole designation is a date, so the year is also the volume
	if (count($volume) == 0 && count($issue) == 0 && count($part) == 0 && !$prose && trim($s) == ''
		&& count($points) > 0 && $points[0]['y'] !== null)
	{
		$years = array();
		foreach ($points as $point)
		{
			if ($point['y'] !== null)
			{
				$years[] = (string)$point['y'];
			}
		}
		$volume = bhl_expand_year_range($years);
	}

	$volume = array_values(array_unique($volume));
	$part   = array_values(array_unique($part));
	$issue  = array_values(array_unique($issue));
	$series = array_values(array_unique($series));

	if (count($volume) > 0)
	{
		$obj->volume = $volume;
	}

	if (count($part) > 0)
	{
		$obj->part = $part;
	}

	if (count($issue) > 0)
	{
		$obj->issue = $issue;
	}

	if (count($series) > 0)
	{
		$obj->{'collection-title'} = $series;
	}

	if (count($parallel) > 1)
	{
		$obj->parallel = $parallel;
	}

	$dateParts = bhl_date_parts($points);

	if (count($dateParts) > 0)
	{
		$obj->issued = new stdclass;
		$obj->issued->{'date-parts'} = $dateParts;

		foreach ($points as $p)
		{
			if ($p['season'] !== null)
			{
				$obj->issued->season = $p['season'];
				break;
			}
		}
	}

	$qualifiers = array_values(array_unique($qualifiers));

	if (in_array('suppl', $qualifiers))
	{
		$obj->supplement = true;
	}

	foreach ($qualifiers as $q)
	{
		if ($q != 'suppl')
		{
			$notes[] = $q;
		}
	}

	$notes = array_values(array_unique(array_filter($notes, function($v) { return trim($v) != ''; })));

	if (count($notes) > 0)
	{
		$obj->note = $notes;
	}

	$obj->parsed = (count($volume) > 0 || count($part) > 0 || count($issue) > 0
		|| count($dateParts) > 0 || count($series) > 0);

	// Report and archive series are designated by a code rather than a volume, e.g.
	// "PA-146", "Technical note-5", "NE-INF-1-2". Record the code as CSL "number".
	if (!$obj->parsed && $s != '' && strlen($s) <= 40
		&& preg_match('/[0-9]/', $s)
		&& preg_match('/^[A-Za-z0-9&][A-Za-z0-9 .,&\/-]*$/', $s))
	{
		$obj->number = $s;
		$obj->parsed = true;
	}

	return $obj;
}

?>
