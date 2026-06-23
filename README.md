# Mythingdong Hotel Core

WordPress backend plugin for the Mythingdong hotel QR ordering and guest request service.

## Features

- Creates hotel service tables on plugin activation.
- Adds WordPress admin pages for hotels, rooms, inventory, guest requests, and partner API tokens.
- Provides basic CRUD screens with nonce, capability checks, sanitization, and escaping.
- Exposes partner REST API endpoints under `/wp-json/mtd/v1`.
- Keeps WooCommerce integration fields (`wc_product_id`, `wc_order_id`) ready for later linking without modifying WooCommerce core.

## Tables

The plugin creates these tables using the active WordPress database prefix:

- `mtd_hotels`
- `mtd_hotel_rooms`
- `mtd_hotel_inventory`
- `mtd_hotel_service_requests`
- `mtd_partner_tokens`

For example, on a default WordPress install they will be created as `wp_mtd_hotels`, `wp_mtd_hotel_rooms`, and so on.

## REST API

- `GET /wp-json/mtd/v1/health`
- `GET /wp-json/mtd/v1/partner/me`
- `GET /wp-json/mtd/v1/partner/requests`
- `POST /wp-json/mtd/v1/partner/requests/{id}/status`

Partner endpoints require:

```http
Authorization: Bearer YOUR_PARTNER_TOKEN
```

Partner tokens are generated in **마이띵동 호텔 > API 토큰 관리**. The plaintext token is displayed only once after creation; only its password hash is stored.

## Installation

Copy this folder to:

```text
wp-content/plugins/mythingdong-hotel-core/
```

Then activate **Mythingdong Hotel Core** from the WordPress admin plugins page.

