<?php

//----------------------------------------------------------------------------------------
// Turning BHL creator strings into names you can actually use.
//
// BHL creator strings are MARC-style name headings, not names. A single string can
// carry the name, life dates, a qualifier on those dates, an expansion of initials,
// and an honorific, all run together:
//
//   "Kindberg, N. C. (Nils Conrad), 1832-1912"
//   "Scot, Michael, ca. 1175-ca"
//   "Bowman, William, Sir"
//   "Nicolaus, Salernitanus, active 12th century-"
//
// parse_creator_name() pulls those apart into a display name, a given/family split,
// and the set of alternative labels the heading implies - "N. C. Kindberg" and
// "Nils Conrad Kindberg" are the same person written two ways, and both need to be
// findable. The components are named for the schema.org properties they map onto, which
// is the point: name, familyName, givenName, additionalName, honorificPrefix,
// honorificSuffix, birthDate and deathDate all carry straight through to schema:, and
// alternatives -> skos:altLabel.
//
// Corporate and meeting headings are left alone apart from cleanup. Their dates are
// part of the name, not biography - "Centennial Exhibition (1876 : Philadelphia, Pa.)"
// is meaningless with the parenthesis stripped.

//----------------------------------------------------------------------------------------
// Date qualifiers, as BHL writes them. "c" with no dot is included because "c1853-1954"
// occurs; it can only match here when followed by digits, so it can't bite a name.
define('CREATOR_QUAL', '(?:fl|b|d|ca|c|circa|approximately|active)\.?');

// A trailing life-date expression, built up from pieces because BHL writes dates
// every way a cataloguer ever has: "1743-1828", "1832-", "-1910", "ca. 1175", "b. 1854",
// "1073 or 1074-1164", "1859 May 13-1921", "June 6, 1881-", "18th cent.",
// "active 12th century-", "234 B.C.-149 B.C.", "active 1840s-", "1543/4-".
//
// Anchored at the end and required to contain digits, so it can never swallow a name.
define('CREATOR_MONTH', '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?');

// an era marker, "B.C." / "A.D."
define('CREATOR_ERA', '(?:\s*[AB]\.?[CD]\.?)?');

// One year, with everything that can hang off it: an era, a month and day (either
// order, sometimes parenthesised), a decade "1840s", a split year "1543/4", and the
// "or" BHL uses when the year itself is uncertain.
define('CREATOR_YEAR',
	'\d{1,4}\?{0,2}(?:s|\/\d{1,4})?' . CREATOR_ERA .
	'(?:[\s,]+' . CREATOR_MONTH . '(?:\s+\d{1,2})?)?' .
	'(?:\s*\(' . CREATOR_MONTH . '\s*\d{0,2}\))?' .
	'(?:\s+or\s*\d{0,4}\?{0,2})?');

// One century, "18th cent." / "12th century B.C."
define('CREATOR_CENTURY', '\d{1,2}(?:st|nd|rd|th|d)\s*cent(?:ury|\.)?' . CREATOR_ERA);

define('CREATOR_DATE_TAIL', '/
	(?:,\s*|\s+|(?<=[^\s,\d])(?=\d))
	(?P<qual>' . CREATOR_QUAL . '(?:\s*' . CREATOR_QUAL . ')*)?\s*
	(?:' . CREATOR_MONTH . '\s*\d{0,2}[\s,]*)?
	(?P<dates>
	    ' . CREATOR_CENTURY . '\s*[-\x{2013}\x{2014}\/]{0,2}\s*(?:' . CREATOR_CENTURY . ')?
	  | ' . CREATOR_YEAR . '\s*[-\x{2013}\x{2014}]{1,2}\s*(?:' . CREATOR_QUAL . '\s*)?(?:' . CREATOR_YEAR . ')?
	  | [-\x{2013}\x{2014}]\s*' . CREATOR_YEAR . '
	  | \d{3,4}\?{0,2}(?:s|\/\d{1,4})?' . CREATOR_ERA . '
	        (?:[\s,]+' . CREATOR_MONTH . '(?:\s+\d{1,2})?)?(?:\s+or\s*\d{0,4}\?{0,2})?
	)
	\s*(?:' . CREATOR_QUAL . ')?
	[\s,.\-\x{2013}\x{2014}]*$/xiu');

// A parenthetical at the very end, e.g. "Kindberg, N. C. (Nils Conrad)".
define('CREATOR_PAREN_TAIL', '/\s*[,;]?\s*\(([^()]*)\)\s*[,;.]?\s*$/u');

//----------------------------------------------------------------------------------------
// Trailing comma segments that are honorifics or ranks rather than part of the name.
// Matched on the first word only, so "Freiherr von und zu" and "duca di Pescolanciano"
// are caught by their leading title.
function creator_honorific_words()
{
	return array(
		'sir', 'dame', 'lady', 'lord', 'mr', 'mrs', 'ms', 'miss', 'dr', 'prof',
		'rev', 'st', 'hon', 'baron', 'baroness', 'freiherr', 'graf', 'grafin',
		'count', 'countess', 'duke', 'duchess', 'duc', 'duca', 'duchesse',
		'conte', 'contessa', 'marquis', 'marquise', 'markgraf', 'prince',
		'princess', 'ritter', 'edler', 'herr', 'mlle', 'mme', 'don', 'dona',
		'capt', 'captain', 'col', 'colonel', 'gen', 'general', 'maj', 'major',
		'lt', 'lieut', 'lieutenant', 'adm', 'admiral', 'abbé', 'abbe', 'pere',
		'père', 'fray', 'brother', 'sister', 'bishop', 'archbishop', 'cardinal',
		'king', 'queen', 'emperor', 'empress', 'pope', 'shaykh', 'hadji'
	);
}

//----------------------------------------------------------------------------------------
// Honorifics that are written in front of the whole name. Nobiliary titles are
// deliberately not here: "Freiherr von und zu" and "duca di Pescolanciano" sit after
// the given name in their own conventions, so a prefixed form would be an invention.
function creator_prefixable_honorifics()
{
	return array('sir', 'dame', 'lady', 'lord', 'mr', 'mrs', 'ms', 'miss', 'dr',
		'prof', 'rev', 'hon', 'st', 'capt', 'captain', 'col', 'colonel', 'gen',
		'general', 'maj', 'major', 'lt', 'lieut', 'lieutenant', 'adm', 'admiral',
		'mlle', 'mme', 'bishop', 'archbishop', 'cardinal');
}

//----------------------------------------------------------------------------------------
// Generational suffixes. Roman numerals are included but only up to VIII: beyond that
// they are almost always regnal numbers on a one-word name, not suffixes.
function creator_suffix_words()
{
	return array('jr', 'jnr', 'junior', 'sr', 'snr', 'senior', 'ii', 'iii', 'iv',
		'v', 'vi', 'vii', 'viii', 'fils', 'père', 'pere');
}

//----------------------------------------------------------------------------------------
// Strip the things that make two copies of the same name compare unequal: bidi marks
// (224 BHL headings carry a stray U+200F), numeric character references that were
// stored literally rather than decoded, and runs of whitespace.
function creator_clean_string($s)
{
	$s = (string)$s;

	if (function_exists('normalizer_normalize'))
	{
		$n = normalizer_normalize($s, Normalizer::FORM_C);

		if ($n !== false && $n !== null)
		{
			$s = $n;
		}
	}

	// "&#x01c2;" and friends, left undecoded in the source data
	$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	// bidi and other invisible formatting characters
	$s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s);

	$s = preg_replace('/\s+/u', ' ', $s);

	return trim($s);
}

//----------------------------------------------------------------------------------------
// Trim the punctuation MARC leaves hanging off the end of a heading, without eating the
// dot of a terminal initial - "Smith, J. W." must keep its last full stop.
function creator_trim_punct($s)
{
	$s = preg_replace('/^[\s,;:]+/u', '', $s);
	$s = preg_replace('/[\s,;:]+$/u', '', $s);

	// A terminal period is MARC's, not the name's - unless the last token is an
	// abbreviation ("Wm.", "Ant.", "N.", "Jr."), where the dot belongs to the word.
	if (preg_match('/\.$/u', $s) && !preg_match('/(?:^|\s|-)\p{L}{1,3}\.$/u', $s))
	{
		$s = preg_replace('/[\s,;:]*\.$/u', '', $s);
		$s = preg_replace('/[\s,;:]+$/u', '', $s);
	}

	return trim($s);
}

//----------------------------------------------------------------------------------------
// Is this token an initial? "N." or "N" or "J.-B."
function creator_is_initial($token)
{
	return (bool)preg_match('/^\p{Lu}\.?(?:-\p{Lu}\.?)*$/u', $token);
}

//----------------------------------------------------------------------------------------
// Reduce a forename to initials: "Lawrence Morris" -> "L. M.", "Jean-Baptiste" -> "J.-B."
// Tokens that are already initials are passed through with their dots normalised, and
// particles ("de", "van") are dropped - an initial for a particle is meaningless.
function creator_initials($forename)
{
	if ($forename === null || trim($forename) === '')
	{
		return null;
	}

	$particles = array('de', 'del', 'della', 'di', 'da', 'du', 'des', 'van', 'von',
		'der', 'den', 'ten', 'ter', 'la', 'le', 'el', 'al', 'bin', 'ibn', 'y',
		"d'", "l'", "dell'", 'af', 'zu', 'op', 'ten');

	$out = array();

	foreach (preg_split('/\s+/u', trim($forename)) as $token)
	{
		$token = creator_trim_punct($token);

		if ($token === '' || in_array(mb_strtolower($token, 'UTF-8'), $particles, true)
			|| in_array(rtrim(mb_strtolower($token, 'UTF-8'), "'\u{2019}"), $particles, true))
		{
			continue;
		}

		$parts = array();

		foreach (explode('-', $token) as $piece)
		{
			if (preg_match('/^(\p{L})/u', $piece, $m))
			{
				$parts[] = mb_strtoupper($m[1], 'UTF-8') . '.';
			}
		}

		if (count($parts) > 0)
		{
			$out[] = implode('-', $parts);
		}
	}

	return count($out) > 0 ? implode(' ', $out) : null;
}

//----------------------------------------------------------------------------------------
// Pull the trailing life dates off a heading. Returns the name with the dates removed,
// and fills $dates with what was found.
function creator_split_dates($s, &$dates)
{
	$dates = array(
		'birthDate'     => null,
		'deathDate'     => null,
		'floruit_start' => null,
		'floruit_end'   => null,
		'qualifier'     => null,
		'uncertain'     => false,
		'text'          => null
	);

	if (!preg_match(CREATOR_DATE_TAIL, $s, $m, PREG_OFFSET_CAPTURE))
	{
		return $s;
	}

	$text = preg_replace('/\s+/u', ' ', trim($m['dates'][0]));
	$text = preg_replace('/[\s,\-–—]+$/u', '', $text);

	// "ca. 1175-ca" leaves a qualifier hanging off the open end of the range
	$text = preg_replace('/[\s,\-–—]+' . CREATOR_QUAL . '$/ui', '', $text);
	$text = preg_replace('/\s+or$/ui', '', $text);

	if ($text === '')
	{
		return $s;
	}

	$dates['text'] = $text;
	$dates['uncertain'] = (strpos($text, '?') !== false);

	$qual = isset($m['qual'][0]) ? mb_strtolower($m['qual'][0], 'UTF-8') : '';

	if (strpos($qual, 'fl') !== false || strpos($qual, 'active') !== false)
	{
		$dates['qualifier'] = 'floruit';
	}
	else if (preg_match('/\bb\.?/u', $qual))
	{
		$dates['qualifier'] = 'birth';
	}
	else if (preg_match('/\bd\.?/u', $qual))
	{
		$dates['qualifier'] = 'death';
	}
	else if (preg_match('/\b(?:ca|c|circa|approximately)\.?/u', $qual))
	{
		$dates['qualifier'] = 'circa';
		$dates['uncertain'] = true;
	}

	$name = creator_trim_punct(substr($s, 0, $m[0][1]));

	// A century ("18th cent.") is a period, not a year, so record the text and stop.
	if (stripos($text, 'cent') !== false)
	{
		return $name;
	}

	$floruit = ($dates['qualifier'] === 'floruit');

	if (preg_match('/[-–—]/u', $text))
	{
		$halves = preg_split('/[-–—]+/u', $text, 2);
		$left  = isset($halves[0]) ? $halves[0] : '';
		$right = isset($halves[1]) ? $halves[1] : '';

		$from = preg_match('/\d{1,4}/u', $left, $lm)  ? (int)$lm[0] : null;
		$to   = preg_match('/\d{1,4}/u', $right, $rm) ? (int)$rm[0] : null;

		if ($floruit)
		{
			$dates['floruit_start'] = $from;
			$dates['floruit_end']   = $to;
		}
		else
		{
			$dates['birthDate'] = $from;
			$dates['deathDate'] = $to;
		}
	}
	else if (preg_match('/\d{1,4}/u', $text, $ym))
	{
		$year = (int)$ym[0];

		if ($floruit)
		{
			$dates['floruit_start'] = $year;
		}
		else if ($dates['qualifier'] === 'death')
		{
			$dates['deathDate'] = $year;
		}
		else
		{
			$dates['birthDate'] = $year;
		}
	}

	return $name;
}

//----------------------------------------------------------------------------------------
// Does a parenthetical look like an expansion of the initials in front of it?
// "Kindberg, N. C. (Nils Conrad)" yes; "Smith, John (Botanist)" no. The test is the
// initials: an expansion has to begin with the same letters it expands. Where there are
// no initials to check against, a short run of capitalised words is accepted as a name.
function creator_is_expansion($paren, $forename)
{
	$paren = trim($paren);

	if ($paren === '' || preg_match('/\d/u', $paren))
	{
		return false;
	}

	// ":" separates the parts of a corporate qualifier, never a personal name
	if (strpos($paren, ':') !== false)
	{
		return false;
	}

	$initials = creator_initials($forename);

	if ($initials !== null && preg_match('/\p{Lu}\./u', $initials))
	{
		$want = preg_replace('/[^\p{Lu}]/u', '', $initials);
		$got  = preg_replace('/[^\p{Lu}]/u', '', creator_initials($paren));

		// the expansion must start with the initials it expands; BHL sometimes
		// expands only the first of two, so a prefix match is enough
		return ($want !== '' && $got !== '' && strpos($got, $want) === 0);
	}

	return (bool)preg_match('/^\p{Lu}[^\s]*(?:\s+\S+){0,4}$/u', $paren);
}

//----------------------------------------------------------------------------------------
// Assemble a name in natural order: "Sir" + "Nils Conrad" + "Kindberg" + "Jr."
function creator_natural($given, $family, $honorific = null, $suffix = null)
{
	$parts = array();

	foreach (array($honorific, $given, $family) as $p)
	{
		if ($p !== null && trim($p) !== '')
		{
			$parts[] = trim($p);
		}
	}

	$s = implode(' ', $parts);

	if ($suffix !== null && trim($suffix) !== '' && $s !== '')
	{
		$s .= ', ' . trim($suffix);
	}

	return $s;
}

//----------------------------------------------------------------------------------------
// Assemble a name in inverted order: "Kindberg, Nils Conrad"
function creator_inverted($given, $family, $suffix = null)
{
	if ($family === null || trim($family) === '')
	{
		return '';
	}

	$s = trim($family);

	if ($given !== null && trim($given) !== '')
	{
		$s .= ', ' . trim($given);
	}

	if ($suffix !== null && trim($suffix) !== '')
	{
		$s .= ', ' . trim($suffix);
	}

	return $s;
}

//----------------------------------------------------------------------------------------
// Words that mark a named meeting - MARC 111. Kept in one place because the list is
// multilingual and BHL's headings are not mostly English.
function creator_meeting_words()
{
	return 'expedition|expeditionen|exp\x{00e9}dition|expedici\x{00f3}n|expedi\x{00e7}\x{00e3}o'
		. '|ekspedition|expeditie|studienreise'
		. '|congress|congr\x{00e8}s|congreso|congresso|kongress|congressus'
		. '|symposium|symposia|simposio|symposion'
		. '|conference|conf\x{00e9}rence|conferencia|conferenza|konferenz'
		. '|workshop|colloquium|colloque|coloquio|convention'
		. '|seminar|seminario|s\x{00e9}minaire|assembly|asamblea|tagung'
		. '|exposition|exposici\x{00f3}n';
}

//----------------------------------------------------------------------------------------
// Words that mark an institution. Multilingual for the same reason the meeting list is.
function creator_corporate_words()
{
	return 'society|soci\x{00e9}t\x{00e9}|sociedad|societ\x{00e0}|societas|gesellschaft'
		. '|academy|acad\x{00e9}mie|academia|akademie'
		. '|museum|mus\x{00e9}um|museo|university|universit\x{00e9}|universidad'
		. '|universit\x{00e0}|universit\x{00e4}t|college|institute|institut|instituto|istituto'
		. '|laboratory|laboratoire|laboratorio|garden|gardens|jardin'
		. '|survey|bureau|department|departamento|dept|division|commission|committee'
		. '|council|association|verein|club|company|compagnie|corporation|inc|ltd'
		. '|imprimerie|press|publishing|publishers|observatory|observatoire|herbarium'
		. '|library|biblioth\x{00e8}que|biblioteca|ministry|minist\x{00e8}re|office|service'
		. '|administration|agency|foundation|trust|board|school|hospital|zoo|aquarium'
		. '|arboretum|station';
}

//----------------------------------------------------------------------------------------
// Work out what kind of thing a heading names, when BHL has not said.
//
// This runs for every credit in partcreator - 161,583 creators, two thirds of BHL's
// total - because that table has no CreatorType column at all, and for the handful of
// creator rows typed "Not Specified".
//
// Three tests, in order of confidence:
//
// 1. A meeting word plus a year. MARC 111 headings name an occasion and nearly always
//    date it: "Novara Expedition 1857-1859", "International Congress of Arachnology
//    Geneva, Switzerland) 1995-". The year is what separates those from corporate
//    bodies that merely contain the word - "Library of Congress", "United States.
//    Congress" - and a meeting word straight after a full stop is a subordinate unit
//    of a body ("Biophysical Society. Symposium"), not a meeting in its own right.
//    Measured against the 79,891 creators BHL does type: 93% precision at 77% recall,
//    and every one of the 43 apparent false positives is a real expedition or congress
//    that BHL itself typed as corporate, so the true precision is higher.
//
// 2. An institution word - "Royal Botanic Gardens, Kew.", "Marine Biological Laboratory
//    (Woods Hole, Mass.)". These carry a comma, so without this test the comma rule
//    below calls them people. Worth 2,128 corrections against 23 regressions.
//
//    The regressions are the readable failure mode here: a person whose surname IS
//    an institution word - "Press, J. R.", "Bureau, Ed. (Edouard), 1830-1918",
//    "Council, Trevor." - gets called an organization. Guarding on a single-word
//    first field fixes those but costs more corporate names than it saves people,
//    so it is left out and noted here instead.
//
// 3. A comma means a personal name, because BHL writes people as "Surname, Forename".
//
// 4. Anything else is a corporate body.
//
// Together: 95.0% against the 79,891 creators BHL does type, up from 92.3% for the
// comma rule alone, with personal recall unchanged at 98.9%.
function creator_infer_kind($name)
{
	$kw = creator_meeting_words();

	if (preg_match('/\b(?:' . $kw . ')\b/iu', $name))
	{
		if (preg_match('/\b1[5-9]\d\d\b|\b20[0-2]\d\b/u', $name)
			&& !preg_match('/\.\s+\p{L}*\s*(?:' . $kw . ')\b/iu', $name)
			&& !preg_match('/library of congress/iu', $name))
		{
			return 'meeting';
		}

		// A meeting word and no date, or a meeting word hanging off a parent body:
		// not a meeting, but not a person either. "Biophysical Society. Symposium
		// Cambridge, Mass.) 1958-" would otherwise be called personal by the comma
		// test below, on the comma in "Cambridge, Mass.".
		return 'corporate';
	}

	if (preg_match('/\b(?:' . creator_corporate_words() . ')\b/iu', $name))
	{
		return 'corporate';
	}

	return (strpos($name, ',') !== false) ? 'personal' : 'corporate';
}

//----------------------------------------------------------------------------------------
// Parse a BHL creator heading.
//
// $kind is "personal", "corporate", "meeting" or null. Pass it when you have it - null
// means "work it out", and creator_infer_kind() above does that from the name alone.
// parse_creator() takes a CreatorType instead and handles this for you.
//
// Returns an array with:
//   kind            personal | corporate | meeting
//   name            the display name, natural order, fullest form available
//   familyName      family name, or null
//   givenName       first given name, or null
//   additionalName  middle name(s), or null
//   initials        given name(s) reduced to initials, or null - the whole given name,
//                   so "Lawrence Morris" gives "L. M." and not just "L."
//   honorificPrefix "Sir", "Mrs" ... stripped out of name, or null
//   honorificSuffix "Jr.", "III" ... or null
//   alternatives    distinct other ways of writing the same name
//   dates           birthDate / deathDate / floruit_start / floruit_end / qualifier /
//                   uncertain / text
//   raw             the input, cleaned of invisible characters
//
// Keys are schema.org property names where schema.org has one. initials, alternatives,
// dates, kind and raw have no schema.org equivalent and keep descriptive names; within
// dates, only birthDate and deathDate are schema.org terms.
function parse_creator_name($name, $kind = null)
{
	$raw = creator_clean_string($name);

	$result = array(
		'kind'            => $kind,
		'name'            => $raw,
		'familyName'      => null,
		'givenName'       => null,
		'additionalName'  => null,
		'initials'        => null,
		'honorificPrefix' => null,
		'honorificSuffix' => null,
		'alternatives'    => array(),
		'dates'           => null,
		'raw'             => $raw
	);

	if ($raw === '')
	{
		$result['kind'] = ($kind === null) ? 'corporate' : $kind;
		return $result;
	}

	if ($kind === null)
	{
		$result['kind'] = creator_infer_kind($raw);
	}

	// Corporate and meeting headings keep their dates: they are part of the name.
	if ($result['kind'] !== 'personal')
	{
		$result['name'] = creator_trim_punct($raw);
		$result['dates'] = null;
		return $result;
	}

	$dates = null;
	$s = creator_split_dates($raw, $dates);
	$result['dates'] = $dates;

	// A parenthetical can sit either side of the dates: "N. C. (Nils Conrad), 1832-"
	// and "Ant. (Antoine) 1797-1838" both occur.
	$paren = null;

	if (preg_match(CREATOR_PAREN_TAIL, $s, $pm))
	{
		$paren = trim($pm[1]);
		$s = creator_trim_punct(mb_substr($s, 0, mb_strpos($s, $pm[0], 0, 'UTF-8'), 'UTF-8'));
	}

	// Split the heading on commas. The first field is the family name, the second the
	// given name; anything after that is an honorific or a generational suffix.
	$fields = array_map('creator_trim_punct', explode(',', $s));
	$fields = array_values(array_filter($fields, 'strlen'));

	// stray record numbers, e.g. "281604, Dick, T. A."
	$fields = array_values(array_filter($fields, function ($f)
	{
		return !preg_match('/^\d+$/u', $f);
	}));

	$family = isset($fields[0]) ? $fields[0] : null;
	$given  = isset($fields[1]) ? $fields[1] : null;

	$honorifics = creator_honorific_words();
	$suffixes   = creator_suffix_words();

	for ($i = 2; $i < count($fields); $i++)
	{
		$field = $fields[$i];
		$first = mb_strtolower(rtrim(preg_split('/\s+/u', $field)[0], '.'), 'UTF-8');

		if (in_array($first, $suffixes, true))
		{
			$result['honorificSuffix'] = $field;
		}
		else if (in_array($first, $honorifics, true)
			|| preg_match('/^\d+(?:st|nd|rd|th|d)\s+\p{L}/u', $field)
			|| preg_match('/^\p{Ll}/u', $field))
		{
			$result['honorificPrefix'] = $field;
		}
		else if ($given === null)
		{
			$given = $field;
		}
		else
		{
			// an epithet with nowhere else to go - keep it on the given name so
			// nothing is silently dropped
			$given .= ', ' . $field;
		}
	}

	// A peerage can ride on the given name with no comma to mark it off:
	// "Hugh Fortescue 3rd earl" -> given "Hugh Fortescue", honorific "3rd earl"
	if ($given !== null && $result['honorificPrefix'] === null)
	{
		if (preg_match('/^(.+?)\s+(\d+(?:st|nd|rd|th|d)\s+\p{L}.*)$/u', $given, $hm))
		{
			$given = creator_trim_punct($hm[1]);
			$result['honorificPrefix'] = creator_trim_punct($hm[2]);
		}
	}

	// A suffix can also ride on the end of the given name with no comma: "John W. Jr"
	if ($given !== null && $result['honorificSuffix'] === null)
	{
		if (preg_match('/^(.*?)[\s,]+((?:' . implode('|', $suffixes) . ')\.?)$/ui', $given, $sm))
		{
			$given = creator_trim_punct($sm[1]);
			$result['honorificSuffix'] = $sm[2];
		}
	}

	$result['familyName'] = ($family === '') ? null : $family;
	$result['givenName']  = ($given === '' || $given === null) ? null : $given;

	// Decide what the parenthetical was. Usually it expands the initials, in which
	// case it becomes the given name and the abbreviated form becomes an alternative.
	$abbreviated = null;

	if ($paren !== null)
	{
		if (creator_is_expansion($paren, $result['givenName']))
		{
			$abbreviated = $result['givenName'];
			$result['givenName'] = $paren;
		}
		else if ($result['givenName'] === null && creator_is_expansion($paren, null))
		{
			$result['givenName'] = $paren;
		}
		else
		{
			// not a name - a disambiguating note like "(Botanist)". Keep it out of
			// the name but preserve it for whoever needs it.
			$result['note'] = $paren;
		}
	}

	$result['initials'] = creator_initials($result['givenName']);

	$result['name'] = creator_natural($result['givenName'], $result['familyName'],
		null, $result['honorificSuffix']);

	if ($result['name'] === '')
	{
		$result['name'] = creator_trim_punct($s);
	}

	// Every other way this heading could reasonably be written. These are for matching
	// and for skos:altLabel, so both orderings and both levels of abbreviation go in.
	$variants = array();

	$givens = array($result['givenName'], $abbreviated, $result['initials']);

	foreach ($givens as $g)
	{
		if ($g === null || trim($g) === '')
		{
			continue;
		}

		$variants[] = creator_natural($g, $result['familyName'], null, $result['honorificSuffix']);
		$variants[] = creator_inverted($g, $result['familyName'], $result['honorificSuffix']);

		if ($result['honorificPrefix'] !== null
			&& in_array(mb_strtolower(rtrim($result['honorificPrefix'], '.'), 'UTF-8'),
				creator_prefixable_honorifics(), true))
		{
			$variants[] = creator_natural($g, $result['familyName'],
				$result['honorificPrefix'], $result['honorificSuffix']);
		}
	}

	if ($result['familyName'] !== null && $result['givenName'] === null)
	{
		$variants[] = $result['familyName'];
	}

	$seen = array($result['name'] => true);
	$alternatives = array();

	foreach ($variants as $v)
	{
		$v = trim($v);

		if ($v === '' || isset($seen[$v]))
		{
			continue;
		}

		$seen[$v] = true;
		$alternatives[] = $v;
	}

	$result['alternatives'] = $alternatives;

	// Split the given name into schema:givenName and schema:additionalName, which schema.org
	// recommends for middle names: "Lawrence Morris" becomes givenName "Lawrence" and
	// additionalName "Morris". Hyphenated compounds such as "Jean-Baptiste" are a single
	// token and stay whole, and a lone given name leaves additionalName null.
	//
	// Done last, on purpose. The display name, the initials and every alternative spelling
	// above are built from the whole given name, so splitting it earlier would drop the
	// middle name out of all of them.
	if ($result['givenName'] !== null)
	{
		$given_parts = preg_split('/\s+/u', trim($result['givenName']), -1,
			PREG_SPLIT_NO_EMPTY);

		if (count($given_parts) > 1)
		{
			$result['givenName']      = $given_parts[0];
			$result['additionalName'] = implode(' ', array_slice($given_parts, 1));
		}
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// CreatorType.
//
// The creator table's CreatorType is two MARC facets welded together with " - ":
//
//   "Main - Personal Name"      "Added - Corporate Name"     "Not Specified"
//    ^^^^   ^^^^^^^^^^^^^        ^^^^^   ^^^^^^^^^^^^^^
//    entry  name type            entry   name type
//
// The name type is MARC's 100/110/111 (personal, corporate, meeting). The entry is
// whether the heading was a main entry (1XX) or an added entry (7XX) - that is, whether
// this creator is the work's principal author or a contributor to it.
//
// The two facets have different owners, and conflating them is the easy mistake here:
//
//   - the NAME TYPE belongs to the creator. A person is a person on every title.
//   - the ENTRY belongs to the CREDIT, not the creator. 10,007 of BHL's 79,892 creators
//     are a main entry on one title and an added entry on another, so "is X an author or
//     a contributor" has no answer until you say "on which title". In RDF terms the entry
//     is a property of the title-to-creator edge, not of the creator resource.
//
// Only the creator table carries this at all. partcreator has no CreatorType column, so
// article credits have neither facet - pass null and let the name shape decide.

//----------------------------------------------------------------------------------------
// The namespaces the RDF terms below come from, for whoever writes the serialiser.
function creator_rdf_prefixes()
{
	return array(
		'foaf'    => 'http://xmlns.com/foaf/0.1/',
		'schema'  => 'https://schema.org/',
		'dcterms' => 'http://purl.org/dc/terms/',
		'bibo'    => 'http://purl.org/ontology/bibo/',
		'skos'    => 'http://www.w3.org/2004/02/skos/core#'
	);
}

//----------------------------------------------------------------------------------------
// Parse a CreatorType into its two facets.
//
// Returns an array with:
//   kind        personal | corporate | meeting | null
//               null means BHL did not say - "Not Specified", or a row from partcreator.
//               Feed it to parse_creator_name(), which infers from the name shape.
//   entry       main | added | null        which MARC entry the heading came from
//   role        author | contributor | null  what that entry means, for this credit only
//   label       a readable name for the kind: "person", "organization", "conference"
//   rdf_class   the class to type the creator as
//   rdf_property the property to link the work to the creator with, for THIS credit
//   raw         the input
function parse_creator_type($type)
{
	$result = array(
		'kind'         => null,
		'entry'        => null,
		'role'         => null,
		'label'        => null,
		'rdf_class'    => null,
		'rdf_property' => null,
		'raw'          => $type
	);

	$t = mb_strtolower(creator_clean_string($type), 'UTF-8');

	if ($t === '' || strpos($t, 'not specified') !== false)
	{
		return $result;
	}

	if (strpos($t, 'personal') !== false)
	{
		$result['kind']      = 'personal';
		$result['label']     = 'person';
		$result['rdf_class'] = 'schema:Person';
	}
	else if (strpos($t, 'corporate') !== false)
	{
		$result['kind']      = 'corporate';
		$result['label']     = 'organization';
		$result['rdf_class'] = 'schema:Organization';
	}
	else if (strpos($t, 'meeting') !== false)
	{
		// MARC 111/711 is a named meeting - a congress, a symposium, an expedition.
		// That is an event, not an agent, so it gets schema:Event rather than
		// schema:Organization. schema.org has no Conference class, so Event is as close
		// as the vocabulary goes; bibo:Conference would be more precise if a second
		// vocabulary were acceptable. Worth knowing before generating RDF: these are the
		// headings where "creator" and "thing that happened" are the same record.
		$result['kind']      = 'meeting';
		$result['label']     = 'conference';
		$result['rdf_class'] = 'schema:Event';
	}

	// Main entry = the work is principally by this creator. Added entry = they are
	// one of the other hands on it. Checked with word boundaries because "Main" is a
	// substring of nothing here but the check should not depend on that.
	if (preg_match('/\bmain\b/u', $t))
	{
		$result['entry']        = 'main';
		$result['role']         = 'author';
		$result['rdf_property'] = 'dcterms:creator';
	}
	else if (preg_match('/\badded\b/u', $t))
	{
		$result['entry']        = 'added';
		$result['role']         = 'contributor';
		$result['rdf_property'] = 'dcterms:contributor';
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// Settle on one kind for a creator who appears on several titles.
//
// 229 CreatorIDs are typed inconsistently across their rows - catalogued as a person on
// one title and a corporate body on another. Neither row is marked authoritative, so
// take the majority and, on a tie, the first kind seen. Rows with no kind don't vote.
//
// $types is the list of CreatorType strings for one CreatorID.
function creator_kind_from_types($types)
{
	$votes = array();

	foreach ((array)$types as $type)
	{
		$kind = parse_creator_type($type)['kind'];

		if ($kind === null)
		{
			continue;
		}

		if (!isset($votes[$kind]))
		{
			$votes[$kind] = 0;
		}

		$votes[$kind]++;
	}

	if (count($votes) === 0)
	{
		return null;
	}

	arsort($votes);

	return key($votes);
}

//----------------------------------------------------------------------------------------
// Parse a creator heading and its type together - the usual entry point when reading
// straight out of the creator table.
//
//   $r = parse_creator($row['CreatorName'], $row['CreatorType']);
//
// Returns everything parse_creator_name() returns, plus entry, role, label, rdf_class
// and rdf_property. The type decides the kind where BHL gave one; where it did not
// ("Not Specified", or any row from partcreator, which has no type column) the name
// shape decides instead.
function parse_creator($name, $type = null)
{
	$t = parse_creator_type($type);
	$r = parse_creator_name($name, $t['kind']);

	$r['entry']        = $t['entry'];
	$r['role']         = $t['role'];
	$r['rdf_property'] = $t['rdf_property'];

	// parse_creator_name() may have inferred a kind the type didn't give, so label and
	// class follow its answer rather than the type's.
	$labels = array('personal' => 'person', 'corporate' => 'organization',
		'meeting' => 'conference');
	$classes = array('personal' => 'schema:Person', 'corporate' => 'schema:Organization',
		'meeting' => 'schema:Event');

	$r['label']     = isset($labels[$r['kind']]) ? $labels[$r['kind']] : null;
	$r['rdf_class'] = isset($classes[$r['kind']]) ? $classes[$r['kind']] : null;

	// true when BHL told us the kind, false when it was guessed from the name
	$r['kind_stated'] = ($t['kind'] !== null);

	return $r;
}
