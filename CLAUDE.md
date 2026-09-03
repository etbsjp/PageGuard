# PageGuard 開発時の制約

ページ単位で BASIC 認証をかける WordPress プラグイン。**確定仕様は [`docs/spec.md`](docs/spec.md) が正本。**

etbs のプラグイン共通ルールと既知の罠は `~/.claude/etbs-plugin-rules.md` にある。**着手前に必ず読むこと。**
ローカル環境（検証サイト・PHP バイナリのパスなど）は `CLAUDE.local.md` にある（git 管理外）。

## 名前とバージョン

| 項目 | 値 |
|---|---|
| 名称 | PageGuard |
| スラッグ | `pageguard` |
| 関数・オプション・フックのプレフィックス | `pggd_` |
| 定数のプレフィックス | `PGGD_`（自プラグイン判定用に `PGGD_PLUGIN_FILE` を持つ） |
| 設定画面の画面ID | `settings_page_pageguard`（`add_options_page` を使うため） |
| リポジトリ / ブランチ | `etbsjp/PageGuard` の **`dist` 一本**（`main` は無い） |

## アンインストール

★ `uninstall.php` の方針は**案A**（task-queue #108）。判定は3分類。

| 利用者が作ったコンテンツ（投稿・投稿メタ） | 利用者が設定した値（オプション） | 一時状態・自分が仕掛けた cron |
|---|---|---|
| **消さない** | **消さない** | **消す** |

理由は害の非対称性。消さないことの害は「DB に少量のレコードが残る」だけだが、
消すことの害は復旧不可能。迷ったら残す側に倒す。

このプラグインでの当てはめ:

- **残す** … 投稿メタ `_pggd_protected` / `_pggd_credentials`（保護設定。消すと保護ページが
  一斉に閲覧可能になり、パスワードはハッシュ保存なので復元できない）、オプション
  `pggd_post_types` / `pggd_max_attempts` / `pggd_lockout_seconds`（設定画面で利用者が
  設定した値。`register_setting()` で登録しており保存はコアが行うため `update_option` の
  grep では見つからない）、TTL 付き transient（自然消滅する）
- **消す** … オプション `pggd_lockouts`（ロックアウト記録。上限200件・非 autoload）、
  `pggd_diagnosis_result`（受信診断の結果。再実行できる）

★ 自前の cron 登録（`wp_schedule_event()`）は無い。同梱の PUC（plugin-update-checker。更新チェック
ライブラリ）が更新チェック用の cron を持つが、PUC 自身が有効化解除時に `wp_clear_scheduled_hook()` で
自己クリーンアップするため、標準的な削除導線（必ず有効化解除を経由する）では `uninstall.php` 実行時点で
残っていない。よって `wp_unschedule_hook()` は不要。独自テーブルも無い。

★ 配布8本のうち「消す」に該当するのは editlock（テーブルと cron）とこのプラグイン（一時状態の
オプション2つ）の2本だけ。他の6本は「何も消さない」が正しい。**横並びで揃えにこないこと。**

## 動作要件

- 外部依存なし
- **`Requires at least`（WP）は実測した下限があるときだけ書く。無ければ書かない。** pageguard は etbsjp/task-queue#88 で自前コード・フック・同梱 PUC を実測した結果、実下限が 5.3（`wp_date()`）と判明したが、6.x 帯に下限は無いため **ヘッダには書かない**（`Requires at least: 6.7` は初版からの定型文で、特定の API に紐づいたものではなかった）
- **`Requires PHP: 7.4` は実測下限ではなく「etbs が動作を保証する最低 PHP」の宣言として据え置く。** 7.4 実バイナリで `php -l` が通ることは 7.4 で*足りる*証明であって 7.4 が*必要*である証明ではない（7.3 以下は未検証）。2つのヘッダは過剰宣言したときの害の向きが逆（WP は有効化・更新を拒否するが、PHP は入れられる環境が狭まるだけ）なので、同じ基準で扱わない
- ★★ **「据え置き」と「新規に足す」は別問題**（2026-08-25 / task-queue #111 で再確認）。既に宣言している版を据え置いても新たに締め出す個体は生まれないが、**無宣言のプラグインに `Requires PHP` を新しく足すと、いま更新が届いている個体を以後届かなくする**。`woo-checkout-colorbox` と `widget-shortcode-tools` が無宣言なのは、この理由による意図的な判断。**8本で揃えにこないこと**
- **他のプラグインと横並びで揃えない。** 実下限はプラグインごとに違う
- **PHP 8 専用構文を使わない。** アロー関数以降の記法・名前付き引数・`match`・コンストラクタプロモーション・`str_contains` 等は不可
- 実装後は **PHP 7.4 実バイナリで `php -l`** を全ファイルに通す（パスは `CLAUDE.local.md`）
- **ヘッダの `Requires at least` / `Requires PHP` を変更したときは、利用者向けの記述も
  同時に見直すこと。該当は2箇所ある。**
  - `readme.txt` の **`= 動作環境 =`**（`* PHP 7.4 以上` と
    `WordPress のバージョン下限は設けていません。`）
  - `README.md` の **`## 動作要件`** の表（`| PHP | 7.4 以上 |`）

  ★★ **見落としやすいのは `readme.txt` のほう。** この散文は `== Description ==` 配下にあり、
  plugin-update-checker が `sections` としてそのまま渡すため
  （`inc/plugin-update-checker/Puc/v5p5/Vcs/PluginUpdateChecker.php:183-185` の
  `$pluginInfo->sections = array_merge( $pluginInfo->sections, $readme['sections'] )`）、
  **管理画面の「詳細を表示」モーダルに出る＝ `README.md` より利用者の目に触れる。**
  `README.md` も `export-ignore` されておらず配布 zip に含まれるが、こちらは zip を開くか
  GitHub を見ないと読まれない。

  ★ **`Requires at least` については `README.md` に対応する記述が現状「無い」。**
  `README.md` に WordPress のバージョン要件を述べた行は1つも無いので、
  「該当箇所を見に行ったが無かった」を「見直し済み」と取り違えないこと。足すなら表に行を起こす
  （`ordermemo` の `README.md` には同趣旨の一文がある）。

  揃えないまま放置すると、次に見た人がどちらが正しいか分からず、利用者向けの記述に合わせて
  ヘッダへ過剰宣言を書き戻す方向に動きかねない。

  ★ **行番号で指さないこと。** 1行入れば即ズレる。`excelrange` / `ordermemo` の同じ節は
  節名で指している（ただし向こうの見出しは「必要環境」で、このリポジトリは `## 動作要件`）。

## 絶対にやってはいけないこと

- **`.htaccess` を自動で書き換えない。** 他プラグイン・サーバー設定と衝突して 500 になったときの復旧コストが重い。
  診断結果とスニペットの**表示に留める**（利用者が自分で貼る）
- **設定済みパスワードを画面に再表示しない。** `password_hash` で保存し `password_verify` で検証する。
  文字列比較が要る箇所は `hash_equals` を使い、タイミング差を残さない
- **`-old` などのバックアップファイルを作らない**（git で戻せる。配布 zip にも同梱されてしまう）
- **「メディアファイルへの直リンクは守れない」を隠さない。** README と管理画面の両方に明記する

## レビュー工程に大（シニアエンジニア）を追加する

このリポジトリでは、安藤（`vk-code-reviewer`）のレビューのあと、**PR を作成する前に**
大（`etbs-senior-wp`）の監査を必ず通すこと。大は etbs の申し送りと過去に踏んだ罠に照らして
「リリースできる形になっているか」を見る担当で、安藤の一般的なコード品質レビューとは層が違う。
認証プラグインという性質上、コード品質だけでなく `etbs-plugin-rules.md` の既知の罠に照らした監査を必ず挟むこと。

- `Agent` ツールで `subagent_type: etbs-senior-wp`、`name: etbs-senior-wp`、
  **`run_in_background: false`** で起動する
- **`isolation: "worktree"` は使えるなら付ける**（付けないと起動応答は「成功」と返るのに
  一度も作業せず待機状態に入ることがある）。ただし ★★ **作業ディレクトリが git リポジトリでないと
  使えない**。その場合は **isolation なしで起動してよい**。「必須」ではない。
  **見分け方は起動応答の形**——`output_file` 付きの正常形なら動いている
- prompt には対象リポジトリ・ブランチ・差分（または PR 番号）を渡す
- 大には **出力の末尾に `監査結果: PASS` または `監査結果: FAIL` を必ず書くよう指示する**
  （★ 大の定義ファイルには出力形式の指定が無いため、指示しないと合否を機械判定できない）
- `監査結果: PASS` を受け取るまで PR を作成しない。`FAIL` なら和田へ差し戻して再監査する

★ 大は vk-agents のメンバー表に登録されていないため、指示が無いと**永久に呼ばれない**。

## CI（2026-08-28 導入）

**共通ルールは `~/.claude/etbs-plugin-rules.md` の 2.7 節**（standard の選定理由・`phpcbf` を走らせない理由・
third-party action をタグ固定にしている判断・陽性対照・配布物の検証手順など）。
**そちらの内容はここに転記しない**（二重管理になり、必ず片方が古びる）。
ここに置くのは **このリポジトリでしか決まらない値**だけ。

- 定義は `.github/workflows/ci.yml`。PR ごとに `php -l`（PHP 7.4 / 8.3）と
  `PHPCS (WordPress-Extra, changed lines)` が走る。`dist` への直 push では `php -l` の2つだけ走る
- **既存指摘の基準値: 29 ERROR / 49 WARNING**（2026-08-28 実測・`WordPress-Extra`）。
  ★ 測り直すときは `vendor/bin/phpcs --standard=./.phpcs.xml.dist --report=summary $(git ls-files '*.php')`
  の形でのみ行う。素の phpcs は `.gitignore` を尊重しない
- **`Requires PHP: 7.4` を宣言している。** 置き場は **`pageguard.php:6` と `readme.txt:5` の2箇所**。
  同梱の plugin-update-checker（PUC）は readme 側の値でヘッダを上書きする仕様のため、**片方だけ
  変更すると配信メタデータがズレる。必ず同時に変更すること。** CI の matrix `['7.4','8.3']`
  （`.github/workflows/ci.yml`）と合わせて**3箇所を一致させた状態を守ること**（どれか1つだけ
  動かさない）
- **原本との差は `composer.json` の `name`**（`etbsjp/pageguard` に改名し、`composer.lock` の
  `content-hash` も再生成済み）。★ **原本の `composer.lock` をコピーで上書きしないこと** ―
  落ちずに警告だけ出て完走し、`composer update` を促されて上の基準値が静かにずれる
- `.github/workflows/ci.yml` は**原本と byte 一致**。書き換えない（直すなら原本側で直して再展開）

## 認証プラグインとして特に気をつけること

`~/.claude/etbs-plugin-rules.md` の「セキュリティ観点」は全項目が該当する。特に:

- **未認証で到達できる経路の棚卸し**（REST・フィード・検索・サイトマップ・AJAX）。HTML 本体だけ塞いでも意味がない
- **未ログインの nonce は全訪問者で同値**。nonce を単独の壁にしない
- **キャッシュに保護ページの中身を載せない**（`DONOTCACHEPAGE` の定義と `nocache_headers()`）
- 401 後の遷移先など、**リダイレクト先は必ず検証する**

## 検証

- **`curl` による HTTP 実測を主とする**（401 と `WWW-Authenticate` の有無、`-u` での 200、REST/フィードの応答）。
  BASIC 認証の 401 はブラウザのネイティブダイアログなので、ブラウザ自動操作とは相性が悪い
- 管理画面（設定画面・メタボックス）は**ブラウザ操作での確認とスクリーンショット報告**を行う
- **Docker が入っていないため `wp-env` は使えない。** Playwright の e2e テストコードは書かない
