<?php

namespace WC_Telegram_Order_Notifications;

defined( 'ABSPATH' ) || exit;

const SETTINGS_PAGE = 'woocommerce-telegram-order-notifications';
const MANUAL_SEND_ACTION = 'wcton_manual_send';

add_action( 'admin_post_' . MANUAL_SEND_ACTION, __NAMESPACE__ . '\handle_manual_send' );

add_action( 'admin_menu', static function () {
	add_options_page( 'WooCommerce Telegram', 'WooCommerce Telegram', 'manage_options', SETTINGS_PAGE, __NAMESPACE__ . '\render_settings_page' );
} );

add_action( 'admin_init', static function () {
	register_setting( SETTINGS_PAGE, SETTINGS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => __NAMESPACE__ . '\sanitize_settings',
	) );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __DIR__ . '/woocommerce-telegram-order-notifications.php' ), static function ( array $links ): array {
	if ( current_user_can( 'manage_options' ) ) {
		$url = admin_url( 'options-general.php?page=' . SETTINGS_PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки</a>' );
	}
	return $links;
} );

function settings_fields_definition(): array {
	return array(
		'WCTON_BOT_TOKEN' => array( 'Токен бота', 'password', 'Введите токен из BotFather. Если токен уже сохранён, оставьте поле пустым, чтобы сохранить его.', 'valid_bot_token', 'Проверьте формат токена бота из BotFather.' ),
		'WCTON_CHAT_ID'   => array( 'Чаты для уведомлений', 'textarea', 'Укажите числовые ID чатов или публичные имена в формате @username. Разделяйте их переносами строк, запятыми или пробелами.', 'chat_ids', 'Проверьте список чатов. Допустимы числовые ID и имена в формате @username.' ),
		'WCTON_PROXY_URL' => array( 'Адрес прокси', 'url', 'Укажите базовый HTTPS-адрес доверенного прокси. Для прямого подключения к Telegram оставьте поле пустым.', 'valid_proxy_url', 'Укажите базовый HTTPS-адрес прокси без логина, пароля, параметров и фрагмента.' ),
	);
}

function sanitize_settings( $input ): array {
	$input = (array) $input;
	$previous = (array) get_option( SETTINGS_OPTION, array() );
	$settings = $previous;
	$valid = true;
	foreach ( settings_fields_definition() as $key => [ , $type, , $validator, $error ] ) {
		if ( external_config_value( $key ) !== null ) {
			continue;
		}
		$value = $input[ $key ] ?? null;
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( $type === 'password' && $value === '' ) {
				$value = $previous[ $key ] ?? '';
			}
			if ( ( __NAMESPACE__ . '\\' . $validator )( $value ) ) {
				$settings[ $key ] = $value;
				continue;
			}
		}
		add_settings_error( SETTINGS_OPTION, $key, $error );
		$valid = false;
	}
	return $valid ? $settings : $previous;
}

function handle_manual_send(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'У вас нет доступа к отправке уведомлений.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( MANUAL_SEND_ACTION );

	$order_id = filter_var( $_POST['wcton_order_id'] ?? '', FILTER_VALIDATE_INT, array(
		'options' => array( 'min_range' => 1 ),
	) );
	$result = 'invalid_order';
	if ( $order_id !== false ) {
		try {
			$result = send_order( $order_id, 0, ( $_POST['wcton_retry_unconfirmed'] ?? '' ) === '1', '', true );
		} catch ( \RuntimeException $error ) {
			$result = 'failed';
			// The delivery exceptions exclude bot credentials and HTTP response bodies.
			$detail = $error->getMessage();
		}
	}
	set_transient( 'wcton_manual_send_' . get_current_user_id(), array( $result, $detail ?? '' ), MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'options-general.php?page=' . SETTINGS_PAGE ), 303 );
	exit;
}

function render_manual_send_notice(): void {
	$key = 'wcton_manual_send_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! is_array( $notice ) ) {
		return;
	}
	delete_transient( $key );
	list( $result, $detail ) = $notice;
	$messages = array(
		'invalid_order'     => array( 'error', 'Введите корректный ID заказа.' ),
		'unavailable_order' => array( 'error', 'Заказ с таким ID отсутствует или ещё оформляется.' ),
		'already_sent'      => array( 'info', 'Telegram уже принял уведомление об этом заказе.' ),
		'sent'              => array( 'success', 'Telegram принял уведомление.' ),
		'scheduled'         => array( 'warning', 'Telegram ограничил частоту запросов. Повтор поставлен в очередь.' ),
		'failed'            => array( 'error', 'Возникли ошибки при отправке в указанные чаты.' ),
	);
	list( $type, $message ) = $messages[ $result ];
	wp_admin_notice( esc_html( trim( $message . ' ' . $detail ) ), array( 'type' => $type ) );
}

function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = (array) get_option( SETTINGS_OPTION, array() );
	?>
	<div class="wrap">
		<h1>WooCommerce Telegram</h1>
		<?php settings_errors(); ?>
		<?php render_manual_send_notice(); ?>
		<form action="options.php" method="post">
			<?php settings_fields( SETTINGS_PAGE ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( settings_fields_definition() as $key => [ $label, $type, $description ] ) : ?>
					<?php
					$id = strtolower( $key );
					$external = external_config_value( $key );
					$value = $type === 'password' ? '' : ( $external ?? ( $settings[ $key ] ?? '' ) );
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<?php if ( $type === 'textarea' ) : ?>
								<textarea class="regular-text" rows="4" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( SETTINGS_OPTION . '[' . $key . ']' ); ?>"
									aria-describedby="<?php echo esc_attr( $id . '_description' ); ?>" <?php disabled( $external !== null ); ?>><?php echo esc_textarea( $value ); ?></textarea>
							<?php else : ?>
							<input class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( SETTINGS_OPTION . '[' . $key . ']' ); ?>"
								type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" aria-describedby="<?php echo esc_attr( $id . '_description' ); ?>"
								<?php disabled( $external !== null ); ?> <?php echo $type === 'password' ? 'autocomplete="new-password"' : ''; ?>>
							<?php endif; ?>
							<p class="description" id="<?php echo esc_attr( $id . '_description' ); ?>">
								<?php echo esc_html( $external !== null ? sprintf( 'Значение %s задано в wp-config.php или переменной среды.', $key ) : $description ); ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2>Ручная отправка</h2>
		<p>Введите внутренний ID заказа WooCommerce. Уведомление отправится во все настроенные чаты, даже если Telegram уже принял его ранее.</p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( MANUAL_SEND_ACTION ); ?>">
			<?php wp_nonce_field( MANUAL_SEND_ACTION ); ?>
			<p>
				<label for="wcton_order_id">ID заказа</label>
				<input id="wcton_order_id" name="wcton_order_id" type="number" min="1" step="1" required>
			</p>
			<p>
				<label>
					<input name="wcton_retry_unconfirmed" type="checkbox" value="1">
					Я проверил чаты. Повторить отправку туда, где результат предыдущей попытки неизвестен.
				</label>
			</p>
			<?php submit_button( 'Отправить уведомление', 'secondary' ); ?>
		</form>
	</div>
	<?php
}
