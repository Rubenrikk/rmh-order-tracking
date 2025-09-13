<?php
/**
 * Plugin Name: Print.com Order Tracker (Track & Trace Pagina's)
 * Description: Maakt per ordernummer automatisch een track & trace pagina aan en toont live orderstatus, items en verzendinformatie via de Print.com API. Tokens worden automatisch vernieuwd. Divi-vriendelijk.
 * Version:     1.6.3
 * Author:      RikkerMediaHub
 * License:     GNU GPLv2
 * Text Domain: printcom-order-tracker
 */

if (!defined('ABSPATH')) exit;

class Printcom_Order_Tracker {
    const OPT_SETTINGS     = 'printcom_ot_settings';
    const OPT_MAPPINGS     = 'printcom_ot_mappings';
    const OPT_STATE        = 'printcom_ot_state';
    const TRANSIENT_TOKEN  = 'printcom_ot_token';
    const TRANSIENT_PREFIX = 'printcom_ot_cache_';
    const META_IMG_ID      = '_printcom_ot_image_id';    // (legacy) 1 afbeelding voor de hele pagina
    const META_ITEM_IMGS   = '_printcom_ot_item_images'; // per product (orderItemNumber => attachment_id)

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_shortcode('print_order_status', [$this, 'render_order_shortcode']);

        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post',       [$this, 'save_metaboxes']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

        add_filter('cron_schedules', [$this, 'add_every5_schedule']);
        add_action('printcom_ot_cron_refresh_token', [$this, 'cron_refresh_token']);
        add_action('printcom_ot_cron_warm_cache',   [$this, 'cron_warm_cache']);

        add_action('http_api_curl', [$this, 'maybe_force_ipv4_for_printcom'], 10, 3);
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
        if ($ts = wp_next_scheduled('printcom_ot_cron_refresh_token')) wp_unschedule_event($ts, 'printcom_ot_cron_refresh_token');
        if ($ts = wp_next_scheduled('printcom_ot_cron_warm_cache'))   wp_unschedule_event($ts, 'printcom_ot_cron_warm_cache');
    }
    public function add_every5_schedule($s){ $s['every5min']=['interval'=>300,'display'=>'Every 5 Minutes']; return $s; }

    /* ===== Admin menu & instellingen ===== */

    public function admin_menu() {
        add_menu_page('Print.com Orders','Print.com Orders','manage_options','printcom-orders',[$this,'orders_page'],'dashicons-location',56);
        add_submenu_page('options-general.php','Print.com Orders','Print.com Orders','manage_options','printcom-orders-settings',[$this,'settings_page']);
    }

    public function register_settings() {
        register_setting(self::OPT_SETTINGS, self::OPT_SETTINGS, [$this,'sanitize_settings']);
        add_settings_section('printcom_ot_section','API-instellingen','__return_false',self::OPT_SETTINGS);
        $s = $this->get_settings();
        $add=function($k,$label,$html,$desc=''){
            add_settings_field($k,$label,function() use($html,$desc){ echo $html; if($desc) echo '<p class="description">'.$desc.'</p>'; },self::OPT_SETTINGS,'printcom_ot_section');
        };
        $add('api_base_url','API Base URL',sprintf('<input type="url" name="%s[api_base_url]" value="%s" class="regular-text" placeholder="https://api.print.com"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['api_base_url']??'https://api.print.com')));
        $add('auth_url','Auth URL (login)',sprintf('<input type="url" name="%s[auth_url]" value="%s" class="regular-text" placeholder="https://api.print.com/login"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['auth_url']??'https://api.print.com/login')),'Voor Print.com: <code>https://api.print.com/login</code>.');
        ob_start(); ?>
            <select name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[grant_type]">
                <option value="password" <?php selected($s['grant_type']??'password','password'); ?>>password (/login)</option>
                <option value="client_credentials" <?php selected($s['grant_type']??'password','client_credentials'); ?>>client_credentials (fallback)</option>
            </select>
        <?php $add('grant_type','Grant type',ob_get_clean());
        $add('client_id','Client ID (optioneel)',sprintf('<input type="text" name="%s[client_id]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['client_id']??'')));
        $add('client_secret','Client Secret (optioneel)',sprintf('<input type="password" name="%s[client_secret]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['client_secret']??'')));
        $add('username','Username',sprintf('<input type="text" name="%s[username]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['username']??'')));
        $add('password','Password',sprintf('<input type="password" name="%s[password]" value="%s" class="regular-text"/>',esc_attr(self::OPT_SETTINGS),esc_attr($s['password']??'')));
        $add('default_cache_ttl','Cache (minuten)',sprintf('<input type="number" min="0" step="1" name="%s[default_cache_ttl]" value="%d" class="small-text"/>',esc_attr(self::OPT_SETTINGS),isset($s['default_cache_ttl'])?(int)$s['default_cache_ttl']:5),'HOT=5m, COLD=24u.');
        $add('force_ipv4','Forceer IPv4 (debug)',sprintf('<label><input type="checkbox" name="%s[force_ipv4]" value="1" %s/> Alleen api.print.com</label>',esc_attr(self::OPT_SETTINGS),checked(!empty($s['force_ipv4']),true,false)));
        $add('alt_payload','Alternatieve payload (debug)',sprintf('<label><input type="checkbox" name="%s[alt_payload]" value="1" %s/> POST zonder <code>{"credentials":...}</code></label>',esc_attr(self::OPT_SETTINGS),checked(!empty($s['alt_payload']),true,false)));
    }

    public function sanitize_settings($in){
        $o=[];
        $o['api_base_url']=isset($in['api_base_url'])?trim(esc_url_raw($in['api_base_url'])):'https://api.print.com';
        $o['auth_url']=isset($in['auth_url'])?trim(esc_url_raw($in['auth_url'])):'https://api.print.com/login';
        $o['grant_type']=in_array($in['grant_type']??'password',['client_credentials','password'],true)?$in['grant_type']:'password';
        $o['client_id']=trim(sanitize_text_field($in['client_id']??''));
        $o['client_secret']=trim(sanitize_text_field($in['client_secret']??''));
        $o['username']=trim(sanitize_text_field($in['username']??''));
        $o['password']=trim((string)($in['password']??''));
        $o['default_cache_ttl']=max(0,(int)($in['default_cache_ttl']??5));
        $o['force_ipv4']=!empty($in['force_ipv4'])?1:0;
        $o['alt_payload']=!empty($in['alt_payload'])?1:0;
        return $o;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Print.com Orders — Instellingen</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT_SETTINGS); do_settings_sections(self::OPT_SETTINGS); submit_button(); ?>
            </form>
            <hr/>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_conn','printcom_ot_test_conn_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_connection"/>
                <button class="button button-secondary">Verbinding testen</button>
            </form>
            <?php if (!empty($_GET['printcom_test_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_result'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('printcom_ot_test_order','printcom_ot_test_order_nonce'); ?>
                <input type="hidden" name="action" value="printcom_ot_test_order"/>
                <input type="text" name="order" placeholder="Ordernummer (bijv. 6001831441)" required/>
                <button class="button">Test: haal order op</button>
            </form>
            <?php if (!empty($_GET['printcom_test_order_result'])): ?>
                <div class="notice notice-info" style="margin-top:10px;"><p><?php echo wp_kses_post(wp_unslash($_GET['printcom_test_order_result'])); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ===== Orders admin ===== */

    public function orders_page() {
        if (!current_user_can('manage_options')) return;

        // Wees-mappings opruimen
        $m = get_option(self::OPT_MAPPINGS, []);
        $chg=false;
        foreach($m as $ord=>$pid){ if(!$pid || !get_post_status((int)$pid)){ unset($m[$ord]); delete_transient(self::TRANSIENT_PREFIX.md5($ord)); $st=get_option(self::OPT_STATE,[]); if(isset($st[$ord])){unset($st[$ord]); update_option(self::OPT_STATE,$st,false);} $chg=true; } }
        if($chg) update_option(self::OPT_MAPPINGS,$m,false);

        $msg='';
        if (!empty($_POST['printcom_ot_new_order']) && check_admin_referer('printcom_ot_new_order_action','printcom_ot_nonce')) {
            $orderNum=sanitize_text_field($_POST['printcom_ot_new_order']);
            if ($orderNum!=='') {
                $page_id=$this->create_or_update_page_for_order($orderNum);
                if ($page_id){ $url=get_permalink($page_id); $msg=sprintf('Pagina voor order <strong>%s</strong> is aangemaakt/bijgewerkt: <a href="%s" target="_blank" rel="noopener">%s</a>',esc_html($orderNum),esc_url($url),esc_html($url)); }
                else { $msg='Er ging iets mis bij het aanmaken of bijwerken van de pagina.'; }
            }
        }
        if (!empty($_GET['printcom_deleted_order'])) {
            $od=sanitize_text_field(wp_unslash($_GET['printcom_deleted_order'])); $msg='Order <strong>'.esc_html($od).'</strong> is uit de lijst verwijderd.';
        }

        $m = get_option(self::OPT_MAPPINGS, []);
        ?>
        <div class="wrap">
            <h1>Print.com Orders</h1>
            <?php if ($msg): ?><div class="notice notice-success"><p><?php echo wp_kses_post($msg); ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('printcom_ot_new_order_action','printcom_ot_nonce'); ?>
                <table class="form-table"><tr><th><label for="printcom_ot_new_order">Ordernummer</label></th><td><input type="text" id="printcom_ot_new_order" name="printcom_ot_new_order" class="regular-text" placeholder="bijv. 6001831441" required/><p class="description">Voer een ordernummer in en klik “Pagina aanmaken/bijwerken”.</p></td></tr></table>
                <?php submit_button('Pagina aanmaken/bijwerken'); ?>
            </form>
            <h2>Bestaande orderpagina’s</h2>
            <?php if ($m): ?>
                <table class="widefat striped"><thead><tr><th>Ordernummer</th><th>Pagina</th><th>Link</th><th>Acties</th></tr></thead><tbody>
                <?php foreach($m as $ord=>$pid):
                    $link=get_permalink($pid); $title=get_the_title($pid);
                    $del=wp_nonce_url(admin_url('admin-post.php?action=printcom_ot_delete_order&order='.rawurlencode($ord)),'printcom_ot_delete_order_'.$ord); ?>
                    <tr><td><?php echo esc_html($ord); ?></td><td><?php echo esc_html($title); ?> (ID: <?php echo (int)$pid; ?>)</td><td><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($link); ?></a></td><td><a class="button button-link-delete" href="<?php echo esc_url($del); ?>" onclick="return confirm('Verwijder deze order uit de lijst? De pagina blijft staan.');">Verwijder uit lijst</a></td></tr>
                <?php endforeach; ?></tbody></table>
            <?php else: ?><p>Nog geen orderpagina’s aangemaakt.</p><?php endif; ?>
        </div>
        <?php
    }

    private function create_or_update_page_for_order($orderNum){
        $map=get_option(self::OPT_MAPPINGS,[]);
        $title='Bestelling '.$orderNum;
        $shortcode=sprintf('[print_order_status order="%s"]',esc_attr($orderNum));
        if (isset($map[$orderNum]) && get_post_status((int)$map[$orderNum])) {
            $page_id=(int)$map[$orderNum];
            $post=['ID'=>$page_id,'post_title'=>$title];
            $cur=get_post($page_id);
            if ($cur && strpos($cur->post_content,'[print_order_status')===false) $post['post_content']=$cur->post_content."\n\n".$shortcode;
            wp_update_post($post);
        } else {
            $page_id=wp_insert_post(['post_title'=>$title,'post_name'=>sanitize_title($title),'post_content'=>$shortcode,'post_status'=>'publish','post_type'=>'page','post_author'=>get_current_user_id()]);
            if(is_wp_error($page_id) || !$page_id) return false;
            $map[$orderNum]=(int)$page_id; update_option(self::OPT_MAPPINGS,$map,false);
        }
        return (int)$page_id;
    }

    private function remove_order_mapping(string $orderNum, bool $also_delete_page=false): bool {
        $m=get_option(self::OPT_MAPPINGS,[]); if(!isset($m[$orderNum])) return false;
        $pid=(int)$m[$orderNum]; if($also_delete_page && $pid && get_post_status($pid)) wp_delete_post($pid,true);
        unset($m[$orderNum]); update_option(self::OPT_MAPPINGS,$m,false);
        delete_transient(self::TRANSIENT_PREFIX.md5($orderNum));
        $st=get_option(self::OPT_STATE,[]); if(isset($st[$orderNum])){unset($st[$orderNum]); update_option(self::OPT_STATE,$st,false);}
        return true;
    }

    /* ===== Shortcode ===== */

    public function render_order_shortcode($atts=[]) {
        $atts = shortcode_atts(['order'=>''], $atts, 'print_order_status');
        $orderNum = trim($atts['order']);
        if ($orderNum==='') return '<div class="printcom-ot printcom-ot--error">Geen ordernummer opgegeven.</div>';

        $cache_key=self::TRANSIENT_PREFIX.md5($orderNum);
        $data=get_transient($cache_key);
        if(!$data){
            $data=$this->api_get_order($orderNum);
            if(is_wp_error($data)) return '<div class="printcom-ot printcom-ot--error">Kon ordergegevens niet ophalen. '.esc_html($data->get_error_message()).'</div>';
            set_transient($cache_key,$data,$this->dynamic_cache_ttl_for($data));
        }

        // prioriteit voor warming
        $st=get_option(self::OPT_STATE,[]); $e=$st[$orderNum]??['status'=>null,'complete_at'=>null,'last_seen'=>null]; $e['last_seen']=time(); $st[$orderNum]=$e; update_option(self::OPT_STATE,$st,false);

        // custom images per item (admin)
        $item_imgs=[]; if (is_singular('page')) { $item_imgs=get_post_meta(get_the_ID(),self::META_ITEM_IMGS,true); if(!is_array($item_imgs)) $item_imgs=[]; }

        $statusLabel=$this->human_status($data);

        $html  = '<div class="printcom-ot">';
        $html .= '<div class="printcom-ot__header"><h2>Status van uw bestelling</h2></div>';
        $html .= '<div class="printcom-ot__status"><strong>Status:</strong> '.esc_html($statusLabel).'</div>';

        $items = $data['items'] ?? [];
        $shipments = $data['shipments'] ?? [];

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

        $html .= '<div class="printcom-ot__items"><h3>Bestelde producten</h3>';
        if($items && is_array($items)){
            $html .= '<div class="printcom-ot__grid">';
            foreach($items as $it){
                $inum = $it['orderItemNumber'] ?? '';
                $qty  = isset($it['quantity']) ? (int)$it['quantity'] : null;
                $sku  = $it['sku'] ?? '';
                // Mooie titel: neem productTranslation.titleSingle/Plural, anders fallback op 'name'
                $title = $this->pretty_product_title($it);

                // Productfoto
                $img_html='';
                if($inum && !empty($item_imgs[$inum])) $img_html = wp_get_attachment_image((int)$item_imgs[$inum],'large',false,['class'=>'printcom-ot__image']);
                if(!$img_html) $img_html = $this->placeholder_svg();

                // Track & Trace
                $links=[]; foreach(($tracks_by_item[$inum]??[]) as $u) $links[]='<a href="'.esc_url($u).'" target="_blank" rel="nofollow noopener">Volg zending</a>';
                $tracks_html = $links ? implode(' &middot; ',$links) : '<em>Track &amp; Trace volgt zodra beschikbaar.</em>';

                // Opties: compact + met labels waar mogelijk
                $options_html = $this->render_options_compact($it['options'] ?? [], $it['productTranslation'] ?? []);

                $html .= '<div class="printcom-ot__card">';
                $html .=   '<div class="printcom-ot__card-img">'.$img_html.'</div>';
                $html .=   '<div class="printcom-ot__card-body">';
                $html .=     '<div class="printcom-ot__card-title">'.esc_html($title).($qty? ' <span class="printcom-ot__muted">×'.(int)$qty.'</span>' : '').'</div>';
                if($sku) $html .= '<div class="printcom-ot__meta"><strong>SKU:</strong> '.esc_html($sku).'</div>';
                if($options_html) $html .= '<div class="printcom-ot__opts">'.$options_html.'</div>';
                $html .=     '<div class="printcom-ot__tracks"><strong>Track &amp; Trace:</strong> '.$tracks_html.'</div>';
                if($inum) $html .= '<div class="printcom-ot__itemnr"><span class="printcom-ot__muted">Itemnummer:</span> '.esc_html($inum).'</div>';
                $html .=   '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<p><em>Er zijn nog geen producten geregistreerd voor deze bestelling.</em></p>';
        }
        $html .= '</div>'; // items
        $html .= '</div>'; // wrapper

        return $html;
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

    private function render_options_compact($options, $translation) : string {
        if (!is_array($options) || !$options) return '';
        $pairs=[]; $max=6;
        $labels = is_array($translation) ? $translation : [];

        foreach ($options as $key=>$val) {
            if (!is_scalar($val) || $val==='') continue;

            // Probeer label te vinden uit productTranslation: "property.<key>" en evt. "property.<key>.option.<value>"
            $prop_label = $labels['property.'.$key] ?? $key;
            $opt_label  = $labels['property.'.$key.'.option.'.$val] ?? $val;

            $pairs[] = '<span class="printcom-ot__chip">'.esc_html($prop_label).': '.esc_html((string)$opt_label).'</span>';
            if (count($pairs) >= $max) break;
        }
        return $pairs ? '<div class="printcom-ot__chips">'.implode(' ',$pairs).'</div>' : '';
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
        <div id="printcom-ot-image-preview" style="margin-bottom:10px;"><?php echo $src?'<img src="'.esc_url($src[0]).'" style="max-width:100%;height:auto;" />':'<em>Geen afbeelding gekozen.</em>'; ?></div>
        <input type="hidden" id="printcom-ot-image-id" name="printcom_ot_image_id" value="<?php echo esc_attr($id); ?>"/>
        <button type="button" class="button" id="printcom-ot-image-upload">Afbeelding kiezen</button>
        <button type="button" class="button" id="printcom-ot-image-remove" <?php disabled(!$id); ?>>Verwijderen</button>
        <script>
        (function($){$(function(){
            let frame;
            $('#printcom-ot-image-upload').on('click',function(e){e.preventDefault(); if(frame){frame.open();return;}
                frame = wp.media({title:'Kies of upload afbeelding',button:{text:'Gebruik deze afbeelding'},multiple:false});
                frame.on('select',function(){const a=frame.state().get('selection').first().toJSON();$('#printcom-ot-image-id').val(a.id);$('#printcom-ot-image-preview').html('<img src="'+a.url+'" style="max-width:100%;height:auto;" />');$('#printcom-ot-image-remove').prop('disabled',false);});
                frame.open();
            });
            $('#printcom-ot-image-remove').on('click',function(e){e.preventDefault();$('#printcom-ot-image-id').val('');$('#printcom-ot-image-preview').html('<em>Geen afbeelding gekozen.</em>');$(this).prop('disabled',true);});
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
        <table class="widefat striped" id="printcom-ot-item-table">
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
                        <div class="printcom-ot-item-prev"><?php echo $src?'<img src="'.esc_url($src[0]).'" style="max-height:60px" />':'<em>Geen</em>'; ?></div>
                        <input type="hidden" name="printcom_ot_item_media[]" value="<?php echo esc_attr((int)$att_id); ?>"/>
                        <button type="button" class="button printcom-ot-pick">Kies/Upload</button>
                        <button type="button" class="button printcom-ot-clear">X</button>
                    </td>
                    <td>
                        <button type="button" class="button link-delete printcom-ot-row-del">Verwijderen</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">
                        <button type="button" class="button" id="printcom-ot-row-add">+ Regel toevoegen</button>
                    </td>
                </tr>
            </tfoot>
        </table>
        <script>
        (function($){
          $(function(){
            const $tbl = $('#printcom-ot-item-table tbody');

            // Nieuwe regel toevoegen (let op type="button" om submit te voorkomen)
            $('#printcom-ot-row-add').on('click', function(e){
              e.preventDefault();
              $tbl.append(`<tr>
                <td><input type="text" name="printcom_ot_item_key[]" value="" class="regular-text" placeholder="bijv. 6001831441-2"/></td>
                <td>
                  <div class="printcom-ot-item-prev"><em>Geen</em></div>
                  <input type="hidden" name="printcom_ot_item_media[]" value=""/>
                  <button type="button" class="button printcom-ot-pick">Kies/Upload</button>
                  <button type="button" class="button printcom-ot-clear">X</button>
                </td>
                <td><button type="button" class="button link-delete printcom-ot-row-del">Verwijderen</button></td>
              </tr>`);
            });

            // Rij verwijderen
            $(document).on('click', '.printcom-ot-row-del', function(e){
              e.preventDefault();
              $(this).closest('tr').remove();
            });

            // Belangrijk: per klik een NIEUW media frame zodat de selectie naar de juiste rij gaat
            $(document).on('click', '.printcom-ot-pick', function(e){
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
                $td.find('.printcom-ot-item-prev').html('<img src="'+a.url+'" style="max-height:60px"/>');
              });

              frame.open();
            });

            // Clear
            $(document).on('click', '.printcom-ot-clear', function(e){
              e.preventDefault();
              const $td = $(this).closest('td');
              $td.find('input[type=hidden]').val('');
              $td.find('.printcom-ot-item-prev').html('<em>Geen</em>');
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
        return '<svg class="printcom-ot__image" role="img" aria-label="Afbeelding volgt" xmlns="http://www.w3.org/2000/svg" width="600" height="338" viewBox="0 0 600 338"><rect width="100%" height="100%" fill="#f2f2f2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#999" font-family="Arial,Helvetica,sans-serif" font-size="18">Afbeelding volgt</text></svg>';
    }

    /* ===== Styles ===== */

    public function enqueue_styles() {
        $css='
        .printcom-ot{border:1px solid #eee;padding:24px;border-radius:12px}
        .printcom-ot__header h2{margin:0 0 8px;font-size:1.5rem}
        .printcom-ot__status{margin:8px 0 20px}
        .printcom-ot__items h3{margin:10px 0}
        .printcom-ot__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
        .printcom-ot__card{border:1px solid #f0f0f0;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;background:#fff}
        .printcom-ot__card-img{background:#fafafa}
        .printcom-ot__image{width:100%;height:auto;display:block}
        .printcom-ot__card-body{padding:12px}
        .printcom-ot__card-title{font-weight:700;margin-bottom:6px}
        .printcom-ot__muted{color:#777}
        .printcom-ot__meta{font-size:.9rem;margin-bottom:6px}
        .printcom-ot__opts{margin:8px 0}
        .printcom-ot__chips{display:flex;flex-wrap:wrap;gap:6px}
        .printcom-ot__chip{background:#f5f5f5;border:1px solid #eee;border-radius:999px;padding:2px 8px;font-size:.8rem}
        .printcom-ot__tracks{margin-top:8px}
        .printcom-ot--error{border:1px solid #f5c2c7;background:#f8d7da;padding:12px;border-radius:8px;color:#842029}
        ';
        wp_register_style('printcom-ot-style', false); wp_enqueue_style('printcom-ot-style'); wp_add_inline_style('printcom-ot-style',$css);
    }

    /* ===== HTTP helpers ===== */

    private function get_settings(){ return get_option(self::OPT_SETTINGS,[]); }
    public function maybe_force_ipv4_for_printcom($h,$r,$url){ $s=$this->get_settings(); if(!empty($s['force_ipv4']) && is_string($url) && stripos($url,'https://api.print.com')===0 && defined('CURLOPT_IPRESOLVE')) curl_setopt($h,CURLOPT_IPRESOLVE,CURL_IPRESOLVE_V4); }

    /* ===== API client ===== */

    private function api_get_order($orderNum) {
        $s = $this->get_settings();
        $base = rtrim($s['api_base_url'] ?? 'https://api.print.com', '/');
        $url  = $base.'/orders/'.rawurlencode($orderNum);

        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $args=['headers'=>[
            'Authorization'=>'Bearer '.$token,
            'Accept'=>'application/json',
            'User-Agent'=>'RMH-Printcom-Tracker/1.6.1 (+WordPress)'],
            'timeout'=>20
        ];
        $res=wp_remote_get($url,$args);
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
        $ua='RMH-Printcom-Tracker/1.6.1 (+WordPress)';

        $is_print_login=(stripos($auth,'/login')!==false);
        if ($is_print_login){
            if ($username==='' || $password==='') return new WP_Error('printcom_auth_missing','Username/Password ontbreken.');
            $payload=!empty($s['alt_payload'])?wp_json_encode(['username'=>$username,'password'=>$password]):wp_json_encode(['credentials'=>['username'=>$username,'password'=>$password]]);
            $res=wp_remote_post($auth,['headers'=>['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>$ua],'body'=>$payload,'timeout'=>20]);
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
        $res=wp_remote_post($auth,['headers'=>['Accept'=>'application/json','User-Agent'=>$ua],'body'=>$body,'timeout'=>20]);
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

    public function cron_refresh_token(){ delete_transient(self::TRANSIENT_TOKEN); $t=$this->get_access_token(true); if(is_wp_error($t)) error_log('[Printcom OT] Token verversen mislukt: '.$t->get_error_message()); else error_log('[Printcom OT] Token ververst.'); }
    public function cron_warm_cache(){
        $st=get_option(self::OPT_STATE,[]); $map=get_option(self::OPT_MAPPINGS,[]); if(empty($map))return;
        $hot_limit=50; $cold_limit=20; $archive_days=14; $now=time(); $orders=[];
        foreach($map as $ord=>$pid){
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
        error_log('[Printcom OT] Warmed HOT: '.$ph.'; COLD: '.$pc.'.');
    }
}

/* ===== Hooks ===== */
register_activation_hook(__FILE__, ['Printcom_Order_Tracker','activate']);
register_deactivation_hook(__FILE__, ['Printcom_Order_Tracker','deactivate']);

/* ===== Admin-post: debug & acties ===== */

// /login test
add_action('admin_post_printcom_ot_test_connection', function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    if(empty($_POST['printcom_ot_test_conn_nonce']) || !wp_verify_nonce($_POST['printcom_ot_test_conn_nonce'],'printcom_ot_test_conn')) wp_die('Nonce invalid');
    $s=get_option(Printcom_Order_Tracker::OPT_SETTINGS,[]);
    $auth=$s['auth_url']??''; $u=trim($s['username']??''); $p=preg_replace("/\r\n|\r|\n/","",(string)($s['password']??'')); $ua='RMH-Printcom-Tracker/1.6.1 (+WordPress)';
    if(!$auth||!$u||!$p){ $msg='❌ Ontbrekende instellingen (auth_url/username/password).'; }
    else{
        $payload=!empty($s['alt_payload'])?wp_json_encode(['username'=>$u,'password'=>$p]):wp_json_encode(['credentials'=>['username'=>$u,'password'=>$p]]);
        $args=['headers'=>['Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>$ua],'body'=>$payload,'timeout'=>20];
        $res=wp_remote_post($auth,$args);
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
        $res=wp_remote_get($url,['headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','User-Agent'=>'RMH-Printcom-Tracker/1.6.1 (+WordPress)'],'timeout'=>20]);
        if(is_wp_error($res)){ $msg='❌ Transportfout: '.esc_html($res->get_error_message()); }
        else{ $code=wp_remote_retrieve_response_code($res); $raw=wp_remote_retrieve_body($res); $hdrs=wp_remote_retrieve_headers($res); $body_preview=$raw?mb_substr($raw,0,260):''; $hints=[]; foreach(['www-authenticate','x-request-id','server'] as $h) if(!empty($hdrs[$h])) $hints[]=strtoupper($h).': '.$hdrs[$h];
            $msg='🔎 Token: len='.$len.', starts="'.esc_html($prefix).'" | ';
            if($code>=200&&$code<300){ $msg.='✅ Order OK ('.$code.'). Body ~'.strlen($raw).' bytes.'; }
            else { $msg.='❌ Order fout ('.$code.'). '.($body_preview?'Body: '.sanitize_text_field($body_preview):'Geen body.'); if($hints) $msg.=' | Hdr: '.esc_html(implode(' | ',$hints)); }
        }
    }
    $dest=wp_get_referer()?:admin_url('options-general.php?page=printcom-orders-settings'); wp_safe_redirect(add_query_arg('printcom_test_order_result',rawurlencode($msg),$dest)); exit;
});

// Mapping verwijderen
add_action('admin_post_printcom_ot_delete_order', function(){
    if(!current_user_can('manage_options')) wp_die('Unauthorized');
    $order=isset($_GET['order'])?sanitize_text_field(wp_unslash($_GET['order'])):''; if($order==='') wp_die('Order ontbreekt.');
    $nonce_key='printcom_ot_delete_order_'.$order; if(empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])),$nonce_key)) wp_die('Nonce invalid');
    $plugin=new Printcom_Order_Tracker();
    try{ $ref=new ReflectionClass($plugin); $m=$ref->getMethod('remove_order_mapping'); $m->setAccessible(true); $ok=$m->invoke($plugin,$order,false);}catch(Throwable $e){ $ok=false; }
    $dest=admin_url('admin.php?page=printcom-orders'); if($ok) $dest=add_query_arg('printcom_deleted_order',rawurlencode($order),$dest); wp_safe_redirect($dest); exit;
});

// Start
new Printcom_Order_Tracker();