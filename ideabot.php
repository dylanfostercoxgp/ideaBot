<?php
/**
 * Plugin Name: ideaBot
 * Plugin URI:  https://ideaboss.io
 * Description: ideaBot by ideaBoss — a conversational lead qualification chat widget. Walks visitors through a guided discovery conversation, captures qualified leads, and sends personalized follow-up emails automatically.
 * Version:     1.0.2
 * Author:      ideaBoss / Cox Group
 * Author URI:  https://ideaboss.io
 * License:     GPL v2 or later
 * Text Domain: ideabot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IDEABOT_VERSION',     '1.0.3' );
define( 'IDEABOT_DB_VER',      '1.0.1' );
define( 'IDEABOT_DIR',         plugin_dir_path( __FILE__ ) );
define( 'IDEABOT_URL',         plugin_dir_url( __FILE__ ) );
define( 'IDEABOT_GITHUB_REPO', 'dylanfostercoxgp/ideaBot' );

// ================================================================
// GITHUB AUTO-UPDATER
// Checks GitHub Releases for new versions and surfaces them in
// WordPress Dashboard → Plugins → "Update available".
// Requires: public GitHub repo with releases tagged v1.0.3, v1.0.4 …
// Each release must have the plugin ZIP attached as a release asset
// (or WordPress will use GitHub's auto-generated zipball).
// To set up: create a release at github.com/YOUR_USERNAME/ideabot/releases/new
// ================================================================
class IdeaBot_GitHub_Updater {

    private $slug;
    private $repo;
    private $plugin_file;
    private $api_cache = null;

    public function __construct( $plugin_file ) {
        $this->slug        = plugin_basename( $plugin_file );
        $this->repo        = IDEABOT_GITHUB_REPO;
        $this->plugin_file = $plugin_file;

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update'  ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info'   ], 10, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'post_install'  ], 10, 3 );
    }

    /** Fetch latest release from GitHub API (cached per request). */
    private function get_release() {
        if ( $this->api_cache !== null ) return $this->api_cache;
        $url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ideaBot-updater',
            ],
        ] );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            $this->api_cache = false;
            return false;
        }
        $this->api_cache = json_decode( wp_remote_retrieve_body( $response ) );
        return $this->api_cache;
    }

    /** Inject update info into the WordPress update transient. */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;
        $release = $this->get_release();
        if ( ! $release || empty( $release->tag_name ) ) return $transient;

        $remote_ver = ltrim( $release->tag_name, 'v' );
        if ( version_compare( IDEABOT_VERSION, $remote_ver, '<' ) ) {
            // Prefer an explicitly uploaded zip asset; fall back to GitHub's zipball.
            $zip_url = $release->zipball_url;
            if ( ! empty( $release->assets ) ) {
                foreach ( $release->assets as $asset ) {
                    if ( false !== strpos( strtolower( $asset->name ), '.zip' ) ) {
                        $zip_url = $asset->browser_download_url;
                        break;
                    }
                }
            }
            $transient->response[ $this->slug ] = (object) [
                'slug'        => dirname( $this->slug ),
                'plugin'      => $this->slug,
                'new_version' => $remote_ver,
                'url'         => 'https://github.com/' . $this->repo,
                'package'     => $zip_url,
            ];
        }
        return $transient;
    }

    /** Populate the "View version details" modal in Plugins. */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) return $result;
        if ( empty( $args->slug ) || $args->slug !== dirname( $this->slug ) ) return $result;

        $release = $this->get_release();
        if ( ! $release ) return $result;

        $remote_ver = ltrim( $release->tag_name, 'v' );
        return (object) [
            'name'          => 'ideaBot',
            'slug'          => dirname( $this->slug ),
            'version'       => $remote_ver,
            'author'        => '<a href="https://ideaboss.io">ideaBoss / Cox Group</a>',
            'homepage'      => 'https://ideaboss.io',
            'download_link' => $release->zipball_url,
            'last_updated'  => isset( $release->published_at ) ? $release->published_at : '',
            'sections'      => [
                'description' => '<p>ideaBot — conversational lead qualification widget by ideaBoss. Walks visitors through a guided discovery chat, captures qualified leads, and sends premium branded follow-up emails automatically.</p>',
                'changelog'   => isset( $release->body ) && $release->body
                                    ? '<pre>' . esc_html( $release->body ) . '</pre>'
                                    : '<p>See <a href="https://github.com/' . esc_attr( $this->repo ) . '/releases" target="_blank">GitHub Releases</a> for full changelog.</p>',
            ],
        ];
    }

    /**
     * After WordPress extracts the ZIP, move it into the correct plugin folder.
     * GitHub zips extract to a randomly-named folder (e.g. user-repo-abc1234/);
     * this step renames it to the expected /ideabot/ directory.
     */
    public function post_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;
        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) return $result;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->slug );
        $wp_filesystem->move( $result['destination'], $plugin_dir, true );
        $result['destination'] = $plugin_dir;

        if ( is_plugin_active( $this->slug ) ) {
            activate_plugin( $this->slug );
        }
        return $result;
    }
}

// Only run updater in admin context to avoid front-end overhead.
if ( is_admin() ) {
    new IdeaBot_GitHub_Updater( __FILE__ );
}

// ================================================================
// HELPERS
// ================================================================
function ideabot_get( $key, $default = '' ) {
    return get_option( 'ideabot_' . $key, $default );
}
function ideabot_opts_array( $key, $default_array = [] ) {
    $raw = ideabot_get( $key, '' );
    if ( empty( trim( $raw ) ) ) return $default_array;
    return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
}

// ================================================================
// DEFAULT VALUES
// ================================================================
function ideabot_defaults() {
    return [
        // General
        'enabled'               => '1',
        'accent_color'          => '#00C2FF',
        'bubble_label'          => "Let's Talk",
        'bubble_pos'            => 'right',
        'widget_title'          => 'ideaBot',
        'bot_icon'              => '💡',
        'contact_email'         => 'hello@ideaboss.io',
        // Conversation — messages
        'welcome'               => "Hey there! 👋 I'm <strong>ideaBot</strong> from ideaBoss.\n\nWe install AI systems that turn ideas into revenue — and I'd love to learn about <em>your</em> business. Mind if I ask a few questions?",
        'success1'              => "You're all set, {first_name}! 🎯",
        'success2'              => "Check your inbox — we sent you a quick intro. Someone from the ideaBoss team will follow up within <strong>1 business day</strong>.<br><br><em>Act. Build. Repeat.</em> 💡",
        'success_cta'           => 'Explore ideaboss.io →',
        // Conversation — questions
        'q_name'                => "Let's start simple — what's your first name?",
        'q_name_ph'             => 'Your first name',
        'q_industry'            => "Great to meet you, {first_name}! What industry are you in?",
        'q_industry_ph'         => 'e.g. Real Estate, HVAC, Healthcare, SaaS…',
        'q_revenue'             => 'Got it. Roughly where is your business in terms of annual revenue?',
        'q_revenue_opts'        => "Under \$500K\n\$500K – \$2M\n\$2M – \$10M\n\$10M – \$50M\n\$50M+",
        'q_challenge'           => "What's your biggest challenge right now?",
        'q_challenge_opts'      => "Repetitive tasks eating time & margin\nHard to stay visible online\nLeads going cold without follow-up\nCan't scale without doing everything myself\nStrategy exists but execution doesn't",
        'q_ai_exp'              => 'Have you used AI tools in your business before?',
        'q_ai_exp_opts'         => "Yes — actively using AI tools\nTried a few things, nothing stuck\nNot yet — just getting started",
        'q_team'                => 'How big is your team right now?',
        'q_team_opts'           => "Just me (solo founder)\n2 – 10 people\n11 – 50 people\n50+ people",
        'q_timeline'            => 'How soon are you looking to make a change?',
        'q_timeline_opts'       => "ASAP — this is urgent\nWithin the next 1 – 3 months\nJust exploring for now",
        'q_win'                 => 'Last thing before I grab your contact info — what would a big win look like for your business in the next 90 days?',
        'q_win_ph'              => 'e.g. Close 5 new deals, automate onboarding, stay top-of-mind with leads…',
        'q_email'               => "Love it, {first_name}. What's the best email to send you some ideas?",
        'q_phone'               => 'And a phone number? (totally optional — skip if you prefer)',
        // Emails
        'notification_email'    => '',
        'cc_email'              => '',
        'from_name'             => 'ideaBoss',
        'from_email'            => '',
        'reply_to'              => '',
        'notify_subject'        => '🎯 New ideaBot Lead: {first_name} — {industry}',
        'followup_subject'      => "Hey {first_name} — we got your info 👋",
        'cta_button_text'       => 'Explore ideaBoss →',
        'cta_url'               => 'https://ideaboss.io',
        'signoff_name'          => 'Dylan Cox & The ideaBoss Team',
        // Display
        'open_delay'            => '0',
        'auto_open'             => '0',
        'hide_mobile'           => '0',
        'excluded_ids'          => '',
        'z_index'               => '999999',
        // Integrations
        'webhook_enabled'       => '0',
        'webhook_url'           => '',
        'webhook_secret'        => '',
    ];
}

function ideabot_default( $key ) {
    $d = ideabot_defaults();
    return $d[ $key ] ?? '';
}

// ================================================================
// ACTIVATION — create DB + set default options
// ================================================================
register_activation_hook( __FILE__, 'ideabot_activate' );
function ideabot_activate() {
    ideabot_run_db_upgrade();
    foreach ( ideabot_defaults() as $key => $val ) {
        add_option( 'ideabot_' . $key, $val );
    }
    update_option( 'ideabot_db_version', IDEABOT_DB_VER );
}

function ideabot_run_db_upgrade() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ideaboss_leads';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        first_name        VARCHAR(100)  DEFAULT '',
        industry          VARCHAR(200)  DEFAULT '',
        revenue_range     VARCHAR(100)  DEFAULT '',
        biggest_challenge VARCHAR(300)  DEFAULT '',
        ai_experience     VARCHAR(100)  DEFAULT '',
        team_size         VARCHAR(100)  DEFAULT '',
        timeline          VARCHAR(100)  DEFAULT '',
        win_definition    TEXT,
        email             VARCHAR(200)  DEFAULT '',
        phone             VARCHAR(50)   DEFAULT '',
        ip_address        VARCHAR(50)   DEFAULT '',
        created_at        DATETIME      DEFAULT CURRENT_TIMESTAMP
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

add_action( 'plugins_loaded', 'ideabot_maybe_upgrade' );
function ideabot_maybe_upgrade() {
    $installed = get_option( 'ideabot_db_version', '0' );
    if ( version_compare( $installed, IDEABOT_DB_VER, '<' ) ) {
        ideabot_run_db_upgrade();
        update_option( 'ideabot_db_version', IDEABOT_DB_VER );
    }
    // Ensure all defaults exist (safe for upgrades from earlier versions)
    foreach ( ideabot_defaults() as $key => $val ) {
        add_option( 'ideabot_' . $key, $val );
    }
}

// ================================================================
// EMAIL SENDER FILTERS — Mailgun / SMTP compatible
// Filters let Mailgun/WP Mail SMTP continue to own the connection
// while we control the friendly from-name displayed to recipients.
// ================================================================
add_filter( 'wp_mail_from',      'ideabot_mail_from',      15 );
add_filter( 'wp_mail_from_name', 'ideabot_mail_from_name', 15 );

function ideabot_mail_from( $email ) {
    $configured = ideabot_get( 'from_email', '' );
    return ( $configured && is_email( $configured ) ) ? $configured : $email;
}
function ideabot_mail_from_name( $name ) {
    $configured = ideabot_get( 'from_name', '' );
    return $configured ?: $name;
}

// ================================================================
// ENQUEUE
// ================================================================
add_action( 'wp_enqueue_scripts', 'ideabot_enqueue' );
function ideabot_enqueue() {
    if ( ideabot_get( 'enabled', '1' ) !== '1' ) return;
    $excluded = array_filter( array_map( 'intval', explode( ',', ideabot_get( 'excluded_ids', '' ) ) ) );
    if ( ! empty( $excluded ) && is_singular() && in_array( get_the_ID(), $excluded, true ) ) return;
    if ( ideabot_get( 'hide_mobile', '0' ) === '1' && wp_is_mobile() ) return;

    wp_enqueue_style(  'ideabot', IDEABOT_URL . 'assets/css/chat.css', [],       IDEABOT_VERSION );
    wp_enqueue_script( 'ideabot', IDEABOT_URL . 'assets/js/chat.js',  [], IDEABOT_VERSION, true );

    $defaults = ideabot_defaults();
    wp_localize_script( 'ideabot', 'ideabotCFG', [
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'ideabot_nonce' ),
        'accentColor' => ideabot_get( 'accent_color',  $defaults['accent_color'] ),
        'zIndex'      => ideabot_get( 'z_index',       $defaults['z_index'] ),
        'display'     => [
            'openDelay' => ideabot_get( 'open_delay', '0' ),
            'autoOpen'  => ideabot_get( 'auto_open',  '0' ),
            'bubblePos' => ideabot_get( 'bubble_pos', 'right' ),
        ],
        'messages' => [
            'welcome'        => ideabot_get( 'welcome',       $defaults['welcome'] ),
            'success1'       => ideabot_get( 'success1',      $defaults['success1'] ),
            'success2'       => ideabot_get( 'success2',      $defaults['success2'] ),
            'successCta'     => ideabot_get( 'success_cta',   $defaults['success_cta'] ),
            'ctaUrl'         => ideabot_get( 'cta_url',       $defaults['cta_url'] ),
            'contactEmail'   => ideabot_get( 'contact_email', $defaults['contact_email'] ),
            'qName'          => ideabot_get( 'q_name',        $defaults['q_name'] ),
            'qNamePh'        => ideabot_get( 'q_name_ph',     $defaults['q_name_ph'] ),
            'qIndustry'      => ideabot_get( 'q_industry',    $defaults['q_industry'] ),
            'qIndustryPh'    => ideabot_get( 'q_industry_ph', $defaults['q_industry_ph'] ),
            'qRevenue'       => ideabot_get( 'q_revenue',     $defaults['q_revenue'] ),
            'qRevenueOpts'   => ideabot_opts_array( 'q_revenue_opts',   explode( "\n", $defaults['q_revenue_opts'] ) ),
            'qChallenge'     => ideabot_get( 'q_challenge',   $defaults['q_challenge'] ),
            'qChallengeOpts' => ideabot_opts_array( 'q_challenge_opts', explode( "\n", $defaults['q_challenge_opts'] ) ),
            'qAiExp'         => ideabot_get( 'q_ai_exp',      $defaults['q_ai_exp'] ),
            'qAiExpOpts'     => ideabot_opts_array( 'q_ai_exp_opts',    explode( "\n", $defaults['q_ai_exp_opts'] ) ),
            'qTeam'          => ideabot_get( 'q_team',        $defaults['q_team'] ),
            'qTeamOpts'      => ideabot_opts_array( 'q_team_opts',      explode( "\n", $defaults['q_team_opts'] ) ),
            'qTimeline'      => ideabot_get( 'q_timeline',    $defaults['q_timeline'] ),
            'qTimelineOpts'  => ideabot_opts_array( 'q_timeline_opts',  explode( "\n", $defaults['q_timeline_opts'] ) ),
            'qWin'           => ideabot_get( 'q_win',         $defaults['q_win'] ),
            'qWinPh'         => ideabot_get( 'q_win_ph',      $defaults['q_win_ph'] ),
            'qEmail'         => ideabot_get( 'q_email',       $defaults['q_email'] ),
            'qPhone'         => ideabot_get( 'q_phone',       $defaults['q_phone'] ),
        ],
    ] );
}

// ================================================================
// OUTPUT WIDGET HTML
// ================================================================
add_action( 'wp_footer', 'ideabot_output_html' );
function ideabot_output_html() {
    if ( ideabot_get( 'enabled', '1' ) !== '1' ) return;
    $excluded = array_filter( array_map( 'intval', explode( ',', ideabot_get( 'excluded_ids', '' ) ) ) );
    if ( ! empty( $excluded ) && is_singular() && in_array( get_the_ID(), $excluded, true ) ) return;
    if ( ideabot_get( 'hide_mobile', '0' ) === '1' && wp_is_mobile() ) return;

    $defaults = ideabot_defaults();
    $accent   = esc_attr( ideabot_get( 'accent_color', $defaults['accent_color'] ) );
    $z        = esc_attr( ideabot_get( 'z_index',      $defaults['z_index'] ) );
    $pos      = ideabot_get( 'bubble_pos', 'right' ) === 'left' ? 'ib-pos-left' : 'ib-pos-right';
    $icon     = esc_html( ideabot_get( 'bot_icon',     $defaults['bot_icon'] ) );
    $label    = esc_html( ideabot_get( 'bubble_label', $defaults['bubble_label'] ) );
    $title    = esc_html( ideabot_get( 'widget_title', $defaults['widget_title'] ) );
    ?>
    <div id="ib-chat-wrap" class="<?php echo esc_attr( $pos ); ?>"
         style="--ib-accent:<?php echo $accent; ?>;z-index:<?php echo $z; ?>;">
        <button id="ib-bubble" aria-label="Chat with <?php echo $title; ?>" aria-expanded="false">
            <span id="ib-bubble-icon" aria-hidden="true"><?php echo $icon; ?></span>
            <span id="ib-bubble-text"><?php echo $label; ?></span>
        </button>
        <div id="ib-chat-window" role="dialog" aria-label="<?php echo $title; ?> Chat" aria-live="polite">
            <div id="ib-chat-header">
                <span><?php echo $icon . ' ' . $title; ?></span>
                <button id="ib-close" aria-label="Close chat">✕</button>
            </div>
            <div id="ib-messages"></div>
            <div id="ib-input-area"></div>
        </div>
    </div>
    <?php
}

// ================================================================
// AJAX — SUBMIT LEAD (front-end chat)
// NOTE: wp_unslash() is REQUIRED before sanitize — WordPress applies
// wp_magic_quotes() to all $_POST data at request start.
// ================================================================
add_action( 'wp_ajax_ideabot_submit',        'ideabot_submit' );
add_action( 'wp_ajax_nopriv_ideabot_submit', 'ideabot_submit' );

function ideabot_submit() {
    check_ajax_referer( 'ideabot_nonce', 'nonce' );
    $p = wp_unslash( $_POST ); // Strip magic-quote slashes added by WordPress

    $data = [
        'first_name'        => sanitize_text_field(     $p['first_name']        ?? '' ),
        'industry'          => sanitize_text_field(     $p['industry']          ?? '' ),
        'revenue_range'     => sanitize_text_field(     $p['revenue_range']     ?? '' ),
        'biggest_challenge' => sanitize_text_field(     $p['biggest_challenge'] ?? '' ),
        'ai_experience'     => sanitize_text_field(     $p['ai_experience']     ?? '' ),
        'team_size'         => sanitize_text_field(     $p['team_size']         ?? '' ),
        'timeline'          => sanitize_text_field(     $p['timeline']          ?? '' ),
        'win_definition'    => sanitize_textarea_field( $p['win_definition']    ?? '' ),
        'email'             => sanitize_email(           $p['email']            ?? '' ),
        'phone'             => sanitize_text_field(     $p['phone']             ?? '' ),
        'ip_address'        => sanitize_text_field(     $_SERVER['REMOTE_ADDR'] ?? '' ),
    ];

    if ( ! is_email( $data['email'] ) ) {
        wp_send_json_error( [ 'message' => 'Invalid email.' ] );
    }

    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'ideaboss_leads', $data );
    $lead_id = $wpdb->insert_id;

    ideabot_notify_team( $data );
    ideabot_send_followup( $data );
    ideabot_fire_webhook( $data );

    wp_send_json_success( [ 'message' => 'Lead saved.', 'lead_id' => $lead_id ] );
}

// ================================================================
// AJAX — ADMIN: resend follow-up email
// ================================================================
add_action( 'wp_ajax_ideabot_resend_followup', 'ideabot_resend_followup' );
function ideabot_resend_followup() {
    check_ajax_referer( 'ideabot_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

    $id   = intval( $_POST['lead_id'] ?? 0 );
    global $wpdb;
    $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ideaboss_leads WHERE id = %d", $id ), ARRAY_A );
    if ( ! $lead ) { wp_send_json_error( [ 'message' => 'Lead not found.' ] ); }

    ideabot_send_followup( $lead );
    wp_send_json_success( [ 'message' => 'Follow-up email resent to ' . esc_html( $lead['email'] ) . '.' ] );
}

// ================================================================
// AJAX — ADMIN: resend team notification
// ================================================================
add_action( 'wp_ajax_ideabot_resend_notify', 'ideabot_resend_notify' );
function ideabot_resend_notify() {
    check_ajax_referer( 'ideabot_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

    $id   = intval( $_POST['lead_id'] ?? 0 );
    global $wpdb;
    $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ideaboss_leads WHERE id = %d", $id ), ARRAY_A );
    if ( ! $lead ) { wp_send_json_error( [ 'message' => 'Lead not found.' ] ); }

    ideabot_notify_team( $lead );
    wp_send_json_success( [ 'message' => 'Team notification resent.' ] );
}

// ================================================================
// AJAX — ADMIN: delete lead
// ================================================================
add_action( 'wp_ajax_ideabot_delete_lead', 'ideabot_delete_lead' );
function ideabot_delete_lead() {
    check_ajax_referer( 'ideabot_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

    $id = intval( $_POST['lead_id'] ?? 0 );
    global $wpdb;
    $deleted = $wpdb->delete( $wpdb->prefix . 'ideaboss_leads', [ 'id' => $id ], [ '%d' ] );
    if ( $deleted ) {
        wp_send_json_success( [ 'message' => 'Lead deleted.' ] );
    } else {
        wp_send_json_error( [ 'message' => 'Could not delete lead.' ] );
    }
}

// ================================================================
// EMAIL — TEAM NOTIFICATION (premium dark template)
// ================================================================
function ideabot_notify_team( $data ) {
    $defaults = ideabot_defaults();
    $to_raw   = ideabot_get( 'notification_email', '' ) ?: get_option( 'admin_email' );
    $to       = array_filter( array_map( 'trim', explode( ',', $to_raw ) ) );
    $cc_raw   = ideabot_get( 'cc_email', '' );
    $reply_to = ideabot_get( 'reply_to', '' );
    $accent   = ideabot_get( 'accent_color', $defaults['accent_color'] );
    $time     = current_time( 'F j, Y \a\t g:i a' );

    $subject  = ideabot_get( 'notify_subject', $defaults['notify_subject'] );
    $subject  = str_replace( [ '{first_name}', '{industry}' ], [ $data['first_name'], $data['industry'] ], $subject );

    $fields = [
        [ '👤 Name',              esc_html( $data['first_name'] ) ],
        [ '✉️ Email',             '<a href="mailto:' . esc_attr( $data['email'] ) . '" style="color:' . esc_attr($accent) . ';font-weight:600;">' . esc_html( $data['email'] ) . '</a>' ],
        [ '📞 Phone',             esc_html( $data['phone'] ?: '—' ) ],
        [ '🏢 Industry',          esc_html( $data['industry'] ) ],
        [ '💰 Revenue',           esc_html( $data['revenue_range'] ) ],
        [ '🔥 Biggest Challenge', esc_html( $data['biggest_challenge'] ) ],
        [ '🤖 AI Experience',     esc_html( $data['ai_experience'] ) ],
        [ '👥 Team Size',         esc_html( $data['team_size'] ) ],
        [ '⏱ Timeline',           esc_html( $data['timeline'] ) ],
        [ '🎯 90-Day Win',        esc_html( $data['win_definition'] ) ],
    ];

    $rows_html = '';
    foreach ( $fields as $i => $f ) {
        $bg = ( $i % 2 === 0 ) ? '#f8f9fa' : '#ffffff';
        $rows_html .= "<tr style='background:{$bg};'>
            <td style='padding:10px 16px;font-size:13px;font-weight:600;color:#555;width:38%;border-bottom:1px solid #eee;white-space:nowrap;'>{$f[0]}</td>
            <td style='padding:10px 16px;font-size:13px;color:#111;border-bottom:1px solid #eee;'>{$f[1]}</td>
        </tr>";
    }

    $service = ideabot_map_challenge( $data['biggest_challenge'] );

    $body = "<!DOCTYPE html>
<html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#1a1a1a;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#1a1a1a;'>
<tr><td align='center' style='padding:32px 16px;'>
<table width='600' cellpadding='0' cellspacing='0' border='0' style='max-width:600px;width:100%;'>

  <!-- HEADER -->
  <tr><td style='background:#0a0a0a;padding:24px 28px;border-radius:10px 10px 0 0;border-bottom:3px solid {$accent};'>
    <table width='100%' cellpadding='0' cellspacing='0' border='0'>
      <tr>
        <td><span style='font-size:22px;font-weight:800;color:{$accent};letter-spacing:-0.5px;'>💡 ideaBot</span>
          <span style='font-size:12px;color:#555;margin-left:10px;'>by ideaBoss</span></td>
        <td align='right'><span style='background:{$accent};color:#000;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;letter-spacing:.5px;text-transform:uppercase;'>New Lead</span></td>
      </tr>
    </table>
  </td></tr>

  <!-- LEAD SUMMARY BAR -->
  <tr><td style='background:#111;padding:16px 28px;'>
    <table width='100%' cellpadding='0' cellspacing='0' border='0'>
      <tr>
        <td style='color:#fff;font-size:18px;font-weight:700;'>{$data['first_name']} <span style='color:#666;font-weight:400;font-size:14px;'>— {$data['industry']}</span></td>
        <td align='right' style='color:#555;font-size:12px;'>{$time}</td>
      </tr>
    </table>
  </td></tr>

  <!-- CHALLENGE CALLOUT -->
  <tr><td style='background:#0d1117;padding:16px 28px;border-left:4px solid {$accent};'>
    <p style='margin:0 0 4px;font-size:11px;color:#555;text-transform:uppercase;letter-spacing:.8px;font-weight:600;'>Matched Service</p>
    <p style='margin:0;font-size:15px;color:{$accent};font-weight:700;'>{$service[0]}</p>
  </td></tr>

  <!-- DATA TABLE -->
  <tr><td style='background:#fff;border:1px solid #ddd;border-top:none;'>
    <table width='100%' cellpadding='0' cellspacing='0' border='0'>{$rows_html}</table>
  </td></tr>

  <!-- CTA -->
  <tr><td style='background:#fff;padding:20px 28px;text-align:center;border:1px solid #ddd;border-top:none;border-radius:0 0 10px 10px;'>
    <a href='mailto:{$data['email']}' style='display:inline-block;background:{$accent};color:#000;font-weight:700;padding:12px 28px;border-radius:6px;text-decoration:none;font-size:14px;margin-right:8px;'>
      Reply to {$data['first_name']} →
    </a>
    <span style='color:#999;font-size:12px;'>{$data['email']}</span>
  </td></tr>

  <!-- FOOTER -->
  <tr><td style='padding:16px 0;text-align:center;'>
    <p style='margin:0;font-size:11px;color:#555;'>ideaBoss® &nbsp;·&nbsp; AI Creative Company &nbsp;·&nbsp; Cox Group &nbsp;·&nbsp;
      <a href='https://ideaboss.io' style='color:{$accent};text-decoration:none;'>ideaboss.io</a></p>
  </td></tr>

</table>
</td></tr></table>
</body></html>";

    // Content-Type only — From is handled by wp_mail_from filter (Mailgun compatible)
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    if ( $cc_raw )   $headers[] = 'Cc: ' . $cc_raw;
    if ( $reply_to ) $headers[] = 'Reply-To: ' . $reply_to;

    wp_mail( $to, $subject, $body, $headers );
}

// ================================================================
// EMAIL — VISITOR FOLLOW-UP (premium branded template)
// ================================================================
function ideabot_send_followup( $data ) {
    $defaults    = ideabot_defaults();
    $reply_to    = ideabot_get( 'reply_to',        '' );
    $cta_url     = ideabot_get( 'cta_url',         $defaults['cta_url'] );
    $cta_txt     = ideabot_get( 'cta_button_text', $defaults['cta_button_text'] );
    $signoff     = ideabot_get( 'signoff_name',    $defaults['signoff_name'] );
    $accent      = ideabot_get( 'accent_color',    $defaults['accent_color'] );

    $subject = ideabot_get( 'followup_subject', $defaults['followup_subject'] );
    $subject = str_replace( [ '{first_name}', '{industry}' ], [ $data['first_name'], $data['industry'] ], $subject );

    $name     = esc_html( $data['first_name'] );
    $industry = esc_html( $data['industry'] );
    $win      = esc_html( $data['win_definition'] );
    $service  = ideabot_map_challenge( $data['biggest_challenge'] );
    $challenge_esc = esc_html( $data['biggest_challenge'] );
    $cta_esc  = esc_url( $cta_url );
    $win_block = $win ? "<tr><td style='padding:0 0 20px;'>
        <p style='margin:0;color:#444;line-height:1.75;font-size:14px;'>Your 90-day goal — <em style='color:#111;font-style:italic;'>\"" . $win . "\"</em> — is absolutely achievable. Let's map out how to get there.</p>
    </td></tr>" : '';

    $body = "<!DOCTYPE html>
<html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f0f0f0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#f0f0f0;'>
<tr><td align='center' style='padding:28px 16px;'>
<table width='600' cellpadding='0' cellspacing='0' border='0' style='max-width:600px;width:100%;'>

  <!-- HEADER -->
  <tr><td style='background:#0a0a0a;padding:32px 36px;text-align:center;border-radius:10px 10px 0 0;'>
    <p style='margin:0 0 6px;font-size:32px;line-height:1;'>💡</p>
    <p style='margin:0 0 2px;font-size:26px;font-weight:800;color:{$accent};letter-spacing:-0.5px;'>ideaBoss</p>
    <p style='margin:0;font-size:12px;color:#555;letter-spacing:.5px;text-transform:uppercase;'>AI Creative Company &nbsp;·&nbsp; Cox Group &nbsp;·&nbsp; Est. 1999</p>
  </td></tr>

  <!-- BODY -->
  <tr><td style='background:#fff;padding:36px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0;'>
    <table width='100%' cellpadding='0' cellspacing='0' border='0'>

      <!-- GREETING -->
      <tr><td style='padding:0 0 16px;'>
        <p style='margin:0;font-size:20px;font-weight:700;color:#111;'>Hey {$name},</p>
      </td></tr>
      <tr><td style='padding:0 0 20px;'>
        <p style='margin:0;color:#555;line-height:1.75;font-size:14px;'>Thanks for taking a few minutes to tell us about your business — I read every answer. Here's what stood out:</p>
      </td></tr>

      <!-- CHALLENGE CALLOUT BOX -->
      <tr><td style='padding:0 0 20px;'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#f5f9ff;border-left:4px solid {$accent};border-radius:0 8px 8px 0;'>
          <tr>
            <td style='padding:16px 20px;'>
              <p style='margin:0 0 3px;font-size:10px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.8px;'>Your Challenge</p>
              <p style='margin:0 0 12px;font-size:14px;font-weight:700;color:#111;'>{$challenge_esc}</p>
              <p style='margin:0 0 3px;font-size:10px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.8px;'>Where We Come In</p>
              <p style='margin:0;font-size:14px;font-weight:700;color:{$accent};'>{$service[0]}</p>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- SERVICE DESCRIPTION -->
      <tr><td style='padding:0 0 20px;'>
        <p style='margin:0;color:#444;line-height:1.75;font-size:14px;'>{$service[1]}</p>
      </td></tr>

      <!-- INDUSTRY LINE -->
      <tr><td style='padding:0 0 20px;'>
        <p style='margin:0;color:#444;line-height:1.75;font-size:14px;'>You're in <strong style='color:#111;'>{$industry}</strong> — one of the 100+ industries we've worked across in 26 years with Cox Group. We've solved this challenge before, and we know what moves the needle.</p>
      </td></tr>

      <!-- 90-DAY WIN (conditional) -->
      {$win_block}

      <!-- DIVIDER -->
      <tr><td style='padding:0 0 20px;'><hr style='border:none;border-top:1px solid #eee;margin:0;'></td></tr>

      <!-- NEXT STEPS -->
      <tr><td style='padding:0 0 8px;'>
        <p style='margin:0;font-size:15px;font-weight:700;color:#111;'>What happens next</p>
      </td></tr>

      <tr><td style='padding:0 0 6px;'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0'>
          <tr>
            <td width='32' valign='top' style='padding:8px 0;'><span style='display:inline-block;width:24px;height:24px;background:{$accent};color:#000;font-size:11px;font-weight:800;text-align:center;line-height:24px;border-radius:50%;'>1</span></td>
            <td style='padding:8px 0 8px 8px;font-size:13.5px;color:#444;line-height:1.5;'>We review your answers and sketch a quick action plan for your business</td>
          </tr>
          <tr>
            <td width='32' valign='top' style='padding:8px 0;'><span style='display:inline-block;width:24px;height:24px;background:{$accent};color:#000;font-size:11px;font-weight:800;text-align:center;line-height:24px;border-radius:50%;'>2</span></td>
            <td style='padding:8px 0 8px 8px;font-size:13.5px;color:#444;line-height:1.5;'>Someone from our team reaches out within <strong>1 business day</strong></td>
          </tr>
          <tr>
            <td width='32' valign='top' style='padding:8px 0;'><span style='display:inline-block;width:24px;height:24px;background:{$accent};color:#000;font-size:11px;font-weight:800;text-align:center;line-height:24px;border-radius:50%;'>3</span></td>
            <td style='padding:8px 0 8px 8px;font-size:13.5px;color:#444;line-height:1.5;'>We walk you through exactly what we'd build — no fluff, no vague retainer pitch</td>
          </tr>
        </table>
      </td></tr>

      <!-- CTA BUTTON -->
      <tr><td style='padding:28px 0 24px;text-align:center;'>
        <a href='{$cta_esc}' target='_blank' style='display:inline-block;background:{$accent};color:#000;font-weight:700;padding:14px 36px;border-radius:7px;text-decoration:none;font-size:15px;letter-spacing:.2px;'>
          " . esc_html( $cta_txt ) . "
        </a>
      </td></tr>

      <!-- DIVIDER -->
      <tr><td style='padding:0 0 18px;'><hr style='border:none;border-top:1px solid #eee;margin:0;'></td></tr>

      <!-- SIGN OFF -->
      <tr><td>
        <p style='margin:0 0 2px;font-size:14px;color:#555;'>Talk soon,</p>
        <p style='margin:0 0 2px;font-size:14px;font-weight:700;color:#111;'>" . esc_html( $signoff ) . "</p>
        <p style='margin:0;font-size:13px;color:#aaa;font-style:italic;'>Act. Build. Repeat.</p>
      </td></tr>

    </table>
  </td></tr>

  <!-- FOOTER -->
  <tr><td style='background:#0a0a0a;padding:20px 36px;text-align:center;border-radius:0 0 10px 10px;'>
    <p style='margin:0;font-size:11px;color:#555;line-height:1.8;'>
      ideaBoss® &nbsp;|&nbsp; AI Creative Company &nbsp;|&nbsp;
      <a href='https://ideaboss.io' style='color:{$accent};text-decoration:none;'>ideaboss.io</a><br>
      You received this because you submitted a form on our website.
    </p>
  </td></tr>

</table>
</td></tr></table>
</body></html>";

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    if ( $reply_to ) $headers[] = 'Reply-To: ' . $reply_to;

    wp_mail( $data['email'], $subject, $body, $headers );
}

// Keyword-based challenge → service mapping
// NOTE: Uses strpos() instead of str_contains() for PHP 7.x compatibility.
function ideabot_map_challenge( $challenge ) {
    $c = strtolower( $challenge );
    if ( false !== strpos( $c, 'repetit' ) || false !== strpos( $c, 'margin' ) || false !== strpos( $c, 'time' ) )
        return [ 'AI Agents & Automation', 'We build AI agents that handle the repetitive work — so your team can focus on what actually moves the needle.' ];
    if ( false !== strpos( $c, 'visible' ) || false !== strpos( $c, 'online' ) || false !== strpos( $c, 'content' ) )
        return [ 'AI Content & Creative', 'We run automated content and outreach systems that keep you visible without you having to think about it.' ];
    if ( false !== strpos( $c, 'lead' ) || false !== strpos( $c, 'cold' ) || false !== strpos( $c, 'follow' ) )
        return [ 'Email & CRM Automation', 'We install smart sequences that follow up automatically — so no lead ever falls through the cracks again.' ];
    if ( false !== strpos( $c, 'scale' ) || false !== strpos( $c, 'myself' ) || false !== strpos( $c, 'founder' ) )
        return [ 'AI Assistants & Delegation', "We build AI assistants that carry your expertise across the business — so you're not the bottleneck anymore." ];
    if ( false !== strpos( $c, 'strateg' ) || false !== strpos( $c, 'execut' ) || false !== strpos( $c, 'plan' ) )
        return [ 'Custom AI Systems', "We build custom agent systems that turn your strategy into daily action — automatically." ];
    return [ 'AI Solutions & Strategy', 'We build custom AI systems tailored to your specific business situation — strategy, automation, and execution in one.' ];
}

// ================================================================
// WEBHOOK
// ================================================================
function ideabot_fire_webhook( $data ) {
    if ( ideabot_get( 'webhook_enabled', '0' ) !== '1' ) return;
    $url = ideabot_get( 'webhook_url', '' );
    if ( empty( $url ) ) return;
    $headers = [ 'Content-Type' => 'application/json' ];
    $secret  = ideabot_get( 'webhook_secret', '' );
    if ( $secret ) $headers['Authorization'] = 'Bearer ' . $secret;
    wp_remote_post( $url, [
        'method'   => 'POST',
        'headers'  => $headers,
        'body'     => wp_json_encode( array_merge( $data, [ 'source' => 'ideabot', 'site' => get_site_url() ] ) ),
        'timeout'  => 10,
        'blocking' => false,
    ] );
}

// ================================================================
// ADMIN MENU
// ================================================================
add_action( 'admin_menu', 'ideabot_admin_menu' );
function ideabot_admin_menu() {
    add_menu_page(    'ideaBot', 'ideaBot', 'manage_options', 'ideabot',          'ideabot_leads_page',    'dashicons-format-chat', 30 );
    add_submenu_page( 'ideabot', 'Leads',    'All Leads', 'manage_options', 'ideabot',          'ideabot_leads_page'    );
    add_submenu_page( 'ideabot', 'Settings', 'Settings',  'manage_options', 'ideabot-settings', 'ideabot_settings_page' );
}

// ================================================================
// ADMIN — LEADS PAGE  (with View / Resend / Delete actions)
// ================================================================
function ideabot_leads_page() {
    global $wpdb;
    $leads = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ideaboss_leads ORDER BY created_at DESC" );

    if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
        ideabot_export_csv( $leads );
        exit;
    }

    $count  = count( $leads );
    $nonce  = wp_create_nonce( 'ideabot_admin_nonce' );
    $ajax   = admin_url( 'admin-ajax.php' );
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            💡 ideaBot — Leads
            <span style="font-size:12px;font-weight:400;color:#999;background:#f5f5f5;padding:2px 10px;border-radius:20px;">v<?php echo IDEABOT_VERSION; ?></span>
        </h1>
        <p>
            <strong><?php echo $count; ?> lead<?php echo $count !== 1 ? 's' : ''; ?> captured.</strong>
            &nbsp;
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ideabot&export=csv' ) ); ?>" class="button button-secondary">⬇ Export CSV</a>
        </p>

        <style>
        .ib-action-btn { display:inline-flex;align-items:center;gap:4px;padding:4px 10px;font-size:12px;border-radius:4px;border:1px solid #ddd;background:#fff;cursor:pointer;transition:all .15s;font-family:inherit;line-height:1.4; }
        .ib-action-btn:hover { background:#f5f5f5; }
        .ib-action-btn.view { color:#0073aa;border-color:#0073aa20; }
        .ib-action-btn.view:hover { background:#e8f4fb; }
        .ib-action-btn.resend { color:#2e7d32;border-color:#2e7d3220; }
        .ib-action-btn.resend:hover { background:#e8f5e9; }
        .ib-action-btn.notify { color:#e65100;border-color:#e6510020; }
        .ib-action-btn.notify:hover { background:#fff3e0; }
        .ib-action-btn.delete { color:#c62828;border-color:#c6282820; }
        .ib-action-btn.delete:hover { background:#ffebee; }
        .ib-actions { display:flex;gap:4px;flex-wrap:wrap; }
        /* MODAL */
        #ib-modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100000;align-items:center;justify-content:center; }
        #ib-modal-overlay.open { display:flex; }
        #ib-modal-box { background:#fff;border-radius:10px;width:680px;max-width:90vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.35); }
        #ib-modal-header { background:#0a0a0a;padding:18px 24px;border-radius:10px 10px 0 0;display:flex;align-items:center;justify-content:space-between; }
        #ib-modal-header h2 { margin:0;color:#00C2FF;font-size:16px; }
        #ib-modal-close { background:none;border:none;color:#666;font-size:20px;cursor:pointer;padding:0;line-height:1; }
        #ib-modal-close:hover { color:#fff; }
        #ib-modal-body { padding:24px; }
        .ib-modal-field { display:grid;grid-template-columns:160px 1fr;gap:8px 16px;padding:10px 0;border-bottom:1px solid #f0f0f0; }
        .ib-modal-field:last-child { border-bottom:none; }
        .ib-modal-label { font-size:12px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.4px;padding-top:2px; }
        .ib-modal-value { font-size:13.5px;color:#111;line-height:1.5; }
        .ib-modal-value:empty::before { content:'—';color:#ccc; }
        #ib-modal-actions { padding:16px 24px;border-top:1px solid #eee;display:flex;gap:8px;background:#f9f9f9;border-radius:0 0 10px 10px; }
        #ib-toast { position:fixed;bottom:20px;right:20px;background:#111;color:#fff;padding:10px 18px;border-radius:6px;font-size:13px;z-index:200000;display:none;box-shadow:0 4px 20px rgba(0,0,0,.3); }
        </style>

        <!-- LEADS TABLE -->
        <table class="wp-list-table widefat fixed striped" style="font-size:12.5px;">
            <thead>
                <tr>
                    <th style="width:80px;">Date</th>
                    <th style="width:90px;">Name</th>
                    <th style="width:165px;">Email</th>
                    <th style="width:100px;">Industry</th>
                    <th style="width:90px;">Revenue</th>
                    <th style="width:80px;">Team</th>
                    <th>Challenge</th>
                    <th style="width:105px;">Timeline</th>
                    <th style="width:200px;">Actions</th>
                </tr>
            </thead>
            <tbody id="ib-leads-tbody">
                <?php if ( empty( $leads ) ) : ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:#999;">No leads yet — ideaBot is live and ready. 🎯</td></tr>
                <?php else : foreach ( $leads as $l ) :
                    $lead_json = esc_attr( wp_json_encode( [
                        'id'                => (int)   $l->id,
                        'first_name'        => (string) $l->first_name,
                        'email'             => (string) $l->email,
                        'phone'             => (string) $l->phone,
                        'industry'          => (string) $l->industry,
                        'revenue_range'     => (string) $l->revenue_range,
                        'biggest_challenge' => (string) $l->biggest_challenge,
                        'ai_experience'     => (string) $l->ai_experience,
                        'team_size'         => (string) $l->team_size,
                        'timeline'          => (string) $l->timeline,
                        'win_definition'    => (string) $l->win_definition,
                        'created_at'        => (string) $l->created_at,
                    ] ) );
                    ?>
                    <tr id="ib-row-<?php echo (int)$l->id; ?>">
                        <td><?php echo esc_html( date( 'M j, Y', strtotime( $l->created_at ) ) ); ?></td>
                        <td><strong><?php echo esc_html( $l->first_name ); ?></strong></td>
                        <td style="word-break:break-all;"><a href="mailto:<?php echo esc_attr( $l->email ); ?>"><?php echo esc_html( $l->email ); ?></a></td>
                        <td><?php echo esc_html( $l->industry ); ?></td>
                        <td><?php echo esc_html( $l->revenue_range ); ?></td>
                        <td><?php echo esc_html( $l->team_size ?: '—' ); ?></td>
                        <td><?php echo esc_html( $l->biggest_challenge ); ?></td>
                        <td><?php echo esc_html( $l->timeline ); ?></td>
                        <td>
                            <div class="ib-actions">
                                <button class="ib-action-btn view"   data-lead="<?php echo $lead_json; ?>">👁 View</button>
                                <button class="ib-action-btn resend" data-id="<?php echo (int)$l->id; ?>" data-name="<?php echo esc_attr($l->first_name); ?>" data-action="followup">📧 Resend</button>
                                <button class="ib-action-btn notify" data-id="<?php echo (int)$l->id; ?>" data-action="notify">🔔 Notify</button>
                                <button class="ib-action-btn delete" data-id="<?php echo (int)$l->id; ?>" data-name="<?php echo esc_attr($l->first_name); ?>" data-action="delete">🗑</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- MODAL -->
        <div id="ib-modal-overlay">
            <div id="ib-modal-box">
                <div id="ib-modal-header">
                    <h2 id="ib-modal-title">💡 Lead Details</h2>
                    <button id="ib-modal-close">✕</button>
                </div>
                <div id="ib-modal-body"></div>
                <div id="ib-modal-actions">
                    <button id="ib-modal-resend" class="button button-primary">📧 Resend Follow-Up</button>
                    <button id="ib-modal-notify" class="button">🔔 Resend Team Notification</button>
                    <button id="ib-modal-close-btn" class="button" style="margin-left:auto;">Close</button>
                </div>
            </div>
        </div>

        <!-- TOAST -->
        <div id="ib-toast"></div>

        <script>
        (function(){
            var AJAX  = '<?php echo esc_js($ajax); ?>';
            var NONCE = '<?php echo esc_js($nonce); ?>';
            var activeLeadId = null;

            function toast(msg, ok) {
                var t = document.getElementById('ib-toast');
                t.textContent = msg;
                t.style.background = ok === false ? '#c62828' : '#1b5e20';
                t.style.display = 'block';
                setTimeout(function(){ t.style.display='none'; }, 3500);
            }

            function openModal(lead) {
                activeLeadId = lead.id;
                document.getElementById('ib-modal-title').textContent = '💡 ' + lead.first_name + ' — ' + lead.email;
                var fields = [
                    ['Date',               lead.created_at],
                    ['Name',               lead.first_name],
                    ['Email',              lead.email],
                    ['Phone',              lead.phone],
                    ['Industry',           lead.industry],
                    ['Revenue Range',      lead.revenue_range],
                    ['Biggest Challenge',  lead.biggest_challenge],
                    ['AI Experience',      lead.ai_experience],
                    ['Team Size',          lead.team_size],
                    ['Timeline',           lead.timeline],
                    ['90-Day Win',         lead.win_definition],
                ];
                var html = '';
                fields.forEach(function(f){
                    html += '<div class="ib-modal-field">'
                         + '<div class="ib-modal-label">' + f[0] + '</div>'
                         + '<div class="ib-modal-value">' + escHtml(f[1] || '') + '</div>'
                         + '</div>';
                });
                document.getElementById('ib-modal-body').innerHTML = html;
                document.getElementById('ib-modal-overlay').classList.add('open');
            }

            function closeModal() {
                document.getElementById('ib-modal-overlay').classList.remove('open');
                activeLeadId = null;
            }

            function doAjax(action, leadId, onSuccess) {
                var fd = new FormData();
                fd.append('action', 'ideabot_' + action);
                fd.append('nonce', NONCE);
                fd.append('lead_id', leadId);
                fetch(AJAX, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success) { toast(res.data.message, true); if(onSuccess) onSuccess(); }
                        else             { toast((res.data && res.data.message) || 'Something went wrong.', false); }
                    }).catch(function(){ toast('Network error.', false); });
            }

            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            // View buttons
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.ib-action-btn');
                if (!btn) return;

                if (btn.classList.contains('view')) {
                    openModal(JSON.parse(btn.getAttribute('data-lead')));
                }
                else if (btn.classList.contains('resend')) {
                    var id = btn.getAttribute('data-id');
                    var n  = btn.getAttribute('data-name');
                    doAjax('resend_followup', id);
                }
                else if (btn.classList.contains('notify')) {
                    var id = btn.getAttribute('data-id');
                    doAjax('resend_notify', id);
                }
                else if (btn.classList.contains('delete')) {
                    var id   = btn.getAttribute('data-id');
                    var name = btn.getAttribute('data-name');
                    if (!confirm('Delete lead from ' + name + '? This cannot be undone.')) return;
                    doAjax('delete_lead', id, function(){
                        var row = document.getElementById('ib-row-' + id);
                        if (row) row.remove();
                    });
                }
            });

            // Modal close
            document.getElementById('ib-modal-close').addEventListener('click', closeModal);
            document.getElementById('ib-modal-close-btn').addEventListener('click', closeModal);
            document.getElementById('ib-modal-overlay').addEventListener('click', function(e){
                if (e.target === this) closeModal();
            });

            // Modal action buttons
            document.getElementById('ib-modal-resend').addEventListener('click', function(){
                if (activeLeadId) doAjax('resend_followup', activeLeadId);
            });
            document.getElementById('ib-modal-notify').addEventListener('click', function(){
                if (activeLeadId) doAjax('resend_notify', activeLeadId);
            });
        })();
        </script>
    </div>
    <?php
}

// ================================================================
// ADMIN — SETTINGS PAGE (5 tabs, all settings editable)
// ================================================================
function ideabot_settings_page() {

    // ---- SAVE — wp_unslash() is CRITICAL here ----
    if ( isset( $_POST['ideabot_save'] ) ) {
        check_admin_referer( 'ideabot_settings' );

        // Checkboxes (unchecked = absent from $_POST)
        foreach ( [ 'enabled', 'auto_open', 'hide_mobile', 'webhook_enabled' ] as $cb ) {
            update_option( 'ideabot_' . $cb, isset( $_POST[$cb] ) ? '1' : '0' );
        }

        // Text / URL / email / number fields — wp_unslash BEFORE sanitizing
        $text_fields = [
            'accent_color'     => 'color',
            'bubble_label'     => 'text',
            'bubble_pos'       => 'text',
            'widget_title'     => 'text',
            'bot_icon'         => 'text',
            'contact_email'    => 'email',
            'notification_email'=> 'text',   // allow comma-separated
            'cc_email'         => 'text',
            'from_name'        => 'text',
            'from_email'       => 'email',
            'reply_to'         => 'email',
            'notify_subject'   => 'text',
            'followup_subject' => 'text',
            'cta_button_text'  => 'text',
            'cta_url'          => 'url',
            'signoff_name'     => 'text',
            'open_delay'       => 'int',
            'excluded_ids'     => 'text',
            'z_index'          => 'int',
            'webhook_url'      => 'url',
            'webhook_secret'   => 'text',
        ];

        foreach ( $text_fields as $field => $type ) {
            if ( ! isset( $_POST[$field] ) ) continue;
            $raw = wp_unslash( $_POST[$field] );   // <- THE FIX
            switch ( $type ) {
                case 'color': $val = sanitize_hex_color( $raw );   break;
                case 'email': $val = sanitize_email( $raw );        break;
                case 'url':   $val = esc_url_raw( $raw );           break;
                case 'int':   $val = (string) absint( $raw );       break;
                default:      $val = sanitize_text_field( $raw );   break;
            }
            update_option( 'ideabot_' . $field, $val );
        }

        // Textarea fields — HTML-allowed (welcome, success) vs plain-text (choice opts)
        $html_textareas  = [ 'welcome', 'success1', 'success2', 'success_cta' ];
        $plain_textareas = [
            'q_name', 'q_name_ph', 'q_industry', 'q_industry_ph',
            'q_revenue', 'q_revenue_opts', 'q_challenge', 'q_challenge_opts',
            'q_ai_exp', 'q_ai_exp_opts', 'q_team', 'q_team_opts',
            'q_timeline', 'q_timeline_opts', 'q_win', 'q_win_ph',
            'q_email', 'q_phone',
        ];

        foreach ( $html_textareas as $ta ) {
            if ( isset( $_POST[$ta] ) ) {
                update_option( 'ideabot_' . $ta, wp_kses_post( wp_unslash( $_POST[$ta] ) ) );
            }
        }
        foreach ( $plain_textareas as $ta ) {
            if ( isset( $_POST[$ta] ) ) {
                update_option( 'ideabot_' . $ta, sanitize_textarea_field( wp_unslash( $_POST[$ta] ) ) );
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>ideaBot settings saved.</strong></p></div>';
    }

    $d = ideabot_defaults();
    // Helpers — named uniquely to avoid redeclaration errors
    function ideabot_field_val( $key )  { global $d; return esc_attr( ideabot_get( $key, $d[$key] ?? '' ) ); }
    function ideabot_field_chk( $key, $def='0' ) { checked( ideabot_get( $key, $def ), '1' ); }
    function ideabot_field_ta( $key )   { global $d; return esc_textarea( ideabot_get( $key, $d[$key] ?? '' ) ); }
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
            💡 ideaBot — Settings
            <span style="font-size:12px;font-weight:400;color:#999;background:#f5f5f5;padding:2px 10px;border-radius:20px;">v<?php echo IDEABOT_VERSION; ?></span>
        </h1>
        <p style="color:#888;margin-top:4px;font-size:12.5px;">All tabs save together — changes take effect immediately.</p>

        <style>
        .ib-tabs{display:flex;gap:0;border-bottom:2px solid #ddd;flex-wrap:wrap;margin-bottom:0;}
        .ib-tab{background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;padding:10px 18px;font-size:13px;cursor:pointer;color:#666;transition:all .15s;border-radius:5px 5px 0 0;font-family:inherit;}
        .ib-tab:hover{background:#f5f5f5;color:#111;}
        .ib-tab.active{color:#00C2FF;border-bottom-color:#00C2FF;font-weight:700;background:#fff;}
        .ib-panel{display:none;}.ib-panel.active{display:block;}
        .ib-box{background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 8px 8px;overflow:hidden;margin-bottom:20px;}
        .ib-section{padding:18px 24px;border-bottom:1px solid #f2f2f2;}.ib-section:last-child{border-bottom:none;}
        .ib-section h3{margin:0 0 14px;color:#111;font-size:12px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;}
        .ib-row{display:grid;grid-template-columns:190px 1fr;gap:8px 20px;align-items:start;padding:8px 0;border-bottom:1px solid #fafafa;}
        .ib-row:last-child{border-bottom:none;}
        .ib-label{font-size:13px;font-weight:600;color:#333;padding-top:7px;line-height:1.4;}
        .ib-sublabel{font-size:11px;font-weight:400;color:#aaa;display:block;}
        .ib-desc{font-size:11.5px;color:#999;margin-top:3px;line-height:1.5;}
        .ib-row input[type=text],.ib-row input[type=email],.ib-row input[type=url],.ib-row input[type=number],.ib-row textarea,.ib-row select{width:100%;max-width:450px;font-size:13px;padding:7px 10px;border:1px solid #ccc;border-radius:5px;font-family:inherit;transition:border-color .15s;box-sizing:border-box;}
        .ib-row input:focus,.ib-row textarea:focus,.ib-row select:focus{border-color:#00C2FF;outline:none;box-shadow:0 0 0 2px rgba(0,194,255,.1);}
        .ib-row textarea{min-height:72px;resize:vertical;}.ib-row textarea.tall{min-height:110px;}
        .ib-row input[type=color]{width:48px;height:36px;padding:2px;border-radius:5px;cursor:pointer;}
        .ib-row input[type=checkbox]{transform:scale(1.2);cursor:pointer;margin-right:5px;}
        .ib-token{font-size:11px;color:#888;margin-top:4px;}.ib-token code{background:#f0f0f0;padding:1px 5px;border-radius:3px;font-size:11px;}
        .ib-note{background:#fffde7;border:1px solid #ffe082;border-radius:6px;padding:12px 16px;font-size:12.5px;color:#664d00;line-height:1.6;margin-top:10px;}
        .ib-save-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 22px;display:flex;align-items:center;gap:16px;}
        .ib-save-bar .button-primary{font-size:14px;padding:6px 22px;height:auto;}
        </style>

        <form method="post" id="ib-settings-form">
            <?php wp_nonce_field( 'ideabot_settings' ); ?>
            <input type="hidden" name="ideabot_save" value="1">

            <!-- TABS -->
            <div class="ib-tabs">
                <button type="button" class="ib-tab active" data-panel="general">⚙️ General</button>
                <button type="button" class="ib-tab" data-panel="conversation">💬 Conversation</button>
                <button type="button" class="ib-tab" data-panel="emails">✉️ Emails</button>
                <button type="button" class="ib-tab" data-panel="display">👁 Display</button>
                <button type="button" class="ib-tab" data-panel="integrations">🔗 Integrations</button>
            </div>

            <!-- ======================== GENERAL ======================== -->
            <div class="ib-panel active" id="panel-general"><div class="ib-box">
                <div class="ib-section"><h3>🔌 Status</h3>
                    <div class="ib-row">
                        <div class="ib-label">Bot Enabled</div>
                        <div><label><input type="checkbox" name="enabled" value="1" <?php ideabot_field_chk( 'enabled', '1' ); ?>> Show ideaBot on the website</label>
                        <div class="ib-desc">Uncheck to hide the widget globally without losing any settings or data.</div></div>
                    </div>
                </div>
                <div class="ib-section"><h3>🎨 Appearance</h3>
                    <div class="ib-row">
                        <div class="ib-label">Accent Color</div>
                        <div><input type="color" name="accent_color" value="<?php echo ideabot_field_val('accent_color'); ?>">
                        <div class="ib-desc">Used on bubble, buttons, email headers, and highlights.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Bubble Label</div>
                        <div><input type="text" name="bubble_label" value="<?php echo ideabot_field_val('bubble_label'); ?>"></div>
                    </div>
                    <div class="ib-label">Bot Icon</div>
                    <div class="ib-row" style="margin-top:-8px;">
                        <div class="ib-label" style="padding-top:7px;"></div>
                        <div><input type="text" name="bot_icon" value="<?php echo ideabot_field_val('bot_icon'); ?>" style="max-width:80px;">
                        <div class="ib-desc">Emoji shown in the bubble and header.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Widget Title</div>
                        <div><input type="text" name="widget_title" value="<?php echo ideabot_field_val('widget_title'); ?>">
                        <div class="ib-desc">Shown in the chat window header bar.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Bubble Position</div>
                        <div><select name="bubble_pos" style="max-width:160px;">
                            <option value="right" <?php selected( ideabot_get('bubble_pos','right'), 'right' ); ?>>Bottom Right</option>
                            <option value="left"  <?php selected( ideabot_get('bubble_pos','right'), 'left'  ); ?>>Bottom Left</option>
                        </select></div>
                    </div>
                </div>
                <div class="ib-section"><h3>📧 Error Contact</h3>
                    <div class="ib-row">
                        <div class="ib-label">Fallback Email</div>
                        <div><input type="email" name="contact_email" value="<?php echo ideabot_field_val('contact_email'); ?>">
                        <div class="ib-desc">Shown in the chat if a submission fails (e.g. <code>hello@ideaboss.io</code>).</div></div>
                    </div>
                </div>
            </div></div>

            <!-- ======================== CONVERSATION ======================== -->
            <div class="ib-panel" id="panel-conversation"><div class="ib-box">
                <div class="ib-section"><h3>👋 Opening</h3>
                    <div class="ib-row">
                        <div class="ib-label">Welcome Message</div>
                        <div><textarea class="tall" name="welcome"><?php echo ideabot_field_ta('welcome'); ?></textarea>
                        <div class="ib-desc">Supports HTML (bold, em, br). Shown before questions start.</div></div>
                    </div>
                </div>
                <div class="ib-section"><h3>❓ Questions</h3>
                    <div class="ib-row">
                        <div class="ib-label">Q1 — Name<span class="ib-sublabel">Text input</span></div>
                        <div>
                            <input type="text" name="q_name" value="<?php echo ideabot_field_val('q_name'); ?>">
                            <input type="text" name="q_name_ph" value="<?php echo ideabot_field_val('q_name_ph'); ?>" placeholder="Placeholder…" style="margin-top:5px;">
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q2 — Industry<span class="ib-sublabel">Text input</span></div>
                        <div>
                            <input type="text" name="q_industry" value="<?php echo ideabot_field_val('q_industry'); ?>">
                            <div class="ib-token">Use <code>{first_name}</code> to personalise.</div>
                            <input type="text" name="q_industry_ph" value="<?php echo ideabot_field_val('q_industry_ph'); ?>" placeholder="Placeholder…" style="margin-top:5px;">
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q3 — Revenue<span class="ib-sublabel">Choice buttons</span></div>
                        <div>
                            <input type="text" name="q_revenue" value="<?php echo ideabot_field_val('q_revenue'); ?>">
                            <textarea name="q_revenue_opts" style="margin-top:5px;"><?php echo ideabot_field_ta('q_revenue_opts'); ?></textarea>
                            <div class="ib-desc">One option per line.</div>
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q4 — Challenge<span class="ib-sublabel">Choice buttons</span></div>
                        <div>
                            <input type="text" name="q_challenge" value="<?php echo ideabot_field_val('q_challenge'); ?>">
                            <textarea name="q_challenge_opts" class="tall" style="margin-top:5px;"><?php echo ideabot_field_ta('q_challenge_opts'); ?></textarea>
                            <div class="ib-desc">One option per line. Used to personalise the follow-up email.</div>
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q5 — AI Experience<span class="ib-sublabel">Choice buttons</span></div>
                        <div>
                            <input type="text" name="q_ai_exp" value="<?php echo ideabot_field_val('q_ai_exp'); ?>">
                            <textarea name="q_ai_exp_opts" style="margin-top:5px;"><?php echo ideabot_field_ta('q_ai_exp_opts'); ?></textarea>
                            <div class="ib-desc">One option per line.</div>
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q6 — Team Size<span class="ib-sublabel">Choice buttons</span></div>
                        <div>
                            <input type="text" name="q_team" value="<?php echo ideabot_field_val('q_team'); ?>">
                            <textarea name="q_team_opts" style="margin-top:5px;"><?php echo ideabot_field_ta('q_team_opts'); ?></textarea>
                            <div class="ib-desc">One option per line.</div>
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q7 — Timeline<span class="ib-sublabel">Choice buttons</span></div>
                        <div>
                            <input type="text" name="q_timeline" value="<?php echo ideabot_field_val('q_timeline'); ?>">
                            <textarea name="q_timeline_opts" style="margin-top:5px;"><?php echo ideabot_field_ta('q_timeline_opts'); ?></textarea>
                            <div class="ib-desc">One option per line.</div>
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q8 — 90-Day Win<span class="ib-sublabel">Long text input</span></div>
                        <div>
                            <input type="text" name="q_win" value="<?php echo ideabot_field_val('q_win'); ?>">
                            <input type="text" name="q_win_ph" value="<?php echo ideabot_field_val('q_win_ph'); ?>" placeholder="Placeholder…" style="margin-top:5px;">
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q9 — Email<span class="ib-sublabel">Email input</span></div>
                        <div>
                            <input type="text" name="q_email" value="<?php echo ideabot_field_val('q_email'); ?>">
                            <div class="ib-token">Use <code>{first_name}</code> to personalise.</div>
                        </div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Q10 — Phone<span class="ib-sublabel">Optional tel input</span></div>
                        <div><input type="text" name="q_phone" value="<?php echo ideabot_field_val('q_phone'); ?>"></div>
                    </div>
                </div>
                <div class="ib-section"><h3>✅ Completion</h3>
                    <div class="ib-row">
                        <div class="ib-label">Success Line 1</div>
                        <div><input type="text" name="success1" value="<?php echo ideabot_field_val('success1'); ?>">
                        <div class="ib-token">Use <code>{first_name}</code> to personalise.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Success Line 2</div>
                        <div><textarea class="tall" name="success2"><?php echo ideabot_field_ta('success2'); ?></textarea>
                        <div class="ib-desc">Supports HTML (bold, em, br).</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Post-Chat Link Text</div>
                        <div><input type="text" name="success_cta" value="<?php echo ideabot_field_val('success_cta'); ?>"></div>
                    </div>
                </div>
            </div></div>

            <!-- ======================== EMAILS ======================== -->
            <div class="ib-panel" id="panel-emails"><div class="ib-box">
                <div class="ib-section"><h3>📤 Sender</h3>
                    <div class="ib-note">
                        <strong>Using Mailgun or WP Mail SMTP?</strong> Leave <em>From Email</em> blank to let your SMTP plugin control the sender address — or set it to a domain-verified address (e.g. <code>hello@ideaboss.io</code>). The <em>From Name</em> is always applied. The <strong>Reply-To</strong> field is separate and always sent — use it so replies go to your personal inbox.
                    </div>
                    <div class="ib-row" style="margin-top:14px;">
                        <div class="ib-label">From Name</div>
                        <div><input type="text" name="from_name" value="<?php echo ideabot_field_val('from_name'); ?>"></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">From Email</div>
                        <div><input type="email" name="from_email" value="<?php echo ideabot_field_val('from_email'); ?>" placeholder="Leave blank to use Mailgun default">
                        <div class="ib-desc">Must be on an authorized Mailgun sending domain.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Reply-To Email</div>
                        <div><input type="email" name="reply_to" value="<?php echo ideabot_field_val('reply_to'); ?>" placeholder="e.g. dylan@coxgp.com">
                        <div class="ib-desc">Replies from leads go here. Recommended.</div></div>
                    </div>
                </div>
                <div class="ib-section"><h3>🔔 Lead Notifications</h3>
                    <div class="ib-row">
                        <div class="ib-label">Notify Email(s)</div>
                        <div><input type="text" name="notification_email" value="<?php echo ideabot_field_val('notification_email'); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        <div class="ib-desc">Comma-separated for multiple (e.g. <code>you@co.com, team@co.com</code>).</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">CC Email(s)</div>
                        <div><input type="text" name="cc_email" value="<?php echo ideabot_field_val('cc_email'); ?>" placeholder="Optional"></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Notification Subject</div>
                        <div><input type="text" name="notify_subject" value="<?php echo ideabot_field_val('notify_subject'); ?>">
                        <div class="ib-token">Tokens: <code>{first_name}</code> <code>{industry}</code></div></div>
                    </div>
                </div>
                <div class="ib-section"><h3>📩 Visitor Follow-Up</h3>
                    <div class="ib-row">
                        <div class="ib-label">Subject Line</div>
                        <div><input type="text" name="followup_subject" value="<?php echo ideabot_field_val('followup_subject'); ?>">
                        <div class="ib-token">Tokens: <code>{first_name}</code> <code>{industry}</code></div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">CTA Button Text</div>
                        <div><input type="text" name="cta_button_text" value="<?php echo ideabot_field_val('cta_button_text'); ?>"></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">CTA Button URL</div>
                        <div><input type="url" name="cta_url" value="<?php echo ideabot_field_val('cta_url'); ?>" placeholder="https://ideaboss.io">
                        <div class="ib-desc">Your Calendly booking page, a landing page, or ideaboss.io.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Sign-Off Name</div>
                        <div><input type="text" name="signoff_name" value="<?php echo ideabot_field_val('signoff_name'); ?>">
                        <div class="ib-desc">Appears as "Talk soon, [name]" in the email.</div></div>
                    </div>
                </div>
            </div></div>

            <!-- ======================== DISPLAY ======================== -->
            <div class="ib-panel" id="panel-display"><div class="ib-box">
                <div class="ib-section"><h3>⏱ Timing</h3>
                    <div class="ib-row">
                        <div class="ib-label">Open Delay</div>
                        <div><input type="number" name="open_delay" value="<?php echo ideabot_field_val('open_delay'); ?>" min="0" max="60" style="max-width:80px;"> seconds
                        <div class="ib-desc">Seconds after page load before the bubble appears. 0 = instant.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Auto-Open</div>
                        <div><label><input type="checkbox" name="auto_open" value="1" <?php ideabot_field_chk('auto_open'); ?>> Automatically open the chat window on page load</label>
                        <div class="ib-desc">Opens after the delay above. Best used on dedicated landing pages.</div></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Hide on Mobile</div>
                        <div><label><input type="checkbox" name="hide_mobile" value="1" <?php ideabot_field_chk('hide_mobile'); ?>> Hide ideaBot on mobile / tablet devices</label></div>
                    </div>
                </div>
                <div class="ib-section"><h3>📄 Page Rules</h3>
                    <div class="ib-row">
                        <div class="ib-label">Exclude Page IDs</div>
                        <div><input type="text" name="excluded_ids" value="<?php echo ideabot_field_val('excluded_ids'); ?>" placeholder="e.g. 12, 45, 89">
                        <div class="ib-desc">Comma-separated WordPress page/post IDs where the bot should NOT appear.</div></div>
                    </div>
                </div>
                <div class="ib-section"><h3>🔧 Advanced</h3>
                    <div class="ib-row">
                        <div class="ib-label">Z-Index</div>
                        <div><input type="number" name="z_index" value="<?php echo ideabot_field_val('z_index'); ?>" min="100" style="max-width:110px;">
                        <div class="ib-desc">Increase if the widget appears behind headers, sliders, or other elements.</div></div>
                    </div>
                </div>
            </div></div>

            <!-- ======================== INTEGRATIONS ======================== -->
            <div class="ib-panel" id="panel-integrations"><div class="ib-box">
                <div class="ib-section"><h3>🔗 Webhook</h3>
                    <div class="ib-row">
                        <div class="ib-label">Enable Webhook</div>
                        <div><label><input type="checkbox" name="webhook_enabled" value="1" <?php ideabot_field_chk('webhook_enabled'); ?>> Fire a POST request on every new lead submission</label></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Webhook URL</div>
                        <div><input type="url" name="webhook_url" value="<?php echo ideabot_field_val('webhook_url'); ?>" placeholder="https://hooks.zapier.com/hooks/catch/…"></div>
                    </div>
                    <div class="ib-row">
                        <div class="ib-label">Bearer Token</div>
                        <div><input type="text" name="webhook_secret" value="<?php echo ideabot_field_val('webhook_secret'); ?>" placeholder="Optional — sent as Authorization: Bearer …"></div>
                    </div>
                    <div class="ib-note">
                        <strong>Payload fields (JSON):</strong> <code>first_name, industry, revenue_range, biggest_challenge, ai_experience, team_size, timeline, win_definition, email, phone, source, site</code><br><br>
                        Works with <strong>Zapier</strong>, <strong>Make</strong>, <strong>GoHighLevel</strong>, <strong>n8n</strong>, or any webhook-compatible service.
                    </div>
                </div>
            </div></div>

            <!-- SAVE BAR -->
            <div class="ib-save-bar">
                <button type="submit" class="button button-primary">💾 Save All Settings</button>
                <span style="color:#aaa;font-size:12px;">ideaBot v<?php echo IDEABOT_VERSION; ?> by <a href="https://ideaboss.io" target="_blank" style="color:#00C2FF;">ideaBoss</a></span>
            </div>

        </form>
    </div>

    <script>
    (function(){
        var tabs   = document.querySelectorAll('.ib-tab');
        var panels = document.querySelectorAll('.ib-panel');
        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                tabs.forEach(function(t){ t.classList.remove('active'); });
                panels.forEach(function(p){ p.classList.remove('active'); });
                this.classList.add('active');
                var el = document.getElementById('panel-' + this.dataset.panel);
                if (el) el.classList.add('active');
            });
        });
    })();
    </script>
    <?php
}

// ================================================================
// CSV EXPORT
// ================================================================
function ideabot_export_csv( $leads ) {
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="ideabot-leads-' . date( 'Y-m-d' ) . '.csv"' );
    $out = fopen( 'php://output', 'w' );
    fputs( $out, "\xEF\xBB\xBF" );
    fputcsv( $out, [ 'Date', 'First Name', 'Email', 'Phone', 'Industry', 'Revenue Range', 'Biggest Challenge', 'AI Experience', 'Team Size', 'Timeline', '90-Day Win' ] );
    foreach ( $leads as $l ) {
        fputcsv( $out, [ $l->created_at, $l->first_name, $l->email, $l->phone, $l->industry, $l->revenue_range, $l->biggest_challenge, $l->ai_experience, $l->team_size ?? '', $l->timeline, $l->win_definition ] );
    }
    fclose( $out );
}
