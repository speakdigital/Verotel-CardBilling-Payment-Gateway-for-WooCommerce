<?php
/*
Plugin Name: Verotel / CardBilling Payment Gateway for WooCommerce + Subscriptions
Plugin URI: http://wordpress.org/#
Description: Use Verotel or CardBilling as a payment method with your WooCommerce store.
Author: Speak Digital
Version: 2.1
Author URI: http://www.speakdigital.co.uk
Notes: 
	John Croucher www.jcroucher.com
		- Added subscription support 
		- Added Verotel Control center API calls
*/


/**
 * Add this gateway to the list of available gateways
 *
 * @param WC_Order $order
 * @return array
 */
function add_CardBilling( $methods ) {
	$methods[] = 'WC_Gateway_CardBilling'; 
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_CardBilling' );


function init_CardBilling() {

	class WC_Gateway_CardBilling extends WC_Payment_Gateway {

		private $gateway_currency = 'USD'; // Defaults to USD, can be set through admin

		function __construct()	
		{	

			require_once("FlexPay.php");

			// Turn on options for subscriptions
			$this->supports = array( 
				'subscriptions', 
				'products', 
				'subscription_cancellation',
				'gateway_scheduled_payments' // This turns off the WooSubscriptions automated payments as Verotel handles this.
			);

			$this->id = "cardbilling";
			$this->icon = null;
			$this->method_title = "Verotel / CardBilling";
			$this->method_description = "Allows for single purchase payments via your Verotel / CardBilling merchant account.";
			
			$url_pb = home_url( '/' )."?wc-api=WC_Gateway_CardBilling&Action=Approval_Post";
			$url_su = home_url( '/' )."?wc-api=WC_Gateway_CardBilling&Action=CheckoutSuccess";
			
			$this->method_description .= "\n\nYour Postback script URL: " . $url_pb;
			$this->method_description .= "\nYour Success URL: " . $url_su;

			$this->init_form_fields();
			$this->init_settings();

			// Turn these settings into variables we can use
			foreach ( $this->settings as $setting_key => $value ) {
				$this->$setting_key = $value;
			}


			// Lets check for SSL
			add_action( 'admin_notices', array( $this,	'do_ssl_check' ) );

			// Save settings
			if ( is_admin() ) {
				// Versions over 2.0
				// Save our administration options. Since we are not going to be doing anything special
				// we have not defined 'process_admin_options' in this class so the method in the parent
				// class will be used instead
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}	
			
			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_gateway_cardbilling', array( $this, 'check_verotel_response' ) );
			add_action( 'woocommerce_api_wc_gateway_cardbilling', array( $this, 'process_verotel_response' ) );
			
			// Hook triggered after a subscription is cancelled
			add_action( 'cancelled_subscription', array( $this, 'cancel_subscription' ),0,2 );

		}



		/**
		 *
	     * Admin form fields
	     *
	     */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title'		=> 'Enable / Disable',
					'label'		=> 'Enable this payment gateway',
					'type'		=> 'checkbox',
					'default'	=> 'no',
				),
				'which' => array (
					'title'		=> 'Which site are you using?',
					'type'		=> 'select',
					'default'	=> 'verotel',
					'options'	=> array( 'verotel' => 'Verotel', 'cardbilling' => "CardBilling"),
				),
				'gateway_currency' => array (
					'title'		=> 'Which currency do you want to send to the gateway',
					'type'		=> 'select',
					'default'	=> 'USD',
					'options'	=> array( 
						'USD' => 'USD', 
						'AUD' => "AUD", 
						'EUR' => 'EUR'
						)
				),
				'title' => array(
					'title'		=> 'Title',
					'type'		=> 'text',
					'desc_tip'	=> 'Payment title the customer will see during the checkout process.',
					'default'	=> 'Credit Card (via Verotel/CardBilling)',
				),
				'description' => array(
					'title'		=> 'Description',
					'type'		=> 'textarea',
					'desc_tip'	=> 'Payment description the customer will see during the checkout process.',
					'default'	=> 'Pay securely with your credit card via Verotel/CardBilling',
					'css'		=> 'max-width:350px;'
				),
				'sig_key' => array(
					'title'		=> 'FlexPay Signatre Key',
					'type'		=> 'text',
					'desc_tip'	=> 'This key is generated in your Verotel/CardBilling Control Center under FelxPay Options',
				),
				'shop_id' => array(
					'title'		=> 'Shop ID',
					'type'		=> 'text',
					'desc_tip'	=> 'This ID is displayed in your Verotel/CardBilling Control Center under FelxPay Options',
				),
				'controlcenter_key' => array(
					'title'		=> 'Control Center Key',
					'type'		=> 'text',
					'desc_tip'	=> 'This is generated in your Verotel/CardBilling Control Center. This is separate to FlexPay',
				),
			);
		}

		/**
	     * Check if we are forcing SSL on checkout pages
	     * Custom function not required by the Gateway
	     */
		public function do_ssl_check() {
			if( $this->enabled == "yes" ) {
				if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
					echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";	
				}
			}		
		}

		/**
		* Internal WooCommerce processing
		*
		* @param int $order_id
    	* @return array
		*/		
		function process_payment( $order_id ) {

			global $woocommerce;
			
			// Create the order
			$order = new WC_Order( $order_id );

			// Mark as on-hold (we're awaiting the cheque)
			$order->update_status('on-hold', __( 'Awaiting credit card payment', 'woocommerce' ));

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			$woocommerce->cart->empty_cart();


			// The redirect URL goes to Verotel so the payment can be made
			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url( $order )
			);
		}

		/**
		 * Get the URL for the appropriate Verotel action
		 *
	     * @param WC_Order $order
	     * @return string
	     */
		public function get_return_url( $order = NULL ) {

			// Basic params for the gateway
			$params = array(
				'shopID' => $this->shop_id,
				'priceAmount' => $order->order_total,
				'priceCurrency' =>  $this->gateway_currency,
				'description' => 'Order '.$order->id,
				'referenceID' => $order->id,
				'email' => $order->billing_email,
				'origin' => 'WooCommerce',
//				'custom1' => 'Custom 1',
//            	'custom3' => 'Custom 3',
            	'type' => 'purchase',
			);
			

			// If the order contains a subscription process the order using the subscription message
			if ( $this->order_contains_subscription( $order->id ) ) 
			{
				$params['type'] = 'subscription';
				$params['subscriptionType'] = 'recurring';
				$params['period'] = 'P1M';

				$price_per_period = WC_Subscriptions_Order::get_recurring_total( $order );
				$url = FlexPay::get_subscription_URL($this->sig_key,$params);	
			}
			else
			{
				// Standard purchase
				$url = FlexPay::get_purchase_URL($this->sig_key,$params);	
			}
			
			
			return $url;
		}

		/**
		 * This method is called when the subscription is cancelled from within WooCommerce
		 *
	     * @param int $user_id
	     * @param string $subscription_key
	     */
		function cancel_subscription( $user_id, $subscription_key ) {

			$splitKey = explode('_',$subscription_key);

			if( isset($splitKey[0]) )
				$order_id = $splitKey[0];
			else 
				$order_id = $subscription_key;

			$order = wc_get_order( $order_id );

			$saleID = $this->get_token_sale_id( $order, $params['saleID'] );

			// Send the cancel request to Verotel
			$url = 'https://controlcenter.verotel.com/api/sale/' . $saleID . '/cancel';
			$response = $this->controlCenterRequest($url);

			if( $response === false )
			{
				$order->update_status( 'cancelled' );
				$order->add_order_note( __( 'Cancel requested by user.','woocommerce-subscriptions' ), false, true );

		       	if ( $this->order_contains_subscription( $order->id ) ) 
		       	{
					WC_Subscriptions_Manager::cancel_subscriptions_for_order( $order );
				}
			}
			else 
			{
				$order->add_order_note( __( 'Failed cancel requested by user. Resp: ' . json_encode($response), 'woocommerce-subscriptions' ), false, true );
				$order->update_status( 'completed' );
				WC_Subscriptions_Manager::activate_subscriptions_for_order( $order );
			}
			

			

		}
		

		/**
	     * This is called with an API request from the Verotel gateway. It is used as a basic check before further processing.
	     */
		function check_verotel_response() {
 			@ob_clean();

			global $woocommerce;

			$params = $_REQUEST;

			$error = false;

			if (!isset($params['signature'])) 
			{	
				$error = "No signature supplied";
			} 
			else
			{	
				if (!FlexPay::validate_signature($this->sig_key,$params))
				{	
					$error = "Signature check failed";
				} 
				else
				{	

					$eventAction = ( isset($_REQUEST['event']) ) ? $_REQUEST['event'] : ''; // Subscriptions have events associated with the action

					if( $eventAction != 'cancel' )
					{
						// We may have a transactionID or a referenceID
						$referenceID = 0;

						if( isset($params['transactionID']) )
							$referenceID = $params['transactionID'];


						if( isset($params['referenceID']) ) // Always fallback to a referenceID
							$referenceID = $params['referenceID'];

						// Attempt to load the order from the reference
						$order = new WC_Order( $referenceID );

						$this->set_token_sale_id( $order, $params['saleID'] );


						// We may have a priceAmount or an here depending on the type of request
						$amount = 0;

						if( isset($params['priceAmount']) )
							$amount = $params['priceAmount'];

						if( isset($params['amount']) )
							$amount = $params['amount'];

						// Make sure the amount in WooCommerce matches the amount sent from the gateway
						if ( $amount != $order->order_total )
						{	
							$error = "Order value does not match payment value. Order: $" . $order->order_total . ' Paid: $' . $amount;
						} 
					}
				}
			}

			if ($error) {
				wp_die("Verotel Transaction Verification Failed: ".$error); //TODO: Need to email site manager and display nicer error
			}
		}
		
		/**
	     * This is called with an API request from the Verotel gateway. This does the actual processing of the Verotel request.
	     */
		function process_verotel_response() {
			@ob_clean();
	  
			$responseAction = $_REQUEST['Action'] != null ? $_REQUEST['Action'] : '';
			$eventAction = ( isset($_REQUEST['event']) ) ? $_REQUEST['event'] : ''; // Subscriptions have events associated with the action

			global $woocommerce;
			
			switch(strToLower($responseAction)) 
			{
			  case 'checkoutsuccess':
				wp_die('<p>Thank you for your order.  Your payment has been approved.</p><p><a href="' . get_permalink( get_option('woocommerce_myaccount_page_id') ) . '" title="' . _e('My Account','woothemes') . '">My Account</a></p><p><a href="?">Return Home</a></p>', array( 'response' => 200 ) );
				break;

			  case 'approval_post':

			  		// We may have a transactionID or a referenceID
					$referenceID = 0;

					if( isset($_REQUEST['transactionID']) )
						$referenceID = $_REQUEST['transactionID'];

					if( isset($_REQUEST['referenceID']) ) // Always fallback to a referenceID
						$referenceID = $_REQUEST['referenceID'];

					$order = new WC_Order( $referenceID );


					if( $order === false || !$order->id > 0 )
						wp_die( "Unable to find the requested order", "Verotel API", array( 'response' => 200 ) );

					// No action is a standard request
			  		if( $eventAction == '' )
			  		{
						$order->add_order_note( __( 'PDT payment completed', 'woocommerce' ) ."\nVerotel Payment Reference: ".$_REQUEST['saleID']);
						$order->payment_complete();
						print "OK"; 
						exit;
			  		}
			  		else 
			  		{
			  			$response = null;


			  			switch(strToLower($eventAction))
			  			{
			  				case 'initial':
			  				case 'rebill': // Gateway has sent a subscription renewal request
			  					$response = $this->gateway_subscription_payment($order);
			  					break;

			  				case 'chargeback':
			  				case 'cancel':
			  					$response = $this->gateway_cancel_payment($order);
			  					break;
			  			}

			  			if( $response === true )
			  			{
			  				print "OK";
			  				exit;
			  			}
			  			else 
			  			{
			  				if( $response === null )
			  				{
								wp_die( "Unknown Event Action Given: " . $eventAction, "Verotel API", array( 'response' => 200 ) );
			  				}
			  				else 
			  				{
			  					wp_die( "Renewal failure", "Verotel API", array( 'response' => 200 ) );
			  				}
			  				
			  			}

			  		}
					  
				break;

			  default: 
			  	wp_die( "Unknown Action Given", "Verotel API", array( 'response' => 200 ) );
				break;
			}
		}


		/**
	     * The gateway has said it is about to process the users renewal, so we need to update the details on our end.
	     *
	     * @param WC_Order $order
	     */
		public function gateway_subscription_payment( $order ) {

			// Make sure the order requested is a subscription, or contains a subscription.
			if ( $this->order_contains_subscription( $order->id ) ) /*&& !$order->has_status( wcs_get_subscription_ended_statuses() )*/ 
			{

				$order->update_status( 'on-hold' );

				// generate a renewal order as normal
				$renewal_order = wcs_create_renewal_order( $order );
				$renewal_order->set_payment_method( $order->payment_gateway );

				// Update the renewal order status to completed
				// This also makes the subscription active
				$renewal_order->update_status('completed');

				// Add a custom note for logging
				$order->add_order_note( __( 'Create and complete renewal order requested by gateway.', 'woocommerce-subscriptions' ), false, true );

				$order->update_status( 'completed' );

				return true;

			}

	        return false;

	    }

		/**
	     * The gateway has said that the subscription has been cancelled, so we need to cancel it on our end.
	     *
	     * @param WC_Order $order
	     * @return bool
	     */
	    public function gateway_cancel_payment( $order ) {

			$order->update_status( 'cancelled' );
			$order->add_order_note( __( 'Cancel requested by gateway.', 'woocommerce-subscriptions' ), false, true );

	       	if ( $this->order_contains_subscription( $order->id ) ) /*&& !$order->has_status( wcs_get_subscription_ended_statuses() )*/ 
	       	{
				WC_Subscriptions_Manager::cancel_subscriptions_for_order( $order );
			}

	        return true;

	    }

		function verify_verotel_sale() // NOT COMPLETE
		{	
			//now check with Verotel and get full data
			$statusparams = array();
			$statusparams['shopID'] = $this->shop_id;
			$statusparams['referenceID'] = $params['saleID'];				
			$url = FlexPay::get_status_URL($this->sig_key,$statusparams);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_FAILONERROR,1);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
			$response = curl_exec($ch);			 
			curl_close($ch);

			if (!$response)
			{	
				$error = "Invalid / No response from Verotel";
			} 
			else
			{	
				$response = explode("\n",$response);
				
				if ($response[0] == "response: NOTFOUND")
				{	
					$error = "Verotel says transaction not found";
				} 
				else if ($response[0] == "response: ERROR")
				{	
					$error = "Verotel says ".$response[2];
				} 
				else 
				{	
					print_r($response);
				}
			}
		}

		/**
		 * Returns the WC_Subscription(s) tied to a WC_Order, or a boolean false.
		 *
		 * @param  WC_Order $order
		 * @return bool|WC_Subscription
		 */
		 function get_subscriptions_from_order( $order ) {

			if ( $this->order_contains_subscription( $order->id ) ) {

				$subscriptions = wcs_get_subscriptions_for_order( $order );

				if ( $subscriptions ) {

					return $subscriptions;

				}

			}

			return false;

		}

		/**
         * Check if order contains subscriptions.
         *
         * @param  WC_Order $order_id
         * @return bool
         */
        function order_contains_subscription( $order_id ) {

        	return ( wcs_is_subscription( $order_id ) || wcs_order_contains_subscription($order_id) || wcs_order_contains_renewal( $order_id ) );
        }

		/**
		 * Get the token sale id for an order
		 *
		 * @param WC_Order $order
		 * @return array|mixed
		 */
		function get_token_sale_id( $order ) {

			$subscriptions = $this->get_subscriptions_from_order( $order );

			if ( $subscriptions ) {

				$subscription = array_shift( $subscriptions );

				return get_post_meta( $subscription->id, '_verotel_sale_id', true );

			}
			else 
			{
				return get_post_meta( $order->id, '_verotel_sale_id', true );
			}

		}


		/**
		 * Set the token sale id for an order
		 *
		 * @param WC_Order $order
		 * @param int $token_sale_id
		 */
		function set_token_sale_id( $order, $token_sale_id ) {

			$subscriptions = $this->get_subscriptions_from_order( $order );

			if ( $subscriptions ) {

				foreach ( $subscriptions as $subscription ) {

					update_post_meta( $subscription->id, '_verotel_sale_id', $token_sale_id );
				}

			}
			else 
			{
				update_post_meta( $order->id, '_verotel_sale_id', $token_sale_id );
			}

		}


		/**
		 * Send a request tot he Verotel Control Center API
		 *
		 * @param string $url
		 * @return bool|string
		 */
		function controlCenterRequest($url)
		{
			$ch = curl_init();

			curl_setopt_array($ch, array(
			    CURLOPT_RETURNTRANSFER => 1,
			    CURLOPT_URL => $url,
			    CURLOPT_USERAGENT => 'woocommerce-subscriptions',
			   	CURLOPT_SSL_VERIFYPEER => false,
			   	CURLOPT_POST => 1,
			    CURLOPT_HTTPHEADER     => array(
				                    'Authorization: Basic ' . $this->controlcenter_key,
									'Accept: application/json; version=1.2.1'
				                )
			));

			$response = curl_exec($ch);			 
			curl_close($ch);

			$error = true;

			if (!$response)
			{	
				$error = "Invalid / No response from Verotel";
			} 
			else
			{	
				$response = json_decode($response,true);

				if( isset($response['error']) )
				{
					$error = $response['error']['title'];
				}
				elseif( isset($response['is_success']) && $response['is_success'] === true )
				{
					$error = false;
				}
			}


	        return $error;
    
		}
	}
}
add_action( 'plugins_loaded', 'init_CardBilling' );
