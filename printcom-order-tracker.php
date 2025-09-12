<?php
/**
 * Plugin Name: Print.com Order Tracker (Track & Trace Pagina's)
 * Description: Maakt per ordernummer automatisch een track & trace pagina aan en toont live orderstatus, items en verzendinformatie via de Print.com API. Tokens worden automatisch vernieuwd. Divi-vriendelijk.
 * Version:     1.0.0
 * Author:      RikkerMediaHub
 * License:     GNU GPLv2
 * Text Domain: printcom-order-tracker
 */

if (!defined('ABSPATH')) exit;

class Printcom_Order_Tracker {
    const OPT_SETTINGS     = 'printcom_ot_settings';       // array met API settings
    const OPT_MAPPINGS     = 'printcom_ot_mappings';       // orderNummer => page_id
    const TRANSIENT_TOKEN  = 'printcom_ot_token';          // access_token transient
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
        add_action('save_post', [$this, 'save_metabox']);

        // Enqueue stijl op frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

        // Veiligheidsfilter: nooit bedragen tonen (wij renderen ze simpelweg niet)
    }

    /* ===========================
     * Admin pagina's en settings
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

        add_settings_field('api_base_url', 'API Base URL', function() {
            $s = $this->get_settings();
            printf('<input type="url" name="%s[api_base_url]" value="%s" class="regular-text" placeholder="https://api.print.com"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['api_base_url'] ?? 'https://api.print.com'));
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('auth_url', 'Auth URL (token endpoint)', function() {
            $s = $this->get_settings();
            printf('<input type="url" name="%s[auth_url]" value="%s" class="regular-text" placeholder="https://auth.print.com/oauth/token"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['auth_url'] ?? ''));
            echo '<p class="description">Volledige URL van het token endpoint.</p>';
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('grant_type', 'Grant type', function() {
            $s = $this->get_settings();
            $val = $s['grant_type'] ?? 'client_credentials';
            ?>
            <select name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[grant_type]">
                <option value="client_credentials" <?php selected($val, 'client_credentials'); ?>>client_credentials</option>
                <option value="password" <?php selected($val, 'password'); ?>>password</option>
            </select>
            <p class="description">Kies de juiste flow. Gebruik <code>client_credentials</code> met Client ID/Secret, of <code>password</code> met Username/Password.</p>
            <?php
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('client_id', 'Client ID', function() {
            $s = $this->get_settings();
            printf('<input type="text" name="%s[client_id]" value="%s" class="regular-text" autocomplete="off"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['client_id'] ?? ''));
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('client_secret', 'Client Secret', function() {
            $s = $this->get_settings();
            printf('<input type="password" name="%s[client_secret]" value="%s" class="regular-text" autocomplete="new-password"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['client_secret'] ?? ''));
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('username', 'Username (password grant)', function() {
            $s = $this->get_settings();
            printf('<input type="text" name="%s[username]" value="%s" class="regular-text" autocomplete="off"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['username'] ?? ''));
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('password', 'Password (password grant)', function() {
            $s = $this->get_settings();
            printf('<input type="password" name="%s[password]" value="%s" class="regular-text" autocomplete="new-password"/>',
                esc_attr(self::OPT_SETTINGS), esc_attr($s['password'] ?? ''));
        }, self::OPT_SETTINGS, 'printcom_ot_section');

        add_settings_field('default_cache_ttl', 'Cache (minuten)', function() {
            $s = $this->get_settings();
            $ttl = isset($s['default_cache_ttl']) ? (int)$s['default_cache_ttl'] : 30;
            printf('<input type="number" min="0" step="1" name="%s[default_cache_ttl]" value="%d" class="small-text"/>',
                esc_attr(self::OPT_SETTINGS), $ttl);
            echo '<p class="description">API-responses cache in minuten (0 = uit). Aanbevolen 30.</p>';
        }, self::OPT_SETTINGS, 'printcom_ot_section');
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['api_base_url']      = isset($input['api_base_url']) ? trim(esc_url_raw($input['api_base_url'])) : 'https://api.print.com';
        $out['auth_url']          = isset($input['auth_url']) ? trim(esc_url_raw($input['auth_url'])) : '';
        $out['grant_type']        = in_array($input['grant_type'] ?? 'client_credentials', ['client_credentials','password'], true) ? $input['grant_type'] : 'client_credentials';
        $out['client_id']         = sanitize_text_field($input['client_id'] ?? '');
        $out['client_secret']     = sanitize_text_field($input['client_secret'] ?? '');
        $out['username']          = sanitize_text_field($input['username'] ?? '');
        $out['password']          = sanitize_text_field($input['password'] ?? '');
        $out['default_cache_ttl'] = max(0, (int)($input['default_cache_ttl'] ?? 30));
        return $out;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Print.com Orders — Instellingen</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPT_SETTINGS);
                do_settings_sections(self::OPT_SETTINGS);
                submit_button();
                ?>
            </form>
            <hr/>
            <p><strong>Let op:</strong> Zorg dat je de juiste Auth URL en grant type gebruikt. Als <code>expires_in</code> niet in de token-response zit, gebruikt de plugin standaard 7 dagen.</p>
        </div>
        <?php
    }

    public function orders_page() {
        if (!current_user_can('manage_options')) return;

        $message = '';
        if (!empty($_POST['printcom_ot_new_order']) && check_admin_referer('printcom_ot_new_order_action', 'printcom_ot_nonce')) {
            $orderNum = sanitize_text_field($_POST['printcom_ot_new_order']);
            if ($orderNum !== '') {
                $page_id = $this->create_or_update_page_for_order($orderNum);
                if ($page_id) {
                    $url = get_permalink($page_id);
                    $message = sprintf('Pagina voor order <strong>%s</strong> is aangemaakt/bijgewerkt: <a href="%s" target="_blank">%s</a>', esc_html($orderNum), esc_url($url), esc_html($url));
                } else {
                    $message = 'Er ging iets mis bij het aanmaken of bijwerken van de pagina.';
                }
            }
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
                    <thead>
                        <tr><th>Ordernummer</th><th>Pagina</th><th>Link</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mappings as $order => $pid): 
                        $link = get_permalink($pid);
                        ?>
                        <tr>
                            <td><?php echo esc_html($order); ?></td>
                            <td><?php echo esc_html(get_the_title($pid)); ?> (ID: <?php echo (int)$pid; ?>)</td>
                            <td><a href="<?php echo esc_url($link); ?>" target="_blank"><?php echo esc_html($link); ?></a></td>
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
            // Bijwerken: laat content minimaal de shortcode bevatten
            $page_id = (int)$mappings[$orderNum];
            $postarr = [
                'ID'           => $page_id,
                'post_title'   => $title,
            ];
            // Laat bestaande content ongemoeid als shortcode al aanwezig is
            $existing = get_post($page_id);
            if ($existing && strpos($existing->post_content, '[print_order_status') === false) {
                $postarr['post_content'] = $existing->post_content . "\n\n" . $content;
            }
            wp_update_post($postarr);
        } else {
            // Nieuwe pagina met shortcode
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

    /* ===========================
     * Shortcode rendering
     * =========================== */

    public function render_order_shortcode($atts = []) {
        $atts = shortcode_atts([
            'order' => '',
        ], $atts, 'print_order_status');

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
            $ttl_minutes = max(0, (int)($settings['default_cache_ttl'] ?? 30));
            if ($ttl_minutes > 0) {
                set_transient($cache_key, $data, $ttl_minutes * MINUTE_IN_SECONDS);
            }
        }

        // Bouw output
        $html  = '<div class="printcom-ot">';
        $html .= '<div class="printcom-ot__header"><h2>Status van uw bestelling</h2></div>';

        // Orderstatus (globaal)
        $statusLabel = $this->human_status($data);
        $html .= '<div class="printcom-ot__status"><strong>Status:</strong> ' . esc_html($statusLabel) . '</div>';

        // Eventuele order-brede track & trace? Meestal per shipment.
        // Toon per product + shipments
        $html .= '<div class="printcom-ot__items"><h3>Bestelde producten</h3>';

        // Custom afbeelding op paginaniveau (optioneel)
        $order_image_html = $this->get_order_page_image_html();
        if ($order_image_html) {
            $html .= '<div class="printcom-ot__order-image">' . $order_image_html . '</div>';
        }

        // Bouw index van verzonden items obv shipments
        $shipped_items = [];
        $tracks_by_item = []; // itemNumber => [trackUrl...]
        if (!empty($data['shipments']) && is_array($data['shipments'])) {
            foreach ($data['shipments'] as $shipment) {
                $itemNums = $shipment['orderItemNumbers'] ?? [];
                $tracks   = $shipment['tracks'] ?? [];
                $urls = [];
                foreach ($tracks as $t) {
                    if (!empty($t['trackUrl'])) $urls[] = $t['trackUrl'];
                }
                foreach ($itemNums as $inum) {
                    $shipped_items[$inum] = true;
                    if (!isset($tracks_by_item[$inum])) $tracks_by_item[$inum] = [];
                    $tracks_by_item[$inum] = array_merge($tracks_by_item[$inum], $urls);
                }
            }
        }

        // Doorloop items
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

                // Item-specifieke info (geen prijzen weergeven)
                // Toon Track & Trace links indien verzonden
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
        // Probeer een logische status af te leiden:
        // Als alle items verzonden zijn => Verzonden, anders In behandeling. Eventueel: Geannuleerd/Onbekend.
        $all = $data['items'] ?? [];
        $ships = $data['shipments'] ?? [];

        if (empty($all)) return 'Onbekend';

        // Bepaal welke item nummers er zijn
        $allItems = [];
        foreach ($all as $it) {
            if (!empty($it['orderItemNumber'])) $allItems[$it['orderItemNumber']] = false;
        }
        // Markeer verzonden
        if (!empty($ships)) {
            foreach ($ships as $s) {
                foreach (($s['orderItemNumbers'] ?? []) as $inum) {
                    if (isset($allItems[$inum])) $allItems[$inum] = true;
                }
            }
        }
        $allShipped = !empty($allItems) && count(array_filter($allItems)) === count($allItems);
        if ($allShipped) return 'Verzonden';

        // Als sommige wel, sommige niet:
        $anyShipped = count(array_filter($allItems)) > 0;
        if ($anyShipped) return 'Deels verzonden';

        // Eventueel veld data['status'] gebruiken als aanwezig
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
        // Toon alleen op pagina’s die door ons zijn aangemaakt (met shortcode)
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

        // Placeholder (eenvoudig inline SVG)
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
     * API client
     * =========================== */

    private function get_settings() {
        return get_option(self::OPT_SETTINGS, []);
    }

    private function api_get_order($orderNum) {
        $settings = $this->get_settings();
        $base     = rtrim($settings['api_base_url'] ?? 'https://api.print.com', '/');
        $url      = $base . '/orders/' . rawurlencode($orderNum);

        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code === 401) {
            // Token wellicht verlopen. Ververs en 1x opnieuw
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

        return $json;
    }

    private function get_access_token($force_refresh = false) {
        $cached = get_transient(self::TRANSIENT_TOKEN);
        if ($cached && !$force_refresh) {
            return $cached;
        }

        $settings = $this->get_settings();
        $auth_url = $settings['auth_url'] ?? '';
        $grant    = $settings['grant_type'] ?? 'client_credentials';
        if (empty($auth_url)) {
            return new WP_Error('printcom_auth_missing', 'Auth URL niet ingesteld.');
        }

        $body = ['grant_type' => $grant];
        $headers = ['Accept' => 'application/json'];

        if ($grant === 'client_credentials') {
            if (empty($settings['client_id']) || empty($settings['client_secret'])) {
                return new WP_Error('printcom_auth_missing', 'Client ID/Secret ontbreken.');
            }
            $body['client_id']     = $settings['client_id'];
            $body['client_secret'] = $settings['client_secret'];
        } else {
            if (empty($settings['username']) || empty($settings['password'])) {
                return new WP_Error('printcom_auth_missing', 'Username/Password ontbreken.');
            }
            // Sommige OAuth servers vereisen ook client_id/secret bij password grant. Voeg velden toe als aanwezig.
            if (!empty($settings['client_id']))     $body['client_id'] = $settings['client_id'];
            if (!empty($settings['client_secret'])) $body['client_secret'] = $settings['client_secret'];
            $body['username'] = $settings['username'];
            $body['password'] = $settings['password'];
        }

        $args = [
            'body'    => $body,
            'headers' => $headers,
            'timeout' => 20,
        ];

        $res = wp_remote_post($auth_url, $args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('printcom_auth_error', 'Auth fout (' . (int)$code . '). Controleer instellingen.');
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['access_token'])) {
            return new WP_Error('printcom_auth_error', 'Kon access_token niet vinden in auth-response.');
        }

        $token = $json['access_token'];
        $expires = isset($json['expires_in']) ? (int)$json['expires_in'] : (7 * DAY_IN_SECONDS); // fallback 7 dagen
        // Zet kleine veiligheidsmarge
        $ttl = max(60, $expires - 60);
        set_transient(self::TRANSIENT_TOKEN, $token, $ttl);
        return $token;
    }
}

new Printcom_Order_Tracker();