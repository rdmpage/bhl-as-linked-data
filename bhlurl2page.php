<?php

// Map BHL URLs "in the wild" to direct links to BHL pages

// A user navigating the BHL site and wanting to link to a particular page is likely to
// copy and paste the URL link in their browser. However, that link is not a direct link
// to the page but rather a URL that typically embeds the item being looked at, and an
// offset to the current page with respect the the page the user first landed on. Hence
// the same page can have multiple browser URLs depending on the navigation history of
// each user. The code in this file attempts to extract the BHL PageID corresponding to
// the URL.

require_once(dirname(__FILE__) . '/sql/sqlite.php');

//----------------------------------------------------------------------------------------
// Parse BHL URL into component parts and return a "target" that we need to match in BHL
function parse_bhl_url($url)
{
	$target = new stdclass;
	$target->url = $url;
	$target->parsed = parse_url($url);
	
	if ($target->parsed)
	{
		// do we have a path?
		if (isset($target->parsed['path']))
		{
			// do we have a fragment?
			if (isset($target->parsed['fragment']))
			{
				// is fragement w.r.t. item?
				$ItemID = 0;
				if (preg_match('/^\/item\/(?<item>\d+)$/', $target->parsed['path'], $m))
				{
					$target->ItemID = $m['item'];					
				}
				
				if (isset($target->ItemID))
				{
					if (preg_match('/^page\/(?<page>\d+)(\/mode\/(?<mode>\d+)up)?/', $target->parsed['fragment'], $m))
					{
						$target->item_order = $m['page'];
						$target->mode = 1; 

						if (isset($m['mode']))
						{
							$target->mode = $m['mode'];
						}						
					}
				}
				else
				{
					// is fragement w.r.t. page?
					$PageID = 0;
					if (preg_match('/^\/page\/(?<page>\d+)$/', $target->parsed['path'], $m))
					{
						$target->PageID = $m['page'];					
					}
					
					if (isset($target->PageID))
					{
						if (preg_match('/^page\/(?<page>\d+)(\/mode\/(?<mode>\d+)up)?/', $target->parsed['fragment'], $m))
						{
							$target->item_order = $m['page'];
							$target->mode = 1; 
	
							if (isset($m['mode']))
							{
								$target->mode = $m['mode'];
							}						
						}
					}
				
				
				
				}
				
				
			}
			else
			{
				// no fragment, so possibly a direct link to a BHL entity
				if (preg_match('/^(bibliography|creator|item|page|title)\/\d+$/', $target->parsed['path']))
				{
					// direct link to BHL page
					if (preg_match('/^page\/(?<id>\d+)$/', $target->parsed['path'], $m))
					{
						$target->PageID = [$m['id']];
					}					
				}			
			}
		}
	}
	
	return $target;
}
	
	
//----------------------------------------------------------------------------------------
// Find target in BHL where we have an ItemID and a position in the page sequence 
// (item_order). The value of offset is 0 by default (we want that position). If,
// for example, we have mode2 in the URL then offset=1 will get the other page on
// display
function find_bhl_item_order(&$target, $offset = 0) 
{
	if (!isset($target->ItemID))
	{
		return;
	}

	$item_order = $target->item_order + $offset;
	
	$sql = 'SELECT * FROM page WHERE ItemID=' . $target->ItemID . ' AND SequenceOrder=' . $item_order;
	
	$data = db_get($sql);
	
	if (!isset($data[0]))
	{
		return;
	}
	
	if (!isset($target->matched))
	{
		$target->matched = [];
	}
	
	if (isset($data[0]->PageID))
	{
		$target->matched[$data[0]->PageID] = "";
	}

	if (isset($data[0]->PageNumber))
	{
		$target->matched[$data[0]->PageID] = $data[0]->PageNumber;
	}

}

//----------------------------------------------------------------------------------------
// Find item from page
function find_bhl_item_from_page(&$target) 
{
	if (!isset($target->PageID))
	{
		return;
	}

	$sql = 'SELECT * FROM page WHERE PageID=' . $target->PageID;
	
	$data = db_get($sql);
	
	if (!isset($data[0]))
	{
		return;
	}	
	
	if (isset($data[0]->ItemID))
	{
		$target->ItemID = $data[0]->ItemID;
	}

}



//----------------------------------------------------------------------------------------


$url = 'http://www.biodiversitylibrary.org/item/111041#page/68/mode/2up';
//$url = 'http://www.biodiversitylibrary.org/item/111041';

$url = 'http://www.biodiversitylibrary.org/item/110504#page/194/mode/2up';
$url = 'https://www.biodiversitylibrary.org/page/35733426#page/195/mode/1up';

$target = parse_bhl_url($url);

print_r($target);

// find

if (isset($target->PageID) && !isset($target->ItemID))
{
	find_bhl_item_from_page($target);
}


if (isset($target->ItemID))
{
	if (isset($target->item_order))
	{
		find_bhl_item_order($target);
		
		if ($target->mode == 2)
		{
			find_bhl_item_order($target, 1);
		}
	}
}

print_r($target);

// if we have > 1 page we may want to use additional information to filter the hits


