<?php

/**
 * One-time migration: imports existing storage/app/export_history.json and
 * storage/app/training_results.json into the export_packs / training_results
 * MySQL tables. Safe to re-run (uses INSERT ... ON DUPLICATE KEY UPDATE).
 *
 * Run once from the CLI or a browser:
 *   php migrate_json_to_db.php
 */

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$appStorageRoot = $appConfig['app_storage_root'] ?? (__DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app');
$exportHistoryPath = $appStorageRoot . DIRECTORY_SEPARATOR . 'export_history.json';
$resultHistoryPath = $appStorageRoot . DIRECTORY_SEPARATOR . 'training_results.json';

$pdo = getDbConnection($appConfig);
ensureSchema($pdo);

function readJson($path)
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    $decoded = json_decode((string) $contents, true);

    return is_array($decoded) ? $decoded : [];
}

$exportHistory = readJson($exportHistoryPath);
$trainingResults = readJson($resultHistoryPath);

$packStmt = $pdo->prepare(
    'INSERT INTO export_packs ('
    . 'job_id, pack_name, side, pack_size, grouped_samples_per_cluster, '
    . 'train_ratio, val_ratio, test_ratio, train_count, val_count, test_count, '
    . 'regular_count, suspicious_count, class_targets_json, manifest_relative_path, '
    . 'folder_relative_path, created_at'
    . ') VALUES ('
    . ':job_id, :pack_name, :side, :pack_size, :grouped_samples_per_cluster, '
    . ':train_ratio, :val_ratio, :test_ratio, :train_count, :val_count, :test_count, '
    . ':regular_count, :suspicious_count, :class_targets_json, :manifest_relative_path, '
    . ':folder_relative_path, :created_at'
    . ') ON DUPLICATE KEY UPDATE '
    . 'pack_name = VALUES(pack_name), side = VALUES(side), pack_size = VALUES(pack_size), '
    . 'grouped_samples_per_cluster = VALUES(grouped_samples_per_cluster), '
    . 'train_count = VALUES(train_count), val_count = VALUES(val_count), test_count = VALUES(test_count), '
    . 'regular_count = VALUES(regular_count), suspicious_count = VALUES(suspicious_count), '
    . 'class_targets_json = VALUES(class_targets_json), '
    . 'manifest_relative_path = VALUES(manifest_relative_path), folder_relative_path = VALUES(folder_relative_path)'
);

$packCount = 0;
foreach ($exportHistory as $pack) {
    if (!is_array($pack) || !isset($pack['job_id'])) {
        continue;
    }

    $splitCounts = is_array($pack['split_counts'] ?? null) ? $pack['split_counts'] : ['train' => 0, 'val' => 0, 'test' => 0];
    $classificationCounts = is_array($pack['classification_counts'] ?? null) ? $pack['classification_counts'] : ['regular' => 0, 'suspicious' => 0];
    $side = in_array($pack['side'] ?? null, ['front', 'back'], true) ? $pack['side'] : 'mixed';
    $createdAt = isset($pack['created_at']) ? date('Y-m-d H:i:s', strtotime((string) $pack['created_at']) ?: time()) : date('Y-m-d H:i:s');

    $packStmt->execute([
        'job_id' => $pack['job_id'],
        'pack_name' => $pack['pack_name'] ?? $pack['job_id'],
        'side' => $side,
        'pack_size' => (int) ($pack['pack_size'] ?? 0),
        'grouped_samples_per_cluster' => (int) ($pack['grouped_samples_per_cluster'] ?? 0),
        'train_ratio' => 0,
        'val_ratio' => 0,
        'test_ratio' => 0,
        'train_count' => (int) ($splitCounts['train'] ?? 0),
        'val_count' => (int) ($splitCounts['val'] ?? 0),
        'test_count' => (int) ($splitCounts['test'] ?? 0),
        'regular_count' => (int) ($classificationCounts['regular'] ?? 0),
        'suspicious_count' => (int) ($classificationCounts['suspicious'] ?? 0),
        'class_targets_json' => json_encode($pack['class_targets'] ?? new stdClass()),
        'manifest_relative_path' => $pack['manifest_relative_path'] ?? '',
        'folder_relative_path' => $pack['folder_relative_path'] ?? '',
        'created_at' => $createdAt,
    ]);
    $packCount++;
}

$resultStmt = $pdo->prepare(
    'INSERT INTO training_results ('
    . 'id, pack_id, pack_name, pack_side, pack_size, accuracy, precision_value, recall_value, '
    . 'false_positives, false_negatives, notes, created_at'
    . ') VALUES ('
    . ':id, :pack_id, :pack_name, :pack_side, :pack_size, :accuracy, :precision_value, :recall_value, '
    . ':false_positives, :false_negatives, :notes, :created_at'
    . ') ON DUPLICATE KEY UPDATE '
    . 'pack_name = VALUES(pack_name), pack_side = VALUES(pack_side), pack_size = VALUES(pack_size), '
    . 'accuracy = VALUES(accuracy), precision_value = VALUES(precision_value), recall_value = VALUES(recall_value), '
    . 'false_positives = VALUES(false_positives), false_negatives = VALUES(false_negatives), notes = VALUES(notes)'
);

$resultCount = 0;
foreach ($trainingResults as $result) {
    if (!is_array($result) || !isset($result['id'])) {
        continue;
    }

    $side = in_array($result['pack_side'] ?? null, ['front', 'back'], true) ? $result['pack_side'] : 'mixed';
    $createdAt = isset($result['created_at']) ? date('Y-m-d H:i:s', strtotime((string) $result['created_at']) ?: time()) : date('Y-m-d H:i:s');

    $resultStmt->execute([
        'id' => $result['id'],
        'pack_id' => $result['pack_id'] ?? '',
        'pack_name' => $result['pack_name'] ?? '',
        'pack_side' => $side,
        'pack_size' => (int) ($result['pack_size'] ?? 0),
        'accuracy' => (float) ($result['accuracy'] ?? 0),
        'precision_value' => (float) ($result['precision'] ?? 0),
        'recall_value' => (float) ($result['recall'] ?? 0),
        'false_positives' => (int) ($result['false_positives'] ?? 0),
        'false_negatives' => (int) ($result['false_negatives'] ?? 0),
        'notes' => (string) ($result['notes'] ?? ''),
        'created_at' => $createdAt,
    ]);
    $resultCount++;
}

echo "Migrated {$packCount} export pack(s) and {$resultCount} training result(s) into MySQL.\n";
