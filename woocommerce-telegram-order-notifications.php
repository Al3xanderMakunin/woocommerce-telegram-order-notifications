<?php
/**
 * Plugin Name: WooCommerce Telegram Order Notifications
 * Plugin URI: https://github.com/Al3xanderMakunin/woocommerce-telegram-order-notifications
 * Description: Sends paid-order notifications to Telegram independently of WordPress email.
 * Version: 1.0.0
 * Author: Александр Макунин
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

namespace WC_Telegram_Order_Notifications;

defined( 'ABSPATH' ) || exit;

const SEND_HOOK = 'wcton_send_paid_order';
const GROUP = 'wc-telegram-orders';
const MESSAGE_ID_META = '_wcton_message_id';
const IN_FLIGHT_META = '_wcton_delivery_unconfirmed';
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

add_action( 'woocommerce_payment_complete', __NAMESPACE__ . '\enqueue_automatically' );
add_action( 'woocommerce_order_status_processing', __NAMESPACE__ . '\enqueue_automatically' );
add_action( 'woocommerce_order_status_completed', __NAMESPACE__ . '\enqueue_automatically' );
add_action( SEND_HOOK, __NAMESPACE__ . '\send_order', 10, 3 );

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
	$chat_id = config_value( 'WCTON_CHAT_ID', $settings );
	$proxy = config_value( 'WCTON_PROXY_URL', $settings );

	if ( ! valid_bot_token( $token ) || ! valid_chat_id( $chat_id ) ) {
		throw new \RuntimeException( 'Configure the Telegram bot token and chat ID under Settings → WooCommerce Telegram or via WCTON_BOT_TOKEN and WCTON_CHAT_ID.' );
	}
	if ( ! valid_proxy_url( $proxy ) ) {
		throw new \RuntimeException( 'The Bot API proxy must be an HTTPS base URL.' );
	}
	$base = $proxy === '' ? 'https://api.telegram.org' : rtrim( $proxy, '/' );
	return array( $base . '/bot' . $token . '/', $chat_id );
}

function telegram_request( string $base, string $method, array $body ): array {
	$response = wp_safe_remote_post( $base . $method, array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => wp_json_encode( $body ),
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

function paid_order( int $order_id ): ?\WC_Order {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
	return $order instanceof \WC_Order && $order->get_date_paid() && $order->is_paid() ? $order : null;
}

function enqueue_automatically( int $order_id ): void {
	$order = paid_order( $order_id );
	if ( ! did_action( 'action_scheduler_init' ) || ! $order ||
		$order->get_meta( MESSAGE_ID_META ) || $order->get_meta( IN_FLIGHT_META ) ) {
		return;
	}
	try {
		configuration();
	} catch ( \RuntimeException $error ) {
		wc_get_logger()->warning( $error->getMessage(), array( 'source' => GROUP ) );
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

function message_for( \WC_Order $order ): string {
	$total = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
	return sprintf(
		"Оплачен заказ №%s\nСумма: %s\nСтатус: %s\n%s",
		$order->get_order_number(),
		$total,
		wc_get_order_status_name( $order->get_status() ),
		$order->get_edit_order_url()
	);
}

function send_order( int $order_id, int $attempt = 0, bool $retry_unconfirmed = false ): void {
	global $wpdb;

	$lock = 'wcton:' . substr( hash( 'sha256', $wpdb->prefix . ':' . DB_NAME . ':' . $order_id ), 0, 48 );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
		throw new \RuntimeException( 'Could not acquire the order delivery lock.' );
	}

	try {
		$order = paid_order( $order_id );
		if ( ! $order || $order->get_meta( MESSAGE_ID_META ) ) {
			return;
		}
		if ( $order->get_meta( IN_FLIGHT_META ) && ! $retry_unconfirmed ) {
			throw new \RuntimeException( 'A previous delivery is unconfirmed. Check the Telegram chat before retrying.' );
		}

		list( $base, $chat_id ) = configuration();
		$order->update_meta_data( IN_FLIGHT_META, time() );
		$order->save_meta_data();
		$data = telegram_request( $base, 'sendMessage', array(
			'chat_id' => $chat_id,
			'text'    => message_for( $order ),
		) );
		$message_id = $data['result']['message_id'] ?? 0;
		if ( ( $data['ok'] ?? null ) === true && is_int( $message_id ) && $message_id > 0 ) {
			// Persist the receipt before removing the marker in case execution is interrupted.
			$order->update_meta_data( MESSAGE_ID_META, $message_id );
			$order->save_meta_data();
			$order->delete_meta_data( IN_FLIGHT_META );
			$order->save_meta_data();
			return;
		}

		$error_code = $data['error_code'] ?? null;
		if ( ( $data['ok'] ?? null ) === false && is_int( $error_code ) ) {
			$order->delete_meta_data( IN_FLIGHT_META );
			$order->save_meta_data();
			if ( $error_code === 429 && $attempt < MAX_RATE_LIMIT_RETRIES ) {
				$delay = max( 60, (int) ( $data['parameters']['retry_after'] ?? 60 ) );
				$action = as_schedule_single_action( time() + $delay, SEND_HOOK, array( $order_id, $attempt + 1, false ), GROUP );
				if ( $action ) {
					return;
				}
			}
			throw new \RuntimeException( sprintf( 'Telegram rejected the notification (API code %d).', $error_code ) );
		}
		throw new \RuntimeException( 'Bot API did not return a message ID. Delivery is unconfirmed.' );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'wc-telegram check', static function ( $args, $assoc_args ) {
		try {
			list( $base, $chat_id ) = configuration();
			$data = isset( $assoc_args['send-test'] )
				? telegram_request( $base, 'sendMessage', array(
					'chat_id' => $chat_id,
					'text'    => 'Тест уведомлений WooCommerce',
				) )
				: telegram_request( $base, 'getMe', array() );
			if ( ! empty( $data['ok'] ) ) {
				\WP_CLI::success( isset( $assoc_args['send-test'] ) ? 'Telegram accepted the test message.' : 'Bot API connection verified.' );
				return;
			}
			\WP_CLI::error( sprintf( 'Telegram rejected the request (API code %d).', $data['error_code'] ?? 0 ) );
		} catch ( \RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
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
			$order = ctype_digit( $id ) ? paid_order( (int) $id ) : null;
			if ( ! $order ) {
				\WP_CLI::warning( 'Skipped an invalid, missing or unpaid order.' );
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
