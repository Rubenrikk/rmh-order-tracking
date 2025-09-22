<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RMH_Productimg_CLI_Command')) {
    /**
     * WP-CLI helpers for RMH automatic product images.
     */
    class RMH_Productimg_CLI_Command {
        /**
         * Test de automatische productafbeeldingsresolver voor een factuur en regelindex.
         *
         * ## OPTIONS
         *
         * <invoice>
         * : Het factuurnummer (bijv. RMH-0021).
         *
         * [<index>]
         * : De 1-based regelindex (default: 1).
         *
         * ## EXAMPLES
         *
         *     wp rmh img-test RMH-0021 1
         *
         * @when after_wp_load
         */
        public function __invoke(array $args, array $assoc_args): void {
            if (!class_exists('WP_CLI')) {
                return;
            }

            $invoice_input = $args[0] ?? '';
            if (!is_string($invoice_input) || $invoice_input === '') {
                WP_CLI::error('Factuurnummer is verplicht.');
            }

            $index_arg = $args[1] ?? '1';
            $index     = is_numeric($index_arg) ? (int) $index_arg : 1;
            if ($index < 1) {
                WP_CLI::error('Index moet minimaal 1 zijn.');
            }

            $invoice = rmh_productimg_normalize_invoice($invoice_input);
            if ($invoice === '') {
                WP_CLI::error('Factuurnummer ongeldig na normalisatie. Controleer het invoerformaat.');
            }

            if (!function_exists('rmh_resolve_orderline_image')) {
                WP_CLI::error('Resolver niet beschikbaar.');
            }

            $result = rmh_resolve_orderline_image($invoice, $index);
            if ($result) {
                WP_CLI::log('FS: ' . $result['path']);
                WP_CLI::log('URL: ' . $result['url']);
                WP_CLI::success('Afbeelding gevonden.');
                return;
            }

            WP_CLI::warning('Geen bestand gevonden. Controleer naam, extensie, rechten of verhoog tijdelijk de cache-buster (optie rmh_img_cache_buster, admin-knop of constante RMH_IMG_CACHE_BUSTER).');
            WP_CLI::halt(1);
        }
    }
}

if (class_exists('WP_CLI')) {
    WP_CLI::add_command('rmh img-test', 'RMH_Productimg_CLI_Command');
}
