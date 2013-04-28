<?php
/**
 * Plugin Name: Google Apps + WordPress
 * Plugin URI: http://www.e5.io
 * Description: Google Apps integration for WordPress.
 * Version: 1.2
 * Author: Built in partnership between EXTREMIS and Second Variety LLP.
 * Author URI: http://secondvariety.org
 * License: GPL
 */

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

require_once('sso.php');
require_once('docs.php');
require_once('picasa.php');
require_once('calendar.php');

class Inverted_GoogleApps
{
	protected $modules = array();

  const VERSION = "1.2";

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->modules['sso'] = new Inverted_GoogleApps_SSO();
		$this->modules['gdocs'] = new Inverted_GoogleApps_Docs();
		$this->modules['picasa'] = new Inverted_GoogleApps_Picasa();
		$this->modules['calendar'] = new Inverted_GoogleApps_Calendar();

		add_action('network_admin_menu', array(&$this, 'network_admin_menu'));
		add_action('init', array(&$this, 'save_network_options'));
		add_action('init', array(&$this, 'init'));

		register_activation_hook(__FILE__, array(&$this, 'activate'));
	}

	public function init()
	{
		$css_url = plugins_url('/css', __FILE__);
		$js_url = plugins_url('/js', __FILE__);

		wp_register_style('inverted-google-apps', $css_url . "/inverted-google-apps.css");
		wp_register_script('inverted-google-apps', $js_url . "/inverted-google-apps.js");

    $this->check_update();
	}

	public function activate()
	{
		$this->modules['sso']->install();

    update_site_option('inverted_gapps_version', self::VERSION);
	}

  public function check_update()
  {
    // if no version, we must be on 1.1
    $version = get_site_option('inverted_gapps_version');
    if(!$version)
      $version = '1.1';

    if(version_compare($version, self::VERSION, "<"))
    {
      $this->modules['sso']->update($version);

      // update version
      update_site_option('inverted_gapps_version', self::VERSION);
    }
  }

	public function options($module, $name)
	{
		if(method_exists($this->modules[$module], 'options'))
			return $this->modules[$module]->options($name);
	}

	/**
	 * Add Network admin menu page
	 */
	public function network_admin_menu()
	{
		add_submenu_page('settings.php', 'Google Apps', 'Google Apps', 'manage_network', 'inverted-gapps', array(&$this, 'display_network_options'));
	}

	/**
   * display network options
   */
	public function display_network_options()
	{
		?>

		<div class="wrap">
			<h2>Google Apps Options</h2>

			<?php if(isset($_GET['msg'])): ?><div id="message" class="updated fade"><p><?php echo urldecode( $_GET['msg'] ); ?></p></div><?php endif; ?>

			<form method="post" action="">
				<?php
				foreach($this->modules as $module)
				{
					if(method_exists($module, 'display_network_options'))
						$module->display_network_options();
				}
				?>

				<p class="submit">
					<?php wp_nonce_field('inverted_gapps_settings_network'); ?>
					<input type="submit" name="submit" value="Save Changes" />
				</p>

			</form>
		</div><!-- .wrap -->

		<?php
	}

	/**
   * save network options
   */
	public function save_network_options()
	{
		if(isset($_POST['submit']))
		{
			if(wp_verify_nonce($_POST['_wpnonce'], 'inverted_gapps_settings_network'))
			{
				foreach($this->modules as $module)
				{
					if(method_exists($module, 'save_network_options'))
						$module->save_network_options();
				}

				wp_redirect(add_query_arg(array('page' => 'inverted-gapps', 'msg' => urlencode('Options saved')), 'settings.php'));
			}
		}
	}

	static function get_http_client()
	{
		global $inverted_gapps;

		$options = array('requestScheme' => Zend_Oauth::REQUEST_SCHEME_HEADER,
										 'version' => '1.0',
										 'signatureMethod' => 'HMAC-SHA1',
										 'consumerKey' => $inverted_gapps->options('sso', 'consumer_key'),
										 'consumerSecret' => $inverted_gapps->options('sso', 'consumer_secret'));

		$consumer = new Zend_Oauth_Consumer($options);
		$token = new Zend_Oauth_Token_Access();
		$http_client = $token->getHttpClient($options);

		return $http_client;
	}
}

$inverted_gapps = new Inverted_GoogleApps;

	
?>
