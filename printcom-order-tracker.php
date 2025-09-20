<?php
/**
 * Plugin Name: Print.com Order Tracker (Track & Trace Pagina's)
 * Description: Maakt per ordernummer automatisch een track & trace pagina aan en toont live orderstatus, items en verzendinformatie via de Print.com API. Tokens worden automatisch vernieuwd. Divi-vriendelijk.
 * Version:     2.4.23
 * Author:      RikkerMediaHub
 * License:     GNU GPLv2
 * Text Domain: printcom-order-tracker
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-rmh-invoice-ninja-client.php';

class Printcom_Order_Tracker {
    public const PLUGIN_VERSION = '2.4.23';
    public const USER_AGENT     = 'RMH-Printcom-Tracker/1.6.1 (+WordPress)';

    const OPT_SETTINGS     = 'printcom_ot_settings';
    const OPT_MAPPINGS     = 'printcom_ot_mappings';
    const OPT_STATE        = 'printcom_ot_state';
    const TRANSIENT_TOKEN  = 'printcom_ot_token';
    const TRANSIENT_PREFIX = 'printcom_ot_cache_';
    const META_IMG_ID      = '_printcom_ot_image_id';    // (legacy) 1 afbeelding voor de hele pagina
    const META_ITEM_IMGS   = '_printcom_ot_item_images'; // per product (orderItemNumber => attachment_id)

    /** @var int[]|null Cached lijst met door de plugin beheerde orderpagina's (per request). */
    private $managed_page_ids = null;

    /** @var array|null Cache van plugin-instellingen voor deze request. */
    private $settings_cache = null;

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_printcom_ot_delete_order', [$this, 'admin_handle_delete_order']);
        add_action('save_post_page', [$this, 'enforce_divi_layout_on_save'], 20, 3);

        add_shortcode('print_order_status', [$this, 'render_order_shortcode']);
        add_shortcode('print_order_lookup', [$this, 'render_lookup_shortcode']);

        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post',       [$this, 'save_metaboxes']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

        add_filter('cron_schedules', [$this, 'add_every5_schedule']);
        add_filter('template_include', [$this, 'force_divi_template_for_orders'], 99);
        add_filter('body_class',      [$this, 'force_divi_body_classes_for_orders']);
        add_action('printcom_ot_cron_refresh_token', [$this, 'cron_refresh_token']);
        add_action('printcom_ot_cron_warm_cache',   [$this, 'cron_warm_cache']);
    }

    /* ===== Activatie/Deactivatie ===== */

    public static function activate() {
        if (!wp_next_scheduled('printcom_ot_cron_refresh_token')) {
            $t = strtotime('03:00:00'); if ($t <= time()) $t = strtotime('tomorrow 03:00:00');
            wp_schedule_event($t, 'daily', 'printcom_ot_cron_refresh_token');
        }
        if (!wp_next_scheduled('printcom_ot_cron_warm_cache')) {
            wp_schedule_event(time()+300, 'every5min', 'printcom_ot_cron_warm_cache');
        }
    }
    public static function deactivate() {
        if ($ts = wp_next_scheduled('printcom_ot_cron_refresh_token')) {
            wp_unschedule_event($ts, 'printcom_ot_cron_refresh_token');
        }
        if ($ts = wp_next_scheduled('printcom_ot_cron_warm_cache')) {
            wp_unschedule_event($ts, 'printcom_ot_cron_warm_cache');
        }
    }

    public function add_every5_schedule($s) {
        $s['every5min'] = [
            'interval' => 300,
            'display'  => 'Every 5 Minutes',
        ];

        return $s;
    }

    /* ===== Admin menu & instellingen ===== */

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
        register_setting(self::OPT_SETTINGS, self::OPT_SETTINGS, [$this,'sanitize_settings']);
        add_settings_section('printcom_ot_section','API-instellingen','__return_false',self::OPT_SETTINGS);
        add_settings_section('printcom_ot_invoice_ninja','Invoice Ninja',[$this,'render_invoice_ninja_section_description'],self::OPT_SETTINGS);
        $s = $this->get_settings();
        $add=function($section,$k,$label,$html,$desc=''){
            add_settings_field($k,$label,function() use($html,$desc){ echo $html; if($desc) echo '<p class="description">'.$desc.'</p>'; },self::OPT_SETTINGS,$section);
        };
        $add('printcom_ot_section','api_base_url','API Base URL',sprintf('<input type="url" name="%s[api_base_url]" value="%s" class="regular-text" placeholder="https://api.print.com"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['api_base_url']??'https://api.print.com')));
        $add('printcom_ot_section','auth_url','Auth URL (login)',sprintf('<input type="url" name="%s[auth_url]" value="%s" class="regular-text" placeholder="https://api.print.com/login"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['auth_url']??'https://api.print.com/login')),'Voor Print.com: <code>https://api.print.com/login</code>.');
        ob_start(); ?>
            <select name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[grant_type]">
                <option value="password" <?php selected($s['grant_type']??'password','password'); ?>>password (/login)</option>
                <option value="client_credentials" <?php selected($s['grant_type']??'password','client_credentials'); ?>>client_credentials (fallback)</option>
            </select>
        <?php $add('printcom_ot_section','grant_type','Grant type',ob_get_clean());
        $add('printcom_ot_section','client_id','Client ID (optioneel)',sprintf('<input type="text" name="%s[client_id]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['client_id']??'')));
        $add('printcom_ot_section','client_secret','Client Secret (optioneel)',sprintf('<input type="password" name="%s[client_secret]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['client_secret']??'')));
        $add('printcom_ot_section','username','Username',sprintf('<input type="text" name="%s[username]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['username']??'')));
        $add('printcom_ot_section','password','Password',sprintf('<input type="password" name="%s[password]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['password']??'')));
        $add('printcom_ot_section','default_cache_ttl','Cache (minuten)',sprintf('<input type="number" min="0" step="1" name="%s[default_cache_ttl]" value="%d" class="small-text"/>',esc_attr(self::OPT_SETTINGS),isset($s['default_cache_ttl'])?(int)$s['default_cache_ttl']:5),'HOT=5m, COLD=24u.');

        $enabled_checked = checked(!empty($s['rmh_integration_enabled']), true, false);
        $add('printcom_ot_invoice_ninja','rmh_integration_enabled','Integratie inschakelen',sprintf('<label><input type="checkbox" name="%1$s[rmh_integration_enabled]" value="1" %2$s/> Schakel Invoice Ninja integratie in</label>',esc_attr(self::OPT_SETTINGS),$enabled_checked),'Toon de betaallink op de track-&-trace pagina.');
        $add('printcom_ot_invoice_ninja','rmh_in_base_url','Invoice Ninja Base URL',sprintf('<input type="url" name="%1$s[rmh_in_base_url]" value="%2$s" class="regular-text" placeholder="https://invoicing.co"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['rmh_in_base_url']??'')),'Bijvoorbeeld: <code>https://invoicing.co</code>.');
        $add('printcom_ot_invoice_ninja','rmh_in_api_token','API Token',sprintf('<input type="password" name="%1$s[rmh_in_api_token]" value="%2$s" class="regular-text" autocomplete="new-password"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['rmh_in_api_token']??'')),'Gebruik een Invoice Ninja v5 API token.');
        $cache_minutes = isset($s['rmh_in_cache_minutes']) ? (int) $s['rmh_in_cache_minutes'] : 10;
        $add('printcom_ot_invoice_ninja','rmh_in_cache_minutes','Cache (minuten)',sprintf('<input type="number" min="0" step="1" name="%1$s[rmh_in_cache_minutes]" value="%2$d" class="small-text"/>',esc_attr(self::OPT_SETTINGS),$cache_minutes),'Bewaar uitnodigingslinks tijdelijk (0 = geen cache).');
    }

    public function render_invoice_ninja_section_description() {
        echo '<p>De factuurknop verschijnt automatisch op de track &amp; trace pagina zodra de integratie actief is en er een factuur-ID aan de order is gekoppeld. Dit factuur-ID komt overeen met de pagina-naam of het order-ID dat door het systeem wordt gebruikt.</p>';
    }

    public function sanitize_settings($in){
        $this->settings_cache = null;

        $o=[];
        $o['api_base_url']=isset($in['api_base_url'])?trim(esc_url_raw($in['api_base_url'])):'https://api.print.com';
        $o['auth_url']=isset($in['auth_url'])?trim(esc_url_raw($in['auth_url'])):'https://api.print.com/login';
        $o['grant_type']=in_array($in['grant_type']??'password',['client_credentials','password'],true)?$in['grant_type']:'password';
        $o['client_id']=trim(sanitize_text_field($in['client_id']??''));
        $o['client_secret']=trim(sanitize_text_field($in['client_secret']??''));
        $o['username']=trim(sanitize_text_field($in['username']??''));
        $o['password']=trim((string)($in['password']??''));
        $o['default_cache_ttl']=max(0,(int)($in['default_cache_ttl']??5));
        $o['rmh_integration_enabled']=!empty($in['rmh_integration_enabled'])?1:0;
        $o['rmh_in_base_url']=$this->normalize_invoice_ninja_base_url($in['rmh_in_base_url']??'');
        $o['rmh_in_api_token']=trim(sanitize_text_field($in['rmh_in_api_token']??''));
        $o['rmh_in_cache_minutes']=max(0,(int)($in['rmh_in_cache_minutes']??10));
        return $o;
    }

    private function normalize_invoice_ninja_base_url($url): string {
        if (!is_string($url)) {
            return '';
        }

        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (stripos($normalized, 'http://') === 0) {
            $normalized = 'https://' . substr($normalized, 7);
        }
        if (stripos($normalized, 'https://') !== 0) {
            return '';
        }

        $normalized = esc_url_raw($normalized);
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        return untrailingslashit($normalized);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Print.com Orders — Instellingen</h1>
            <?php
            if (!empty($_GET['rmh_in_test_status'])) {
                $status = sanitize_key(wp_unslash($_GET['rmh_in_test_status']));
                if ($status === 'success') {
                    echo '<div class="notice notice-success" style="margin-top:10px;"><p>✅ Verbinding met Invoice Ninja succesvol.</p></div>';
                } elseif ($status === 'error') {
                    echo '<div class="notice notice-error" style="margin-top:10px;"><p>❌ Verbinding mislukt. Controleer je URL en API token.</p></div>';
                }
            }
            ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT_SETTINGS); do_settings_sections(self::OPT_SETTINGS); submit_button(); ?>
            </form>
            <hr/>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_conn','printcom_ot_test_conn_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_connection"/>
                <button class="button button-secondary">Print.com verbinding testen</button>
            </form>
            <?php if (!empty($_GET['printcom_test_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_result'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_order','printcom_ot_test_order_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_order"/>
                <input type="text" name="order" placeholder="Ordernummer (bijv. 6001831441)" required/>
                <button class="button">Test: haal Print.com order op</button>
            </form>
            <?php if (!empty($_GET['printcom_test_order_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_order_result'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('rmh_in_test_connection','rmh_in_test_connection_nonce'); ?>
                <input type="hidden" name="action" value="rmh_in_test_connection"/>
                <button class="button button-secondary">Invoice Ninja verbinding testen</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('rmh_in_test_invoice_lookup','rmh_in_test_invoice_lookup_nonce'); ?>
                <input type="hidden" name="action" value="rmh_in_test_invoice_lookup"/>
                <input type="text" name="invoice" placeholder="Factuurnummer" required/>
                <button class="button">Test: zoek Invoice Ninja factuur</button>
            </form>
            <?php if (!empty($_GET['rmh_in_test_invoice_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['rmh_in_test_invoice_result'])); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ===== Orders admin ===== */

    public function orders_page() {
        if (!current_user_can('manage_options')) return;

        // Admin notice via transient (gezet door handler)
        $notice = get_transient('printcom_ot_admin_notice');
        if ($notice) {
            delete_transient('printcom_ot_admin_notice');
        }

        // Wees-mappings opruimen
        $m = get_option(self::OPT_MAPPINGS, []);
        $chg=false;
        foreach($m as $own=>$info){
            $pid=(int)($info['page_id']??0); $print=$info['print_order']??'';
            if(!$pid || !get_post_status($pid)){
                unset($m[$own]);
                if($print){ delete_transient(self::TRANSIENT_PREFIX.md5($print)); $st=get_option(self::OPT_STATE,[]); if(isset($st[$print])){unset($st[$print]); update_option(self::OPT_STATE,$st,false);} }
                $chg=true;
            }
        }
        if($chg){
            update_option(self::OPT_MAPPINGS,$m,false);
            $this->reset_managed_page_cache();
        }

        $msg='';
        $error_msg='';
        if (!empty($_POST['rmh_ot_my_order']) && check_admin_referer('printcom_ot_new_order_action','printcom_ot_nonce')) {
            $my=sanitize_text_field($_POST['rmh_ot_my_order']);
            $invoice='';
            if (isset($_POST['rmh_ot_invoice_number'])) {
                $invoice=sanitize_text_field($_POST['rmh_ot_invoice_number']);
            }
            $my=trim($my);
            $invoice=trim($invoice);

            if ($my==='') {
                $error_msg='Vul een eigen ordernummer in.';
            } elseif ($invoice==='') {
                $error_msg='Vul een Invoice Ninja factuurnummer in.';
            } else {
                $client=function_exists('rmh_get_invoice_ninja_client_singleton')?rmh_get_invoice_ninja_client_singleton():null;
                if(!$client){
                    $error_msg='Invoice Ninja integratie is niet geconfigureerd of niet ingeschakeld.';
                } else {
                    $details=$client->get_invoice_portal_details($invoice);
                    if(!is_array($details) || !array_key_exists('custom_value1',$details)){
                        $error_msg='Kon het Print.com ordernummer (custom_value1) niet ophalen uit de factuur.';
                    } else {
                        $raw_print=trim((string)$details['custom_value1']);
                        if($raw_print===''){
                            $error_msg='Het opgehaalde Print.com ordernummer is leeg.';
                        } else {
                            $print=sanitize_text_field($raw_print);
                            if($print===''){
                                $error_msg='Het opgehaalde Print.com ordernummer bevat ongeldige tekens.';
                            } else {
                                $res=$this->create_or_update_page_for_order($my,$print,$invoice);
                                if ($res){ [$page_id,$token]=$res; $url=add_query_arg('token',rawurlencode($token),get_permalink($page_id)); $msg=sprintf('Pagina voor order <strong>%s</strong> is aangemaakt/bijgewerkt: <a href="%s" target="_blank" rel="noopener">%s</a>. Print.com order: <strong>%s</strong>.',esc_html($my),esc_url($url),esc_html($url),esc_html($print)); }
                                else { $error_msg='Er ging iets mis bij het aanmaken of bijwerken van de pagina.'; }
                            }
                        }
                    }
                }
            }
        }

        $m = get_option(self::OPT_MAPPINGS, []);
        ?>
        <div class="wrap">
            <?php if (!empty($notice)): ?>
                <div class="notice notice-success"><p><?php echo wp_kses_post($notice); ?></p></div>
            <?php endif; ?>
            
            <h1>Print.com Orders</h1>
            <?php if ($error_msg): ?><div class="notice notice-error"><p><?php echo esc_html($error_msg); ?></p></div><?php endif; ?>
            <?php if ($msg): ?><div class="notice notice-success"><p><?php echo wp_kses_post($msg); ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('printcom_ot_new_order_action','printcom_ot_nonce'); ?>
                <table class="form-table">
                    <tr><th><label for="rmh_ot_my_order">Eigen ordernummer</label></th><td><input type="text" id="rmh_ot_my_order" name="rmh_ot_my_order" class="regular-text" required/></td></tr>
                    <tr><th><label for="rmh_ot_invoice_number">Invoice Ninja factuurnummer</label></th><td><input type="text" id="rmh_ot_invoice_number" name="rmh_ot_invoice_number" class="regular-text" required/><p class="description">Het Print.com ordernummer wordt automatisch opgehaald uit <code>custom_value1</code> van deze factuur.</p></td></tr>
                </table>
                <?php submit_button('Pagina aanmaken/bijwerken'); ?>
            </form>
            <h2>Bestaande orderpagina’s</h2>
            <?php if ($m): ?>
                <table class="widefat striped"><thead><tr><th>Eigen nummer</th><th>Print.com nummer</th><th>Pagina</th><th>Link</th><th>Acties</th></tr></thead><tbody>
                <?php foreach($m as $own=>$info):
                    $pid   = (int)($info['page_id']??0);
                    $print = $info['print_order']??'';
                    $token = $info['token']??'';
                    $link  = $pid ? add_query_arg('token',rawurlencode($token),get_permalink($pid)) : '';
                    $title = $pid ? get_the_title($pid) : '';

                    $del = wp_nonce_url(
                        admin_url('admin-post.php?action=printcom_ot_delete_order&order='.rawurlencode($own).'&hard=1'),
                        'printcom_ot_delete_order_'.$own
                    );
                ?>
                <tr>
                  <td><?php echo esc_html($own); ?></td>
                  <td><?php echo esc_html($print); ?></td>
                  <td><?php echo esc_html($title); ?> (ID: <?php echo (int)$pid; ?>)</td>
                  <td><?php if($link): ?><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($link); ?></a><?php endif; ?></td>
                  <td>
                    <a class="button button-link-delete"
                       href="<?php echo esc_url($del); ?>"
                       onclick="return confirm('Weet je zeker dat je deze order én de gekoppelde pagina definitief wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                       Verwijder uit lijst + pagina
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?></tbody></table>
            <?php else: ?><p>Nog geen orderpagina’s aangemaakt.</p><?php endif; ?>
        </div>
        <?php
    }

    private function create_or_update_page_for_order(string $ownOrder, string $printOrder, string $invoiceNumber = '') {
        $mappings = get_option(self::OPT_MAPPINGS, []);
        $existing_entry = isset($mappings[$ownOrder]) && is_array($mappings[$ownOrder]) ? $mappings[$ownOrder] : [];
        $title    = 'Bestelling '.$ownOrder;
        $shortcode= sprintf('[print_order_status order="%s"]', esc_attr($ownOrder));

        $parent_id = 0;
        $parent = get_page_by_path('bestellingen');
        if ($parent) { $parent_id = (int)$parent->ID; }

        $token = $existing_entry['token'] ?? wp_generate_password(20,false,false);

        if (isset($mappings[$ownOrder]) && get_post_status((int)$mappings[$ownOrder]['page_id'])) {
            $page_id = (int)$mappings[$ownOrder]['page_id'];
            $update = [
                'ID'         => $page_id,
                'post_title' => $title,
                'post_name'  => sanitize_title($ownOrder),
                'post_parent'=> $parent_id,
            ];
            $cur = get_post($page_id);
            if ($cur && strpos($cur->post_content, '[print_order_status') === false) {
                $update['post_content'] = $cur->post_content . "\n\n" . $shortcode;
            }
            wp_update_post($update);
        } else {
            $page_id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => sanitize_title($ownOrder),
                'post_content' => $shortcode,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_parent'  => $parent_id,
                'post_author'  => get_current_user_id(),
                'meta_input'   => [
                    '_wp_page_template' => 'et_full_width_page.php',
                    'et_pb_page_layout' => 'et_no_sidebar',
                ],
            ]);
            if (is_wp_error($page_id) || !$page_id) return false;
        }

        $invoiceNumber = trim(sanitize_text_field($invoiceNumber));

        $entry = is_array($existing_entry) ? $existing_entry : [];
        $entry['page_id']    = (int)$page_id;
        $entry['print_order']= $printOrder;
        $entry['token']      = $token;
        if ($invoiceNumber !== '') {
            $entry['invoice_id'] = $invoiceNumber;
        }

        $mappings[$ownOrder] = $entry;
        update_option(self::OPT_MAPPINGS, $mappings, false);
        $this->reset_managed_page_cache();

        $this->apply_divi_fullwidth_no_sidebar((int)$page_id);
        return [(int)$page_id,$token];
    }

    private function remove_order_mapping(string $ownOrder, bool $also_delete_page=false): bool {
        $m = get_option(self::OPT_MAPPINGS, []);
        if (!isset($m[$ownOrder])) return false;

        $info = $m[$ownOrder];
        $pid = (int)($info['page_id']??0);
        $print = $info['print_order']??'';

        if ($also_delete_page && $pid > 0) {
            wp_delete_post($pid, true);
        }

        unset($m[$ownOrder]);
        update_option(self::OPT_MAPPINGS, $m, false);
        $this->reset_managed_page_cache();

        if ($print) {
            delete_transient(self::TRANSIENT_PREFIX.md5($print));
            $st = get_option(self::OPT_STATE, []);
            if (isset($st[$print])) { unset($st[$print]); update_option(self::OPT_STATE, $st, false); }
        }

        return true;
    }

    public function admin_handle_delete_order() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

        $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';
        if ($order === '') wp_die('Order ontbreekt.', 400);

        $nonce_key = 'printcom_ot_delete_order_' . $order;
        if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce_key)) {
            wp_die('Nonce invalid', 403);
        }

        $map = get_option(self::OPT_MAPPINGS, []);
        $pid = isset($map[$order]['page_id']) ? (int)$map[$order]['page_id'] : 0;

        $this->remove_order_mapping($order, true);

        set_transient('printcom_ot_admin_notice',
            'Order <strong>'.esc_html($order).'</strong> en pagina (ID: '.(int)$pid.') zijn verwijderd.', 30);

        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=printcom-orders') );
        exit;
    }

    public function enforce_divi_layout_on_save($post_ID, $post, $update) {
        if ($post->post_type !== 'page') return;

        // Alleen afdwingen voor door de plugin beheerde orderpagina’s
        if (!$this->is_managed_order_page($post_ID)) {
            return;
        }

        $this->apply_divi_fullwidth_no_sidebar((int)$post_ID);
    }

    public function force_divi_template_for_orders($template) {
        if (!is_page()) return $template;

        $pid  = get_queried_object_id();
        if (!$pid || !$this->is_managed_order_page($pid)) {
            return $template;
        }

        // Force Divi full-width template if available
        $full = locate_template('et_full_width_page.php');
        if ($full && file_exists($full)) {
            return $full;
        }
        return $template;
    }

    public function force_divi_body_classes_for_orders(array $classes) : array {
        if (!is_page()) return $classes;

        $pid  = get_queried_object_id();
        if ($pid && $this->is_managed_order_page($pid)) {
            // Tell Divi “no sidebar + full-width” via classes, regardless of UI state
            $classes[] = 'et_full_width_page';
            $classes[] = 'et_no_sidebar';
        }
        return $classes;
    }

    /* ===== Helpers voor orderpagina's ===== */

    private function reset_managed_page_cache(): void {
        $this->managed_page_ids = null;
    }

    private function get_managed_page_ids(): array {
        if (is_array($this->managed_page_ids)) {
            return $this->managed_page_ids;
        }

        $maps = get_option(self::OPT_MAPPINGS, []);
        $ids  = [];

        foreach ($maps as $info) {
            if (!is_array($info)) {
                continue;
            }
            $pid = isset($info['page_id']) ? (int)$info['page_id'] : 0;
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }

        $this->managed_page_ids = $ids;

        return $ids;
    }

    private function is_managed_order_page($post_id): bool {
        $pid = (int)$post_id;
        if ($pid <= 0) {
            return false;
        }

        return in_array($pid, $this->get_managed_page_ids(), true);
    }

    /* ===== Shortcode ===== */


    public function render_lookup_shortcode() {
        $order_val = '';
        $postcode_val_raw = '';
        $error = '';
        $order_invalid = false;
        $postcode_invalid = false;

        if (!empty($_POST['rmh_ol_order']) && isset($_POST['rmh_ol_postcode'])) {
            $order_val = sanitize_text_field(wp_unslash($_POST['rmh_ol_order']));
            $postcode_val_raw = wp_unslash($_POST['rmh_ol_postcode']);
            $postcode_norm = strtoupper(preg_replace('/\\s+/', '', sanitize_text_field($postcode_val_raw)));

            if ($order_val === '') {
                $error = 'Vul een ordernummer in.';
                $order_invalid = true;
            } elseif (!preg_match('/^[0-9]{4}[A-Z]{2}$/', $postcode_norm)) {
                $error = 'Vul een geldige postcode in (bijv. 1234AB).';
                $postcode_invalid = true;
            } else {
                $maps = get_option(self::OPT_MAPPINGS, []);
                $map = null;
                foreach ($maps as $stored_order => $entry) {
                    if (strcasecmp((string) $stored_order, $order_val) === 0) {
                        $map = $entry;
                        break;
                    }
                }
                if (is_array($map)) {
                    $data = $this->api_get_order($map['print_order']);
                    if (!is_wp_error($data)) {
                        $addr   = $this->extract_primary_shipping_address($data);
                        $postal = strtoupper(preg_replace('/\\s+/', '', $addr['postcode'] ?? ''));
                        if ($postcode_norm === $postal) {
                            $url = add_query_arg('token', rawurlencode($map['token']), get_permalink((int)$map['page_id']));
                            wp_safe_redirect($url);
                            exit;
                        }
                    }
                }
                $error = 'We hebben geen bestelling gevonden met deze combinatie.';
                $order_invalid = $postcode_invalid = true;
            }
        }

        $order_attr = $order_invalid ? ' aria-invalid="true"' : '';
        $postcode_attr = $postcode_invalid ? ' aria-invalid="true"' : '';

        $html  = '<div class="rmh-order-lookup">';
        $html .= '<h2 class="rmh-ol__title">Bestelling zoeken</h2>';
        $html .= '<div class="rmh-ol__feedback' . ($error ? ' rmh-ol__feedback--error' : '') . '" aria-live="polite">' . ($error ? esc_html($error) : '') . '</div>';
        $html .= '<form class="rmh-ol__form" method="post">';
        $html .=   '<div class="rmh-ol__field">';
        $html .=     '<label class="rmh-ol__label" for="rmh_ol_order">Ordernummer <span aria-hidden="true">*</span></label>';
        $html .=     '<input class="rmh-ol__input" type="text" id="rmh_ol_order" name="rmh_ol_order" placeholder="RMH-12345 / 1234" required' . $order_attr . ' value="' . esc_attr($order_val) . '" />';
        $html .=   '</div>';
        $html .=   '<div class="rmh-ol__field">';
        $html .=     '<label class="rmh-ol__label" for="rmh_ol_postcode">Postcode <span aria-hidden="true">*</span></label>';
        $html .=     '<input class="rmh-ol__input" type="text" id="rmh_ol_postcode" name="rmh_ol_postcode" placeholder="1234AB (zonder spatie)" pattern="^[0-9]{4}[A-Za-z]{2}$" required' . $postcode_attr . ' value="' . esc_attr($postcode_val_raw) . '" />';
        $html .=   '</div>';
        $html .=   '<div class="rmh-ol__actions"><button type="submit" class="rmh-ol__btn">Zoek bestelling</button></div>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
    public function render_order_shortcode($atts=[]) {
        $atts = shortcode_atts(['order'=>''], $atts, 'print_order_status');
        $own = trim($atts['order']);
        if ($own==='') return '<div class="rmh-ot rmh-ot--error">Geen ordernummer opgegeven.</div>';

        $maps = get_option(self::OPT_MAPPINGS, []);
        if (empty($maps[$own])) return '<div class="rmh-ot rmh-ot--error">Onbekend ordernummer.</div>';
        $map            = $maps[$own];
        $token          = $map['token'] ?? '';
        $orderNum       = $map['print_order'];
        $requestedToken = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $valid          = is_user_logged_in() || ($requestedToken !== '' && $requestedToken === $token);
        if (!$valid) {
            wp_safe_redirect( home_url( '/bestellingen' ) );
            exit;
        }

        $cache_key = self::TRANSIENT_PREFIX.md5($orderNum);
        $data = get_transient($cache_key);
        if (!is_array($data)) {
            $data = $this->api_get_order($orderNum);
            if(is_wp_error($data)) return '<div class="rmh-ot rmh-ot--error">Kon ordergegevens niet ophalen. '.esc_html($data->get_error_message()).'</div>';
            set_transient($cache_key,$data,$this->dynamic_cache_ttl_for($data));
        }
        
        $overall_delivery = $this->render_delivery_window_range($data['shipments'] ?? []);
        if (!$overall_delivery) {
            $all_sh = [];
            foreach (($data['items'] ?? []) as $it) {
                if (!empty($it['shipments'])) $all_sh = array_merge($all_sh, $it['shipments']);
            }
            if ($all_sh) $overall_delivery = $this->render_delivery_window_range($all_sh);
        }
        $ship_addr = $this->extract_primary_shipping_address($data);
        $nl_status = $this->human_status($data); // geeft NL: "Deels verzonden", "Verzonden", etc.

        $invoice_cta_html = '';
        $invoice_info_html = '';
        $invoice_hint_html = '';
        $invoice_link_found = false;
        $invoice_client_available = false;
        $invoice_display_details = null;
        $invoice_portal_link = '';
        if (function_exists('rmh_get_invoice_ninja_client_singleton')) {
            $client = rmh_get_invoice_ninja_client_singleton();
            if ($client instanceof RMH_InvoiceNinja_Client) {
                $invoice_client_available = true;
                $invoice_id = $this->resolve_invoice_ninja_invoice_id($map, $data, $own, $orderNum);
                if ($invoice_id) {
                    $details = $client->get_invoice_portal_details($invoice_id);
                    if (is_array($details) && !empty($details['link'])) {
                        $invoice_link_found = true;
                        $invoice_display_details = $details;
                        $invoice_portal_link = (string) $details['link'];
                    }
                }
            }
        }
        if (!$invoice_link_found && $invoice_client_available && current_user_can('manage_options')) {
            $invoice_hint_html = '<p class="rmh-invoice-admin-hint">' . esc_html('Invoice Ninja: geen portal-link gevonden voor deze order. Controleer de factuurnummer-koppeling.') . '</p>';
        }
    

        // prioriteit voor warming
        $st=get_option(self::OPT_STATE,[]); $e=$st[$orderNum]??['status'=>null,'complete_at'=>null,'last_seen'=>null]; $e['last_seen']=time(); $st[$orderNum]=$e; update_option(self::OPT_STATE,$st,false);

        // Per-item custom images (admin) — merge van gemapte pagina + huidige pagina (fallback)
        $item_imgs = [];
        $page_id = (int)($map['page_id'] ?? 0);
        if ($page_id) {
            $map_main = get_post_meta($page_id, self::META_ITEM_IMGS, true);
            if (is_array($map_main)) $item_imgs = $map_main;
        }
        if (get_the_ID()) {
            $map_here = get_post_meta((int) get_the_ID(), self::META_ITEM_IMGS, true);
            if (is_array($map_here)) $item_imgs = array_merge($item_imgs, $map_here);
        }
        // normaliseer keys (trim/strip quotes)
        if ($item_imgs) {
            $norm = [];
            foreach ($item_imgs as $k => $v) {
                $kk = is_string($k) ? trim($k) : (string)$k;
                if ((substr($kk,0,1)==='"' && substr($kk,-1)==='"')||(substr($kk,0,1)==="'" && substr($kk,-1)==="'")) $kk = substr($kk,1,-1);
                $norm[$kk] = (int)$v;
            }
            $item_imgs = $norm;
        }

        $items = $data['items'] ?? [];
        $shipments = $data['shipments'] ?? [];
        $product_count = 0;
        if (is_array($items)) {
            foreach ($items as $item_entry) {
                if (is_array($item_entry)) {
                    $product_count++;
                }
            }
        }
        $overall_status = $this->determine_overall_order_status($data);

        $desktop_intro_text = $this->build_intro_summary($product_count, $overall_status, $data, $invoice_display_details);
        $mobile_intro_text = $this->build_mobile_intro_summary($product_count, $overall_status, $data, $invoice_display_details);

        $summary_fragments = [];
        if ($desktop_intro_text !== '') {
            $summary_fragments[] = '<span class="rmh-ot__summary-text rmh-ot__summary-text--desktop">' . $desktop_intro_text . '</span>';
        }
        if ($mobile_intro_text !== '') {
            $summary_fragments[] = '<span class="rmh-ot__summary-text rmh-ot__summary-text--mobile">' . $mobile_intro_text . '</span>';
        }

        $progress_text_html = implode('', $summary_fragments);

        $invoice_info_html = '';
        $invoice_cta_button_html = '';
        $invoice_is_paid_flag = null;

        if ($invoice_display_details) {
            $invoice_is_paid_flag = $this->invoice_details_is_paid($invoice_display_details);

            if ($invoice_portal_link !== '') {
                $button_text = ($invoice_is_paid_flag === true) ? 'Bekijk factuur' : 'Bekijk en betaal factuur';
                $button_aria_label = ($invoice_is_paid_flag === true)
                    ? 'Bekijk factuur (opent in een nieuw tabblad)'
                    : 'Bekijk en betaal factuur (opent in een nieuw tabblad)';

                $cta_classes = ['btn', 'rmh-invoice-card__cta'];
                $cta_classes[] = ($invoice_is_paid_flag === true) ? 'rmh-invoice-card__cta--view' : 'rmh-invoice-card__cta--pay';

                $invoice_cta_button_html = '<a class="' . esc_attr(implode(' ', $cta_classes)) . '" target="_blank" rel="nofollow noopener" href="' . esc_url($invoice_portal_link) . '" aria-label="' . esc_attr($button_aria_label) . '">' . esc_html($button_text) . '</a>';
            }

            $invoice_info_html = $this->render_invoice_information_block($invoice_display_details, $invoice_cta_button_html);
        }

        $html  = '<div class="rmh-ot">';
        if ($invoice_info_html !== '') {
            if ($progress_text_html !== '') {
                $html .= '<p class="rmh-order-progress rmh-ot__summary">' . $progress_text_html . '</p>';
            }
            $html .= $invoice_info_html;
        } elseif ($progress_text_html !== '') {
            $html .= '<p class="rmh-order-progress rmh-ot__summary">' . $progress_text_html . '</p>';
        }
        // GEEN header-row meer

        // Tracklinks per itemNumber
        $tracks_by_item=[];
        if(is_array($shipments)){
            foreach($shipments as $s){
                $urls=[]; foreach(($s['tracks']??[]) as $t){ if(!empty($t['trackUrl'])) $urls[]=$t['trackUrl']; }
                foreach(($s['orderItemNumbers']??[]) as $inum){
                    if(!isset($tracks_by_item[$inum])) $tracks_by_item[$inum]=[];
                    $tracks_by_item[$inum]=array_merge($tracks_by_item[$inum],$urls);
                }
            }
        }

        $html .= '<div class="rmh-ot__items"><h3 class="rmh-ot__overview-title">Overzicht van je bestelling</h3>';
        if($items && is_array($items)){
            $html .= '<div class="rmh-ot__grid">';
            foreach($items as $index=>$it){
                $inum = $it['orderItemNumber'] ?? '';
                $qty  = isset($it['quantity']) ? (int)$it['quantity'] : null;
                $title = $this->pretty_product_title($it);

                // Afbeelding
                $img_html = '';
                if ($inum) {
                    $att_id = $this->resolve_item_image_id($inum, $item_imgs);
                    if ($att_id > 0) $img_html = wp_get_attachment_image($att_id, 'large', false, ['class'=>'rmh-ot__image']);
                }
                if (!$img_html) $img_html = $this->placeholder_svg();

                // Status badge (NL)
                $it_status = isset($it['status']) ? (string)$it['status'] : '';
                $status_badge = $it_status !== '' ? $this->item_status_badge($it_status) : '';

                // Eigenschappen & Extraʼs
                $specs = $this->build_specs_groups($it);
                $eig = $specs['eigenschappen']; $extra = $specs['extras'];

                // Bezorgadres (per item!)
                $addr = $this->extract_item_address($it);

                // Leverdata + methode (per item!)
                $dw = $this->extract_item_delivery_window($it);
                $date_big = $date_small = $carrier_lbl = '';
                if ($dw) {
                    if ($dw['start'] instanceof DateTime) $date_big = $this->fmt_day_short($dw['start']);
                    if ($dw['end']   instanceof DateTime && $dw['start'] && $dw['end']->format('Ymd') !== $dw['start']->format('Ymd')) {
                        $date_small = $this->fmt_day_short($dw['end']);
                    }
                    $carrier_lbl = $this->carrier_label_from_method((string)$dw['method']);
                }

                // Track & Trace (per item)
                $btn_html = '<a class="btn btn--track" href="#" aria-disabled="true" onclick="return false;">Wacht op verzending</a>';
                if (!empty($tracks_by_item[$inum])) {
                    $first = $tracks_by_item[$inum][0];
                    $btn_html = '<a class="btn btn--track" href="'.esc_url($first).'" target="_blank" rel="nofollow noopener">Track &amp; Trace</a>';
                }

                // Render
                $n = $index + 1;
                $html .= '<div class="rmh-ot__item">';
                $html .=   '<div class="rmh-ot__item-header">';
                $html .=     '<div class="rmh-ot__badge-top">'.$status_badge.'</div>';
                $html .=     '<h2 class="rmh-ot__title">'.$n.'. '.esc_html($title).'</h2>';
                $html .=   '</div>';

                $html .=   '<div class="rmh-ot__item-grid">';

                // kolom 1: foto
                $html .=   '<div class="rmh-ot__photo">'.$img_html.'</div>';

                // kolom 2: specs
                $html .=   '<div>';
                    $html .=     '<div class="rmh-ot__panel rmh-ot__specs">';
                if ($eig) {
                    $html .= '<h4>Eigenschappen</h4><ul>';
                    foreach ($eig as $ln) $html .= '<li>'.esc_html($ln).'</li>';
                    $html .= '</ul>';
                }
                if ($extra) {
                    $html .= '<h4>Extra&#39;s</h4>';
                    $html .= '<p>'.esc_html(implode(', ', $extra)).'</p>';
                }
                $html .=     '</div>';
                $html .=   '</div>';

                // kolom 3: adres + datum/methode + T&T knop
                $html .=   '<div class="rmh-ot__delivery">';
                // Adres
                $html .=     '<div class="rmh-ot__panel">';
                $html .=       '<h4>Bezorgadres(sen)</h4>';
                if ($addr) {
                    $fn      = trim(($addr['firstName'] ?? '').' '.($addr['lastName'] ?? ''));
                    $street  = trim(                ($addr['fullstreet'] ?? '').' '.($addr['houseNumber'] ?? ''));
                    $city    = trim(($addr['postcode'] ?? '').' '.($addr['city'] ?? ''));
                    $country = $addr['country'] ?? '';

                    $html .= '<p>';
                    if ($fn)      $html .= '<strong>'.esc_html($fn).'</strong><br>';
                    if ($street)  $html .= esc_html($street).'<br>';
                    if ($city)    $html .= esc_html($city).'<br>';
                    if ($country) $html .= esc_html($country);
                    $html .= '</p>';
                } else {
                    $html .= '<p><em>Nog geen adresinformatie beschikbaar.</em></p>';
                }
                $html .=     '</div>';

                // Datum & bezorgdienst
                $html .= '<div class="rmh-ot__panel">';

                // Vervoerder + methode
                if ($carrier_lbl) {
                    $method = $dw['method'] ?? $carrier_lbl; // fallback
                    $cinfo  = $this->carrier_logo_and_label((string)$method);

                    $html .= '<h4>Je pakket wordt bezorgd door</h4>';
                    $html .= '<div class="rmh-ot__carrier-row">';
                    if (!empty($cinfo['url'])) {
                        $html .= '<img src="'.esc_url($cinfo['url']).'" alt="'.esc_attr($cinfo['name']).'" class="rmh-ot__carrier-logo" />';
                    }
                    $html .= '<span>'.esc_html($cinfo['name']).'</span>';
                    $html .= '</div>';
                }

                // Verwachte levering
                if ($date_big) {
                    $html .= '<h4>Verwachte levering</h4>';
                    if ($date_small) {
                        $html .= '<p>'.esc_html($date_big).' tot '.esc_html($date_small).'</p>';
                    } else {
                        $html .= '<p>'.esc_html($date_big).'</p>';
                    }
                } else {
                    $html .= '<p><em>Bezorgdatum nog niet bekend.</em></p>';
                }

                $html .= '</div>';

                // Track & Trace knop / placeholder
                $html .=     '<div class="rmh-ot__panel rmh-ot__panel--track">'.$btn_html.'</div>';

                $html .=   '</div>'; // delivery col

                $html .=   '</div>'; // grid
                $html .= '</div>';   // item
            }
            $html .=   '</div>'; // grid
        } else {
            $html .= '<p><em>Er zijn nog geen producten geregistreerd voor deze bestelling.</em></p>';
        }
        $html .= '</div>'; // items
        if ($invoice_hint_html !== '') {
            $html .= $invoice_hint_html;
        }
        $html .= '</div>'; // wrapper

        return $html;
        }

    private function render_invoice_information_block(array $details, string $cta_button_html = ''): string {
        $is_paid = $this->invoice_details_is_paid($details);
        $is_partially_paid = $this->get_invoice_detail_flag($details, 'is_partially_paid');

        $badge_classes = ['rmh-invoice-card__badge'];
        $badge_classes[] = ($is_paid === true) ? 'rmh-invoice-card__badge--paid' : 'rmh-invoice-card__badge--open';

        $desktop_badge_text = ($is_paid === true) ? 'Betaald' : 'Openstaand';
        $mobile_badge_text = $desktop_badge_text;
        $badge_aria_label = $desktop_badge_text;

        if ($is_paid === true) {
            $mobile_badge_text = 'Betaald';
            $badge_aria_label = $mobile_badge_text;
        } elseif ($is_partially_paid === true) {
            $mobile_badge_text = 'Deels betaald';
            $badge_classes[] = 'rmh-invoice-card__badge--partial';
            $badge_aria_label = $mobile_badge_text;
        }

        $badge_inner_html = '<span class="rmh-invoice-card__badge-text-desktop">' . esc_html($desktop_badge_text) . '</span>';
        if ($mobile_badge_text !== '') {
            $badge_inner_html .= '<span class="rmh-invoice-card__badge-text-mobile">' . esc_html($mobile_badge_text) . '</span>';
        }

        $invoice_number = '';
        if (isset($details['invoice_number']) && is_string($details['invoice_number'])) {
            $invoice_number = trim($details['invoice_number']);
        }
        $title = $invoice_number !== '' ? sprintf('Factuur #%s', $invoice_number) : 'Factuur';

        $currency = isset($details['currency']) && is_string($details['currency']) ? $details['currency'] : null;
        $date_display = $this->format_invoice_date_display($details['invoice_date'] ?? null);
        $due_date_display = $this->format_invoice_date_display($details['due_date'] ?? null);
        $total_display = $this->format_invoice_amount($details['total'] ?? null, $currency);
        $subtotal_display = $this->format_invoice_amount($details['subtotal'] ?? null, $currency);
        $tax_value = isset($details['tax']) && is_numeric($details['tax']) ? (float) $details['tax'] : null;
        $total_value = isset($details['total']) && is_numeric($details['total']) ? (float) $details['total'] : null;
        $balance_value = $details['balance'] ?? null;
        $balance_display = $this->format_invoice_amount($balance_value, $currency);

        $meta_rows = [];
        if ($date_display) {
            $meta_rows[] = ['label' => 'Factuurdatum', 'value' => $date_display, 'modifier' => 'date'];
        }
        if ($due_date_display) {
            $meta_rows[] = ['label' => 'Vervaldatum', 'value' => $due_date_display, 'modifier' => 'date'];
        }
        $excl_total_display = $subtotal_display;
        if ($excl_total_display === null && $total_value !== null && $tax_value !== null) {
            $excl_total_display = $this->format_invoice_amount($total_value - $tax_value, $currency);
        }
        if ($excl_total_display === null && $total_display !== null) {
            $excl_total_display = $total_display;
        }
        if ($excl_total_display !== null) {
            $meta_rows[] = ['label' => 'Totaal (excl. btw)', 'value' => $excl_total_display, 'modifier' => 'amount-subtle'];
        }
        if ($total_display) {
            $meta_rows[] = ['label' => 'Totaal (incl. btw)', 'value' => $total_display, 'modifier' => 'amount-total'];
        }
        $show_balance = false;
        if ($balance_value !== null) {
            $numeric_balance = (float) $balance_value;
            if ($numeric_balance > 0.009) {
                $show_balance = true;
            } elseif ($is_paid === false && $numeric_balance > 0) {
                $show_balance = true;
            }
        }
        $meta_html = '';
        if ($meta_rows) {
            $meta_html .= '<div class="rmh-invoice-card__meta">';
            foreach ($meta_rows as $row) {
                $classes = ['rmh-invoice-card__meta-item'];
                if (!empty($row['modifier'])) {
                    $classes[] = 'rmh-invoice-card__meta-item--' . preg_replace('/[^a-z0-9_-]/i', '', (string) $row['modifier']);
                }
                $meta_html .= '<div class="' . esc_attr(implode(' ', $classes)) . '"><span class="rmh-invoice-card__meta-label">' . esc_html($row['label']) . '</span><span class="rmh-invoice-card__meta-value">' . esc_html($row['value']) . '</span></div>';
            }
            $meta_html .= '</div>';
        }

        $card_status = 'open';
        if ($is_paid === true) {
            $card_status = 'paid';
        } elseif ($is_partially_paid === true) {
            $card_status = 'partial';
        }

        $footer_summary_html = '';
        if ($show_balance && $balance_display && $card_status !== 'paid') {
            $footer_summary_html = '<div class="rmh-invoice-card__footer-summary"><span class="rmh-invoice-card__footer-label">Openstaand:</span><span class="rmh-invoice-card__footer-amount">' . esc_html($balance_display) . '</span></div>';
        }

        $status_classes = ['rmh-invoice-card__status'];
        $status_message = '';
        if ($card_status === 'paid') {
            $status_classes[] = 'rmh-invoice-card__status--paid';
            $status_message = 'Factuur betaald';
        } elseif ($card_status === 'partial') {
            $status_classes[] = 'rmh-invoice-card__status--partial';
            $status_message = 'Factuur gedeeltelijk betaald';
        } else {
            $status_classes[] = 'rmh-invoice-card__status--open';
            $status_message = 'Factuur openstaand';
        }

        $status_html = '<div class="' . esc_attr(implode(' ', $status_classes)) . '" role="status"><span class="rmh-invoice-card__status-icon" aria-hidden="true"></span><span class="rmh-invoice-card__status-text">' . esc_html($status_message) . '</span></div>';

        $html  = '<div class="rmh-ot__items rmh-ot__items--invoice">';
        $html .=   '<h3>Overzicht van je factuur</h3>';
        $html .=   '<section class="rmh-invoice-card" aria-label="Factuurinformatie" data-invoice-status="' . esc_attr($card_status) . '">';
        $html .=   '<div class="rmh-invoice-card__header">';
        $html .=     '<h3 class="rmh-invoice-card__title">' . esc_html($title) . '</h3>';
        $html .=     '<span class="' . esc_attr(implode(' ', $badge_classes)) . '" aria-label="' . esc_attr($badge_aria_label) . '">' . $badge_inner_html . '</span>';
        $html .=     '<div class="rmh-invoice-card__status-region">' . $status_html . '</div>';
        $html .=   '</div>';
        $body_html = $meta_html;
        if ($body_html !== '') {
            $html .=   '<div class="rmh-invoice-card__body">' . $body_html . '</div>';
        }
        $footer_has_content = ($footer_summary_html !== '' || $cta_button_html !== '');
        if ($footer_has_content) {
            $html .=   '<div class="rmh-invoice-card__footer">';
            if ($footer_summary_html !== '') {
                $html .= $footer_summary_html;
            }
            if ($cta_button_html !== '') {
                $html .= '<div class="rmh-invoice-card__footer-action">' . $cta_button_html . '</div>';
            }
            $html .=   '</div>';
        }
        $html .=   '</section>';
        $html .= '</div>';

        return $html;
    }

    private function build_intro_summary(int $product_count, string $overall_status, array $order_data, ?array $invoice_details): string {
        $count_display = esc_html(number_format_i18n($product_count));
        $product_label = esc_html($product_count === 1 ? 'product' : 'producten');

        $sentences = [];
        $sentences[] = sprintf(
            'Je bestelling bevat <span class="rmh-order-progress__count rmh-ot__summary-count">%s</span> %s.',
            $count_display,
            $product_label
        );

        $order_sentence = $this->build_order_status_sentence($overall_status, $order_data);
        if ($order_sentence !== '') {
            $sentences[] = $order_sentence;
        }

        $invoice_sentence = $this->build_invoice_sentence($invoice_details);
        if ($invoice_sentence !== '') {
            $sentences[] = $invoice_sentence;
        }

        $sentences = array_values(array_filter($sentences));
        if (!$sentences) {
            return '';
        }

        return implode(' ', $sentences);
    }

    private function build_mobile_intro_summary(int $product_count, string $overall_status, array $order_data, ?array $invoice_details): string {
        $count_display = esc_html(number_format_i18n($product_count));
        $product_label = esc_html($product_count === 1 ? 'product' : 'producten');
        $status_phrase = $this->determine_order_status_phrase($overall_status, $order_data);

        if ($status_phrase === '') {
            $status_phrase = 'in behandeling';
        }

        $first_sentence = sprintf(
            'Je bestelling bevat <span class="rmh-order-progress__count rmh-ot__summary-count">%s</span> %s en is <span class="rmh-order-progress__status rmh-ot__summary-status">%s</span>.',
            $count_display,
            $product_label,
            esc_html($status_phrase)
        );

        $invoice_sentence = $this->build_mobile_invoice_sentence($invoice_details);

        return trim($first_sentence . ' ' . $invoice_sentence);
    }

    private function build_order_status_sentence(string $overall_status, array $order_data): string {
        $status_phrase = $this->determine_order_status_phrase($overall_status, $order_data);

        if ($status_phrase === '') {
            $status_phrase = 'in behandeling';
        }

        return 'Je bestelling is <span class="rmh-order-progress__status rmh-ot__summary-status">' . esc_html($status_phrase) . '</span>.';
    }

    private function determine_order_status_phrase(string $overall_status, array $order_data): string {
        $candidate_statuses = [];
        foreach (['order_status', 'orderStatus', 'status', 'statusCode', 'orderStatusCode'] as $status_key) {
            if (!empty($order_data[$status_key]) && is_string($order_data[$status_key])) {
                $candidate_statuses[] = strtolower(trim((string) $order_data[$status_key]));
            }
        }

        $normalized_code = '';
        foreach ($candidate_statuses as $candidate) {
            $normalized = str_replace([' ', '-'], '_', $candidate);
            if ($normalized !== '') {
                $normalized_code = $normalized;
                break;
            }
        }

        $status_phrase = '';
        if ($normalized_code !== '') {
            if (in_array($normalized_code, ['delivered', 'bezorgd'], true)) {
                $status_phrase = 'geleverd';
            } elseif (in_array($normalized_code, ['partially_delivered', 'partial_delivered', 'partially_shipped', 'gedeeltelijk_verzonden'], true)) {
                $status_phrase = 'deels geleverd';
            } elseif (in_array($normalized_code, ['shipped', 'verzonden', 'in_transit', 'intransit'], true)) {
                $status_phrase = 'verzonden';
            } elseif (in_array($normalized_code, ['readyforproduction', 'ready_for_production', 'in_production', 'production', 'printed', 'finished', 'acceptedbysupplier', 'accepted_by_supplier'], true)) {
                $status_phrase = 'in productie';
            } elseif (in_array($normalized_code, ['processing', 'orderreceived', 'order_received', 'draft', 'manualcheck', 'in_behandeling'], true)) {
                $status_phrase = 'in behandeling';
            } elseif (in_array($normalized_code, ['cancelled', 'canceled', 'geannuleerd'], true)) {
                $status_phrase = 'geannuleerd';
            }
        }

        if ($status_phrase === '') {
            $overall_lower = strtolower($overall_status);
            if (strpos($overall_lower, 'geannuleerd') !== false) {
                $status_phrase = 'geannuleerd';
            } elseif (strpos($overall_lower, 'gedeeltelijk') !== false || strpos($overall_lower, 'deels') !== false) {
                $status_phrase = 'deels geleverd';
            } elseif (strpos($overall_lower, 'geleverd') !== false || strpos($overall_lower, 'bezorgd') !== false) {
                $status_phrase = 'geleverd';
            } elseif (strpos($overall_lower, 'verzonden') !== false) {
                $status_phrase = 'verzonden';
            } elseif (strpos($overall_lower, 'productie') !== false) {
                $status_phrase = 'in productie';
            } elseif (strpos($overall_lower, 'behandeling') !== false) {
                $status_phrase = 'in behandeling';
            }
        }

        if ($status_phrase === '') {
            $status_phrase = 'in behandeling';
        }

        return $status_phrase;
    }

    private function build_invoice_sentence(?array $invoice_details): string {
        if (!is_array($invoice_details) || !$invoice_details) {
            return '';
        }

        $is_paid = $this->invoice_details_is_paid($invoice_details);
        $is_partially_paid = $this->get_invoice_detail_flag($invoice_details, 'is_partially_paid');
        $is_overdue = $this->get_invoice_detail_flag($invoice_details, 'is_overdue');

        if ($is_paid === true) {
            return 'De factuur is betaald. Hieronder vind je het overzicht van je factuur en bestelling.';
        }

        if ($is_partially_paid === true) {
            return 'De factuur is deels betaald. Je kunt het resterende bedrag hieronder voldoen.';
        }

        $invoice_open = ($is_paid === false || $is_paid === null);
        if ($invoice_open && $is_overdue === true) {
            return 'De factuur staat open en is vervallen. Je kunt hieronder direct betalen.';
        }

        if ($invoice_open) {
            return 'De factuur staat open. Je kunt hieronder direct betalen.';
        }

        if ($is_overdue === true) {
            return 'De factuur staat open en is vervallen. Je kunt hieronder direct betalen.';
        }

        return 'Hieronder vind je het overzicht van je factuur en bestelling.';
    }

    private function build_mobile_invoice_sentence(?array $invoice_details): string {
        if (!is_array($invoice_details) || !$invoice_details) {
            return 'Hieronder vind je het overzicht van je factuur en bestelling.';
        }

        $is_paid = $this->invoice_details_is_paid($invoice_details);
        $is_partially_paid = $this->get_invoice_detail_flag($invoice_details, 'is_partially_paid');
        $is_overdue = $this->get_invoice_detail_flag($invoice_details, 'is_overdue');

        if ($is_paid === true) {
            return 'De factuur is volledig betaald. Hieronder vind je het overzicht van je factuur en bestelling.';
        }

        if ($is_partially_paid === true) {
            return 'De factuur is deels betaald; het resterende bedrag kun je hieronder voldoen.';
        }

        $invoice_open = ($is_paid === false || $is_paid === null);
        if ($invoice_open && $is_overdue === true) {
            return 'De factuur staat open en is vervallen; je kunt het bedrag hieronder direct voldoen.';
        }

        if ($invoice_open) {
            return 'De factuur staat nog open en kan hieronder direct betaald worden.';
        }

        if ($is_overdue === true) {
            return 'De factuur staat open en is vervallen; je kunt het bedrag hieronder direct voldoen.';
        }

        return 'Hieronder vind je het overzicht van je factuur en bestelling.';
    }

    private function get_invoice_detail_flag(array $details, string $key): ?bool {
        if (!array_key_exists($key, $details)) {
            return null;
        }

        $value = $details[$key];
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    private function invoice_details_is_paid(array $details): ?bool {
        $flag = null;
        if (array_key_exists('is_paid', $details)) {
            if ($details['is_paid'] === null) {
                $flag = null;
            } else {
                $flag = (bool) $details['is_paid'];
            }
        }

        if (array_key_exists('balance', $details) && $details['balance'] !== null) {
            $balance = (float) $details['balance'];
            if (abs($balance) <= 0.009) {
                $flag = true;
            } elseif ($balance > 0) {
                $flag = false;
            } elseif ($balance < 0) {
                $flag = true;
            }
        }

        if ($flag === null && array_key_exists('total', $details) && $details['total'] !== null) {
            $total = (float) $details['total'];
            if ($total <= 0) {
                $flag = true;
            }
        }

        return $flag;
    }

    private function format_invoice_date_display($raw): ?string {
        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
        } else {
            $tz_string = function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string');
            if (!is_string($tz_string) || $tz_string === '') {
                $tz_string = 'UTC';
            }
            try {
                $timezone = new \DateTimeZone($tz_string);
            } catch (\Exception $e) {
                $timezone = new \DateTimeZone('UTC');
            }
        }

        $timestamp = null;

        try {
            $dt = new \DateTimeImmutable($raw, $timezone);
            $timestamp = $dt->getTimestamp();
        } catch (\Exception $e) {
            $fallback = strtotime($raw);
            if ($fallback !== false) {
                $timestamp = (int) $fallback;
            } elseif (preg_match('/^\d{8}$/', $raw)) {
                $iso = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
                $fallback_iso = strtotime($iso);
                if ($fallback_iso !== false) {
                    $timestamp = (int) $fallback_iso;
                }
            }
        }

        if ($timestamp !== null) {
            return $this->format_timestamp_dutch($timestamp, $timezone);
        }

        return $raw;
    }

    private function format_timestamp_dutch(int $timestamp, \DateTimeZone $timezone): string {
        try {
            $dt = new \DateTimeImmutable('@' . $timestamp);
        } catch (\Exception $e) {
            $dt = \DateTimeImmutable::createFromFormat('U', (string) $timestamp);
            if (!$dt) {
                $dt = new \DateTimeImmutable('@0');
                $dt = $dt->setTimestamp($timestamp);
            }
        }

        if ($dt instanceof \DateTimeImmutable) {
            $dt = $dt->setTimezone($timezone);
        }

        $day = (int) $dt->format('j');
        $month_number = (int) $dt->format('n');
        $year = $dt->format('Y');

        $month_names = [
            1 => 'januari',
            2 => 'februari',
            3 => 'maart',
            4 => 'april',
            5 => 'mei',
            6 => 'juni',
            7 => 'juli',
            8 => 'augustus',
            9 => 'september',
            10 => 'oktober',
            11 => 'november',
            12 => 'december',
        ];

        $month = $month_names[$month_number] ?? strtolower($dt->format('F'));
        if (!isset($month_names[$month_number]) && function_exists('mb_strtolower')) {
            $month = mb_strtolower($dt->format('F'));
        }

        return sprintf('%d %s %s', $day, $month, $year);
    }

    private function format_invoice_amount($amount, ?string $currency): ?string {
        if (!is_numeric($amount)) {
            return null;
        }

        $formatted = number_format_i18n((float) $amount, 2);
        $symbol = $this->currency_symbol_for_code($currency);
        if ($symbol) {
            return $symbol . ' ' . $formatted;
        }

        if (is_string($currency) && $currency !== '') {
            return $formatted . ' ' . strtoupper($currency);
        }

        return '€ ' . $formatted;
    }

    private function currency_symbol_for_code(?string $currency): ?string {
        if (!is_string($currency)) {
            return null;
        }

        $code = strtoupper(trim($currency));
        if ($code === '') {
            return null;
        }

        $map = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
        ];

        return $map[$code] ?? null;
    }

    private function resolve_invoice_ninja_invoice_id($map_entry, array $order_data, string $own_order, string $print_order): ?string {
        if (!is_array($map_entry)) {
            $map_entry = [];
        }

        $candidates = [];

        if (!empty($map_entry['invoice_id'])) {
            $candidates[] = $map_entry['invoice_id'];
        }

        foreach (['invoiceId','invoice_id','invoiceUuid','invoice_uuid','invoiceNumber','invoice_number'] as $field) {
            if (!empty($order_data[$field])) {
                $candidates[] = $order_data[$field];
            }
        }

        if (!empty($order_data['invoiceIds']) && is_array($order_data['invoiceIds'])) {
            foreach ($order_data['invoiceIds'] as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $candidates[] = $value;
                    break;
                }
            }
        }

        if (!empty($order_data['financial']) && is_array($order_data['financial'])) {
            foreach (['invoiceId','invoice_id','invoiceUuid','invoice_uuid'] as $field) {
                if (!empty($order_data['financial'][$field])) {
                    $candidates[] = $order_data['financial'][$field];
                }
            }
        }

        $invoice_id = '';
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                $candidate = trim((string) $candidate);
                if ($candidate !== '') {
                    $invoice_id = $candidate;
                    break;
                }
            }
        }

        /**
         * Bepaal het Invoice Ninja ID voor deze order.
         *
         * @param string $invoice_id  Huidige gevonden invoice ID (kan leeg zijn).
         * @param array  $order_data  Orderdata van de Print.com API.
         * @param array  $map_entry   Mapping info voor deze order.
         * @param string $own_order   Eigen ordernummer.
         * @param string $print_order Print.com ordernummer.
         */
        $invoice_id = apply_filters('rmh_invoice_ninja_invoice_id', $invoice_id, $order_data, $map_entry, $own_order, $print_order);
        if (!is_string($invoice_id)) {
            $invoice_id = '';
        }

        $invoice_id = trim($invoice_id);

        return $invoice_id !== '' ? $invoice_id : null;
    }

    private function pretty_product_title(array $item): string {
        $qty  = isset($item['quantity']) ? (int)$item['quantity'] : 1;
        $tr   = $item['productTranslation'] ?? [];
        $name = $item['name'] ?? ($item['productName'] ?? 'Product');
        $label = $name;
        if (!empty($tr['titleSingle']) || !empty($tr['titlePlural'])) {
            $label = ($qty === 1 ? ($tr['titleSingle'] ?? $name) : ($tr['titlePlural'] ?? $name));
        }
        return $label;
    }

    private function human_status(array $order) : string {
        $items = $order['items'] ?? [];
        $ships = $order['shipments'] ?? [];
        if ($items) {
            $flags=[];
            foreach($items as $it){ if(!empty($it['orderItemNumber'])) $flags[$it['orderItemNumber']]=false; }
            foreach($ships as $s){ foreach(($s['orderItemNumbers']??[]) as $n){ if(isset($flags[$n])) $flags[$n]=true; } }
            if ($flags && count(array_filter($flags))===count($flags)) return 'Verzonden';
            if (count(array_filter($flags))>0) return 'Deels verzonden';
        }
        $s = strtolower((string)($order['status'] ?? ''));
        if ($s==='delivered') return 'Bezorgd';
        if ($s==='intransit') return 'Onderweg';
        if ($s==='shipped') return 'Verzonden';
        if ($s==='readyforproduction') return 'Gereed voor productie';
        if ($s==='printed' || $s==='finished') return 'Gereed / Afgewerkt';
        if ($s==='acceptedbysupplier' || $s==='orderreceived' || $s==='draft' || $s==='processing') return 'In behandeling';
        if ($s==='cancelled' || $s==='canceled' || $s==='refusedbysupplier') return 'Geannuleerd';
        return $items ? 'In behandeling' : 'Onbekend';
    }

    private function determine_overall_order_status(array $order): string {
        $items = $order['items'] ?? [];
        $counts = [
            'delivered'  => 0,
            'shipped'    => 0,
            'production' => 0,
            'processing' => 0,
            'cancelled'  => 0,
            'unknown'    => 0,
        ];
        $total = 0;

        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $total++;
                $class = $this->classify_item_status(isset($item['status']) ? (string)$item['status'] : '');
                switch ($class) {
                    case 'bezorgd':
                        $counts['delivered']++;
                        break;
                    case 'verzonden':
                    case 'deels':
                        $counts['shipped']++;
                        break;
                    case 'inproductie':
                        $counts['production']++;
                        break;
                    case 'behandelen':
                        $counts['processing']++;
                        break;
                    case 'geannuleerd':
                        $counts['cancelled']++;
                        break;
                    default:
                        $counts['unknown']++;
                        break;
                }
            }
        }

        if ($total > 0) {
            if ($counts['cancelled'] === $total) {
                return 'Geannuleerd';
            }
            if ($counts['cancelled'] > 0) {
                return 'Deels geannuleerd';
            }
            if ($counts['delivered'] === $total) {
                return 'Geleverd';
            }
            if (($counts['shipped'] + $counts['delivered']) === $total) {
                return 'Verzonden';
            }
            if (($counts['shipped'] + $counts['delivered']) > 0 && ($counts['production'] > 0 || $counts['processing'] > 0 || $counts['unknown'] > 0)) {
                return 'Gedeeltelijk verzonden';
            }
            if ($counts['production'] > 0 && $counts['shipped'] === 0 && $counts['delivered'] === 0 && $counts['cancelled'] === 0 && $counts['unknown'] === 0) {
                return 'In productie';
            }
            if (($counts['processing'] + $counts['unknown']) === $total) {
                return 'In behandeling';
            }
            if ($counts['processing'] > 0 && $counts['shipped'] === 0 && $counts['delivered'] === 0 && $counts['production'] === 0) {
                return 'In behandeling';
            }
        }

        return $this->fallback_overall_status($order['status'] ?? '');
    }

    private function fallback_overall_status($status): string {
        $status_str = is_string($status) ? $status : (string)$status;
        $s = strtoupper(trim($status_str));
        if ($s === '') {
            return 'In behandeling';
        }
        if ($s === 'DELIVERED') {
            return 'Geleverd';
        }
        if (in_array($s, ['SHIPPED', 'INTRANSIT'], true)) {
            return 'Verzonden';
        }
        if (in_array($s, ['CANCELLED', 'CANCELED', 'REFUSEDBYSUPPLIER'], true)) {
            return 'Geannuleerd';
        }
        if (in_array($s, ['READYFORPRODUCTION', 'PREPAREDFORPRINT', 'PRINTED', 'CUT', 'FINISHED', 'ACCEPTEDBYSUPPLIER', 'QUALITYAPPROVED', 'INPRODUCTION'], true)) {
            return 'In productie';
        }
        if (in_array($s, ['ORDERRECEIVED', 'DRAFT', 'PROCESSING', 'SENTTOSUPPLIER', 'PACKED', 'MANUALCHECK', 'DESIGNADDED', 'DESIGNCONFIRMED', 'DESIGNREJECTED', 'DESIGNWARNING', 'WAITINGFORPAYMENT', 'ERROR'], true)) {
            return 'In behandeling';
        }
        return 'In behandeling';
    }

    /* ===== Metaboxes ===== */

    public function add_metaboxes() {
        add_meta_box('printcom_ot_image','Orderafbeelding (optioneel) — verouderd',[$this,'metabox_order_image'],'page','side','default');
        add_meta_box('printcom_ot_item_images','Productfoto’s (per item)',[$this,'metabox_item_images'],'page','normal','default');
    }

    public function metabox_order_image($post) {
        if (strpos($post->post_content,'[print_order_status')===false){ echo '<p>Deze pagina lijkt geen Print.com orderpagina te zijn.</p>'; return; }
        wp_enqueue_media();
        $id=(int)get_post_meta($post->ID,self::META_IMG_ID,true);
        $src=$id?wp_get_attachment_image_src($id,'medium'):null;
        ?>
        <p>Oud veld (1 afbeelding voor de hele pagina). Gebruik liever de metabox “Productfoto’s (per item)”.</p>
        <div id="rmh-ot-image-preview" style="margin-bottom:10px;"><?php echo $src?'<img src="'.esc_url($src[0]).'" style="max-width:100%;height:auto;" />':'<em>Geen afbeelding gekozen.</em>'; ?></div>
        <input type="hidden" id="rmh-ot-image-id" name="printcom_ot_image_id" value="<?php echo esc_attr($id); ?>"/>
        <button type="button" class="button" id="rmh-ot-image-upload">Afbeelding kiezen</button>
        <button type="button" class="button" id="rmh-ot-image-remove" <?php disabled(!$id); ?>>Verwijderen</button>
        <script>
        (function($){$(function(){
            let frame;
            $('#rmh-ot-image-upload').on('click',function(e){e.preventDefault(); if(frame){frame.open();return;}
                frame = wp.media({title:'Kies of upload afbeelding',button:{text:'Gebruik deze afbeelding'},multiple:false});
                frame.on('select',function(){const a=frame.state().get('selection').first().toJSON();$('#rmh-ot-image-id').val(a.id);$('#rmh-ot-image-preview').html('<img src="'+a.url+'" style="max-width:100%;height:auto;" />');$('#rmh-ot-image-remove').prop('disabled',false);});
                frame.open();
            });
            $('#rmh-ot-image-remove').on('click',function(e){e.preventDefault();$('#rmh-ot-image-id').val('');$('#rmh-ot-image-preview').html('<em>Geen afbeelding gekozen.</em>');$(this).prop('disabled',true);});
        });})(jQuery);
        </script>
        <?php
    }

    public function metabox_item_images($post) {
        if (strpos($post->post_content,'[print_order_status')===false){
            echo '<p>Deze pagina lijkt geen Print.com orderpagina te zijn.</p>';
            return;
        }
        wp_enqueue_media();
        $map = get_post_meta($post->ID, self::META_ITEM_IMGS, true);
        if (!is_array($map)) $map = [];
        ?>
        <p>Voeg per <strong>orderItemNumber</strong> een afbeelding toe. Deze verschijnt bij het juiste product op de bestelpagina.</p>
        <table class="widefat striped" id="rmh-ot-item-table">
            <thead>
                <tr>
                    <th style="width:220px;">orderItemNumber</th>
                    <th>Afbeelding</th>
                    <th style="width:100px;">Actie</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($map): foreach ($map as $k=>$att_id):
                $src = $att_id ? wp_get_attachment_image_src((int)$att_id, 'thumbnail') : null; ?>
                <tr>
                    <td>
                        <input type="text" name="printcom_ot_item_key[]" value="<?php echo esc_attr($k); ?>" class="regular-text" placeholder="bijv. 6001831441-2"/>
                    </td>
                    <td>
                        <div class="rmh-ot-item-prev"><?php echo $src?'<img src="'.esc_url($src[0]).'" style="max-height:60px" />':'<em>Geen</em>'; ?></div>
                        <input type="hidden" name="printcom_ot_item_media[]" value="<?php echo esc_attr((int)$att_id); ?>"/>
                        <button type="button" class="button rmh-ot-pick">Kies/Upload</button>
                        <button type="button" class="button rmh-ot-clear">X</button>
                    </td>
                    <td>
                        <button type="button" class="button link-delete rmh-ot-row-del">Verwijderen</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">
                        <button type="button" class="button" id="rmh-ot-row-add">+ Regel toevoegen</button>
                    </td>
                </tr>
            </tfoot>
        </table>
        <script>
        (function($){
          $(function(){
            const $tbl = $('#rmh-ot-item-table tbody');

            // Nieuwe regel toevoegen (let op type="button" om submit te voorkomen)
            $('#rmh-ot-row-add').on('click', function(e){
              e.preventDefault();
              $tbl.append(`<tr>
                <td><input type="text" name="printcom_ot_item_key[]" value="" class="regular-text" placeholder="bijv. 6001831441-2"/></td>
                <td>
                  <div class="rmh-ot-item-prev"><em>Geen</em></div>
                  <input type="hidden" name="printcom_ot_item_media[]" value=""/>
                  <button type="button" class="button rmh-ot-pick">Kies/Upload</button>
                  <button type="button" class="button rmh-ot-clear">X</button>
                </td>
                <td><button type="button" class="button link-delete rmh-ot-row-del">Verwijderen</button></td>
              </tr>`);
            });

            // Rij verwijderen
            $(document).on('click', '.rmh-ot-row-del', function(e){
              e.preventDefault();
              $(this).closest('tr').remove();
            });

            // Belangrijk: per klik een NIEUW media frame zodat de selectie naar de juiste rij gaat
            $(document).on('click', '.rmh-ot-pick', function(e){
              e.preventDefault();
              const $td = $(this).closest('td');

              const frame = wp.media({
                title: 'Kies of upload afbeelding',
                button: { text: 'Gebruik deze afbeelding' },
                multiple: false
              });
            
              frame.on('select', function(){
                const a = frame.state().get('selection').first().toJSON();
                $td.find('input[type=hidden]').val(a.id);
                $td.find('.rmh-ot-item-prev').html('<img src="'+a.url+'" style="max-height:60px"/>');
              });

              frame.open();
            });

            // Clear
            $(document).on('click', '.rmh-ot-clear', function(e){
              e.preventDefault();
              const $td = $(this).closest('td');
              $td.find('input[type=hidden]').val('');
              $td.find('.rmh-ot-item-prev').html('<em>Geen</em>');
            });
          });
        })(jQuery);
        </script>
        <?php
    }

    public function save_metaboxes($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$post_id)) return;
        if (isset($_POST['printcom_ot_image_id'])) {
            $id=(int)$_POST['printcom_ot_image_id'];
            if ($id>0) update_post_meta($post_id,self::META_IMG_ID,$id); else delete_post_meta($post_id,self::META_IMG_ID);
        }
        if (isset($_POST['printcom_ot_item_key'], $_POST['printcom_ot_item_media']) && is_array($_POST['printcom_ot_item_key'])) {
            $keys=array_map('sanitize_text_field',array_map('wp_unslash',$_POST['printcom_ot_item_key']));
            $meds=array_map('intval',$_POST['printcom_ot_item_media']);
            $map=[];
            for($i=0;$i<count($keys);$i++){ $k=trim($keys[$i]??''); $m=(int)($meds[$i]??0); if($k!=='' && $m>0) $map[$k]=$m; }
            if($map) update_post_meta($post_id,self::META_ITEM_IMGS,$map); else delete_post_meta($post_id,self::META_ITEM_IMGS);
        }
    }

    private function placeholder_svg(): string {
        return '<svg class="rmh-ot__image" role="img" aria-label="Afbeelding volgt" xmlns="http://www.w3.org/2000/svg" width="600" height="338" viewBox="0 0 600 338"><rect width="100%" height="100%" fill="#f2f2f2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#999" font-family="Arial,Helvetica,sans-serif" font-size="18">Afbeelding volgt</text></svg>';
    }

    private function resolve_item_image_id(string $orderItemNumber, array $map): int {
        // 1) exact
        if (isset($map[$orderItemNumber]) && (int)$map[$orderItemNumber] > 0) {
            return (int)$map[$orderItemNumber];
        }
        // 2) exact maar getrimd / zonder quotes
        $k = trim($orderItemNumber);
        if ((substr($k,0,1)==='"' && substr($k,-1)==='"')||(substr($k,0,1)==="'" && substr($k,-1)==="'")) $k = substr($k,1,-1);
        if (isset($map[$k]) && (int)$map[$k] > 0) {
            return (int)$map[$k];
        }
        // 3) suffix-match: als beheerder alleen "-2" of "2" heeft ingevuld
        if (preg_match('/-(\d+)$/', $orderItemNumber, $m)) {
            $suffix = '-'.$m[1];
            foreach ($map as $key => $id) {
                if (!is_string($key)) continue;
                $kk = trim($key);
                if (substr($kk, -strlen($suffix)) === $suffix && (int)$id > 0) {
                    return (int)$id;
                }
                    if ($kk === $m[1] && (int)$id > 0) {
                    return (int)$id;
                }
            }
        }
        return 0;
    }

    private function item_status_nl(string $s): string {
        $map = [
            'ACCEPTEDBYSUPPLIER'   => 'In productie',
            'CANCELEDBYSUPPLIER'   => 'Geannuleerd door RikkerMediaHub',
            'CANCELEDBYUSER'       => 'Geannuleerd op verzoek van klant',
            'CUT'                  => 'Gesneden',
            'DELIVERED'            => 'Bezorgd',

            'DESIGNADDED'          => 'Ontwerp ontvangen',
            'DESIGNCONFIRMED'      => 'Ontwerp bevestigd',
            'DESIGNREJECTED'       => 'Ontwerp afgekeurd',
            'DESIGNWARNING'        => 'Ontwerp waarschuwing',

            'DRAFT'                => 'Concept',
            'FINISHED'             => 'Afgewerkt',
            'INTRANSIT'            => 'Onderweg',
            'MANUALCHECK'          => 'Handmatige controle',
            'ERROR'                => 'Fout',
            'WAITINGFORPAYMENT'    => 'Wacht op betaling',
            'ORDERRECEIVED'        => 'Bestelling ontvangen',
            'PACKED'               => 'Ingepakt',
            'POSSIBLYDELAYED'      => 'Mogelijk vertraagd',
            'PREPAREDFORPRINT'     => 'Gereed voor druk',
            'PRINTED'              => 'Gedrukt',

            'QUALITYAPPROVED'      => 'Kwaliteit goedgekeurd',
            'QUALITYREJECTED'      => 'Kwaliteit afgekeurd',

            'READYFORPRODUCTION'   => 'Gereed voor productie',
            'REFUSEDBYSUPPLIER'    => 'Geweigerd door leverancier',
            'RETURNED'             => 'Geretourneerd',
            'RETURNREQUESTED'      => 'Retour aangevraagd',
            'SENTTOSUPPLIER'       => 'Verstuurd naar leverancier',
            'SHIPPED'              => 'Verzonden',
        ];
        $s = strtoupper($s);
        return $map[$s] ?? ucfirst(strtolower($s));
    }

    private function tr_label(array $tr, string $key, string $fallback='') : string {
        return isset($tr[$key]) && is_string($tr[$key]) && $tr[$key] !== '' ? $tr[$key] : $fallback;
    }

    private function tr_prop_opt(array $tr, string $prop, string $val, string $fallback='') : string {
        $k = 'property.'.$prop.'.option.'.$val;
        return $this->tr_label($tr, $k, $fallback);
    }

    /** Bouwt "Eigenschappen" + "Extra's" tekstregels op basis van options + vertalingen */
    private function build_specs_groups(array $item) : array {
        $opts = $item['options'] ?? [];
        $tr   = $item['productTranslation'] ?? [];

        $eig = []; $extra = [];

        // Aantal (stuks)
        $copies = isset($opts['copies']) ? (int)$opts['copies'] : (isset($item['quantity'])?(int)$item['quantity']:0);
        $stuks  = $copies ? number_format($copies, 0, ',', '.') . ' stuks' : '';

        // Printtype (4/4 etc.) + Printingmethod (Offset/Digitaal)
        $pt_raw = isset($opts['printtype']) ? (string)$opts['printtype'] : '';
        $pt     = $pt_raw ? $this->tr_prop_opt($tr, 'printtype', $pt_raw, $pt_raw) : '';
        $pm_raw = isset($opts['printingmethod']) ? (string)$opts['printingmethod'] : '';
        $pm     = $pm_raw ? $this->tr_prop_opt($tr, 'printingmethod', $pm_raw, ucfirst($pm_raw)) : '';

        // Size
        $size_code = isset($opts['size']) ? (string)$opts['size'] : '';
        $size_lbl  = $size_code ? $this->tr_prop_opt($tr, 'size', $size_code, '') : '';
        if (!$size_lbl) {
            // fallback uit mm
            $w = isset($opts['width']) ? (string)$opts['width'] : '';
            $h = isset($opts['height'])? (string)$opts['height']: '';
            if ($w && $h) $size_lbl = $w.' x '.$h.' mm';
        }

        // Materiaal
        $mat_code = isset($opts['material']) ? (string)$opts['material'] : '';
        $material = $mat_code ? $this->tr_prop_opt($tr, 'material', $mat_code, '') : '';

        // === EIGENSCHAPPEN samenstellen ===
        $line1 = trim(implode(', ', array_filter([$stuks, $pt, $pm])));
        if ($line1) $eig[] = $line1;
        if ($size_lbl) $eig[] = $size_lbl;
        if ($material) $eig[] = $material;

        // === EXTRAʼS ===
        // Schoonsnijden
        if (isset($opts['clean_cut']) && $this->tr_prop_opt($tr, 'clean_cut', (string)$opts['clean_cut']) !== $this->tr_prop_opt($tr, 'clean_cut', 'no')) {
            $extra[] = 'Schoonsnijden';
        }
        // Laminaat/finish (negeer "geen")
        if (!empty($opts['finish'])) {
            $f = $this->tr_prop_opt($tr, 'finish', (string)$opts['finish'], '');
            if ($f && stripos($f, 'Geen') === false) $extra[] = $f;
        }
        // Rillen/creasing (negeer "niet gerild")
        if (isset($opts['creasing'])) {
            $c = $this->tr_prop_opt($tr, 'creasing', (string)$opts['creasing'], '');
            if ($c && stripos($c, 'Niet gerild') === false) $extra[] = $c;
        }
        // Vouwen/fold → Plano geleverd (ongevouwen)
        if (!empty($opts['fold'])) {
            $fold_lbl = $this->tr_prop_opt($tr, 'fold', (string)$opts['fold'], '');
            if ($fold_lbl) {
                if (stripos($fold_lbl, 'Ongevouwen') !== false || stripos($fold_lbl, 'Plano') !== false) {
                    $extra[] = 'Plano geleverd (ongevouwen)';
                } elseif (stripos($fold_lbl, 'Ongevouwen') === false) {
                    $extra[] = $fold_lbl;
                }
            }
        }
        // Bundelen / verpakking / extras
        if (!empty($opts['standard_bundle']) && $this->tr_prop_opt($tr,'standard_bundle',(string)$opts['standard_bundle'],'') ){
            $extra[] = $this->tr_prop_opt($tr,'standard_bundle',(string)$opts['standard_bundle'],'Bundelen');
        }
        if (!empty($opts['packaging_extras'])) {
            $extra[] = $this->tr_prop_opt($tr,'packaging_extras',(string)$opts['packaging_extras'],'Verpakking');
        }

        // numberOfDesigns → alleen tonen als >1
        if (!empty($opts['numberOfDesigns']) && (int)$opts['numberOfDesigns'] > 1) {
            $extra[] = 'Aantal ontwerpen: '.(int)$opts['numberOfDesigns'];
        }

        return ['eigenschappen'=>$eig, 'extras'=>$extra];
    }

    private function fmt_date_ymdh(string $s): ?DateTime {
        // accepteert "2025-09-12 17:00:00" of ISO "2025-09-12T17:00:00.000Z"
        try {
            if (strpos($s,'T') !== false) return new DateTime($s);
            return DateTime::createFromFormat('Y-m-d H:i:s', $s) ?: new DateTime($s);
        } catch (\Throwable $e) { return null; }
    }

    private function render_delivery_window_range(array $shipments): ?string {
        $start=null; $end=null;
        foreach ($shipments as $s) {
            $d1 = !empty($s['deliveryDate']) ? $this->fmt_date_ymdh((string)$s['deliveryDate']) : null;
            $d2 = !empty($s['latestDeliveryDate']) ? $this->fmt_date_ymdh((string)$s['latestDeliveryDate']) : null;
            if ($d1 && (!$start || $d1 < $start)) $start = $d1;
            if ($d2 && (!$end   || $d2 > $end))   $end   = $d2;
            if ($d1 && !$d2) { if (!$end || $d1 > $end) $end = $d1; }
        }
        if (!$start && !$end) return null;

        $fmt = function(DateTime $dt){ return $dt->format('d-m'); };
        if ($start && $end && $start->format('d-m') !== $end->format('d-m')) {
            return $fmt($start).' of '.$fmt($end);
        }
        $one = $start ?: $end;
        return $fmt($one);
    }

    private function extract_primary_shipping_address(array $order): ?array {
        // Neemt adres uit order-level shipments, anders uit eerste item->shipments
        $ships = $order['shipments'] ?? [];
        foreach ($ships as $s) {
            if (!empty($s['address'])) return $s['address'];
        }
        $items = $order['items'] ?? [];
        foreach ($items as $it) {
            foreach (($it['shipments'] ?? []) as $s) {
                if (!empty($s['address'])) return $s['address'];
            }
        }
        return null;
    }

    private function classify_item_status(string $status): string {
        $s = strtoupper(trim($status));
        if ($s === '') {
            return 'onbekend';
        }

        switch ($s) {
            case 'DELIVERED':
                return 'bezorgd';

            case 'SHIPPED':
            case 'INTRANSIT':
                return 'verzonden';

            case 'POSSIBLYDELAYED':
            case 'PARTIALLYSHIPPED':
                return 'deels';

            case 'ACCEPTEDBYSUPPLIER':
            case 'READYFORPRODUCTION':
            case 'PREPAREDFORPRINT':
            case 'PRINTED':
            case 'CUT':
            case 'FINISHED':
            case 'INPRODUCTION':
            case 'PRODUCED':
            case 'READYFORDELIVERY':
                return 'inproductie';

            case 'ORDERRECEIVED':
            case 'DRAFT':
            case 'PACKED':
            case 'SENTTOSUPPLIER':
            case 'QUALITYAPPROVED':
            case 'MANUALCHECK':
            case 'DESIGNADDED':
            case 'DESIGNCONFIRMED':
            case 'DESIGNREJECTED':
            case 'DESIGNWARNING':
            case 'WAITINGFORPAYMENT':
            case 'ERROR':
            case 'PROCESSING':
                return 'behandelen';

            case 'CANCELEDBYUSER':
            case 'CANCELEDBYSUPPLIER':
            case 'REFUSEDBYSUPPLIER':
            case 'CANCELLED':
            case 'CANCELED':
            case 'QUALITYREJECTED':
            case 'RETURNED':
            case 'RETURNREQUESTED':
                return 'geannuleerd';
        }

        return 'onbekend';
    }

    private function item_status_badge(string $status): string {
        $class = $this->classify_item_status($status);

        return '<span class="rmh-ot__badge rmh-ot__badge--'.$class.'">'.
               esc_html($this->item_status_nl($status)).
               '</span>';
    }

    private function extract_item_address(array $item): ?array {
        foreach (($item['shipments'] ?? []) as $s) {
            if (!empty($s['address'])) return $s['address'];
        }
        return null;
    }

    private function extract_item_delivery_window(array $item): ?array {
        $start = null; $end = null; $method = '';
        foreach (($item['shipments'] ?? []) as $s) {
            $d1 = !empty($s['deliveryDate']) ? $this->fmt_date_ymdh((string)$s['deliveryDate']) : null;
            $d2 = !empty($s['latestDeliveryDate']) ? $this->fmt_date_ymdh((string)$s['latestDeliveryDate']) : null;
            if ($d1 && (!$start || $d1 < $start)) $start = $d1;
            if ($d2 && (!$end   || $d2 > $end))   $end   = $d2;
            if (!$method && !empty($s['method'])) $method = (string)$s['method'];
        }
        if (!$start && !$end) return null;
        return ['start'=>$start,'end'=>$end,'method'=>$method];
    }

    private function fmt_day_short(DateTime $dt): string {
        $days = ['zo','ma','di','wo','do','vr','za'];
        return $days[(int)$dt->format('w')] . ' ' . $dt->format('d M');
    }

    private function carrier_label_from_method(string $method): string {
        $m = strtoupper($method);
        $map = [
            'DHG_EUROPLUS'      => 'DHL Zakelijk',
            'DHG_FORYOU'        => 'DHL Thuislevering',
            'DHG_EXPRESSER'     => 'DHL Ochtendlevering',
            'DHG_CONNECT'       => 'DHL Connect (internationaal)',
            'DHG_FORYOU_EVE'    => 'DHL Avondlevering',
            'DHG_BUSPAKKET'     => 'DHL Brievenbuspakket',
            'DHG_EPI'           => 'DHL Pallet (internationaal)',
            'PNA_4940'          => 'PostNL België (handtekening)',
            'PNA_3085'          => 'PostNL Standaard',
            'PNA_3085_ZONDERHANDTEKENING' => 'PostNL Standaard',
            'PNA_3189'          => 'PostNL Standaard (handtekening)',
            'PNL_4940'          => 'PostNL Internationaal (handtekening)',
            'FED_EXPRESS'       => 'FedEx Express (ochtend)',
            'FED_PRIORITY'      => 'FedEx Priority',
            'FED_ECONOMY'       => 'FedEx Economy',
            'FED_ECONOMYFREIGHT'=> 'FedEx Pallet',
            'DPD_B2C'           => 'DPD Thuislevering',
            'DPD_CLASSIC'       => 'DPD Zakelijk',
            'DPD_1200'          => 'DPD Ochtendlevering',
            'NDS_PALLET'        => 'Palletlevering leverancier',
            'NPD_PACKAGE'       => 'Pakketlevering leverancier',
            'RTG_STANDARD'      => 'Koerier NL/BE',
            'UPS_EXPRESS'       => 'UPS Express',
            'UPS_STANDARD'      => 'UPS Standaard',
            'UPS_SAVER'         => 'UPS Saver',
            'CHP_CHRONO13'      => 'Chronopost Binnenland (ochtend)',
            'CHP_CLASSIC'       => 'Chronopost Internationaal',
        ];
        if (isset($map[$m])) return $map[$m];

        $m = strtolower($method);
        if (strpos($m,'pna_') === 0 || strpos($m,'pnl_') === 0 || strpos($m,'postnl') !== false) return 'PostNL';
        if (strpos($m,'dh') === 0  || strpos($m,'dhl') !== false)     return 'DHL';
        if (strpos($m,'dpd') === 0)                                   return 'DPD';
        if (strpos($m,'gls') !== false)                               return 'GLS';
        if (strpos($m,'ups') === 0)                                   return 'UPS';
        if (strpos($m,'fed') === 0)                                   return 'FedEx';
        if (strpos($m,'chp') === 0)                                   return 'Chronopost';
        if (strpos($m,'nds_') === 0 || strpos($m,'npd_') === 0)       return 'Leverancier';
        if (strpos($m,'rtg_') === 0)                                  return 'Koerier';
        return ucfirst($method ?: 'Bezorgdienst nog niet bekend');
    }

    private function carrier_logo_and_label(string $method): array {
        $name = $this->carrier_label_from_method($method);
        $m = strtolower($method);

        if (strpos($m,'pna_') === 0 || strpos($m,'pnl_') === 0 || strpos($m,'postnl') !== false) {
            $slug = 'postnl';
        } elseif (strpos($m,'dhg_') === 0 || strpos($m,'dhl') !== false || strpos($m,'dh') === 0) {
            $slug = 'dhl';
        } elseif (strpos($m,'dpd') === 0) {
            $slug = 'dpd';
        } elseif (strpos($m,'gls') !== false) {
            $slug = 'gls';
        } elseif (strpos($m,'ups') === 0) {
            $slug = 'ups';
        } elseif (strpos($m,'fed') === 0) {
            $slug = 'fedex';
        } elseif (strpos($m,'chp') === 0) {
            $slug = 'chronopost';
        } elseif (strpos($m,'nds_') === 0 || strpos($m,'npd_') === 0) {
            $slug = 'supplier';
        } elseif (strpos($m,'rtg_') === 0) {
            $slug = 'courier';
        } else {
            $slug = 'carrier';
        }

        $map = [
            'postnl'     => 'postnl.png',
            'dhl'        => 'dhl.png',
            'dpd'        => 'dpd.png',
            'gls'        => 'gls.png',
            'ups'        => 'ups.png',
            'fedex'      => 'fedex.png',
            'chronopost' => 'chronopost.png',
            'supplier'   => 'supplier.png',
            'courier'    => 'courier.png',
            'carrier'    => 'onbekend.png',
        ];

        $file     = $map[$slug] ?? 'onbekend.png';
        $relative = 'assets/carriers/' . $file;
        $path     = plugin_dir_path(__FILE__) . $relative;
        if (!file_exists($path)) {
            $relative = 'assets/carriers/onbekend.png';
        }
        $url = plugins_url($relative, __FILE__);

        return [
            'name' => $name,
            'url'  => $url,
            'slug' => $slug,
        ];
    }

    /**
     * Forceer Divi: geen zijbalk + full-width template.
     */
    private function apply_divi_fullwidth_no_sidebar(int $page_id): void {
        if ($page_id <= 0) return;

        // 1) Divi page layout: Geen zijbalk
        //   (waarden: et_right_sidebar | et_left_sidebar | et_no_sidebar)
        update_post_meta($page_id, 'et_pb_page_layout', 'et_no_sidebar');

        // 2) Template: et_full_width_page.php
        //   Let op: _wp_page_template verwacht de bestandsnaam, geen pad.
        $current_tpl = get_post_meta($page_id, '_wp_page_template', true);
        if ($current_tpl !== 'et_full_width_page.php') {
            update_post_meta($page_id, '_wp_page_template', 'et_full_width_page.php');
        }
    }

    /* ===== Styles ===== */

    /**
     * Laad frontend-styles enkel wanneer de relevante shortcodes aanwezig zijn.
     */
    public function enqueue_styles() {
        if (!is_singular()) {
            return;
        }

        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return;
        }

        $content = (string) $post->post_content;

        if ($content !== '' && has_shortcode($content, 'print_order_status')) {
            wp_enqueue_style(
                'rmh-ot-style',
                plugins_url('assets/css/order-tracker.css', __FILE__),
                [],
                self::PLUGIN_VERSION
            );
        }

        if ($content !== '' && has_shortcode($content, 'print_order_lookup')) {
            wp_enqueue_style(
                'rmh-order-lookup',
                plugins_url('assets/css/order-lookup.css', __FILE__),
                [],
                '2.1.10'
            );
        }
    }

    /* ===== HTTP helpers ===== */

    /**
     * Haal plugin-instellingen op en cache deze gedurende de request.
     */
    private function get_settings(): array {
        if (is_array($this->settings_cache)) {
            return $this->settings_cache;
        }

        $settings = get_option(self::OPT_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $this->settings_cache = $settings;

        return $settings;
    }

    /* ===== API client ===== */

    private function api_get_order($orderNum) {
        $s = $this->get_settings();
        $base = rtrim($s['api_base_url'] ?? 'https://api.print.com', '/');
        $url  = $base.'/orders/'.rawurlencode($orderNum);

        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => self::USER_AGENT,
            ],
            'timeout' => 20,
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return $res;

        $code=wp_remote_retrieve_response_code($res);
        $body=wp_remote_retrieve_body($res);
        if ($code===401){
            delete_transient(self::TRANSIENT_TOKEN);
            $token=$this->get_access_token(true);
            if (is_wp_error($token)) return $token;
            $args['headers']['Authorization']='Bearer '.$token;
            $res=wp_remote_get($url,$args);
            if (is_wp_error($res)) return $res;
            $code=wp_remote_retrieve_response_code($res);
            $body=wp_remote_retrieve_body($res);
        }
        if ($code<200 || $code>=300) return new WP_Error('printcom_api_error','API fout ('.$code.').');

        $json=json_decode($body,true);
        if (!is_array($json)) return new WP_Error('printcom_api_error','Ongeldig API-antwoord.');

        /**
         * --- BELANGRIJK ---
         * Print.com kan de order teruggeven als:
         *  A) { order: { ... items, shipments ... } }
         *  B) { items: [...], shipments:[...], ... }  (direct)
         *  C) { results: [ { ... } ] }                (gepagineerde wrapper)
         */
        $order = $json;
        if (isset($json['order']) && is_array($json['order'])) {
            $order = $json['order'];
        } elseif (isset($json['results']) && is_array($json['results']) && count($json['results'])>0) {
            $order = $json['results'][0];
        }

        // normaliseer sleutelvelden
        if (!isset($order['items']) && isset($order['orderItems']))     $order['items']     = $order['orderItems'];
        if (!isset($order['shipments']) && isset($order['orderShipments'])) $order['shipments'] = $order['orderShipments'];

        if (!is_array($order) || (!isset($order['items']) && !isset($order['shipments']))) {
            return new WP_Error('printcom_api_error','Order niet gevonden of leeg antwoord.');
        }

        $this->update_order_state($orderNum,$order);
        return $order;
    }

    private function get_access_token($force_refresh=false){
        $cached=get_transient(self::TRANSIENT_TOKEN);
        if ($cached && !$force_refresh) return $cached;

        $s=$this->get_settings();
        $auth=trim($s['auth_url']??''); if(!$auth) return new WP_Error('printcom_auth_missing','Auth URL niet ingesteld.');
        $username=isset($s['username'])?trim($s['username']):'';
        $password=isset($s['password'])?(string)$s['password']:'';
        $password=preg_replace("/\r\n|\r|\n/","",$password);
        $ua=self::USER_AGENT;

        $is_print_login=(stripos($auth,'/login')!==false);
        if ($is_print_login){
            if ($username==='' || $password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');
            $payload=wp_json_encode(['credentials'=>['username'=>$username,'password'=>$password]]);
            $res = wp_remote_post($auth, [
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent'   => $ua,
                ],
                'body'    => $payload,
                'timeout' => 20,
            ]);
            if (is_wp_error($res)) return $res;
            $code=wp_remote_retrieve_response_code($res); $raw=wp_remote_retrieve_body($res);
            if ($code===401){
                $err='Auth fout (401). Controleer login & URL.'; $j=json_decode($raw,true);
                if(is_array($j)){ if(!empty($j['message'])) $err.=' Detail: '.sanitize_text_field($j['message']); if(!empty($j['error'])) $err.=' ('.sanitize_text_field($j['error']).')'; }
                elseif(!empty($raw)) $err.=' Detail: '.sanitize_text_field($raw);
                return new WP_Error('printcom_auth_error',$err);
            }
            if ($code<200 || $code>=300) return new WP_Error('printcom_auth_error','Auth fout ('.$code.'). Raw: '.sanitize_text_field($raw));
            $j=json_decode($raw,true);
            $token=null;
            if(is_array($j)) $token=$j['access_token']??$j['token']??$j['jwt']??null;
            if(!$token && is_string($raw) && strlen($raw)>20 && strpos($raw,'{')===false) $token=trim($raw);
            if(!$token) return new WP_Error('printcom_auth_error','Kon JWT niet vinden in login-response. Raw: '.sanitize_text_field($raw));

            // normaliseer (Bearer / quotes)
            $token=(string)$token; $token=preg_replace('/^\s*Bearer\s+/i','',$token); $token=trim($token);
            if ((substr($token,0,1)==='"' && substr($token,-1)==='"')||(substr($token,0,1)==="'" && substr($token,-1)==="'")) $token=substr($token,1,-1);

            set_transient(self::TRANSIENT_TOKEN,$token,max(60,(7*DAY_IN_SECONDS)-60));
            return $token;
        }

        // Fallback OAuth
        $grant=$s['grant_type']??'client_credentials';
        $body=['grant_type'=>$grant];
        if ($grant==='client_credentials'){
            if (empty($s['client_id'])||empty($s['client_secret'])) return new WP_Error('printcom_auth_missing','Client ID/Secret ontbreken.');
            $body['client_id']=$s['client_id']; $body['client_secret']=$s['client_secret'];
        } else {
            if ($username===''||$password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');
            if (!empty($s['client_id'])) $body['client_id']=$s['client_id'];
            if (!empty($s['client_secret'])) $body['client_secret']=$s['client_secret'];
            $body['username']=$username; $body['password']=$password;
        }
        $res = wp_remote_post($auth, [
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => $ua,
            ],
            'body'    => $body,
            'timeout' => 20,
        ]);
        if (is_wp_error($res)) return $res;
        $code=wp_remote_retrieve_response_code($res); $raw=wp_remote_retrieve_body($res);
        if ($code===401){
            $err='Auth fout (401). Controleer OAuth en credentials.'; $j=json_decode($raw,true);
            if(is_array($j)){ if(!empty($j['error_description'])) $err.=' '.sanitize_text_field($j['error_description']); if(!empty($j['error'])) $err.=' ('.sanitize_text_field($j['error']).')'; }
            elseif(!empty($raw)) $err.=' Detail: '.sanitize_text_field($raw);
            return new WP_Error('printcom_auth_error',$err);
        }
        if ($code<200 || $code>=300) return new WP_Error('printcom_auth_error','Auth fout ('.$code.'). Raw: '.sanitize_text_field($raw));
        $j=json_decode($raw,true); if(!is_array($j)) return new WP_Error('printcom_auth_error','Ongeldige auth-response.');
        $token=$j['access_token']??null; $expires=isset($j['expires_in'])?(int)$j['expires_in']:(7*DAY_IN_SECONDS);
        if(!$token) return new WP_Error('printcom_auth_error','Kon access_token niet vinden in auth-response. Raw: '.sanitize_text_field($raw));
        $token=(string)$token; $token=preg_replace('/^\s*Bearer\s+/i','',$token); $token=trim($token);
        if ((substr($token,0,1)==='"' && substr($token,-1)==='"')||(substr($token,0,1)==="'" && substr($token,-1)==="'")) $token=substr($token,1,-1);
        set_transient(self::TRANSIENT_TOKEN,$token,max(60,$expires-60)); return $token;
    }

    /* ===== State/TTL ===== */

    private function is_order_complete(array $d): bool {
        $all=$d['items']??[]; $ships=$d['shipments']??[]; if(empty($all))return false;
        $map=[]; foreach($all as $it) if(!empty($it['orderItemNumber'])) $map[$it['orderItemNumber']]=false;
        foreach($ships as $s) foreach(($s['orderItemNumbers']??[]) as $n) if(isset($map[$n])) $map[$n]=true;
        return $map && count(array_filter($map))===count($map);
    }
    private function update_order_state(string $orderNum,array $data): void {
        $st=get_option(self::OPT_STATE,[]); $now=time(); $complete=$this->is_order_complete($data);
        $status=isset($data['status'])?strtolower((string)$data['status']):($complete?'shipped':'processing');
        $e=$st[$orderNum]??['status'=>null,'complete_at'=>null,'last_seen'=>null];
        $e['status']=$status; if($complete && empty($e['complete_at'])) $e['complete_at']=$now; if(!$complete) $e['complete_at']=null;
        $st[$orderNum]=$e; update_option(self::OPT_STATE,$st,false);
    }
    private function dynamic_cache_ttl_for(array $d): int { return $this->is_order_complete($d)?DAY_IN_SECONDS:5*MINUTE_IN_SECONDS; }

    /* ===== Cron ===== */

    public function cron_refresh_token(){ delete_transient(self::TRANSIENT_TOKEN); $t=$this->get_access_token(true); if(is_wp_error($t)) error_log('[Printcom OT] Token verversen mislukt: '.$t->get_error_message()); }
    public function cron_warm_cache(){
        $st=get_option(self::OPT_STATE,[]); $map=get_option(self::OPT_MAPPINGS,[]); if(empty($map))return;
        $hot_limit=50; $cold_limit=20; $archive_days=14; $now=time(); $orders=[];
        foreach($map as $info){
            $ord=$info['print_order']??''; if($ord==='') continue;
            $e=$st[$ord]??null; $complete_at=$e['complete_at']??null; $status=$e['status']??null;
            if($complete_at && ($now-(int)$complete_at)>($archive_days*DAY_IN_SECONDS)) continue;
            $isComplete=($complete_at!==null)||($status==='shipped'||$status==='completed');
            $orders[]=['order'=>$ord,'type'=>$isComplete?'COLD':'HOT','last_seen'=>$e['last_seen']??0];
        }
        if(empty($orders))return; shuffle($orders);
        $hot=array_values(array_filter($orders,fn($o)=>$o['type']==='HOT')); $cold=array_values(array_filter($orders,fn($o)=>$o['type']==='COLD'));
        usort($hot,function($a,$b){return $b['last_seen']<=>$a['last_seen'];});
        $ph=0; foreach($hot as $o){ if($ph>=$hot_limit)break; $d=$this->api_get_order($o['order']); if(!is_wp_error($d)) set_transient(self::TRANSIENT_PREFIX.md5($o['order']),$d,$this->dynamic_cache_ttl_for($d)); $ph++; }
        $pc=0; foreach($cold as $o){ if($pc>=$cold_limit)break; $d=$this->api_get_order($o['order']); if(!is_wp_error($d)) set_transient(self::TRANSIENT_PREFIX.md5($o['order']),$d,$this->dynamic_cache_ttl_for($d)); $pc++; }
    }
}

/**
 * Verkrijg een gedeelde Invoice Ninja client op basis van de huidige instellingen.
 *
 * @return RMH_InvoiceNinja_Client|null
 */
function rmh_get_invoice_ninja_client_singleton() {
    static $instance = null;
    static $state_hash = null;

    $settings = get_option(Printcom_Order_Tracker::OPT_SETTINGS, []);
    $enabled  = !empty($settings['rmh_integration_enabled']);
    $base_raw = isset($settings['rmh_in_base_url']) ? (string) $settings['rmh_in_base_url'] : '';
    $base     = '';
    if ($base_raw !== '') {
        $candidate = esc_url_raw(trim($base_raw));
        if (is_string($candidate) && $candidate !== '' && stripos($candidate, 'https://') === 0) {
            $base = untrailingslashit($candidate);
        }
    }
    $token    = isset($settings['rmh_in_api_token']) ? (string) $settings['rmh_in_api_token'] : '';
    $cache    = isset($settings['rmh_in_cache_minutes']) ? absint($settings['rmh_in_cache_minutes']) : 10;

    $hash = md5(wp_json_encode([$enabled, $base, $token, $cache]));
    if ($state_hash === $hash) {
        return $instance;
    }

    $state_hash = $hash;

    if (!$enabled || $base === '' || trim($token) === '') {
        $instance = null;
        return null;
    }

    $instance = new RMH_InvoiceNinja_Client($base, trim($token), $cache);

    return $instance;
}

/* ===== Hooks ===== */
register_activation_hook(__FILE__, ['Printcom_Order_Tracker','activate']);
register_deactivation_hook(__FILE__, ['Printcom_Order_Tracker','deactivate']);

/* ===== Admin-post: debug & acties ===== */

// Invoice Ninja verbinding testen
add_action('admin_post_rmh_in_test_connection', function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    if(empty($_POST['rmh_in_test_connection_nonce']) || !wp_verify_nonce($_POST['rmh_in_test_connection_nonce'],'rmh_in_test_connection')) wp_die('Nonce invalid');

    $settings = get_option(Printcom_Order_Tracker::OPT_SETTINGS, []);
    $base     = isset($settings['rmh_in_base_url']) ? trim((string) $settings['rmh_in_base_url']) : '';
    $token    = isset($settings['rmh_in_api_token']) ? trim((string) $settings['rmh_in_api_token']) : '';
    $status   = 'error';

    if($base !== '' && $token !== '' && stripos($base, 'https://') === 0){
        $endpoint = untrailingslashit($base) . '/api/v1/ping';
        $response = wp_remote_get($endpoint,[
            'timeout' => 10,
            'headers' => [
                'X-API-Token'      => $token,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
                'User-Agent'       => 'RMH-Order-Tracker (+WordPress)'
            ],
        ]);
        if(!is_wp_error($response)){
            $code = (int) wp_remote_retrieve_response_code($response);
            if($code >= 200 && $code < 300){
                $status = 'success';
            }
        }
    }

    $redirect = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    $redirect = remove_query_arg('rmh_in_test_status', $redirect);
    $redirect = add_query_arg('rmh_in_test_status', $status, $redirect);
    wp_safe_redirect($redirect);
    exit;
});

// Invoice Ninja factuur zoeken
add_action('admin_post_rmh_in_test_invoice_lookup', function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    if(empty($_POST['rmh_in_test_invoice_lookup_nonce']) || !wp_verify_nonce($_POST['rmh_in_test_invoice_lookup_nonce'],'rmh_in_test_invoice_lookup')) wp_die('Nonce invalid');

    $invoice = isset($_POST['invoice']) ? sanitize_text_field(wp_unslash($_POST['invoice'])) : '';
    $invoice = trim($invoice);
    $msg     = '';

    if($invoice === ''){
        $msg = '❌ Vul een factuurnummer in.';
    } else {
        $client = function_exists('rmh_get_invoice_ninja_client_singleton') ? rmh_get_invoice_ninja_client_singleton() : null;

        if(!$client){
            $msg = '❌ Invoice Ninja client niet beschikbaar. Controleer of de integratie is ingeschakeld en of de URL/token correct zijn.';
        } else {
            $details = $client->get_invoice_portal_details($invoice);

            if(is_array($details) && !empty($details['link'])){
                $status = 'Onbekend';
                if(array_key_exists('is_paid', $details)){
                    if($details['is_paid'] === true){
                        $status = 'Betaald';
                    } elseif($details['is_paid'] === false){
                        $status = 'Niet betaald';
                    }
                }

                $msg = sprintf(
                    '✅ Factuur <strong>%1$s</strong> gevonden. Portal-link: <a href="%2$s" target="_blank" rel="noopener">%2$s</a>. Betaalstatus: %3$s.',
                    esc_html($invoice),
                    esc_url($details['link']),
                    esc_html($status)
                );
            } else {
                $msg = sprintf('❌ Geen portal-link gevonden voor factuur <strong>%s</strong>.', esc_html($invoice));
            }
        }
    }

    $redirect = wp_get_referer() ?: admin_url('options-general.php?page=printcom-orders-settings');
    $redirect = remove_query_arg('rmh_in_test_invoice_result', $redirect);
    $redirect = add_query_arg('rmh_in_test_invoice_result', rawurlencode($msg), $redirect);
    wp_safe_redirect($redirect);
    exit;
});

// /login test
add_action('admin_post_printcom_ot_test_connection', function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    if(empty($_POST['printcom_ot_test_conn_nonce']) || !wp_verify_nonce($_POST['printcom_ot_test_conn_nonce'],'printcom_ot_test_conn')) wp_die('Nonce invalid');
    $s    = get_option(Printcom_Order_Tracker::OPT_SETTINGS, []);
    $auth = $s['auth_url'] ?? '';
    $u    = trim($s['username'] ?? '');
    $p    = preg_replace("/\r\n|\r|\n/", '', (string) ($s['password'] ?? ''));
    $ua   = Printcom_Order_Tracker::USER_AGENT;

    if(!$auth||!$u||!$p){ $msg='❌ Ontbrekende instellingen (auth_url/username/password).'; }
    else{
        $payload = wp_json_encode(['credentials' => ['username' => $u, 'password' => $p]]);
        $args    = [
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent'   => $ua,
            ],
            'body'    => $payload,
            'timeout' => 20,
        ];
        $res = wp_remote_post($auth, $args);
        if(is_wp_error($res)) $msg='❌ Verbindingsfout: '.$res->get_error_message();
        else { $code=wp_remote_retrieve_response_code($res); $raw=wp_remote_retrieve_body($res); $msg=($code>=200&&$code<300)?'✅ OK ('.$code.'). Body lengte: '.strlen($raw).'.':'❌ Auth fout ('.$code.'). '.(!empty($raw)?'Body: '.sanitize_text_field($raw):'Geen body.'); }
    }
    $dest=wp_get_referer()?:admin_url('options-general.php?page=printcom-orders-settings'); wp_safe_redirect(add_query_arg('printcom_test_result',rawurlencode($msg),$dest)); exit;
});

// /orders test
add_action('admin_post_printcom_ot_test_order', function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    if(empty($_POST['printcom_ot_test_order_nonce']) || !wp_verify_nonce($_POST['printcom_ot_test_order_nonce'],'printcom_ot_test_order')) wp_die('Nonce invalid');
    $order=isset($_POST['order'])?sanitize_text_field(wp_unslash($_POST['order'])):''; if($order==='') $order='DEMO';
    $plugin=new Printcom_Order_Tracker(); delete_transient(Printcom_Order_Tracker::TRANSIENT_TOKEN);
    try{ $ref=new ReflectionClass($plugin); $m=$ref->getMethod('get_access_token'); $m->setAccessible(true); $token=$m->invoke($plugin,true);}catch(Throwable $e){ $token=new WP_Error('exception',$e->getMessage()); }
    if(is_wp_error($token)){ $msg='❌ Tokenfout: '.esc_html($token->get_error_message()); }
    else{
        $prefix=substr($token,0,16); $len=strlen($token);
        $s=get_option(Printcom_Order_Tracker::OPT_SETTINGS,[]); $base=rtrim($s['api_base_url']??'https://api.print.com','/'); $url=$base.'/orders/'.rawurlencode($order);
        $res = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => Printcom_Order_Tracker::USER_AGENT,
            ],
            'timeout' => 20,
        ]);
        if(is_wp_error($res)){ $msg='❌ Transportfout: '.esc_html($res->get_error_message()); }
        else{ $code=wp_remote_retrieve_response_code($res); $raw=wp_remote_retrieve_body($res); $hdrs=wp_remote_retrieve_headers($res); $body_preview=$raw?mb_substr($raw,0,260):''; $hints=[]; foreach(['www-authenticate','x-request-id','server'] as $h) if(!empty($hdrs[$h])) $hints[]=strtoupper($h).': '.$hdrs[$h];
            $msg='🔎 Token: len='.$len.', starts="'.esc_html($prefix).'" | ';
            if($code>=200&&$code<300){ $msg.='✅ Order OK ('.$code.'). Body ~'.strlen($raw).' bytes.'; }
            else { $msg.='❌ Order fout ('.$code.'). '.($body_preview?'Body: '.sanitize_text_field($body_preview):'Geen body.'); if($hints) $msg.=' | Hdr: '.esc_html(implode(' | ',$hints)); }
        }
    }
    $dest=wp_get_referer()?:admin_url('options-general.php?page=printcom-orders-settings'); wp_safe_redirect(add_query_arg('printcom_test_order_result',rawurlencode($msg),$dest)); exit;
});

// Start
new Printcom_Order_Tracker();
