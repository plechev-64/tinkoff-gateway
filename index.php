<?php

add_action( 'rcl_payments_gateway_init', 'rcl_gateway_tinkoff_init', 10 );
function rcl_gateway_tinkoff_init() {
	rcl_gateway_register( 'tinkoff', 'Rcl_Tinkoff_Payment' );
}

class Rcl_Tinkoff_Payment extends Rcl_Gateway_Core {
	function __construct() {
		parent::__construct( array(
			'request'	 => 'tinkoffPayment',
			'name'		 => rcl_get_commerce_option( 'tinkoff_custom_name', 'Tinkoff' ),
			'submit'	 => __( 'Оплатить через Tinkoff' ),
			'icon'		 => rcl_addon_url( 'icon.jpg', __FILE__ )
		) );
	}

	function get_options() {

		return array(
			array(
				'type'			 => 'text',
				'slug'			 => 'tinkoff_custom_name',
				'title'			 => __( 'Наименование платежной системы' ),
				'placeholder'	 => 'Tinkoff'
			),
			array(
				'type'	 => 'text',
				'slug'	 => 'tinkoff_terminal',
				'title'	 => __( 'Терминал' ),
				'notice' => __( 'Указан в личном кабинете https://oplata.tinkoff.ru' )
			),
			array(
				'type'	 => 'password',
				'slug'	 => 'tinkoff_password',
				'title'	 => __( 'Пароль' ),
				'notice' => __( 'Указан в личном кабинете https://oplata.tinkoff.ru' )
			),
			array(
				'type'		 => 'select',
				'slug'		 => 'tinkoff_fn',
				'title'		 => __( 'Фискализация платежа' ),
				'values'	 => array(
					__( 'Отключено' ),
					__( 'Включено' )
				),
				'childrens'	 => array(
					1 => array(
						array(
							'type'	 => 'select',
							'slug'	 => 'tinkoff_tax',
							'title'	 => __( 'Система налогообложения' ),
							'values' => array(
								'osn'				 => __( 'ОСН' ),
								'usn_income'		 => __( 'УСН доходы' ),
								'usn_income_outcome' => __( 'УСН - расходы' ),
								'envd'				 => __( 'ЕНДВ' ),
								'esn'				 => __( 'ЕСН' ),
								'patent'			 => __( 'ПАТЕНТ' )
							)
						),
						array(
							'type'	 => 'select',
							'slug'	 => 'tinkoff_nds',
							'title'	 => __( 'Ставка НДС' ),
							'values' => array(
								'none'	 => __( 'без НДС' ),
								'vat0'	 => __( 'НДС по ставке 0%' ),
								'vat10'	 => __( 'НДС по ставке 10%' ),
								'vat20'	 => __( 'НДС по ставке 20%' ),
								'vat110' => __( 'НДС по ставке 10/110' ),
								'vat118' => __( 'НДС по ставке 18/118' ),
								'vat120' => __( 'НДС по ставке 20/120' )
							)
						)
					)
				)
			)
		);
	}

	function get_form( $data ) {

		$terminal	 = rcl_get_commerce_option( 'tinkoff_terminal' );
		$password	 = rcl_get_commerce_option( 'tinkoff_password' );

		$fields = array(
			'TerminalKey'	 => $terminal,
			'Amount'		 => round( $data->pay_summ * 100 ),
			'OrderId'		 => $data->pay_id,
			'Description'	 => $data->description,
			'CustomerKey'	 => $data->user_id
		);

		$fields['Token'] = $this->genToken( $fields );

		if ( rcl_get_commerce_option( 'tinkoff_fn' ) ) {

			if ( $data->pay_type == 1 ) {

				$cashItems = array(
					array(
						"Name"		 => __( 'Пополнение личного счета' ),
						"Quantity"	 => 1,
						"Price"		 => round( $data->pay_summ * 100 ),
						'Tax'		 => rcl_get_commerce_option( 'tinkoff_nds' ),
						'Amount'	 => round( $data->pay_summ * 100 )
					)
				);
			} else if ( $data->pay_type == 2 ) {

				$order = rcl_get_order( $data->pay_id );

				if ( $order ) {

					$cashItems = array();

					foreach ( $order->products as $product ) {

						if ( ! $product->product_price )
							continue;

						$total = $product->product_price * $product->product_amount;

						$cashItems[] = array(
							"Name"		 => get_the_title( $product->product_id ),
							"Quantity"	 => $product->product_amount,
							"Price"		 => round( $product->product_price * 100 ),
							'Tax'		 => rcl_get_commerce_option( 'tinkoff_nds' ),
							'Amount'	 => $total * 100
						);
					}
				}
			} else {

				$cashItems = array(
					array(
						"Name"		 => $data->description,
						"Quantity"	 => 1,
						"Price"		 => round( $data->pay_summ * 100 ),
						'Tax'		 => rcl_get_commerce_option( 'tinkoff_nds' ),
						'Amount'	 => round( $data->pay_summ * 100 )
					)
				);
			}

			$fields['Receipt'] = array(
				'Email'		 => get_the_author_meta( 'email', $data->user_id ),
				'Taxation'	 => rcl_get_commerce_option( 'tinkoff_tax' ),
				'Items'		 => $cashItems
			);
		}

		$fields['DATA'] = array(
			'baggage_data'	 => $data->baggage_data,
			'pay_type'		 => $data->pay_type
		);

		$result = $this->_sendRequest( 'https://securepay.tinkoff.ru/v2/Init', $fields );

		$fields['DATA'] = json_encode( $fields['DATA'] );

		if ( isset( $fields['Receipt'] ) )
			$fields['Receipt'] = json_encode( $fields['Receipt'] );

		return parent::construct_form( array(
				'action' => $result->PaymentURL,
				'fields' => $fields
			) );
	}

	function result( $data ) {

		$POST = json_decode( file_get_contents( 'php://input' ), TRUE );

		if ( $POST['Status'] != 'CONFIRMED' ) {
			echo "OK";
			exit;
		}

		if ( $POST['Success'] )
			$POST['Success'] = "true";

		$Data	 = $POST['Data'];
		$Token	 = $POST['Token'];

		$Original = $POST;

		unset( $POST['Token'] );
		unset( $POST['Data'] );

		$myToken = $this->genToken( $POST );

		if ( $Token != $myToken ) {
			rcl_mail_payment_error( $myToken, $Original );
			exit;
		}

		if ( ! parent::get_payment( $POST["OrderId"] ) ) {
			parent::insert_payment( array(
				'pay_id'		 => $POST["OrderId"],
				'pay_summ'		 => $POST['Amount'] / 100,
				'user_id'		 => $Data["CUSTOMER_KEY"],
				'pay_type'		 => $Data["pay_type"],
				'baggage_data'	 => $Data["baggage_data"]
			) );
		}

		echo "OK";
		exit;
	}

	private function genToken( $args ) {

		$token				 = '';
		$args['Password']	 = rcl_get_commerce_option( 'tinkoff_password' );
		ksort( $args );

		foreach ( $args as $arg ) {
			if ( ! is_array( $arg ) ) {
				$token .= $arg;
			}
		}

		$token = hash( 'sha256', $token );

		return $token;
	}

	private function _sendRequest( $api_url, $args ) {
		$this->_error = '';
		//todo add string $args support
		//$proxy = 'http://192.168.5.22:8080';
		//$proxyAuth = '';
		if ( is_array( $args ) ) {
			$args = json_encode( $args );
		}

		if ( $curl = curl_init() ) {

			curl_setopt( $curl, CURLOPT_URL, $api_url );
			curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $args );
			curl_setopt( $curl, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
			) );
			$out = curl_exec( $curl );

			$json = json_decode( $out );

			curl_close( $curl );

			return $json;
		} else {
			throw new HttpException(
			'Can not create connection to ' . $api_url . ' with args '
			. $args, 404
			);
		}
	}

}
