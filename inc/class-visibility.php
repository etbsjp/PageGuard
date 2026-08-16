<?php
/**
 * 保護中の投稿を、本体表示以外の経路から見えなくするクラス。
 *
 * docs/spec.md 5 の 2〜5（REST API・フィード・検索/アーカイブ・サイトマップ）を担う。
 * 本体（HTML）の 401 は Pggd_Auth が持っているので、ここでは
 * 「一覧に載せない」「内容を返さない」だけを扱い、認証の判定は重複させない。
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保護中の投稿を一覧・REST・フィード・サイトマップから隠す。
 */
class Pggd_Visibility {

	/**
	 * 処理中の REST リクエスト。
	 *
	 * pre_get_posts は REST 経由でも走るが、そこからは
	 * 「どの context で呼ばれたか」を知る手段が無いため、
	 * ディスパッチ時に掴んだリクエストを持ち回る。
	 *
	 * @var WP_REST_Request|null
	 */
	private static $rest_request = null;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		/*
		 * 優先度を遅くして、テーマ・他プラグインが同じフックで組み立てた
		 * meta_query の「あと」に自分の条件を足す（相手の条件を消さずに AND で重ねる）。
		 */
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_query' ), 999 );

		// REST の単体取得（/wp-json/wp/v2/pages/<ID> など）と oEmbed を塞ぐ。
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_rest_item' ), 10, 3 );

		// 前後の投稿へのリンク（WP_Query を通らず素の SQL を組み立てるため個別に塞ぐ）。
		add_filter( 'get_previous_post_where', array( __CLASS__, 'filter_adjacent_post_where' ) );
		add_filter( 'get_next_post_where', array( __CLASS__, 'filter_adjacent_post_where' ) );

		// コメントフィード（/comments/feed/）と REST のコメント一覧。
		add_filter( 'comment_feed_where', array( __CLASS__, 'filter_comment_feed_where' ) );
		add_filter( 'comments_clauses', array( __CLASS__, 'filter_comments_clauses' ), 10, 2 );
	}

	/*-------------------------------------------*/
	/* 一覧クエリからの除外
	/*-------------------------------------------*/

	/**
	 * 一覧系のクエリから保護中の投稿を除外する。
	 *
	 * 除外条件は「保護中フラグを持たない」かつ「資格情報を持たない」。
	 * Pggd_Credentials::is_protected() が索引キーと資格情報の
	 * 「どちらか一方でも保護中を示していれば保護中」と扱うため、
	 * クエリ側も同じ向き（どちらか一方でも持っていれば隠す）に揃えている。
	 * 片方だけが残った壊れた状態でも一覧に出さない（fail-closed）。
	 *
	 * @param WP_Query $query 実行前のクエリ。
	 * @return void
	 */
	public static function exclude_from_query( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}
		if ( ! self::should_exclude( $query ) ) {
			return;
		}

		/*
		 * 除外は meta_query で行う。投稿IDの索引を option などに持って
		 * post__not_in で外す方法もあるが、索引と実体がズレた瞬間に
		 * 保護が外れる（＝漏れる側に倒れる）ため採らない。
		 * メタ検索ぶんの JOIN がフロントのクエリに乗るコストは承知のうえで、
		 * 認証プラグインとして漏れない側を選んでいる。
		 */
		$exclusion = array(
			'relation' => 'AND',
			array(
				'key'     => Pggd_Credentials::META_PROTECTED,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Pggd_Credentials::META_CREDENTIALS,
				'compare' => 'NOT EXISTS',
			),
		);

		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			$query->set( 'meta_query', $exclusion );
			return;
		}

		// 既存の条件は入れ子のまま残し、AND で自分の条件と束ねる。
		$query->set(
			'meta_query',
			array(
				'relation' => 'AND',
				$existing,
				$exclusion,
			)
		);
	}

	/**
	 * このクエリに除外を効かせるかどうかを返す。
	 *
	 * @param WP_Query $query 実行前のクエリ。
	 * @return bool 除外するなら true。
	 */
	private static function should_exclude( $query ) {
		/*
		 * ページ本体の表示（メインクエリの単一投稿）は対象外。ここで除外すると、
		 * 正しい ID / パスワードで認証を通した閲覧者にまで 404 を返すことになる。
		 * 本体を守るのは Pggd_Auth の 401 で、こちらの担当ではない。
		 *
		 * is_main_query() まで見ているのは、テーマやショートコードが
		 * new WP_Query( array( 'p' => 12 ) ) のように単一投稿を引いて
		 * 別のページに埋め込む書き方があるため。これは認証を通っていない
		 * 経路なので、単一投稿の形でも除外する。
		 */
		if ( $query->is_singular() && $query->is_main_query() ) {
			return false;
		}

		$exclude = true;

		if ( self::is_rest_request() ) {
			/*
			 * 編集コンテキストだけは素通しする。context=edit はコア側で
			 * edit_posts 権限を要求しているため、ブロックエディターの
			 * 親ページ選択などが動かなくなるのを防げる。
			 * 権限も併せて見て、context の指定だけで通らないようにしている。
			 */
			$exclude = ! ( 'edit' === self::get_rest_context() && current_user_can( 'edit_posts' ) );
		} elseif ( is_admin() ) {
			if ( wp_doing_ajax() ) {
				/*
				 * admin-ajax.php は is_admin() が true になるが未ログインでも叩ける。
				 * 管理画面の操作を壊さないために権限で判定する。
				 */
				$exclude = ! current_user_can( 'edit_posts' );
			} else {
				// 投稿一覧・検索など管理画面の表示はそのまま動かす。
				$exclude = false;
			}
		}

		/**
		 * 一覧クエリから保護中の投稿を除外するかどうかを差し替えるフィルター。
		 *
		 * @param bool     $exclude 除外するなら true。
		 * @param WP_Query $query   対象のクエリ。
		 */
		return (bool) apply_filters( 'pggd_exclude_protected_from_query', $exclude, $query );
	}

	/*-------------------------------------------*/
	/* REST API
	/*-------------------------------------------*/

	/**
	 * REST の単体取得で、保護中の投稿の内容を返さない。
	 *
	 * 一覧・検索エンドポイントは WP_Query を通るので exclude_from_query() で消えるが、
	 * ID を直接指定する単体取得はクエリを通らないため、ここで止める。
	 *
	 * 401 ではなく 404 を返す。REST を叩いているのはブラウザ内のスクリプトのことが多く、
	 * 401 と WWW-Authenticate を返すと画面の裏で BASIC 認証ダイアログが開いてしまう。
	 * ページ本体が 401 を返す以上「存在」自体は隠せていないので、
	 * ここは「内容を返さない」ことだけを目的に、余計な副作用の無い 404 にしている。
	 *
	 * @param mixed           $result  他のフィルターが決めた応答（未決定なら null）。
	 * @param WP_REST_Server  $server  REST サーバー（未使用）。
	 * @param WP_REST_Request $request 処理中のリクエスト。
	 * @return mixed 応答。保護中なら WP_Error。
	 */
	public static function block_rest_item( $result, $server, $request ) {
		// pre_get_posts から context を見られるようにここで掴んでおく。
		if ( $request instanceof WP_REST_Request ) {
			self::$rest_request = $request;
		}

		// 既に他のフィルターが応答を決めているなら触らない。
		if ( null !== $result && false !== $result ) {
			return $result;
		}
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$post_id = self::get_post_id_from_rest_request( $request );
		if ( $post_id < 1 ) {
			return $result;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $result;
		}

		// 添付ファイルは親投稿の保護を引き継ぐ。判定は認証側と同じ関数を使う。
		$target_id = Pggd_Auth::resolve_target_id( $post );

		if ( ! Pggd_Credentials::is_protected( $target_id ) ) {
			return $result;
		}

		// その投稿を編集できるユーザーは素通し（docs/spec.md 6）。編集画面が壊れないようにする。
		if ( is_user_logged_in() && current_user_can( 'edit_post', $target_id ) ) {
			return $result;
		}

		return new WP_Error(
			'pggd_rest_protected',
			__( 'このコンテンツは保護されているため取得できません。', 'pageguard' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * REST のリクエストから、保護判定の対象になる投稿IDを取り出す。
	 *
	 * ルートの文字列で判定する。`id` パラメータの有無だけで判定すると、
	 * カテゴリーやユーザーの ID（/wp/v2/categories/5 など）まで
	 * 投稿IDとして扱ってしまい、無関係な応答を 404 にしてしまう。
	 *
	 * @param WP_REST_Request $request 処理中のリクエスト。
	 * @return int 投稿ID。該当しなければ 0。
	 */
	private static function get_post_id_from_rest_request( $request ) {
		$route = '/' . ltrim( (string) $request->get_route(), '/' );

		/*
		 * oEmbed は URL を渡す形なので別扱い。
		 * 塞がないと、保護中ページのタイトルと埋め込み HTML が未認証で取れる。
		 */
		if ( '/oembed/1.0/embed' === untrailingslashit( $route ) ) {
			$url = (string) $request->get_param( 'url' );
			return ( '' === $url ) ? 0 : (int) url_to_postid( $url );
		}

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			$namespace = ! empty( $post_type->rest_namespace ) ? $post_type->rest_namespace : 'wp/v2';
			$rest_base = ! empty( $post_type->rest_base ) ? $post_type->rest_base : $post_type->name;

			$prefix = '/' . trim( (string) $namespace, '/' ) . '/' . trim( (string) $rest_base, '/' ) . '/';
			if ( 0 !== strpos( $route, $prefix ) ) {
				continue;
			}

			// prefix の次のセグメントが数値なら投稿IDとみなす（/revisions などが続いても拾える）。
			$segments = explode( '/', substr( $route, strlen( $prefix ) ) );
			if ( ! isset( $segments[0] ) || '' === $segments[0] || ! ctype_digit( $segments[0] ) ) {
				continue;
			}

			return (int) $segments[0];
		}

		return 0;
	}

	/**
	 * REST リクエストの処理中かどうかを返す。
	 *
	 * @return bool REST なら true。
	 */
	private static function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * 処理中の REST リクエストの context を返す。
	 *
	 * @return string context（掴めていなければ空文字）。
	 */
	private static function get_rest_context() {
		if ( ! self::$rest_request instanceof WP_REST_Request ) {
			return '';
		}
		return (string) self::$rest_request->get_param( 'context' );
	}

	/*-------------------------------------------*/
	/* WP_Query を通らない経路
	/*-------------------------------------------*/

	/**
	 * 前後の投稿へのリンクから保護中の投稿を外す。
	 *
	 * get_adjacent_post() は WP_Query を使わず SQL を直接組み立てるため、
	 * meta_query による除外が効かない。タイトルだけとはいえ
	 * 保護中ページの存在が隣の記事から漏れるので、ここでも外す。
	 *
	 * @param string $where WHERE 句（"WHERE ..." で始まる）。
	 * @return string WHERE 句。
	 */
	public static function filter_adjacent_post_where( $where ) {
		if ( is_admin() ) {
			return $where;
		}
		return $where . ' AND ' . self::get_not_protected_sql( 'p.ID' );
	}

	/**
	 * コメントフィードから保護中の投稿のコメントを外す。
	 *
	 * サイト全体のコメントフィード（/comments/feed/）には、
	 * 保護中ページに付いたコメントの本文と投稿タイトルが載ってしまう。
	 * 保護中ページ自身のコメントフィードは Pggd_Auth が 401 で止めている。
	 *
	 * @param string $cwhere コメント取得の WHERE 句（"WHERE ..." で始まる）。
	 * @return string WHERE 句。
	 */
	public static function filter_comment_feed_where( $cwhere ) {
		global $wpdb;
		return $cwhere . ' AND ' . self::get_not_protected_sql( $wpdb->comments . '.comment_post_ID' );
	}

	/**
	 * REST のコメント取得から保護中の投稿のコメントを外す。
	 *
	 * /wp-json/wp/v2/comments?post=<ID> は投稿本体を返さないため
	 * REST の単体ブロックに引っかからないが、コメント本文はページの中身と同じく
	 * 未認証で読ませたくない。フロントの wp_list_comments（＝認証を通した
	 * ページ上でのコメント表示）まで消さないよう、REST に限って効かせる。
	 *
	 * @param array            $clauses       SQL の各句。
	 * @param WP_Comment_Query $comment_query コメントクエリ（未使用）。
	 * @return array SQL の各句。
	 */
	public static function filter_comments_clauses( $clauses, $comment_query ) {
		if ( ! self::is_rest_request() ) {
			return $clauses;
		}
		if ( 'edit' === self::get_rest_context() && current_user_can( 'edit_posts' ) ) {
			return $clauses;
		}

		$condition = self::get_not_protected_sql( 'comment_post_ID' );
		$where     = isset( $clauses['where'] ) ? (string) $clauses['where'] : '';

		// この句は空になり得る。空のまま AND を足すと SQL が壊れる。
		$clauses['where'] = ( '' === trim( $where ) ) ? $condition : $where . ' AND ' . $condition;

		return $clauses;
	}

	/**
	 * 「保護中の投稿ではない」ことを表す SQL 条件を返す。
	 *
	 * 索引キーと資格情報のどちらか一方でも持っていれば除外する。
	 * meta_query での除外条件と同じ向き（fail-closed）に揃えている。
	 *
	 * @param string $column 投稿IDが入っている列名（テーブル修飾を含む）。
	 * @return string SQL の条件式。
	 */
	private static function get_not_protected_sql( $column ) {
		global $wpdb;

		// 列名は呼び出し側が渡す固定文字列のみ。外部入力は混ぜない。
		return $column . ' NOT IN ( ' . $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
			Pggd_Credentials::META_PROTECTED,
			Pggd_Credentials::META_CREDENTIALS
		) . ' )';
	}
}
