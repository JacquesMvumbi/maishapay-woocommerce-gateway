<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package MaishaPayGateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// -----------------------------------------------------------------------
// 1. Suppression de la table de données MaishaPay
//    $maishapay_table_name est construit depuis $wpdb->prefix — valeur
//    interne WP, jamais issue d'une entrée utilisateur.
//    DROP TABLE ne supporte pas les placeholders $wpdb->prepare().
//    SchemaChange, InterpolatedNotPrepared et UnescapedDBParameter sont
//    structurellement inévitables ici — justifiés pour le reviewer WP.org.
// -----------------------------------------------------------------------
$maishapay_table_name = $wpdb->prefix . 'maishapay_data';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query(
	"DROP TABLE IF EXISTS `{$maishapay_table_name}`"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

// -----------------------------------------------------------------------
// 2. Suppression des options WordPress
// -----------------------------------------------------------------------
delete_option( 'woocommerce_maishapay_settings' );

// -----------------------------------------------------------------------
// 3. Suppression des fichiers de log
//    wp_delete_file() est utilisé à la place de unlink() — conforme WP.
// -----------------------------------------------------------------------
$maishapay_log_files = glob( WP_CONTENT_DIR . '/uploads/wc-logs/maishapay-*.log' );
if ( is_array( $maishapay_log_files ) ) {
	foreach ( $maishapay_log_files as $maishapay_log_file ) {
		if ( file_exists( $maishapay_log_file ) ) {
			wp_delete_file( $maishapay_log_file );
		}
	}
}

// -----------------------------------------------------------------------
// 4. Suppression des meta sur wp_postmeta
//    DirectQuery / NoCaching : inévitables dans un uninstall.
//    SlowDBQuery sur meta_key : index natif WP présent, acceptable ici.
// -----------------------------------------------------------------------
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete(
	$wpdb->postmeta,
	array( 'meta_key' => '_maishapay_nonce' ),
	array( '%s' )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// -----------------------------------------------------------------------
// 5. Suppression des meta sur wc_orders_meta (HPOS WooCommerce)
//    Table construite depuis $wpdb->prefix, vérifiée via SHOW TABLES LIKE
//    avec $wpdb->prepare() avant usage. Mêmes justifications qu'au §4.
// -----------------------------------------------------------------------
$maishapay_orders_meta_table = $wpdb->prefix . 'wc_orders_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$maishapay_table_exists = $wpdb->get_var(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $maishapay_orders_meta_table )
);

if ( $maishapay_table_exists === $maishapay_orders_meta_table ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete(
		$maishapay_orders_meta_table,
		array( 'meta_key' => '_maishapay_nonce' ),
		array( '%s' )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}