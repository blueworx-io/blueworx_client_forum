<?php
/**
 * Backend: Remove Historical WooCommerce Images
 *
 * A Snippet that runs through WooCommerce images and purges them.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */
/**
 * Plugin Name: One-Time WooCommerce Product Image Purge
 * Description: Safely scans WooCommerce product images, protects images used elsewhere, then deletes product-only images in batches.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * This is a one-time, admin-only maintenance tool (Tools > Purge Product Images, manage_options).
 * It intentionally runs direct, uncached, schema-level queries against core tables in batches, and
 * builds IN() lists from integer IDs already passed through absint(). Caching and prepared-statement
 * placeholders add no security value in this context, so the relevant sniffs are disabled for this file.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery, Squiz.PHP.DiscouragedFunctions.Discouraged

final class One_Time_WC_Product_Image_Purge {
    private const PAGE_SLUG = 'one-time-wc-product-image-purge';
    private const NONCE_ACTION = 'one_time_wc_product_image_purge';
    private const PLAN_OPTION = 'one_time_wc_product_image_purge_plan';
    private const BATCH_SIZE = 250;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
    }

    public function add_admin_page(): void {
        add_management_page(
            'Purge Product Images',
            'Purge Product Images',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $result = null;

        if (isset($_POST['wc_product_image_purge_mode'])) {
            check_admin_referer(self::NONCE_ACTION);
            $mode = sanitize_text_field(wp_unslash($_POST['wc_product_image_purge_mode']));

            if ($mode === 'dry_run') {
                $result = $this->scan();
                $this->save_plan($result);
            } elseif ($mode === 'delete') {
                $result = $this->delete_next_batch();
            } elseif ($mode === 'clear') {
                delete_option(self::PLAN_OPTION);
                $result = ['mode' => 'clear'];
            }
        }

        $plan = get_option(self::PLAN_OPTION);

        echo '<div class="wrap">';
        echo '<h1>One-Time WooCommerce Product Image Purge</h1>';
        echo '<p>Use this once, after taking a backup. For large sites, keep this page open while deletion runs.</p>';

        if ($result) {
            $this->render_result($result);
        } elseif (is_array($plan) && !empty($plan['remaining_ids'])) {
            echo '<div class="notice notice-warning"><p>A saved scan is ready. Product-only images left to delete: <strong>' . esc_html((string) count($plan['remaining_ids'])) . '</strong>.</p></div>';
        }

        echo '<form method="post" style="margin-top:20px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="wc_product_image_purge_mode" value="dry_run">';
        submit_button('Run dry scan', 'secondary');
        echo '</form>';

        echo '<form method="post" style="margin-top:12px;" onsubmit="return confirm(\'This permanently deletes product-only image files. Continue?\');">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="wc_product_image_purge_mode" value="delete">';
        submit_button('Delete product-only images', 'delete');
        echo '</form>';

        if (is_array($plan)) {
            echo '<form method="post" style="margin-top:12px;">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="wc_product_image_purge_mode" value="clear">';
            submit_button('Clear saved scan', 'secondary');
            echo '</form>';
        }

        echo '</div>';
    }

    private function scan(): array {
        @set_time_limit(900);

        $candidate_map = $this->collect_product_image_ids();
        $candidate_ids = array_keys($candidate_map);
        sort($candidate_ids);

        $lookup = $this->build_candidate_lookup($candidate_ids);
        $protected = $this->collect_protected_ids($candidate_ids, $lookup);
        $to_delete = array_values(array_diff($candidate_ids, array_keys($protected)));
        sort($to_delete);

        return [
            'mode' => 'dry_run',
            'candidate_ids' => $candidate_ids,
            'protected' => $protected,
            'to_delete' => $to_delete,
            'candidate_map' => $candidate_map,
        ];
    }

    private function save_plan(array $scan): void {
        update_option(self::PLAN_OPTION, [
            'created_at' => time(),
            'total_candidates' => count($scan['candidate_ids']),
            'total_protected' => count($scan['protected']),
            'total_to_delete' => count($scan['to_delete']),
            'remaining_ids' => array_values($scan['to_delete']),
            'deleted_ids' => [],
            'failed_ids' => [],
        ], false);
    }

    private function delete_next_batch(): array {
        @set_time_limit(600);

        $plan = get_option(self::PLAN_OPTION);

        if (!is_array($plan) || !isset($plan['remaining_ids'])) {
            $scan = $this->scan();
            $this->save_plan($scan);
            $plan = get_option(self::PLAN_OPTION);
        }

        $remaining = array_values(array_map('absint', $plan['remaining_ids']));
        $batch = array_slice($remaining, 0, self::BATCH_SIZE);
        $deleted = [];
        $failed = [];

        foreach ($batch as $attachment_id) {
            if (!$attachment_id) {
                continue;
            }

            $deleted_post = wp_delete_attachment($attachment_id, true);

            if ($deleted_post) {
                $deleted[] = $attachment_id;
            } else {
                $failed[] = $attachment_id;
            }
        }

        if ($deleted) {
            $this->remove_deleted_product_image_references($deleted);
        }

        $plan['remaining_ids'] = array_slice($remaining, count($batch));
        $plan['deleted_ids'] = array_values(array_unique(array_merge($plan['deleted_ids'] ?? [], $deleted)));
        $plan['failed_ids'] = array_values(array_unique(array_merge($plan['failed_ids'] ?? [], $failed)));
        update_option(self::PLAN_OPTION, $plan, false);

        return [
            'mode' => 'delete',
            'batch_count' => count($batch),
            'batch_deleted' => count($deleted),
            'batch_failed' => count($failed),
            'total_candidates' => absint($plan['total_candidates'] ?? 0),
            'total_protected' => absint($plan['total_protected'] ?? 0),
            'total_to_delete' => absint($plan['total_to_delete'] ?? 0),
            'total_deleted' => count($plan['deleted_ids']),
            'total_failed' => count($plan['failed_ids']),
            'remaining_count' => count($plan['remaining_ids']),
        ];
    }

    private function collect_product_image_ids(): array {
        global $wpdb;

        $images = [];

        $thumbnail_rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_type
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND p.post_type IN ('product', 'product_variation')"
        );

        foreach ($thumbnail_rows as $row) {
            $label = $row->post_type === 'product_variation' ? 'variation image' : 'product featured image';
            $this->add_candidate($images, absint($row->meta_value), $label . ' on post #' . absint($row->post_id));
        }

        $gallery_rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
             AND p.post_type = 'product'"
        );

        foreach ($gallery_rows as $row) {
            $gallery_ids = array_filter(array_map('absint', explode(',', (string) $row->meta_value)));

            foreach ($gallery_ids as $attachment_id) {
                $this->add_candidate($images, $attachment_id, 'product gallery on post #' . absint($row->post_id));
            }
        }

        $attached_rows = $wpdb->get_results(
            "SELECT a.ID AS attachment_id, p.ID AS post_id
             FROM {$wpdb->posts} a
             INNER JOIN {$wpdb->posts} p ON p.ID = a.post_parent
             WHERE a.post_type = 'attachment'
             AND p.post_type IN ('product', 'product_variation')"
        );

        foreach ($attached_rows as $row) {
            $this->add_candidate($images, absint($row->attachment_id), 'media attached to product #' . absint($row->post_id));
        }

        foreach (array_keys($images) as $attachment_id) {
            if (get_post_type($attachment_id) !== 'attachment') {
                unset($images[$attachment_id]);
            }
        }

        return $images;
    }

    private function add_candidate(array &$images, int $attachment_id, string $reason): void {
        if (!$attachment_id) {
            return;
        }

        if (!isset($images[$attachment_id])) {
            $images[$attachment_id] = [];
        }

        if (count($images[$attachment_id]) < 5) {
            $images[$attachment_id][] = $reason;
        }
    }

    private function build_candidate_lookup(array $candidate_ids): array {
        $lookup = [
            'ids' => [],
            'paths' => [],
            'basenames' => [],
            'normalized_basenames' => [],
        ];

        foreach ($candidate_ids as $attachment_id) {
            $lookup['ids'][$attachment_id] = true;
            $attached_file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);

            if ($attached_file !== '') {
                $this->add_file_to_lookup($lookup, $attached_file, $attachment_id);
            }

            $meta = wp_get_attachment_metadata($attachment_id);

            if (is_array($meta) && !empty($meta['file'])) {
                $this->add_file_to_lookup($lookup, (string) $meta['file'], $attachment_id);
                $dir = dirname((string) $meta['file']);
                $dir = $dir === '.' ? '' : trailingslashit($dir);

                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size) {
                        if (!empty($size['file'])) {
                            $this->add_file_to_lookup($lookup, $dir . $size['file'], $attachment_id);
                        }
                    }
                }
            }
        }

        return $lookup;
    }

    private function add_file_to_lookup(array &$lookup, string $file, int $attachment_id): void {
        $path_key = $this->normalize_path_key($file);
        $basename = basename($path_key);
        $normalized_basename = $this->normalize_image_basename($basename);

        if ($path_key !== '') {
            $lookup['paths'][$path_key][$attachment_id] = true;
        }

        if ($basename !== '') {
            $lookup['basenames'][$basename][$attachment_id] = true;
        }

        if ($normalized_basename !== '') {
            $lookup['normalized_basenames'][$normalized_basename][$attachment_id] = true;
        }
    }

    private function collect_protected_ids(array $candidate_ids, array $lookup): array {
        if (!$candidate_ids) {
            return [];
        }

        $protected = [];

        $this->protect_non_product_featured_images($candidate_ids, $protected);
        $this->protect_taxonomy_images($candidate_ids, $protected);
        $this->protect_attachments_owned_by_non_products($candidate_ids, $protected);
        $this->protect_images_found_in_post_content($lookup, $protected);
        $this->protect_images_found_in_post_meta($lookup, $protected);
        $this->protect_images_found_in_options($lookup, $protected);

        return $protected;
    }

    private function protect_non_product_featured_images(array $candidate_ids, array &$protected): void {
        global $wpdb;

        foreach (array_chunk($candidate_ids, 1000) as $chunk) {
            $ids_sql = implode(',', array_map('absint', $chunk));
            $rows = $wpdb->get_results(
                "SELECT pm.meta_value AS attachment_id, p.ID AS post_id, p.post_type
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_thumbnail_id'
                 AND pm.meta_value IN ($ids_sql)
                 AND p.post_type NOT IN ('product', 'product_variation')"
            );

            foreach ($rows as $row) {
                $this->protect($protected, absint($row->attachment_id), 'featured image on ' . $row->post_type . ' #' . absint($row->post_id));
            }
        }
    }

    private function protect_taxonomy_images(array $candidate_ids, array &$protected): void {
        global $wpdb;

        foreach (array_chunk($candidate_ids, 1000) as $chunk) {
            $ids_sql = implode(',', array_map('absint', $chunk));
            $rows = $wpdb->get_results(
                "SELECT tm.meta_value AS attachment_id, tt.taxonomy
                 FROM {$wpdb->termmeta} tm
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                 WHERE tm.meta_value IN ($ids_sql)"
            );

            foreach ($rows as $row) {
                $this->protect($protected, absint($row->attachment_id), 'taxonomy image on ' . $row->taxonomy);
            }
        }
    }

    private function protect_attachments_owned_by_non_products(array $candidate_ids, array &$protected): void {
        global $wpdb;

        foreach (array_chunk($candidate_ids, 1000) as $chunk) {
            $ids_sql = implode(',', array_map('absint', $chunk));
            $rows = $wpdb->get_results(
                "SELECT a.ID AS attachment_id, parent.ID AS parent_id, parent.post_type
                 FROM {$wpdb->posts} a
                 INNER JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent
                 WHERE a.ID IN ($ids_sql)
                 AND a.post_parent > 0
                 AND parent.post_type NOT IN ('product', 'product_variation')"
            );

            foreach ($rows as $row) {
                $this->protect($protected, absint($row->attachment_id), 'attached to ' . $row->post_type . ' #' . absint($row->parent_id));
            }
        }
    }

    private function protect_images_found_in_post_content(array $lookup, array &$protected): void {
        global $wpdb;

        $last_id = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_type, post_content, post_excerpt
                     FROM {$wpdb->posts}
                     WHERE ID > %d
                     AND post_type <> 'attachment'
                     AND (post_content <> '' OR post_excerpt <> '')
                     ORDER BY ID ASC
                     LIMIT 250",
                    $last_id
                )
            );

            foreach ($rows as $row) {
                $last_id = absint($row->ID);
                $value = (string) $row->post_content . "\n" . (string) $row->post_excerpt;
                $this->protect_images_found_in_value($value, $lookup, $protected, 'used in content on ' . $row->post_type . ' #' . absint($row->ID));
            }
        } while ($rows);
    }

    private function protect_images_found_in_post_meta(array $lookup, array &$protected): void {
        global $wpdb;

        $last_id = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.meta_id, pm.meta_key, pm.meta_value, p.ID AS post_id, p.post_type
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_id > %d
                     AND p.post_type <> 'attachment'
                     AND pm.meta_value <> ''
                     AND pm.meta_key NOT IN ('_thumbnail_id', '_product_image_gallery')
                     ORDER BY pm.meta_id ASC
                     LIMIT 250",
                    $last_id
                )
            );

            foreach ($rows as $row) {
                $last_id = absint($row->meta_id);
                $this->protect_images_found_in_value(
                    (string) $row->meta_value,
                    $lookup,
                    $protected,
                    'used in meta ' . $row->meta_key . ' on ' . $row->post_type . ' #' . absint($row->post_id)
                );
            }
        } while ($rows);
    }

    private function protect_images_found_in_options(array $lookup, array &$protected): void {
        global $wpdb;

        $last_id = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_id, option_name, option_value
                     FROM {$wpdb->options}
                     WHERE option_id > %d
                     AND option_value <> ''
                     AND option_name NOT LIKE '\_transient\_%'
                     AND option_name NOT LIKE '\_site\_transient\_%'
                     ORDER BY option_id ASC
                     LIMIT 250",
                    $last_id
                )
            );

            foreach ($rows as $row) {
                $last_id = absint($row->option_id);
                $this->protect_images_found_in_value(
                    (string) $row->option_value,
                    $lookup,
                    $protected,
                    'used in WordPress option ' . $row->option_name
                );
            }
        } while ($rows);
    }

    private function protect_images_found_in_value(string $value, array $lookup, array &$protected, string $reason): void {
        if ($value === '') {
            return;
        }

        $value = $this->normalize_search_value($value);
        $found = [];

        if (preg_match_all('/wp-image-(\d+)\b/i', $value, $matches)) {
            foreach ($matches[1] as $id) {
                $this->add_found_id($found, absint($id), $lookup);
            }
        }

        if (preg_match_all('/["\'](?:id|image|image_id|media|media_id|attachment_id)["\']\s*[:=]\s*["\']?(\d+)["\']?/i', $value, $matches)) {
            foreach ($matches[1] as $id) {
                $this->add_found_id($found, absint($id), $lookup);
            }
        }

        if (preg_match_all('/s:\d+:"(?:id|image|image_id|media|media_id|attachment_id)";(?:i:(\d+)|s:\d+:"(\d+)")/i', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->add_found_id($found, absint($match[1] ?: $match[2]), $lookup);
            }
        }

        if (preg_match_all('/(?:wp-content\/uploads\/)?((?:\d{4}\/\d{2}\/)?[^"\'\s<>)]+?\.(?:jpe?g|png|gif|webp|avif|svg))\b/i', $value, $matches)) {
            foreach ($matches[1] as $file) {
                $this->add_found_file($found, $file, $lookup);
            }
        }

        foreach (array_keys($found) as $attachment_id) {
            $this->protect($protected, absint($attachment_id), $reason);
        }
    }

    private function add_found_id(array &$found, int $attachment_id, array $lookup): void {
        if ($attachment_id && isset($lookup['ids'][$attachment_id])) {
            $found[$attachment_id] = true;
        }
    }

    private function add_found_file(array &$found, string $file, array $lookup): void {
        $path_key = $this->normalize_path_key($file);
        $basename = basename($path_key);
        $normalized_basename = $this->normalize_image_basename($basename);

        foreach ([$lookup['paths'][$path_key] ?? [], $lookup['basenames'][$basename] ?? [], $lookup['normalized_basenames'][$normalized_basename] ?? []] as $ids) {
            foreach (array_keys($ids) as $attachment_id) {
                $found[$attachment_id] = true;
            }
        }
    }

    private function normalize_search_value(string $value): string {
        $value = wp_unslash($value);
        $value = str_replace(['\/', '\\u002F', '\\\\'], ['/', '/', '/'], $value);
        return rawurldecode($value);
    }

    private function normalize_path_key(string $path): string {
        $path = $this->normalize_search_value($path);
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim($path, '/');

        $uploads_pos = stripos($path, 'wp-content/uploads/');
        if ($uploads_pos !== false) {
            $path = substr($path, $uploads_pos + strlen('wp-content/uploads/'));
        }

        return strtolower($path);
    }

    private function normalize_image_basename(string $basename): string {
        $info = pathinfo(strtolower($basename));

        if (empty($info['filename']) || empty($info['extension'])) {
            return strtolower($basename);
        }

        $filename = preg_replace('/-\d+x\d+$/', '', $info['filename']);
        return $filename . '.' . $info['extension'];
    }

    private function protect(array &$protected, int $attachment_id, string $reason): void {
        if (!$attachment_id) {
            return;
        }

        if (!isset($protected[$attachment_id])) {
            $protected[$attachment_id] = [];
        }

        if (count($protected[$attachment_id]) < 5) {
            $protected[$attachment_id][] = $reason;
        }
    }

    private function remove_deleted_product_image_references(array $deleted_ids): void {
        global $wpdb;

        $ids_sql = implode(',', array_map('absint', $deleted_ids));

        if ($ids_sql === '') {
            return;
        }

        $wpdb->query(
            "DELETE pm
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND pm.meta_value IN ($ids_sql)
             AND p.post_type IN ('product', 'product_variation')"
        );

        $gallery_rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
             AND p.post_type = 'product'"
        );

        $deleted_lookup = array_flip(array_map('absint', $deleted_ids));

        foreach ($gallery_rows as $row) {
            $existing_ids = array_values(array_filter(array_map('absint', explode(',', (string) $row->meta_value))));
            $remaining_ids = array_values(array_filter($existing_ids, static function ($id) use ($deleted_lookup) {
                return !isset($deleted_lookup[$id]);
            }));

            if ($remaining_ids === $existing_ids) {
                continue;
            }

            if ($remaining_ids) {
                update_post_meta(absint($row->post_id), '_product_image_gallery', implode(',', $remaining_ids));
            } else {
                delete_post_meta(absint($row->post_id), '_product_image_gallery');
            }
        }
    }

    private function render_result(array $result): void {
        if ($result['mode'] === 'clear') {
            echo '<div class="notice notice-success"><p>Saved scan cleared.</p></div>';
            return;
        }

        if ($result['mode'] === 'dry_run') {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>Product-linked images found:</strong> ' . esc_html((string) count($result['candidate_ids'])) . '<br>';
            echo '<strong>Protected because used elsewhere:</strong> ' . esc_html((string) count($result['protected'])) . '<br>';
            echo '<strong>Product-only images ready to delete:</strong> ' . esc_html((string) count($result['to_delete']));
            echo '</p></div>';

            $this->render_id_preview('Protected images', array_keys($result['protected']));
            $this->render_id_preview('Product-only images', $result['to_delete']);
            return;
        }

        if ($result['mode'] === 'delete') {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>Deleted this batch:</strong> ' . esc_html((string) $result['batch_deleted']) . '<br>';
            echo '<strong>Failed this batch:</strong> ' . esc_html((string) $result['batch_failed']) . '<br>';
            echo '<strong>Total deleted:</strong> ' . esc_html((string) $result['total_deleted']) . ' of ' . esc_html((string) $result['total_to_delete']) . '<br>';
            echo '<strong>Remaining:</strong> ' . esc_html((string) $result['remaining_count']);
            echo '</p></div>';

            if ($result['remaining_count'] > 0) {
                echo '<p>Continuing automatically. Keep this tab open.</p>';
                echo '<form id="wc-product-image-purge-continue" method="post">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="wc_product_image_purge_mode" value="delete">';
                echo '</form>';
                echo '<script>setTimeout(function(){document.getElementById("wc-product-image-purge-continue").submit();}, 900);</script>';
            } else {
                echo '<div class="notice notice-success"><p>Finished. You can now deactivate and delete this plugin.</p></div>';
            }
        }
    }

    private function render_id_preview(string $title, array $ids): void {
        if (!$ids) {
            return;
        }

        $limit = 300;
        $shown = array_slice($ids, 0, $limit);

        echo '<details style="margin-top:15px;"><summary>' . esc_html($title) . ' - showing ' . esc_html((string) count($shown)) . ' of ' . esc_html((string) count($ids)) . '</summary><ol>';

        foreach ($shown as $attachment_id) {
            echo '<li>#' . esc_html((string) absint($attachment_id)) . ' - ' . esc_html(get_the_title($attachment_id) ?: '(no title)') . '</li>';
        }

        echo '</ol></details>';
    }
}

new One_Time_WC_Product_Image_Purge();
