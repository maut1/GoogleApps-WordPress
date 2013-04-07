<?php

class Inverted_GoogleApps_Docs_API
{

	function __construct()
	{
		add_action('init', array(&$this, 'init'));
	}

	public function init()
	{
		$http_client = Inverted_GoogleApps::get_http_client();
		$this->service = new Zend_Gdata_Docs($http_client);
	}

	/**
	 * Get Document List Feed
	 *
	 * Parameters are:
	 *  - search: Search query string. If set, a search query will be performed
	 *  - max-results: Maximum results to return
	 *  - doc_type: Document type
	 *
	 * @param array $params (Optional) Parameters array
	 */
	public function get_document_list_feed($params = array())
	{
		global $current_user;
		global $user_email;
	  get_currentuserinfo();

		$endpoint = 'https://docs.google.com/feeds/default/private/full';

		$params = array_merge(array('search' => '', 'max-results' => 500, 'doc_type' => 'all_types'), $params);

		$doc_type = $params['doc_type'];
		$max_results = $params['max-results'];
		$search = $params['search'];

		// sanitise parameters
		if($max_results > 1000)	$max_results = 1000;
		if(!in_array($doc_type, Inverted_GoogleApps_Docs::$doc_types)) $doc_type = 'all_types';

		// set document type
		if($doc_type != 'all_types') $endpoint .= '/-/' . $doc_type;

		$endpoint .= '?v=3&max-results=' . $max_results . '&xoauth_requestor_id=' . urlencode($user_email);

		if($search && $search != '')
			$endpoint .= '&q=' . urlencode($search);

		try
		{
			$feed = $this->service->getDocumentListFeed($endpoint);
		}
		catch(Exception $e)
		{
			echo $e->getMessage();
		}

		return $feed;
	}

	/**
	 * Get revisions for a particular resource
	 *
	 * @param string $resource_id Resource Id of the document
	 * @return mixed An array of revisions or false
	 */
	public function get_entry_revisions($resource_id)
	{
		global $user_email;
	  get_currentuserinfo();

		$endpoint = 'https://docs.google.com/feeds/default/private/full/' . $resource_id . '/revisions';
		$endpoint .= '?v=3&xoauth_requestor_id=' . urlencode($user_email);

		try
		{
			$feed = $this->service->getDocumentListFeed($endpoint);
		}
		catch(Exception $e)
		{
			echo $e->getMessage();
		}

		$num_entries = count($feed->entries);
		if($num_entries > 0)
			return $feed->entries;

		return false;
	}

	/**
	 * Determine if an entry is published
	 *
	 * @param string $resource_id The resource ID
	 * @return boolean true if published, otherwise false
	 */
	public function get_entry_published($resource_id)
	{
		global $current_user;
		global $user_email;
	  get_currentuserinfo();

		$endpoint = 'https://docs.google.com/feeds/default/private/full/' . $resource_id . '/revisions';
		$endpoint .= '?v=3&xoauth_requestor_id=' . urlencode($user_email);

		try
		{
			$feed = $this->service->getDocumentListFeed($endpoint);
		}
		catch(Exception $e)
		{
			echo $e->getMessage();
		}

		$num_entries = count($feed->entries);
		if($num_entries > 0)
		{
			// get last revision
			$revision = $feed->entries[$num_entries - 1];
			$exts = $revision->getExtensionElements();

			foreach($exts as $ext)
			{
				if($ext->rootElement == 'publish')
				{
					$atts = $ext->getExtensionAttributes();
					if($atts['value']['value'] == true)
						return true;
				}
			}
		}

		return false;
	}

	/**
	 * Set an entry either as published or unpublished
	 *
	 * @param object $entry The document entry
	 * @param boolean $publish If true, will publish, false will unpublish
	 */
	public function set_entry_published($entry, $publish)
	{
		try
		{
			$revisions = $this->get_entry_revisions($entry->resource_id);
			if(!$revisions)
				throw new Exception("No revisions for entry");

			// get last revision
			$revision = $revisions[count($revisions) - 1];

			$exts = $revision->getExtensionElements();

			if(!$publish)
			{
				foreach($exts as $ext)
				{
					// change XML element's value to false
					if($ext->rootElement == 'publish')
					{
						$atts = $ext->getExtensionAttributes();
						$atts['value']['value'] = "false";
						$ext->setExtensionAttributes($atts);
					}
				}
			}
			else
			{
				// we need to have the following element added and set to publish
				$extensions = array('publish', 'publishAuto', 'publishOutsideDomain');

				$exts = array();

				foreach($extensions as $extension)
				{
					$ext = new Zend_Gdata_App_Extension_Element($extension, '', 'http://schemas.google.com/docs/2007');
					$atts = array('value' => array('namespaceUri' => null, 'name' => 'value', 'value' => "true"));
					$ext->setExtensionAttributes($atts);
					$exts[] = $ext;
				}
			}

			$revision->setExtensionElements($exts);

			// update the entry
			$this->service->updateEntry($revision, $revision->link[1]->href);

			$entry->published = $publish;

			return $entry;
		}
		catch(Zend_Gdata_App_Exception $e)
		{
			$status = (int)$e->getResponse()->getStatus();

			return "Google Docs API failure, code " . $status;
		}
		catch(Exception $e)
		{
			return $e->getMessage();
		}
	}

	/**
	 * Retrieve the user metadata feed
	 *
	 * @return string The raw XML response
	 */
	public function get_user_metadata()
	{
		global $user_email;
	  get_currentuserinfo();

		$endpoint = 'https://docs.google.com/feeds/metadata/default';
		$endpoint .= '?v=3&xoauth_requestor_id=' . urlencode($user_email);

		try
		{
			$response = $this->service->performHttpRequest('GET', $endpoint);

			return $response->getBody();
		}
		catch(Exception $e)
		{
			echo $e->getMessage();
		}
	}

	/**
	 * Get changes for the document entries. This is done by fetching the changes feed,
	 * providing the largestChangestamp value that was stored in get_entries_cache.
	 *
	 * @see get_entries_cache
	 */
	public function get_document_list_changes()
	{
		global $user_email;
		global $user_ID;
	  get_currentuserinfo();

		$endpoint = 'https://docs.google.com/feeds/default/private/changes';
		$endpoint .= '?v=3&xoauth_requestor_id=' . urlencode($user_email);

		// get the last change stamp
		$ts = get_user_meta($user_ID, 'inverted_gapps_largest_changestamp', true);

		// @todo output a warning that it can't detect changes and needs a refresh
		if(!$ts)
			return;

		// add one (see google docs api documentation)
		$ts++;

		$endpoint .= '&start-index=' . $ts;

		try
		{
			$feed = $this->service->getDocumentListFeed($endpoint);
		}
		catch(Exception $e)
		{
			echo $e->getMessage();
		}

		return $feed;
	}


}


?>