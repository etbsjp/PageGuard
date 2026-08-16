<?php
/**
 * 投稿編集画面のメタボックス（BASIC 認証の設定 UI）。
 *
 * 植草（UX）と確定した設計に沿って実装している。
 * ・normal / high に form-table 1枚
 * ・保護のかけ外しは「保護する / 保護しない」のラジオ2択で、確定はエディタの「更新」に集約
 * ・設定済みパスワードは画面に一切出さない
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
	 * @param bool $is_protected 保護中かどうか。
	 * @return string ラベル。
	 */
	private static function get_password_label( $is_protected ) {
		return $is_protected
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

		if ( ! $is_protected ) {
			?>
			<p class="pggd-status-line">
				<span class="dashicons dashicons-unlock" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'このページは BASIC 認証で保護されていません。', 'pageguard' ); ?></strong>
			</p>
			<?php
			return;
		}
		?>
		<p class="pggd-status-line">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'このページは BASIC 認証で保護されています。', 'pageguard' ); ?></strong>
		</p>

		<?php if ( null === $credential ) : ?>
			<div class="notice notice-error inline pggd-state-note">
				<p><?php esc_html_e( '保護中ですが、ユーザー名とパスワードの記録が読み取れませんでした。', 'pageguard' ); ?></p>
				<p><?php esc_html_e( 'このままでは誰も閲覧できません。ユーザー名とパスワードを入力し直して「更新」してください。', 'pageguard' ); ?></p>
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
			<p>
				<?php
				printf(
					/* translators: 1: 対象ページへのリンク開始タグ, 2: リンク終了タグ */
					esc_html__( '保護を設定したら、プライベートウィンドウ（シークレットモード）で %1$sこのページ%2$s を開き、実際にユーザー名とパスワードで表示できることを必ず確認してください。', 'pageguard' ),
					$permalink ? '<a href="' . esc_url( $permalink ) . '" target="_blank" rel="noopener noreferrer">' : '<strong>',
					$permalink ? '</a>' : '</strong>'
				);
				?>
				<?php esc_html_e( 'サーバーの構成によっては認証情報がプラグインに届かず、正しいユーザー名とパスワードでも表示できないことがあります。', 'pageguard' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * パスワード欄の説明文を出力する。
	 *
	 * @param bool $is_protected 保護中かどうか。
	 * @return void
	 */
	public static function render_password_description( $is_protected ) {
		if ( $is_protected ) {
			?>
			<?php esc_html_e( '変更する場合だけ、新しいパスワードを入力してください。', 'pageguard' ); ?>
			<?php esc_html_e( '空欄のままなら、現在のパスワードがそのまま使われます。', 'pageguard' ); ?>
			<?php esc_html_e( '設定済みのパスワードは、安全のため画面には表示できません。', 'pageguard' ); ?>
			<?php esc_html_e( '変更した場合は、保存後に表示できなくなるため控えを取ってから「更新」してください。', 'pageguard' ); ?>
			<br>
			<strong><?php esc_html_e( 'パスワードだけを空欄にしても保護は解除されません。解除するには「保護しない」を選んでから「更新」してください。', 'pageguard' ); ?></strong>
			<br>
			<?php esc_html_e( '半角の英数字と記号で、8 文字以上を目安に設定してください。', 'pageguard' ); ?>
			<?php esc_html_e( '全角文字や日本語は、ブラウザによって正しく送信されず認証できないことがあります。', 'pageguard' ); ?>
			<?php
			return;
		}
		?>
		<?php esc_html_e( 'ページを閲覧する人に伝えるパスワードです。', 'pageguard' ); ?>
		<?php esc_html_e( '保存後は画面に表示できなくなるため、控えを取ってから「更新」してください。', 'pageguard' ); ?>
		<br>
		<?php esc_html_e( '半角の英数字と記号で、8 文字以上を目安に設定してください。', 'pageguard' ); ?>
		<?php esc_html_e( '全角文字や日本語は、ブラウザによって正しく送信されず認証できないことがあります。', 'pageguard' ); ?>
		<?php
	}

	/**
	 * メタボックスの中身を出力する。
	 *
	 * @param WP_Post $post 投稿オブジェクト。
	 * @return void
	 */
	public static function render( $post ) {
		$credential   = Pggd_Credentials::get_primary( $post->ID );
		$is_protected = Pggd_Credentials::is_protected( $post->ID );
		$username     = $credential ? $credential['username'] : '';

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

					<tr>
						<th scope="row">
							<label for="pggd_username"><?php esc_html_e( 'ユーザー名', 'pageguard' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="pggd_username" name="pggd_username"
								value="<?php echo esc_attr( $username ); ?>"
								autocomplete="off" spellcheck="false" autocapitalize="off"
								aria-describedby="pggd-username-description">
							<p class="description" id="pggd-username-description">
								<?php esc_html_e( 'ページを閲覧する人に伝えるユーザー名です。', 'pageguard' ); ?>
								<?php esc_html_e( '半角の英数字と記号で設定してください。', 'pageguard' ); ?>
								<?php esc_html_e( '全角文字や日本語は、ブラウザによって正しく送信されず認証できないことがあります。', 'pageguard' ); ?>
								<?php esc_html_e( 'コロン（:）は BASIC 認証の仕組み上使えません。', 'pageguard' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pggd_password" id="pggd-password-label"><?php echo esc_html( self::get_password_label( $is_protected ) ); ?></label>
						</th>
						<td>
							<span class="pggd-password-row">
								<input type="password" class="regular-text" id="pggd_password" name="pggd_password"
									value=""
									<?php if ( $is_protected ) : ?>
										placeholder="<?php esc_attr_e( '変更する場合のみ入力', 'pageguard' ); ?>"
									<?php endif; ?>
									autocomplete="new-password" spellcheck="false" autocapitalize="off"
									aria-describedby="pggd-password-description">
								<?php
								/*
								 * パスワードの表示切替ボタンは JavaScript で組み立てる。
								 * サーバー側で出すと、JavaScript が無効な環境では
								 * 押しても何も起きない死んだボタンとして残ってしまう。
								 */
								?>
								<span id="pggd-password-actions"></span>
							</span>
							<p class="description" id="pggd-password-description"><?php self::render_password_description( $is_protected ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="notice notice-info inline pggd-media-note">
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
	 *
	 * @return void
	 */
	public static function ajax_get_state() {
		check_ajax_referer( self::STATE_NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error();
		}

		$is_protected = Pggd_Credentials::is_protected( $post_id );

		ob_start();
		self::render_state( $post );
		$state_html = ob_get_clean();

		ob_start();
		self::render_password_description( $is_protected );
		$description_html = ob_get_clean();

		wp_send_json_success(
			array(
				'state'               => $state_html,
				'hasPassword'         => $is_protected ? 1 : 0,
				'passwordLabel'       => self::get_password_label( $is_protected ),
				'passwordDescription' => $description_html,
				'passwordPlaceholder' => $is_protected ? __( '変更する場合のみ入力', 'pageguard' ) : '',
			)
		);
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

		// ユーザー名は sanitize_text_field() を通さない。
		// あの関数は "%2f" のような並びを削除するため、"ab%2fcd" が "abcd" になり、
		// 利用者へ伝えた文字列では認証できなくなる。
		// ここで落とすのは制御文字だけにして、使えない文字は検証で弾く。
		$username = isset( $_POST['pggd_username'] ) ? (string) wp_unslash( $_POST['pggd_username'] ) : '';
		$username = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $username );
		$username = trim( $username );

		// パスワードもサニタイズしない。文字が落ちると入力どおりに認証できなくなる。
		// ハッシュ化して保存するので、そのまま扱ってよい。
		$password = isset( $_POST['pggd_password'] ) ? (string) wp_unslash( $_POST['pggd_password'] ) : '';
		$password = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $password );

		$was_protected = Pggd_Credentials::is_protected( $post_id );
		$current       = Pggd_Credentials::get_primary( $post_id );

		/* ---------- 解除 ---------- */
		if ( '0' === $protect ) {
			if ( $was_protected ) {
				Pggd_Credentials::delete( $post_id );
				self::add_notice( $post_id, 'unprotected' );
				return;
			}
			// 「保護する」への切り替えを忘れたまま入力だけした場合、
			// 黙って捨てると「設定したつもりで無保護」になる。必ず知らせる。
			if ( '' !== $username || '' !== $password ) {
				self::add_notice( $post_id, 'ignored_input' );
			}
			return;
		}

		/* ---------- 保護する ---------- */
		$errors = array();

		if ( '' === $username ) {
			$errors[] = 'username_empty';
		} elseif ( false !== strpos( $username, ':' ) ) {
			$errors[] = 'username_colon';
		} elseif ( preg_match( '/[^\x20-\x7E]/', $username ) ) {
			$errors[] = 'username_non_ascii';
		}

		if ( '' === $password && null === $current ) {
			// 既存のハッシュが読めない場合は、空欄の据え置きができない。
			$errors[] = 'password_empty';
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
		// ブロックエディタでは編集画面を再読み込みするまで表示できないため、
		// 少し長めに保持する。
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
	 * 投稿編集画面に保存結果の通知を出す。
	 *
	 * クラシックエディタでは保存直後のリダイレクト先で表示される。
	 * ブロックエディタは応答を読み捨てるため、編集画面を次に開いたときに表示される
	 * （ブロックエディタでの入力不備は、そもそも保存前に止めている）。
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
	 * @return array 通知コードをキーとし、type / class / text を持つ配列。
	 */
	private static function get_notice_messages() {
		return array(
			'protected'          => array(
				'type'  => 'success',
				'class' => 'notice-success',
				'text'  => __( 'BASIC 認証で保護しました。このページの閲覧にはユーザー名とパスワードが必要になります。', 'pageguard' ),
			),
			'password_changed'   => array(
				'type'  => 'success',
				'class' => 'notice-success',
				'text'  => __( 'パスワードを変更しました。閲覧する方への連絡をお忘れなく。', 'pageguard' ),
			),
			'username_changed'   => array(
				'type'  => 'success',
				'class' => 'notice-success',
				'text'  => __( 'ユーザー名を変更しました。閲覧する方への連絡をお忘れなく。', 'pageguard' ),
			),
			'unprotected'        => array(
				'type'  => 'warning',
				'class' => 'notice-warning',
				'text'  => __( 'BASIC 認証を解除しました。このページは URL を知っている人なら誰でも閲覧できる状態です。', 'pageguard' ),
			),
			'ignored_input'      => array(
				'type'  => 'warning',
				'class' => 'notice-warning',
				'text'  => __( '「保護しない」が選ばれていたため、入力したユーザー名とパスワードは保存していません。保護するには「保護する」を選んでから「更新」してください。', 'pageguard' ),
			),
			'username_empty'     => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名が入力されていません。', 'pageguard' ),
			),
			'username_colon'     => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名にコロン（:）は使えません。', 'pageguard' ),
			),
			'username_non_ascii' => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名に、半角の英数字と記号以外の文字（全角文字や日本語など）が含まれています。', 'pageguard' ),
			),
			'password_empty'     => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'パスワードが入力されていません。', 'pageguard' ),
			),
			'password_non_ascii' => array(
				'type'  => 'error',
				'class' => 'notice-error',
				'text'  => __( 'パスワードに、半角の英数字と記号以外の文字（全角文字や日本語など）が含まれています。', 'pageguard' ),
			),
			'save_failed'        => array(
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
				'hasPassword'         => Pggd_Credentials::is_protected( $post->ID ) ? 1 : 0,
				'postId'              => (int) $post->ID,
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'stateNonce'          => wp_create_nonce( self::STATE_NONCE_ACTION ),
				'passwordPlaceholder' => __( '変更する場合のみ入力', 'pageguard' ),
				'i18n'                => array(
					'showPassword'       => __( 'パスワードを表示', 'pageguard' ),
					'hidePassword'       => __( 'パスワードを隠す', 'pageguard' ),
					'showLabel'          => __( '表示', 'pageguard' ),
					'hideLabel'          => __( '隠す', 'pageguard' ),
					'blockedPrefix'      => __( 'BASIC 認証の設定に不足があるため、更新できません。', 'pageguard' ),
					'usernameEmpty'      => __( 'ユーザー名が入力されていません。', 'pageguard' ),
					'usernameColon'      => __( 'ユーザー名にコロン（:）は使えません。', 'pageguard' ),
					'usernameNonAscii'   => __( 'ユーザー名に、半角の英数字と記号以外の文字（全角文字や日本語など）が含まれています。', 'pageguard' ),
					'passwordEmpty'      => __( 'パスワードが入力されていません。', 'pageguard' ),
					'passwordNonAscii'   => __( 'パスワードに、半角の英数字と記号以外の文字（全角文字や日本語など）が含まれています。', 'pageguard' ),
				),
			)
		);
	}
}
