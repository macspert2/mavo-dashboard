<?php
/**
 * Shared helpers used by both dashboard screens:
 *  - Polylang language helpers + the language <select> control.
 *  - The curated tag-slug list.
 *  - Content parsing (links/images/word count).
 *  - Internal-link → target-post resolution (for the link-map rebuild).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_Helpers {

	/* ------------------------------------------------------------------ */
	/* Language (Polylang aware)                                           */
	/* ------------------------------------------------------------------ */

	/** Slugs of the languages Polylang knows about (empty if Polylang inactive). */
	public static function languages() {
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
			if ( ! empty( $slugs ) ) {
				return $slugs;
			}
		}
		return array();
	}

	/** Currently chosen backend language: 'all' | 'fr' | 'en' | 'de' ... */
	public static function current_lang() {
		$langs = self::languages();

		if ( isset( $_GET['mavo_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$v = sanitize_key( wp_unslash( $_GET['mavo_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'all' === $v || in_array( $v, $langs, true ) ) {
				return $v;
			}
		}
		// Default to the Polylang admin language filter, if any.
		if ( function_exists( 'pll_current_language' ) ) {
			$c = pll_current_language( 'slug' );
			if ( $c ) {
				return $c;
			}
		}
		return 'all';
	}

	/** Normalise an incoming AJAX lang value against the known list. */
	public static function sanitize_lang( $value ) {
		$value = sanitize_key( $value );
		$langs = self::languages();
		if ( 'all' === $value || in_array( $value, $langs, true ) ) {
			return $value;
		}
		return 'all';
	}

	/** Echo the shared language <select> (id="mavo-lang"); nothing if Polylang is off. */
	public static function render_lang_select( $current ) {
		$langs = self::languages();
		if ( empty( $langs ) ) {
			return;
		}
		$names = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs  = pll_languages_list( array( 'fields' => 'slug' ) );
			$labels = pll_languages_list( array( 'fields' => 'name' ) );
			$names  = array_combine( $slugs, $labels );
		}
		?>
		<label class="mavo-lang-wrap">
			<?php esc_html_e( 'Language:', 'mavo-dashboard' ); ?>
			<select id="mavo-lang">
				<option value="all" <?php selected( 'all', $current ); ?>><?php esc_html_e( 'All', 'mavo-dashboard' ); ?></option>
				<?php foreach ( $langs as $slug ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $current ); ?>>
						<?php echo esc_html( isset( $names[ $slug ] ) ? $names[ $slug ] : strtoupper( $slug ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Curated tags                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The predefined, curated tag slugs, in display order.
	 * Filterable via the 'mavo_dashboard_tag_slugs' hook.
	 */
	public static function dashboard_tag_slugs() {
		$slugs = array(
			'europe', 'angleterre', 'londres', 'ecosse', 'pays-de-galles', 'irlande',
			'italie', 'espagne', 'andalousie', 'grece', 'allemagne', 'danemark',
			'norvege', 'portugal', 'suisse', 'croatie', 'montenegro', 'belgique',
			'malte', 'sicile', 'pays-bas', 'pologne', 'suede', 'autriche', 'bosnie',
			'france', 'paris', 'pays-de-la-loire-et-centre-val-de-loire', 'bretagne',
			'sud-ouest', 'alpes', 'outre-mer', 'normandie', 'corse',
			'bourgogne-franche-comte', 'ile-de-france', 'provence-et-cote-dazur',
			'grand-est', 'hauts-de-france', 'auvergne-rhone', 'europe-en-en', 'italy',
			'spain', 'greece', 'denmark', 'malta', 'netherlands', 'uk', 'sri-lanka',
			'europa', 'england-de', 'frankreich', 'griechenland', 'italien', 'kroatien',
			'schottland', 'spanien', 'asie', 'ameriques', 'afrique', 'oceanie',
			'tour-du-monde-2016', 'vivre-en-angleterre', 'rando', 'wanderung',
			'hiking', 'velo', 'bebe', 'campervan', 'bateau', 'ski',
		);
		return apply_filters( 'mavo_dashboard_tag_slugs', $slugs );
	}

	/* ------------------------------------------------------------------ */
	/* Content parsing                                                     */
	/* ------------------------------------------------------------------ */

	/** Normalised host of this site (www. stripped, lower-cased). */
	public static function site_host() {
		return preg_replace( '#^www\.#', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	/**
	 * Parse post content into counts and link/image lists.
	 * Internal  = same host as the site (or relative).
	 * Booking   = host contains booking.com
	 * Discover  = host contains discovercars.com
	 * Other     = every other external link.
	 *
	 * Returns: array( words, internal[], booking[], discover[], other[], images[] )
	 * where each link entry is array( 'url' => ..., 'text' => ... ).
	 */
	public static function parse_links_images( $content ) {
		$content = (string) $content;
		$words   = str_word_count( wp_strip_all_tags( $content ) );
		$site    = self::site_host();

		$internal = array();
		$booking  = array();
		$discover = array();
		$other    = array();
		$images   = array();

		if ( '' !== trim( $content ) ) {
			$dom = new DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content );
			libxml_clear_errors();

			foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
				$href = trim( $a->getAttribute( 'href' ) );
				if ( '' === $href
					|| 0 === stripos( $href, '#' )
					|| 0 === stripos( $href, 'mailto:' )
					|| 0 === stripos( $href, 'tel:' ) ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				$host = $host ? preg_replace( '#^www\.#', '', strtolower( $host ) ) : '';
				$text = trim( preg_replace( '/\s+/', ' ', $a->textContent ) );
				$item = array(
					'url'  => $href,
					'text' => '' !== $text ? $text : $href,
				);

				if ( '' === $host || $host === $site ) {
					$internal[] = $item;
				} elseif ( false !== stripos( $host, 'booking.com' ) ) {
					$booking[] = $item;
				} elseif ( false !== stripos( $host, 'discovercars.com' ) ) {
					$discover[] = $item;
				} else {
					$other[] = $item;
				}
			}

			foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
				$src = trim( $img->getAttribute( 'src' ) );
				if ( '' !== $src ) {
					$images[] = $src;
				}
			}
		}

		return array(
			'words'    => $words,
			'internal' => $internal,
			'booking'  => $booking,
			'discover' => $discover,
			'other'    => $other,
			'images'   => $images,
		);
	}

	/**
	 * Resolve an internal URL to a published post ID, or 0 if it doesn't
	 * resolve to one. Used by the link-map rebuild.
	 */
	public static function resolve_internal( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url
			|| 0 === stripos( $url, '#' )
			|| 0 === stripos( $url, 'mailto:' )
			|| 0 === stripos( $url, 'tel:' ) ) {
			return 0;
		}

		// Drop the fragment.
		$url = preg_replace( '/#.*$/', '', $url );

		// Make absolute if the link is protocol-relative or root/relative.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			if ( 0 === strpos( $url, '//' ) ) {
				$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
			} else {
				$url = home_url( '/' . ltrim( $url, '/' ) );
			}
		}

		$id = url_to_postid( $url );

		// Fallback: match the last path segment against a post slug.
		if ( ! $id ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$segs = array_values( array_filter( explode( '/', $path ) ) );
			$slug = end( $segs );
			if ( $slug ) {
				$p = get_page_by_path( $slug, OBJECT, 'post' );
				if ( $p ) {
					$id = (int) $p->ID;
				}
			}
		}

		if ( $id && 'post' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			return (int) $id;
		}
		return 0;
	}
}
