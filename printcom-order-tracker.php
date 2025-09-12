<?php
/**
 * Plugin Name: Print.com Order Tracker (Track & Trace Pagina's)
 * Description: Maakt per ordernummer automatisch een track & trace pagina aan en toont live orderstatus, items en verzendinformatie via de Print.com API. Tokens worden automatisch vernieuwd. Divi-vriendelijk.
 * Version:     1.5.2
 * Author:      RikkerMediaHub
 * License:     GNU GPLv2
 * Text Domain: printcom-order-tracker
 */

if (!defined('ABSPATH')) exit;

class Printcom_Order_Tracker {
    const OPT_SETTINGS     = 'printcom_ot_settings';       // array met API settings
    const OPT_MAPPINGS     = 'printcom_ot_mappings';       // orderNummer => page_id
    const OPT_STATE        = 'printcom_ot_state';          // orderNummer => ['status'=>..., 'complete_at'=>ts|null, 'last_seen'=>ts|null]
    const TRANSIENT_TOKEN  = 'printcom_ot_token';          // access_token/JWT transient
    const TRANSIENT_PREFIX = 'printcom_ot_cache_';         // cache per order
    const META_IMG_ID      = '_printcom_ot_image_id';      // attachment ID voor custom afbeelding

    public function __construct() {
        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Shortcode
        add_shortcode('print_order_status', [$this, 'render_order_shortcode']);

        // Metabox voor custom afbeelding
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post',       [$this, 'save_metabox']);

        // Frontend styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

        // Cron schedules & actions
        add_filter('cron_schedules', [$this, 'add_every5_schedule']);
        add_action('printcom_ot_cron_refresh_token', [$this, 'cron_refresh_token']);
        add_action('printcom_ot_cron_warm_cache',   [$this, 'cron_warm_cache']);

        // Force IPv4 (debug) op curl-transport (alleen api.print.com)
        add_action('http_api_curl', [$this, 'maybe_force_ipv4_for_printcom'], 10, 3);
    }

    /* ===========================
     * Activatie/Deactivatie (cron)
     * =========================== */

    public static function activate() {
        // Dagelijks token refresh 03:00 (server-tijd)
        if (!wp_next_scheduled('printcom_ot_cron_refresh_token')) {
            $t = strtotime('03:00:00');
            if ($t <= time()) $t = strtotime('tomorrow 03:00:00');
            wp_schedule_event($t, 'daily', 'printcom_ot_cron_refresh_token');
        }
        // Warm cache elke 5 minuten
        if (!wp_next_scheduled('printcom_ot_cron_warm_cache')) {
            wp_schedule_event(time() + 300, 'every5min', 'printcom_ot_cron_warm_cache');
        }
    }
    public static function deactivate() {
        if ($ts = wp_next_scheduled('printcom_ot_cron_refresh_token')) wp_unschedule_event($ts, 'printcom_ot_cron_refresh_token');
        if ($ts = wp_next_scheduled('printcom_ot_cron_warm_cache'))   wp_unschedule_event($ts, 'printcom_ot_cron_warm_cache');
    }
    public function add_every5_schedule($schedules) {
        $schedules['every5min'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
        return $schedules;
    }

    /* ===========================
     * Admin menu & instellingen
     * =========================== */

    public function admin_menu() {
        add_menu_page(
            'Print.com Orders',
            'Print.com Orders',
            'manage_options',
            'printcom-orders',
            [$this, 'orders_page'],
            'dashicons-location',
            56
        );

        add_submenu_page(
            'options-general.php',
            'Print.com Orders',
            'Print.com Orders',
            'manage_options',
            'printcom-orders-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPT_SETTINGS, self::OPT_SETTINGS, [$this, 'sanitize_settings']);

        add_settings_section('printcom_ot_section', 'API-instellingen', '__return_false', self::OPT_SETTINGS);

        $s = $this->get_settings();

        // Helpers
        $add_field = function($key, $label, $html, $desc = '') {
            add_settings_field(
                $key,
                $label,
                function() use ($html, $desc) {
                    echo $html;
                    if ($desc) echo '<p class="description">'.$desc.'</p>';
                },
                self::OPT_SETTINGS,
                'printcom_ot_section'
            );
        };

        $add_field('api_base_url', 'API Base URL',
            sprintf('<input type="url" name="%s[api_base_url]" value="%s" class="regular-text" placeholder="https://api.print.com"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['api_base_url'] ?? 'https://api.print.com'))
        );

        $add_field('auth_url', 'Auth URL (login endpoint)',
            sprintf('<input type="url" name="%s[auth_url]" value="%s" class="regular-text" placeholder="https://api.print.com/login"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['auth_url'] ?? 'https://api.print.com/login')),
            'Voor Print.com: <code>https://api.print.com/login</code> (JWT ±168 uur).'
        );

        ob_start(); ?>
            <select name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[grant_type]">
                <option value="password" <?php selected($s['grant_type'] ?? 'password', 'password'); ?>>password (Print.com /login)</option>
                <option value="client_credentials" <?php selected($s['grant_type'] ?? 'password', 'client_credentials'); ?>>client_credentials (fallback)</option>
            </select>
        <?php
        $add_field('grant_type', 'Grant type', ob_get_clean(), 'Gebruik <code>password</code> met jouw Username/Password voor <code>/login</code>.');

        $add_field('client_id', 'Client ID (optioneel)',
            sprintf('<input type="text" name="%s[client_id]" value="%s" class="regular-text" autocomplete="off"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['client_id'] ?? ''))
        );
        $add_field('client_secret', 'Client Secret (optioneel)',
            sprintf('<input type="password" name="%s[client_secret]" value="%s" class="regular-text" autocomplete="new-password"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['client_secret'] ?? ''))
        );

        $add_field('username', 'Username (Print.com login)',
            sprintf('<input type="text" name="%s[username]" value="%s" class="regular-text" autocomplete="off"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['username'] ?? ''))
        );
        $add_field('password', 'Password (Print.com login)',
            sprintf('<input type="password" name="%s[password]" value="%s" class="regular-text" autocomplete="new-password"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['password'] ?? ''))
        );

        $ttl = isset($s['default_cache_ttl']) ? (int)$s['default_cache_ttl'] : 5;
        $add_field('default_cache_ttl', 'Cache (minuten)',
            sprintf('<input type="number" min="0" step="1" name="%s[default_cache_ttl]" value="%d" class="small-text"/>',
                esc_attr(self::OPT_SETTINGS), $ttl),
            'Basis cache (0 = uit). Dynamische TTL: HOT = 5m, COLD = 24u.'
        );

        // Debug-opties
        $add_field('force_ipv4', 'Forceer IPv4 (debug)',
            sprintf('<label><input type="checkbox" name="%s[force_ipv4]" value="1" %s/> Alleen voor api.print.com</label>',
                esc_attr(self::OPT_SETTINGS), checked(!empty($s['force_ipv4']), true, false))
        );
        $add_field('alt_payload', 'Alternatieve payload (debug)',
            sprintf('<label><input type="checkbox" name="%s[alt_payload]" value="1" %s/> POST zonder <code>{"credentials": ...}</code> wrapper</label>',
                esc_attr(self::OPT_SETTINGS), checked(!empty($s['alt_payload']), true, false)),
            'Alleen gebruiken om te testen als /login op jouw account anders reageert.'
        );
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['api_base_url']      = isset($input['api_base_url']) ? trim(esc_url_raw($input['api_base_url'])) : 'https://api.print.com';
        $out['auth_url']          = isset($input['auth_url']) ? trim(esc_url_raw($input['auth_url'])) : 'https://api.print.com/login';
        $out['grant_type']        = in_array($input['grant_type'] ?? 'password', ['client_credentials','password'], true) ? $input['grant_type'] : 'password';
        $out['client_id']         = trim(sanitize_text_field($input['client_id'] ?? ''));
        $out['client_secret']     = trim(sanitize_text_field($input['client_secret'] ?? ''));
        $out['username']          = trim(sanitize_text_field($input['username'] ?? ''));
        $out['password']          = trim((string)($input['password'] ?? ''));
        $out['default_cache_ttl'] = max(0, (int)($input['default_cache_ttl'] ?? 5));
        $out['force_ipv4']        = !empty($input['force_ipv4']) ? 1 : 0;
        $out['alt_payload']       = !empty($input['alt_payload']) ? 1 : 0;
        return $out;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $dns_info = $this->resolve_dns_records(); ?>
        <div class="wrap">
            <h1>Print.com Orders — Instellingen</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT_SETTINGS); do_settings_sections(self::OPT_SETTINGS); submit_button(); ?>
            </form>

            <hr/>
            <p><strong>DNS check (api.print.com):</strong> <?php echo esc_html($dns_info); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_conn', 'printcom_ot_test_conn_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_connection"/>
                <button class="button button-secondary">Verbinding testen</button>
                <p class="description">Test login en toon response code/body.</p>
            </form>
            <?php if (!empty($_GET['printcom_test_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_result'])); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_order', 'printcom_ot_test_order_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_order"/>
                <input type="text" name="order" placeholder="Ordernummer (bijv. 6001831441)" required/>
                <button class="button">Test: haal order op</button>
                <p class="description">Test direct de /orders/{orderNumber} call met het huidige token.</p>
            </form>
            <?php if (!empty($_GET['printcom_test_order_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_order_result'])); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_show_ip','printcom_ot_show_ip_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_show_server_ip"/>
                <button class="button">Toon server uitgaand IP</button>
            </form>
            <?php if (!empty($_GET['printcom_server_ip'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><strong>Server IP:</strong> <?php echo esc_html($_GET['printcom_server_ip']); ?></p></div>
            <?php endif; ?>

            <p><strong>Tip:</strong> Gebruik <code>https://api.print.com/login</code> met grant type <code>password</code> en je klantlogin. Token wordt 7 dagen bewaard en dagelijks ververst.</p>
        </div>
        <?php
    }

    private function resolve_dns_records(): string {
        $out = [];
        foreach (['A','AAAA'] as $t) {
            $recs = function_exists('dns_get_record') ? @dns_get_record('api.print.com', constant('DNS_'.$t)) : [];
            if ($recs) {
                $ips = array_map(function($r) use($t){ return $t==='A' ? $r['ip'] : $r['ipv6']; }, $recs);
                $out[] = $t.': '.implode(', ', $ips);
            }
        }
        return $out ? implode(' | ', $out) : 'Kan DNS niet ophalen (serverblok/disabled).';
    }

    public function orders_page() {
        if (!current_user_can('manage_options')) return;

        // Auto-opschonen: verwijder mappings waarvan de pagina niet meer bestaat
        $mappings = get_option(self::OPT_MAPPINGS, []);
        $changed = false;
        foreach ($mappings as $order => $pid) {
            if (!$pid || !get_post_status((int)$pid)) {
                unset($mappings[$order]);
                delete_transient(self::TRANSIENT_PREFIX . md5($order));
                $state = get_option(self::OPT_STATE, []);
                if (isset($state[$order])) { unset($state[$order]); update_option(self::OPT_STATE, $state, false); }
                $changed = true;
            }
        }
        if ($changed) update_option(self::OPT_MAPPINGS, $mappings, false);

        $message = '';
        // Nieuwe/bijwerken
        if (!empty($_POST['printcom_ot_new_order']) && check_admin_referer('printcom_ot_new_order_action', 'printcom_ot_nonce')) {
            $orderNum = sanitize_text_field($_POST['printcom_ot_new_order']);
            if ($orderNum !== '') {
                $page_id = $this->create_or_update_page_for_order($orderNum);
                if ($page_id) {
                    $url = get_permalink($page_id);
                    $message = sprintf('Pagina voor order <strong>%s</strong> is aangemaakt/bijgewerkt: <a href="%s" target="_blank" rel="noopener">%s</a>', esc_html($orderNum), esc_url($url), esc_html($url));
                } else {
                    $message = 'Er ging iets mis bij het aanmaken of bijwerken van de pagina.';
                }
            }
        }
        if (!empty($_GET['printcom_deleted_order'])) {
            $od = sanitize_text_field(wp_unslash($_GET['printcom_deleted_order']));
            $message = 'Order <strong>' . esc_html($od) . '</strong> is uit de lijst verwijderd.';
        }

        $mappings = get_option(self::OPT_MAPPINGS, []);
        ?>
        <div class="wrap">
            <h1>Print.com Orders</h1>
            <?php if ($message): ?>
                <div class="notice notice-success"><p><?php echo wp_kses_post($message); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('printcom_ot_new_order_action', 'printcom_ot_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="printcom_ot_new_order">Ordernummer</label></th>
                        <td>
                            <input type="text" id="printcom_ot_new_order" name="printcom_ot_new_order" class="regular-text" placeholder="bijv. 6001831441" required/>
                            <p class="description">Voer een ordernummer in en klik op “Pagina aanmaken/bijwerken”.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Pagina aanmaken/bijwerken'); ?>
            </form>

            <h2>Bestaande orderpagina’s</h2>
            <?php if (!empty($mappings)): ?>
                <table class="widefat striped">
                    <thead><tr><th>Ordernummer</th><th>Pagina</th><th>Link</th><th>Acties</th></tr></thead>
                    <tbody>
                    <?php foreach ($mappings as $order => $pid):
                        $link = get_permalink($pid);
                        $title = get_the_title($pid);
                        $delete_url = wp_nonce_url(
                            admin_url('admin-post.php?action=printcom_ot_delete_order&order=' . rawurlencode($order)),
                            'printcom_ot_delete_order_'.$order
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html($order); ?></td>
                            <td><?php echo esc_html($title); ?> (ID: <?php echo (int)$pid; ?>)</td>
                            <td><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($link); ?></a></td>
                            <td><a class="button button-link-delete" href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Verwijder deze order uit de lijst? De pagina zelf blijft ongewijzigd.');">Verwijder uit lijst</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Nog geen orderpagina’s aangemaakt.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function create_or_update_page_for_order($orderNum) {
        $mappings = get_option(self::OPT_MAPPINGS, []);
        $title    = 'Bestelling ' . $orderNum;
        $shortcode = sprintf('[print_order_status order="%s"]', esc_attr($orderNum));
        $content  = $shortcode;

        if (isset($mappings[$orderNum]) && get_post_status((int)$mappings[$orderNum])) {
            $page_id = (int)$mappings[$orderNum];
            $postarr = ['ID' => $page_id, 'post_title' => $title];
            $existing = get_post($page_id);
            if ($existing && strpos($existing->post_content, '[print_order_status') === false) {
                $postarr['post_content'] = $existing->post_content . "\n\n" . $content;
            }
            wp_update_post($postarr);
        } else {
            $page_id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => sanitize_title($title),
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => get_current_user_id(),
            ]);
            if (is_wp_error($page_id) || !$page_id) return false;
            $mappings[$orderNum] = (int)$page_id;
            update_option(self::OPT_MAPPINGS, $mappings, false);
        }
        return (int)$page_id;
    }

    private function remove_order_mapping(string $orderNum, bool $also_delete_page = false): bool {
        $mappings = get_option(self::OPT_MAPPINGS, []);
        if (!isset($mappings[$orderNum])) return false;

        $page_id = (int) $mappings[$orderNum];
        if ($also_delete_page && $page_id && get_post_status($page_id)) {
            wp_delete_post($page_id, true);
        }
        unset($mappings[$orderNum]);
        update_option(self::OPT_MAPPINGS, $mappings, false);

        delete_transient(self::TRANSIENT_PREFIX . md5($orderNum));

        $state = get_option(self::OPT_STATE, []);
        if (isset($state[$orderNum])) {
            unset($state[$orderNum]);
            update_option(self::OPT_STATE, $state, false);
        }
        return true;
    }

    /* ===========================
     * Shortcode rendering
     * =========================== */

    public function render_order_shortcode($atts = []) {
        $atts = shortcode_atts(['order' => ''], $atts, 'print_order_status');
        $orderNum = trim($atts['order']);
        if ($orderNum === '') {
            return '<div class="printcom-ot printcom-ot--error">Geen ordernummer opgegeven.</div>';
        }

        $settings = $this->get_settings();
        if (empty($settings['api_base_url'])) {
            return '<div class="printcom-ot printcom-ot--error">API niet geconfigureerd. Vraag de beheerder om de instellingen in te vullen.</div>';
        }

        // Cache per order
        $cache_key = self::TRANSIENT_PREFIX . md5($orderNum);
        $data = get_transient($cache_key);
        if (!$data) {
            $data = $this->api_get_order($orderNum);
            if (is_wp_error($data)) {
                return '<div class="printcom-ot printcom-ot--error">Kon ordergegevens niet ophalen. ' . esc_html($data->get_error_message()) . '</div>';
            }
            // Dynamische TTL op basis van status
            set_transient($cache_key, $data, $this->dynamic_cache_ttl_for($data));
        }

        // Markeer recent bekeken (prioriteit)
        $st = get_option(self::OPT_STATE, []);
        $e = $st[$orderNum] ?? ['status'=>null,'complete_at'=>null,'last_seen'=>null];
        $e['last_seen'] = time();
        $st[$orderNum] = $e;
        update_option(self::OPT_STATE, $st, false);

        $html  = '<div class="printcom-ot">';
        $html .= '<div class="printcom-ot__header"><h2>Status van uw bestelling</h2></div>';

        $statusLabel = $this->human_status($data);
        $html .= '<div class="printcom-ot__status"><strong>Status:</strong> ' . esc_html($statusLabel) . '</div>';

        $html .= '<div class="printcom-ot__items"><h3>Bestelde producten</h3>';

        $order_image_html = $this->get_order_page_image_html();
        if ($order_image_html) {
            $html .= '<div class="printcom-ot__order-image">' . $order_image_html . '</div>';
        }

        // bepaal verzonden items en tracklinks
        $shipped_items = [];
        $tracks_by_item = [];
        if (!empty($data['shipments']) && is_array($data['shipments'])) {
            foreach ($data['shipments'] as $shipment) {
                $itemNums = $shipment['orderItemNumbers'] ?? [];
                $urls = [];
                foreach (($shipment['tracks'] ?? []) as $t) {
                    if (!empty($t['trackUrl'])) $urls[] = $t['trackUrl'];
                }
                foreach ($itemNums as $inum) {
                    $shipped_items[$inum] = true;
                    if (!isset($tracks_by_item[$inum])) $tracks_by_item[$inum] = [];
                    $tracks_by_item[$inum] = array_merge($tracks_by_item[$inum], $urls);
                }
            }
        }

        $html .= '<ul class="printcom-ot__list">';
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $itemNum = $item['orderItemNumber'] ?? '';
                $name    = $item['productName'] ?? ($item['productSku'] ?? 'Product');
                $isShipped = isset($shipped_items[$itemNum]);

                $html .= '<li class="printcom-ot__item">';
                $html .= '<div class="printcom-ot__item-head">';
                $html .= '<span class="printcom-ot__item-name">'. esc_html($name) .'</span>';
                $html .= $isShipped
                    ? '<span class="printcom-ot__badge printcom-ot__badge--ok">Verzonden</span>'
                    : '<span class="printcom-ot__badge printcom-ot__badge--pending">In behandeling</span>';
                $html .= '</div>';

                if ($isShipped) {
                    $urls = $tracks_by_item[$itemNum] ?? [];
                    if (!empty($urls)) {
                        $html .= '<div class="printcom-ot__tracks"><strong>Track & Trace:</strong> ';
                        $links = [];
                        foreach ($urls as $u) {
                            $links[] = '<a href="'. esc_url($u) .'" target="_blank" rel="nofollow noopener">Volg zending</a>';
                        }
                        $html .= implode(' &middot; ', $links);
                        $html .= '</div>';
                    } else {
                        $html .= '<div class="printcom-ot__tracks"><em>Track & Trace volgt spoedig.</em></div>';
                    }
                }

                $html .= '</li>';
            }
        } else {
            $html .= '<li>Er zijn nog geen producten geregistreerd voor deze bestelling.</li>';
        }
        $html .= '</ul>';

        $html .= '</div>'; // .items
        $html .= '</div>'; // .printcom-ot

        return $html;
    }

    private function human_status(array $data) {
        $all = $data['items'] ?? [];
        $ships = $data['shipments'] ?? [];

        if (empty($all)) return 'Onbekend';

        $allItems = [];
        foreach ($all as $it) {
            if (!empty($it['orderItemNumber'])) $allItems[$it['orderItemNumber']] = false;
        }
        if (!empty($ships)) {
            foreach ($ships as $s) {
                foreach (($s['orderItemNumbers'] ?? []) as $inum) {
                    if (isset($allItems[$inum])) $allItems[$inum] = true;
                }
            }
        }
        $allShipped = !empty($allItems) && count(array_filter($allItems)) === count($allItems);
        if ($allShipped) return 'Verzonden';

        $anyShipped = count(array_filter($allItems)) > 0;
        if ($anyShipped) return 'Deels verzonden';

        if (!empty($data['status'])) {
            $s = strtolower($data['status']);
            if ($s === 'cancelled' || $s === 'canceled') return 'Geannuleerd';
            if ($s === 'processing') return 'In behandeling';
            if ($s === 'shipped') return 'Verzonden';
            if ($s === 'created') return 'Aangemaakt';
        }

        return 'In behandeling';
    }

    /* ===========================
     * Metabox: eigen afbeelding
     * =========================== */

    public function add_metabox() {
        add_meta_box(
            'printcom_ot_image',
            'Orderafbeelding (optioneel)',
            [$this, 'metabox_html'],
            'page',
            'side',
            'default'
        );
    }

    public function metabox_html($post) {
        if (strpos($post->post_content, '[print_order_status') === false) {
            echo '<p>Deze pagina lijkt geen Print.com orderpagina te zijn.</p>';
            return;
        }

        wp_enqueue_media();
        $attachment_id = (int)get_post_meta($post->ID, self::META_IMG_ID, true);
        $img_src = $attachment_id ? wp_get_attachment_image_src($attachment_id, 'medium') : null;

        ?>
        <div>
            <p>Gebruik dit om een eigen afbeelding te tonen op de orderpagina. Handig wanneer de API geen ontwerpafbeelding levert.</p>
            <div id="printcom-ot-image-preview" style="margin-bottom:10px;">
                <?php
                if ($img_src) {
                    echo '<img src="'. esc_url($img_src[0]) .'" style="max-width:100%;height:auto;" />';
                } else {
                    echo '<em>Geen afbeelding gekozen.</em>';
                }
                ?>
            </div>
            <input type="hidden" id="printcom-ot-image-id" name="printcom_ot_image_id" value="<?php echo esc_attr($attachment_id); ?>"/>
            <button type="button" class="button" id="printcom-ot-image-upload">Afbeelding kiezen</button>
            <button type="button" class="button" id="printcom-ot-image-remove" <?php disabled(!$attachment_id); ?>>Verwijderen</button>
        </div>
        <script>
        (function($){
            $(function(){
                let frame;
                $('#printcom-ot-image-upload').on('click', function(e){
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: 'Kies of upload afbeelding',
                        button: { text: 'Gebruik deze afbeelding' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        const attachment = frame.state().get('selection').first().toJSON();
                        $('#printcom-ot-image-id').val(attachment.id);
                        $('#printcom-ot-image-preview').html('<img src="'+attachment.url+'" style="max-width:100%;height:auto;" />');
                        $('#printcom-ot-image-remove').prop('disabled', false);
                    });
                    frame.open();
                });
                $('#printcom-ot-image-remove').on('click', function(e){
                    e.preventDefault();
                    $('#printcom-ot-image-id').val('');
                    $('#printcom-ot-image-preview').html('<em>Geen afbeelding gekozen.</em>');
                    $(this).prop('disabled', true);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function save_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['printcom_ot_image_id'])) {
            $id = (int)$_POST['printcom_ot_image_id'];
            if ($id > 0) {
                update_post_meta($post_id, self::META_IMG_ID, $id);
            } else {
                delete_post_meta($post_id, self::META_IMG_ID);
            }
        }
    }

    private function get_order_page_image_html() {
        if (!is_singular('page')) return '';
        $id = get_the_ID();
        $attachment_id = (int)get_post_meta($id, self::META_IMG_ID, true);

        if ($attachment_id) {
            return wp_get_attachment_image($attachment_id, 'large', false, ['class' => 'printcom-ot__image']);
        }

        // Placeholder (inline SVG)
        $svg = '<svg class="printcom-ot__image" role="img" aria-label="Afbeelding volgt" xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630"><rect width="100%" height="100%" fill="#f2f2f2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#999" font-family="Arial,Helvetica,sans-serif" font-size="32">Afbeelding volgt</text></svg>';
        return $svg;
    }

    /* ===========================
     * Styles
     * =========================== */

    public function enqueue_styles() {
        $css = '
        .printcom-ot {border:1px solid #eee; padding:24px; border-radius:12px;}
        .printcom-ot__header h2 {margin:0 0 8px; font-size:1.5rem;}
        .printcom-ot__status {margin:8px 0 20px;}
        .printcom-ot__order-image {margin:12px 0 20px;}
        .printcom-ot__image {width:100%; height:auto; display:block; border-radius:8px}
        .printcom-ot__items h3 {margin-top:0;}
        .printcom-ot__list {list-style:none; margin:0; padding:0;}
        .printcom-ot__item {padding:14px 0; border-bottom:1px solid #f0f0f0;}
        .printcom-ot__item:last-child {border-bottom:none;}
        .printcom-ot__item-head {display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;}
        .printcom-ot__item-name {font-weight:600;}
        .printcom-ot__badge {display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; background:#ddd; color:#333;}
        .printcom-ot__badge--ok {background:#e6f7ed; color:#1f7a3e;}
        .printcom-ot__badge--pending {background:#fff7e6; color:#8a5300;}
        .printcom-ot__tracks {margin-top:8px;}
        .printcom-ot--error {border:1px solid #f5c2c7; background:#f8d7da; padding:12px; border-radius:8px; color:#842029;}
        ';
        wp_register_style('printcom-ot-style', false);
        wp_enqueue_style('printcom-ot-style');
        wp_add_inline_style('printcom-ot-style', $css);
    }

    /* ===========================
     * HTTP helpers
     * =========================== */

    private function get_settings(){ return get_option(self::OPT_SETTINGS, []); }

    public function maybe_force_ipv4_for_printcom($handle, $r, $url){
        $s = $this->get_settings();
        if (!empty($s['force_ipv4']) && is_string($url) && stripos($url, 'https://api.print.com') === 0) {
            if (defined('CURLOPT_IPRESOLVE')) {
                curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
        }
    }

    /* ===========================
     * API client + state/TTL
     * =========================== */

    private function api_get_order($orderNum) {
        $s = $this->get_settings();
        $base = rtrim($s['api_base_url'] ?? 'https://api.print.com', '/');
        $url  = $base . '/orders/' . rawurlencode($orderNum);

        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'RMH-Printcom-Tracker/1.5.1 (+WordPress)',
            ],
            'timeout' => 20,
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code === 401) {
            // Token verlopen? Ververs en 1x opnieuw
            delete_transient(self::TRANSIENT_TOKEN);
            $token = $this->get_access_token(true);
            if (is_wp_error($token)) return $token;
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) return $res;
            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('printcom_api_error', 'API fout (' . (int)$code . ').');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return new WP_Error('printcom_api_error', 'Ongeldig API-antwoord.');
        }

        // State bijwerken
        $this->update_order_state($orderNum, $json);

        return $json;
    }

    private function get_access_token($force_refresh = false) {
        $cached = get_transient(self::TRANSIENT_TOKEN);
        if ($cached && !$force_refresh) return $cached;

        $s = $this->get_settings();
        $auth_url = trim($s['auth_url'] ?? '');
        if (!$auth_url) return new WP_Error('printcom_auth_missing', 'Auth URL niet ingesteld.');

        // Trimmen voorkomt onzichtbare witruimtes in UI
        $username = isset($s['username']) ? trim($s['username']) : '';
        $password = isset($s['password']) ? (string)$s['password'] : '';
        $password = preg_replace("/\r\n|\r|\n/", "", $password); // linebreaks verwijderen

        $ua = 'RMH-Printcom-Tracker/1.5.1 (+WordPress)';

        // Print.com /login: POST JSON {"credentials":{"username","password"}} -> JWT
        $is_print_login = (stripos($auth_url, '/login') !== false);
        if ($is_print_login) {
            if ($username==='' || $password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');

            $payload = !empty($s['alt_payload'])
                ? wp_json_encode(['username' => $username, 'password' => $password]) // DEBUG-alternatief
                : wp_json_encode(['credentials' => ['username' => $username, 'password' => $password]]); // volgens docs

            $args = [
                'headers' => ['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>$ua],
                'body'    => $payload,
                'timeout' => 20,
            ];
            $res  = wp_remote_post($auth_url, $args);
            if (is_wp_error($res)) return $res;

            $code = wp_remote_retrieve_response_code($res);
            $raw  = wp_remote_retrieve_body($res);

            if ($code === 401) {
                $err = 'Auth fout (401). Controleer login & URL.';
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    if (!empty($json['message'])) $err .= ' Detail: ' . sanitize_text_field($json['message']);
                    if (!empty($json['error']))   $err .= ' (' . sanitize_text_field($json['error']) . ')';
                } elseif (!empty($raw)) {
                    $err .= ' Detail: ' . sanitize_text_field($raw);
                }
                return new WP_Error('printcom_auth_error', $err);
            }
            if ($code < 200 || $code >= 300) {
                return new WP_Error('printcom_auth_error', 'Auth fout (' . (int)$code . '). Raw: ' . sanitize_text_field($raw));
            }

            $json = json_decode($raw, true);
            $token = null;
            if (is_array($json)) {
                $token = $json['access_token'] ?? $json['token'] ?? $json['jwt'] ?? null;
            }
            if (!$token && is_string($raw) && strlen($raw) > 20 && strpos($raw, '{') === false) {
                $token = trim($raw); // fallback: raw JWT tekst
            }
            if (!$token) {
                return new WP_Error('printcom_auth_error', 'Kon JWT niet vinden in login-response. Raw: ' . sanitize_text_field($raw));
            }

            // Normaliseer: verwijder eventueel "Bearer " voorvoegsel
            $token = preg_replace('/^\s*Bearer\s+/i', '', (string)$token);

            set_transient(self::TRANSIENT_TOKEN, $token, max(60, (7 * DAY_IN_SECONDS) - 60));
            return $token;
        }

        // Fallback: generieke OAuth (client_credentials/password met form-encoded)
        $grant = $s['grant_type'] ?? 'client_credentials';
        $body  = ['grant_type' => $grant];
        if ($grant === 'client_credentials') {
            if (empty($s['client_id']) || empty($s['client_secret'])) {
                return new WP_Error('printcom_auth_missing', 'Client ID/Secret ontbreken.');
            }
            $body['client_id']     = $s['client_id'];
            $body['client_secret'] = $s['client_secret'];
        } else { // password grant
            if ($username==='' || $password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');
            if (!empty($s['client_id']))     $body['client_id'] = $s['client_id'];
            if (!empty($s['client_secret'])) $body['client_secret'] = $s['client_secret'];
            $body['username'] = $username;
            $body['password'] = $password;
        }

        $args = [
            'body'    => $body,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => $ua],
            'timeout' => 20,
        ];
        $res = wp_remote_post($auth_url, $args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);

        if ($code === 401) {
            $err = 'Auth fout (401). Controleer OAuth en credentials.';
            $json = json_decode($raw, true);
            if (is_array($json)) {
                if (!empty($json['error_description'])) $err .= ' ' . sanitize_text_field($json['error_description']);
                if (!empty($json['error'])) $err .= ' (' . sanitize_text_field($json['error']) . ')';
            } elseif (!empty($raw)) {
                $err .= ' Detail: ' . sanitize_text_field($raw);
            }
            return new WP_Error('printcom_auth_error', $err);
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('printcom_auth_error', 'Auth fout (' . (int)$code . '). Raw: ' . sanitize_text_field($raw));
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return new WP_Error('printcom_auth_error', 'Ongeldige auth-response.');
        }

        $token   = $json['access_token'] ?? null;
        $expires = isset($json['expires_in']) ? (int)$json['expires_in'] : (7 * DAY_IN_SECONDS);
        if (!$token) {
            return new WP_Error('printcom_auth_error', 'Kon access_token niet vinden in auth-response. Raw: ' . sanitize_text_field($raw));
        }

        // Normaliseer: verwijder eventueel "Bearer " voorvoegsel
        $token = preg_replace('/^\s*Bearer\s+/i', '', (string)$token);

        set_transient(self::TRANSIENT_TOKEN, $token, max(60, $expires - 60));
        return $token;
    }

    /* ===========================
     * State/Dynamic TTL helpers
     * =========================== */

    private function is_order_complete(array $data): bool {
        $all = $data['items'] ?? [];
        $ships = $data['shipments'] ?? [];
        if (empty($all)) return false;

        $shipped = [];
        foreach ($all as $it) {
            if (!empty($it['orderItemNumber'])) $shipped[$it['orderItemNumber']] = false;
        }
        foreach ($ships as $s) {
            foreach (($s['orderItemNumbers'] ?? []) as $inum) {
                if (isset($shipped[$inum])) $shipped[$inum] = true;
            }
        }
        return !empty($shipped) && count(array_filter($shipped)) === count($shipped);
    }

    private function update_order_state(string $orderNum, array $data): void {
        $state = get_option(self::OPT_STATE, []);
        $now = time();
        $complete = $this->is_order_complete($data);
        $status = isset($data['status']) ? strtolower((string)$data['status']) : ($complete ? 'shipped' : 'processing');
        $entry = $state[$orderNum] ?? ['status'=>null,'complete_at'=>null,'last_seen'=>null];
        $entry['status'] = $status;
        if ($complete && empty($entry['complete_at'])) $entry['complete_at'] = $now;
        if (!$complete) $entry['complete_at'] = null; // terugzetten indien verandering
        $state[$orderNum] = $entry;
        update_option(self::OPT_STATE, $state, false);
    }

    private function dynamic_cache_ttl_for(array $data): int {
        if ($this->is_order_complete($data)) {
            return DAY_IN_SECONDS; // COLD
        }
        return 5 * MINUTE_IN_SECONDS; // HOT
    }

    /* ===========================
     * Cron taken
     * =========================== */

    public function cron_refresh_token() {
        delete_transient(self::TRANSIENT_TOKEN);
        $token = $this->get_access_token(true);
        if (is_wp_error($token)) {
            error_log('[Printcom OT] Token verversen mislukt: ' . $token->get_error_message());
        } else {
            error_log('[Printcom OT] Token succesvol ververst.');
        }
    }

    public function cron_warm_cache() {
        $state    = get_option(self::OPT_STATE, []);
        $mappings = get_option(self::OPT_MAPPINGS, []);
        if (empty($mappings)) return;

        // Parameters
        $hot_limit    = 50;              // max HOT orders per run
        $cold_limit   = 20;              // max COLD orders per run
        $archive_days = 14;              // na 14 dagen complete -> overslaan
        $now          = time();

        $orders = [];
        foreach ($mappings as $orderNum => $pid) {
            $entry = $state[$orderNum] ?? null;
            $complete_at = $entry['complete_at'] ?? null;
            $status = $entry['status'] ?? null;

            // ARCHIVE: compleet én ouder dan X dagen -> overslaan
            if ($complete_at && ($now - (int)$complete_at) > ($archive_days * DAY_IN_SECONDS)) {
                continue;
            }

            // Classificeer
            $isComplete = ($complete_at !== null) || ($status === 'shipped' || $status === 'completed');
            $orders[] = [
                'order'     => $orderNum,
                'type'      => $isComplete ? 'COLD' : 'HOT',
                'last_seen' => $entry['last_seen'] ?? 0,
            ];
        }

        if (empty($orders)) return;

        // Shuffle + sorteer HOT recent-first
        shuffle($orders);
        $hot  = array_values(array_filter($orders, fn($o) => $o['type']==='HOT'));
        $cold = array_values(array_filter($orders, fn($o) => $o['type']==='COLD'));
        usort($hot, function($a,$b){ return ($b['last_seen'] <=> $a['last_seen']); });

        $processed_hot = 0;
        foreach ($hot as $o) {
            if ($processed_hot >= $hot_limit) break;
            $data = $this->api_get_order($o['order']);
            if (is_wp_error($data)) {
                error_log('[Printcom OT] HOT warm fout '.$o['order'].': '.$data->get_error_message());
                continue;
            }
            $cache_key = self::TRANSIENT_PREFIX . md5($o['order']);
            set_transient($cache_key, $data, $this->dynamic_cache_ttl_for($data));
            $processed_hot++;
        }

        $processed_cold = 0;
        foreach ($cold as $o) {
            if ($processed_cold >= $cold_limit) break;
            $data = $this->api_get_order($o['order']);
            if (is_wp_error($data)) {
                error_log('[Printcom OT] COLD warm fout '.$o['order'].': '.$data->get_error_message());
                continue;
            }
            $cache_key = self::TRANSIENT_PREFIX . md5($o['order']);
            set_transient($cache_key, $data, $this->dynamic_cache_ttl_for($data));
            $processed_cold++;
        }

        error_log('[Printcom OT] Warmed HOT: '.$processed_hot.'; COLD: '.$processed_cold.'.');
    }
}

/* ===========================
 * Hooks activeren/deactiveren
 * =========================== */
register_activation_hook(__FILE__, ['Printcom_Order_Tracker', 'activate']);
register_deactivation_hook(__FILE__, ['Printcom_Order_Tracker', 'deactivate']);

/* ===========================
 * Admin-post handlers (debug & acties)
 * =========================== */

// Verbinding testen (/login)
add_action('admin_post_printcom_ot_test_connection', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    if (empty($_POST['printcom_ot_test_conn_nonce']) || !wp_verify_nonce($_POST['printcom_ot_test_conn_nonce'],'printcom_ot_test_conn')) wp_die('Nonce invalid');

    $s = get_option(Printcom_Order_Tracker::OPT_SETTINGS, []);
    $auth_url = $s['auth_url'] ?? '';
    $username = isset($s['username']) ? trim($s['username']) : '';
    $password = isset($s['password']) ? (string)$s['password'] : '';
    $password = preg_replace("/\r\n|\r|\n/", "", $password);
    $ua = 'RMH-Printcom-Tracker/1.5.1 (+WordPress)';

    if (!$auth_url || !$username || !$password) {
        $msg = '❌ Ontbrekende instellingen (auth_url/username/password).';
    } else {
        $payload = !empty($s['alt_payload'])
            ? wp_json_encode(['username'=>$username,'password'=>$password])
            : wp_json_encode(['credentials'=>['username'=>$username,'password'=>$password]]);
        $args = [
            'headers' => ['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>$ua],
            'body'    => $payload,
            'timeout' => 20,
        ];
        if (!empty($s['force_ipv4'])) add_action('http_api_curl','printcom_ot_force_v4_once',10,3);
        function printcom_ot_force_v4_once($handle,$r,$url){ if (defined('CURLOPT_IPRESOLVE')) curl_setopt($handle,CURLOPT_IPRESOLVE,CURL_IPRESOLVE_V4); remove_action('http_api_curl','printcom_ot_force_v4_once',10); }

        $res = wp_remote_post($auth_url,$args);
        if (is_wp_error($res)) {
            $msg = '❌ Verbindingsfout (transport): ' . esc_html($res->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($res);
            $raw  = wp_remote_retrieve_body($res);
            if ($code >= 200 && $code < 300) {
                $msg = '✅ OK ('.$code.'). Body lengte: '.strlen($raw).'.';
            } else {
                $msg = '❌ Auth fout ('.$code.'). '.(!empty($raw)?'Body: '.sanitize_text_field($raw):'Geen body.');
            }
        }
    }
    $dest = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    wp_safe_redirect(add_query_arg('printcom_test_result', rawurlencode($msg), $dest));
    exit;
});

// Test: /orders/{orderNumber}
add_action('admin_post_printcom_ot_test_order', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    if (empty($_POST['printcom_ot_test_order_nonce']) || !wp_verify_nonce($_POST['printcom_ot_test_order_nonce'], 'printcom_ot_test_order')) wp_die('Nonce invalid');

    $order = isset($_POST['order']) ? sanitize_text_field(wp_unslash($_POST['order'])) : '';
    if ($order === '') $order = 'DEMO';

    $plugin = new Printcom_Order_Tracker();

    // Forceer vers token (debug)
    delete_transient(Printcom_Order_Tracker::TRANSIENT_TOKEN);
    try {
        $ref = new ReflectionClass($plugin);
        $m   = $ref->getMethod('get_access_token'); $m->setAccessible(true);
        $token = $m->invoke($plugin, true);
    } catch (Throwable $e) {
        $token = new WP_Error('exception', $e->getMessage());
    }

    if (is_wp_error($token)) {
        $msg = '❌ Tokenfout: ' . esc_html($token->get_error_message());
    } else {
        // Laat token-shape zien (veilig: alleen eerste 16 tekens)
        $prefix = substr($token, 0, 16);
        $len    = strlen($token);

        $s = get_option(Printcom_Order_Tracker::OPT_SETTINGS, []);
        $base = rtrim($s['api_base_url'] ?? 'https://api.print.com', '/');
        $url  = $base.'/orders/'.rawurlencode($order);
        $args = [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'RMH-Printcom-Tracker/1.5.1 (+WordPress)',
            ],
            'timeout' => 20,
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) {
            $msg = '❌ Transportfout: ' . esc_html($res->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($res);
            $raw  = wp_remote_retrieve_body($res);
            $body_preview = $raw ? mb_substr($raw, 0, 260) : '';
            $msg = '🔎 Token: len='.$len.', starts="'.esc_html($prefix).'" | ';
            if ($code >= 200 && $code < 300) {
                $msg .= '✅ Order OK ('.$code.'). Body ~'.strlen($raw).' bytes.';
            } else {
                $msg .= '❌ Order fout ('.$code.'). '.($body_preview ? 'Body: '.sanitize_text_field($body_preview) : 'Geen body.');
            }
        }
    }
    $dest = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    wp_safe_redirect(add_query_arg('printcom_test_order_result', rawurlencode($msg), $dest));
    exit;
});

// Toon server uitgaand IP
add_action('admin_post_printcom_ot_show_server_ip', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    if (empty($_POST['printcom_ot_show_ip_nonce']) || !wp_verify_nonce($_POST['printcom_ot_show_ip_nonce'],'printcom_ot_show_ip')) wp_die('Nonce invalid');
    $res = wp_remote_get('https://api64.ipify.org?format=text', ['timeout' => 10]);
    $ip  = is_wp_error($res) ? $res->get_error_message() : trim(wp_remote_retrieve_body($res));
    $dest = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    wp_safe_redirect(add_query_arg('printcom_server_ip', rawurlencode($ip), $dest));
    exit;
});

// Verwijder mapping (order uit lijst)
add_action('admin_post_printcom_ot_delete_order', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';
    if ($order === '') wp_die('Order ontbreekt.');

    $nonce_key = 'printcom_ot_delete_order_'.$order;
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce_key)) {
        wp_die('Nonce invalid');
    }

    $plugin = new Printcom_Order_Tracker();
    try {
        $ref = new ReflectionClass($plugin);
        $m   = $ref->getMethod('remove_order_mapping'); $m->setAccessible(true);
        $ok  = $m->invoke($plugin, $order, false); // false = pagina behouden
    } catch (Throwable $e) {
        $ok = false;
    }

    $dest = admin_url('admin.php?page=printcom-orders');
    if ($ok) {
        $dest = add_query_arg('printcom_deleted_order', rawurlencode($order), $dest);
    }
    wp_safe_redirect($dest);
    exit;
});

// Start plugin
new Printcom_Order_Tracker();