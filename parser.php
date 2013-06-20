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
 * Include the Simple HTML DOM Parser.
 */
include('class.simple_html_dom.inc.php');

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
	curl_setopt($ch, CURLOPT_TIMEOUT_MS, 20000);
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
	 * This HTML is wildly invalid. Clean it up with HTML Tidy.
	 */
	$tidy = new tidy;
	$tidy->parseString($html);
	$tidy->cleanRepair();
	$html = $tidy;

	/*
	 * Render this as an object with PHP Simple HTML DOM Parser.
	 */
	$html = str_get_html($html);
	
	/*
	 * Create the object that we'll use to store the list of periods.
	 */
	$lobbyists = new stdClass();
	
	foreach ($html->find('table[id=lobbyistList]') as $table)
	{
	
		$i=0;
		
		/*
		 * Iterate through the table rows -- each row is a single registration.
		 */
		foreach ($table->find('tr') as $registration)
		{
			$lobbyists->{$i}->url = 'https://solutions.virginia.gov' . $registration->find('a', 0)->href;
			$lobbyists->{$i}->name = $registration->find('td', 0)->plaintext;
			$lobbyists->{$i}->organization = $registration->find('td', 1)->plaintext;
			$lobbyists->{$i}->principal = $registration->find('td', 2)->plaintext;
			$lobbyists->{$i}->id = str_replace('contactId=', '', strstr($lobbyists->{$i}->url, 'contactId='));
			$i++;
		}
	}
	
	return $lobbyists;
}

/**
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
	
	echo $period_range . PHP_EOL;
	
	/*
	 * Only update the file if we don't already have a copy.
	 */
	if (file_exists($filename) === FALSE)
	{
		$registrations = fetch_list($period_id);
		file_put_contents($filename, json_encode($registrations));
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
