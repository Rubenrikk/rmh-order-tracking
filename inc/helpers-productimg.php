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
        $candidates = [home_url(), site_url()];
        foreach ($candidates as $candidate) {
            $parts = wp_parse_url($candidate);
            if (!is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $scheme = $parts['scheme'] ?? (is_ssl() ? 'https' : 'http');
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
            return trailingslashit($scheme . '://' . $parts['host'] . $port);
        }

        return trailingslashit(home_url());
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
            ];
        }

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc_root = rmh_productimg_normalize_path(rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\'));
            if ($doc_root !== '') {
                $path = rmh_productimg_normalize_path($doc_root . '/productimg');
                $bases[$path] = [
                    'path'     => $path,
                    'url_base' => rmh_productimg_root_url() . 'productimg/',
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
        $salt = defined('RMH_IMG_CACHE_BUSTER') ? RMH_IMG_CACHE_BUSTER : '';
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
        $invoice = rmh_productimg_normalize_invoice($invoiceNumber);
        $line    = (int) $lineIndex;

        if ($invoice === '' || $line < 1) {
            return null;
        }

        return rmh_productimg_find_image($invoice, $line);
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
    function rmh_productimg_find_image(string $invoice, int $lineIndex): ?array {
        if ($invoice === '' || $lineIndex < 1) {
            return null;
        }
        if (!rmh_productimg_is_autoload_enabled()) {
            rmh_productimg_debug_log('Autoload uitgeschakeld via filter.');
            return null;
        }

        $cache_key = rmh_productimg_cache_key($invoice, $lineIndex);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            if (!empty($cached['found'])) {
                return $cached['data'];
            }
            return null;
        }

        $bases = rmh_productimg_get_bases();
        $extensions = rmh_productimg_get_extensions();
        $target = sprintf('%s-%d', $invoice, $lineIndex);

        foreach ($bases as $base) {
            $base_path = $base['path'];
            $url_base  = $base['url_base'];

            foreach ($extensions as $ext) {
                $candidates = array_unique([$ext, strtoupper($ext), ucfirst($ext)]);
                foreach ($candidates as $candidate_ext) {
                    $filename = $target . '.' . $candidate_ext;
                    $absolute = $base_path . $filename;
                    rmh_productimg_debug_log('Probeer ' . $absolute);
                    if (!is_readable($absolute) || !is_file($absolute)) {
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
                        $variant_path = $base_path . $variant_filename;
                        rmh_productimg_debug_log('Zoek variant ' . $variant_path);
                        if (!is_readable($variant_path) || !is_file($variant_path)) {
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
                        'url'    => $variants[0]['url'],
                        'path'   => $variants[0]['path'],
                        'width'  => $variants[0]['width'] ?? null,
                        'height' => $variants[0]['height'] ?? null,
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

                    return $result;
                }
            }
        }

        set_transient($cache_key, ['found' => false], RMH_IMG_CACHE_TTL_MISS);
        rmh_productimg_debug_log('Geen afbeelding gevonden voor ' . $target);

        return null;
    }
}

if (!function_exists('rmh_render_orderline_image')) {
    function rmh_render_orderline_image($invoiceNumber, $lineIndex, array $args = []): string {
        $invoice = rmh_productimg_normalize_invoice($invoiceNumber);
        $line    = (int) $lineIndex;

        $defaults = [
            'legacy_html'      => '',
            'placeholder_html' => '',
        ];
        $args = array_merge($defaults, $args);

        $image = null;
        if ($invoice !== '' && $line >= 1) {
            $image = rmh_resolve_orderline_image($invoice, $line);
        }

        if ($image) {
            $alt = sprintf(
                /* translators: 1: invoice number, 2: line index */
                __('Productafbeelding factuur %1$s – regel %2$d', 'printcom-order-tracker'),
                $invoice,
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
        } elseif (!rmh_productimg_is_legacy_disabled() && $args['legacy_html'] !== '') {
            $img_html = $args['legacy_html'];
        } else {
            $img_html = $args['placeholder_html'];
        }

        $figure = sprintf('<figure class="rmh-orderline-image">%s</figure>', $img_html);

        return apply_filters('rmh_productimg_render_html', $figure, $invoice, $line, $args);
    }
}
