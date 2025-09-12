<?php
/**
 * Plugin Name: Print.com Order Tracker (Track & Trace Pagina's)
 * Description: Maakt per ordernummer automatisch een track & trace pagina aan en toont live orderstatus, items en verzendinformatie via de Print.com API. Tokens worden automatisch vernieuwd. Divi-vriendelijk.
 * Version:     1.5.0
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
        add_action('save_post', [$this, 'save_metabox']);

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
        if (!wp_next_scheduled('printcom_ot_cron_refresh_token')) {
            $t = strtotime('03:00:00'); if ($t <= time()) $t = strtotime('tomorrow 03:00:00');
            wp_schedule_event($t, 'daily', 'printcom_ot_cron_refresh_token');
        }
        if (!wp_next_scheduled('printcom_ot_cron_warm_cache')) {
            wp_schedule_event(time() + 300, 'every5min', 'printcom_ot_cron_warm_cache');
        }
    }
    public static function deactivate() {
        if ($ts = wp_next_scheduled('printcom_ot_cron_refresh_token')) wp_unschedule_event($ts, 'printcom_ot_cron_refresh_token');
        if ($ts2 = wp_next_scheduled('printcom_ot_cron_warm_cache')) wp_unschedule_event($ts2, 'printcom_ot_cron_warm_cache');
    }
    public function add_every5_schedule($schedules) {
        $schedules['every5min'] = ['interval'=>300,'display'=>'Every 5 Minutes']; return $schedules;
    }

    /* ===========================
     * Admin menu & instellingen
     * =========================== */

    public function admin_menu() {
        add_menu_page('Print.com Orders','Print.com Orders','manage_options','printcom-orders',[$this,'orders_page'],'dashicons-location',56);
        add_submenu_page('options-general.php','Print.com Orders','Print.com Orders','manage_options','printcom-orders-settings',[$this,'settings_page']);
    }

    public function register_settings() {
        register_setting(self::OPT_SETTINGS, self::OPT_SETTINGS, [$this, 'sanitize_settings']);
        add_settings_section('printcom_ot_section', 'API-instellingen', '__return_false', self::OPT_SETTINGS);

        $f = function($k,$label,$html,$desc=''){
            add_settings_field($k,$label,function() use($html,$desc){ echo $html; if($desc) echo '<p class="description">'.$desc.'</p>'; }, self::OPT_SETTINGS,'printcom_ot_section');
        };
        $s = $this->get_settings();

        $f('api_base_url','API Base URL',sprintf('<input type="url" name="%s[api_base_url]" value="%s" class="regular-text" placeholder="https://api.print.com"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['api_base_url']??'https://api.print.com')));

        $f('auth_url','Auth URL (login endpoint)',sprintf('<input type="url" name="%s[auth_url]" value="%s" class="regular-text" placeholder="https://api.print.com/login"/>',
            esc_attr(self::OPT_SETTINGS),esc_attr($s['auth_url']??'https://api.print.com/login')), 'Voor Print.com: <code>https://api.print.com/login</code> (JWT ±168 uur).');

        $val = $s['grant_type'] ?? 'password';
        ob_start(); ?>
            <select name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[grant_type]">
                <option value="password" <?php selected($val,'password'); ?>>password (Print.com /login)</option>
                <option value="client_credentials" <?php selected($val,'client_credentials'); ?>>client_credentials (fallback)</option>
            </select>
        <?php $f('grant_type','Grant type',ob_get_clean(),'Gebruik <code>password</code> met jouw Username/Password voor <code>/login</code>.');

        $f('client_id','Client ID (optioneel)',sprintf('<input type="text" name="%s[client_id]" value="%s" class="regular-text" autocomplete="off"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['client_id']??'')));
        $f('client_secret','Client Secret (optioneel)',sprintf('<input type="password" name="%s[client_secret]" value="%s" class="regular-text" autocomplete="new-password"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['client_secret']??'')));

        $f('username','Username (Print.com login)',sprintf('<input type="text" name="%s[username]" value="%s" class="regular-text" autocomplete="off"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['username']??'')));
        $f('password','Password (Print.com login)',sprintf('<input type="password" name="%s[password]" value="%s" class="regular-text" autocomplete="new-password"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['password']??'')));

        $ttl = isset($s['default_cache_ttl']) ? (int)$s['default_cache_ttl'] : 5;
        $f('default_cache_ttl','Cache (minuten)',sprintf('<input type="number" min="0" step="1" name="%s[default_cache_ttl]" value="%d" class="small-text"/>',esc_attr(self::OPT_SETTINGS),$ttl),'Basis cache (0 = uit). Dynamische TTL: HOT=5m, COLD=24u.');

        // Debug opties
        $force_v4 = !empty($s['force_ipv4']);
        $alt_body = !empty($s['alt_payload']);
        $f('force_ipv4','Forceer IPv4 (debug)',sprintf('<label><input type="checkbox" name="%s[force_ipv4]" value="1" %s/> Alleen voor api.print.com</label>',esc_attr(self::OPT_SETTINGS),checked($force_v4,true,false)));
        $f('alt_payload','Alternatieve payload (debug)',sprintf('<label><input type="checkbox" name="%s[alt_payload]" value="1" %s/> POST zonder <code>{"credentials": ...}</code> wrapper</label>',esc_attr(self::OPT_SETTINGS),checked($alt_body,true,false)),'Alleen gebruiken om te testen als /login op jouw account anders reageert.');
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['api_base_url']      = isset($input['api_base_url']) ? trim(esc_url_raw($input['api_base_url'])) : 'https://api.print.com';
        $out['auth_url']          = isset($input['auth_url']) ? trim(esc_url_raw($input['auth_url'])) : 'https://api.print.com/login';
        $out['grant_type']        = in_array($input['grant_type'] ?? 'password',['client_credentials','password'],true) ? $input['grant_type'] : 'password';
        $out['client_id']         = trim(sanitize_text_field($input['client_id'] ?? ''));
        $out['client_secret']     = trim(sanitize_text_field($input['client_secret'] ?? ''));
        $out['username']          = trim(sanitize_text_field($input['username'] ?? ''));
        $out['password']          = trim((string)($input['password'] ?? ''));
        $out['default_cache_ttl'] = max(0,(int)($input['default_cache_ttl'] ?? 5));
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
            <p><strong>DNS check (api.print.com):</strong>
                <?php echo esc_html($dns_info); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_conn','printcom_ot_test_conn_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_connection"/>
                <button class="button button-secondary">Verbinding testen</button>
                <p class="description">Test login en toon response code/body + nuttige headers.</p>
            </form>
            <?php if (!empty($_GET['printcom_test_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_result'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_show_ip','printcom_ot_show_ip_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_show_server_ip"/>
                <button class="button">Toon server uitgaand IP</button>
            </form>
            <?php if (!empty($_GET['printcom_server_ip'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><strong>Server IP:</strong> <?php echo esc_html($_GET['printcom_server_ip']); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function resolve_dns_records(): string {
        $out = [];
        foreach (['A','AAAA'] as $t) {
            $recs = function_exists('dns_get_record') ? @dns_get_record('api.print.com', constant('DNS_'.$t)) : [];
            if ($recs) {
                $ips = array_map(function($r) use($t){ return $t==='A' ? $r['ip'] : $r['ipv6']; }, $recs);
                $out[] = $t.': '.implode(', ',$ips);
            }
        }
        return $out ? implode(' | ',$out) : 'Kan DNS niet ophalen (serverblok/disabled).';
    }

    private function remove_order_mapping(string $orderNum, bool $also_delete_page = false): bool {
        $mappings = get_option(self::OPT_MAPPINGS, []);
        if (!isset($mappings[$orderNum])) return false;

        $page_id = (int) $mappings[$orderNum];

        // Optioneel: ook de pagina zelf verwijderen
        if ($also_delete_page && $page_id && get_post_status($page_id)) {
            wp_delete_post($page_id, true);
        }

        // Mapping verwijderen
        unset($mappings[$orderNum]);
        update_option(self::OPT_MAPPINGS, $mappings, false);

        // Cache opruimen
        delete_transient(self::TRANSIENT_PREFIX . md5($orderNum));

        // State opruimen
        $state = get_option(self::OPT_STATE, []);
        if (isset($state[$orderNum])) {
            unset($state[$orderNum]);
            update_option(self::OPT_STATE, $state, false);
        }
        return true;
    }

    public function orders_page() {
        if (!current_user_can('manage_options')) return;

        // Auto-opschonen: verwijder mappings waarvan de pagina niet meer bestaat
        $mappings = get_option(self::OPT_MAPPINGS, []);
        $changed = false;
        foreach ($mappings as $order => $pid) {
            if (!$pid || !get_post_status((int)$pid)) {
                unset($mappings[$order]);
                // Ruim ook cache/state op
                delete_transient(self::TRANSIENT_PREFIX . md5($order));
                $state = get_option(self::OPT_STATE, []);
                if (isset($state[$order])) {
                    unset($state[$order]);
                    update_option(self::OPT_STATE, $state, false);
                }
                $changed = true;
            }
        }
        if ($changed) update_option(self::OPT_MAPPINGS, $mappings, false);

        $message = '';
        // Aanmaken/bijwerken via formulier
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

        // Verwijderd-notice uit actie-handler tonen
        if (!empty($_GET['printcom_deleted_order'])) {
            $od = sanitize_text_field(wp_unslash($_GET['printcom_deleted_order']));
            $message = 'Order <strong>' . esc_html($od) . '</strong> is uit de lijst verwijderd.';
        }
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

    /* ===========================
     * Shortcode rendering
     * =========================== */

    public function render_order_shortcode($atts = []) {
        $atts = shortcode_atts(['order' => ''], $atts, 'print_order_status');
        $orderNum = trim($atts['order']);
        if ($orderNum === '') return '<div class="printcom-ot printcom-ot--error">Geen ordernummer opgegeven.</div>';

        $settings = $this->get_settings();
        if (empty($settings['api_base_url'])) return '<div class="printcom-ot printcom-ot--error">API niet geconfigureerd.</div>';

        $cache_key = self::TRANSIENT_PREFIX . md5($orderNum);
        $data = get_transient($cache_key);
        if (!$data) {
            $data = $this->api_get_order($orderNum);
            if (is_wp_error($data)) return '<div class="printcom-ot printcom-ot--error">Kon ordergegevens niet ophalen. ' . esc_html($data->get_error_message()) . '</div>';
            set_transient($cache_key, $data, $this->dynamic_cache_ttl_for($data));
        }

        // Touch voor prioriteit
        $st = get_option(self::OPT_STATE, []);
        $e = $st[$orderNum] ?? ['status'=>null,'complete_at'=>null,'last_seen'=>null];
        $e['last_seen'] = time(); $st[$orderNum] = $e; update_option(self::OPT_STATE,$st,false);

        $html  = '<div class="printcom-ot">';
        $html .= '<div class="printcom-ot__header"><h2>Status van uw bestelling</h2></div>';
        $html .= '<div class="printcom-ot__status"><strong>Status:</strong> ' . esc_html($this->human_status($data)) . '</div>';
        $html .= '<div class="printcom-ot__items"><h3>Bestelde producten</h3>';

        if ($img = $this->get_order_page_image_html()) $html .= '<div class="printcom-ot__order-image">'.$img.'</div>';

        // verzonden items & tracks
        $shipped = []; $tracks_by_item = [];
        if (!empty($data['shipments']) && is_array($data['shipments'])) {
            foreach ($data['shipments'] as $shipment) {
                $itemNums = $shipment['orderItemNumbers'] ?? [];
                $urls = [];
                foreach (($shipment['tracks']??[]) as $t) if (!empty($t['trackUrl'])) $urls[] = $t['trackUrl'];
                foreach ($itemNums as $inum) { $shipped[$inum] = true; $tracks_by_item[$inum] = array_merge($tracks_by_item[$inum]??[],$urls); }
            }
        }

        $html .= '<ul class="printcom-ot__list">';
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $inum  = $item['orderItemNumber'] ?? '';
                $name  = $item['productName'] ?? ($item['productSku'] ?? 'Product');
                $isS   = isset($shipped[$inum]);
                $html .= '<li class="printcom-ot__item"><div class="printcom-ot__item-head">';
                $html .= '<span class="printcom-ot__item-name">'.esc_html($name).'</span>';
                $html .= $isS ? '<span class="printcom-ot__badge printcom-ot__badge--ok">Verzonden</span>' : '<span class="printcom-ot__badge printcom-ot__badge--pending">In behandeling</span>';
                $html .= '</div>';
                if ($isS) {
                    $urls = $tracks_by_item[$inum] ?? [];
                    if ($urls) {
                        $html .= '<div class="printcom-ot__tracks"><strong>Track & Trace:</strong> ';
                        $links=[]; foreach ($urls as $u) $links[] = '<a href="'.esc_url($u).'" target="_blank" rel="nofollow noopener">Volg zending</a>';
                        $html .= implode(' &middot; ',$links).'</div>';
                    } else {
                        $html .= '<div class="printcom-ot__tracks"><em>Track & Trace volgt spoedig.</em></div>';
                    }
                }
                $html .= '</li>';
            }
        } else {
            $html .= '<li>Er zijn nog geen producten geregistreerd voor deze bestelling.</li>';
        }
        $html .= '</ul></div></div>';

        return $html;
    }

    private function human_status(array $data) {
        $all = $data['items'] ?? []; $ships = $data['shipments'] ?? [];
        if (empty($all)) return 'Onbekend';
        $flags = [];
        foreach ($all as $it) if (!empty($it['orderItemNumber'])) $flags[$it['orderItemNumber']] = false;
        foreach ($ships as $s) foreach (($s['orderItemNumbers'] ?? []) as $n) if (isset($flags[$n])) $flags[$n]=true;
        if ($flags && count(array_filter($flags))===count($flags)) return 'Verzonden';
        if (count(array_filter($flags))>0) return 'Deels verzonden';
        if (!empty($data['status'])) { $s=strtolower($data['status']); if ($s==='cancelled'||$s==='canceled')return'Geannuleerd'; if($s==='processing')return'In behandeling'; if($s==='shipped')return'Verzonden'; if($s==='created')return'Aangemaakt'; }
        return 'In behandeling';
    }

    /* ===========================
     * Metabox & styles
     * =========================== */

    public function add_metabox() { add_meta_box('printcom_ot_image','Orderafbeelding (optioneel)',$this->metabox_html(...),'page','side','default'); }
    public function metabox_html($post) {
        if (strpos($post->post_content,'[print_order_status')===false){ echo '<p>Deze pagina lijkt geen Print.com orderpagina te zijn.</p>'; return; }
        wp_enqueue_media();
        $attachment_id = (int)get_post_meta($post->ID,self::META_IMG_ID,true);
        $img_src = $attachment_id ? wp_get_attachment_image_src($attachment_id,'medium') : null; ?>
        <div>
            <p>Gebruik dit om een eigen afbeelding te tonen op de orderpagina. Handig wanneer de API geen ontwerpafbeelding levert.</p>
            <div id="printcom-ot-image-preview" style="margin-bottom:10px;"><?php
                if ($img_src) echo '<img src="'.esc_url($img_src[0]).'" style="max-width:100%;height:auto;" />'; else echo '<em>Geen afbeelding gekozen.</em>';
            ?></div>
            <input type="hidden" id="printcom-ot-image-id" name="printcom_ot_image_id" value="<?php echo esc_attr($attachment_id); ?>"/>
            <button type="button" class="button" id="printcom-ot-image-upload">Afbeelding kiezen</button>
            <button type="button" class="button" id="printcom-ot-image-remove" <?php disabled(!$attachment_id); ?>>Verwijderen</button>
        </div>
        <script>(function($){$(function(){let f;$('#printcom-ot-image-upload').on('click',function(e){e.preventDefault();if(f){f.open();return;}f=wp.media({title:'Kies of upload afbeelding',button:{text:'Gebruik deze afbeelding'},multiple:false});f.on('select',function(){const a=f.state().get('selection').first().toJSON();$('#printcom-ot-image-id').val(a.id);$('#printcom-ot-image-preview').html('<img src="'+a.url+'" style="max-width:100%;height:auto;" />');$('#printcom-ot-image-remove').prop('disabled',false);});f.open();});$('#printcom-ot-image-remove').on('click',function(e){e.preventDefault();$('#printcom-ot-image-id').val('');$('#printcom-ot-image-preview').html('<em>Geen afbeelding gekozen.</em>');$(this).prop('disabled',true);});});})(jQuery);</script>
        <?php
    }
    public function save_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return; if (!current_user_can('edit_post',$post_id)) return;
        if (isset($_POST['printcom_ot_image_id'])) { $id=(int)$_POST['printcom_ot_image_id']; if ($id>0) update_post_meta($post_id,self::META_IMG_ID,$id); else delete_post_meta($post_id,self::META_IMG_ID); }
    }
    public function enqueue_styles() {
        $css='.printcom-ot{border:1px solid #eee;padding:24px;border-radius:12px}.printcom-ot__header h2{margin:0 0 8px;font-size:1.5rem}.printcom-ot__status{margin:8px 0 20px}.printcom-ot__order-image{margin:12px 0 20px}.printcom-ot__image{width:100%;height:auto;display:block;border-radius:8px}.printcom-ot__items h3{margin-top:0}.printcom-ot__list{list-style:none;margin:0;padding:0}.printcom-ot__item{padding:14px 0;border-bottom:1px solid #f0f0f0}.printcom-ot__item:last-child{border-bottom:none}.printcom-ot__item-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.printcom-ot__item-name{font-weight:600}.printcom-ot__badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:.85rem;background:#ddd;color:#333}.printcom-ot__badge--ok{background:#e6f7ed;color:#1f7a3e}.printcom-ot__badge--pending{background:#fff7e6;color:#8a5300}.printcom-ot__tracks{margin-top:8px}.printcom-ot--error{border:1px solid #f5c2c7;background:#f8d7da;padding:12px;border-radius:8px;color:#842029}';
        wp_register_style('printcom-ot-style', false); wp_enqueue_style('printcom-ot-style'); wp_add_inline_style('printcom-ot-style',$css);
    }

    /* ===========================
     * API client + state/TTL
     * =========================== */

    private function get_settings(){ return get_option(self::OPT_SETTINGS,[]); }

    public function maybe_force_ipv4_for_printcom($handle, $r, $url){
        $s = $this->get_settings();
        if (!empty($s['force_ipv4']) && is_string($url) && (stripos($url,'https://api.print.com/')===0 || stripos($url,'https://api.print.com?')===0 || stripos($url,'https://api.print.com')===0)) {
            if (defined('CURLOPT_IPRESOLVE')) {
                curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
        }
    }

    private function api_get_order($orderNum) {
        $s = $this->get_settings();
        $base = rtrim($s['api_base_url'] ?? 'https://api.print.com','/');
        $url  = $base.'/orders/'.rawurlencode($orderNum);

        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'RMH-Printcom-Tracker/1.4.1 (+WordPress)',
            ],
            'timeout' => 20,
        ];
        $res = wp_remote_get($url,$args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code === 401) {
            delete_transient(self::TRANSIENT_TOKEN);
            $token = $this->get_access_token(true);
            if (is_wp_error($token)) return $token;
            $args['headers']['Authorization'] = 'Bearer '.$token;
            $res = wp_remote_get($url,$args);
            if (is_wp_error($res)) return $res;
            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
        }
        if ($code < 200 || $code >= 300) return new WP_Error('printcom_api_error','API fout ('.$code.').');

        $json = json_decode($body,true);
        if (!is_array($json)) return new WP_Error('printcom_api_error','Ongeldig API-antwoord.');
        $this->update_order_state($orderNum,$json);
        return $json;
    }

    private function get_access_token($force_refresh=false){
        $cached = get_transient(self::TRANSIENT_TOKEN);
        if ($cached && !$force_refresh) return $cached;

        $s = $this->get_settings();
        $auth_url = trim($s['auth_url'] ?? '');
        if (!$auth_url) return new WP_Error('printcom_auth_missing','Auth URL niet ingesteld.');

        // Trimmen voorkomt onzichtbare witruimtes in UI
        $username = isset($s['username']) ? trim($s['username']) : '';
        $password = isset($s['password']) ? (string)$s['password'] : '';
        $password = preg_replace("/\r\n|\r|\n/", "", $password); // harde linebreaks verwijderen

        $ua = 'RMH-Printcom-Tracker/1.4.1 (+WordPress)';

        $is_print_login = (stripos($auth_url,'/login') !== false);
        if ($is_print_login) {
            if ($username==='' || $password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');
            $payload = !empty($s['alt_payload'])
                ? wp_json_encode(['username'=>$username,'password'=>$password]) // DEBUG: alternatief schema
                : wp_json_encode(['credentials'=>['username'=>$username,'password'=>$password]]); // volgens docs

            $args = [
                'headers' => ['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>$ua],
                'body'    => $payload,
                'timeout' => 20,
            ];
            $res = wp_remote_post($auth_url,$args);
            if (is_wp_error($res)) return $res;

            $code = wp_remote_retrieve_response_code($res);
            $raw  = wp_remote_retrieve_body($res);

            if ($code === 401) {
                $err = 'Auth fout (401). Controleer login & URL.';
                $json = json_decode($raw,true);
                if (is_array($json)) {
                    if (!empty($json['message'])) $err .= ' Detail: '.sanitize_text_field($json['message']);
                    if (!empty($json['error']))   $err .= ' ('.sanitize_text_field($json['error']).')';
                } elseif (!empty($raw)) { $err .= ' Detail: '.sanitize_text_field($raw); }
                return new WP_Error('printcom_auth_error',$err);
            }
            if ($code < 200 || $code >= 300) return new WP_Error('printcom_auth_error','Auth fout ('.$code.'). Raw: '.sanitize_text_field($raw));

            $json = json_decode($raw,true);
            $token = null;
            if (is_array($json)) $token = $json['access_token'] ?? $json['token'] ?? $json['jwt'] ?? null;
            if (!$token && is_string($raw) && strlen($raw)>20 && strpos($raw,'{')===false) $token = trim($raw);
            if (!$token) return new WP_Error('printcom_auth_error','Kon JWT niet vinden in login-response. Raw: '.sanitize_text_field($raw));

            set_transient(self::TRANSIENT_TOKEN,$token,max(60,(7*DAY_IN_SECONDS)-60));
            return $token;
        }

        // Fallback OAuth
        $grant = $s['grant_type'] ?? 'client_credentials';
        $body  = ['grant_type'=>$grant];
        if ($grant==='client_credentials') {
            if (empty($s['client_id']) || empty($s['client_secret'])) return new WP_Error('printcom_auth_missing','Client ID/Secret ontbreken.');
            $body['client_id']=$s['client_id']; $body['client_secret']=$s['client_secret'];
        } else {
            if ($username==='' || $password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');
            if (!empty($s['client_id'])) $body['client_id']=$s['client_id'];
            if (!empty($s['client_secret'])) $body['client_secret']=$s['client_secret'];
            $body['username']=$username; $body['password']=$password;
        }
        $args = ['headers'=>['Accept'=>'application/json','User-Agent'=>$ua],'body'=>$body,'timeout'=>20];
        $res = wp_remote_post($auth_url,$args);
        if (is_wp_error($res)) return $res;
        $code=wp_remote_retrieve_response_code($res); $raw=wp_remote_retrieve_body($res);

        if ($code===401){
            $err='Auth fout (401). Controleer OAuth en credentials.'; $j=json_decode($raw,true);
            if (is_array($j)) { if(!empty($j['error_description']))$err.=' '.sanitize_text_field($j['error_description']); if(!empty($j['error']))$err.=' ('.sanitize_text_field($j['error']).')'; }
            elseif(!empty($raw)) $err.=' Detail: '.sanitize_text_field($raw);
            return new WP_Error('printcom_auth_error',$err);
        }
        if ($code<200 || $code>=300) return new WP_Error('printcom_auth_error','Auth fout ('.$code.'). Raw: '.sanitize_text_field($raw));

        $j=json_decode($raw,true); if(!is_array($j)) return new WP_Error('printcom_auth_error','Ongeldige auth-response.');
        $token=$j['access_token'] ?? null; $expires=isset($j['expires_in'])?(int)$j['expires_in']:(7*DAY_IN_SECONDS);
        if(!$token) return new WP_Error('printcom_auth_error','Kon access_token niet vinden in auth-response. Raw: '.sanitize_text_field($raw));
        set_transient(self::TRANSIENT_TOKEN,$token,max(60,$expires-60)); return $token;
    }

    /* ===========================
     * State/Dynamic TTL helpers
     * =========================== */

    private function is_order_complete(array $data): bool {
        $all=$data['items']??[]; $ships=$data['shipments']??[]; if(empty($all))return false;
        $shipped=[]; foreach($all as $it) if(!empty($it['orderItemNumber'])) $shipped[$it['orderItemNumber']]=false;
        foreach($ships as $s) foreach(($s['orderItemNumbers']??[]) as $n) if(isset($shipped[$n])) $shipped[$n]=true;
        return !empty($shipped) && count(array_filter($shipped))===count($shipped);
    }
    private function update_order_state(string $orderNum,array $data): void {
        $state=get_option(self::OPT_STATE,[]); $now=time(); $complete=$this->is_order_complete($data);
        $status=isset($data['status'])?strtolower((string)$data['status']):($complete?'shipped':'processing');
        $entry=$state[$orderNum]??['status'=>null,'complete_at'=>null,'last_seen'=>null];
        $entry['status']=$status; if($complete && empty($entry['complete_at'])) $entry['complete_at']=$now; if(!$complete) $entry['complete_at']=null;
        $state[$orderNum]=$entry; update_option(self::OPT_STATE,$state,false);
    }
    private function dynamic_cache_ttl_for(array $data): int { return $this->is_order_complete($data) ? DAY_IN_SECONDS : 5*MINUTE_IN_SECONDS; }

    /* ===========================
     * Cron taken
     * =========================== */

    public function cron_refresh_token() {
        delete_transient(self::TRANSIENT_TOKEN);
        $t=$this->get_access_token(true);
        if (is_wp_error($t)) error_log('[Printcom OT] Token verversen mislukt: '.$t->get_error_message());
        else error_log('[Printcom OT] Token succesvol ververst.');
    }
    public function cron_warm_cache() {
        $state=get_option(self::OPT_STATE,[]); $mappings=get_option(self::OPT_MAPPINGS,[]); if(empty($mappings))return;
        $hot_limit=50; $cold_limit=20; $archive_days=14; $now=time();
        $orders=[];
        foreach($mappings as $orderNum=>$pid){
            $e=$state[$orderNum]??null; $complete_at=$e['complete_at']??null; $status=$e['status']??null;
            if($complete_at && ($now-(int)$complete_at)>($archive_days*DAY_IN_SECONDS)) continue;
            $isComplete=($complete_at!==null)||($status==='shipped'||$status==='completed');
            $orders[]=['order'=>$orderNum,'type'=>$isComplete?'COLD':'HOT','last_seen'=>$e['last_seen']??0];
        }
        if(empty($orders))return;
        shuffle($orders);
        $hot=array_values(array_filter($orders,fn($o)=>$o['type']==='HOT'));
        $cold=array_values(array_filter($orders,fn($o)=>$o['type']==='COLD'));
        usort($hot,function($a,$b){return $b['last_seen']<=>$a['last_seen'];});

        $ph=0; foreach($hot as $o){ if($ph>=$hot_limit)break; $d=$this->api_get_order($o['order']); if(is_wp_error($d)){error_log('[Printcom OT] HOT warm fout '.$o['order'].': '.$d->get_error_message()); continue;} set_transient(self::TRANSIENT_PREFIX.md5($o['order']),$d,$this->dynamic_cache_ttl_for($d)); $ph++; }
        $pc=0; foreach($cold as $o){ if($pc>=$cold_limit)break; $d=$this->api_get_order($o['order']); if(is_wp_error($d)){error_log('[Printcom OT] COLD warm fout '.$o['order'].': '.$d->get_error_message()); continue;} set_transient(self::TRANSIENT_PREFIX.md5($o['order']),$d,$this->dynamic_cache_ttl_for($d)); $pc++; }
        error_log('[Printcom OT] Warmed HOT: '.$ph.'; COLD: '.$pc.'.');
    }
}

// Hooks
register_activation_hook(__FILE__, ['Printcom_Order_Tracker','activate']);
register_deactivation_hook(__FILE__, ['Printcom_Order_Tracker','deactivate']);

// Admin-post: Verbinding testen (uitgebreid)
add_action('admin_post_printcom_ot_test_connection', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    if (empty($_POST['printcom_ot_test_conn_nonce']) || !wp_verify_nonce($_POST['printcom_ot_test_conn_nonce'],'printcom_ot_test_conn')) wp_die('Nonce invalid');

    $s = get_option(Printcom_Order_Tracker::OPT_SETTINGS, []);
    $auth_url = $s['auth_url'] ?? '';
    $username = isset($s['username']) ? trim($s['username']) : '';
    $password = isset($s['password']) ? (string)$s['password'] : '';
    $password = preg_replace("/\r\n|\r|\n/", "", $password);
    $ua = 'RMH-Printcom-Tracker/1.4.1 (+WordPress)';

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
        // Forceer IPv4 indien gevraagd
        if (!empty($s['force_ipv4'])) add_action('http_api_curl','printcom_ot_force_v4_once',10,3);
        function printcom_ot_force_v4_once($handle,$r,$url){ if (defined('CURLOPT_IPRESOLVE')) curl_setopt($handle,CURLOPT_IPRESOLVE,CURL_IPRESOLVE_V4); remove_action('http_api_curl','printcom_ot_force_v4_once',10); }

        $res = wp_remote_post($auth_url,$args);
        if (is_wp_error($res)) {
            $msg = '❌ Verbindingsfout (transport): '.esc_html($res->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($res);
            $hdrs = wp_remote_retrieve_headers($res);
            $raw  = wp_remote_retrieve_body($res);
            if ($code >= 200 && $code < 300) {
                $msg = '✅ OK ('.$code.'). Body lengte: '.strlen($raw).'.';
            } else {
                $hints = [];
                foreach (['www-authenticate','x-request-id','cf-ray','server'] as $h) if (!empty($hdrs[$h])) $hints[] = strtoupper($h).': '.$hdrs[$h];
                $msg = '❌ Auth fout ('.$code.'). '.(!empty($raw)?'Body: '.sanitize_text_field($raw):'Geen body.');
                if ($hints) $msg .= ' | Hints: '.implode(' | ', array_map('esc_html',$hints));
            }
        }
    }
    $dest = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    wp_safe_redirect(add_query_arg('printcom_test_result', rawurlencode($msg), $dest));
    exit;
});

// Admin-post: Toon server uitgaand IP
add_action('admin_post_printcom_ot_show_server_ip', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    if (empty($_POST['printcom_ot_show_ip_nonce']) || !wp_verify_nonce($_POST['printcom_ot_show_ip_nonce'],'printcom_ot_show_ip')) wp_die('Nonce invalid');
    $res = wp_remote_get('https://api64.ipify.org?format=text', ['timeout'=>10]);
    $ip  = is_wp_error($res) ? $res->get_error_message() : trim(wp_remote_retrieve_body($res));
    $dest = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    wp_safe_redirect(add_query_arg('printcom_server_ip', rawurlencode($ip), $dest));
    exit;
});

add_action('admin_post_printcom_ot_delete_order', function(){
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';
    if ($order === '') wp_die('Order ontbreekt.');

    // Nonce check op basis van order
    $nonce_key = 'printcom_ot_delete_order_'.$order;
    if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce_key)) {
        wp_die('Nonce invalid');
    }

    // Verwijder mapping + cache + state
    $plugin = new Printcom_Order_Tracker();
    // zet evt. tweede param op true als je óók de pagina wil verwijderen:
    $plugin_removed = (new ReflectionClass($plugin))->getMethod('remove_order_mapping')->invoke($plugin, $order, false);

    $dest = admin_url('admin.php?page=printcom-orders');
    if ($plugin_removed) {
        $dest = add_query_arg('printcom_deleted_order', rawurlencode($order), $dest);
    }
    wp_safe_redirect($dest);
    exit;
});

// Start plugin
new Printcom_Order_Tracker();