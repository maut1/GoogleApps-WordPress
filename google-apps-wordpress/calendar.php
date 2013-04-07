<?php

require_once('Zend/Gdata/Calendar.php');
require_once('Zend/Gdata/Calendar/EventEntry.php');

class Inverted_GoogleApps_Calendar
{
	protected $service;

	/**
	 * constructor
	 */
	public function __construct()
	{
		add_action('init', array(&$this, 'init'));
		add_action('admin_init', array(&$this, 'admin_init'));
		add_action('save_post', array(&$this, 'save_post'), 10, 2);
	}

	/**
	 * init action hook
	 */
	public function init()
	{
		$http_client = Inverted_GoogleApps::get_http_client();
		$this->service = new Zend_Gdata_Calendar($http_client);
	}

	public function admin_init()
	{
		add_settings_section('svga-calendar', 'Google Calendar', array(&$this, 'writing_settings_section'), 'writing');
		add_settings_field('svga-calendar-publish-calendar', 'Publish Calendar', array(&$this, 'publish_calendar_setting'), 'writing', 'svga-calendar');

		register_setting('inverted_gapps_calendar', 'inverted_gapps_calendar');
	}

	/**
	 * save_post action hook
	 *
	 * @param int $post_id The post ID
	 * @param object $post The post object
	 */
	public function save_post($post_id, $post)
	{
		// check if we already have an event or not
		$event_id = get_post_meta($post_id, 'inverted_gapps_calendar_event_id', true);

		if($event_id)
			$this->modify_event($post_id, $post);
		else
			$this->create_event($post_id, $post);
	}

	public function create_event($post_id, $post)
	{
		global $user_email;
	  get_currentuserinfo();

		// check that its set for a future publish
		if(empty($post) || ($post->post_status != 'future'))
			return;

		$publish_calendar = $this->get_publish_calendar();

		// build the event
		$event = $this->service->newEventEntry();
		$event->title = $this->service->newTitle($post->post_title);

		// set start and end time in RFC3339 format
		$publish_time = strtotime($post->post_date_gmt . ' GMT');
		$when = $this->service->newWhen();
		$when->startTime = date(DateTime::RFC3339, $publish_time);
		$when->endTime = date(DateTime::RFC3339, ($publish_time + (10 * 60)));
		$event->when = array($when);

		$uri = 'https://www.google.com/calendar/feeds/' . $publish_calendar . '/private/full' . "?xoauth_requestor_id=" . urlencode($user_email);

		try
		{
			$new_event = $this->service->insertEvent($event, $uri);

			// store event id
			$event_id = $new_event->id->text;

			update_post_meta($post_id, 'inverted_gapps_calendar_event_id', $event_id);
		}
		catch(Exception $e)
		{
			error_log($e->getMessage());
		}
	}

	public function modify_event($post_id, $post)
	{
		$event_id = get_post_meta($post_id, 'inverted_gapps_calendar_event_id', true);

		try
		{
			global $user_email;
			get_currentuserinfo();

			// add xoauth_requestor_id and change to https
			$event_id .= "?xoauth_requestor_id=" . urlencode($user_email);
			$event_id = str_replace('http', 'https', $event_id);

			// get event
			$event = $this->service->getCalendarEventEntry($event_id);

			// if post has been put in the trash
			if($post->post_status == 'trash')
			{
				delete_post_meta($post_id, 'inverted_gapps_calendar_event_id');
				$event->delete();

				return;
			}

			$publish_time = strtotime($post->post_date_gmt . ' GMT');

			// set title
			$event->title = $this->service->newTitle($post->post_title);

			// set start and end time in RFC3339 format
			$when = $this->service->newWhen();
			$when->startTime = date(DateTime::RFC3339, $publish_time);
			$when->endTime = date(DateTime::RFC3339, ($publish_time + (10 * 60)));
			$event->when = array($when);

			$event->save();
		}
		catch(Zend_Gdata_App_Exception $e)
		{
			error_log($e->getMessage());
		}

	}

	/**
   * Return  the publish calendar, if not set, use primary
	 *
	 * @return string The publish calendar
	 */
	protected function get_publish_calendar()
	{
		$options = get_option('inverted_gapps_calendar');
		if($options && $options['publish_calendar'])
		{
			$publish_calendar = $options['publish_calendar'];
		}
		else
		{
			$publish_calendar = 'primary';
		}

		return $publish_calendar;
	}


	public function writing_settings_section()
	{
	}

	/**
   * Display setting for publish calendar
	 *
	 * @param array $args Arguments
	 */
	public function publish_calendar_setting($args)
	{
		$options = get_option('inverted_gapps_calendar');

		if(is_array($options))
			extract($options);

		// get calendars
		$calendars = $this->get_calendars();

		foreach($calendars as $calendar)
		{
			$id_parts = explode("/", $calendar->id->text);
			$calendar_options[end($id_parts)] = $calendar->title->text;
		}

		// need this to save our options
		settings_fields('inverted_gapps_calendar');

		?>
    <select name="inverted_gapps_calendar[publish_calendar]">
			<?php foreach($calendar_options as $id => $title): ?>
			<option value="<?php echo $id; ?>" <?php echo selected($publish_calendar, $id); ?>><?php echo $title; ?></option>
			<?php endforeach; ?>
		</select>

		<?php
	}


	public function get_calendars()
	{
		global $user_email;
	  get_currentuserinfo();

		$uri = Zend_Gdata_Calendar::CALENDAR_FEED_URI . '/default/?xoauth_requestor_id=' . urlencode($user_email);
		return $this->service->getFeed($uri,'Zend_Gdata_Calendar_ListFeed');
	}
}



?>