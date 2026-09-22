<?php

error_reporting(E_ALL);

//----------------------------------------------------------------------------------------
// text is being annotated, highlight is bit being tagged, last_pos is last position 
// we tagged in this text, offset is offset with respect to larger document
function annotation_selector($text, $highlight, &$last_pos, $offset = 0)
{
	$flanking_length = 32;
	
	$selectors = array();
	
	// position
	$start = mb_strpos($text, $highlight, $last_pos, mb_detect_encoding($text));
	$length = mb_strlen($highlight, mb_detect_encoding($highlight));
	$end = $start + $length;
	
	$selector = new stdclass;
	$selector->type = 'TextPositionSelector';
	$selector->start = (Integer)$start;
	$selector->end = (Integer)$end;
	
	$selectors[] = $selector; 
	
	// text loc
	$selector = new stdclass;
	$selector->type = 'TextQuoteSelector';
	$selector->exact = $highlight;
	
	$pre_length = min($start, $flanking_length);
	$pre_start = $start - $pre_length;	
	$selector->prefix = mb_substr($text, $pre_start, $pre_length, mb_detect_encoding($text)); 
	
	// $end is already one past the last character of the match -- start + length -- so the
	// suffix begins at $end. Starting it at $end + 1 dropped the first character after the
	// match: on page 57579616 "...30.00253Â° E, 1600 m" gave a suffix of " 1600 m", losing the
	// comma. That is not cosmetic. A TextQuoteSelector is used by searching the text for
	// prefix + exact + suffix, so a suffix that never follows the quote in the source makes
	// the annotation unrelocatable in exactly the case it exists for.
	$post_length = 	min(mb_strlen($text, mb_detect_encoding($text)) - $end, $flanking_length);					
	$selector->suffix = mb_substr($text, $end, $post_length, mb_detect_encoding($text));
	
	$selectors[] = $selector; 
			
	$last_pos = $end;
	
	return $selectors;
}

?>
