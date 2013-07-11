<?php defined('SYSPATH') or die('No direct script access.');
/**
 * smsautomate Hook - Load All Events
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author	   Ushahidi Team <team@ushahidi.com> 
 * @package	   Ushahidi - http://source.ushahididev.com
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license	   http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL) 
 */

class smsautomate {

	/**
	 * Registers the main event add method
	 */
	public function __construct()
	{

		// Hook into routing
		Event::add('system.pre_controller', array($this, 'add'));

		$this->settings = ORM::factory('smsautomate')
			->where('id', 1)
			->find();

	}

	/**
	 * Adds all the events to the main Ushahidi application
	 */
	public function add()
	{
		Event::add('ushahidi_action.message_sms_add', array($this, '_parse_sms'));		
	}

	/**
	 * Check the SMS message and parse it
	 */
	public function _parse_sms()
	{
		//the message
		$message = Event::$data->message;
		$from = Event::$data->message_from;
		$reporterId = Event::$data->reporter_id;
		$message_date = Event::$data->message_date;

		// We store a reference of the Event for updating it later
		$sms_event = &Event::$data;

		$form = array(
			'incident_title' => '',
			'incident_description' => '',
			'incident_date' => '',
			'incident_hour' => '',
			'incident_minute' => '',
			'incident_ampm' => '',
			'latitude' => '',
			'longitude' => '',
			'location_name' => '',
			'incident_category' => array(),
			'person_first' => '',
			'person_last' => '',
			'person_email' => '',
			'form_id'	  => '',
			'custom_field' => array(),
			'service_id' => 1  // Mode : sms
		);

		//check to see if we're using the white list, and if so, if our SMSer is whitelisted
		$num_whitelist = ORM::factory('smsautomate_whitelist')
			->count_all();
		if($num_whitelist > 0)
		{
			//check if the phone number of the incoming text is white listed
			$whitelist_number = ORM::factory('smsautomate_whitelist')
				->where('phone_number', $from)
				->count_all();
			if($whitelist_number == 0)
			{
				return;
			}
		}

		//the delimiter
		$delimiter = $this->settings->delimiter;

		//the code word
		$code_word = $this->settings->code_word;


		//split up the string using the delimiter
		$message_elements = explode($delimiter, $message);

		//echo Kohana::debug($message_elements);

		//check if the message properly exploded
		$elements_count = count($message_elements);

		if( $elements_count < 4) //must have code word, lat, lon, title. Which is 4 elements
		{
			return;
		}

		//check to see if they used the right code word, code word should be first
		if(strtoupper($message_elements[0]) != strtoupper($code_word))
		{
			return;
		}

		//start parsing
		//latitude
		$post['latitude'] = strtoupper(trim($message_elements[1]));

		//longitude
		$post['longitude'] = strtoupper(trim($message_elements[2]));

		//title
		$post['incident_title'] = trim($message_elements[3]);

		//location
		$location_description = "";
		//check and see if we have a textual location
		if($elements_count >= 5)
		{
			$location_description =trim($message_elements[4]);
		}
		if($location_description == "")
		{
			$location_description = "Sent Via SMS";
		}
		$post['location_name'] = $location_description;

		$description = "";
		//check and see if we have a description
		if($elements_count >= 6)
		{
			$description = $description.trim($message_elements[5]);
		}

		// TODO NOTE : Make the appended text optionable and configurable
		$post['incident_description'] = $description."\n\r\n\rThis reported was created automatically via SMS.";

		//check and see if we have categories
		if($elements_count >=7)
		{
			$post['incident_category'] = explode(",", $message_elements[6]);
		}

		//Date
		$date = DateTime::createFromFormat('Y-m-d H:i:s', $message_date);

		$post['incident_date'] = $date->format('m/d/Y'); // mm/dd/yyyy
		$post['incident_hour'] = $date->format('h');
		$post['incident_minute'] = $date->format('i');
		$post['incident_ampm'] = $date->format('a');

		//for testing:
		/*
		echo "lat: ". $lat."<br/>";
		echo "lon: ". $lon."<br/>";
		echo "title: ". $title."<br/>";
		echo "description: ". $description."<br/>";
		echo "category: ". Kohana::debug($categories)."<br/>";
		 */


		// We re-use the same process as Reports_Controller->submit()
		if (reports::validate($post))
		{
			// STEP 1: SAVE LOCATION
			$location = new Location_Model();
			reports::save_location($post, $location);

			// STEP 2: SAVE INCIDENT
			$incident = new Incident_Model();
			reports::save_report($post, $incident, $location->id);

			// STEP 2b: SAVE INCIDENT GEOMETRIES
			// We don't have any geometries here
			// reports::save_report_geometry($post, $incident);

			// STEP 3: SAVE CATEGORIES
			reports::save_category($post, $incident);

			// STEP 4: SAVE MEDIA
			// We don't have any media here
			// reports::save_media($post, $incident);

			// STEP 5: SAVE CUSTOM FORM FIELDS
			reports::save_custom_fields($post, $incident);

			// STEP 6: SAVE PERSONAL INFORMATION
			reports::save_personal_info($post, $incident);

			// Don't forget to update the message with Incident Id
			$sms_event->incident_id = $incident->id;
			$sms_event->save();

			// Run events
			Event::run('ushahidi_action.report_submit', $post);
			Event::run('ushahidi_action.report_add', $incident);

		} else {
			echo "ERROR";
			//	echo Kohana::debug($post);
			print_r($post->errors('report'));

			// Save the error trace inside the message
			$sms_event->message = 'VALIDATION ERROR' . "\n" . $sms_event->message;

			$errors = "\n\n" . 'ERROR TRACE :' . "\n" . print_r($post->errors('report'), TRUE);
			$sms_event->message .= $errors;

			$sms_event->save();

		}

		// TODO : Add option to automatically activate & verify reports	

	}


}

new smsautomate;
