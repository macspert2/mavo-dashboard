<?php
/**
 * Screen 2 — Internal Link Map.
 *
 * Left: a circular/chord graph of the curated tags (one language at a time),
 * with a line between two tags whenever a post tagged with one links internally
 * to a post tagged with the other. Right: the actual links for a clicked line.
 *
 * The links are precomputed into a dedicated table by a manual "Recalculate"
 * button (batched AJAX, truncate-and-fill); nothing here updates dynamically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_Link_Map {

	const CAP         = 'edit_posts';     // who may view the map
	const CAP_REBUILD = 'manage_options'; // who may rebuild the table
	const NONCE       = 'mavo_link_map';
	const SLUG        = 'mavo-link-map';
	const BATCH       = 50;               // posts processed per rebuild request
	const OPTION_BUILT = 'mavo_links_last_built';

	/** Tags excluded from this screen (kept on screen 1). */
	const EXCLUDE = array( 'europe', 'europe-en-en', 'europa' );

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_mavo_lm_graph', array( $this, 'ajax_graph' ) );
		add_action( 'wp_ajax_mavo_lm_links', array( $this, 'ajax_links' ) );
		add_action( 'wp_ajax_mavo_lm_rebuild_start', array( $this, 'ajax_rebuild_start' ) );
		add_action( 'wp_ajax_mavo_lm_rebuild_batch', array( $this, 'ajax_rebuild_batch' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Table                                                               */
	/* ------------------------------------------------------------------ */

	private function table() {
		global $wpdb;
		return $wpdb->prefix . MAVO_LINKS_TABLE;
	}

	private function table_exists() {
		global $wpdb;
		$t = $this->table();
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
	}

	private function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table();
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			anchor TEXT NULL,
			url TEXT NULL,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY target_id (target_id)
		) {$collate};";
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	public function assets( $hook ) {
		if ( 'mavo-dashboard_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_register_style( 'mavo-link-map', false );
		wp_enqueue_style( 'mavo-link-map' );
		wp_add_inline_style( 'mavo-link-map', $this->css() );

		wp_register_script( 'mavo-link-map', '', array(), '1.0.0', true );
		wp_enqueue_script( 'mavo-link-map' );
		wp_localize_script(
			'mavo-link-map',
			'MAVO_LM',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'lang'    => Mavo_Helpers::current_lang(),
				'batch'   => self::BATCH,
				'canEdit' => current_user_can( self::CAP_REBUILD ),
				'i18n'    => array(
					'confirm' => __( 'Rebuild the internal-link table from scratch? This scans every published post and may take a while.', 'mavo-dashboard' ),
					'building'=> __( 'Building…', 'mavo-dashboard' ),
					'done'    => __( 'Done.', 'mavo-dashboard' ),
					'empty'   => __( 'No link data yet. Click “Recalculate” to build it.', 'mavo-dashboard' ),
					'nonodes' => __( 'No tags to display for this language.', 'mavo-dashboard' ),
					'hint'    => __( 'Click a line between two tags to list the internal links connecting them.', 'mavo-dashboard' ),
					'loading' => __( 'Loading…', 'mavo-dashboard' ),
				),
			)
		);
		wp_add_inline_script( 'mavo-link-map', $this->js() );
	}

	/* ------------------------------------------------------------------ */
	/* Tags / nodes                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Curated tags for this screen (minus the excluded ones), filtered to the
	 * given language. Returns term objects in the curated order.
	 */
	private function map_tags( $lang ) {
		$slugs = array_values( array_diff( Mavo_Helpers::dashboard_tag_slugs(), self::EXCLUDE ) );
		if ( empty( $slugs ) ) {
			return array();
		}
		$args = array(
			'taxonomy'   => 'post_tag',
			'slug'       => $slugs,
			'hide_empty' => false,
		);
		if ( function_exists( 'pll_languages_list' ) ) {
			$args['lang'] = '';
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$by_slug = array();
		foreach ( $terms as $t ) {
			// Language filter (Polylang): keep only this language's tags.
			if ( 'all' !== $lang && function_exists( 'pll_get_term_language' ) ) {
				$tl = pll_get_term_language( $t->term_id, 'slug' );
				if ( $tl && $tl !== $lang ) {
					continue;
				}
			}
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

	/* ------------------------------------------------------------------ */
	/* AJAX: graph data                                                    */
	/* ------------------------------------------------------------------ */

	public function ajax_graph() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array(), 403 );
		}
		global $wpdb;
		$lang  = isset( $_POST['lang'] ) ? Mavo_Helpers::sanitize_lang( wp_unslash( $_POST['lang'] ) ) : 'all';
		$terms = $this->map_tags( $lang );

		$nodes      = array();
		$tt_to_term = array();
		$tt_ids     = array();
		foreach ( $terms as $t ) {
			$nodes[]                          = array(
				'id'    => (int) $t->term_id,
				'label' => $t->name,
				'count' => (int) $t->count,
			);
			$tt_to_term[ (int) $t->term_taxonomy_id ] = (int) $t->term_id;
			$tt_ids[]                                 = (int) $t->term_taxonomy_id;
		}

		$edges = array();
		if ( count( $tt_ids ) >= 2 && $this->table_exists() ) {
			$table = $this->table();
			$in    = implode( ',', array_map( 'intval', $tt_ids ) );
			$tr    = $wpdb->term_relationships;
			// One row per qualifying internal link, folded to an undirected
			// tag pair (self-pairs excluded), counted distinct per pair.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				"SELECT LEAST(s.term_taxonomy_id, t.term_taxonomy_id) AS a,
				        GREATEST(s.term_taxonomy_id, t.term_taxonomy_id) AS b,
				        COUNT(DISTINCT l.id) AS weight
				 FROM {$table} l
				 JOIN {$tr} s ON s.object_id = l.source_id AND s.term_taxonomy_id IN ({$in})
				 JOIN {$tr} t ON t.object_id = l.target_id AND t.term_taxonomy_id IN ({$in})
				 WHERE s.term_taxonomy_id <> t.term_taxonomy_id
				 GROUP BY a, b"
			);
			foreach ( (array) $rows as $r ) {
				$a = isset( $tt_to_term[ (int) $r->a ] ) ? $tt_to_term[ (int) $r->a ] : 0;
				$b = isset( $tt_to_term[ (int) $r->b ] ) ? $tt_to_term[ (int) $r->b ] : 0;
				if ( $a && $b ) {
					$edges[] = array(
						'a' => $a,
						'b' => $b,
						'w' => (int) $r->weight,
					);
				}
			}
		}

		wp_send_json_success(
			array(
				'nodes'     => $nodes,
				'edges'     => $edges,
				'built'     => $this->built_label(),
				'hasTable'  => $this->table_exists(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: link list for a pair                                          */
	/* ------------------------------------------------------------------ */

	public function ajax_links() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '', '', 403 );
		}
		$a = isset( $_POST['a'] ) ? absint( $_POST['a'] ) : 0;
		$b = isset( $_POST['b'] ) ? absint( $_POST['b'] ) : 0;
		echo $this->render_links( $a, $b ); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_die();
	}

	private function render_links( $term_a, $term_b ) {
		global $wpdb;
		$ta = get_term( $term_a, 'post_tag' );
		$tb = get_term( $term_b, 'post_tag' );
		if ( ! $ta || ! $tb || is_wp_error( $ta ) || is_wp_error( $tb ) || ! $this->table_exists() ) {
			return '<p class="mavo-empty">' . esc_html__( 'No links to show.', 'mavo-dashboard' ) . '</p>';
		}
		$tta   = (int) $ta->term_taxonomy_id;
		$ttb   = (int) $tb->term_taxonomy_id;
		$table = $this->table();
		$tr    = $wpdb->term_relationships;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT l.id, l.source_id, l.target_id, l.anchor, l.url
				 FROM {$table} l
				 JOIN {$tr} s ON s.object_id = l.source_id
				 JOIN {$tr} t ON t.object_id = l.target_id
				 WHERE ( s.term_taxonomy_id = %d AND t.term_taxonomy_id = %d )
				    OR ( s.term_taxonomy_id = %d AND t.term_taxonomy_id = %d )",
				$tta,
				$ttb,
				$ttb,
				$tta
			)
		);

		$rows = (array) $rows;
		// Order by source then target title.
		$titles = array();
		foreach ( $rows as $r ) {
			$titles[ (int) $r->source_id ] = '';
			$titles[ (int) $r->target_id ] = '';
		}
		foreach ( array_keys( $titles ) as $id ) {
			$titles[ $id ] = get_the_title( $id );
		}
		usort(
			$rows,
			static function ( $x, $y ) use ( $titles ) {
				$sx = strcasecmp( $titles[ (int) $x->source_id ], $titles[ (int) $y->source_id ] );
				if ( 0 !== $sx ) {
					return $sx;
				}
				return strcasecmp( $titles[ (int) $x->target_id ], $titles[ (int) $y->target_id ] );
			}
		);

		ob_start();
		?>
		<p class="mavo-lm-listhead">
			<strong><?php echo esc_html( $ta->name ); ?></strong>
			<span class="mavo-lm-arrows">&harr;</span>
			<strong><?php echo esc_html( $tb->name ); ?></strong>
			<span class="mavo-count"><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></span>
		</p>
		<?php if ( empty( $rows ) ) : ?>
			<p class="mavo-empty"><?php esc_html_e( 'No internal links connect these two tags.', 'mavo-dashboard' ); ?></p>
		<?php else : ?>
			<ul class="mavo-lm-links">
				<?php foreach ( $rows as $r ) : ?>
					<li>
						<a class="mavo-lm-src" href="<?php echo esc_url( get_edit_post_link( (int) $r->source_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $titles[ (int) $r->source_id ] ); ?></a>
						<span class="mavo-lm-to">&rarr;</span>
						<a class="mavo-lm-tgt" href="<?php echo esc_url( get_edit_post_link( (int) $r->target_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $titles[ (int) $r->target_id ] ); ?></a>
						<?php if ( '' !== trim( (string) $r->anchor ) ) : ?>
							<span class="mavo-lm-anchor">&ldquo;<?php echo esc_html( $r->anchor ); ?>&rdquo;</span>
						<?php endif; ?>
						<span class="mavo-url"><?php echo esc_html( $r->url ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: rebuild                                                        */
	/* ------------------------------------------------------------------ */

	public function ajax_rebuild_start() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP_REBUILD ) ) {
			wp_send_json_error( array(), 403 );
		}
		global $wpdb;
		$this->ensure_table();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		// Reset rebuild diagnostics.
		set_transient(
			self::OPTION_BUILT . '_stats',
			array(
				'candidates' => 0,
				'resolved'   => 0,
				'samples'    => array(),
			),
			DAY_IN_SECONDS
		);

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
		);
		wp_send_json_success( array( 'total' => $total ) );
	}

	public function ajax_rebuild_batch() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP_REBUILD ) ) {
			wp_send_json_error( array(), 403 );
		}
		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = self::BATCH;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
				$batch,
				$offset
			)
		);

		$stats = get_transient( self::OPTION_BUILT . '_stats' );
		if ( ! is_array( $stats ) ) {
			$stats = array(
				'candidates' => 0,
				'resolved'   => 0,
				'samples'    => array(),
			);
		}

		$rows = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$parsed = Mavo_Helpers::parse_links_images( $post->post_content );
			foreach ( $parsed['internal'] as $link ) {
				++$stats['candidates'];
				$target = Mavo_Helpers::resolve_internal( $link['url'] );
				if ( $target && $target !== $id ) {
					++$stats['resolved'];
					$rows[] = array(
						$id,
						$target,
						mb_substr( (string) $link['text'], 0, 1000 ),
						mb_substr( (string) $link['url'], 0, 2000 ),
					);
				} elseif ( ! $target && count( $stats['samples'] ) < 25 ) {
					$stats['samples'][] = $link['url'];
				}
			}
		}
		$this->insert_links( $rows );
		set_transient( self::OPTION_BUILT . '_stats', $stats, DAY_IN_SECONDS );

		$processed = $offset + count( $ids );
		$done      = count( $ids ) < $batch;
		if ( $done ) {
			$total_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			update_option(
				self::OPTION_BUILT,
				array(
					'time'       => time(),
					'links'      => $total_links,
					'candidates' => (int) $stats['candidates'],
					'resolved'   => (int) $stats['resolved'],
					'samples'    => array_slice( (array) $stats['samples'], 0, 25 ),
				),
				false
			);
			delete_transient( self::OPTION_BUILT . '_stats' );
		}

		wp_send_json_success(
			array(
				'processed' => $processed,
				'done'      => $done,
				'built'     => $done ? $this->built_label() : '',
			)
		);
	}

	/** Bulk-insert resolved links: each row = [source_id, target_id, anchor, url]. */
	private function insert_links( $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$table  = $this->table();
		$place  = array();
		$values = array();
		foreach ( $rows as $r ) {
			$place[]  = '(%d,%d,%s,%s)';
			$values[] = $r[0];
			$values[] = $r[1];
			$values[] = $r[2];
			$values[] = $r[3];
		}
		$sql = "INSERT INTO {$table} (source_id, target_id, anchor, url) VALUES " . implode( ',', $place );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	private function built_label() {
		$info = get_option( self::OPTION_BUILT );
		if ( ! is_array( $info ) || empty( $info['time'] ) ) {
			return '';
		}
		$label = sprintf(
			/* translators: 1: date/time, 2: number of links */
			__( 'Last built: %1$s · %2$s links', 'mavo-dashboard' ),
			wp_date( 'Y-m-d H:i', (int) $info['time'] ),
			number_format_i18n( (int) $info['links'] )
		);
		if ( isset( $info['candidates'] ) ) {
			$label .= ' ' . sprintf(
				/* translators: 1: resolved count, 2: total internal candidates */
				__( '(resolved %1$s of %2$s internal links found)', 'mavo-dashboard' ),
				number_format_i18n( (int) $info['resolved'] ),
				number_format_i18n( (int) $info['candidates'] )
			);
		}
		return $label;
	}

	/** A few example internal URLs that did NOT resolve (diagnostic aid). */
	private function unresolved_samples() {
		$info = get_option( self::OPTION_BUILT );
		if ( ! is_array( $info ) || empty( $info['samples'] ) ) {
			return array();
		}
		return (array) $info['samples'];
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$lang = Mavo_Helpers::current_lang();
		?>
		<div class="wrap mavo-wrap">
			<div id="mavo-lm">

				<div id="mavo-lm-bar">
					<h1><?php esc_html_e( 'Internal Link Map', 'mavo-dashboard' ); ?></h1>
					<div class="mavo-controls">
						<span id="mavo-lm-built" class="mavo-lm-built"><?php echo esc_html( $this->built_label() ); ?></span>
						<?php if ( current_user_can( self::CAP_REBUILD ) ) : ?>
							<button type="button" class="button button-primary" id="mavo-lm-rebuild"><?php esc_html_e( 'Recalculate', 'mavo-dashboard' ); ?></button>
						<?php endif; ?>
						<?php Mavo_Helpers::render_lang_select( $lang ); ?>
					</div>
					<div id="mavo-lm-progress" class="mavo-lm-progress" hidden>
						<div class="mavo-lm-progress-bar"><span></span></div>
						<span class="mavo-lm-progress-text"></span>
					</div>
					<?php $samples = $this->unresolved_samples(); ?>
					<?php if ( ! empty( $samples ) ) : ?>
						<details class="mavo-lm-diag">
							<summary><?php esc_html_e( 'Examples of internal links that did NOT resolve to a published post', 'mavo-dashboard' ); ?></summary>
							<ul>
								<?php foreach ( $samples as $s ) : ?>
									<li><?php echo esc_html( $s ); ?></li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
				</div>

				<div id="mavo-lm-body">
					<div id="mavo-lm-left" class="mavo-part">
						<div id="mavo-lm-graph"><p class="mavo-empty"><?php esc_html_e( 'Loading…', 'mavo-dashboard' ); ?></p></div>
					</div>
					<div id="mavo-lm-right" class="mavo-part">
						<div id="mavo-lm-list"><p class="mavo-empty"><?php esc_html_e( 'Click a line between two tags to list the internal links connecting them.', 'mavo-dashboard' ); ?></p></div>
					</div>
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
#mavo-lm { display: flex; flex-direction: column; gap: 12px; height: calc(100vh - 60px); }
#mavo-lm-bar { flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 10px 14px; }
#mavo-lm-bar h1 { font-size: 18px; margin: 0; padding: 0; }
.mavo-controls { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.mavo-lm-built { color: #50575e; font-size: 12px; }
.mavo-lang-wrap { font-weight: 600; }
.mavo-lang-wrap select { margin-left: 6px; }

.mavo-lm-progress { flex-basis: 100%; display: flex; align-items: center; gap: 10px; }
.mavo-lm-progress-bar { flex: 1; height: 10px; background: #f0f0f1; border-radius: 5px; overflow: hidden; }
.mavo-lm-progress-bar span { display: block; height: 100%; width: 0; background: #2271b1; transition: width .2s ease; }
.mavo-lm-progress-text { font-size: 12px; color: #50575e; white-space: nowrap; }
.mavo-lm-diag { flex-basis: 100%; font-size: 12px; color: #50575e; }
.mavo-lm-diag summary { cursor: pointer; }
.mavo-lm-diag ul { margin: 6px 0 0; max-height: 160px; overflow: auto; }
.mavo-lm-diag li { font-family: Menlo, Consolas, monospace; word-break: break-all; }

#mavo-lm-body { flex: 1 1 0; min-height: 0; display: flex; gap: 12px; }
.mavo-part { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,.04); min-height: 0; }
#mavo-lm-left { flex: 1 1 55%; overflow: hidden; padding: 6px; }
#mavo-lm-right { flex: 1 1 45%; overflow: auto; padding: 12px 14px; }
#mavo-lm-graph { width: 100%; height: 100%; }
.mavo-lm-svg { width: 100%; height: 100%; display: block; }

.mavo-edge { fill: none; stroke: #d4d4d6; stroke-width: 1; }
.mavo-edge-hit { fill: none; stroke: transparent; stroke-width: 10; cursor: pointer; }
.mavo-edge-g.hover .mavo-edge { stroke: #444; stroke-width: 1.8; }
.mavo-edge-g.selected .mavo-edge { stroke: #2271b1; stroke-width: 2.4; }
.mavo-node-dot { fill: #50575e; }
.mavo-node-label { fill: #1d2327; font-family: inherit; cursor: default; }
.mavo-node-label.dim { fill: #c3c4c7; }
.mavo-edge-weight { fill: #1d2327; font-size: 12px; font-weight: 700; paint-order: stroke; stroke: #fff; stroke-width: 3px; stroke-linejoin: round; pointer-events: none; }

.mavo-lm-listhead { margin: 0 0 10px; font-size: 14px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.mavo-lm-arrows { color: #2271b1; }
.mavo-count { background: #2271b1; color: #fff; border-radius: 10px; padding: 0 8px; font-size: 11px; line-height: 18px; }
.mavo-lm-links { margin: 0; padding: 0; list-style: none; }
.mavo-lm-links li { padding: 8px 0; border-bottom: 1px solid #f0f0f1; }
.mavo-lm-links a { text-decoration: none; font-weight: 600; }
.mavo-lm-to { color: #787c82; margin: 0 6px; }
.mavo-lm-anchor { color: #50575e; font-style: italic; margin-left: 6px; }
.mavo-url { display: block; color: #787c82; font-size: 11px; font-family: Menlo, Consolas, monospace; word-break: break-all; margin-top: 2px; }
.mavo-empty { color: #787c82; font-style: italic; }
CSS;
	}

	private function js() {
		return <<<'JS'
(function () {
	var NS = 'http://www.w3.org/2000/svg';
	var nodeLabels = {};
	var selectedG = null;

	function post(data, cb) {
		var body = new URLSearchParams(data);
		return fetch(MAVO_LM.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (r) { return r.text(); }).then(cb);
	}
	function postJSON(data, cb) {
		return post(data, function (txt) { var j; try { j = JSON.parse(txt); } catch (e) { j = null; } cb(j); });
	}
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

	/* ---- Graph ---- */
	function loadGraph() {
		var wrap = document.getElementById('mavo-lm-graph');
		wrap.innerHTML = '<p class="mavo-empty">' + esc(MAVO_LM.i18n.loading) + '</p>';
		postJSON({ action: 'mavo_lm_graph', nonce: MAVO_LM.nonce, lang: MAVO_LM.lang }, function (res) {
			if (!res || !res.success) { wrap.innerHTML = '<p class="mavo-empty">Error loading graph.</p>'; return; }
			if (res.data.built) { document.getElementById('mavo-lm-built').textContent = res.data.built; }
			drawGraph(res.data.nodes, res.data.edges, res.data.hasTable);
		});
	}

	function drawGraph(nodes, edges, hasTable) {
		var wrap = document.getElementById('mavo-lm-graph');
		wrap.innerHTML = '';
		selectedG = null;
		if (!nodes || !nodes.length) { wrap.innerHTML = '<p class="mavo-empty">' + esc(MAVO_LM.i18n.nonodes) + '</p>'; return; }
		if (!hasTable || !edges) { edges = []; }

		var W = wrap.clientWidth || 800, H = wrap.clientHeight || 600;
		var cx = W / 2, cy = H / 2;
		var margin = 130;
		var R = Math.max(60, Math.min(W, H) / 2 - margin);
		var N = nodes.length;

		var minC = Infinity, maxC = -Infinity;
		nodes.forEach(function (n) { minC = Math.min(minC, n.count); maxC = Math.max(maxC, n.count); });
		var pos = {};
		nodes.forEach(function (n, i) {
			var ang = -Math.PI / 2 + i * 2 * Math.PI / N;
			n.x = cx + R * Math.cos(ang);
			n.y = cy + R * Math.sin(ang);
			n.ang = ang;
			pos[n.id] = n;
			nodeLabels[n.id] = n.label;
		});

		function fontSize(c) {
			if (maxC <= minC) { return 14; }
			return 11 + (c - minC) / (maxC - minC) * 11; // 11..22
		}

		var edgeHtml = '';
		edges.forEach(function (e) {
			var a = pos[e.a], b = pos[e.b];
			if (!a || !b) { return; }
			var mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
			var ctrlX = mx + (cx - mx) * 0.6, ctrlY = my + (cy - my) * 0.6;
			var d = 'M' + a.x.toFixed(1) + ' ' + a.y.toFixed(1) + ' Q' + ctrlX.toFixed(1) + ' ' + ctrlY.toFixed(1) + ' ' + b.x.toFixed(1) + ' ' + b.y.toFixed(1);
			edgeHtml += '<g class="mavo-edge-g" data-a="' + e.a + '" data-b="' + e.b + '" data-w="' + e.w + '" data-lx="' + ctrlX.toFixed(1) + '" data-ly="' + ctrlY.toFixed(1) + '">'
				+ '<path class="mavo-edge" d="' + d + '"></path>'
				+ '<path class="mavo-edge-hit" d="' + d + '"></path>'
				+ '</g>';
		});

		var nodeHtml = '';
		nodes.forEach(function (n) {
			var cos = Math.cos(n.ang);
			var anchor = cos < -0.15 ? 'end' : (cos > 0.15 ? 'start' : 'middle');
			var lx = cx + (R + 10) * Math.cos(n.ang);
			var ly = cy + (R + 10) * Math.sin(n.ang);
			var fs = fontSize(n.count).toFixed(1);
			nodeHtml += '<circle class="mavo-node-dot" cx="' + n.x.toFixed(1) + '" cy="' + n.y.toFixed(1) + '" r="3"></circle>'
				+ '<text class="mavo-node-label" data-id="' + n.id + '" x="' + lx.toFixed(1) + '" y="' + ly.toFixed(1) + '" text-anchor="' + anchor + '" dominant-baseline="middle" font-size="' + fs + '">'
				+ esc(n.label) + ' (' + n.count + ')</text>';
		});

		var svg = document.createElementNS(NS, 'svg');
		svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
		svg.setAttribute('class', 'mavo-lm-svg');
		wrap.appendChild(svg);
		svg.innerHTML = '<g class="mavo-edges">' + edgeHtml + '</g><g class="mavo-nodes">' + nodeHtml + '</g><g class="mavo-overlay"></g>';

		var edgesLayer = svg.querySelector('.mavo-edges');
		var overlay = svg.querySelector('.mavo-overlay');

		function showWeight(g) {
			overlay.innerHTML = '<text class="mavo-edge-weight" x="' + g.dataset.lx + '" y="' + g.dataset.ly + '" text-anchor="middle" dominant-baseline="middle">' + g.dataset.w + '</text>';
		}
		function hideWeight() { overlay.innerHTML = ''; }

		svg.querySelectorAll('.mavo-edge-g').forEach(function (g) {
			g.addEventListener('mouseenter', function () { edgesLayer.appendChild(g); g.classList.add('hover'); showWeight(g); });
			g.addEventListener('mouseleave', function () { g.classList.remove('hover'); if (g !== selectedG) { hideWeight(); } else { showWeight(g); } });
			g.addEventListener('click', function () {
				if (selectedG) { selectedG.classList.remove('selected'); }
				selectedG = g; g.classList.add('selected'); edgesLayer.appendChild(g); showWeight(g);
				loadLinks(g.dataset.a, g.dataset.b);
			});
		});
	}

	/* ---- Link list ---- */
	function loadLinks(a, b) {
		var list = document.getElementById('mavo-lm-list');
		list.innerHTML = '<p class="mavo-empty">' + esc(MAVO_LM.i18n.loading) + '</p>';
		post({ action: 'mavo_lm_links', nonce: MAVO_LM.nonce, a: a, b: b }, function (html) { list.innerHTML = html; });
	}

	/* ---- Rebuild ---- */
	function rebuild() {
		if (!window.confirm(MAVO_LM.i18n.confirm)) { return; }
		var btn = document.getElementById('mavo-lm-rebuild');
		var prog = document.getElementById('mavo-lm-progress');
		var bar = prog.querySelector('.mavo-lm-progress-bar span');
		var txt = prog.querySelector('.mavo-lm-progress-text');
		btn.disabled = true; prog.hidden = false; bar.style.width = '0'; txt.textContent = MAVO_LM.i18n.building;

		postJSON({ action: 'mavo_lm_rebuild_start', nonce: MAVO_LM.nonce }, function (res) {
			if (!res || !res.success) { txt.textContent = 'Error.'; btn.disabled = false; return; }
			var total = res.data.total || 0;
			function step(offset) {
				postJSON({ action: 'mavo_lm_rebuild_batch', nonce: MAVO_LM.nonce, offset: offset }, function (r) {
					if (!r || !r.success) { txt.textContent = 'Error.'; btn.disabled = false; return; }
					var processed = r.data.processed;
					var pct = total ? Math.min(100, Math.round(processed / total * 100)) : 100;
					bar.style.width = pct + '%';
					txt.textContent = MAVO_LM.i18n.building + ' ' + processed + ' / ' + total + ' (' + pct + '%)';
					if (r.data.done) {
						txt.textContent = MAVO_LM.i18n.done + ' ' + (r.data.built || '');
						btn.disabled = false;
						if (r.data.built) { document.getElementById('mavo-lm-built').textContent = r.data.built; }
						setTimeout(function () { prog.hidden = true; }, 4000);
						loadGraph();
					} else {
						step(offset + MAVO_LM.batch);
					}
				});
			}
			step(0);
		});
	}

	/* ---- Wire up ---- */
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', loadGraph); }
	else { loadGraph(); }

	var btn = document.getElementById('mavo-lm-rebuild');
	if (btn) { btn.addEventListener('click', rebuild); }

	var ls = document.getElementById('mavo-lang');
	if (ls) {
		ls.addEventListener('change', function () {
			var u = new URL(window.location.href);
			u.searchParams.set('mavo_lang', this.value);
			window.location.href = u.toString();
		});
	}
})();
JS;
	}
}
