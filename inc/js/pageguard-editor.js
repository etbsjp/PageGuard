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
	// 保存後の状態を取得できなかった場合に立てる。
	// 手元の isProtected / hasCredential が当てにならない以上、検証もできない。
	var stateUnknown   = false;

	/**
	 * 前後の空白を落とす。
	 *
	 * サーバー側 Pggd_Meta_Box::trim_input() と同じ文字集合であること。
	 * JavaScript の \s は全角スペース（U+3000）を含むが PHP の trim() は含まないため、
	 * 素朴に書くと「JS は通すのにサーバーが弾く」食い違いが生まれる。
	 * PHP 側をこちらに合わせてある。
	 */
	function trimInput( value ) {
		return String( value ).replace( /^\s+|\s+$/g, '' );
	}

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
	 * メタボックスを開いて画面内に入れ、直すべき欄へフォーカスする。
	 * 「更新」を止めるからには、直す場所への導線をセットで用意する。
	 *
	 * @param {string} field 'username' または 'password'。
	 */
	function focusMetaBox( field ) {
		// 折りたたまれている場合があるので開く。
		box.classList.remove( 'closed' );
		var toggle = box.querySelector( '.handlediv' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
		}

		if ( box.scrollIntoView ) {
			box.scrollIntoView( { block: 'center' } );
		}

		// 空欄とは限らない（コロン・全角・制御文字は値が入っている）。
		// 問題のある欄を指定してもらい、決まらなければユーザー名欄へ寄せる。
		var target = ( 'password' === field ) ? password : username;
		if ( ! target ) {
			target = username || password;
		}
		if ( target ) {
			target.focus();
		}
	}

	/**
	 * 現在の入力内容から、保存を止めるべき理由を返す。
	 * サーバー側 save() の検証と同じ条件にしてある。
	 *
	 * @return {Array} { field, text } の配列。
	 */
	function collectProblems() {
		var problems = [];

		if ( stateUnknown ) {
			// 状態が分からないので、検証の前提が無い。
			return problems;
		}
		if ( ! protectOn || ! protectOn.checked ) {
			return problems;
		}

		var passRaw = password ? password.value : '';
		var user    = username ? trimInput( username.value ) : '';
		var pass    = trimInput( passRaw );

		var userEmpty = ( '' === user );

		/*
		 * 空白だけが入力された状態。サーバー側では「変更しない」と同じ扱いになり、
		 * 既存のパスワードが残ったまま何も起きない。
		 * 打った本人は変更したつもりなので、保存前に止めて知らせる。
		 */
		var passWhitespaceOnly = ( '' !== passRaw && '' === pass );

		// パスワードの必須判定は hasCredential（既存のハッシュが読めるか）で行う。
		var passEmpty = ( '' === pass && ! hasCredential && ! passWhitespaceOnly );

		// 両方空のときは1文にまとめる（同じ形の文を2つ並べない）。
		if ( userEmpty && passEmpty ) {
			problems.push( { field: 'username', text: i18n.bothEmpty || '' } );
			return problems;
		}

		if ( userEmpty ) {
			problems.push( { field: 'username', text: i18n.usernameEmpty || '' } );
		} else if ( /[\x00-\x1F\x7F]/.test( user ) ) {
			problems.push( { field: 'username', text: i18n.usernameControlChars || '' } );
		} else if ( -1 !== user.indexOf( ':' ) ) {
			problems.push( { field: 'username', text: i18n.usernameColon || '' } );
		} else if ( /[^\x20-\x7E]/.test( user ) ) {
			problems.push( { field: 'username', text: i18n.usernameNonAscii || '' } );
		}

		if ( passWhitespaceOnly ) {
			problems.push( { field: 'password', text: i18n.passwordWhitespace || '' } );
		} else if ( passEmpty ) {
			problems.push( { field: 'password', text: i18n.passwordEmpty || '' } );
		} else if ( '' !== pass && /[\x00-\x1F\x7F]/.test( pass ) ) {
			problems.push( { field: 'password', text: i18n.passwordControlChars || '' } );
		} else if ( '' !== pass && /[^\x20-\x7E]/.test( pass ) ) {
			problems.push( { field: 'password', text: i18n.passwordNonAscii || '' } );
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
		var texts           = [];
		var index;
		for ( index = 0; index < problems.length; index++ ) {
			texts.push( problems[ index ].text );
		}
		var key = texts.join( '' );

		// 内容が変わっていないなら再通知しない（打鍵のたびに再描画させない）。
		if ( key === lastProblemKey ) {
			return;
		}
		lastProblemKey = key;

		if ( problems.length ) {
			var firstField = problems[ 0 ].field;

			dispatchEditor.lockPostSaving( LOCK_KEY );
			if ( dispatchNotices && 'function' === typeof dispatchNotices.createErrorNotice ) {
				dispatchNotices.createErrorNotice(
					[ i18n.blockedPrefix || '' ].concat( texts ).concat( [ i18n.blockedHelp || '' ] ).join( ' ' ),
					{
						id: NOTICE_ID,
						isDismissible: false,
						actions: [
							{
								label: i18n.blockedAction || '',
								onClick: function() {
									focusMetaBox( firstField );
								}
							}
						]
					}
				);
			}
			return;
		}

		unlockSaving();
	}

	/**
	 * 保存の抑止と、そのための通知を解除する。
	 */
	function unlockSaving() {
		var dispatchEditor = getStore( 'core/editor', 'dispatch' );
		if ( dispatchEditor && 'function' === typeof dispatchEditor.unlockPostSaving ) {
			dispatchEditor.unlockPostSaving( LOCK_KEY );
		}
		var dispatchNotices = getStore( 'core/notices', 'dispatch' );
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
			var url = stateBox.querySelector( '#pggd-verify-url' );
			if ( ! url ) {
				return;
			}
			// 非クリップボード分岐では同じ id が <input> に付け替わる。
			// 将来ボタンと入力欄が共存したときに、textContent が空文字を返して
			// 無言で空をコピーすることがないよう value を先に見る。
			var urlText = url.value ? url.value : url.textContent;

			navigator.clipboard.writeText( urlText ).then( function() {
				trigger.textContent = i18n.copiedLabel || '';
				// 押しっぱなしの表示にせず、少ししたら元のラベルへ戻す。
				window.setTimeout( function() {
					trigger.textContent = i18n.copyLabel || '';
				}, 2000 );
			} ).catch( function() {
				// コピーできなくても URL は画面に出ているので手で選択できる。
			} );
		} );
	}

	/**
	 * 確認用 URL の行を、環境に応じて使いやすい形にする。
	 *
	 * navigator.clipboard は HTTPS か localhost にしか存在しない。
	 * HTTP 運用のサイトではコピーボタンを出せないので、
	 * 代わりに読み取り専用の入力欄にして「クリックで全選択」できるようにする
	 * （word-break した <code> は手で選択しづらい）。
	 */
	function injectCopyButton() {
		var row = stateBox ? stateBox.querySelector( '.pggd-url-row' ) : null;
		if ( ! row || row.querySelector( '.pggd-copy-url' ) || row.querySelector( '.pggd-url-input' ) ) {
			return;
		}

		var code = row.querySelector( '#pggd-verify-url' );
		if ( ! code ) {
			return;
		}

		if ( ! navigator.clipboard ) {
			var field = document.createElement( 'input' );
			field.type      = 'text';
			field.readOnly  = true;
			field.className = 'regular-text code pggd-url-input';
			field.id        = 'pggd-verify-url';
			field.value     = code.textContent;
			// 読み取り専用でもフォームコントロールなので、名前が無いと
			// 「編集テキスト、読み取り専用、http://…」としか読み上げられず、
			// 何の URL なのか分からない。
			field.setAttribute( 'aria-label', i18n.verifyUrlLabel || '' );
			field.addEventListener( 'focus', function() {
				field.select();
			} );
			row.replaceChild( field, code );
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
		/*
		 * 状態が分からなくなった以上、手元の isProtected / hasCredential も
		 * 当てにならない。この前提で検証を続けると
		 * 「パスワードが入力されていません」のような嘘の理由で更新を止めてしまう。
		 * 検証そのものを降ろし、抑止も通知も解除して、
		 * 画面に出す指示を「再読み込み」1本に絞る。
		 */
		stateUnknown   = true;
		lastProblemKey = null;
		unlockSaving();

		// 入力したままの平文パスワードを画面に残さない。
		if ( password ) {
			password.value = '';
			password.setAttribute( 'type', 'password' );
		}

		if ( ! stateBox ) {
			return;
		}

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'notice notice-warning inline pggd-state-note';

		// 読み上げの対象は文章だけ。操作要素はこの外に置く。
		var live = document.createElement( 'div' );
		live.setAttribute( 'role', 'status' );
		var text = document.createElement( 'p' );
		text.textContent = message || i18n.stateFailed || '';
		live.appendChild( text );
		wrapper.appendChild( live );

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
	 *
	 * id を付けるのは、同じ通知が積み上がらないようにするため
	 * （ブロックエディタの固定通知は自動では消えない）。
	 * 成功系は snackbar にして数秒で消す。コアの「更新しました」と同じ扱い。
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
				{
					id: notices[ index ].id,
					type: notices[ index ].type,
					isDismissible: true
				}
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
				var reason  = ( result && result.data && result.data.message ) ? result.data.message : '';
				var message = reason
					? ( ( i18n.stateFailedPrefix || '' ) + reason + ( i18n.reloadHint || '' ) )
					: i18n.stateFailed;
				showStateFailure( message );
				return;
			}

			stateUnknown  = false;
			isProtected   = ( 1 === parseInt( result.data.isProtected, 10 ) );
			hasCredential = ( 1 === parseInt( result.data.hasCredential, 10 ) );

			// 保存がエラーで拒否された場合は、入力していた値を書き戻さない。
			// 「もう一度お試しください」と言われた時点で欄が空だと、
			// 「もう一度」が入力し直しから始まってしまう。
			var hasError = ( 1 === parseInt( result.data.hasError, 10 ) );

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
			if ( password && ! hasError ) {
				// 入力したパスワードを欄に残さない。
				password.value = '';
				password.setAttribute( 'type', 'password' );
				if ( result.data.passwordPlaceholder ) {
					password.setAttribute( 'placeholder', result.data.passwordPlaceholder );
				} else {
					password.removeAttribute( 'placeholder' );
				}
			}
			if ( username && ! hasError ) {
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
