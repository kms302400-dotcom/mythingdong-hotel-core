<?php

if (!defined('ABSPATH')) {
    exit;
}

class Mythingdong_Hotel_DB
{
    public const HOTELS = 'mtd_hotels';
    public const ROOMS = 'mtd_hotel_rooms';
    public const INVENTORY = 'mtd_hotel_inventory';
    public const REQUESTS = 'mtd_hotel_service_requests';
    public const TOKENS = 'mtd_partner_tokens';

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $hotels = self::table(self::HOTELS);
        $rooms = self::table(self::ROOMS);
        $inventory = self::table(self::INVENTORY);
        $requests = self::table(self::REQUESTS);
        $tokens = self::table(self::TOKENS);

        $sql = [];
        $sql[] = "CREATE TABLE {$hotels} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            address TEXT NULL,
            phone VARCHAR(50) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$rooms} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_id BIGINT UNSIGNED NOT NULL,
            room_number VARCHAR(80) NOT NULL,
            floor VARCHAR(50) NULL,
            qr_code VARCHAR(191) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY hotel_id (hotel_id),
            KEY status (status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$inventory} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_id BIGINT UNSIGNED NOT NULL,
            item_name VARCHAR(191) NOT NULL,
            sku VARCHAR(100) NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_quantity INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            wc_product_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY hotel_id (hotel_id),
            KEY sku (sku),
            KEY wc_product_id (wc_product_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$requests} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_id BIGINT UNSIGNED NOT NULL,
            room_id BIGINT UNSIGNED NULL,
            request_type VARCHAR(50) NOT NULL DEFAULT 'service',
            title VARCHAR(191) NOT NULL,
            message TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            wc_order_id BIGINT UNSIGNED NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY hotel_id (hotel_id),
            KEY room_id (room_id),
            KEY status (status),
            KEY wc_order_id (wc_order_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$tokens} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(191) NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            capabilities TEXT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY hotel_id (hotel_id),
            KEY status (status)
        ) {$charset_collate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    public static function now(): string
    {
        return current_time('mysql');
    }

    public static function get_hotels_for_select(): array
    {
        global $wpdb;
        $table = self::table(self::HOTELS);
        return $wpdb->get_results("SELECT id, name FROM {$table} ORDER BY name ASC", ARRAY_A) ?: [];
    }

    public static function get_rooms_for_select(): array
    {
        global $wpdb;
        $table = self::table(self::ROOMS);
        return $wpdb->get_results("SELECT id, hotel_id, room_number FROM {$table} ORDER BY hotel_id ASC, room_number ASC", ARRAY_A) ?: [];
    }
}

