<?php

namespace WC_Telegram_Order_Notifications;

defined( 'ABSPATH' ) || exit;

const SETTINGS_PAGE = 'woocommerce-telegram-order-notifications';

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
		'WCTON_CHAT_ID'   => array( 'ID чата', 'text', 'Укажите числовой ID чата или публичное имя в формате @username.', 'valid_chat_id', 'Укажите корректный ID чата или имя в формате @username.' ),
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

function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = (array) get_option( SETTINGS_OPTION, array() );
	?>
	<div class="wrap">
		<h1>WooCommerce Telegram</h1>
		<?php settings_errors(); ?>
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
							<input class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( SETTINGS_OPTION . '[' . $key . ']' ); ?>"
								type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" aria-describedby="<?php echo esc_attr( $id . '_description' ); ?>"
								<?php disabled( $external !== null ); ?> <?php echo $type === 'password' ? 'autocomplete="new-password"' : ''; ?>>
							<p class="description" id="<?php echo esc_attr( $id . '_description' ); ?>">
								<?php echo esc_html( $external !== null ? sprintf( 'Значение %s задано в wp-config.php или переменной среды.', $key ) : $description ); ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
