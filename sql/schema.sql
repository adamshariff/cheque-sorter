-- Cheque Sorter metadata index schema.
-- MySQL/MariaDB. Filesystem remains the source of truth for image bytes;
-- these tables only store metadata for fast querying (organizer/exporter/results pages).
-- Safe to re-run: every statement uses CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    side ENUM('front', 'back') NOT NULL,
    classification ENUM('regular', 'suspicious') NOT NULL,
    cluster VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    relative_path VARCHAR(1024) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_relative_path (relative_path(768)),
    KEY idx_bucket (side, classification, cluster)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS export_packs (
    job_id VARCHAR(128) NOT NULL,
    pack_name VARCHAR(255) NOT NULL,
    side ENUM('front', 'back', 'mixed') NOT NULL DEFAULT 'mixed',
    pack_size INT UNSIGNED NOT NULL DEFAULT 0,
    grouped_samples_per_cluster INT UNSIGNED NOT NULL DEFAULT 0,
    train_ratio INT UNSIGNED NOT NULL DEFAULT 0,
    val_ratio INT UNSIGNED NOT NULL DEFAULT 0,
    test_ratio INT UNSIGNED NOT NULL DEFAULT 0,
    train_count INT UNSIGNED NOT NULL DEFAULT 0,
    val_count INT UNSIGNED NOT NULL DEFAULT 0,
    test_count INT UNSIGNED NOT NULL DEFAULT 0,
    regular_count INT UNSIGNED NOT NULL DEFAULT 0,
    suspicious_count INT UNSIGNED NOT NULL DEFAULT 0,
    class_targets_json TEXT NULL,
    manifest_relative_path VARCHAR(1024) NOT NULL DEFAULT '',
    folder_relative_path VARCHAR(1024) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (job_id),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_results (
    id VARCHAR(128) NOT NULL,
    pack_id VARCHAR(128) NOT NULL,
    pack_name VARCHAR(255) NOT NULL,
    pack_side ENUM('front', 'back', 'mixed') NOT NULL DEFAULT 'mixed',
    pack_size INT UNSIGNED NOT NULL DEFAULT 0,
    accuracy DECIMAL(5,2) NOT NULL DEFAULT 0,
    precision_value DECIMAL(5,2) NOT NULL DEFAULT 0,
    recall_value DECIMAL(5,2) NOT NULL DEFAULT 0,
    false_positives INT UNSIGNED NOT NULL DEFAULT 0,
    false_negatives INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pack_id (pack_id),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small key/value table used for one-time bootstrap flags (e.g. initial full reindex).
CREATE TABLE IF NOT EXISTS app_meta (
    meta_key VARCHAR(191) NOT NULL,
    meta_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
