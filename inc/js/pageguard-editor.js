/**
 * PageGuard: 投稿編集画面のメタボックス用スクリプト。
 *
 * このスクリプトが読み込まれなくても保護の設定・解除はサーバー側で成立する。
 * ここでやるのは次の3つだけ。
 *
 *  1. ブロックエディタに「未保存の変更がある」と伝える（これが無いと「更新」が押せない）
 *  2. ラジオの選択に合わせた警告表示と required の付け外し
 *  3. パスワードの表示切替
 */
( function() {
	'use strict';

	var box = document.getElementById( 'pggd_meta_box' );
	if ( ! box ) {
		return;
	}

	var data = ( 'undefined' !== typeof PggdEditorData ) ? PggdEditorData : {};
	var i18n = data.i18n || {};

	var protectOn  = document.getElementById( 'pggd_protect_on' );
	var protectOff = document.getElementById( 'pggd_protect_off' );
	var username   = document.getElementById( 'pggd_username' );
	var password   = document.getElementById( 'pggd_password' );
	var warning    = document.getElementById( 'pggd-unprotect-warning' );
	var toggle     = document.getElementById( 'pggd-toggle-password' );

	/* ------------------------------------------------------------------
	 * 1. ブロックエディタに未保存の変更を知らせる
	 *
	 * ブロックエディタは、クラシックメタボックス内の変更を検知しない。
	 * 対策しないと、パスワードを入力しても「更新」ボタンが有効にならず、
	 * 保護をまったく設定できない状態になる。
	 * editPost() に未登録のメタキーを渡して投稿を dirty にする。
	 * 未登録のメタキーは REST 側で無視されるため、実際には保存されない。
	 * ------------------------------------------------------------------ */
	function markPostDirty() {
		// クラシックエディタには wp.data が無い。その場合は何もしない。
		if ( ! window.wp || ! wp.data || ! wp.data.select || ! wp.data.dispatch ) {
			return;
		}

		var editorStore = wp.data.select( 'core/editor' );
		if ( ! editorStore || 'function' !== typeof editorStore.isEditedPostDirty ) {
			return;
		}
		// 既に dirty なら何もしない（打鍵のたびにアクションを投げない）。
		if ( editorStore.isEditedPostDirty() ) {
			return;
		}

		var dispatcher = wp.data.dispatch( 'core/editor' );
		if ( ! dispatcher || 'function' !== typeof dispatcher.editPost ) {
			return;
		}

		dispatcher.editPost( { meta: { _pggd_meta_box_touched: true } } );
	}

	var watched = box.querySelectorAll( 'input' );
	var index;
	for ( index = 0; index < watched.length; index++ ) {
		watched[ index ].addEventListener( 'change', markPostDirty );
		watched[ index ].addEventListener( 'input', markPostDirty );
	}

	/* ------------------------------------------------------------------
	 * 2. ラジオの選択に合わせた表示の切り替え
	 * ------------------------------------------------------------------ */
	function syncProtectState() {
		var wantsProtection = !! ( protectOn && protectOn.checked );
		var hasPassword     = 1 === parseInt( data.hasPassword, 10 );

		// 警告は「保護しない」を選んでいるときだけ出す。
		// サーバー側の初期出力では隠していないため、
		// このスクリプトが動かない環境では常に表示されたままになる（安全側）。
		if ( warning ) {
			if ( wantsProtection ) {
				warning.setAttribute( 'hidden', 'hidden' );
			} else {
				warning.removeAttribute( 'hidden' );
			}
		}

		// required は「保護する」を選んだときだけ付ける。
		// 「保護しない」のときに付いたままだと、保護を使わない投稿の保存まで
		// ブラウザの入力チェックで止まってしまう。
		if ( username ) {
			setRequired( username, wantsProtection );
		}
		// パスワードが未設定のときだけ必須。設定済みなら空欄＝変更なし。
		if ( password ) {
			setRequired( password, wantsProtection && ! hasPassword );
		}
	}

	function setRequired( element, required ) {
		if ( required ) {
			element.setAttribute( 'required', 'required' );
		} else {
			element.removeAttribute( 'required' );
		}
	}

	if ( protectOn ) {
		protectOn.addEventListener( 'change', syncProtectState );
	}
	if ( protectOff ) {
		protectOff.addEventListener( 'change', syncProtectState );
	}
	syncProtectState();

	/* ------------------------------------------------------------------
	 * 3. パスワードの表示切替
	 * ------------------------------------------------------------------ */
	if ( toggle && password ) {
		toggle.addEventListener( 'click', function() {
			var willShow = ( 'password' === password.getAttribute( 'type' ) );

			password.setAttribute( 'type', willShow ? 'text' : 'password' );
			toggle.setAttribute( 'aria-pressed', willShow ? 'true' : 'false' );
			toggle.setAttribute( 'aria-label', willShow ? ( i18n.hidePassword || '' ) : ( i18n.showPassword || '' ) );
			toggle.textContent = willShow ? ( i18n.hideLabel || '' ) : ( i18n.showLabel || '' );
		} );
	}
} )();
