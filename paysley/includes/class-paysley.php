<?php
/**
 * Paysley Class
 *
 * @package Paysley
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once dirname( __FILE__ ) . '/class-paysley-api.php';

/**
 * Paysley class.
 *
 * @extends WC_Payment_Gateway
 */
class Paysley extends WC_Payment_Gateway {
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = 'paysley';
		// title for backend.
		$this->method_title       = __( 'Paysley', 'paysley' );
		$this->method_description = __( 'Paysley redirects customers to Paysley to enter their payment information.', 'paysley' );
		// title for frontend.
		$this->icon     = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/img/py-logo.png';
		$this->supports = array( 'refunds' );

		// setup backend configuration.
		$this->init_form_fields();
		$this->init_settings();

		// save woocomerce settings checkout tab section paysley.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		// validate form fields when saved.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'validate_admin_options' ) );
		// use hook to receive response url.
		add_action( 'woocommerce_before_thankyou', array( $this, 'response_page' ) );
		// use hook to do full refund.
		add_action( 'woocommerce_order_edit_status', array( $this, 'process_full_refund' ), 10, 2 );
		// use hook to add notes when payment amount greater than order amount.
		add_action( 'woocommerce_order_status_changed', array( $this, 'add_full_refund_notes' ), 10, 3 );

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->payment_type   = 'DB';
		$this->access_key     = $this->get_option( 'access_key' );
		$this->enable_logging = 'yes' === $this->get_option( 'enable_logging' );
		$this->is_test_mode   = 'py_live' !== substr( $this->access_key, 0, 7 );
		$this->init_api();
	}

	/**
	 * Override function.
	 * Initialise settings form fields for paysley
	 * Add an array of fields to be displayed on the paysley settings screen.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'paysley' ),
				'label'   => __( 'Enable Paysley', 'paysley' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'paysley' ),
				'type'        => 'text',
				'description' => __( 'This is the title which the user sees during checkout.', 'paysley' ),
				'default'     => __( 'Paysley', 'paysley' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'paysley' ),
				'type'        => 'text',
				'description' => __( 'This is the description which the user sees during checkout.', 'paysley' ),
				'default'     => 'Pay with Paysley',
				'desc_tip'    => true,
			),
			'access_key'     => array(
				'title'       => __( 'Access Key', 'paysley' ),
				'type'        => 'password',
				'description' => __( '* This is the access key, received from Paysley developer portal. ( required )', 'paysley' ),
				'default'     => '',
			),
			'enable_logging' => array(
				'title'   => __( 'Enable Logging', 'paysley' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable transaction logging for paysley.', 'paysley' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Show error notice if access key is empty.
	 */
	public function validate_admin_options() {
		$post_data  = $this->get_post_data();
		$access_key = $this->get_field_value( 'access_key', $this->form_fields, $post_data );
		if ( empty( $access_key ) ) {
			WC_Admin_Settings::add_error( __( 'Please enter an access key!', 'paysley' ) );
		}
	}

	/**
	 * Override function.
	 * Disable if access key is empty.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = parent::is_available();
		if ( empty( $this->access_key ) ) {
			$is_available = false;
		}

		return $is_available;
	}

	/**
	 * Get order property with compatibility check on order getter introduced
	 * in WC 3.0.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $prop  Property name.
	 *
	 * @return mixed Property value
	 */
	public static function get_order_prop( $order, $prop ) {
		switch ( $prop ) {
			case 'order_total':
				$getter = array( $order, 'get_total' );
				break;
			default:
				$getter = array( $order, 'get_' . $prop );
				break;
		}

		return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
	}

	/**
	 * Log system processes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param string $level Log level.
	 */
	public function log( $message, $level = 'info' ) {
		if ( $this->enable_logging ) {
			if ( empty( $this->logger ) ) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add( 'paysley-' . $level, $message );
		}
	}

	/**
	 * Init the API class and set the access key.
	 */
	protected function init_api() {
		Paysley_API::$access_key   = $this->access_key;
		Paysley_API::$is_test_mode = $this->is_test_mode;
	}

	/**
	 * Get payment url.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $transaction_id Transaction ID.
	 *
	 * @return string
	 * @throws \Exception Error.
	 */
	protected function get_payment_url( $order_id, $transaction_id ) {
		$order      = wc_get_order( $order_id );
		$currency   = $order->get_currency();
		$amount     = $this->get_order_prop( $order, 'order_total' );
		$token      = $this->generate_token( $order_id, $currency );
		$return_url = $this->get_return_url( $order );

		$body                     = array(
			'reference'    => $transaction_id,
			'payment_type' => $this->payment_type,
			'currency'     => $currency,
			'amount'       => (float) $amount,
			'cart_items'   => $this->get_cart_items( $order_id ),
			'cancel_url'   => wc_get_checkout_url(),
			'return_url'   => $return_url,
			'response_url' => $return_url . '&py_token=' . $token,
		);
		$log_body                 = $body;
		$log_body['response_url'] = $return_url . '&py_token=*****';
		$this->log( 'get_payment_url - body: ' . wp_json_encode( $log_body ) );

		$results = Paysley_API::generate_pos_link( $body );
		$this->log( 'get_payment_url - results: ' . wp_json_encode( $results ) );

		if ( 200 === $results['response']['code'] && 'success' === $results['body']['result'] ) {
			return $results['body']['long_url'];
		}

		if ( 422 === $results['response']['code'] && 'currency' === $results['body']['error_field'] ) {
			throw new Exception( __( 'We are sorry, currency is not supported. Please contact us.', 'paysley' ), 1 );
		}

		throw new Exception( __( 'Error while Processing Request: please try again.', 'paysley' ), 1 );
	}

	/**
	 * Get cart items.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function get_cart_items( $order_id ) {
		$cart_items = array();
		$order      = wc_get_order( $order_id );

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$sku = $product->get_sku();
			if ( !$sku ) {
				$sku = '-';
			}
			$item_total  = isset( $item['recurring_line_total'] ) ? $item['recurring_line_total'] : $order->get_item_total( $item );

			$cart_items[] = array(
				'sku'		 => $sku,
				'name' 		 => $item->get_name(),
				'qty'  		 => $item->get_quantity(),
				'unit_price' => $item_total
			);
		}

		return $cart_items;
	}

	/**
	 * Override function.
	 *
	 * Send data to the API to get the payment url.
	 * Redirect user to the payment url.
	 * This should return the success and redirect in an array. e.g:
	 *
	 *        return array(
	 *            'result'   => 'success',
	 *            'redirect' => $this->get_return_url( $order )
	 *        );
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order          = wc_get_order( $order_id );
		$transaction_id = 'wc-' . $order->get_order_number();
		$secret_key     = wc_rand_hash();

		// * save transaction_id and secret_key first before call get_payment_url function.
		update_post_meta( $order->get_id(), '_paysley_transaction_id', $transaction_id );
		update_post_meta( $order->get_id(), '_paysley_secret_key', $secret_key );

		$payment_url = $this->get_payment_url( $order_id, $transaction_id );

		return array(
			'result'   => 'success',
			'redirect' => $payment_url,
		);
	}

	/**
	 * Process partial refund.
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund.
	 * a passed in amount.
	 *
	 * @param  int    $order_id Order ID.
	 * @param  float  $amount Refund amount.
	 * @param  string $reason Refund reason.
	 * @return boolean True or false based on success, or a WP_Error object.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'paysley' === $order->get_payment_method() ) {
			$payment_id = get_post_meta( $order->get_id(), '_paysley_payment_id', true );
			$body       = array(
				'email'  => $order->get_billing_email(),
				'amount' => (float) $amount,
			);
			$this->log( 'process_refund - request body ' . wp_json_encode( $body ) );
			$results = Paysley_API::do_refund( $payment_id, $body );
			$this->log( 'process_refund - results: ' . wp_json_encode( $results ) );

			if ( 200 === $results['response']['code'] && 'refund' === $results['body']['status'] ) {
				$order->add_order_note( __( 'Paysley partial refund successfull.' ) );
				$this->log( 'process_refund: Success' );
				return true;
			}

			$this->log( 'process_refund: Failed' );
			return new WP_Error( $results['response']['code'], __( 'Refund Failed', 'paysley' ) . ': ' . $results['body']['message'] );
		}
	}

	/**
	 * Process full refund when order status change from processing / completed to refunded.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status_to change status to.
	 */
	public function process_full_refund( $order_id, $status_to ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'paysley' === $order->get_payment_method() ) {
			$status_from = $order->get_status();

			if ( ( 'processing' === $status_from || 'completed' === $status_from ) && 'refunded' === $status_to ) {
				$amount     = (float) $this->get_order_prop( $order, 'order_total' );
				$payment_id = get_post_meta( $order->get_id(), '_paysley_payment_id', true );
				$body       = array(
					'email'  => $order->get_billing_email(),
					'amount' => $amount,
				);
				$this->log( 'process_full_refund - request body ' . wp_json_encode( $body ) );
				$results = Paysley_API::do_refund( $payment_id, $body );
				$this->log( 'process_full_refund - do_refund results: ' . wp_json_encode( $results ) );

				if ( 200 === $results['response']['code'] && 'refund' === $results['body']['status'] ) {
					$this->restock_refunded_items( $order );
					$order->add_order_note( __( 'Paysley full refund successfull.' ) );
					$this->log( 'process_full_refund: Success' );
				} else {
					$this->log( 'process_full_refund: Failed' );
					$redirect = get_admin_url() . 'post.php?post=' . $order_id . '&action=edit';
					WC_Admin_Meta_Boxes::add_error( __( 'Refund Failed', 'paysley' ) . ':' . $results['body']['message'] );
					wp_safe_redirect( $redirect );
					exit;
				}
			}
		}
	}

	/**
	 * Add notes if payment amount greater than order amount when order status change from processing / completed to refunded.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status_from change status from.
	 * @param string $status_to change status to.
	 */
	public function add_full_refund_notes( $order_id, $status_from, $status_to ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'paysley' === $order->get_payment_method() ) {
			if ( ( 'processing' === $status_from || 'completed' === $status_from ) && 'refunded' === $status_to ) {
				$order_amount = (float) $this->get_order_prop( $order, 'order_total' );
				$payment_id   = get_post_meta( $order->get_id(), '_paysley_payment_id', true );
				$results      = Paysley_API::get_payment( $payment_id );
				$this->log( 'add_full_refund_notes - get_payment results: ' . wp_json_encode( $results ) );
				if ( 200 === $results['response']['code'] ) {
					$payment_amount = (float) $results['body']['payment']['amount'];
					if ( $payment_amount > $order_amount ) {
						$order->add_order_note( __( 'Paysley notes: You still have amount to be refunded, because Merchant use tax/tip when customer paid. Please contact the merchant to refund the tax/tip amount.' ) );
					}
				}
			}
		}
	}

	/**
	 * Increase stock for refunded items.
	 *
	 * @param obj $order Order.
	 */
	public function restock_refunded_items( $order ) {
		$refunded_line_items = array();
		$line_items          = $order->get_items();

		foreach ( $line_items as $item_id => $item ) {
			$refunded_line_items[ $item_id ]['qty'] = $item->get_quantity();
		}
		wc_restock_refunded_items( $order, $refunded_line_items );
	}

	/**
	 * Use this generated token to secure get payment status.
	 * Before call this function make sure _paysley_transaction_id and _paysley_secret_key already saved.
	 *
	 * @param int    $order_id - Order Id.
	 * @param string $currency - Currency.
	 *
	 * @return string
	 */
	protected function generate_token( $order_id, $currency ) {
		$transaction_id = get_post_meta( $order_id, '_paysley_transaction_id', true );
		$secret_key     = get_post_meta( $order_id, '_paysley_secret_key', true );

		return md5( (string) $order_id . $currency . $transaction_id . $secret_key );
	}

	/**
	 * Page to handle response from the gateway.
	 * Get payment status and update order status.
	 *
	 * @param int $order_id - Order Id.
	 */
	public function response_page( $order_id ) {
		$token = get_query_var( 'py_token' );

		if ( ! empty( $token ) ) {
			$this->log( 'get response from the gateway reponse url' );
			$response = get_query_var( 'response' );
			$this->log( 'response_page - original response: ' . $response );
			$response = json_decode( wp_unslash( $response ), true );
			$this->log( 'response_page - formated response: ' . wp_json_encode( $response ) );

			$payment_status = '';
			$payment_id     = '';
			$currency       = '';

			if ( isset( $response['status'] ) ) {
				$payment_status = $response['status'];
			} elseif ( isset( $response['result'] ) ) {
				$payment_status = $response['result'];
			}

			if ( isset( $response['payment_id'] ) ) {
				$payment_id = $response['payment_id'];
			} elseif ( isset( $response['response'] ) && isset( $response['response']['id'] ) ) {
				$payment_id = $response['response']['id'];
			}

			if ( isset( $response['currency'] ) ) {
				$currency = $response['currency'];
			} elseif ( isset( $response['response'] ) && isset( $response['response']['currency'] ) ) {
				$currency = $response['response']['currency'];
			}

			$generated_token = $this->generate_token( $order_id, $currency );
			$order           = wc_get_order( $order_id );

			if ( $order && 'paysley' === $order->get_payment_method() ) {
				if ( $token === $generated_token ) {
					if ( 'ACK' === $payment_status ) {
						$this->log( 'response_page: update order status to processing' );
						$order_status = 'processing';
						$order_notes  = 'Paysley payment successfull:';
						update_post_meta( $order->get_id(), '_paysley_payment_id', $payment_id );
						update_post_meta( $order->get_id(), '_paysley_payment_result', 'succes' );
						$order->update_status( $order_status, $order_notes );
					} else {
						$this->log( 'response_page: update order status to failed' );
						$order_status = 'failed';
						$order_notes  = 'Paysley payment failed:';
						update_post_meta( $order->get_id(), '_paysley_payment_result', 'failed' );
						$order->update_status( $order_status, $order_notes );
					}
					die( 'OK' );
				} else {
					$this->log( 'response_page: FRAUD detected, token is not same with the generated token' );
				}
			}
		} else {
			$this->log( 'response_page: go to thank you page' );
		}
	}

}
