<?php
/**
 * Plugin Name: چکاد - مسدودسازی دامنه‌ها
 * Description: افزونه‌ای برای جلوگیری از افت سرعت وردپرس در شرایط محدودیت‌های اینترنتی ایران
 * Version: 1.0.0
 * Plugin URI: https://github.com/mehdibinam/chekaad
 * Author: Chekaad Team
 * Author URI: https://github.com/mehdibinam/chekaad
 * License: GPL v2 or later
 * Text Domain: chekaad
 * Domain Path: /languages
 * GitHub Plugin URI: https://github.com/mehdibinam/chekaad
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}
// Define plugin constants
define('CHEKAAD_VERSION', '1.0.0');
define('CHEKAAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHEKAAD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHEKAAD_OPTION_KEY', 'chekaad_settings');
define('CHEKAAD_BLOCKED_DOMAINS_KEY', 'chekaad_blocked_domains');
define('CHEKAAD_WHITELIST_DOMAINS_KEY', 'chekaad_whitelist_domains');
define('CHEKAAD_GITHUB_REPO', 'https://api.github.com/repos/mehdibinam/chekaad/releases/latest');
define('CHEKAAD_GITHUB_REPO_URL', 'https://github.com/mehdibinam/');
define('CHEKAAD_MODE_KEY', 'chekaad_mode');

// Main plugin class
class Chekaad_Plugin {
    private static $instance = null;
    private $settings = [];
    private $blocked_domains = [];
    private $whitelist_domains = [];
    private $mode = 'blacklist';
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function __construct() {
        $this->load_settings();
        $this->register_hooks();
    }
    private function load_settings() {
        $this->settings = get_option(CHEKAAD_OPTION_KEY, $this->get_default_settings());
        $this->blocked_domains = get_option(CHEKAAD_BLOCKED_DOMAINS_KEY, []);
        $this->whitelist_domains = get_option(CHEKAAD_WHITELIST_DOMAINS_KEY, []);
        $this->mode = get_option(CHEKAAD_MODE_KEY, 'blacklist');
    }

    private function get_default_settings() {
        return [
            'blocking_mode' => 'js',
            'disable_gravatar' => false,
            'disable_telemetry' => false,
            'remove_dns_prefetch' => false,
            'block_dynamic_js' => false,
            'block_fetch_xhr' => false,
            'disable_wp_updates' => false,
            'whitelist_ir_domains' => false,
            'output_buffer_enabled' => false,
            'check_updates' => true,
            'github_repo_url' => 'https://github.com/mehdibinam/'
        ];
    }

    private function register_hooks() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);

        // AJAX handlers
        add_action('wp_ajax_chekaad_add_domain', [$this, 'ajax_add_domain']);
        add_action('wp_ajax_chekaad_delete_domain', [$this, 'ajax_delete_domain']);
        add_action('wp_ajax_chekaad_import_domains', [$this, 'ajax_import_domains']);
        add_action('wp_ajax_chekaad_export_domains', [$this, 'ajax_export_domains']);
        add_action('wp_ajax_chekaad_add_suggested_list', [$this, 'ajax_add_suggested_list']);
        add_action('wp_ajax_chekaad_update_settings', [$this, 'ajax_update_settings']);
        add_action('wp_ajax_chekaad_check_updates', [$this, 'ajax_check_updates']);
        add_action('wp_ajax_chekaad_switch_mode', [$this, 'ajax_switch_mode']);
        add_action('wp_ajax_chekaad_get_domains', [$this, 'ajax_get_domains']);

        // Server-side blocking
        if ($this->settings['blocking_mode'] === 'php' || $this->settings['blocking_mode'] === 'both') {
            add_filter('pre_http_request', [$this, 'block_http_requests'], 0, 3);
        }

        // Gravatar blocking
        if ($this->settings['disable_gravatar']) {
            add_filter('get_avatar', [$this, 'disable_gravatar']);
        }

        // Telemetry blocking
        if ($this->settings['disable_telemetry']) {
            $this->disable_telemetry();
        }

        // DNS Prefetch removal
        if ($this->settings['remove_dns_prefetch']) {
            add_filter('wp_resource_hints', [$this, 'remove_dns_prefetch'], 999, 2);
        }

        // Output buffer for HTML cleaning
        if ($this->settings['output_buffer_enabled']) {
            add_action('init', [$this, 'start_output_buffer'], 0);
            add_action('wp_loaded', [$this, 'start_output_buffer'], 0);
            add_action('template_redirect', [$this, 'start_output_buffer'], 0);
            add_action('admin_init', [$this, 'start_output_buffer'], 0);
        }

        // Client-side (JavaScript) blocking
        if ($this->settings['blocking_mode'] === 'js' || $this->settings['blocking_mode'] === 'both') {
            add_action('wp_footer', [$this, 'inject_js_blocking'], 1);
            add_action('admin_footer', [$this, 'inject_js_blocking'], 1);
            add_action('admin_head', [$this, 'inject_js_blocking'], 1);
        }

        // Disable WordPress auto-updates
        if ($this->settings['disable_wp_updates']) {
            $this->disable_wp_updates();
        }

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_admin_menu() {
        add_menu_page(
            'چکاد - مسدودسازی دامنه‌ها',
            'چکاد',
            'manage_options',
            'chekaad',
            [$this, 'render_admin_page'],
            'dashicons-shield-alt',
            80
        );
    }

    public function add_plugin_action_links($links) {
        $settings_url = admin_url('admin.php?page=chekaad');
        $settings_link = '<a href="' . esc_url($settings_url) . '">تنظیمات</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function enqueue_admin_assets($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_chekaad') {
            return;
        }

        wp_enqueue_style('chekaad-tailwind', 'https://cdn.tailwindcss.com', [], CHEKAAD_VERSION);
        wp_add_inline_style('chekaad-tailwind', $this->get_inline_styles());

        wp_enqueue_script('chekaad-admin', CHEKAAD_PLUGIN_URL . 'admin.js', ['jquery'], CHEKAAD_VERSION, true);

        wp_localize_script('chekaad-admin', 'chekaadData', [
            'nonce' => wp_create_nonce('chekaad_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'mode' => $this->mode,
        ]);
    }

    private function get_inline_styles() {
        return '
        :root {
            --primary: #f8f9fa;
            --secondary: #e9ecef;
            --accent: #8b5cf6;
            --accent-dark: #6d28d9;
            --text: #1a202c;
            --text-light: #4a5568;0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body.chekaad-admin {
            background: #3858e9;
            font-family: "Segoe UI", "Tahoma", "IRANSans", sans-serif;
            direction: rtl;
            color: var(--text);
            min-height: 100vh;
        }

        .chekaad-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 10px;
        }

        .chekaad-header {
            background: linear-gradient(#a3ffb9,#1ef74d 0%,#fff 50%,#f8f9fa 50%,#ff6464 80%,#ff2f2f);
            color: var(--text);
            padding: 40px 35px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            position: relative;
        }

        .chekaad-header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.5px;
        }

        .chekaad-header p {
            margin: 12px 0 0 0;
            color: var(--text-light);
            font-size: 15px;
            font-weight: 400;
        }

        .chekaad-main-content {
            display: grid;
            grid-template-columns: 1fr 600px;
            gap: 20px;
        }

        .chekaad-main-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .chekaad-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
            border: 0;
        }

        .chekaad-card {
            background: #fff;
            border-radius: 15px;
            border: 0;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            transition: all 0.3s ease;
            position: relative;
        }

        .chekaad-card:hover {
            transform: translateY(-3px);
        }

        .chekaad-sidebar .chekaad-card {
            padding: 20px;
            margin-bottom: 0;
        }

        .chekaad-card h2 {
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            position: relative;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .chekaad-sidebar .chekaad-card h2 {
            font-size: 16px;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .chekaad-card h2::before {
            content: "";
            display: inline-block;
            width: 6px;
            height: 24px;
            background: #3858e9;
            border-radius: 3px;
            margin-left: 12px;
            box-shadow: 0 2px 4px rgba(139, 92, 246, 0.3);
        }

        .chekaad-sidebar .chekaad-card h2::before {
            width: 4px;
            height: 18px;
        }

        .chekaad-form-group {
            margin-bottom: 20px;
        }

        .chekaad-sidebar .chekaad-form-group {
            margin-bottom: 12px;
        }

        .chekaad-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }

        .chekaad-form-group input[type="text"],
        .chekaad-form-group select,
        .chekaad-form-group input[type="file"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 0;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
            background: #f0f4f8;
            color: var(--text);
        }

        .chekaad-sidebar .chekaad-form-group input {
            padding: 10px 12px;
            font-size: 13px;
        }

        .chekaad-form-group input[type="text"]::placeholder,
        .chekaad-form-group select {
            color: var(--text-light);
        }

        .chekaad-form-group input[type="text"]:focus,
        .chekaad-form-group select:focus,
        .chekaad-form-group input[type="file"]:focus {
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05), 0 0 0 3px rgba(139, 92, 246, 0.1), 0 1px 0 rgba(255, 255, 255, 0.5);
            background: #ffffff;
        }
        .chekaad-btn {
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .chekaad-sidebar .chekaad-btn {
            width: 100%;
            justify-content: center;
            padding: 10px 14px;
            font-size: 13px;
            border: 0;
        }
        .chekaad-btn-primary {
            background: #3858e9;
            color: white;
            border-color: var(--accent-dark);
        }

        .chekaad-btn-primary:hover {
            transform: translateY(-3px);
        }
        .chekaad-btn-primary:active {
            transform: translateY(0);
        }
        .chekaad-btn-secondary {
            background: #3858e9;
            color: #fff;
            border: 0;
        }
        .chekaad-btn-secondary:hover {
            background: #3858e9;
        }
        .chekaad-btn-danger {
            background: #dc2626;
            color: #fff;
            padding: 7px 14px;
            font-size: 12px;
            border: 0;
        }

        .chekaad-btn-danger:hover {
            background: #3858e9;
            transform: translateY(-3px);
        }

        .chekaad-btn-success {
            background: green;
            color: #fff;
            border: 0;
        }

        .chekaad-btn-success:hover {
            transform: translateY(-3px);
        }

        .chekaad-table {
            width: 100%;
            border-collapse: collapse;
        }

        .chekaad-table thead {
            background: #3858e9;
        }

        .chekaad-table th {
            padding: 16px;
            text-align: right;
            font-weight: 700;
            color: #fff;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .chekaad-table td {
            padding: 14px 16px;
            font-size: 14px;
            color: var(--text-light);
        }

        .chekaad-table tbody tr {
            transition: all 0.2s ease;
        }

        .chekaad-table tbody tr:hover {
            background: #f9fafb;
        }

        .chekaad-message {
            padding: 16px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: none;
            border-right: 4px solid;
            animation: slideDown 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .chekaad-message.show {
            display: block;
        }

        .chekaad-message.success {
            background: #d1fae5;
            color: #047857;
            border-right-color: var(--success);
        }

        .chekaad-message.error {
            background: #fee2e2;
            color: #dc2626;
            border-right-color: var(--danger);
        }

        .chekaad-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
            padding: 14px;
            background: #f0f4f8;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .chekaad-checkbox:hover {
            transform: translateY(-3px);
        }

        .chekaad-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--accent);
            border-radius: 4px;
            margin-top: 2px;
            flex-shrink: 0;
            border: 0;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        .chekaad-checkbox label {
            margin: 0px;
            cursor: pointer;
            font-weight: 600;
            user-select: none;
            color: var(--text);
        }

        .chekaad-list-container {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 12px;
            background: #f0f4f8;
            ::-webkit-scrollbar {width: 0px;}
        }

        .chekaad-list-container::-webkit-scrollbar {
            width: 8px;
        }

        .chekaad-list-container::-webkit-scrollbar-track {
            background: #f0f4f8;
            border-radius: 10px;
        }

        .chekaad-list-container::-webkit-scrollbar-thumb {
            background: #f0f4f8;
            border-radius: 10px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }

        .chekaad-list-container::-webkit-scrollbar-thumb:hover {
            background: #3858e9;
        }

        .chekaad-mode-toggle {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .chekaad-mode-btn {
            flex: 1;
            padding: 12px 16px;
            background: #f0f4f8;
            color: var(--text-light);
            border-radius: 10px;
            border: 0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .chekaad-mode-btn.active {
            background: #673AB7;
            color: #fff;
        }

        .chekaad-mode-btn:hover:not(.active) {
            color: var(--accent);
            background: #f0f4f8;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }

        .chekaad-list-section {
            display: none;
        }

        .chekaad-list-section.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .chekaad-input-group {
            display: flex;
            gap: 12px;
        }

        .chekaad-input-group input {
            flex: 1;
        }

        .chekaad-input-group button {
            flex-shrink: 0;
            border: 0;
        }

        .chekaad-help-text {
            background: #fef8ee;
            border-right: 2px solid #f0b849;
            padding: 10px;
            border-radius: 3px;
            font-size: 12px;
            color: #000;
            margin-top: 10px;
        }

        .chekaad-status-message {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            display: none;
            border-right: 4px solid;
            animation: slideDown 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .chekaad-status-message.show {
            display: block;
        }

        .chekaad-status-message.success {
            background: #d1fae5;
            color: #047857;
            border-right-color: var(--success);
        }

        .chekaad-status-message.loading {
            background: #fef3c7;
            color: #92400e;
            border-right-color: var(--warning);
        }

        .chekaad-sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .chekaad-sidebar-section p {
            font-size: 13px;
            color: var(--text-light);
            margin: 0;
            line-height: 1.5;
        }
        small {
            display: block;
            color: var(--text-light);
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 1024px) {
            .chekaad-main-content {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .chekaad-container {
                padding: 10px;
            }

            .chekaad-header {
                padding: 25px 20px;
            }

            .chekaad-header h1 {
                font-size: 24px;
            }

            .chekaad-card {
                padding: 20px;
            }

            .chekaad-mode-toggle {
                flex-direction: column;
            }

            .chekaad-mode-btn {
                width: 100%;
            }

            .chekaad-main-content {
                grid-template-columns: 1fr;
            }
        }
        ';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی رد شد.');
        }

        ?>
        <div class="chekaad-admin">
            <div class="chekaad-container">
                <div class="chekaad-header">
                    <h1>چکاد - مسدودسازی دامنه‌ها</h1>
                    <p>جلوگیری از افت سرعت وردپرس در شرایط محدودیت‌های اینترنتی ایران</p>
                </div>

                <div id="chekaad-message" class="chekaad-message"></div>

                <div class="chekaad-main-content">
                    <!-- Main Column -->
                    <div class="chekaad-main-column">
                        <!-- Domain Management -->
                        <div class="chekaad-card">
                            <h2>مدیریت دامنه‌ها</h2>

                            <div class="chekaad-mode-toggle">
                                <button class="chekaad-mode-btn <?php echo ($this->mode === 'blacklist') ? 'active' : ''; ?>" data-mode="blacklist">لیست سیاه</button>
                                <button class="chekaad-mode-btn <?php echo ($this->mode === 'whitelist') ? 'active' : ''; ?>" data-mode="whitelist">لیست سفید</button>
                            </div>

                            <!-- Blacklist Section -->
                            <div class="chekaad-list-section <?php echo ($this->mode === 'blacklist') ? 'active' : ''; ?>" id="list-blacklist">
                                <div class="chekaad-form-group">
                                    <label for="chekaad-domain-input">افزودن دامنه (لیست سیاه)</label>
                                    <div class="chekaad-input-group">
                                        <input type="text" id="chekaad-domain-input" placeholder="example.com یا *site.com" />
                                        <button class="chekaad-btn chekaad-btn-primary" id="chekaad-add-domain-btn">افزودن</button>
                                    </div>
                                    <small>می‌توانید دامنه‌ها را با یا بدون http/https و با یا بدون www وارد کنید. از علامت ستاره (*) نیز می‌توانید استفاده کنید.</small>
                                    <div class="chekaad-help-text">
                                        <strong>توضیح:</strong> در حالت لیست سیاه، تمام درخواست‌های خارجی مجاز هستند و فقط دامنه‌های موجود در این لیست مسدود می‌شوند. این حالت برای مسدودسازی دامنه‌های خاص کند مناسب است.
                                    </div>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <button class="chekaad-btn chekaad-btn-secondary" id="chekaad-suggested-list-btn">افزودن لیست پیشنهادی</button>
                                    <div id="chekaad-suggested-status" class="chekaad-status-message"></div>
                                </div>

                                <div class="chekaad-list-container">
                                    <table class="chekaad-table">
                                        <thead>
                                            <tr>
                                                <th>دامنه</th>
                                                <th style="width: 100px;">عملیات</th>
                                            </tr>
                                        </thead>
                                        <tbody id="chekaad-blocked-table-body">
                                            <?php $this->render_blocked_domains_table(); ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Whitelist Section -->
                            <div class="chekaad-list-section <?php echo ($this->mode === 'whitelist') ? 'active' : ''; ?>" id="list-whitelist">
                                <div class="chekaad-form-group">
                                    <label for="chekaad-whitelist-input">افزودن دامنه (لیست سفید)</label>
                                    <div class="chekaad-input-group">
                                        <input type="text" id="chekaad-whitelist-input" placeholder="example.ir یا *site.ir" />
                                        <button class="chekaad-btn chekaad-btn-primary" id="chekaad-add-whitelist-btn">افزودن</button>
                                    </div>
                                    <small>می‌توانید دامنه‌ها را با یا بدون http/https و با یا بدون www وارد کنید. از علامت ستاره (*) نیز می‌توانید استفاده کنید.</small>
                                    <div class="chekaad-help-text">
                                        <strong>توضیح:</strong> در حالت لیست سفید، تمام درخواست‌های خارجی مسدود هستند و فقط دامنه‌های موجود در این لیست مجاز می‌شوند. این حالت برای محدودسازی شدید درخواست‌های خارجی مناسب است.
                                    </div>
                                </div>

                                <div class="chekaad-list-container">
                                    <table class="chekaad-table">
                                        <thead>
                                            <tr>
                                                <th>دامنه</th>
                                                <th style="width: 100px;">عملیات</th>
                                            </tr>
                                        </thead>
                                        <tbody id="chekaad-whitelist-table-body">
                                            <?php $this->render_whitelist_domains_table(); ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Settings -->
                        <div class="chekaad-card">
                            <h2>تنظیمات</h2>
                            <form id="chekaad-settings-form">
                                <div class="chekaad-settings-grid">
                                    <!-- Left Column -->
                                    <div>
                                        <div class="chekaad-form-group">
                                            <label for="chekaad-blocking-mode">حالت مسدودسازی</label>
                                            <div style="display: flex; gap: 10px; margin-top: 8px;">
                                                <button type="button" class="chekaad-mode-btn" data-blocking-mode="php" style="flex: 1; padding: 10px 12px; font-size: 13px; <?php echo ($this->settings['blocking_mode'] === 'php') ? 'background: #3858e9; color: white;' : ''; ?>">سرور</button>
                                                <button type="button" class="chekaad-mode-btn" data-blocking-mode="js" style="flex: 1; padding: 10px 12px; font-size: 13px; <?php echo ($this->settings['blocking_mode'] === 'js') ? 'background: #3858e9; color: white;' : ''; ?>">مرورگر</button>
                                                <button type="button" class="chekaad-mode-btn" data-blocking-mode="both" style="flex: 1; padding: 10px 12px; font-size: 13px; <?php echo ($this->settings['blocking_mode'] === 'both') ? 'background: #3858e9; color: white;' : ''; ?>">هر دو</button>
                            </div>
                            <input type="hidden" id="chekaad-blocking-mode" value="<?php echo esc_attr($this->settings['blocking_mode']); ?>" />
                                            <div class="chekaad-help-text">
                                                <strong>سمت سرور:</strong> درخواست‌های سرور را مسدود می‌کند. <strong>سمت مرورگر:</strong> درخواست‌های مرورگر را مسدود می‌کند. <strong>هر دو:</strong>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-disable-gravatar" <?php checked($this->settings['disable_gravatar']); ?> />
                                            <div>
                                                <label for="chekaad-disable-gravatar">غیرفعال کردن Gravatar</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    Gravatar سرویسی است که تصاویر پروفایل کاربران را از سرورهای خارجی بارگذاری می‌کند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-disable-telemetry" <?php checked($this->settings['disable_telemetry']); ?> />
                                            <div>
                                                <label for="chekaad-disable-telemetry">غیرفعال کردن Telemetry وردپرس</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    Telemetry ابزاری است که وردپرس برای جمع‌آوری اطلاعات استفاده سایت استفاده می‌کند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-remove-dns-prefetch" <?php checked($this->settings['remove_dns_prefetch']); ?> />
                                            <div>
                                                <label for="chekaad-remove-dns-prefetch">حذف DNS Prefetch</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    DNS Prefetch مرورگر را برای اتصال سریع به دامنه‌های خارجی آماده می‌کند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-block-dynamic-js" <?php checked($this->settings['block_dynamic_js']); ?> />
                                            <div>
                                                <label for="chekaad-block-dynamic-js">مسدودسازی اسکریپت‌های پویا</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    اسکریپت‌های پویا کدهایی هستند که در زمان اجرا بارگذاری می‌شوند.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right Column -->
                                    <div>
                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-block-fetch-xhr" <?php checked($this->settings['block_fetch_xhr']); ?> />
                                            <div>
                                                <label for="chekaad-block-fetch-xhr">مسدودسازی Fetch و XHR</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    Fetch و XHR روش‌های ارسال درخواست‌های شبکه از طریق JavaScript هستند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-output-buffer-enabled" <?php checked($this->settings['output_buffer_enabled']); ?> />
                                            <div>
                                                <label for="chekaad-output-buffer-enabled">فعال‌سازی Output Buffer</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    Output Buffer صفحه HTML را پاکسازی می‌کند و درخواست‌های دامنه‌های مسدود را حذف می‌کند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-disable-wp-updates" <?php checked($this->settings['disable_wp_updates']); ?> />
                                            <div>
                                                <label for="chekaad-disable-wp-updates">غیرفعال کردن به‌روزرسانی‌های خودکار</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    وردپرس به صورت خودکار برای به‌روزرسانی‌های هسته، افزونه و تم بررسی می‌کند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-whitelist-ir-domains" <?php checked($this->settings['whitelist_ir_domains']); ?> />
                                            <div>
                                                <label for="chekaad-whitelist-ir-domains">اجازه دسترسی به دامنه‌های ایرانی</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    این گزینه تمام دامنه‌های ایرانی (*.ir) را از مسدودسازی محافظت می‌کند.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="chekaad-checkbox">
                                            <input type="checkbox" id="chekaad-check-updates" <?php checked($this->settings['check_updates']); ?> />
                                            <div>
                                                <label for="chekaad-check-updates">بررسی خودکار به‌روزرسانی‌ها</label>
                                                <div class="chekaad-help-text" style="margin-top: 6px;">
                                                    این گزینه افزونه را برای به‌روزرسانی‌های جدید از مخزن GitHub بررسی می‌کند.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="chekaad-btn chekaad-btn-success" style="margin-top: 0px;">ذخیره تنظیمات</button>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="chekaad-sidebar">
                        <!-- GitHub Updates -->
                        <div class="chekaad-card">
                            <h2>به‌روزرسانی‌ها</h2>
                            <div class="chekaad-sidebar-section">
                                <p>بررسی نسخه‌های جدید از مخزن GitHub</p>
                                <button class="chekaad-btn chekaad-btn-secondary" id="chekaad-check-updates-btn">بررسی آپدیت افزونه</button>
                                <div id="chekaad-updates-status" class="chekaad-status-message"></div>
                            </div>
                        </div>

                        <!-- Import/Export -->
                        <div class="chekaad-card">
                            <h2>درون‌ریزی و برون‌بری</h2>
                            <div class="chekaad-sidebar-section">
                                <p>بارگذاری فایل JSON</p>
                                <div class="chekaad-form-group">
                                    <input type="file" id="chekaad-import-file" accept=".json" />
                                </div>
                                <button class="chekaad-btn chekaad-btn-primary" id="chekaad-import-btn">درون‌ریزی</button>
                            </div>

                            <div class="chekaad-sidebar-section" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e2e8f0;">
                                <p>دانلود لیست  JSON</p>
                                <button class="chekaad-btn chekaad-btn-primary" id="chekaad-export-btn">برون‌بری</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Switch between blacklist and whitelist modes
            $('.chekaad-mode-btn[data-mode]').on('click', function() {
                const mode = $(this).data('mode');
                $('.chekaad-mode-btn[data-mode]').removeClass('active');
                $('[data-mode="' + mode + '"]').addClass('active');
                $('.chekaad-list-section').removeClass('active');
                $('#list-' + mode).addClass('active');
                
                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_switch_mode',
                    nonce: chekaadData.nonce,
                    mode: mode
                });
            });

            // Switch blocking mode
            $('[data-blocking-mode]').on('click', function(e) {
                e.preventDefault();
                const mode = $(this).data('blocking-mode');
                $('[data-blocking-mode]').css({
                    'background': '#3858e9',
                    'color': 'var(--text-light)'
                });
                $(this).css({
                    'background': '#3858e9',
                    'color': 'white'
                });
                $('#chekaad-blocking-mode').val(mode);
            });

            $('#chekaad-add-domain-btn').on('click', function() {
                const domain = $('#chekaad-domain-input').val().trim();
                if (!domain) {
                    chekaadShowMessage('لطفاً یک دامنه وارد کنید.', 'error');
                    return;
                }
                chekaadAddDomain(domain, 'blacklist');
            });

            $('#chekaad-add-whitelist-btn').on('click', function() {
                const domain = $('#chekaad-whitelist-input').val().trim();
                if (!domain) {
                    chekaadShowMessage('لطفاً یک دامنه وارد کنید.', 'error');
                    return;
                }
                chekaadAddDomain(domain, 'whitelist');
            });

            $(document).on('click', '.chekaad-delete-btn', function() {
                const domain = $(this).data('domain');
                const type = $(this).data('type');
                if (confirm('آیا مطمئن هستید؟')) {
                    chekaadDeleteDomain(domain, type);
                }
            });

            $('#chekaad-suggested-list-btn').on('click', function() {
                chekaadAddSuggestedList();
            });

            $('#chekaad-settings-form').on('submit', function(e) {
                e.preventDefault();
                chekaadUpdateSettings();
            });

            $('#chekaad-export-btn').on('click', function() {
                chekaadExportDomains();
            });

            $('#chekaad-import-btn').on('click', function() {
                const file = $('#chekaad-import-file')[0].files[0];
                if (!file) {
                    chekaadShowMessage('لطفاً یک فایل انتخاب کنید.', 'error');
                    return;
                }
                chekaadImportDomains(file);
            });

            $('#chekaad-check-updates-btn').on('click', function() {
                chekaadCheckUpdates();
            });

            window.chekaadShowMessage = function(message, type) {
                const $msg = $('#chekaad-message');
                $msg.removeClass('success error').addClass(type).text(message).addClass('show');
                setTimeout(() => $msg.removeClass('show'), 4000);
            };

            window.chekaadShowStatusMessage = function(message, type, elementId) {
                const $msg = $('#' + elementId);
                $msg.removeClass('success loading error').addClass(type).text(message).addClass('show');
                if (type !== 'loading') {
                    setTimeout(() => $msg.removeClass('show'), 4000);
                }
            };

            window.chekaadAddDomain = function(domain, type) {
                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_add_domain',
                    nonce: chekaadData.nonce,
                    domain: domain,
                    type: type
                }, function(response) {
                    if (response.success) {
                        chekaadShowMessage('دامنه اضافه شد.', 'success');
                        if (type === 'blacklist') {
                            $('#chekaad-domain-input').val('');
                        } else {
                            $('#chekaad-whitelist-input').val('');
                        }
                        chekaadRefreshDomainsList(type);
                    } else {
                        chekaadShowMessage(response.data.message, 'error');
                    }
                });
            };

            window.chekaadDeleteDomain = function(domain, type) {
                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_delete_domain',
                    nonce: chekaadData.nonce,
                    domain: domain,
                    type: type
                }, function(response) {
                    if (response.success) {
                        chekaadShowMessage('دامنه حذف شد.', 'success');
                        chekaadRefreshDomainsList(type);
                    } else {
                        chekaadShowMessage(response.data.message, 'error');
                    }
                });
            };

            window.chekaadRefreshDomainsList = function(type) {
                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_get_domains',
                    nonce: chekaadData.nonce,
                    type: type
                }, function(response) {
                    if (response.success) {
                        const tableBody = type === 'blacklist' ? '#chekaad-blocked-table-body' : '#chekaad-whitelist-table-body';
                        
                        if (response.data.domains.length === 0) {
                            $(tableBody).html('<tr><td colspan="2" style="text-align: center; color: var(--text-light);">هیچ دامنه‌ای اضافه نشده است.</td></tr>');
                        } else {
                            let html = '';
                            response.data.domains.forEach(domain => {
                                html += '<tr><td>' + domain + '</td><td><button class="chekaad-btn chekaad-btn-danger chekaad-delete-btn" data-domain="' + domain + '" data-type="' + type + '">حذف</button></td></tr>';
                            });
                            $(tableBody).html(html);
                        }
                    }
                });
            };

            window.chekaadAddSuggestedList = function() {
                const suggestedDomains = [
                    'gravatar.com', 'googleapis.com', 'gstatic.com', 'googletagmanager.com',
                    'google-analytics.com', 'recaptcha.net', 'cloudflare.com', 'jsdelivr.net',
                    'unpkg.com', 'github.com', 'github.io', 'wordpress.org', 'fontawesome.com'
                ];

                chekaadShowStatusMessage('درحال افزودن لیست پیشنهادی...', 'loading', 'chekaad-suggested-status');

                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_add_suggested_list',
                    nonce: chekaadData.nonce,
                    domains: suggestedDomains
                }, function(response) {
                    if (response.success) {
                        chekaadShowStatusMessage('لیست پیشنهادی اضافه شد.', 'success', 'chekaad-suggested-status');
                        chekaadRefreshDomainsList('blacklist');
                    } else {
                        chekaadShowStatusMessage(response.data.message, 'error', 'chekaad-suggested-status');
                    }
                });
            };

            window.chekaadUpdateSettings = function() {
                const settings = {
                    blocking_mode: $('#chekaad-blocking-mode').val(),
                    disable_gravatar: $('#chekaad-disable-gravatar').is(':checked'),
                    disable_telemetry: $('#chekaad-disable-telemetry').is(':checked'),
                    remove_dns_prefetch: $('#chekaad-remove-dns-prefetch').is(':checked'),
                    block_dynamic_js: $('#chekaad-block-dynamic-js').is(':checked'),
                    block_fetch_xhr: $('#chekaad-block-fetch-xhr').is(':checked'),
                    output_buffer_enabled: $('#chekaad-output-buffer-enabled').is(':checked'),
                    disable_wp_updates: $('#chekaad-disable-wp-updates').is(':checked'),
                    whitelist_ir_domains: $('#chekaad-whitelist-ir-domains').is(':checked'),
                    check_updates: $('#chekaad-check-updates').is(':checked'),
                    github_repo_url: 'https://github.com/mehdibinam/'
                };

                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_update_settings',
                    nonce: chekaadData.nonce,
                    settings: settings
                }, function(response) {
                    if (response.success) {
                        chekaadShowMessage('تنظیمات ذخیره شد.', 'success');
                    } else {
                        chekaadShowMessage(response.data.message, 'error');
                    }
                });
            };

            window.chekaadExportDomains = function() {
                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_export_domains',
                    nonce: chekaadData.nonce
                }, function(response) {
                    if (response.success) {
                        const dataStr = JSON.stringify(response.data, null, 2);
                        const dataBlob = new Blob([dataStr], { type: 'application/json' });
                        const url = URL.createObjectURL(dataBlob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = 'chekaad-domains.json';
                        link.click();
                        chekaadShowMessage('فایل دانلود شد.', 'success');
                    }
                });
            };

            window.chekaadImportDomains = function(file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const data = JSON.parse(e.target.result);
                        $.post(chekaadData.ajaxurl, {
                            action: 'chekaad_import_domains',
                            nonce: chekaadData.nonce,
                            domains: data
                        }, function(response) {
                            if (response.success) {
                                chekaadShowMessage('لیست درون‌ریزی شد.', 'success');
                                chekaadRefreshDomainsList('blacklist');
                                $('#chekaad-import-file').val('');
                            } else {
                                chekaadShowMessage(response.data.message, 'error');
                            }
                        });
                    } catch (err) {
                        chekaadShowMessage('فایل نامعتبر است.', 'error');
                    }
                };
                reader.readAsText(file);
            };

            window.chekaadCheckUpdates = function() {
                chekaadShowStatusMessage('درحال بررسی...', 'loading', 'chekaad-updates-status');

                $.post(chekaadData.ajaxurl, {
                    action: 'chekaad_check_updates',
                    nonce: chekaadData.nonce,
                    repo_url: 'mehdibinam/chekaad'
                }, function(response) {
                    if (response.success) {
                        chekaadShowStatusMessage(response.data.message, 'success', 'chekaad-updates-status');
                    } else {
                        chekaadShowStatusMessage(response.data.message, 'error', 'chekaad-updates-status');
                    }
                });
            };
        });
        </script>
        <?php
    }

    private function render_blocked_domains_table() {
        if (empty($this->blocked_domains)) {
            echo '<tr><td colspan="2" style="text-align: center; color: var(--text-light);">هیچ دامنه‌ای اضافه نشده است.</td></tr>';
            return;
        }

        foreach ($this->blocked_domains as $domain) {
            echo '<tr>';
            echo '<td>' . esc_html($domain) . '</td>';
            echo '<td><button class="chekaad-btn chekaad-btn-danger chekaad-delete-btn" data-domain="' . esc_attr($domain) . '" data-type="blacklist">حذف</button></td>';
            echo '</tr>';
        }
    }

    private function render_whitelist_domains_table() {
        if (empty($this->whitelist_domains)) {
            echo '<tr><td colspan="2" style="text-align: center; color: var(--text-light);">هیچ دامنه‌ای اضافه نشده است.</td></tr>';
            return;
        }

        foreach ($this->whitelist_domains as $domain) {
            echo '<tr>';
            echo '<td>' . esc_html($domain) . '</td>';
            echo '<td><button class="chekaad-btn chekaad-btn-danger chekaad-delete-btn" data-domain="' . esc_attr($domain) . '" data-type="whitelist">حذف</button></td>';
            echo '</tr>';
        }
    }

    public function block_http_requests($pre, $args, $url) {
        if ($this->mode === 'whitelist') {
            if ($this->is_domain_allowed($url)) {
                return $pre;
            }
            return new WP_Error('chekaad_blocked', 'Request blocked.');
        } else {
            if ($this->is_domain_blocked($url)) {
                return new WP_Error('chekaad_blocked', 'Request blocked.');
            }
            return $pre;
        }
    }

    private function is_domain_blocked($url) {
        foreach ($this->blocked_domains as $domain) {
            if ($this->domain_matches($url, $domain)) {
                return true;
            }
        }
        return false;
    }

    private function is_domain_allowed($url) {
        foreach ($this->whitelist_domains as $domain) {
            if ($this->domain_matches($url, $domain)) {
                return true;
            }
        }
        return false;
    }

    private function domain_matches($url, $pattern) {
        $pattern = strtolower(trim($pattern, '/'));
        $url = strtolower($url);

        $pattern = preg_replace('#^https?://#', '', $pattern);
        $url = preg_replace('#^https?://#', '', $url);

        if (strpos($pattern, '*') === 0) {
            $pattern = substr($pattern, 1);
            return strpos($url, $pattern) !== false;
        }

        $pattern = preg_replace('#^www\.#', '', $pattern);
        $url = preg_replace('#^www\.#', '', $url);

        if (strpos($url, $pattern) !== false) {
            return true;
        }

        if (preg_match('#' . preg_quote($pattern, '#') . '($|/)#', $url)) {
            return true;
        }

        return false;
    }

    public function disable_gravatar($avatar) {
        return '';
    }

    private function disable_telemetry() {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'api.wordpress.org') !== false || 
                strpos($url, 'telemetry.wordpress.com') !== false) {
                return new WP_Error('chekaad_blocked', 'Telemetry blocked.');
            }
            return $pre;
        }, 0, 3);
    }

    public function remove_dns_prefetch($urls, $relation_type) {
        if ($relation_type !== 'dns-prefetch') {
            return $urls;
        }

        foreach ($urls as $key => $url) {
            if ($this->mode === 'whitelist') {
                if (!$this->is_domain_allowed($url)) {
                    unset($urls[$key]);
                }
            } else {
                if ($this->is_domain_blocked($url)) {
                    unset($urls[$key]);
                }
            }
        }

        return $urls;
    }

    public function start_output_buffer() {
        static $started = false;
        if (!$started) {
            ob_start([$this, 'clean_html_output']);
            $started = true;
        }
    }

    public function clean_html_output($html) {
        if (stripos($html, '<html') === false) {
            return $html;
        }

        if ($this->mode === 'whitelist') {
            foreach ($this->blocked_domains as $domain) {
                $pattern = '#https?://([a-z0-9\-]+\.)*' . preg_quote($domain, '#') . '[^\s"\']*#i';
                $html = preg_replace($pattern, '', $html);
            }
        } else {
            foreach ($this->blocked_domains as $domain) {
                $pattern = '#https?://([a-z0-9\-]+\.)*' . preg_quote($domain, '#') . '[^\s"\']*#i';
                $html = preg_replace($pattern, '', $html);
            }
        }

        return $html;
    }

    public function inject_js_blocking() {
        $blocked = $this->blocked_domains;
        $whitelisted = $this->whitelist_domains;
        $mode = $this->mode;
        $domains_json = json_encode($blocked);
        $whitelist_json = json_encode($whitelisted);
        ?>
        <script>
        (function() {
            const blocked = <?php echo $domains_json; ?>;
            const whitelisted = <?php echo $whitelist_json; ?>;
            const mode = '<?php echo $mode; ?>';

            function domainMatches(url, pattern) {
                url = url.toLowerCase();
                pattern = pattern.toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '');
                
                if (pattern.startsWith('*')) {
                    return url.includes(pattern.substring(1));
                }
                
                return url.includes(pattern);
            }

            function isBlocked(url) {
                if (mode === 'whitelist') {
                    for (let domain of whitelisted) {
                        if (domainMatches(url, domain)) return false;
                    }
                    return true;
                } else {
                    for (let domain of blocked) {
                        if (domainMatches(url, domain)) return true;
                    }
                    return false;
                }
            }

            const originalCreate = document.createElement;
            document.createElement = function(tag) {
                const el = originalCreate.call(document, tag);
                if (tag === 'script') {
                    Object.defineProperty(el, 'src', {
                        set: function(value) {
                            if (isBlocked(value)) return;
                            this.setAttribute('src', value);
                        }
                    });
                }
                return el;
            };

            const originalFetch = window.fetch;
            window.fetch = function(url, options) {
                if (typeof url === 'string' && isBlocked(url)) {
                    return Promise.reject('Request blocked.');
                }
                return originalFetch.apply(this, arguments);
            };

            const originalOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function(method, url) {
                if (typeof url === 'string' && isBlocked(url)) {
                    return;
                }
                return originalOpen.apply(this, arguments);
            };
        })();
        </script>
        <?php
    }

    private function disable_wp_updates() {
        add_filter('pre_transient_update_core', '__return_zero');
        add_filter('pre_transient_update_plugins', '__return_zero');
        add_filter('pre_transient_update_themes', '__return_zero');
    }

    // AJAX: Add domain
    public function ajax_add_domain() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $domain = trim(sanitize_text_field($_POST['domain'] ?? ''));
        $type = sanitize_text_field($_POST['type'] ?? 'blacklist');

        if (!$domain) {
            wp_send_json_error(['message' => 'دامنه نامعتبر است.']);
        }

        $domain = $this->normalize_domain($domain);

        $key = ($type === 'whitelist') ? CHEKAAD_WHITELIST_DOMAINS_KEY : CHEKAAD_BLOCKED_DOMAINS_KEY;
        $domains = get_option($key, []);

        if (in_array($domain, $domains)) {
            wp_send_json_error(['message' => 'دامنه قبلاً وجود دارد.']);
        }

        $domains[] = $domain;
        update_option($key, $domains);

        wp_send_json_success(['message' => 'دامنه اضافه شد.']);
    }

    // AJAX: Delete domain
    public function ajax_delete_domain() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $domain = trim(sanitize_text_field($_POST['domain'] ?? ''));
        $type = sanitize_text_field($_POST['type'] ?? 'blacklist');

        $key = ($type === 'whitelist') ? CHEKAAD_WHITELIST_DOMAINS_KEY : CHEKAAD_BLOCKED_DOMAINS_KEY;
        $domains = get_option($key, []);

        $domains = array_filter($domains, function($d) use ($domain) {
            return $d !== $domain;
        });

        update_option($key, array_values($domains));

        wp_send_json_success(['message' => 'دامنه حذف شد.']);
    }

    // AJAX: Get domains
    public function ajax_get_domains() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $type = sanitize_text_field($_POST['type'] ?? 'blacklist');
        $key = ($type === 'whitelist') ? CHEKAAD_WHITELIST_DOMAINS_KEY : CHEKAAD_BLOCKED_DOMAINS_KEY;
        $domains = get_option($key, []);

        wp_send_json_success(['domains' => $domains]);
    }

    // AJAX: Import domains
    public function ajax_import_domains() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $domains = $_POST['domains'] ?? [];

        if (!is_array($domains)) {
            wp_send_json_error(['message' => 'فرمت داده نامعتبر است.']);
        }

        $sanitized_domains = [];
        foreach ($domains as $domain) {
            $domain = trim(sanitize_text_field($domain));
            if ($domain) {
                $sanitized_domains[] = $this->normalize_domain($domain);
            }
        }

        $current_domains = get_option(CHEKAAD_BLOCKED_DOMAINS_KEY, []);
        $merged_domains = array_unique(array_merge($current_domains, $sanitized_domains));

        update_option(CHEKAAD_BLOCKED_DOMAINS_KEY, $merged_domains);

        wp_send_json_success(['message' => 'دامنه‌ها درون‌ریزی شدند.']);
    }

    // AJAX: Export domains
    public function ajax_export_domains() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $domains = get_option(CHEKAAD_BLOCKED_DOMAINS_KEY, []);
        wp_send_json_success($domains);
    }

    // AJAX: Add suggested list
    public function ajax_add_suggested_list() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $suggested = $_POST['domains'] ?? [];

        if (!is_array($suggested)) {
            wp_send_json_error(['message' => 'فرمت داده نامعتبر است.']);
        }

        $sanitized = [];
        foreach ($suggested as $domain) {
            $domain = trim(sanitize_text_field($domain));
            if ($domain) {
                $sanitized[] = $this->normalize_domain($domain);
            }
        }

        $current = get_option(CHEKAAD_BLOCKED_DOMAINS_KEY, []);
        $merged = array_unique(array_merge($current, $sanitized));

        update_option(CHEKAAD_BLOCKED_DOMAINS_KEY, $merged);

        wp_send_json_success(['message' => 'لیست پیشنهادی اضافه شد.']);
    }

    // AJAX: Update settings
    public function ajax_update_settings() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $settings = $_POST['settings'] ?? [];

        if (!is_array($settings)) {
            wp_send_json_error(['message' => 'فرمت داده نامعتبر است.']);
        }

        $sanitized_settings = [
            'blocking_mode' => in_array($settings['blocking_mode'] ?? '', ['php', 'js', 'both']) ? $settings['blocking_mode'] : 'js',
            'disable_gravatar' => (bool)($settings['disable_gravatar'] ?? false),
            'disable_telemetry' => (bool)($settings['disable_telemetry'] ?? false),
            'remove_dns_prefetch' => (bool)($settings['remove_dns_prefetch'] ?? false),
            'block_dynamic_js' => (bool)($settings['block_dynamic_js'] ?? false),
            'block_fetch_xhr' => (bool)($settings['block_fetch_xhr'] ?? false),
            'output_buffer_enabled' => (bool)($settings['output_buffer_enabled'] ?? false),
            'disable_wp_updates' => (bool)($settings['disable_wp_updates'] ?? false),
            'whitelist_ir_domains' => (bool)($settings['whitelist_ir_domains'] ?? false),
            'check_updates' => (bool)($settings['check_updates'] ?? false),
            'github_repo_url' => 'https://github.com/mehdibinam/'
        ];

        update_option(CHEKAAD_OPTION_KEY, $sanitized_settings);

        wp_send_json_success(['message' => 'تنظیمات ذخیره شد.']);
    }

    // AJAX: Check for updates
    public function ajax_check_updates() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $api_url = 'https://api.github.com/repos/mehdibinam/chekaad/releases/latest';

        $response = wp_remote_get($api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json'
            ]
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'خطای اتصال GitHub.']);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || isset($data['message'])) {
            wp_send_json_error(['message' => 'مخزن GitHub یافت نشد.']);
        }

        $latest_version = isset($data['tag_name']) ? ltrim($data['tag_name'], 'v') : 'unknown';
        $current_version = CHEKAAD_VERSION;

        if (version_compare($latest_version, $current_version, '>')) {
            $message = 'نسخه جدید ' . $latest_version . ' در دسترس است.';
            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_success(['message' => 'شما از آخرین نسخه استفاده می‌کنید.']);
        }
    }

    // AJAX: Switch mode
    public function ajax_switch_mode() {
        check_ajax_referer('chekaad_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی رد شد.']);
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'blacklist');

        if (!in_array($mode, ['blacklist', 'whitelist'])) {
            wp_send_json_error(['message' => 'حالت نامعتبر است.']);
        }

        update_option(CHEKAAD_MODE_KEY, $mode);

        wp_send_json_success(['message' => 'حالت تغییر یافت.']);
    }

    private function normalize_domain($domain) {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        $domain = strtolower($domain);
        return $domain;
    }
}

// Initialize plugin
Chekaad_Plugin::get_instance();

// Plugin activation
register_activation_hook(__FILE__, function() {
    if (!get_option(CHEKAAD_OPTION_KEY)) {
        $plugin = Chekaad_Plugin::get_instance();
        update_option(CHEKAAD_OPTION_KEY, $plugin->get_default_settings());
    }
    if (!get_option(CHEKAAD_MODE_KEY)) {
        update_option(CHEKAAD_MODE_KEY, 'blacklist');
    }
});

// Plugin deactivation
register_deactivation_hook(__FILE__, function() {
    // Optional cleanup
});