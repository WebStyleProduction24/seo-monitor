<?php
/**
 * Plugin Name: SEO Monitor
 * Description: Регулярно проверяет технические SEO-настройки и отправляет отчёты в Telegram.
 * Version: 1.0.0
 * Author: ООО "АЙ ТИ ГАММА"
 * URL Author: https://wsp24.ru
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class P77_SEO_Monitor {
	private const VERSION             = '1.0.0';
	private const SETTINGS_OPTION     = 'p77_seo_monitor_settings';
	private const SUBSCRIBERS_OPTION  = 'p77_seo_monitor_subscribers';
	private const LAST_REPORT_OPTION  = 'p77_seo_monitor_last_report';
	private const ALERT_STATE_OPTION  = 'p77_seo_monitor_alert_state';
	private const HOURLY_HOOK         = 'p77_seo_monitor_hourly';
	private const WEEKLY_HOOK         = 'p77_seo_monitor_weekly';
	private const REST_NAMESPACE      = 'p77-seo/v1';
	private const SITE_URL            = 'https://pivzavod77.ru';
	private const TELEGRAM_API        = 'https://api.telegram.org/bot';

	public static function bootstrap(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ) );
		add_action( self::HOURLY_HOOK, array( __CLASS__, 'run_hourly_check' ) );
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'run_weekly_check' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_post_p77_seo_monitor_save', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_post_p77_seo_monitor_action', array( __CLASS__, 'handle_admin_action' ) );
		}
	}

	public static function activate(): void {
		$settings = self::get_settings();
		update_option( self::SETTINGS_OPTION, $settings, false );
		self::reschedule_events( $settings );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['p77_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Раз в неделю (Пивзавод77)',
		);
		return $schedules;
	}

	public static function ensure_schedules(): void {
		$settings = self::get_settings();
		if ( ! empty( $settings['hourly_enabled'] ) && ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
		if ( ! empty( $settings['weekly_enabled'] ) && ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_event( self::next_weekly_timestamp( $settings ), 'p77_weekly', self::WEEKLY_HOOK );
		}
	}

	private static function reschedule_events( array $settings ): void {
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );

		if ( ! empty( $settings['hourly_enabled'] ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
		if ( ! empty( $settings['weekly_enabled'] ) ) {
			wp_schedule_event( self::next_weekly_timestamp( $settings ), 'p77_weekly', self::WEEKLY_HOOK );
		}
	}

	private static function next_weekly_timestamp( array $settings ): int {
		$day  = max( 1, min( 7, (int) ( $settings['weekly_day'] ?? 1 ) ) );
		$time = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) ( $settings['weekly_time'] ?? '' ) )
			? $settings['weekly_time']
			: '10:00';
		$tz   = new DateTimeZone( 'Europe/Moscow' );
		$now  = new DateTimeImmutable( 'now', $tz );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
		$days_ahead = ( $day - (int) $now->format( 'N' ) + 7 ) % 7;
		$next = $now->modify( '+' . $days_ahead . ' days' )->setTime( $hour, $minute, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+7 days' );
		}
		return $next->getTimestamp();
	}

	private static function defaults(): array {
		return array(
			'encrypted_token' => '',
			'bot_username'    => 'pivzavod77_seo_bot',
			'webhook_path'    => wp_generate_password( 40, false, false ),
			'webhook_secret'  => wp_generate_password( 48, false, false ),
			'invite_code'     => strtoupper( wp_generate_password( 12, false, false ) ),
			'hourly_enabled'  => 1,
			'weekly_enabled'  => 1,
			'weekly_day'      => 1,
			'weekly_time'     => '10:00',
		);
	}

	private static function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	private static function encrypt_token( string $token ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return '';
		}
		return base64_encode( $iv . $tag . $ciphertext );
	}

	private static function decrypt_token( string $payload ): string {
		if ( '' === $payload || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return '';
		}
		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}

	private static function token(): string {
		$settings = self::get_settings();
		return self::decrypt_token( (string) $settings['encrypted_token'] );
	}

	public static function admin_menu(): void {
		add_options_page(
			'Пивзавод77 SEO Monitor',
			'SEO Monitor',
			'manage_options',
			'p77-seo-monitor',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings    = self::get_settings();
		$subscribers = self::get_subscribers();
		$last_report = get_option( self::LAST_REPORT_OPTION, array() );
		$days        = array( 1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье' );
		$notice      = isset( $_GET['p77_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['p77_notice'] ) ) : '';
		$invite_link = 'https://t.me/' . ltrim( (string) $settings['bot_username'], '@' ) . '?start=' . rawurlencode( (string) $settings['invite_code'] );
		?>
		<div class="wrap">
			<h1>Пивзавод77 — SEO Monitor</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-error"><p>WP-Cron отключён в wp-config.php. Проверки по расписанию не запустятся.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="p77_seo_monitor_save">
				<?php wp_nonce_field( 'p77_seo_monitor_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="p77_token">Токен Telegram-бота</label></th>
						<td>
							<input id="p77_token" name="token" type="password" class="regular-text" autocomplete="new-password" value="">
							<p class="description"><?php echo $settings['encrypted_token'] ? 'Токен сохранён в зашифрованном виде. Оставьте поле пустым, чтобы не менять его.' : 'Вставьте токен, полученный у @BotFather.'; ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">Аварийные проверки</th>
						<td><label><input type="checkbox" name="hourly_enabled" value="1" <?php checked( $settings['hourly_enabled'] ); ?>> каждый час</label></td>
					</tr>
					<tr>
						<th scope="row">Полный отчёт</th>
						<td>
							<label><input type="checkbox" name="weekly_enabled" value="1" <?php checked( $settings['weekly_enabled'] ); ?>> включён</label>
							<select name="weekly_day">
								<?php foreach ( $days as $number => $label ) : ?>
									<option value="<?php echo esc_attr( $number ); ?>" <?php selected( (int) $settings['weekly_day'], $number ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="time" name="weekly_time" value="<?php echo esc_attr( $settings['weekly_time'] ); ?>">
							<span class="description">по московскому времени</span>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Сохранить и подключить Telegram' ); ?>
			</form>

			<hr>
			<h2>Добавление получателей</h2>
			<p>Отправьте администратору или заказчику эту персональную ссылку. После нажатия «Запустить» пользователь будет добавлен в рассылку:</p>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $invite_link ); ?>" onclick="this.select();"></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="p77_seo_monitor_action">
				<input type="hidden" name="p77_action" value="rotate_invite">
				<?php wp_nonce_field( 'p77_seo_monitor_action' ); ?>
				<?php submit_button( 'Сменить ссылку-приглашение', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Получатели (<?php echo esc_html( count( $subscribers ) ); ?>)</h2>
			<?php if ( $subscribers ) : ?>
				<table class="widefat striped">
					<thead><tr><th>Имя</th><th>Username</th><th>Chat ID</th><th>Добавлен</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $subscribers as $chat_id => $subscriber ) : ?>
						<tr>
							<td><?php echo esc_html( $subscriber['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( isset( $subscriber['username'] ) && $subscriber['username'] ? '@' . $subscriber['username'] : '—' ); ?></td>
							<td><code><?php echo esc_html( $chat_id ); ?></code></td>
							<td><?php echo esc_html( $subscriber['added_at'] ?? '' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="p77_seo_monitor_action">
									<input type="hidden" name="p77_action" value="remove_subscriber">
									<input type="hidden" name="chat_id" value="<?php echo esc_attr( $chat_id ); ?>">
									<?php wp_nonce_field( 'p77_seo_monitor_action' ); ?>
									<button type="submit" class="button-link-delete">Удалить</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>Получателей пока нет.</p>
			<?php endif; ?>

			<p style="margin-top:20px">
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=p77_seo_monitor_action&p77_action=run_full' ), 'p77_seo_monitor_action' ) ); ?>">Проверить сайт сейчас</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=p77_seo_monitor_action&p77_action=test_message' ), 'p77_seo_monitor_action' ) ); ?>">Отправить тестовое сообщение</a>
			</p>

			<?php if ( is_array( $last_report ) && ! empty( $last_report['created_at'] ) ) : ?>
				<h2>Последняя проверка</h2>
				<pre style="background:#fff;padding:16px;border:1px solid #ccd0d4;white-space:pre-wrap"><?php echo esc_html( self::format_report( $last_report, false ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'p77_seo_monitor_save' );
		$settings = self::get_settings();
		$token    = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$notice   = 'Настройки сохранены.';

		$settings['hourly_enabled'] = isset( $_POST['hourly_enabled'] ) ? 1 : 0;
		$settings['weekly_enabled'] = isset( $_POST['weekly_enabled'] ) ? 1 : 0;
		$settings['weekly_day']     = isset( $_POST['weekly_day'] ) ? max( 1, min( 7, absint( $_POST['weekly_day'] ) ) ) : 1;
		$settings['weekly_time']    = isset( $_POST['weekly_time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $_POST['weekly_time'] )
			? sanitize_text_field( wp_unslash( $_POST['weekly_time'] ) )
			: '10:00';

		if ( $token ) {
			if ( ! preg_match( '/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
				self::redirect_admin( 'Токен имеет неверный формат.' );
			}
			$encrypted = self::encrypt_token( $token );
			if ( ! $encrypted ) {
				self::redirect_admin( 'Не удалось зашифровать токен: на сервере недоступен OpenSSL.' );
			}
			$me = self::telegram_request_with_token( $token, 'getMe' );
			if ( is_wp_error( $me ) || empty( $me['ok'] ) ) {
				self::redirect_admin( 'Telegram отклонил токен. Проверьте его и повторите попытку.' );
			}
			$settings['encrypted_token'] = $encrypted;
			$settings['bot_username']    = sanitize_user( $me['result']['username'] ?? 'pivzavod77_seo_bot', true );
			$notice = 'Токен проверен, Telegram подключён.';
		}

		update_option( self::SETTINGS_OPTION, $settings, false );
		self::reschedule_events( $settings );

		if ( self::token() ) {
			$webhook = self::set_webhook();
			if ( is_wp_error( $webhook ) ) {
				$notice .= ' Но webhook не подключён: ' . $webhook->get_error_message();
			} elseif ( empty( $webhook['ok'] ) ) {
				$notice .= ' Но Telegram не подтвердил webhook.';
			}
		}

		self::redirect_admin( $notice );
	}

	public static function handle_admin_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'p77_seo_monitor_action' );
		$action   = isset( $_REQUEST['p77_action'] ) ? sanitize_key( $_REQUEST['p77_action'] ) : '';
		$settings = self::get_settings();
		$notice   = 'Готово.';

		switch ( $action ) {
			case 'rotate_invite':
				$settings['invite_code'] = strtoupper( wp_generate_password( 12, false, false ) );
				update_option( self::SETTINGS_OPTION, $settings, false );
				$notice = 'Ссылка-приглашение обновлена. Старая ссылка больше не работает.';
				break;
			case 'remove_subscriber':
				$chat_id     = isset( $_POST['chat_id'] ) ? preg_replace( '/[^\d-]/', '', (string) $_POST['chat_id'] ) : '';
				$subscribers = self::get_subscribers();
				unset( $subscribers[ $chat_id ] );
				update_option( self::SUBSCRIBERS_OPTION, $subscribers, false );
				$notice = 'Получатель удалён.';
				break;
			case 'run_full':
				$report = self::run_full_check();
				$notice = 'Проверка завершена: ' . strtoupper( $report['status'] ) . '.';
				break;
			case 'test_message':
				if ( ! self::get_subscribers() ) {
					$notice = 'Сначала добавьте хотя бы одного получателя.';
				} else {
					self::broadcast( "✅ Тестовое сообщение\nSEO Monitor pivzavod77.ru подключён." );
					$notice = 'Тестовое сообщение отправлено.';
				}
				break;
		}
		self::redirect_admin( $notice );
	}

	private static function redirect_admin( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'p77-seo-monitor', 'p77_notice' => $notice ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	private static function set_webhook() {
		$settings = self::get_settings();
		$url      = rest_url( self::REST_NAMESPACE . '/telegram/' . rawurlencode( $settings['webhook_path'] ) );
		return self::telegram_request(
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => $settings['webhook_secret'],
				'allowed_updates'      => wp_json_encode( array( 'message' ) ),
				'drop_pending_updates' => false,
			)
		);
	}

	public static function register_rest_routes(): void {
		$settings = self::get_settings();
		register_rest_route(
			self::REST_NAMESPACE,
			'/telegram/' . preg_quote( $settings['webhook_path'], '/' ),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'telegram_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function telegram_webhook( WP_REST_Request $request ): WP_REST_Response {
		$settings = self::get_settings();
		$secret   = (string) $request->get_header( 'x-telegram-bot-api-secret-token' );
		if ( ! hash_equals( (string) $settings['webhook_secret'], $secret ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$update  = $request->get_json_params();
		$message = is_array( $update ) && isset( $update['message'] ) && is_array( $update['message'] ) ? $update['message'] : array();
		$chat_id = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';
		$text    = isset( $message['text'] ) ? trim( (string) $message['text'] ) : '';
		if ( ! $chat_id || ! $text ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$parts   = preg_split( '/\s+/', $text, 2 );
		$command = strtolower( preg_replace( '/@[^\s]+$/', '', (string) $parts[0] ) );
		$param   = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		$subs    = self::get_subscribers();
		$known   = isset( $subs[ $chat_id ] );

		if ( '/start' === $command ) {
			if ( ! $known && ! hash_equals( (string) $settings['invite_code'], $param ) ) {
				self::send_message( $chat_id, "🔒 Доступ к SEO-отчётам предоставляется по ссылке-приглашению.\nОбратитесь к администратору сайта." );
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}
			if ( ! $known ) {
				$from = isset( $message['from'] ) && is_array( $message['from'] ) ? $message['from'] : array();
				$name = trim( (string) ( $from['first_name'] ?? '' ) . ' ' . (string) ( $from['last_name'] ?? '' ) );
				$subs[ $chat_id ] = array(
					'name'       => sanitize_text_field( $name ),
					'username'   => sanitize_user( (string) ( $from['username'] ?? '' ), true ),
					'added_at'   => current_time( 'mysql' ),
				);
				update_option( self::SUBSCRIBERS_OPTION, $subs, false );
			}
			self::send_message( $chat_id, "✅ Вы подключены к SEO-отчётам pivzavod77.ru.\n\n/status — текущее состояние\n/report — последний отчёт\n/help — справка" );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		if ( ! $known ) {
			self::send_message( $chat_id, '🔒 Сначала подключитесь по ссылке-приглашению.' );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		switch ( $command ) {
			case '/status':
				$report = self::run_quick_check();
				self::send_message( $chat_id, self::format_report( $report, true ) );
				break;
			case '/report':
				$report = get_option( self::LAST_REPORT_OPTION, array() );
				self::send_message( $chat_id, $report ? self::format_report( $report, false ) : 'Отчёт ещё не сформирован. Используйте /status.' );
				break;
			case '/help':
			default:
				self::send_message( $chat_id, "SEO Monitor pivzavod77.ru\n\n/status — быстрая проверка\n/report — последний полный отчёт\n/help — список команд\n\nКритические ошибки отправляются автоматически." );
				break;
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function get_subscribers(): array {
		$subscribers = get_option( self::SUBSCRIBERS_OPTION, array() );
		return is_array( $subscribers ) ? $subscribers : array();
	}

	private static function telegram_request( string $method, array $body = array() ) {
		$token = self::token();
		if ( ! $token ) {
			return new WP_Error( 'p77_no_token', 'Токен Telegram не настроен.' );
		}
		return self::telegram_request_with_token( $token, $method, $body );
	}

	private static function telegram_request_with_token( string $token, string $method, array $body = array() ) {
		$response = wp_remote_post(
			self::TELEGRAM_API . rawurlencode( $token ) . '/' . rawurlencode( $method ),
			array( 'timeout' => 15, 'body' => $body )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : new WP_Error( 'p77_bad_telegram_response', 'Некорректный ответ Telegram.' );
	}

	private static function send_message( string $chat_id, string $text ): void {
		self::telegram_request(
			'sendMessage',
			array(
				'chat_id'                  => $chat_id,
				'text'                     => self::truncate( $text, 4000 ),
				'disable_web_page_preview' => 'true',
			)
		);
	}

	private static function broadcast( string $text ): void {
		foreach ( array_keys( self::get_subscribers() ) as $chat_id ) {
			self::send_message( (string) $chat_id, $text );
		}
	}

	public static function run_hourly_check(): void {
		$report          = self::run_quick_check();
		$critical_issues = array_values( array_filter( $report['issues'], static fn( $issue ) => 'critical' === $issue['level'] ) );
		$old_state       = get_option( self::ALERT_STATE_OPTION, array( 'fingerprint' => '', 'had_critical' => false ) );
		$fingerprint     = md5( wp_json_encode( $critical_issues ) );

		if ( $critical_issues && ( empty( $old_state['had_critical'] ) || $fingerprint !== ( $old_state['fingerprint'] ?? '' ) ) ) {
			self::broadcast( "🚨 Критическая SEO-ошибка\n\n" . self::format_report( $report, true ) );
		} elseif ( ! $critical_issues && ! empty( $old_state['had_critical'] ) ) {
			self::broadcast( "✅ SEO Monitor: критическая ошибка устранена.\nСайт pivzavod77.ru снова проходит аварийную проверку." );
		}

		update_option(
			self::ALERT_STATE_OPTION,
			array( 'fingerprint' => $fingerprint, 'had_critical' => ! empty( $critical_issues ) ),
			false
		);
	}

	public static function run_weekly_check(): void {
		$report = self::run_full_check();
		self::broadcast( self::format_report( $report, false ) );
	}

	private static function new_report( string $type ): array {
		return array(
			'type'         => $type,
			'created_at'   => gmdate( 'c' ),
			'status'       => 'ok',
			'checked'      => 0,
			'passed'       => 0,
			'issues'       => array(),
			'url_count'    => 0,
		);
	}

	private static function add_issue( array &$report, string $level, string $label, string $details = '' ): void {
		$report['issues'][] = array( 'level' => $level, 'label' => $label, 'details' => $details );
		if ( 'critical' === $level ) {
			$report['status'] = 'critical';
		} elseif ( 'ok' === $report['status'] ) {
			$report['status'] = 'warning';
		}
	}

	private static function pass( array &$report ): void {
		++$report['checked'];
		++$report['passed'];
	}

	private static function fail( array &$report, string $level, string $label, string $details = '' ): void {
		++$report['checked'];
		self::add_issue( $report, $level, $label, $details );
	}

	public static function run_quick_check(): array {
		$report = self::new_report( 'quick' );
		$home   = self::fetch( self::SITE_URL . '/', 5 );
		if ( 200 !== $home['code'] ) {
			self::fail( $report, 'critical', 'Главная страница недоступна', 'HTTP ' . $home['code'] );
		} else {
			self::pass( $report );
			$meta = self::extract_meta( $home['body'] );
			if ( str_contains( strtolower( $meta['robots'] ), 'noindex' ) ) {
				self::fail( $report, 'critical', 'На главной появился noindex', $meta['robots'] );
			} else {
				self::pass( $report );
			}
			if ( self::normalize_url( $meta['canonical'] ) !== self::SITE_URL . '/' ) {
				self::fail( $report, 'critical', 'Canonical главной некорректен', $meta['canonical'] ?: 'отсутствует' );
			} else {
				self::pass( $report );
			}
		}

		$robots = self::fetch( self::SITE_URL . '/robots.txt', 2 );
		if ( 200 !== $robots['code'] || false === stripos( $robots['body'], 'Sitemap: ' . self::SITE_URL . '/wp-sitemap.xml' ) ) {
			self::fail( $report, 'critical', 'robots.txt или строка Sitemap недоступны', 'HTTP ' . $robots['code'] );
		} else {
			self::pass( $report );
		}

		$sitemap = self::fetch( self::SITE_URL . '/wp-sitemap.xml', 2 );
		if ( 200 !== $sitemap['code'] || ! preg_match( '/<(?:sitemapindex|urlset)\b/i', $sitemap['body'] ) ) {
			self::fail( $report, 'critical', 'Карта сайта недоступна', 'HTTP ' . $sitemap['code'] );
		} else {
			self::pass( $report );
			foreach ( array( '/checkout/', '/lk/', '/author/admin/', '/author/administrator/' ) as $forbidden ) {
				if ( false !== stripos( $sitemap['body'], self::SITE_URL . $forbidden ) ) {
					self::fail( $report, 'warning', 'Технический URL найден в sitemap', $forbidden );
				} else {
					self::pass( $report );
				}
			}
		}

		self::check_redirect( $report, 'http://pivzavod77.ru/', self::SITE_URL . '/' );
		self::check_redirect( $report, 'https://www.pivzavod77.ru/', self::SITE_URL . '/' );
		self::check_page_canonical( $report, self::SITE_URL . '/shop/' );
		self::check_noindex_page( $report, self::SITE_URL . '/lk/' );

		$report['url_count'] = $report['checked'];
		return $report;
	}

	public static function run_full_check(): array {
		$report       = self::run_quick_check();
		$report['type'] = 'full';
		$urls         = self::sitemap_urls();
		$report['url_count'] = count( $urls );
		$technical    = array( '/checkout/', '/lk/', '/author/' );

		foreach ( array_slice( $urls, 0, 120 ) as $url ) {
			$response = self::fetch( $url, 5 );
			if ( 200 !== $response['code'] ) {
				self::fail( $report, 'warning', 'URL из sitemap отвечает не 200', $url . ' — HTTP ' . $response['code'] );
				continue;
			}
			self::pass( $report );
			foreach ( $technical as $fragment ) {
				if ( str_contains( $url, $fragment ) ) {
					self::fail( $report, 'warning', 'Технический URL находится в sitemap', $url );
					continue 2;
				}
			}
			$meta = self::extract_meta( $response['body'] );
			if ( str_contains( strtolower( $meta['robots'] ), 'noindex' ) ) {
				self::fail( $report, 'warning', 'URL из sitemap закрыт noindex', $url );
			} elseif ( self::normalize_url( $meta['canonical'] ) !== self::normalize_url( $url ) ) {
				self::fail( $report, 'warning', 'Canonical отсутствует или ведёт на другой URL', $url . ' → ' . ( $meta['canonical'] ?: 'отсутствует' ) );
			} else {
				self::pass( $report );
			}
		}

		foreach ( array( self::SITE_URL . '/author/admin/', self::SITE_URL . '/author/administrator/' ) as $author_url ) {
			self::check_noindex_page( $report, $author_url, true );
		}
		foreach ( array( self::SITE_URL . '/feed/', self::SITE_URL . '/comments/feed/' ) as $feed_url ) {
			$feed = self::fetch( $feed_url, 2 );
			if ( 200 === $feed['code'] && ! str_contains( strtolower( $feed['x_robots'] ), 'noindex' ) ) {
				self::fail( $report, 'warning', 'RSS доступен без X-Robots-Tag: noindex', $feed_url );
			} else {
				self::pass( $report );
			}
		}

		update_option( self::LAST_REPORT_OPTION, $report, false );
		return $report;
	}

	private static function sitemap_urls(): array {
		$index = self::fetch( self::SITE_URL . '/wp-sitemap.xml', 2 );
		if ( 200 !== $index['code'] ) {
			return array();
		}
		$locations = self::extract_locations( $index['body'] );
		$urls      = array();
		foreach ( array_slice( $locations, 0, 20 ) as $location ) {
			if ( ! str_ends_with( wp_parse_url( $location, PHP_URL_PATH ) ?: '', '.xml' ) ) {
				$urls[] = $location;
				continue;
			}
			$child = self::fetch( $location, 2 );
			if ( 200 === $child['code'] ) {
				$urls = array_merge( $urls, self::extract_locations( $child['body'] ) );
			}
			if ( count( $urls ) >= 120 ) {
				break;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	private static function extract_locations( string $xml ): array {
		if ( ! preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/is', $xml, $matches ) ) {
			return array();
		}
		return array_map( static fn( $url ) => html_entity_decode( trim( $url ), ENT_QUOTES | ENT_XML1, 'UTF-8' ), $matches[1] );
	}

	private static function check_redirect( array &$report, string $source, string $expected ): void {
		$response = self::fetch( $source, 0, 'HEAD' );
		$location = self::normalize_url( $response['location'] );
		if ( ! in_array( $response['code'], array( 301, 308 ), true ) || $location !== self::normalize_url( $expected ) ) {
			self::fail( $report, 'critical', 'Некорректный основной редирект', $source . ' → ' . ( $response['location'] ?: 'без Location' ) . ' (HTTP ' . $response['code'] . ')' );
		} else {
			self::pass( $report );
		}
	}

	private static function check_page_canonical( array &$report, string $url ): void {
		$response = self::fetch( $url, 5 );
		$meta     = self::extract_meta( $response['body'] );
		if ( 200 !== $response['code'] || self::normalize_url( $meta['canonical'] ) !== self::normalize_url( $url ) ) {
			self::fail( $report, 'warning', 'Canonical страницы некорректен', $url . ' → ' . ( $meta['canonical'] ?: 'отсутствует' ) );
		} else {
			self::pass( $report );
		}
	}

	private static function check_noindex_page( array &$report, string $url, bool $allow_404 = false ): void {
		$response = self::fetch( $url, 5 );
		if ( $allow_404 && 404 === $response['code'] ) {
			self::pass( $report );
			return;
		}
		$meta = self::extract_meta( $response['body'] );
		if ( 200 !== $response['code'] || ! str_contains( strtolower( $meta['robots'] ), 'noindex' ) ) {
			self::fail( $report, 'warning', 'Техническая страница не закрыта noindex', $url . ' — HTTP ' . $response['code'] );
		} else {
			self::pass( $report );
		}
	}

	private static function fetch( string $url, int $redirection = 5, string $method = 'GET' ): array {
		$args = array(
			'timeout'     => 12,
			'redirection' => $redirection,
			'user-agent'  => 'Pivzavod77-SEO-Monitor/' . self::VERSION,
			'sslverify'   => true,
		);
		$response = 'HEAD' === $method ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'code' => 0, 'body' => '', 'location' => '', 'x_robots' => '', 'error' => $response->get_error_message() );
		}
		$headers = wp_remote_retrieve_headers( $response );
		return array(
			'code'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'      => (string) wp_remote_retrieve_body( $response ),
			'location'  => isset( $headers['location'] ) ? (string) $headers['location'] : '',
			'x_robots'  => isset( $headers['x-robots-tag'] ) ? (string) $headers['x-robots-tag'] : '',
			'error'     => '',
		);
	}

	private static function extract_meta( string $html ): array {
		$result = array( 'robots' => '', 'canonical' => '' );
		if ( preg_match( '/<meta\b[^>]*\bname=["\']robots["\'][^>]*\bcontent=["\']([^"\']*)["\'][^>]*>/i', $html, $match ) ||
			preg_match( '/<meta\b[^>]*\bcontent=["\']([^"\']*)["\'][^>]*\bname=["\']robots["\'][^>]*>/i', $html, $match ) ) {
			$result['robots'] = html_entity_decode( trim( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( preg_match( '/<link\b[^>]*\brel=["\'][^"\']*canonical[^"\']*["\'][^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $html, $match ) ||
			preg_match( '/<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\'][^"\']*canonical[^"\']*["\'][^>]*>/i', $html, $match ) ) {
			$result['canonical'] = html_entity_decode( trim( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $result;
	}

	private static function normalize_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( ! $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}
		return strtolower( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	private static function format_report( array $report, bool $short ): string {
		$status_icons = array( 'ok' => '✅', 'warning' => '⚠️', 'critical' => '🚨' );
		$status_names = array( 'ok' => 'Ошибок не обнаружено', 'warning' => 'Есть предупреждения', 'critical' => 'Обнаружены критические ошибки' );
		$status       = $report['status'] ?? 'warning';
		$timestamp    = isset( $report['created_at'] ) ? strtotime( $report['created_at'] ) : time();
		$date         = wp_date( 'd.m.Y H:i', $timestamp, new DateTimeZone( 'Europe/Moscow' ) );
		$text         = ( $status_icons[ $status ] ?? 'ℹ️' ) . ' SEO-отчёт pivzavod77.ru' . "\n";
		$text        .= 'Статус: ' . ( $status_names[ $status ] ?? $status ) . "\n";
		$text        .= 'Проверено: ' . (int) ( $report['checked'] ?? 0 ) . ', успешно: ' . (int) ( $report['passed'] ?? 0 ) . "\n";
		if ( ! $short && ! empty( $report['url_count'] ) ) {
			$text .= 'URL в проверке: ' . (int) $report['url_count'] . "\n";
		}
		$text .= 'Дата: ' . $date . " МСК\n";

		$issues = isset( $report['issues'] ) && is_array( $report['issues'] ) ? $report['issues'] : array();
		if ( $issues ) {
			$text .= "\nПроблемы:\n";
			$limit = $short ? 8 : 18;
			foreach ( array_slice( $issues, 0, $limit ) as $issue ) {
				$icon  = 'critical' === $issue['level'] ? '🔴' : '🟡';
				$text .= $icon . ' ' . $issue['label'];
				if ( ! empty( $issue['details'] ) ) {
					$text .= ': ' . $issue['details'];
				}
				$text .= "\n";
			}
			if ( count( $issues ) > $limit ) {
				$text .= '…и ещё ' . ( count( $issues ) - $limit ) . "\n";
			}
		} else {
			$text .= "\nВсе контрольные параметры в норме.";
		}
		return self::truncate( trim( $text ), 4000 );
	}

	private static function truncate( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}

register_activation_hook( __FILE__, array( 'P77_SEO_Monitor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'P77_SEO_Monitor', 'deactivate' ) );
P77_SEO_Monitor::bootstrap();

