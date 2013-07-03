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
define('PERIOD_DIR', 'years');
define('LOBBYIST_DIR', 'lobbyists');

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

/**
 * Get a list of all lobbyist for a given period.
 */
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
		
		/*
		 * Check to see if the registration has been terminated. There's no special field for this,
		 * but instead it's just stored parenthetically after the registrant's name.
		 */
		$pos = strpos($lobbyists->{$i}->name, '(Registration');
		if ($pos !== FALSE)
		{
			$tmp = substr($lobbyists->{$i}->name, $pos);
			preg_match('/([0-9]{1,2})\/([0-9]{1,2})\/([0-9]{2,4})/', $tmp, $date);
			$lobbyists->{$i}->terminated = date('Y-m-d', strtotime($date[0]));
			$lobbyists->{$i}->name = substr($lobbyists->{$i}->name, 0, $pos);
		}
		
		/*
		 * Drop any blank fields.
		 */
		foreach ($lobbyists->{$i} as $key => &$value)
		{
			if (empty($value))
			{
				unset($lobbyists->{$i}->$key);
			}
		}
		
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
 * Fetch the record for a single lobbyist.
 */
function fetch_lobbyist($url)
{
	if (empty($url))
	{
		return false;
	}
	
	$html = fetch_url($url);
	
	/*
	 * Carve out the bit that we need, ignoring everything else.
	 */
	$start = strpos($html, '<table>');
	$end = strpos($html, '</table>');
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
		
		/*
		 * If we still can't render this as an object, then give up on this lobbyist record.
		 */
		if ($dom === FALSE)
		{
			return FALSE;
		}
	}
	
	/*
	 * Grab two of the table cells.
	 */
	$address = trim($dom->find('td', 0)->plaintext);
	$statement = trim($dom->find('td', 2)->plaintext);
	
	/*
	 * Create an object to store these data in.
	 */
	$lobbyist = new stdClass();
	
	/*
	 * The address field is broken up into two components -- address and phone number.
	 */
	$tmp = explode("\n", $address);
	$lobbyist->address = trim(implode("\n", array_slice($tmp, 1, -1)));
	$lobbyist->phone_number = trim(implode('', array_slice($tmp, -1)));
	
	/*
	 * Reformat the phone number field from (555) 555-1212 to 555-555-1212.
	 */
	$lobbyist->phone_number = preg_replace('/\(([0-9]{3})\) /', '\1-', $lobbyist->phone_number);
	
	/*
	 * The statement field is broken up into two components -- statement and date registered.
	 */
	$tmp = explode('Registered:', $statement);
	$lobbyist->statement = trim($tmp[0]);
	$lobbyist->registered = date('Y-m-d', strtotime(trim($tmp[1])));
	
	/*
	 * Remove any blank lines from the address field and standardize the address.
	 */
	$normalizer = new AddressStandardizationSolution;
	$address = explode("\n", $lobbyist->address);
	foreach ($address as &$tmp)
	{
		$tmp = trim($tmp);
		if (empty($tmp))
		{
			unset($tmp);
		}
		
		$tmp = $normalizer->AddressLineStandardization($tmp);	
	}
	$lobbyist->address = implode("\n", $address);
	
	/*
	 * And now atomize the address.
	 */
	$tmp = explode("\n", $lobbyist->address);
	$lobbyist->address = new stdClass();
	$lobbyist->address->street_1 = $tmp[0];
	if (count($tmp) == 3)
	{
		$lobbyist->address->street_2 = $tmp[1];
	}
	preg_match('/(.*) ([A-Z]{2}) ([0-9]{5})/', array_shift(array_slice($tmp, -1)), $components);
	$lobbyist->address->city = $components[1];
	$lobbyist->address->state = $components[2];
	$lobbyist->address->zip_code = $components[3];
	
	return $lobbyist;
	
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
 * Create the directories, if they don't already exist.
 */
if (!file_exists(PERIOD_DIR))
{
	mkdir(PERIOD_DIR);
}
if (!file_exists(LOBBYIST_DIR))
{
	mkdir(LOBBYIST_DIR);
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
	
	echo 'Retrieving ' . $period_range . '...';
	
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
		
		/*
		 * Get the list of registrations from the file, so that we can iterate through them and
		 * retrieve additional data about them.
		 */
		$registrations = json_decode(file_get_contents(PERIOD_DIR . '/' . $filename));
		
	}
	
	/*
	 * Iterate through the list of lobbyists in this batch of registrations and retrive the record
	 * for each one of them.
	 */
	foreach ($registrations as $registration)
	{
		
		/*
		 * Only save this lobbyist registration if we haven't done so already.
		 */
		if (!file_exists(LOBBYIST_DIR . '/' . $registration->id . '.json'))
		{
		
			$lobbyist = fetch_lobbyist($registration->url);
			
			if ($lobbyist === FALSE)
			{
				echo 'Error: Could not retrieve lobbyist record ' . $registration->id . PHP_EOL;
			}
			
			/*
			 * Append this new data to the existing registration data.
			 */
			$tmp = array_merge((array) $registration, (array) $lobbyist);
			$registration = (object) $tmp;
			unset($tmp);
			
			/*
			 * Store this lobbyist record in the filesystem.
			 */
			$result = file_put_contents(LOBBYIST_DIR . '/' . $registration->id . '.json', json_encode($registration));
			
			/*
			 * If we cannot store this lobbyist record, then we've got bigger problems -- abandon
			 * the entire process.
			 */
			if ($result === FALSE)
			{
				die('Unable to write lobbyist record to ' . LOBBYIST_DIR . '/' . $registration->id . '.json.');
			}
			
			echo 'Saved ' . $registration->name . '.' . PHP_EOL;
			
		}
		
	}
	
}
