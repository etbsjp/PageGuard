<?php
/**
 * アクセス元単位の失敗回数ロック（総当たり対策）。
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
 * ■ 失敗回数はページごとに数える
 *
 * 回数をアクセス元ごとに1つだけ持つと、認証成功で記録を消す処理と噛み合って
 * 制限を回避できてしまう。このプラグインは「ページごとに違う資格情報を配る」
 * ものなので、次が現実に成立する。
 *
 *   1. ページ A の資格情報を正規に持っている人が
 *   2. ページ B へ誤ったパスワードで4回試し
 *   3. ページ A へ正しく認証して回数を 0 に戻し
 *   4. 2 へ戻る
 *
 * これでは 5 回目に到達せず、ページ B を無制限に試せる。
 * そのため回数は投稿IDごとに持ち、認証成功で消すのは
 * 「そのページぶんの回数」だけにしている。ロックはアクセス元全体に掛ける。
 *
 * ■ 保存形式
 *
 *  array(
 *    '<送信元のハッシュ>' => array(
 *      'ip'       => '203.0.113.9',   // 解除画面で人が読むために持つ
 *      'failures' => array( 12 => 3 ), // 投稿ID => 失敗回数
 *      'locked'   => 0,               // ロックが解ける UNIX time。0 ならロックしていない
 *      'expires'  => 1760000900,      // このレコードを捨ててよくなる UNIX time
 *    ),
 *  )
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * アクセス元単位の失敗回数ロック。
 */
class Pggd_Lockout {

	/**
	 * 記録を格納するオプション名。
	 */
	const OPTION = 'pggd_lockouts';

	/**
	 * 保持するレコードの上限件数（＝アクセス元の数）。
	 *
	 * 超えた場合はロックしていないものから捨てる。
	 * 未認証のリクエストだけで options の行を増やされないための蓋。
	 */
	const MAX_ENTRIES = 500;

	/**
	 * 1つのアクセス元について、同時に回数を数えるページ数の上限。
	 *
	 * 回数をページごとに持つとレコードが太りうるので上限を設ける。
	 * ただし超えた分を捨てると、多数のページを巡回して古い回数を
	 * 押し出す回避路になる。捨てずにロックする。
	 * これだけのページを短時間に失敗して回るのは正規の利用ではない。
	 */
	const MAX_POSTS_PER_SOURCE = 20;

	/**
	 * 書き込み競合時にやり直す回数。
	 */
	const WRITE_RETRIES = 5;

	/**
	 * write() の戻り値: 書き込めた。
	 */
	const WRITE_OK = 1;

	/**
	 * write() の戻り値: 他のリクエストが先に書いた（やり直せば通る）。
	 */
	const WRITE_CONFLICT = 0;

	/**
	 * write() の戻り値: データベース側の障害（やり直しても直らない）。
	 */
	const WRITE_DB_ERROR = -1;

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
	 * アクセス元を表す文字列に正規化する。
	 *
	 * IPv6 はアドレス1つを鍵にすると意味がない。
	 * 一般的な割り当ては /64 単位で、その中のアドレスは自由に変えられるため、
	 * アドレス全体で数えると 5 回制限を素通りできるうえ、
	 * 別アドレスを名乗り続けてレコードの上限を溢れさせ、
	 * 他のロック記録を押し出すこともできる。/64 に丸めて数える。
	 *
	 * @param string $ip IP アドレス。
	 * @return string 正規化した文字列。
	 */
	private static function normalize_ip( $ip ) {
		$ip = (string) $ip;

		if ( '' === $ip ) {
			// IP が取れない環境（CLI 等）は一つのバケットにまとめる。
			return 'unknown';
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip; // IPv4 はそのまま。
		}

		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}

		// 上位 64 ビットだけ残して下位を 0 で埋める。
		$prefix = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );

		return ( false === $prefix ) ? $ip : $prefix . '/64';
	}

	/**
	 * アクセス元からレコードのキーを組み立てる。
	 *
	 * IP をそのままキーにすると、オプションの中身を覗いただけで
	 * 接続元の一覧が読めてしまう。ソルト付きハッシュで固定長にする。
	 *
	 * @param string $ip IP アドレス。
	 * @return string レコードのキー。
	 */
	private static function build_key( $ip ) {
		return substr( hash( 'sha256', self::normalize_ip( $ip ) . '|' . wp_salt( 'auth' ) ), 0, 32 );
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
	 * ということなので WRITE_CONFLICT を返し、呼び出し側でやり直す。
	 * 判定と更新は 1 本の UPDATE 文の中で行われるため、
	 * PHP 側で読んでから書くまでの隙間に割り込まれても取りこぼさない。
	 *
	 * データベース側の障害は「やり直せば通る競合」とは別物なので区別する。
	 * 取り違えると、障害が続くあいだ黙って計上を諦め続け、
	 * 総当たり対策だけが誰にも気づかれずに無効化される。
	 *
	 * @param string|null $previous_raw 読み込んだ時点の生の値。
	 * @param array       $records      書き込むレコード配列。
	 * @return int WRITE_OK / WRITE_CONFLICT / WRITE_DB_ERROR のいずれか。
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
				return self::WRITE_CONFLICT; // 別のリクエストが先に作った。やり直す。
			}
			return self::WRITE_OK;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				self::OPTION,
				$previous_raw
			)
		);

		if ( false === $updated ) {
			// SQL の実行そのものに失敗している。やり直しても直らない。
			error_log( 'PageGuard: 総当たり対策の記録を更新できませんでした: ' . $wpdb->last_error );
			return self::WRITE_DB_ERROR;
		}

		// 書き込む内容が読み込んだ時点とまったく同じ場合、
		// MySQL は「変更なし」として 0 行を返す。これは競合ではないので成功扱いにする。
		if ( $updated < 1 && $new_raw !== $previous_raw ) {
			return self::WRITE_CONFLICT;
		}

		// 直接 UPDATE したので、オプションのキャッシュを捨てて読み直させる。
		// notoptions（「この名前のオプションは存在しない」という記録）も消しておく。
		// 残っていると、後から get_option() で読んだときに未設定と誤認されうる。
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return self::WRITE_OK;
	}

	/**
	 * レコード1件を、欠けた項目を補った形に整える。
	 *
	 * @param mixed  $record レコード。
	 * @param string $ip     アクセス元（新規作成時に使う）。
	 * @return array 整えたレコード。
	 */
	private static function normalize_record( $record, $ip ) {
		if ( ! is_array( $record ) ) {
			$record = array();
		}

		$failures = isset( $record['failures'] ) && is_array( $record['failures'] ) ? $record['failures'] : array();

		return array(
			'ip'       => isset( $record['ip'] ) ? (string) $record['ip'] : self::normalize_ip( $ip ),
			'failures' => $failures,
			'locked'   => isset( $record['locked'] ) ? (int) $record['locked'] : 0,
			'expires'  => isset( $record['expires'] ) ? (int) $record['expires'] : 0,
		);
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
	 * 有効なレコードをすべて返す。
	 *
	 * このクラスの外からロック状況を読むときは、必ずこのメソッドを使うこと。
	 * get_option() を直接呼ぶと、直接 UPDATE している都合で
	 * 古いキャッシュや期限切れのレコードを掴む可能性がある。
	 * 後続の設定画面（ロック中 IP の一覧・解除）もここを入り口にする。
	 *
	 * @return array レコード配列（キーは送信元のハッシュ）。
	 */
	public static function get_all_records() {
		return self::prune( self::decode( self::read_raw() ) );
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
		// 壊れた記録で警告を出さない。未認証アクセスのたびに警告が出ると、
		// WP_DEBUG_DISPLAY が有効な環境では 401 のヘッダより先に出力され、
		// 認証を要求できなくなる。
		if ( ! isset( $records[ $key ]['expires'] ) ) {
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
	 * ロックはアクセス元全体に掛かる（ページ単位ではない）。
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
	 * 回数は投稿IDごとに数え、規定回数に達したらアクセス元全体をロックする。
	 * ロック中は何も書き込まない（書くとロックが延び続けて事実上の恒久ロックになる）。
	 *
	 * @param string $ip      IP アドレス。
	 * @param int    $post_id 認証に失敗した投稿ID。
	 * @return bool 呼び出し後にロック状態なら true。
	 */
	public static function record_failure( $ip, $post_id ) {
		$max     = pggd_get_max_attempts();
		$seconds = pggd_get_lockout_seconds();
		$key     = self::build_key( $ip );
		$post_id = (int) $post_id;

		for ( $attempt = 0; $attempt < self::WRITE_RETRIES; $attempt++ ) {
			$now     = time();
			$raw     = self::read_raw();
			$records = self::prune( self::decode( $raw ) );

			$record = self::normalize_record(
				isset( $records[ $key ] ) ? $records[ $key ] : null,
				$ip
			);

			// 既にロック中なら数え直さない。
			if ( $record['locked'] > $now ) {
				return true;
			}

			$failures              = $record['failures'];
			$count                 = isset( $failures[ $post_id ] ) ? (int) $failures[ $post_id ] + 1 : 1;
			$failures[ $post_id ]  = $count;

			$locked = 0;
			if ( $count >= $max ) {
				$locked = $now + $seconds;
			} elseif ( count( $failures ) > self::MAX_POSTS_PER_SOURCE ) {
				// 短時間に多数のページを失敗して回るのは正規の利用ではない。
				// 古い回数を捨てて回避路を作るより、ここでロックするほうが安全。
				$locked = $now + $seconds;
			}

			if ( $locked > 0 ) {
				// ロック解除後は 0 から数え直す。
				$failures = array();
			}

			$records[ $key ] = array(
				'ip'       => self::normalize_ip( $ip ),
				'failures' => $failures,
				'locked'   => $locked,
				// ロック中はロックが解けるまで、そうでなければ失敗の計上期間ぶん保持する。
				'expires'  => $locked > 0 ? $locked : $now + $seconds,
			);

			$result = self::write( $raw, $records );

			if ( self::WRITE_OK === $result ) {
				return $locked > 0;
			}
			if ( self::WRITE_DB_ERROR === $result ) {
				// やり直しても直らない。記録済みのログを頼りに調査してもらう。
				return false;
			}
		}

		// 規定回数やり直しても書けなかった＝同じ瞬間に多数のリクエストが
		// 競合している状態。この1回ぶんの計上は諦める（他のリクエストが
		// 計上しているため、回数が丸ごと失われるわけではない）。
		return false;
	}

	/**
	 * そのページぶんの失敗回数を消す（認証成功時に呼ぶ）。
	 *
	 * 消すのは指定したページの回数だけで、他のページの回数もロックも残す。
	 * ここでレコードごと消すと、ページ A の資格情報を持っている人が
	 * ページ B の回数を好きなだけ 0 に戻せる（回避路になる）。
	 *
	 * @param string $ip      IP アドレス。
	 * @param int    $post_id 認証に成功した投稿ID。
	 * @return void
	 */
	public static function clear( $ip, $post_id ) {
		$key     = self::build_key( $ip );
		$post_id = (int) $post_id;

		for ( $attempt = 0; $attempt < self::WRITE_RETRIES; $attempt++ ) {
			$raw     = self::read_raw();
			$records = self::decode( $raw );

			if ( ! isset( $records[ $key ] ) ) {
				return; // 記録が無いので何もしなくてよい。
			}

			$record = self::normalize_record( $records[ $key ], $ip );

			if ( ! isset( $record['failures'][ $post_id ] ) ) {
				return; // このページぶんの回数は無い。書き込む必要がない。
			}

			unset( $record['failures'][ $post_id ] );

			if ( empty( $record['failures'] ) && $record['locked'] <= time() ) {
				// 数えるものもロックも無くなったレコードは残さない。
				unset( $records[ $key ] );
			} else {
				$records[ $key ] = $record;
			}

			$records = self::prune( $records );
			$result  = self::write( $raw, $records );

			if ( self::WRITE_OK === $result || self::WRITE_DB_ERROR === $result ) {
				return;
			}
		}
	}
}
