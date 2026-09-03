<?php

$appConfig = [
    'project_root' => __DIR__,
    'dataset_root' => getenv('CHEQUE_SORTER_DATASET_ROOT') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dummy_data',
    'export_root' => getenv('CHEQUE_SORTER_EXPORT_ROOT') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'exports',
    'app_storage_root' => getenv('CHEQUE_SORTER_APP_STORAGE_ROOT') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app',
    'db_host' => getenv('CHEQUE_SORTER_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('CHEQUE_SORTER_DB_PORT') ?: '3306',
    'db_name' => getenv('CHEQUE_SORTER_DB_NAME') ?: 'cheque_sorter',
    'db_user' => getenv('CHEQUE_SORTER_DB_USER') ?: 'root',
    'db_pass' => getenv('CHEQUE_SORTER_DB_PASS') !== false ? getenv('CHEQUE_SORTER_DB_PASS') : '',
];

if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'config.local.php')) {
    $localConfig = [];
    require __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';

    foreach ($appConfig as $key => $defaultValue) {
        if (isset($localConfig[$key]) && $localConfig[$key] !== null && $localConfig[$key] !== '') {
            $appConfig[$key] = $localConfig[$key];
        }
    }
}
