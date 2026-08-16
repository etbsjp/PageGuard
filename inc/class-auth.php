<?php
/**
 * 認証コア。template_redirect で BASIC 認証を要求する。
 *
 * docs/spec.md 4（環境差の吸収）・6（ログイン中の扱い）・7（総当たり対策）。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フロント表示での BASIC 認証。
 */
class Pggd_Auth {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// redirect_canonical（優先度10）より先に判定したいので優先度1で入る。
		add_action( 'template_redirect', array( __CLASS__, 'maybe_require_auth' ), 1 );
	}

	/**
	 * 保護対象ページなら BASIC 認証を要求する。
	 *
	 * @return void
	 */
	public static function maybe_require_auth() {
		// 管理画面・AJAX・cron・REST・XML-RPC では走らせない。
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}

		// 単一投稿の表示だけが対象。
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// 資格情報が保存されているかどうかだけで判定する。
		// 対象投稿タイプの設定（pggd_post_types）はメタボックスを出す範囲の設定であって、
		// これを認証側の条件にすると、設定変更だけで保護済みページが素通しになってしまう。
		if ( ! Pggd_Credentials::is_protected( $post->ID ) ) {
			return;
		}

		// 保護対象と判定した時点でキャッシュを抑止する。
		// 認証を通したあとの 200 がページキャッシュに載り、
		// 未認証の訪問者へそのまま配られるのを防ぐ（docs/spec.md 5-6）。
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		// その投稿の編集権限を持つログインユーザーは認証をスキップする（docs/spec.md 6）。
		if ( is_user_logged_in() && current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$ip = Pggd_Lockout::get_client_ip();

		// ロック中は資格情報が正しくても通さない。
		if ( Pggd_Lockout::is_locked( $ip ) ) {
			self::send_locked( $post->ID, Pggd_Lockout::get_unlock_time( $ip ) );
		}

		$submitted = self::get_submitted_credentials();

		// 資格情報が送られていない＝最初のアクセス。失敗として数えず認証を要求する。
		if ( null === $submitted ) {
			self::send_challenge( $post->ID );
		}

		if ( Pggd_Credentials::verify( $post->ID, $submitted['username'], $submitted['password'] ) ) {
			// 成功したら失敗回数を消す。
			Pggd_Lockout::clear( $ip );
			return;
		}

		// 失敗を記録し、この失敗でロックに達したらロック応答へ切り替える。
		$locked = Pggd_Lockout::record_failure( $ip );
		if ( $locked ) {
			self::send_locked( $post->ID, Pggd_Lockout::get_unlock_time( $ip ) );
		}

		self::send_challenge( $post->ID );
	}

	/**
	 * リクエストから BASIC 認証の資格情報を取り出す。
	 *
	 * PHP_AUTH_USER / PHP_AUTH_PW は Apache モジュール版などでしか埋まらない。
	 * CGI / FastCGI（PHP-FPM）経由では Authorization ヘッダが
	 * HTTP_AUTHORIZATION や REDIRECT_HTTP_AUTHORIZATION として渡ってくるため、
	 * こちらも自前で base64 デコードして受け取る（docs/spec.md 4）。
	 *
	 * @return array|null username / password を持つ配列。取得できなければ null。
	 */
	private static function get_submitted_credentials() {
		// $_SERVER も wp_magic_quotes() でスラッシュが付くため、必ず wp_unslash する。
		// これを忘れるとバックスラッシュや引用符を含むパスワードが一致しなくなる。
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) && '' !== $_SERVER['PHP_AUTH_USER'] ) {
			return array(
				'username' => (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ),
				'password' => isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '',
			);
		}

		$header_keys = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );
		foreach ( $header_keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$header = trim( (string) wp_unslash( $_SERVER[ $key ] ) );

			// スキーム名は大文字小文字を区別しない（RFC 7235）。
			if ( 0 !== stripos( $header, 'Basic ' ) ) {
				continue;
			}

			$encoded = trim( substr( $header, 6 ) );
			if ( '' === $encoded ) {
				continue;
			}

			// 第2引数 true で厳格デコード。base64 として不正な文字が混ざれば false になる。
			$decoded = base64_decode( $encoded, true );
			if ( false === $decoded ) {
				continue;
			}

			// user:pass の形でなければ不正として扱う。
			$position = strpos( $decoded, ':' );
			if ( false === $position ) {
				continue;
			}

			return array(
				// パスワード側にコロンが入り得るので、最初のコロンだけで分割する。
				'username' => substr( $decoded, 0, $position ),
				'password' => substr( $decoded, $position + 1 ),
			);
		}

		return null;
	}

	/**
	 * WWW-Authenticate に載せる realm 文字列を組み立てる。
	 *
	 * サイト名をそのまま入れるとヘッダの構文を壊したり、
	 * 改行を含む名前でヘッダ注入に使われたりするため、
	 * 引用符・バックスラッシュ・改行を取り除く。
	 *
	 * @return string realm に使う文字列。
	 */
	private static function get_realm() {
		// 実体参照（&amp; 等）を戻してから記号を落とす。
		$realm = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$realm = str_replace( array( '"', '\\' ), '', (string) $realm );
		$realm = preg_replace( '/[\r\n\t]+/', ' ', $realm );
		$realm = trim( (string) $realm );

		if ( '' === $realm ) {
			$realm = 'PageGuard';
		}

		return $realm;
	}

	/**
	 * 401 を返して認証を要求する。
	 *
	 * @param int $post_id 対象の投稿ID。
	 * @return void
	 */
	private static function send_challenge( $post_id ) {
		nocache_headers();
		header( 'WWW-Authenticate: Basic realm="' . self::get_realm() . '", charset="UTF-8"' );
		status_header( 401 );

		$title = __( '認証が必要です', 'pageguard' );
		$body  = __( 'このページを表示するにはユーザー名とパスワードが必要です。', 'pageguard' ) . "\n"
			. __( 'ページの管理者からお知らせされたユーザー名とパスワードを入力してください。', 'pageguard' );

		/**
		 * 401 画面の見出しを差し替えるフィルター。
		 *
		 * @param string $title   見出し。
		 * @param int    $post_id 対象の投稿ID。
		 */
		$title = (string) apply_filters( 'pggd_unauthorized_title', $title, $post_id );

		/**
		 * 401 画面の本文を差し替えるフィルター。
		 *
		 * 出力時にエスケープされるため、HTML タグは使えない（改行は反映される）。
		 *
		 * @param string $body    本文。
		 * @param int    $post_id 対象の投稿ID。
		 */
		$body = (string) apply_filters( 'pggd_unauthorized_message', $body, $post_id );

		self::render_page( $title, $body );
	}

	/**
	 * ロック中であることを返す。
	 *
	 * 401 ではなく 429 を返す。401 を返すとブラウザが認証ダイアログを出し続け、
	 * 「入力しても通らない」状態の理由が利用者に伝わらないため。
	 * WWW-Authenticate も送らない（再入力を促さない）。
	 *
	 * @param int $post_id     対象の投稿ID。
	 * @param int $unlock_time ロックが解ける UNIX time。
	 * @return void
	 */
	private static function send_locked( $post_id, $unlock_time ) {
		nocache_headers();
		status_header( 429 );

		$retry_after = (int) $unlock_time - time();
		if ( $retry_after < 1 ) {
			$retry_after = 1;
		}
		header( 'Retry-After: ' . $retry_after );

		$minutes = (int) ceil( $retry_after / 60 );

		$title = __( 'しばらく認証を受け付けられません', 'pageguard' );
		$body  = __( 'ユーザー名またはパスワードの誤りが続いたため、このアクセス元からの認証を一時的に停止しています。', 'pageguard' ) . "\n"
			. sprintf(
				/* translators: %d: 待つ必要のある分数 */
				__( 'およそ %d 分後に、もう一度お試しください。', 'pageguard' ),
				$minutes
			);

		/**
		 * ロック中画面の見出しを差し替えるフィルター。
		 *
		 * @param string $title   見出し。
		 * @param int    $post_id 対象の投稿ID。
		 */
		$title = (string) apply_filters( 'pggd_locked_title', $title, $post_id );

		/**
		 * ロック中画面の本文を差し替えるフィルター。
		 *
		 * 出力時にエスケープされるため、HTML タグは使えない（改行は反映される）。
		 *
		 * @param string $body        本文。
		 * @param int    $post_id     対象の投稿ID。
		 * @param int    $retry_after 再試行できるまでの秒数。
		 */
		$body = (string) apply_filters( 'pggd_locked_message', $body, $post_id, $retry_after );

		self::render_page( $title, $body );
	}

	/**
	 * 認証を通していない訪問者へ返す最小限の HTML を出力して終了する。
	 *
	 * テーマのテンプレートを通すと、保護しているページの断片（サイドバーの
	 * 最近の投稿など）が漏れる可能性があるため、自前の固定 HTML を返す。
	 *
	 * @param string $title 見出し。
	 * @param string $body  本文（改行区切り）。
	 * @return void
	 */
	private static function render_page( $title, $body ) {
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$lines = preg_split( '/\r\n|\r|\n/', (string) $body );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $body );
		}

		?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
body { margin: 0; padding: 3em 1.5em; background: #f0f0f1; color: #1d2327; font-family: -apple-system, "Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", sans-serif; line-height: 1.7; }
.pggd-box { max-width: 32em; margin: 0 auto; padding: 1.75em 2em; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; }
.pggd-box h1 { margin: 0 0 .75em; font-size: 1.25em; }
.pggd-box p { margin: 0 0 .5em; }
</style>
</head>
<body>
<div class="pggd-box">
<h1><?php echo esc_html( $title ); ?></h1>
<?php foreach ( $lines as $line ) : ?>
	<?php if ( '' === trim( $line ) ) { continue; } ?>
<p><?php echo esc_html( $line ); ?></p>
<?php endforeach; ?>
</div>
</body>
</html>
		<?php
		exit;
	}
}
