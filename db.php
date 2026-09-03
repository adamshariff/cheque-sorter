<?php

/**
 * Database access layer for the MySQL-backed metadata index.
 * Filesystem remains the source of truth for image bytes; these tables only
 * store metadata for fast querying (organizer/exporter/results pages).
 */

function getDbConnection(array $appConfig)
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = $appConfig['db_host'] ?? '127.0.0.1';
    $port = $appConfig['db_port'] ?? '3306';
    $name = $appConfig['db_name'] ?? 'cheque_sorter';
    $user = $appConfig['db_user'] ?? 'root';
    $pass = $appConfig['db_pass'] ?? '';

    // Make sure the database exists before selecting it (first-run convenience).
    $bootstrapDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
    $bootstrap = new PDO($bootstrapDsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $bootstrap->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bootstrap = null;

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensureSchema(PDO $pdo)
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $schemaPath = __DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema.sql';

    if (!is_file($schemaPath)) {
        $ensured = true;
        return;
    }

    $sql = file_get_contents($schemaPath);

    if ($sql === false || trim($sql) === '') {
        $ensured = true;
        return;
    }

    // Schema file is CREATE TABLE IF NOT EXISTS only, so this is cheap and idempotent.
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '') {
            continue;
        }

        $pdo->exec($statement);
    }

    $ensured = true;
}

function getAppMeta(PDO $pdo, $key, $default = null)
{
    $stmt = $pdo->prepare('SELECT meta_value FROM app_meta WHERE meta_key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : $value;
}

function setAppMeta(PDO $pdo, $key, $value)
{
    $stmt = $pdo->prepare('INSERT INTO app_meta (meta_key, meta_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

/**
 * Full filesystem walk that reconciles the images table with the dataset on disk.
 * This is the ONLY place allowed to do a full filesystem walk. It both inserts
 * newly found images and removes DB rows for files that no longer exist.
 *
 * @return array{scanned:int, inserted:int, removed:int}
 */
function reindexDataset(PDO $pdo, $datasetRoot, array $allowedSides, array $allowedClassifications, array $allowedImageExtensions)
{
    $foundRelativePaths = [];
    $rows = [];

    foreach ($allowedSides as $side) {
        foreach ($allowedClassifications as $classification) {
            $baseDirectory = $datasetRoot . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $classification;

            if (!is_dir($baseDirectory)) {
                continue;
            }

            $clusterEntries = @scandir($baseDirectory);

            if (!is_array($clusterEntries)) {
                continue;
            }

            foreach ($clusterEntries as $clusterName) {
                if ($clusterName === '.' || $clusterName === '..') {
                    continue;
                }

                $clusterDirectory = $baseDirectory . DIRECTORY_SEPARATOR . $clusterName;

                if (!is_dir($clusterDirectory)) {
                    continue;
                }

                $fileEntries = @scandir($clusterDirectory);

                if (!is_array($fileEntries)) {
                    continue;
                }

                foreach ($fileEntries as $filename) {
                    if ($filename === '.' || $filename === '..') {
                        continue;
                    }

                    $filePath = $clusterDirectory . DIRECTORY_SEPARATOR . $filename;

                    if (!is_file($filePath)) {
                        continue;
                    }

                    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

                    if (!in_array($extension, $allowedImageExtensions, true)) {
                        continue;
                    }

                    $relativePath = $side . '/' . $classification . '/' . $clusterName . '/' . $filename;
                    $foundRelativePaths[] = $relativePath;
                    $rows[] = [$side, $classification, $clusterName, $filename, $relativePath];
                }
            }
        }
    }

    $pdo->beginTransaction();

    try {
        $insertStmt = $pdo->prepare(
            'INSERT INTO images (side, classification, cluster, filename, relative_path) '
            . 'VALUES (:side, :classification, :cluster, :filename, :relative_path) '
            . 'ON DUPLICATE KEY UPDATE side = VALUES(side), classification = VALUES(classification), '
            . 'cluster = VALUES(cluster), filename = VALUES(filename)'
        );

        $inserted = 0;
        foreach ($rows as $row) {
            $insertStmt->execute([
                'side' => $row[0],
                'classification' => $row[1],
                'cluster' => $row[2],
                'filename' => $row[3],
                'relative_path' => $row[4],
            ]);
            $inserted++;
        }

        $removed = 0;
        $existingStmt = $pdo->query('SELECT relative_path FROM images');
        $existingPaths = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
        $foundSet = array_flip($foundRelativePaths);
        $staleePaths = [];

        foreach ($existingPaths as $existingPath) {
            if (!isset($foundSet[$existingPath])) {
                $staleePaths[] = $existingPath;
            }
        }

        if (!empty($staleePaths)) {
            $deleteStmt = $pdo->prepare('DELETE FROM images WHERE relative_path = :relative_path');
            foreach ($staleePaths as $stalePath) {
                $deleteStmt->execute(['relative_path' => $stalePath]);
                $removed++;
            }
        }

        $pdo->commit();
    } catch (Exception $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    setAppMeta($pdo, 'last_full_reindex_at', date(DATE_ATOM));

    return [
        'scanned' => count($rows),
        'inserted' => $inserted,
        'removed' => $removed,
    ];
}

/**
 * Targeted reindex of a single side/classification/cluster folder. Used after
 * an upload so we don't have to walk the entire dataset tree.
 *
 * @return array{scanned:int, inserted:int, removed:int}
 */
function reindexClusterFolder(PDO $pdo, $datasetRoot, $side, $classification, $cluster, array $allowedImageExtensions)
{
    $clusterDirectory = $datasetRoot . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $classification . DIRECTORY_SEPARATOR . $cluster;
    $foundRelativePaths = [];
    $rows = [];

    if (is_dir($clusterDirectory)) {
        $fileEntries = @scandir($clusterDirectory);

        if (is_array($fileEntries)) {
            foreach ($fileEntries as $filename) {
                if ($filename === '.' || $filename === '..') {
                    continue;
                }

                $filePath = $clusterDirectory . DIRECTORY_SEPARATOR . $filename;

                if (!is_file($filePath)) {
                    continue;
                }

                $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

                if (!in_array($extension, $allowedImageExtensions, true)) {
                    continue;
                }

                $relativePath = $side . '/' . $classification . '/' . $cluster . '/' . $filename;
                $foundRelativePaths[] = $relativePath;
                $rows[] = [$side, $classification, $cluster, $filename, $relativePath];
            }
        }
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO images (side, classification, cluster, filename, relative_path) '
        . 'VALUES (:side, :classification, :cluster, :filename, :relative_path) '
        . 'ON DUPLICATE KEY UPDATE side = VALUES(side), classification = VALUES(classification), '
        . 'cluster = VALUES(cluster), filename = VALUES(filename)'
    );

    $inserted = 0;
    foreach ($rows as $row) {
        $insertStmt->execute([
            'side' => $row[0],
            'classification' => $row[1],
            'cluster' => $row[2],
            'filename' => $row[3],
            'relative_path' => $row[4],
        ]);
        $inserted++;
    }

    $removed = 0;
    $existingStmt = $pdo->prepare('SELECT relative_path FROM images WHERE side = :side AND classification = :classification AND cluster = :cluster');
    $existingStmt->execute(['side' => $side, 'classification' => $classification, 'cluster' => $cluster]);
    $existingPaths = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
    $foundSet = array_flip($foundRelativePaths);

    $deleteStmt = $pdo->prepare('DELETE FROM images WHERE relative_path = :relative_path');
    foreach ($existingPaths as $existingPath) {
        if (!isset($foundSet[$existingPath])) {
            $deleteStmt->execute(['relative_path' => $existingPath]);
            $removed++;
        }
    }

    return [
        'scanned' => count($rows),
        'inserted' => $inserted,
        'removed' => $removed,
    ];
}

/**
 * Runs the full reindex exactly once (on first ever load) by checking if the
 * images table is empty and no prior reindex has been recorded.
 */
function bootstrapIndexIfNeeded(PDO $pdo, $datasetRoot, array $allowedSides, array $allowedClassifications, array $allowedImageExtensions, array &$messages)
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM images');
    $count = (int) $stmt->fetchColumn();

    if ($count > 0) {
        return;
    }

    if (getAppMeta($pdo, 'last_full_reindex_at') !== null) {
        // Already indexed before; an empty table now means the dataset is genuinely empty.
        return;
    }

    $messages[] = 'Building image index for the first time...';
    $result = reindexDataset($pdo, $datasetRoot, $allowedSides, $allowedClassifications, $allowedImageExtensions);
    $messages[] = 'Indexed ' . $result['inserted'] . ' image(s) from the dataset folder.';
}
