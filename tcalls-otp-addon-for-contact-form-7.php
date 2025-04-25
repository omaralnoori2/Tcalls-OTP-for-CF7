<?php
/**
 * Plugin Name: Tcalls OTP addon for Contact Form 7
 * Plugin URI: https://tcalls.com/to-download/
 * Description: Tcalls is a WordPress plugin that integrates with the popular Contact Form 7 plugin to enhance form security by adding OTP (One-Time Password) validation via WhatsApp..
 * Version: 1.0.0
 * Author: Tcalls Team
 * Author URI: https://tcalls.com/author/?utm_source=sl_plugin_author
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: Tcalls-Whatsapp-OTP-CF7
 */

if (! defined('ABSPATH')) {
	exit;
}

/*
 * Class Tcalls_Addon_CF7
 */
class Tcalls_WhatsApp_OTP_CF7
{

	/*
	 * Construct function
	 */
	public function __construct()
	{
		define('TCALLS_WA_OTP_CF7_URL', plugin_dir_url(__FILE__));
		define('TCALLS_WA_OTP_CF7_ADDONS', TCALLS_WA_OTP_CF7_URL . 'addons');
		define('TCALLS_WA_OTP_CF7_PATH', plugin_dir_path(__FILE__));
		define('TCALLS_WA_OTP_CF7_VERSION', '1.0.0');

		if (! class_exists('Appsero\Client')) {
			require_once(__DIR__ . '/inc/app/src/Client.php');
		}

		//Plugin loaded
		add_action('plugins_loaded', [$this, 'tcalls_wa_otp_cf7_plugin_loaded'], 5);

		if (defined('WPCF7_VERSION') && WPCF7_VERSION >= 5.7) {
			add_filter('wpcf7_autop_or_not', '__return_false');
		}

		// Initialize the appsero
		$this->appsero_init_tracker_Tcalls_addons_for_contact_form_7();

		//enqueue scripts
		add_action('admin_enqueue_scripts', [$this, 'tourfic_admin_denqueue_script'], 20);

		// WordPress AJAX hooks for both logged-in and logged-out users
		add_action('wp_ajax_send_otp_ajax_action', [$this, 'send_otp_ajax_handler']);
		add_action('wp_ajax_nopriv_send_otp_ajax_action', [$this, 'send_otp_ajax_handler']);

		add_action('wp_ajax_compare_otp_ajax_action', [$this, 'compare_otp_ajax_handler']);
		add_action('wp_ajax_nopriv_compare_otp_ajax_action', [$this, 'compare_otp_ajax_handler']);

		register_activation_hook(__FILE__, [$this, 'wa_otp_create_otps_table']);
		register_deactivation_hook(__FILE__, [$this, 'wa_otp_remove_otps_table']);
	}

	// Function to create a custom database table
	public function wa_otp_create_otps_table()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'otps'; // The table name with WordPress table prefix
		$charset_collate = $wpdb->get_charset_collate();

		// SQL query to create the table
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            phone varchar(255) DEFAULT '' NOT NULL,
            otp INT(6) UNSIGNED ZEROFILL NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

		// Include the upgrade.php file to use the dbDelta function
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		// Create or update the table
		dbDelta($sql);

		// Insert two rows in options table.
		// First row
		$wpdb->insert(
			$wpdb->options, // The options table
			array(
				'option_name' => 'WA_PHONE_NUMBER_ID',
				'option_value' => '',
			)
		);

		// Second row
		$wpdb->insert(
			$wpdb->options, // The options table
			array(
				'option_name' => 'WA_ACCESS_TOKEN',
				'option_value' => '',
			)
		);

		// Third row
		$wpdb->insert(
			$wpdb->options, // The options table
			array(
				'option_name' => 'WA_TEMPLATE_NAME',
				'option_value' => '',
			)
		);
	}

	public function wa_otp_remove_otps_table()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'otps';

		$sql = "DROP TABLE IF EXISTS $table_name;";
		$wpdb->query($sql);

		// Delete two rows in options table
		// First row removal
		$wpdb->delete(
			$wpdb->options, // The options table
			array('option_name' => 'WA_PHONE_NUMBER_ID') // Where clause
		);

		// Second row removal
		$wpdb->delete(
			$wpdb->options, // The options table
			array('option_name' => 'WA_ACCESS_TOKEN') // Where clause
		);

		// Third row removal
		$wpdb->delete(
			$wpdb->options, // The options table
			array('option_name' => 'WA_TEMPLATE_NAME') // Where clause
		);
	}

	public function send_otp_ajax_handler()
	{

		// Check if the required data is present
		if (isset($_POST['data'])) {
			$phone = sanitize_text_field($_POST['data']['phone']);
			$otp = rand(100000, 999999);

			global $wpdb;

			$wa_phone_number_id = get_option('WA_PHONE_NUMBER_ID');
			$access_token = get_option('WA_ACCESS_TOKEN');
			$template_name = get_option('WA_TEMPLATE_NAME');

			$api_url = 'https://graph.facebook.com/v20.0/' . $wa_phone_number_id . '/messages';

			// Create the payload for the request
			$data = array(
				'messaging_product' => 'whatsapp',
				'to' => str_replace("+", "", $phone),
				'type' => 'template',
				"template" => array(
					"name" => $template_name,
					"language" => array(
						"code" => "en"
					),
					"components" => array(
						array(
							"type" => "body",
							"parameters" => array(
								array(
									"type" => "text",
									"text" => $otp
								)
							)
						),
						array(
							"type" => "button",
							"sub_type" => "url",
							"index" => "0",
							"parameters" => array(
								array(
									"type" => "text",
									"text" => $otp
								)
							)
						)
					)
				)
			);

			// Initialize cURL session
			$ch = curl_init($api_url);

			// Set the cURL options
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Authorization: Bearer ' . $access_token,
				'Content-Type: application/json'
			));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

			// Execute the cURL request
			$response = curl_exec($ch);

			// Check for any cURL errors
			if (curl_errno($ch)) {
				wp_send_json_error(curl_error($ch));
			} else {
				// Parse the response into an array (assuming it's JSON)
				$response_data = json_decode($response, true);

				// Check if the response contains an error
				if (isset($response_data['error'])) {
					// Handle the error from the API
					wp_send_json_error($response_data['error']['message']);
				} else {
					$table_name = $wpdb->prefix . 'otps'; // Use the table name you created
					// Prepare the data to insert
					$data = array(
						'phone' => $phone,
						'otp' => $otp,
					);
					// Insert the row into the table
					$wpdb->insert(
						$table_name, // Table name
						$data, // Data to insert
						array(
							'%s', // Format for phone (string)
							'%d', // Format for six_digit_code (string or integer depending on your column type)
						)
					);

					if ($wpdb->insert_id) {
						// Insert successful
						wp_send_json_success($response); // Return the ID of the newly inserted row
					} else {
						// Insert failed
						wp_send_json_error('Insert failed');
					}
				}
			}

			// Close the cURL session
			curl_close($ch);
		}

		wp_die(); // Required to end the AJAX request
	}

	public function compare_otp_ajax_handler()
	{

		// Check if the required data is present
		if (isset($_POST['data'])) {
			$phone = sanitize_text_field($_POST['data']['phone']);
			$otp = absint($_POST['data']['otp']);

			global $wpdb;
			$table_name = $wpdb->prefix . 'otps'; // Use the table name you created

			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT otp FROM $table_name WHERE phone = %s ORDER BY id DESC LIMIT 1",
				$phone
			));

			// Check if a row was found and return the OTP
			if ($row) {
				if ($row->otp == $otp) {
					wp_send_json_success();
				} else {
					wp_send_json_error();
				}
			} else {
				wp_send_json_error(); // No matching row found
			}
		}

		wp_die(); // Required to end the AJAX request
	}

	/*
	 * Tcalls addons loaded
	 */
	public function tcalls_wa_otp_cf7_plugin_loaded()
	{
		//Register text domain
		load_plugin_textdomain('tcalls-otp-addon-for-contact-form-7', false, basename(dirname(__FILE__)) . '/languages');



		//Enqueue admin scripts
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'), 2);
		add_action('wp_enqueue_scripts', array($this, 'tcalls_wa_otp_cf7_frontend_scripts'));

		//Require Tcalls functions
		require_once('inc/functions.php');


		if (class_exists('WPCF7')) {
			//Init Tcalls addons
			$this->tcalls_wa_otp_cf7_init();
		} else {
			//Admin notice
			add_action('admin_notices', array($this, 'tcalls_wa_otp_cf7_admin_notice'));
		}


		// Require the main Option file
		if (file_exists(TCALLS_WA_OTP_CF7_PATH . 'admin/tf-options/TF_Options.php')) {
			require_once TCALLS_WA_OTP_CF7_PATH . 'admin/tf-options/TF_Options.php';
		}
	}

	/*
	 * Admin setting option dequeue 
	 */
	public function tourfic_admin_denqueue_script($screen)
	{
		$tf_options_screens = array(
			'toplevel_page_tcalls_wa_otp_cf7_settings',
			'tcalls-otp-addon_page_waotpcf7_addon',
			'toplevel_page_wpcf7',
			'contact_page_wpcf7-new',
			'admin_page_tcalls_wa_otp_cf7-setup-wizard',
			'wa-otp-addon_page_tcalls_wa_otp_cf7_license_info',
		);

		//The tcalls-otp-addon-for-contact-form-7 admin js Listings Directory Compatibility
		if (in_array($screen, $tf_options_screens) && wp_style_is('tf-admin', 'enqueued')) {
			wp_dequeue_style('tf-admin');
			wp_deregister_style('tf-admin');
		}
	}

	/*
	 * Admin notice- To check the Contact form 7 plugin is installed
	 */
	public function tcalls_wa_otp_cf7_admin_notice()
	{
?>
		<div class="notice notice-error">
			<p>
				<?php printf(
					__('%1$s requires %2$s to be installed and active. You can install and activate it from %3$s', 'tcalls-otp-addon-for-contact-form-7'),
					'<strong>Tcalls OTP Addon for Contact Form 7</strong>',
					'<strong>Contact form 7</strong>',
					'<a href="' . admin_url('plugin-install.php?tab=search&s=contact+form+7') . '">here</a>.'
				); ?>
			</p>
		</div>
<?php
	}

	/*
	 * Init Tcalls addons
	 */
	public function tcalls_wa_otp_cf7_init()
	{
		//Require admin menu
		require_once('admin/admin-menu.php');

		//Require Tcalls addons
		require_once('addons/addons.php');

		//  Update TCALLS_WA_OTP_CF7 Plugin Version
		if (TCALLS_WA_OTP_CF7_VERSION != get_option('tcalls_wa_otp_cf7_version')) {
			update_option('tcalls_wa_otp_cf7_version', TCALLS_WA_OTP_CF7_VERSION);
		}
	}


	// Enqueue admin scripts
	public function enqueue_admin_scripts()
	{

		// Ensure is_plugin_active function is available
		if (! function_exists('is_plugin_active')) {
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}

		// Check if the TCALLS_WA_OTP_CF7 pro plugin is active
		$pro_active = is_plugin_active('Tcalls-addons-for-contact-form-7-pro/Tcalls-addons-for-contact-form-7-pro.php');

		wp_enqueue_style('tcalls_wa_otp_cf7-admin-style', TCALLS_WA_OTP_CF7_URL . 'assets/css/admin-style.css', 'sadf');

		// // wp_enqueue_media();
		// wp_enqueue_script('wp-color-picker');
		// wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('tcalls_wa_otp_cf7-admin-script', TCALLS_WA_OTP_CF7_URL . 'assets/js/admin-script.js', array('jquery'), null, true);
		wp_localize_script(
			'tcalls_wa_otp_cf7-admin',
			'tcalls_wa_otp_cf7_options',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('tcalls_wa_otp_cf7_options_nonce'),
			)
		);
		wp_localize_script(
			'tcalls_wa_otp_cf7-admin',
			'tcalls_wa_otp_cf7_admin_params',
			array(
				'tcalls_wa_otp_cf7_nonce' => wp_create_nonce('updates'),
				'ajax_url' => admin_url('admin-ajax.php'),
				'pro_active' => $pro_active
			)
		);
		wp_enqueue_style('notyf', TCALLS_WA_OTP_CF7_URL . 'assets/app/libs/notyf/notyf.min.css', '', TCALLS_WA_OTP_CF7_VERSION);
		wp_enqueue_script('notyf', TCALLS_WA_OTP_CF7_URL . 'assets/app/libs/notyf/notyf.min.js', array('jquery'), TCALLS_WA_OTP_CF7_VERSION, true);
	}

	// Enqueue admin scripts
	public function tcalls_wa_otp_cf7_frontend_scripts()
	{
		wp_enqueue_style('tcalls_wa_otp_cf7-frontend-style', TCALLS_WA_OTP_CF7_URL . 'assets/css/tcalls_wa_otp_cf7-frontend.css', '');
		wp_enqueue_style('tcalls_wa_otp_cf7-form-style', TCALLS_WA_OTP_CF7_URL . 'assets/css/form-style.css', '');
	}

	/**
	 * Initialize the plugin tracker
	 *
	 * @return void
	 */
	public function appsero_init_tracker_Tcalls_addons_for_contact_form_7()
	{

		$client = new Appsero\Client('7d0e21bd-f697-4c80-8235-07b65893e0dd', 'Tcalls OTP Addon for Contact Form 7', __FILE__);

		// Change Admin notice text

		$notice = sprintf($client->__trans('Want to help make <strong>%1$s</strong> even more awesome? Allow %1$s to collect non-sensitive diagnostic data and usage information.'), $client->name);
		$client->insights()->notice($notice);

		// Active insights
		$client->insights()->init();
	}
}

/*
 * Object - Tcalls_WhatsApp_OTP_CF7
 */
$Tcalls_addons_cf7 = new Tcalls_WhatsApp_OTP_CF7();