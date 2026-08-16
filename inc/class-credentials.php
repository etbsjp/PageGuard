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
	 * 索引キー（_pggd_protected）ではなく資格情報の実体を根拠にする。
	 * 索引キーだけが失われたときに保護が外れる（fail-open）事故を避けるため。
	 *
	 * @param int $post_id 投稿ID。
	 * @return bool 保護中なら true。
	 */
	public static function is_protected( $post_id ) {
		$credentials = self::get_all( $post_id );
		return ! empty( $credentials );
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
	 * @return bool 保存できたら true。
	 */
	public static function save_primary( $post_id, $username, $password ) {
		$post_id  = (int) $post_id;
		$username = (string) $username;
		$password = (string) $password;

		$credentials = self::get_all( $post_id );
		$current     = isset( $credentials[0] ) ? $credentials[0] : null;

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
			return false;
		}

		if ( false === $hash || '' === (string) $hash ) {
			return false;
		}

		$credentials[0] = array(
			'username'         => $username,
			'password_hash'    => (string) $hash,
			'password_updated' => (int) $updated,
		);

		update_post_meta( $post_id, self::META_CREDENTIALS, $credentials );
		// 一覧を meta_query で引くための索引を立てる。
		update_post_meta( $post_id, self::META_PROTECTED, '1' );

		return true;
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
		foreach ( $credentials as $credential ) {
			// 早期 return せず全組を回すことで、一致した位置による処理時間の差を減らす。
			if ( ! hash_equals( $credential['username'], $username ) ) {
				continue;
			}
			if ( password_verify( $password, $credential['password_hash'] ) ) {
				$matched = true;
			}
		}

		return $matched;
	}
}
