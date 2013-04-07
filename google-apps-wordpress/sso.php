<?php

require_once(dirname(__FILE__) . "/Auth/OpenID/google_discovery.php");
require_once(dirname(__FILE__) . "/Auth/OpenID/AX.php");
require_once(dirname(__FILE__) . "/Auth/OpenID/Consumer.php");
require_once(dirname(__FILE__) . "/Auth/OpenID/FileStore.php");
require_once(dirname(__FILE__) . "/Auth/OpenID/PAPE.php");

/**
 * SSO module class
 */
class Inverted_GoogleApps_SSO
{
	/* the google apps domain */
	protected $domain;

	/* network url */
	protected $base_url;

	/* options */
	protected $options;

	/* google apps store */
	protected $store;

	/* consumer object */
	protected $consumer;

	/* error info */
	protected $error_info;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		add_action('init', array(&$this, 'init'));

		$this->options = get_site_option('inverted_gapps_sso');
	}

  /**
   * Return an option
   *
   * @param string $name The name of the option
   * @return mixed The value of the option
   */
	public function options($name)
	{
		return $this->options[$name];
	}

	/**
	 * Installation
	 */
	public function install()
	{
		if(!get_site_option('inverted_gapps_sso'))
		{
			$sso_settings = array('consumer_key' => '', 'consumer_secret' => '', 'default_signin_role' => 'subscriber');

      $prosites_levels = (array)get_site_option('psts_levels');
      foreach($prosites_levels as $id => $level)
      {
        $default_id = $id;
        break;
      }

      $sso_settings['default_prosites_level'] = $default_id;
      $sso_settings['default_prosites_period'] = 3;

      update_site_option('inverted_gapps_sso', $sso_settings);
		}
  }

  public function update($cur_version)
  {
    $sso_settings = get_site_option('inverted_gapps_sso');

    // upgrade from 1.1
    if($cur_version == '1.1')
    {
      $prosites_levels = (array)get_site_option('psts_levels');
      foreach($prosites_levels as $id => $level)
      {
        $default_id = $id;
        break;
      }

      $sso_settings['default_prosites_level'] = $default_id;
      $sso_settings['default_prosites_period'] = 3;
    }

    update_site_option('inverted_gapps_sso', $sso_settings);
	}

	/**
	 * init action hook
	 */
	public function init()
	{
		$this->store_path = "/tmp/_php_consumer_test";
		$this->base_url = site_url();

		session_start();

		if(!file_exists($this->store_path) && !mkdir($this->store_path)) 
		{
			$this->error_info = "Could not create the FileStore directory '$this->store_path'. Please check the effective permissions.";
			$this->error();
		}

		$this->store = new Auth_OpenID_FileStore($this->store_path);
		$this->consumer = new Auth_OpenID_Consumer($this->store);
		new GApps_OpenID_Discovery($this->consumer);

		$this->set_domain();

		$this->process();
	}

  /**
   * Process request
   */
	function process()
	{
		if($this->is_return())
			$this->sso_return();

		if($this->domain)
			$this->start();
	}

	/**
	 * Check if we are in the return step
   *
   * @return boolean Whether we are in the return step
	 */
	public function is_return()
	{
		return (isset($_GET['return']));
	}

	/**
	 * Get the requested domain from the 'domain' or 'email' variables and
	 * set it to $this->domain
	 */
	public function set_domain()
	{
		$this->domain = false;

		if(isset($_GET['domain']))
		{
			$this->domain = $_GET['domain'];
		}
		elseif(isset($_GET['email']))
		{
			if(preg_match("/(.*)@(.*)/", $_GET['email'], $matches))
				$this->domain = $matches[2];
		}
	}

	/**
	 * start the single sign on process
	 */
	public function start()
	{
		$this->domain = $_GET['domain'];
		session_unset();
		session_destroy();
		session_start();

		// if we are performing a subsite setup, store the query variables
		// as they will not be preserved after the user submits the form
		if($_GET['action'] == 'gapps-setup')
		{
			$_SESSION['gapps-setup'] = true;

			// callback will only be set when we are installing the application
			if(isset($_GET['callback'])) $_SESSION['callback'] = $_GET['callback'];
			if(isset($_GET['address']))	$_SESSION['address'] = $_GET['address'];
			if(isset($_GET['site_title'])) $_SESSION['site_title'] = $_GET['site_title'];
		}

		$auth = $this->consumer->begin($this->domain);

		// request email, first name and last name
		$attribute = array(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/contact/email', 2, 1, 'email'),
											 Auth_OpenID_AX_AttrInfo::make('http://axschema.org/namePerson/first', 1, 1, 'firstname'),
											 Auth_OpenID_AX_AttrInfo::make('http://axschema.org/namePerson/last', 1, 1, 'lastname'));


		$ax = new Auth_OpenID_AX_FetchRequest;
		foreach($attribute as $attr)
			$ax->add($attr);

		$auth->addExtension($ax);

		/**
		 * Redirect user to Google to complete OpenID authentication
		 * Params for $auth->redirectURL():
		 *   1. the openid.realm (which must match declared value in manifest)
		 *   2. the openid.return_to (URL to return to after authentication is complete)
		 */
		$url = $auth->redirectURL($this->base_url . '/', $this->base_url . '/?return=true');
		header('Location: ' . $url);

		exit;
	}

	/**
	 * Perform the return action. The steps are the following
	 *
	 * 1. check if OpenID authentication succeeded
	 * 2. if we are in setup, create a new subsite
	 * 3. if needed, create a new user and add him/her to the related blogs and set his/her role
	 * 4. login the user
	 * 5. if we are installing the application, redirect back to google
	 * 6. redirect to primary blog backend
	 */
	public function sso_return()
	{
		$response = $this->consumer->complete($this->base_url . '/?return=true');

		if($response->status != Auth_OpenID_SUCCESS)
		{
			$_SESSION['OPENID_AUTH'] = false;
			$this->error_info = $response;

			$this->error();

			exit;
		}

		// authentication was successful
		$_SESSION['OPENID_AUTH'] = true;

		// fetch information from the response
		$ax = new Auth_OpenID_AX_FetchResponse();
		$data = $ax->fromSuccessResponse($response)->data;
		$oid = $response->endpoint->claimed_id;

		$firstname = $_SESSION['firstName'] = $data['http://axschema.org/namePerson/first'][0];
		$lastname = $_SESSION['lastName'] = $data['http://axschema.org/namePerson/last'][0];
		$email = $_SESSION['email'] = $data['http://axschema.org/contact/email'][0];

		// get the user by his email
		$user = get_user_by('email', $email);

		// retrieve the domain from the email
		preg_match("/(.*)@(.*)/", $email, $matches);
		$this->domain = $matches[2];

		// will hold the new blog id if we are setting one up
		$new_blog_id = false;

		$user_created = $primary_blog_id = false;

		// create new blog if necessary and retrieve info
		if($_SESSION['gapps-setup'] == true)
		{
			$new_blog_id = $this->create_new_blog($email);
			$bloginfo = get_blog_details((int)$new_blog_id, false);
			$address = (is_subdomain_install() ? $bloginfo->domain : $bloginfo->path);
		}
		else
		{
			$primary_blog_id = $this->get_primary_blog();
			$bloginfo = get_blog_details((int)$primary_blog_id, false);
			$address = (is_subdomain_install() ? $bloginfo->domain : $bloginfo->path);
		}

		// create a new user if one doesn't exist
		if(!$user)
		{
			$user = $this->create_user($firstname, $lastname, $email);
			$user_created = true;
		}

		if(!$primary_blog_id)
			$primary_blog_id = $this->get_primary_blog();

		// if a new blog was recently created, assign the new user as an admin
		if($new_blog_id !== false)
		{
			$user->for_blog($new_blog_id);
			$user->set_role('administrator');
		}

		// if we created a new user, set the role on exisiting related blogs
		if($user_created)
			$this->add_user_to_related_blogs($user, $new_blog_id);

		// login the user
		wp_set_auth_cookie($user->ID, true);

		// if we were installing the app callback should be set, redirect to google apps
		if($_SESSION['gapps-setup'] == true && isset($_SESSION['callback']) && ($_SESSION['callback'] != ''))
		{
			$return_url = $_SESSION['callback'];

			unset($_SESSION['callback']);
			unset($_SESSION['gapps-setup']);
			unset($_SESSION['address']);

			header('Location: ' . $return_url);
			exit;
		}

		// if the user is the super admin, primary blog is the root blog
		if(is_super_admin($user->ID))
			$primary_blog_id = 1;

		$redirect_url = get_blogaddress_by_id($primary_blog_id);

		// redirect to backend
		header('Location: ' . $redirect_url . 'wp-admin/');

		return;
	}

	public function error()
	{
		print_r($this->error_info);
		exit;
	}

	/**
   * Create a new user using details provided. Creates a new user with a randomly generated password.
	 * The user name is the email address
	 *
	 * @param string $email The user's email
	 * @return WP_User The new user object
	 */
	protected function create_user($firstname, $lastname, $email)
	{
		$password = wp_generate_password(12, false);
		$user_id = wp_create_user($email, $password, $email);

		if(!is_numeric($user_id))
		{
			$this->error_info = "could not create user. Please contact support";
			$this->error();

			exit;
		}

		wp_update_user(array('ID' => $user_id, 'first_name' => $firstname, 'last_name' => $lastname));

		// remove from root blog
		remove_user_from_blog($user_id, 1);

		$user = get_user_by('email', $email);

		return $user;
	}

	/**
   * Add a user to related blogs. The role can be supplied or taken from the plugin options. Will not
	 * assign role if the the blog id is in $exclude or if user is already a member of the blog.
	 * The $exclude parameter can either be an array of IDs or a single blog ID.
	 *
	 * @param WP_User $user The user object
	 * @param mixed $exclude (optional) The blogs ids to exclude
	 * @param boolean $role (optional) The role to assign
	 */
	protected function add_user_to_related_blogs($user, $exclude = array(), $role = false)
	{
		if(!is_array($exclude))
			$exclude = array($exclude);

		$default_role = $this->options['default_signin_role'];
		$related_blogs = $this->get_related_blogs();

		foreach($related_blogs as $blog_id)
		{
			// check that its not the blog that was just created, if any
			if(!in_array($blog_id, $exclude))
			{
				// check that its not already a user
				if(!is_user_member_of_blog($user_id, $blog_id))
				{
					$user->for_blog($blog_id);
					$user->set_role($default_role);
				}
			}
		}
	}

	/**
	 * Return ids of blogs that have $domain as in the 'gapps_domain' blog option
	 *
	 * @return array An array of blog ids
	 */
	function get_related_blogs()
	{
		global $wpdb;
		
		$blogs = $wpdb->get_results('SELECT blog_id from ' . $wpdb->prefix . 'blogs', ARRAY_A);

		$related_blogs = array();

		foreach($blogs as $blog)
			if(get_blog_option($blog['blog_id'], 'gapps_domain') == $this->domain)
				$related_blogs[] = $blog['blog_id'];

		return $related_blogs;
	}

	/**
	 * Return the primary blog amongst all the related blogs
	 *
	 * @return mixed The id of the primary blog or false if none found
	 */
	protected function get_primary_blog()
	{
		$related_blogs = $this->get_related_blogs();

		foreach($related_blogs as $blog_id)
		{
			if(get_blog_option($blog_id, 'gapps_primary') == 1)
				return $blog_id;
		}

		return false;
	}

	/**
	 * Add all the users of related blogs to a blog
	 *
	 * @param int $blog_id The blog id to add the users to
	 */
	protected function add_users_to_blog($blog_id)
	{
		$related_blogs = $this->get_related_blogs();
		$default_role = $this->options['default_signin_role'];

		$user_ids = array();

		// collect all users of related blogs
		foreach($related_blogs as $related_blog)
		{
			if($related_blog == $blog_id)
				continue;

			$users = get_users(array('blog_id' => $related_blog, 'fields' => array('ID')));

			$blog_user_ids = array();
			foreach($users as $user)
				$blog_user_ids[] = $user->ID;

			$user_ids = array_merge($user_ids, $blog_user_ids);
		}

		// add each user to new blog
		foreach($user_ids as $user_id)
		{
			add_user_to_blog($blog_id, $user_id, $default_role);
		}
	}

	/**
	 * Setup a new site based on google apps domain
	 *
	 * @param string $email The administrator's email
	 */
	function create_new_blog($email)
	{
		// are we handling the form?
		if(isset($_SESSION['address']))
		{
			global $wpdb;

			// the blog creation form's value should be in the session
			$address = $_SESSION['address'];
      if(is_subdomain_install())
      {
        $new_subsite_url = $address . "." .  str_replace('http://', '', $this->base_url);
        $new_subsite_path = "/";
      }
      else
      {
        $new_subsite_url = str_replace('http://', '', $this->base_url);
        $new_subsite_path = "/" . $address;
      }

			$errors = array();

			// check title
			if($_SESSION['site_title'] == '')
				$errors['site_title'] = "Invalid Site Title";

			// check if this subsite already exists
			if(get_blog_id_from_url($new_subsite_url, $new_subsite_path . "/") > 0)
				$errors['address'] = "Blog already exits";

			// if there are errors go back to setup
			if(!empty($errors))
			{
				$this->error_info = $errors;
				$this->setup();

				exit;
			}

			$new_site_id = create_empty_blog($new_subsite_url, $new_subsite_path, $_SESSION['site_title']);

			// create_empty_blog does not seem to set the title, set it manually 
			// along with the admin email
			update_blog_option($new_site_id, 'blogname', $_SESSION['site_title']);
			update_blog_option($new_site_id, 'admin_email', $user_email);

			// set the google apps domain
			add_blog_option($new_site_id, 'gapps_domain', $this->domain);

      $this->prosites_signup($new_site_id);

			// if there are no primary domains, set this one as primary
			if($this->get_primary_blog() === false)
      {
				add_blog_option($new_site_id, 'gapps_primary', 1);
      }

			// add other users to this new blog
			$this->add_users_to_blog($new_site_id, $this->domain);
		
			return $new_site_id;
		}
		else
		{
			$this->setup();
		}
	}

  /**
   * Display site setup template
   */
	public function setup()
	{
		// use google apps domain as default site address
		$address = preg_replace("/(.*)\.(.*)/", "$1", $this->domain);

		$sso = $this;

		require_once(dirname(__FILE__) . "/templates/site-setup.php");

		exit;
	}

  /**
   * Display SSO options
   */	
	public function display_network_options()
	{
    global $wp_roles;

		$sso_settings = get_site_option('inverted_gapps_sso');
    $roles = $wp_roles->get_names();
    $prosites_levels = (array)get_site_option('psts_levels');
    $prosites_levels['free'] = array('name' => 'Free');
    $prosites_periods = array(1, 3, 12);
    $prosites_installed = class_exists('ProSites');

		require_once(dirname(__FILE__) . "/templates/sso-options.php");
	}

	/**
	 * Save SSO specific options
	 */
	public function save_network_options()
	{
		$network_settings['consumer_key'] = trim($_POST['inverted_gapps_consumer_key']);
		$network_settings['consumer_secret'] = trim($_POST['inverted_gapps_consumer_secret']);
		$network_settings['default_signin_role'] = trim($_POST['inverted_gapps_default_signin_role']);
    $network_settings['default_prosites_level'] = trim($_POST['inverted_gapps_default_prosites_level']);
    $network_settings['default_prosites_period'] = trim($_POST['inverted_gapps_default_prosites_period']);

		update_site_option('inverted_gapps_sso', $network_settings);
	}

  /**
   * signup for pro sites
   *
   * @param int $blog_id The blog id
   */
  function prosites_signup($blog_id)
  {
    global $psts;
    if(!$psts)
    {
      if(class_exists('ProSites'))
        $psts = new ProSites();
      else
        return;
    }    

    $sso_settings = get_site_option('inverted_gapps_sso');

    if($sso_settings['default_prosites_level'] != 'free')
      $psts->extend($blog_id, $sso_settings['default_prosites_period'], false, $sso_settings['default_prosites_level']);
  }

}

?>