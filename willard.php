<?php

/**
 * Willard
 *
 * A parser for lobbyist registrations filed with Virginia's Secretary of the Commonwealth. Fetches,
 * transforms, and stores those registrations in machine-readable formats.
 * 
 * PHP version 5
 *
 * @author		Waldo Jaquith <waldo at jaquith.org>
 * @copyright	2013 Waldo Jaquith
 * @license		MIT
 * @version		0.1
 * @link		http://www.github.com/openva/willard
 * @since		0.1
 *
 */
 
/*
 * Define configuration options.
 */
define('PERIOD_DIR', 'year');
define('LOBBYIST_DIR', 'lobbyist');

/*
 * Our time zone. (This is, of course, EST, but we have to define this to keep PHP from
 * complaining.)
 */
date_default_timezone_set('America/New_York');

/*
 * Include the Simple HTML DOM Parser.
 */
include('class.simple_html_dom.inc.php');

/*
 * Include the Address Standardization Solution.
 */
include('class.AddressStandardizationSolution.inc.php');

function fetch_list($period_id)
{
	
	if (empty($period_id))
	{
		return FALSE;
	}
	
	/*
	 * Initialize our cURL session.
	 */
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, TRUE);
	/* We need a long timeout, because the remote server is slow. */
	curl_setopt($ch, CURLOPT_TIMEOUT_MS, 30000);
	curl_setopt($ch, CURLOPT_URL, 'https://solutions.virginia.gov/Lobbyist/Reports/LobbyistSearch');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	$allowed_protocols = CURLPROTO_HTTP | CURLPROTO_HTTPS;
	curl_setopt($ch, CURLOPT_PROTOCOLS, $allowed_protocols);
	curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $allowed_protocols & ~(CURLPROTO_FILE | CURLPROTO_SCP));
	curl_setopt($ch, CURLOPT_POST, TRUE);
	curl_setopt($ch, CURLOPT_POSTFIELDS, 'registrationYear=' . $period_id . '&principalName=&lobbyistName=+');
	$html = curl_exec($ch);
	
	if ($html === FALSE)
	{
		die(curl_error($ch));
	}

	curl_close($ch);
	
	/*
	 * Carve out the bit that we need, ignoring everything else.
	 */
	 $start = strpos($html, '<tbody>');
	 $end = strpos($html, '</tbody>');
	 $html = substr($html, $start, ($end - $start + 7));
	
	/*
	 * Render this as an object with PHP Simple HTML DOM Parser.
	 */
	$dom = str_get_html($html);
	
	/*
	 * We could not render this HTML as an object.
	 */
	if ($dom === FALSE)
	{
		
		/*
		 * This HTML is invalid. Clean it up with HTML Tidy.
		 */
		if (class_exists('tidy', FALSE))
		{
	
			$tidy = new tidy;
			$tidy->parseString($html);
			$tidy->cleanRepair();
			$html = $tidy;
		}
		
		elseif (exec('which tidy'))
		{
		
			$filename = '/tmp/' . $period_id .'.tmp';
			file_put_contents($filename, $html);
			exec('tidy --show-errors 0 --show-warnings no -q -m ' . $filename);
			$html = file_get_contents($filename);
			unlink($filename);
			
		}
		
		/*
		 * Try again to render this as an object with PHP Simple HTML DOM Parser.
		 */
		$dom = str_get_html($html);
		
		if ($dom === FALSE)
		{
			die('Invalid HTML -- could not be rendered as an object.');
		}
	}
	
	/*
	 * Create the object that we'll use to store the list of periods.
	 */
	$lobbyists = new stdClass();
	
	$i=0;
	
	/*
	 * Iterate through the table rows -- each row is a single registration.
	 */
	foreach ($dom->find('tr') as $registration)
	{
		
		$lobbyists->{$i} = new stdClass();
		
		$lobbyists->{$i}->url = 'https://solutions.virginia.gov' . trim($registration->find('a', 0)->href);
		$lobbyists->{$i}->name = trim($registration->find('td', 0)->plaintext);
		$lobbyists->{$i}->organization = trim($registration->find('td', 1)->plaintext);
		$lobbyists->{$i}->principal = trim($registration->find('td', 2)->plaintext);
		$lobbyists->{$i}->id = str_replace('contactId=', '', trim(strstr($lobbyists->{$i}->url, 'contactId=')));
		
		$i++;
		
	}
	
	/*
	 * It's possible that we failed to identify any lobbyists. This would likely be a result of a
	 * change in the HTML that rendered useless our HTML scraping.
	 */
	if (count((array) $lobbyists) == 0)
	{
		return FALSE;
	}
	
	return $lobbyists;
}

/**
	$normalizer = new AddressStandardizationSolution;
		$tmp = $normalizer->AddressLineStandardization($tmp);
 * Retrieve the content at a given URL.
 */
function fetch_url($url)
{
	
	if (empty($url))
	{
		return FALSE;
	}
	
	$ch = curl_init(); 
	curl_setopt($ch, CURLOPT_URL, $url);  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);  
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);  
	$html = curl_exec($ch);
	curl_close($ch);
	
	if ($html === FALSE)
	{
		die(curl_error($ch));
	}
	
	return $html;
	
}


/*
 * First get a list of all registration years.
 *
 * You might think that these would just be passed in the URL as actual years. You would be wrong.
 * 2013 is, at this writing, represented as "315880002".
 */
 
$html = fetch_url('https://solutions.virginia.gov/Lobbyist/Reports/LobbyistSearch');
if ($html === FALSE)
{
	die('Could not fetch list of registration years.');
}

/*
 * Render this as an object with PHP Simple HTML DOM Parser.
 */
$html = str_get_html($html);

/*
 * Create the object that we'll use to store the list of periods.
 */
$periods = new stdClass();

foreach ($html->find('option') as $option)
{
	$option->plaintext = str_replace(' - ', '–', $option->plaintext);
	$periods->{$option->value} = $option->plaintext;
}

/*
 * Step through every biennium and download a list of all lobbyist registrations for that period.
 */
foreach ($periods as $period_id => $period_range)
{
	
	/*
	 * Define the filename for this period's JSON file.
	 */
	$filename = substr($period_range, 0, 4) . '.json';
	
	echo 'Retreiving '.$period_range . '...';
	
	/*
	 * Only update the file if we don't already have a copy.
	 */
	if (file_exists(PERIOD_DIR . '/' . $filename) === FALSE)
	{
		$registrations = fetch_list($period_id);
		if ($registrations !== FALSE)
		{
			file_put_contents(PERIOD_DIR . '/' . $filename, json_encode($registrations));
			echo 'succeeded.' . PHP_EOL;
		}
		else
		{
			echo 'failed.' . PHP_EOL;
		}
	}
	
	/*
	 * If we already have a copy, acknowledge it.
	 */
	else
	{
		echo 'using cached copy.' . PHP_EOL;
	}
	
	/*
	 * Connect to
	 * https://solutions.virginia.gov/Lobbyist/Reports/LobbyistSearch/Detail?contactId=' . $contactid
	 * and retrieve the lobbyist's address, phone number, and principal's statement.
	 * THERE MAY BE MORE TO RETRIEVE FOR SOME FILINGS. Analyze them to find out.
	 *
	 * Store a JSON-based record of each lobbyist's data.
	 *
	 * Note that lobbyist IDs are not unique. If somebody has registered six times, they have six
	 * IDs. So we need to match-merge duplicates, and set up a table that cross-references them.
	 *
	 * This may call for SQLite. Use PDO SQLite.
	 */
	
	
}
