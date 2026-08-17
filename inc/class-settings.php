<?php
/**
 * 設定画面（add_options_page、画面ID settings_page_pageguard）。
 *
 * 植草（UX）と確定した設計に沿って、タブ切り替えではなく1画面スクロール構成にしている
 * （issue #7 のコメントに decision-record あり）。
 *
 * ■ 2種類のフォームを分けている理由
 *
 * 「保護の基本設定」（対象投稿タイプ・失敗回数・ロック時間）だけを Settings API の
 * 通常フォーム（options.php 経由）にし、「受信診断の実行」「ロック中アクセス元の解除」は
 * それぞれ独立した nonce を持つ小さなフォーム（admin-post.php 経由）にしている。
 * 1つのフォームに混在させると、一覧の「解除」ボタンを1回押しただけで
 * 基本設定フォームの全項目が一緒に送信され、意図しない設定変更が起きる事故になるため。
 *
 * ■ 受信診断の方式
 *
 * 現在アクセス中のリクエストには認証ヘッダーが乗っていない（未認証のアクセスだから）ため、
 * 「このサイトで Authorization ヘッダーが PHP まで届くか」は今のリクエストからは判定できない。
 * そこで、自分自身へのループバックリクエスト（wp_remote_get）に Authorization ヘッダーを
 * 乗せて送り、フロント側の使い捨てトークン付きエンドポイント（maybe_respond_to_diagnosis_probe）が
 * 「実際に PHP まで届いたか」を返す方式にしている。ループバックが失敗した場合（WP_Error・
 * タイムアウト・想定外の応答）は「診断できず」として扱い、.htaccess スニペットを保険的に表示する。
 *
 * ■ ロック中アクセス元の解除
 *
 * Pggd_Lockout のレコードは IP そのものではなくソルト付きハッシュのキーで保存されている
 * （inc/class-lockout.php 参照）。解除フォームはこのキーをそのまま識別子として送信し、
 * Pggd_Lockout::unlock_by_key() でキー指定のまま削除する。画面側で IP を再度ハッシュ化して
 * 照合する設計にはしていない（正規化・ソルトの実装がズレると解除できない、または
 * 別の送信元を解除してしまう事故のもとになるため）。
 *
 * ■ 保護中ページの一覧
 *
 * 現在の対象投稿タイプ設定（pggd_post_types）に関わらず、_pggd_protected /
 * _pggd_credentials のどちらかのメタキーを持つ投稿を投稿タイプ横断（post_type => 'any'）で拾う。
 * 対象投稿タイプの設定を後から変えても、既に保護済みのページが一覧から消えないようにするため。
 *
 * 一覧のリンクは編集画面（post.php?post=ID&action=edit）だけにし、フロント側 URL には
 * 絶対にリンクしない。編集権限を持つ人はログイン中に認証をスキップするため、フロント側への
 * リンクがあると「壊れていても正常に見える」事故を招く（inc/class-meta-box.php の
 * render_state() と同じ注意）。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定画面。
 */
class Pggd_Settings {

	/**
	 * 設定画面のスラッグ（add_options_page の $menu_slug）。
	 *
	 * 画面IDは WordPress のコアが自動的に settings_page_<スラッグ> にする。
	 */
	const PAGE_SLUG = 'pageguard';

	/**
	 * 「保護の基本設定」の Settings API オプショングループ名。
	 */
	const OPTION_GROUP = 'pggd_settings';

	/**
	 * 受信診断を実行する admin-post アクション名（＝ nonce のアクション名にも使う）。
	 */
	const DIAG_ACTION = 'pggd_run_diagnosis';

	/**
	 * 受信診断の結果を保存するオプション名（autoload 無効）。
	 */
	const DIAG_RESULT_OPTION = 'pggd_diagnosis_result';

	/**
	 * フロント側の受信診断エンドポイントを見分けるクエリ変数名。
	 */
	const DIAG_QUERY_VAR = 'pggd_diag';

	/**
	 * 受信診断の使い捨てトークンを保存する transient のキー接頭辞。
	 */
	const DIAG_TOKEN_PREFIX = 'pggd_diag_token_';

	/**
	 * 受信診断のループバックリクエストで使うダミーのユーザー名 / パスワード。
	 *
	 * 実在の資格情報とは無関係。ヘッダーが PHP まで届くかどうかだけを見るため、
	 * 値そのものに意味はない。
	 */
	const DIAG_TEST_USER = 'pggd-diagnosis';
	const DIAG_TEST_PASS = 'pggd-diagnosis';

	/**
	 * ロック解除の admin-post アクション名（＝ nonce のアクション名の接頭辞にも使う）。
	 */
	const UNLOCK_ACTION = 'pggd_unlock_ip';

	/**
	 * ロック中アクセス元の一覧に表示する上限件数。
	 *
	 * Pggd_Lockout 側は最大 200 件まで保持し得るため、そのまま出すと
	 * 設定画面が重くなる。解除が近い（＝影響が小さい）ものから優先して表示する。
	 */
	const LOCKED_IPS_DISPLAY_LIMIT = 50;

	/**
	 * 保護中ページ一覧の1ページあたりの件数。
	 */
	const PROTECTED_PAGES_PER_PAGE = 20;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::DIAG_ACTION, array( __CLASS__, 'handle_run_diagnosis' ) );
		add_action( 'admin_post_' . self::UNLOCK_ACTION, array( __CLASS__, 'handle_unlock' ) );

		// フロント側の受信診断エンドポイント。管理画面に限らないリクエストで判定するため、
		// admin_init ではなく init に掛ける。
		add_action( 'init', array( __CLASS__, 'maybe_respond_to_diagnosis_probe' ) );
	}

	/**
	 * 設定画面を「設定」メニューの下に登録する。
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'PageGuard 設定', 'pageguard' ),
			__( 'PageGuard', 'pageguard' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * 設定画面の CSS を読み込む。
	 *
	 * @param string $hook 現在の管理画面のフック名。
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'pggd-settings',
			plugins_url( 'css/settings.css', __FILE__ ),
			array(),
			PGGD_VERSION
		);
	}

	/**
	 * 設定画面の URL を返す。
	 *
	 * @return string 設定画面の URL。
	 */
	private static function get_settings_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/*-------------------------------------------*/
	/* 保護の基本設定（Settings API）
	/*-------------------------------------------*/

	/**
	 * 「保護の基本設定」を Settings API に登録する。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'pggd_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_types' ),
				'default'           => array( 'page' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'pggd_max_attempts',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_max_attempts' ),
				'default'           => 5,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			'pggd_lockout_seconds',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_lockout_seconds' ),
				'default'           => 900,
			)
		);

		add_settings_section(
			'pggd_basic_settings',
			'',
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'pggd_post_types',
			__( '対象の投稿タイプ', 'pageguard' ),
			array( __CLASS__, 'render_post_types_field' ),
			self::PAGE_SLUG,
			'pggd_basic_settings'
		);

		add_settings_field(
			'pggd_max_attempts',
			__( '認証に失敗できる回数', 'pageguard' ),
			array( __CLASS__, 'render_max_attempts_field' ),
			self::PAGE_SLUG,
			'pggd_basic_settings'
		);

		add_settings_field(
			'pggd_lockout_seconds',
			__( 'ロックする時間', 'pageguard' ),
			array( __CLASS__, 'render_lockout_seconds_field' ),
			self::PAGE_SLUG,
			'pggd_basic_settings'
		);
	}

	/**
	 * 「対象の投稿タイプ」を保存前に検証する。
	 *
	 * 選択できる投稿タイプの範囲外の値が混ざっていても、その値だけを取り除く
	 * （画面を改造されて未知の投稿タイプ名を送られても、意味のない値を保存しない）。
	 *
	 * @param mixed $value 送信された値。
	 * @return array 検証済みの投稿タイプ名の配列。
	 */
	public static function sanitize_post_types( $value ) {
		$value     = is_array( $value ) ? $value : array();
		$available = array_keys( pggd_get_selectable_post_types() );

		$sanitized = array();
		foreach ( $value as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $slug, $available, true ) && ! in_array( $slug, $sanitized, true ) ) {
				$sanitized[] = $slug;
			}
		}

		return $sanitized;
	}

	/**
	 * 「認証に失敗できる回数」を保存前に検証する。
	 *
	 * @param mixed $value 送信された値。
	 * @return int 検証済みの回数（1 以上）。
	 */
	public static function sanitize_max_attempts( $value ) {
		$value = (int) $value;

		if ( $value < 1 ) {
			add_settings_error(
				'pggd_max_attempts',
				'pggd_max_attempts_invalid',
				__( '認証に失敗できる回数は 1 以上の数値を指定してください。既定値（5 回）を使用しました。', 'pageguard' )
			);
			return 5;
		}

		return $value;
	}

	/**
	 * 「ロックする時間」を保存前に検証する。
	 *
	 * @param mixed $value 送信された値（秒）。
	 * @return int 検証済みの秒数（1 以上）。
	 */
	public static function sanitize_lockout_seconds( $value ) {
		$value = (int) $value;

		if ( $value < 1 ) {
			add_settings_error(
				'pggd_lockout_seconds',
				'pggd_lockout_seconds_invalid',
				__( 'ロックする時間は 1 秒以上の数値を指定してください。既定値（900 秒）を使用しました。', 'pageguard' )
			);
			return 900;
		}

		return $value;
	}

	/**
	 * 「対象の投稿タイプ」フィールドを出力する。
	 *
	 * 表示する現在値は pggd_get_target_post_types()（実行時にフィルターで
	 * 差し替えられ得る値）ではなく、保存されている生のオプション値を使う。
	 * フィルターが掛かっている環境で、画面の表示と実際に保存されている値が
	 * 食い違って見えるのを避けるため。
	 *
	 * @return void
	 */
	public static function render_post_types_field() {
		$stored = get_option( 'pggd_post_types', array( 'page' ) );
		$stored = is_array( $stored ) ? $stored : array( 'page' );

		$post_types = pggd_get_selectable_post_types();
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( '対象の投稿タイプ', 'pageguard' ); ?></legend>
			<?php if ( empty( $post_types ) ) : ?>
				<p><?php esc_html_e( '選択できる投稿タイプが見つかりませんでした。', 'pageguard' ); ?></p>
			<?php else : ?>
				<?php foreach ( $post_types as $slug => $post_type_object ) : ?>
					<p class="pggd-checkbox-row">
						<label for="pggd_post_type_<?php echo esc_attr( $slug ); ?>">
							<input type="checkbox" id="pggd_post_type_<?php echo esc_attr( $slug ); ?>"
								name="pggd_post_types[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $stored, true ) ); ?>>
							<?php echo esc_html( $post_type_object->labels->name ); ?>
						</label>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'ここで選んだ投稿タイプの編集画面に、BASIC 認証の設定欄が表示されます。', 'pageguard' ); ?>
			<?php esc_html_e( 'チェックを外しても、既に保護を設定した投稿の保護は解除されません。', 'pageguard' ); ?>
		</p>
		<?php
	}

	/**
	 * 「認証に失敗できる回数」フィールドを出力する。
	 *
	 * @return void
	 */
	public static function render_max_attempts_field() {
		$value = pggd_get_max_attempts();
		?>
		<input type="number" min="1" step="1" class="small-text"
			id="pggd_max_attempts" name="pggd_max_attempts" value="<?php echo esc_attr( $value ); ?>">
		<p class="description">
			<?php esc_html_e( 'ユーザー名またはパスワードをこの回数連続で間違えると、そのアクセス元をロックします。', 'pageguard' ); ?>
		</p>
		<?php
	}

	/**
	 * 「ロックする時間」フィールドを出力する。
	 *
	 * @return void
	 */
	public static function render_lockout_seconds_field() {
		$value = pggd_get_lockout_seconds();
		?>
		<input type="number" min="1" step="1" class="small-text"
			id="pggd_lockout_seconds" name="pggd_lockout_seconds" value="<?php echo esc_attr( $value ); ?>">
		<?php esc_html_e( '秒', 'pageguard' ); ?>
		<p class="description">
			<?php esc_html_e( 'ロックしたアクセス元が、再び認証を試せるようになるまでの秒数です。', 'pageguard' ); ?>
			<?php esc_html_e( '目安: 300 秒で 5 分、900 秒で 15 分、1800 秒で 30 分です。', 'pageguard' ); ?>
		</p>
		<?php
	}

	/*-------------------------------------------*/
	/* 画面の描画
	/*-------------------------------------------*/

	/**
	 * 設定画面全体を出力する。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap pggd-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			// HTTPS でない場合の警告は、診断結果に関わらず常に最上部へ出す
			// （植草との decision-record どおり。見落とすと事故につながる警告のため）。
			self::render_https_warning();
			self::render_action_notice();
			settings_errors();
			?>

			<h2 class="pggd-settings-heading"><?php esc_html_e( '保護の基本設定', 'pageguard' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				// id を明示しないと submit_button() は $name（既定 'submit'）を id にするため、
				// 他フォームの送信ボタンと id が重複する（診断実行・行ごとの解除ボタン）。
				submit_button( __( '設定を保存', 'pageguard' ), 'primary', 'submit', true, array( 'id' => 'pggd_save_submit' ) );
				?>
			</form>

			<hr class="pggd-settings-divider">

			<?php
			self::render_diagnosis_section();
			self::render_locked_ips_section();
			self::render_protected_pages_section();
			?>
		</div>
		<?php
	}

	/**
	 * サイトが HTTPS でない場合の警告を出力する。
	 *
	 * @return void
	 */
	private static function render_https_warning() {
		if ( self::is_https_site() ) {
			return;
		}
		?>
		<div class="notice notice-warning pggd-https-warning">
			<p>
				<strong><?php esc_html_e( 'このサイトは HTTPS で運用されていません。', 'pageguard' ); ?></strong>
				<?php esc_html_e( 'BASIC 認証はページを表示するたびにユーザー名とパスワードを送信します。HTTPS でない場合、通信経路上の第三者にこれらの内容を読み取られる可能性があります。', 'pageguard' ); ?>
			</p>
			<p><?php esc_html_e( '可能であれば、サイト全体を HTTPS 化してからこの機能をご利用ください。', 'pageguard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * サイトが HTTPS で運用されているかを返す。
	 *
	 * is_ssl() は「今のリクエストが HTTPS か」を見るため、この管理画面自体が
	 * HTTPS でも、サイト表側（home_url）が HTTP のままの構成があり得る。
	 * 判定はサイトの URL 設定そのもの（home_url のスキーム）で行う。
	 *
	 * @return bool HTTPS なら true。
	 */
	private static function is_https_site() {
		return 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
	}

	/**
	 * ロック解除操作の結果通知を出力する。
	 *
	 * @return void
	 */
	private static function render_action_notice() {
		if ( ! isset( $_GET['pggd_unlocked'] ) ) {
			return;
		}
		$ok = ( '1' === $_GET['pggd_unlocked'] );
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			$ok ? 'notice-success' : 'notice-error',
			$ok
				? esc_html__( 'ロックを解除しました。', 'pageguard' )
				: esc_html__( 'ロックの解除に失敗しました。時間をおいてもう一度お試しください。', 'pageguard' )
		);
	}

	/*-------------------------------------------*/
	/* 受信診断
	/*-------------------------------------------*/

	/**
	 * 「受信診断」セクションを出力する。
	 *
	 * @return void
	 */
	private static function render_diagnosis_section() {
		$result = self::get_diagnosis_result();
		?>
		<h2 class="pggd-settings-heading"><?php esc_html_e( '受信診断', 'pageguard' ); ?></h2>
		<p>
			<?php esc_html_e( 'サーバーの構成によっては、ユーザー名とパスワードがサーバーから PHP まで届かず、正しく入力しても認証できないことがあります。', 'pageguard' ); ?>
			<?php esc_html_e( '下のボタンから、このサイトで受け取れているかどうかを確認できます。', 'pageguard' ); ?>
		</p>

		<?php self::render_diagnosis_result( $result ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DIAG_ACTION ); ?>">
			<?php wp_nonce_field( self::DIAG_ACTION, 'pggd_diag_nonce' ); ?>
			<?php submit_button( __( '診断を実行', 'pageguard' ), 'secondary', 'submit', false, array( 'id' => 'pggd_diag_submit' ) ); ?>
		</form>
		<?php
	}

	/**
	 * 受信診断の結果表示（成功 / 失敗 / 診断できず / 未実施）を出力する。
	 *
	 * 「診断できず」と「未実施」は原因が違う（前者は実行したが判定できなかった、
	 * 後者はまだ一度も実行していない）ため、文言と見た目（notice の色）を分けている。
	 * ただし .htaccess スニペットは、成功以外の全状態（未実施も含む）で保険的に表示する。
	 * 実行し忘れたまま気付かず運用されるより、常に見える側が安全なため。
	 *
	 * @param array|null $result get_diagnosis_result() の戻り値。
	 * @return void
	 */
	private static function render_diagnosis_result( $result ) {
		if ( null === $result ) {
			self::render_diagnosis_not_run_notice();
			self::render_htaccess_snippet();
			return;
		}

		$status     = $result['status'];
		$checked_at = $result['checked_at'];
		$detail     = $result['detail'];

		$labels = array(
			'success' => array(
				'class' => 'notice-success',
				'text'  => __( '受信できています。このサイトでは BASIC 認証が正しく動作します。', 'pageguard' ),
			),
			'failure' => array(
				'class' => 'notice-error',
				'text'  => __( '受信できていません。正しいユーザー名とパスワードを入力しても認証できません。', 'pageguard' ),
			),
			'unknown' => array(
				'class' => 'notice-warning',
				// サーバーやネットワークの都合で判定できなかっただけで、失敗と決まったわけではない。
				// 次に何をすればよいか（下の設定例を試す）まで書く。
				'text'  => __( 'サーバーからの応答を確認できなかったため、診断できませんでした。下記の設定例をお試しください。', 'pageguard' ),
			),
		);
		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'];
		?>
		<div class="notice inline <?php echo esc_attr( $label['class'] ); ?> pggd-diagnosis-result">
			<p><strong><?php echo esc_html( $label['text'] ); ?></strong></p>
			<?php if ( $checked_at > 0 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: 診断を実行した日時 */
						esc_html__( '診断日時: %s', 'pageguard' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked_at ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $detail ) : ?>
				<?php
				/*
				 * cURL の英語エラーメッセージ等がそのまま入るため、主表示には出さない
				 * （読んでも次の行動に結びつかず、管理者を不安にさせるだけのため）。
				 * 技術的な詳細として見たい人だけが開ける従属的な位置に下げる。
				 */
				?>
				<details class="pggd-diagnosis-detail">
					<summary><?php esc_html_e( '技術的な詳細を表示', 'pageguard' ); ?></summary>
					<p><code><?php echo esc_html( $detail ); ?></code></p>
				</details>
			<?php endif; ?>
		</div>
		<?php
		if ( 'success' !== $status ) {
			self::render_htaccess_snippet();
		}
	}

	/**
	 * 「まだ診断を実行していません」の通知を出力する。
	 *
	 * render_diagnosis_result() の「診断できず」（実行したが判定できなかった）とは
	 * 意味が異なるため、文言と notice の色を分けている。
	 *
	 * @return void
	 */
	private static function render_diagnosis_not_run_notice() {
		?>
		<div class="notice inline notice-info pggd-diagnosis-result">
			<p><strong><?php esc_html_e( 'まだ診断を実行していません。', 'pageguard' ); ?></strong></p>
			<p class="description"><?php esc_html_e( '下のボタンから診断を実行してください。', 'pageguard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * .htaccess のスニペット（表示専用）を出力する。
	 *
	 * 【絶対にやってはいけないこと】このプラグインは .htaccess を自動で書き換えない。
	 * 表示に留め、利用者が内容を確認したうえで自分で貼ることを前提にする。
	 *
	 * @return void
	 */
	private static function render_htaccess_snippet() {
		$snippet = "# PageGuard: Authorization ヘッダーを PHP まで届けるための設定例\n"
			. "# 該当する方法のコメント（#）を外し、サイトの .htaccess に追記してください。\n\n"
			. "# Apache 2.4.13 以降で使える場合はこちら\n"
			. "#<IfModule mod_authz_core.c>\n"
			. "#    CGIPassAuth On\n"
			. "#</IfModule>\n\n"
			. "# 上記が使えない環境ではこちら\n"
			. "#<IfModule mod_rewrite.c>\n"
			. "#    RewriteEngine On\n"
			. "#    RewriteCond %{HTTP:Authorization} ^(.+)$\n"
			. "#    RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]\n"
			. "#</IfModule>\n";
		?>
		<div class="pggd-htaccess-note">
			<p>
				<?php esc_html_e( '以下は、サーバーの構成によってはこの状態を解決できる .htaccess の記述例です。', 'pageguard' ); ?>
				<strong><?php esc_html_e( 'このプラグインが .htaccess を自動で書き換えることはありません。内容を確認のうえ、ご自身で追記してください。', 'pageguard' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( '追記する前に、必ず現在の .htaccess の控えを取ってください。', 'pageguard' ); ?>
				<?php esc_html_e( 'サーバーや他のプラグインの設定との組み合わせによっては、正しく動作しない場合やサイトの表示に影響する場合があります。', 'pageguard' ); ?>
				<?php esc_html_e( 'ご不安な場合は、サーバーの管理会社にご相談ください。', 'pageguard' ); ?>
			</p>
			<pre><code><?php echo esc_html( $snippet ); ?></code></pre>
		</div>
		<?php
	}

	/**
	 * 保存済みの受信診断結果を返す。
	 *
	 * @return array|null status / checked_at / detail を持つ配列。未実施なら null。
	 */
	private static function get_diagnosis_result() {
		$result = get_option( self::DIAG_RESULT_OPTION, null );
		if ( ! is_array( $result ) || ! isset( $result['status'], $result['checked_at'] ) ) {
			return null;
		}
		return array(
			'status'     => (string) $result['status'],
			'checked_at' => (int) $result['checked_at'],
			'detail'     => isset( $result['detail'] ) ? (string) $result['detail'] : '',
		);
	}

	/**
	 * 受信診断の実行（admin-post ハンドラ）。
	 *
	 * manage_options 権限 + nonce の両方を必須にする。未認証で叩けると、
	 * このサイトへ外向きのループバックリクエストを何度も発生させられてしまうため。
	 *
	 * @return void
	 */
	public static function handle_run_diagnosis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'pageguard' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DIAG_ACTION, 'pggd_diag_nonce' );

		$result = self::run_diagnosis();

		// autoload 無効。通常のリクエストで毎回読み込まれる必要はない値のため。
		update_option( self::DIAG_RESULT_OPTION, $result, false );

		wp_safe_redirect( self::get_settings_url() );
		exit;
	}

	/**
	 * ループバックリクエストで受信診断を実行する。
	 *
	 * @return array status（success/failure/unknown）/ checked_at / detail を持つ配列。
	 */
	private static function run_diagnosis() {
		$token = bin2hex( random_bytes( 16 ) );
		// 60 秒だけ有効な使い捨てトークン。ループバックが返ってくる前提の待ち時間として十分で、
		// 万一トークンを推測されても実害の無い短さにしている。
		set_transient( self::DIAG_TOKEN_PREFIX . $token, 1, 60 );

		$url = add_query_arg(
			array(
				self::DIAG_QUERY_VAR => '1',
				'token'               => $token,
			),
			home_url( '/' )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				// コアの loopback リクエストと同じ考え方。自己署名証明書などで
				// SSL 検証に失敗し、診断そのものが動かなくなるのを避ける。
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( self::DIAG_TEST_USER . ':' . self::DIAG_TEST_PASS ),
				),
			)
		);

		// 応答の有無に関わらずトークンは使い捨てる。
		delete_transient( self::DIAG_TOKEN_PREFIX . $token );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'     => 'unknown',
				'checked_at' => time(),
				'detail'     => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['received'] ) ) {
			// ページキャッシュ等が割り込んで診断用の応答そのものが返ってこなかった場合も
			// ここに来る。原因を特定できないので「診断できず」にする。
			return array(
				'status'     => 'unknown',
				'checked_at' => time(),
				'detail'     => sprintf(
					/* translators: %d: 応答の HTTP ステータスコード */
					__( '診断用リクエストへの応答が想定外でした（ステータス: %d）。', 'pageguard' ),
					$code
				),
			);
		}

		return array(
			'status'     => ! empty( $body['received'] ) ? 'success' : 'failure',
			'checked_at' => time(),
			'detail'     => '',
		);
	}

	/**
	 * フロント側の受信診断エンドポイント。
	 *
	 * 有効なトークン付きでアクセスされたときだけ応答し、それ以外では即座に抜けて
	 * 通常のリクエスト処理に戻す。認証は必須にできない（未認証のアクセスで
	 * ヘッダーが届くかを見る仕組みのため）が、使い捨てトークンで正当な呼び出し以外を弾く。
	 *
	 * @return void
	 */
	public static function maybe_respond_to_diagnosis_probe() {
		if ( ! isset( $_GET[ self::DIAG_QUERY_VAR ], $_GET['token'] ) ) {
			return;
		}
		if ( '1' !== $_GET[ self::DIAG_QUERY_VAR ] ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		// bin2hex( random_bytes( 16 ) ) の生成形式（16進32桁）どおりかだけを見る。
		// Pggd_Lockout::KEY_PATTERN と偶然同じ形式だが、意味が違う値なので流用しない
		// （Pggd_Lockout 側でキー形式を変えたときに、無関係なこちらまで巻き込まれないようにする）。
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return;
		}

		$transient_key = self::DIAG_TOKEN_PREFIX . $token;
		if ( false === get_transient( $transient_key ) ) {
			// トークンが無効・期限切れ・既に使用済み。診断エンドポイントとしては応答しない。
			return;
		}
		delete_transient( $transient_key ); // 使い捨て。

		$received = Pggd_Auth::has_incoming_auth_header();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'received' => $received ) );
		exit;
	}

	/*-------------------------------------------*/
	/* ロック中のアクセス元
	/*-------------------------------------------*/

	/**
	 * 「ロック中のアクセス元」セクションを出力する。
	 *
	 * @return void
	 */
	private static function render_locked_ips_section() {
		$now     = time();
		$records = Pggd_Lockout::get_all_records();

		$locked = array();
		foreach ( $records as $key => $record ) {
			if ( isset( $record['locked'] ) && (int) $record['locked'] > $now ) {
				$locked[ $key ] = $record;
			}
		}

		// 解除が近い（＝影響が小さい）ものから並べる。
		uasort(
			$locked,
			function ( $a, $b ) {
				return (int) $a['locked'] - (int) $b['locked'];
			}
		);

		$total   = count( $locked );
		$display = array_slice( $locked, 0, self::LOCKED_IPS_DISPLAY_LIMIT, true );
		?>
		<h2 class="pggd-settings-heading"><?php esc_html_e( 'ロック中のアクセス元', 'pageguard' ); ?></h2>
		<p><?php esc_html_e( 'ユーザー名またはパスワードの入力ミスが続き、一時的に認証を受け付けていないアクセス元です。解除すると、そのアクセス元は失敗回数 0 からやり直せます。', 'pageguard' ); ?></p>

		<?php if ( $total > count( $display ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: 表示している件数, 2: 全体の件数 */
					esc_html__( '件数が多いため、解除が近い %1$d 件のみ表示しています（全 %2$d 件）。', 'pageguard' ),
					count( $display ),
					$total
				);
				?>
			</p>
		<?php endif; ?>

		<table class="widefat fixed striped pggd-locked-ips-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'アクセス元', 'pageguard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'ロック解除予定', 'pageguard' ); ?></th>
					<th scope="col"><?php esc_html_e( '操作', 'pageguard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $display ) ) : ?>
					<tr>
						<td colspan="3"><?php esc_html_e( '現在ロック中のアクセス元はありません。', 'pageguard' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $display as $key => $record ) : ?>
						<tr>
							<td><?php echo esc_html( $record['ip'] ); ?></td>
							<td>
								<?php
								echo esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										(int) $record['locked']
									)
								);
								?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::UNLOCK_ACTION ); ?>">
									<input type="hidden" name="pggd_key" value="<?php echo esc_attr( $key ); ?>">
									<?php wp_nonce_field( self::UNLOCK_ACTION . '_' . $key, 'pggd_unlock_nonce' ); ?>
									<?php
									/*
									 * id は行ごとに一意にする（submit_button() は既定で $name＝'submit' を
									 * id にするため、行が複数あると id が重複してしまう）。
									 * aria-label も行ごとに変え、どのアクセス元の解除ボタンかを
									 * 視覚情報なしでも判別できるようにする（誤操作コストのある操作のため）。
									 */
									submit_button(
										__( '解除', 'pageguard' ),
										'secondary small',
										'submit',
										false,
										array(
											'id'         => 'pggd_unlock_submit_' . $key,
											/* translators: %s: ロックされているアクセス元（IP アドレス） */
											'aria-label' => sprintf( __( '%s のロックを解除', 'pageguard' ), $record['ip'] ),
										)
									);
									?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * ロック解除（admin-post ハンドラ）。
	 *
	 * @return void
	 */
	public static function handle_unlock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'pageguard' ), '', array( 'response' => 403 ) );
		}

		$key = isset( $_POST['pggd_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pggd_key'] ) ) : '';
		// Pggd_Lockout のレコードキー形式（sha256 の先頭32文字＝16進32桁）以外は受け付けない。
		// unlock_by_key() 自身も同じ形式を検証するが、ここで弾けば不正な値で
		// nonce 検証まで進めずに済む（check_admin_referer() は $key を使って
		// アクション名を組み立てるため、先に形式を確かめておきたい）。
		if ( ! preg_match( Pggd_Lockout::KEY_PATTERN, $key ) ) {
			wp_die( esc_html__( '不正なリクエストです。', 'pageguard' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::UNLOCK_ACTION . '_' . $key, 'pggd_unlock_nonce' );

		$ok = Pggd_Lockout::unlock_by_key( $key );

		wp_safe_redirect( add_query_arg( 'pggd_unlocked', $ok ? '1' : '0', self::get_settings_url() ) );
		exit;
	}

	/*-------------------------------------------*/
	/* 保護中のページ
	/*-------------------------------------------*/

	/**
	 * 「保護中のページ」セクションを出力する。
	 *
	 * @return void
	 */
	private static function render_protected_pages_section() {
		$paged = isset( $_GET['pggd_page'] ) ? max( 1, (int) $_GET['pggd_page'] ) : 1;

		$query = new WP_Query(
			array(
				'post_type'          => 'any',
				'post_status'        => 'any',
				'posts_per_page'     => self::PROTECTED_PAGES_PER_PAGE,
				'paged'              => $paged,
				'orderby'            => 'title',
				'order'              => 'ASC',
				'ignore_sticky_posts' => true,
				'no_found_rows'      => false,
				'meta_query'         => array(
					'relation' => 'OR',
					array(
						'key'     => Pggd_Credentials::META_PROTECTED,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Pggd_Credentials::META_CREDENTIALS,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		?>
		<?php
		/*
		 * この見出しに id を振り、下のページングリンクの遷移先に # フラグメントとして付与する。
		 * 1画面スクロール構成の一番下のセクションなので、付けないとページ送りのたびに
		 * ページ全体がリロードされて h1 の先頭に戻ってしまい、毎回スクロールし直す羽目になる。
		 */
		?>
		<h2 class="pggd-settings-heading" id="pggd-protected-pages"><?php esc_html_e( '保護中のページ', 'pageguard' ); ?></h2>
		<p>
			<?php esc_html_e( '現在 BASIC 認証で保護されている投稿の一覧です。対象の投稿タイプの設定を変更しても、既に保護を設定した投稿はここに表示され続けます。', 'pageguard' ); ?>
			<?php esc_html_e( 'サイト表側の URL は、ログイン中の編集者でも認証をスキップしてしまうため、ここにはリンクしていません。内容の確認は編集画面のリンクからお願いします。', 'pageguard' ); ?>
		</p>

		<table class="widefat fixed striped pggd-protected-pages-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'タイトル', 'pageguard' ); ?></th>
					<th scope="col"><?php esc_html_e( '投稿タイプ', 'pageguard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'ステータス', 'pageguard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $query->have_posts() ) : ?>
					<tr>
						<td colspan="3"><?php esc_html_e( '保護中のページはありません。', 'pageguard' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $query->posts as $post_item ) : ?>
						<?php
						$title = get_the_title( $post_item );
						$title = ( '' !== $title ) ? $title : __( '(タイトルなし)', 'pageguard' );

						// フロント側 URL には絶対にリンクしない。編集画面へのリンクだけを出す。
						$edit_link = get_edit_post_link( $post_item->ID );

						$type_object = get_post_type_object( $post_item->post_type );
						$type_label  = $type_object ? $type_object->labels->singular_name : $post_item->post_type;

						$status_object = get_post_status_object( $post_item->post_status );
						$status_label  = $status_object ? $status_object->label : $post_item->post_status;
						?>
						<tr>
							<td>
								<?php if ( $edit_link ) : ?>
									<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $title ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $type_label ); ?></td>
							<td><?php echo esc_html( $status_label ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $query->max_num_pages > 1 ) : ?>
			<div class="pggd-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'pggd_page', '%#%' ) . '#pggd-protected-pages',
							'format'  => '',
							'current' => $paged,
							'total'   => (int) $query->max_num_pages,
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
		<?php
		wp_reset_postdata();
	}
}
