<?php

if (!defined('ABSPATH')) {
    exit;
}

class Mythingdong_Hotel_REST
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route('mtd/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'health'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('mtd/v1', '/partner/me', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'partner_me'],
            'permission_callback' => [self::class, 'partner_permission'],
        ]);

        register_rest_route('mtd/v1', '/partner/requests', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'partner_requests'],
            'permission_callback' => [self::class, 'partner_permission'],
        ]);

        register_rest_route('mtd/v1', '/partner/requests/(?P<id>\d+)/status', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'partner_request_status'],
            'permission_callback' => [self::class, 'partner_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'status' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    public static function health(): WP_REST_Response
    {
        return rest_ensure_response([
            'ok' => true,
            'service' => 'mythingdong-hotel-core',
            'version' => MTD_HOTEL_CORE_VERSION,
            'timestamp' => current_time('mysql'),
        ]);
    }

    public static function partner_permission(WP_REST_Request $request): bool|WP_Error
    {
        $partner = self::authenticate_partner($request);
        if (is_wp_error($partner)) {
            return $partner;
        }

        $request->set_param('mtd_partner', $partner);
        return true;
    }

    public static function partner_me(WP_REST_Request $request): WP_REST_Response
    {
        $partner = $request->get_param('mtd_partner');
        return rest_ensure_response([
            'token_id' => (int) $partner['id'],
            'hotel_id' => (int) $partner['hotel_id'],
            'label' => $partner['label'],
            'capabilities' => self::parse_capabilities($partner['capabilities'] ?? ''),
            'expires_at' => $partner['expires_at'],
        ]);
    }

    public static function partner_requests(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $partner = $request->get_param('mtd_partner');
        $capability_error = self::require_capability($partner, 'requests:read');
        if (is_wp_error($capability_error)) {
            return $capability_error;
        }

        $table = Mythingdong_Hotel_DB::table(Mythingdong_Hotel_DB::REQUESTS);

        $items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE hotel_id = %d ORDER BY id DESC LIMIT 100", (int) $partner['hotel_id']),
            ARRAY_A
        ) ?: [];

        return rest_ensure_response(['requests' => array_map([self::class, 'normalize_request'], $items)]);
    }

    public static function partner_request_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $partner = $request->get_param('mtd_partner');
        $capability_error = self::require_capability($partner, 'requests:write');
        if (is_wp_error($capability_error)) {
            return $capability_error;
        }

        $id = absint($request['id']);
        $status = sanitize_key((string) $request->get_param('status'));
        $allowed = ['pending', 'accepted', 'processing', 'completed', 'cancelled'];

        if (!in_array($status, $allowed, true)) {
            return new WP_Error('mtd_invalid_status', __('허용되지 않은 상태값입니다.', 'mythingdong-hotel-core'), ['status' => 400]);
        }

        $table = Mythingdong_Hotel_DB::table(Mythingdong_Hotel_DB::REQUESTS);
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d AND hotel_id = %d", $id, (int) $partner['hotel_id'])
        );

        if ($exists < 1) {
            return new WP_Error('mtd_request_not_found', __('요청을 찾을 수 없습니다.', 'mythingdong-hotel-core'), ['status' => 404]);
        }

        $updated = $wpdb->update(
            $table,
            ['status' => $status, 'updated_at' => Mythingdong_Hotel_DB::now()],
            ['id' => $id, 'hotel_id' => (int) $partner['hotel_id']],
            ['%s', '%s'],
            ['%d', '%d']
        );

        if ($updated === false) {
            return new WP_Error('mtd_update_failed', __('요청 상태를 수정하지 못했습니다.', 'mythingdong-hotel-core'), ['status' => 500]);
        }

        return rest_ensure_response(['ok' => true, 'id' => $id, 'status' => $status]);
    }

    private static function authenticate_partner(WP_REST_Request $request): array|WP_Error
    {
        global $wpdb;

        $authorization = $request->get_header('authorization');
        if (!$authorization || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return new WP_Error('mtd_missing_token', __('Bearer 토큰이 필요합니다.', 'mythingdong-hotel-core'), ['status' => 401]);
        }

        $plain_token = trim($matches[1]);
        $table = Mythingdong_Hotel_DB::table(Mythingdong_Hotel_DB::TOKENS);
        $tokens = $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'active'", ARRAY_A) ?: [];

        foreach ($tokens as $token) {
            if (!wp_check_password($plain_token, $token['token_hash'])) {
                continue;
            }

            if (!empty($token['expires_at']) && strtotime($token['expires_at']) < current_time('timestamp')) {
                return new WP_Error('mtd_expired_token', __('만료된 토큰입니다.', 'mythingdong-hotel-core'), ['status' => 401]);
            }

            $wpdb->update(
                $table,
                ['last_used_at' => Mythingdong_Hotel_DB::now(), 'updated_at' => Mythingdong_Hotel_DB::now()],
                ['id' => (int) $token['id']],
                ['%s', '%s'],
                ['%d']
            );

            return $token;
        }

        return new WP_Error('mtd_invalid_token', __('유효하지 않은 토큰입니다.', 'mythingdong-hotel-core'), ['status' => 401]);
    }

    private static function parse_capabilities(string $capabilities): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $capabilities))));
    }

    private static function require_capability(array $partner, string $capability): bool|WP_Error
    {
        $capabilities = self::parse_capabilities($partner['capabilities'] ?? '');
        if (in_array($capability, $capabilities, true) || in_array('*', $capabilities, true)) {
            return true;
        }

        return new WP_Error('mtd_forbidden', __('파트너 토큰에 필요한 권한이 없습니다.', 'mythingdong-hotel-core'), ['status' => 403]);
    }

    private static function normalize_request(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'hotel_id' => (int) $item['hotel_id'],
            'room_id' => $item['room_id'] ? (int) $item['room_id'] : null,
            'request_type' => $item['request_type'],
            'title' => $item['title'],
            'message' => $item['message'],
            'status' => $item['status'],
            'priority' => $item['priority'],
            'wc_order_id' => $item['wc_order_id'] ? (int) $item['wc_order_id'] : null,
            'metadata' => $item['metadata'] ? json_decode($item['metadata'], true) : null,
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
        ];
    }
}
