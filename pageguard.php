<?php
/**
 * Plugin Name:       PageGuard
 * Description:       ページ単位で BASIC 認証をかけられるプラグイン。ページごとに独立したユーザー名 / パスワードを設定できます。
 * Version:           1.0.1
 * Requires PHP:      7.4
 * Author:            DAI
 * Author URI:        https://etbs.jp
 * Plugin URI:        https://etbs.jp/product-category/wordpress-tools/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pageguard
 *
 * @package pageguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接アクセスされた場合は終了する。
}

define( 'PGGD_VERSION', '1.0.1' );
// プラグイン一覧行（plugin_row_meta）で自プラグインを判定するために使う。
define( 'PGGD_PLUGIN_FILE', __FILE__ );

require_once( dirname( __FILE__ ) . '/inc/func.php' );

/*-------------------------------------------*/
/*  プラグインのアップデートチェック
/*-------------------------------------------*/
require 'inc/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$pggd_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/etbsjp/PageGuard/',
	__FILE__,
	'pageguard'
);
// リポジトリは dist ブランチ一本で運用しているため、更新元も dist を見る。
$pggd_update_checker->setBranch( 'dist' );
