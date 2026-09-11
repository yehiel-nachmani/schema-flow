<?php
/**
 * תיקוני תאימות לתוספים אחרים באתר.
 *
 * ── WP Pay Per View ──────────────────────────────────────────────
 * התוסף מחובר לפילטר woocommerce_get_checkout_order_received_url, ובקולבק
 * wppv_woocommerce_get_checkout_order_received_url() הוא קורא ל-
 * wc_get_raw_referer(). ווקומרס טוענת את הפונקציה הזאת רק בבקשות חזית —
 * לא באדמין ולא ב-cron — ולכן כל קריאה ל-$order->get_checkout_order_received_url()
 * מתוך wp-admin או מתוך Action Scheduler מתפוצצת ב-
 * "Call to undefined function wc_get_raw_referer()" ומחזירה מסך לבן.
 *
 * זה הפיל את "שליחה מחדש" של מייל הזמנה (11/09/2026). התיקון: מספקים את
 * הפונקציה החסרה — אותה התנהגות בדיוק כמו בווקומרס — אם היא לא קיימת.
 * מוגן ב-function_exists, כך שבחזית ווקומרס תמיד מנצחת ואין redeclare.
 *
 * להסיר כשהתוסף יתוקן אצל היצרן (wppayperview.com).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function (): void {
	if ( function_exists( 'wc_get_raw_referer' ) ) {
		return;
	}

	/**
	 * העתק של ווקומרס: includes/wc-template-functions.php
	 *
	 * @return string|false
	 */
	function wc_get_raw_referer() {
		if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return wp_unslash( $_REQUEST['_wp_http_referer'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			return wp_unslash( $_SERVER['HTTP_REFERER'] );
		}

		return false;
	}
}, 1 );
