<?php
/**
 * IP 単位の失敗回数ロック（総当たり対策）。
 *
 * docs/spec.md 7。失敗回数を transient に記録し、規定回数に達したら
 * 一定時間ロックする。ロック解除の管理画面 UI は別 issue のスコープ。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IP 単位の失敗回数ロック。
 */
class Pggd_Lockout {

	/**
	 * 失敗回数を数える transient のキー接頭辞。
	 */
	const PREFIX_FAILURES = 'pggd_fail_';

	/**
	 * ロック状態を保持する transient のキー接頭辞。
	 */
	const PREFIX_LOCK = 'pggd_lock_';

	/**
	 * 接続元 IP アドレスを返す。
	 *
	 * REMOTE_ADDR だけを見る。X-Forwarded-For などのリクエストヘッダは
	 * 送信側が自由に書けるため、そのまま鍵にすると
	 * (1) 値を変えるだけでロックを無限に回避でき、
	 * (2) 他人の IP を名乗って第三者をロックさせられる。
	 * リバースプロキシ配下で別の値を使いたい場合は、
	 * どのヘッダを信用するかをサイト側が明示できる pggd_client_ip フィルターで差し替える。
	 *
	 * @return string IP アドレス。取得できない場合は空文字。
	 */
	public static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		// 書式が IP として妥当なものだけを採用する。
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		/**
		 * 接続元 IP アドレスを差し替えるフィルター。
		 *
		 * 信頼できるリバースプロキシ配下でのみ使うこと。
		 *
		 * @param string $ip REMOTE_ADDR から取得した IP アドレス。
		 */
		$ip = (string) apply_filters( 'pggd_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * IP から transient のキーを組み立てる。
	 *
	 * IP をそのままキーに使うと文字種（IPv6 のコロン）と長さの点で扱いにくく、
	 * options テーブルを覗いただけで接続元の一覧が読めてしまう。
	 * ソルト付きハッシュの先頭32文字にして固定長にする。
	 *
	 * @param string $prefix キーの接頭辞。
	 * @param string $ip     IP アドレス。
	 * @return string transient のキー。
	 */
	private static function build_key( $prefix, $ip ) {
		// IP が取れない環境（CLI 等）は一つのバケットにまとめる。
		$source = '' !== $ip ? $ip : 'unknown';
		return $prefix . substr( hash( 'sha256', $source . '|' . wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * 現在ロック中かを返す。
	 *
	 * @param string $ip IP アドレス。
	 * @return bool ロック中なら true。
	 */
	public static function is_locked( $ip ) {
		return false !== get_transient( self::build_key( self::PREFIX_LOCK, $ip ) );
	}

	/**
	 * ロックが解ける UNIX time を返す。
	 *
	 * @param string $ip IP アドレス。
	 * @return int 解除時刻。ロックしていなければ 0。
	 */
	public static function get_unlock_time( $ip ) {
		$lock = get_transient( self::build_key( self::PREFIX_LOCK, $ip ) );
		if ( ! is_array( $lock ) || ! isset( $lock['until'] ) ) {
			return 0;
		}
		return (int) $lock['until'];
	}

	/**
	 * 認証失敗を1回記録する。
	 *
	 * 規定回数に達したらロックを立て、失敗回数は捨てる。
	 * ロック中はこのメソッドを呼ばない（呼ぶとロックが延び続けてしまうため）。
	 *
	 * @param string $ip IP アドレス。
	 * @return bool この失敗でロック状態になったら true。
	 */
	public static function record_failure( $ip ) {
		$max     = pggd_get_max_attempts();
		$seconds = pggd_get_lockout_seconds();

		$fail_key  = self::build_key( self::PREFIX_FAILURES, $ip );
		$failures  = (int) get_transient( $fail_key );
		$failures += 1;

		if ( $failures >= $max ) {
			set_transient(
				self::build_key( self::PREFIX_LOCK, $ip ),
				array(
					// 将来の「ロック中 IP の解除」画面で人が読めるように IP も持たせる。
					'ip'    => $ip,
					'until' => time() + $seconds,
				),
				$seconds
			);
			// ロック解除後は 0 から数え直す。
			delete_transient( $fail_key );
			return true;
		}

		// 失敗の有効期間はロック時間と同じにする（この間に規定回数に達したらロック）。
		set_transient( $fail_key, $failures, $seconds );

		return false;
	}

	/**
	 * 失敗回数とロックを消す（認証成功時に呼ぶ）。
	 *
	 * @param string $ip IP アドレス。
	 * @return void
	 */
	public static function clear( $ip ) {
		delete_transient( self::build_key( self::PREFIX_FAILURES, $ip ) );
		delete_transient( self::build_key( self::PREFIX_LOCK, $ip ) );
	}
}
