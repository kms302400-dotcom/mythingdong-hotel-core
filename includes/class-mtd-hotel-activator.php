<?php

if (!defined('ABSPATH')) {
    exit;
}

class Mythingdong_Hotel_Activator
{
    public static function activate(): void
    {
        Mythingdong_Hotel_DB::create_tables();
        update_option('mtd_hotel_core_version', MTD_HOTEL_CORE_VERSION, false);
    }
}

