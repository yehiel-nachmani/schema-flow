<?php
/**
 * Plugin Name: Schema Flow
 * Plugin URI: http://new-media.org.il/
 * Description: נתונים מובנים לאתר — שחזור Product schema שתבנית אלמנטור מדלגת עליו, ישות Book אחת לכל ספר (עמוד נחיתה + דף מוצר), וסימון עשיר למיילי ההזמנה בג׳ימייל.
 * Version: 1.3.4
 * Author: יחיאל נחמני
 * Author URI: http://new-media.org.il/
 * Text Domain: schema-flow
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'SFLW_VERSION', '1.3.4' );
define( 'SFLW_PLUGIN_FILE', __FILE__ );
define( 'SFLW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** עדכונים אוטומטיים דרך PUC — ריפו schema-flow. */
if ( file_exists( SFLW_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once SFLW_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

	$sflw_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/yehiel-nachmani/schema-flow/',
		SFLW_PLUGIN_FILE,
		'schema-flow'
	);
	$sflw_update_checker->setBranch( 'main' );

	// הריפו פרטי — מחזרים את הטוקן שכבר קיים באתר (Store Flow / WhatsApp Flow).
	$sflw_token = ( defined( 'SFLW_GITHUB_TOKEN' ) && SFLW_GITHUB_TOKEN ) ? SFLW_GITHUB_TOKEN : '';
	foreach ( [ 'sflw_github_token', 'pkf_github_token', 'k1wa_github_token' ] as $sflw_option ) {
		if ( '' !== $sflw_token ) {
			break;
		}
		$sflw_token = (string) get_option( $sflw_option, '' );
	}
	if ( $sflw_token ) {
		$sflw_update_checker->setAuthentication( $sflw_token );
	}
	unset( $sflw_token, $sflw_option );
}

final class Schema_Flow {

	const VERSION      = SFLW_VERSION;
	const NONCE        = 'sf_book_meta';
	const M_IS_BOOK    = '_sf_is_book';
	const M_LANDING    = '_sf_landing_page_id';
	const M_PAGES      = '_sf_book_pages';
	const M_PUBDATE    = '_sf_book_pubdate';
	const M_FORMAT     = '_sf_book_format';
	const M_AUTHOR     = '_sf_book_author';
	const M_AUTHOR_KEY = '_sf_book_author_slug';
	const M_AUTHOR_URL = '_sf_book_author_urls';
	const M_DESC       = '_sf_book_description';
	const M_GENRE      = '_sf_book_genre';
	const M_SUBTITLE   = '_sf_book_subtitle';
	const M_ABOUT      = '_sf_book_about';
	const M_EDITION    = '_sf_book_edition';
	const T_MAP        = 'sf_landing_map';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		// מודול 1 — החזרת ה-Product schema של ווקומרס.
		// generate_product_data תלוי ב-hook woocommerce_single_product_summary,
		// שתבנית מוצר של Elementor Pro לא מפעילה. ההדפסה רצה ב-10, לכן אנחנו ב-5.
		add_action( 'wp_footer', [ $this, 'restore_product_data' ], 5 );

		// מוסיף משלוח, החזרות ו-validFrom להצעה, ומסיר שדות ריקים
		// (ווקומרס פולטת brand: null כשלא הוגדר מותג).
		add_filter( 'woocommerce_structured_data_product', [ $this, 'filter_product_data' ], 10, 2 );

		// הגדרות משלוח והחזרות — גלובליות לכל האתר.
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// מודול 2 — ישות Book.
		add_action( 'wp_footer', [ $this, 'print_book_graph' ], 20 );

		// ממשק — כרטיס המוצר הוא מקור האמת היחיד.
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_product', [ $this, 'save_meta' ], 10, 2 );
	}

	/* ---------------------------------------------------------------
	 * מודול 1 — Product schema
	 * ------------------------------------------------------------- */

	public function restore_product_data(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->structured_data ) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_queried_object_id() );
		}
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// אם ווקומרס כבר אספה נתוני מוצר (למשל אחרי מעבר לפריסה קלאסית) — לא נכפיל.
		$collected = WC()->structured_data->get_data();
		foreach ( (array) $collected as $entry ) {
			if ( isset( $entry['@type'] ) && 'Product' === $entry['@type'] ) {
				return;
			}
		}

		WC()->structured_data->generate_product_data( $product );
	}

	public function filter_product_data( $markup, $product = null ) {
		if ( ! is_array( $markup ) ) {
			return $markup;
		}

		if ( isset( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			$extras = $this->offer_extras();
			$from   = $product instanceof WC_Product ? $this->price_valid_from( $product ) : '';

			foreach ( $markup['offers'] as $i => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$markup['offers'][ $i ] = array_merge( $offer, $extras );

				// validFrom יושב על מפרט המחיר, לא על ההצעה.
				if ( '' !== $from && isset( $markup['offers'][ $i ]['priceSpecification'] ) ) {
					$markup['offers'][ $i ]['priceSpecification'] =
						$this->add_valid_from( $markup['offers'][ $i ]['priceSpecification'], $from );
				}
			}
		}

		return $this->strip_empty( $markup );
	}

	/**
	 * מאיזה תאריך המחיר הנוכחי בתוקף — תחילת המבצע, ואם אין, יצירת המוצר.
	 */
	private function price_valid_from( WC_Product $product ): string {
		$date = $product->get_date_on_sale_from() ?: $product->get_date_created();
		return $date ? $date->date( 'Y-m-d' ) : '';
	}

	private function add_valid_from( array $spec, string $from ): array {
		$is_list = ( $spec === array_values( $spec ) );
		$specs   = $is_list ? $spec : [ $spec ];

		foreach ( $specs as $i => $one ) {
			if ( is_array( $one ) && ! isset( $one['validFrom'] ) ) {
				$specs[ $i ]['validFrom'] = $from;
			}
		}

		return $is_list ? $specs : $specs[0];
	}

	/**
	 * משלוח והחזרות — שני השדות שגוגל מבקש לכרטיסי מוכרים.
	 * זה גם מה שמייצר את "משלוח בעלות X ₪ · אפשרות החזרה תוך Y ימים" בתוצאות,
	 * בלי להיות תלוי בפיד של Merchant Center.
	 */
	private function offer_extras(): array {
		$currency = get_woocommerce_currency();
		$country  = (string) get_option( 'sf_ship_country', 'IL' );

		$extras = [
			'shippingDetails' => [
				'@type'                => 'OfferShippingDetails',
				'shippingRate'         => [
					'@type'    => 'MonetaryAmount',
					'value'    => (string) wc_format_decimal( (float) get_option( 'sf_ship_cost', 24 ), 2 ),
					'currency' => $currency,
				],
				'shippingDestination'  => [
					'@type'         => 'DefinedRegion',
					'addressCountry' => $country,
				],
				'deliveryTime'         => [
					'@type'        => 'ShippingDeliveryTime',
					// גוגל מפרש את שני אלה בימי עסקים.
					'handlingTime' => [
						'@type'    => 'QuantitativeValue',
						'minValue' => (int) get_option( 'sf_handling_min', 1 ),
						'maxValue' => (int) get_option( 'sf_handling_max', 2 ),
						'unitCode' => 'DAY',
					],
					'transitTime'  => [
						'@type'    => 'QuantitativeValue',
						'minValue' => (int) get_option( 'sf_transit_min', 1 ),
						'maxValue' => (int) get_option( 'sf_transit_max', 12 ),
						'unitCode' => 'DAY',
					],
				],
			],
			'hasMerchantReturnPolicy' => [
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => (int) get_option( 'sf_return_days', 14 ),
				'returnMethod'         => 'https://schema.org/' . (string) get_option( 'sf_return_method', 'ReturnByMail' ),
				'returnFees'           => 'https://schema.org/' . (string) get_option( 'sf_return_fees', 'ReturnFeesCustomerResponsibility' ),
			],
		];

		return apply_filters( 'sf_schema_offer_extras', $extras );
	}

	/**
	 * מסיר null / מחרוזות ריקות / מערכים ריקים, ומשמר רשימות כרשימות —
	 * unset מתוך רשימה משאיר מפתחות לא רציפים, ואז json_encode פולט אובייקט.
	 */
	private function strip_empty( array $data ): array {
		$is_list = ( $data === array_values( $data ) );
		$out     = [];

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = $this->strip_empty( $value );
			}
			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}

		return $is_list ? array_values( $out ) : $out;
	}

	/* ---------------------------------------------------------------
	 * מודול 2 — ישות Book
	 * ------------------------------------------------------------- */

	public function print_book_graph(): void {
		$product = $this->context_product();
		if ( ! $product ) {
			return;
		}

		$graph = $this->build_graph( $product );
		if ( ! $graph ) {
			return;
		}

		printf(
			"\n<!-- Schema Flow %s -->\n<script type=\"application/ld+json\">%s</script>\n",
			esc_html( self::VERSION ),
			wp_json_encode(
				[ '@context' => 'https://schema.org', '@graph' => $graph ],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * איזה ספר מתאר את העמוד הנוכחי — דף המוצר עצמו, או העמוד שהוגדר כעמוד נחיתה שלו.
	 */
	private function context_product(): ?WC_Product {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			return ( $product && $this->is_book( $product ) ) ? $product : null;
		}

		if ( ! is_page() ) {
			return null;
		}

		// מפה של עמוד נחיתה => מוצר, כדי לא לשאול את ה-DB בפוטר של כל עמוד באתר.
		$map = $this->landing_map();
		$product_id = $map[ (int) get_queried_object_id() ] ?? 0;
		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		return ( $product && $this->is_book( $product ) ) ? $product : null;
	}

	private function landing_map(): array {
		$map = get_transient( self::T_MAP );
		if ( is_array( $map ) ) {
			return $map;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value > 0",
				self::M_LANDING
			)
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->meta_value ] = (int) $row->post_id;
		}

		set_transient( self::T_MAP, $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	private function build_graph( WC_Product $product ): array {
		$org    = $this->organization();
		$person = $this->person( $product, $org['@id'] );

		$landing_id  = (int) $product->get_meta( self::M_LANDING );
		$landing_url = $landing_id ? get_permalink( $landing_id ) : '';
		$work_url    = $landing_url ?: $product->get_permalink();

		$isbn   = $this->isbn( $product );
		$pages  = (int) $product->get_meta( self::M_PAGES );
		$format = (string) ( $product->get_meta( self::M_FORMAT ) ?: 'Paperback' );
		$date   = (string) $product->get_meta( self::M_PUBDATE );
		$desc   = trim( (string) $product->get_meta( self::M_DESC ) );
		$genre  = $this->csv( (string) $product->get_meta( self::M_GENRE ) );
		$about  = $this->csv( (string) $product->get_meta( self::M_ABOUT ) );
		$sub    = trim( (string) $product->get_meta( self::M_SUBTITLE ) );
		$bedit  = trim( (string) $product->get_meta( self::M_EDITION ) );

		if ( '' === $desc ) {
			$desc = wp_strip_all_tags( $product->get_short_description() );
		}

		// המהדורה — הישות המסחרית, יושבת על דף המוצר.
		$edition = [
			'@type'         => 'Book',
			'@id'           => $product->get_permalink() . '#edition',
			'name'          => $product->get_name(),
			'url'           => $product->get_permalink(),
			'bookFormat'    => 'https://schema.org/' . $format,
			'inLanguage'    => 'he',
			'exampleOfWork' => [ '@id' => $work_url . '#book' ],
			'publisher'     => [ '@id' => $org['@id'] ],
		];

		if ( $isbn )   { $edition['isbn']          = $isbn; }
		if ( $pages )  { $edition['numberOfPages'] = $pages; }
		if ( $date )   { $edition['datePublished'] = $date; }
		if ( $bedit )  { $edition['bookEdition']   = $bedit; }
		if ( $person ) { $edition['author']        = [ '@id' => $person['@id'] ]; }

		// מכירה = offers עם Offer. ReadAction/expectsAcceptanceOf היא התבנית
		// של תוכנית הספרים־לקריאה־אונליין של גוגל, לא של קנייה.
		$offer = $this->offer( $product, $org['@id'] );
		if ( $offer ) {
			$edition['offers'] = array_merge( $offer, $this->offer_extras() );
		}

		// היצירה — ישות אחת, ה-@id שלה על עמוד הנחיתה.
		$work = [
			'@type'            => 'Book',
			'@id'              => $work_url . '#book',
			'name'             => $this->work_name( $product ),
			'url'              => $work_url,
			'mainEntityOfPage' => $work_url,
			'inLanguage'       => 'he',
			'publisher'        => [ '@id' => $org['@id'] ],
			'workExample'      => [ $edition ],
		];

		// bookFormat ו-numberOfPages הם מאפייני מהדורה ולא של החיבור — הם יושבים על ה-edition בלבד.
		if ( $sub )    { $work['alternativeHeadline'] = $sub; }
		if ( $desc )   { $work['description']        = $desc; }
		if ( $isbn )   { $work['isbn']               = $isbn; }
		if ( $date )   { $work['datePublished']      = $date; }
		if ( $genre )  { $work['genre']              = array_values( $genre ); }
		if ( $person ) { $work['author']             = [ '@id' => $person['@id'] ]; }

		if ( $about ) {
			$work['about'] = array_values( array_map(
				static fn( string $name ) => [ '@type' => 'Person', 'name' => $name ],
				$about
			) );
		}

		$image = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
		if ( $image ) {
			$work['image'] = $image;
		}

		$graph = [ $work ];
		if ( $person ) {
			$graph[] = $person;
		}
		$graph[] = $org;

		return $this->strip_empty( apply_filters( 'sf_schema_graph', $graph, $product ) );
	}

	/**
	 * שם היצירה — בלי הזנב של המהדורה ("// ספר מודפס").
	 */
	private function work_name( WC_Product $product ): string {
		$name = $product->get_name();
		$name = preg_replace( '~\s*//.*$~u', '', $name );
		return trim( (string) $name );
	}

	private function offer( WC_Product $product, string $seller_id ): array {
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return [];
		}

		$offer = [
			'@type'         => 'Offer',
			'url'           => $product->get_permalink(),
			'price'         => wc_format_decimal( $price, wc_get_price_decimals() ),
			'priceCurrency' => get_woocommerce_currency(),
			'itemCondition' => 'https://schema.org/NewCondition',
			// נגזר מהמלאי האמיתי. הצהרת InStock על מוצר שאזל היא אי-התאמה
			// שגוגל מזהה, ובמרצ'נט סנטר היא פוסלת את הפריט.
			'availability'  => $product->is_in_stock()
				? 'https://schema.org/InStock'
				: 'https://schema.org/OutOfStock',
			'seller'        => [ '@id' => $seller_id ],
		];

		// חשוב כשיש מחיר מבצע — בלי תאריך סיום גוגל מתלונן על שדה חסר.
		$sale_end = $product->get_date_on_sale_to();
		if ( $sale_end ) {
			$offer['priceValidUntil'] = $sale_end->date( 'Y-m-d' );
		}

		return $offer;
	}

	/** @return string[] */
	private function csv( string $raw ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	private function organization(): array {
		// Yoast באתר לא מייצר צומת ארגון, ולכן publisher/seller היו מצביעים לכלום.
		$org = [
			'@type'  => 'Organization',
			'@id'    => home_url( '/#organization' ),
			'name'   => get_bloginfo( 'name' ),
			'url'    => home_url( '/' ),
			'sameAs' => [
				'https://www.facebook.com/kvishehad/',
				'https://www.instagram.com/kvish1/',
				'https://www.tiktok.com/@kvish_1',
				'https://www.youtube.com/c/כבישאחד',
				'https://open.spotify.com/show/5ELcfhnaEE72SHf3tzltk8',
			],
		];

		$email = (string) get_option( 'sf_org_email', 'mail@kvish1.co.il' );
		$phone = (string) get_option( 'sf_org_phone', '' );
		if ( $email ) { $org['email']     = $email; }
		if ( $phone ) { $org['telephone'] = $phone; }

		// Hello + אלמנטור לא מגדירים custom_logo, ולכן ניפול לאייקון האתר.
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		if ( '' === $logo ) {
			$logo = (string) get_site_icon_url( 512 );
		}
		$logo = (string) apply_filters( 'sf_schema_logo', $logo );
		if ( '' !== $logo ) {
			$org['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
			$org['image'] = [ '@id' => $org['@id'] . '-logo' ];
		}

		return apply_filters( 'sf_schema_organization', $org );
	}

	private function person( WC_Product $product, string $org_id ): array {
		$name = trim( (string) $product->get_meta( self::M_AUTHOR ) );
		if ( '' === $name ) {
			return [];
		}

		$key = trim( (string) $product->get_meta( self::M_AUTHOR_KEY ) );
		$key = preg_replace( '~[^a-z0-9-]~', '', strtolower( $key ) );
		if ( '' === $key ) {
			$key = substr( md5( $name ), 0, 10 ); // עברית ב-@id מייצרת מזהה מקודד — עדיף hash יציב
		}

		$person = [
			'@type'    => 'Person',
			'@id'      => home_url( '/#person-' . $key ),
			'name'     => $name,
			'jobTitle' => 'סופר',
			'worksFor' => [ '@id' => $org_id ],
		];

		$urls = array_filter( array_map(
			'esc_url_raw',
			preg_split( '~\R~', (string) $product->get_meta( self::M_AUTHOR_URL ) ) ?: []
		) );
		if ( $urls ) {
			$person['sameAs'] = array_values( $urls );
		}

		return $person;
	}

	/**
	 * ISBN מהשדה של ווקומרס (לשונית מלאי, "GTIN, UPC, EAN, or ISBN") — מ-Woo 9.2.
	 */
	private function isbn( WC_Product $product ): string {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$id = trim( (string) $product->get_global_unique_id() );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return trim( (string) $product->get_meta( '_global_unique_id' ) );
	}

	/**
	 * ספר = יש ISBN, או שסומן ידנית. שולטים בזה מבחוץ עם sf_schema_is_book.
	 */
	private function is_book( WC_Product $product ): bool {
		$is_book = '' !== $this->isbn( $product ) || 'yes' === $product->get_meta( self::M_IS_BOOK );
		return (bool) apply_filters( 'sf_schema_is_book', $is_book, $product );
	}

	/* ---------------------------------------------------------------
	 * הגדרות משלוח והחזרות
	 * ------------------------------------------------------------- */

	/** @return array<string, array{label:string, type:string, default:string|int, choices?:array<string,string>, hint?:string}> */
	private function fields(): array {
		return [
			'sf_email_markup'  => [
				'label' => 'סימון עשיר במיילים (ג׳ימייל)', 'type' => 'checkbox', 'default' => 1,
				'hint'  => 'כרטיס הזמנה ומעקב משלוח מעל מיילי ווקומרס בג׳ימייל. דורש רישום חד-פעמי של כתובת השולח מול גוגל',
			],
			'sf_email_carrier' => [
				'label' => 'שם חברת השילוח', 'type' => 'text', 'default' => 'תפוז',
				'hint'  => 'לכרטיס המעקב, כשאין ערך שמור בהזמנה',
			],
			'sf_ship_cost'     => [ 'label' => 'עלות משלוח', 'type' => 'number', 'default' => 24, 'hint' => 'בשקלים. 0 = משלוח חינם' ],
			'sf_ship_country'  => [ 'label' => 'ארץ יעד', 'type' => 'text', 'default' => 'IL', 'hint' => 'קוד דו-אותי' ],
			'sf_handling_min'  => [ 'label' => 'זמן טיפול — מינימום', 'type' => 'number', 'default' => 1, 'hint' => 'ימי עסקים מההזמנה עד המסירה לשליח' ],
			'sf_handling_max'  => [ 'label' => 'זמן טיפול — מקסימום', 'type' => 'number', 'default' => 2 ],
			'sf_transit_min'   => [ 'label' => 'זמן שילוח — מינימום', 'type' => 'number', 'default' => 1, 'hint' => 'ימי עסקים בדרך. הסכום עם זמן הטיפול הוא ההתחייבות כלפי הלקוח' ],
			'sf_transit_max'   => [ 'label' => 'זמן שילוח — מקסימום', 'type' => 'number', 'default' => 12 ],
			'sf_return_days'   => [ 'label' => 'חלון החזרה (ימים)', 'type' => 'number', 'default' => 14, 'hint' => 'מיום המסירה' ],
			'sf_return_method' => [
				'label' => 'אופן ההחזרה', 'type' => 'select', 'default' => 'ReturnByMail',
				'choices' => [
					'ReturnByMail'    => 'בדואר / שליח',
					'ReturnInStore'   => 'בחנות',
					'ReturnAtKiosk'   => 'בנקודת איסוף',
				],
			],
			'sf_return_fees'   => [
				'label' => 'מי משלם על ההחזרה', 'type' => 'select', 'default' => 'ReturnFeesCustomerResponsibility',
				'choices' => [
					'ReturnFeesCustomerResponsibility' => 'הלקוח',
					'FreeReturn'                       => 'החזרה חינם',
					'ReturnShippingFees'               => 'הלקוח, בעלות משלוח ההחזרה',
				],
			],
		];
	}

	public function register_settings(): void {
		foreach ( $this->fields() as $key => $field ) {
			$type = $field['type'];
			register_setting( 'sf_schema_settings', $key, [
				'type'              => in_array( $type, [ 'number', 'checkbox' ], true ) ? 'number' : 'string',
				'default'           => $field['default'],
				'sanitize_callback' => in_array( $type, [ 'number', 'checkbox' ], true )
					? static fn( $v ) => max( 0, (int) $v )
					: 'sanitize_text_field',
			] );
		}
	}

	public function add_settings_page(): void {
		add_submenu_page(
			'woocommerce',
			'Schema Flow — משלוח והחזרות',
			'Schema Flow',
			'manage_woocommerce',
			'schema-flow',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Schema Flow — משלוח, החזרות ומיילים</h1>
			<p style="max-width:760px">
				שני השדות שגוגל מבקש לכרטיסי מוכרים, וגם מה שמייצר את
				"משלוח בעלות X ₪ · אפשרות החזרה תוך Y ימים" בתוצאות החיפוש.
				ההגדרות חלות על <strong>כל</strong> המוצרים באתר.
				<strong>שיהיו זהות למה שמוגדר ב-Merchant Center</strong> — אי-התאמה בין השניים
				היא סיבה לפסילת פריטים.
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'sf_schema_settings' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $this->fields() as $key => $field ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<?php $value = get_option( $key, $field['default'] ); ?>
								<?php if ( 'select' === $field['type'] ) : ?>
									<select name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>">
										<?php foreach ( $field['choices'] as $cv => $cl ) : ?>
											<option value="<?php echo esc_attr( $cv ); ?>" <?php selected( $value, $cv ); ?>>
												<?php echo esc_html( $cl ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'checkbox' === $field['type'] ) : ?>
									<label>
										<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="0">
										<input type="checkbox" name="<?php echo esc_attr( $key ); ?>"
											id="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( (int) $value, 1 ); ?>>
										פעיל
									</label>
								<?php else : ?>
									<input type="<?php echo esc_attr( $field['type'] ); ?>"
										name="<?php echo esc_attr( $key ); ?>"
										id="<?php echo esc_attr( $key ); ?>"
										value="<?php echo esc_attr( (string) $value ); ?>"
										class="<?php echo 'number' === $field['type'] ? 'small-text' : 'regular-text'; ?>">
								<?php endif; ?>
								<?php if ( ! empty( $field['hint'] ) ) : ?>
									<p class="description"><?php echo esc_html( $field['hint'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>סימון עשיר במיילים — מה צריך כדי שזה יעבוד</h2>
			<p style="max-width:760px">
				התוסף מטמיע במיילי ההזמנה של ווקומרס שתי ישויות JSON-LD:
				<code>Order</code> (שם החנות, הפריטים, הסכום וכפתור "צפייה בהזמנה")
				ו-<code>ParcelDelivery</code> — רק כשיש בהזמנה מספר משלוח —
				עם מספר המעקב וכפתור "מעקב אחרי המשלוח".
			</p>
			<p style="max-width:760px">
				<strong>הסימון לבדו לא מספיק.</strong> ג׳ימייל מציג את הכרטיס רק לשולחים
				<a href="https://developers.google.com/workspace/gmail/markup/registering-with-google" target="_blank" rel="noopener">רשומים אצל גוגל</a>:
				דומיין שולח קבוע, אימות SPF/DKIM, נפח שליחה יציב ודירוג ספאם נמוך.
				עד הרישום אפשר לראות את הכרטיס בבדיקה — מייל שנשלח מהכתובת שלך
				<em>אל עצמה</em> מרונדר בלי רישום. לבדיקת תקינות הסימון:
				<a href="https://www.google.com/webmasters/markup-tester/" target="_blank" rel="noopener">Email Markup Tester</a>.
			</p>

			<?php if ( class_exists( 'SF_Email_Markup' ) ) : ?>
				<?php SF_Email_Markup::instance()->render_test_box(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * ממשק ניהול
	 * ------------------------------------------------------------- */

	public function add_meta_box(): void {
		add_meta_box(
			'sf-book-meta',
			'Schema Flow — נתוני ספר',
			[ $this, 'render_meta_box' ],
			'product',
			'normal',
			'default'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE );
		$isbn = $this->isbn( $product );
		$get  = fn( string $key ) => (string) $product->get_meta( $key );
		?>
		<style>
			.sf-grid{display:grid;grid-template-columns:180px 1fr;gap:10px 14px;align-items:center;max-width:820px}
			.sf-grid label{font-weight:600}
			.sf-grid input[type=text],.sf-grid input[type=number],.sf-grid input[type=date],.sf-grid textarea,.sf-grid select{width:100%}
			.sf-note{color:#646970;font-size:12px;margin:10px 0 14px}
		</style>
		<p class="sf-note">
			<?php if ( $isbn ) : ?>
				ISBN מזוהה: <code><?php echo esc_html( $isbn ); ?></code> — נקרא מלשונית <strong>מלאי</strong>, שדה "GTIN, UPC, EAN, or ISBN".
			<?php else : ?>
				<strong>אין ISBN.</strong> מזינים אותו בלשונית <strong>מלאי</strong>, בשדה "GTIN, UPC, EAN, or ISBN".
				בלעדיו המוצר לא ייחשב ספר, אלא אם תסמן את התיבה למטה.
			<?php endif; ?>
		</p>

		<div class="sf-grid">
			<label for="sf_is_book">זה ספר</label>
			<label><input type="checkbox" id="sf_is_book" name="<?php echo esc_attr( self::M_IS_BOOK ); ?>" value="yes"
				<?php checked( 'yes', $get( self::M_IS_BOOK ) ); ?>> סמן אם אין ISBN ובכל זאת צריך ישות Book</label>

			<label for="sf_landing">עמוד נחיתה</label>
			<?php
			wp_dropdown_pages( [
				'name'              => self::M_LANDING,
				'id'                => 'sf_landing',
				'selected'          => (int) $get( self::M_LANDING ),
				'show_option_none'  => '— אין עמוד נחיתה, הישות תשב על דף המוצר —',
				'option_none_value' => '0',
			] );
			?>

			<label for="sf_author">שם המחבר</label>
			<input type="text" id="sf_author" name="<?php echo esc_attr( self::M_AUTHOR ); ?>"
				value="<?php echo esc_attr( $get( self::M_AUTHOR ) ); ?>" placeholder="יחיאל נחמני">

			<label for="sf_author_key">מזהה מחבר (אנגלית)</label>
			<input type="text" id="sf_author_key" name="<?php echo esc_attr( self::M_AUTHOR_KEY ); ?>"
				value="<?php echo esc_attr( $get( self::M_AUTHOR_KEY ) ); ?>" placeholder="yehiel-nachmani">

			<label for="sf_author_urls">פרופילים של המחבר</label>
			<textarea id="sf_author_urls" name="<?php echo esc_attr( self::M_AUTHOR_URL ); ?>" rows="3"
				placeholder="כתובת אחת בכל שורה"><?php echo esc_textarea( $get( self::M_AUTHOR_URL ) ); ?></textarea>

			<label for="sf_pages">מספר עמודים</label>
			<input type="number" id="sf_pages" name="<?php echo esc_attr( self::M_PAGES ); ?>" min="0" step="1"
				value="<?php echo esc_attr( $get( self::M_PAGES ) ); ?>">

			<label for="sf_pubdate">תאריך יציאה לאור</label>
			<input type="date" id="sf_pubdate" name="<?php echo esc_attr( self::M_PUBDATE ); ?>"
				value="<?php echo esc_attr( $get( self::M_PUBDATE ) ); ?>">

			<label for="sf_format">פורמט</label>
			<select id="sf_format" name="<?php echo esc_attr( self::M_FORMAT ); ?>">
				<?php
				$formats = [
					'Paperback'       => 'כריכה רכה',
					'Hardcover'       => 'כריכה קשה',
					'EBook'           => 'ספר דיגיטלי',
					'AudiobookFormat' => 'ספר מוקלט',
					'GraphicNovel'    => 'גרפי',
				];
				$current = $get( self::M_FORMAT ) ?: 'Paperback';
				foreach ( $formats as $value => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $value ),
						selected( $current, $value, false ),
						esc_html( $label )
					);
				}
				?>
			</select>

			<label for="sf_genre">ז'אנרים</label>
			<input type="text" id="sf_genre" name="<?php echo esc_attr( self::M_GENRE ); ?>"
				value="<?php echo esc_attr( $get( self::M_GENRE ) ); ?>" placeholder="ספרות עברית, פרוזה, הגות יהודית">

			<label for="sf_subtitle">כותרת משנה</label>
			<input type="text" id="sf_subtitle" name="<?php echo esc_attr( self::M_SUBTITLE ); ?>"
				value="<?php echo esc_attr( $get( self::M_SUBTITLE ) ); ?>"
				placeholder="מפגשים עם קבצנים, צדיקים נסתרים, רשעים נסתרים ולילות ייסורים">

			<label for="sf_edition">מהדורה</label>
			<input type="text" id="sf_edition" name="<?php echo esc_attr( self::M_EDITION ); ?>"
				value="<?php echo esc_attr( $get( self::M_EDITION ) ); ?>" placeholder="מהדורה ראשונה">

			<label for="sf_about">דמויות ונושאים</label>
			<input type="text" id="sf_about" name="<?php echo esc_attr( self::M_ABOUT ); ?>"
				value="<?php echo esc_attr( $get( self::M_ABOUT ) ); ?>"
				placeholder="רבי נחמן, א״ד גורדון, הרצל, פנחס שדה">

			<label for="sf_desc">משפט מגדיר</label>
			<textarea id="sf_desc" name="<?php echo esc_attr( self::M_DESC ); ?>" rows="4"
				placeholder="הפתרון האינסופי הוא ספר מאת… — זה הטקסט שמנועי AI מצטטים"><?php echo esc_textarea( $get( self::M_DESC ) ); ?></textarea>
		</div>
		<p class="sf-note">
			אם המשפט המגדיר ריק — הוא נלקח מהתיאור הקצר של המוצר.
			"דמויות ונושאים" הופך ל-<code>about</code>, ועוזר לגוגל לקשר את הספר לחיפושים על אותן דמויות.
			המלאי והמחיר נגזרים מהמוצר עצמו ואין מה למלא אותם כאן.
		</p>
		<?php
	}

	public function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$text = fn( string $key ) => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

		$product->update_meta_data( self::M_IS_BOOK, isset( $_POST[ self::M_IS_BOOK ] ) ? 'yes' : '' );
		$product->update_meta_data( self::M_LANDING, absint( $_POST[ self::M_LANDING ] ?? 0 ) );
		$product->update_meta_data( self::M_PAGES, absint( $_POST[ self::M_PAGES ] ?? 0 ) );
		$product->update_meta_data( self::M_AUTHOR, $text( self::M_AUTHOR ) );
		$product->update_meta_data( self::M_GENRE, $text( self::M_GENRE ) );
		$product->update_meta_data( self::M_SUBTITLE, $text( self::M_SUBTITLE ) );
		$product->update_meta_data( self::M_ABOUT, $text( self::M_ABOUT ) );
		$product->update_meta_data( self::M_EDITION, $text( self::M_EDITION ) );

		$key = strtolower( $text( self::M_AUTHOR_KEY ) );
		$product->update_meta_data( self::M_AUTHOR_KEY, preg_replace( '~[^a-z0-9-]~', '', $key ) );

		$date = $text( self::M_PUBDATE );
		$product->update_meta_data( self::M_PUBDATE, preg_match( '~^\d{4}-\d{2}-\d{2}$~', $date ) ? $date : '' );

		$format = $text( self::M_FORMAT );
		$allowed = [ 'Paperback', 'Hardcover', 'EBook', 'AudiobookFormat', 'GraphicNovel' ];
		$product->update_meta_data( self::M_FORMAT, in_array( $format, $allowed, true ) ? $format : 'Paperback' );

		$urls = isset( $_POST[ self::M_AUTHOR_URL ] )
			? implode( "\n", array_filter( array_map(
				fn( $u ) => esc_url_raw( trim( $u ) ),
				preg_split( '~\R~', wp_unslash( $_POST[ self::M_AUTHOR_URL ] ) ) ?: []
			) ) )
			: '';
		$product->update_meta_data( self::M_AUTHOR_URL, $urls );

		$desc = isset( $_POST[ self::M_DESC ] )
			? sanitize_textarea_field( wp_unslash( $_POST[ self::M_DESC ] ) )
			: '';
		$product->update_meta_data( self::M_DESC, $desc );

		$product->save();
		delete_transient( self::T_MAP );
	}
}

require_once SFLW_PLUGIN_DIR . 'includes/class-sf-email-markup.php';

add_action( 'plugins_loaded', static function (): void {
	if ( class_exists( 'WooCommerce' ) ) {
		Schema_Flow::instance();
		SF_Email_Markup::instance();
	}
} );
