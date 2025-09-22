<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('rmh_productimg_register_debug_shortcode')) {
    /**
     * Register the debug shortcode for testing automatic product images.
     */
    function rmh_productimg_register_debug_shortcode(): void {
        add_shortcode('rmh_test_image', 'rmh_productimg_render_debug_shortcode');
    }
    add_action('init', 'rmh_productimg_register_debug_shortcode');
}

if (!function_exists('rmh_productimg_render_debug_shortcode')) {
    /**
     * Render output for the [rmh_test_image] shortcode.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     */
    function rmh_productimg_render_debug_shortcode($atts): string {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            $enabled = apply_filters('rmh_enable_debug_shortcodes', false);
            if (!$enabled) {
                return '<code>' . esc_html__('Debugshortcode uitgeschakeld.', 'printcom-order-tracker') . '</code>';
            }
        }

        $atts = shortcode_atts(
            [
                'invoice' => '',
                'index'   => '1',
            ],
            $atts,
            'rmh_test_image'
        );

        $invoice_raw = is_string($atts['invoice']) ? $atts['invoice'] : '';
        $index_raw   = is_string($atts['index']) || is_numeric($atts['index']) ? (string) $atts['index'] : '1';

        $invoice = rmh_productimg_normalize_invoice($invoice_raw);
        $line    = (int) $index_raw;
        if ($line < 1) {
            $line = 1;
        }

        if ($invoice === '') {
            return '<code>' . esc_html__('Geef een factuurnummer op via invoice="...".', 'printcom-order-tracker') . '</code>';
        }
        if (!function_exists('rmh_resolve_orderline_image')) {
            return '<code>' . esc_html__('Resolver niet beschikbaar.', 'printcom-order-tracker') . '</code>';
        }

        $result = rmh_resolve_orderline_image($invoice, $line);
        if (!$result) {
            $message = sprintf(
                esc_html__('Geen bestand gevonden. Controleer naam (%1$s-%2$d.{ext}), 1-based index, extensie, rechten of verhoog tijdelijk de cache-buster (optie rmh_img_cache_buster, admin-knop of RMH_IMG_CACHE_BUSTER).', 'printcom-order-tracker'),
                $invoice,
                $line
            );
            return '<code>' . $message . '</code>';
        }

        $output  = '<div class="rmh-productimg-debug">';
        $output .= '<p><strong>' . esc_html__('Bestandspad', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($result['path']) . '</code></p>';
        $output .= '<p><strong>' . esc_html__('URL', 'printcom-order-tracker') . ':</strong> <code>' . esc_html($result['url']) . '</code></p>';

        $img_attrs = [
            'src'      => esc_url($result['url']),
            'alt'      => sprintf(esc_attr__('Debugvoorbeeld voor %1$s regel %2$d', 'printcom-order-tracker'), $invoice, $line),
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

        $attr_pairs = [];
        foreach ($img_attrs as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $attr_pairs[] = sprintf('%s="%s"', $key, $value);
        }
        $output .= '<p><img ' . implode(' ', $attr_pairs) . ' /></p>';
        $output .= '</div>';

        return $output;
    }
}
