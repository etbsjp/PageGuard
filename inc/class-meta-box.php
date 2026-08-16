<?php
/**
 * 投稿編集画面のメタボックス（BASIC 認証の設定 UI）。
 *
 * 植草（UX）と確定した設計に沿って実装している。
 * ・normal / high に form-table 1枚
 * ・保護のかけ外しは「保護する / 保護しない」のラジオ2択で、確定はエディタの「更新」に集約
 * ・設定済みパスワードは画面に一切出さない
 *
 * ■「保護中」と「パスワードが読める」は別物
 *
 * is_protected() は索引キーだけが残った壊れた状態でも true を返す（fail-closed）。
 * その状態では既存のハッシュが読めないため、パスワードの入力は必須になる。
 * 「保護中か（$is_protected）」と「既存の資格情報が読めるか（$has_credential）」を
 * 混同すると、画面が「入力しなくていい」と言いながらサーバーが弾く、という
 * 出口の無い状態になる。ラベル・placeholder・説明文・必須判定は
 * すべて $has_credential 側で出し分けること。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 投稿編集画面のメタボックス。
 */
class Pggd_Meta_Box {

	/**
	 * メタボックスのHTML ID。JavaScript 側の起点にもなる。
	 */
	const BOX_ID = 'pggd_meta_box';

	/**
	 * nonce のアクション名の接頭辞。実際には投稿IDを繋げて使う。
	 */
	const NONCE_ACTION = 'pggd_save_meta_box';

	/**
	 * nonce の input 名。
	 */
	const NONCE_NAME = 'pggd_meta_box_nonce';

	/**
	 * 状態表示を取り直す AJAX の nonce アクション名。
	 */
	const STATE_NONCE_ACTION = 'pggd_get_state';

	/**
	 * 前後から落とす空白文字の集合（正規表現の文字クラス用）。
	 *
	 * JavaScript の String.prototype.trim() が落とす文字と同じものを並べている。
	 * PHP の trim() は " \t\n\r\0\x0B" しか落とさず、全角スペース（U+3000）などが残る。
	 * ここがずれると、JavaScript 側は空白を落として「入力あり」と判定して通すのに
	 * サーバー側は空白を含んだまま「使えない文字がある」と弾く、という
	 * 利用者から見て意味の分からない食い違いが起きる。
	 */
	const TRIM_CHARS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

	/**
	 * 入力値の前後から空白を落とす。
	 *
	 * @param string $value 入力値。
	 * @return string 前後の空白を落とした値。
	 */
	private static function trim_input( $value ) {
		$value   = (string) $value;
		$pattern = '/^[' . self::TRIM_CHARS . ']+|[' . self::TRIM_CHARS . ']+$/u';
		$trimmed = preg_replace( $pattern, '', $value );

		// 不正な UTF-8 の場合 preg_replace() は null を返す。
		// その値はどのみち非 ASCII として弾かれるので、素の trim() に落とす。
		return ( null === $trimmed ) ? trim( $value ) : $trimmed;
	}

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_filter( 'redirect_post_location', array( __CLASS__, 'mark_metabox_loader_redirect' ) );
		add_action( 'wp_ajax_pggd_get_state', array( __CLASS__, 'ajax_get_state' ) );
	}

	/**
	 * この投稿でメタボックスを出すべきかを返す。
	 *
	 * 対象投稿タイプでなくても、既に保護されている投稿では出す。
	 * そうしないと、設定で投稿タイプを外したあとに解除できなくなるため。
	 *
	 * @param WP_Post|null $post      投稿オブジェクト。
	 * @param string       $post_type 投稿タイプ名。
	 * @return bool 表示するなら true。
	 */
	private static function is_target( $post, $post_type ) {
		if ( in_array( $post_type, pggd_get_target_post_types(), true ) ) {
			return true;
		}
		if ( $post instanceof WP_Post && Pggd_Credentials::is_protected( $post->ID ) ) {
			return true;
		}
		return false;
	}

	/**
	 * メタボックスを登録する。
	 *
	 * @param string       $post_type 投稿タイプ名。
	 * @param WP_Post|null $post      投稿オブジェクト。
	 * @return void
	 */
	public static function register( $post_type, $post ) {
		if ( ! self::is_target( $post, $post_type ) ) {
			return;
		}
		if ( $post instanceof WP_Post && ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__( 'BASIC 認証（PageGuard）', 'pageguard' ),
			array( __CLASS__, 'render' ),
			$post_type,
			'normal',
			'high',
			// ブロックエディタでもクラシックメタボックス領域に出す。
			array( '__block_editor_compatible_meta_box' => true )
		);
	}

	/**
	 * パスワード欄のラベルを返す。
	 *
	 * 「保護中か」ではなく「既存のパスワードが読めるか」で切り替える。
	 *
	 * @param bool $has_credential 既存の資格情報が読めるかどうか。
	 * @return string ラベル。
	 */
	private static function get_password_label( $has_credential ) {
		return $has_credential
			? __( '新しいパスワード', 'pageguard' )
			: __( 'パスワード', 'pageguard' );
	}

	/**
	 * 保護中の状態表示を出力する。
	 *
	 * ブロックエディタでは保存後にこの部分だけを AJAX で取り直して差し替える。
	 * そのため、この中身は単独で描画できるようにしておく。
	 *
	 * @param WP_Post $post 投稿オブジェクト。
	 * @return void
	 */
	public static function render_state( $post ) {
		$credential   = Pggd_Credentials::get_primary( $post->ID );
		$is_protected = Pggd_Credentials::is_protected( $post->ID );
		$permalink    = get_permalink( $post );

		/*
		 * 読み上げの対象は状態を表す1文だけに絞る。
		 * 下の確認案内まで囲むと、保存のたびに4段落と URL が読み上げられる。
		 * 操作要素（コピーボタン等）もこの外に置くこと。
		 * ライブリージョンの読み上げはフォーカスを移さないため、
		 * 中にボタンがあっても、そこへ到達する手段が案内されない。
		 */
		if ( ! $is_protected ) {
			?>
			<div class="pggd-status" role="status">
				<p class="pggd-status-line">
					<span class="dashicons dashicons-unlock" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'このページは BASIC 認証で保護されていません。', 'pageguard' ); ?></strong>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<div class="pggd-status" role="status">
			<p class="pggd-status-line">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'このページは BASIC 認証で保護されています。', 'pageguard' ); ?></strong>
			</p>
		</div>

		<?php if ( null === $credential ) : ?>
			<div class="notice notice-error inline pggd-state-note">
				<p><?php esc_html_e( '保護中ですが、ユーザー名とパスワードの記録が読み取れませんでした。', 'pageguard' ); ?></p>
				<p><?php esc_html_e( 'このままでは誰も閲覧できません。ユーザー名とパスワードの両方を入力し直して「更新」してください。', 'pageguard' ); ?></p>
			</div>
		<?php else : ?>
			<ul class="pggd-state-list">
				<li>
					<?php
					printf(
						/* translators: %s: 設定済みのユーザー名 */
						esc_html__( '設定済みのユーザー名: %s', 'pageguard' ),
						'<code>' . esc_html( $credential['username'] ) . '</code>'
					);
					?>
				</li>
				<?php if ( $credential['password_updated'] > 0 ) : ?>
					<li>
						<?php
						printf(
							/* translators: %s: パスワードを設定した日時 */
							esc_html__( 'パスワードの設定日時: %s', 'pageguard' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $credential['password_updated'] ) )
						);
						?>
					</li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>

		<div class="notice notice-info inline pggd-state-note">
			<p><?php esc_html_e( 'あなたはこのページの編集権限があるため、ログイン中は認証なしで閲覧できます。', 'pageguard' ); ?></p>
			<?php
			/*
			 * ここをリンクにしてはいけない。クリックで開くのはログイン中の
			 * 新しいタブであり、編集権限で素通りするため
			 * 「認証が壊れていても普通に表示された」と見えてしまう。
			 * この案内自身が、防ぎたい事故を誘発することになる。
			 */
			?>
			<p><?php esc_html_e( '保護を設定したら、次の URL をコピーしてプライベートウィンドウ（シークレットモード）に貼り付けて開き、実際にユーザー名とパスワードで表示できることを必ず確認してください。', 'pageguard' ); ?></p>
			<?php if ( $permalink ) : ?>
				<p class="pggd-url-row" id="pggd-url-row">
					<code class="pggd-url" id="pggd-verify-url"><?php echo esc_html( $permalink ); ?></code>
				</p>
			<?php endif; ?>
			<p><?php esc_html_e( 'サーバーの構成によっては認証情報がプラグインに届かず、正しいユーザー名とパスワードでも表示できないことがあります。', 'pageguard' ); ?></p>
			<p><?php esc_html_e( '表示できなかった場合は、サーバー側の設定変更が必要です。サイトの管理会社にご相談ください。', 'pageguard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * パスワード欄の説明文を出力する。
	 *
	 * @param bool $is_protected   保護中かどうか。
	 * @param bool $has_credential 既存の資格情報が読めるかどうか。
	 * @return void
	 */
	public static function render_password_description( $is_protected, $has_credential ) {
		if ( $has_credential ) {
			?>
			<?php esc_html_e( '変更する場合だけ、新しいパスワードを入力してください。', 'pageguard' ); ?>
			<?php esc_html_e( '空欄のままなら、現在のパスワードがそのまま使われます。', 'pageguard' ); ?>
			<?php esc_html_e( '変更したパスワードは保存後に画面へ表示できなくなるため、控えを取ってから「更新」してください。', 'pageguard' ); ?>
			<br>
			<strong><?php esc_html_e( 'パスワードだけを空欄にしても保護は解除されません。解除するには「保護しない」を選んでから「更新」してください。', 'pageguard' ); ?></strong>
			<?php
			return;
		}

		if ( $is_protected ) {
			// 保護中なのに既存のパスワードが読めない＝復旧のための入力を求めている場面。
			// 新規設定と同じ調子で書くと、いま何を求められているのかがぼやける。
			?>
			<?php esc_html_e( 'このページの保護を続けるには、ユーザー名とパスワードの両方を入力し直してください。', 'pageguard' ); ?>
			<?php esc_html_e( '保存後は画面に表示できなくなるため、控えを取ってから「更新」してください。', 'pageguard' ); ?>
			<br>
			<strong><?php esc_html_e( 'パスワードだけを空欄にしても保護は解除されません。解除するには「保護しない」を選んでから「更新」してください。', 'pageguard' ); ?></strong>
			<?php
			return;
		}
		?>
		<?php esc_html_e( 'ページを閲覧する人に伝えるパスワードです。', 'pageguard' ); ?>
		<?php esc_html_e( '保存後は画面に表示できなくなるため、控えを取ってから「更新」してください。', 'pageguard' ); ?>
		<?php
	}

	/**
	 * メタボックスの中身を出力する。
	 *
	 * @param WP_Post $post 投稿オブジェクト。
	 * @return void
	 */
	public static function render( $post ) {
		$credential     = Pggd_Credentials::get_primary( $post->ID );
		$has_credential = ( null !== $credential );
		$is_protected   = Pggd_Credentials::is_protected( $post->ID );
		$username       = $has_credential ? $credential['username'] : '';

		// nonce のアクション名に投稿IDを含める。
		// save_post は1リクエスト中に保存されたすべての投稿で発火するため、
		// 固定のアクション名だと、他プラグインが同じリクエストで別投稿を
		// 更新したときに、その投稿の保護設定を書き換えてしまう。
		wp_nonce_field( self::NONCE_ACTION . '_' . (int) $post->ID, self::NONCE_NAME );
		?>
		<div id="pggd-meta-box-body">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( '現在の状態', 'pageguard' ); ?></th>
						<td>
							<?php
							/*
							 * 保存後に JavaScript が中身を差し替える箱。
							 * ライブリージョン（role="status"）はこの中の状態文だけに付ける。
							 * ここ全体に付けると、確認案内や URL まで毎回読み上げられる。
							 */
							?>
							<div id="pggd-state"><?php self::render_state( $post ); ?></div>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'このページの保護', 'pageguard' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'このページの保護', 'pageguard' ); ?></legend>
								<p class="pggd-radio-row">
									<label for="pggd_protect_on">
										<input type="radio" name="pggd_protect" id="pggd_protect_on" value="1" <?php checked( $is_protected ); ?>>
										<?php esc_html_e( '保護する', 'pageguard' ); ?>
									</label>
								</p>
								<p class="pggd-radio-row">
									<label for="pggd_protect_off">
										<input type="radio" name="pggd_protect" id="pggd_protect_off" value="0" <?php checked( ! $is_protected ); ?>
											aria-describedby="pggd-unprotect-warning">
										<?php esc_html_e( '保護しない', 'pageguard' ); ?>
									</label>
								</p>
								<?php
								/*
								 * 警告は保護中のページでだけ出す。未保護のページでは
								 * 初期状態が「保護しない」なので、出しっぱなしにすると
								 * すべての編集画面に警告が並び、本当に効かせたい
								 * 「保護中のページを解除するとき」に読まれなくなる。
								 * 保護中のときはサーバー側で隠さないので、
								 * JavaScript が動かない環境では常に見えている（安全側）。
								 */
								?>
								<div id="pggd-unprotect-warning" class="notice notice-warning inline pggd-unprotect-warning" role="status" <?php echo $is_protected ? '' : 'hidden'; ?>>
									<p>
										<?php esc_html_e( '「保護しない」を選んだまま「更新」すると、BASIC 認証が解除され、URL を知っている人なら誰でもこのページを閲覧できるようになります。', 'pageguard' ); ?>
										<br><?php esc_html_e( '設定済みのパスワードは削除され、元に戻すことはできません（再設定が必要です）。', 'pageguard' ); ?>
									</p>
								</div>
							</fieldset>
						</td>
					</tr>

					<?php
					/*
					 * 文字種の注意はユーザー名とパスワードに共通する。
					 * どちらか一方の行の中に置くと、もう一方を入力している人の
					 * 視線には入らない（画面上は2行ぶん離れてしまう）。
					 * 両方の入力欄より前に、独立した1行として置く。
					 */
					?>
					<tr>
						<th scope="row"><?php esc_html_e( '入力のきまり', 'pageguard' ); ?></th>
						<td>
							<p class="description" id="pggd-charset-note">
								<?php esc_html_e( 'ユーザー名とパスワードは、半角の英数字と記号で設定してください。', 'pageguard' ); ?>
								<?php esc_html_e( '全角文字や日本語は、ブラウザによって正しく送信されず認証できないことがあります。', 'pageguard' ); ?>
								<?php esc_html_e( 'パスワードは 8 文字以上を目安にしてください。', 'pageguard' ); ?>
								<?php
								/*
								 * 認証側は受け取った値を trim しないため、これを書いておかないと
								 * 「末尾に空白が付いた文字列を貼り付けて保存し、その文字列を
								 * そのまま閲覧者へ伝えたのに認証が通らない」という食い違いが残る。
								 */
								?>
								<?php esc_html_e( '前後の空白は自動的に取り除かれます。', 'pageguard' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pggd_username"><?php esc_html_e( 'ユーザー名', 'pageguard' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="pggd_username" name="pggd_username"
								value="<?php echo esc_attr( $username ); ?>"
								autocomplete="off" spellcheck="false" autocapitalize="off"
								aria-describedby="pggd-username-description pggd-charset-note">
							<p class="description" id="pggd-username-description">
								<?php esc_html_e( 'ページを閲覧する人に伝えるユーザー名です。', 'pageguard' ); ?>
								<?php esc_html_e( 'コロン（:）は BASIC 認証の仕組み上使えません。', 'pageguard' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pggd_password" id="pggd-password-label"><?php echo esc_html( self::get_password_label( $has_credential ) ); ?></label>
						</th>
						<td>
							<span class="pggd-password-row">
								<input type="password" class="regular-text" id="pggd_password" name="pggd_password"
									value=""
									<?php if ( $has_credential ) : ?>
										placeholder="<?php esc_attr_e( '変更する場合のみ入力', 'pageguard' ); ?>"
									<?php endif; ?>
									autocomplete="new-password" spellcheck="false" autocapitalize="off"
									aria-describedby="pggd-password-description pggd-charset-note">
								<?php
								/*
								 * パスワードの表示切替ボタンは JavaScript で組み立てる。
								 * サーバー側で出すと、JavaScript が無効な環境では
								 * 押しても何も起きない死んだボタンとして残ってしまう。
								 */
								?>
								<span id="pggd-password-actions"></span>
							</span>
							<p class="description" id="pggd-password-description"><?php self::render_password_description( $is_protected, $has_credential ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="pggd-media-note">
				<p>
					<strong><?php esc_html_e( 'メディアファイルへの直リンクは保護できません。', 'pageguard' ); ?></strong>
					<?php esc_html_e( 'このページに貼った画像や PDF などのファイル URL に直接アクセスされた場合、内容は表示されてしまいます。', 'pageguard' ); ?>
					<?php esc_html_e( 'これらのファイルは WordPress を経由せず Web サーバーが直接返すためです。', 'pageguard' ); ?>
				</p>
				<p><?php esc_html_e( '本当に見せたくないファイルは、このページに貼らずに、別の手段でお渡しください。', 'pageguard' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * 状態表示を取り直す AJAX ハンドラ。
	 *
	 * ブロックエディタは保存後もメタボックスを再描画しないため、
	 * 保存が終わったタイミングでこの内容を取りに来て差し替える。
	 * 保存結果の通知も、ここに載せてエディタ内へ即時に出す
	 * （そうしないと警告・エラーが「次に編集画面を開いたとき」までは届かない）。
	 *
	 * @return void
	 */
	public static function ajax_get_state() {
		// 第3引数 false で die させず、JSON で理由を返す。
		// 既定の die は本文が "-1" になり、JavaScript 側では
		// JSON として解釈できてしまうため失敗に気づけない。
		if ( ! check_ajax_referer( self::STATE_NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( '編集画面を開いてから時間が経ったため、保存後の状態を取得できませんでした。', 'pageguard' ),
				)
			);
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'この投稿の状態を取得する権限がありません。', 'pageguard' ),
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error(
				array(
					'code'    => 'not_found',
					'message' => __( '対象の投稿が見つかりませんでした。', 'pageguard' ),
				)
			);
		}

		$credential     = Pggd_Credentials::get_primary( $post_id );
		$has_credential = ( null !== $credential );
		$is_protected   = Pggd_Credentials::is_protected( $post_id );

		ob_start();
		self::render_state( $post );
		$state_html = ob_get_clean();

		ob_start();
		self::render_password_description( $is_protected, $has_credential );
		$description_html = ob_get_clean();

		$notices   = self::get_notices_for_js( $post_id );
		$has_error = false;
		foreach ( $notices as $notice ) {
			if ( 'error' === $notice['status'] ) {
				$has_error = true;
			}
		}

		wp_send_json_success(
			array(
				'state'               => $state_html,
				'isProtected'         => $is_protected ? 1 : 0,
				'hasCredential'       => $has_credential ? 1 : 0,
				'username'            => $has_credential ? $credential['username'] : '',
				'passwordLabel'       => self::get_password_label( $has_credential ),
				'passwordDescription' => $description_html,
				'passwordPlaceholder' => $has_credential ? __( '変更する場合のみ入力', 'pageguard' ) : '',
				'notices'             => $notices,
				// エラーで拒否された場合、入力欄を保存前の値へ戻さないための目印。
				// 「もう一度お試しください」と言われた時点で入力が消えていると、
				// 「もう一度」が入力し直しからになってしまう。
				'hasError'            => $has_error ? 1 : 0,
			)
		);
	}

	/**
	 * 保存結果の通知を、エディタ内通知に使える形へ組み立てる。
	 *
	 * id を通知コードから作るのは、同じ種類の通知が積み上がらないようにするため。
	 * ブロックエディタの通知は自動では消えないので、id が無いと
	 * パスワードを3回変更しただけで同じ通知が3つ並ぶ。
	 *
	 * 成功系は snackbar（数秒で消える）にする。コアの「更新しました」と同じ扱い。
	 * 警告とエラーは、見落とすと事故になるので上部の固定通知に残す。
	 *
	 * @param int $post_id 投稿ID。
	 * @return array id / status / text / type を持つ配列の配列。
	 */
	private static function get_notices_for_js( $post_id ) {
		$messages = self::get_notice_messages();
		$notices  = array();
		$errors   = array();

		foreach ( self::consume_notices( $post_id ) as $code ) {
			if ( ! isset( $messages[ $code ] ) ) {
				continue;
			}
			if ( 'error' === $messages[ $code ]['type'] ) {
				$errors[] = $messages[ $code ]['text'];
				continue;
			}
			$notices[] = array(
				'id'     => 'pggd-' . $code,
				'status' => $messages[ $code ]['type'],
				'text'   => $messages[ $code ]['text'],
				'type'   => ( 'success' === $messages[ $code ]['type'] ) ? 'snackbar' : 'default',
			);
		}

		if ( ! empty( $errors ) ) {
			// エラーは1件にまとめる（原因ごとに並べると同じ末尾が繰り返される）。
			$notices[] = array(
				'id'     => 'pggd-errors',
				'status' => 'error',
				'text'   => implode(
					' ',
					array_merge(
						array( __( 'BASIC 認証の設定を保存できませんでした。', 'pageguard' ) ),
						$errors,
						array( __( '保護の状態は変更していません。', 'pageguard' ) )
					)
				),
				'type'   => 'default',
			);
		}

		return $notices;
	}

	/**
	 * メタボックスの保存処理。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	public static function save( $post_id ) {
		// 自動保存とリビジョンでは何もしない。
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// メタボックスを含まない保存経路（REST・クイック編集・一括編集など）は対象外。
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		// nonce は投稿IDごとに発行している。save_post は1リクエスト中に保存された
		// すべての投稿で発火するため、これで「今まさに編集画面から送られてきた投稿」
		// 以外に設定が適用されるのを防ぐ。
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION . '_' . (int) $post_id ) ) {
			return;
		}

		// 送信されたフォームの対象投稿と一致することも確かめる。
		if ( ! isset( $_POST['post_ID'] ) || (int) $_POST['post_ID'] !== (int) $post_id ) {
			return;
		}

		// nonce は未ログインでも同値になり得るため、権限チェックを必ず併せて行う。
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$protect = isset( $_POST['pggd_protect'] ) ? (string) wp_unslash( $_POST['pggd_protect'] ) : '';
		if ( '0' !== $protect && '1' !== $protect ) {
			// ラジオが送られていない＝この UI からの保存ではない。状態を変えない。
			return;
		}

		// ユーザー名・パスワードともサニタイズしない。
		// sanitize_text_field() は "%2f" のような並びを削除するため、
		// "ab%2fcd" が "abcd" になり、伝えた文字列では認証できなくなる。
		// 使えない文字は黙って落とさず、検証で弾いて利用者に知らせる。
		$username = isset( $_POST['pggd_username'] ) ? (string) wp_unslash( $_POST['pggd_username'] ) : '';
		$password = isset( $_POST['pggd_password'] ) ? (string) wp_unslash( $_POST['pggd_password'] ) : '';

		/*
		 * 前後の空白は両方とも落とす。
		 * メールやチャットからコピーすると末尾に半角スペースが紛れ込むことは日常的に起きる。
		 * 半角スペースは使える文字の範囲内なのでエラーにもならず、そのままハッシュ化され、
		 * 「伝えたパスワードでは通らないのに、設定済みパスワードは再表示できないので
		 * 原因を確かめようがない」という最悪の形になる。
		 * BASIC 認証のパスワードに前後の空白を意図して入れる利用者はまずいない。
		 */
		$password_raw = $password;
		$username     = self::trim_input( $username );
		$password     = self::trim_input( $password );

		/*
		 * 空白だけが入力された場合、trim の結果は空文字になる。
		 * 既存の資格情報が読める状態だと、これは「変更しない」と同じ扱いになり、
		 * 何も通知しないと「パスワードを変えたのに何も言われない、実は変わっていない」
		 * という沈黙した失敗になる。入力を黙って捨てない方針に合わせて拾っておく。
		 */
		$password_was_whitespace = ( '' === $password && '' !== $password_raw );

		$was_protected = Pggd_Credentials::is_protected( $post_id );
		$current       = Pggd_Credentials::get_primary( $post_id );

		/* ---------- 解除 ---------- */
		if ( '0' === $protect ) {
			if ( $was_protected ) {
				Pggd_Credentials::delete( $post_id );
				self::add_notice( $post_id, 'unprotected' );
				return;
			}
			/*
			 * 「保護する」への切り替えを忘れたまま入力した場合、黙って捨てると
			 * 「設定したつもりで無保護」になる。ただし判定材料をユーザー名まで
			 * 広げると、解除した直後に欄が残っているだけで警告が出てしまう。
			 * 保護の意思表示として強いパスワード欄だけで判定する。
			 */
			if ( '' !== $password ) {
				self::add_notice( $post_id, 'ignored_input' );
			}
			return;
		}

		/* ---------- 保護する ---------- */
		$errors = array();

		if ( '' === $username ) {
			$errors[] = 'username_empty';
		} elseif ( preg_match( '/[\x00-\x1F\x7F]/', $username ) ) {
			$errors[] = 'username_control_chars';
		} elseif ( false !== strpos( $username, ':' ) ) {
			$errors[] = 'username_colon';
		} elseif ( preg_match( '/[^\x20-\x7E]/', $username ) ) {
			$errors[] = 'username_non_ascii';
		}

		if ( '' === $password && null === $current ) {
			// 既存のハッシュが読めない場合は、空欄の据え置きができない。
			$errors[] = 'password_empty';
		} elseif ( '' !== $password && preg_match( '/[\x00-\x1F\x7F]/', $password ) ) {
			// 制御文字を黙って除去すると、利用者が意図したものと違う
			// パスワードがハッシュ化される。非 ASCII と同じくエラーで弾く。
			$errors[] = 'password_control_chars';
		} elseif ( '' !== $password && preg_match( '/[^\x20-\x7E]/', $password ) ) {
			$errors[] = 'password_non_ascii';
		}

		if ( ! empty( $errors ) ) {
			// 入力不備でも「保護しない」状態へは落とさない。直前の状態を保ったままエラーを返す。
			self::add_notice( $post_id, $errors );
			return;
		}

		$name_changed  = ( null !== $current ) && ! hash_equals( $current['username'], $username );
		$password_sent = ( '' !== $password );

		if ( ! Pggd_Credentials::save_primary( $post_id, $username, $password ) ) {
			self::add_notice( $post_id, 'save_failed' );
			return;
		}

		$notices = array();
		if ( ! $was_protected ) {
			$notices[] = 'protected';
		} else {
			if ( $password_sent ) {
				$notices[] = 'password_changed';
			} elseif ( $password_was_whitespace ) {
				// 変更するつもりで打ったのに何も起きなかった、を沈黙させない。
				$notices[] = 'password_whitespace';
			}
			if ( $name_changed ) {
				$notices[] = 'username_changed';
			}
		}

		if ( ! empty( $notices ) ) {
			self::add_notice( $post_id, $notices );
		}
	}

	/**
	 * 保存結果の通知コードを一時保存する。
	 *
	 * 保存後にリダイレクトが挟まるため、transient に預けて次の描画で取り出す。
	 *
	 * @param int          $post_id 投稿ID。
	 * @param array|string $codes   通知コード（配列または単一の文字列）。
	 * @return void
	 */
	private static function add_notice( $post_id, $codes ) {
		$codes = (array) $codes;
		set_transient( self::notice_key( $post_id ), $codes, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * ブロックエディタのメタボックス保存に伴うリダイレクト先へ目印を付ける。
	 *
	 * ブロックエディタはメタボックスを post.php へ POST するが、
	 * その応答（リダイレクト先の編集画面 HTML を含む）を読み捨てる。
	 * 目印が無いと、この「誰も見ない画面」の admin_notices が
	 * 通知の transient を消費してしまい、利用者に通知が届かなくなる。
	 *
	 * @param string $location リダイレクト先 URL。
	 * @return string リダイレクト先 URL。
	 */
	public static function mark_metabox_loader_redirect( $location ) {
		if ( isset( $_REQUEST['meta-box-loader'] ) ) {
			$location = add_query_arg( 'pggd_skip_notice', '1', $location );
		}
		return $location;
	}

	/**
	 * 保存結果の通知コードを取り出して破棄する。
	 *
	 * @param int $post_id 投稿ID。
	 * @return array 通知コードの配列。
	 */
	private static function consume_notices( $post_id ) {
		$key   = self::notice_key( $post_id );
		$codes = get_transient( $key );
		if ( ! is_array( $codes ) ) {
			return array();
		}
		delete_transient( $key );
		return $codes;
	}

	/**
	 * 通知用 transient のキーを組み立てる。
	 *
	 * ユーザーと投稿の組でキーを分け、他の編集者の通知が混ざらないようにする。
	 *
	 * @param int $post_id 投稿ID。
	 * @return string transient のキー。
	 */
	private static function notice_key( $post_id ) {
		return 'pggd_notice_' . get_current_user_id() . '_' . (int) $post_id;
	}

	/**
	 * 投稿編集画面に保存結果の通知を出す（クラシックエディタ用）。
	 *
	 * ブロックエディタでは ajax_get_state() が保存直後に通知を持ち帰り、
	 * エディタ内通知として出す。transient はどちらか一方だけが消費するため、
	 * 二重に表示されることはない。
	 *
	 * @return void
	 */
	public static function render_admin_notices() {
		// メタボックス保存そのもののリクエスト、および
		// 読み捨てられるリダイレクト先では通知を消費しない。
		if ( isset( $_REQUEST['meta-box-loader'] ) || isset( $_GET['pggd_skip_notice'] ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		self::print_notices( self::consume_notices( $post->ID ) );
	}

	/**
	 * 通知コードに対応するメッセージを出力する。
	 *
	 * エラーは1つの通知にまとめる。原因ごとに通知を並べると
	 * 「保護の状態は変更していません」が何度も繰り返されて読みにくいため。
	 *
	 * @param array $codes 通知コードの配列。
	 * @return void
	 */
	private static function print_notices( $codes ) {
		if ( empty( $codes ) ) {
			return;
		}

		$messages = self::get_notice_messages();
		$errors   = array();
		$others   = array();

		foreach ( $codes as $code ) {
			if ( ! isset( $messages[ $code ] ) ) {
				continue;
			}
			if ( 'error' === $messages[ $code ]['type'] ) {
				$errors[] = $messages[ $code ]['text'];
			} else {
				$others[] = $messages[ $code ];
			}
		}

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error is-dismissible">';
			echo '<p>' . esc_html__( 'BASIC 認証の設定を保存できませんでした。', 'pageguard' ) . '</p>';
			echo '<ul class="pggd-error-list">';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul>';
			echo '<p>' . esc_html__( '保護の状態は変更していません。', 'pageguard' ) . '</p>';
			echo '</div>';
		}

		foreach ( $others as $message ) {
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $message['class'] ),
				esc_html( $message['text'] )
			);
		}
	}

	/**
	 * 通知コードとメッセージの対応表を返す。
	 *
	 * type は core/notices の status としてもそのまま使う。
	 *
	 * @return array 通知コードをキーとし、type / class / text を持つ配列。
	 */
	private static function get_notice_messages() {
		return array(
			'protected'              => array(
				'type'  => 'success',
				'class' => 'notice-success',
				'text'  => __( 'BASIC 認証で保護しました。このページの閲覧にはユーザー名とパスワードが必要になります。', 'pageguard' ),
			),
			'password_changed'       => array(
				'type'  => 'success',
				'class' => 'notice-success',
				'text'  => __( 'パスワードを変更しました。閲覧する方への連絡をお忘れなく。', 'pageguard' ),
			),
			'username_changed'       => array(
				'type'  => 'success',
				'class' => 'notice-success',
				'text'  => __( 'ユーザー名を変更しました。閲覧する方への連絡をお忘れなく。', 'pageguard' ),
			),
			'unprotected'            => array(
				'type'  => 'warning',
				'class' => 'notice-warning',
				'text'  => __( 'BASIC 認証を解除しました。このページは URL を知っている人なら誰でも閲覧できる状態です。', 'pageguard' ),
			),
			'password_whitespace'    => array(
				'type'  => 'warning',
				'class' => 'notice-warning',
				'text'  => __( '入力されたパスワードが空白だけだったため、パスワードは変更していません。変更する場合は、空白以外の文字を入力してください。', 'pageguard' ),
			),
			'ignored_input'          => array(
				'type'  => 'warning',
				'class' => 'notice-warning',
				'text'  => __( '「保護しない」が選ばれていたため、入力したパスワードは保存していません。保護するには「保護する」を選んでから「更新」してください。', 'pageguard' ),
			),
			'username_empty'         => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名が入力されていません。', 'pageguard' ),
			),
			'username_colon'         => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名にコロン（:）は使えません。', 'pageguard' ),
			),
			'username_non_ascii'     => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名に、半角の英数字と記号以外の文字（全角文字や日本語など）が含まれています。', 'pageguard' ),
			),
			'username_control_chars' => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名に、改行やタブなどの目に見えない文字が含まれています。他の場所からコピーした場合は、入力欄で選び直して手で入力してください。', 'pageguard' ),
			),
			'password_empty'         => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'パスワードが入力されていません。', 'pageguard' ),
			),
			'password_non_ascii'     => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'パスワードに、半角の英数字と記号以外の文字（全角文字や日本語など）が含まれています。', 'pageguard' ),
			),
			'password_control_chars' => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'パスワードに、改行やタブなどの目に見えない文字が含まれています。他の場所からコピーした場合は、入力欄で選び直して手で入力してください。', 'pageguard' ),
			),
			'save_failed'            => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'データベースへの保存に失敗しました。時間をおいて、もう一度お試しください。', 'pageguard' ),
			),
		);
	}

	/**
	 * 投稿編集画面にメタボックス用のスタイルとスクリプトを読み込む。
	 *
	 * @param string $hook 現在の管理画面のフック名。
	 * @return void
	 */
	public static function enqueue_editor_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! self::is_target( $post, $post->post_type ) ) {
			return;
		}

		wp_enqueue_style(
			'pggd-admin',
			plugins_url( 'css/admin.css', __FILE__ ),
			array(),
			PGGD_VERSION
		);

		// ブロックエディタのときだけ wp-data / wp-notices を依存に入れる。
		// クラシックエディタでは不要なので読み込ませない。
		$deps = array();
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			$deps[] = 'wp-data';
			$deps[] = 'wp-notices';
		}

		wp_enqueue_script(
			'pggd-editor',
			plugins_url( 'js/pageguard-editor.js', __FILE__ ),
			$deps,
			PGGD_VERSION,
			true
		);

		wp_localize_script(
			'pggd-editor',
			'PggdEditorData',
			array(
				// 「保護中か」と「既存のパスワードが読めるか」は別物。
				// 必須判定と表示の出し分けには hasCredential を使う。
				'isProtected'   => Pggd_Credentials::is_protected( $post->ID ) ? 1 : 0,
				'hasCredential' => ( null !== Pggd_Credentials::get_primary( $post->ID ) ) ? 1 : 0,
				'postId'        => (int) $post->ID,
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'stateNonce'    => wp_create_nonce( self::STATE_NONCE_ACTION ),
				'i18n'          => array(
					'showPassword'         => __( 'パスワードを表示', 'pageguard' ),
					'hidePassword'         => __( 'パスワードを隠す', 'pageguard' ),
					'showLabel'            => __( '表示', 'pageguard' ),
					'hideLabel'            => __( '隠す', 'pageguard' ),
					'copyLabel'            => __( 'URL をコピー', 'pageguard' ),
					'copiedLabel'          => __( 'コピーしました', 'pageguard' ),
					'verifyUrlLabel'       => __( '動作確認用のページ URL', 'pageguard' ),
					'blockedPrefix'        => __( 'BASIC 認証の設定に不足があるため、更新できません。', 'pageguard' ),
					'blockedHelp'          => __( '投稿下部の「BASIC 認証（PageGuard）」で入力してください。保護しない場合は「保護しない」を選ぶと更新できます。', 'pageguard' ),
					'blockedAction'        => __( '設定欄へ移動', 'pageguard' ),
					'bothEmpty'            => __( 'ユーザー名とパスワードが入力されていません。', 'pageguard' ),
					'usernameEmpty'        => __( 'ユーザー名が入力されていません。', 'pageguard' ),
					'usernameColon'        => __( 'ユーザー名にコロン（:）は使えません。', 'pageguard' ),
					'usernameNonAscii'     => __( 'ユーザー名に、半角の英数字と記号以外の文字が含まれています。', 'pageguard' ),
					'usernameControlChars' => __( 'ユーザー名に、改行やタブなどの目に見えない文字が含まれています。他の場所からコピーした場合は、入力欄で選び直して手で入力してください。', 'pageguard' ),
					'passwordEmpty'        => __( 'パスワードが入力されていません。', 'pageguard' ),
					'passwordWhitespace'   => __( 'パスワードに空白以外の文字が入力されていません。変更しない場合は、パスワード欄を空にしてください。', 'pageguard' ),
					'passwordNonAscii'     => __( 'パスワードに、半角の英数字と記号以外の文字が含まれています。', 'pageguard' ),
					'passwordControlChars' => __( 'パスワードに、改行やタブなどの目に見えない文字が含まれています。他の場所からコピーした場合は、入力欄で選び直して手で入力してください。', 'pageguard' ),
					'stateFailed'          => __( '保存の送信自体は完了しています。ただし保存後の状態を取得できなかったため、この画面の表示は当てになりません。編集画面を再読み込みして確認してください。', 'pageguard' ),
					'stateFailedPrefix'    => __( '保存の送信自体は完了しています。', 'pageguard' ),
					'reloadHint'           => __( 'この画面の表示は当てにならないため、編集画面を再読み込みして確認してください。', 'pageguard' ),
					'reloadLabel'          => __( '編集画面を再読み込みする', 'pageguard' ),
				),
			)
		);
	}
}
