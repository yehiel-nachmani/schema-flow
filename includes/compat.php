<?php
/**
 * תיקוני תאימות לתוספים אחרים באתר.
 *
 * ── WP Pay Per View ──────────────────────────────────────────────
 * התוסף מחובר לפילטר woocommerce_get_checkout_order_received_url, ובקולבק
 * wppv_woocommerce_get_checkout_order_received_url() הוא קורא ל-
 * wc_get_raw_referer(). ווקומרס טוענת את הפונקציה הזאת רק בבקשות חזית
 * (include_template_functions מותנה ב-! is_admin() ו-! DOING_CRON), ולכן כל
 * קריאה ל-$order->get_checkout_order_received_url() מתוך wp-admin או מתוך
 * Action Scheduler התפוצצה ב-"Call to undefined function wc_get_raw_referer()"
 * והחזירה מסך לבן. זה הפיל את "שליחה מחדש" של מייל הזמנה (11/09/2026).
 *
 * ⚠️ ניסיון ראשון (1.5.0) הגדיר כאן את הפונקציה החסרה בעצמנו. זו הייתה טעות:
 * בעורך של אלמנטור ווקומרס בכל זאת טוענת את wc-template-functions.php מאוחר
 * יותר, ואז ההגדרה שלנו כבר קיימת → Cannot redeclare → fatal בעריכת עמוד
 * (14/09/2026). לעולם לא להגדיר פונקציות בשם של ווקומרס.
 *
 * התיקון הנוכחי לא נוגע במרחב השמות של ווקומרס בכלל: כשהפונקציה חסרה —
 * כלומר בדיוק בבקשות שבהן הקולבק היה מתרסק — מנתקים את הקולבק של WPPV
 * מהפילטר. הכתובת פשוט לא עוברת את העיבוד של WPPV בהקשרים האלה, ובחזית
 * (היחיד שבו זה משנה ללקוח) שום דבר לא השתנה.
 *
 * להסיר כשהתוסף יתוקן אצל היצרן (wppayperview.com).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function (): void {
	// קיימת → אנחנו בחזית, הקולבק של WPPV יעבוד כרגיל. לא נוגעים.
	if ( function_exists( 'wc_get_raw_referer' ) ) {
		return;
	}

	$tag      = 'woocommerce_get_checkout_order_received_url';
	$callback = 'wppv_woocommerce_get_checkout_order_received_url';

	global $wp_filter;
	if ( empty( $wp_filter[ $tag ] ) || ! $wp_filter[ $tag ] instanceof WP_Hook ) {
		return;
	}

	// העדיפות לא מתועדת בשום מקום — מאתרים אותה במקום להמר עליה.
	foreach ( $wp_filter[ $tag ]->callbacks as $priority => $callbacks ) {
		if ( isset( $callbacks[ $callback ] ) ) {
			remove_filter( $tag, $callback, $priority );
		}
	}
}, 5 );
