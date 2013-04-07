<?php

require_once('Zend/Gdata/Photos.php');
require_once('Zend/Gdata/AuthSub.php');
require_once('Zend/Gdata/Photos/AlbumQuery.php');
require_once('Zend/Gdata/Photos/PhotoQuery.php');
require_once('Zend/Gdata/Photos/UserQuery.php');

class Inverted_GoogleApps_Picasa_API
{
	protected $session_token;

	protected $service;

	function __construct()
	{
		add_action('init', array(&$this, 'init'));
	}

	public function init()
	{
		global $inverted_gapps;
		global $user_ID;
		get_currentuserinfo();

		// check whether we are receiving the initial AuthSub token from google
		if(isset($_GET['token']))
		{
			$authsub_token = $_GET['token'];

			error_log($authsub_token);

			// request the session token
			$session_token = Zend_Gdata_Authsub::getAuthSubSessionToken($authsub_token);

			update_user_meta($user_ID, 'inverted_gapps_picasa_session_token', $session_token);

			$redirect = $_SESSION['picasa_auth_redirect'];
			unset($_SESSION['picasa_auth_redirect']);

			wp_redirect($redirect);
			exit;
		}

		$this->session_token = get_user_meta($user_ID, 'inverted_gapps_picasa_session_token', true);

		if($this->session_token)
		{
			$http_client = Zend_Gdata_AuthSub::getHttpClient($this->session_token);
			$this->service = new Zend_Gdata_Photos($http_client);
		}
	}

	public function check_authentication()
	{
		global $user_ID;
		get_currentuserinfo();

		if(get_user_meta($user_ID, 'inverted_gapps_picasa_session_token', true) == false)
		{
			$this->picasa_authsub();
			exit;
		}

		return true;
	}

	/**
	 * Perform authentication via AuthSub
	 */
	public function picasa_authsub()
	{
		// get the uri for token request, make sure we use the blog url
		$uri = Zend_Gdata_Authsub::getAuthSubTokenUri(home_url(), Zend_Gdata_Photos::PICASA_BASE_URI, 0, 1);

		// since the google will redirect to the home url with the token, we need to redirect to the upload content
		// after storing the session token
		$_SESSION['picasa_auth_redirect'] = get_upload_iframe_src('picasa') . "&login_successful=true";

		echo apply_filters('inverted_gapps_picasa_authsub_login', '', $uri);
	}

	public function clear_token()
	{
		global $user_ID;
		get_currentuserinfo();

		update_user_meta($user_ID, 'inverted_gapps_picasa_session_token', false);
	}

	/**
	 * Get the user feed
	 *
	 * @param string $location (Optional) Url to query
	 * @param mixed Either Zend_Gdata_UserFeed object or false on error
	 */
	public function get_user_feed($location = null)
	{
		try
		{
			$user_feed = $this->service->getUserFeed(null, $location);

			return $user_feed;
		}
		catch(Exception $e)
		{
			if((int)$e->getResponse()->getStatus() == 403)
			{
				$this->clear_token();
				$this->check_authentication();
			}

			error_log($e->getMessage());
		}
	}


	public function get_photos($album_id = 'all')
	{
		if($album_id == 'all')
		{
			$user_query = new Zend_Gdata_Photos_UserQuery();
			$user_query->setKind('photo');
			return $this->get_user_feed($user_query);
		}

		try
		{
			$query = new Zend_Gdata_Photos_AlbumQuery();
			
			$query->setAlbumId($album_id);
			$album_feed = $this->service->getAlbumFeed($query);

			return $album_feed;
		}
		catch(Exception $e)
		{
			error_log($e->getMessage());
		}
	}

	public function get_photo($photo_url)
	{
		try
		{
			$entry = $this->service->getPhotoEntry($photo_url);

			return $entry;
		}
		catch(Exception $e)
		{
			error_log($e->getMessage());
		}
	}

	public function search_photos($query)
	{
		// the psc=S query parameter ensures that it searches just for the user
		$user_query = new Zend_Gdata_Photos_UserQuery();
		$user_query->setParam('q', $query);
		$user_query->setParam('psc', 'S');
		$user_query->setKind('photo');

		try
		{
			$feed = $this->service->getUserFeed(null, $user_query);

			return $feed;
		}
		catch(Exception $e)
		{
			error_log($e->getMessage());
		}
	}

	public function add_photo($location, $name, $album_id)
	{
		$file_parts = explode(".", $name);
		$extension = end($file_parts);

		if(!in_array($extension, array('png', 'jpeg', 'jpg', 'bmp')))
			return false;
		else
		{
			if($extension == 'jpg')
				$extension = 'jpeg';

			$content_type = "image/" . $extension;
		}

		$fd = $this->service->newMediaFileSource($location);
		$fd->setContentType($content_type);

		$entry = new Zend_Gdata_Photos_PhotoEntry();
		$entry->setMediaSource($fd);
		$entry->setTitle($this->service->newTitle($name));

		$album_query = new Zend_Gdata_Photos_AlbumQuery();
		$album_query->setAlbumId($album_id);

		$album_entry = $this->service->getAlbumEntry($album_query);

		$this->service->insertPhotoEntry($entry, $album_entry);	

		return true;
	}

}