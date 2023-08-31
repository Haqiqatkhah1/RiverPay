<?php

//Direct Access NoN
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

//Start Plugin Work
function Riverpay_Gateway() {

	//Force Used USD
	if ( get_woocommerce_currency() == 'USD' ) {

		//Check WooCommerce Exists
		if ( ! function_exists( 'Add_River_Gateway' ) && class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'WC_Riverpay' ) ) {
			add_filter( 'woocommerce_payment_gateways', 'Add_River_Gateway' );
			function Add_River_Gateway( $methods ) {
				$methods[] = 'WC_Riverpay';

				return $methods;
			}


			//Class River
			class WC_Riverpay extends WC_Payment_Gateway {

				private $username;
				private $password;
				private $webhook_url;
				private $payment_url;
				private $status_url;
				private $oauth_url;


				//Construct
				public function __construct() {
					$this->id                 = 'WC_Riverpay';
					$this->method_title       = __( 'RiverPay gateway', 'woocommerce' );
					$this->method_description = __( 'RiverPay gateway settings', 'woocommerce' );
					$this->has_fields         = false;

					$this->init_form_fields();
					$this->init_settings();
					$this->init_plugin();

					//Actions
					if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
						add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
							&$this,
							'process_admin_options'
						) );
					} else {
						add_action( 'woocommerce_update_options_payment_gateways', array(
							&$this,
							'process_admin_options'
						) );
					}

					//create order
					add_action( 'woocommerce_receipt_' . $this->id . '', array( $this, 'Send_to_River_Gateway' ) );

					//callback
					add_action( 'init', array( &$this, 'process_callback' ) );
					add_action( 'woocommerce_api_river_callback_url', array( &$this, 'process_callback1' ) );

					//web hook
					add_action( 'woocommerce_api_river_hook_url', array( &$this, 'process_webhook' ) );

					//customize checkout page
					add_action( 'woocommerce_order_details_after_order_table_items', array(
						$this,
						'add_River_status_to_order_detail'
					) );
					add_action( 'woocommerce_order_details_before_order_table', array(
						$this,
						'add_River_note_in_checkout_page'
					) );


				}

				//Init Plugin Data
				function init_plugin() {
					$this->icon        = apply_filters( 'WC_River_logo', WP_PLUGIN_URL . '/' . plugin_basename( __DIR__ ) . '/assets/images/Group-15.webp' . '" class="river_logo"' );
					$this->webhook_url = WC()->api_request_url( 'river_hook_url' );
					$this->title       = $this->settings['title'];
					$this->description = $this->settings['description'];
					$this->username    = $this->settings['username'];
					$this->password    = $this->settings['password'];
					$this->payment_url = 'https://api.riverpay.io/v1/payment';
					$this->status_url  = 'https://api.riverpay.io/v1/payment/inquiry';
					$this->oauth_url   = 'https://api.riverpay.io/api/v1/payment/oauth/token';
				}

				//Admin Form Settings
				public function init_form_fields() {
					$this->form_fields = apply_filters( 'WC_River_Config', array(
							'base_config' => array(
								'title'       => __( 'Base Settings', 'woocommerce' ),
								'type'        => 'title',
								'description' => 'settings for River gateway',
							),
							'enabled'     => array(
								'title'   => __( 'Active/Deactivate', 'woocommerce' ),
								'type'    => 'checkbox',
								'label'   => __( 'Active/Deactivate', 'woocommerce' ),
								'default' => 'yes',
							),
							'title'       => array(
								'title'   => __( 'Gateway Title', 'woocommerce' ),
								'type'    => 'text',
								'default' => __( 'RiverPay', 'woocommerce' ),
							),
							'description' => array(
								'title'       => __( 'Gateway description', 'woocommerce' ),
								'type'        => 'textarea',
								'desc_tip'    => true,
								'description' => __( 'Descriptions that will be displayed to the gateway during the payment process', 'woocommerce' ),
								'default'     => __( 'Secure payment through the River gateway', 'woocommerce' )
							),
							'username'    => array(
								'title'   => __( 'User Name', 'woocommerce' ),
								'type'    => 'text',
								'default' => ''
							),
							'password'    => array(
								'title'   => __( 'Password', 'woocommerce' ),
								'type'    => 'text',
								'default' => ''
							)
						)
					);
				}

				//Process Payments
				public function process_payment( $order_id ) {
					$order = new WC_Order( $order_id );

					return array(
						'result'   => 'success',
						'redirect' => $order->get_checkout_payment_url( true )
					);
				}


				//Get Authenticate Code
				public function login() {
					$post = json_encode( [
						'username' => $this->username,
						'password' => $this->password,
					] );

					$headers  = [
						"content-type" => "application/json"
					];
					$response = wp_remote_post(
						$this->oauth_url,
						array(
							'method'  => 'POST',
							'timeout' => 120,
							'headers' => $headers,
//                        'user-agent' => self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION,
							'body'    => $post,
						)

					);

					$body     = wp_remote_retrieve_body( $response );
					$response = json_decode( $body, true );

					return $response['data']['access_token'];

				}


				//Inquiry

				/**
				 * @param $params
				 *
				 * @return mixed
				 */
				public function GetStatusFromRiver( $params ) {
					$headers  = [
						"content-type"   => "application/json",
						"Authentication" => 'bearer ' . $this->login(),
					];
					$response = wp_remote_post(
						$this->status_url,
						array(
							'method'  => 'POST',
							'timeout' => 120,
							'headers' => $headers,
//                        'user-agent' => self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION,
							'body'    => $params,
						)
					);
					$body     = wp_remote_retrieve_body( $response );
					$response = json_decode( $body, true );

					return $response;
				}


				//Notes

				/**
				 * @param $order
				 */
				public function add_River_status_to_order_detail( $order ) {
					$order_id = $order->get_id();

					$refId = get_post_meta( $order_id, '_transaction_id' )[0];

					$result    = $this->GetStatusFromRiver( json_encode( [
						'refId' => $refId
					] ) );
					$statusMap = [
						0 => "Payment Pending",
						1 => "Payment Success",
						2 => "Payment Failed",
						3 => "Payment Uncompleted",
						4 => "Payment Expired",
					];

					if ( $result['success'] ) {
						$status = $result['data']['status'];
					} else {
						$status = 0;
					}
					echo "<th scope=\"row\">RiverPay Payment Status</th>
						<td><span class=\"woocommerce-Price-amount amount\"><span class=\"woocommerce-Price-currencySymbol\">$statusMap[$status]</td>";
					echo "<tr><th scope=\"row\">RiverPay Payment RefId</th>
						<td><span class=\"woocommerce-Price-amount amount\"><span class=\"woocommerce-Price-currencySymbol\">$refId</td></tr>";
				}

				//Human Language Order Status
				public function getMessageWithStatus( $status ) {
					$messages = [
						20 => 'Your order has been successfully queued for transaction confirmation',
						51 => 'Your payment failed',
						21 => 'Your transaction was approved and the payment was successful, but the customer\'s payment figure is different from the requested amount',
						50 => 'The transaction has expired'
					];

					return $messages[ $status ];

				}

				//Notes

				/**
				 * @param $order
				 */
				public function add_River_note_in_checkout_page( $order ) {
					$order_id = $order->get_id();

					$refId = get_post_meta( $order_id, '_transaction_id' )[0];

					$result = $this->GetStatusFromRiver( json_encode( [
						'refId' => $refId
					] ) );
					$status = $result['data']['status'];
					if ( $result['success'] ) {

						if ( $status == 1 ) {

							$Note = $this->getMessageWithStatus( $status );
							$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Success_Note', $Note, $order_id, $refId );

							//Processing
							if ( $order->get_status() != 'processing' ) {
								$order->add_order_note( $Note, 1 );
							}
							do_action( 'WC_RiverPay_Payment_Success', $order_id );
							$order->payment_complete();

						} else if ( $status == 3 ) {
							$Note = $this->getMessageWithStatus( $status );
							$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Failed_Note', $Note, $order_id, $refId );
							if ( $order->get_status() != 'failed' ) {
								$order->add_order_note( $Note, 1 );
							}
							do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
							$order->update_status( 'failed', $Note );

						} else if ( $status == 4 ) {

							$Note = $this->getMessageWithStatus( $status );
							$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Failed_Note', $Note, $order_id, $refId );
							if ( $order->get_status() != 'failed' ) {
								$order->add_order_note( $Note, 1 );
							}
							do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
							$order->update_status( 'failed', $Note );
							echo "<h3>$Note</h3>";
						} else if ( $status == 0 ) {
							$Note = $this->getMessageWithStatus( $status );
						} else {
							$Note = $this->getMessageWithStatus( $status );
							$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Failed_Note', $Note, $order_id, $refId );
							if ( $order->get_status() != 'failed' ) {
								$order->add_order_note( $Note, 1 );
							}
							do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
							$order->update_status( 'failed', $Note );
						}
						echo "<div style=\"text-align: center;border: 1px solid #c9c9c9;border-radius: 10px;padding: 25px 0;direction: rtl;\"><h3>{$Note}</h3></div>";
					}

				}


				public function check_order_status() {
					$on_hold_orders = wc_get_orders( array(
						'limit'  => - 1,
						'status' => 'on-hold',
					) );
					foreach ( $on_hold_orders as $order ) {

						$order_id = $order->get_id();
						$refId    = get_post_meta( $order_id, '_transaction_id' )[0];
						$result   = $this->GetStatusFromRiver( json_encode( [
							'refId' => $refId
						] ) );
						if ( $result['success'] ) {
							$status = $result['data']['statusCode'];
							if ( $status == 20 ) {
								$Note = $this->getMessageWithStatus( $status );
								$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Success_Note', $Note, $order_id, $refId );
								if ( $order->get_status() != 'processing' ) {
									$order->add_order_note( $Note, 1 );
								}
								do_action( 'WC_RiverPay_Payment_Success', $order_id );
								$order->payment_complete();
								exit;
							} else if ( $status == 51 || $status == 21 || $status == 22 ) {
								$Note = $this->getMessageWithStatus( $status );
								$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Failed_Note', $Note, $order_id, $refId );
								if ( $order->get_status() != 'failed' ) {
									$order->add_order_note( $Note, 1 );
								}
								do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
								$order->update_status( 'failed', $Note );
								exit;
							} else if ( $status == 50 ) {
								$Note = $this->getMessageWithStatus( $status );
								$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Failed_Note', $Note, $order_id, $refId );
								if ( $order->get_status() != 'failed' ) {
									$order->add_order_note( $Note, 1 );
								}
								do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
								$order->update_status( 'failed', $Note );
								exit;
							} else {
								$Note = $this->getMessageWithStatus( $status );
								$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Failed_Note', $Note, $order_id, $refId );
								if ( $order->get_status() != 'failed' ) {
									$order->add_order_note( $Note, 1 );
								}
								do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
								$order->update_status( 'failed', $Note );
								exit;
							}
						}
					}
				}


				/**
				 * @param $params string
				 *
				 * @return mixed
				 */

				//Send To River Func
				public function SendRequestToRiverPay( $params ) {
					$headers  = [
						"content-type"  => "application/json",
						"Authorization" => 'bearer ' . $this->login(),
					];
					$response = wp_remote_post(
						$this->payment_url,
						array(
							'method'     => 'POST',
							'timeout'    => 120,
							'headers'    => $headers,
							'user-agent' => 'Mozilla/5.0',
							'body'       => $params,
						)
					);
					$body     = wp_remote_retrieve_body( $response );
					$response = json_decode( $body, true );

					return $response;
				}

				//Turn To GateWay
				public function Send_to_River_Gateway( $order_id ) {
					global $woocommerce;

					$woocommerce->session->order_id_river = $order_id;

					$order    = new WC_Order( $order_id );
					$currency = $order->get_currency();
					$currency = apply_filters( 'RiverPay_Currency', $currency, $order_id );
					$form     = '<form action="" method="POST" class="riverpay-checkout-form" id="riverpay-checkout-form">
						<input type="submit" name="river_submit" class="button alt" id="river-payment-button" value="' . __( 'پرداخت', 'woocommerce' ) . '"/>
						<a class="button cancel" href="' . $woocommerce->cart->get_checkout_url() . '">' . __( 'بازگشت', 'woocommerce' ) . '</a>
					 </form><br/>';
					$form     = apply_filters( 'RiverPay_Form', $form, $order_id, $woocommerce );

					do_action( 'RiverPay_Gateway_Before_Form', $order_id, $woocommerce );
					echo $form;
					do_action( 'RiverPay_Gateway_After_Form', $order_id, $woocommerce );

					$Amount = (float) $order->order_total;

					//$CallbackUrl = add_query_arg('wc_order', $order_id, WC()->api_request_url('river_callback_url'));

					$data   = array(
						'orderId'     => $order_id,
						'amount'      => $Amount,
						'callBackUrl' => 'https://rivtest.digarsoo.com/wc-api/river_callback_url',
						'webhookUrl'  => $this->webhook_url
					);
					$result = $this->SendRequestToRiverPay( json_encode( $data ) );

					update_post_meta( $order_id, '_transaction_id', $result['data']['refId'] );
					$multiCoinRedirectUrl = $result['data']['redirectUrl'];

					$redirectUrl = $result['data']['redirectUrl'];

					if ( $result['message'] == 'Success' ) {


						wp_redirect( $multiCoinRedirectUrl );
						exit;

					} else {
						if ( ! $result['message'] ) {
							$Message = 'Connection to port failed';
						} else {
							$Message = $result['message'];
						}
					}
					if ( ! empty( $Message ) && $Message ) {
						$Note = sprintf( __( 'Error : %s', 'woocommerce' ), $Message );
						$Note = apply_filters( 'WC_RiverPay_Send_to_Gateway_Failed_Note', $Note, $order_id );
						$order->add_order_note( $Note );

						$Notice = sprintf( __( 'Error : <br/>%s', 'woocommerce' ), $Message );
						$Notice = apply_filters( 'WC_RiverPay_Send_to_Gateway_Failed_Notice', $Notice, $order_id );
						if ( $Notice ) {
							wc_add_notice( $Notice, 'error' );
						}
						do_action( 'WC_RiverPay_Send_to_Gateway_Failed', $order_id );
					}
				}

				//Return From GateWay
				public function process_callback1() {

					$status = isset( $_GET['statusCode'] ) ? $_GET['statusCode'] : null;

					$order_id = isset( $_GET['orderId'] ) ? $_GET['orderId'] : null;

					$refId = isset( $_GET['trxId'] ) ? $_GET['trxId'] : null;


					global $woocommerce;

					$orderNotFound = 'order not exists!';

					if ( ! is_null( $order_id ) ) {

						$order = wc_get_order( $order_id );
						if ( ! $order ) {
							wc_add_notice( $orderNotFound, 'error' );
							wp_redirect( $woocommerce->cart->get_checkout_url() );
							exit;
						}
						update_post_meta( $order_id, '_transaction_id', $refId );
						if ( isset( $_GET['trxId'] ) && isset( $_GET['orderId'] ) ) {
							if ( $status == 10 || $status == 12 ) {
								$refIdWord = 'RiverPay ref id';
								$Note      = sprintf( __( $this->getMessageWithStatus( $status ) . '<br/> ' . $refIdWord . ' : %s', 'woocommerce' ), $refId );
								$Note      = apply_filters( 'WC_RiverPay_Return_from_Gateway_Success_Note', $Note, $order_id, $refId );
								$order->add_order_note( 'RiverPay: ' . $Note, 1 );
								wc_add_notice( $Note, 'success' );
								$order->update_status( 'on-hold', $Note );
								wp_redirect( add_query_arg( 'wc_status', 'success', $this->get_return_url( $order ) ) );
								wp_redirect( $woocommerce->cart->get_checkout_url() );
								exit;
							} else if ( $status == 20 || $status == 21 || $status == 22 ) {
								$refIdWord = 'Payment Successful! RiverPay: ref id';
								$Note      = sprintf( __( $this->getMessageWithStatus( $status ) . '<br/> ' . $refIdWord . ' : %s', 'woocommerce' ), $refId );
								$Note      = apply_filters( 'WC_RiverPay_Call_WebHook_Success_Note', $Note, $order_id, $refId );
								$order->add_order_note( 'RiverPay: ' . $Note, 1 );
								$order->payment_complete();
								wc_add_notice( $Note, 'success' );
								do_action( 'WC_RiverPay_Payment_Success', $order_id );
								wp_redirect( add_query_arg( 'wc_status', 'success', $this->get_return_url( $order ) ) );
								exit;
							} else {
								$refIdWord = 'RiverPay ref id';
								$Note      = sprintf( __( $this->getMessageWithStatus( $status ) . '<br/> ' . $refIdWord . ' : %s', 'woocommerce' ), $refId );
								$Note      = apply_filters( 'WC_RiverPay_Return_from_Gateway_Failed_Note', $Note, $order_id, $refId );
								$order->add_order_note( 'RiverPay: ' . $Note, 1 );
								wc_add_notice( $Note, 'error' );
								$order->update_status( 'failed', $Note );
								wp_redirect( $woocommerce->cart->get_checkout_url() );
								exit;
							}
						} else {
							wc_add_notice( $orderNotFound, 'error' );
							wp_redirect( $woocommerce->cart->get_checkout_url() );
							exit;
						}
					}
				}

				public function process_webhook() {
					$status   = isset( $_POST['status'] ) ? $_POST['status'] : null;
					$order_id = isset( $_POST['orderId'] ) ? $_POST['orderId'] : null;
					$refId    = isset( $_POST['refId'] ) ? $_POST['refId'] : null;

					global $woocommerce;
					$orderNotFound = 'order not exists!';

					if ( ! is_null( $order_id ) ) {

						$order = wc_get_order( $order_id );
						if ( ! $order ) {
							header( 'Content-Type: application/json' );
							die( json_encode( [
								'refId'   => $refId,
								'orderId' => '',
								'status'  => $status
							] ) );
						}
						update_post_meta( $order_id, '_transaction_id', $refId );
						$refIdWord = 'ref id';
						if ( $status == 10 || $status == 12 ) {
							$Note = sprintf( __( $this->getMessageWithStatus( $status ) . '<br/> ' . $refIdWord . ' : %s', 'woocommerce' ), $refId );
							$Note = apply_filters( 'WC_RiverPay_Return_from_Gateway_Success_Note', $Note, $order_id, $refId );
							$order->add_order_note( 'RiverPay: ' . $Note, 1 );
							wc_add_notice( $Note, 'success' );
							$order->update_status( 'on-hold', $Note );
						} else if ( $status == 20 || $status == 21 || $status == 22 ) {
							$Note = $this->getMessageWithStatus( $status );
							$Note = apply_filters( 'WC_RiverPay_Call_WebHook_Success_Note', $Note, $order_id, $refId );
							$order->add_order_note( 'RiverPay: ' . $Note, 1 );
							do_action( 'WC_RiverPay_Payment_Success', $order_id );
							$order->payment_complete();
						} else {
							$Note = sprintf( __( $this->getMessageWithStatus( $status ) . '<br/> ' . $refIdWord . ' : %s', 'woocommerce' ), $refId );
							$Note = apply_filters( 'WC_RiverPay_Return_from_Gateway_Failed_Note', $Note, $order_id, $refId );
							$order->add_order_note( 'RiverPay: ' . $Note, 1 );
							do_action( 'WC_RiverPay_Payment_Failed', $order_id, $status );
							$order->update_status( 'failed', $Note );
						}
					} else {
						header( 'Content-Type: application/json' );
						die( json_encode( [
							'refId'   => $refId,
							'orderId' => '',
							'status'  => $status
						] ) );
					}
					header( 'Content-Type: application/json' );
					die( json_encode( [
						'refId'   => $refId,
						'orderId' => $order_id,
						'status'  => $status
					] ) );
				}


			}
		}
	} else {


		function independence_notice() {
			global $pagenow;
			$admin_pages = [ 'index.php', 'edit.php', 'plugins.php', 'admin.php' ];
			if ( in_array( $pagenow, $admin_pages ) ) {
				?>
                <div class="notice notice-error">
                    <h2 style="direction: ltr;text-align: left">RiverPay Just Works With USD Right Now. Please Change
                        Your Currency To USD Or Contact Us.</h2>
                </div>
				<?php
			}
		}
		add_action( 'admin_notices', 'independence_notice' );
	}
}


add_action( 'plugins_loaded', 'Riverpay_Gateway', 0 );
