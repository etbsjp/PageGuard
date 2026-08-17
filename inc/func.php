<?php
/**
 * PageGuard 実装本体のエントリポイント。
 *
 * 各機能はクラスファイルへ分け、このファイルでは
 * 「読み込み」「設定値ヘルパー」「支援・依頼リンク」だけを扱う。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*-------------------------------------------*/
/* Load Module
/*-------------------------------------------*/
require_once( dirname( __FILE__ ) . '/class-credentials.php' );
require_once( dirname( __FILE__ ) . '/class-lockout.php' );
require_once( dirname( __FILE__ ) . '/class-auth.php' );
require_once( dirname( __FILE__ ) . '/class-visibility.php' );
require_once( dirname( __FILE__ ) . '/class-meta-box.php' );
require_once( dirname( __FILE__ ) . '/class-settings.php' );

/*-------------------------------------------*/
/* 設定値ヘルパー
/*-------------------------------------------*/

if ( ! function_exists( 'pggd_get_selectable_post_types' ) ) {
	/**
	 * 保護対象として選択できる投稿タイプの一覧を返す。
	 *
	 * 設定画面のチェックボックス（Pggd_Settings）と、保存値の検証（サニタイズ）の
	 * どちらからも使うため、対象範囲の定義をここへ集約している。
	 *
	 * @return array 投稿タイプ名をキー、WP_Post_Type オブジェクトを値に持つ配列。
	 */
	function pggd_get_selectable_post_types() {
		// 公開されていて編集 UI を持つ投稿タイプのみを対象にする。
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		unset( $post_types['attachment'] );

		/**
		 * 保護対象として選択できる投稿タイプを差し替えるフィルター。
		 *
		 * @param array $post_types 投稿タイプ名をキー、WP_Post_Type オブジェクトを値に持つ配列。
		 */
		return (array) apply_filters( 'pggd_selectable_post_types', $post_types );
	}
}

if ( ! function_exists( 'pggd_get_target_post_types' ) ) {
	/**
	 * 保護設定 UI（メタボックス）の対象とする投稿タイプを返す。
	 *
	 * 既定は固定ページのみ（docs/spec.md 2）。設定画面（Pggd_Settings）で選べる。
	 * 実在しない投稿タイプや非公開の投稿タイプが混ざっていても無視できるよう、
	 * 選択可能な投稿タイプ（pggd_get_selectable_post_types()）と突き合わせて絞り込む。
	 *
	 * @return array 投稿タイプ名の配列。
	 */
	function pggd_get_target_post_types() {
		$post_types = get_option( 'pggd_post_types', array( 'page' ) );
		if ( ! is_array( $post_types ) ) {
			$post_types = array( 'page' );
		}

		$available  = array_keys( pggd_get_selectable_post_types() );
		$post_types = array_values( array_intersect( $post_types, $available ) );

		/**
		 * 保護設定 UI の対象投稿タイプを差し替えるフィルター。
		 *
		 * @param array $post_types 投稿タイプ名の配列。
		 */
		return (array) apply_filters( 'pggd_target_post_types', $post_types );
	}
}

if ( ! function_exists( 'pggd_get_max_attempts' ) ) {
	/**
	 * 総当たり対策でロックするまでの失敗回数を返す。
	 *
	 * 既定 5 回。打ち間違い 2〜3 回では止まらず、総当たりには十分に効く値。
	 *
	 * @return int 1 以上の失敗回数。
	 */
	function pggd_get_max_attempts() {
		$attempts = (int) get_option( 'pggd_max_attempts', 5 );
		return $attempts > 0 ? $attempts : 5;
	}
}

if ( ! function_exists( 'pggd_get_lockout_seconds' ) ) {
	/**
	 * ロック時間（秒）を返す。
	 *
	 * 既定 900 秒（15 分）。打ち間違いで締め出された利用者が
	 * 現実的に待てる長さで、かつ総当たりの試行速度を大きく落とせる値。
	 *
	 * @return int 1 以上の秒数。
	 */
	function pggd_get_lockout_seconds() {
		$seconds = (int) get_option( 'pggd_lockout_seconds', 900 );
		return $seconds > 0 ? $seconds : 900;
	}
}

/*-------------------------------------------*/
/* フックの登録
/* 上の設定値ヘルパーは function_exists() で囲んだ条件付き定義のため、
/* 定義文が実行されるまで呼び出せない。init() より前に置くこと。
/*-------------------------------------------*/
Pggd_Auth::init();
Pggd_Visibility::init();
Pggd_Meta_Box::init();
Pggd_Settings::init();

/*-------------------------------------------*/
/* 支援・依頼リンク（プラグイン一覧行）
/* 【既知の罠】コールバックは4引数で受ける。3引数で書くと、
/* 他プラグイン（CBX 等）のコールバックが ArgumentCountError で落ちる。
/*-------------------------------------------*/
if ( ! function_exists( 'pggd_plugin_row_meta' ) ) {
	/**
	 * プラグイン一覧のプラグイン行に支援・依頼リンクを足す。
	 *
	 * @param array  $links       行に表示されるリンクの配列。
	 * @param string $file        プラグインのメインファイル（plugin_basename 形式）。
	 * @param array  $plugin_data プラグインヘッダの情報（未使用だが4引数で受ける）。
	 * @param string $status      プラグインの状態（未使用だが4引数で受ける）。
	 * @return array リンクの配列。
	 */
	function pggd_plugin_row_meta( $links, $file, $plugin_data = array(), $status = '' ) {
		// 自プラグインの行だけに足す。判定には PGGD_PLUGIN_FILE を使う。
		if ( plugin_basename( PGGD_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="https://etbs.jp/product/donate/?utm_source=pageguard&utm_medium=plugin" target="_blank" rel="noopener noreferrer">'
			. esc_html__( '開発を支援', 'pageguard' ) . '</a>';
		$links[] = '<a href="https://etbs.jp/product-category/wordpress-tools/?utm_source=pageguard&utm_medium=plugin" target="_blank" rel="noopener noreferrer">'
			. esc_html__( '開発のご依頼', 'pageguard' ) . '</a>';

		return $links;
	}
	add_filter( 'plugin_row_meta', 'pggd_plugin_row_meta', 10, 4 );
}

/*-------------------------------------------*/
/* 支援・依頼リンク（ダッシュボードウィジェット）
/*-------------------------------------------*/
if ( ! function_exists( 'pggd_add_dashboard_widget' ) ) {
	/**
	 * ダッシュボードに PageGuard のウィジェットを追加する。
	 *
	 * @return void
	 */
	function pggd_add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'pggd_dashboard_widget',
			'PageGuard',
			'pggd_render_dashboard_widget'
		);
	}
	add_action( 'wp_dashboard_setup', 'pggd_add_dashboard_widget' );
}

if ( ! function_exists( 'pggd_render_dashboard_widget' ) ) {
	/**
	 * ダッシュボードウィジェットの中身を出力する。
	 *
	 * @return void
	 */
	function pggd_render_dashboard_widget() {
		?>
		<p><?php esc_html_e( 'ページごとに独立したユーザー名 / パスワードで BASIC 認証をかけられます。保護したいページの編集画面で設定してください。', 'pageguard' ); ?></p>
		<p><?php esc_html_e( '初期設定では固定ページだけが対象です。投稿やカスタム投稿タイプの編集画面には設定欄が出ません。', 'pageguard' ); ?></p>

		<?php
		/*
		 * 見出しは strong + br ではなく見出し要素で出す。
		 * 段落が増えて構造が要る量になったので、支援技術でも項目単位で辿れるようにする。
		 * ダッシュボードのウィジェット名が h2 なので、その下は h3 にする。
		 */
		?>
		<h3><?php esc_html_e( 'ご注意', 'pageguard' ); ?></h3>
		<?php
		/*
		 * 1文目は strong で強調する。ダッシュボードでは
		 * コアの #dashboard-widgets h3 が font-weight: 400 を当てるため見出しが太字にならず、
		 * さらにこのプラグインの CSS は post.php / post-new.php でしか読み込まれない。
		 * 強調を CSS の追加読み込みではなくマークアップで担保する（メタボックス側とも表記が揃う）。
		 */
		?>
		<p>
			<strong><?php esc_html_e( 'メディアファイルへの直リンク（画像・PDF などのファイル URL への直接アクセス）は保護できません。', 'pageguard' ); ?></strong>
			<?php esc_html_e( 'これらのファイルは WordPress を経由せず Web サーバーが直接返すためです。', 'pageguard' ); ?>
		</p>

		<h3><?php esc_html_e( 'サイト表側の一覧について', 'pageguard' ); ?></h3>
		<?php
		// 理由（キャッシュ経由の配布を避けるため）は README に置き、ここは事実と対処だけにする。
		?>
		<p>
			<?php esc_html_e( '保護中のページは、サイト内の検索結果・アーカイブ一覧・フィード・サイトマップ・REST API から外れます。', 'pageguard' ); ?>
			<?php esc_html_e( 'ログイン中でもサイト表側には表示されないため、保護中のページを探すときは管理画面の固定ページ一覧をご利用ください。', 'pageguard' ); ?>
		</p>

		<h3><?php esc_html_e( 'サポート', 'pageguard' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: 1: 開発のご依頼ページへのリンク開始タグ, 2: リンク終了タグ, 3: 開発を支援ページへのリンク開始タグ, 4: リンク終了タグ */
				esc_html__( '有償サポートやカスタマイズは %1$sこちらのページ%2$s からお問い合わせください。開発の継続は %3$sご支援%4$s で応援いただけます。', 'pageguard' ),
				'<a href="' . esc_url( 'https://etbs.jp/product-category/wordpress-tools/?utm_source=pageguard&utm_medium=plugin' ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>',
				'<a href="' . esc_url( 'https://etbs.jp/product/donate/?utm_source=pageguard&utm_medium=plugin' ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			);
			?>
		</p>
		<?php
	}
}

/*-------------------------------------------*/
/* 支援・依頼リンク（設定画面のフッター）
/* 【既知の罠】画面IDで絞らないと、全管理画面のフッターを乗っ取ってしまう。
/* PageGuard の設定画面（settings_page_pageguard）でだけ差し替える。
/*-------------------------------------------*/
if ( ! function_exists( 'pggd_admin_footer_text' ) ) {
	/**
	 * PageGuard の設定画面のフッター文言を、支援・依頼リンク付きに差し替える。
	 *
	 * @param string $text 既定のフッター文言。
	 * @return string フッター文言。
	 */
	function pggd_admin_footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_pageguard' !== $screen->id ) {
			return $text;
		}

		return sprintf(
			/* translators: 1: 開発を支援ページへのリンク開始タグ, 2: リンク終了タグ, 3: 開発のご依頼ページへのリンク開始タグ, 4: リンク終了タグ */
			esc_html__( 'PageGuard の開発は %1$sご支援%2$s いただけます。カスタマイズのご依頼は %3$sこちら%4$s から。', 'pageguard' ),
			'<a href="' . esc_url( 'https://etbs.jp/product/donate/?utm_source=pageguard&utm_medium=plugin' ) . '" target="_blank" rel="noopener noreferrer">',
			'</a>',
			'<a href="' . esc_url( 'https://etbs.jp/product-category/wordpress-tools/?utm_source=pageguard&utm_medium=plugin' ) . '" target="_blank" rel="noopener noreferrer">',
			'</a>'
		);
	}
	add_filter( 'admin_footer_text', 'pggd_admin_footer_text' );
}
