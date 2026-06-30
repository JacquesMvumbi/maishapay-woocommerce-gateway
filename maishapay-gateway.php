<?php
/**
 * Plugin Name: MaishaPay Gateway
 * Description: Acceptez les paiements Mobile Money (MPESA, Airtel Money, Orange Money) et par carte bancaire (Visa, Mastercard, American Express) directement sur votre boutique WooCommerce via MaishaPay — la passerelle de paiement africaine de confiance.
 * Version:     3.5
 * Author:      MaishaPay
 * Author URI:  https://www.maishapay.online/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: maishapay-gateway
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// ============================================================
// ACTIVATION
// ============================================================
register_activation_hook( __FILE__, 'maishapay_on_activate' );
function maishapay_on_activate() {
	maishapay_create_table();
	maishapay_fix_checkout_page();
}

function maishapay_create_table() {
	global $wpdb;
	// $wpdb->prefix est une valeur interne de confiance — jamais issue d'une entrée utilisateur.
	$maishapay_table = $wpdb->prefix . 'maishapay_data';
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS {$maishapay_table} (
		id           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
		ref          VARCHAR(100)     DEFAULT NULL,
		email        VARCHAR(150)     DEFAULT NULL,
		orderid      VARCHAR(100)     DEFAULT NULL,
		total_cost   DECIMAL(10,2)    DEFAULT NULL,
		currency     VARCHAR(10)      DEFAULT NULL,
		order_state  CHAR(1)          DEFAULT NULL,
		operator_ref VARCHAR(100)     DEFAULT NULL,
		sessionid    VARCHAR(32)      DEFAULT NULL,
		created_at   DATETIME         DEFAULT NULL,
		PRIMARY KEY (id),
		KEY idx_orderid (orderid),
		KEY idx_ref (ref),
		KEY idx_operator_ref (operator_ref)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

function maishapay_fix_checkout_page() {
	$checkout_page_id = get_option( 'woocommerce_checkout_page_id' );
	if ( ! $checkout_page_id ) {
		$p = get_page_by_path( 'commander' );
		if ( ! $p ) $p = get_page_by_path( 'checkout' );
		if ( $p ) $checkout_page_id = $p->ID;
	}
	if ( ! $checkout_page_id ) return;
	$page = get_post( intval( $checkout_page_id ) );
	if ( $page && strpos( $page->post_content, 'wp:woocommerce/checkout' ) !== false ) {
		wp_update_post( array(
			'ID'           => intval( $checkout_page_id ),
			'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
		) );
	}
}

add_action( 'wp', 'maishapay_runtime_fix_checkout' );
function maishapay_runtime_fix_checkout() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
	$checkout_page_id = get_option( 'woocommerce_checkout_page_id' );
	if ( ! $checkout_page_id ) return;
	$page = get_post( intval( $checkout_page_id ) );
	if ( $page && strpos( $page->post_content, 'wp:woocommerce/checkout' ) !== false ) {
		wp_update_post( array(
			'ID'           => intval( $checkout_page_id ),
			'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
		) );
	}
}

// ============================================================
// ASSETS — CSS ADMIN + JS CHECKOUT
// ============================================================
add_action( 'admin_enqueue_scripts', 'maishapay_admin_styles' );
function maishapay_admin_styles( $hook ) {
	if ( strpos( $hook, 'maishapay' ) === false ) return;
	wp_enqueue_style(
		'maishapay-admin',
		plugin_dir_url( __FILE__ ) . 'assets/css/maishapay-admin.css',
		array(),
		'3.5'
	);
}

add_action( 'wp_enqueue_scripts', 'maishapay_enqueue_checkout_script' );
function maishapay_enqueue_checkout_script() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
	wp_enqueue_script(
		'maishapay-checkout',
		plugin_dir_url( __FILE__ ) . 'assets/js/checkout.js',
		array(),
		'3.5',
		true
	);
}

// ============================================================
// GATEWAY
// ============================================================
add_action( 'plugins_loaded', 'maishapay_init_gateway', 0 );
function maishapay_init_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
	if ( class_exists( 'MaishaPay_WC_Gateway' ) ) return;

	/**
	 * MaishaPay WooCommerce Payment Gateway.
	 *
	 * @package MaishaPay_Gateway
	 */
	class MaishaPay_WC_Gateway extends WC_Payment_Gateway {

		const GATEWAY_URL = 'https://marchand.maishapay.online/payment/vers1.0/merchant/checkout';

		public function __construct() {
			$this->id                 = 'maishapay';
			$this->icon               = apply_filters( 'maishapay_gateway_icon', plugin_dir_url( __FILE__ ) . 'assets/images/logoma.png' );
			$this->has_fields         = false;
			$this->method_title       = 'MaishaPay';
			$this->method_description = 'Acceptez les paiements Mobile Money (MPESA, Airtel Money, Orange Money) et par carte bancaire (Visa, Mastercard, American Express) via MaishaPay.';
			$this->init_form_fields();
			$this->init_settings();
			$this->title                = $this->get_option( 'title' );
			$this->description          = $this->get_option( 'description' );
			$this->devise               = $this->get_option( 'devise' );
			$this->gatewayMode          = $this->get_option( 'gatewayMode' );
			$this->secretApiKey         = $this->get_option( 'secretApiKey' );
			$this->publicApiKey         = $this->get_option( 'publicApiKey' );
			$this->maishapay_processing = $this->get_option( 'maishapay_processing', 'completed' );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
			add_action( 'woocommerce_api_wc_maishapay', array( $this, 'handle_callback' ) );
			add_action( 'woocommerce_api_maishapay_webhook', array( $this, 'handle_callback' ) );
			if ( ! $this->is_valid_for_use() ) $this->enabled = 'no';
		}

		public function is_valid_for_use() {
			return in_array( get_woocommerce_currency(), array( 'CDF', 'USD', 'CFA', 'EUR' ), true );
		}

		public function admin_options() {
			echo '<h2>MaishaPay Gateway</h2>';
			echo '<p>Acceptez les paiements Mobile Money (MPESA, Airtel Money, Orange Money) et par carte bancaire (Visa, Mastercard, American Express) directement sur votre boutique WooCommerce.</p>';
			if ( $this->is_valid_for_use() ) {
				echo '<table class="form-table">';
				$this->generate_settings_html();
				echo '</table>';
			} else {
				echo '<div class="inline error"><p><strong>Passerelle désactivée</strong> : MaishaPay ne supporte pas la devise de votre boutique. Devises acceptées : CDF, USD, CFA, EUR.</p></div>';
			}
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'              => array( 'title' => 'Activer / Désactiver', 'type' => 'checkbox', 'label' => 'Activer MaishaPay comme moyen de paiement', 'default' => 'no' ),
				'title'                => array( 'title' => 'Titre', 'type' => 'text', 'description' => 'Titre affiché au client lors du paiement.', 'default' => 'MaishaPay', 'desc_tip' => true ),
				'description'          => array( 'title' => 'Description', 'type' => 'textarea', 'description' => 'Description affichée au client lors du paiement.', 'default' => 'Payez avec MPESA, Airtel Money, Orange Money, Afrimoney, Visa, Mastercard ou American Express via MaishaPay.', 'desc_tip' => true, 'css' => 'max-width:400px;' ),
				'devise'               => array( 'title' => 'Devise', 'type' => 'select', 'description' => 'Devise utilisée pour les transactions MaishaPay.', 'desc_tip' => true, 'options' => array( 'USD' => 'Dollar américain (USD)', 'CDF' => 'Franc congolais (CDF)', 'CFA' => 'Franc CFA (CFA)', 'EUR' => 'Euro (EUR)' ) ),
				'gatewayMode'          => array( 'title' => 'Mode', 'type' => 'select', 'description' => 'Choisissez Sandbox pour les tests, Live pour la production.', 'desc_tip' => true, 'options' => array( '0' => 'Sandbox (Test)', '1' => 'Live (Production)' ), 'default' => '0' ),
				'secretApiKey'         => array( 'title' => 'Clé API secrète', 'type' => 'password', 'description' => 'Votre clé API secrète correspondant au mode choisi.', 'desc_tip' => true ),
				'publicApiKey'         => array( 'title' => 'Clé API publique', 'type' => 'text', 'description' => 'Votre clé API publique correspondant au mode choisi.', 'desc_tip' => true ),
				'maishapay_processing' => array( 'title' => 'Statut après paiement réussi', 'type' => 'select', 'description' => 'Statut attribué à la commande après un paiement accepté.', 'desc_tip' => true, 'default' => 'completed', 'options' => array( 'completed' => 'Terminée', 'processing' => 'En cours', 'on-hold' => 'En attente' ) ),
			);
		}

		public function payment_fields() {
			if ( ! empty( $this->description ) ) echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		public function receipt_page( $order_id ) {
			echo '<p>' . wp_kses_post( $this->description ) . '<br/>';
			echo 'Merci pour votre commande. Cliquez sur le bouton ci-dessous pour procéder au paiement.</p>';
			echo $this->generate_form( $order_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		public function generate_form( $order_id ) {
			global $wpdb;
			$order = wc_get_order( $order_id );
			if ( ! $order ) return '<p>Commande introuvable.</p>';
			if ( $order->has_status( array( 'processing', 'completed' ) ) ) return '<p>Cette commande a déjà été traitée.</p>';
			$mref   = 'REF' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 9 );
			$email  = $order->get_billing_email();
			$total  = $order->get_total();
			$devise = $this->devise;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'maishapay_data',
				array(
					'ref'         => $mref,
					'email'       => $email,
					'orderid'     => (string) $order_id,
					'total_cost'  => $total,
					'currency'    => $devise,
					'order_state' => 'I',
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
			);
			wp_cache_delete( 'maishapay_last5', 'maishapay' );

			$callback_url = add_query_arg( 'order_id', $order_id, get_site_url() . '/wc-api/maishapay_webhook' );
			$fields = array(
				'gatewayMode'  => $this->gatewayMode,
				'secretApiKey' => $this->secretApiKey,
				'publicApiKey' => $this->publicApiKey,
				'devise'       => $devise,
				'montant'      => $total,
				'callbackUrl'  => $callback_url,
			);
			$fields = apply_filters( 'maishapay_gateway_args', $fields, $order );
			$inputs = '';
			foreach ( $fields as $key => $value ) {
				$inputs .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />' . "\n";
			}
			return sprintf(
				'<form action="%s" method="POST" id="maishapay_payment_form">%s<input type="submit" class="button alt" id="submit_maishapay_payment_form" value="Payer maintenant" />&nbsp;<a href="%s">Annuler</a></form>',
				esc_url( self::GATEWAY_URL ),
				$inputs,
				esc_url( $order->get_cancel_order_url() )
			);
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wc_add_notice( 'Commande introuvable.', 'error' );
				return array( 'result' => 'fail' );
			}
			$order->update_status( 'pending', 'En attente de paiement MaishaPay.' );
			WC()->cart->empty_cart();
			return array( 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) );
		}

		public function handle_callback() {
			global $wpdb;

			$order_id    = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$description = isset( $_GET['description'] ) ? sanitize_text_field( wp_unslash( $_GET['description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$mp_ref      = isset( $_GET['transactionRefId'] ) ? sanitize_text_field( wp_unslash( $_GET['transactionRefId'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$op_ref      = isset( $_GET['operatorRefId'] ) ? sanitize_text_field( wp_unslash( $_GET['operatorRefId'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$this->log( sprintf( 'Callback — order_id:%d status:%s description:%s transactionRefId:%s operatorRefId:%s', $order_id, $status, $description, $mp_ref, $op_ref ) );

			if ( ! $order_id ) {
				$this->log( 'Callback sans order_id.' );
				wp_die( 'Paramètre manquant.', 'MaishaPay', array( 'response' => 400 ) );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$this->log( 'Commande introuvable : #' . $order_id );
				wp_die( 'Commande introuvable.', 'MaishaPay', array( 'response' => 404 ) );
			}

			if ( $op_ref ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'maishapay_data',
					array( 'operator_ref' => $op_ref ),
					array( 'orderid' => (string) $order_id ),
					array( '%s' ),
					array( '%s' )
				);
			}

			if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
				wp_redirect( $order->get_checkout_order_received_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}

			$desc_upper = strtoupper( $description );

			if ( in_array( $desc_upper, array( 'ACCEPTED', 'APPROVED' ), true ) || $status === '200' || $status === '202' ) {
				$order->payment_complete();
				$order->update_status( $this->maishapay_processing, 'Paiement MaishaPay confirmé.' );
				$order->add_order_note( sprintf( 'Paiement accepté via MaishaPay. Ref MaishaPay : %s — Ref opérateur : %s', esc_html( $mp_ref ), esc_html( $op_ref ) ) );
				$this->update_db_order_state( $order_id, 'C' );
				WC()->cart->empty_cart();
				wp_redirect( $order->get_checkout_order_received_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			} elseif ( $desc_upper === 'PENDING' ) {
				$order->update_status( 'on-hold', 'Paiement MaishaPay en attente de confirmation.' );
				$this->update_db_order_state( $order_id, 'P' );
				wc_add_notice( "Votre commande a été passée mais le paiement n'a pas encore été confirmé. Conservez votre preuve de paiement.", 'notice' );
				wp_redirect( wc_get_checkout_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			} else {
				$order->update_status( 'failed', 'Paiement MaishaPay échoué.' );
				$order->add_order_note( sprintf( 'Paiement échoué. Statut : %s — Raison : %s', esc_html( $status ), esc_html( $description ) ) );
				$this->update_db_order_state( $order_id, 'F' );
				wc_add_notice( sprintf( 'Le paiement a échoué : %s. Veuillez réessayer.', esc_html( $description ) ), 'error' );
				wp_redirect( wc_get_checkout_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		}

		private function update_db_order_state( $order_id, $state ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'maishapay_data',
				array( 'order_state' => $state ),
				array( 'orderid' => (string) $order_id ),
				array( '%s' ),
				array( '%s' )
			);
			wp_cache_delete( 'maishapay_last5', 'maishapay' );
		}

		private function log( $message ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( $message, array( 'source' => 'maishapay' ) );
			}
		}
	}

	add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
		$methods[] = 'MaishaPay_WC_Gateway';
		return $methods;
	} );
}

// ============================================================
// MENU ADMIN
// ============================================================
add_action( 'admin_menu', 'maishapay_admin_menu' );
function maishapay_admin_menu() {
	$svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#fff" d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>' );
	add_menu_page( 'MaishaPay', 'MaishaPay', 'manage_woocommerce', 'maishapay-dashboard', 'maishapay_page_dashboard', $svg, 30 );
	add_submenu_page( 'maishapay-dashboard', 'Tableau de bord — MaishaPay', 'Tableau de bord', 'manage_woocommerce', 'maishapay-dashboard', 'maishapay_page_dashboard' );
	add_submenu_page( 'maishapay-dashboard', 'Transactions — MaishaPay', 'Transactions', 'manage_woocommerce', 'maishapay-transactions', 'maishapay_page_transactions' );
	add_submenu_page( 'maishapay-dashboard', 'Rapports — MaishaPay', 'Rapports', 'manage_woocommerce', 'maishapay-reports', 'maishapay_page_reports' );
	add_submenu_page( 'maishapay-dashboard', 'Reglages — MaishaPay', 'Reglages', 'manage_woocommerce', 'maishapay-settings', 'maishapay_page_settings' );
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Retourne les statistiques MaishaPay.
 *
 * La table est construite depuis $wpdb->prefix (valeur interne de confiance).
 * Le placeholder %i (WP 6.2+) échappe automatiquement les identifiants SQL.
 * $where est construit exclusivement via $wpdb->prepare() ou une chaîne littérale
 * constante — jamais depuis une entrée utilisateur non validée.
 *
 * @param int $days Nombre de jours à analyser (0 = tout).
 * @return array
 */
function maishapay_get_stats( $days = 0 ) {
	global $wpdb;

	// Nom de table construit depuis $wpdb->prefix — valeur interne de confiance.
	$maishapay_table = $wpdb->prefix . 'maishapay_data';

	// $where est soit une chaîne littérale constante, soit le résultat de $wpdb->prepare().
	// Dans les deux cas, aucune entrée utilisateur n'est interpolée directement.
	if ( $days > 0 ) {
		$where = $wpdb->prepare( 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days );
	} else {
		$where = 'WHERE 1=1';
	}

	$daily_where      = $days > 0 ? $where : $wpdb->prepare( 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', 30 );
	$cache_key_suffix = $days . '_' . md5( $where );

	// --- total ---
	$cache_key = 'maishapay_total_' . $cache_key_suffix;
	$total     = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $total ) {
		// %i échappe le nom de table comme identifiant SQL (backticks).
		// $where vient de $wpdb->prepare() ou d'une constante — pas d'entrée utilisateur.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", $maishapay_table ) );
		wp_cache_set( $cache_key, $total, 'maishapay', 300 );
	}

	// --- success ---
	$cache_key = 'maishapay_success_' . $cache_key_suffix;
	$success   = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $success ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$success = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where} AND order_state = 'C'", $maishapay_table ) );
		wp_cache_set( $cache_key, $success, 'maishapay', 300 );
	}

	// --- pending ---
	$cache_key = 'maishapay_pending_' . $cache_key_suffix;
	$pending   = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $pending ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where} AND order_state = 'P'", $maishapay_table ) );
		wp_cache_set( $cache_key, $pending, 'maishapay', 300 );
	}

	// --- failed ---
	$cache_key = 'maishapay_failed_' . $cache_key_suffix;
	$failed    = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $failed ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where} AND order_state = 'F'", $maishapay_table ) );
		wp_cache_set( $cache_key, $failed, 'maishapay', 300 );
	}

	// --- initiated ---
	$cache_key = 'maishapay_initiated_' . $cache_key_suffix;
	$initiated = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $initiated ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$initiated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where} AND order_state = 'I'", $maishapay_table ) );
		wp_cache_set( $cache_key, $initiated, 'maishapay', 300 );
	}

	// --- revenue ---
	$cache_key = 'maishapay_revenue_' . $cache_key_suffix;
	$revenue   = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $revenue ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$revenue = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_cost),0) FROM %i {$where} AND order_state = 'C'", $maishapay_table ) );
		wp_cache_set( $cache_key, $revenue, 'maishapay', 300 );
	}

	// --- by_devise ---
	$cache_key = 'maishapay_by_devise_' . $cache_key_suffix;
	$by_devise = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $by_devise ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_devise = $wpdb->get_results( $wpdb->prepare( "SELECT currency, COUNT(*) as cnt, SUM(total_cost) as total FROM %i {$where} AND order_state = 'C' GROUP BY currency", $maishapay_table ) );
		wp_cache_set( $cache_key, $by_devise, 'maishapay', 300 );
	}

	// --- daily ---
	$cache_key = 'maishapay_daily_' . $cache_key_suffix;
	$daily     = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $daily ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) as day, COUNT(*) as cnt, SUM(CASE WHEN order_state='C' THEN total_cost ELSE 0 END) as rev FROM %i {$daily_where} GROUP BY DATE(created_at) ORDER BY day ASC", $maishapay_table ) );
		wp_cache_set( $cache_key, $daily, 'maishapay', 300 );
	}

	return compact( 'total', 'success', 'pending', 'failed', 'initiated', 'revenue', 'by_devise', 'daily' );
}

function maishapay_fmt( $n ) {
	return number_format( (float) $n, 2, ',', ' ' );
}

function maishapay_badge( $state ) {
	$map = array(
		'C' => array( 'mp-badge-success', 'Réussie' ),
		'P' => array( 'mp-badge-warning', 'En attente' ),
		'F' => array( 'mp-badge-danger',  'Échouée' ),
		'I' => array( 'mp-badge-info',    'Initiée' ),
	);
	$b = isset( $map[ $state ] ) ? $map[ $state ] : array( 'mp-badge-info', 'Inconnu' );
	return '<span class="mp-badge ' . esc_attr( $b[0] ) . '">' . esc_html( $b[1] ) . '</span>';
}

function maishapay_icon( $type ) {
	$icons = array(
		'total'   => '<svg viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-7 14H8v-2h4v2zm4-4H8v-2h8v2zm0-4H8V7h8v2z"/></svg>',
		'success' => '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>',
		'failed'  => '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>',
		'pending' => '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>',
		'revenue' => '<svg viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1H6.5c.12 2.19 1.76 3.42 3.7 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>',
	);
	return isset( $icons[ $type ] ) ? $icons[ $type ] : '';
}

// ============================================================
// PAGE : TABLEAU DE BORD
// ============================================================
function maishapay_page_dashboard() {
	global $wpdb;
	$s            = maishapay_get_stats();
	$success_rate = $s['total'] > 0 ? round( $s['success'] / $s['total'] * 100 ) : 0;

	$cache_key = 'maishapay_last5';
	$last5     = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $last5 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$last5 = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT 5', $wpdb->prefix . 'maishapay_data' )
		);
		wp_cache_set( $cache_key, $last5, 'maishapay', 60 );
	}

	echo '<div class="wrap mp-wrap">';
	echo '<p class="mp-page-title">Tableau de bord</p>';
	echo '<p class="mp-page-sub">Vue d\'ensemble de votre activite de paiement MaishaPay</p>';

	echo '<div class="mp-cards">';
	$cards = array(
		array( 'total',   $s['total'],                    'Total transactions', 'ic-blue'   ),
		array( 'success', $s['success'],                  'Reussies',           'ic-green'  ),
		array( 'failed',  $s['failed'],                   'Echouees',           'ic-red'    ),
		array( 'pending', $s['pending'],                  'En attente',         'ic-orange' ),
		array( 'revenue', maishapay_fmt( $s['revenue'] ), 'Montant encaisse',   'ic-purple' ),
	);
	foreach ( $cards as $c ) {
		echo '<div class="mp-card">';
		echo '<div class="mp-card-icon ' . esc_attr( $c[3] ) . '">' . maishapay_icon( $c[0] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="mp-card-val">' . esc_html( $c[1] ) . '</div>';
		echo '<div class="mp-card-label">' . esc_html( $c[2] ) . '</div>';
		echo '</div>';
	}
	echo '</div>';

	echo '<div class="mp-progress-wrap">';
	echo '<div class="mp-progress-title">Taux de succes global</div>';
	echo '<div class="mp-progress-bar"><div class="mp-progress-fill" style="width:' . (int) $success_rate . '%"></div></div>';
	echo '<div class="mp-progress-meta"><span>' . (int) $s['success'] . ' transactions reussies sur ' . (int) $s['total'] . '</span><span><strong>' . (int) $success_rate . '%</strong></span></div>';
	echo '</div>';

	echo '<div class="mp-section">';
	echo '<div class="mp-section-head"><h2>Dernieres transactions</h2><a href="' . esc_url( admin_url( 'admin.php?page=maishapay-transactions' ) ) . '" class="mp-btn mp-btn-secondary">Voir tout</a></div>';
	echo '<div class="mp-section-body">';
	echo '<table class="mp-table"><thead><tr><th>Reference</th><th>Email</th><th>Commande</th><th>Montant</th><th>Statut</th><th>Date</th></tr></thead><tbody>';
	if ( $last5 ) {
		foreach ( $last5 as $r ) {
			$link = $r->orderid ? '<a href="' . esc_url( admin_url( 'post.php?post=' . intval( $r->orderid ) . '&action=edit' ) ) . '">#' . esc_html( $r->orderid ) . '</a>' : '—';
			echo '<tr>';
			echo '<td><code>' . esc_html( $r->ref ) . '</code></td>';
			echo '<td>' . esc_html( $r->email ) . '</td>';
			echo '<td>' . $link . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( maishapay_fmt( $r->total_cost ) ) . ' ' . esc_html( $r->currency ) . '</td>';
			echo '<td>' . maishapay_badge( $r->order_state ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( gmdate( 'd/m/Y H:i', strtotime( $r->created_at ) ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">Aucune transaction pour le moment.</td></tr>';
	}
	echo '</tbody></table></div></div>';
	echo '</div>';
}

// ============================================================
// PAGE : TRANSACTIONS
// ============================================================
function maishapay_page_transactions() {
	global $wpdb;

	$per_page = 20;
	$page     = isset( $_GET['mppage'] ) ? max( 1, (int) $_GET['mppage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$offset   = ( $page - 1 ) * $per_page;
	$search   = isset( $_GET['mpsearch'] ) ? sanitize_text_field( wp_unslash( $_GET['mpsearch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$fstate   = isset( $_GET['mpstate'] ) ? sanitize_text_field( wp_unslash( $_GET['mpstate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// $where_parts contient uniquement des fragments produits par $wpdb->prepare()
	// ou des chaînes littérales constantes — jamais de données utilisateur brutes.
	$where_parts = array( '1=1' );
	if ( $search ) {
		$where_parts[] = $wpdb->prepare(
			'(email LIKE %s OR ref LIKE %s OR orderid LIKE %s)',
			'%' . $wpdb->esc_like( $search ) . '%',
			'%' . $wpdb->esc_like( $search ) . '%',
			'%' . $wpdb->esc_like( $search ) . '%'
		);
	}
	if ( $fstate ) {
		$where_parts[] = $wpdb->prepare( 'order_state = %s', $fstate );
	}
	$where_clause = 'WHERE ' . implode( ' AND ', $where_parts );

	$maishapay_table  = $wpdb->prefix . 'maishapay_data';
	$cache_key_suffix = md5( $where_clause . $page );

	// --- total_rows ---
	$cache_key  = 'maishapay_tx_count_' . $cache_key_suffix;
	$total_rows = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $total_rows ) {
		// %i pour le nom de table ; $where_clause construit via prepare() — sûr.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_clause}", $maishapay_table ) );
		wp_cache_set( $cache_key, $total_rows, 'maishapay', 60 );
	}

	// --- rows ---
	$cache_key = 'maishapay_tx_rows_' . $cache_key_suffix;
	$rows      = wp_cache_get( $cache_key, 'maishapay' );
	if ( false === $rows ) {
		// Le SQL final est entièrement contrôlé : %i pour la table, %d pour LIMIT/OFFSET,
		// $where_clause composé de fragments prepare() et constantes — aucune interpolation utilisateur.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM %i {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$maishapay_table,
				$per_page,
				$offset
			)
		);
		wp_cache_set( $cache_key, $rows, 'maishapay', 60 );
	}

	$total_pages = (int) ceil( $total_rows / $per_page );
	$base        = admin_url( 'admin.php?page=maishapay-transactions' );
	$statuses    = array(
		''  => 'Tous les statuts',
		'C' => 'Reussie',
		'P' => 'En attente',
		'F' => 'Echouee',
		'I' => 'Initiee',
	);

	echo '<div class="wrap mp-wrap">';
	echo '<p class="mp-page-title">Transactions</p>';
	echo '<p class="mp-page-sub">Historique complet de toutes les transactions MaishaPay</p>';

	echo '<div class="mp-section">';
	echo '<div class="mp-filter-bar">';
	echo '<form method="get" style="display:contents;">';
	echo '<input type="hidden" name="page" value="maishapay-transactions">';
	echo '<input type="text" name="mpsearch" placeholder="Rechercher par email, reference, commande..." value="' . esc_attr( $search ) . '" style="min-width:280px;">';
	echo '<select name="mpstate">';
	foreach ( $statuses as $k => $v ) {
		echo '<option value="' . esc_attr( $k ) . '"' . ( $fstate === $k ? ' selected' : '' ) . '>' . esc_html( $v ) . '</option>';
	}
	echo '</select>';
	echo '<button type="submit" class="mp-btn mp-btn-primary">Filtrer</button>';
	if ( $search || $fstate ) {
		echo '<a href="' . esc_url( $base ) . '" class="mp-btn mp-btn-secondary">Reinitialiser</a>';
	}
	echo '</form></div>';

	echo '<table class="mp-table"><thead><tr><th>ID</th><th>Reference</th><th>N&deg; Commande</th><th>Email client</th><th>Montant</th><th>Devise</th><th>Statut</th><th>Date</th></tr></thead><tbody>';
	if ( $rows ) {
		foreach ( $rows as $r ) {
			$link = $r->orderid ? '<a href="' . esc_url( admin_url( 'post.php?post=' . intval( $r->orderid ) . '&action=edit' ) ) . '">#' . esc_html( $r->orderid ) . '</a>' : '—';
			echo '<tr>';
			echo '<td style="color:#94a3b8;">' . intval( $r->id ) . '</td>';
			echo '<td><code>' . esc_html( $r->ref ) . '</code></td>';
			echo '<td>' . $link . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $r->email ) . '</td>';
			echo '<td style="font-weight:600;">' . esc_html( maishapay_fmt( $r->total_cost ) ) . '</td>';
			echo '<td>' . esc_html( $r->currency ) . '</td>';
			echo '<td>' . maishapay_badge( $r->order_state ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td style="color:#64748b;">' . esc_html( gmdate( 'd/m/Y H:i', strtotime( $r->created_at ) ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">Aucune transaction trouvee.</td></tr>';
	}
	echo '</tbody></table>';

	if ( $total_pages > 1 || $total_rows > 0 ) {
		echo '<div class="mp-pagination">';
		echo '<span class="mp-pagination-info">Affichage de ' . ( intval( $offset ) + 1 ) . ' a ' . (int) min( $offset + $per_page, $total_rows ) . ' sur ' . (int) $total_rows . ' transactions</span>';
		echo '<div class="mp-pagination-links">';
		if ( $page > 1 ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'mppage' => $page - 1, 'mpsearch' => $search, 'mpstate' => $fstate ), $base ) ) . '">&larr; Precedent</a>';
		}
		echo '<span>Page ' . (int) $page . ' / ' . (int) $total_pages . '</span>';
		if ( $page < $total_pages ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'mppage' => $page + 1, 'mpsearch' => $search, 'mpstate' => $fstate ), $base ) ) . '">Suivant &rarr;</a>';
		}
		echo '</div></div>';
	}
	echo '</div></div>';
}

// ============================================================
// PAGE : RAPPORTS
// ============================================================
function maishapay_page_reports() {
	$period = isset( $_GET['period'] ) ? (int) $_GET['period'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! in_array( $period, array( 7, 30, 90 ), true ) ) $period = 30;
	$s            = maishapay_get_stats( $period );
	$success_rate = $s['total'] > 0 ? round( $s['success'] / $s['total'] * 100 ) : 0;
	$base         = admin_url( 'admin.php?page=maishapay-reports' );

	echo '<div class="wrap mp-wrap">';
	echo '<p class="mp-page-title">Rapports</p>';
	echo '<p class="mp-page-sub">Analyse de votre activite sur la periode selectionnee</p>';

	echo '<div class="mp-section" style="margin-bottom:20px;"><div class="mp-period-bar">';
	foreach ( array( 7 => '7 jours', 30 => '30 jours', 90 => '90 jours' ) as $d => $lbl ) {
		$cls = $period === $d ? ' active' : '';
		echo '<a href="' . esc_url( add_query_arg( 'period', $d, $base ) ) . '" class="mp-period-btn' . esc_attr( $cls ) . '">' . esc_html( $lbl ) . '</a>';
	}
	echo '</div></div>';

	echo '<div class="mp-cards">';
	echo '<div class="mp-card"><div class="mp-card-icon ic-blue">' . maishapay_icon( 'total' ) . '</div><div class="mp-card-val">' . (int) $s['total'] . '</div><div class="mp-card-label">Total transactions</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="mp-card"><div class="mp-card-icon ic-green">' . maishapay_icon( 'success' ) . '</div><div class="mp-card-val">' . (int) $s['success'] . '</div><div class="mp-card-label">Reussies</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="mp-card"><div class="mp-card-icon ic-purple">' . maishapay_icon( 'revenue' ) . '</div><div class="mp-card-val">' . esc_html( maishapay_fmt( $s['revenue'] ) ) . '</div><div class="mp-card-label">Montant encaisse</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="mp-card"><div class="mp-card-icon ic-orange">' . maishapay_icon( 'pending' ) . '</div><div class="mp-card-val">' . (int) $success_rate . '%</div><div class="mp-card-label">Taux de succes</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';

	echo '<div class="mp-grid-2">';
	echo '<div class="mp-section"><div class="mp-section-head"><h2>Repartition par statut</h2></div><div class="mp-section-body" style="padding:16px 24px;">';
	$stat_items = array(
		array( 'Reussies',   $s['success'],   '#2563eb' ),
		array( 'Echouees',   $s['failed'],    '#dc2626' ),
		array( 'En attente', $s['pending'],   '#ea580c' ),
		array( 'Initiees',   $s['initiated'], '#9333ea' ),
	);
	$max_stat = max( 1, $s['total'] );
	foreach ( $stat_items as $item ) {
		$pct = round( $item[1] / $max_stat * 100 );
		echo '<div class="mp-bar-row"><span class="mp-bar-row-label">' . esc_html( $item[0] ) . '</span><div class="mp-bar-row-bg"><div class="mp-bar-row-fill" style="width:' . (int) $pct . '%;background:' . esc_attr( $item[2] ) . ';"></div></div><span class="mp-bar-row-val">' . (int) $item[1] . ' (' . (int) $pct . '%)</span></div>';
	}
	echo '</div></div>';

	echo '<div class="mp-section"><div class="mp-section-head"><h2>Revenus par devise</h2></div>';
	echo '<table class="mp-table"><thead><tr><th>Devise</th><th>Transactions</th><th>Total encaisse</th></tr></thead><tbody>';
	if ( ! empty( $s['by_devise'] ) ) {
		foreach ( $s['by_devise'] as $r ) {
			echo '<tr><td><strong>' . esc_html( $r->currency ) . '</strong></td><td>' . intval( $r->cnt ) . '</td><td>' . esc_html( maishapay_fmt( $r->total ) ) . '</td></tr>';
		}
	} else {
		echo '<tr><td colspan="3" style="text-align:center;padding:20px;color:#94a3b8;">Aucune donnee</td></tr>';
	}
	echo '</tbody></table></div>';
	echo '</div>';

	echo '<div class="mp-section"><div class="mp-section-head"><h2>Evolution quotidienne</h2></div>';
	echo '<table class="mp-table"><thead><tr><th>Date</th><th>Transactions</th><th>Revenus encaisses</th><th>Volume</th></tr></thead><tbody>';
	if ( ! empty( $s['daily'] ) ) {
		$max_rev = max( 1, max( array_map( function( $r ) { return (float) $r->rev; }, $s['daily'] ) ) );
		foreach ( $s['daily'] as $r ) {
			$pct = round( (float) $r->rev / $max_rev * 100 );
			echo '<tr>';
			echo '<td>' . esc_html( gmdate( 'd/m/Y', strtotime( $r->day ) ) ) . '</td>';
			echo '<td>' . intval( $r->cnt ) . '</td>';
			echo '<td>' . esc_html( maishapay_fmt( $r->rev ) ) . '</td>';
			echo '<td style="width:200px;"><div class="mp-bar-row-bg"><div class="mp-bar-row-fill" style="width:' . (int) $pct . '%;"></div></div></td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;">Aucune donnee pour cette periode</td></tr>';
	}
	echo '</tbody></table></div>';
	echo '</div>';
}

// ============================================================
// PAGE : REGLAGES
// ============================================================
function maishapay_page_settings() {
	if ( isset( $_GET['mpfix'] ) && '1' === $_GET['mpfix'] && current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		maishapay_fix_checkout_page();
		echo '<div class="notice notice-success is-dismissible"><p>Page checkout corrigee avec succes.</p></div>';
	}

	$cid      = get_option( 'woocommerce_checkout_page_id' );
	$cp       = $cid ? get_post( $cid ) : null;
	$is_sc    = $cp && strpos( $cp->post_content, 'woocommerce_checkout' ) !== false;
	$is_block = $cp && strpos( $cp->post_content, 'wp:woocommerce/checkout' ) !== false;

	echo '<div class="wrap mp-wrap">';
	echo '<p class="mp-page-title">Reglages</p>';
	echo '<p class="mp-page-sub">Configuration et informations de la passerelle MaishaPay</p>';

	echo '<div class="mp-section" style="margin-bottom:20px;">';
	echo '<div class="mp-section-head"><h2>Configuration de la passerelle</h2></div>';
	echo '<div style="padding:20px 24px;">';
	echo '<p style="color:#64748b;font-size:13px;margin:0 0 16px;">Les parametres de cles API et les options de paiement sont configures depuis les reglages WooCommerce.</p>';
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=maishapay' ) ) . '" class="mp-btn mp-btn-primary">Configurer MaishaPay dans WooCommerce</a>';
	echo '</div></div>';

	echo '<div class="mp-section">';
	echo '<div class="mp-section-head"><h2>Informations du plugin</h2></div>';
	echo '<table class="mp-info-table">';
	echo '<tr><td>Version</td><td>3.5</td></tr>';
	echo '<tr><td>Version PHP minimale</td><td>7.4</td></tr>';
	echo '<tr><td>Version WooCommerce minimale</td><td>8.0</td></tr>';
	echo '<tr><td>Devises supportees</td><td>CDF (Franc congolais), USD (Dollar), CFA (Franc CFA), EUR (Euro)</td></tr>';
	echo '<tr><td>Methodes de paiement</td><td>MPESA, Airtel Money, Orange Money, MoMo, Visa, Mastercard, American Express</td></tr>';
	echo '<tr><td>URL de callback</td><td><code>' . esc_html( get_site_url() . '/wc-api/maishapay_webhook' ) . '</code></td></tr>';
	echo '<tr><td>Page de validation</td><td>';
	if ( $is_sc ) {
		echo '<span class="mp-status-ok"><svg width="14" height="14" viewBox="0 0 24 24" fill="#16a34a"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg> Shortcode classique detecte — compatible</span>';
	} elseif ( $is_block ) {
		echo '<span class="mp-status-warn"><svg width="14" height="14" viewBox="0 0 24 24" fill="#dc2626"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg> Bloc Gutenberg detecte</span> &nbsp; <a href="' . esc_url( admin_url( 'admin.php?page=maishapay-settings&mpfix=1' ) ) . '" class="mp-btn mp-btn-outline" style="padding:4px 12px;font-size:12px;">Corriger automatiquement</a>';
	} else {
		echo '<span style="color:#94a3b8;">Non determine</span>';
	}
	echo '</td></tr>';
	echo '<tr><td>Documentation officielle</td><td><a href="https://www.maishapay.online" target="_blank" rel="noopener noreferrer" style="color:#2563eb;">www.maishapay.online</a></td></tr>';
	echo '</table></div>';
	echo '</div>';
}