<?php
/**
 * Screen 1 — Overview: curated tags, per-tag post metrics, per-post detail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_Dashboard {

	const CAP   = 'edit_posts';
	const NONCE = 'mavo_dashboard';
	const SLUG  = 'mavo-dashboard';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_mavo_posts', array( $this, 'ajax_posts' ) );
		add_action( 'wp_ajax_mavo_detail', array( $this, 'ajax_detail' ) );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return; // only on the Overview page
		}
		wp_register_style( 'mavo-dashboard', false );
		wp_enqueue_style( 'mavo-dashboard' );
		wp_add_inline_style( 'mavo-dashboard', $this->css() );

		wp_register_script( 'mavo-dashboard', '', array(), '1.0.0', true );
		wp_enqueue_script( 'mavo-dashboard' );
		wp_localize_script(
			'mavo-dashboard',
			'MAVO',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'lang'  => Mavo_Helpers::current_lang(),
			)
		);
		wp_add_inline_script( 'mavo-dashboard', $this->js() );
	}

	/* ------------------------------------------------------------------ */
	/* Data queries                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The curated tags as term objects, in the predefined order.
	 * Slugs that don't exist are skipped. Languages are not filtered here —
	 * the list deliberately spans languages.
	 */
	private function dashboard_tags() {
		$slugs = Mavo_Helpers::dashboard_tag_slugs();
		if ( empty( $slugs ) ) {
			return array();
		}
		$args = array(
			'taxonomy'   => 'post_tag',
			'slug'       => $slugs,
			'hide_empty' => false,
		);
		if ( function_exists( 'pll_languages_list' ) ) {
			$args['lang'] = ''; // all languages, so curated cross-language tags all appear
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$by_slug = array();
		foreach ( $terms as $t ) {
			$by_slug[ $t->slug ] = $t;
		}
		$ordered = array();
		foreach ( $slugs as $s ) {
			if ( isset( $by_slug[ $s ] ) ) {
				$ordered[] = $by_slug[ $s ];
			}
		}
		return $ordered;
	}

	/** All published posts carrying $tag_id, scoped to $lang. */
	private function query_posts( $tag_id, $lang ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array(
				array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $tag_id,
				),
			),
		);
		if ( function_exists( 'pll_languages_list' ) ) {
			$args['lang'] = ( 'all' === $lang ) ? '' : $lang;
		}
		$q = new WP_Query( $args );
		return $q->posts;
	}

	/* ------------------------------------------------------------------ */
	/* View history (sparkline)                                            */
	/* ------------------------------------------------------------------ */

	/** Fully-qualified history table name. */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . MAVO_VIEWS_TABLE;
	}

	/** Does the history table exist? Cached per request. */
	private function table_exists() {
		global $wpdb;
		static $exists = null;
		if ( null === $exists ) {
			$t      = $this->table();
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
		}
		return $exists;
	}

	/**
	 * Fetch the monthly view series for many posts in one query.
	 * Returns: array( post_id => array( array( 'month' => 'YYYY-MM-01', 'views' => int ), ... ) )
	 * ordered chronologically.
	 */
	private function fetch_series( $post_ids ) {
		global $wpdb;
		$series   = array();
		$post_ids = array_filter( array_map( 'absint', (array) $post_ids ) );
		if ( empty( $post_ids ) || ! $this->table_exists() ) {
			return $series;
		}
		$ids   = implode( ',', $post_ids ); // already cast to ints
		$table = $this->table();            // from constant, not user input
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT post_id, snapshot_month, views FROM {$table} WHERE post_id IN ({$ids}) ORDER BY snapshot_month ASC" );
		foreach ( (array) $rows as $r ) {
			$series[ (int) $r->post_id ][] = array(
				'month' => $r->snapshot_month,
				'views' => (int) $r->views,
			);
		}
		return $series;
	}

	/** Site-wide monthly totals (post_id = 0). Cached per request. */
	private function fetch_totals() {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		if ( ! $this->table_exists() ) {
			return $cache;
		}
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT snapshot_month, views FROM {$table} WHERE post_id = 0 ORDER BY snapshot_month ASC" );
		foreach ( (array) $rows as $r ) {
			$cache[] = array(
				'month' => $r->snapshot_month,
				'views' => (int) $r->views,
			);
		}
		return $cache;
	}

	/**
	 * Render a tiny inline-SVG line sparkline.
	 * Draws the site-wide totals line (post_id = 0) behind the post line, each
	 * normalised independently to the full height so both peaks reach the top.
	 * The x-axis is shared by month, so the post line sits on the correct part
	 * of the timeline; the post line is drawn last so it reads as continuous.
	 */
	private function sparkline( $points, $totals = array() ) {
		if ( count( $points ) < 2 ) {
			return '<span class="mavo-dash-na">—</span>';
		}
		$w   = 120;
		$h   = 30;
		$pad = 3;

		// Shared month axis: prefer the (complete) totals timeline.
		$axis_source = ( count( $totals ) >= 2 ) ? $totals : $points;
		$month_index = array();
		foreach ( $axis_source as $i => $p ) {
			$month_index[ $p['month'] ] = $i;
		}
		$denom = max( 1, count( $axis_source ) - 1 );

		// Build a polyline (each series normalised to its own min/max).
		$build = function ( $series ) use ( $month_index, $denom, $w, $h, $pad ) {
			$vals = array();
			foreach ( $series as $p ) {
				$vals[] = (float) $p['views'];
			}
			$min   = min( $vals );
			$max   = max( $vals );
			$range = ( $max - $min ) ?: 1;
			$out   = array();
			foreach ( array_values( $series ) as $i => $p ) {
				$idx   = isset( $month_index[ $p['month'] ] ) ? $month_index[ $p['month'] ] : $i;
				$x     = $pad + ( $idx / $denom ) * ( $w - 2 * $pad );
				$y     = $pad + ( 1 - ( ( (float) $p['views'] - $min ) / $range ) ) * ( $h - 2 * $pad );
				$out[] = round( $x, 1 ) . ',' . round( $y, 1 );
			}
			return $out;
		};

		$post_coords = $build( $points );
		$post_poly   = implode( ' ', $post_coords );
		$last_xy     = explode( ',', end( $post_coords ) );

		$totals_line     = '';
		$totals_by_month = array();
		if ( count( $totals ) >= 2 ) {
			$totals_line = sprintf(
				'<polyline fill="none" stroke="#dba617" stroke-width="1.2" stroke-linejoin="round" stroke-linecap="round" points="%s" />',
				esc_attr( implode( ' ', $build( $totals ) ) )
			);
			foreach ( $totals as $p ) {
				$totals_by_month[ $p['month'] ] = (int) $p['views'];
			}
		}

		// Relative performance: post views / total views, per month.
		// Values are tiny, so $build normalises this line to its own min/max.
		$ratio_line = '';
		$ratio      = array();
		foreach ( $points as $p ) {
			$m = $p['month'];
			if ( isset( $totals_by_month[ $m ] ) && $totals_by_month[ $m ] > 0 ) {
				$ratio[] = array(
					'month' => $m,
					'views' => (int) $p['views'] / $totals_by_month[ $m ],
				);
			}
		}
		if ( count( $ratio ) >= 2 ) {
			$ratio_line = sprintf(
				'<polyline class="mavo-spark-ratio" fill="none" stroke="#00a32a" stroke-width="1.2" stroke-linejoin="round" stroke-linecap="round" points="%s" />',
				esc_attr( implode( ' ', $build( $ratio ) ) )
			);
		}

		$post_vals = array();
		foreach ( $points as $p ) {
			$post_vals[] = (int) $p['views'];
		}
		$title = sprintf(
			/* translators: 1: first month, 2: last month, 3: latest value */
			__( '%1$s → %2$s · latest (3-mo rolling): %3$s views · blue = post, gold = all-site total, green = relative share', 'mavo-dashboard' ),
			$points[0]['month'],
			$points[ count( $points ) - 1 ]['month'],
			number_format_i18n( end( $post_vals ) )
		);

		return sprintf(
			'<svg class="mavo-spark" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" preserveAspectRatio="none" role="img" aria-label="%9$s"><title>%9$s</title>%6$s<polyline fill="none" stroke="#2271b1" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="%3$s" />%7$s<circle cx="%4$s" cy="%5$s" r="2" fill="#2271b1" /></svg>',
			$w,
			$h,
			esc_attr( $post_poly ),
			esc_attr( $last_xy[0] ),
			esc_attr( $last_xy[1] ),
			$totals_line, // already escaped above
			$ratio_line,  // already escaped above
			'', // reserved
			esc_attr( $title )
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                 */
	/* ------------------------------------------------------------------ */

	public function ajax_posts() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '', '', 403 );
		}
		$tag  = isset( $_POST['tag'] ) ? absint( $_POST['tag'] ) : 0;
		$lang = isset( $_POST['lang'] ) ? Mavo_Helpers::sanitize_lang( wp_unslash( $_POST['lang'] ) ) : 'all';
		echo $this->render_posts( $tag, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_die();
	}

	public function ajax_detail() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '', '', 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		echo $this->render_detail( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_die();
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	/** Part 2 — the post list for one tag. */
	private function render_posts( $tag_id, $lang ) {
		$tag = $tag_id ? get_term( $tag_id, 'post_tag' ) : null;
		if ( ! $tag || is_wp_error( $tag ) ) {
			return '<p class="mavo-empty">' . esc_html__( 'Select a tag above to list its posts.', 'mavo-dashboard' ) . '</p>';
		}

		$posts = $this->query_posts( $tag_id, $lang );
		if ( empty( $posts ) ) {
			return '<p class="mavo-empty">' . esc_html__( 'No posts found for this tag in the selected language.', 'mavo-dashboard' ) . '</p>';
		}

		$rows = array();
		foreach ( $posts as $p ) {
			$rows[] = array(
				'p'     => $p,
				'a'     => Mavo_Helpers::parse_links_images( $p->post_content ),
				'views' => (int) get_post_meta( $p->ID, MAVO_META_VIEWS, true ),
			);
		}
		// Order DESC by the views meta.
		usort(
			$rows,
			static function ( $x, $y ) {
				return $y['views'] <=> $x['views'];
			}
		);

		// One batched query for the whole list's view history, plus the
		// site-wide totals series (post_id = 0) drawn behind each line.
		$series = $this->fetch_series(
			array_map(
				static function ( $r ) {
					return $r['p']->ID;
				},
				$rows
			)
		);
		$totals = $this->fetch_totals();

		ob_start();
		?>
		<p class="mavo-posts-head">
			<strong><?php echo esc_html( $tag->name ); ?></strong>
			<?php
			printf(
				/* translators: %d: number of posts */
				esc_html( _n( '%d post', '%d posts', count( $rows ), 'mavo-dashboard' ) ),
				count( $rows )
			);
			?>
		</p>
		<table class="mavo-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Published', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Featured', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Words', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Comments', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Images', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Internal', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Booking', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'DiscoverCars', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'bpul', 'mavo-dashboard' ); ?></th>
					<th><?php esc_html_e( 'maj', 'mavo-dashboard' ); ?></th>
					<th class="mavo-views"><?php esc_html_e( 'Views', 'mavo-dashboard' ); ?></th>
					<th class="mavo-trend"><?php esc_html_e( 'Trend (3-mo rolling)', 'mavo-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php
				$p     = $r['p'];
				$a     = $r['a'];
				$thumb = get_the_post_thumbnail( $p->ID, array( 56, 56 ) );
				?>
				<tr class="mavo-post-row">
					<td>
						<a href="#" class="mavo-post-title" data-id="<?php echo esc_attr( $p->ID ); ?>">
							<?php echo esc_html( get_the_title( $p ) ); ?>
						</a>
					</td>
					<td class="mavo-slug"><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p->post_name ); ?></a></td>
					<td><?php echo esc_html( get_the_date( 'Y-m-d', $p ) ); ?></td>
					<td class="mavo-thumb"><?php echo $thumb ? $thumb : '<span class="mavo-dash-na">&mdash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $a['words'] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( (int) $p->comment_count ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( count( $a['images'] ) ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( count( $a['internal'] ) ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( count( $a['booking'] ) ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( count( $a['discover'] ) ) ); ?></td>
					<td><?php echo esc_html( $this->meta_display( $p->ID, MAVO_META_BPUL ) ); ?></td>
					<td><?php echo esc_html( $this->meta_display( $p->ID, MAVO_META_MAJ ) ); ?></td>
					<td class="num mavo-views"><?php echo esc_html( number_format_i18n( $r['views'] ) ); ?></td>
					<td class="mavo-trend"><?php echo $this->sparkline( isset( $series[ $p->ID ] ) ? $series[ $p->ID ] : array(), $totals ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/** Part 3 — full detail for one post. */
	private function render_detail( $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return '<p class="mavo-empty">' . esc_html__( 'Select a post to see its links and images.', 'mavo-dashboard' ) . '</p>';
		}
		$a = Mavo_Helpers::parse_links_images( $post->post_content );

		ob_start();
		?>
		<h2 class="mavo-detail-title">
			<?php echo esc_html( get_the_title( $post ) ); ?>
			<a class="mavo-edit-link" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'edit', 'mavo-dashboard' ); ?></a>
		</h2>
		<div class="mavo-detail-grid">
			<?php
			echo $this->link_block( __( 'Internal links', 'mavo-dashboard' ), $a['internal'], 'internal' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->link_block( __( 'Booking + DiscoverCars links', 'mavo-dashboard' ), array_merge( $a['booking'], $a['discover'] ), 'affiliate' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->link_block( __( 'Other external links', 'mavo-dashboard' ), $a['other'], 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			<section class="mavo-block mavo-images">
				<h3><?php esc_html_e( 'Image thumbnails', 'mavo-dashboard' ); ?> <span class="mavo-count"><?php echo esc_html( count( $a['images'] ) ); ?></span></h3>
				<?php if ( empty( $a['images'] ) ) : ?>
					<p class="mavo-dash-na"><?php esc_html_e( 'No images.', 'mavo-dashboard' ); ?></p>
				<?php else : ?>
					<div class="mavo-image-grid">
						<?php foreach ( $a['images'] as $src ) : ?>
							<a href="<?php echo esc_url( $src ); ?>" target="_blank" rel="noopener">
								<img src="<?php echo esc_url( $src ); ?>" alt="" loading="lazy" />
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/** One labelled, scrollable list of links. */
	private function link_block( $title, $links, $class ) {
		ob_start();
		?>
		<section class="mavo-block mavo-links mavo-links-<?php echo esc_attr( $class ); ?>">
			<h3><?php echo esc_html( $title ); ?> <span class="mavo-count"><?php echo esc_html( count( $links ) ); ?></span></h3>
			<?php if ( empty( $links ) ) : ?>
				<p class="mavo-dash-na"><?php esc_html_e( 'None.', 'mavo-dashboard' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $links as $l ) : ?>
						<li>
							<a href="<?php echo esc_url( $l['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $l['text'] ); ?></a>
							<span class="mavo-url"><?php echo esc_html( $l['url'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/** Render a meta value, arrays collapsed to a readable string. */
	private function meta_display( $post_id, $key ) {
		$v = get_post_meta( $post_id, $key, true );
		if ( '' === $v || null === $v ) {
			return '—';
		}
		if ( is_array( $v ) ) {
			return implode( ', ', array_map( 'strval', $v ) );
		}
		return (string) $v;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$lang      = Mavo_Helpers::current_lang();
		$tags      = $this->dashboard_tags();
		$first_tag = ! empty( $tags ) ? (int) $tags[0]->term_id : 0;

		// Pre-render parts 2 & 3 so the page is useful without a click.
		$posts_html = $this->render_posts( $first_tag, $lang );
		$first_post = 0;
		if ( $first_tag ) {
			$p = $this->query_posts( $first_tag, $lang );
			if ( ! empty( $p ) ) {
				// match the DESC-by-views ordering of the list
				usort(
					$p,
					static function ( $x, $y ) {
						return (int) get_post_meta( $y->ID, MAVO_META_VIEWS, true ) <=> (int) get_post_meta( $x->ID, MAVO_META_VIEWS, true );
					}
				);
				$first_post = (int) $p[0]->ID;
			}
		}
		$detail_html = $this->render_detail( $first_post );
		?>
		<div class="wrap mavo-wrap">
			<div id="mavo-dash">

				<div id="mavo-part1" class="mavo-part">
					<div class="mavo-bar">
						<h1><?php esc_html_e( 'Mavo Dashboard', 'mavo-dashboard' ); ?></h1>
						<div class="mavo-controls">
							<label class="mavo-toggle">
								<input type="checkbox" id="mavo-ratio-toggle" />
								<span class="mavo-swatch" style="background:#00a32a"></span>
								<?php esc_html_e( 'Relative share line', 'mavo-dashboard' ); ?>
							</label>
							<?php Mavo_Helpers::render_lang_select( $lang ); ?>
						</div>
					</div>
					<div class="mavo-tags">
						<?php if ( empty( $tags ) ) : ?>
							<span class="mavo-empty"><?php esc_html_e( 'No tags found.', 'mavo-dashboard' ); ?></span>
						<?php else : ?>
							<?php
							$out = array();
							foreach ( $tags as $i => $t ) {
								$out[] = sprintf(
									'<a href="#" class="mavo-tag%s" data-id="%d">%s <span class="mavo-tagcount">(%s)</span></a>',
									0 === $i ? ' active' : '',
									(int) $t->term_id,
									esc_html( $t->name ),
									esc_html( number_format_i18n( $t->count ) )
								);
							}
							echo implode( ', ', $out ); // phpcs:ignore WordPress.Security.EscapeOutput
							?>
						<?php endif; ?>
					</div>
				</div>

				<div id="mavo-part2" class="mavo-part">
					<div id="mavo-posts"><?php echo $posts_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</div>

				<div id="mavo-part3" class="mavo-part">
					<div id="mavo-detail"><?php echo $detail_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</div>

			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Inline assets                                                       */
	/* ------------------------------------------------------------------ */

	private function css() {
		return <<<CSS
.mavo-wrap { margin-right: 20px; }
#mavo-dash { display: flex; flex-direction: column; gap: 12px; height: calc(100vh - 60px); }
.mavo-part { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,.04); min-height: 0; }
#mavo-part1 { flex: 0 0 auto; padding: 10px 14px; }
#mavo-part2, #mavo-part3 { flex: 1 1 0; overflow: auto; padding: 0; }
#mavo-part2 > div, #mavo-part3 > div { padding: 12px 14px; }

.mavo-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.mavo-bar h1 { font-size: 18px; margin: 0; padding: 0; }
.mavo-controls { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
.mavo-lang-wrap { font-weight: 600; }
.mavo-lang-wrap select { margin-left: 6px; }
.mavo-toggle { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; cursor: pointer; }
.mavo-swatch { display: inline-block; width: 14px; height: 3px; border-radius: 2px; }
#mavo-dash.mavo-hide-ratio .mavo-spark-ratio { display: none; }

.mavo-tags { margin-top: 8px; line-height: 1.9; }
.mavo-tag { text-decoration: none; }
.mavo-tag.active { font-weight: 700; background: #2271b1; color: #fff; padding: 1px 7px; border-radius: 10px; }
.mavo-tag.active .mavo-tagcount { color: #d8e6f3; }
.mavo-tagcount { color: #787c82; }

.mavo-posts-head { margin: 0 0 8px; font-size: 13px; color: #50575e; }
.mavo-posts-head strong { font-size: 14px; color: #1d2327; margin-right: 8px; }

.mavo-table { border-collapse: collapse; width: 100%; font-size: 13px; }
.mavo-table th, .mavo-table td { border-bottom: 1px solid #f0f0f1; padding: 7px 10px; text-align: left; vertical-align: middle; }
.mavo-table thead th { position: sticky; top: 0; background: #f6f7f7; z-index: 2; border-bottom: 1px solid #c3c4c7; white-space: nowrap; }
.mavo-table tbody tr:hover { background: #f6f7f7; }
.mavo-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.mavo-table .mavo-views { font-weight: 700; }
.mavo-table .mavo-trend { width: 130px; }
.mavo-spark { display: block; overflow: visible; }
.mavo-table .mavo-slug { color: #50575e; font-family: Menlo, Consolas, monospace; font-size: 12px; }
.mavo-table .mavo-thumb img { display: block; width: 44px; height: 44px; object-fit: cover; border-radius: 4px; }
.mavo-post-row.active { background: #e7f0f7 !important; box-shadow: inset 3px 0 0 #2271b1; }
.mavo-post-title { font-weight: 600; text-decoration: none; }

.mavo-detail-title { margin: 0 0 12px; font-size: 16px; }
.mavo-edit-link { font-size: 12px; font-weight: 400; margin-left: 8px; }
.mavo-detail-grid { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; }
.mavo-block { flex: 1 1 280px; min-width: 260px; border: 1px solid #e0e0e2; border-radius: 6px; padding: 10px 12px; background: #fbfbfc; }
.mavo-block h3 { margin: 0 0 8px; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.mavo-count { background: #2271b1; color: #fff; border-radius: 10px; padding: 0 8px; font-size: 11px; line-height: 18px; }
.mavo-links ul { margin: 0; list-style: none; padding: 0; }
.mavo-links li { padding: 5px 0; border-bottom: 1px dashed #e6e6e8; }
.mavo-links li a { text-decoration: none; font-weight: 600; word-break: break-word; }
.mavo-url { display: block; color: #787c82; font-size: 11px; font-family: Menlo, Consolas, monospace; word-break: break-all; }
.mavo-links-affiliate { background: #fff7e6; border-color: #f0d9a8; }
.mavo-image-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.mavo-image-grid img { width: 84px; height: 84px; object-fit: cover; border-radius: 4px; border: 1px solid #dcdcde; }
.mavo-dash-na { color: #a7aaad; }
.mavo-empty { color: #787c82; font-style: italic; }
CSS;
	}

	private function js() {
		return <<<'JS'
(function () {
	function post(data, cb) {
		var body = new URLSearchParams(data);
		fetch(MAVO.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) { return r.text(); }).then(cb);
	}

	function selectPost(id) {
		document.querySelectorAll('.mavo-post-row.active').forEach(function (el) { el.classList.remove('active'); });
		var t = document.querySelector('.mavo-post-title[data-id="' + id + '"]');
		if (t) { var row = t.closest('.mavo-post-row'); if (row) row.classList.add('active'); }
		document.getElementById('mavo-detail').innerHTML = '<p class="mavo-empty">Loading…</p>';
		post({ action: 'mavo_detail', nonce: MAVO.nonce, id: id }, function (html) {
			document.getElementById('mavo-detail').innerHTML = html;
		});
	}

	function selectTag(el) {
		document.querySelectorAll('.mavo-tag.active').forEach(function (a) { a.classList.remove('active'); });
		el.classList.add('active');
		document.getElementById('mavo-posts').innerHTML = '<p class="mavo-empty">Loading…</p>';
		post({ action: 'mavo_posts', nonce: MAVO.nonce, lang: MAVO.lang, tag: el.dataset.id }, function (html) {
			document.getElementById('mavo-posts').innerHTML = html;
			var first = document.querySelector('#mavo-posts .mavo-post-title');
			if (first) { selectPost(first.dataset.id); }
			else { document.getElementById('mavo-detail').innerHTML = '<p class="mavo-empty">No post to show.</p>'; }
		});
	}

	document.addEventListener('click', function (e) {
		var tag = e.target.closest('.mavo-tag');
		if (tag) { e.preventDefault(); selectTag(tag); return; }
		var pt = e.target.closest('.mavo-post-title');
		if (pt) { e.preventDefault(); selectPost(pt.dataset.id); return; }
	});

	var ls = document.getElementById('mavo-lang');
	if (ls) {
		ls.addEventListener('change', function () {
			var u = new URL(window.location.href);
			u.searchParams.set('mavo_lang', this.value);
			window.location.href = u.toString();
		});
	}

	var rt = document.getElementById('mavo-ratio-toggle');
	if (rt) {
		var dash = document.getElementById('mavo-dash');
		var applyRatio = function () { dash.classList.toggle('mavo-hide-ratio', !rt.checked); };
		rt.addEventListener('change', applyRatio);
		applyRatio();
	}
})();
JS;
	}
}
