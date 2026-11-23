<?php
/**
 * Plugin Name: WooCommerce Telegram Order Notifications
 * Plugin URI: https://github.com/Al3xanderMakunin/woocommerce-telegram-order-notifications
 * Description: Sends order notifications to Telegram independently of WordPress email.
 * Version: 1.2.0
 * Author: Александр Макунин
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

namespace WC_Telegram_Order_Notifications;

defined( 'ABSPATH' ) || exit;

// Keep the hook name so previously queued notifications can still run.
const SEND_HOOK = 'wcton_send_paid_order';
const GROUP = 'wc-telegram-orders';
const MESSAGE_ID_META = '_wcton_message_id';
const IN_FLIGHT_META = '_wcton_delivery_unconfirmed';
const CHAT_DELIVERY_META = '_wcton_chat_deliveries';
const MAX_RATE_LIMIT_RETRIES = 3;
const SETTINGS_OPTION = 'wcton_settings';

if ( is_admin() ) {
	require_once __DIR__ . '/settings.php';
}

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'woocommerce_new_order', __NAMESPACE__ . '\enqueue_automatically' );
add_action( 'woocommerce_order_status_changed', __NAMESPACE__ . '\enqueue_automatically' );
add_action( SEND_HOOK, __NAMESPACE__ . '\send_order', 10, 5 );

function external_config_value( string $key ): ?string {
	$value = defined( $key ) ? constant( $key ) : getenv( $key );
	return ! defined( $key ) && $value === false ? null : ( is_scalar( $value ) ? trim( (string) $value ) : '' );
}

function config_value( string $key, array $settings ): string {
	return external_config_value( $key ) ?? ( $settings[ $key ] ?? '' );
}

function valid_bot_token( string $token ): bool {
	return (bool) preg_match( '/^\d+:[A-Za-z0-9_-]+$/D', $token );
}

function valid_chat_id( string $chat_id ): bool {
	return (bool) preg_match( '/^(?:-?[1-9]\d*|@[A-Za-z0-9_]+)$/D', $chat_id );
}

function chat_ids( string $value ): array {
	$ids = preg_split( '/[,\s]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
	foreach ( $ids as &$id ) {
		if ( ! valid_chat_id( $id ) ) {
			return array();
		}
		$id = strtolower( $id );
	}
	return array_values( array_unique( $ids ) );
}

function valid_proxy_url( string $proxy ): bool {
	if ( $proxy === '' ) {
		return true;
	}
	$parts = wp_parse_url( $proxy );
	return is_array( $parts ) && ( $parts['scheme'] ?? '' ) === 'https' && ! empty( $parts['host'] ) &&
		! isset( $parts['user'] ) && ! isset( $parts['pass'] ) &&
		! isset( $parts['query'] ) && ! isset( $parts['fragment'] );
}

function configuration(): array {
	$settings = (array) get_option( SETTINGS_OPTION, array() );
	$token = config_value( 'WCTON_BOT_TOKEN', $settings );
	$chat_ids = chat_ids( config_value( 'WCTON_CHAT_ID', $settings ) );
	$proxy = config_value( 'WCTON_PROXY_URL', $settings );

	if ( ! valid_bot_token( $token ) || ! $chat_ids ) {
		throw new \RuntimeException( 'Configure the Telegram bot token and chat IDs under Settings → WooCommerce Telegram or via WCTON_BOT_TOKEN and WCTON_CHAT_ID.' );
	}
	if ( ! valid_proxy_url( $proxy ) ) {
		throw new \RuntimeException( 'The Bot API proxy must be an HTTPS base URL.' );
	}
	$base = $proxy === '' ? 'https://api.telegram.org' : rtrim( $proxy, '/' );
	return array( $base . '/bot' . $token . '/', $chat_ids );
}

function telegram_request( string $base, string $method, array|string $body, string $content_type = 'application/json' ): array {
	$response = wp_safe_remote_post( $base . $method, array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array( 'Content-Type' => $content_type ),
		'body'        => is_array( $body ) ? wp_json_encode( $body ) : $body,
	) );

	if ( is_wp_error( $response ) ) {
		throw new \RuntimeException( 'Bot API transport failed. Delivery is unconfirmed.' );
	}
	$status = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $status === 200 && is_array( $data ) && ( $data['ok'] ?? null ) === true ) {
		return $data;
	}
	if ( is_array( $data ) && ( $data['ok'] ?? null ) === false &&
		$status >= 400 && $status < 500 && ( $data['error_code'] ?? null ) === $status ) {
		return $data;
	}
	throw new \RuntimeException( sprintf( 'Unexpected Bot API response (HTTP %d). Delivery is unconfirmed.', $status ) );
}

function get_order_for_notification( int $order_id ): ?\WC_Order {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
	// Checkout Blocks creates a draft before the customer submits the order.
	return $order instanceof \WC_Order && ! $order->has_status( 'checkout-draft' ) ? $order : null;
}

function log_order_error( int $order_id, \RuntimeException $error ): void {
	$settings = (array) get_option( SETTINGS_OPTION, array() );
	if ( ( $settings['error_logging'] ?? '0' ) !== '1' || ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	$message = $error->getMessage();
	$token = config_value( 'WCTON_BOT_TOKEN', $settings );
	if ( $token !== '' ) {
		$message = str_replace( $token, '[redacted]', $message );
	}
	wc_get_logger()->error( sprintf( 'Order %d: %s', $order_id, $message ), array( 'source' => GROUP ) );
}

function enqueue_automatically( int $order_id ): void {
	$order = get_order_for_notification( $order_id );
	if ( ! did_action( 'action_scheduler_init' ) || ! $order ||
		$order->get_meta( MESSAGE_ID_META ) || $order->get_meta( IN_FLIGHT_META ) ||
		$order->get_meta( CHAT_DELIVERY_META ) ) {
		return;
	}
	try {
		configuration();
	} catch ( \RuntimeException $error ) {
		log_order_error( $order_id, $error );
		return;
	}
	enqueue( $order_id );
}

function enqueue( int $order_id, bool $retry_unconfirmed = false ): int {
	$args = array( $order_id, 0, $retry_unconfirmed );
	if ( as_has_scheduled_action( SEND_HOOK, $args, GROUP ) ) {
		return 0;
	}
	// Older Action Scheduler versions treat different order IDs as identical with the unique flag.
	return as_enqueue_async_action( SEND_HOOK, $args, GROUP );
}

function plain_text( string $html ): string {
	return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
}

function billing_address_lines( \WC_Order $order ): array {
	if ( $order->get_billing_country() === 'RU' ) {
		$countries = WC()->countries;
		$states = $countries->get_states( 'RU' );
		$state = $order->get_billing_state();
		$location = array(
			$order->get_billing_postcode(),
			$order->get_billing_city(),
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
		);
		$address = array(
			$order->get_formatted_billing_full_name(),
			$order->get_billing_company(),
			$countries->get_countries()['RU'] ?? 'Россия',
			$states[ $state ] ?? $state,
			implode( ', ', array_filter( $location, 'strlen' ) ),
		);
	} else {
		$address = preg_split( '~<br\s*/?>~i', $order->get_formatted_billing_address() );
	}

	$address[] = $order->get_billing_phone();
	$address[] = $order->get_billing_email();
	return array_filter( array_map( __NAMESPACE__ . '\plain_text', $address ), 'strlen' );
}

function message_for( \WC_Order $order ): string {
	$use_tax = $order->get_prices_include_tax();
	$currency = $order->get_currency();
	$price = static function ( float $amount ) use ( $currency ): string {
		if ( $currency === 'RUB' ) {
			$decimals = round( $amount, 2 ) === round( $amount ) ? 0 : 2;
			return number_format( $amount, $decimals, ',', ' ' ) . ' ₽';
		}

		return plain_text( wc_price( $amount, array( 'currency' => $currency ) ) );
	};

	$site = plain_text( get_bloginfo( 'name' ) );
	$source = wp_parse_url( home_url(), PHP_URL_HOST );
	$app_url = 'https://woocommerce.com/mobile?blog_id=0&utm_campaign=deeplinks_promote_app&utm_medium=email&utm_source=' . rawurlencode( (string) $source ) . '&utm_term=0';
	$number = '№ ' . $order->get_order_number();
	$date = $order->get_date_created();
	$customer = plain_text( $order->get_formatted_billing_full_name() );
	$rule = str_repeat( '-', 30 );
	$section = str_repeat( '-', 13 );

	$lines = array(
		"🔔‌[$site]: Новый заказ {$number}🔔",
		'',
		"Новый заказ: $number",
		$rule,
		'Получен заказ от покупателя' . ( $customer !== '' ? ' ' . $customer : '' ) . ':',
		'',
		"[Заказ $number] (" . $order->get_edit_order_url() . ')' . ( $date ? ' (' . wc_format_datetime( $date, 'd.m.Y' ) . ')' : '' ),
		'',
		'Товар | Количество | Цена',
		$rule,
	);

	$subtotal = 0.0;
	foreach ( $order->get_items() as $item ) {
		$line_subtotal = $order->get_line_subtotal( $item, $use_tax, false );
		$subtotal += $line_subtotal;
		$product_lines = array( plain_text( $item->get_name() ) );
		foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
			$product_lines[] = '- ' . plain_text( $meta->display_key ) . ':';
			$product_lines[] = plain_text( $meta->display_value );
		}
		$product_lines[ count( $product_lines ) - 1 ] .= ' | ' . $item->get_quantity() . ' | ' . $price( $line_subtotal );
		$lines = array_merge( $lines, $product_lines );
	}

	$shipping = $price( (float) $order->get_shipping_total() + ( $use_tax ? (float) $order->get_shipping_tax() : 0 ) );
	$shipping_method = plain_text( $order->get_shipping_method() );
	$payment = plain_text( $order->get_payment_method_title() ?: $order->get_payment_method() );

	return implode( "\n", array_merge(
		$lines,
		array(
			'',
			$section,
			'',
			'Подытог: | ' . $price( $subtotal ),
			$rule,
			'Скидка: | ' . $price( -$order->get_total_discount( ! $use_tax ) ),
			$rule,
			'Доставка: | ' . $shipping . ( $shipping_method !== '' ? ' – ' . $shipping_method : '' ),
			$rule,
			'Способ оплаты: | ' . ( $payment ?: '—' ),
			$rule,
			'Итого: | ' . $price( $order->get_total() ),
			'',
			$section,
			'',
			'Платёжный адрес',
			'',
		),
		billing_address_lines( $order ),
		array(
			'',
			'Поздравляем вас с продажей.',
			'',
			"Работать с заказами можно в приложении ($app_url).",
			$rule,
			"$site — Built with WooCommerce (https://woocommerce.com/) |",
		)
	) );
}

function telegram_order_request( string $base, string $chat_id, string $message, int $order_id ): array {
	// Counting UTF-16 units also keeps messages with supplementary characters within the limit.
	$length = preg_match_all( '/./us', $message );
	$supplementary = preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $message );
	if ( $length !== false && $length + $supplementary <= 4096 ) {
		return telegram_request( $base, 'sendMessage', array(
			'chat_id' => $chat_id,
			'text'    => $message,
		) );
	}

	$boundary = 'wcton-' . wp_generate_uuid4();
	$body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"chat_id\"\r\n\r\n{$chat_id}\r\n";
	$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"caption\"\r\n\r\nЗаказ №{$order_id} (ID WooCommerce)\r\n";
	$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"document\"; filename=\"order-{$order_id}.txt\"\r\n";
	$body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$message}\r\n--{$boundary}--\r\n";
	return telegram_request( $base, 'sendDocument', $body, 'multipart/form-data; boundary=' . $boundary );
}

function send_order( int $order_id, int $attempt = 0, bool $retry_unconfirmed = false, string $retry_chat_id = '', bool $resend = false ): string {
	try {
		return deliver_order( $order_id, $attempt, $retry_unconfirmed, $retry_chat_id, $resend );
	} catch ( \RuntimeException $error ) {
		log_order_error( $order_id, $error );
		throw $error;
	}
}

function deliver_order( int $order_id, int $attempt, bool $retry_unconfirmed, string $retry_chat_id, bool $resend ): string {
	global $wpdb;

	$lock = 'wcton:' . substr( hash( 'sha256', $wpdb->prefix . ':' . DB_NAME . ':' . $order_id ), 0, 48 );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
		throw new \RuntimeException( 'Could not acquire the order delivery lock.' );
	}

	try {
		$order = get_order_for_notification( $order_id );
		if ( ! $order ) {
			return 'unavailable_order';
		}
		// Another worker may have saved a receipt before we acquired the lock.
		$order->read_meta_data( true );
		if ( ! $resend && $order->get_meta( MESSAGE_ID_META ) ) {
			return 'already_sent';
		}
		if ( $order->get_meta( IN_FLIGHT_META ) && ! $retry_unconfirmed ) {
			throw new \RuntimeException( 'A previous delivery is unconfirmed. Check the Telegram chat before retrying.' );
		}

		list( $base, $chat_ids ) = configuration();
		if ( $retry_chat_id !== '' ) {
			$chat_ids = array_intersect( $chat_ids, array( $retry_chat_id ) );
		}
		$deliveries = (array) $order->get_meta( CHAT_DELIVERY_META, true );
		if ( $order->get_meta( IN_FLIGHT_META ) ) {
			foreach ( $chat_ids as $chat_id ) {
				$deliveries[ $chat_id ] ??= array( 'unconfirmed' => true );
			}
			$order->update_meta_data( CHAT_DELIVERY_META, $deliveries );
			$order->save_meta_data();
			$order->delete_meta_data( IN_FLIGHT_META );
			$order->save_meta_data();
		}

		$message = null;
		$result = 'already_sent';
		$errors = array();
		foreach ( $chat_ids as $chat_id ) {
			if ( ! $resend && isset( $deliveries[ $chat_id ]['message_id'] ) ) {
				continue;
			}
			try {
				if ( ! empty( $deliveries[ $chat_id ]['unconfirmed'] ) && ! $retry_unconfirmed ) {
					throw new \RuntimeException( 'A previous delivery is unconfirmed. Check the Telegram chat before retrying.' );
				}
				$status = send_to_chat( $order, $base, $chat_id, $message ??= message_for( $order ), $attempt, $deliveries, $resend );
				if ( $result !== 'scheduled' ) {
					$result = $status;
				}
			} catch ( \RuntimeException $error ) {
				$errors[] = sprintf( '%s — %s', $chat_id, $error->getMessage() );
			}
		}
		if ( $errors ) {
			throw new \RuntimeException( implode( ' ', $errors ) );
		}
		return $result;
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function send_to_chat( \WC_Order $order, string $base, string $chat_id, string $message, int $attempt, array &$deliveries, bool $resend = false ): string {
	$deliveries[ $chat_id ]['unconfirmed'] = true;
	$order->update_meta_data( CHAT_DELIVERY_META, $deliveries );
	$order->save_meta_data();

	$data = telegram_order_request( $base, $chat_id, $message, $order->get_id() );
	$message_id = $data['result']['message_id'] ?? 0;
	if ( ( $data['ok'] ?? null ) === true && is_int( $message_id ) && $message_id > 0 ) {
		$deliveries[ $chat_id ] = array( 'message_id' => $message_id );
		$order->update_meta_data( CHAT_DELIVERY_META, $deliveries );
		$order->save_meta_data();
		return 'sent';
	}

	$error_code = $data['error_code'] ?? null;
	if ( ( $data['ok'] ?? null ) === false && is_int( $error_code ) ) {
		unset( $deliveries[ $chat_id ]['unconfirmed'] );
		$order->update_meta_data( CHAT_DELIVERY_META, $deliveries );
		$order->save_meta_data();
		if ( $error_code === 429 && $attempt < MAX_RATE_LIMIT_RETRIES ) {
			$delay = max( 60, (int) ( $data['parameters']['retry_after'] ?? 60 ) );
			$args = array( $order->get_id(), $attempt + 1, false, $chat_id );
			if ( $resend ) {
				$args[] = true;
			}
			if ( as_schedule_single_action( time() + $delay, SEND_HOOK, $args, GROUP ) ) {
				return 'scheduled';
			}
		}
		throw new \RuntimeException( sprintf( 'Telegram rejected the notification (API code %d).', $error_code ) );
	}
	throw new \RuntimeException( 'Bot API did not return a message ID. Delivery is unconfirmed.' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'wc-telegram check', static function ( $args, $assoc_args ) {
		try {
			list( $base, $chat_ids ) = configuration();
			if ( ! isset( $assoc_args['send-test'] ) ) {
				$data = telegram_request( $base, 'getMe', array() );
				if ( empty( $data['ok'] ) ) {
					throw new \RuntimeException( sprintf( 'Telegram rejected the request (API code %d).', $data['error_code'] ?? 0 ) );
				}
				\WP_CLI::success( 'Bot API connection verified.' );
				return;
			}
		} catch ( \RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}

		$failed = false;
		foreach ( $chat_ids as $chat_id ) {
			try {
				$data = telegram_request( $base, 'sendMessage', array(
					'chat_id' => $chat_id,
					'text'    => 'Тест уведомлений WooCommerce',
				) );
				if ( empty( $data['ok'] ) ) {
					throw new \RuntimeException( sprintf( 'Telegram rejected the request (API code %d).', $data['error_code'] ?? 0 ) );
				}
				\WP_CLI::success( sprintf( 'Telegram accepted the test message for %s.', $chat_id ) );
			} catch ( \RuntimeException $error ) {
				\WP_CLI::warning( sprintf( '%s — %s', $chat_id, $error->getMessage() ) );
				$failed = true;
			}
		}
		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}, array( 'synopsis' => '[--send-test]' ) );

	\WP_CLI::add_command( 'wc-telegram enqueue', static function ( $args, $assoc_args ) {
		if ( ! did_action( 'action_scheduler_init' ) ) {
			\WP_CLI::error( 'WooCommerce Action Scheduler is unavailable.' );
		}
		try {
			configuration();
		} catch ( \RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		$failed = false;
		foreach ( $args as $id ) {
			$order = ctype_digit( $id ) ? get_order_for_notification( (int) $id ) : null;
			if ( ! $order ) {
				\WP_CLI::warning( 'Skipped an invalid, missing or draft order.' );
				$failed = true;
				continue;
			}
			if ( $order->get_meta( MESSAGE_ID_META ) ) {
				\WP_CLI::log( sprintf( 'Order %d already has a Telegram receipt.', $order->get_id() ) );
				continue;
			}
			$retry = isset( $assoc_args['retry-unconfirmed'] );
			if ( $order->get_meta( IN_FLIGHT_META ) && ! $retry ) {
				\WP_CLI::warning( sprintf( 'Order %d has an unconfirmed delivery. Check the chat before retrying.', $order->get_id() ) );
				$failed = true;
				continue;
			}
			$action = enqueue( $order->get_id(), $retry );
			if ( ! $action ) {
				\WP_CLI::warning( sprintf( 'Order %d could not be queued or already has a queued action.', $order->get_id() ) );
				$failed = true;
				continue;
			}
			\WP_CLI::log( sprintf( 'Order %d queued as action %d.', $order->get_id(), $action ) );
		}
		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}, array( 'synopsis' => '<order-id>... [--retry-unconfirmed]' ) );
}
