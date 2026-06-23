<?php
/**
 * Plugin Name: Mythingdong Hotel Core
 * Description: Central backend for Mythingdong hotel QR ordering and guest request services.
 * Version: 0.1.0
 * Author: Mythingdong
 * Requires PHP: 8.0
 * Text Domain: mythingdong-hotel-core
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MTD_HOTEL_CORE_VERSION', '0.1.0');
define('MTD_HOTEL_CORE_FILE', __FILE__);
define('MTD_HOTEL_CORE_DIR', plugin_dir_path(__FILE__));
define('MTD_HOTEL_CORE_URL', plugin_dir_url(__FILE__));

require_once MTD_HOTEL_CORE_DIR . 'includes/class-mtd-hotel-db.php';
require_once MTD_HOTEL_CORE_DIR . 'includes/class-mtd-hotel-activator.php';
require_once MTD_HOTEL_CORE_DIR . 'admin/class-mtd-hotel-admin.php';
require_once MTD_HOTEL_CORE_DIR . 'public/class-mtd-hotel-rest.php';

register_activation_hook(__FILE__, ['Mythingdong_Hotel_Activator', 'activate']);

function mtd_hotel_core_init(): void
{
    if (is_admin()) {
        Mythingdong_Hotel_Admin::init();
    }

    Mythingdong_Hotel_REST::init();
}

add_action('plugins_loaded', 'mtd_hotel_core_init');

