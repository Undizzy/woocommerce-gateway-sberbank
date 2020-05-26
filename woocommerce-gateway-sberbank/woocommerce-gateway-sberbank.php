<?php
/**
 * Plugin Name: WooCommerce Sberbank Gateway
 * Description: WooCommerce Payment gateway for Sberbank
 * Author: Undizzy
 * Author URI: http://twitter.com/NVitkovsky
 * Version: 1.1.3
 * Text Domain: wc-gateway-sbrf
 * Domain Path: /languages/
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * This gateway forks the WooCommerce core "Cheque" payment gateway to create another payment method.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + Sberbank gateway
 */
function wc_sbrf_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_SBRF';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_sbrf_add_to_gateways' );


/**
 * Register our custom post status, used for order status.
 */
function register_sbrf_post_status() {

	register_post_status('wc-payment-sbrf', array(
				'label'                     => _x( 'Pending credit card payment', 'Order status', 'wc-gateway-sbrf' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Pending credit card payment <span class="count">(%s)</span>', 'Pending credit card payment <span class="count">(%s)</span>', 'wc-gateway-sbrf' ),
			)
	);
}
add_action('init', 'register_sbrf_post_status', 9);

function add_sbrf_order_status( $order_statuses ) {
	$order_statuses['wc-payment-sbrf'] = _x( 'Pending credit card payment', 'Order status', 'wc-gateway-sbrf' );

	return $order_statuses;
}
add_filter( 'wc_order_statuses', 'add_sbrf_order_status' );


/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_sbrf_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sbrf_gateway' ) . '">' . __( 'Configure', 'wc-gateway-sbrf' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_sbrf_gateway_plugin_links' );


/**
 * Adds action to order page
 *
 * @param array $order_actions all order actions
 * @return array $order_actions all order actions + our custom action (Send costumer email with payment link)
 */
function wc_sbrf_gateway_order_action ($order_actions ){
	$order_actions['send_sbrf_pay_link'] = __( 'Email costumer invoice with Pay link', 'wc-gateway-sbrf' );
	return $order_actions;
}
add_filter('woocommerce_order_actions', 'wc_sbrf_gateway_order_action');


/**
 * Action handler. Email costumer invoice with Pay link.
 *
 * @param int $post_id order id
 * @return void
 */
function send_costumer_sbrf_pay_link( $post_id ){
	// Order data saved, now get it so we can manipulate status.
	$order = wc_get_order( $post_id );
	// Send the customer invoice email.
	$order->update_status(  'pending', __( 'Awaiting payment', 'wc-gateway-sbrf' ) );
	WC()->payment_gateways();
	WC()->shipping();
	WC()->mailer()->customer_invoice( $order );

	// Note the event.
	$order->add_order_note( __( 'Pay Link manually sent to customer.', 'wc-gateway-sbrf' ), false, true );
}
add_action('woocommerce_order_action_send_sbrf_pay_link', 'send_costumer_sbrf_pay_link');


/**
 * Including language directory
 */
function plugin_lang() {
	load_plugin_textdomain( 'wc-gateway-sbrf', false, dirname( plugin_basename(__FILE__) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'plugin_lang' );


/**
 * Offline Payment Gateway
 *
 * Provides an Sberbank Payment Gateway.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_SBRF
 * @extends		WC_Payment_Gateway
 * @version		2.1.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Undizzy
 */
add_action( 'plugins_loaded', 'wc_sbrf_gateway_init', 11 );


function wc_sbrf_gateway_init() {

	class WC_Gateway_SBRF extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'sbrf_gateway';
			$this->icon               = apply_filters( 'woocommerce_cheque_icon', '' );
			$this->has_fields         = false;
			$this->method_title       = _x( 'Sberbank Acquiring payments', 'Sberbank Acquiring payment method', 'wc-gateway-sbrf' );
			$this->method_description = __( 'Accept payments by credit cards through acquiring of Sberbank of Russia.', 'wc-gateway-sbrf' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions' );
			$this->payment_url  = $this->get_option('payment_url');
			$this->token        = $this->get_option( 'token');

			// Actions.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
			add_filter('woocommerce_get_checkout_payment_url', array($this, 'sbrf_checkout_payment_url'), 10, 2 );

			// Customer Emails.
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled'      => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Sberbank Acquiring payments', 'wc-gateway-sbrf' ),
					'default' => 'no',
				),
				'title'        => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => __( 'Credit card payment', 'wc-gateway-sbrf' ),
					'desc_tip'    => true,
				),
				'description'  => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Credit card payment.', 'wc-gateway-sbrf' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'woocommerce' ),
					'default'     => __('Please wait for the operator to confirm the order.', 'wc-gateway-sbrf'),
					'desc_tip'    => true,
				),
				'payment_url' => array(
					'title'       => __( 'Sberbank acquiring Payment URL ', 'wc-gateway-sbrf' ),
					'type'        => 'text',
					'description' => __( 'Sberbank acquiring Payment URL.', 'wc-gateway-sbrf' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'token' => array(
					'title'       => __( 'Sberbank acquiring Token', 'wc-gateway-sbrf' ),
					'type'        => 'text',
					'description' => __( 'Sberbank acquiring Token.', 'wc-gateway-sbrf' ),
					'default'     => '',
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
			}
		}

		/**
		 * Adds Checkout Payment URL to Sberbank Acquiring Page
		 *
		 * @param $url string current checkout payment url
		 * @param $order object WC_Order
		 * @return string Sberbank Acquiring Page URL + data
		 */
		public function sbrf_checkout_payment_url( $url, $order ){
			$total = $order->get_total();
			$order_number = $order->get_order_number();
			$order_billing_email = $order->get_billing_email();

			return $url = $this->payment_url."?token=".$this->token."&def=%7B&quot;amount&quot;:&quot;". $total ."&quot;%7D&def=%7B&quot;description&quot;:&quot;Оплата%20заказа%20№".$order_number ."&quot;%7D&def=%7B&quot;email&quot;:&quot;". $order_billing_email ."&quot;%7D";
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 *
		 * @param WC_Order $order Order object.
		 * @param bool $sent_to_admin Sent to admin.
		 * @param bool $plain_text Email format: plain text or HTML.
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
					echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
			}
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id Order ID.
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			if ( $order->get_total() > 0 ) {
				// Mark as on-hold (we're awaiting the cheque).
				$order->update_status( apply_filters( 'woocommerce_cheque_process_payment_order_status', 'on-hold', $order ), _x( 'Awaiting processing payment', 'Check payment method', 'woocommerce' ) );
				$order->update_status( apply_filters( 'woocommerce_cheque_process_payment_order_status', 'payment-sbrf', $order ), _x( 'Awaiting payment-sbrf payment', 'Check payment method', 'woocommerce' ) );
			} else {
				$order->payment_complete();
			}

			// Remove cart.
			WC()->cart->empty_cart();

			// Return thankyou redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}
