/**
 * PageGuard: 投稿編集画面のメタボックス用スクリプト。
 *
 * クラシックエディタでは HTML5 の required とサーバー側の検証で成立するが、
 * ブロックエディタはメタボックスを FormData ＋ apiFetch で送るため
 * required も reportValidity() も走らない。そのため、この JavaScript が
 * 次の5つを引き受ける。
 *
 *  1. ブロックエディタに「未保存の変更がある」と伝える（無いと「更新」が押せない）
 *  2. 入力が不足しているうちは lockPostSaving() で「更新」自体を止める
 *  3. メタボックス保存の完了を検知して、状態表示・ラベル・入力欄を描き直す
 *  4. 保存結果の通知をエディタ内へ即時に出す
 *     （出さないと、警告・エラーが「次に編集画面を開いたとき」まで届かない）
 *  5. ラジオ連動の警告表示・required の付け外し・パスワードの表示切替
 *
 * ■「保護中」と「パスワードが読める」は別物
 *
 * isProtected は索引だけが残った壊れた状態でも 1 になる。
 * その状態では既存のハッシュが読めず、パスワードの入力が必須になる。
 * 必須判定と表示の出し分けは、必ず hasCredential 側を見ること。
 * ここを取り違えると、画面が「入力しなくていい」と言いながら
 * サーバーが弾く、出口の無い状態になる。
 */
( function() {
	'use strict';

	var box = document.getElementById( 'pggd_meta_box' );
	if ( ! box ) {
		return;
	}

	var data = ( 'undefined' !== typeof PggdEditorData ) ? PggdEditorData : {};
	var i18n = data.i18n || {};

	var LOCK_KEY  = 'pggd-incomplete';
	var NOTICE_ID = 'pggd-incomplete-notice';

	var protectOn   = document.getElementById( 'pggd_protect_on' );
	var protectOff  = document.getElementById( 'pggd_protect_off' );
	var username    = document.getElementById( 'pggd_username' );
	var password    = document.getElementById( 'pggd_password' );
	var warning     = document.getElementById( 'pggd-unprotect-warning' );
	var stateBox    = document.getElementById( 'pggd-state' );
	var passLabel   = document.getElementById( 'pggd-password-label' );
	var passDesc    = document.getElementById( 'pggd-password-description' );
	var passActions = document.getElementById( 'pggd-password-actions' );

	// 保存後に変わりうるので、変数として持ち回る。
	var isProtected    = ( 1 === parseInt( data.isProtected, 10 ) );
	var hasCredential  = ( 1 === parseInt( data.hasCredential, 10 ) );
	var lastProblemKey = null;

	/**
	 * wp.data のストアを安全に取り出す。クラシックエディタでは null を返す。
	 */
	function getStore( name, kind ) {
		if ( ! window.wp || ! wp.data || ! wp.data[ kind ] ) {
			return null;
		}
		try {
			return wp.data[ kind ]( name ) || null;
		} catch ( e ) {
			return null;
		}
	}

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
		var selectEditor = getStore( 'core/editor', 'select' );
		if ( ! selectEditor || 'function' !== typeof selectEditor.isEditedPostDirty ) {
			return;
		}
		// 既に dirty なら何もしない（打鍵のたびにアクションを投げない）。
		if ( selectEditor.isEditedPostDirty() ) {
			return;
		}

		var dispatchEditor = getStore( 'core/editor', 'dispatch' );
		if ( ! dispatchEditor || 'function' !== typeof dispatchEditor.editPost ) {
			return;
		}

		dispatchEditor.editPost( { meta: { _pggd_meta_box_touched: true } } );
	}

	/* ------------------------------------------------------------------
	 * 2. 入力が不足しているうちは保存を止める
	 * ------------------------------------------------------------------ */

	/**
	 * メタボックスを開いて画面内に入れ、最初の未入力欄へフォーカスする。
	 * 「更新」を止めるからには、直す場所への導線をセットで用意する。
	 */
	function focusMetaBox() {
		// 折りたたまれている場合があるので開く。
		box.classList.remove( 'closed' );
		var toggle = box.querySelector( '.handlediv' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
		}

		if ( box.scrollIntoView ) {
			box.scrollIntoView( { block: 'center' } );
		}

		var target = null;
		if ( username && '' === username.value.replace( /^\s+|\s+$/g, '' ) ) {
			target = username;
		} else if ( password && '' === password.value && ! hasCredential ) {
			target = password;
		}
		if ( target ) {
			target.focus();
		}
	}

	/**
	 * 現在の入力内容から、保存を止めるべき理由の一覧を返す。
	 * サーバー側 save() の検証と同じ条件にしてある。
	 */
	function collectProblems() {
		var problems = [];

		if ( ! protectOn || ! protectOn.checked ) {
			return problems;
		}

		var user = username ? username.value.replace( /^\s+|\s+$/g, '' ) : '';
		var pass = password ? password.value : '';

		var userEmpty = ( '' === user );
		// パスワードの必須判定は hasCredential（既存のハッシュが読めるか）で行う。
		var passEmpty = ( '' === pass && ! hasCredential );

		// 両方空のときは1文にまとめる（同じ形の文を2つ並べない）。
		if ( userEmpty && passEmpty ) {
			problems.push( i18n.bothEmpty || '' );
			return problems;
		}

		if ( userEmpty ) {
			problems.push( i18n.usernameEmpty || '' );
		} else if ( /[\x00-\x1F\x7F]/.test( user ) ) {
			problems.push( i18n.usernameControlChars || '' );
		} else if ( -1 !== user.indexOf( ':' ) ) {
			problems.push( i18n.usernameColon || '' );
		} else if ( /[^\x20-\x7E]/.test( user ) ) {
			problems.push( i18n.usernameNonAscii || '' );
		}

		if ( passEmpty ) {
			problems.push( i18n.passwordEmpty || '' );
		} else if ( '' !== pass && /[\x00-\x1F\x7F]/.test( pass ) ) {
			problems.push( i18n.passwordControlChars || '' );
		} else if ( '' !== pass && /[^\x20-\x7E]/.test( pass ) ) {
			problems.push( i18n.passwordNonAscii || '' );
		}

		return problems;
	}

	/**
	 * 検証結果を「更新」ボタンとエディタ内通知へ反映する。
	 * クラシックエディタでは wp.data が無いので何もしない（required が働く）。
	 */
	function applySaveLock( problems ) {
		var dispatchEditor = getStore( 'core/editor', 'dispatch' );
		if ( ! dispatchEditor || 'function' !== typeof dispatchEditor.lockPostSaving ) {
			return;
		}

		var dispatchNotices = getStore( 'core/notices', 'dispatch' );
		var key             = problems.join( '' );

		// 内容が変わっていないなら再通知しない（打鍵のたびに再描画させない）。
		if ( key === lastProblemKey ) {
			return;
		}
		lastProblemKey = key;

		if ( problems.length ) {
			dispatchEditor.lockPostSaving( LOCK_KEY );
			if ( dispatchNotices && 'function' === typeof dispatchNotices.createErrorNotice ) {
				dispatchNotices.createErrorNotice(
					( i18n.blockedPrefix || '' ) + problems.join( '' ) + ( i18n.blockedHelp || '' ),
					{
						id: NOTICE_ID,
						isDismissible: false,
						actions: [
							{
								label: i18n.blockedAction || '',
								onClick: focusMetaBox
							}
						]
					}
				);
			}
			return;
		}

		if ( 'function' === typeof dispatchEditor.unlockPostSaving ) {
			dispatchEditor.unlockPostSaving( LOCK_KEY );
		}
		if ( dispatchNotices && 'function' === typeof dispatchNotices.removeNotice ) {
			dispatchNotices.removeNotice( NOTICE_ID );
		}
	}

	/* ------------------------------------------------------------------
	 * 5. ラジオ連動の表示切り替え
	 * ------------------------------------------------------------------ */
	function setRequired( element, required ) {
		if ( ! element ) {
			return;
		}
		if ( required ) {
			element.setAttribute( 'required', 'required' );
		} else {
			element.removeAttribute( 'required' );
		}
	}

	function syncUi() {
		var wantsProtection = !! ( protectOn && protectOn.checked );

		/*
		 * 警告は「保護中のページで、保護しないを選んでいるとき」だけ出す。
		 * 未保護のページでは失うものが無いので出さない（警告疲れを避ける）。
		 * サーバー側は保護中のとき隠さずに出しているので、
		 * このスクリプトが動かない環境では表示されたままになる（安全側）。
		 */
		if ( warning ) {
			if ( isProtected && ! wantsProtection ) {
				warning.removeAttribute( 'hidden' );
			} else {
				warning.setAttribute( 'hidden', 'hidden' );
			}
		}

		/*
		 * required は「保護する」を選んだときだけ付ける。
		 * 「保護しない」のときに付いたままだと、保護を使わない投稿の保存まで
		 * ブラウザの入力チェックで止まってしまう。
		 */
		setRequired( username, wantsProtection );
		// 既存のパスワードが読めるときだけ空欄を許す。
		setRequired( password, wantsProtection && ! hasCredential );

		applySaveLock( collectProblems() );
	}

	/* ------------------------------------------------------------------
	 * パスワードの表示切替ボタン（JavaScript でのみ生成する）
	 * ------------------------------------------------------------------ */
	function buildToggleButton() {
		if ( ! passActions || ! password ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type        = 'button';
		button.className   = 'button pggd-toggle-password';
		button.textContent = i18n.showLabel || '';
		/*
		 * aria-pressed は付けない。「パスワードを隠す、押されています」のように
		 * 読み上げが二重否定的になり、どちらの状態か分かりにくくなるため、
		 * WordPress コアの表示切替ボタンに揃えて aria-label だけを状態に追随させる。
		 */
		button.setAttribute( 'aria-label', i18n.showPassword || '' );

		button.addEventListener( 'click', function() {
			var willShow = ( 'password' === password.getAttribute( 'type' ) );

			password.setAttribute( 'type', willShow ? 'text' : 'password' );
			button.setAttribute( 'aria-label', willShow ? ( i18n.hidePassword || '' ) : ( i18n.showPassword || '' ) );
			button.textContent = willShow ? ( i18n.hideLabel || '' ) : ( i18n.showLabel || '' );
		} );

		passActions.appendChild( button );
	}

	/*
	 * 確認用 URL のコピーボタン。状態表示は差し替えられるので、
	 * 個々の要素ではなくメタボックスに対して委譲で拾う。
	 */
	function setupCopyButton() {
		if ( ! navigator.clipboard || ! stateBox ) {
			return;
		}

		box.addEventListener( 'click', function( event ) {
			var trigger = event.target.closest ? event.target.closest( '.pggd-copy-url' ) : null;
			if ( ! trigger ) {
				return;
			}
			var url = document.getElementById( 'pggd-verify-url' );
			if ( ! url ) {
				return;
			}
			navigator.clipboard.writeText( url.textContent ).then( function() {
				trigger.textContent = i18n.copiedLabel || '';
			} ).catch( function() {
				// コピーできなくても URL は画面に出ているので手で選択できる。
			} );
		} );
	}

	/**
	 * 状態表示の中にコピーボタンを差し込む（クリップボードが使えるときだけ）。
	 */
	function injectCopyButton() {
		if ( ! navigator.clipboard ) {
			return;
		}
		var row = stateBox ? stateBox.querySelector( '.pggd-url-row' ) : null;
		if ( ! row || row.querySelector( '.pggd-copy-url' ) ) {
			return;
		}
		var button = document.createElement( 'button' );
		button.type        = 'button';
		button.className   = 'button button-small pggd-copy-url';
		button.textContent = i18n.copyLabel || '';
		row.appendChild( button );
	}

	/* ------------------------------------------------------------------
	 * 3 と 4. メタボックス保存の完了を検知して、表示と通知を更新する
	 *
	 * ブロックエディタはメタボックスを POST したあと応答を読み捨てるため、
	 * 放っておくと状態表示が保存前のまま残り、
	 * 「保護されていません」と出したまま実際は保護済み、という嘘になる。
	 * 保存結果の通知も同じ理由で届かないので、ここで一緒に受け取る。
	 * ------------------------------------------------------------------ */

	/**
	 * 状態が分からなくなったことを画面に出す。
	 * 知らない状態を断定表示させない（古い表示を残すと嘘をつき続ける）。
	 */
	function showStateFailure( message ) {
		if ( ! stateBox ) {
			return;
		}

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'notice notice-warning inline pggd-state-note';

		var text = document.createElement( 'p' );
		text.textContent = message || i18n.stateFailed || '';
		wrapper.appendChild( text );

		var actions = document.createElement( 'p' );
		var reload  = document.createElement( 'button' );
		reload.type        = 'button';
		reload.className   = 'button';
		reload.textContent = i18n.reloadLabel || '';
		reload.addEventListener( 'click', function() {
			window.location.reload();
		} );
		actions.appendChild( reload );
		wrapper.appendChild( actions );

		stateBox.innerHTML = '';
		stateBox.appendChild( wrapper );
	}

	/**
	 * 保存結果の通知をエディタ内に出す。
	 */
	function showSavedNotices( notices ) {
		if ( ! notices || ! notices.length ) {
			return;
		}
		var dispatchNotices = getStore( 'core/notices', 'dispatch' );
		if ( ! dispatchNotices || 'function' !== typeof dispatchNotices.createNotice ) {
			return;
		}
		var index;
		for ( index = 0; index < notices.length; index++ ) {
			dispatchNotices.createNotice(
				notices[ index ].status,
				notices[ index ].text,
				{ isDismissible: true }
			);
		}
	}

	function refreshState() {
		if ( ! data.ajaxUrl || ! data.stateNonce || ! window.fetch ) {
			showStateFailure( i18n.stateFailed );
			return;
		}

		var body = new window.FormData();
		body.append( 'action', 'pggd_get_state' );
		body.append( 'nonce', data.stateNonce );
		body.append( 'post_id', data.postId );

		window.fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function( response ) {
			return response.json();
		} ).then( function( result ) {
			if ( ! result || ! result.success || ! result.data ) {
				// nonce 切れや権限不足。古い表示を残さず、理由と復旧手段を出す。
				var message = ( result && result.data && result.data.message ) ? result.data.message : i18n.stateFailed;
				showStateFailure( message );
				return;
			}

			isProtected   = ( 1 === parseInt( result.data.isProtected, 10 ) );
			hasCredential = ( 1 === parseInt( result.data.hasCredential, 10 ) );

			if ( stateBox ) {
				stateBox.innerHTML = result.data.state;
				injectCopyButton();
			}
			if ( passLabel ) {
				passLabel.textContent = result.data.passwordLabel;
			}
			if ( passDesc ) {
				passDesc.innerHTML = result.data.passwordDescription;
			}
			if ( password ) {
				// 入力したパスワードを欄に残さない。
				password.value = '';
				if ( result.data.passwordPlaceholder ) {
					password.setAttribute( 'placeholder', result.data.passwordPlaceholder );
				} else {
					password.removeAttribute( 'placeholder' );
				}
			}
			if ( username ) {
				/*
				 * 解除された場合はユーザー名も消す。残しておくと、
				 * 次に何も触らず更新しただけで「入力を保存しませんでした」と
				 * 身に覚えのない警告が出ることになる。
				 */
				username.value = result.data.username || '';
			}

			showSavedNotices( result.data.notices );

			lastProblemKey = null; // 状態が変わったので通知を出し直させる。
			syncUi();
		} ).catch( function() {
			// 通信そのものに失敗した場合も、古い表示を残さない。
			showStateFailure( i18n.stateFailed );
		} );
	}

	function watchMetaBoxSaving() {
		if ( ! window.wp || ! wp.data || 'function' !== typeof wp.data.subscribe ) {
			return;
		}

		var wasSaving = false;

		wp.data.subscribe( function() {
			var store = getStore( 'core/edit-post', 'select' );
			if ( ! store || 'function' !== typeof store.isSavingMetaBoxes ) {
				return;
			}

			var saving = store.isSavingMetaBoxes();
			if ( wasSaving && ! saving ) {
				refreshState();
			}
			wasSaving = saving;
		} );
	}

	/* ------------------------------------------------------------------
	 * 組み立て
	 * ------------------------------------------------------------------ */
	function onInputChanged() {
		markPostDirty();
		syncUi();
	}

	var inputs = box.querySelectorAll( 'input' );
	var index;
	for ( index = 0; index < inputs.length; index++ ) {
		inputs[ index ].addEventListener( 'change', onInputChanged );
		inputs[ index ].addEventListener( 'input', onInputChanged );
	}

	buildToggleButton();
	setupCopyButton();
	injectCopyButton();
	watchMetaBoxSaving();
	syncUi();
} )();
