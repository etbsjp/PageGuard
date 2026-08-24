<?php
/**
 * アンインストール処理。
 *
 * PageGuard は保護設定（ユーザー名・パスワードハッシュ）を投稿メタに保存している。
 * アンインストールでこれを削除すると、保護していたページが一斉に閲覧できる状態になり、
 * しかもパスワードは復元できない（ハッシュ保存のため）。事故の被害が大きすぎるので、
 * このプラグインは投稿メタ（`_pggd_protected` / `_pggd_credentials`）と TTL 付き transient を
 * 削除時に一切消さない。TTL 付き transient は自然消滅するため、消さなくても残り続けない。
 * 同じ理由で、設定画面の保護基本設定オプション `pggd_post_types` / `pggd_max_attempts` /
 * `pggd_lockout_seconds` も消さない（利用者が設定画面から設定した値であり、`register_setting()`
 * で登録されているため保存はコア（`options.php`）が行う。プラグイン内を `update_option` で
 * grep しても見つからないので取りこぼしやすい）。
 * （docs/spec.md 10 の確定事項。データを消したい場合は利用者が明示的に操作する）
 *
 * 一方、次の2つのオプションは一時状態にすぎないため削除する（task-queue #108 の案A）。
 * - `pggd_lockouts` … ロックアウト記録。上限200件で頭打ちになる一時的な記録であり、
 *   利用者が明示的に設定した値ではない
 * - `pggd_diagnosis_result` … 受信診断の結果。いつでも再実行できるキャッシュ的な値
 *
 * クラス定数（`Pggd_Lockout::OPTION` / `Pggd_Settings::DIAG_RESULT_OPTION`）は
 * ここでは参照できない（`uninstall.php` の実行時点でプラグイン本体は読み込まれていない）ため、
 * オプション名は文字列リテラルで直接指定する。
 *
 * 自前の cron 登録（`wp_schedule_event()`）は行っていない。同梱の plugin-update-checker（PUC。
 * 更新チェックを行うライブラリ）が更新チェック用の cron を登録しているが、PUC 自身が
 * `register_deactivation_hook()` で有効化解除時に `wp_clear_scheduled_hook()` を実行して
 * 自己クリーンアップするため、管理画面からの削除や `wp plugin uninstall` などの標準的な
 * 削除導線（必ず有効化解除を経由する）では、この `uninstall.php` の実行時点で PUC の cron は
 * 既に消えている。したがってこのファイルで `wp_unschedule_hook()` を呼ぶ必要は無い。
 *
 * @package pageguard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

delete_option( 'pggd_lockouts' );
delete_option( 'pggd_diagnosis_result' );
