<?php
if (!defined('ABSPATH')) {
    exit;
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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $result = rmh_resolve_orderline_image($invoice, $index_input);
                if (!$result) {
                    $error_message = sprintf(
                        esc_html__('Geen bestand gevonden voor %1$s-%2$d. Controleer naam, extensie, rechten en cache-buster (RMH_IMG_CACHE_BUSTER).', 'printcom-order-tracker'),
                        $invoice,
                        $index_input
                    );
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('RMH Productimg Test', 'printcom-order-tracker') . '</h1>';
        echo '<p>' . esc_html__('Test de automatische productafbeelding op basis van factuurnummer en 1-based regelindex. Bestanden moeten in /productimg/ staan en heten {factuurnummer}-{index}.{ext}.', 'printcom-order-tracker') . '</p>';

        echo '<form method="post">';
        wp_nonce_field('rmh_productimg_test_action', 'rmh_productimg_test_nonce');
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

        if ($error_message !== '') {
            echo '<div class="notice notice-warning"><p>' . $error_message . '</p></div>';
        } elseif ($result) {
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

        echo '</div>';
    }
}
