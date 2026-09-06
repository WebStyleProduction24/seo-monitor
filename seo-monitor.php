<?php
/**
 * Plugin Name: SEO Monitor
 * Description: Регулярно проверяет технические SEO-настройки и отправляет отчёты в Telegram.
 * Version: 1.1.0
 * Author: ООО "АЙ ТИ ГАММА"
 * Uthor URL: https://wsp24.ru/
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Update URI: https://github.com/WebStyleProduction24/seo-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SEO_Monitor {
	private const VERSION             = '1.1.0';
	private const PLUGIN_SLUG         = 'seo-monitor';
	private const GITHUB_REPOSITORY   = 'WebStyleProduction24/seo-monitor';
	private const GITHUB_URL          = 'https://github.com/WebStyleProduction24/seo-monitor';
	private const GITHUB_API_URL      = 'https://api.github.com/repos/WebStyleProduction24/seo-monitor/releases/latest';
	private const UPDATE_CACHE_KEY    = 'seo_monitor_github_release';
	private const SETTINGS_OPTION     = 'seo_monitor_settings';
	private const SUBSCRIBERS_OPTION  = 'seo_monitor_subscribers';
	private const LAST_REPORT_OPTION  = 'seo_monitor_last_report';
	private const ALERT_STATE_OPTION  = 'seo_monitor_alert_state';
	private const LAST_YANDEX_REPORT_OPTION = 'seo_monitor_last_yandex_report';
	private const YANDEX_ALERT_STATE_OPTION = 'seo_monitor_yandex_alert_state';
	private const YANDEX_REGION_STATE_OPTION = 'seo_monitor_yandex_region_state';
	private const VERSION_OPTION      = 'seo_monitor_version';
	private const HOURLY_HOOK         = 'seo_monitor_hourly';
	private const WEEKLY_HOOK         = 'seo_monitor_weekly';
	private const YANDEX_DAILY_HOOK   = 'seo_monitor_yandex_daily';
	private const YANDEX_ON_DEMAND_HOOK = 'seo_monitor_yandex_on_demand';
	private const YANDEX_REGION_CHECK_HOOK = 'seo_monitor_yandex_region_check';
	private const YANDEX_REGION_ON_DEMAND_HOOK = 'seo_monitor_yandex_region_on_demand';
	private const REST_NAMESPACE      = 'seo/v1';
	private const SITE_URL            = 'https://pivzavod77.ru';
	private const TELEGRAM_API        = 'https://api.telegram.org/bot';
	private const YANDEX_API          = 'https://api.webmaster.yandex.net';
	private const YANDEX_OAUTH_AUTHORIZE = 'https://oauth.yandex.ru/authorize';
	private const YANDEX_OAUTH_TOKEN  = 'https://oauth.yandex.ru/token';

	public static function bootstrap(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 20 );
		add_action( self::HOURLY_HOOK, array( __CLASS__, 'run_hourly_check' ) );
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'run_weekly_check' ) );
		add_action( self::YANDEX_DAILY_HOOK, array( __CLASS__, 'run_yandex_daily_check' ) );
		add_action( self::YANDEX_ON_DEMAND_HOOK, array( __CLASS__, 'run_yandex_on_demand' ), 10, 1 );
		add_action( self::YANDEX_REGION_CHECK_HOOK, array( __CLASS__, 'run_yandex_scheduled_region_check' ) );
		add_action( self::YANDEX_REGION_ON_DEMAND_HOOK, array( __CLASS__, 'run_yandex_region_on_demand' ), 10, 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_github_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'github_plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'normalize_update_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_update_cache' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_post_seo_monitor_save', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_post_seo_monitor_save_yandex', array( __CLASS__, 'save_yandex_settings' ) );
			add_action( 'admin_post_seo_monitor_action', array( __CLASS__, 'handle_admin_action' ) );
			add_action( 'admin_post_yandex_oauth_start', array( __CLASS__, 'yandex_oauth_start' ) );
			add_action( 'admin_post_yandex_oauth_callback', array( __CLASS__, 'yandex_oauth_callback' ) );
		}
	}

	public static function activate(): void {
		$settings = self::get_settings();
		update_option( self::SETTINGS_OPTION, $settings, false );
		update_option( self::VERSION_OPTION, self::VERSION, false );
		self::reschedule_events( $settings );
		if ( self::token() ) {
			self::set_bot_commands();
		}
	}

	/**
	 * Однократно синхронизирует команды Telegram после обновления плагина.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		if ( self::token() ) {
			self::set_bot_commands();
		}
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::YANDEX_DAILY_HOOK );
		wp_clear_scheduled_hook( self::YANDEX_REGION_CHECK_HOOK );
		wp_clear_scheduled_hook( self::YANDEX_REGION_ON_DEMAND_HOOK );
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['weekly'] = array(
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
			wp_schedule_event( self::next_weekly_timestamp( $settings ), 'weekly', self::WEEKLY_HOOK );
		}
		if ( ! empty( $settings['yandex_daily_enabled'] ) && self::yandex_is_connected( $settings ) && ! wp_next_scheduled( self::YANDEX_DAILY_HOOK ) ) {
			wp_schedule_event( self::next_daily_timestamp(), 'daily', self::YANDEX_DAILY_HOOK );
		}
		self::ensure_yandex_region_schedule( $settings );
	}

	private static function reschedule_events( array $settings ): void {
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::YANDEX_DAILY_HOOK );
		wp_clear_scheduled_hook( self::YANDEX_REGION_CHECK_HOOK );

		if ( ! empty( $settings['hourly_enabled'] ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
		if ( ! empty( $settings['weekly_enabled'] ) ) {
			wp_schedule_event( self::next_weekly_timestamp( $settings ), 'weekly', self::WEEKLY_HOOK );
		}
		if ( ! empty( $settings['yandex_daily_enabled'] ) && self::yandex_is_connected( $settings ) ) {
			wp_schedule_event( self::next_daily_timestamp(), 'daily', self::YANDEX_DAILY_HOOK );
		}
		self::ensure_yandex_region_schedule( $settings );
	}

	private static function ensure_yandex_region_schedule( array $settings ): void {
		if ( empty( $settings['yandex_region_check_enabled'] ) || wp_next_scheduled( self::YANDEX_REGION_CHECK_HOOK ) ) {
			return;
		}
		$timestamp = self::yandex_region_check_timestamp( $settings );
		if ( $timestamp > time() ) {
			wp_schedule_single_event( $timestamp, self::YANDEX_REGION_CHECK_HOOK );
		}
	}

	private static function yandex_region_check_timestamp( array $settings ): int {
		$date = (string) ( $settings['yandex_region_check_date'] ?? '' );
		$time = (string) ( $settings['yandex_region_check_time'] ?? '' );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $date_parts ) ||
			! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] ) ||
			! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return 0;
		}
		$scheduled = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone( 'Europe/Moscow' ) );
		return $scheduled instanceof DateTimeImmutable ? $scheduled->getTimestamp() : 0;
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

	private static function next_daily_timestamp(): int {
		$tz   = new DateTimeZone( 'Europe/Moscow' );
		$now  = new DateTimeImmutable( 'now', $tz );
		$next = $now->setTime( 10, 15, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
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
			'yandex_client_id'               => '',
			'encrypted_yandex_client_secret' => '',
			'encrypted_yandex_access_token'  => '',
			'encrypted_yandex_refresh_token' => '',
			'yandex_token_expires_at'        => 0,
			'yandex_user_id'                 => '',
			'yandex_host_id'                 => '',
			'yandex_host_url'                => '',
			'yandex_daily_enabled'           => 1,
			'yandex_region_check_enabled'    => 1,
			'yandex_region_check_date'       => '2026-09-09',
			'yandex_region_check_time'       => '10:00',
			'yandex_expected_region'         => 'Москва и Московская область',
		);
	}

	private static function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Добавляет обновление из последнего публичного GitHub Release в стандартный
	 * список обновлений WordPress.
	 */
	public static function check_github_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$release = self::latest_github_release();
		if ( is_wp_error( $release ) ) {
			return $transient;
		}

		$new_version = self::release_version( $release );
		$package     = self::release_package( $release );
		$plugin_file = plugin_basename( __FILE__ );

		if ( $new_version && $package && version_compare( $new_version, self::VERSION, '>' ) ) {
			$transient->response[ $plugin_file ] = (object) array(
				'id'           => self::GITHUB_URL,
				'slug'         => self::PLUGIN_SLUG,
				'plugin'       => $plugin_file,
				'new_version'  => $new_version,
				'url'          => self::GITHUB_URL . '/releases/latest',
				'package'      => $package,
				'tested'       => '7.1',
				'requires_php' => '8.0',
				'icons'        => array(),
				'banners'      => array(),
			);
		} else {
			$transient->no_update[ $plugin_file ] = (object) array(
				'id'           => self::GITHUB_URL,
				'slug'         => self::PLUGIN_SLUG,
				'plugin'       => $plugin_file,
				'new_version'  => self::VERSION,
				'url'          => self::GITHUB_URL,
				'package'      => '',
				'tested'       => '7.1',
				'requires_php' => '8.0',
			);
		}

		return $transient;
	}

	/**
	 * Выводит описание GitHub Release в стандартном окне «Детали версии».
	 */
	public static function github_plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::PLUGIN_SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::latest_github_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}
		$version = self::release_version( $release );
		$package = self::release_package( $release );
		if ( ! $version || ! $package ) {
			return $result;
		}

		$body = isset( $release['body'] ) ? trim( (string) $release['body'] ) : '';
		return (object) array(
			'name'          => 'Пивзавод77 — SEO Monitor',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $version,
			'author'        => '<a href="https://github.com/WebStyleProduction24">WebStyleProduction24</a>',
			'homepage'      => self::GITHUB_URL,
			'requires'      => '6.2',
			'tested'        => '7.1',
			'requires_php'  => '8.0',
			'download_link' => $package,
			'last_updated'  => (string) ( $release['published_at'] ?? '' ),
			'sections'      => array(
				'description' => 'Технический SEO-мониторинг и контроль данных Яндекс Вебмастера с отправкой отчётов через Telegram.',
				'changelog'   => $body ? nl2br( esc_html( $body ) ) : 'Описание изменений опубликовано в GitHub Releases.',
			),
		);
	}

	/**
	 * GitHub zipball содержит динамическое имя корневой директории. Перед
	 * установкой переименовываем её в постоянный slug плагина.
	 */
	public static function normalize_update_source( $source, $remote_source, $upgrader, array $hook_extra ) {
		$plugin_file = plugin_basename( __FILE__ );
		if ( empty( $hook_extra['plugin'] ) || $plugin_file !== $hook_extra['plugin'] ) {
			return $source;
		}
		if ( self::PLUGIN_SLUG === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return new WP_Error( 'update_filesystem', 'Не удалось инициализировать файловую систему WordPress.' );
		}

		$normalized_source = trailingslashit( $remote_source ) . self::PLUGIN_SLUG;
		if ( $wp_filesystem->exists( $normalized_source ) ) {
			$wp_filesystem->delete( $normalized_source, true );
		}
		if ( ! $wp_filesystem->move( $source, $normalized_source, true ) ) {
			return new WP_Error( 'update_move', 'Не удалось подготовить каталог обновления плагина.' );
		}

		return trailingslashit( $normalized_source );
	}

	public static function clear_update_cache( $upgrader, array $options ): void {
		if ( 'plugin' !== ( $options['type'] ?? '' ) || 'update' !== ( $options['action'] ?? '' ) ) {
			return;
		}
		$plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array();
		if ( in_array( plugin_basename( __FILE__ ), $plugins, true ) ) {
			delete_transient( self::UPDATE_CACHE_KEY );
		}
	}

	private static function latest_github_release() {
		$cached = get_transient( self::UPDATE_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_API_URL,
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'SEO-Monitor/' . self::VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'github_http', 'GitHub Releases вернул HTTP ' . $code . '.' );
		}
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return new WP_Error( 'github_response', 'GitHub вернул некорректные данные релиза.' );
		}

		set_transient( self::UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	private static function release_version( array $release ): string {
		$version = ltrim( trim( (string) ( $release['tag_name'] ?? '' ) ), "vV \t\n\r\0\x0B" );
		return preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ? $version : '';
	}

	private static function release_package( array $release ): string {
		$asset_name = self::PLUGIN_SLUG . '.zip';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( $asset_name === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
				return esc_url_raw( (string) $asset['browser_download_url'] );
			}
		}
		return ! empty( $release['zipball_url'] ) ? esc_url_raw( (string) $release['zipball_url'] ) : '';
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

	private static function yandex_is_connected( ?array $settings = null ): bool {
		$settings = null === $settings ? self::get_settings() : $settings;
		return '' !== self::decrypt_token( (string) $settings['encrypted_yandex_access_token'] )
			&& '' !== (string) $settings['yandex_user_id']
			&& '' !== (string) $settings['yandex_host_id'];
	}

	private static function yandex_client_secret( ?array $settings = null ): string {
		$settings = null === $settings ? self::get_settings() : $settings;
		return self::decrypt_token( (string) $settings['encrypted_yandex_client_secret'] );
	}

	private static function yandex_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=yandex_oauth_callback' );
	}

	private static function yandex_oauth_token_request( array $body ) {
		$response = wp_remote_post(
			self::YANDEX_OAUTH_TOKEN,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			$message = is_array( $decoded ) ? (string) ( $decoded['error_description'] ?? $decoded['error'] ?? '' ) : '';
			return new WP_Error( 'yandex_oauth', $message ?: 'Яндекс OAuth вернул HTTP ' . $code . '.' );
		}
		return $decoded;
	}

	private static function store_yandex_tokens( array $tokens ): bool {
		$settings = self::get_settings();
		$access   = trim( (string) ( $tokens['access_token'] ?? '' ) );
		if ( '' === $access ) {
			return false;
		}

		$encrypted_access = self::encrypt_token( $access );
		if ( '' === $encrypted_access ) {
			return false;
		}
		$settings['encrypted_yandex_access_token'] = $encrypted_access;

		$refresh = trim( (string) ( $tokens['refresh_token'] ?? '' ) );
		if ( '' !== $refresh ) {
			$encrypted_refresh = self::encrypt_token( $refresh );
			if ( '' === $encrypted_refresh ) {
				return false;
			}
			$settings['encrypted_yandex_refresh_token'] = $encrypted_refresh;
		}

		$expires_in = isset( $tokens['expires_in'] ) ? max( 0, (int) $tokens['expires_in'] ) : 0;
		$settings['yandex_token_expires_at'] = $expires_in ? time() + $expires_in : 0;
		update_option( self::SETTINGS_OPTION, $settings, false );
		return true;
	}

	private static function yandex_access_token( bool $force_refresh = false ) {
		$settings = self::get_settings();
		$access   = self::decrypt_token( (string) $settings['encrypted_yandex_access_token'] );
		$expires  = (int) $settings['yandex_token_expires_at'];
		if ( '' === $access ) {
			return new WP_Error( 'yandex_not_connected', 'Яндекс Вебмастер не подключён.' );
		}

		// Обновляем заранее, чтобы редкий запуск WP-Cron не пришёлся уже на
		// истёкший refresh token.
		if ( ! $force_refresh && ( 0 === $expires || $expires > time() + 7 * DAY_IN_SECONDS ) ) {
			return $access;
		}

		$refresh = self::decrypt_token( (string) $settings['encrypted_yandex_refresh_token'] );
		if ( '' === $refresh ) {
			if ( 0 === $expires || $expires > time() ) {
				return $access;
			}
			return new WP_Error( 'yandex_token_expired', 'Срок действия OAuth-токена Яндекса истёк. Подключите аккаунт повторно.' );
		}

		$client_id     = trim( (string) $settings['yandex_client_id'] );
		$client_secret = self::yandex_client_secret( $settings );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'yandex_credentials', 'Для обновления OAuth-токена не сохранены ClientID или Client secret.' );
		}

		$tokens = self::yandex_oauth_token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( ! self::store_yandex_tokens( $tokens ) ) {
			return new WP_Error( 'yandex_encrypt', 'Не удалось безопасно сохранить обновлённый токен Яндекса.' );
		}
		return trim( (string) $tokens['access_token'] );
	}

	private static function yandex_api_request( string $path, array $query = array(), bool $retry = true ) {
		$token = self::yandex_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::YANDEX_API . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'OAuth ' . $token,
					'Accept'        => 'application/json',
				),
				'user-agent' => 'SEO-Monitor/' . self::VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && $retry ) {
			$refreshed = self::yandex_access_token( true );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return self::yandex_api_request( $path, $query, false );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			$message = is_array( $decoded ) ? (string) ( $decoded['error_message'] ?? $decoded['message'] ?? $decoded['error_code'] ?? '' ) : '';
			$error_code = 401 === $code ? 'yandex_unauthorized' : ( 403 === $code ? 'yandex_forbidden' : 'yandex_api' );
			return new WP_Error( $error_code, $message ?: 'API Яндекс Вебмастера вернул HTTP ' . $code . '.', array( 'status' => $code ) );
		}
		return $decoded;
	}

	public static function yandex_oauth_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'yandex_oauth_start' );
		$settings      = self::get_settings();
		$client_id     = trim( (string) $settings['yandex_client_id'] );
		$client_secret = self::yandex_client_secret( $settings );
		if ( '' === $client_id || '' === $client_secret ) {
			self::redirect_admin( 'Сначала сохраните ClientID и Client secret приложения Яндекс OAuth.' );
		}

		$state = wp_generate_password( 48, false, false );
		set_transient(
			'yandex_oauth_' . hash( 'sha256', $state ),
			array( 'user_id' => get_current_user_id() ),
			10 * MINUTE_IN_SECONDS
		);
		$url = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'redirect_uri'  => self::yandex_redirect_uri(),
				'scope'         => 'webmaster:hostinfo',
				'force_confirm' => 'yes',
				'state'         => $state,
			),
			self::YANDEX_OAUTH_AUTHORIZE
		);
		wp_redirect( esc_url_raw( $url ) );
		exit;
	}

	public static function yandex_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Для завершения подключения войдите в WordPress с правами администратора.' );
		}

		$state     = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$state_key = 'yandex_oauth_' . hash( 'sha256', $state );
		$saved     = '' !== $state ? get_transient( $state_key ) : false;
		delete_transient( $state_key );
		if ( ! is_array( $saved ) || (int) ( $saved['user_id'] ?? 0 ) !== get_current_user_id() ) {
			self::redirect_admin( 'Не удалось проверить OAuth-запрос. Запустите подключение Яндекса ещё раз.' );
		}

		if ( isset( $_GET['error'] ) ) {
			$error_value = isset( $_GET['error_description'] ) && is_string( $_GET['error_description'] ) ? $_GET['error_description'] : $_GET['error'];
			$error = is_string( $error_value ) ? sanitize_text_field( wp_unslash( $error_value ) ) : 'доступ отклонён';
			self::redirect_admin( 'Яндекс не предоставил доступ: ' . $error );
		}
		$code = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			self::redirect_admin( 'Яндекс не передал код подтверждения.' );
		}

		$settings = self::get_settings();
		$tokens   = self::yandex_oauth_token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => (string) $settings['yandex_client_id'],
				'client_secret' => self::yandex_client_secret( $settings ),
			)
		);
		if ( is_wp_error( $tokens ) ) {
			self::redirect_admin( 'Не удалось получить OAuth-токен: ' . $tokens->get_error_message() );
		}
		if ( ! self::store_yandex_tokens( $tokens ) ) {
			self::redirect_admin( 'Не удалось зашифровать OAuth-токен. Проверьте наличие OpenSSL на сервере.' );
		}

		$host = self::discover_yandex_host();
		if ( is_wp_error( $host ) ) {
			self::redirect_admin( 'Токен получен, но сайт не подключён: ' . $host->get_error_message() );
		}
		$settings                    = self::get_settings();
		$settings['yandex_user_id']  = (string) $host['user_id'];
		$settings['yandex_host_id']  = (string) $host['host_id'];
		$settings['yandex_host_url'] = (string) $host['host_url'];
		update_option( self::SETTINGS_OPTION, $settings, false );
		self::reschedule_events( $settings );
		$report = self::run_yandex_check();
		self::remember_yandex_region_state( $report, false );
		self::redirect_admin( 'Яндекс Вебмастер подключён. Сайт найден, первая проверка выполнена.' );
	}

	private static function discover_yandex_host() {
		$user = self::yandex_api_request( '/v4/user' );
		if ( is_wp_error( $user ) || empty( $user['user_id'] ) ) {
			return is_wp_error( $user ) ? $user : new WP_Error( 'yandex_user', 'API не вернул идентификатор пользователя.' );
		}
		$user_id = (string) $user['user_id'];
		$hosts   = self::yandex_api_request( '/v4/user/' . rawurlencode( $user_id ) . '/hosts' );
		if ( is_wp_error( $hosts ) ) {
			return $hosts;
		}

		$target = self::normalize_url( self::SITE_URL . '/' );
		foreach ( (array) ( $hosts['hosts'] ?? array() ) as $host ) {
			$host_url = (string) ( $host['ascii_host_url'] ?? $host['unicode_host_url'] ?? '' );
			if ( self::normalize_url( $host_url ) === $target && ! empty( $host['verified'] ) ) {
				return array( 'user_id' => $user_id, 'host_id' => (string) $host['host_id'], 'host_url' => $host_url );
			}
		}
		foreach ( (array) ( $hosts['hosts'] ?? array() ) as $host ) {
			$mirror = isset( $host['main_mirror'] ) && is_array( $host['main_mirror'] ) ? $host['main_mirror'] : array();
			$url    = (string) ( $mirror['ascii_host_url'] ?? $mirror['unicode_host_url'] ?? '' );
			if ( self::normalize_url( $url ) === $target && ! empty( $mirror['verified'] ) ) {
				return array( 'user_id' => $user_id, 'host_id' => (string) $mirror['host_id'], 'host_url' => $url );
			}
		}
		return new WP_Error( 'yandex_host', 'В аккаунте нет подтверждённого сайта ' . self::SITE_URL . '.' );
	}

	public static function admin_menu(): void {
		add_options_page(
			'Пивзавод77 SEO Monitor',
			'SEO Monitor',
			'manage_options',
			'seo-monitor',
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
		$last_yandex_report = get_option( self::LAST_YANDEX_REPORT_OPTION, array() );
		$days        = array( 1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье' );
		$notice      = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';
		$invite_link = 'https://t.me/' . ltrim( (string) $settings['bot_username'], '@' ) . '?start=' . rawurlencode( (string) $settings['invite_code'] );
		$yandex_connected = self::yandex_is_connected( $settings );
		$region_check_timestamp = wp_next_scheduled( self::YANDEX_REGION_CHECK_HOOK );
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
				<input type="hidden" name="action" value="seo_monitor_save">
				<?php wp_nonce_field( 'seo_monitor_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="token">Токен Telegram-бота</label></th>
						<td>
							<input id="token" name="token" type="password" class="regular-text" autocomplete="new-password" value="">
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
			<h2>Яндекс Вебмастер</h2>
			<p>
				Статус:
				<?php if ( $yandex_connected ) : ?>
					<strong style="color:#008a20">подключён</strong>
					<?php if ( $settings['yandex_host_url'] ) : ?>
						— <?php echo esc_html( $settings['yandex_host_url'] ); ?>
					<?php endif; ?>
				<?php else : ?>
					<strong>не подключён</strong>
				<?php endif; ?>
			</p>
			<p>Создайте приложение Яндекс OAuth с платформой «Веб-сервисы», правом <code>webmaster:hostinfo</code> и следующим Redirect URI:</p>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::yandex_redirect_uri() ); ?>" onclick="this.select();"></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="seo_monitor_save_yandex">
				<?php wp_nonce_field( 'seo_monitor_save_yandex' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="yandex_client_id">ClientID</label></th>
						<td><input id="yandex_client_id" name="yandex_client_id" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $settings['yandex_client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="yandex_client_secret">Client secret</label></th>
						<td>
							<input id="yandex_client_secret" name="yandex_client_secret" type="password" class="regular-text" autocomplete="new-password" value="">
							<p class="description"><?php echo $settings['encrypted_yandex_client_secret'] ? 'Секрет сохранён в зашифрованном виде. Оставьте поле пустым, чтобы не менять его.' : 'Секрет приложения не отображается после сохранения.'; ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yandex_manual_token">Готовый OAuth-токен</label></th>
						<td>
							<input id="yandex_manual_token" name="yandex_manual_token" type="password" class="regular-text" autocomplete="new-password" value="">
							<p class="description">Альтернативный способ подключения для приложения «Доступ к API или отладка». Токен сохраняется только в зашифрованном виде.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Автоматическая проверка</th>
						<td><label><input type="checkbox" name="yandex_daily_enabled" value="1" <?php checked( $settings['yandex_daily_enabled'] ); ?>> ежедневно в 10:15 МСК</label></td>
					</tr>
					<tr>
						<th scope="row">Контроль региональности</th>
						<td>
							<label><input type="checkbox" name="yandex_region_check_enabled" value="1" <?php checked( $settings['yandex_region_check_enabled'] ); ?>> выполнить однократную проверку</label>
							<p>
								<label>Ожидаемый регион:
									<input type="text" name="yandex_expected_region" class="regular-text" value="<?php echo esc_attr( $settings['yandex_expected_region'] ); ?>">
								</label>
							</p>
							<p>
								<label>Дата: <input type="date" name="yandex_region_check_date" value="<?php echo esc_attr( $settings['yandex_region_check_date'] ); ?>"></label>
								<label style="margin-left:12px">Время: <input type="time" name="yandex_region_check_time" value="<?php echo esc_attr( $settings['yandex_region_check_time'] ); ?>"></label>
								<span class="description">по московскому времени</span>
							</p>
							<?php if ( $region_check_timestamp ) : ?>
								<p class="description"><strong>Запланировано:</strong> <?php echo esc_html( wp_date( 'd.m.Y H:i', $region_check_timestamp, new DateTimeZone( 'Europe/Moscow' ) ) ); ?> МСК.</p>
							<?php else : ?>
								<p class="description">После сохранения будущей даты WordPress поставит однократную задачу в WP-Cron. Фактический запуск возможен при первом посещении сайта после указанного времени.</p>
							<?php endif; ?>
							<p class="description">API позволяет определить, отмечает ли Яндекс проблему «регион не задан». Точное название принятого региона нужно подтвердить в интерфейсе Вебмастера.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Сохранить настройки Яндекса', 'secondary' ); ?>
			</form>

			<p>
				<?php if ( $settings['yandex_client_id'] && $settings['encrypted_yandex_client_secret'] ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yandex_oauth_start' ), 'yandex_oauth_start' ) ); ?>"><?php echo $yandex_connected ? 'Подключить Яндекс повторно' : 'Подключить Яндекс'; ?></a>
				<?php endif; ?>
				<?php if ( $yandex_connected ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seo_monitor_action&action=run_yandex' ), 'seo_monitor_action' ) ); ?>">Проверить Яндекс сейчас</a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seo_monitor_action&action=run_region' ), 'seo_monitor_action' ) ); ?>">Проверить региональность</a>
					<a class="button-link-delete" style="margin-left:12px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seo_monitor_action&action=disconnect_yandex' ), 'seo_monitor_action' ) ); ?>" onclick="return confirm('Отключить Яндекс Вебмастер?');">Отключить</a>
				<?php endif; ?>
			</p>
			<?php if ( is_array( $last_yandex_report ) && ! empty( $last_yandex_report['created_at'] ) ) : ?>
				<h3>Последняя проверка Яндекс Вебмастера</h3>
				<pre style="background:#fff;padding:16px;border:1px solid #ccd0d4;white-space:pre-wrap"><?php echo esc_html( self::format_yandex_report( $last_yandex_report ) ); ?></pre>
			<?php endif; ?>

			<hr>
			<h2>Добавление получателей</h2>
			<p>Отправьте администратору или заказчику эту персональную ссылку. После нажатия «Запустить» пользователь будет добавлен в рассылку:</p>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $invite_link ); ?>" onclick="this.select();"></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="seo_monitor_action">
				<input type="hidden" name="action" value="rotate_invite">
				<?php wp_nonce_field( 'seo_monitor_action' ); ?>
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
									<input type="hidden" name="action" value="seo_monitor_action">
									<input type="hidden" name="action" value="remove_subscriber">
									<input type="hidden" name="chat_id" value="<?php echo esc_attr( $chat_id ); ?>">
									<?php wp_nonce_field( 'seo_monitor_action' ); ?>
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
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seo_monitor_action&action=run_full' ), 'seo_monitor_action' ) ); ?>">Проверить сайт сейчас</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seo_monitor_action&action=test_message' ), 'seo_monitor_action' ) ); ?>">Отправить тестовое сообщение</a>
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
		check_admin_referer( 'seo_monitor_save' );
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

			$commands = self::set_bot_commands();
			if ( is_wp_error( $commands ) ) {
				$notice .= ' Не удалось обновить меню команд: ' . $commands->get_error_message();
			} elseif ( empty( $commands['ok'] ) ) {
				$notice .= ' Telegram не подтвердил обновление меню команд.';
			}
		}

		self::redirect_admin( $notice );
	}

	public static function save_yandex_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'seo_monitor_save_yandex' );
		$settings      = self::get_settings();
		$old_client_id = (string) $settings['yandex_client_id'];
		$client_id     = isset( $_POST['yandex_client_id'] ) && is_string( $_POST['yandex_client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yandex_client_id'] ) ) ) : '';
		$client_secret = isset( $_POST['yandex_client_secret'] ) && is_string( $_POST['yandex_client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yandex_client_secret'] ) ) ) : '';
		$manual_token  = isset( $_POST['yandex_manual_token'] ) && is_string( $_POST['yandex_manual_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yandex_manual_token'] ) ) ) : '';
		$region_date   = isset( $_POST['yandex_region_check_date'] ) && is_string( $_POST['yandex_region_check_date'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yandex_region_check_date'] ) ) ) : '';
		$region_time   = isset( $_POST['yandex_region_check_time'] ) && is_string( $_POST['yandex_region_check_time'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yandex_region_check_time'] ) ) ) : '';
		$expected_region = isset( $_POST['yandex_expected_region'] ) && is_string( $_POST['yandex_expected_region'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['yandex_expected_region'] ) ) ) : '';

		if ( '' !== $client_id && ! preg_match( '/^[A-Za-z0-9_-]{10,}$/', $client_id ) ) {
			self::redirect_admin( 'ClientID Яндекса имеет неверный формат.' );
		}
		if ( '' !== $client_secret ) {
			$encrypted = self::encrypt_token( $client_secret );
			if ( '' === $encrypted ) {
				self::redirect_admin( 'Не удалось зашифровать Client secret: на сервере недоступен OpenSSL.' );
			}
			$settings['encrypted_yandex_client_secret'] = $encrypted;
		}
		if ( '' !== $manual_token && ! preg_match( '/^[A-Za-z0-9_.:\-]{20,}$/', $manual_token ) ) {
			self::redirect_admin( 'OAuth-токен Яндекса имеет неверный формат.' );
		}
		$region_enabled = isset( $_POST['yandex_region_check_enabled'] ) ? 1 : 0;
		if ( $region_enabled ) {
			if ( '' === $expected_region ) {
				self::redirect_admin( 'Укажите ожидаемый регион для контрольной проверки.' );
			}
			$region_settings = array(
				'yandex_region_check_date' => $region_date,
				'yandex_region_check_time' => $region_time,
			);
			if ( self::yandex_region_check_timestamp( $region_settings ) <= time() ) {
				self::redirect_admin( 'Укажите будущую дату и время проверки региональности.' );
			}
		}

		$settings['yandex_client_id']     = $client_id;
		$settings['yandex_daily_enabled'] = isset( $_POST['yandex_daily_enabled'] ) ? 1 : 0;
		$settings['yandex_region_check_enabled'] = $region_enabled;
		$settings['yandex_region_check_date']    = $region_date;
		$settings['yandex_region_check_time']    = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $region_time ) ? $region_time : '10:00';
		$settings['yandex_expected_region']      = '' !== $expected_region ? $expected_region : 'Москва и Московская область';
		if ( $old_client_id !== $client_id ) {
			if ( '' === $client_secret ) {
				$settings['encrypted_yandex_client_secret'] = '';
			}
			$settings['encrypted_yandex_access_token']  = '';
			$settings['encrypted_yandex_refresh_token'] = '';
			$settings['yandex_token_expires_at']        = 0;
			$settings['yandex_user_id']                 = '';
			$settings['yandex_host_id']                 = '';
			$settings['yandex_host_url']                = '';
		}

		update_option( self::SETTINGS_OPTION, $settings, false );
		if ( '' !== $manual_token ) {
			if ( ! self::store_yandex_tokens( array( 'access_token' => $manual_token, 'expires_in' => 180 * DAY_IN_SECONDS ) ) ) {
				self::redirect_admin( 'Не удалось зашифровать OAuth-токен Яндекса.' );
			}
			$host = self::discover_yandex_host();
			if ( is_wp_error( $host ) ) {
				$settings = self::get_settings();
				$settings['encrypted_yandex_access_token']  = '';
				$settings['encrypted_yandex_refresh_token'] = '';
				$settings['yandex_token_expires_at']        = 0;
				$settings['yandex_user_id']                 = '';
				$settings['yandex_host_id']                 = '';
				$settings['yandex_host_url']                = '';
				update_option( self::SETTINGS_OPTION, $settings, false );
				self::redirect_admin( 'Яндекс отклонил токен или сайт не найден: ' . $host->get_error_message() );
			}
			$settings                    = self::get_settings();
			$settings['yandex_user_id']  = (string) $host['user_id'];
			$settings['yandex_host_id']  = (string) $host['host_id'];
			$settings['yandex_host_url'] = (string) $host['host_url'];
			update_option( self::SETTINGS_OPTION, $settings, false );
			self::reschedule_events( $settings );
			$report = self::run_yandex_check();
			self::remember_yandex_region_state( $report, false );
			self::redirect_admin( 'OAuth-токен проверен. Яндекс Вебмастер подключён, первая проверка выполнена.' );
		}
		self::reschedule_events( $settings );
		$notice = 'Настройки Яндекс Вебмастера сохранены.';
		if ( ! self::yandex_is_connected( $settings ) ) {
			if ( '' === $client_id || '' === self::yandex_client_secret( $settings ) ) {
				$notice .= ' Для подключения вставьте готовый OAuth-токен либо укажите ClientID и Client secret.';
			} else {
				$notice .= ' Теперь нажмите «Подключить Яндекс».';
			}
		}
		self::redirect_admin( $notice );
	}

	public static function handle_admin_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'seo_monitor_action' );
		$action   = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
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
					self::broadcast( "✅ Тестовое сообщение\nSEO Monitor подключён." );
					$notice = 'Тестовое сообщение отправлено.';
				}
				break;
			case 'run_yandex':
				$report = self::run_yandex_check();
				self::remember_yandex_region_state( $report, false );
				$notice = 'Проверка Яндекс Вебмастера завершена: ' . strtoupper( (string) ( $report['status'] ?? 'warning' ) ) . '.';
				break;
			case 'run_region':
				$report = self::run_yandex_check();
				self::remember_yandex_region_state( $report, false );
				$notice = 'Проверка региональности завершена: ' . self::yandex_region_status_name( (string) ( $report['region_status'] ?? 'unknown' ) ) . '.';
				break;
			case 'disconnect_yandex':
				$settings['encrypted_yandex_access_token']  = '';
				$settings['encrypted_yandex_refresh_token'] = '';
				$settings['yandex_token_expires_at']        = 0;
				$settings['yandex_user_id']                 = '';
				$settings['yandex_host_id']                 = '';
				$settings['yandex_host_url']                = '';
				update_option( self::SETTINGS_OPTION, $settings, false );
				delete_option( self::LAST_YANDEX_REPORT_OPTION );
				delete_option( self::YANDEX_ALERT_STATE_OPTION );
				delete_option( self::YANDEX_REGION_STATE_OPTION );
				self::reschedule_events( $settings );
				$notice = 'Яндекс Вебмастер отключён. ClientID и Client secret сохранены для повторного подключения.';
				break;
		}
		self::redirect_admin( $notice );
	}

	private static function redirect_admin( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'seo-monitor', 'notice' => $notice ), admin_url( 'options-general.php' ) ) );
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

	private static function set_bot_commands() {
		$commands = array(
			array( 'command' => 'start', 'description' => 'Подключиться к SEO-отчётам' ),
			array( 'command' => 'status', 'description' => 'Проверить состояние сайта' ),
			array( 'command' => 'report', 'description' => 'Получить последний SEO-отчёт' ),
			array( 'command' => 'yandex', 'description' => 'Проверить данные Яндекс Вебмастера' ),
			array( 'command' => 'region', 'description' => 'Проверить региональность сайта' ),
			array( 'command' => 'cyrillic', 'description' => 'Найти кириллицу в URL' ),
			array( 'command' => 'help', 'description' => 'Показать доступные команды' ),
		);
		return self::telegram_request(
			'setMyCommands',
			array( 'commands' => wp_json_encode( $commands, JSON_UNESCAPED_UNICODE ) )
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
			self::send_message( $chat_id, "✅ Вы подключены к SEO-отчётам.\n\n/status — текущее состояние\n/report — последний полный отчёт\n/yandex — данные Яндекс Вебмастера\n/region — региональность сайта\n/cyrillic — поиск кириллицы в URL\n/help — справка" );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		if ( ! $known ) {
			self::send_message( $chat_id, '🔒 Сначала подключитесь по ссылке-приглашению.' );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		switch ( $command ) {
			case '/status':
				$report = self::run_quick_check();
				$text = self::format_report( $report, true );
				$yandex_report = get_option( self::LAST_YANDEX_REPORT_OPTION, array() );
				if ( is_array( $yandex_report ) && $yandex_report ) {
					$text .= "\n\n" . self::format_yandex_compact( $yandex_report );
				}
				self::send_message( $chat_id, $text );
				break;
			case '/report':
				$report = get_option( self::LAST_REPORT_OPTION, array() );
				self::send_message( $chat_id, $report ? self::format_report( $report, false ) : 'Отчёт ещё не сформирован. Используйте /status.' );
				$yandex_report = get_option( self::LAST_YANDEX_REPORT_OPTION, array() );
				if ( is_array( $yandex_report ) && $yandex_report ) {
					self::send_message( $chat_id, self::format_yandex_report( $yandex_report ) );
				}
				break;
			case '/yandex':
				if ( ! self::yandex_is_connected() ) {
					self::send_message( $chat_id, 'Яндекс Вебмастер ещё не подключён в настройках плагина.' );
					break;
				}
				$args = array( $chat_id );
				if ( wp_next_scheduled( self::YANDEX_ON_DEMAND_HOOK, $args ) ) {
					self::send_message( $chat_id, '⏳ Проверка Яндекс Вебмастера уже выполняется.' );
				} else {
					wp_schedule_single_event( time() + 1, self::YANDEX_ON_DEMAND_HOOK, $args );
					self::send_message( $chat_id, '⏳ Запускаю проверку Яндекс Вебмастера. Отчёт придёт отдельным сообщением.' );
				}
				break;
			case '/region':
				if ( ! self::yandex_is_connected() ) {
					self::send_message( $chat_id, 'Яндекс Вебмастер ещё не подключён в настройках плагина.' );
					break;
				}
				$args = array( $chat_id );
				if ( wp_next_scheduled( self::YANDEX_REGION_ON_DEMAND_HOOK, $args ) ) {
					self::send_message( $chat_id, '⏳ Проверка региональности уже выполняется.' );
				} else {
					wp_schedule_single_event( time() + 1, self::YANDEX_REGION_ON_DEMAND_HOOK, $args );
					self::send_message( $chat_id, '⏳ Проверяю региональность по данным Яндекс Вебмастера. Результат придёт отдельным сообщением.' );
				}
				break;
			case '/cyrillic':
				self::send_message( $chat_id, self::format_cyrillic_report( self::find_cyrillic_urls() ) );
				break;
			case '/help':
			default:
				self::send_message( $chat_id, "SEO Monitor\n\n/status — быстрая проверка\n/report — последний полный отчёт\n/yandex — свежие данные Яндекс Вебмастера\n/region — проверить региональность сайта\n/cyrillic — найти кириллицу в URL из sitemap\n/help — список команд\n\nКритические ошибки, существенное падение индексации и изменение региональности отправляются автоматически." );
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
			return new WP_Error( 'no_token', 'Токен Telegram не настроен.' );
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
		return is_array( $decoded ) ? $decoded : new WP_Error( 'bad_telegram_response', 'Некорректный ответ Telegram.' );
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
			self::broadcast( "✅ SEO Monitor: критическая ошибка устранена.\nСайт снова проходит аварийную проверку." );
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
		if ( self::yandex_is_connected() ) {
			$yandex_report = self::run_yandex_check();
			self::remember_yandex_region_state( $yandex_report, false );
			self::broadcast( self::format_yandex_report( $yandex_report ) );
		}
	}

	public static function run_yandex_daily_check(): void {
		if ( ! self::yandex_is_connected() ) {
			return;
		}
		$report      = self::run_yandex_check();
		$issues      = isset( $report['issues'] ) && is_array( $report['issues'] ) ? $report['issues'] : array();
		$old_state   = get_option( self::YANDEX_ALERT_STATE_OPTION, array( 'fingerprint' => '', 'had_issues' => false ) );
		$fingerprint = md5( wp_json_encode( $issues ) );

		if ( $issues && ( empty( $old_state['had_issues'] ) || $fingerprint !== ( $old_state['fingerprint'] ?? '' ) ) ) {
			self::broadcast( "⚠️ Изменение в Яндекс Вебмастере\n\n" . self::format_yandex_report( $report ) );
		} elseif ( ! $issues && ! empty( $old_state['had_issues'] ) ) {
			self::broadcast( "✅ Проблемы Яндекс Вебмастера устранены.\nСайт снова проходит автоматическую проверку." );
		}

		update_option(
			self::YANDEX_ALERT_STATE_OPTION,
			array( 'fingerprint' => $fingerprint, 'had_issues' => ! empty( $issues ) ),
			false
		);
		self::remember_yandex_region_state( $report, true );
	}

	public static function run_yandex_on_demand( string $chat_id ): void {
		$subscribers = self::get_subscribers();
		if ( ! isset( $subscribers[ $chat_id ] ) || ! self::yandex_is_connected() ) {
			return;
		}
		$report = self::run_yandex_check();
		self::remember_yandex_region_state( $report, false );
		self::send_message( $chat_id, self::format_yandex_report( $report ) );
	}

	public static function run_yandex_region_on_demand( string $chat_id ): void {
		$subscribers = self::get_subscribers();
		if ( ! isset( $subscribers[ $chat_id ] ) || ! self::yandex_is_connected() ) {
			return;
		}
		$report = self::run_yandex_check();
		self::remember_yandex_region_state( $report, false );
		self::send_message( $chat_id, self::format_yandex_region_report( $report ) );
	}

	public static function run_yandex_scheduled_region_check(): void {
		$report = self::run_yandex_check();
		self::remember_yandex_region_state( $report, false );
		self::broadcast( "📅 Запланированная проверка региональности\n\n" . self::format_yandex_region_report( $report ) );

		$settings = self::get_settings();
		$settings['yandex_region_check_enabled'] = 0;
		update_option( self::SETTINGS_OPTION, $settings, false );
	}

	private static function remember_yandex_region_state( array $report, bool $notify_change ): void {
		$current = (string) ( $report['region_status'] ?? 'unknown' );
		$allowed = array( 'assigned', 'not_assigned', 'unknown' );
		if ( ! in_array( $current, $allowed, true ) ) {
			$current = 'unknown';
		}

		$previous = get_option( self::YANDEX_REGION_STATE_OPTION, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$previous_status = (string) ( $previous['status'] ?? '' );
		if ( $notify_change && 'unknown' !== $current && '' !== $previous_status && $previous_status !== $current ) {
			self::broadcast( "📍 Изменение региональности в Яндекс Вебмастере\n\n" . self::format_yandex_region_report( $report ) );
		}

		if ( 'unknown' === $current && '' !== $previous_status && 'unknown' !== $previous_status ) {
			$current = $previous_status;
		}
		update_option(
			self::YANDEX_REGION_STATE_OPTION,
			array(
				'status'     => $current,
				'checked_at' => (string) ( $report['created_at'] ?? gmdate( 'c' ) ),
			),
			false
		);
	}

	public static function run_yandex_check(): array {
		$settings = self::get_settings();
		$report   = array(
			'created_at'       => gmdate( 'c' ),
			'status'           => 'ok',
			'host_url'         => (string) $settings['yandex_host_url'],
			'verified'         => null,
			'host_data_status' => '',
			'region_status'    => 'unknown',
			'region_diagnostic_state' => '',
			'expected_region'  => (string) $settings['yandex_expected_region'],
			'in_search'        => null,
			'in_search_previous' => null,
			'sitemap_index_check_complete' => false,
			'sitemap_index_total'          => 0,
			'sitemap_indexed_count'        => 0,
			'sitemap_not_in_search'        => array(),
			'indexing'         => array(),
			'important_urls_count'      => 0,
			'important_urls_known'      => 0,
			'important_urls_searchable' => 0,
			'important_url_issues'      => array(),
			'diagnostics'      => array(),
			'sitemaps_count'   => 0,
			'sitemap_urls'     => 0,
			'sitemap_errors'   => 0,
			'token_expires_at' => (int) $settings['yandex_token_expires_at'],
			'issues'           => array(),
		);

		if ( ! self::yandex_is_connected( $settings ) ) {
			self::add_yandex_issue( $report, 'warning', 'Яндекс Вебмастер не подключён' );
			return $report;
		}

		$user_id = rawurlencode( (string) $settings['yandex_user_id'] );
		$host_id = rawurlencode( (string) $settings['yandex_host_id'] );
		$base    = '/v4/user/' . $user_id . '/hosts/' . $host_id;
		$host    = self::yandex_api_request( $base );
		if ( is_wp_error( $host ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось получить состояние сайта', $host );
			update_option( self::LAST_YANDEX_REPORT_OPTION, $report, false );
			return $report;
		}

		$report['verified']         = ! empty( $host['verified'] );
		$report['host_data_status'] = (string) ( $host['host_data_status'] ?? '' );
		$report['host_url']         = (string) ( $host['ascii_host_url'] ?? $host['unicode_host_url'] ?? $settings['yandex_host_url'] );
		if ( ! $report['verified'] ) {
			self::add_yandex_issue( $report, 'critical', 'Права на сайт не подтверждены в Яндекс Вебмастере' );
		}
		if ( 'OK' !== $report['host_data_status'] ) {
			$level = 'NOT_INDEXED' === $report['host_data_status'] ? 'critical' : 'warning';
			self::add_yandex_issue( $report, $level, 'Данные сайта в Яндексе недоступны', $report['host_data_status'] ?: 'статус не указан' );
		}

		$date_query = array(
			'date_from' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 * DAY_IN_SECONDS ),
			'date_to'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		$in_search = self::yandex_api_request( $base . '/search-urls/in-search/history', $date_query );
		if ( is_wp_error( $in_search ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось получить количество страниц в поиске', $in_search );
		} else {
			$values = self::latest_yandex_points( (array) ( $in_search['history'] ?? array() ) );
			$report['in_search']          = $values['latest'];
			$report['in_search_previous'] = $values['previous'];
			if ( null !== $values['latest'] && 0 === $values['latest'] && 'OK' === $report['host_data_status'] ) {
				self::add_yandex_issue( $report, 'warning', 'В поиске Яндекса нет страниц сайта' );
			}
			if ( null !== $values['latest'] && null !== $values['previous'] && $values['previous'] >= 5 ) {
				$drop = $values['previous'] - $values['latest'];
				if ( $drop >= 5 && $values['latest'] <= (int) floor( $values['previous'] * 0.8 ) ) {
					$percent = (int) round( $drop / $values['previous'] * 100 );
					self::add_yandex_issue( $report, 'warning', 'Количество страниц в поиске существенно снизилось', $values['previous'] . ' → ' . $values['latest'] . ' (-' . $percent . '%)' );
				}
			}
		}

		$search_samples = self::yandex_in_search_urls( $base );
		if ( is_wp_error( $search_samples ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось сопоставить sitemap с поиском Яндекса', $search_samples );
		} else {
			if ( null === $report['in_search'] ) {
				$report['in_search'] = (int) $search_samples['total'];
			}
			if ( ! empty( $search_samples['complete'] ) ) {
				$sitemap_urls = self::sitemap_urls();
				$report['sitemap_index_check_complete'] = true;
				$report['sitemap_index_total'] = count( $sitemap_urls );
				$search_lookup = array_fill_keys( array_map( array( __CLASS__, 'normalize_url' ), (array) $search_samples['urls'] ), true );
				foreach ( $sitemap_urls as $url ) {
					if ( isset( $search_lookup[ self::normalize_url( $url ) ] ) ) {
						++$report['sitemap_indexed_count'];
					} else {
						$report['sitemap_not_in_search'][] = $url;
					}
				}
				if ( $report['sitemap_not_in_search'] ) {
					$examples = array_map( array( __CLASS__, 'display_url' ), array_slice( $report['sitemap_not_in_search'], 0, 3 ) );
					self::add_yandex_issue(
						$report,
						'warning',
						'URL из sitemap отсутствуют в поиске Яндекса',
						'Всего: ' . count( $report['sitemap_not_in_search'] ) . '. Примеры: ' . implode( '; ', $examples )
					);
				}
			}
		}

		$important_urls = self::yandex_api_request( $base . '/important-urls' );
		if ( is_wp_error( $important_urls ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось проверить важные страницы', $important_urls );
		} else {
			$items = (array) ( $important_urls['urls'] ?? array() );
			$report['important_urls_count'] = count( $items );
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$url             = (string) ( $item['url'] ?? '' );
				$indexing_status = isset( $item['indexing_status'] ) && is_array( $item['indexing_status'] ) ? $item['indexing_status'] : array();
				$search_status   = isset( $item['search_status'] ) && is_array( $item['search_status'] ) ? $item['search_status'] : array();
				$searchable      = array_key_exists( 'searchable', $search_status ) ? (bool) $search_status['searchable'] : null;
				if ( null !== $searchable ) {
					++$report['important_urls_known'];
				}
				if ( true === $searchable ) {
					++$report['important_urls_searchable'];
				}

				$robot_status = strtoupper( (string) ( $indexing_status['status'] ?? '' ) );
				if ( '' !== $robot_status && 'HTTP_2XX' !== $robot_status ) {
					$http_code = isset( $indexing_status['http_code'] ) ? 'HTTP ' . (int) $indexing_status['http_code'] : $robot_status;
					$problem = array( 'url' => $url, 'reason' => $http_code );
					$report['important_url_issues'][] = $problem;
					$level = in_array( $robot_status, array( 'HTTP_5XX', 'OTHER' ), true ) ? 'critical' : 'warning';
					self::add_yandex_issue( $report, $level, 'Яндекс-робот получил ошибку на важной странице', self::display_url( $url ) . ' — ' . $http_code );
				}
				if ( false === $searchable ) {
					$reason = strtoupper( (string) ( $search_status['excluded_url_status'] ?? 'NOT_IN_SEARCH' ) );
					$problem = array( 'url' => $url, 'reason' => $reason );
					$report['important_url_issues'][] = $problem;
					self::add_yandex_issue( $report, 'warning', 'Важная страница отсутствует в поиске Яндекса', self::display_url( $url ) . ' — ' . self::yandex_exclusion_name( $reason ) );
				}
			}
		}

		$indexing = self::yandex_api_request( $base . '/indexing/history', $date_query );
		if ( is_wp_error( $indexing ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось получить статистику обхода', $indexing );
		} else {
			foreach ( (array) ( $indexing['indicators'] ?? array() ) as $indicator => $points ) {
				$values = self::latest_yandex_points( is_array( $points ) ? $points : array() );
				if ( null !== $values['latest'] ) {
					$report['indexing'][ sanitize_key( (string) $indicator ) ] = $values['latest'];
				}
			}
			$error_pages = 0;
			foreach ( array( 'http_4xx', 'http_5xx', 'other', 'failed_to_download' ) as $indicator ) {
				$error_pages += (int) ( $report['indexing'][ $indicator ] ?? 0 );
			}
			if ( $error_pages > 0 ) {
				self::add_yandex_issue( $report, 'warning', 'Яндекс обнаружил ошибки при обходе страниц', 'URL с ошибками: ' . $error_pages );
			}
		}

		$diagnostics = self::yandex_api_request( $base . '/diagnostics' );
		if ( is_wp_error( $diagnostics ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось получить диагностику сайта', $diagnostics );
		} else {
			$diagnostic_problems = (array) ( $diagnostics['problems'] ?? array() );
			foreach ( $diagnostic_problems as $problem_code => $problem ) {
				if ( 'NO_REGIONS' === strtoupper( (string) $problem_code ) && is_array( $problem ) ) {
					$region_state = strtoupper( (string) ( $problem['state'] ?? 'UNDEFINED' ) );
					$report['region_diagnostic_state'] = $region_state;
					if ( 'PRESENT' === $region_state ) {
						$report['region_status'] = 'not_assigned';
					} elseif ( 'ABSENT' === $region_state ) {
						$report['region_status'] = 'assigned';
					}
				}
				if ( ! is_array( $problem ) || 'PRESENT' !== (string) ( $problem['state'] ?? '' ) ) {
					continue;
				}
				$severity = strtoupper( (string) ( $problem['severity'] ?? 'POSSIBLE_PROBLEM' ) );
				$report['diagnostics'][] = array(
					'code'     => sanitize_key( (string) $problem_code ),
					'severity' => $severity,
					'updated'  => sanitize_text_field( (string) ( $problem['last_state_update'] ?? '' ) ),
				);
			}
			$fatal = array_values( array_filter( $report['diagnostics'], static fn( $problem ) => in_array( $problem['severity'], array( 'FATAL', 'CRITICAL' ), true ) ) );
			$possible = array_values( array_filter( $report['diagnostics'], static fn( $problem ) => 'POSSIBLE_PROBLEM' === $problem['severity'] ) );
			if ( $fatal ) {
				self::add_yandex_issue( $report, 'critical', 'Критические проблемы в диагностике Яндекса', self::yandex_problem_codes( $fatal ) );
			}
			if ( $possible ) {
				self::add_yandex_issue( $report, 'warning', 'Возможные проблемы в диагностике Яндекса', self::yandex_problem_codes( $possible ) );
			}
			if ( 'not_assigned' === $report['region_status'] && 'ok' === $report['status'] ) {
				$report['status'] = 'warning';
			}
		}

		$sitemaps = self::yandex_api_request( $base . '/sitemaps', array( 'limit' => 100 ) );
		if ( is_wp_error( $sitemaps ) ) {
			self::add_yandex_api_issue( $report, 'Не удалось получить состояние Sitemap', $sitemaps );
		} else {
			$items = (array) ( $sitemaps['sitemaps'] ?? array() );
			$report['sitemaps_count'] = count( $items );
			foreach ( $items as $sitemap ) {
				if ( ! is_array( $sitemap ) ) {
					continue;
				}
				$report['sitemap_urls']   += (int) ( $sitemap['urls_count'] ?? 0 );
				$report['sitemap_errors'] += (int) ( $sitemap['errors_count'] ?? 0 );
			}
			if ( 0 === $report['sitemaps_count'] ) {
				self::add_yandex_issue( $report, 'warning', 'Яндекс не обнаружил Sitemap' );
			} elseif ( $report['sitemap_errors'] > 0 ) {
				self::add_yandex_issue( $report, 'warning', 'В Sitemap есть ошибки по данным Яндекса', 'Ошибок: ' . $report['sitemap_errors'] );
			}
		}

		$settings = self::get_settings();
		$refresh = self::decrypt_token( (string) $settings['encrypted_yandex_refresh_token'] );
		$expires = (int) $settings['yandex_token_expires_at'];
		$report['token_expires_at'] = $expires;
		if ( '' === $refresh && $expires > 0 && $expires <= time() + 14 * DAY_IN_SECONDS ) {
			$days = max( 0, (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );
			self::add_yandex_issue( $report, 'warning', 'Скоро истечёт OAuth-токен Яндекса', 'Осталось дней: ' . $days );
		}

		update_option( self::LAST_YANDEX_REPORT_OPTION, $report, false );
		return $report;
	}

	private static function latest_yandex_points( array $points ): array {
		$normalized = array();
		foreach ( $points as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['value'] ) ) {
				continue;
			}
			$timestamp = isset( $point['date'] ) ? strtotime( (string) $point['date'] ) : false;
			$normalized[] = array(
				'time'  => false === $timestamp ? 0 : $timestamp,
				'value' => (int) $point['value'],
			);
		}
		usort( $normalized, static fn( $a, $b ) => $a['time'] <=> $b['time'] );
		$count = count( $normalized );
		return array(
			'latest'   => $count ? $normalized[ $count - 1 ]['value'] : null,
			'previous' => $count > 1 ? $normalized[ $count - 2 ]['value'] : null,
		);
	}

	private static function yandex_in_search_urls( string $base ) {
		$urls   = array();
		$offset = 0;
		$total  = null;
		do {
			$response = self::yandex_api_request(
				$base . '/search-urls/in-search/samples',
				array( 'offset' => $offset, 'limit' => 100 )
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$total   = null === $total ? max( 0, (int) ( $response['count'] ?? 0 ) ) : $total;
			$samples = (array) ( $response['samples'] ?? array() );
			foreach ( $samples as $sample ) {
				if ( is_array( $sample ) && ! empty( $sample['url'] ) ) {
					$urls[] = esc_url_raw( (string) $sample['url'] );
				}
			}
			$fetched = count( $samples );
			$offset += $fetched;
		} while ( $fetched > 0 && $offset < $total && $offset < 500 );

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return array(
			'urls'     => $urls,
			'total'    => (int) $total,
			'complete' => $offset >= (int) $total,
		);
	}

	private static function add_yandex_issue( array &$report, string $level, string $label, string $details = '' ): void {
		$report['issues'][] = array( 'level' => $level, 'label' => $label, 'details' => $details );
		if ( 'critical' === $level ) {
			$report['status'] = 'critical';
		} elseif ( 'ok' === $report['status'] ) {
			$report['status'] = 'warning';
		}
	}

	private static function add_yandex_api_issue( array &$report, string $label, WP_Error $error ): void {
		$critical_codes = array( 'yandex_not_connected', 'yandex_token_expired', 'yandex_unauthorized', 'yandex_forbidden', 'yandex_credentials' );
		$level = in_array( $error->get_error_code(), $critical_codes, true ) ? 'critical' : 'warning';
		self::add_yandex_issue( $report, $level, $label, $error->get_error_message() );
	}

	private static function yandex_problem_codes( array $problems ): string {
		$codes = array_map(
			static fn( $problem ): string => strtoupper( str_replace( '_', ' ', (string) ( $problem['code'] ?? '' ) ) ),
			array_slice( $problems, 0, 5 )
		);
		$text = implode( ', ', array_filter( $codes ) );
		if ( count( $problems ) > 5 ) {
			$text .= ' и ещё ' . ( count( $problems ) - 5 );
		}
		return $text;
	}

	private static function yandex_exclusion_name( string $reason ): string {
		$names = array(
			'NOTHING_FOUND'          => 'страница не найдена роботом',
			'HOST_ERROR'             => 'ошибка доступа к сайту',
			'REDIRECT_NOTSEARCHABLE' => 'индексируется цель редиректа',
			'HTTP_ERROR'             => 'HTTP-ошибка',
			'NOT_CANONICAL'          => 'индексируется другой canonical',
			'NOT_MAIN_MIRROR'        => 'страница относится к неглавному зеркалу',
			'PARSER_ERROR'           => 'ошибка обработки страницы',
			'ROBOTS_HOST_ERROR'      => 'сайт закрыт в robots.txt',
			'ROBOTS_URL_ERROR'       => 'страница закрыта в robots.txt',
			'DUPLICATE'              => 'дубликат другой страницы',
			'LOW_QUALITY'            => 'исключена алгоритмом качества',
			'CLEAN_PARAMS'           => 'исключена директивой Clean-param',
			'NO_INDEX'               => 'закрыта meta robots noindex',
			'OTHER'                  => 'нет актуальных данных',
			'NOT_IN_SEARCH'          => 'не участвует в поиске',
		);
		return $names[ $reason ] ?? $reason;
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
			'cyrillic_url_count' => 0,
			'cyrillic_urls'       => array(),
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
		$cyrillic_urls = self::find_cyrillic_urls( $urls );
		$report['cyrillic_url_count'] = count( $cyrillic_urls );
		$report['cyrillic_urls']       = $cyrillic_urls;
		if ( $cyrillic_urls ) {
			$examples = array_map( array( __CLASS__, 'display_url' ), array_slice( $cyrillic_urls, 0, 3 ) );
			self::fail(
				$report,
				'warning',
				'Найдены URL с кириллицей',
				'Всего: ' . count( $cyrillic_urls ) . '. Примеры: ' . implode( '; ', $examples )
			);
		} else {
			self::pass( $report );
		}

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

	/**
	 * Возвращает URL из sitemap, в пути или параметрах которых есть кириллица
	 * в обычном или percent-encoded виде.
	 */
	private static function find_cyrillic_urls( ?array $urls = null ): array {
		$urls = null === $urls ? self::sitemap_urls() : $urls;
		return array_values(
			array_filter(
				$urls,
				static function ( $url ): bool {
					$decoded = rawurldecode( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					return 1 === preg_match( '/\p{Cyrillic}/u', $decoded );
				}
			)
		);
	}

	private static function display_url( string $url ): string {
		$decoded = rawurldecode( $url );
		return 1 === preg_match( '//u', $decoded ) ? $decoded : $url;
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
			'user-agent'  => 'SEO-Monitor/' . self::VERSION,
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
		$path = self::normalize_url_component( $path );
		$query = isset( $parts['query'] ) ? self::normalize_url_component( (string) $parts['query'] ) : '';
		return strtolower( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ) . $path . ( '' !== $query ? '?' . $query : '' );
	}

	private static function normalize_url_component( string $value ): string {
		// Яндекс может вернуть кириллицу как UTF-8 или percent-encoding. Оба
		// представления приводим к одному виду, не декодируя зарезервированные
		// символы вроде %2F.
		$value = preg_replace_callback(
			'/[^\x00-\x7F]+/u',
			static fn( array $match ): string => rawurlencode( $match[0] ),
			$value
		);
		// Регистр шестнадцатеричных символов в percent-encoding не меняет URL.
		return preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static fn( array $match ): string => strtoupper( $match[0] ),
			(string) $value
		);
	}

	private static function format_report( array $report, bool $short ): string {
		$status_icons = array( 'ok' => '✅', 'warning' => '⚠️', 'critical' => '🚨' );
		$status_names = array( 'ok' => 'Ошибок не обнаружено', 'warning' => 'Есть предупреждения', 'critical' => 'Обнаружены критические ошибки' );
		$status       = $report['status'] ?? 'warning';
		$timestamp    = isset( $report['created_at'] ) ? strtotime( $report['created_at'] ) : time();
		$date         = wp_date( 'd.m.Y H:i', $timestamp, new DateTimeZone( 'Europe/Moscow' ) );
		$text         = ( $status_icons[ $status ] ?? 'ℹ️' ) . ' SEO-отчёт' . "\n";
		$text        .= 'Статус: ' . ( $status_names[ $status ] ?? $status ) . "\n";
		$text        .= 'Проверено: ' . (int) ( $report['checked'] ?? 0 ) . ', успешно: ' . (int) ( $report['passed'] ?? 0 ) . "\n";
		if ( ! $short && ! empty( $report['url_count'] ) ) {
			$text .= 'URL в проверке: ' . (int) $report['url_count'] . "\n";
			$text .= 'URL с кириллицей: ' . (int) ( $report['cyrillic_url_count'] ?? 0 ) . "\n";
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

	private static function format_yandex_compact( array $report ): string {
		$status_icons = array( 'ok' => '✅', 'warning' => '⚠️', 'critical' => '🚨' );
		$status       = (string) ( $report['status'] ?? 'warning' );
		$in_search    = $report['in_search'] ?? null;
		$timestamp    = isset( $report['created_at'] ) ? strtotime( (string) $report['created_at'] ) : false;
		$date         = false === $timestamp ? 'нет даты' : wp_date( 'd.m.Y H:i', $timestamp, new DateTimeZone( 'Europe/Moscow' ) ) . ' МСК';
		$text         = ( $status_icons[ $status ] ?? 'ℹ️' ) . ' Яндекс Вебмастер: ';
		$text        .= null === $in_search ? 'нет данных о страницах в поиске' : 'страниц в поиске — ' . (int) $in_search;
		$text        .= '; региональность — ' . self::yandex_region_status_name( (string) ( $report['region_status'] ?? 'unknown' ) );
		$text        .= ' (' . $date . ')';
		return $text;
	}

	private static function format_yandex_report( array $report ): string {
		$status_icons = array( 'ok' => '✅', 'warning' => '⚠️', 'critical' => '🚨' );
		$status_names = array( 'ok' => 'всё в норме', 'warning' => 'есть предупреждения', 'critical' => 'есть критические проблемы' );
		$status       = (string) ( $report['status'] ?? 'warning' );
		$timestamp    = isset( $report['created_at'] ) ? strtotime( (string) $report['created_at'] ) : false;
		$date         = false === $timestamp ? 'не указана' : wp_date( 'd.m.Y H:i', $timestamp, new DateTimeZone( 'Europe/Moscow' ) ) . ' МСК';
		$text         = ( $status_icons[ $status ] ?? 'ℹ️' ) . " Яндекс Вебмастер\n";
		$text        .= 'Статус: ' . ( $status_names[ $status ] ?? $status ) . "\n";
		if ( null !== ( $report['verified'] ?? null ) ) {
			$text .= 'Права подтверждены: ' . ( ! empty( $report['verified'] ) ? 'да' : 'нет' ) . "\n";
		}
		if ( ! empty( $report['host_data_status'] ) ) {
			$text .= 'Индексирование сайта: ' . self::yandex_host_status_name( (string) $report['host_data_status'] ) . "\n";
		}
		$text .= 'Региональность: ' . self::yandex_region_status_name( (string) ( $report['region_status'] ?? 'unknown' ) );
		if ( ! empty( $report['expected_region'] ) ) {
			$text .= ' (ожидается: ' . (string) $report['expected_region'] . ')';
		}
		$text .= "\n";

		$in_search = $report['in_search'] ?? null;
		if ( null !== $in_search ) {
			$text .= 'Страниц в поиске: ' . (int) $in_search;
			$previous = $report['in_search_previous'] ?? null;
			if ( null !== $previous && (int) $previous !== (int) $in_search ) {
				$delta = (int) $in_search - (int) $previous;
				$text .= ' (' . ( $delta > 0 ? '+' : '' ) . $delta . ' к предыдущему обновлению)';
			}
			$text .= "\n";
		}
		if ( ! empty( $report['sitemap_index_check_complete'] ) ) {
			$text .= 'URL из sitemap в поиске: ' . (int) ( $report['sitemap_indexed_count'] ?? 0 ) . ' из ' . (int) ( $report['sitemap_index_total'] ?? 0 ) . "\n";
		}
		if ( ! empty( $report['important_urls_count'] ) ) {
			$known = (int) ( $report['important_urls_known'] ?? 0 );
			$text .= 'Важные страницы: ';
			$text .= $known ? 'в поиске ' . (int) ( $report['important_urls_searchable'] ?? 0 ) . ' из ' . $known : 'статус поиска ещё не получен';
			if ( $known !== (int) $report['important_urls_count'] ) {
				$text .= ', отслеживается — ' . (int) $report['important_urls_count'];
			}
			$important_issues = isset( $report['important_url_issues'] ) && is_array( $report['important_url_issues'] ) ? count( $report['important_url_issues'] ) : 0;
			if ( $important_issues ) {
				$text .= ', проблем — ' . $important_issues;
			}
			$text .= "\n";
		}

		$indexing = isset( $report['indexing'] ) && is_array( $report['indexing'] ) ? $report['indexing'] : array();
		if ( $indexing ) {
			$text .= 'Обход: 2xx — ' . (int) ( $indexing['http_2xx'] ?? 0 );
			$text .= ', 3xx — ' . (int) ( $indexing['http_3xx'] ?? 0 );
			$text .= ', 4xx — ' . (int) ( $indexing['http_4xx'] ?? 0 );
			$text .= ', 5xx — ' . (int) ( $indexing['http_5xx'] ?? 0 );
			if ( isset( $indexing['other'] ) ) {
				$text .= ', прочие ошибки — ' . (int) $indexing['other'];
			}
			if ( isset( $indexing['failed_to_download'] ) ) {
				$text .= ', не загружено — ' . (int) $indexing['failed_to_download'];
			}
			$text .= "\n";
		}

		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		if ( $diagnostics ) {
			$counts = array( 'FATAL' => 0, 'CRITICAL' => 0, 'POSSIBLE_PROBLEM' => 0, 'RECOMMENDATION' => 0 );
			foreach ( $diagnostics as $problem ) {
				$severity = (string) ( $problem['severity'] ?? '' );
				if ( isset( $counts[ $severity ] ) ) {
					++$counts[ $severity ];
				}
			}
			$text .= 'Диагностика: фатальных — ' . $counts['FATAL'] . ', критичных — ' . $counts['CRITICAL'] . ', возможных — ' . $counts['POSSIBLE_PROBLEM'] . ', рекомендаций — ' . $counts['RECOMMENDATION'] . "\n";
		} else {
			$text .= "Диагностика: активных проблем нет\n";
		}

		if ( isset( $report['sitemaps_count'] ) ) {
			$text .= 'Sitemap: файлов — ' . (int) $report['sitemaps_count'] . ', URL — ' . (int) ( $report['sitemap_urls'] ?? 0 ) . ', ошибок — ' . (int) ( $report['sitemap_errors'] ?? 0 ) . "\n";
		}
		$text .= 'Дата данных: ' . $date . "\n";

		$issues = isset( $report['issues'] ) && is_array( $report['issues'] ) ? $report['issues'] : array();
		if ( $issues ) {
			$text .= "\nТребует внимания:\n";
			foreach ( array_slice( $issues, 0, 10 ) as $issue ) {
				$icon  = 'critical' === ( $issue['level'] ?? '' ) ? '🔴' : '🟡';
				$text .= $icon . ' ' . (string) ( $issue['label'] ?? '' );
				if ( ! empty( $issue['details'] ) ) {
					$text .= ': ' . (string) $issue['details'];
				}
				$text .= "\n";
			}
			if ( count( $issues ) > 10 ) {
				$text .= '…и ещё ' . ( count( $issues ) - 10 ) . "\n";
			}
		} elseif ( 'not_assigned' === (string) ( $report['region_status'] ?? '' ) ) {
			$text .= "\nДругих проблем по данным Яндекс Вебмастера не обнаружено. Региональность ещё не назначена.";
		} else {
			$text .= "\nПроблем по данным Яндекс Вебмастера не обнаружено.";
		}
		return self::truncate( trim( $text ), 4000 );
	}

	private static function yandex_host_status_name( string $status ): string {
		$names = array(
			'OK'          => 'данные доступны',
			'NOT_INDEXED' => 'сайт ещё не проиндексирован',
			'NOT_LOADED'  => 'данные ещё не загружены',
		);
		return $names[ $status ] ?? $status;
	}

	private static function yandex_region_status_name( string $status ): string {
		$names = array(
			'assigned'     => 'признак «регион не задан» отсутствует',
			'not_assigned' => 'регион пока не назначен',
			'unknown'      => 'статус не определён',
		);
		return $names[ $status ] ?? $names['unknown'];
	}

	private static function format_yandex_region_report( array $report ): string {
		$status    = (string) ( $report['region_status'] ?? 'unknown' );
		$expected  = trim( (string) ( $report['expected_region'] ?? self::get_settings()['yandex_expected_region'] ) );
		$timestamp = isset( $report['created_at'] ) ? strtotime( (string) $report['created_at'] ) : false;
		$date      = false === $timestamp ? 'не указана' : wp_date( 'd.m.Y H:i', $timestamp, new DateTimeZone( 'Europe/Moscow' ) ) . ' МСК';
		$icons     = array( 'assigned' => '✅', 'not_assigned' => '⚠️', 'unknown' => 'ℹ️' );

		$text  = ( $icons[ $status ] ?? 'ℹ️' ) . " Региональность\n";
		$text .= 'Ожидаемый регион: ' . ( $expected ?: 'не указан' ) . "\n";
		$text .= 'Статус: ' . self::yandex_region_status_name( $status ) . "\n";
		if ( 'assigned' === $status ) {
			$text .= "Яндекс больше не сообщает проблему «регион не задан». Точное название назначенного региона подтвердите в интерфейсе Вебмастера.\n";
		} elseif ( 'not_assigned' === $status ) {
			$text .= "В диагностике Яндекса всё ещё присутствует проблема NO_REGIONS.\n";
		} else {
			$text .= "API не вернул однозначный статус NO_REGIONS. Проверьте региональность вручную в интерфейсе Вебмастера.\n";
		}
		$text .= 'Дата проверки: ' . $date;
		return self::truncate( $text, 4000 );
	}

	private static function format_cyrillic_report( array $urls ): string {
		if ( ! $urls ) {
			return "✅ Кириллические URL не найдены.\nПроверены адреса из sitemap";
		}

		$text  = '⚠️ Найдены URL с кириллицей: ' . count( $urls ) . "\n\n";
		$limit = 15;
		foreach ( array_slice( $urls, 0, $limit ) as $url ) {
			$text .= '• ' . self::display_url( (string) $url ) . "\n";
		}
		if ( count( $urls ) > $limit ) {
			$text .= '…и ещё ' . ( count( $urls ) - $limit ) . "\n";
		}
		$text .= "\nКириллица в URL допустима, но такие адреса стоит проверить и при необходимости заменить на транслитерацию с настройкой 301-редиректов.";
		return self::truncate( trim( $text ), 4000 );
	}

	private static function truncate( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}

register_activation_hook( __FILE__, array( 'SEO_Monitor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SEO_Monitor', 'deactivate' ) );
SEO_Monitor::bootstrap();
