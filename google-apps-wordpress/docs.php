<?php


require_once('Zend/Oauth/Consumer.php');
require_once('Zend/Gdata/Docs.php');
require_once('Zend/Gdata/Docs/DocumentListFeed.php');
require_once('Zend/Gdata/Docs/DocumentListEntry.php');
require_once('Zend/Gdata/Docs/Query.php');


require_once('docs-api.php');

class Inverted_GoogleApps_Docs
{
	/* Zend_Gdata_Docs object */
	protected $client;

	/* Current entries */
	protected $entries = array();

	/* 24 hours cache */
	const CACHE_TIMEOUT = 86400;

	/* Cache key */
	const ENTRIES_LIST_CACHE_KEY = 'inverted_gapps_entries_list';

	/* Download formats */
	public static $download_formats = array('document' => array('html', 'doc', 'odt', 'pdf', 'png', 'rtf', 'txt', 'zip'),
																					'drawing' => array('jpeg', 'pdf', 'png', 'svg'),
																					'presentation' => array('pdf', 'png', 'ppt', 'txt'),
																					'spreadsheet' => array('xls', 'csv', 'pdf'),
																					'pdf' => array('pdf'));


	/* Document types */
	public static $doc_types = array('all_types' => 'All Types',
																	 'document' => 'Documents',
																	 'presentation' => 'Presentations',
																	 'spreadsheet' => 'Spreadsheets',
																	 'drawing' => 'Drawings',
																	 'pdf' => 'PDF');

	/**
	 * Constructor
	 */
	public function __construct()
	{
		add_action('init', array(&$this, 'init'));
		add_action('admin_print_styles', array(&$this, 'admin_print_styles'));

		// filters and actions for thickbox display
		add_action('media_buttons', array(&$this, 'media_button'), 12);
		add_filter('media_upload_tabs', array(&$this, 'media_upload_tabs'));
		add_filter('media_upload_default_tab', array(&$this, 'media_upload_default_tab'));
		add_action('media_upload_gdocs', array(&$this, 'media_upload'));
		add_action('media_upload_gdocs_upload', array(&$this, 'media_upload_gdocs_upload'));
		add_action('media_upload_gdocs_list', array(&$this, 'media_upload_gdocs_list'));

		// ajax actions
		add_action('wp_ajax_gdocs_publish_toggle', array(&$this, 'ajax_publish_toggle'));

		add_shortcode('gdoc', array(&$this, 'embed_shortcode'));

		$this->api = new Inverted_GoogleApps_Docs_API();
	}

	/**
	 * init action hook
	 */
	public function init()
	{
		$css_url = plugins_url('/css', __FILE__);

		wp_register_style('inverted-google-apps-docs', $css_url . "/docs.css", 'inverted-google-apps');
	}

	/**
	 * Print styles and scripts if required
	 */
	public function admin_print_styles()
	{
		if($_GET['type'] == 'gdocs')
		{
			wp_enqueue_style('inverted-google-apps');
			wp_enqueue_style('inverted-google-apps-docs');
			wp_enqueue_script('inverted-google-apps');
		}
	}

	/**
	 * Display the media button above the editor
	 */
	public function media_button()
	{
		$type = 'gdocs';
		$id = 'gdocs';
		$title = "Add Google Docs";
		$icon =  plugins_url('/images/google-docs-logo.png', __FILE__);

		echo "<a href='" . esc_url( get_upload_iframe_src($type) ) . "' id='{$id}-add_{$type}' class='thickbox add_$type' title='" . esc_attr( $title ) . "'><img src='" . $icon . "' alt='$title' onclick='return false;' /></a>";

	}

	/**
	 * Set the tabs for google docs box
	 *
	 * @param array $tabs Tabs to filter
	 * @return array New tabs
	 */
	public function media_upload_tabs($tabs)
	{
		if($_GET['type'] == 'gdocs')
			return array('gdocs_upload' => 'Upload', 'gdocs_list' => 'Documents');

		return $tabs;
	}

	/**
	 * Set default tab for google docs box
	 *
	 * @param array $default Default tab to filter
	 * @return array Default tab
	 */
	public function media_upload_default_tab($default)
	{
		if($_GET['type'] == 'gdocs')
			return 'gdocs_upload';

		return $default;
	}

	/**
	 * Display tabs
	 */
	public function media_upload_content_tabs()
	{
		?>
		<div id="media-upload-header">
			<?php the_media_upload_tabs(); ?>
		</div><!-- #media-upload-header -->
		<?php
	}

	/**
	 * Wrapper google docs box iframe
	 */
	public function media_upload()
	{
		wp_iframe(array(&$this, 'media_upload_content'));
	}

	/**
	 * Display the google docs box iframe contents
	 */
	public function media_upload_content()
	{
		$this->media_upload_gdocs_upload();		
	}

	/**
	 * Wrapper for google docs upload tab
	 */
	public function media_upload_gdocs_upload()
	{
		$upload_successful = false;

		if(isset($_FILES['gdocs_file']))
		{
			global $user_email;
			get_currentuserinfo();

			$temp_name = $_FILES['gdocs_file']['tmp_name'];
			$name = $_FILES['gdocs_file']['name'];

			$feed = $this->api->get_document_list_feed(array('max-results' => 1));

			$link = $feed->getLink('http://schemas.google.com/g/2005#resumable-create-media')->href;

			// redo the query string as xoauth_requestor_id is not urlencoded
			$link = preg_replace('/(.*)\?(.*)/', '$1', $link);
			$link .= "?v=3&xoauth_requestor_id=" . urlencode($user_email);

			$file_parts = explode(".", $name);
			$extension = end($file_parts);

			if(isset($_POST['convert']) && $_POST['convert'] == 'on')
				$convert = '&convert=true';
			else
				$convert = '';

			if(in_array($extension, array('png', 'jpeg', 'jpg')))
			{
				$mime_type = "image/" . $extension;
				$convert = '&convert=false';
			}
			else
			{
				$mime_type = Zend_Gdata_Docs::lookupMimeType($extension);
			}

			$link .= $convert;

			try
			{
				$session = $this->client->performHttpRequest('POST', $link,
																										 array('GData-Version' => '3.0',
																													 'Content-Length' => 0,
																													 'Content-Type' => $mime_type,
																													 'Slug' => $name,
																													 'X-Upload-Content-Type' => $mime_type,
																													 'X-Upload-Content-Length' => filesize($temp_name)),
																										 '',
																										 $mime_type);

				$headers = $session->getHeaders();

				$new_entry = $this->client->uploadFile($temp_name, $name, $mime_type, $headers['Location']);

				$upload_successful = true;
			}
			catch(Exception $e)
			{
				echo $e->getMessage();
			}

		}

		return wp_iframe(array(&$this, 'media_upload_gdocs_upload_content'), $upload_successful);
	}

	/**
	 * Display upload tab contents
	 *
	 * @param boolean $upload_successful Whether the upload was successful
	 */
	public function media_upload_gdocs_upload_content($upload_successful)
	{
		$this->media_upload_content_tabs();
		?>

		<form action="" method="post" enctype="multipart/form-data" id="gdocs-upload">

		  <?php	if($upload_successful):	?>
		  <p>File successfully uploaded</p>
			<?php	endif; ?>

			<p>
				<input type="file" name="gdocs_file" />
			</p>
			<p>
				<input type="checkbox" name="convert" /><label for="convert">Convert to Google Docs Format</label>
			</p>
			<p>
				<input type="submit" class="button-secondary" value="Upload" />
			</p>
		</form>
    <?php
	}

	/**
	 * Wrapper for google docs list tab content
	 */
	public function media_upload_gdocs_list()
	{
		// detect if we are embedding 
		if(isset($_POST['gdocs_insert']))
		{
			$resource_id = esc_attr($_POST['resource_id']);

			if($_POST['insert_as'] == 'embed')
			{
				$height = (int)$_POST['insert_height'];
				$doc_type = esc_attr($_POST['doc_type']);

				if($doc_type == 'drawing')
				{
					$width = (int)$_POST['insert_width'];
					$this->insert_image($resource_id, $width, $height);
				}
				else
				{
					$this->embed_resource_shortcode($resource_id, $height, $doc_type);
				}
			}
			elseif($_POST['insert_as'] == 'download')
			{
				$format = esc_url($_POST['download_format']);
				$this->insert_download_link($resource_id, $format);
			}
		}

		wp_iframe(array(&$this, 'media_upload_gdocs_list_content'));
	}

	/**
	 * Display google docs list tab content
	 */
	public function media_upload_gdocs_list_content()
	{
		$items_per_page = 20;

		// detect changes
		$this->document_list_changes();

		$this->media_upload_content_tabs();

		$doc_type = $_GET['doc_type'];
		$paged = $_GET['paged'];

		if(!$paged)
			$paged = 1;

		// check document type
		$categories = array_keys(self::$doc_types);
		if(!in_array($doc_type, $categories))
			$doc_type = 'all_types';

		// check if this is a search query
		if(isset($_GET['gdocs_search_query']) && $_GET['gdocs_search_query'] != '')
		{
			$query = esc_attr($_GET['gdocs_search_query']);
			$feed = $this->api->get_document_list_feed(array('search' => $query, 'doc_type' => $doc_type));

			$raw_entries = $feed->entries;
			foreach($raw_entries as $entry)
			{
				$processed_entry = $this->process_entry($entry);

				// allow filtering during search
				if($doc_type != 'all_types' && $doc_type != $processed_entry->type)
					continue;

				$entries[] = $processed_entry;
			}
		}
		else
		{
			$entries = $this->get_entries($doc_type);
			$this->prefetch_publish_status($entries, $start, $end);
		}

		$num_pages = ceil(count($entries) / $items_per_page);

		// start and end of current page
		$start = ($paged - 1) * $items_per_page;
		$end = min(count($entries), $start + $items_per_page);

		?>
		<form id="filter" method="get" action="">
			<p class="search-box">
				<input type="hidden" name="type" value="gdocs" />
				<input type="hidden" name="tab" value="gdocs_list" />
				<input id="media-search-input" type="text" value="<?php echo $query; ?>" placeholder="Search Documents" name="gdocs_search_query" />
				<input class="button" type="submit" value="Search Docs" />
			</p>

			<?php $this->display_filter_menu(self::$doc_types); ?>

			<div class="tablenav">
				<div class="tablenav-pages">

					<?php if($paged > 1): ?>
					<a class="page-numbers" href="<?php echo add_query_arg(array('paged' => $paged - 1)); ?>">&laquo;</a>
					<?php endif; ?>

					<?php for($page = max(1, $paged - 3); $page < $paged; $page++): ?>
					<a class="page-numbers" href="<?php echo add_query_arg(array('paged' => $page)); ?>"><?php echo $page; ?></a>
					<?php endfor; ?>

					<a class="current page-numbers" href="<?php echo add_query_arg(array('paged' => $paged)); ?>"><?php echo $page; ?></a>

					<?php for($page = $paged + 1; $page < min($paged + 3, $num_pages + 1); $page++): ?>
					<a class="page-numbers"	 href="<?php echo add_query_arg(array('paged' => $page )); ?>"><?php echo $page; ?></a>
					<?php endfor; ?>

					<?php if($paged < $num_pages): ?>
					<a class="page-numbers" href="<?php echo add_query_arg(array('paged' => $paged + 1)); ?>">&raquo;</a>
					<?php endif; ?>

				</div><!-- .tablenav-pages -->
			</div><!-- .tablenav -->

		</form>

		<?php

		// if no documents found display a message
		if(count($entries) == 0)
		{
		?>
		<div class="no-results">No documents found</div>
		<?php
			return;
		}
		?>

		<div id="media-items" class="gdocs">
			<?php
			 for($i = $start; $i < $end; $i++):
				 $entry = $entries[$i];
			?>
			<div class="media-item">

				<div class="docs-icon docs-icon-<?php echo $entry->type; ?>"></div>

				<div class="filename">
					<span class="title"><?php echo $entry->title; ?></span>
				</div>

				<div class="owner">
					<?php echo $entry->author; ?>
				</div>

				<div class="last-updated">
					<?php echo date('j M', strtotime($entry->updated)); ?>
				</div>

				<div class="insert-options">
					<form action="" method="post">

						<p class="insert-as">
							<label for="insert_as">Insert As</label>
							<span class="choices">
								<input type="radio" class="embed" name="insert_as" value="embed" checked="checked" /><label for="insert_as">Embed</label>
								<input type="radio" class="insert" name="insert_as" value="download" /><label for="insert_as">Download Link</label>
							</span>
						</p>

						<div class="embed insert-type">
              <?php if($entry->type != 'pdf'): ?>
							<p class="publish-toggle">
								<span class="action">
									<?php if($entry->published): ?>
									<label>This Document is published</label><input type="button" value="Stop Publishing" data-status="stop" />
									<?php else: ?>
									<label>This Document is not published</label><input type="button" value="Start Publishing" data-status="start" />
									<?php endif; ?>
								</span>
								<img src="<?php echo admin_url('images/wpspin_light.gif'); ?>" class="loading" />
								<span class="error"></span>
							</p>
              <?php else: ?>
						  <p>Your document must be visible at least to anyone with the link for it to be viewable in an embedded format</p>
              <?php endif; ?>

							<?php if($entry->type == 'drawing'): ?>
							<p>
								<label for="insert_width">Width</label><input type="text" value="800" name="insert_width" /> px
							</p>
							<?php endif; ?>

							<p>
								<label for="insert_height">Height</label><input type="text" value="600" name="insert_height" /> px
							</p>
						</div><!-- .embed -->

						<?php 
            $formats = self::$download_formats[$entry->type];	

						if($formats && !empty($formats)):
						?>
						<div class="download insert-type">

							<div class="download-format">
								<label for="download_format">Format</label>

								<select name="download_format">
						
									<?php	foreach($formats as $format):	?>
									<option value="<?php echo $format; ?>"><?php echo $format ?></option>
									<?php endforeach;	?>

								</select>
							</div><!-- .download-format -->

						</div><!-- .download -->
						<?php endif; ?>

						<p>
							<input type="hidden" value="<?php echo $entry->resource_id; ?>" name="resource_id" />
							<input type="hidden" value="<?php echo $entry->type; ?>" name="doc_type" />
							<input type="submit" class="button-secondary" value="Insert" name="gdocs_insert" />
						</p>
					</form>
				</div><!-- .gdocs-insert -->

			</div><!-- .media-item -->

			<?php endfor; ?>

		</div><!-- #media-items -->

		<?php		
	}

	/**
	 * Display menu for filtering across document types
	 *
	 * @param array $menu Associative array of document type with label
	 */
	public function display_filter_menu($menu)
	{
		$current = (isset($_GET['doc_type']) ? $_GET['doc_type'] : 'all_types');
		$output = array();
		?>

		<ul class="subsubsub">

		<?php
	  foreach($menu as $menu_item => $label):
		  if($menu_item != 'all_types') $class = "docs-icon docs-icon-" . $menu_item;
			if($current == $menu_item) $class .= " current";
		?>

		<li>
			<a class="<?php echo $class; ?>" title="<?php echo $label; ?>" href="<?php echo add_query_arg(array('paged' => null, 'doc_type' => $menu_item)); ?>"><?php if($menu_item == 'all_types') echo $label; ?></a>
		</li>

		<?php	endforeach;	?>

		</ul>
		<?php
	}

	/**
	 * Return cached entries according to document type
	 *
	 * @param string $doc_type The Document Type
	 * @return array Array of entries
	 */
	public function get_entries_cache($force_refresh = false)
	{
		if($_GET['cache_refresh'] == 'true')
			$force_refresh = true;

		$cached_entries = get_transient(self::get_entries_cache_key());

		// if no cached entries, fetch them
		if($cached_entries == false || count($cached_entries) == 0 || $force_refresh === true)
		{
			global $user_ID;

			$cached_entries = array();

			// store largest changestamp
			$metadata_xml = $this->api->get_user_metadata();
			$metadata = new SimpleXMLElement($metadata_xml);
			$metadata_docs = $metadata->children('http://schemas.google.com/docs/2007');

			$atts = $metadata_docs->largestChangestamp->attributes();
			foreach($atts as $name => $value)
			{
				if($name == 'value')
					$largest_cs = (int)$value;
			}

			update_user_meta($user_ID, 'inverted_gapps_largest_changestamp', $largest_cs);

			// get the document feed and store it in the cache
			$feed = $this->api->get_document_list_feed(array('doc_type' => $doc_type));

			foreach($feed->entries as $entry)
			{
				$processed_entry = $this->process_entry($entry);

				// only add document types we currently support
				if(array_key_exists($processed_entry->type, self::$doc_types))
					$cached_entries[] = $this->process_entry($entry);
			}

			set_transient(self::get_entries_cache_key(), $cached_entries, self::CACHE_TIMEOUT);
		}

	  return $cached_entries;
	}

	/**
	 * Get cached entries, either all of them or filtered by document type
	 *
	 * @return array Array of entries
	 */
	public function get_entries($doc_type = 'all_types')
	{
		// if entries are not loaded, fetch from cache
		if(!$this->entries)
			$this->entries = $this->get_entries_cache();

		// filter entries
		if($doc_type != 'all_types')
		{
			$filtered_entries = array();

			foreach($this->entries as $entry)
				if($entry->type == $doc_type)
					$filtered_entries[] = $entry;

			return $filtered_entries;
		}

		return $this->entries;
	}

	/**
	 * Fetch the publication status of a set of already cached entries
	 * Update the cache if necessary
	 *
	 * @param string $doc_type The Document Type
	 * @param int $start Start index
	 * @param int $end End Index
	 */
	function prefetch_publish_status(&$entries, $start, $end)
	{
		$write_cache = array();

		for($i = $start; $i < $end; $i++)
		{
			$entry = $entries[$i];

			if(!property_exists($entry, 'published'))
			{
				$entry->published = $this->api->get_entry_published($entry->resource_id);
				$write_cache = true;
			}
		}

		if($write_cache)
		{
			set_transient(self::get_entries_cache_key(), $entries, self::CACHE_TIMEOUT);
		}
	}

	/**
	 * Add shortcode to editor
	 *
	 * @param string $resource_id The document's resource id
	 * @param int $height Iframe height
	 * @param string $doc_type The document type
	 */
	public function embed_resource_shortcode($resource_id, $height, $doc_type)
	{
    // if its a pdf, we need the whole link
    /*    if($doc_type == 'pdf')
    {
      $entries = $this->get_entries('pdf');
      foreach($entries as $entry)
        if($entry->resource_id == $resource_id)
        {
          $pdf_entry = $entry;
          break;
        }

      if(!$pdf_entry)
        return;

      $resource_id = $entry->id;
      }*/

		$output = "[gdoc id=" . $resource_id . " height=" . $height . " type=" . $doc_type . "]";

		$this->send_to_editor($output);
	}

	/**
	 * Add a download link to the editor
	 *
	 * @param string $resource_id The document's resource id
	 * @param string $format The format to download the document
	 */
	public function insert_download_link($resource_id, $format = false)
	{
		global $user_email;
	  get_currentuserinfo();

		$endpoint = 'https://docs.google.com/feeds/default/private/full/' . $resource_id;
		$endpoint .= '?v=3&xoauth_requestor_id=' . urlencode($user_email);

		if($format && $format != '')
			$endpoint .= '&exportFormat=' . $format;

		$entry = $this->client->getEntry($endpoint);

		$output = '<a href="' . $entry->content->src . '" title="' . $entry->title->text . '">' . $entry->title->text . '</a>';

		$this->send_to_editor($output);
	}

	/**
	 * insert an image in the editor
	 *
	 * @param string $resource_id The document's resource id
	 * @param int $width Image Width
	 * @param int $height Image Height
	 */
	public function insert_image($resource_id, $width, $height)
	{
		$output = '<img src="https://docs.google.com/drawings/pub?id=' . $resource_id . '&w=' . $width . '&h=' . $height . '" />';
		$this->send_to_editor($output);
	}

	/**
	 * Send html to the editor
	 *
	 * @param string $html The HTML to output
	 */
	public function send_to_editor($html)
	{
		?>

		<script type="text/javascript">
		/* <![CDATA[ */
		var win = window.dialogArguments || opener || parent || top;
		win.send_to_editor('<?php echo addslashes($html); ?>');
		/* ]]> */
		</script>

		<?php
		exit;
	}

	/**
	 * Handle the gdoc shortcode
	 *
	 * @param array $atts The shortcode attributes
	 * @return string The output from parsing the shortcode
	 */
	public function embed_shortcode($atts)
	{
		$atts = array_merge($atts, array('height' => 600));
		extract($atts);

		$args = array('action' => 'pub', 'id_param' => 'id', 'extras' => '&embedded=true');

		// presnetations have /embed, other docs /pub
		if($type == 'presentation')
		{
			$args['action'] = 'embed';
			$args['extras'] = '&start=false&delayms=3000';
		}
		elseif($type == 'spreadsheet')
		{
			$args['id_param'] = 'key';
			$args['extras'] = '&output=html';
		}

    if($type == 'pdf')
    {
      $endpoint = 'https://docs.google.com/viewer?srcid=' . $id . '&embedded=true&pid=explorer&chrome=false';
    }
    else
    {
      $endpoint = "https://docs.google.com/" . $type . "/" . $args['action'] . "?" . $args['id_param'] . "=" . $id . $args['extras'];
    }

		return '<iframe src="' . $endpoint . '" style="height: ' . $height . 'px"></iframe>';
	}

	/**
	 * Handle publish_toggle action
	 */
	public function ajax_publish_toggle()
	{
		$resource_id = esc_attr($_POST['resource_id']);
		$status = esc_attr($_POST['status']);

		if($status == 'start')
			$b = true;
		else
			$b = false;

		$cached_entries = $this->get_entries_cache();

		foreach($cached_entries as $index => $entry)
		{
			if($entry->resource_id == $resource_id)
				break;
		}

		$result = $this->api->set_entry_published($entry, $b);

		// if we have an error
		if(is_string($result))
		{
			echo json_encode(array('error' => $result));
			exit;											 
		}

		// update the entry and cache
		$this->entries[$index] = $result;
		set_transient(self::get_entries_cache_key(), $cached_entries, self::CACHE_TIMEOUT);

		if($result->published)
		{
			$html = '<label>This Document is published</label><input type="button" value="Stop Publishing" data-status="stop" />';
		}
		else
		{
			$html = '<label>This Document is not published</label><input type="button" value="Start Publishing" data-status="start" />';
		}

		echo json_encode(array('html' => $html));

		exit;
	}

	/**
	 * Process the document list changes. Loops through the results, adding or 
	 * removing entries from the cache and storing it again
	 *
	 * @see get_entries_cache
	 */
	public function document_list_changes()
	{
		// fetch list of changes
		$feed = $this->api->get_document_list_changes();

		if(empty($feed->entry))
			return;

		$cached_entries = $this->get_entries_cache();

		// entries to remove
		$remove = array();

		// index of entries to remove
		$remove_indices = array();

		// entries to add or update
		$add_or_update = array();


		// loop through changes
		foreach($feed->entry as $entry)
		{
			$id = $this->get_resource_id_and_type($entry->getId());
			$is_remove = false;

			$ext_elems = $entry->getExtensionElements();

			// if element was deleted or removed, remove it from list
			foreach($ext_elems as $ext)
			{
				if($ext->rootElement == 'deleted' || $ext->rootElement == 'removed')
				{
					$remove[] = $id->resource_id;
					$is_remove = true;
				}
			}

			// new or update entry
			if(!$is_remove)
			{
				$add_or_update[] = $this->process_entry($entry);
			}
		}


		// remove, add or update entries if any
		if(!empty($remove) || !empty($add_or_update))
		{
			// looping through cached entries once is more efficient
			foreach($cached_entries as $cached_index => $cached_entry)
			{
				foreach($add_or_update as $nindex => $entry)
				{
					// match by resource id, if it matches update cached entry
					if($cached_entry->resource_id == $entry->resource_id)
					{
						$cached_entries[$nindex] = $entry;
						$updated = true;
					}

					// otherwise add it to the list
					if(!$updated)
						array_unshift($cached_entries, $entry);

					// unset and return to outer loop
					unset($add_or_update[$nindex]);
					break;
				}

				foreach($remove as $remove_index => $resource_id)
				{
					// match by resource id, if matches, just record the cache index
					if($cached_entry->resource_id == $resource_id)
					{
						$remove_indices[] = $cached_index;
						unset($remove[$remove_index]);

						// return to outer loop
						break;
					}
				}
			}
		}

		if(count($remove_indices) == 1)
		{
			array_splice($cached_entries, $remove_index[0], 1);
		}
		elseif(count($remove_indices) > 1)
		{
			$new_cached_entries = array();

			// loop through cached entries again to rebuild cached entries array
			foreach($cached_entries as $cached_index => $cached_entry)
			{
				if(in_array($cached_index, $remove_indices))
					continue;

				$new_cached_entries[] = $cached_entry;
			}

			$cached_entries = $new_cached_entries;
		}

		// update largest timestamp
		$feed_ext_elems = $feed->getExtensionElements();
		foreach($feed_ext_elems as $ext)
		{
			if($ext->rootElement == 'largestChangestamp')
			{
				$atts = $ext->getExtensionAttributes();
				$largest_cs = $atts['value']['value'];
			}
		}

		// update largest change stamp
		if($largest_cs)
			update_user_meta($user_ID, 'inverted_gapps_largest_changestamp', $largest_cs);

		set_transient(self::get_entries_cache_key(), $cached_entries, self::CACHE_TIMEOUT);
	}


	/**
	 * Turn a Zend_GData_Docs_DocumentListEntry into a lightweight object
	 * suitable for caching
	 *
	 * @param object $entry A Zend_GData_Docs_DocumentListEntry object
	 * @return object The converted lightweight object
	 */
	function process_entry($entry)
	{
		$id = $this->get_resource_id_and_type($entry->getId());

		$internal_entry = array('type' => $id->type,
														'resource_id' => $id->resource_id,
														'id' => $entry->id->text,
														'title' => $entry->title->text,
														'author' => $entry->author[0]->name->text,
														'updated' => $entry->updated->text);

		return (object)$internal_entry;
	}

	/**
	 * Return resource id and type in an object
	 *
	 * @param string $url URL representation of the entry id
	 * @return object an object with resourece_id and type members
	 */
	function get_resource_id_and_type($url)
	{
		$parts = explode("/", $url);
		$id = rawurldecode(end($parts));
		$id_parts = explode(":", $id);

		$resource_id = $id_parts[1];
		$entry_type = $id_parts[0];

		return (object)array('resource_id' => $resource_id, 'type' => $entry_type);
	}

  public static function get_entries_cache_key()
  {
		global $user_ID;
	  get_currentuserinfo();

    return self::ENTRIES_LIST_CACHE_KEY . "_" . $user_ID;
  }

}
