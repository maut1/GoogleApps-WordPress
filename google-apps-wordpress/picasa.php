<?php

require_once('picasa-api.php');


class Inverted_GoogleApps_Picasa
{
	public function __construct()
	{
		$this->api = new Inverted_GoogleApps_Picasa_API();

		add_action('init', array(&$this, 'init'));
		add_action('admin_print_styles', array(&$this, 'admin_print_styles'));

		// filters and actions for thickbox display
		add_action('media_buttons', array(&$this, 'media_button'), 14);
		add_filter('media_upload_tabs', array(&$this, 'media_upload_tabs'));
		add_filter('media_upload_default_tab', array(&$this, 'media_upload_default_tab'));
		add_action('media_upload_picasa', array(&$this, 'media_upload'));
		add_action('media_upload_picasa_upload', array(&$this, 'media_upload_picasa_upload'));
		add_action('media_upload_picasa_list', array(&$this, 'media_upload_picasa_list'));

		add_filter('inverted_gapps_picasa_authsub_login', array(&$this, 'display_login'), 10, 2);
	}


	public function init()
	{
	}

	/**
	 * Print styles and scripts if required
	 */
	public function admin_print_styles()
	{
		if($_GET['type'] == 'picasa')
		{
			wp_enqueue_style('inverted-google-apps');
			wp_enqueue_script('inverted-google-apps');
		}
	}

	/**
	 * Display the media button above the editor
	 */
	public function media_button()
	{
		$type = 'picasa';
		$id = 'picasa';
		$title = "Add Picasa Media";
		$icon =  plugins_url('/images/picasa-logo.png', __FILE__);

		echo "<a href='" . esc_url( get_upload_iframe_src($type) ) . "' id='{$id}-add_{$type}' class='thickbox add_$type' title='" . esc_attr( $title ) . "'><img src='" . $icon . "' alt='$title' onclick='return false;' /></a>";
	}


	/**
	 * Set the tabs for picasa box
	 *
	 * @param array $tabs Tabs to filter
	 * @return array New tabs
	 */
	public function media_upload_tabs($tabs)
	{
		if($_GET['type'] == 'picasa')
			return array('picasa_upload' => 'Upload', 'picasa_list' => 'Pictures');

		return $tabs;
	}
	

	/**
	 * Set default tab for picasa box
	 *
	 * @param array $default Default tab to filter
	 * @return array Default tab
	 */
	public function media_upload_default_tab($default)
	{
		if($_GET['type'] == 'picasa')
			return 'picasa_upload';

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
	 * Wrapper picasa box iframe
	 */
	public function media_upload()
	{
		$this->api->check_authentication();

		wp_iframe(array(&$this, 'media_upload_content'));
	}

	/**
	 * Display the picasa box iframe contents
	 */
	public function media_upload_content()
	{
		$this->media_upload_picasa_upload();		
	}

	/**
	 * Wrapper for picasa upload tab
	 */
	public function media_upload_picasa_upload()
	{
		$this->api->check_authentication();

		if($_GET['login_successful'] == 'true')
			return wp_iframe(array(&$this, 'display_login_successful'));

		if(isset($_FILES['picasa_file']))
		{
			$temp_name = $_FILES['picasa_file']['tmp_name'];
			$name = $_FILES['picasa_file']['name'];

			$result = $this->api->add_photo($temp_name, $name, $_POST['album']);
		}

		return wp_iframe(array(&$this, 'media_upload_picasa_upload_content'), $result);
	}

	/**
	 * Display upload tab contents
	 */
	public function media_upload_picasa_upload_content($upload_successful)
	{
		// get user feed
		$user_feed = $this->api->get_user_feed();
		if(!$user_feed)
			return;
	
		foreach($user_feed->entry as $album)
			$album_options[(string)$album->gphotoId] = $album->getTitle()->text;

		$this->media_upload_content_tabs();

		?>

		<form action="" method="post" enctype="multipart/form-data" id="picasa-upload">

		  <?php	if($upload_successful):	?>
		  <p>Photo successfully uploaded</p>
			<?php	endif; ?>

			<label for="album">Album</label>
			<select name="album">
				<?php foreach($album_options as $id => $title): ?>
				<option value="<?php echo $id ?>" <?php echo selected($id, $current_album); ?>><?php echo $title; ?></option>
				<?php endforeach; ?>
			</select>

			<p>
				<input type="file" name="picasa_file" />
			</p>
			<p>
				<input type="submit" class="button-secondary" value="Upload" />
			</p>
		</form>

		<?php 
	}

	/**
	 * Wrapper for picasa list tab content
	 */
	public function media_upload_picasa_list()
	{
		$this->api->check_authentication();

		if(isset($_POST['picasa_embed']))
		{
			$photo_id = $_POST['photo_id'];
			$link_url = esc_url($_POST['link_url']);
			$size = esc_attr($_POST['size']);
			$alignment = esc_attr($_POST['alignment']);

			$this->insert_photo($photo_id, compact('link_url', 'size', 'alignment'));
		}

		wp_iframe(array(&$this, 'media_upload_picasa_list_content'));
	}

	/**
	 * Display picasa list tab content
	 */
	public function media_upload_picasa_list_content()
	{
		$items_per_page = 20;

		$user_feed = $this->api->get_user_feed();
		$albums = ($user_feed->entry ? $user_feed->entry : array());

		// get current album
		$current_album = (isset($_GET['album']) ? $_GET['album'] : 'all');

		// get current page
		$paged = (isset($_GET['paged']) ? $_GET['paged'] : 1);

		// get photos
		if(isset($_GET['picasa_search_query']))
		{
			$query = $_GET['picasa_search_query'];
			$photos_feed = $this->api->search_photos($query);
		}
		else
		{
			$photos_feed = $this->api->get_photos($current_album);
		}

		$photos = $photos_feed->entry;

		// set album options
		$album_options = array('all' => 'All Albums');
		foreach($albums as $album)
			$album_options[(string)$album->gphotoId] = $album->getTitle()->text;

		$num_pages = ceil(count($photos) / $items_per_page);

		// start and end of current page
		$start = ($paged - 1) * $items_per_page;
		$end = min(count($photos), $start + $items_per_page);

		$this->media_upload_content_tabs();

		?>
		<form id="filter" method="get" action="">
			<p class="search-box">
				<input type="hidden" name="type" value="picasa" />
				<input type="hidden" name="tab" value="picasa_list" />
				<input id="media-search-input" type="text" value="<?php echo $query; ?>" placeholder="Search Photos" name="picasa_search_query" />
				<input class="button" type="submit" value="Search Photos" />
			</p>

		  <select name="album">
		    <?php foreach($album_options as $id => $title): ?>
				<option value="<?php echo $id ?>" <?php echo selected($id, $current_album); ?>><?php echo $title; ?></option>
				<?php endforeach; ?>
			</select>

			<input class="button" type="submit" value="Filter" />

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

		<div id="media-items" class="picasa">

			<?php
			for($photo_index = $start; $photo_index < $end; $photo_index++):
				$photo = $photos[$photo_index];

			  $media = $photo->getMediaGroup();
				$thumbnails = $media->getThumbnail();

				$thumbs = array();
				$thumb_sizes = array('small', 'medium', 'large');
				foreach($thumbnails as $i => $thumb)
				{
					$thumbs[$thumb_sizes[$i]] = array('url' => $thumb->getUrl(),
																						'width' => $thumb->getWidth(),
																						'height' => $thumb->getHeight());
				}

			?>

			<div class="media-item">
				<img src="<?php echo $thumbs['small']['url']; ?>"
						 width="<?php echo $thumbs['small']['width']; ?>"
						 height="<?php echo $thumbs['small']['height']; ?>"
						 style="float: left; padding: 4px;"
						 />
					
				<div class="filename">
					<?php echo $photo->getTitle(); ?>
				</div>

				<div class="insert-options">

					<form action="" method="post">
						<p class="link-url">
							<label for="link_url">Link URL</label>
							<input type="text" name="link_url" />
						</p>
						<p class="alignment">
							<label for="alignment">Alignment</label>
							<span class="alignment-options">
								<input type="radio" value="none" name="alignment" checked="checked"><label>None</label>
								<input type="radio" value="left" name="alignment"><label>Left</label>
								<input type="radio" value="center" name="alignment"><label>Center</label>
								<input type="radio" value="right" name="alignment"><label>Right</label>
							</span>
						</p>

						<div class="size">
							<label for="size">Size</label>
							<div class="size-options">
								<?php foreach($thumbs as $size => $thumb): ?>
								<input type="radio" value="<?php echo $size; ?>" name="size">
								<label><?php echo ucwords($size); ?> Thumbnails (<?php echo $thumb['width']; ?> x <?php echo $thumb['height']; ?>)</label><br />
								<?php endforeach; ?>
								<input type="radio" value="full" name="size" checked="checked">
								<label>Full (<?php echo $photo->getGphotoWidth(); ?> x <?php echo $photo->getGphotoHeight(); ?>)</label><br />
							</div><!-- .size-options -->
						</div>

						<p>
							<input type="hidden" value="<?php echo $photo->getId()->getText(); ?>" name="photo_id" />
							<input type="submit" class="button-secondary" value="Insert" name="picasa_embed" />
						</p>

					</form>

				</div><!-- .insert-options-->
			</div><!-- .media-item -->

			<?php endfor; ?>

		</div><!-- #media-items -->
		<?php
	}


	protected function insert_photo($photo_id, $params)
	{
		$photo = $this->api->get_photo($photo_id);

		$params = array_merge(array('size' => 'small',
																'alignment' => 'none'),
													$params);

		// get thumbnails
		$media = $photo->getMediaGroup();
		$thumbnails = $media->getThumbnail();

		extract($params);

		$thumbs = array();
		$thumb_sizes = array('small', 'medium', 'large');
		foreach($thumbnails as $i => $thumb)
		{
			$thumbs[$thumb_sizes[$i]] = array('url' => $thumb->getUrl(),
																				'width' => $thumb->getWidth(),
																				'height' => $thumb->getHeight());
		}


		// get the right thumbnail, or full one
		if(in_array($size, $thumb_sizes))
		{
			$src = $thumbs[$size]['url'];
		}
		else
		{
			$content = $media->getContent();
			$src = $content[0]->getUrl();
		}

		$html .= '<img class="align' . $alignment . '" src="' . $src . '" />';

		if($link_url)
			$html = '<a href="' . $link_url . '">' . $html . '</a>';

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

	public function display_login($output, $uri)
	{
		ob_start();
		wp_iframe(array(&$this, 'display_login_content'), $uri);
		$output = ob_get_clean();

		return $output;
	}

	public function display_login_content($uri)
	{
		?>
		<div id="picasa-login">
			<p>You need to grant access to <?php echo home_url(); ?> for Picasa Web Albums in order to use this service</p>
			<a class="grant-access" href="<?php echo $uri; ?>" target="_blank">Grant Access</a>
			<p>Once done, click on the button below</p>
			<a class="reload" href="">Reload</a>
			<p>You can revoke access by going to your <a target="_blank" href="http://www.google.com/settings">Google Account Settings</a>, under "Authorizing applications & sites"</a></p>

		</div><!-- #picasa-login -->';
		<?php
	}

	public function display_login_successful()
	{
		?>
		<div id="picasa-login">
			<p>You have successfully granted access for Picasa Web Albums. <a href="javascript: window.close();">Close this window</a>, then click the <strong>Reload</strong> button in the Picasa Media Box of your previous tab</p>
		</div><!-- #picasa-login -->';
		<?php

	}

	public function display_network_options()
	{
		$sso_settings = get_site_option('inverted_gapps_picasa');
	}


	/**
	 * Save SSO specific options
	 */
	public function save_network_options()
	{
		
	}


}

?>
