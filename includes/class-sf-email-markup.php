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

		$nodes = [ $this->order_node( $order ) ];
		$parcel = $this->parcel_node( $order );
		if ( $parcel ) {
			$nodes[] = $parcel;
		}

		/** אפשר להוסיף/להחליף ישויות מבחוץ (למשל FlightReservation, EventReservation). */
		$nodes = (array) apply_filters( 'sf_email_markup_nodes', $nodes, $order, $email_id );

		$script = '';
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! $node ) {
				continue;
			}
			// בלי JSON_UNESCAPED_SLASHES בכוונה — כך "</script>" בשם מוצר
			// נכתב כ-"<\/script>" ולא סוגר את התגית.
			$json = wp_json_encode( self::prune( $node ), JSON_UNESCAPED_UNICODE );
			if ( ! $json ) {
				continue;
			}
			$script .= '<script type="application/ld+json">' . $json . "</script>\n";
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

	/* ---------------------------------------------------------------
	 * הישויות
	 * ------------------------------------------------------------- */

	private function order_node( WC_Order $order ): array {
		$currency = $order->get_currency();
		$url      = $order->get_checkout_order_received_url(); // ציבורי, בלי התחברות
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

			$offers[] = [
				'@type'       => 'Offer',
				'itemOffered' => [
					'@type' => 'Product',
					'name'  => wp_strip_all_tags( $item->get_name() ),
					'url'   => $product ? $product->get_permalink( $item ) : '',
					'image' => $product ? $this->image( $product ) : '',
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
