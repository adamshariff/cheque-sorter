<?php

$appConfig = [
    'project_root' => __DIR__,
    'dataset_root' => getenv('CHEQUE_SORTER_DATASET_ROOT') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dummy_data',
    'export_root' => getenv('CHEQUE_SORTER_EXPORT_ROOT') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'exports',
    'app_storage_root' => getenv('CHEQUE_SORTER_APP_STORAGE_ROOT') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app',
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
