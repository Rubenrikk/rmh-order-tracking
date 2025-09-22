<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('rmh_productimg_debug_log')) {
    function rmh_productimg_debug_log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RMH productimg] ' . $message);
        }
    }
}

if (!function_exists('rmh_productimg_normalize_path')) {
    function rmh_productimg_normalize_path(string $path): string {
        $normalized = wp_normalize_path($path);
        if ($normalized === '') {
            return '';
        }
        return rtrim($normalized, '/') . '/';
    }
}

if (!function_exists('rmh_productimg_root_url')) {
    function rmh_productimg_root_url(): string {
        if (function_exists('home_url')) {
            return trailingslashit(home_url());
        }

        if (function_exists('site_url')) {
            return trailingslashit(site_url());
        }

        return '/';
    }
}

if (!function_exists('rmh_productimg_get_cache_buster_option')) {
    /**
     * Retrieve the cache-buster option used for product image caching.
     */
    function rmh_productimg_get_cache_buster_option(): string {
        if (!function_exists('get_option')) {
            return '';
        }

        $value = get_option('rmh_img_cache_buster', '');
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('rmh_productimg_get_cache_salt')) {
    /**
     * Combine the cache-buster sources (option and optional constant) into a salt string.
     */
    function rmh_productimg_get_cache_salt(): string {
        $parts = [];

        $option_value = rmh_productimg_get_cache_buster_option();
        if ($option_value !== '') {
            $parts[] = $option_value;
        }

        if (defined('RMH_IMG_CACHE_BUSTER') && RMH_IMG_CACHE_BUSTER !== '') {
            $parts[] = (string) RMH_IMG_CACHE_BUSTER;
        }

        return implode('|', $parts);
    }
}

if (!function_exists('rmh_productimg_set_last_debug')) {
    /**
     * Store debug context for the most recent product image lookup.
     *
     * @param array<string,mixed> $context Debug information to persist for later inspection.
     */
    function rmh_productimg_set_last_debug(array $context): void {
        $context['timestamp'] = microtime(true);
        $GLOBALS['rmh_productimg_last_debug'] = $context;
    }
}

if (!function_exists('rmh_productimg_get_last_debug')) {
    /**
     * Retrieve the debug context of the most recent product image lookup.
     *
     * @return array<string,mixed>
     */
    function rmh_productimg_get_last_debug(): array {
        $debug = $GLOBALS['rmh_productimg_last_debug'] ?? [];
        return is_array($debug) ? $debug : [];
    }
}

if (!function_exists('rmh_productimg_get_default_bases')) {
    function rmh_productimg_get_default_bases(): array {
        $bases = [];

        if (defined('ABSPATH')) {
            $path = rmh_productimg_normalize_path(trailingslashit(ABSPATH) . 'productimg');
            $bases[$path] = [
                'path'     => $path,
                'url_base' => trailingslashit(home_url()) . 'productimg/',
                'source'   => 'ABSPATH',
            ];
        }

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc_root = rmh_productimg_normalize_path(rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\'));
            if ($doc_root !== '') {
                $path = rmh_productimg_normalize_path($doc_root . '/productimg');
                $bases[$path] = [
                    'path'     => $path,
                    'url_base' => trailingslashit(home_url()) . 'productimg/',
                    'source'   => 'DOCUMENT_ROOT',
                ];
            }
        }

        if (!function_exists('get_home_path') && defined('ABSPATH')) {
            $file = trailingslashit(ABSPATH) . 'wp-admin/includes/file.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }

        if (function_exists('get_home_path')) {
            $home_path = get_home_path();
            if (is_string($home_path) && $home_path !== '') {
                $path = rmh_productimg_normalize_path(trailingslashit($home_path) . 'productimg');
                $bases[$path] = [
                    'path'     => $path,
                    'url_base' => trailingslashit(home_url()) . 'productimg/',
                    'source'   => 'HOME_PATH',
                ];
            }
        }

        return $bases;
    }
}

if (!function_exists('rmh_productimg_get_bases')) {
    function rmh_productimg_get_bases(): array {
        $defaults = rmh_productimg_get_default_bases();
        $default_paths = array_keys($defaults);

        $filtered_paths = apply_filters('rmh_productimg_bases', $default_paths);
        if (!is_array($filtered_paths)) {
            $filtered_paths = $default_paths;
        }

        $bases = [];
        foreach ($filtered_paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized = rmh_productimg_normalize_path($path);
            if ($normalized === '') {
                continue;
            }
            if (isset($bases[$normalized])) {
                continue;
            }
            if (isset($defaults[$normalized])) {
                $bases[$normalized] = $defaults[$normalized];
                continue;
            }
            $bases[$normalized] = [
                'path'     => $normalized,
                'url_base' => trailingslashit(home_url()) . 'productimg/',
                'source'   => 'FILTER',
            ];
        }

        return array_values($bases);
    }
}

if (!function_exists('rmh_productimg_get_extensions')) {
    function rmh_productimg_get_extensions(): array {
        $extensions = ['png', 'jpg', 'jpeg', 'webp'];
        $extensions = apply_filters('rmh_productimg_exts', $extensions);
        if (!is_array($extensions)) {
            $extensions = ['png', 'jpg', 'jpeg', 'webp'];
        }

        $normalized = [];
        foreach ($extensions as $ext) {
            if (!is_string($ext)) {
                continue;
            }
            $ext = strtolower(trim($ext));
            if ($ext === '') {
                continue;
            }
            $normalized[$ext] = true;
        }

        return array_keys($normalized);
    }
}

if (!function_exists('rmh_productimg_is_autoload_enabled')) {
    function rmh_productimg_is_autoload_enabled(): bool {
        $enabled = true;
        $enabled = apply_filters('rmh_productimg_enable_autoload', $enabled);
        return (bool) $enabled;
    }
}

if (!function_exists('rmh_productimg_is_legacy_disabled')) {
    function rmh_productimg_is_legacy_disabled(): bool {
        if (defined('RMH_DISABLE_LEGACY_IMAGES')) {
            return (bool) RMH_DISABLE_LEGACY_IMAGES;
        }
        return (bool) get_option('rmh_disable_legacy_images', 0);
    }
}

if (!function_exists('rmh_productimg_normalize_invoice')) {
    function rmh_productimg_normalize_invoice($invoiceNumber): string {
        $invoiceNumber = is_string($invoiceNumber) ? trim($invoiceNumber) : '';
        if ($invoiceNumber === '') {
            return '';
        }
        $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceNumber);
        if (!is_string($sanitized)) {
            return '';
        }
        return $sanitized;
    }
}

if (!defined('RMH_IMG_CACHE_TTL_HIT')) {
    define('RMH_IMG_CACHE_TTL_HIT', 15 * MINUTE_IN_SECONDS);
}

if (!defined('RMH_IMG_CACHE_TTL_MISS')) {
    define('RMH_IMG_CACHE_TTL_MISS', 5 * MINUTE_IN_SECONDS);
}

if (!function_exists('rmh_productimg_cache_key')) {
    function rmh_productimg_cache_key(string $invoice, int $lineIndex): string {
        $salt = rmh_productimg_get_cache_salt();
        $key  = strtolower($invoice) . '|' . $lineIndex . '|' . $salt;
        return 'rmh_prodimg_' . md5($key);
    }
}

if (!function_exists('rmh_resolve_orderline_image')) {
    /**
     * Resolve the automatic product image for an invoice + order line index.
     *
     * @param mixed $invoiceNumber Raw invoice identifier (will be normalised).
     * @param mixed $lineIndex     Order line index (1-based).
     *
     * @return array|null Result data (`url`, `path`, optionally `width`, `height`, `srcset`, `sizes`) or null when missing.
     */
    function rmh_resolve_orderline_image($invoiceNumber, $lineIndex): ?array {
        $raw_invoice = is_string($invoiceNumber) ? $invoiceNumber : '';
        $invoice     = rmh_productimg_normalize_invoice($invoiceNumber);
        $line        = (int) $lineIndex;

        if ($invoice === '' || $line < 1) {
            rmh_productimg_set_last_debug([
                'invoice'     => $invoice,
                'raw_invoice' => $raw_invoice,
                'line_index'  => $line,
                'found'       => false,
                'bases'       => [],
                'hit'         => null,
                'cache'       => [
                    'key'        => null,
                    'salt'       => rmh_productimg_get_cache_salt(),
                    'from_cache' => false,
                ],
                'skipped'     => 'invalid_input',
            ]);

            return null;
        }

        return rmh_productimg_find_image($invoice, $line, [
            'raw_invoice' => $raw_invoice,
        ]);
    }
}

if (!function_exists('rmh_productimg_collect_image_sizes')) {
    function rmh_productimg_collect_image_sizes(string $path): array {
        if (!is_readable($path)) {
            return [];
        }
        $size = @getimagesize($path);
        if (!is_array($size)) {
            return [];
        }
        if (!isset($size[0], $size[1])) {
            return [];
        }
        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }
}

if (!function_exists('rmh_productimg_find_image')) {
    /**
     * Locate an automatic product image based on the invoice and line index.
     *
     * @param array<string,mixed> $context Additional lookup context (e.g. raw invoice) for debug purposes.
     */
    function rmh_productimg_find_image(string $invoice, int $lineIndex, array $context = []): ?array {
        $raw_invoice = isset($context['raw_invoice']) && is_string($context['raw_invoice']) ? $context['raw_invoice'] : $invoice;

        $cache_key = rmh_productimg_cache_key($invoice, $lineIndex);
        $cache_salt = rmh_productimg_get_cache_salt();

        $debug = [
            'invoice'      => $invoice,
            'raw_invoice'  => $raw_invoice,
            'line_index'   => $lineIndex,
            'target'       => sprintf('%s-%d', $invoice, $lineIndex),
            'found'        => false,
            'bases'        => [],
            'last_base'    => null,
            'hit'          => null,
            'cache'        => [
                'key'        => $cache_key,
                'salt'       => $cache_salt,
                'from_cache' => false,
            ],
            'skipped'      => null,
            'legacy_allowed' => !rmh_productimg_is_legacy_disabled(),
        ];

        if ($invoice === '' || $lineIndex < 1) {
            $debug['skipped'] = 'invalid_arguments';
            rmh_productimg_set_last_debug($debug);
            return null;
        }

        if (!rmh_productimg_is_autoload_enabled()) {
            $debug['skipped'] = 'autoload_disabled';
            rmh_productimg_set_last_debug($debug);
            rmh_productimg_debug_log('Autoload uitgeschakeld via filter.');
            return null;
        }

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $debug['cache']['from_cache'] = true;
            $debug['cache']['value']      = $cached;

            if (!empty($cached['found']) && !empty($cached['data']) && is_array($cached['data'])) {
                $cached_data = $cached['data'];
                $debug['found'] = true;
                $debug['hit'] = [
                    'path' => $cached_data['path'] ?? '',
                    'url'  => $cached_data['url'] ?? '',
                    'base' => $cached_data['debug_base'] ?? null,
                ];
                $debug['last_base'] = $debug['hit']['base'];

                rmh_productimg_set_last_debug($debug);
                return $cached_data;
            }

            rmh_productimg_set_last_debug($debug);
            return null;
        }

        $bases = rmh_productimg_get_bases();
        $extensions = rmh_productimg_get_extensions();
        $target = $debug['target'];

        foreach ($bases as $base) {
            $base_path = $base['path'];
            $url_base  = $base['url_base'];
            $base_debug = [
                'path'       => $base_path,
                'url_base'   => $url_base,
                'source'     => $base['source'] ?? '',
                'candidates' => [],
            ];

            foreach ($extensions as $ext) {
                $candidates = array_unique([$ext, strtoupper($ext), ucfirst($ext)]);
                foreach ($candidates as $candidate_ext) {
                    $filename = $target . '.' . $candidate_ext;
                    $absolute = $base_path . $filename;
                    $exists   = is_readable($absolute) && is_file($absolute);

                    $base_debug['candidates'][] = [
                        'path'      => $absolute,
                        'extension' => $candidate_ext,
                        'exists'    => $exists,
                        'variant'   => '',
                        'hit'       => $exists,
                    ];

                    rmh_productimg_debug_log('Probeer ' . $absolute);
                    if (!$exists) {
                        continue;
                    }

                    $info = rmh_productimg_collect_image_sizes($absolute);
                    $variants = [
                        [
                            'url'    => $url_base . $filename,
                            'path'   => $absolute,
                            'width'  => $info['width'] ?? null,
                            'height' => $info['height'] ?? null,
                        ],
                    ];

                    $variant_suffixes = ['@2x', '-large'];
                    foreach ($variant_suffixes as $suffix) {
                        $variant_filename = $target . $suffix . '.' . $candidate_ext;
                        $variant_path     = $base_path . $variant_filename;
                        $variant_exists   = is_readable($variant_path) && is_file($variant_path);

                        $base_debug['candidates'][] = [
                            'path'      => $variant_path,
                            'extension' => $candidate_ext,
                            'exists'    => $variant_exists,
                            'variant'   => $suffix,
                            'hit'       => $variant_exists,
                        ];

                        rmh_productimg_debug_log('Zoek variant ' . $variant_path);
                        if (!$variant_exists) {
                            continue;
                        }

                        $variant_info = rmh_productimg_collect_image_sizes($variant_path);
                        $variants[] = [
                            'url'    => $url_base . $variant_filename,
                            'path'   => $variant_path,
                            'width'  => $variant_info['width'] ?? null,
                            'height' => $variant_info['height'] ?? null,
                        ];
                    }

                    $srcset_parts = [];
                    $max_width = 0;
                    foreach ($variants as $variant) {
                        if (!empty($variant['width'])) {
                            $descriptor = $variant['width'] . 'w';
                            $max_width = max($max_width, (int) $variant['width']);
                        } else {
                            $descriptor = '1x';
                        }
                        $srcset_parts[] = esc_url_raw($variant['url']) . ' ' . $descriptor;
                    }

                    $result = [
                        'url'        => $variants[0]['url'],
                        'path'       => $variants[0]['path'],
                        'width'      => $variants[0]['width'] ?? null,
                        'height'     => $variants[0]['height'] ?? null,
                        'debug_base' => [
                            'path'     => $base_path,
                            'url_base' => $url_base,
                            'source'   => $base['source'] ?? '',
                        ],
                    ];

                    if (count($variants) > 1) {
                        $result['srcset'] = implode(', ', $srcset_parts);
                        if ($max_width > 0) {
                            $result['sizes'] = sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $max_width);
                        } else {
                            $result['sizes'] = '100vw';
                        }
                    }

                    set_transient($cache_key, ['found' => true, 'data' => $result], RMH_IMG_CACHE_TTL_HIT);
                    rmh_productimg_debug_log('Gevonden: ' . $absolute);

                    $debug['bases'][] = $base_debug;
                    $debug['found'] = true;
                    $debug['hit'] = [
                        'path' => $result['path'],
                        'url'  => $result['url'],
                        'base' => $result['debug_base'],
                    ];
                    $debug['last_base'] = $result['debug_base'];
                    $debug['cache']['stored'] = 'hit';

                    rmh_productimg_set_last_debug($debug);

                    return $result;
                }
            }

            $debug['bases'][] = $base_debug;
            $debug['last_base'] = [
                'path'     => $base_path,
                'url_base' => $url_base,
                'source'   => $base['source'] ?? '',
            ];
        }

        set_transient($cache_key, ['found' => false], RMH_IMG_CACHE_TTL_MISS);
        rmh_productimg_debug_log('Geen afbeelding gevonden voor ' . $target);

        $debug['cache']['stored'] = 'miss';
        rmh_productimg_set_last_debug($debug);

        return null;
    }
}

if (!function_exists('rmh_render_orderline_image')) {
    function rmh_render_orderline_image($invoiceNumber, $lineIndex, array $args = []): string {
        $normalized_invoice = rmh_productimg_normalize_invoice($invoiceNumber);
        $line               = (int) $lineIndex;

        $defaults = [
            'legacy_html'      => '',
            'placeholder_html' => '',
        ];
        $args = array_merge($defaults, $args);

        $image = rmh_resolve_orderline_image($invoiceNumber, $line);

        $legacy_disabled = rmh_productimg_is_legacy_disabled();
        $debug_data = [];
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $debug_data = rmh_productimg_get_last_debug();
        }

        if ($image) {
            if (isset($image['debug_base'])) {
                unset($image['debug_base']);
            }

            $alt = sprintf(
                /* translators: 1: invoice number, 2: line index */
                __('Productafbeelding factuur %1$s – regel %2$d', 'printcom-order-tracker'),
                $normalized_invoice,
                $line
            );
            $attributes = [
                'src'      => $image['url'],
                'alt'      => $alt,
                'loading'  => 'lazy',
                'decoding' => 'async',
                'class'    => 'rmh-ot__image rmh-orderline-image__img',
            ];
            if (!empty($image['srcset'])) {
                $attributes['srcset'] = $image['srcset'];
            }
            if (!empty($image['sizes'])) {
                $attributes['sizes'] = $image['sizes'];
            }
            if (!empty($image['width'])) {
                $attributes['width'] = (int) $image['width'];
            }
            if (!empty($image['height'])) {
                $attributes['height'] = (int) $image['height'];
            }

            $parts = [];
            foreach ($attributes as $key => $value) {
                if (($value === '' || $value === null) && $key !== 'width' && $key !== 'height') {
                    continue;
                }
                if ($key === 'src') {
                    $value = esc_url($value);
                } elseif ($key === 'width' || $key === 'height') {
                    $value = esc_attr((string) $value);
                } else {
                    $value = esc_attr($value);
                }
                $parts[] = sprintf('%s="%s"', $key, $value);
            }
            $img_html = '<img ' . implode(' ', $parts) . ' />';
        } elseif (!$legacy_disabled && $args['legacy_html'] !== '') {
            $img_html = $args['legacy_html'];
        } else {
            $img_html = $args['placeholder_html'];
        }

        $figure = sprintf('<figure class="rmh-orderline-image">%s</figure>', $img_html);
        $figure = apply_filters('rmh_productimg_render_html', $figure, $normalized_invoice, $line, $args);

        $debug_comment = '';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $comment_parts = [
                'invoice=' . ($normalized_invoice !== '' ? $normalized_invoice : 'n/a'),
                'line=' . $line,
                'found=' . ($image ? 'yes' : 'no'),
            ];

            $matches_lookup = !empty($debug_data)
                && ($debug_data['invoice'] ?? null) === $normalized_invoice
                && (int) ($debug_data['line_index'] ?? 0) === $line;

            if ($matches_lookup) {
                $base_source = $debug_data['hit']['base']['source'] ?? $debug_data['last_base']['source'] ?? '';
                if ($base_source !== '') {
                    $comment_parts[] = 'base=' . $base_source;
                }

                $cache_state = '';
                if (!empty($debug_data['cache']['from_cache'])) {
                    $cache_state = 'hit';
                } elseif (!empty($debug_data['cache']['stored'])) {
                    $cache_state = (string) $debug_data['cache']['stored'];
                }
                if ($cache_state !== '') {
                    $comment_parts[] = 'cache=' . $cache_state;
                }

                if (!$image) {
                    if (!$legacy_disabled && $args['legacy_html'] !== '') {
                        $comment_parts[] = 'fallback=legacy';
                    } elseif ($args['placeholder_html'] !== '') {
                        $comment_parts[] = 'fallback=placeholder';
                    } else {
                        $comment_parts[] = 'fallback=none';
                    }

                    if (!empty($debug_data['skipped'])) {
                        $comment_parts[] = 'skipped=' . $debug_data['skipped'];
                    }
                }
            }

            $debug_comment = '<!-- RMH dbg ' . implode(' ', $comment_parts) . ' -->';
        }

        if ($debug_comment !== '') {
            $figure .= $debug_comment;
        }

        return $figure;
    }
}
