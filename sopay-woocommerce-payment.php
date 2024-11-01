<?php

/*

 * Plugin Name: SoPay Gateway for WooCommerce

 * Description: SoPay payment module for WooCommerce.

 * Author: Codemakers

 * Author URI: https://codemakers.dk/

 * Version: 1.0.2

 */



if ( ! defined( 'ABSPATH' ) ) {

	exit;

} // Exit if accessed directly



add_filter( 'woocommerce_payment_gateways', 'sopay_gateway_class' );

function sopay_gateway_class( $gateways ) {

	$gateways[] = 'WC_SoPay_Gateway';

	return $gateways;

}





add_action( 'plugins_loaded', 'sopay_init_gateway_class' );

function sopay_init_gateway_class() {



	class WC_SoPay_Gateway extends WC_Payment_Gateway {

		/**

		* Constructor

		*/

 		public function __construct() {

 			$this->id = 'sopay';

			$this->icon = '';

			$this->has_fields = true;

			$this->method_title = 'SoPay Gateway';

			$this->method_description = 'SoPay redirects customers to SoPay website to enter their payment information.';

			$this->supports = array(

				'products',

				'refunds'

			);

			$this->init_form_fields();

			$this->init_settings();

			$this->title = $this->get_option( 'title' );

			$this->description = $this->get_option( 'description' );

			$this->enabled = $this->get_option( 'enabled' );

			$this->api_key = $this->get_option( 'api_key' );

			$this->environment = $this->get_option( 'environment' );



			// Actions

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		 	add_action( 'woocommerce_thankyou', array( $this, 'sopay_thankyou' ));

			add_action( 'woocommerce_api_sopay-payment-complete', array( $this, 'webhook' ) );

 		}



		/**

		* Admin page field

		*/

 		public function init_form_fields(){

			$this->form_fields = array(

				'enabled' => array(

					'title'       => __( 'Enable/Disable', 'sopay-woocommerce-payment' ),

					'label'       => __( 'Enable SoPay Gateway', 'sopay-woocommerce-payment' ),

					'type'        => 'checkbox',

					'description' => '',

					'default'     => 'no'

				),

				'environment' => array(

					'title'       => __( 'Environment', 'sopay-woocommerce-payment' ),

					'type'        => 'select',

					'description' => __( 'Choose production or sandbox environment.', 'sopay-woocommerce-payment' ),

					'default'     => 'production',

					'options' => array(

						'sandbox'        => __( 'Sandbox', 'woocommerce' ),

						'production'       => __( 'Production', 'woocommerce' ),

					),

				),

				'api_key' => array(

					'title'       => __( 'API KEY', 'sopay-woocommerce-payment' ),

					'type'        => 'text',

				),

				'title' => array(

					'title'       => __( 'Title', 'sopay-woocommerce-payment' ),

					'type'        => 'text',

					'description' => __( 'This controls the title which the user sees during checkout.', 'sopay-woocommerce-payment' ),

					'default'     => __( 'Credit Card', 'sopay-woocommerce-payment' ),

				),

				'description' => array(

					'title'       => __( 'Description', 'sopay-woocommerce-payment' ),

					'type'        => 'textarea',

					'description' => __( 'This controls the description which the user sees during checkout.', 'sopay-woocommerce-payment' ),

					'default'     => __( 'Pay with your credit card via our super-cool payment gateway.', 'sopay-woocommerce-payment' ),

				)

			);

	 	}



		/**

		* Check selected environment and set respective URL

		*/

		public function get_active_environment() {

			if ( $this->environment ) {

				error_log($this->environment);

				$url = '';

				if ($this->environment === 'sandbox') {

					$url = 'https://sandbox.sopayapp.com/mmmp/';

				} else {

					$url = 'https://api.sopayapp.com/mmmp/';

				}

				error_log(print_r($url, true));

				return $url;

			}

		}



		/**

		* Print SoPay description in checkout page

		*/

		public function payment_fields() {

			if ( $this->description ) {

				echo wpautop( wp_kses_post( $this->description ) );

			}

		}



		/**

		* SoPay validation currency

		*/

		public function validate_fields(){

			global  $woocommerce;

			if(get_woocommerce_currency() != 'USD'){

				wc_add_notice('Invalid currency!', 'error' );

				return false;

			}

			return true;

		}



		/**

		* SoPay Process Payment

		* Redirect to payment page

		*/

		public function process_payment( $order_id ) {

			global $woocommerce;

    		$order = new WC_Order( $order_id );

			$time = date('h:i:s');

			$resp = get_sopay_token($this->api_key);



			if($resp != 'error'){



				$fields = array(

					'amount' => $order->get_total(),

					'currency' => 'USD',

					'externalId' => $order_id.'_'.$time,

					'returnUrl' => $this->get_return_url( $order ),

					'callbackUrl' => get_home_url().'/wc-api/sopay-payment-complete/',

					'cancelUrl' => get_home_url().'/checkout/'

				);



				$args = array(

					'method' => 'POST',

					'headers' => array(

						'Content-Type' => 'application/json',

						'Authorization' => 'Bearer '. $resp

					),

					'body' => json_encode($fields)

				);

				$response = wp_remote_post( 	$this->get_active_environment().'api/v1/payment/weborder/create', $args );

				$array = $response["response"];

				$results = json_decode($response["body"], true);

				if ($array["code"] == '200') {

					update_post_meta($order_id, 'externalId', $order_id.'_'.$time);

					return array(

						'result' => 'success',

						'redirect' => 	$this->get_active_environment()."checkout/v1/start?order=" . $results['order']

					);

				}else{

					wc_add_notice(  __( 'Please try again.', 'sopay-woocommerce-payment' ), 'error' );

					return;

				}

			}else{

				wc_add_notice(  __( 'Connection error.', 'sopay-woocommerce-payment' ), 'error' );

				return;

			}



	 	}



		/**

		* SoPay check payment

		*/

		public function webhook() {

			$request_body = file_get_contents( 'php://input' );

			$response = json_decode($request_body, true);

			if('/wc-api/sopay-payment-complete/' == $_SERVER['REQUEST_URI']){

				$extern_id = $response['order']['externalId'];

				$order_id_externID = $response['order']['externalId'];

				$order_id_externID = explode('_', $order_id_externID);

				$order_id = $order_id_externID[0];

				$order = new WC_Order( $order_id );

				$resp = get_sopay_token($this->api_key);

				$queryResp = get_sopay_order_info($resp, $extern_id);

				if($resp != 'error'){

					if ($queryResp["header"]["status"] != 'ERROR') {

						$order->add_order_note(

							sprintf( __( 'SoPay payment approved! Transaction ID: %s. Payer Name: %s. Payer Mobile: %s. Payee Name: %s.', 'sopay-woocommerce-payment' ),

								$queryResp["id"],

								$queryResp["payerName"],

								$queryResp["payerMobileNumber"],

								$queryResp["payeeName"]

							)

						);

						$order->update_status( 'processing' );

					}else{

						sopay_logs("Webhook error");

						sopay_logs($queryResp);

					}

				}

			}

		}



		/**

		* SoPay Refund

		*/

		public function process_refund( $order_id, $amount = null, $reason = '' ) {

			$order = new WC_Order( $order_id );

			if($order->get_total() == $amount){

				$resp = get_sopay_token($this->api_key);

				$externalId = get_post_meta($order_id, 'externalId', true);

				$order_info = get_sopay_order_info($resp, $externalId);

				$origin = 'sopay-checkout-sample-php';

				$fields = array(

					'header' => array(

						"origin" => $origin,

						"retry" => false,

						"trackingId" => uniqid()

					),

					'body' => array(

						"autoCapture" => true,

						'externalId' => $order_id.$order_info['id'],

						'message' => 'Test refund',

						'paymentId' => $order_info['id']

					)

				);



				$args = array(

					'method' => 'POST',

					'headers' => array(

						'Content-Type' => 'application/json',

						'Authorization' => 'Bearer '. $resp

					),

					'body' => json_encode($fields)

				);

				$response = wp_remote_post( 	$this->get_active_environment().'api/v1/payment/refund', $args );

				$array = $response["response"];

				$results = json_decode($response["body"], true);

				if ($array["code"] == '200') {

					$order->add_order_note(

						sprintf( __( 'Refunded: %s.', 'sopay-woocommerce-payment' ),

							wc_price( $amount )

						)

					);

					return true;

				}else{

					return false;

					sopay_logs("Process refund error");

					sopay_logs($array);

				}

			}else{

				return false;

			}

		}



		/**

		* SoPay Thank You Page

		*/

		public function sopay_thankyou($order_id) {

			$resp = get_sopay_token($this->api_key);

			$queryResp = get_sopay_order_info($resp, $_POST["externalId"]);

			if($resp != 'error'){

				$order = new WC_Order( $order_id );

				if ($queryResp["header"]["status"] == 'ERROR') {

					echo "<style>.site-main header, .site-main .woocommerce-customer-details, .site-main .woocommerce-order-details, .site-main .woocommerce-thankyou-order-details, .site-main .woocommerce-notice {display:none;}</style>";

					echo "<h1>Payment failed</h1>";

					echo " - Status Code: " . $queryResp['body']['statusCode'] . " - Message: " . $queryResp['body']['localizedMessage'] . "<br>";

					sopay_logs("Thank you page error");

					sopay_logs($queryResp);

				}

			}else{

				sopay_logs("Thank you page error");

				sopay_logs($queryResp);

			}

		}

 	}

}



/**

* Get SoPay token

*/

function get_sopay_token($apikey){

	$fields = array('apiKey' => $apikey);

	$args = array(

		'method' => 'POST',

		'headers' => array(

			'Content-Type' => 'application/json'

		),

		'body' => json_encode($fields)

	);

	$WC_SoPay_Class = new WC_SoPay_Gateway();



	$response = wp_remote_post( $WC_SoPay_Class->get_active_environment().'api/v1/auth', $args );

	$array = $response["response"];

	$results = json_decode($response["body"], true);

	if ($array["code"] == '200') {

		$result = $results['token'];

	}else{

		$result = 'error';

		sopay_logs("SoPay token error");

		sopay_logs($array);

	}

	return $result;

}



/**

* Get SoPay order info

*/

function get_sopay_order_info($token, $externalId){

	$args = array(

		'method' => 'GET',

		'headers' => array(

			'Authorization' => 'Bearer '.$token

		)

	);

	$WC_SoPay_Class = new WC_SoPay_Gateway();



	$response = wp_remote_post( $WC_SoPay_Class->get_active_environment().'api/v1/payment/externalid/' . $externalId, $args );

	$array = $response["response"];

	$results = json_decode($response["body"], true);

	if ($array["code"] == '200') {

		$result = $results;

	}else{

		$result = 'error';

		sopay_logs("Order info error");

		sopay_logs($array);

	}

	return $results;

}



/**

* Write log

*/

function sopay_logs($message) {

    $path = dirname(__FILE__) . '/log.txt';

    $agent = $_SERVER['HTTP_USER_AGENT'];

    if (($h = fopen($path, "a")) !== FALSE) {

        $mystring = date('Y-m-d h:i:s') ." : ". print_r($message, TRUE) ."\n";

        fwrite( $h, $mystring );

        fclose($h);

    }

}

