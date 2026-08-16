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
	 * nonce のアクション名。
	 */
	const NONCE_ACTION = 'pggd_save_meta_box';

	/**
	 * nonce の input 名。
	 */
	const NONCE_NAME = 'pggd_meta_box_nonce';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_script' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_filter( 'redirect_post_location', array( __CLASS__, 'mark_metabox_loader_redirect' ) );
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
	 * メタボックスの中身を出力する。
	 *
	 * @param WP_Post $post 投稿オブジェクト。
	 * @return void
	 */
	public static function render( $post ) {
		$credential   = Pggd_Credentials::get_primary( $post->ID );
		$is_protected = ( null !== $credential );
		$username     = $is_protected ? $credential['username'] : '';
		$updated      = $is_protected ? (int) $credential['password_updated'] : 0;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div id="pggd-meta-box-body">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( '現在の状態', 'pageguard' ); ?></th>
						<td>
							<?php if ( $is_protected ) : ?>
								<p style="margin-top:0;">
									<span class="dashicons dashicons-lock" aria-hidden="true"></span>
									<strong><?php esc_html_e( 'このページは BASIC 認証で保護されています。', 'pageguard' ); ?></strong>
								</p>
								<p style="margin-bottom:0;">
									<?php
									printf(
										/* translators: %s: 設定済みのユーザー名 */
										esc_html__( '設定済みのユーザー名: %s', 'pageguard' ),
										'<code>' . esc_html( $username ) . '</code>'
									);
									?>
									<br>
									<?php
									if ( $updated > 0 ) {
										printf(
											/* translators: %s: パスワードを設定した日時 */
											esc_html__( 'パスワードの設定日時: %s', 'pageguard' ),
											esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated ) )
										);
									} else {
										esc_html_e( 'パスワードの設定日時: 記録がありません', 'pageguard' );
									}
									?>
								</p>
							<?php else : ?>
								<p style="margin:0;">
									<span class="dashicons dashicons-unlock" aria-hidden="true"></span>
									<strong><?php esc_html_e( 'このページは BASIC 認証で保護されていません。', 'pageguard' ); ?></strong>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'このページの保護', 'pageguard' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'このページの保護', 'pageguard' ); ?></legend>
								<p style="margin-top:0;">
									<label for="pggd_protect_on">
										<input type="radio" name="pggd_protect" id="pggd_protect_on" value="1" <?php checked( $is_protected ); ?>>
										<?php esc_html_e( '保護する', 'pageguard' ); ?>
									</label>
								</p>
								<p style="margin-bottom:0;">
									<label for="pggd_protect_off">
										<input type="radio" name="pggd_protect" id="pggd_protect_off" value="0" <?php checked( ! $is_protected ); ?>>
										<?php esc_html_e( '保護しない', 'pageguard' ); ?>
									</label>
								</p>
								<?php // JavaScript が動かない環境では常に表示されたままになる（安全側に倒す）。 ?>
								<div id="pggd-unprotect-warning" class="notice notice-warning inline" role="status" style="margin:8px 0 0;">
									<p>
										<?php esc_html_e( '「保護しない」を選んだまま「更新」すると、このページは URL を知っている人なら誰でも閲覧できる状態になります。', 'pageguard' ); ?>
										<?php if ( $is_protected ) : ?>
											<br><?php esc_html_e( '設定済みのパスワードは削除され、元に戻すことはできません（再設定が必要です）。', 'pageguard' ); ?>
										<?php endif; ?>
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
								<?php esc_html_e( 'コロン（:）は BASIC 認証の仕組み上使えません。', 'pageguard' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pggd_password">
								<?php
								if ( $is_protected ) {
									esc_html_e( '新しいパスワード', 'pageguard' );
								} else {
									esc_html_e( 'パスワード', 'pageguard' );
								}
								?>
							</label>
						</th>
						<td>
							<input type="password" class="regular-text" id="pggd_password" name="pggd_password"
								value=""
								<?php if ( $is_protected ) : ?>
									placeholder="<?php esc_attr_e( '変更する場合のみ入力', 'pageguard' ); ?>"
								<?php endif; ?>
								autocomplete="new-password" spellcheck="false" autocapitalize="off"
								aria-describedby="pggd-password-description">
							<button type="button" class="button" id="pggd-toggle-password"
								aria-pressed="false"
								aria-label="<?php esc_attr_e( 'パスワードを表示', 'pageguard' ); ?>">
								<?php esc_html_e( '表示', 'pageguard' ); ?>
							</button>
							<p class="description" id="pggd-password-description">
								<?php if ( $is_protected ) : ?>
									<?php esc_html_e( '設定済みのパスワードは、安全のため画面には表示できません。', 'pageguard' ); ?>
									<?php esc_html_e( '変更する場合だけ新しいパスワードを入力してください。空欄のままなら、現在のパスワードがそのまま使われます。', 'pageguard' ); ?>
									<br>
									<strong><?php esc_html_e( 'パスワードだけを空欄にしても保護は解除されません。解除するには「保護しない」を選んでから「更新」してください。', 'pageguard' ); ?></strong>
								<?php else : ?>
									<?php esc_html_e( 'ページを閲覧する人に伝えるパスワードです。', 'pageguard' ); ?>
									<?php esc_html_e( '保存後は画面に表示できなくなるため、控えを取ってから「更新」してください。', 'pageguard' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<hr>

			<p class="description">
				<strong><?php esc_html_e( 'メディアファイルへの直リンクは保護できません。', 'pageguard' ); ?></strong>
				<?php esc_html_e( 'このページに貼った画像や PDF などのファイル URL に直接アクセスされた場合、内容は表示されてしまいます。', 'pageguard' ); ?>
				<?php esc_html_e( 'これらのファイルは WordPress を経由せず Web サーバーが直接返すためです。', 'pageguard' ); ?>
			</p>
		</div>
		<?php
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
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
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

		$username = isset( $_POST['pggd_username'] ) ? sanitize_text_field( wp_unslash( $_POST['pggd_username'] ) ) : '';

		// パスワードはサニタイズしない。sanitize_text_field 等を通すと文字が落ちて、
		// 利用者が入力したとおりのパスワードでは認証できなくなるため。
		// ハッシュ化して保存するので、そのまま扱ってよい。
		$password = isset( $_POST['pggd_password'] ) ? (string) wp_unslash( $_POST['pggd_password'] ) : '';

		$was_protected = Pggd_Credentials::is_protected( $post_id );

		/* ---------- 解除 ---------- */
		if ( '0' === $protect ) {
			if ( $was_protected ) {
				Pggd_Credentials::delete( $post_id );
				self::add_notice( $post_id, 'unprotected' );
			}
			return;
		}

		/* ---------- 保護する ---------- */
		$errors = array();
		if ( '' === $username ) {
			$errors[] = 'error_username_empty';
		} elseif ( false !== strpos( $username, ':' ) ) {
			$errors[] = 'error_username_colon';
		}
		if ( '' === $password && ! $was_protected ) {
			$errors[] = 'error_password_empty';
		}

		if ( ! empty( $errors ) ) {
			// 入力不備でも「保護しない」状態へは落とさない。直前の状態を保ったままエラーを返す。
			self::add_notice( $post_id, $errors );
			return;
		}

		$current       = Pggd_Credentials::get_primary( $post_id );
		$name_changed  = ( null !== $current ) && ! hash_equals( $current['username'], $username );
		$password_sent = ( '' !== $password );

		if ( ! Pggd_Credentials::save_primary( $post_id, $username, $password ) ) {
			self::add_notice( $post_id, 'error_save_failed' );
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
	 * ブロックエディタは応答を読み捨てるため、編集画面を次に開いたときに表示される。
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
	 * @param array $codes 通知コードの配列。
	 * @return void
	 */
	private static function print_notices( $codes ) {
		if ( empty( $codes ) ) {
			return;
		}

		$messages = self::get_notice_messages();

		foreach ( $codes as $code ) {
			if ( ! isset( $messages[ $code ] ) ) {
				continue;
			}
			printf(
				'<div class="notice %1$s"><p>%2$s</p></div>',
				esc_attr( $messages[ $code ]['class'] ),
				esc_html( $messages[ $code ]['text'] )
			);
		}
	}

	/**
	 * 通知コードとメッセージの対応表を返す。
	 *
	 * @return array 通知コードをキーとし、class / text を持つ配列。
	 */
	private static function get_notice_messages() {
		return array(
			'protected'            => array(
				'class' => 'notice-success',
				'text'  => __( 'BASIC 認証で保護しました。このページの閲覧にはユーザー名とパスワードが必要になります。', 'pageguard' ),
			),
			'password_changed'     => array(
				'class' => 'notice-success',
				'text'  => __( 'パスワードを変更しました。閲覧する方への連絡をお忘れなく。', 'pageguard' ),
			),
			'username_changed'     => array(
				'class' => 'notice-success',
				'text'  => __( 'ユーザー名を変更しました。閲覧する方への連絡をお忘れなく。', 'pageguard' ),
			),
			'unprotected'          => array(
				'class' => 'notice-warning',
				'text'  => __( 'BASIC 認証を解除しました。このページは URL を知っている人なら誰でも閲覧できる状態です。', 'pageguard' ),
			),
			'error_username_empty' => array(
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名が入力されていないため、BASIC 認証の設定を保存できませんでした。保護の状態は変更していません。', 'pageguard' ),
			),
			'error_username_colon' => array(
				'class' => 'notice-error',
				'text'  => __( 'ユーザー名にコロン（:）は使えないため、BASIC 認証の設定を保存できませんでした。保護の状態は変更していません。', 'pageguard' ),
			),
			'error_password_empty' => array(
				'class' => 'notice-error',
				'text'  => __( 'パスワードが入力されていないため、BASIC 認証の設定を保存できませんでした。保護の状態は変更していません。', 'pageguard' ),
			),
			'error_save_failed'    => array(
				'class' => 'notice-error',
				'text'  => __( 'BASIC 認証の設定を保存できませんでした。時間をおいて、もう一度お試しください。', 'pageguard' ),
			),
		);
	}

	/**
	 * 投稿編集画面にメタボックス用のスクリプトを読み込む。
	 *
	 * @param string $hook 現在の管理画面のフック名。
	 * @return void
	 */
	public static function enqueue_editor_script( $hook ) {
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

		// ブロックエディタのときだけ wp-data を依存に入れる。
		// クラシックエディタでは不要なので読み込ませない。
		$deps = array();
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			$deps[] = 'wp-data';
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
				'hasPassword' => Pggd_Credentials::is_protected( $post->ID ) ? 1 : 0,
				'i18n'        => array(
					'showPassword' => __( 'パスワードを表示', 'pageguard' ),
					'hidePassword' => __( 'パスワードを隠す', 'pageguard' ),
					'showLabel'    => __( '表示', 'pageguard' ),
					'hideLabel'    => __( '隠す', 'pageguard' ),
				),
			)
		);
	}
}
