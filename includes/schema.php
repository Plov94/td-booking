<?php
global $wpdb;
defined('ABSPATH') || exit;

function td_bkg_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $prefix = $wpdb->prefix;

    // Check if buffer_min column exists, if not add it
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$prefix}td_service'");
    if ($table_exists) {
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$prefix}td_service");
        $has_buffer_min = false;
        foreach ($columns as $column) {
            if ($column->Field === 'buffer_min') {
                $has_buffer_min = true;
                break;
            }
        }
        if (!$has_buffer_min) {
            $wpdb->query("ALTER TABLE {$prefix}td_service ADD COLUMN buffer_min INT NOT NULL DEFAULT 0 AFTER name");
        }
        // Ensure booking table has HMAC index columns for PII search
        $bk_cols = $wpdb->get_results("SHOW COLUMNS FROM {$prefix}td_booking");
        $have_email_hash = false; $have_phone_hash = false;
        foreach ($bk_cols as $c) {
            if ($c->Field === 'email_hash') $have_email_hash = true;
            if ($c->Field === 'phone_hash') $have_phone_hash = true;
        }
        if (!$have_email_hash) {
            $wpdb->query("ALTER TABLE {$prefix}td_booking ADD COLUMN email_hash VARCHAR(64) NULL AFTER customer_email");
            $wpdb->query("ALTER TABLE {$prefix}td_booking ADD KEY idx_email_hash (email_hash)");
        }
        if (!$have_phone_hash) {
            $wpdb->query("ALTER TABLE {$prefix}td_booking ADD COLUMN phone_hash VARCHAR(64) NULL AFTER customer_phone");
            $wpdb->query("ALTER TABLE {$prefix}td_booking ADD KEY idx_phone_hash (phone_hash)");
        }
        // Don't recreate existing tables
        return;
    }

    // Only create tables if they don't exist
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    $sql = [];
    $sql[] = "CREATE TABLE {$prefix}td_service (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(190) UNIQUE,
        name VARCHAR(190) NOT NULL,
        buffer_min INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        duration_min INT NOT NULL,
        buffer_before_min INT NOT NULL DEFAULT 0,
        buffer_after_min INT NOT NULL DEFAULT 0,
        skills_json LONGTEXT NULL,
        meeting_mode ENUM('virtual','onsite','remotehelp') NOT NULL DEFAULT 'virtual',
        location_json LONGTEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_active (active)
    ) $charset_collate;";
    
    $sql[] = "CREATE TABLE {$prefix}td_service_staff (
        service_id BIGINT NOT NULL,
        staff_id BIGINT NOT NULL,
        PRIMARY KEY (service_id, staff_id),
        KEY idx_staff_id (staff_id)
    ) $charset_collate;";
    
    $sql[] = "CREATE TABLE {$prefix}td_booking (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        service_id BIGINT NOT NULL,
        staff_id BIGINT NOT NULL,
        status ENUM('pending','confirmed','cancelled','conflicted','failed_sync') NOT NULL DEFAULT 'pending',
        start_utc DATETIME NOT NULL,
        end_utc DATETIME NOT NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        email_hash VARCHAR(64) NULL,
        customer_phone VARCHAR(64) NULL,
        phone_hash VARCHAR(64) NULL,
        customer_address_json LONGTEXT NULL,
        group_size INT NOT NULL DEFAULT 1,
        sms_reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        caldav_uid VARCHAR(190) NULL,
        caldav_etag VARCHAR(190) NULL,
        source ENUM('public','admin') NOT NULL DEFAULT 'public',
        idempotency_key VARCHAR(190) NULL,
        ics_token VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY idx_idempotency (idempotency_key),
        KEY idx_service_start (service_id, start_utc),
        KEY idx_staff_start (staff_id, start_utc),
        KEY idx_status (status),
        KEY idx_email_hash (email_hash),
        KEY idx_phone_hash (phone_hash)
    ) $charset_collate;";
    
    $sql[] = "CREATE TABLE {$prefix}td_calendar_cache (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        staff_id BIGINT NOT NULL,
        from_utc DATETIME NOT NULL,
        to_utc DATETIME NOT NULL,
        payload LONGTEXT NOT NULL,
        etag VARCHAR(190) NULL,
        cached_at DATETIME NOT NULL,
        UNIQUE KEY idx_staff_period (staff_id, from_utc, to_utc)
    ) $charset_collate;";
    
    $sql[] = "CREATE TABLE {$prefix}td_exception (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        service_id BIGINT NULL,
        staff_id BIGINT NULL,
        start_utc DATETIME NOT NULL,
        end_utc DATETIME NOT NULL,
        note VARCHAR(255) NULL,
        KEY idx_service_start (service_id, start_utc),
        KEY idx_staff_start (staff_id, start_utc)
    ) $charset_collate;";
    
    $sql[] = "CREATE TABLE {$prefix}td_audit (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        ts DATETIME NOT NULL,
        level ENUM('info','warn','error') NOT NULL,
        source VARCHAR(64) NOT NULL,
        booking_id BIGINT NULL,
        staff_id BIGINT NULL,
        message VARCHAR(255) NOT NULL,
        context LONGTEXT NULL,
        KEY idx_ts (ts),
        KEY idx_source (source),
        KEY idx_booking (booking_id)
    ) $charset_collate;";

    // Table for staff breaks and holidays
    $sql[] = "CREATE TABLE {$prefix}td_staff_breaks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        start_utc DATETIME NOT NULL,
        end_utc DATETIME NOT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'break',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_staff_id (staff_id),
        KEY idx_start_utc (start_utc),
        KEY idx_end_utc (end_utc)
    ) $charset_collate;";

    foreach ($sql as $q) {
        dbDelta($q);
    }
}
