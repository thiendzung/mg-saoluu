<?php
if (!defined('ABSPATH')) { exit; }
function mg_saoluu_host() {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    return preg_replace('/:\d+$/', '', $host);
}

function mg_saoluu_auto_environment() {
    // Chỉ tin WP_ENVIRONMENT_TYPE khi người quản trị đã khai báo rõ.
    // wp_get_environment_type() mặc định trả về "production" ngay cả trên tên miền .test,
    // vì vậy không được dùng giá trị mặc định đó trước khi kiểm tra tên miền local.
    $declared_env = '';
    if (defined('WP_ENVIRONMENT_TYPE')) {
        $declared_env = strtolower(trim((string) WP_ENVIRONMENT_TYPE));
    } else {
        $env_value = getenv('WP_ENVIRONMENT_TYPE');
        if ($env_value !== false) {
            $declared_env = strtolower(trim((string) $env_value));
        }
    }

    if (in_array($declared_env, ['local', 'development'], true)) return 'local';
    if ($declared_env === 'staging') return 'staging';
    if ($declared_env === 'production') return 'production';

    $host = mg_saoluu_host();
    if (
        $host === 'localhost' ||
        $host === '127.0.0.1' ||
        $host === '::1' ||
        substr($host, -5) === '.test' ||
        substr($host, -6) === '.local' ||
        substr($host, -10) === '.localhost'
    ) return 'local';

    if (preg_match('/(^|[.\-])(staging|stage|dev|demo|sandbox|test)([.\-]|$)/i', $host)) return 'staging';

    $db_host = strtolower((string) DB_HOST);
    if (PHP_OS_FAMILY === 'Darwin' && ($db_host === 'localhost' || strpos($db_host, '127.0.0.1') === 0)) return 'local';

    return 'production';
}
function mg_saoluu_environment() {
    $manual = get_option('mg_saoluu_environment', 'auto');
    $auto = mg_saoluu_auto_environment();
    if (!in_array($manual, ['local', 'staging', 'production'], true)) return $auto;
    if ($auto === 'production' && in_array($manual, ['local', 'staging'], true)) {
        $verified_host = strtolower((string) get_option('mg_saoluu_environment_verified_host', ''));
        if ($verified_host !== mg_saoluu_host()) return 'production';
    }
    return $manual;
}

function mg_saoluu_environment_info($env = '') {
    if (!$env) $env = mg_saoluu_environment();
    $map = [
        'local' => [
            'label' => 'LOCAL · MÁY MAC',
            'color' => '#059669',
            'bg' => '#ecfdf5',
            'text' => 'Dùng để phát triển. Có thể nhận bản sao từ web thật. Không dùng để nhận đơn hoặc gửi email thật.',
            'phrase' => 'RESTORE LOCAL',
        ],
        'staging' => [
            'label' => 'WEB THỬ NGHIỆM',
            'color' => '#d97706',
            'bg' => '#fffbeb',
            'text' => 'Chỉ dùng để kiểm tra. Không thanh toán thật, không gửi email thật và không cho Google lập chỉ mục.',
            'phrase' => 'RESTORE STAGING',
        ],
        'production' => [
            'label' => 'WEB THẬT · DỮ LIỆU THẬT',
            'color' => '#dc2626',
            'bg' => '#fef2f2',
            'text' => 'Đang thao tác trên website thật. Mọi đơn hàng, bài viết và dữ liệu khách có thể bị thay đổi.',
            'phrase' => 'RESTORE ' . strtoupper(mg_saoluu_host()),
        ],
    ];
    return $map[$env];
}

function mg_saoluu_is_local_environment() { return mg_saoluu_environment() === 'local'; }

add_action('admin_bar_menu', 'mg_saoluu_admin_bar_environment', 999);
function mg_saoluu_admin_bar_environment($bar) {
    if (!current_user_can('manage_options')) return;
    $info = mg_saoluu_environment_info();
    $bar->add_node([
        'id' => 'mg-saoluu-environment',
        'title' => esc_html($info['label']),
        'href' => admin_url('admin.php?page=mg-saoluu-tools'),
        'meta' => ['class' => 'mg-saoluu-env-bar'],
    ]);
}
add_action('admin_head', 'mg_saoluu_environment_bar_css');
add_action('wp_head', 'mg_saoluu_environment_bar_css');
function mg_saoluu_environment_bar_css() {
    if (!is_admin_bar_showing() || !current_user_can('manage_options')) return;
    $info = mg_saoluu_environment_info();
    echo '<style>#wpadminbar #wp-admin-bar-mg-saoluu-environment>.ab-item{background:' . esc_attr($info['color']) . '!important;color:#fff!important;font-weight:700}</style>';
}

add_filter('pre_wp_mail', 'mg_saoluu_block_nonproduction_email', 10, 2);
function mg_saoluu_block_nonproduction_email($return, $atts) {
    if (mg_saoluu_environment() === 'production') return $return;
    if (get_option('mg_saoluu_block_emails', '1') !== '1') return $return;
    if (defined('MG_SAOLUU_ALLOW_EMAIL') && MG_SAOLUU_ALLOW_EMAIL) return $return;
    return true;
}

