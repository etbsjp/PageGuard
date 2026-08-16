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
	 * 表示中の投稿に対して、保護の判定に使う投稿IDを返す。
	 *
	 * 添付ファイルページ（/?attachment_id=... や添付ファイルのパーマリンク）は
	 * is_singular() が true になるが、対象は添付ファイル自身なので
	 * そのままでは保護判定に引っかからず素通りする。
	 * 保護したページに挿入した画像や PDF の「添付ファイルページ」が
	 * 未認証で開けてしまうため、親投稿の保護を引き継ぐ。
	 *
	 * なお、Web サーバーが直接返すファイル URL そのもの（wp-content/uploads/...）は
	 * WordPress を通らないため、これとは別に原理的に保護できない。
	 *
	 * REST の入口（Pggd_Visibility）からも同じ読み替えが要るため public にしている。
	 * 経路ごとに書くと、片方だけ添付ファイルの扱いが抜ける形で穴が空く。
	 *
	 * @param WP_Post $post 表示中の投稿。
	 * @return int 保護の判定に使う投稿ID。WP_Post 以外を渡された場合は 0。
	 */
	public static function resolve_target_id( $post ) {
		// public にしたので、呼び出し側の型を信用しない。
		// 引数に型宣言を付けると、渡し間違いが fatal になって画面ごと落ちる。
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$target_id = (int) $post->ID;

		if ( Pggd_Credentials::is_protected( $target_id ) ) {
			return $target_id;
		}

		if ( 'attachment' === $post->post_type && $post->post_parent ) {
			return (int) $post->post_parent;
		}

		return $target_id;
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

		// 添付ファイルページは親投稿の保護を引き継ぐ。
		$target_id = self::resolve_target_id( $post );

		// 資格情報が保存されているかどうかだけで判定する。
		// 対象投稿タイプの設定（pggd_post_types）はメタボックスを出す範囲の設定であって、
		// これを認証側の条件にすると、設定変更だけで保護済みページが素通しになってしまう。
		if ( ! Pggd_Credentials::is_protected( $target_id ) ) {
			return;
		}

		// 保護対象と判定した時点でキャッシュを抑止する。
		// 認証を通したあとの 200 がページキャッシュに載り、
		// 未認証の訪問者へそのまま配られるのを防ぐ（docs/spec.md 5-6）。
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		self::send_vary_header();

		// その投稿の編集権限を持つログインユーザーは認証をスキップする（docs/spec.md 6）。
		if ( is_user_logged_in() && current_user_can( 'edit_post', $target_id ) ) {
			return;
		}

		$ip = Pggd_Lockout::get_client_ip();

		// ロック中は資格情報が正しくても通さない。
		if ( Pggd_Lockout::is_locked( $ip ) ) {
			self::send_locked( $target_id, Pggd_Lockout::get_unlock_time( $ip ) );
		}

		$submitted = self::get_submitted_credentials();

		// 資格情報が送られていない＝最初のアクセス。失敗として数えず認証を要求する。
		if ( null === $submitted ) {
			self::send_challenge( $target_id );
		}

		if ( Pggd_Credentials::verify( $target_id, $submitted['username'], $submitted['password'] ) ) {
			// 成功したら、このページぶんの失敗回数だけを消す。
			// アクセス元の記録ごと消すと、別のページの回数まで 0 に戻り、
			// 「資格情報を知っているページへ成功しに行く」だけで
			// 総当たりの回数制限を回避できてしまう。
			Pggd_Lockout::clear( $ip, $target_id );
			return;
		}

		// 失敗を記録し、この失敗でロックに達したらロック応答へ切り替える。
		if ( Pggd_Lockout::record_failure( $ip, $target_id ) ) {
			self::send_locked( $target_id, Pggd_Lockout::get_unlock_time( $ip ) );
		}

		self::send_challenge( $target_id );
	}

	/**
	 * Vary ヘッダを送る。
	 *
	 * no-store を尊重しない前段のキャッシュ（CDN・リバースプロキシ）が、
	 * 認証済みの応答と未認証の応答を同じものとして扱わないようにするための保険。
	 *
	 * @return void
	 */
	private static function send_vary_header() {
		if ( headers_sent() ) {
			return;
		}
		// 第2引数 false で、既にある Vary を消さずに足す。
		header( 'Vary: Authorization', false );
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
		self::send_vary_header();
		header( 'WWW-Authenticate: Basic realm="' . self::get_realm() . '", charset="UTF-8"' );
		status_header( 401 );

		$title = __( '認証が必要です', 'pageguard' );
		$body  = __( 'このページを表示するにはユーザー名とパスワードが必要です。', 'pageguard' ) . "\n"
			. __( '入力欄が表示されなかった場合や、入力をキャンセルした場合は、下のボタンからもう一度お試しください。', 'pageguard' ) . "\n"
			. __( 'ユーザー名とパスワードが分からない場合は、このページの管理者にお問い合わせください。', 'pageguard' );

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

		self::render_page(
			$title,
			$body,
			array(
				// href を空にすると同じ URL への再読み込みになる。
				// 再読み込みするとブラウザが認証ダイアログを出し直す。
				array(
					'url'     => '',
					'label'   => __( 'もう一度入力する', 'pageguard' ),
					'primary' => true,
				),
				array(
					'url'     => home_url( '/' ),
					'label'   => __( 'サイトのトップへ戻る', 'pageguard' ),
					'primary' => false,
				),
			)
		);
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
		self::send_vary_header();
		status_header( 429 );

		$retry_after = (int) $unlock_time - time();
		if ( $retry_after < 1 ) {
			$retry_after = 1;
		}
		header( 'Retry-After: ' . $retry_after );

		$minutes = (int) ceil( $retry_after / 60 );

		$title = __( 'しばらく認証を受け付けられません', 'pageguard' );
		$body  = __( 'ユーザー名またはパスワードの誤りが続いたため、お使いの回線からの認証を一時的に停止しています。', 'pageguard' ) . "\n"
			. __( 'オフィスや携帯電話の回線では、同じ回線を使っている他の方の入力ミスが原因のこともあります。', 'pageguard' ) . "\n"
			. sprintf(
				/* translators: %d: 待つ必要のある分数 */
				__( 'およそ %d 分後にこのページを再度開くと、もう一度入力できます。', 'pageguard' ),
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

		self::render_page(
			$title,
			$body,
			array(
				array(
					'url'     => home_url( '/' ),
					'label'   => __( 'サイトのトップへ戻る', 'pageguard' ),
					'primary' => false,
				),
			)
		);
	}

	/**
	 * 認証を通していない訪問者へ返す最小限の HTML を出力して終了する。
	 *
	 * テーマのテンプレートを通すと、保護しているページの断片（サイドバーの
	 * 最近の投稿など）が漏れる可能性があるため、自前の固定 HTML を返す。
	 *
	 * @param string $title   見出し。
	 * @param string $body    本文（改行区切り）。
	 * @param array  $actions 行き先のリンク。url / label / primary を持つ配列の配列。
	 * @return void
	 */
	private static function render_page( $title, $body, $actions = array() ) {
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$lines = preg_split( '/\r\n|\r|\n/', (string) $body );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $body );
		}

		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$page_title = ( '' !== trim( (string) $site_name ) )
			? $title . ' | ' . $site_name
			: $title;

		?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $page_title ); ?></title>
<style>
body { margin: 0; padding: 3em 1.5em; background: #f0f0f1; color: #1d2327; font-family: -apple-system, "Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", sans-serif; line-height: 1.7; }
.pggd-box { max-width: 32em; margin: 0 auto; padding: 1.75em 2em; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; }
.pggd-box h1 { margin: 0 0 .75em; font-size: 1.25em; }
.pggd-box p { margin: 0 0 .5em; }
.pggd-actions { margin: 1.5em 0 0; display: flex; flex-wrap: wrap; gap: .75em; }
.pggd-actions a { display: inline-block; padding: .5em 1.25em; border: 1px solid #2271b1; border-radius: 3px; color: #2271b1; text-decoration: none; }
.pggd-actions a:hover, .pggd-actions a:focus { background: #f0f6fc; }
.pggd-actions a.pggd-primary { background: #2271b1; color: #fff; }
.pggd-actions a.pggd-primary:hover, .pggd-actions a.pggd-primary:focus { background: #135e96; border-color: #135e96; }
@media (prefers-color-scheme: dark) {
	body { background: #1d2327; color: #f0f0f1; }
	.pggd-box { background: #2c3338; border-color: #3c434a; }
	.pggd-actions a { border-color: #72aee6; color: #72aee6; }
	.pggd-actions a:hover, .pggd-actions a:focus { background: #32373c; }
	.pggd-actions a.pggd-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
	.pggd-actions a.pggd-primary:hover, .pggd-actions a.pggd-primary:focus { background: #135e96; border-color: #135e96; }
}
</style>
</head>
<body>
<div class="pggd-box">
<h1><?php echo esc_html( $title ); ?></h1>
<?php foreach ( $lines as $line ) : ?>
	<?php if ( '' === trim( $line ) ) { continue; } ?>
<p><?php echo esc_html( $line ); ?></p>
<?php endforeach; ?>
<?php if ( ! empty( $actions ) ) : ?>
<div class="pggd-actions">
	<?php foreach ( $actions as $action ) : ?>
	<a href="<?php echo esc_url( $action['url'] ); ?>"<?php echo ! empty( $action['primary'] ) ? ' class="pggd-primary"' : ''; ?>><?php echo esc_html( $action['label'] ); ?></a>
	<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</body>
</html>
		<?php
		exit;
	}
}
