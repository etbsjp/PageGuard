<?php
/**
 * 保護設定（資格情報）の読み書きを担うクラス。
 *
 * 投稿メタの構造をこのクラスの中だけに閉じ込め、
 * 呼び出し側がメタキーやデータ形式を直接触らないようにしている。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保護設定（資格情報）の読み書き。
 *
 * ■ データ構造
 *
 * _pggd_credentials … 資格情報「1組ぶんの連想配列」を要素に持つ配列。
 *   array(
 *     array(
 *       'username'         => 'client-a',   // 平文（docs/spec.md 3）
 *       'password_hash'    => '$2y$10$...', // password_hash() の結果
 *       'password_updated' => 1760000000,   // パスワードを設定した UNIX time
 *     ),
 *   )
 *   v1.0 は「1ページにつき1組」（docs/spec.md 1）なので要素は常に1つだが、
 *   最初から配列で持つことで v1.1 の複数組対応が UI の拡張だけで済む。
 *
 * _pggd_protected … 保護中のとき '1'。将来の「保護中ページ一覧」を
 *   meta_query で引くための独立した索引キー。資格情報そのものは
 *   シリアライズされた配列なので meta_query では引けないため併置している。
 *
 * どちらもアンダースコア始まりで、register_meta() を使わない
 * （＝カスタムフィールド UI にもRESTにもハッシュを露出させない）。
 */
class Pggd_Credentials {

	/**
	 * 保護中を示す索引用メタキー。
	 */
	const META_PROTECTED = '_pggd_protected';

	/**
	 * 資格情報を格納するメタキー。
	 */
	const META_CREDENTIALS = '_pggd_credentials';

	/**
	 * save_primary() の戻り値: 保存できた。
	 */
	const SAVE_OK = 'ok';

	/**
	 * save_primary() の戻り値: 保存できず、保護の状態は保存前のまま。
	 */
	const SAVE_FAILED = 'failed';

	/**
	 * save_primary() の戻り値: 保存できなかったが、保護の状態が変わってしまった。
	 *
	 * メタを2つ書くため、片方だけ書けて片方が落ちる形の失敗があり得る。
	 * 「変更していません」と伝えてはいけない場面。
	 */
	const SAVE_PARTIAL = 'partial';

	/**
	 * 投稿に保存された資格情報の一覧を返す。
	 *
	 * 保存形式が壊れている要素は取り除いて返すため、
	 * 戻り値の各要素は必ず username / password_hash を持つ。
	 *
	 * @param int $post_id 投稿ID。
	 * @return array 資格情報の配列（無ければ空配列）。
	 */
	public static function get_all( $post_id ) {
		$stored = get_post_meta( (int) $post_id, self::META_CREDENTIALS, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$credentials = array();
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$username = isset( $item['username'] ) ? (string) $item['username'] : '';
			$hash     = isset( $item['password_hash'] ) ? (string) $item['password_hash'] : '';
			// ユーザー名かハッシュが欠けている組は認証に使えないので捨てる。
			if ( '' === $username || '' === $hash ) {
				continue;
			}
			$credentials[] = array(
				'username'         => $username,
				'password_hash'    => $hash,
				'password_updated' => isset( $item['password_updated'] ) ? (int) $item['password_updated'] : 0,
			);
		}

		return $credentials;
	}

	/**
	 * 主となる資格情報（1組目）を返す。
	 *
	 * v1.0 の画面はこの1組だけを扱う。
	 *
	 * @param int $post_id 投稿ID。
	 * @return array|null 資格情報。無ければ null。
	 */
	public static function get_primary( $post_id ) {
		$credentials = self::get_all( $post_id );
		return isset( $credentials[0] ) ? $credentials[0] : null;
	}

	/**
	 * 投稿が BASIC 認証で保護されているかを返す。
	 *
	 * 資格情報の実体と索引キーのどちらか一方でも「保護中」を示していれば保護中と扱う。
	 * 片方だけが失われたときに、どちらの向きでも保護が外れない（fail-closed）ようにするため。
	 * 索引だけが残った場合は資格情報が無いので誰も認証を通せないが、
	 * その投稿の編集権限を持つユーザーは素通りできるので設定し直して復旧できる。
	 *
	 * @param int $post_id 投稿ID。
	 * @return bool 保護中なら true。
	 */
	public static function is_protected( $post_id ) {
		if ( ! empty( self::get_all( $post_id ) ) ) {
			return true;
		}
		return '1' === (string) get_post_meta( (int) $post_id, self::META_PROTECTED, true );
	}

	/**
	 * 主となる資格情報を保存する（新規保護・変更の両方で使う）。
	 *
	 * パスワードに空文字を渡した場合は既存のハッシュと設定日時を引き継ぐ。
	 * 「パスワード欄が空＝変更しない」という画面側の仕様に対応するため。
	 *
	 * @param int    $post_id  投稿ID。
	 * @param string $username ユーザー名（平文）。
	 * @param string $password 新しいパスワード（平文）。空文字なら据え置き。
	 * @return string SAVE_OK / SAVE_FAILED / SAVE_PARTIAL のいずれか。
	 */
	public static function save_primary( $post_id, $username, $password ) {
		$post_id  = (int) $post_id;
		$username = (string) $username;
		$password = (string) $password;

		$credentials = self::get_all( $post_id );
		$current     = isset( $credentials[0] ) ? $credentials[0] : null;

		// 失敗したときに「状態が変わったかどうか」を判定するための保存前の姿。
		$before_protected  = self::is_protected( $post_id );
		$before_credential = $current;

		if ( '' !== $password ) {
			// PASSWORD_DEFAULT は PHP のバージョン更新でアルゴリズムが強くなる。
			// password_verify() はハッシュ側の情報で判定するので、混在しても検証できる。
			$hash    = password_hash( $password, PASSWORD_DEFAULT );
			$updated = time();
		} elseif ( $current ) {
			$hash    = $current['password_hash'];
			$updated = $current['password_updated'];
		} else {
			// 新規保護でパスワードが無いのは保護として成立しない。
			// 何も書いていないので状態は変わっていない。
			return self::SAVE_FAILED;
		}

		if ( false === $hash || '' === (string) $hash ) {
			return self::SAVE_FAILED;
		}

		$credentials[0] = array(
			'username'         => $username,
			'password_hash'    => (string) $hash,
			'password_updated' => (int) $updated,
		);

		// update_metadata() は内部でもう一度 wp_unslash() する。
		// そのまま渡すとユーザー名のバックスラッシュが保存時に落ちるため、
		// あらかじめ wp_slash() で1段ぶん増やしておく。
		update_post_meta( $post_id, self::META_CREDENTIALS, wp_slash( $credentials ) );
		// 一覧を meta_query で引くための索引を立てる。
		update_post_meta( $post_id, self::META_PROTECTED, '1' );

		/*
		 * 書き込めたことを読み戻して確かめる。
		 *
		 * update_post_meta() の戻り値だけでは判定できない。あの関数は
		 * 「値が変わらなかった」ときにも false を返すため、失敗と区別が付かない。
		 * 逆に戻り値を見ずに true を返すと、書き込みに失敗しても画面には
		 * 「保護しました」と出て、利用者が無保護のページの URL を配ることになる。
		 *
		 * 書き込みが失敗した場合、update_metadata() はメタのキャッシュを
		 * 更新しないため、ここで読み戻すと古い値が返り、食い違いを検出できる。
		 */
		$saved = self::get_all( $post_id );

		$stored_ok = isset( $saved[0] )
			&& hash_equals( $credentials[0]['password_hash'], $saved[0]['password_hash'] )
			&& hash_equals( $credentials[0]['username'], $saved[0]['username'] )
			&& '1' === (string) get_post_meta( $post_id, self::META_PROTECTED, true );

		if ( $stored_ok ) {
			return self::SAVE_OK;
		}

		/*
		 * 書き込めなかった。ここで「保護の状態は変更していません」と伝えてよいかは、
		 * 実際に何が残ったかによる。このメソッドはメタを2つ書くため、
		 * 片方だけ書けて片方が落ちる形の失敗があり得る。
		 *
		 *  ・資格情報が落ちて索引だけ立った → is_protected() は true になる（fail-closed）が、
		 *    認証できる資格情報が無い。保護前と同じ状態ではない
		 *  ・資格情報は書けて索引が落ちた → 保護は正しく効いている。これも保存前とは違う
		 *
		 * どちらも「変更していません」は嘘になるので、読み直して区別し、
		 * 呼び出し側が実態に合った文言を出せるようにする。
		 *
		 * 巻き戻しは意図的にしない。理由は3つ。
		 *  1. 書き込みが失敗している状況では、巻き戻しの書き込みも失敗する見込みが高い。
		 *     「たいてい戻せる」コードは、戻せなかったときに不整合を隠すぶんかえって悪い
		 *  2. 巻き戻すと、成功した側の書き込みまで消すことになる。
		 *     資格情報が書けて索引だけ落ちた場合、ページは正しく保護されている。
		 *     それを巻き戻すのは、認証プラグインとして最悪の方向（保護を自分で外す）
		 *  3. 索引だけ残った状態は fail-closed（誰も入れない）で漏れにはならず、
		 *     メタボックスがその状態を検知して復旧手順を表示する作りになっている
		 */
		$after_protected  = self::is_protected( $post_id );
		$after_credential = self::get_primary( $post_id );

		$unchanged = ( $after_protected === $before_protected )
			&& self::is_same_credential( $before_credential, $after_credential );

		return $unchanged ? self::SAVE_FAILED : self::SAVE_PARTIAL;
	}

	/**
	 * 資格情報2つが同じ内容かを返す。
	 *
	 * @param array|null $a 比較対象。
	 * @param array|null $b 比較対象。
	 * @return bool 同じなら true。
	 */
	private static function is_same_credential( $a, $b ) {
		if ( null === $a && null === $b ) {
			return true;
		}
		if ( null === $a || null === $b ) {
			return false;
		}
		return hash_equals( $a['username'], $b['username'] )
			&& hash_equals( $a['password_hash'], $b['password_hash'] )
			&& (int) $a['password_updated'] === (int) $b['password_updated'];
	}

	/**
	 * 投稿の保護を解除し、資格情報を削除する。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	public static function delete( $post_id ) {
		$post_id = (int) $post_id;
		delete_post_meta( $post_id, self::META_CREDENTIALS );
		delete_post_meta( $post_id, self::META_PROTECTED );
	}

	/**
	 * 送信されたユーザー名 / パスワードが投稿の資格情報と一致するかを検証する。
	 *
	 * ユーザー名の比較は hash_equals()、パスワードは password_verify() を使い、
	 * どちらも比較にかかる時間から内容を推測されにくくする。
	 *
	 * @param int    $post_id  投稿ID。
	 * @param string $username 送信されたユーザー名。
	 * @param string $password 送信されたパスワード。
	 * @return bool 一致すれば true。
	 */
	public static function verify( $post_id, $username, $password ) {
		$credentials = self::get_all( $post_id );
		if ( empty( $credentials ) ) {
			return false;
		}

		$username = (string) $username;
		$password = (string) $password;

		$matched = false;
		$checked = false;

		foreach ( $credentials as $credential ) {
			// 早期 return せず全組を回すことで、一致した位置による処理時間の差を減らす。
			if ( ! hash_equals( $credential['username'], $username ) ) {
				continue;
			}
			$checked = true;
			if ( password_verify( $password, $credential['password_hash'] ) ) {
				$matched = true;
			}
		}

		if ( ! $checked ) {
			// ユーザー名がどれとも一致しなかった場合、ここで打ち切ると
			// password_verify() の計算時間ぶんだけ応答が速くなり、
			// 「ユーザー名が違う」ことを応答時間から判別できてしまう。
			// 保存済みのハッシュ（＝同じアルゴリズムとコスト）に対して
			// 必ず1回検証を回し、処理時間を揃える。結果は使わない。
			password_verify( $password, $credentials[0]['password_hash'] );
		}

		return $matched;
	}
}
