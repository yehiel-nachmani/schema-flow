<?php
/**
 * מודול 3 — סימון עשיר למיילי ווקומרס (Gmail Email Markup).
 *
 * ג׳ימייל קורא JSON-LD מתוך גוף המייל ומרנדר ממנו כרטיס מעל ההודעה:
 * שם החנות, הפריטים, הסכום וכפתור "צפייה בהזמנה" — ובמשלוח שיצא לדרך
 * גם כרטיס מעקב עם מספר המשלוח וכפתור "מעקב".
 *
 * שתי ישויות, כל אחת ב-script משלה (כך גוגל מתעדת אותן):
 *   Order          — בכל מייל אישור/השלמה/חשבונית ללקוח
 *   ParcelDelivery — רק כשיש מספר משלוח בהזמנה
 *
 * ההזרקה נעשית ב-woocommerce_mail_content, כלומר *אחרי* ה-CSS inliner
 * של ווקומרס (Emogrifier) — כך ה-script לא עובר דרך DOMDocument בדרך.
 *
 * תלות רכה ב-WhatsApp Flow: אם K1WA_Shipping/K1WA_Variables קיימים
 * מושכים מהם מספר משלוח וקישור מעקב אישי; אם לא — נשאר רק כרטיס ההזמנה.
 */

defined( 'ABSPATH' ) || exit;

final class SF_Email_Markup {

	/** מיילים ללקוח שמקבלים סימון. מיילי מנהל והחזר כספי — לא. */
	private const EMAILS = [
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_invoice',
	];

	/** קודי סטטוס תפוז שמשמעותם "בדרך" (STATUS_MAP ב-K1WA_Shipping). */
	private const IN_TRANSIT = [ 2, 4, 7, 50 ];

	private static ?self $instance = null;

	/** ההזמנה שנתפסה בזמן רינדור התבנית — הפילטר של הגוף לא מקבל אותה. */
	private ?WC_Order $order = null;
	private string $email_id = '';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_email_header', [ $this, 'reset' ], 1 );
		add_action( 'woocommerce_email_order_details', [ $this, 'capture' ], 1, 4 );
		add_filter( 'woocommerce_mail_content', [ $this, 'inject' ], 99 );
		add_action( 'admin_post_sf_email_test', [ $this, 'handle_test' ] );
	}

	/* ---------------------------------------------------------------
	 * לכידה והזרקה
	 * ------------------------------------------------------------- */

	public function reset(): void {
		$this->order    = null;
		$this->email_id = '';
	}

	/**
	 * @param mixed $order
	 * @param mixed $email
	 */
	public function capture( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || $plain_text || ! $order instanceof WC_Order ) {
			return;
		}
		$this->order    = $order;
		$this->email_id = ( is_object( $email ) && isset( $email->id ) ) ? (string) $email->id : '';
	}

	/**
	 * @param mixed $content
	 * @return mixed
	 */
	public function inject( $content ) {
		$order    = $this->order;
		$email_id = $this->email_id;
		$this->reset();

		if ( ! $order instanceof WC_Order || ! in_array( $email_id, self::EMAILS, true ) ) {
			return $content;
		}
		if ( ! (int) get_option( 'sf_email_markup', 1 ) ) {
			return $content;
		}
		// גוף טקסט נקי מגיע לאותו פילטר — אין לאן להזריק.
		if ( ! is_string( $content ) || false === stripos( $content, '<html' ) ) {
			return $content;
		}

		// הסימון הוא קישוט. אם הבנייה נכשלת, המייל חייב לצאת בכל זאת —
		// ולכן כל מה שנוגע בנתוני ההזמנה עטוף, והכישלון נרשם ללוג.
		try {
			$script = '';
			foreach ( $this->nodes_for( $order, $email_id ) as $json ) {
				$script .= '<script type="application/ld+json">' . $json . "</script>\n";
			}
		} catch ( \Throwable $e ) {
			self::log_failure( $e, $order );
			return $content;
		}

		if ( '' === $script ) {
			return $content;
		}

		$pos = stripos( $content, '</head>' );
		if ( false === $pos && preg_match( '~<body[^>]*>~i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
		}

		// substr_replace ולא preg_replace — ב-JSON יש $ ו-\ שהיו מתפרשים כהפניות.
		return ( false === $pos ) ? $script . $content : substr_replace( $content, $script, $pos, 0 );
	}

	/**
	 * הישויות של הזמנה כ-JSON מוכן להטמעה. ציבורי — גם תצוגת הבדיקה באדמין
	 * משתמשת בו, כדי שמה שנבדק יהיה בדיוק מה שנשלח.
	 *
	 * @return string[]
	 */
	public function nodes_for( WC_Order $order, string $email_id = 'customer_completed_order' ): array {
		$nodes  = [ $this->order_node( $order ) ];
		$parcel = $this->parcel_node( $order );
		if ( $parcel ) {
			$nodes[] = $parcel;
		}

		/** אפשר להוסיף/להחליף ישויות מבחוץ (למשל FlightReservation, EventReservation). */
		$nodes = (array) apply_filters( 'sf_email_markup_nodes', $nodes, $order, $email_id );

		$out = [];
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! $node ) {
				continue;
			}
			// בלי JSON_UNESCAPED_SLASHES בכוונה — כך "</script>" בשם מוצר
			// נכתב כ-"<\/script>" ולא סוגר את התגית.
			$json = wp_json_encode( self::prune( $node ), JSON_UNESCAPED_UNICODE );
			if ( $json ) {
				$out[] = $json;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------
	 * הישויות
	 * ------------------------------------------------------------- */

	private function order_node( WC_Order $order ): array {
		$currency = $order->get_currency();
		$url      = $this->received_url( $order ); // ציבורי, בלי התחברות
		$customer = trim( $order->get_formatted_billing_full_name() );
		$date     = $order->get_date_created();

		$node = [
			'@context'      => 'http://schema.org',
			'@type'         => 'Order',
			'merchant'      => $this->merchant(),
			'orderNumber'   => (string) $order->get_order_number(),
			'orderStatus'   => 'http://schema.org/' . $this->order_status( $order ),
			'priceCurrency' => $currency,
			'price'         => (string) wc_format_decimal( $order->get_total(), 2 ),
			'orderDate'     => $date ? $date->format( 'c' ) : '',
			'acceptedOffer' => $this->offers( $order ),
			'url'           => $url,
			'customer'      => $customer ? [ '@type' => 'Person', 'name' => $customer ] : [],
			'potentialAction' => [
				'@type' => 'ViewAction',
				'url'   => $url,
				'name'  => 'צפייה בהזמנה',
			],
		];

		return (array) apply_filters( 'sf_email_markup_order', $node, $order );
	}

	/**
	 * כתובת "תודה על ההזמנה" — ציבורית, נפתחת בלי התחברות.
	 *
	 * get_checkout_order_received_url() מפעיל פילטר ציבורי, ולפחות תוסף אחד
	 * באתר (WP Pay Per View) קורא בתוכו ל-wc_get_raw_referer() שאינה נטענת
	 * בבקשת אדמין — ומפיל את כל הבקשה. לכן: מנסים, ואם נפל בונים את אותה
	 * כתובת בעצמנו בלי לעבור בפילטר.
	 */
	private function received_url( WC_Order $order ): string {
		// silent: באתר הזה הפילטר נופל בכל בקשת אדמין, ורישום לכל מייל היה
		// מציף את הלוג בלי להוסיף מידע — יש נפילה ידועה ויש מסלול חלופי.
		$url = self::guarded( static fn() => (string) $order->get_checkout_order_received_url(), true );
		if ( '' !== $url ) {
			return $url;
		}

		return self::guarded( static function () use ( $order ) {
			if ( ! function_exists( 'wc_get_endpoint_url' ) || ! function_exists( 'wc_get_checkout_url' ) ) {
				return '';
			}
			return add_query_arg(
				'key',
				$order->get_order_key(),
				wc_get_endpoint_url( 'order-received', $order->get_id(), wc_get_checkout_url() )
			);
		} );
	}

	private function parcel_node( WC_Order $order ): array {
		if ( ! $order->has_shipping_address() && ! $order->needs_shipping_address() ) {
			return [];
		}

		$tn = class_exists( 'K1WA_Shipping' ) ? K1WA_Shipping::get_tracking_number( $order ) : '';
		if ( '' === $tn ) {
			$tn = (string) $order->get_meta( '_k1wa_tracking_number' );
		}
		if ( '' === $tn ) {
			return []; // עוד אין משלוח — כרטיס מעקב ריק רק מבלבל
		}

		$track = class_exists( 'K1WA_Variables' ) ? K1WA_Variables::build_tracking_page_url( $order ) : '';
		if ( '' === $track && class_exists( 'K1WA_Shipping' ) ) {
			$track = K1WA_Shipping::get_tapuz_tracking_url( $tn );
		}

		$carrier = trim( (string) $order->get_meta( '_k1wa_shipping_carrier' ) );
		if ( '' === $carrier ) {
			$carrier = (string) get_option( 'sf_email_carrier', 'תפוז' );
		}

		$items = [];
		foreach ( $this->offers( $order ) as $offer ) {
			if ( ! empty( $offer['itemOffered'] ) ) {
				$items[] = $offer['itemOffered'];
			}
		}

		$node = [
			'@context'            => 'http://schema.org',
			'@type'               => 'ParcelDelivery',
			'deliveryAddress'     => $this->address( $order ),
			'expectedArrivalUntil' => $this->expected_arrival( $order ),
			'carrier'             => $carrier ? [ '@type' => 'Organization', 'name' => $carrier ] : [],
			'itemShipped'         => $items,
			'partOfOrder'         => [
				'@type'       => 'Order',
				'orderNumber' => (string) $order->get_order_number(),
				'merchant'    => $this->merchant(),
			],
			'trackingNumber'      => $tn,
			'trackingUrl'         => $track,
			'potentialAction'     => $track ? [
				'@type' => 'TrackAction',
				'url'   => $track,
				'name'  => 'מעקב אחרי המשלוח',
			] : [],
		];

		return (array) apply_filters( 'sf_email_markup_parcel', $node, $order, $tn );
	}

	/* ---------------------------------------------------------------
	 * חלקים
	 * ------------------------------------------------------------- */

	private function merchant(): array {
		return (array) apply_filters( 'sf_email_markup_merchant', [
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		] );
	}

	/** @return array<int,array<string,mixed>> */
	private function offers( WC_Order $order ): array {
		$currency = $order->get_currency();
		$offers   = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$price   = (float) $item->get_total() + (float) $item->get_total_tax();

			// קישור ותמונה עוברים דרך פילטרים של וורדפרס שתוספים אחרים רוכבים
			// עליהם. תוסף שנופל שם לא יפיל את הסימון — פשוט נוותר על השדה.
			$url   = $product ? self::guarded( fn() => (string) $product->get_permalink( $item ) ) : '';
			$image = $product ? self::guarded( fn() => $this->image( $product ) ) : '';

			$offers[] = [
				'@type'       => 'Offer',
				'itemOffered' => [
					'@type' => 'Product',
					'name'  => wp_strip_all_tags( $item->get_name() ),
					'url'   => $url,
					'image' => $image,
					'sku'   => $product ? (string) $product->get_sku() : '',
				],
				'price'            => (string) wc_format_decimal( $price, 2 ),
				'priceCurrency'    => $currency,
				'eligibleQuantity' => [
					'@type' => 'QuantitativeValue',
					'value' => (int) $item->get_quantity(),
				],
			];
		}

		return $offers;
	}

	private function image( WC_Product $product ): string {
		$id = (int) $product->get_image_id();
		if ( ! $id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$id     = $parent ? (int) $parent->get_image_id() : 0;
		}
		// ג׳ימייל מוריד את התמונה בעצמו — צריך URL מוחלט ב-https.
		return $id ? (string) wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ) : '';
	}

	private function address( WC_Order $order ): array {
		$street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
		$city   = $order->get_shipping_city();

		if ( '' === $street && '' === $city ) { // איסוף עצמי / כתובת חיוב בלבד
			$street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
			$city   = $order->get_billing_city();
		}

		return [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street,
			'addressLocality' => $city,
			'addressRegion'   => $order->get_shipping_state() ?: $order->get_billing_state(),
			'postalCode'      => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
			'addressCountry'  => $order->get_shipping_country() ?: ( $order->get_billing_country() ?: 'IL' ),
		];
	}

	/**
	 * "עד מתי" — תאריך ההזמנה + זמן טיפול מקסימלי + זמן שילוח מקסימלי,
	 * מאותן הגדרות שמזינות את OfferShippingDetails בדף המוצר. ימי עסקים.
	 */
	private function expected_arrival( WC_Order $order ): string {
		// כבר נמסר — הזמן שתפוז דיווח עליו, לא הערכה. פורמט dd/mm/yyyy hh:mm:ss.
		$delivered = trim( (string) $order->get_meta( '_k1wa_delivered_at' ) );
		if ( '' !== $delivered ) {
			$actual = DateTimeImmutable::createFromFormat( 'd/m/Y H:i:s', $delivered, wp_timezone() );
			if ( $actual instanceof DateTimeImmutable ) {
				return $actual->format( 'c' );
			}
		}

		$date = $order->get_date_created();
		if ( ! $date ) {
			return '';
		}

		$days = (int) get_option( 'sf_handling_max', 2 ) + (int) get_option( 'sf_transit_max', 12 );
		$when = new DateTimeImmutable( $date->format( 'Y-m-d H:i:s' ), wp_timezone() );

		while ( $days > 0 ) {
			$when = $when->modify( '+1 day' );
			if ( ! in_array( (int) $when->format( 'N' ), [ 5, 6 ], true ) ) { // שישי, שבת
				$days--;
			}
		}

		return $when->setTime( 20, 0 )->format( 'c' );
	}

	private function order_status( WC_Order $order ): string {
		$code = (int) $order->get_meta( '_k1wa_shipping_status_code' );
		if ( 3 === $code ) {
			return 'OrderDelivered';
		}
		if ( in_array( $code, self::IN_TRANSIT, true ) ) {
			return 'OrderInTransit';
		}

		switch ( $order->get_status() ) {
			case 'pending':
			case 'on-hold':
				return 'OrderPaymentDue';
			case 'refunded':
				return 'OrderReturned';
			case 'cancelled':
				return 'OrderCancelled';
			case 'completed':
				// "הושלם" אצלנו = יצא מהמחסן, לא בהכרח נמסר.
				return $order->needs_shipping_address() ? 'OrderInTransit' : 'OrderDelivered';
		}

		return 'OrderProcessing';
	}

	/* ---------------------------------------------------------------
	 * כלי בדיקה באדמין
	 * ------------------------------------------------------------- */

	/** תוצאת התצוגה/השליחה נשמרת לרגע — ה-JSON גדול מדי בשביל query arg. */
	private const T_RESULT = 'sf_email_test_';

	/**
	 * שולח מייל ווקומרס אמיתי של הזמנה קיימת לכתובת אחרת, בלי לגעת בהזמנה:
	 * מפנים רק את פילטר הנמען, לשליחה אחת.
	 */
	public function handle_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'אין הרשאה', 403 );
		}
		check_admin_referer( 'sf_email_test' );

		$order_id = absint( $_POST['sf_order'] ?? 0 );
		$which    = sanitize_text_field( wp_unslash( $_POST['sf_email_id'] ?? '' ) );
		$to       = sanitize_email( wp_unslash( $_POST['sf_to'] ?? '' ) );
		$send     = isset( $_POST['sf_send'] );

		if ( ! in_array( $which, self::EMAILS, true ) ) {
			$which = 'customer_completed_order';
		}

		self::watch_fatals();

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			$this->finish( 'לא נמצאה הזמנה עם המזהה ' . $order_id, [] );
		}

		try {
			$json = $this->nodes_for( $order, $which );
		} catch ( \Throwable $e ) {
			self::log_failure( $e, $order );
			$frames = array_slice( explode( "\n", str_replace( ABSPATH, '', $e->getTraceAsString() ) ), 0, 4 );
			$this->finish( sprintf(
				'הבנייה נכשלה — %s: %s (%s שורה %d) | %s',
				get_class( $e ),
				$e->getMessage(),
				str_replace( ABSPATH, '', $e->getFile() ),
				$e->getLine(),
				implode( ' « ', $frames )
			), [] );
		}

		if ( ! $send ) {
			$this->finish( sprintf( 'הסימון של הזמנה %s — %d ישויות.', $order->get_order_number(), count( $json ) ), $json );
		}

		if ( ! is_email( $to ) ) {
			$this->finish( 'כתובת יעד לא תקינה.', $json );
		}

		$target = null;
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( isset( $email->id ) && $email->id === $which ) {
				$target = $email;
				break;
			}
		}
		if ( ! $target ) {
			$this->finish( 'לא נמצא מייל מסוג ' . $which, $json );
		}
		if ( ! $target->is_enabled() ) {
			$this->finish( 'המייל "' . $which . '" כבוי בהגדרות ווקומרס — הפעילו אותו כדי לשלוח.', $json );
		}

		$reroute = static function () use ( $to ) {
			return $to;
		};
		add_filter( 'woocommerce_email_recipient_' . $which, $reroute, 999 );
		$target->trigger( $order->get_id(), $order );
		remove_filter( 'woocommerce_email_recipient_' . $which, $reroute, 999 );

		$this->finish( sprintf( 'נשלח מייל %s של הזמנה %s אל %s.', $which, $order->get_order_number(), $to ), $json );
	}

	/**
	 * @param string[] $json
	 * @return never
	 */
	private function finish( string $message, array $json ): void {
		set_transient( self::T_RESULT . get_current_user_id(), [ 'message' => $message, 'json' => $json ], 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=schema-flow#sf-email-test' ) );
		exit;
	}

	/** תיבת הבדיקה בעמוד ההגדרות. */
	public function render_test_box(): void {
		self::watch_fatals();

		$key    = self::T_RESULT . get_current_user_id();
		$result = get_transient( $key );
		// נמחק לפני ההצגה בכוונה: תוצאה שמפילה את העמוד לא תפיל אותו שוב ברענון.
		if ( $result ) {
			delete_transient( $key );
		}
		if ( $result && ! is_array( $result ) ) {
			$result = [ 'message' => 'תוצאה בפורמט לא צפוי.', 'json' => [] ];
		}
		?>
		<h2 id="sf-email-test">בדיקה — מייל אמיתי לכתובת אחרת</h2>
		<p style="max-width:760px">
			שולח את מייל ההזמנה המקורי של הזמנה קיימת לכתובת שתבחרו, בלי לשנות
			שום דבר בהזמנה ובלי להודיע ללקוח. זה מה שצריך לשלב "דוגמה"
			ברישום מול גוגל, וגם הדרך לראות את הכרטיס בג׳ימייל לפני האישור —
			מייל שנשלח מהכתובת שלכם אליה עצמה מרונדר בלי רישום.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sf_email_test">
			<?php wp_nonce_field( 'sf_email_test' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sf_order">מזהה הזמנה</label></th>
					<td>
						<input type="number" name="sf_order" id="sf_order" class="small-text" required>
						<p class="description">המספר בכתובת של עמוד עריכת ההזמנה</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sf_email_id">איזה מייל</label></th>
					<td>
						<select name="sf_email_id" id="sf_email_id">
							<option value="customer_completed_order">הזמנה הושלמה</option>
							<option value="customer_processing_order">הזמנה התקבלה</option>
							<option value="customer_invoice">חשבונית / פרטי הזמנה</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sf_to">לשלוח אל</label></th>
					<td>
						<input type="email" name="sf_to" id="sf_to" class="regular-text"
							value="schema.whitelisting+sample@gmail.com">
						<p class="description">זו הכתובת שגוגל מבקשת לדוגמה. לבדיקה עצמית — הכתובת שממנה החנות שולחת</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="sf_preview" class="button">הצג את הסימון</button>
				<button type="submit" name="sf_send" class="button button-primary">שלח מייל בדיקה</button>
			</p>
		</form>
		<?php
		if ( ! $result ) {
			return;
		}
		?>
		<div class="notice notice-info inline"><p><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></p></div>
		<?php foreach ( (array) ( $result['json'] ?? [] ) as $one ) : ?>
			<pre style="max-width:900px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:12px;direction:ltr;text-align:left"><?php
				$decoded = json_decode( (string) $one, true );
				echo esc_html( (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			?></pre>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * גם fatal שאינו Throwable (זיכרון, זמן ריצה, undefined function) ישאיר
	 * עקבות בלוג — try/catch לבדו לא תופס את אלה.
	 */
	private static function watch_fatals(): void {
		static $armed = false;
		if ( $armed ) {
			return;
		}
		$armed = true;

		register_shutdown_function( static function (): void {
			$last = error_get_last();
			if ( $last && in_array( $last['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
				self::log_raw( sprintf( 'FATAL %s @ %s:%d', $last['message'], $last['file'], $last['line'] ) );
			}
		} );
	}

	/**
	 * מריץ שדה לא-קריטי ומחזיר '' אם משהו נפל בדרך. קיים כי שדות כמו
	 * קישור ותמונה עוברים בפילטרים ציבוריים שכל תוסף באתר יכול לשבת עליהם.
	 */
	private static function guarded( callable $fn, bool $silent = false ): string {
		try {
			return (string) $fn();
		} catch ( \Throwable $e ) {
			if ( ! $silent ) {
				self::log_failure( $e );
			}
			return '';
		}
	}

	/** רישום כישלון ללוג של ווקומרס, מקור schema-flow. */
	private static function log_failure( \Throwable $e, ?WC_Order $order = null ): void {
		self::log_raw( sprintf(
			"%s: %s @ %s:%d | order %s\n%s",
			get_class( $e ),
			$e->getMessage(),
			str_replace( ABSPATH, '', $e->getFile() ),
			$e->getLine(),
			$order ? (string) $order->get_id() : '-',
			str_replace( ABSPATH, '', $e->getTraceAsString() )
		) );
	}

	private static function log_raw( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, [ 'source' => 'schema-flow' ] );
		}
	}

	/** מסיר null / ריקים, ומשמר רשימות כרשימות (כמו ב-Schema_Flow::strip_empty). */
	private static function prune( array $data ): array {
		$is_list = ( $data === array_values( $data ) );
		$out     = [];

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::prune( $value );
			}
			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}

		return $is_list ? array_values( $out ) : $out;
	}
}
