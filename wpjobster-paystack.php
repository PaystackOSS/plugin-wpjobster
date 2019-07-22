<?php
/**
 * Paystack Plugin for WPJobster Theme
 * 
 * WPJobster is a WordPress theme created to let you setup your own 
 * online jobs / services marketplace and lets users offer 
 * their services in a similar fashion to Envato Studio 
 * or Fiverr by setting an hourly rate or fixed price.
 * 
 * Plugin Name: WPJobster Paystack Gateway
 * Plugin URI: https://developers.paystack.co/v1.0/docs/plugins
 * Description: This plugin extends WPJobster Theme to accept payments with Paystack.
 * Author: Paystack
 * Author URI: https://paystack.com/
 * Version: 2.0
 * 
 * @category   CategoryName
 * @package    PackageName
 * @author     Douglas Kendyson <kendyson@kendyson.com>
 * @author     Stephen Amaza <steve@paystack.com>
 * @copyright  2016-2018 Paystack Limited
 * @license    https://github.com/PaystackHQ/Wordpress-wpjobster-paystack/LICENSE  MIT License
 * @version    SVN: $Id$
 * @link       https://github.com/PaystackHQ/Wordpress-wpjobster-paystack
 * @see        NetOther, Net_Sample::Net_Sample()
 * @since      File available since Release 1.0.0
 * @deprecated File deprecated in Release 2.0.0
 */


if (! defined('ABSPATH') ) {
    exit;
}


/**
 * Required minimums
 */
define('WPJOBSTER_PAYSTACK_MIN_PHP_VER', '5.4.0');
include_once plugin_dir_path(__FILE__) . 'class-paystack-plugin-tracker.php';

class WPJobster_Paystack_Loader
{

    /**
     * @var Singleton The reference the *Singleton* instance of this class
     */
    private static $instance;
    public $priority, $unique_slug;


    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return Singleton The *Singleton* instance.
     */
    public static function get_instance() 
    {
        if (null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    /**
     * Notices (array)
     *
     * @var array
     */
    public $notices = array();


    /**
     * Protected constructor to prevent creating a new instance of the
     * *Singleton* via the `new` operator from outside of this class.
     */
    protected function __construct() 
    {
        $this->priority = 1111;           // 100, 200, 300 [...] are reserved
        $this->unique_slug = 'paystack';    // this needs to be unique

        add_action('admin_init', array( $this, 'check_environment' ));
        add_action('admin_notices', array( $this, 'admin_notices' ), 15);
        add_action('plugins_loaded', array( $this, 'init_gateways' ), 0);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'plugin_action_links' ));

        add_filter('wpjobster_take_allowed_currency_paystack_gateway', array( $this,'get_gateway_currency' ));

        add_action('wpjobster_taketo_paystack_gateway', array( $this, 'taketogateway_function' ), 10, 2);
        add_action('wpjobster_processafter_paystack_gateway', array( $this, 'processgateway_function' ), 10, 2);

        if (isset($_POST[ 'wpjobster_save_' . $this->unique_slug ]) ) {
            add_action('wpjobster_payment_methods_action', array( $this, 'save_gateway' ), 11);
        }
    }

    function get_gateway_currency( $currency ) 
    {
        // if the gateway requires a specific currency you can declare it there
        // currency conversions are done automatically
        $currency = 'NGN'; // delete this line if the gateway works with any currency
        return $currency;
    }


    /**
     * Initialize the gateway. Called very early - in the context of the plugins_loaded action
     *
     * @since 1.0.0
     */
    public function init_gateways() 
    {
        load_plugin_textdomain('wpjobster-paystack', false, trailingslashit(dirname(plugin_basename(__FILE__))));
        add_filter('wpjobster_payment_gateways', array( $this, 'add_gateways' ));
    }


    /**
     * Add the gateways to WPJobster
     *
     * @since 1.0.0
     */
    public function add_gateways( $methods ) 
    {
        $methods[$this->priority] =
        array(
        'label'           => __('Paystack', 'wpjobster-paystack'),
        'action'          => 'wpjobster_taketo_paystack_gateway',
        'unique_id'       => $this->unique_slug,
        'process_action'  => 'wpjobster_taketo_paystack_gateway',
        'response_action' => 'wpjobster_processafter_paystack_gateway',
        );
        add_action('wpjobster_show_paymentgateway_forms', array( $this, 'show_gateways' ), $this->priority, 3);

        return $methods;
    }


    /**
     * Save the gateway settings in admin
     *
     * @since 1.0.0
     */
    public function save_gateway() 
    {
        if (isset($_POST['wpjobster_save_' . $this->unique_slug]) ) {
            // _enable and _button_caption are mandatory
            update_option('wpjobster_' . $this->unique_slug . '_enable',         sanitize_text_field($_POST['wpjobster_' . $this->unique_slug . '_enable']));
            update_option('wpjobster_' . $this->unique_slug . '_button_caption', sanitize_text_field($_POST['wpjobster_' . $this->unique_slug . '_button_caption']));

            // you can add here any other information that you need from the user
            update_option('wpjobster_paystack_enablesandbox',                    sanitize_text_field($_POST['wpjobster_paystack_enablesandbox']));
            update_option('wpjobster_paystack_tsk',                              sanitize_text_field($_POST['wpjobster_paystack_tsk']));
            update_option('wpjobster_paystack_tpk',                              sanitize_text_field($_POST['wpjobster_paystack_tpk']));
            update_option('wpjobster_paystack_lsk',                              sanitize_text_field($_POST['wpjobster_paystack_lsk']));
            update_option('wpjobster_paystack_lpk',                              sanitize_text_field($_POST['wpjobster_paystack_lpk']));
            update_option('wpjobster_paystack_success_page',                     sanitize_text_field($_POST['wpjobster_paystack_success_page']));
            update_option('wpjobster_paystack_failure_page',                     sanitize_text_field($_POST['wpjobster_paystack_failure_page']));

            echo '<div class="updated fade"><p>' . __('Settings saved!', 'wpjobster-paystack') . '</p></div>';
        }
    }


    /**
     * Display the gateway settings in admin
     *
     * @since 1.0.0
     */
    public function show_gateways( $wpjobster_payment_gateways, $arr, $arr_pages ) 
    {
        $tab_id = get_tab_id($wpjobster_payment_gateways);
        ?>
     <div id="tabs<?php echo esc_attr($tab_id)?>">
      <form method="post" action="<?php bloginfo('siteurl'); ?>/wp-admin/admin.php?page=payment-methods&active_tab=tabs<?php echo esc_attr($tab_id); ?>">
      <table width="100%" class="sitemile-table">
                <tr>
        <?php // _enable and _button_caption are mandatory ?>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Enable/Disable Paystack payment gateway', 'wpjobster-paystack')); ?></td>
                    <td width="200"><?php _e('Enable:', 'wpjobster-paystack'); ?></td>
                    <td><?php echo wpjobster_get_option_drop_down($arr, 'wpjobster_paystack_enable', 'no'); ?></td>
                </tr>
                <tr>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Enable/Disable Paystack test mode.', 'wpjobster-paystack')); ?></td>
                    <td width="200"><?php _e('Enable Test Mode:', 'wpjobster-paystack'); ?></td>
                    <td><?php echo wpjobster_get_option_drop_down($arr, 'wpjobster_paystack_enablesandbox', 'no'); ?></td>
                </tr>
                <tr>
        <?php // _enable and _button_caption are mandatory ?>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Put the Paystack button caption you want user to see on purchase page', 'wpjobster-paystack')); ?></td>
                    <td><?php _e('Paystack Button Caption:', 'wpjobster-paystack'); ?></td>
                    <td><input type="text" size="85" name="wpjobster_<?php echo esc_attr($this->unique_slug); ?>_button_caption" value="<?php echo get_option('wpjobster_' . $this->unique_slug . '_button_caption'); ?>" /></td>
                </tr>
                <tr>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Your Paystack Test Secret Key', 'wpjobster-paystack')); ?></td>
                    <td ><?php _e('Paystack Test Secret Key:', 'wpjobster-paystack'); ?></td>
                    <td><input type="text" size="85" name="wpjobster_paystack_tsk" value="<?php echo get_option('wpjobster_paystack_tsk'); ?>" /></td>
                </tr>
                <tr>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Your Paystack Test Public Key', 'wpjobster-paystack')); ?></td>
                    <td ><?php _e('Paystack Test Public Key:', 'wpjobster-paystack'); ?></td>
                    <td><input type="text" size="85" name="wpjobster_paystack_tpk" value="<?php echo get_option('wpjobster_paystack_tpk'); ?>" /></td>
                </tr>
                <tr>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Your Paystack Live Secret Key', 'wpjobster-paystack')); ?></td>
                    <td ><?php _e('Paystack Live Secret Key:', 'wpjobster-paystack'); ?></td>
                    <td><input type="text" size="85" name="wpjobster_paystack_lsk" value="<?php echo get_option('wpjobster_paystack_lsk'); ?>" /></td>
                </tr>
                <tr>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Your Paystack Live Public Key', 'wpjobster-paystack')); ?></td>
                    <td ><?php _e('Paystack Live Public Key:', 'wpjobster-paystack'); ?></td>
                    <td><input type="text" size="85" name="wpjobster_paystack_lpk" value="<?php echo get_option('wpjobster_paystack_lpk'); ?>" /></td>
                </tr>
                <tr>
                    <td valign=top width="22"><?php wpjobster_theme_bullet(__('Please select a page to show when Paystack payment successful. If empty, it redirects to the transaction page', 'wpjobster-paystack')); ?></td>
                    <td><?php _e('Transaction Success Redirect:', 'wpjobster-paystack'); ?></td>
                    <td><?php
                    echo wpjobster_get_option_drop_down($arr_pages, 'wpjobster_' . $this->unique_slug . '_success_page', '', ' class="select2" '); ?>
         </td>
       </tr>
       <tr>
        <td valign=top width="22"><?php wpjobster_theme_bullet(__('Please select a page to show when Paystack payment failed. If empty, it redirects to the transaction page', 'wpjobster-paystack')); ?></td>
        <td><?php _e('Transaction Failure Redirect:', 'wpjobster-paystack'); ?></td>
        <td><?php
        echo wpjobster_get_option_drop_down($arr_pages, 'wpjobster_' . $this->unique_slug . '_failure_page', '', ' class="select2" '); ?></td>
       </tr>
       <tr>
        <td></td>
        <td></td>
        <td><input type="submit" name="wpjobster_save_<?php echo $this->unique_slug; ?>" value="<?php _e('Save Options', 'wpjobster-paystack'); ?>" /></td>
       </tr>
       </table>
      </form>
     </div>
        <?php
    }


    /**
     * This function is not required, but it helps making the code a bit cleaner.
     *
     * @since 1.0.0
     */
    public function get_gateway_credentials() 
    {

        $wpjobster_paystack_enablesandbox = get_option('wpjobster_paystack_enablesandbox');

        if ($wpjobster_paystack_enablesandbox == 'no' ) {
            $paystack_payment_url = 'https://paystack.url';
            $secretkey = get_option('wpjobster_paystack_lsk');
            $publickey = get_option('wpjobster_paystack_lpk');

        } else {
            $paystack_payment_url = 'https://test.paystack.url';
            $secretkey = get_option('wpjobster_paystack_tsk');
            $publickey = get_option('wpjobster_paystack_tpk');

        }

        $merchant_key = get_option('wpjobster_paystack_id');
        
        $credentials = array(
        'publickey'                => $publickey,
        'secretkey'                => $secretkey,
        // 'merchant_key'       => $merchant_key,
        // 'paystack_payment_url' => $paystack_payment_url,
        );
        return $credentials;
    }

    function paystack_generate_new_code($length = 10)
    {
        $characters = '06EFGHI9KL'.time().'MNOPJRSUVW01YZ923234'.time().'ABCD5678QXT';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return time()."-".$randomString;
    }
    function paystack_meta_as_custom_fields($metadata)
    {
        $custom_fields = [];
        foreach ($metadata as $key => $value) {
            $custom_fields[] = [
            'display_name' => ucwords(str_replace("_", " ", $key)),
            'variable_name' => $key,
            'value' => $value
            ];

        }
        return $custom_fields;
    }
    /**
     * Collect all the info that we need and forward to the gateway
     *
     * @since 1.0.0
     */
    public function taketogateway_function($payment_type, $common_details) 
    {  
        $credentials = $this->get_gateway_credentials();

        $all_data  = array();
        // $all_data['publickey'] = $credentials['publickey'];
        // $all_data['secretkey'] = $credentials['secretkey'];
        
        $currency = wpjobster_get_currency();
        if ($currency != 'NGN') {
            _e('You can only pay in Naira with Paystack, go back and select Naira', 'wpjobster');
            exit;
        }

        $uid                                 = $common_details['uid'];
        $order_id                            = $common_details['order_id'];
        $wpjobster_final_payable_amount      = $common_details['wpjobster_final_payable_amount'];
        $currency                            = $common_details['currency'];

        ////
        $all_data['amount']                  = $wpjobster_final_payable_amount;
        $all_data['currency']                = $currency;
        
        // any other info that the gateway needs
        $all_data['firstname']               = user($uid, 'first_name');
        $all_data['email']                   = user($uid, 'user_email');
        $all_data['phone']                   = user($uid, 'cell_number');
        $all_data['lastname']                = user($uid, 'last_name');
        $all_data['address']                 = user($uid, 'address');
        $all_data['city']                    = user($uid, 'city');
        $all_data['country']                 = user($uid, 'country_name');
        $all_data['job_title']               = $common_details['job_title'];
        $all_data['job_id']                  = $common_details['pid'];
        $all_data['user_id']                 = $common_details['uid'];
        $all_data['order_id']                = $order_id;
        $all_data['Plugin']                  = "wp-jobster";
        $all_data['success_url']             = get_bloginfo('url') . '/?payment_response=paystack&payment_type=' . $payment_type;
        $all_data['fail_url']                = get_bloginfo('url') . '/?payment_response=paystack&action=fail&payment_type=' . $payment_type;

        $txn = $this->paystack_generate_new_code();
        $meta = $this->paystack_meta_as_custom_fields($all_data);
        // echo '<pre>';

        $txn_code = $txn.'_'.$order_id;

        $koboamount = $wpjobster_final_payable_amount*100;
        
        $paystack_url = 'https://api.paystack.co/transaction/initialize';
        $headers = array(
            'Content-Type'    => 'application/json',
            'Authorization'   => 'Bearer ' . $credentials['secretkey']
        );
        //Create Plan
        $body = array(
        'email'        => user($uid, 'user_email'),
        'amount'       => $koboamount,
        'reference'    => $txn_code,
        'metadata'     => json_encode(array('custom_fields' => $meta )),
        'callback_url' => get_bloginfo('url') . '?payment_response=paystack_response',

        );
        $args = array(
        'body'         => json_encode($body),
        'headers'      => $headers,
        'timeout'      => 60
        );

        $request = wp_remote_post($paystack_url, $args);
        // print_r($request);
        if (! is_wp_error($request)) {
            $paystack_response = json_decode(wp_remote_retrieve_body($request));
            $url    = $paystack_response->data->authorization_url;
            wp_redirect($url);
            exit;
        }
        exit;
    }
    /**
     * Process the response from the gateway and mark the order as completed or failed
     *
     * @since 1.0.0
     */
    function processgateway_function( $payment_type, $details ) 
    {

        $credentials        = $this->get_gateway_credentials();
        $key                = $credentials['secretkey'];

        $code               = $_GET['trxref'];
        $paystack_url       = 'https://api.paystack.co/transaction/verify/' . $code;
        $headers            = array(
            'Authorization' => 'Bearer ' . $key
        );
        $args               = array(
            'headers'    => $headers,
            'timeout'    => 60
        );
        $request = wp_remote_get($paystack_url, $args);
        if (! is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request) ) {
            $paystack_response = json_decode(wp_remote_retrieve_body($request));

            if ('success' == $paystack_response->data->status ) {
                $status        = $paystack_response->data->status;
                $amount        = $paystack_response->data->amount / 100;
                $paystack_ref  = $paystack_response->data->reference;
                $order_id      = substr($paystack_ref, strpos($paystack_ref, "_") + 1);
                
                // PSTK Logger 

                $pstk_logger = new wp_jobster_paystack_plugin_tracker('wp-jobster', $credentials['publicKey']);
                $pstk_logger->log_transaction_success($code);
                
                //


                ////
                $order_details = wpjobster_get_order_details_by_orderid($order_id);
                $amt           = $order_details->final_paidamount;

                $amt_arr       = explode("|", $amt);
                $currency      = $amt_arr['0'];
                $order_amount  = $amt_arr['1'];
            } else {
                $status        = "failed";
            }

        }

        // if ( $status == 'success' ) {

        //     if ( $amount == $order_amount) {
        //         $payment_status = 'completed';
        //         $payment_response = maybe_serialize( $_POST ); // maybe we want to debug later
        //         $payment_details = '';

        //         wpjobster_mark_job_prchase_completed( $order_id, $payment_status, $payment_response, $payment_details );

        //         if ( get_option( 'wpjobster_paystack_success_page' ) != '' ) {
        //             wp_redirect( get_permalink( get_option( 'wpjobster_paystack_success_page' ) ) );
        //         } else {
        //             wp_redirect( get_bloginfo( 'siteurl' ) . '/?jb_action=chat_box&oid=' . $order_id );
        //         }

        //         exit;

        //     } else {

        //         $payment_status  = 'failed';
        //         $payment_response = maybe_serialize( $_POST ); // maybe we want to debug later
        //         $payment_details = 'Final amount is different! ' . $common_details['wpjobster_final_payable_amount'] . ' expected, ' . $amount . ' paid.';

        //         wpjobster_mark_job_prchase_completed( $order_id, $payment_status, $payment_response, $payment_details );

        //         if ( get_option( 'wpjobster_paystack_failure_page' ) != '' ) {
        //             wp_redirect( get_permalink( get_option( 'wpjobster_paystack_failure_page' ) ) );
        //         } else {
        //             wp_redirect( get_bloginfo( 'siteurl' ) . '/?jb_action=chat_box&oid=' . $order_id );
        //         }
        //     }
        // } else {

        //     $payment_status = 'failed';
        //     $payment_response = maybe_serialize( $_POST );
        //     $payment_details = 'Paystack gateway declined the transaction';

        //     wpjobster_mark_job_prchase_completed( $order_id, $payment_status, $payment_response, $payment_details );

        //     if ( get_option( 'wpjobster_paystack_failure_page' ) != '' ) {
        //         wp_redirect( get_permalink( get_option( 'wpjobster_paystack_failure_page' ) ) );
        //     } else {
        //         wp_redirect( get_bloginfo( 'siteurl' ) . '/?jb_action=chat_box&oid=' . $order_id );
        //     }
        // }

        $payment_response = $serialise = maybe_serialize($_REQUEST);
 
        if ($status == 'success' ) {
            $payment_details = "success action returned"; // any info you may find useful for debug
            do_action(
                "wpjobster_" . $payment_type . "_payment_success",
                $order_id,
                $this->unique_slug,
                $payment_details,
                $payment_response
            );
            die();
        } else {
            $payment_details = "Failed action returned"; // any info you may find useful for debug
            do_action(
                "wpjobster_" . $payment_type . "_payment_failed",
                $order_id,
                $this->unique_slug,
                $payment_details,
                $payment_response
            );
            die();
        }
    }


    /**
     * Allow this class and other classes to add slug keyed notices (to avoid duplication)
     */
    public function add_admin_notice( $slug, $class, $message ) 
    {
        $this->notices[ $slug ] = array(
        'class'   => $class,
        'message' => $message
        );
    }


    /**
     * The primary sanity check, automatically disable the plugin on activation if it doesn't
     * meet minimum requirements.
     *
     * Based on http://wptavern.com/how-to-prevent-wordpress-plugins-from-activating-on-sites-with-incompatible-hosting-environments
     */
    public static function activation_check() 
    {
        $environment_warning = self::get_environment_warning(true);
        if ($environment_warning ) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die($environment_warning);
        }
    }


    /**
     * The backup sanity check, in case the plugin is activated in a weird way,
     * or the environment changes after activation.
     */
    public function check_environment() 
    {
        $environment_warning = self::get_environment_warning();
        if ($environment_warning && is_plugin_active(plugin_basename(__FILE__)) ) {
            deactivate_plugins(plugin_basename(__FILE__));
            $this->add_admin_notice('bad_environment', 'error', $environment_warning);
            if (isset($_GET['activate']) ) {
                unset($_GET['activate']);
            }
        }
    }


    /**
     * Checks the environment for compatibility problems. Returns a string with the first incompatibility
     * found or false if the environment has no problems.
     */
    static function get_environment_warning( $during_activation = false ) 
    {
        if (version_compare(phpversion(), WPJOBSTER_PAYSTACK_MIN_PHP_VER, '<') ) {
            if ($during_activation ) {
                $message = __('The plugin could not be activated. The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'wpjobster-paystack');
            } else {
                $message = __('The Paystack Powered by wpjobster plugin has been deactivated. The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'wpjobster-paystack');
            }
            return sprintf($message, WPJOBSTER_PAYSTACK_MIN_PHP_VER, phpversion());
        }
        return false;
    }

    /**
     * Adds plugin action links
     *
     * @since 1.0.0
     */
    public function plugin_action_links( $links ) 
    {
        $setting_link = $this->get_setting_link();
        $plugin_links = array(
        '<a href="' . $setting_link . '">' . __('Settings', 'wpjobster-paystack') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }


    /**
     * Get setting link.
     *
     * @return string Paystack checkout setting link
     */
    public function get_setting_link() 
    {
        $section_slug = $this->unique_slug;
        return admin_url('admin.php?page=payment-methods&active_tab=tabs' . $section_slug);
    }


    /**
     * Display any notices we've collected thus far (e.g. for connection, disconnection)
     */
    public function admin_notices() 
    {
        foreach ( (array) $this->notices as $notice_key => $notice ) {
            echo "<div class='" . esc_attr($notice['class']) . "'><p>";
            echo wp_kses($notice['message'], array( 'a' => array( 'href' => array() ) ));
            echo "</p></div>";
        }
    }
}

$GLOBALS['WPJobster_Paystack_Loader'] = WPJobster_Paystack_Loader::get_instance();
register_activation_hook(__FILE__, array( 'WPJobster_Paystack_Loader', 'activation_check' ));
