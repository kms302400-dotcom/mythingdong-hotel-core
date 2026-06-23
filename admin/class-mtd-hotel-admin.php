<?php

if (!defined('ABSPATH')) {
    exit;
}

class Mythingdong_Hotel_Admin
{
    private const CAPABILITY = 'manage_options';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_notices', [self::class, 'show_token_notice']);
    }

    public static function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'mtd-hotel') === false) {
            return;
        }

        wp_enqueue_style(
            'mtd-hotel-admin',
            MTD_HOTEL_CORE_URL . 'assets/css/admin.css',
            [],
            MTD_HOTEL_CORE_VERSION
        );
    }

    public static function register_menu(): void
    {
        add_menu_page(
            __('마이띵동 호텔', 'mythingdong-hotel-core'),
            __('마이띵동 호텔', 'mythingdong-hotel-core'),
            self::CAPABILITY,
            'mtd-hotel',
            [self::class, 'render_hotels_page'],
            'dashicons-building',
            56
        );

        add_submenu_page('mtd-hotel', __('호텔 관리', 'mythingdong-hotel-core'), __('호텔 관리', 'mythingdong-hotel-core'), self::CAPABILITY, 'mtd-hotel', [self::class, 'render_hotels_page']);
        add_submenu_page('mtd-hotel', __('객실 관리', 'mythingdong-hotel-core'), __('객실 관리', 'mythingdong-hotel-core'), self::CAPABILITY, 'mtd-hotel-rooms', [self::class, 'render_rooms_page']);
        add_submenu_page('mtd-hotel', __('재고 관리', 'mythingdong-hotel-core'), __('재고 관리', 'mythingdong-hotel-core'), self::CAPABILITY, 'mtd-hotel-inventory', [self::class, 'render_inventory_page']);
        add_submenu_page('mtd-hotel', __('고객 요청', 'mythingdong-hotel-core'), __('고객 요청', 'mythingdong-hotel-core'), self::CAPABILITY, 'mtd-hotel-requests', [self::class, 'render_requests_page']);
        add_submenu_page('mtd-hotel', __('API 토큰 관리', 'mythingdong-hotel-core'), __('API 토큰 관리', 'mythingdong-hotel-core'), self::CAPABILITY, 'mtd-hotel-tokens', [self::class, 'render_tokens_page']);
    }

    public static function show_token_notice(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $token = get_transient('mtd_partner_token_' . get_current_user_id());
        if (!$token) {
            return;
        }

        delete_transient('mtd_partner_token_' . get_current_user_id());
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('새 API 토큰입니다. 지금 한 번만 복사할 수 있습니다:', 'mythingdong-hotel-core') . '</strong></p><code class="mtd-token-code">' . esc_html($token) . '</code></div>';
    }

    public static function render_hotels_page(): void
    {
        self::render_crud_page(self::config_hotels());
    }

    public static function render_rooms_page(): void
    {
        self::render_crud_page(self::config_rooms());
    }

    public static function render_inventory_page(): void
    {
        self::render_crud_page(self::config_inventory());
    }

    public static function render_requests_page(): void
    {
        self::render_crud_page(self::config_requests());
    }

    public static function render_tokens_page(): void
    {
        self::render_crud_page(self::config_tokens());
    }

    private static function render_crud_page(array $config): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('권한이 없습니다.', 'mythingdong-hotel-core'));
        }

        global $wpdb;
        $table = Mythingdong_Hotel_DB::table($config['table']);
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $message = '';

        if (isset($_POST['mtd_action'])) {
            check_admin_referer($config['nonce']);
            $posted_action = sanitize_key(wp_unslash($_POST['mtd_action']));

            if ($posted_action === 'save') {
                $saved_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
                $data = self::sanitize_fields($config, $_POST);
                $data['updated_at'] = Mythingdong_Hotel_DB::now();

                if ($saved_id > 0) {
                    $wpdb->update($table, $data, ['id' => $saved_id], self::formats_for($data), ['%d']);
                    $message = __('수정되었습니다.', 'mythingdong-hotel-core');
                } else {
                    $data['created_at'] = Mythingdong_Hotel_DB::now();
                    if (!empty($config['generate_token'])) {
                        $plain_token = self::generate_partner_token();
                        $data['token_hash'] = wp_hash_password($plain_token);
                        set_transient('mtd_partner_token_' . get_current_user_id(), $plain_token, 5 * MINUTE_IN_SECONDS);
                    }
                    $wpdb->insert($table, $data, self::formats_for($data));
                    $message = __('등록되었습니다.', 'mythingdong-hotel-core');
                }
            }

            if ($posted_action === 'delete') {
                $delete_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
                if ($delete_id > 0) {
                    $wpdb->delete($table, ['id' => $delete_id], ['%d']);
                    $message = __('삭제되었습니다.', 'mythingdong-hotel-core');
                    $action = '';
                    $id = 0;
                }
            }
        }

        $record = null;
        if ($id > 0 && $action === 'edit') {
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        }

        $items = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A) ?: [];

        echo '<div class="wrap mtd-admin-wrap">';
        echo '<h1>' . esc_html($config['title']) . '</h1>';

        if ($message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }

        self::render_form($config, $record);
        self::render_list($config, $items);
        echo '</div>';
    }

    private static function render_form(array $config, ?array $record): void
    {
        $is_edit = !empty($record['id']);
        echo '<div class="mtd-admin-panel">';
        echo '<h2>' . esc_html($is_edit ? __('수정', 'mythingdong-hotel-core') : __('등록', 'mythingdong-hotel-core')) . '</h2>';
        echo '<form method="post">';
        wp_nonce_field($config['nonce']);
        echo '<input type="hidden" name="mtd_action" value="save">';
        echo '<input type="hidden" name="id" value="' . esc_attr($record['id'] ?? 0) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ($config['fields'] as $field => $meta) {
            if (!empty($meta['readonly_on_edit']) && $is_edit) {
                continue;
            }
            $value = $record[$field] ?? ($meta['default'] ?? '');
            echo '<tr><th scope="row"><label for="' . esc_attr($field) . '">' . esc_html($meta['label']) . '</label></th><td>';
            self::render_field($field, $meta, $value);
            if (!empty($meta['help'])) {
                echo '<p class="description">' . esc_html($meta['help']) . '</p>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button($is_edit ? __('수정 저장', 'mythingdong-hotel-core') : __('새로 등록', 'mythingdong-hotel-core'));
        echo '</form></div>';
    }

    private static function render_field(string $field, array $meta, mixed $value): void
    {
        $type = $meta['type'] ?? 'text';

        if ($type === 'textarea') {
            echo '<textarea class="large-text" rows="4" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '">' . esc_textarea((string) $value) . '</textarea>';
            return;
        }

        if ($type === 'select') {
            echo '<select id="' . esc_attr($field) . '" name="' . esc_attr($field) . '">';
            foreach (self::options_for($meta) as $option_value => $label) {
                echo '<option value="' . esc_attr((string) $option_value) . '"' . selected((string) $value, (string) $option_value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            return;
        }

        $input_type = in_array($type, ['number', 'datetime-local'], true) ? $type : 'text';
        echo '<input class="regular-text" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" type="' . esc_attr($input_type) . '" value="' . esc_attr((string) $value) . '">';
    }

    private static function render_list(array $config, array $items): void
    {
        echo '<h2>' . esc_html__('목록', 'mythingdong-hotel-core') . '</h2>';
        echo '<table class="widefat striped mtd-admin-table"><thead><tr>';
        echo '<th>' . esc_html__('ID', 'mythingdong-hotel-core') . '</th>';
        foreach ($config['list_columns'] as $column) {
            echo '<th>' . esc_html($config['fields'][$column]['label'] ?? $column) . '</th>';
        }
        echo '<th>' . esc_html__('작업', 'mythingdong-hotel-core') . '</th></tr></thead><tbody>';

        if (!$items) {
            echo '<tr><td colspan="' . esc_attr((string) (count($config['list_columns']) + 2)) . '">' . esc_html__('데이터가 없습니다.', 'mythingdong-hotel-core') . '</td></tr>';
        }

        foreach ($items as $item) {
            $edit_url = add_query_arg(['page' => $config['page'], 'action' => 'edit', 'id' => absint($item['id'])], admin_url('admin.php'));
            echo '<tr><td>' . esc_html((string) $item['id']) . '</td>';
            foreach ($config['list_columns'] as $column) {
                echo '<td>' . esc_html((string) ($item[$column] ?? '')) . '</td>';
            }
            echo '<td><a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('수정', 'mythingdong-hotel-core') . '</a> ';
            echo '<form class="mtd-inline-form" method="post">';
            wp_nonce_field($config['nonce']);
            echo '<input type="hidden" name="mtd_action" value="delete">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $item['id']) . '">';
            submit_button(__('삭제', 'mythingdong-hotel-core'), 'delete button-small', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('정말 삭제하시겠습니까?', 'mythingdong-hotel-core')) . "');"]);
            echo '</form></td></tr>';
        }

        echo '</tbody></table>';
    }

    private static function sanitize_fields(array $config, array $source): array
    {
        $data = [];
        foreach ($config['fields'] as $field => $meta) {
            if (!array_key_exists($field, $source)) {
                continue;
            }

            $raw = wp_unslash($source[$field]);
            $type = $meta['type'] ?? 'text';

            if (!empty($meta['integer'])) {
                $data[$field] = (int) $raw;
            } elseif (!empty($meta['nullable_datetime']) && trim((string) $raw) === '') {
                $data[$field] = null;
            } elseif ($type === 'number') {
                $data[$field] = isset($meta['decimal']) ? (float) $raw : (int) $raw;
            } elseif ($type === 'textarea') {
                $data[$field] = sanitize_textarea_field((string) $raw);
            } elseif ($field === 'slug') {
                $data[$field] = sanitize_title((string) $raw);
            } else {
                $data[$field] = sanitize_text_field((string) $raw);
            }
        }
        return $data;
    }

    private static function formats_for(array $data): array
    {
        $formats = [];
        foreach ($data as $value) {
            if ($value === null) {
                $formats[] = null;
            } elseif (is_int($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    private static function options_for(array $meta): array
    {
        if (($meta['options'] ?? '') === 'hotels') {
            $options = ['' => __('선택', 'mythingdong-hotel-core')];
            foreach (Mythingdong_Hotel_DB::get_hotels_for_select() as $hotel) {
                $options[$hotel['id']] = $hotel['name'];
            }
            return $options;
        }

        if (($meta['options'] ?? '') === 'rooms') {
            $options = ['' => __('선택 안 함', 'mythingdong-hotel-core')];
            foreach (Mythingdong_Hotel_DB::get_rooms_for_select() as $room) {
                $options[$room['id']] = sprintf('#%d %s', (int) $room['hotel_id'], $room['room_number']);
            }
            return $options;
        }

        return $meta['options'] ?? [];
    }

    private static function generate_partner_token(): string
    {
        return 'mtd_' . wp_generate_password(48, false, false);
    }

    private static function config_hotels(): array
    {
        return [
            'title' => __('호텔 관리', 'mythingdong-hotel-core'),
            'page' => 'mtd-hotel',
            'table' => Mythingdong_Hotel_DB::HOTELS,
            'nonce' => 'mtd_hotels_nonce',
            'fields' => [
                'name' => ['label' => __('호텔명', 'mythingdong-hotel-core')],
                'slug' => ['label' => __('슬러그', 'mythingdong-hotel-core'), 'help' => __('비워두지 마세요. 영문/숫자/하이픈 권장.', 'mythingdong-hotel-core')],
                'address' => ['label' => __('주소', 'mythingdong-hotel-core'), 'type' => 'textarea'],
                'phone' => ['label' => __('전화번호', 'mythingdong-hotel-core')],
                'status' => ['label' => __('상태', 'mythingdong-hotel-core'), 'type' => 'select', 'default' => 'active', 'options' => ['active' => 'active', 'inactive' => 'inactive']],
            ],
            'list_columns' => ['name', 'slug', 'status'],
        ];
    }

    private static function config_rooms(): array
    {
        return [
            'title' => __('객실 관리', 'mythingdong-hotel-core'),
            'page' => 'mtd-hotel-rooms',
            'table' => Mythingdong_Hotel_DB::ROOMS,
            'nonce' => 'mtd_rooms_nonce',
            'fields' => [
                'hotel_id' => ['label' => __('호텔', 'mythingdong-hotel-core'), 'type' => 'select', 'options' => 'hotels', 'integer' => true],
                'room_number' => ['label' => __('객실 번호', 'mythingdong-hotel-core')],
                'floor' => ['label' => __('층', 'mythingdong-hotel-core')],
                'qr_code' => ['label' => __('QR 코드 값', 'mythingdong-hotel-core')],
                'status' => ['label' => __('상태', 'mythingdong-hotel-core'), 'type' => 'select', 'default' => 'active', 'options' => ['active' => 'active', 'inactive' => 'inactive', 'maintenance' => 'maintenance']],
                'notes' => ['label' => __('메모', 'mythingdong-hotel-core'), 'type' => 'textarea'],
            ],
            'list_columns' => ['hotel_id', 'room_number', 'status'],
        ];
    }

    private static function config_inventory(): array
    {
        return [
            'title' => __('재고 관리', 'mythingdong-hotel-core'),
            'page' => 'mtd-hotel-inventory',
            'table' => Mythingdong_Hotel_DB::INVENTORY,
            'nonce' => 'mtd_inventory_nonce',
            'fields' => [
                'hotel_id' => ['label' => __('호텔', 'mythingdong-hotel-core'), 'type' => 'select', 'options' => 'hotels', 'integer' => true],
                'item_name' => ['label' => __('품목명', 'mythingdong-hotel-core')],
                'sku' => ['label' => __('SKU', 'mythingdong-hotel-core')],
                'price' => ['label' => __('가격', 'mythingdong-hotel-core'), 'type' => 'number', 'decimal' => true, 'default' => '0'],
                'stock_quantity' => ['label' => __('재고 수량', 'mythingdong-hotel-core'), 'type' => 'number', 'default' => '0'],
                'status' => ['label' => __('상태', 'mythingdong-hotel-core'), 'type' => 'select', 'default' => 'active', 'options' => ['active' => 'active', 'inactive' => 'inactive', 'soldout' => 'soldout']],
                'wc_product_id' => ['label' => __('WooCommerce 상품 ID', 'mythingdong-hotel-core'), 'type' => 'number', 'default' => '0', 'integer' => true],
            ],
            'list_columns' => ['hotel_id', 'item_name', 'sku', 'stock_quantity', 'status'],
        ];
    }

    private static function config_requests(): array
    {
        return [
            'title' => __('고객 요청', 'mythingdong-hotel-core'),
            'page' => 'mtd-hotel-requests',
            'table' => Mythingdong_Hotel_DB::REQUESTS,
            'nonce' => 'mtd_requests_nonce',
            'fields' => [
                'hotel_id' => ['label' => __('호텔', 'mythingdong-hotel-core'), 'type' => 'select', 'options' => 'hotels', 'integer' => true],
                'room_id' => ['label' => __('객실', 'mythingdong-hotel-core'), 'type' => 'select', 'options' => 'rooms', 'integer' => true],
                'request_type' => ['label' => __('요청 유형', 'mythingdong-hotel-core'), 'default' => 'service'],
                'title' => ['label' => __('제목', 'mythingdong-hotel-core')],
                'message' => ['label' => __('내용', 'mythingdong-hotel-core'), 'type' => 'textarea'],
                'status' => ['label' => __('상태', 'mythingdong-hotel-core'), 'type' => 'select', 'default' => 'pending', 'options' => ['pending' => 'pending', 'accepted' => 'accepted', 'processing' => 'processing', 'completed' => 'completed', 'cancelled' => 'cancelled']],
                'priority' => ['label' => __('우선순위', 'mythingdong-hotel-core'), 'type' => 'select', 'default' => 'normal', 'options' => ['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'urgent' => 'urgent']],
                'wc_order_id' => ['label' => __('WooCommerce 주문 ID', 'mythingdong-hotel-core'), 'type' => 'number', 'default' => '0', 'integer' => true],
                'metadata' => ['label' => __('메타데이터(JSON)', 'mythingdong-hotel-core'), 'type' => 'textarea'],
            ],
            'list_columns' => ['hotel_id', 'room_id', 'title', 'status', 'priority'],
        ];
    }

    private static function config_tokens(): array
    {
        return [
            'title' => __('API 토큰 관리', 'mythingdong-hotel-core'),
            'page' => 'mtd-hotel-tokens',
            'table' => Mythingdong_Hotel_DB::TOKENS,
            'nonce' => 'mtd_tokens_nonce',
            'generate_token' => true,
            'fields' => [
                'hotel_id' => ['label' => __('호텔', 'mythingdong-hotel-core'), 'type' => 'select', 'options' => 'hotels', 'integer' => true],
                'label' => ['label' => __('토큰 이름', 'mythingdong-hotel-core')],
                'capabilities' => ['label' => __('권한', 'mythingdong-hotel-core'), 'type' => 'textarea', 'default' => 'requests:read,requests:write'],
                'expires_at' => ['label' => __('만료일시', 'mythingdong-hotel-core'), 'nullable_datetime' => true, 'help' => __('예: 2026-12-31 23:59:59. 비우면 만료 없음.', 'mythingdong-hotel-core')],
                'status' => ['label' => __('상태', 'mythingdong-hotel-core'), 'type' => 'select', 'default' => 'active', 'options' => ['active' => 'active', 'inactive' => 'inactive']],
            ],
            'list_columns' => ['hotel_id', 'label', 'status', 'expires_at', 'last_used_at'],
        ];
    }
}
