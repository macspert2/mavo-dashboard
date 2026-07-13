# Mavo Dashboard

An admin-only WordPress plugin that adds two content-analytics dashboards to the
backend. It is **completely invisible on the frontend** — no HTML, CSS, or
JavaScript is ever enqueued or output on public pages. Every hook is registered
against the admin, and all assets are inlined only on the plugin's own screens.

- **Version:** 1.1.0
- **Text Domain:** `mavo-dashboard`
- **Minimum capability to view:** `edit_posts`
- **Capability to rebuild the link table:** `manage_options`

---

## Table of contents

- [Installation](#installation)
- [Menu structure](#menu-structure)
- [Screen 1 — Overview](#screen-1--overview)
- [Screen 2 — Internal Link Map](#screen-2--internal-link-map)
- [Language handling (Polylang)](#language-handling-polylang)
- [Curated tags](#curated-tags)
- [Content parsing rules](#content-parsing-rules)
- [Configuration constants](#configuration-constants)
- [Database tables](#database-tables)
- [Hooks / filters](#hooks--filters)
- [File layout](#file-layout)
- [Security notes](#security-notes)

---

## Installation

1. Copy the `mavo-dashboard` folder into `wp-content/plugins/`.
2. Activate **Mavo Dashboard** from the Plugins screen.
3. (Optional) Define the postmeta and table constants in `wp-config.php` to match
   your data — see [Configuration constants](#configuration-constants).
4. Open **Mavo Dashboard** in the left admin menu.
5. To populate the link map, open **Internal Link Map** and click **Recalculate**
   (requires `manage_options`).

No build step, database migration, or activation hook is required. The link table
is created on demand the first time you run a rebuild.

---

## Menu structure

The plugin registers a single top-level menu (`dashicons-chart-area`, position 3)
with two submenus:

| Menu item          | Slug             | Handler class     | Purpose                                    |
| ------------------ | ---------------- | ----------------- | ------------------------------------------ |
| Overview           | `mavo-dashboard` | `Mavo_Dashboard`  | Tags → posts → per-post link/image detail  |
| Internal Link Map  | `mavo-link-map`  | `Mavo_Link_Map`   | Chord graph of internal links between tags |

Both screens require the `edit_posts` capability to view.

---

## Screen 1 — Overview

A three-part, full-height layout that drills down from a tag, to the posts under
that tag, to the links and images inside a single post. All navigation happens
through AJAX (no page reloads except when changing language).

### Part 1 — Tag bar (slim, top)

- The **curated tags** (see [Curated tags](#curated-tags)) rendered as a single
  wrapping, comma-separated line, each followed by its post count in parentheses,
  e.g. `Europe (240), France (188), Paris (92), …`.
- Tags are shown in their predefined curated order (not by frequency), and span
  all languages regardless of the current language filter.
- Clicking a tag loads Part 2 for that tag; the first tag is active on load.
- A **"Relative share line"** checkbox toggles the green ratio line in every
  sparkline (see below).
- A **Language** `<select>` (only shown when Polylang is active).

### Part 2 — Post list (upper half, scrollable)

A sortable table of every published post carrying the selected tag, in the
current language. Columns (wrapping if too wide):

| Column           | Source                                                              |
| ---------------- | ------------------------------------------------------------------ |
| Title            | Post title — clickable, loads Part 3                               |
| Slug             | `post_name` — links to the post editor                            |
| Published        | Publish date (`Y-m-d`)                                             |
| Featured         | 56×56 featured-image thumbnail                                    |
| Words            | Word count of the stripped content                                |
| Comments         | `comment_count`                                                   |
| Images           | Number of `<img>` tags in the content                             |
| Internal         | Count of internal links                                           |
| Booking          | Count of `booking.com` links                                      |
| DiscoverCars     | Count of `discovercars.com` links                                 |
| bpul             | `MAVO_META_BPUL` postmeta value                                   |
| maj              | `MAVO_META_MAJ` postmeta value                                    |
| Views            | `MAVO_META_VIEWS` postmeta — **the table is sorted DESC by this** |
| Trend (monthly)  | Inline-SVG sparkline of the monthly view history                 |

**The sparkline (Trend column)** overlays up to three lines, each normalised
independently to the full height of the cell:

- **Blue** — this post's monthly views, with a dot marking the latest month.
- **Gold** — the site-wide monthly view total (rows stored under `post_id = 0`),
  drawn behind the post line as a reference baseline.
- **Green** — the post's *relative share* (post views ÷ site total) per month.
  Hidden by default; toggled by the "Relative share line" checkbox.

Missing months are treated as zero views. The series for the whole list is fetched
in a single batched query; the source data lives in the monthly-snapshot table
(see [Database tables](#database-tables)). If that table is absent, the column
shows a dash and everything else still works.

### Part 3 — Post detail (lower half, scrollable)

Full link/image breakdown for the selected post, in four blocks:

- **Internal links** — anchor text + URL for every same-site link.
- **Booking + DiscoverCars links** — the two affiliate buckets, combined and
  highlighted.
- **Other external links** — every remaining external link.
- **Image thumbnails** — a grid of every `<img>` in the content, each linking to
  the full-size source.

Each block shows a count badge, and the detail header links to the post editor.

On initial page load, Parts 2 and 3 are pre-rendered for the first tag and its
top-viewed post, so the dashboard is useful without any clicking.

---

## Screen 2 — Internal Link Map

A visualisation of how the curated tags are connected by **internal links between
their posts**. If a post tagged *A* links internally to a post tagged *B*, that
contributes to the *A ↔ B* edge.

### Layout

- **Left (≈55%)** — a circular **chord graph**. Each curated tag is a node on the
  circle; node label size scales with the tag's post count. A curved line connects
  two tags for every internal-link relationship between their posts. Hovering an
  edge shows its weight (number of connecting links); hovering a node shows its
  **intra-tag** link count (links between two posts that share that tag).
- **Right (≈45%)** — when you click an edge (or a node, for intra-tag links), this
  panel lists the actual links: source post → target post, the anchor text, and
  the raw URL. Both post titles link to their editors, sorted by source then
  target title.

The **`europe`, `europe-en-en`, and `europa`** tags are excluded from this screen
(they remain on the Overview) because they are umbrella tags that would dominate
the graph.

### Rebuilding the link table

The graph reads from a precomputed table; nothing is calculated live. To (re)build
it, a user with `manage_options` clicks **Recalculate**:

1. `mavo_lm_rebuild_start` truncates (and, if needed, creates) the link table and
   counts the published posts.
2. `mavo_lm_rebuild_batch` is called repeatedly from JavaScript, processing
   **25 posts per request** using keyset pagination by post ID (robust against
   timeouts, index-friendly, no `OFFSET`). For each post it parses the content,
   resolves each internal link to a target post ID, and bulk-inserts the resolved
   `source → target` rows.
3. Progress is shown as a bar; on completion the graph reloads.

Because the rebuild is chunked over many AJAX requests, it copes with large sites
without hitting PHP execution limits. If a batch fails (e.g. a server timeout), the
rebuild stops and must be restarted from the beginning.

### Diagnostics

After a rebuild, a collapsible **Rebuild diagnostics** panel reports:

- Whether the resolver code is up to date (guards against stale OPcache/deploys).
- Posts scanned, internal links found vs. resolved, and external/booking/
  discovercars counts.
- A per-batch log (`after=… got=… lastID=…`).
- Sample URLs that **did not** resolve to a published post — useful for spotting
  broken or non-canonical internal links.
- Sample URLs treated as **external/other** — useful for catching your own domain
  being missed (e.g. a legacy host).

### Link resolution

Internal URLs are resolved to a post ID by `Mavo_Helpers::resolve_internal()`,
which tries, in order:

1. `?p=` / `?page_id=` numeric IDs.
2. WordPress' own `url_to_postid()` on the absolutised URL.
3. A direct `post_name` lookup on the last path segment (handles Polylang
   language prefixes, mismatched `www`/host, query strings, and stray
   `.html`/`.php` suffixes).
4. WordPress' `_wp_old_slug` meta, so links to renamed posts still resolve.

Only **published posts** count as valid targets, and self-links are dropped.

---

## Language handling (Polylang)

The plugin is Polylang-aware but degrades gracefully when Polylang is inactive
(the language selector simply doesn't appear, and everything runs unscoped).

- The current language is read from `?mavo_lang=` (`all` | a Polylang slug),
  falling back to Polylang's admin language filter, then to `all`.
- Changing the selector reloads the page with the new `mavo_lang` query arg.
- On the **Overview**, the post query is scoped to the chosen language; the tag
  bar itself always spans all languages (the curated list is cross-language by
  design).
- On the **Link Map**, the node set is filtered to the chosen language via
  `pll_get_term_language()`.

All incoming language values are sanitised against the known Polylang slug list.

---

## Curated tags

Both screens work from a single, hand-curated list of tag **slugs** (in display
order) returned by `Mavo_Helpers::dashboard_tag_slugs()`. It contains ~75 slugs
spanning French, English, and German variants of European destinations, French
regions, and activity tags (hiking, cycling, ski, campervan, …).

Slugs that don't resolve to an existing term are silently skipped, and the list is
filterable — see [Hooks / filters](#hooks--filters).

---

## Content parsing rules

`Mavo_Helpers::parse_links_images()` parses post content once (via `DOMDocument`)
and classifies every link and image. This single method feeds the counts on
Screen 1, the detail panel, and the link-map rebuild.

- **Words** — `str_word_count()` of the tag-stripped content.
- **Internal** — same host as the site, or a relative/root-relative URL.
- **Booking** — host contains `booking.com`.
- **Discover** — host contains `discovercars.com`.
- **Other** — any remaining external link.
- **Images** — the `src` of every `<img>`.

Links that are pure fragments (`#…`), `mailto:`, `tel:`, or that point directly at
an image file (lightbox/file links) are ignored. Host comparison strips a leading
`www.` and is case-insensitive.

---

## Configuration constants

Define any of these in `wp-config.php` (or let the plugin fall back to its
defaults). **The postmeta keys almost certainly need adjusting to match your
data.**

| Constant            | Default                  | Meaning                                                        |
| ------------------- | ------------------------ | -------------------------------------------------------------- |
| `MAVO_META_VIEWS`   | `views`                  | Numeric view counter; the Overview post list sorts DESC by it. |
| `MAVO_META_BPUL`    | `_mavo_bpul_key`         | The "bpul" field shown in the post list.                       |
| `MAVO_META_MAJ`     | `_mavo_maj_key`          | The "maj" field shown in the post list.                        |
| `MAVO_VIEWS_TABLE`  | `rpp_monthly_snapshots`  | Monthly view-history table (WP prefix prepended).              |
| `MAVO_LINKS_TABLE`  | `mavo_internal_links`    | Precomputed internal-link table (WP prefix prepended).         |

---

## Database tables

The WordPress table prefix (e.g. `wp_`) is prepended to both names automatically.

### Monthly view history — `MAVO_VIEWS_TABLE` (read-only here)

The plugin **reads** this table for the sparklines but does not create or populate
it — it is expected to be maintained by your view-tracking setup. Columns used:

| Column           | Notes                                                       |
| ---------------- | ----------------------------------------------------------- |
| `post_id`        | Post ID; **`0`** holds the site-wide monthly totals.        |
| `snapshot_month` | `DATE`, first of the month (`YYYY-MM-01`).                   |
| `views`          | That single month's view count.                             |

A post with no row for a month is treated as `0` views for that month. If the
table doesn't exist, the Trend column simply shows dashes.

### Internal-link map — `MAVO_LINKS_TABLE` (created & filled by the plugin)

Created on demand (via `dbDelta`) and filled by the **Recalculate** button:

| Column       | Notes                                     |
| ------------ | ----------------------------------------- |
| `id`         | Auto-increment primary key.               |
| `source_id`  | Post that contains the link (indexed).    |
| `target_id`  | Resolved published post it links to (indexed). |
| `anchor`     | Anchor text (truncated to 1000 chars).    |
| `url`        | Raw URL (truncated to 2000 chars).        |

Edges/weights are derived at query time by joining this table against
`wp_term_relationships`, so re-tagging posts is reflected without a rebuild;
only changes to the *links themselves* require re-running Recalculate.

---

## Hooks / filters

| Filter                     | Purpose                                                    |
| -------------------------- | ---------------------------------------------------------- |
| `mavo_dashboard_tag_slugs` | Modify the curated tag-slug list (array of slugs, ordered).|

AJAX actions (all admin-only, nonce-protected):

| Action                   | Screen | Purpose                              |
| ------------------------ | ------ | ------------------------------------ |
| `mavo_posts`             | 1      | Render Part 2 for a tag + language.  |
| `mavo_detail`            | 1      | Render Part 3 for a post.            |
| `mavo_lm_graph`          | 2      | Return graph nodes + edges (JSON).   |
| `mavo_lm_links`          | 2      | Render the link list for a tag pair. |
| `mavo_lm_rebuild_start`  | 2      | Truncate/create table, count posts.  |
| `mavo_lm_rebuild_batch`  | 2      | Process one batch of posts.          |

---

## File layout

```
mavo-dashboard/
├── mavo-dashboard.php            Plugin header, constants, menu registration
├── includes/
│   ├── class-mavo-dashboard.php  Screen 1 — Overview (queries, sparklines, AJAX, inline CSS/JS)
│   ├── class-mavo-link-map.php   Screen 2 — Internal Link Map (graph, rebuild, AJAX, inline CSS/JS)
│   └── class-mavo-helpers.php    Shared: Polylang, curated tags, content parsing, link resolution
├── README.md
└── CLAUDE.md
```

Each screen's CSS and JS are generated inline by the handler class and enqueued
only on that screen's admin hook, so nothing leaks onto other admin pages or the
frontend.

---

## Security notes

- Every screen and AJAX handler checks the `edit_posts` capability (rebuild
  requires `manage_options`) and verifies a per-screen nonce.
- All output is escaped (`esc_html`, `esc_url`, `esc_attr`); the few raw echoes
  are pre-escaped HTML fragments marked with `phpcs:ignore`.
- Direct SQL uses `$wpdb->prepare()` wherever values are user-influenced; the
  remaining direct queries only interpolate integer IDs already cast with
  `intval`/`absint` and table names built from constants.
- The plugin never enqueues or outputs anything on the frontend.
