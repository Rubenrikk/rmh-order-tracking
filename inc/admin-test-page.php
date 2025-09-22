<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!is_admin()) {
    return;
}

if (function_exists('current_user_can') && !current_user_can('manage_options')) {
    return;
}

if (!function_exists('rmh_productimg_register_admin_test_page')) {
    add_action('admin_menu', 'rmh_productimg_register_admin_test_page');

    /**
     * Register the Productimg test page under the Settings menu.
     */
    function rmh_productimg_register_admin_test_page(): void {
        add_options_page(
            __('RMH Productimg Test', 'printcom-order-tracker'),
            __('RMH Productimg Test', 'printcom-order-tracker'),
            'manage_options',
            'rmh-productimg-test',
            'rmh_productimg_render_admin_test_page'
        );
    }
}

if (!function_exists('rmh_productimg_render_admin_test_page')) {
    /**
     * Render the admin test page for the automatic product image resolver.
     */
    function rmh_productimg_render_admin_test_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen rechten om deze pagina te bekijken.', 'printcom-order-tracker'));
        }

        $invoice_input = '';
        $invoice       = '';
        $index_input   = 1;
        $result        = null;
        $error_message = '';
        $messages      = [];
        $debug_details = [];

        $cache_buster_value = rmh_productimg_get_cache_buster_option();
        $cache_salt         = rmh_productimg_get_cache_salt();

        $action = 'test';
        if (!empty($_SERVER['REQUEST_METHOD'])) {
            $action = isset($_POST['rmh_productimg_action']) ? sanitize_text_field(wp_unslash((string) $_POST['rmh_productimg_action'])) : 'test';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($action === 'bump_cache') {
                check_admin_referer('rmh_productimg_bump_cache_action', 'rmh_productimg_bump_cache_nonce');

                $current_value = rmh_productimg_get_cache_buster_option();
                $current_int   = is_numeric($current_value) ? (int) $current_value : 0;
                $next_int      = $current_int + 1;
                update_option('rmh_img_cache_buster', (string) $next_int);

                $cache_buster_value = rmh_productimg_get_cache_buster_option();
                $cache_salt         = rmh_productimg_get_cache_salt();
                $messages[] = sprintf(
                    __('Cache buster verhoogd naar %s.', 'printcom-order-tracker'),
                    $cache_buster_value !== '' ? $cache_buster_value : __('(leeg)', 'printcom-order-tracker')
                );
            } else {
                check_admin_referer('rmh_productimg_test_action', 'rmh_productimg_test_nonce');

                if (isset($_POST['rmh_productimg_invoice'])) {
                    $invoice_input = sanitize_text_field(wp_unslash((string) $_POST['rmh_productimg_invoice']));
                    $invoice       = rmh_productimg_normalize_invoice($invoice_input);
                }

                if (isset($_POST['rmh_productimg_index'])) {
                    $index_input = (int) wp_unslash((string) $_POST['rmh_productimg_index']);
                    if ($index_input < 1) {
                        $index_input = 1;
                    }
                }

                if ($invoice === '') {
                    $error_message = esc_html__('Factuurnummer is verplicht. Gebruik het exacte nummer uit Invoice Ninja.', 'printcom-order-tracker');
                } elseif (!function_exists('rmh_resolve_orderline_image')) {
                    $error_message = esc_html__('Resolver niet beschikbaar.', 'printcom-order-tracker');
                } else {
                    $result        = rmh_resolve_orderline_image($invoice_input, $index_input);
                    $debug_details = rmh_productimg_get_last_debug();

                    if (!$result) {
                        $error_message = sprintf(
                            esc_html__('Geen bestand gevonden voor %1$s-%2$d. Controleer naam, extensie, rechten en de cache-buster (knop hieronder of optie rmh_img_cache_buster).', 'printcom-order-tracker'),
                            $invoice,
                            $index_input
                        );
                    }
                }
            }
        }

        $minute = (defined('MINUTE_IN_SECONDS') && MINUTE_IN_SECONDS > 0) ? (int) MINUTE_IN_SECONDS : 60;
        $ttl_hit = defined('RMH_IMG_CACHE_TTL_HIT') ? (int) RMH_IMG_CACHE_TTL_HIT : 15 * $minute;
        $ttl_miss = defined('RMH_IMG_CACHE_TTL_MISS') ? (int) RMH_IMG_CACHE_TTL_MISS : 5 * $minute;
        $ttl_hit_minutes = $minute > 0 ? round($ttl_hit / $minute, 1) : $ttl_hit;
        $ttl_miss_minutes = $minute > 0 ? round($ttl_miss / $minute, 1) : $ttl_miss;

        $docroot_example = null;
        foreach (rmh_productimg_get_default_bases() as $base) {
            if (($base['source'] ?? '') !== 'DOCUMENT_ROOT') {
                continue;
            }
            $docroot_example = [
                'path' => $base['path'] . 'RMH-0021-3.png',
                'url'  => $base['url_base'] . 'RMH-0021-3.png',
            ];
            break;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('RMH Productimg Test', 'printcom-order-tracker') . '</h1>';
        echo '<p>' . esc_html__('Test de automatische productafbeelding op basis van factuurnummer en 1-based regelindex. Bestanden moeten in /productimg/ staan en heten {factuurnummer}-{index}.{ext}.', 'printcom-order-tracker') . '</p>';

        echo '<form method="post" class="rmh-productimg-test-form">';
        wp_nonce_field('rmh_productimg_test_action', 'rmh_productimg_test_nonce');
        echo '<input type="hidden" name="rmh_productimg_action" value="test" />';
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="rmh_productimg_invoice">' . esc_html__('Factuurnummer', 'printcom-order-tracker') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="rmh_productimg_invoice" name="rmh_productimg_invoice" value="' . esc_attr($invoice_input) . '" required /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="rmh_productimg_index">' . esc_html__('Regelindex (1-based)', 'printcom-order-tracker') . '</label></th>';
        echo '<td><input type="number" min="1" class="small-text" id="rmh_productimg_index" name="rmh_productimg_index" value="' . esc_attr((string) $index_input) . '" required /></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        submit_button(esc_html__('Test resolver', 'printcom-order-tracker'));
        echo '</form>';

        echo '<div class="rmh-productimg-cache-tools">';
        echo '<h2>' . esc_html__('Diagnose & cache', 'printcom-order-tracker') . '</h2>';
        echo '<p><strong>' . esc_html__('Cache-buster waarde', 'printcom-order-tracker') . ':</strong> <code>' . ($cache_buster_value !== '' ? esc_html($cache_buster_value) : esc_html__('(leeg)', 'printcom-order-tracker')) . '</code></p>';
        echo '<p><strong>' . esc_html__('Cache-salt', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($cache_salt !== '' ? $cache_salt : __('(leeg)', 'printcom-order-tracker')) . '</code></p>';
        echo '<p><strong>' . esc_html__('TTL hit', 'printcom-order-tracker') . ':</strong> ' . esc_html($ttl_hit) . 's (~' . esc_html($ttl_hit_minutes) . 'm)</p>';
        echo '<p><strong>' . esc_html__('TTL miss', 'printcom-order-tracker') . ':</strong> ' . esc_html($ttl_miss) . 's (~' . esc_html($ttl_miss_minutes) . 'm)</p>';
        echo '<form method="post" class="rmh-productimg-cache-bump">';
        wp_nonce_field('rmh_productimg_bump_cache_action', 'rmh_productimg_bump_cache_nonce');
        echo '<input type="hidden" name="rmh_productimg_action" value="bump_cache" />';
        submit_button(esc_html__('Cache buster verhogen', 'printcom-order-tracker'), 'secondary', 'rmh_productimg_bump_cache', false);
        echo '</form>';
        echo '</div>';

        foreach ($messages as $message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }

        if ($error_message !== '') {
            echo '<div class="notice notice-warning"><p>' . esc_html($error_message) . '</p></div>';
        }

        if ($result) {
            echo '<h2>' . esc_html__('Resultaat', 'printcom-order-tracker') . '</h2>';
            echo '<p><strong>' . esc_html__('Bestandspad', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($result['path']) . '</code></p>';
            echo '<p><strong>' . esc_html__('URL', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($result['url']) . '</code></p>';

            $img_attrs = [
                'src'      => esc_url($result['url']),
                'alt'      => sprintf(esc_attr__('Testafbeelding voor %1$s regel %2$d', 'printcom-order-tracker'), $invoice ?: $invoice_input, $index_input),
                'loading'  => 'lazy',
                'decoding' => 'async',
                'style'    => 'max-width:100%;height:auto;display:block;margin-top:8px;',
            ];
            if (!empty($result['srcset'])) {
                $img_attrs['srcset'] = esc_attr($result['srcset']);
            }
            if (!empty($result['sizes'])) {
                $img_attrs['sizes'] = esc_attr($result['sizes']);
            }
            if (!empty($result['width'])) {
                $img_attrs['width'] = (int) $result['width'];
            }
            if (!empty($result['height'])) {
                $img_attrs['height'] = (int) $result['height'];
            }

            $pairs = [];
            foreach ($img_attrs as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $pairs[] = sprintf('%s="%s"', $key, $value);
            }
            echo '<p><img ' . implode(' ', $pairs) . ' /></p>';
        }

        if (!empty($debug_details)) {
            echo '<h2>' . esc_html__('Diagnoserapport', 'printcom-order-tracker') . '</h2>';
            echo '<p><strong>' . esc_html__('Invoer', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($debug_details['raw_invoice'] ?? $invoice_input) . '</code> → <code>' . esc_html($debug_details['target'] ?? '') . '</code></p>';
            echo '<p><strong>' . esc_html__('Legacy fallback toegestaan', 'printcom-order-tracker') . ':</strong> ' . (!empty($debug_details['legacy_allowed']) ? esc_html__('ja', 'printcom-order-tracker') : esc_html__('nee', 'printcom-order-tracker')) . '</p>';
            if (!empty($debug_details['hit']['path'])) {
                echo '<p><strong>' . esc_html__('Hit', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($debug_details['hit']['path']) . '</code> → <code>' . esc_html($debug_details['hit']['url']) . '</code></p>';
            } else {
                echo '<p><strong>' . esc_html__('Hit', 'printcom-order-tracker') . ':</strong> ' . esc_html__('geen, fallback actief', 'printcom-order-tracker') . '</p>';
            }
            if (!empty($debug_details['skipped'])) {
                echo '<p><strong>' . esc_html__('Skips', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($debug_details['skipped']) . '</code></p>';
            }

            echo '<p><strong>' . esc_html__('Cache-key', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($debug_details['cache']['key'] ?? '') . '</code> (';
            if (!empty($debug_details['cache']['from_cache'])) {
                echo esc_html__('uit cache', 'printcom-order-tracker');
            } elseif (!empty($debug_details['cache']['stored'])) {
                $stored_state = (string) $debug_details['cache']['stored'];
                echo esc_html(sprintf(__('nieuw: %s', 'printcom-order-tracker'), $stored_state));
            } else {
                echo esc_html__('lookup', 'printcom-order-tracker');
            }
            echo ')</p>';

            if (!empty($debug_details['bases'])) {
                echo '<div class="rmh-productimg-base-details">';
                foreach ($debug_details['bases'] as $base) {
                    $source_label = $base['source'] ?? '';
                    if (!is_string($source_label) || $source_label === '') {
                        $source_label = esc_html__('custom', 'printcom-order-tracker');
                    }
                    $summary = sprintf(
                        __('Basis %1$s — %2$s', 'printcom-order-tracker'),
                        $source_label,
                        $base['path'] ?? ''
                    );
                    echo '<details>';
                    echo '<summary>' . esc_html($summary) . '</summary>';
                    echo '<p><strong>' . esc_html__('Pad', 'printcom-order-tracker') . ':</strong> <code>' . esc_html((string) ($base['path'] ?? '')) . '</code></p>';
                    echo '<p><strong>' . esc_html__('URL-base', 'printcom-order-tracker') . ':</strong> <code>' . esc_html((string) ($base['url_base'] ?? '')) . '</code></p>';
                    if (!empty($base['candidates'])) {
                        echo '<ul>';
                        foreach ($base['candidates'] as $candidate) {
                            $label = (string) ($candidate['path'] ?? '');
                            $status_text = !empty($candidate['hit']) ? __('gevonden', 'printcom-order-tracker') : __('niet gevonden', 'printcom-order-tracker');
                            if (!empty($candidate['variant'])) {
                                $variant_text = sprintf(__('variant %s', 'printcom-order-tracker'), (string) $candidate['variant']);
                                $status_text .= ' (' . $variant_text . ')';
                            }
                            echo '<li><code>' . esc_html($label) . '</code> – ' . esc_html($status_text) . '</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</details>';
                }
                echo '</div>';
            }
        }

        if ($docroot_example) {
            echo '<h2>' . esc_html__('Self-check DOCROOT', 'printcom-order-tracker') . '</h2>';
            echo '<p><strong>' . esc_html__('Voorbeeldpad', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($docroot_example['path']) . '</code></p>';
            echo '<p><strong>' . esc_html__('Voorbeeld-URL', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($docroot_example['url']) . '</code></p>';
        }

        echo '</div>';
    }
}
