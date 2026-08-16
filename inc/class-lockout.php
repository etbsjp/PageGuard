<?php
/**
 * IP 単位の失敗回数ロック（総当たり対策）。
 *
 * docs/spec.md 7。ロック解除の管理画面 UI は別 issue のスコープ。
 *
 * ■ 記録の持ち方
 *
 * 送信元ごとに transient を作る方式はやめ、件数上限つきの1本のオプション
 * （pggd_lockouts / autoload 無効）へ集約している。理由は2つ。
 *
 *  1. 未認証のリクエストだけで options の行を無制限に増やせてしまう。
 *     送信元を変えながら失敗を繰り返すと、期限切れの掃除（1日2回の cron）が
 *     追いつかず行が積み上がる。1本にまとめて上限を設ければ頭打ちになる。
 *  2. 後続 issue の「ロック中 IP の一覧・解除」画面が、この配列を読むだけで作れる。
 *
 * ■ 保存形式
 *
 *  array(
 *    '<送信元のハッシュ>' => array(
 *      'ip'       => '203.0.113.9', // 解除画面で人が読むために持つ
 *      'failures' => 3,             // 現在の失敗回数（ロック成立時に 0 へ戻す）
 *      'locked'   => 0,             // ロックが解ける UNIX time。0 ならロックしていない
 *      'expires'  => 1760000900,    // このレコードを捨ててよくなる UNIX time
 *    ),
 *  )
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
	 * 記録を格納するオプション名。
	 */
	const OPTION = 'pggd_lockouts';

	/**
	 * 保持するレコードの上限件数。
	 *
	 * 超えた場合はロックしていないものから捨てる。
	 * 1レコードは 100 バイト前後なので、上限に達しても数十 KB に収まる。
	 */
	const MAX_ENTRIES = 500;

	/**
	 * 書き込み競合時にやり直す回数。
	 */
	const WRITE_RETRIES = 5;

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
	 * IP からレコードのキーを組み立てる。
	 *
	 * IP をそのままキーにすると、オプションの中身を覗いただけで
	 * 接続元の一覧が読めてしまう。ソルト付きハッシュで固定長にする。
	 *
	 * @param string $ip IP アドレス。
	 * @return string レコードのキー。
	 */
	private static function build_key( $ip ) {
		// IP が取れない環境（CLI 等）は一つのバケットにまとめる。
		$source = '' !== $ip ? $ip : 'unknown';
		return substr( hash( 'sha256', $source . '|' . wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * 保存されている生の値（シリアライズ済み文字列）を返す。
	 *
	 * 書き込みの競合検出に使うため、オブジェクトキャッシュを経由せず
	 * 必ずデータベースの現在値を読む。
	 *
	 * @return string|null 生の値。行が無ければ null。
	 */
	private static function read_raw() {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);

		return ( null === $raw ) ? null : (string) $raw;
	}

	/**
	 * 生の値をレコード配列に戻す。
	 *
	 * @param string|null $raw 生の値。
	 * @return array レコード配列。
	 */
	private static function decode( $raw ) {
		if ( null === $raw ) {
			return array();
		}
		$value = maybe_unserialize( $raw );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * レコード配列を書き戻す。
	 *
	 * 読み込んだ時点の値と現在の値が一致するときだけ更新する
	 * （compare-and-swap）。一致しなければ他のリクエストが先に書いた
	 * ということなので false を返し、呼び出し側でやり直す。
	 * 判定と更新は 1 本の UPDATE 文の中で行われるため、
	 * PHP 側で読んでから書くまでの隙間に割り込まれても取りこぼさない。
	 *
	 * @param string|null $previous_raw 読み込んだ時点の生の値。
	 * @param array       $records      書き込むレコード配列。
	 * @return bool 書き込めたら true。競合したら false。
	 */
	private static function write( $previous_raw, $records ) {
		global $wpdb;

		$new_raw = maybe_serialize( $records );

		if ( null === $previous_raw ) {
			// 行がまだ無い場合。autoload を無効にして追加する。
			// ここだけは競合しても上書きになる（初回の1回ぶんの失敗記録が
			// 失われうる）が、0件から1件目を書く瞬間に限られる。
			$added = add_option( self::OPTION, $records, '', false );
			if ( ! $added ) {
				return false; // 別のリクエストが先に作った。やり直す。
			}
			return true;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				self::OPTION,
				$previous_raw
			)
		);

		// 書き込む内容が読み込んだ時点とまったく同じ場合、
		// MySQL は「変更なし」として 0 行を返す。これは競合ではないので成功扱いにする。
		if ( $updated < 1 && $new_raw !== $previous_raw ) {
			return false;
		}

		// 直接 UPDATE したので、オプションのキャッシュを捨てて読み直させる。
		wp_cache_delete( self::OPTION, 'options' );

		return true;
	}

	/**
	 * 期限切れのレコードを捨て、件数を上限内に収める。
	 *
	 * @param array $records レコード配列。
	 * @return array 整理後のレコード配列。
	 */
	private static function prune( $records ) {
		$now = time();

		foreach ( $records as $key => $record ) {
			if ( ! is_array( $record ) || ! isset( $record['expires'] ) ) {
				unset( $records[ $key ] );
				continue;
			}
			if ( (int) $record['expires'] <= $now ) {
				unset( $records[ $key ] );
			}
		}

		if ( count( $records ) <= self::MAX_ENTRIES ) {
			return $records;
		}

		// 上限を超えたら、ロック中でないものから先に捨てる。
		// ロック中のレコードを消すと総当たりが再開できてしまうため、
		// こちらを優先して残す。
		$locked   = array();
		$unlocked = array();
		foreach ( $records as $key => $record ) {
			if ( isset( $record['locked'] ) && (int) $record['locked'] > $now ) {
				$locked[ $key ] = $record;
			} else {
				$unlocked[ $key ] = $record;
			}
		}

		// 期限が近いものから捨てる。
		uasort(
			$unlocked,
			function ( $a, $b ) {
				return (int) $a['expires'] - (int) $b['expires'];
			}
		);
		while ( ( count( $locked ) + count( $unlocked ) ) > self::MAX_ENTRIES && ! empty( $unlocked ) ) {
			reset( $unlocked );
			unset( $unlocked[ key( $unlocked ) ] );
		}

		// ロック中だけで上限を超える場合は、解除が近いものから捨てる。
		uasort(
			$locked,
			function ( $a, $b ) {
				return (int) $a['locked'] - (int) $b['locked'];
			}
		);
		while ( count( $locked ) > self::MAX_ENTRIES && ! empty( $locked ) ) {
			reset( $locked );
			unset( $locked[ key( $locked ) ] );
		}

		// キーは重複しないので、そのまま結合してよい。
		return $locked + $unlocked;
	}

	/**
	 * 送信元のレコードを1件返す。
	 *
	 * @param string $ip IP アドレス。
	 * @return array|null レコード。無ければ null。
	 */
	private static function get_record( $ip ) {
		$records = self::decode( self::read_raw() );
		$key     = self::build_key( $ip );

		if ( ! isset( $records[ $key ] ) || ! is_array( $records[ $key ] ) ) {
			return null;
		}
		if ( (int) $records[ $key ]['expires'] <= time() ) {
			return null;
		}

		return $records[ $key ];
	}

	/**
	 * 現在ロック中かを返す。
	 *
	 * @param string $ip IP アドレス。
	 * @return bool ロック中なら true。
	 */
	public static function is_locked( $ip ) {
		$record = self::get_record( $ip );
		if ( null === $record ) {
			return false;
		}
		return isset( $record['locked'] ) && (int) $record['locked'] > time();
	}

	/**
	 * ロックが解ける UNIX time を返す。
	 *
	 * @param string $ip IP アドレス。
	 * @return int 解除時刻。ロックしていなければ 0。
	 */
	public static function get_unlock_time( $ip ) {
		$record = self::get_record( $ip );
		if ( null === $record || ! isset( $record['locked'] ) ) {
			return 0;
		}
		$locked = (int) $record['locked'];
		return $locked > time() ? $locked : 0;
	}

	/**
	 * 認証失敗を1回記録する。
	 *
	 * 規定回数に達したらロックを立て、失敗回数は 0 に戻す。
	 * ロック中は何も書き込まない（書くとロックが延び続けて事実上の恒久ロックになる）。
	 *
	 * @param string $ip IP アドレス。
	 * @return bool 呼び出し後にロック状態なら true。
	 */
	public static function record_failure( $ip ) {
		$max     = pggd_get_max_attempts();
		$seconds = pggd_get_lockout_seconds();
		$key     = self::build_key( $ip );

		for ( $attempt = 0; $attempt < self::WRITE_RETRIES; $attempt++ ) {
			$now     = time();
			$raw     = self::read_raw();
			$records = self::prune( self::decode( $raw ) );

			$record = isset( $records[ $key ] ) && is_array( $records[ $key ] )
				? $records[ $key ]
				: array(
					'ip'       => $ip,
					'failures' => 0,
					'locked'   => 0,
					'expires'  => 0,
				);

			// 既にロック中なら数え直さない。
			if ( isset( $record['locked'] ) && (int) $record['locked'] > $now ) {
				return true;
			}

			$failures = (int) $record['failures'] + 1;
			$locked   = 0;

			if ( $failures >= $max ) {
				$locked   = $now + $seconds;
				$failures = 0; // ロック解除後は 0 から数え直す。
			}

			$records[ $key ] = array(
				'ip'       => $ip,
				'failures' => $failures,
				'locked'   => $locked,
				// ロック中はロックが解けるまで、そうでなければ失敗の計上期間ぶん保持する。
				'expires'  => $locked > 0 ? $locked : $now + $seconds,
			);

			if ( self::write( $raw, $records ) ) {
				return $locked > 0;
			}
		}

		// 規定回数やり直しても書けなかった＝同じ瞬間に多数のリクエストが
		// 競合している状態。この1回ぶんの計上は諦める（他のリクエストが
		// 計上しているため、回数が丸ごと失われるわけではない）。
		return false;
	}

	/**
	 * 送信元の記録を消す（認証成功時に呼ぶ）。
	 *
	 * @param string $ip IP アドレス。
	 * @return void
	 */
	public static function clear( $ip ) {
		$key = self::build_key( $ip );

		for ( $attempt = 0; $attempt < self::WRITE_RETRIES; $attempt++ ) {
			$raw     = self::read_raw();
			$records = self::decode( $raw );

			if ( ! isset( $records[ $key ] ) ) {
				return; // 記録が無いので何もしなくてよい。
			}

			unset( $records[ $key ] );
			$records = self::prune( $records );

			if ( self::write( $raw, $records ) ) {
				return;
			}
		}
	}
}
