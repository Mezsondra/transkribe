<?php
/**
 * Plugin Name: Transcribe AI
 * Description: Securely upload, transcribe, and manage audio files using AI.
 * Version: 18.0-FIXED-ALL-ISSUES
 * Author: Halil
 * Text Domain: transcribe-ai
 */

if (!defined('ABSPATH')) exit;

// ==========================================
// CONSTANTS
// ==========================================
define('TRANSCRIBE_AI_VERSION', '18.0-FIXED');
define('TRANSCRIBE_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TRANSCRIBE_AI_PLUGIN_URL', plugin_dir_url(__FILE__));

// FIX #22: Named constants instead of magic numbers
define('TRANSCRIBE_AI_POLL_INTERVAL', 5000); // milliseconds
define('TRANSCRIBE_AI_MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB
define('TRANSCRIBE_AI_CHUNK_LIMIT', 4000); // DeepL character limit
define('TRANSCRIBE_AI_AUTOSAVE_INTERVAL', 120000); // 2 minutes

// ==========================================
// ACTIVATION & DEACTIVATION
// ==========================================
register_activation_hook(__FILE__, 'transcribe_ai_activate');
register_deactivation_hook(__FILE__, 'transcribe_ai_deactivate');
function transcribe_ai_activate() {
    Transcribe_AI_Post_Types::register();
    flush_rewrite_rules();
    
    // Create secure upload directory
    $upload_dir = wp_upload_dir();
    $secure_dir = $upload_dir['basedir'] . '/transcribe-ai-secure/';
    $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
    
    if (!file_exists($secure_dir)) {
        wp_mkdir_p($secure_dir);
        $htaccess_content = "Order Deny,Allow\nDeny from all";
        file_put_contents($secure_dir . '.htaccess', $htaccess_content);
    }
    
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }
    
    // Create database tables
    global $wpdb;
    $table_name = $wpdb->prefix . 'transcribe_ai_guest_usage';
    $highlights_table = $wpdb->prefix . 'transcribe_ai_highlights';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // FIX #15: Added index on last_updated for better cleanup performance
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        guest_id varchar(64) NOT NULL,
        minutes_used int(11) NOT NULL DEFAULT 0,
        month_year varchar(7) NOT NULL,
        last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY guest_month (guest_id, month_year),
        INDEX month_year_idx (month_year),
        INDEX last_updated_idx (last_updated)
    ) $charset_collate;";
    
    $sql2 = "CREATE TABLE IF NOT EXISTS $highlights_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        transcript_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL DEFAULT 0,
        guest_id varchar(64) DEFAULT NULL,
        highlight_text text NOT NULL,
        start_time float DEFAULT NULL,
        end_time float DEFAULT NULL,
        color varchar(7) DEFAULT '#ffeb3b',
        note text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY transcript_id (transcript_id),
        KEY user_id (user_id),
        KEY guest_id (guest_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($sql2);
}

function transcribe_ai_deactivate() {
    flush_rewrite_rules();
    
    // Clean up old temp files on deactivation
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
    
    if (is_dir($temp_dir)) {
        $files = glob($temp_dir . '*');
        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 3600) {
                @unlink($file);
            }
        }
    }
}

// ==========================================
// CUSTOM POST TYPE
// ==========================================
class Transcribe_AI_Post_Types {
    public static function register() {
        register_post_type('transcript', [
            'labels' => [
                'name' => __('Transcripts', 'transcribe-ai'),
                'singular_name' => __('Transcript', 'transcribe-ai'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_icon' => 'dashicons-media-audio',
            'supports' => ['title', 'author'],
            'show_in_rest' => false,
        ]);
    }
}
add_action('init', ['Transcribe_AI_Post_Types', 'register']);

// ==========================================
// MAIN PLUGIN CLASS
// ==========================================
class Transcribe_AI {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('init', ['Transcribe_AI_Shortcodes', 'register']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('theme_page_templates', [$this, 'register_page_template']);
        add_filter('template_include', [$this, 'use_page_template']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_audio_stream']);
        add_action('template_redirect', [$this, 'handle_file_download']);
        add_action('transcribe_ai_cleanup_temp_file', [$this, 'cleanup_temp_file']);
        
        Transcribe_AI_Ajax::init();
        Transcribe_AI_Settings::init();
    }

    public function enqueue_scripts() {
        global $post;
        
        if (!is_a($post, 'WP_Post')) return;
        
        $is_uploader = has_shortcode($post->post_content, 'transcribe_ai_uploader');
        $is_list = has_shortcode($post->post_content, 'my_transcripts_list');
		$is_viewer = has_shortcode($post->post_content, 'transcribe_ai_viewer');        
        if ($is_uploader || $is_viewer || $is_list) {
            wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', [], null);
            wp_enqueue_style('material-symbols', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0', [], null);
            wp_enqueue_style('transcribe-ai', TRANSCRIBE_AI_PLUGIN_URL . 'assets/transcribe-ai.css', [], TRANSCRIBE_AI_VERSION);
            wp_enqueue_script('jquery'); 
        }
        
        if ($is_uploader) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('transcribe-ai-uploader', 
                TRANSCRIBE_AI_PLUGIN_URL . 'assets/transcribe-ai.js', 
                ['jquery'],
                TRANSCRIBE_AI_VERSION,
                true
            );
            
            wp_localize_script('transcribe-ai-uploader', 'transcribeAI', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('transcribe_ai_nonce'),
                'user_data' => Transcribe_AI_Helpers::get_user_data(),
                'languages' => Transcribe_AI_Helpers::get_supported_languages(),
                'max_file_size' => TRANSCRIBE_AI_MAX_FILE_SIZE,
                'poll_interval' => TRANSCRIBE_AI_POLL_INTERVAL,
                'strings' => [
                    'uploading' => __('Uploading...', 'transcribe-ai'),
                    'processing' => __('Processing...', 'transcribe-ai'),
                    'complete' => __('Complete!', 'transcribe-ai'),
                    'error' => __('Error', 'transcribe-ai'),
                ]
            ]);
        }
        
        if ($is_viewer) {
            wp_enqueue_script('transcribe-ai-viewer', 
                TRANSCRIBE_AI_PLUGIN_URL . 'assets/transcribe-ai-viewer.js', 
                ['jquery'], 
                TRANSCRIBE_AI_VERSION, 
                true
            );
            
            $transcript_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
            
            wp_localize_script('transcribe-ai-viewer', 'transcriptViewer', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('transcribe_ai_nonce'),
                'transcript_id' => $transcript_id,
                'audio_url' => $transcript_id ? $this->get_audio_stream_url($transcript_id) : '',
                'deepl_languages' => Transcribe_AI_Helpers::get_deepl_languages(),
                'deepl_enabled' => !empty(Transcribe_AI_Helpers::get_api_key('deepl')),
                'openai_enabled' => !empty(Transcribe_AI_Helpers::get_api_key('openai')),
                'autosave_interval' => TRANSCRIBE_AI_AUTOSAVE_INTERVAL,
                'strings' => [
                    'loading' => __('Loading...', 'transcribe-ai'),
                    'error' => __('Error', 'transcribe-ai'),
                    'saved' => __('Saved successfully!', 'transcribe-ai'),
                    'save_failed' => __('Save failed', 'transcribe-ai'),
                ]
            ]);
        }
    }

    private function get_audio_stream_url($transcript_id) {
        return add_query_arg([
            'transcribe_audio' => 1,
            'tid' => $transcript_id,
            'nonce' => wp_create_nonce('audio_stream_' . $transcript_id)
        ], home_url('/'));
    }

 public function register_page_template($templates) {
        // $templates['page-transcript-viewer.php'] = __('Transcript Viewer', 'transcribe-ai');
        return $templates;
    }
    // Change it to this (by adding '//' to comment out the code):
    public function use_page_template($template) {
        // if (is_page() && get_page_template_slug() === 'page-transcript-viewer.php') {
        //     return TRANSCRIBE_AI_PLUGIN_DIR . 'templates/page-transcript-viewer.php';
        // }
        return $template;
    }
    public function add_query_vars($vars) {
        $vars[] = 'transcribe_audio';
        $vars[] = 'tid';
        $vars[] = 'transcribe_download';
        return $vars;
    }

    public function handle_audio_stream() {
        if (!get_query_var('transcribe_audio')) return;
        
        $transcript_id = absint(get_query_var('tid'));
        $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
        
        if (!wp_verify_nonce($nonce, 'audio_stream_' . $transcript_id)) {
            wp_die('Invalid request', 'Error', ['response' => 403]);
        }
        
        $post = get_post($transcript_id);
        if (!$post || $post->post_type !== 'transcript') {
            wp_die('Invalid transcript', 'Error', ['response' => 404]);
        }
        
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_die('Permission denied', 'Error', ['response' => 403]);
        }
        
        $audio_path = get_post_meta($transcript_id, '_secure_audio_path', true);
        if (!$audio_path || !file_exists($audio_path)) {
            wp_die('Audio file not found', 'Error', ['response' => 404]);
        }
        
        $this->stream_audio_file($audio_path);
        exit;
    }

    // FIX #6: Improved audio streaming with better connection handling
    private function stream_audio_file($file_path) {
        $file_size = filesize($file_path);
        $mime_type = mime_content_type($file_path);
        
        $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
        
        if ($range) {
            list($size_unit, $range_orig) = explode('=', $range, 2);
            if ($size_unit == 'bytes') {
                list($range) = explode(',', $range_orig, 2);
                list($seek_start, $seek_end) = explode('-', $range, 2);
                
                $seek_start = max(0, intval($seek_start));
                $seek_end = ($seek_end == '') ? ($file_size - 1) : min(abs(intval($seek_end)), ($file_size - 1));
                
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $seek_start . '-' . $seek_end . '/' . $file_size);
                header('Content-Length: ' . ($seek_end - $seek_start + 1));
            }
        } else {
            header('Content-Length: ' . $file_size);
            $seek_start = 0;
            $seek_end = $file_size - 1;
        }
        
        header('Content-Type: ' . $mime_type);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        
        $file = @fopen($file_path, 'rb');
        if ($file) {
            fseek($file, $seek_start);
            
            $buffer_size = 8192;
            $bytes_sent = 0;
            $bytes_to_send = $seek_end - $seek_start + 1;
            
            while (!feof($file) && $bytes_sent < $bytes_to_send) {
                // FIX #6: Check connection status more frequently
                if (connection_status() != CONNECTION_NORMAL) {
                    @fclose($file);
                    exit;
                }
                
                $chunk_size = min($buffer_size, $bytes_to_send - $bytes_sent);
                $chunk = @fread($file, $chunk_size);
                
                if ($chunk === false) {
                    break;
                }
                
                echo $chunk;
                $bytes_sent += strlen($chunk);
                
                @ob_flush();
                flush();
            }
            
            @fclose($file);
        }
        exit;
    }
    
    public function handle_file_download() {
        if (!isset($_GET['transcribe_download'])) return;
        
        $file = isset($_GET['file']) ? basename(sanitize_file_name($_GET['file'])) : '';
        $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
        
        if (empty($file) || !wp_verify_nonce($nonce, 'download_' . $file)) {
            wp_die('Invalid download link');
        }
        
        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/transcribe-ai-temp/' . $file;
        
        if (!file_exists($filepath)) {
            wp_die('File not found');
        }
        
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $content_types = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pdf' => 'application/pdf',
            'html' => 'text/html'
        ];
        
        $content_type = isset($content_types[$extension]) ? $content_types[$extension] : 'application/octet-stream';

        $display_name = '';
        if (isset($_GET['display'])) {
            $encoded = sanitize_text_field(wp_unslash($_GET['display']));
            $decoded = base64_decode(rawurldecode($encoded));
            if (is_string($decoded) && $decoded !== '') {
                $display_name = self::sanitize_download_filename($decoded, $extension);
            }
        }

        if ($display_name === '') {
            $display_name = self::sanitize_download_filename($file, $extension);
        }

        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $display_name . '"; filename*=UTF-8\'\'' . rawurlencode($display_name));
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($filepath);
        
        // Schedule cleanup instead of immediate delete
        wp_schedule_single_event(time() + 60, 'transcribe_ai_cleanup_temp_file', [$filepath]);
        exit;
    }
    
    // FIX #17: Improved temp file cleanup with timeout handling
    public function cleanup_temp_file($filepath) {
        if (!file_exists($filepath)) {
            return;
        }
        
        $lockfile = $filepath . '.lock';
        $lock_handle = @fopen($lockfile, 'w');
        
        if (!$lock_handle) {
            error_log('Transcribe AI: Could not create lock file for cleanup: ' . $filepath);
            return;
        }
        
        // FIX #17: Use non-blocking lock with timeout
        $timeout = time() + 30; // 30 second timeout
        $locked = false;
        
        while (time() < $timeout) {
            if (flock($lock_handle, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(100000); // Wait 100ms before retry
        }
        
        if ($locked) {
            try {
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
                flock($lock_handle, LOCK_UN);
            } catch (Exception $e) {
                error_log('Transcribe AI: Cleanup error: ' . $e->getMessage());
            }
        } else {
            error_log('Transcribe AI: Could not acquire lock for cleanup (timeout): ' . $filepath);
        }
        
        @fclose($lock_handle);
        
        // Clean up lock file
        if (file_exists($lockfile)) {
            @unlink($lockfile);
        }
    }
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================
class Transcribe_AI_Helpers {
    
    public static function get_user_data() {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $user_id = $user->ID;
            $roles = $user->roles;
            
            $is_premium = in_array('administrator', $roles) || in_array('premium_user', $roles);
            
            if ($is_premium) {
                return [
                    'is_logged_in' => true,
                    'role' => 'premium',
                    'minutes_remaining' => 'unlimited',
                    'plan_name' => 'Premium',
                    'can_save' => true,
                    'user_id' => $user_id,
                    'display_name' => $user->display_name
                ];
            }
            
            $usage_key = 'transcribe_ai_usage_' . date('Y_m');
            $used_minutes = intval(get_user_meta($user_id, $usage_key, true));
            $monthly_limit = 120;
            
            return [
                'is_logged_in' => true,
                'role' => 'basic',
                'minutes_remaining' => max(0, $monthly_limit - $used_minutes),
                'plan_name' => 'Basic',
                'can_save' => true,
                'user_id' => $user_id,
                'display_name' => $user->display_name
            ];
        } else {
            $guest_id = self::get_guest_id();
            $used_minutes = self::get_guest_usage($guest_id);
            $monthly_limit = 20;
            
            return [
                'is_logged_in' => false,
                'role' => 'guest',
                'minutes_remaining' => max(0, $monthly_limit - $used_minutes),
                'plan_name' => 'Guest',
                'can_save' => false,
                'guest_id' => $guest_id,
                'display_name' => 'Guest User'
            ];
        }
    }
    
    // FIX #16: Improved cookie security
    public static function get_guest_id() {
        if (isset($_COOKIE['transcribe_ai_guest_id'])) {
            return sanitize_text_field($_COOKIE['transcribe_ai_guest_id']);
        }
        
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $timestamp = time();
        $random = wp_generate_password(16, false); // Longer random string
        
        $guest_id = hash('sha256', $ip . $user_agent . $timestamp . $random);
        
        // FIX #16: Always use secure flag in production, detect HTTPS properly
        $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        setcookie('transcribe_ai_guest_id', $guest_id, [
            'expires' => time() + (30 * DAY_IN_SECONDS),
            'path' => '/',
            'domain' => '', // Let browser set domain
            'secure' => $is_https, // Fixed detection
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        return $guest_id;
    }
    
    public static function get_guest_usage($guest_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'transcribe_ai_guest_usage';
        $month_year = date('Y-m');
        
        $usage = $wpdb->get_var($wpdb->prepare(
            "SELECT minutes_used FROM $table_name WHERE guest_id = %s AND month_year = %s",
            $guest_id,
            $month_year
        ));
        
        return intval($usage);
    }
    
    public static function update_guest_usage($guest_id, $minutes) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'transcribe_ai_guest_usage';
        $month_year = date('Y-m');
        
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_name (guest_id, month_year, minutes_used) 
             VALUES (%s, %s, %d) 
             ON DUPLICATE KEY UPDATE minutes_used = minutes_used + %d",
            $guest_id,
            $month_year,
            $minutes,
            $minutes
        ));
    }
    
    public static function update_usage($user_id, $minutes) {
        $usage_key = 'transcribe_ai_usage_' . date('Y_m');
        $current_usage = intval(get_user_meta($user_id, $usage_key, true));
        update_user_meta($user_id, $usage_key, $current_usage + $minutes);
    }
    
    public static function user_can_access_transcript($transcript_id) {
        $post = get_post($transcript_id);
        if (!$post || $post->post_type !== 'transcript') {
            return false;
        }
        
        $guest_id = get_post_meta($transcript_id, '_guest_id', true);
        if ($guest_id) {
            return $guest_id === self::get_guest_id();
        }
        
        if (is_user_logged_in()) {
            return $post->post_author == get_current_user_id() || current_user_can('edit_others_posts');
        }
        
        return false;
    }
    
    public static function get_api_key($service) {
        return get_option("transcribe_ai_{$service}_key", '');
    }
    
    public static function format_duration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }
        return sprintf('%d:%02d', $minutes, $seconds);
    }
    
    public static function get_supported_languages() {
        return [
            "en" => "English (Default)", "af" => "Afrikaans", "ar" => "Arabic", "hy" => "Armenian", 
            "az" => "Azerbaijani", "be" => "Belarusian", "bs" => "Bosnian", "bg" => "Bulgarian", 
            "ca" => "Catalan", "zh" => "Chinese", "hr" => "Croatian", "cs" => "Czech", 
            "da" => "Danish", "nl" => "Dutch", "et" => "Estonian", "fi" => "Finnish", 
            "fr" => "French", "gl" => "Galician", "de" => "German", "el" => "Greek", 
            "he" => "Hebrew", "hi" => "Hindi", "hu" => "Hungarian", "is" => "Icelandic", 
            "id" => "Indonesian", "it" => "Italian", "ja" => "Japanese", "kn" => "Kannada", 
            "kk" => "Kazakh", "ko" => "Korean", "lv" => "Latvian", "lt" => "Lithuanian", 
            "mk" => "Macedonian", "ms" => "Malay", "mr" => "Marathi", "mi" => "Maori", 
            "ne" => "Nepali", "no" => "Norwegian", "fa" => "Persian", "pl" => "Polish", 
            "pt" => "Portuguese", "ro" => "Romanian", "ru" => "Russian", "sr" => "Serbian", 
            "sk" => "Slovak", "sl" => "Slovenian", "es" => "Spanish", "sw" => "Swahili", 
            "sv" => "Swedish", "tl" => "Tagalog", "ta" => "Tamil", "th" => "Thai", 
            "tr" => "Turkish", "uk" => "Ukrainian", "ur" => "Urdu", "vi" => "Vietnamese", 
            "cy" => "Welsh"
        ];
    }

    // FIX #21: Fixed character encoding
    public static function get_deepl_languages() {
        return [
            'AR' => 'Arabic', 'BG' => 'Bulgarian', 'ZH' => 'Chinese (Simplified)',
            'CS' => 'Czech', 'DA' => 'Danish', 'NL' => 'Dutch',
            'EN-US' => 'English (American)', 'EN-GB' => 'English (British)',
            'ET' => 'Estonian', 'FI' => 'Finnish', 'FR' => 'French',
            'DE' => 'German', 'EL' => 'Greek', 'HU' => 'Hungarian',
            'ID' => 'Indonesian', 'IT' => 'Italian', 'JA' => 'Japanese',
            'KO' => 'Korean', 'LV' => 'Latvian', 'LT' => 'Lithuanian',
            'NB' => 'Norwegian (Bokmal)', // Fixed encoding
            'PL' => 'Polish',
            'PT-BR' => 'Portuguese (Brazilian)', 'PT-PT' => 'Portuguese (European)',
            'RO' => 'Romanian', 'RU' => 'Russian', 'SK' => 'Slovak',
            'SL' => 'Slovenian', 'ES' => 'Spanish', 'SV' => 'Swedish',
            'TR' => 'Turkish', 'UK' => 'Ukrainian'
        ];
    }
}


// ==========================================
// SHORTCODES
// ==========================================
class Transcribe_AI_Shortcodes {
    
    public static function register() {
        add_shortcode('transcribe_ai_uploader', [self::class, 'render_uploader']);
        add_shortcode('my_transcripts_list', [self::class, 'render_list']);
        add_shortcode('transcribe_ai_viewer', [self::class, 'render_viewer']); // <-- ADD THIS LINE
    }
    // In transcribe-ai.php, add this new function inside the Transcribe_AI_Shortcodes class

    public static function render_viewer($atts) {
        ob_start();
        // This will include the viewer's HTML, which we will edit in Part 2
        include TRANSCRIBE_AI_PLUGIN_DIR . 'templates/page-transcript-viewer.php';
        return ob_get_clean();
    }
    
    public static function render_uploader($atts) {
        ob_start();
        include TRANSCRIBE_AI_PLUGIN_DIR . 'templates/uploader.php';
        return ob_get_clean();
    }
    
   public static function render_list($atts) {
        if (!is_user_logged_in()) {
            return '<div class="transcribe-ai-notice">Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view your saved transcripts.</div>';
        }
        
        $user_id = get_current_user_id();

        // --- START: NEW PAGINATION FIX ---

        // 1. Get ALL transcripts (for stats and empty check)
        // This is the variable the template was missing.
        $all_transcripts_args = [
            'post_type' => 'transcript',
            'author' => $user_id,
            'posts_per_page' => -1, // Get all posts
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => ['publish', 'private', 'draft']
        ];
        $transcripts = get_posts($all_transcripts_args); // This defines $transcripts

        // 2. Get the PAGINATED query (for the table and page links)
        $current_page = get_query_var('paged') ? get_query_var('paged') : 1;
        $per_page = 10; // Show 10 transcripts per page

        $paged_args = [
            'post_type' => 'transcript',
            'author' => $user_id,
            'posts_per_page' => $per_page,
            'paged' => $current_page,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => ['publish', 'private', 'draft']
        ];
        
        // This defines $transcript_query for the pagination controls
        $transcript_query = new WP_Query($paged_args); 
        
        // --- END: NEW PAGINATION FIX ---
        
        ob_start();
        // The template will now have access to BOTH $transcripts and $transcript_query
        include TRANSCRIBE_AI_PLUGIN_DIR . 'templates/transcript-list.php'; 
        return ob_get_clean();
    }
        }

// ==========================================
// AJAX HANDLERS  
// ==========================================
class Transcribe_AI_Ajax {
    
    // FIX #2: Add save operation lock tracking
    private static $save_locks = [];
    
    public static function init() {
        $actions = [
            'start_transcription', 'check_transcription', 'get_transcript_data',
            'save_transcript', 'save_speaker_map', 'delete_transcript', 'export_transcript',
            'translate_transcript', 'update_transcript_title', 'generate_summary',
            'save_highlight', 'delete_highlight', 'get_highlights'
        ];
        
        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [self::class, $action]);
            if (in_array($action, ['start_transcription', 'check_transcription', 'get_transcript_data', 'export_transcript', 'translate_transcript', 'generate_summary', 'save_highlight', 'delete_highlight', 'get_highlights'])) {
                add_action("wp_ajax_nopriv_{$action}", [self::class, $action]);
            }
        }
    }
    
    // FIX #1: Enhanced file validation with magic number checking
    private static function validate_audio_file($file) {
        // Size check
        if ($file['size'] > TRANSCRIBE_AI_MAX_FILE_SIZE) {
            return ['valid' => false, 'error' => 'File size exceeds 500MB limit'];
        }
        
        // MIME type validation with finfo
        if (!function_exists('finfo_open')) {
            return ['valid' => false, 'error' => 'File type validation not available on this server'];
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actual_mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mime_types = [
            'audio/mpeg', 'audio/wav', 'audio/mp4', 'video/mp4',
            'audio/ogg', 'video/webm', 'audio/aac', 'audio/flac', 'audio/x-m4a'
        ];
        
        if (!in_array($actual_mime_type, $allowed_mime_types)) {
            return ['valid' => false, 'error' => 'Invalid file type: ' . $actual_mime_type];
        }
        
        // FIX #1: Magic number validation to prevent polyglot files
        $handle = fopen($file['tmp_name'], 'rb');
        $header = fread($handle, 12);
        fclose($handle);
        
        $magic_numbers = [
            'mp3' => ["\xFF\xFB", "\xFF\xF3", "\xFF\xF2", "ID3"],
            'wav' => ["RIFF"],
            'mp4' => ["ftyp", "\x00\x00\x00"],
            'ogg' => ["OggS"],
            'flac' => ["fLaC"],
            'webm' => ["\x1A\x45\xDF\xA3"]
        ];
        
        $is_valid_magic = false;
        foreach ($magic_numbers as $type => $signatures) {
            foreach ($signatures as $sig) {
                if (strpos($header, $sig) !== false) {
                    $is_valid_magic = true;
                    break 2;
                }
            }
        }
        
        if (!$is_valid_magic) {
            return ['valid' => false, 'error' => 'File appears to be corrupted or is not a valid audio file'];
        }
        
        // Extension validation
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime_to_extension = [
            'audio/mpeg' => ['mp3'],
            'audio/wav' => ['wav'],
            'audio/mp4' => ['m4a', 'mp4'],
            'audio/x-m4a' => ['m4a', 'mp4'],
            'video/mp4' => ['mp4'],
            'audio/ogg' => ['ogg'],
            'video/webm' => ['webm'],
            'audio/aac' => ['aac'],
            'audio/flac' => ['flac']
        ];
        
        $valid_extension = false;
        if (isset($mime_to_extension[$actual_mime_type])) {
            $valid_extension = in_array($file_extension, $mime_to_extension[$actual_mime_type]);
        }
        
        if (!$valid_extension) {
            return ['valid' => false, 'error' => 'File extension does not match file content'];
        }
        
        return ['valid' => true];
    }
    
    public static function start_transcription() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $user_data = Transcribe_AI_Helpers::get_user_data();
        
        if ($user_data['minutes_remaining'] !== 'unlimited' && $user_data['minutes_remaining'] <= 0) {
            wp_send_json_error($user_data['is_logged_in'] ? 'Monthly transcription limit reached.' : 'Monthly guest limit reached.');
        }
        
        if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed');
        }
       
        $file = $_FILES['audio_file'];
        
        // FIX #1: Use enhanced validation
        $validation = self::validate_audio_file($file);
        if (!$validation['valid']) {
            wp_send_json_error($validation['error']);
        }
        
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : 'en';
        if (!array_key_exists($language, Transcribe_AI_Helpers::get_supported_languages())) {
            $language = 'en';
        }

        $api_key = Transcribe_AI_Helpers::get_api_key('assemblyai');
        if (empty($api_key)) {
            wp_send_json_error('Transcription service not configured.');
        }
        
        try {
            $upload_response = self::upload_to_assemblyai($file['tmp_name'], $api_key);
            if (!$upload_response['success']) {
                wp_send_json_error($upload_response['error']);
            }
            
            $transcription_response = self::start_assemblyai_transcription($upload_response['upload_url'], $api_key, $language);
            if (!$transcription_response['success']) {
                wp_send_json_error($transcription_response['error']);
            }
            
            $job_id = $transcription_response['id'];
            $temp_data = [
                'job_id' => $job_id,
                'user_id' => $user_data['is_logged_in'] ? $user_data['user_id'] : null,
                'guest_id' => !$user_data['is_logged_in'] ? $user_data['guest_id'] : null,
                'filename' => sanitize_file_name($file['name']),
                'language' => $language,
            ];
            
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
            
            // FIX #1: Enhanced filename sanitization with path traversal prevention
            $original_filename = $file['name'];
            $safe_filename = sanitize_file_name(basename($original_filename));
            $safe_filename = substr($safe_filename, 0, 100);
            $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $safe_filename);
            
            // FIX #1: Explicitly reject path traversal attempts
            if (strpos($safe_filename, '..') !== false || strpos($safe_filename, '/') !== false || strpos($safe_filename, '\\') !== false) {
                wp_send_json_error('Invalid filename');
            }
            
            $allowed_extensions = ['mp3', 'wav', 'm4a', 'mp4', 'ogg', 'webm', 'aac', 'flac'];
            $file_extension = strtolower(pathinfo($safe_filename, PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_extensions)) {
                wp_send_json_error('File type not allowed');
            }

            $temp_file = $temp_dir . $job_id . '_' . $safe_filename;
            
            if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
                wp_send_json_error('Failed to save uploaded file');
            }
            
            $temp_data['temp_file_path'] = $temp_file;

            set_transient('transcribe_job_' . $job_id, $temp_data, HOUR_IN_SECONDS * 2);
            
            wp_send_json_success(['job_id' => $job_id, 'message' => 'Transcription started']);
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    private static function upload_to_assemblyai($file_path, $api_key) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.assemblyai.com/v2/upload', 
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => file_get_contents($file_path),
            CURLOPT_HTTPHEADER => ['authorization: ' . $api_key, 'Content-Type: application/octet-stream'],
            CURLOPT_TIMEOUT => 300 // 5 minute timeout
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => 'Upload failed with code: ' . $http_code];
        }
        $data = json_decode($response, true);
        if (!isset($data['upload_url'])) {
            return ['success' => false, 'error' => 'Invalid upload response'];
        }
        return ['success' => true, 'upload_url' => $data['upload_url']];
    }
    
    private static function start_assemblyai_transcription($audio_url, $api_key, $language = 'en') {
        $ch = curl_init();
        $data = [
            'audio_url' => $audio_url, 
            'language_code' => $language, 
            'speaker_labels' => true, 
        ];
        
        if ($language === 'en') {
            $data['summarization'] = true;
            $data['summary_model'] = 'informative';
            $data['summary_type'] = 'bullets';
            $data['auto_chapters'] = false;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.assemblyai.com/v2/transcript', 
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['authorization: ' . $api_key, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 60
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => 'Failed to start transcription'];
        }
        $response_data = json_decode($response, true);
        if (!isset($response_data['id'])) {
            return ['success' => false, 'error' => 'Invalid transcription response'];
        }
        return ['success' => true, 'id' => $response_data['id']];
    }
    
    public static function check_transcription() {
        $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        if (empty($job_id)) {
            wp_send_json_error('Invalid job ID');
        }
        
        $job_data = get_transient('transcribe_job_' . $job_id);
        if (!$job_data) {
            wp_send_json_error('Job not found or expired');
        }
        
        $api_key = Transcribe_AI_Helpers::get_api_key('assemblyai');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.assemblyai.com/v2/transcript/' . $job_id,
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_HTTPHEADER => ['authorization: ' . $api_key],
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            wp_send_json_error('Failed to check status');
        }
        $data = json_decode($response, true);
        
        $progress_data = ['status' => $data['status']];
        
        if ($data['status'] === 'completed') {
            $post_id = self::create_transcript_post($data, $job_data);
            if (is_wp_error($post_id)) {
                wp_send_json_error('Failed to save transcript');
            }
            
            if (isset($data['audio_duration'])) {
                $minutes = ceil($data['audio_duration'] / 60);
                if ($job_data['user_id']) {
                    if (Transcribe_AI_Helpers::get_user_data()['role'] === 'basic') {
                        Transcribe_AI_Helpers::update_usage($job_data['user_id'], $minutes);
                    }
                } else {
                    Transcribe_AI_Helpers::update_guest_usage($job_data['guest_id'], $minutes);
                }
            }
            
            delete_transient('transcribe_job_' . $job_id);
            $viewer_page = get_page_by_path('transcript-viewer');
            $viewer_url = $viewer_page ? add_query_arg('id', $post_id, get_permalink($viewer_page->ID)) : admin_url('post.php?post=' . $post_id . '&action=edit');
            
            wp_send_json_success(['status' => 'completed', 'redirect_url' => $viewer_url]);
        } elseif ($data['status'] === 'processing' || $data['status'] === 'queued') {
            wp_send_json_success($progress_data);
        } elseif ($data['status'] === 'error') {
            delete_transient('transcribe_job_' . $job_id);
            wp_send_json_error($data['error'] ?? 'Transcription failed');
        } else {
            wp_send_json_success($progress_data);
        }
    }
    
    private static function create_transcript_post($transcript_data, $job_data) {
        $post_data = [
            'post_title' => $job_data['filename'], 
            'post_type' => 'transcript',
            'post_status' => 'private', 
            'post_author' => $job_data['user_id'] ?: 0
        ];
        $post_id = wp_insert_post($post_data);
        if (is_wp_error($post_id)) return $post_id;
        
        update_post_meta($post_id, '_transcript_data', $transcript_data);
        update_post_meta($post_id, '_transcript_job_id', $job_data['job_id']);
        update_post_meta($post_id, '_transcript_language', $job_data['language']);
        
        if (!empty($transcript_data['summary'])) {
            update_post_meta($post_id, '_transcript_summary', $transcript_data['summary']);
        }
        if (!empty($transcript_data['chapters'])) {
            update_post_meta($post_id, '_transcript_chapters', $transcript_data['chapters']);
        }
        
        if ($job_data['guest_id']) {
            update_post_meta($post_id, '_guest_id', $job_data['guest_id']);
        }
        
        $temp_file = $job_data['temp_file_path'];
        if (file_exists($temp_file)) {
            $upload_dir = wp_upload_dir();
            $secure_dir = $upload_dir['basedir'] . '/transcribe-ai-secure/';
            $secure_file = $secure_dir . $post_id . '_' . basename($job_data['filename']);
            
            if (rename($temp_file, $secure_file)) {
                update_post_meta($post_id, '_secure_audio_path', $secure_file);
            } else {
                error_log('Transcribe AI: Failed to move audio file to secure location');
            }
        }
        
        return $post_id;
    }
    
    public static function get_transcript_data() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        if (!$transcript_id) {
            wp_send_json_error('Invalid transcript ID');
        }
        
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_send_json_error('Permission denied');
        }
        
        $post = get_post($transcript_id);
        $transcript_data = get_post_meta($transcript_id, '_transcript_data', true);
        if (!$transcript_data) {
            wp_send_json_error('No transcript data found');
        }
        
        $transcript_data = self::deep_unslash($transcript_data);
        
        $summary = get_post_meta($transcript_id, '_transcript_summary', true);
        $chapters = get_post_meta($transcript_id, '_transcript_chapters', true);
        $speaker_map = get_post_meta($transcript_id, '_speaker_map', true);
        
        wp_send_json_success([
            'id' => $transcript_id,
            'title' => get_the_title($transcript_id),
            'date' => get_the_date('F j, Y g:i a', $transcript_id),
            'data' => $transcript_data,
            'summary' => $summary,
            'chapters' => $chapters,
		'speaker_map' => $speaker_map ?: (object)[],
            'can_edit' => is_user_logged_in() && ($post->post_author == get_current_user_id() || current_user_can('edit_others_posts'))
        ]);
    }
    
    private static function deep_unslash($value) {
        if (is_array($value)) {
            return array_map([self::class, 'deep_unslash'], $value);
        }
        if (is_string($value)) {
            return stripslashes($value);
        }
        return $value;
    }
    
    public static function update_transcript_title() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        if (!is_user_logged_in()) {
            wp_send_json_error('Please log in to edit transcripts');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        $new_title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        
        if (!$transcript_id || empty($new_title)) {
            wp_send_json_error('Invalid data');
        }
        
        $post = get_post($transcript_id);
        if (!$post || $post->post_type !== 'transcript') {
            wp_send_json_error('Invalid transcript');
        }
        
        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $updated_post = wp_update_post([
            'ID' => $transcript_id,
            'post_title' => $new_title
        ]);
        
        if (is_wp_error($updated_post)) {
            wp_send_json_error('Failed to update title');
        }
        
        wp_send_json_success(['message' => 'Title updated successfully', 'new_title' => $new_title]);
    }
    
    public static function save_speaker_map() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        if (!is_user_logged_in()) {
            wp_send_json_error('Please log in to edit.');
        }

        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        $speaker_map = isset($_POST['speaker_map']) ? json_decode(stripslashes($_POST['speaker_map']), true) : null;

        if (!$transcript_id || !$speaker_map) {
            wp_send_json_error('Invalid data');
        }

        $post = get_post($transcript_id);
        if (!$post || $post->post_type !== 'transcript' || ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts'))) {
            wp_send_json_error('Permission denied');
        }

        $sanitized_speaker_map = [];
        foreach($speaker_map as $key => $value) {
            $sanitized_speaker_map[sanitize_text_field($key)] = sanitize_text_field($value);
        }

        update_post_meta($transcript_id, '_speaker_map', $sanitized_speaker_map);

        wp_send_json_success(['message' => 'Speaker map saved successfully']);
    }
    
    // FIX #2: Implement save locking to prevent race conditions

public static function save_transcript() {
    if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid security token');
    }
    if (!is_user_logged_in()) {
        wp_send_json_error('Please log in to edit transcripts');
    }
    
    $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
    $transcript_data = isset($_POST['transcript_data']) ? json_decode(stripslashes($_POST['transcript_data']), true) : null;
    $speaker_map = isset($_POST['speaker_map']) ? json_decode(stripslashes($_POST['speaker_map']), true) : null;
    
    if (!$transcript_id || !$transcript_data) {
        wp_send_json_error('Invalid data');
    }
    
    $lock_key = 'transcribe_save_lock_' . $transcript_id;
    set_transient($lock_key, session_id(), 30);
    
    $post = get_post($transcript_id);
    if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
        delete_transient($lock_key);
        wp_send_json_error('Permission denied');
    }
    
    // Get the original data to compare against
    $original_data = get_post_meta($transcript_id, '_transcript_data', true);
    $sanitized_data = self::sanitize_transcript_data($transcript_data, true);
    
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    
    try {
        update_post_meta($transcript_id, '_transcript_data', $sanitized_data);
        update_post_meta($transcript_id, '_transcript_modified', current_time('mysql'));
        
        if ($speaker_map) {
            $sanitized_speaker_map = array_map('sanitize_text_field', $speaker_map);
            update_post_meta($transcript_id, '_speaker_map', $sanitized_speaker_map);
        }
        
        $wpdb->query('COMMIT');
        delete_transient($lock_key);
        
        wp_send_json_success(['message' => 'Transcript saved successfully']);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        delete_transient($lock_key);
        wp_send_json_error('Save failed: ' . $e->getMessage());
    }
}

    
    // FIX #18: Enhanced sanitization with proper words array validation
    private static function sanitize_transcript_data($data, $preserve_words = true) {
        if (!is_array($data)) return [];
        
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if ($key === 'text' && is_string($value)) {
                $sanitized[$key] = sanitize_textarea_field($value);
            } elseif ($key === 'utterances' && is_array($value)) {
                $sanitized[$key] = [];
                foreach ($value as $utterance) {
                    if (is_array($utterance)) {
                        $clean_utterance = [];
                        foreach ($utterance as $ukey => $uvalue) {
                            if ($ukey === 'text') {
                                $clean_utterance[$ukey] = sanitize_textarea_field($uvalue);
                            } elseif ($ukey === 'speaker' || $ukey === 'speaker_id') {
                                $clean_utterance[$ukey] = sanitize_text_field($uvalue);
                            } elseif (in_array($ukey, ['start', 'end', 'confidence']) && is_numeric($uvalue)) {
                                $clean_utterance[$ukey] = floatval($uvalue);
                            } elseif ($ukey === 'words' && is_array($uvalue) && $preserve_words) {
                                // FIX #18: Validate each word object
                                $clean_words = [];
                                foreach ($uvalue as $word) {
                                    if (is_array($word) && isset($word['text'], $word['start'], $word['end'])) {
                                        $clean_words[] = [
                                            'text' => sanitize_text_field($word['text']),
                                            'start' => floatval($word['start']),
                                            'end' => floatval($word['end']),
                                            'confidence' => isset($word['confidence']) ? floatval($word['confidence']) : 0
                                        ];
                                    }
                                }
                                $clean_utterance[$ukey] = $clean_words;
                            } elseif ($ukey === 'is_edited') {
                                $clean_utterance[$ukey] = (bool) $uvalue;
                            }
                        }
                        $sanitized[$key][] = $clean_utterance;
                    }
                }
            } elseif (in_array($key, ['audio_duration', 'confidence']) && is_numeric($value)) {
                $sanitized[$key] = floatval($value);
            } elseif ($key === 'words' && is_array($value) && $preserve_words) {
                // Validate root-level words array as well
                $clean_words = [];
                foreach ($value as $word) {
                    if (is_array($word) && isset($word['text'], $word['start'], $word['end'])) {
                        $clean_words[] = [
                            'text' => sanitize_text_field($word['text']),
                            'start' => floatval($word['start']),
                            'end' => floatval($word['end']),
                            'confidence' => isset($word['confidence']) ? floatval($word['confidence']) : 0
                        ];
                    }
                }
                $sanitized[$key] = $clean_words;
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        
        return $sanitized;
    }
    
    public static function delete_transcript() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        if (!is_user_logged_in()) {
            wp_send_json_error('Please log in to delete transcripts');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        if (!$transcript_id) {
            wp_send_json_error('Invalid transcript ID');
        }
        
        $post = get_post($transcript_id);
        if ($post->post_author != get_current_user_id() && !current_user_can('delete_others_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $highlights_table = $wpdb->prefix . 'transcribe_ai_highlights';
        $wpdb->delete($highlights_table, ['transcript_id' => $transcript_id]);
        
        $audio_path = get_post_meta($transcript_id, '_secure_audio_path', true);
        if ($audio_path && file_exists($audio_path)) {
            @unlink($audio_path);
        }
        
        wp_delete_post($transcript_id, true);
        wp_send_json_success(['message' => 'Transcript deleted']);
    }
    
    public static function export_transcript() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }

        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'txt';
        $include_timestamps = isset($_POST['include_timestamps']) ? filter_var($_POST['include_timestamps'], FILTER_VALIDATE_BOOLEAN) : false;
        $include_speakers = isset($_POST['include_speakers']) ? filter_var($_POST['include_speakers'], FILTER_VALIDATE_BOOLEAN) : true;
        $include_highlights = isset($_POST['include_highlights']) ? filter_var($_POST['include_highlights'], FILTER_VALIDATE_BOOLEAN) : false;
        $timestamp_mode = isset($_POST['timestamp_mode']) ? sanitize_text_field($_POST['timestamp_mode']) : 'utterance';
        $paragraph_mode = isset($_POST['paragraph_mode']) ? sanitize_text_field($_POST['paragraph_mode']) : 'utterance';

        $allowed_timestamp_modes = ['utterance', 'sentence', 'none'];
        if (!in_array($timestamp_mode, $allowed_timestamp_modes, true)) {
            $timestamp_mode = 'utterance';
        }

        if (!$include_timestamps || $timestamp_mode === 'none') {
            $include_timestamps = false;
            $timestamp_mode = 'none';
        }

        $allowed_paragraph_modes = ['utterance', 'speaker', 'continuous'];
        if (!in_array($paragraph_mode, $allowed_paragraph_modes, true)) {
            $paragraph_mode = 'utterance';
        }

        if (!$transcript_id) {
            wp_send_json_error('Invalid transcript ID');
        }
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_send_json_error('Permission denied');
        }

        if (isset($_POST['transcript_data'])) {
            $transcript_data = json_decode(stripslashes($_POST['transcript_data']), true);
        } else {
            $transcript_data = get_post_meta($transcript_id, '_transcript_data', true);
        }

        if (!$transcript_data) {
            wp_send_json_error('No transcript data');
        }

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : get_the_title($transcript_id);
        $date = get_the_date('F j, Y', $transcript_id);

        switch ($format) {
            case 'docx':
                $result = self::export_as_docx($transcript_data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode, $transcript_id, $include_highlights);
                break;
            case 'pdf':
                $result = self::export_as_pdf($transcript_data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode, $transcript_id, $include_highlights);
                break;
            default:
                $export_data = self::format_export($transcript_data, $format, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode);
                $result = [
                    'content' => $export_data,
                    'filename' => self::build_export_filename($title, $format),
                    'is_download_url' => false
                ];
        }

        wp_send_json_success($result);
    }
    
    private static function format_export($data, $format, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode) {
        switch ($format) {
            case 'srt':
                return self::format_as_srt($data);
            case 'vtt':
                return self::format_as_vtt($data);
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            case 'txt':
            default:
                return self::format_as_text($data, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode);
        }
    }

    private static function format_as_text($data, $include_timestamps = false, $timestamp_mode = 'utterance', $include_speakers = true, $paragraph_mode = 'utterance') {
        $text = '';
        $utterances = isset($data['utterances']) ? $data['utterances'] : [];

        if (empty($utterances)) {
            return $data['text'] ?? 'No transcript content available.';
        }

        $processed_utterances = [];
        foreach ($utterances as $utterance) {
            $speaker = $utterance['speaker'] ?? '';
            $clean_text = self::strip_highlight_markup($utterance['text'] ?? '');
            $clean_text = self::normalize_export_segment_text($clean_text);

            $effective_timestamp_mode = $include_timestamps ? $timestamp_mode : 'none';
            $line_body = $clean_text;

            if ($effective_timestamp_mode === 'sentence' && !empty($utterance['words'])) {
                $sentences = self::build_sentence_chunks($utterance);
                if (!empty($sentences)) {
                    $parts = [];
                    foreach ($sentences as $sentence) {
                        $sentence_text = self::normalize_export_segment_text($sentence['text']);
                        if ($sentence_text === '') {
                            continue;
                        }
                        $parts[] = '[' . self::ms_to_time($sentence['start']) . '] ' . $sentence_text;
                    }
                    if (!empty($parts)) {
                        $line_body = implode(' ', $parts);
                    }
                }
            }

            $line_without_speaker = $line_body;
            if ($effective_timestamp_mode === 'utterance' && isset($utterance['start'])) {
                $line_without_speaker = '[' . self::ms_to_time($utterance['start']) . '] ' . $line_without_speaker;
            }

            $line_without_speaker = trim(preg_replace('/\s+/u', ' ', $line_without_speaker));

            $line_with_speaker = $line_without_speaker;
            if ($include_speakers && $speaker !== '') {
                $line_with_speaker = $speaker . ': ' . $line_without_speaker;
            }

            $processed_utterances[] = [
                'line_with_speaker' => $line_with_speaker,
                'line_without_speaker' => $line_without_speaker,
                'speaker' => $speaker
            ];
        }

        if ($paragraph_mode === 'continuous') {
            $lines = array_map(function ($p) {
                return $p['line_with_speaker'];
            }, $processed_utterances);
            $text = implode(' ', array_filter(array_map('trim', $lines)));
        } elseif ($paragraph_mode === 'speaker') {
            $paragraphs = [];
            $current_speaker = null;
            $current_block = [];

            $append_block = function($speaker, $block) use (&$paragraphs, $include_speakers) {
                if (empty($block)) {
                    return;
                }

                $body = trim(implode(' ', $block));
                if ($body === '') {
                    return;
                }

                if ($include_speakers && $speaker !== '') {
                    $paragraphs[] = $speaker . ":\n" . $body;
                } else {
                    $paragraphs[] = $body;
                }
            };

            foreach ($processed_utterances as $p) {
                if ($p['speaker'] !== $current_speaker) {
                    $append_block($current_speaker, $current_block);
                    $current_block = [];
                    $current_speaker = $p['speaker'];
                }

                $current_block[] = $include_speakers ? $p['line_without_speaker'] : $p['line_with_speaker'];
            }

            $append_block($current_speaker, $current_block);

            $text = implode("\n\n", array_map('trim', $paragraphs));
        } else {
            $lines = array_map(function ($p) {
                return $p['line_with_speaker'];
            }, $processed_utterances);
            $text = implode("\n\n", array_map('trim', $lines));
        }

        return trim($text);
    }

    private static function strip_highlight_markup($text) {
        if (!is_string($text) || $text === '') {
            return '';
        }

        $text = preg_replace('/\[\[HIGHLIGHT color="[^\"]+"\]\](.*?)\[\[\/HIGHLIGHT\]\]/s', '$1', $text);
        $text = preg_replace('/<\/?mark[^>]*>/i', '', $text);
        $text = wp_strip_all_tags($text, false);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function normalize_export_segment_text($text) {
        if (!is_string($text)) {
            return '';
        }

        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace("/\r\n|\r|\n/u", ' ', $decoded);
        $hasLeadingSpace = preg_match('/^\s/u', $decoded) === 1;
        $hasTrailingSpace = preg_match('/\s$/u', $decoded) === 1;

        $decoded = trim($decoded);
        if ($decoded === '') {
            return ($hasLeadingSpace || $hasTrailingSpace) ? ' ' : '';
        }

        $decoded = preg_replace('/\s+/u', ' ', $decoded);

        if ($hasLeadingSpace) {
            $decoded = ' ' . $decoded;
        }

        if ($hasTrailingSpace) {
            $decoded .= ' ';
        }

        return $decoded;
    }

    private static function build_sentence_chunks($utterance) {
        $chunks = [];
        $words = isset($utterance['words']) && is_array($utterance['words']) ? $utterance['words'] : [];

        if (empty($words)) {
            return $chunks;
        }

        $buffer = '';
        $sentence_start = null;

        foreach ($words as $word) {
            if (!is_array($word) || !isset($word['text'])) {
                continue;
            }

            if ($sentence_start === null) {
                $sentence_start = isset($word['start']) ? $word['start'] : ($utterance['start'] ?? 0);
            }

            $buffer .= $word['text'] . ' ';

            if (preg_match('/[.?!]$/', trim($word['text']))) {
                $chunks[] = [
                    'start' => $sentence_start,
                    'text' => trim($buffer)
                ];
                $buffer = '';
                $sentence_start = null;
            }
        }

        if ($buffer !== '') {
            $chunks[] = [
                'start' => $sentence_start ?? ($utterance['start'] ?? 0),
                'text' => trim($buffer)
            ];
        }

        return $chunks;
    }

    private static function build_export_filename($title, $extension) {
        return self::sanitize_download_filename($title, $extension);
    }

    private static function sanitize_download_filename($filename, $extension = '') {
        $base = is_string($filename) ? $filename : '';
        $base = wp_strip_all_tags($base);
        $base = preg_replace('/[\\\\\/:"*?<>|]+/', '', $base);
        $base = preg_replace('/\s+/u', ' ', trim($base));

        if ($base === '') {
            $base = 'transcript';
        }

        $extension = ltrim((string) $extension, '.');
        if ($extension !== '') {
            if (!preg_match('/\.' . preg_quote($extension, '/') . '$/u', $base)) {
                $base .= '.' . $extension;
            }
        }

        return $base;
    }

    private static function build_utterance_segments($utterance, $options, $include_speaker_prefix = true) {
        $segments = [];

        $include_timestamps = !empty($options['include_timestamps']);
        $timestamp_mode = $include_timestamps ? ($options['timestamp_mode'] ?? 'utterance') : 'none';
        $include_speakers = !empty($options['include_speakers']);
        $include_highlights = !empty($options['include_highlights']);
        $highlights = $options['highlights'] ?? [];

        if ($include_timestamps && $timestamp_mode === 'utterance' && isset($utterance['start'])) {
            $segments[] = [
                'text' => '[' . self::ms_to_time($utterance['start']) . '] ',
                'color' => null,
                'bold' => false
            ];
        }

        if ($include_speaker_prefix && $include_speakers && !empty($utterance['speaker'])) {
            $segments[] = [
                'text' => $utterance['speaker'] . ': ',
                'color' => null,
                'bold' => true
            ];
        }

        if ($include_timestamps && $timestamp_mode === 'sentence' && !empty($utterance['words'])) {
            $sentences = self::build_sentence_chunks($utterance);
            $parts = [];
            foreach ($sentences as $sentence) {
                $sentence_text = self::normalize_export_segment_text($sentence['text']);
                if ($sentence_text === '') {
                    continue;
                }
                $parts[] = '[' . self::ms_to_time($sentence['start']) . '] ' . $sentence_text;
            }

            if (!empty($parts)) {
                $segments[] = [
                    'text' => implode(' ', $parts),
                    'color' => null,
                    'bold' => false
                ];
                return self::prepare_segments_for_output($segments);
            }
        }

        if ($include_highlights) {
            $raw_segments = (!empty($utterance['words']))
                ? self::generate_highlighted_segments($utterance, $highlights)
                : self::parse_highlighted_text($utterance['text']);

            foreach ($raw_segments as $segment) {
                $normalized = self::normalize_export_segment_text($segment['text']);
                if ($normalized === '') {
                    continue;
                }
                $color = null;
                if (!empty($segment['color'])) {
                    $color = sanitize_hex_color($segment['color']);
                }
                $segments[] = [
                    'text' => $normalized,
                    'color' => $color ?: null,
                    'bold' => false
                ];
            }
        } else {
            $plain_text = self::strip_highlight_markup($utterance['text'] ?? '');
            $normalized = self::normalize_export_segment_text($plain_text);
            if ($normalized !== '') {
                $segments[] = [
                    'text' => $normalized,
                    'color' => null,
                    'bold' => false
                ];
            }
        }

        return self::prepare_segments_for_output($segments);
    }

    private static function prepare_segments_for_output($segments) {
        $prepared = [];
        $previousEndsWithSpace = true;

        foreach ($segments as $segment) {
            $text = isset($segment['text']) ? $segment['text'] : '';
            if ($text === '') {
                continue;
            }

            $startsWithSpace = preg_match('/^\s/u', $text) === 1;
            $startsWithPunctuation = preg_match('/^[,.;:?!]/u', $text) === 1;

            if (!$previousEndsWithSpace && !$startsWithSpace && !$startsWithPunctuation) {
                $text = ' ' . $text;
            }

            $previousEndsWithSpace = preg_match('/\s$/u', $text) === 1;

            $segment['text'] = $text;
            $prepared[] = $segment;
        }

        return $prepared;
    }

    private static function prepare_export_paragraphs($utterances, $options) {
        $paragraphs = [];
        $paragraph_mode = $options['paragraph_mode'] ?? 'utterance';
        $include_speakers = !empty($options['include_speakers']);

        if ($paragraph_mode === 'continuous') {
            $combined = [];
            foreach ($utterances as $utterance) {
                $segments = self::build_utterance_segments($utterance, $options, true);
                if (empty($segments)) {
                    continue;
                }
                if (!empty($combined)) {
                    $combined[] = [
                        'text' => ' ',
                        'color' => null,
                        'bold' => false
                    ];
                }
                $combined = array_merge($combined, $segments);
            }

            if (!empty($combined)) {
                $paragraphs[] = ['segments' => self::prepare_segments_for_output($combined)];
            }

            return $paragraphs;
        }

        $current_speaker = null;

        foreach ($utterances as $utterance) {
            $speaker = $utterance['speaker'] ?? '';
            $include_prefix = !($paragraph_mode === 'speaker' && $include_speakers);
            $segments = self::build_utterance_segments($utterance, $options, $include_prefix);

            if (empty($segments)) {
                continue;
            }

            if ($paragraph_mode === 'speaker') {
                if ($speaker !== $current_speaker) {
                    if ($current_speaker !== null) {
                        $paragraphs[] = ['segments' => []];
                    }

                    if ($include_speakers && $speaker !== '') {
                        $paragraphs[] = ['segments' => self::prepare_segments_for_output([
                            [
                                'text' => $speaker . ':',
                                'color' => null,
                                'bold' => true
                            ]
                        ])];
                    }

                    $current_speaker = $speaker;
                }

                $paragraphs[] = ['segments' => $segments];
                continue;
            }

            $paragraphs[] = ['segments' => $segments];
        }

        return $paragraphs;
    }
    
    private static function get_highlights_for_export($transcript_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'transcribe_ai_highlights';
        $query = "SELECT * FROM $table_name WHERE transcript_id = %d ORDER BY start_time ASC";
        $highlights = $wpdb->get_results($wpdb->prepare($query, $transcript_id), ARRAY_A);
        return $highlights ?: [];
    }

   // +++ START: THIS IS THE NEW, IMPROVED FUNCTION +++
private static function map_hex_to_word_color($hex) {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    // Maps the specific colors from the UI to Word's available colors
    // #ffeb3b -> yellow
    if ($r > 220 && $g > 200 && $b < 100) return 'yellow';
    // #a7ffeb -> cyan
    if ($g > 220 && $b > 200 && $r < 200) return 'cyan';
    // #ff9f9c -> red
    if ($r > 220 && $g > 150 && $b > 150) return 'red';
    // #b4a7d6 -> magenta
    if ($b > 200 && $r > 150 && $g > 150) return 'magenta';

    return 'yellow'; // Default for safety
}
// +++ END: THIS IS THE NEW, IMPROVED FUNCTION +++
    // FIX #9: Improved highlight segmentation with better boundary detection
// in transcribe-ai.php, replace the entire generate_highlighted_segments() function

    private static function generate_highlighted_segments($utterance, $highlights) {
        // +++ START OF THE FIX +++
        // If utterance is edited (no 'words' data), parse it for [[HIGHLIGHT]] markers.
        if (!isset($utterance['words']) || empty($utterance['words'])) {
            return self::parse_highlighted_text($utterance['text']);
        }
        // +++ END OF THE FIX +++

        $segments = [];
        $words = $utterance['words'];
        
        $current_segment_text = '';
        $current_segment_color = null;

        foreach ($words as $index => $word) {
            $word_highlight_color = null;
            
            // Check if word is within any highlight range
            foreach ($highlights as $h) {
                if ($h['start_time'] !== null && $h['end_time'] !== null) {
                    if ($word['start'] >= $h['start_time'] && $word['end'] <= $h['end_time']) {
                        $word_highlight_color = $h['color'];
                        break;
                    }
                }
            }

            if ($index === 0) {
                $current_segment_text = $word['text'];
                $current_segment_color = $word_highlight_color;
            } elseif ($word_highlight_color !== $current_segment_color) {
                $segments[] = ['text' => $current_segment_text, 'color' => $current_segment_color];
                $current_segment_text = $word['text'];
                $current_segment_color = $word_highlight_color;
            } else {
                $current_segment_text .= ' ' . $word['text'];
            }
        }

        if (!empty($current_segment_text)) {
            $segments[] = ['text' => $current_segment_text, 'color' => $current_segment_color];
        }

        return $segments;
    }
    


// REPLACE the entire parse_highlighted_text function with this:

    /**
     * Parses text with embedded [[HIGHLIGHT]] markers into structured segments. (IMPROVED VERSION)
     * @param string $text The text to parse.
     * @return array An array of segments, each with 'text' and 'color' properties.
     */
    private static function parse_highlighted_text($text) {
        if (empty($text)) {
            return [];
        }

        $segments = [];
        $regex = '/\[\[HIGHLIGHT color="([^"]+)"\]\](.*?)\[\[\/HIGHLIGHT\]\]/';
        $last_offset = 0;

        preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE);

        // If there are no highlight markers, return the whole text as one segment
        if (empty($matches[0])) {
            return [['text' => $text, 'color' => null]];
        }

        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $full_match_text = $match[0];
            $color = $matches[1][$index][0];
            $highlighted_text = $matches[2][$index][0];

            // Add any plain text that occurred before this highlight
            if ($offset > $last_offset) {
                $segments[] = [
                    'text'  => substr($text, $last_offset, $offset - $last_offset),
                    'color' => null
                ];
            }

            // Add the highlighted segment itself
            $segments[] = [
                'text'  => $highlighted_text,
                'color' => $color
            ];

            // Update our position in the string
            $last_offset = $offset + strlen($full_match_text);
        }

        // Add any remaining plain text at the very end of the string
        if ($last_offset < strlen($text)) {
            $segments[] = [
                'text'  => substr($text, $last_offset),
                'color' => null
            ];
        }

        return $segments;
    }
    
    private static function export_as_docx($data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode, $transcript_id, $include_highlights) {
        if (!class_exists('ZipArchive')) {
            return self::export_as_rtf($data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode);
        }

        $highlights = ($include_highlights && $transcript_id) ? self::get_highlights_for_export($transcript_id) : [];

        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $filename = 'transcript_' . uniqid() . '.docx';
        $filepath = $temp_dir . $filename;
        
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
            wp_send_json_error('Failed to create DOCX file');
            return;
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');

        $docContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        $docContent .= '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
        $docContent .= '<w:p><w:r><w:t>' . htmlspecialchars($date, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';

        $utterances = isset($data['utterances']) ? $data['utterances'] : [];
        $options = [
            'include_timestamps' => $include_timestamps,
            'timestamp_mode' => $timestamp_mode,
            'include_speakers' => $include_speakers,
            'paragraph_mode' => $paragraph_mode,
            'include_highlights' => $include_highlights,
            'highlights' => $highlights
        ];

        $paragraphs = self::prepare_export_paragraphs($utterances, $options);

        foreach ($paragraphs as $paragraph) {
            if (empty($paragraph['segments'])) {
                $docContent .= '<w:p/>';
                continue;
            }

            $docContent .= '<w:p>';
            foreach ($paragraph['segments'] as $segment) {
                $text = isset($segment['text']) ? $segment['text'] : '';
                if ($text === '') {
                    continue;
                }

                $docContent .= '<w:r>';
                $runProps = '';
                if (!empty($segment['bold']) || !empty($segment['color'])) {
                    $runProps .= '<w:rPr>';
                    if (!empty($segment['bold'])) {
                        $runProps .= '<w:b/>';
                    }
                    if (!empty($segment['color'])) {
                        $mapped_color = self::map_hex_to_word_color($segment['color']);
                        $runProps .= '<w:highlight w:val="' . htmlspecialchars($mapped_color, ENT_XML1, 'UTF-8') . '"/>';
                    }
                    $runProps .= '</w:rPr>';
                }

                if ($runProps !== '') {
                    $docContent .= $runProps;
                }

                $docContent .= '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t>';
                $docContent .= '</w:r>';
            }
            $docContent .= '</w:p>';
        }

        $docContent .= '</w:body></w:document>';
        $zip->addFromString('word/document.xml', $docContent);
        $zip->close();

        $download_url = add_query_arg([
            'transcribe_download' => 1,
            'file' => basename($filepath),
            'nonce' => wp_create_nonce('download_' . basename($filepath)),
            'display' => rawurlencode(base64_encode(self::build_export_filename($title, 'docx')))
        ], home_url('/'));

        wp_schedule_single_event(time() + 3600, 'transcribe_ai_cleanup_temp_file', [$filepath]);

        return [
            'download_url' => $download_url,
            'filename' => self::build_export_filename($title, 'docx'),
            'is_download_url' => true
        ];
    }

    private static function export_as_rtf($data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode) {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $filename = 'transcript_' . uniqid() . '.rtf';
        $filepath = $temp_dir . $filename;
        
        $rtf = '{\rtf1\ansi\deff0 {\fonttbl{\f0 Times New Roman;}}';
        $rtf .= '\viewkind4\uc1\pard\f0\fs24 ';
        
        $rtf .= '\b\fs32 ' . self::rtf_encode($title) . '\b0\fs24\par\par ';
        $rtf .= self::rtf_encode($date) . '\par\par ';
        
        $utterances = isset($data['utterances']) ? $data['utterances'] : [];
        $effective_timestamp_mode = $include_timestamps ? $timestamp_mode : 'none';
        foreach ($utterances as $utterance) {
            if ($effective_timestamp_mode === 'utterance' && isset($utterance['start'])) {
                $rtf .= '[' . self::ms_to_time($utterance['start']) . '] ';
            }
            if ($include_speakers && isset($utterance['speaker'])) {
                $rtf .= '\b ' . self::rtf_encode($utterance['speaker']) . ':\b0 ';
            }

            if ($effective_timestamp_mode === 'sentence' && !empty($utterance['words'])) {
                $sentences = self::build_sentence_chunks($utterance);
                $parts = [];
                foreach ($sentences as $sentence) {
                    $parts[] = '[' . self::ms_to_time($sentence['start']) . '] ' . self::rtf_encode($sentence['text']);
                }
                $rtf .= implode(' ', $parts) . '\par\par ';
            } else {
                $plain = self::strip_highlight_markup($utterance['text'] ?? '');
                $rtf .= self::rtf_encode($plain) . '\par\par ';
            }
        }

        $rtf .= '}';

        file_put_contents($filepath, $rtf);

        $download_url = add_query_arg([
            'transcribe_download' => 1,
            'file' => basename($filepath),
            'nonce' => wp_create_nonce('download_' . basename($filepath)),
            'display' => rawurlencode(base64_encode(self::build_export_filename($title, 'rtf')))
        ], home_url('/'));

        wp_schedule_single_event(time() + 3600, 'transcribe_ai_cleanup_temp_file', [$filepath]);

        return [
            'download_url' => $download_url,
            'filename' => self::build_export_filename($title, 'rtf'),
            'is_download_url' => true
        ];
    }
    
    private static function rtf_encode($text) {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('{', '\{', $text);
        $text = str_replace('}', '\}', $text);
        $text = str_replace("\n", '\par ', $text);
        return mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');
    }

   private static function export_as_pdf($data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode, $transcript_id, $include_highlights) {
        // Check if TCPDF is available
        $tcpdf_path = TRANSCRIBE_AI_PLUGIN_DIR . 'vendor/autoload.php';
        if (!file_exists($tcpdf_path)) {
            // Graceful fallback if the PDF library is not bundled
            return self::export_as_html_fallback(
                $data,
                $title,
                $date,
                $include_timestamps,
                $timestamp_mode,
                $include_speakers,
                $paragraph_mode,
                $transcript_id,
                $include_highlights
            );
        }

        require_once($tcpdf_path);

        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Transcribe AI');
        $pdf->SetAuthor('Transcribe AI');
        $pdf->SetTitle($title);
        $pdf->SetSubject('Transcript');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Set font
        $pdf->SetFont('dejavusans', '', 10);

        // Add a page
        $pdf->AddPage();

        // Title
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 10, $title, 0, 1);

        // Date
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 5, $date, 0, 1);
        $pdf->Ln(5);

        // Reset text color
        $pdf->SetTextColor(0, 0, 0);

        // Get highlights if needed
        $highlights = ($include_highlights && $transcript_id) ? self::get_highlights_for_export($transcript_id) : [];

        // Process utterances
        $utterances = isset($data['utterances']) ? $data['utterances'] : [];

        $options = [
            'include_timestamps' => $include_timestamps,
            'timestamp_mode' => $timestamp_mode,
            'include_speakers' => $include_speakers,
            'paragraph_mode' => $paragraph_mode,
            'include_highlights' => $include_highlights,
            'highlights' => $highlights,
        ];

        $paragraphs = self::prepare_export_paragraphs($utterances, $options);

        foreach ($paragraphs as $paragraph) {
            if (empty($paragraph['segments'])) {
                $pdf->Ln(6);
                continue;
            }

            $html = '<span style="font-size:10pt; font-family:dejavusans; white-space: pre-wrap;">';
            foreach ($paragraph['segments'] as $segment) {
                $text = isset($segment['text']) ? $segment['text'] : '';
                if ($text === '') {
                    continue;
                }

                $encoded = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $open = '';
                $close = '';

                if (!empty($segment['bold'])) {
                    $open .= '<strong>';
                    $close = '</strong>' . $close;
                }

                if (!empty($segment['color'])) {
                    $color = sanitize_hex_color($segment['color']);
                    if (!$color) {
                        $color = '#ffff00';
                    }
                    $open .= '<span style="background-color:' . $color . ';">';
                    $close = '</span>' . $close;
                }

                $html .= $open . $encoded . $close;
            }
            $html .= '</span>';

            $pdf->writeHTML($html, true, false, true, false, '');

            if ($paragraph_mode !== 'continuous') {
                $pdf->Ln(2);
            }
        }

        // Save PDF to temp file
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $safe_title = sanitize_file_name($title);
        $filename = $safe_title . '_' . uniqid() . '.pdf';
        $filepath = $temp_dir . $filename;

        $pdf->Output($filepath, 'F');

        $download_url = add_query_arg([
            'transcribe_download' => 1,
            'file' => basename($filepath),
            'nonce' => wp_create_nonce('download_' . basename($filepath)),
            'display' => rawurlencode(base64_encode(self::build_export_filename($title, 'pdf')))
        ], home_url('/'));

        wp_schedule_single_event(time() + 3600, 'transcribe_ai_cleanup_temp_file', [$filepath]);

        return [
            'download_url' => $download_url,
            'filename' => self::build_export_filename($title, 'pdf'),
            'is_download_url' => true,
            'message' => __('PDF generated successfully!', 'transcribe-ai')
        ];
    }

    private static function export_as_html_fallback($data, $title, $date, $include_timestamps, $timestamp_mode, $include_speakers, $paragraph_mode, $transcript_id, $include_highlights) {
        $highlights = ($include_highlights && $transcript_id) ? self::get_highlights_for_export($transcript_id) : [];
        $utterances = isset($data['utterances']) ? $data['utterances'] : [];

        $options = [
            'include_timestamps' => $include_timestamps,
            'timestamp_mode' => $timestamp_mode,
            'include_speakers' => $include_speakers,
            'paragraph_mode' => $paragraph_mode,
            'include_highlights' => $include_highlights,
            'highlights' => $highlights,
        ];

        $paragraphs = self::prepare_export_paragraphs($utterances, $options);

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        $html .= '<title>' . esc_html($title) . '</title>';
        $html .= '<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#111;line-height:1.6;}';
        $html .= 'h1{font-size:24px;margin:0 0 8px;}';
        $html .= '.transcript-date{color:#555;margin:0 0 24px;}';
        $html .= '.transcript-paragraph{white-space:pre-wrap;margin:0 0 16px;font-size:14px;}';
        $html .= '.transcript-gap{height:12px;}';
        $html .= '.transcript-highlight{padding:0 2px;border-radius:2px;}';
        $html .= '</style></head><body>';
        $html .= '<h1>' . esc_html($title) . '</h1>';
        $html .= '<p class="transcript-date">' . esc_html($date) . '</p>';

        foreach ($paragraphs as $paragraph) {
            if (empty($paragraph['segments'])) {
                $html .= '<div class="transcript-gap"></div>';
                continue;
            }

            $html .= '<p class="transcript-paragraph">';
            foreach ($paragraph['segments'] as $segment) {
                $text = isset($segment['text']) ? $segment['text'] : '';
                if ($text === '') {
                    continue;
                }

                $encoded = esc_html($text);
                $open = '';
                $close = '';

                if (!empty($segment['bold'])) {
                    $open .= '<strong>';
                    $close = '</strong>' . $close;
                }

                if (!empty($segment['color'])) {
                    $color = sanitize_hex_color($segment['color']);
                    if (!$color) {
                        $color = '#ffff00';
                    }
                    $open .= '<span class="transcript-highlight" style="background-color:' . esc_attr($color) . ';">';
                    $close = '</span>' . $close;
                }

                $html .= $open . $encoded . $close;
            }
            $html .= '</p>';
        }

        $html .= '</body></html>';

        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/transcribe-ai-temp/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $filename = 'transcript_' . uniqid() . '.html';
        $filepath = trailingslashit($temp_dir) . $filename;
        file_put_contents($filepath, $html);

        $download_url = add_query_arg([
            'transcribe_download' => 1,
            'file' => basename($filepath),
            'nonce' => wp_create_nonce('download_' . basename($filepath)),
            'display' => rawurlencode(base64_encode(self::build_export_filename($title, 'html')))
        ], home_url('/'));

        wp_schedule_single_event(time() + 3600, 'transcribe_ai_cleanup_temp_file', [$filepath]);

        return [
            'download_url' => $download_url,
            'filename' => self::build_export_filename($title, 'html'),
            'is_download_url' => true,
            'message' => __('PDF export unavailable - provided HTML instead.', 'transcribe-ai')
        ];
    }

    private static function generate_html_for_export($data, $title, $date, $include_timestamps, $include_speakers, $paragraph_mode, $transcript_id, $include_highlights) {
        $html = '<h1>' . esc_html($title) . '</h1>';
        $html .= '<p class="meta">' . esc_html($date) . '</p>';
        
        $highlights = ($include_highlights && $transcript_id) ? self::get_highlights_for_export($transcript_id) : [];
        $utterances = isset($data['utterances']) ? $data['utterances'] : [];
        
        $render_text = function($utterance) use ($highlights, $include_highlights) {
            if ($include_highlights && !empty($highlights)) {
                $segments = self::generate_highlighted_segments($utterance, $highlights);
                $html_parts = [];
                foreach ($segments as $segment) {
                    $segment_text = esc_html($segment['text']);
                    if ($segment['color']) {
                        $html_parts[] = '<mark style="background-color:' . esc_attr($segment['color']) . ';">' . $segment_text . '</mark>';
                    } else {
                        $html_parts[] = $segment_text;
                    }
                }
                return implode(' ', $html_parts);
            }
            return esc_html($utterance['text']);
        };
        
        if ($paragraph_mode === 'continuous') {
            $html .= '<p>';
            foreach ($utterances as $utterance) {
                if ($include_timestamps) {
                    $html .= '<span class="timestamp">[' . self::ms_to_time($utterance['start']) . ']</span>';
                }
                if ($include_speakers && isset($utterance['speaker'])) {
                    $html .= '<strong>' . esc_html($utterance['speaker']) . ':</strong> ';
                }
                $html .= $render_text($utterance) . ' ';
            }
            $html .= '</p>';
        } else if ($paragraph_mode === 'speaker') {
            $current_speaker = null;
            $current_block = '';
            foreach ($utterances as $utterance) {
                $speaker = $utterance['speaker'] ?? 'Unknown';
                if ($speaker !== $current_speaker) {
                    if ($current_block) {
                        $html .= '<div class="speaker-block">';
                        if ($include_speakers && $current_speaker) {
                            $html .= '<p class="speaker-name">Speaker ' . esc_html($current_speaker) . ':</p>';
                        }
                        $html .= '<p class="utterance">' . trim($current_block) . '</p></div>';
                    }
                    $current_speaker = $speaker;
                    $current_block = '';
                }
                if ($include_timestamps) {
                    $current_block .= '<span class="timestamp">[' . self::ms_to_time($utterance['start']) . ']</span>';
                }
                $current_block .= $render_text($utterance) . ' ';
            }
            if ($current_block) {
                $html .= '<div class="speaker-block">';
                if ($include_speakers && $current_speaker) {
                    $html .= '<p class="speaker-name">Speaker ' . esc_html($current_speaker) . ':</p>';
                }
                $html .= '<p class="utterance">' . trim($current_block) . '</p></div>';
            }
        } else {
            foreach ($utterances as $utterance) {
                $html .= '<p>';
                if ($include_timestamps) {
                    $html .= '<span class="timestamp">[' . self::ms_to_time($utterance['start']) . ']</span>';
                }
                if ($include_speakers && isset($utterance['speaker'])) {
                    $html .= '<strong>' . esc_html($utterance['speaker']) . ':</strong> ';                }
                $html .= $render_text($utterance);
                $html .= '</p>';
            }
        }
        
        return $html;
    }
    
    private static function generate_subtitle_cues($data) {
        $cues = [];
        if (empty($data['words'])) {
            return [];
        }

        $words = $data['words'];
        $current_cue_words = [];
        
        foreach ($words as $word) {
            $current_cue_words[] = $word;
            $text = implode(' ', array_column($current_cue_words, 'text'));

            if (strlen($text) > 70 || preg_match('/[.?!]$/', $word['text'])) {
                $start_time = $current_cue_words[0]['start'];
                $end_time = end($current_cue_words)['end'];

                // Add line breaks for readability
                if (strlen($text) > 40) {
                    $midpoint = floor(count($current_cue_words) / 2);
                    $line1 = implode(' ', array_slice(array_column($current_cue_words, 'text'), 0, $midpoint));
                    $line2 = implode(' ', array_slice(array_column($current_cue_words, 'text'), $midpoint));
                    $text = $line1 . "\n" . $line2;
                }

                $cues[] = ['start' => $start_time, 'end' => $end_time, 'text' => $text];
                $current_cue_words = [];
            }
        }

        if (!empty($current_cue_words)) {
            $cues[] = [
                'start' => $current_cue_words[0]['start'],
                'end' => end($current_cue_words)['end'],
                'text' => implode(' ', array_column($current_cue_words, 'text'))
            ];
        }

        return $cues;
    }
    
    private static function format_as_srt($data) {
        $cues = self::generate_subtitle_cues($data);
        if (empty($cues)) {
            return "1\n00:00:00,000 --> 00:00:05,000\n[No timing information available]";
        }

        $srt = '';
        $index = 1;

        foreach ($cues as $cue) {
            $start = self::ms_to_srt_time($cue['start']);
            $end = self::ms_to_srt_time($cue['end']);
            $text = trim($cue['text']);

            if (!empty($text)) {
                $srt .= "{$index}\n{$start} --> {$end}\n{$text}\n\n";
                $index++;
            }
        }

        return $srt;
    }

    private static function format_as_vtt($data) {
        $cues = self::generate_subtitle_cues($data);
        $vtt = "WEBVTT\n\n";

        if (empty($cues)) {
            return $vtt . "00:00:00.000 --> 00:00:05.000\n[No timing information available]";
        }

        foreach ($cues as $cue) {
            $start = self::ms_to_vtt_time($cue['start']);
            $end = self::ms_to_vtt_time($cue['end']);
            $text = trim($cue['text']);

            if (!empty($text)) {
                $vtt .= "{$start} --> {$end}\n{$text}\n\n";
            }
        }

        return $vtt;
    }

    // FIX #10: Improved translation with better chunking for long utterances
// REPLACE the entire translate_transcript function with this:
    public static function translate_transcript() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $api_key = Transcribe_AI_Helpers::get_api_key('deepl');
        if (empty($api_key)) {
            wp_send_json_error('DeepL API key not configured.');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : 'EN-US';
        $speaker_map = isset($_POST['speaker_map']) ? json_decode(stripslashes($_POST['speaker_map']), true) : null;
        
        if (!$transcript_id) {
            wp_send_json_error('Invalid transcript ID');
        }
        
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_send_json_error('Permission denied. You cannot access this transcript.');
        }
        
        $transcript_data = get_post_meta($transcript_id, '_transcript_data', true);
        if (!$transcript_data || !isset($transcript_data['utterances'])) {
            wp_send_json_error('No text to translate.');
        }
        
        $utterances = $transcript_data['utterances'];
        
        // --- START NEW LOGIC ---
        
        // 1. Collect all texts to be translated
        $texts_to_translate = [];
        foreach ($utterances as $utterance) {
            // If the text was edited, it contains highlight markers. Strip them before translating.
            $clean_text = preg_replace('/\[\[HIGHLIGHT color="[^"]+"\]\](.*?)\[\[\/HIGHLIGHT\]\]/', '$1', $utterance['text']);
            $texts_to_translate[] = $clean_text;
        }

        if (empty($texts_to_translate)) {
            wp_send_json_error('No text to translate.');
        }

        // 2. Batch translation requests (DeepL free limit is 50 texts per request)
        $text_batches = array_chunk($texts_to_translate, 50);
        $all_translated_texts = [];

        foreach ($text_batches as $batch) {
            $ch = curl_init();
            
            // --- START: THIS IS THE FIX ---
            // Manually build the 'text=...' query parts
            $post_data = [];
            foreach ($batch as $text_item) {
                $post_data[] = 'text=' . urlencode($text_item);
            }

            // Add the other parameters
            $post_data[] = 'target_lang=' . urlencode($target_lang);
            $post_data[] = 'tag_handling=' . urlencode('xml');

            // Join all parts with '&'
            $data = implode('&', $post_data);
            // --- END: THIS IS THE FIX ---
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api-free.deepl.com/v2/translate',
                CURLOPT_RETURNTRANSFER => true, 
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data, // <-- Pass the correctly formatted string
                CURLOPT_HTTPHEADER => [
                    'Authorization: DeepL-Auth-Key ' . $api_key,
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_TIMEOUT => 60 // Increased timeout for batch
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                wp_send_json_error('Translation error: ' . $error);
                return;
            }
            
            if ($http_code !== 200) {
                $error_msg = 'Translation API error (HTTP ' . $http_code . ')';
                if ($response) {
                    $error_data = json_decode($response, true);
                    if (isset($error_data['message'])) {
                        $error_msg .= ': ' . $error_data['message'];
                    }
                }
                wp_send_json_error($error_msg);
                return;
            }
            
            $response_data = json_decode($response, true);
            if (!$response_data || !isset($response_data['translations'])) {
                wp_send_json_error('Invalid translation response.');
                return;
            }
            
            // Add the translated texts from this batch to our master list
            foreach ($response_data['translations'] as $translation) {
                $all_translated_texts[] = $translation['text'];
            }
        }
        
        // 3. Reconstruct the utterance array with translated text
        $translated_utterances = [];
        
        // Get the speaker map to apply display names
        if (!$speaker_map) {
            $speaker_map = get_post_meta($transcript_id, '_speaker_map', true) ?: [];
        }

        foreach ($utterances as $index => $utterance) {
            $original_speaker = $utterance['speaker'] ?? 'A';
            // Use the passed-in speaker map first, fallback to saved map
            $display_speaker = $speaker_map[$original_speaker] ?? (get_post_meta($transcript_id, '_speaker_map', true)[$original_speaker] ?? 'Speaker ' . $original_speaker);

            $translated_utterances[] = [
                'start' => $utterance['start'],
                'end' => $utterance['end'],
                'speaker' => $original_speaker, // Send the original speaker ID
                'display_speaker' => $display_speaker, // Send the display name
                'text' => $all_translated_texts[$index] ?? '[Translation Error]'
            ];
        }

        // Send the structured array, not a single text block
        wp_send_json_success(['translated_utterances' => $translated_utterances]);
        // --- END NEW LOGIC ---
    }
        
    public static function generate_summary() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        if (!$transcript_id) {
            wp_send_json_error('Invalid transcript ID');
        }
        
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_send_json_error('Permission denied');
        }
        
        $summary = get_post_meta($transcript_id, '_transcript_summary', true);
        $chapters = get_post_meta($transcript_id, '_transcript_chapters', true);
        
        if ($summary || $chapters) {
            wp_send_json_success([
                'summary' => $summary,
                'chapters' => $chapters
            ]);
            return;
        }
        
        $openai_key = Transcribe_AI_Helpers::get_api_key('openai');
        if (empty($openai_key)) {
            wp_send_json_error('Summary generation requires OpenAI API key or AssemblyAI summarization.');
        }
        
        $transcript_data = get_post_meta($transcript_id, '_transcript_data', true);
        if (!$transcript_data || !isset($transcript_data['text'])) {
            wp_send_json_error('No transcript text available for summary.');
        }
        
        $text = substr($transcript_data['text'], 0, 4000);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant that creates concise summaries of transcripts. Create a bullet point summary of the key points.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Please summarize this transcript:\n\n" . $text
                    ]
                ],
                'max_tokens' => 500
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openai_key,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            wp_send_json_error('Failed to generate summary');
        }
        
        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            wp_send_json_error('Invalid response from AI');
        }
        
        $summary = $data['choices'][0]['message']['content'];
        
        update_post_meta($transcript_id, '_transcript_summary', $summary);
        
        wp_send_json_success(['summary' => $summary]);
    }
    
    public static function save_highlight() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        $text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
        $start_time = isset($_POST['start_time']) ? floatval($_POST['start_time']) : null;
        $end_time = isset($_POST['end_time']) ? floatval($_POST['end_time']) : null;
        $color = isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '#ffeb3b';
        $note = isset($_POST['note']) ? sanitize_textarea_field($_POST['note']) : '';
        
        if (!$transcript_id || empty($text)) {
            wp_send_json_error('Invalid data');
        }
        
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_send_json_error('Permission denied');
        }
        
        $user_data = Transcribe_AI_Helpers::get_user_data();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'transcribe_ai_highlights';
        
        $result = $wpdb->insert($table_name, [
            'transcript_id' => $transcript_id,
            'user_id' => $user_data['is_logged_in'] ? $user_data['user_id'] : 0,
            'guest_id' => !$user_data['is_logged_in'] ? $user_data['guest_id'] : null,
            'highlight_text' => $text,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'color' => $color,
            'note' => $note
        ]);
        
        if ($result === false) {
            wp_send_json_error('Failed to save highlight');
        }
        
        wp_send_json_success([
            'id' => $wpdb->insert_id,
            'message' => 'Highlight saved'
        ]);
    }
    
    public static function get_highlights() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $transcript_id = isset($_POST['transcript_id']) ? absint($_POST['transcript_id']) : 0;
        if (!$transcript_id) {
            wp_send_json_error('Invalid transcript ID');
        }
        
        if (!Transcribe_AI_Helpers::user_can_access_transcript($transcript_id)) {
            wp_send_json_error('Permission denied');
        }
        
        $user_data = Transcribe_AI_Helpers::get_user_data();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'transcribe_ai_highlights';
        
        $query = "SELECT * FROM $table_name WHERE transcript_id = %d";
        $params = [$transcript_id];

        if ($user_data['is_logged_in']) {
            $query .= " AND user_id = %d";
            $params[] = $user_data['user_id'];
        } else {
            $query .= " AND guest_id = %s";
            $params[] = $user_data['guest_id'];
        }

        $query .= " ORDER BY start_time ASC";
        
        $highlights = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
        
        if (!empty($highlights)) {
            foreach ($highlights as $key => $highlight) {
                if (isset($highlight['highlight_text'])) {
                    $highlights[$key]['highlight_text'] = stripslashes($highlight['highlight_text']);
                }
                if (isset($highlight['note'])) {
                    $highlights[$key]['note'] = stripslashes($highlight['note']);
                }
            }
        }

        wp_send_json_success($highlights);
    }
    
    public static function delete_highlight() {
        if (!check_ajax_referer('transcribe_ai_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security token');
        }
        
        $highlight_id = isset($_POST['highlight_id']) ? absint($_POST['highlight_id']) : 0;
        if (!$highlight_id) {
            wp_send_json_error('Invalid highlight ID');
        }
        
        $user_data = Transcribe_AI_Helpers::get_user_data();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'transcribe_ai_highlights';
        
        $highlight = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $highlight_id
        ));
        
        if (!$highlight) {
            wp_send_json_error('Highlight not found');
        }
        
        if ($user_data['is_logged_in'] && $highlight->user_id != $user_data['user_id']) {
            wp_send_json_error('Permission denied');
        }
        if (!$user_data['is_logged_in'] && $highlight->guest_id != $user_data['guest_id']) {
            wp_send_json_error('Permission denied');
        }
        
        $result = $wpdb->delete($table_name, ['id' => $highlight_id]);
        
        if ($result === false) {
            wp_send_json_error('Failed to delete highlight');
        }
        
        wp_send_json_success(['message' => 'Highlight deleted']);
    }
    
    private static function ms_to_time($ms) {
        $seconds = $ms / 1000;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        
        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%02d:%02d', $minutes, $secs);
    }
    
    private static function ms_to_srt_time($ms) {
        $seconds = $ms / 1000;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $millis = round(($seconds - floor($seconds)) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }
    
    private static function ms_to_vtt_time($ms) {
        $seconds = $ms / 1000;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $millis = round(($seconds - floor($seconds)) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $millis);
    }
}

// ==========================================
// SETTINGS PAGE
// ==========================================
class Transcribe_AI_Settings {
    
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }
    
    public static function add_menu() {
        add_submenu_page(
            'edit.php?post_type=transcript',
            __('Transcribe AI Settings', 'transcribe-ai'),
            __('Settings', 'transcribe-ai'),
            'manage_options',
            'transcribe-ai-settings',
            [self::class, 'render_page']
        );
    }
    
    public static function register_settings() {
        register_setting('transcribe_ai_settings', 'transcribe_ai_assemblyai_key');
        register_setting('transcribe_ai_settings', 'transcribe_ai_deepl_key');
        register_setting('transcribe_ai_settings', 'transcribe_ai_openai_key');
    }
    
    public static function render_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('transcribe_ai_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="transcribe_ai_assemblyai_key">AssemblyAI API Key</label></th>
                        <td>
                            <input type="password" id="transcribe_ai_assemblyai_key" name="transcribe_ai_assemblyai_key" value="<?php echo esc_attr(get_option('transcribe_ai_assemblyai_key')); ?>" class="regular-text" />
                            <p class="description">Get your API key from <a href="https://www.assemblyai.com/" target="_blank">AssemblyAI</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="transcribe_ai_deepl_key">DeepL API Key (Optional)</label></th>
                        <td>
                            <input type="password" id="transcribe_ai_deepl_key" name="transcribe_ai_deepl_key" value="<?php echo esc_attr(get_option('transcribe_ai_deepl_key')); ?>" class="regular-text" />
                            <p class="description">For translation features. Get your API key from <a href="https://www.deepl.com/pro-api" target="_blank">DeepL</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="transcribe_ai_openai_key">OpenAI API Key (Optional)</label></th>
                        <td>
                            <input type="password" id="transcribe_ai_openai_key" name="transcribe_ai_openai_key" value="<?php echo esc_attr(get_option('transcribe_ai_openai_key')); ?>" class="regular-text" />
                            <p class="description">For AI summaries. Get your API key from <a href="https://platform.openai.com/" target="_blank">OpenAI</a></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Usage Limits</h2>
            <table class="widefat fixed" cellspacing="0">
                <thead><tr><th>User Type</th><th>Monthly Limit</th><th>Features</th></tr></thead>
                <tbody>
                    <tr><td><strong>Guest Users</strong></td><td>20 minutes</td><td>Basic transcription, temporary access</td></tr>
                    <tr><td><strong>Registered Users</strong></td><td>120 minutes</td><td>Save transcripts, edit, export, translate</td></tr>
                    <tr><td><strong>Premium Users</strong></td><td>Unlimited</td><td>All features, priority support</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

// Initialize the plugin
Transcribe_AI::get_instance();