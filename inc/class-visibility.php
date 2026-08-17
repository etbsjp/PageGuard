<?php
/**
 * 保護中の投稿を、本体表示以外の経路から見えなくするクラス。
 *
 * docs/spec.md 5 の 2〜5（REST API・フィード・検索/アーカイブ・サイトマップ）を担う。
 * 本体（HTML）の 401 は Pggd_Auth が持っているので、ここでは
 * 「一覧に載せない」「内容を返さない」だけを扱い、認証の判定は重複させない。
 *
 * ■ 除外の向き（このファイル全体の前提）
 *
 * 保護中かどうかは Pggd_Credentials::is_protected() が正で、
 * 「索引キー（_pggd_protected）と資格情報（_pggd_credentials）の
 * どちらか一方でも保護中を示していれば保護中」と扱う。
 * このクラスの SQL / meta_query も同じ向きに揃えてある。
 *
 * このとき **_pggd_protected の「値」は見ない。キーが存在するかどうかだけで判定する。**
 * 値まで見て '1' のときだけ除外する書き方にすると、値が壊れた（'0' や空になった）
 * 瞬間に一覧へ出てしまう。キーの有無で判定すれば、壊れた側は必ず「隠す」に倒れる。
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
	 * ディスパッチが終わったら null に戻す（get_rest_context() の
	 * 戻り値が、後続の無関係なクエリにまで効き続けないようにするため）。
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

		// 添付ファイルは親投稿の保護を引き継ぐ。meta_query では親を辿れないので SQL で足す。
		add_filter( 'posts_where', array( __CLASS__, 'filter_attachment_parent_where' ), 999, 2 );

		// REST の単体取得（/wp-json/wp/v2/pages/<ID> など）と oEmbed を塞ぐ。
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'block_rest_item' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'release_rest_request' ), 10, 3 );

		// 前後の投稿へのリンクと wp_get_archives()（WP_Query を通らず素の SQL を組み立てる）。
		add_filter( 'get_previous_post_where', array( __CLASS__, 'filter_adjacent_post_where' ) );
		add_filter( 'get_next_post_where', array( __CLASS__, 'filter_adjacent_post_where' ) );
		add_filter( 'getarchives_where', array( __CLASS__, 'filter_getarchives_where' ) );

		// コメント（コメントフィード・最近のコメントウィジェット・REST のコメント一覧）。
		add_filter( 'comment_feed_where', array( __CLASS__, 'filter_comment_feed_where' ) );
		add_filter( 'comments_clauses', array( __CLASS__, 'filter_comments_clauses' ), 10, 2 );
	}

	/*-------------------------------------------*/
	/* 一覧クエリからの除外
	/*-------------------------------------------*/

	/**
	 * 一覧系のクエリから保護中の投稿を除外する。
	 *
	 * 除外条件は「保護中の索引キーを持たない」かつ「資格情報のキーを持たない」。
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
		 *
		 * 同じ理由で「保護中が0件なら条件を足さない」最適化も入れない。
		 * 数えた時点と実行する時点のズレが、そのまま漏れになる。
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
	 * 添付ファイルを返し得るクエリから、保護中の投稿に属する添付を除外する。
	 *
	 * 添付ファイル自身には保護のメタが付かないため meta_query では拾えない。
	 * /wp-json/wp/v2/media?parent=<保護中ページのID> のように親を指定されると、
	 * タイトル・キャプション・説明・代替テキスト・ファイル URL が一覧で取れてしまう。
	 * 認証側（Pggd_Auth::resolve_target_id）が添付ファイルページで親の保護を
	 * 引き継いでいるのと同じ読み替えを、一覧側にも入れる。
	 *
	 * post_parent が 0 の未添付ファイルは巻き込まない。
	 *
	 * なお、この posts_where は suppress_filters => true のクエリ
	 * （get_posts() の既定）では適用されない。そちらは pre_get_posts 側の
	 * meta_query で消える投稿本体と違い、添付ファイルまでは辿れない点が残る。
	 *
	 * @param string   $where WHERE 句。
	 * @param WP_Query $query 実行中のクエリ。
	 * @return string WHERE 句。
	 */
	public static function filter_attachment_parent_where( $where, $query ) {
		global $wpdb;

		if ( ! $query instanceof WP_Query ) {
			return $where;
		}
		if ( ! self::query_may_return_attachments( $query ) ) {
			return $where;
		}
		if ( ! self::should_exclude( $query ) ) {
			return $where;
		}

		$parent_column = $wpdb->posts . '.post_parent';

		return $where . ' AND ( ' . $parent_column . ' = 0 OR '
			. self::get_not_protected_sql( $parent_column, self::get_authorized_singular_id() ) . ' )';
	}

	/**
	 * そのクエリが添付ファイルを返し得るかを返す。
	 *
	 * @param WP_Query $query 実行中のクエリ。
	 * @return bool 返し得るなら true。
	 */
	private static function query_may_return_attachments( $query ) {
		$post_types = $query->get( 'post_type' );
		if ( empty( $post_types ) ) {
			// 投稿タイプを指定しないクエリで添付ファイルが返ることはない。
			return false;
		}

		$post_types = (array) $post_types;

		return in_array( 'any', $post_types, true ) || in_array( 'attachment', $post_types, true );
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

		if ( self::is_cli_request() ) {
			/*
			 * WP-CLI は除外しない。コマンドを打てる時点でサーバーの権限を
			 * 持っているので隠しても守りにはならず、障害対応中に
			 * 「投稿が消えた」と管理者が状況を誤認する危険のほうが大きい。
			 */
			$exclude = false;
		} elseif ( self::is_rest_request() ) {
			$exclude = ! self::is_rest_edit_context_allowed( $query );
		} elseif ( is_admin() ) {
			/*
			 * 管理画面の一覧・検索は素通しする。ただし ajax かどうかでは分けない。
			 * admin-ajax.php も admin-post.php も is_admin() が true になるうえ、
			 * admin-post.php は admin_post_nopriv_{action} を未ログインでも発火させる。
			 * 「管理画面だから安全」ではないので、権限で判定する。
			 */
			$exclude = ! self::can_see_protected_in_admin();
		}

		/**
		 * 一覧クエリから保護中の投稿を除外するかどうかを差し替えるフィルター。
		 *
		 * @param bool     $exclude 除外するなら true。
		 * @param WP_Query $query   対象のクエリ。
		 */
		return (bool) apply_filters( 'pggd_exclude_protected_from_query', $exclude, $query );
	}

	/**
	 * REST の編集コンテキストとして素通ししてよいリクエストかを返す。
	 *
	 * context はリクエスト側が自由に指定できる値なので、単独の条件にはしない。
	 * 必ず権限と AND で判定する。
	 *
	 * 権限は「クエリの対象になっている投稿タイプごとの edit_others_posts 相当」で見る。
	 * docs/spec.md 6 が定めるのは「その投稿の編集権限（edit_post）」だが、
	 * 一覧は SQL で消す以上、投稿1件ごとの判定ができない。
	 * そこで、投稿単位で判定できないぶんはいちばん狭い側に倒し、
	 * 「他人の投稿も編集できる権限」を持つ人にだけ一覧を見せる。
	 *
	 * 一般権限の edit_posts で判定してはいけない。あれは寄稿者（Contributor）も
	 * 持っているため、他人の保護中の投稿が context=edit の一覧に
	 * 生本文付きで返ってしまう（単体取得は edit_post で正しく塞がるので、
	 * 一覧だけが通る不整合になる）。
	 *
	 * @param WP_Query $query 実行前のクエリ。
	 * @return bool 素通ししてよければ true。
	 */
	private static function is_rest_edit_context_allowed( $query ) {
		if ( 'edit' !== self::get_rest_context() ) {
			return false;
		}

		$post_types = self::get_queried_post_types( $query );
		if ( empty( $post_types ) ) {
			return false;
		}

		foreach ( $post_types as $post_type ) {
			$object = get_post_type_object( $post_type );
			// 判定できない投稿タイプが混ざったら素通ししない。
			if ( ! $object || empty( $object->cap->edit_others_posts ) ) {
				return false;
			}
			if ( ! current_user_can( $object->cap->edit_others_posts ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * クエリの対象になっている投稿タイプ名を返す。
	 *
	 * 対象を絞れないとき（未指定・any）は、登録済みの全投稿タイプを返す。
	 * 権限判定はこの全部を満たすことを要求する形になるので、
	 * 絞れない場合はいちばん狭い側（＝素通ししにくい側）に倒れる。
	 *
	 * @param WP_Query $query 実行前のクエリ。
	 * @return array 投稿タイプ名の配列。
	 */
	private static function get_queried_post_types( $query ) {
		$post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) ) {
			return array_values( get_post_types( array(), 'names' ) );
		}

		$post_types = array_values( (array) $post_types );

		if ( in_array( 'any', $post_types, true ) ) {
			return array_values( get_post_types( array(), 'names' ) );
		}

		return $post_types;
	}

	/**
	 * 管理画面側の経路で、保護中の投稿を見せてよいユーザーかを返す。
	 *
	 * @return bool 見せてよければ true。
	 */
	private static function can_see_protected_in_admin() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
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
	 * ディスパッチが終わった REST リクエストを手放す。
	 *
	 * 掴んだままにすると、そのリクエストの context が
	 * 後続の無関係なクエリの判定にまで効き続ける形になる。
	 * 実害が出る経路は今のところ無いが、緩む方向の残り方なので明示的に戻す。
	 *
	 * @param WP_HTTP_Response $result  応答。
	 * @param WP_REST_Server   $server  REST サーバー（未使用）。
	 * @param WP_REST_Request  $request 処理を終えたリクエスト（未使用）。
	 * @return WP_HTTP_Response 応答（変更しない）。
	 */
	public static function release_rest_request( $result, $server, $request ) {
		self::$rest_request = null;
		return $result;
	}

	/**
	 * REST のリクエストから、保護判定の対象になる投稿IDを取り出す。
	 *
	 * ルートの文字列で判定する。`id` パラメータの有無だけで判定すると、
	 * カテゴリーやユーザーの ID（/wp/v2/categories/5 など）まで
	 * 投稿IDとして扱ってしまい、無関係な応答を 404 にしてしまう。
	 *
	 * oEmbed とコメントのルートはコアが固定で登録するもので、名前空間もパスも動かない。
	 * 投稿タイプ側の rest_base にこれらのコアのルート名が来ることは無い前提で、
	 * 先に個別の分岐で拾ってから投稿タイプの照合に進む。
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

		/*
		 * コメントの単体取得（/wp/v2/comments/<ID>）。
		 * コアの WP_REST_Comments_Controller は get_comment() を直接呼ぶため、
		 * comments_clauses フィルターが発火せず一覧側の除外がまったく効かない。
		 * コメント ID は連番で総当たりできるので、ここで塞ぐ必要がある。
		 * コメントが付いている投稿へ読み替えて、投稿と同じ保護判定に載せる。
		 */
		$comments_prefix = '/wp/v2/comments/';
		if ( 0 === strpos( $route, $comments_prefix ) ) {
			$segments = explode( '/', substr( $route, strlen( $comments_prefix ) ) );
			if ( ! isset( $segments[0] ) || ! ctype_digit( $segments[0] ) ) {
				return 0;
			}
			$comment = get_comment( (int) $segments[0] );
			return ( $comment instanceof WP_Comment ) ? (int) $comment->comment_post_ID : 0;
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
	 * WP-CLI から実行されているかどうかを返す。
	 *
	 * @return bool WP-CLI なら true。
	 */
	private static function is_cli_request() {
		return defined( 'WP_CLI' ) && WP_CLI;
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
		if ( self::is_cli_request() ) {
			return $where;
		}
		// is_admin() だけで素通しにしない（admin-post.php は未ログインでも到達できる）。
		if ( is_admin() && self::can_see_protected_in_admin() ) {
			return $where;
		}
		return $where . ' AND ' . self::get_not_protected_sql( 'p.ID' );
	}

	/**
	 * wp_get_archives() の一覧から保護中の投稿を外す。
	 *
	 * type=postbypost では投稿タイトルがそのまま並ぶ。
	 * この関数も WP_Query を通らないため個別に塞ぐ。
	 *
	 * @param string $where WHERE 句。
	 * @return string WHERE 句。
	 */
	public static function filter_getarchives_where( $where ) {
		global $wpdb;

		if ( self::is_cli_request() ) {
			return $where;
		}
		if ( is_admin() && self::can_see_protected_in_admin() ) {
			return $where;
		}
		return $where . ' AND ' . self::get_not_protected_sql( $wpdb->posts . '.ID' );
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
	 * コメントの取得から、保護中の投稿に付いたコメントを外す。
	 *
	 * 塞ぐ対象は、サイト表側の「最近のコメント」ウィジェット（get_comments()）や
	 * REST のコメント一覧など、保護中ページの本文を読めない人にまで
	 * コメント本文・投稿者名・保護中ページのタイトルと URL が届く経路。
	 *
	 * 例外は「いま表示している単一ページ自身のコメント」だけにする。
	 * そこへ到達している時点で Pggd_Auth の 401 を通過済み（＝認証を通したか
	 * 編集権限を持つか）なので、そのページのコメント表示は壊さずに済む。
	 * 「REST のときだけ効かせる」という絞り方では、表側のウィジェットが素通しになる。
	 *
	 * @param array            $clauses       SQL の各句。
	 * @param WP_Comment_Query $comment_query コメントクエリ（未使用）。
	 * @return array SQL の各句。
	 */
	public static function filter_comments_clauses( $clauses, $comment_query ) {
		if ( self::is_cli_request() ) {
			return $clauses;
		}

		if ( self::is_rest_request() ) {
			// コメントの編集コンテキストは、コアの権限判定と同じ moderate_comments で見る。
			if ( 'edit' === self::get_rest_context() && current_user_can( 'moderate_comments' ) ) {
				return $clauses;
			}
		} elseif ( is_admin() && current_user_can( 'moderate_comments' ) ) {
			// コメント管理画面（保護中ページのコメントの承認・削除）を壊さない。
			return $clauses;
		}

		$condition = self::get_not_protected_sql( 'comment_post_ID', self::get_authorized_singular_id() );
		$where     = isset( $clauses['where'] ) ? (string) $clauses['where'] : '';

		// この句は空になり得る。空のまま AND を足すと SQL が壊れる。
		$clauses['where'] = ( '' === trim( $where ) ) ? $condition : $where . ' AND ' . $condition;

		return $clauses;
	}

	/**
	 * いま表示中で、かつ認証の判定を通過済みの単一投稿のIDを返す。
	 *
	 * 「通過済みかどうか」は Pggd_Auth::is_authorized() に問い合わせる。
	 *
	 * 以前は did_action( 'template_redirect' ) の有無で代用していたが、
	 * do_action() はコールバックを1つも実行する前に実行済みカウンタを
	 * 進めてしまうため、他プラグイン・テーマが template_redirect に
	 * Pggd_Auth::maybe_require_auth()（優先度1）より早い優先度（0以下）で
	 * フックしてコメントクエリを実行すると、401 判定が確定する前から
	 * 「認証済み」と誤判定していた（fail-open の穴）。
	 * Pggd_Auth 側で判定が確定した投稿IDだけを見るこの実装は、
	 * template_redirect のフック優先度に依存しない。
	 *
	 * @return int 投稿ID。該当しなければ 0。
	 */
	private static function get_authorized_singular_id() {
		if ( self::is_rest_request() || self::is_cli_request() || is_admin() ) {
			return 0;
		}
		if ( ! is_singular() ) {
			return 0;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! Pggd_Auth::is_authorized( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * 「保護中の投稿ではない」ことを表す SQL 条件を返す。
	 *
	 * 索引キーと資格情報のどちらか一方でも持っていれば除外する
	 * （キーの存在だけを見る。値は見ない）。
	 * meta_query での除外条件と同じ向き（fail-closed）に揃えている。
	 *
	 * **$column には必ずコード中のリテラル（テーブル名 + 列名）を渡すこと。**
	 * リクエスト由来の値を渡すとそのまま SQL に入る。プレースホルダで
	 * 埋められるのはメタキーと投稿IDだけで、列名は埋められない。
	 *
	 * @param string $column          投稿IDが入っている列名（テーブル修飾を含むリテラル）。
	 * @param int    $allowed_post_id この投稿だけは除外しない（0 なら例外なし）。
	 * @return string SQL の条件式。
	 */
	private static function get_not_protected_sql( $column, $allowed_post_id = 0 ) {
		global $wpdb;

		$allowed_post_id = (int) $allowed_post_id;

		/*
		 * NOT IN ( サブクエリ ) ではなく NOT EXISTS 形にしている。
		 * MySQL では NOT IN のサブクエリが依存サブクエリに落ちて
		 * 行ごとに実行される計画になることがあり、件数が増えると重くなる。
		 */
		$sql = 'NOT EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' AS pggd_pm'
			. ' WHERE pggd_pm.post_id = ' . $column
			. ' AND pggd_pm.meta_key IN ( %s, %s ) )';

		if ( $allowed_post_id > 0 ) {
			$sql = '( ' . $column . ' = %d OR ' . $sql . ' )';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $column は呼び出し側のリテラルのみ。値は全てプレースホルダで埋めている。
			return $wpdb->prepare( $sql, $allowed_post_id, Pggd_Credentials::META_PROTECTED, Pggd_Credentials::META_CREDENTIALS );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $column は呼び出し側のリテラルのみ。値は全てプレースホルダで埋めている。
		return $wpdb->prepare( $sql, Pggd_Credentials::META_PROTECTED, Pggd_Credentials::META_CREDENTIALS );
	}
}
