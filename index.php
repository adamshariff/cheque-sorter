<?php
session_start();
require __DIR__ . '/config.php';

$requestPage = isset($_GET['page']) ? strtolower((string) $_GET['page']) : 'landing';

$projectRoot = $appConfig['project_root'] ?? __DIR__;
$datasetRoot = $appConfig['dataset_root'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dummy_data');
$exportRoot = $appConfig['export_root'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'exports');
$appStorageRoot = $appConfig['app_storage_root'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app');
$exportHistoryPath = $appStorageRoot . DIRECTORY_SEPARATOR . 'export_history.json';
$resultHistoryPath = $appStorageRoot . DIRECTORY_SEPARATOR . 'training_results.json';
$allowedSides = ['front', 'back'];
$allowedClassifications = ['regular', 'suspicious'];
$allowedImageExtensions = ['png', 'jpg', 'jpeg', 'bmp', 'gif', 'webp', 'tif', 'tiff'];

$errors = [];
$messages = [];
$recentExport = null;
$recentResult = null;

ensureDirectory($exportRoot, $errors, 'export storage');
ensureDirectory($appStorageRoot, $errors, 'application storage');

$dataset = scanDataset($datasetRoot, $allowedSides, $allowedClassifications, $allowedImageExtensions);
$exportHistoryLoad = loadJsonFile($exportHistoryPath);
$resultHistoryLoad = loadJsonFile($resultHistoryPath);
$exportHistory = $exportHistoryLoad['data'];
$trainingResults = $resultHistoryLoad['data'];

if ($exportHistoryLoad['error'] !== null) {
    $errors[] = $exportHistoryLoad['error'];
}

if ($resultHistoryLoad['error'] !== null) {
    $errors[] = $resultHistoryLoad['error'];
}

$discoveredExports = discoverExports($exportRoot);
$exportHistory = mergeExportHistory($discoveredExports, $exportHistory);
$availablePackMap = indexBy($exportHistory, 'job_id');

$organizerSide = isset($_GET['organizer_side']) ? strtolower((string) $_GET['organizer_side']) : $allowedSides[0];
if (!in_array($organizerSide, $allowedSides, true)) {
    $organizerSide = $allowedSides[0];
}

$organizerClassification = isset($_GET['organizer_classification']) ? strtolower((string) $_GET['organizer_classification']) : $allowedClassifications[0];
if (!in_array($organizerClassification, $allowedClassifications, true)) {
    $organizerClassification = $allowedClassifications[0];
}

$organizerClusterChoices = [];
foreach ($dataset['clusters'] as $cluster) {
    if ($cluster['side'] !== $organizerSide || $cluster['classification'] !== $organizerClassification) {
        continue;
    }

    $organizerClusterChoices[] = $cluster['cluster'];
}
sort($organizerClusterChoices, SORT_NATURAL | SORT_FLAG_CASE);

$organizerCluster = isset($_GET['organizer_cluster']) ? strtolower((string) $_GET['organizer_cluster']) : '';
if (!in_array($organizerCluster, $organizerClusterChoices, true)) {
    $organizerCluster = !empty($organizerClusterChoices) ? $organizerClusterChoices[0] : '';
}

$organizerSelectedCard = null;
if ($organizerCluster !== '') {
    foreach ($dataset['clusters'] as $cluster) {
        if ($cluster['side'] === $organizerSide && $cluster['classification'] === $organizerClassification && $cluster['cluster'] === $organizerCluster) {
            $organizerSelectedCard = $cluster;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'upload-images') {
        $uploadResult = handleUploadRequest(
            $_POST,
            $_FILES,
            $datasetRoot,
            $allowedSides,
            $allowedClassifications,
            $allowedImageExtensions
        );

        $errors = array_merge($errors, $uploadResult['errors']);
        $messages = array_merge($messages, $uploadResult['messages']);

        if (!empty($uploadResult['uploaded'])) {
            $dataset = scanDataset($datasetRoot, $allowedSides, $allowedClassifications, $allowedImageExtensions);
        }
    } elseif ($action === 'create-pack') {
        $packResult = handlePackCreation(
            $_POST,
            $dataset,
            $exportRoot,
            $allowedSides,
            $allowedClassifications,
            $exportHistory,
            $exportHistoryPath
        );

        $errors = array_merge($errors, $packResult['errors']);
        $messages = array_merge($messages, $packResult['messages']);

        if ($packResult['pack'] !== null) {
            $recentExport = $packResult['pack'];
            $exportHistory = mergeExportHistory(discoverExports($exportRoot), $exportHistory);
            $availablePackMap = indexBy($exportHistory, 'job_id');
        }
    } elseif ($action === 'save-result') {
        $resultSave = handleResultSave(
            $_POST,
            $availablePackMap,
            $trainingResults,
            $resultHistoryPath
        );

        $errors = array_merge($errors, $resultSave['errors']);
        $messages = array_merge($messages, $resultSave['messages']);

        if ($resultSave['result'] !== null) {
            $recentResult = $resultSave['result'];
            $trainingResults = $resultSave['results'];
        }
    }
}

$organizerClusterChoices = [];
foreach ($dataset['clusters'] as $cluster) {
    if ($cluster['side'] !== $organizerSide || $cluster['classification'] !== $organizerClassification) {
        continue;
    }

    $organizerClusterChoices[] = $cluster['cluster'];
}
sort($organizerClusterChoices, SORT_NATURAL | SORT_FLAG_CASE);

if (!in_array($organizerCluster, $organizerClusterChoices, true)) {
    $organizerCluster = !empty($organizerClusterChoices) ? $organizerClusterChoices[0] : '';
}

$organizerSelectedCard = null;
if ($organizerCluster !== '') {
    foreach ($dataset['clusters'] as $cluster) {
        if ($cluster['side'] === $organizerSide && $cluster['classification'] === $organizerClassification && $cluster['cluster'] === $organizerCluster) {
            $organizerSelectedCard = $cluster;
            break;
        }
    }
}

$exportSideValue = stickyPostValue('export_side', $allowedSides[0]);
if (!in_array($exportSideValue, $allowedSides, true)) {
    $exportSideValue = $allowedSides[0];
}

$recommendation = buildRecommendation($dataset, $exportHistory, $trainingResults, $allowedClassifications, $exportSideValue);
$selectedPackId = isset($_POST['result_pack_id']) ? (string) $_POST['result_pack_id'] : '';
$groupedSamplesPerClusterValue = stickyPostValue('grouped_samples_per_cluster', (string) $recommendation['grouped_samples_per_cluster']);
$packSizeValue = stickyPostValue('pack_size', (string) $recommendation['pack_size']);
$packNameValue = stickyPostValue('pack_name', '');
$trainRatioValue = stickyPostValue('train_ratio', '70');
$valRatioValue = stickyPostValue('val_ratio', '15');
$testRatioValue = stickyPostValue('test_ratio', '15');
$resultAccuracyValue = stickyPostValue('accuracy', '');
$resultPrecisionValue = stickyPostValue('precision', '');
$resultRecallValue = stickyPostValue('recall', '');
$resultFalsePositiveValue = stickyPostValue('false_positives', '0');
$resultFalseNegativeValue = stickyPostValue('false_negatives', '0');
$resultNotesValue = stickyPostValue('notes', '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cheque Sorter</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body data-page="<?= htmlspecialchars($requestPage, ENT_QUOTES, 'UTF-8') ?>">
    <header class="hero">
        <div class="hero__content">
            <div>
                <h1>Cheque sorter</h1>
            </div>
            <nav class="top-nav" aria-label="Primary">
                <a href="index.php">Overview</a>
                <a href="organizer.php">Organizer</a>
                <a href="exporter.php">Exporter</a>
                <a href="results.php">Results</a>
            </nav>
        </div>
    </header>

    <main class="page-shell">
        <?php if (!empty($errors) || !empty($messages)) : ?>
            <section class="flash-stack" aria-live="polite">
                <?php foreach ($errors as $error) : ?>
                    <article class="flash flash--error">
                        <strong>Action needed:</strong>
                        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                    </article>
                <?php endforeach; ?>
                <?php foreach ($messages as $message) : ?>
                    <article class="flash flash--success">
                        <strong>Done:</strong>
                        <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($requestPage === 'landing') : ?>
        <section id="overview" class="section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Dataset overview</p>
                    <h2>Track image coverage across sides, classifications, and clusters.</h2>
                </div>
                <p class="section-copy">
                    Minimum pack size to touch every grouped cluster: <strong><?= htmlspecialchars((string) $recommendation['minimum_pack_size'], ENT_QUOTES, 'UTF-8') ?></strong>
                </p>
            </div>

            <div class="stat-grid">
                <article class="stat-card">
                    <span class="stat-card__label">Total images</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) $dataset['stats']['total_images'], ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
                <article class="stat-card">
                    <span class="stat-card__label">Grouped images</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) $dataset['stats']['grouped_images'], ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
                <article class="stat-card">
                    <span class="stat-card__label">Ungroupable images</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) $dataset['stats']['ungroupable_images'], ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
                <article class="stat-card">
                    <span class="stat-card__label">Saved export packs</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) count($exportHistory), ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
            </div>

            <div class="overview-grid">
                <?php foreach ($allowedSides as $side) : ?>
                    <article class="summary-card">
                        <h3><?= htmlspecialchars(ucfirst($side), ENT_QUOTES, 'UTF-8') ?></h3>
                        <ul class="summary-list">
                            <?php foreach ($allowedClassifications as $classification) : ?>
                                <li>
                                    <span><?= htmlspecialchars(ucfirst($classification), ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= htmlspecialchars((string) ($dataset['stats']['by_side'][$side][$classification] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
                <?php foreach ($allowedClassifications as $classification) : ?>
                    <article class="summary-card">
                        <h3><?= htmlspecialchars(ucfirst($classification), ENT_QUOTES, 'UTF-8') ?></h3>
                        <ul class="summary-list">
                            <?php foreach ($allowedSides as $side) : ?>
                                <li>
                                    <span><?= htmlspecialchars(ucfirst($side), ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= htmlspecialchars((string) ($dataset['stats']['by_classification'][$classification][$side] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($requestPage === 'organizer') : ?>
        <section id="organizer" class="section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Organizer</p>
                    <h2>Browse clustered cheque images and add new training data.</h2>
                </div>
                <p class="section-copy">
                    Select a side, type, and group to focus on one cluster at a time, then open any cheque for a full-size preview.
                </p>
            </div>

            <div class="panel-grid">
                <article class="panel">
                    <h3>Add images</h3>
                    <form method="post" enctype="multipart/form-data" class="stack-form">
                        <input type="hidden" name="action" value="upload-images">
                        <div class="field-row">
                            <label>
                                <span>Side</span>
                                <select name="upload_side">
                                    <?php foreach ($allowedSides as $side) : ?>
                                        <option value="<?= htmlspecialchars($side, ENT_QUOTES, 'UTF-8') ?>"<?= stickySelected('upload_side', $side) ?>><?= htmlspecialchars(ucfirst($side), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Type</span>
                                <select name="upload_classification">
                                    <?php foreach ($allowedClassifications as $classification) : ?>
                                        <option value="<?= htmlspecialchars($classification, ENT_QUOTES, 'UTF-8') ?>"<?= stickySelected('upload_classification', $classification) ?>><?= htmlspecialchars(ucfirst($classification), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label>
                            <span>Cluster folder</span>
                            <input type="text" name="upload_cluster" value="<?= htmlspecialchars(stickyPostValue('upload_cluster', 'group_1'), ENT_QUOTES, 'UTF-8') ?>" placeholder="group_4 or ungroupable">
                        </label>
                        <label>
                            <span>Images</span>
                            <input type="file" name="images[]" accept=".png,.jpg,.jpeg,.bmp,.gif,.webp,.tif,.tiff" multiple>
                        </label>
                        <p class="help-text">Images are copied into the existing storage layout. New cluster folder names are allowed.</p>
                        <button type="submit" class="button button--primary">Add images to organizer</button>
                    </form>
                </article>

                <article class="panel">
                    <h3>Select one group</h3>
                    <form method="get" action="organizer.php" class="filters">
                        <label>
                            <span>Side</span>
                            <select id="sideFilter" name="organizer_side">
                                <?php foreach ($allowedSides as $side) : ?>
                                    <option value="<?= htmlspecialchars($side, ENT_QUOTES, 'UTF-8') ?>"<?= $organizerSide === $side ? ' selected' : '' ?>><?= htmlspecialchars(ucfirst($side), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Type</span>
                            <select id="classificationFilter" name="organizer_classification">
                                <?php foreach ($allowedClassifications as $classification) : ?>
                                    <option value="<?= htmlspecialchars($classification, ENT_QUOTES, 'UTF-8') ?>"<?= $organizerClassification === $classification ? ' selected' : '' ?>><?= htmlspecialchars(ucfirst($classification), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="filters__search">
                            <span>Group</span>
                            <select id="clusterFilter" name="organizer_cluster">
                                <?php foreach ($organizerClusterChoices as $clusterChoice) : ?>
                                    <option value="<?= htmlspecialchars($clusterChoice, ENT_QUOTES, 'UTF-8') ?>"<?= $organizerCluster === $clusterChoice ? ' selected' : '' ?>><?= htmlspecialchars($clusterChoice, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="button button--primary">Show group</button>
                    </form>
                </article>
            </div>

            <div id="clusterGrid" class="cluster-grid">
                <?php if ($organizerSelectedCard !== null) : ?>
                    <article
                        class="cluster-card"
                        data-side="<?= htmlspecialchars($organizerSelectedCard['side'], ENT_QUOTES, 'UTF-8') ?>"
                        data-classification="<?= htmlspecialchars($organizerSelectedCard['classification'], ENT_QUOTES, 'UTF-8') ?>"
                        data-cluster="<?= htmlspecialchars($organizerSelectedCard['cluster'], ENT_QUOTES, 'UTF-8') ?>"
                        data-cluster-key="<?= htmlspecialchars($organizerSelectedCard['key'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <header class="cluster-card__header">
                            <div>
                                <p class="cluster-card__eyebrow"><?= htmlspecialchars(strtoupper($organizerSelectedCard['side']) . ' / ' . strtoupper($organizerSelectedCard['classification']), ENT_QUOTES, 'UTF-8') ?></p>
                                <h3><?= htmlspecialchars($organizerSelectedCard['cluster'], ENT_QUOTES, 'UTF-8') ?></h3>
                            </div>
                            <span class="badge"><?= htmlspecialchars((string) $organizerSelectedCard['count'], ENT_QUOTES, 'UTF-8') ?> images</span>
                        </header>
                        <p class="cluster-card__path"><?= htmlspecialchars($organizerSelectedCard['relative_directory'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="cheque-image-grid">
                            <?php foreach ($organizerSelectedCard['images'] as $image) : ?>
                                <button
                                    type="button"
                                    class="thumbnail-button"
                                    data-preview-src="<?= htmlspecialchars($image['web_path'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-preview-alt="<?= htmlspecialchars($image['filename'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-preview-meta="<?= htmlspecialchars($organizerSelectedCard['side'] . ' / ' . $organizerSelectedCard['classification'] . ' / ' . $organizerSelectedCard['cluster'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <img src="<?= htmlspecialchars($image['web_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($image['filename'], ENT_QUOTES, 'UTF-8') ?>">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
            <?php if ($organizerSelectedCard === null) : ?>
                <p id="clusterEmptyState" class="empty-state">No group is available for the selected side and type.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($requestPage === 'exporter') : ?>
        <section id="exporter" class="section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Exporter</p>
                    <h2>Create persistent training packs that sample every grouped cluster first.</h2>
                </div>
                <p class="section-copy">
                    Each pack is side-specific (front-only or back-only), takes a fixed number of images from every grouped folder, then uses ungroupable images as the primary fill source before repeating grouped images.
                </p>
            </div>

            <div class="panel-grid">
                <article class="panel panel--accent">
                    <h3>Next-pack recommendation</h3>
                    <dl class="recommendation-list">
                        <div>
                            <dt>Suggested pack size</dt>
                            <dd><?= htmlspecialchars((string) $recommendation['pack_size'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Regular quota</dt>
                            <dd><?= htmlspecialchars((string) $recommendation['class_targets']['regular'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Suspicious quota</dt>
                            <dd><?= htmlspecialchars((string) $recommendation['class_targets']['suspicious'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                    <ul class="bullet-list">
                        <?php foreach ($recommendation['notes'] as $note) : ?>
                            <li><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>

                <article class="panel">
                    <h3>Create training pack</h3>
                    <form method="post" class="stack-form">
                        <input type="hidden" name="action" value="create-pack">
                        <label>
                            <span>Cheque side</span>
                            <select name="export_side">
                                <?php foreach ($allowedSides as $side) : ?>
                                    <option value="<?= htmlspecialchars($side, ENT_QUOTES, 'UTF-8') ?>"<?= $exportSideValue === $side ? ' selected' : '' ?>><?= htmlspecialchars(ucfirst($side), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Pack name</span>
                            <input type="text" name="pack_name" value="<?= htmlspecialchars($packNameValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="August suspicious review">
                        </label>
                        <label>
                            <span>Total images</span>
                            <input type="number" name="pack_size" min="<?= htmlspecialchars((string) $recommendation['minimum_pack_size'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($packSizeValue, ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            <span>Grouped items per folder</span>
                            <input type="number" name="grouped_samples_per_cluster" min="1" value="<?= htmlspecialchars($groupedSamplesPerClusterValue, ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <div class="field-row field-row--triple">
                            <label>
                                <span>Train %</span>
                                <input type="number" name="train_ratio" min="0" max="100" value="<?= htmlspecialchars($trainRatioValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span>Val %</span>
                                <input type="number" name="val_ratio" min="0" max="100" value="<?= htmlspecialchars($valRatioValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span>Test %</span>
                                <input type="number" name="test_ratio" min="0" max="100" value="<?= htmlspecialchars($testRatioValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                        </div>
                        <p class="help-text">Ratios must total 100. Higher grouped-items-per-folder values require larger pack sizes. Generated packs are written into <code>storage/exports</code> and tracked in persistent history.</p>
                        <button type="submit" class="button button--primary">Create export pack</button>
                    </form>

                    <?php if ($recentExport !== null) : ?>
                        <div class="callout">
                            <h4>Most recent pack</h4>
                            <p><strong><?= htmlspecialchars($recentExport['pack_name'], ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars(ucfirst($recentExport['side'] ?? 'mixed'), ENT_QUOTES, 'UTF-8') ?>) created with <?= htmlspecialchars((string) $recentExport['pack_size'], ENT_QUOTES, 'UTF-8') ?> images.</p>
                            <p class="callout__links">
                                <a href="<?= htmlspecialchars($recentExport['manifest_web_path'], ENT_QUOTES, 'UTF-8') ?>">Manifest</a>
                                <a href="<?= htmlspecialchars($recentExport['folder_web_path'], ENT_QUOTES, 'UTF-8') ?>">Open exported folder</a>
                            </p>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <article class="panel">
                <div class="panel__header">
                    <h3>Export history</h3>
                    <span class="badge"><?= htmlspecialchars((string) count($exportHistory), ENT_QUOTES, 'UTF-8') ?> packs</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Pack</th>
                                <th>Side</th>
                                <th>Created</th>
                                <th>Total</th>
                                <th>Regular</th>
                                <th>Suspicious</th>
                                <th>Links</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exportHistory as $pack) : ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($pack['pack_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="table-subtext"><?= htmlspecialchars($pack['job_id'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(ucfirst($pack['side'] ?? 'mixed'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(formatDateTime($pack['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $pack['pack_size'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($pack['classification_counts']['regular'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($pack['classification_counts']['suspicious'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="table-links">
                                        <a href="<?= htmlspecialchars($pack['manifest_web_path'], ENT_QUOTES, 'UTF-8') ?>">Manifest</a>
                                        <a href="<?= htmlspecialchars($pack['folder_web_path'], ENT_QUOTES, 'UTF-8') ?>">Folder</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($requestPage === 'results') : ?>
        <section id="results" class="section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Results and recommendations</p>
                    <h2>Record pack outcomes and adjust the next training batch.</h2>
                </div>
                <p class="section-copy">
                    Record model outcomes so the next recommendation can adjust overall pack size and class balance.
                </p>
            </div>

            <div class="panel-grid">
                <article class="panel">
                    <h3>Save pack outcome</h3>
                    <form method="post" class="stack-form">
                        <input type="hidden" name="action" value="save-result">
                        <label>
                            <span>Export pack</span>
                            <select name="result_pack_id">
                                <option value="">Select a pack</option>
                                <?php foreach ($exportHistory as $pack) : ?>
                                    <option value="<?= htmlspecialchars($pack['job_id'], ENT_QUOTES, 'UTF-8') ?>"<?= $selectedPackId === $pack['job_id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($pack['pack_name'] . ' (' . $pack['job_id'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="field-row field-row--triple">
                            <label>
                                <span>Accuracy %</span>
                                <input type="number" name="accuracy" min="0" max="100" step="0.1" value="<?= htmlspecialchars($resultAccuracyValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span>Precision %</span>
                                <input type="number" name="precision" min="0" max="100" step="0.1" value="<?= htmlspecialchars($resultPrecisionValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span>Recall %</span>
                                <input type="number" name="recall" min="0" max="100" step="0.1" value="<?= htmlspecialchars($resultRecallValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                        </div>
                        <div class="field-row">
                            <label>
                                <span>False positives</span>
                                <input type="number" name="false_positives" min="0" step="1" value="<?= htmlspecialchars($resultFalsePositiveValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                            <label>
                                <span>False negatives</span>
                                <input type="number" name="false_negatives" min="0" step="1" value="<?= htmlspecialchars($resultFalseNegativeValue, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                        </div>
                        <label>
                            <span>Notes</span>
                            <textarea name="notes" rows="4" placeholder="Capture model behavior, edge cases, or reviewer feedback."><?= htmlspecialchars($resultNotesValue, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </label>
                        <button type="submit" class="button button--primary">Save pack result</button>
                    </form>

                    <?php if ($recentResult !== null) : ?>
                        <div class="callout">
                            <h4>Latest result saved</h4>
                            <p><?= htmlspecialchars($recentResult['pack_name'], ENT_QUOTES, 'UTF-8') ?> recorded with accuracy <?= htmlspecialchars(number_format((float) $recentResult['accuracy'], 1), ENT_QUOTES, 'UTF-8') ?>%.</p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="panel panel--accent">
                    <h3>Recommendation signals</h3>
                    <ul class="bullet-list">
                        <li>False negatives increase suspicious quota and overall pack size.</li>
                        <li>False positives increase regular hard-negative coverage.</li>
                    </ul>
                    <div class="recommendation-summary">
                        <p><strong>Current target size:</strong> <?= htmlspecialchars((string) $recommendation['pack_size'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p><strong>Grouped items per folder:</strong> <?= htmlspecialchars((string) $recommendation['grouped_samples_per_cluster'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </article>
            </div>

            <article class="panel">
                <div class="panel__header">
                    <h3>Training result history</h3>
                    <span class="badge"><?= htmlspecialchars((string) count($trainingResults), ENT_QUOTES, 'UTF-8') ?> entries</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Pack</th>
                                <th>Recorded</th>
                                <th>Accuracy</th>
                                <th>Precision</th>
                                <th>Recall</th>
                                <th>False + / -</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trainingResults as $result) : ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($result['pack_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="table-subtext"><?= htmlspecialchars($result['pack_id'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(formatDateTime($result['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(number_format((float) $result['accuracy'], 1), ENT_QUOTES, 'UTF-8') ?>%</td>
                                    <td><?= htmlspecialchars(number_format((float) $result['precision'], 1), ENT_QUOTES, 'UTF-8') ?>%</td>
                                    <td><?= htmlspecialchars(number_format((float) $result['recall'], 1), ENT_QUOTES, 'UTF-8') ?>%</td>
                                    <td><?= htmlspecialchars((string) ($result['false_positives'] ?? 0), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string) ($result['false_negatives'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
        <?php endif; ?>
    </main>

    <div id="imagePreview" class="lightbox" hidden>
        <div class="lightbox__backdrop" data-close-preview="true"></div>
        <div class="lightbox__dialog" role="dialog" aria-modal="true" aria-labelledby="lightboxTitle">
            <button type="button" class="lightbox__close" id="lightboxClose" aria-label="Close preview">&times;</button>
            <p id="lightboxTitle" class="lightbox__meta">Cheque preview</p>
            <img id="lightboxImage" src="" alt="">
        </div>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>
<?php

function ensureDirectory($path, array &$errors, $label)
{
    if (is_dir($path)) {
        return true;
    }

    if (@mkdir($path, 0777, true)) {
        return true;
    }

    $errors[] = 'Unable to create ' . $label . ' at ' . relativeProjectPath($path) . '.';
    return false;
}

function loadJsonFile($path)
{
    if (!is_file($path)) {
        return ['data' => [], 'error' => null];
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        return ['data' => [], 'error' => 'Unable to read ' . relativeProjectPath($path) . '.'];
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        return ['data' => [], 'error' => 'The file ' . relativeProjectPath($path) . ' does not contain valid JSON.'];
    }

    return ['data' => $decoded, 'error' => null];
}

function saveJsonFile($path, $payload, array &$errors, $label)
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        $errors[] = 'Unable to encode ' . $label . ' for ' . relativeProjectPath($path) . '.';
        return false;
    }

    if (file_put_contents($path, $encoded) === false) {
        $errors[] = 'Unable to write ' . $label . ' to ' . relativeProjectPath($path) . '.';
        return false;
    }

    return true;
}

function scanDataset($datasetRoot, array $allowedSides, array $allowedClassifications, array $allowedExtensions)
{
    $clusters = [];
    $stats = [
        'total_images' => 0,
        'grouped_images' => 0,
        'ungroupable_images' => 0,
        'by_side' => [],
        'by_classification' => [],
    ];

    foreach ($allowedSides as $side) {
        $stats['by_side'][$side] = [];

        foreach ($allowedClassifications as $classification) {
            $stats['by_side'][$side][$classification] = 0;
        }
    }

    foreach ($allowedClassifications as $classification) {
        $stats['by_classification'][$classification] = [];

        foreach ($allowedSides as $side) {
            $stats['by_classification'][$classification][$side] = 0;
        }
    }

    foreach ($allowedSides as $side) {
        foreach ($allowedClassifications as $classification) {
            $baseDirectory = $datasetRoot . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $classification;

            if (!is_dir($baseDirectory)) {
                continue;
            }

            $clusterNames = [];
            $entries = scandir($baseDirectory);

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $clusterDirectory = $baseDirectory . DIRECTORY_SEPARATOR . $entry;

                if (!is_dir($clusterDirectory)) {
                    continue;
                }

                $clusterNames[] = $entry;
            }

            natcasesort($clusterNames);

            foreach ($clusterNames as $clusterName) {
                $clusterDirectory = $baseDirectory . DIRECTORY_SEPARATOR . $clusterName;
                $images = listImages($clusterDirectory, $allowedExtensions);
                $count = count($images);

                if ($count === 0) {
                    continue;
                }

                $clusters[] = [
                    'key' => $side . '/' . $classification . '/' . $clusterName,
                    'side' => $side,
                    'classification' => $classification,
                    'cluster' => $clusterName,
                    'count' => $count,
                    'relative_directory' => relativeProjectPath($clusterDirectory),
                    'images' => $images,
                ];

                $stats['total_images'] += $count;
                $stats['by_side'][$side][$classification] += $count;
                $stats['by_classification'][$classification][$side] += $count;

                if ($clusterName === 'ungroupable') {
                    $stats['ungroupable_images'] += $count;
                } else {
                    $stats['grouped_images'] += $count;
                }
            }
        }
    }

    usort($clusters, function ($left, $right) {
        return strcmp($left['key'], $right['key']);
    });

    return [
        'clusters' => $clusters,
        'stats' => $stats,
    ];
}

function listImages($directory, array $allowedExtensions)
{
    $records = [];
    $entries = scandir($directory);

    if (!is_array($entries)) {
        return $records;
    }

    natcasesort($entries);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;

        if (!is_file($path)) {
            continue;
        }

        $extension = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $relativePath = relativeProjectPath($path);
        $records[] = [
            'filename' => $entry,
            'absolute_path' => $path,
            'relative_path' => $relativePath,
            'web_path' => toWebPath($relativePath),
            'size_bytes' => (int) filesize($path),
            'modified_at' => (int) filemtime($path),
        ];
    }

    return $records;
}

function discoverExports($exportRoot)
{
    $exports = [];

    if (!is_dir($exportRoot)) {
        return $exports;
    }

    $entries = scandir($exportRoot);

    if (!is_array($entries)) {
        return $exports;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $jobDirectory = $exportRoot . DIRECTORY_SEPARATOR . $entry;

        if (!is_dir($jobDirectory)) {
            continue;
        }

        $packPath = $jobDirectory . DIRECTORY_SEPARATOR . 'pack.json';

        if (is_file($packPath)) {
            $payload = loadJsonFile($packPath);

            if ($payload['error'] === null && !empty($payload['data'])) {
                $exports[] = normalizeExportHistoryItem($payload['data']);
                continue;
            }
        }

        $manifestPath = $jobDirectory . DIRECTORY_SEPARATOR . 'manifest.csv';

        if (!is_file($manifestPath)) {
            continue;
        }

        $parsed = parseManifestCounts($manifestPath);
        $exports[] = [
            'job_id' => $entry,
            'pack_name' => $entry,
            'side' => $parsed['side'],
            'pack_size' => $parsed['pack_size'],
            'created_at' => date(DATE_ATOM, (int) filemtime($manifestPath)),
            'split_counts' => $parsed['split_counts'],
            'classification_counts' => $parsed['classification_counts'],
            'manifest_relative_path' => relativeProjectPath($manifestPath),
            'manifest_web_path' => toWebPath(relativeProjectPath($manifestPath)),
            'folder_relative_path' => relativeProjectPath($jobDirectory),
            'folder_web_path' => toWebPath(relativeProjectPath($jobDirectory)),
        ];
    }

    usort($exports, function ($left, $right) {
        return strcmp($right['created_at'], $left['created_at']);
    });

    return $exports;
}

function parseManifestCounts($manifestPath)
{
    $handle = fopen($manifestPath, 'rb');
    $splitCounts = ['train' => 0, 'val' => 0, 'test' => 0];
    $classificationCounts = ['regular' => 0, 'suspicious' => 0];
    $sideCounts = ['front' => 0, 'back' => 0];
    $packSize = 0;

    if ($handle === false) {
        return [
            'side' => 'mixed',
            'pack_size' => 0,
            'split_counts' => $splitCounts,
            'classification_counts' => $classificationCounts,
        ];
    }

    $header = fgetcsv($handle);

    while (($row = fgetcsv($handle)) !== false) {
        $packSize++;

        if (isset($row[2], $splitCounts[$row[2]])) {
            $splitCounts[$row[2]]++;
        }

        if (isset($row[3], $classificationCounts[$row[3]])) {
            $classificationCounts[$row[3]]++;
        }

        if (isset($row[4], $sideCounts[$row[4]])) {
            $sideCounts[$row[4]]++;
        }
    }

    fclose($handle);

    $side = 'mixed';
    if ($sideCounts['front'] > 0 && $sideCounts['back'] === 0) {
        $side = 'front';
    } elseif ($sideCounts['back'] > 0 && $sideCounts['front'] === 0) {
        $side = 'back';
    }

    return [
        'side' => $side,
        'pack_size' => $packSize,
        'split_counts' => $splitCounts,
        'classification_counts' => $classificationCounts,
    ];
}

function normalizeExportHistoryItem(array $item)
{
    $item['side'] = isset($item['side']) && in_array($item['side'], ['front', 'back'], true) ? $item['side'] : 'mixed';
    $item['split_counts'] = isset($item['split_counts']) && is_array($item['split_counts']) ? $item['split_counts'] : ['train' => 0, 'val' => 0, 'test' => 0];
    $item['classification_counts'] = isset($item['classification_counts']) && is_array($item['classification_counts']) ? $item['classification_counts'] : ['regular' => 0, 'suspicious' => 0];
    $item['manifest_relative_path'] = isset($item['manifest_relative_path']) ? $item['manifest_relative_path'] : '';
    $item['folder_relative_path'] = isset($item['folder_relative_path']) ? $item['folder_relative_path'] : '';
    $item['manifest_web_path'] = isset($item['manifest_web_path']) && $item['manifest_web_path'] !== '' ? $item['manifest_web_path'] : toWebPath($item['manifest_relative_path']);
    $item['folder_web_path'] = isset($item['folder_web_path']) && $item['folder_web_path'] !== '' ? $item['folder_web_path'] : toWebPath($item['folder_relative_path']);
    return $item;
}

function mergeExportHistory(array $discoveredExports, array $storedHistory)
{
    $merged = [];

    foreach ($discoveredExports as $export) {
        $merged[$export['job_id']] = normalizeExportHistoryItem($export);
    }

    foreach ($storedHistory as $export) {
        if (!is_array($export) || !isset($export['job_id'])) {
            continue;
        }

        $merged[$export['job_id']] = normalizeExportHistoryItem(array_merge($merged[$export['job_id']] ?? [], $export));
    }

    $merged = array_values($merged);
    usort($merged, function ($left, $right) {
        return strcmp($right['created_at'], $left['created_at']);
    });

    return $merged;
}

function indexBy(array $records, $key)
{
    $indexed = [];

    foreach ($records as $record) {
        if (isset($record[$key])) {
            $indexed[$record[$key]] = $record;
        }
    }

    return $indexed;
}

function handleUploadRequest(array $post, array $files, $datasetRoot, array $allowedSides, array $allowedClassifications, array $allowedExtensions)
{
    $errors = [];
    $messages = [];
    $uploaded = [];

    $side = isset($post['upload_side']) ? (string) $post['upload_side'] : '';
    $classification = isset($post['upload_classification']) ? (string) $post['upload_classification'] : '';
    $clusterInput = isset($post['upload_cluster']) ? (string) $post['upload_cluster'] : '';
    $cluster = sanitizeClusterName($clusterInput);

    if (!in_array($side, $allowedSides, true)) {
        $errors[] = 'Select a valid cheque side for the upload.';
    }

    if (!in_array($classification, $allowedClassifications, true)) {
        $errors[] = 'Select a valid cheque type for the upload.';
    }

    if ($cluster === '') {
        $errors[] = 'Enter a cluster folder name such as group_1 or ungroupable.';
    }

    $uploads = normalizeUploads(isset($files['images']) ? $files['images'] : null);

    if (count($uploads) === 0) {
        $errors[] = 'Choose at least one image to upload.';
    }

    if (!empty($errors)) {
        return ['errors' => $errors, 'messages' => $messages, 'uploaded' => $uploaded];
    }

    $targetDirectory = $datasetRoot . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $classification . DIRECTORY_SEPARATOR . $cluster;

    if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0777, true)) {
        $errors[] = 'Unable to create the target cluster directory at ' . relativeProjectPath($targetDirectory) . '.';
        return ['errors' => $errors, 'messages' => $messages, 'uploaded' => $uploaded];
    }

    foreach ($uploads as $upload) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed for ' . $upload['name'] . ': ' . uploadErrorMessage($upload['error']);
            continue;
        }

        if (!is_uploaded_file($upload['tmp_name'])) {
            $errors[] = 'PHP could not verify the uploaded file ' . $upload['name'] . '.';
            continue;
        }

        $extension = strtolower((string) pathinfo($upload['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Unsupported file type for ' . $upload['name'] . '.';
            continue;
        }

        $safeBaseName = sanitizeFileBase((string) pathinfo($upload['name'], PATHINFO_FILENAME));
        $targetName = buildUniqueFileName($targetDirectory, $safeBaseName, $extension);
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $targetName;

        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            $errors[] = 'Unable to move ' . $upload['name'] . ' into ' . relativeProjectPath($targetDirectory) . '.';
            continue;
        }

        $uploaded[] = relativeProjectPath($targetPath);
    }

    if (!empty($uploaded)) {
        $messages[] = 'Added ' . count($uploaded) . ' image(s) to ' . $side . '/' . $classification . '/' . $cluster . '.';
    }

    return ['errors' => $errors, 'messages' => $messages, 'uploaded' => $uploaded];
}

function normalizeUploads($fileField)
{
    $normalized = [];

    if (!is_array($fileField) || !isset($fileField['name']) || !is_array($fileField['name'])) {
        return $normalized;
    }

    $count = count($fileField['name']);

    for ($index = 0; $index < $count; $index++) {
        if (!isset($fileField['name'][$index])) {
            continue;
        }

        if ((int) $fileField['error'][$index] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[] = [
            'name' => (string) $fileField['name'][$index],
            'type' => (string) $fileField['type'][$index],
            'tmp_name' => (string) $fileField['tmp_name'][$index],
            'error' => (int) $fileField['error'][$index],
            'size' => (int) $fileField['size'][$index],
        ];
    }

    return $normalized;
}

function uploadErrorMessage($errorCode)
{
    $map = [
        UPLOAD_ERR_INI_SIZE => 'the file exceeded the server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'the file exceeded the form upload limit',
        UPLOAD_ERR_PARTIAL => 'the file was only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'the temporary upload directory is missing',
        UPLOAD_ERR_CANT_WRITE => 'the server could not write the file',
        UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
    ];

    return isset($map[$errorCode]) ? $map[$errorCode] : 'unknown upload error';
}

function sanitizeClusterName($cluster)
{
    $cluster = trim(strtolower($cluster));
    $cluster = preg_replace('/\s+/', '_', $cluster);
    $cluster = preg_replace('/[^a-z0-9_-]/', '', $cluster);
    return $cluster;
}

function sanitizeFileBase($baseName)
{
    $baseName = trim($baseName);
    $baseName = preg_replace('/\s+/', '_', $baseName);
    $baseName = preg_replace('/[^A-Za-z0-9_-]/', '', $baseName);
    return $baseName === '' ? 'cheque_image' : $baseName;
}

function buildUniqueFileName($directory, $baseName, $extension)
{
    $candidate = $baseName . '.' . $extension;
    $suffix = 2;

    while (is_file($directory . DIRECTORY_SEPARATOR . $candidate)) {
        $candidate = $baseName . '_' . $suffix . '.' . $extension;
        $suffix++;
    }

    return $candidate;
}

function handlePackCreation(array $post, array $dataset, $exportRoot, array $allowedSides, array $allowedClassifications, array &$exportHistory, $exportHistoryPath)
{
    $errors = [];
    $messages = [];
    $pack = null;

    $exportSide = isset($post['export_side']) ? strtolower((string) $post['export_side']) : '';
    $packSize = filter_var(isset($post['pack_size']) ? $post['pack_size'] : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $groupedSamplesPerCluster = filter_var(isset($post['grouped_samples_per_cluster']) ? $post['grouped_samples_per_cluster'] : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $packName = trim(isset($post['pack_name']) ? (string) $post['pack_name'] : '');
    $trainRatio = parsePercentage(isset($post['train_ratio']) ? $post['train_ratio'] : null);
    $valRatio = parsePercentage(isset($post['val_ratio']) ? $post['val_ratio'] : null);
    $testRatio = parsePercentage(isset($post['test_ratio']) ? $post['test_ratio'] : null);
    $ratioTotal = $trainRatio + $valRatio + $testRatio;
    $minimumPackSize = 0;

    if (!in_array($exportSide, $allowedSides, true)) {
        $errors[] = 'Select a valid cheque side for the export pack.';
    }

    if ($packName === '') {
        $packName = 'Training pack ' . date('Y-m-d H:i');
    }

    if ($groupedSamplesPerCluster === false || $groupedSamplesPerCluster === null) {
        $errors[] = 'Enter a valid grouped-items-per-folder value.';
    } else {
        $minimumPackSize = countGroupedBuckets($dataset, $exportSide) * $groupedSamplesPerCluster;
    }

    if ($packSize === false || $packSize === null) {
        $errors[] = 'Enter a valid training pack size.';
    } elseif ($packSize < $minimumPackSize) {
        $errors[] = 'Pack size must be at least ' . $minimumPackSize . ' for the selected grouped-items-per-folder value.';
    }

    if ($trainRatio === null || $valRatio === null || $testRatio === null) {
        $errors[] = 'Pack ratios must be whole numbers between 0 and 100.';
    } elseif ($ratioTotal !== 100) {
        $errors[] = 'Train, val, and test ratios must add up to 100.';
    }

    $pools = buildExportPools($dataset, $allowedClassifications, $exportSide);
    $availableImageCount = 0;
    $minimumByClassification = [];

    $insufficientGroupedBuckets = [];

    foreach ($allowedClassifications as $classification) {
        $availableImageCount += $pools[$classification]['total_available'];

        if ($groupedSamplesPerCluster !== false && $groupedSamplesPerCluster !== null) {
            foreach ($pools[$classification]['grouped_buckets'] as $bucket) {
                if (count($bucket['items']) < $groupedSamplesPerCluster) {
                    $insufficientGroupedBuckets[] = $bucket['key'] . ' (' . count($bucket['items']) . ' available)';
                }
            }
        }

        $minimumByClassification[$classification] = $pools[$classification]['grouped_bucket_count'] * (int) $groupedSamplesPerCluster;
    }

    if (!empty($insufficientGroupedBuckets)) {
        $errors[] = 'Some grouped folders do not have enough images for ' . $groupedSamplesPerCluster . ' per folder: ' . implode(', ', $insufficientGroupedBuckets) . '.';
    }

    if ($packSize !== false && $packSize !== null && $packSize > $availableImageCount) {
        $errors[] = 'Requested pack size exceeds the available image count of ' . $availableImageCount . '.';
    }

    if (!empty($errors)) {
        return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
    }

    $classTargets = allocateClassificationTargets($packSize, $minimumByClassification, $pools);

    if ($classTargets === null) {
        $errors[] = 'The current dataset cannot satisfy the requested pack size while sampling each grouped cluster.';
        return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
    }

    $selectedItems = [];

    foreach ($allowedClassifications as $classification) {
        $selection = selectImagesForClassification($pools[$classification], $classTargets[$classification], (int) $groupedSamplesPerCluster);

        if (count($selection) < $classTargets[$classification]) {
            $errors[] = 'Not enough ' . $classification . ' images are available on the ' . $exportSide . ' side to build the requested pack.';
            return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
        }

        $selectedItems = array_merge($selectedItems, $selection);
    }

    shuffle($selectedItems);
    $splitCounts = allocateSplitCounts(count($selectedItems), ['train' => $trainRatio, 'val' => $valRatio, 'test' => $testRatio]);
    $splitQueue = [];

    foreach ($splitCounts as $split => $count) {
        for ($index = 0; $index < $count; $index++) {
            $splitQueue[] = $split;
        }
    }

    $jobId = 'job_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $jobDirectory = $exportRoot . DIRECTORY_SEPARATOR . $jobId;

    if (!@mkdir($jobDirectory, 0777, true)) {
        $errors[] = 'Unable to create the export folder at ' . relativeProjectPath($jobDirectory) . '.';
        return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
    }

    $manifestRows = [];
    $classificationCounts = ['regular' => 0, 'suspicious' => 0];
    $createdAt = date(DATE_ATOM);

    foreach ($selectedItems as $index => $item) {
        $split = $splitQueue[$index];
        $targetDirectory = $jobDirectory . DIRECTORY_SEPARATOR . $split . DIRECTORY_SEPARATOR . $item['classification'];

        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0777, true)) {
            $errors[] = 'Unable to create export split directory at ' . relativeProjectPath($targetDirectory) . '.';
            return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
        }

        $targetName = buildUniqueFileName(
            $targetDirectory,
            sanitizeFileBase($item['side'] . '__' . $item['cluster'] . '__' . pathinfo($item['filename'], PATHINFO_FILENAME)),
            strtolower((string) pathinfo($item['filename'], PATHINFO_EXTENSION))
        );
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $targetName;

        if (!copy($item['absolute_path'], $targetPath)) {
            $errors[] = 'Unable to copy ' . $item['relative_path'] . ' into the export pack.';
            return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
        }

        $classificationCounts[$item['classification']]++;
        $manifestRows[] = [
            $item['relative_path'],
            relativeProjectPath($targetPath),
            $split,
            $item['classification'],
            $item['side'],
            $item['classification'],
            $item['cluster'],
        ];
    }

    $manifestPath = $jobDirectory . DIRECTORY_SEPARATOR . 'manifest.csv';
    $manifestHandle = fopen($manifestPath, 'wb');

    if ($manifestHandle === false) {
        $errors[] = 'Unable to create the export manifest at ' . relativeProjectPath($manifestPath) . '.';
        return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
    }

    fputcsv($manifestHandle, ['source_path', 'target_path', 'split', 'class_name', 'side', 'classification', 'group']);

    foreach ($manifestRows as $row) {
        fputcsv($manifestHandle, $row);
    }

    fclose($manifestHandle);

    $pack = [
        'job_id' => $jobId,
        'pack_name' => $packName,
        'side' => $exportSide,
        'pack_size' => count($selectedItems),
        'created_at' => $createdAt,
        'split_counts' => $splitCounts,
        'classification_counts' => $classificationCounts,
        'grouped_samples_per_cluster' => (int) $groupedSamplesPerCluster,
        'class_targets' => $classTargets,
        'manifest_relative_path' => relativeProjectPath($manifestPath),
        'manifest_web_path' => toWebPath(relativeProjectPath($manifestPath)),
        'folder_relative_path' => relativeProjectPath($jobDirectory),
        'folder_web_path' => toWebPath(relativeProjectPath($jobDirectory)),
    ];

    $packPath = $jobDirectory . DIRECTORY_SEPARATOR . 'pack.json';

    if (!saveJsonFile($packPath, $pack, $errors, 'pack metadata')) {
        return ['errors' => $errors, 'messages' => $messages, 'pack' => null];
    }

    $exportHistory = mergeExportHistory([$pack], $exportHistory);

    if (!saveJsonFile($exportHistoryPath, array_values($exportHistory), $errors, 'export history')) {
        return ['errors' => $errors, 'messages' => $messages, 'pack' => null];
    }

    $messages[] = 'Created ' . $exportSide . '-side export pack ' . $packName . ' with ' . count($selectedItems) . ' images.';
    return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
}

function parsePercentage($value)
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
    return $parsed === false ? null : $parsed;
}

function countGroupedBuckets(array $dataset, $side = null)
{
    $count = 0;

    foreach ($dataset['clusters'] as $cluster) {
        if ($side !== null && $side !== '' && $cluster['side'] !== $side) {
            continue;
        }

        if ($cluster['cluster'] !== 'ungroupable') {
            $count++;
        }
    }

    return $count;
}

function buildExportPools(array $dataset, array $allowedClassifications, $side = null)
{
    $pools = [];

    foreach ($allowedClassifications as $classification) {
        $pools[$classification] = [
            'grouped_buckets' => [],
            'ungroupable_items' => [],
            'leftover_grouped_items' => [],
            'grouped_bucket_count' => 0,
            'total_available' => 0,
        ];
    }

    foreach ($dataset['clusters'] as $cluster) {
        if ($side !== null && $side !== '' && $cluster['side'] !== $side) {
            continue;
        }

        $classification = $cluster['classification'];
        $bucketItems = [];

        foreach ($cluster['images'] as $image) {
            $bucketItems[] = [
                'filename' => $image['filename'],
                'absolute_path' => $image['absolute_path'],
                'relative_path' => $image['relative_path'],
                'side' => $cluster['side'],
                'classification' => $classification,
                'cluster' => $cluster['cluster'],
            ];
        }

        $pools[$classification]['total_available'] += count($bucketItems);

        if ($cluster['cluster'] === 'ungroupable') {
            $pools[$classification]['ungroupable_items'] = array_merge($pools[$classification]['ungroupable_items'], $bucketItems);
            continue;
        }

        $pools[$classification]['grouped_bucket_count']++;
        $pools[$classification]['grouped_buckets'][] = [
            'key' => $cluster['key'],
            'items' => $bucketItems,
        ];
    }

    return $pools;
}

function allocateClassificationTargets($packSize, array $minimumByClassification, array $pools)
{
    $minimumTotal = array_sum($minimumByClassification);

    if ($packSize < $minimumTotal) {
        return null;
    }

    $targets = $minimumByClassification;
    $remaining = $packSize - $minimumTotal;
    $rotation = ['regular', 'suspicious'];
    $rotationIndex = 0;

    while ($remaining > 0) {
        $classification = $rotation[$rotationIndex % count($rotation)];
        $available = $pools[$classification]['total_available'];

        if ($targets[$classification] < $available) {
            $targets[$classification]++;
            $remaining--;
        }

        $rotationIndex++;

        if ($rotationIndex > 10000) {
            return null;
        }
    }

    return $targets;
}

function selectImagesForClassification(array $pool, $targetCount, $groupedSamplesPerCluster)
{
    $selected = [];
    $groupedBuckets = $pool['grouped_buckets'];
    $ungroupableItems = $pool['ungroupable_items'];

    shuffle($groupedBuckets);

    foreach ($groupedBuckets as &$bucket) {
        shuffle($bucket['items']);

        if (empty($bucket['items'])) {
            continue;
        }

        $mustTake = min((int) $groupedSamplesPerCluster, count($bucket['items']));

        for ($takeIndex = 0; $takeIndex < $mustTake; $takeIndex++) {
            $selected[] = array_shift($bucket['items']);

            if (count($selected) === $targetCount) {
                return $selected;
            }
        }
    }
    unset($bucket);

    shuffle($ungroupableItems);

    while (!empty($ungroupableItems) && count($selected) < $targetCount) {
        $selected[] = array_shift($ungroupableItems);
    }

    if (count($selected) === $targetCount) {
        return $selected;
    }

    $leftovers = [];

    foreach ($groupedBuckets as $bucket) {
        foreach ($bucket['items'] as $item) {
            $leftovers[] = $item;
        }
    }

    shuffle($leftovers);

    while (!empty($leftovers) && count($selected) < $targetCount) {
        $selected[] = array_shift($leftovers);
    }

    return $selected;
}

function allocateSplitCounts($total, array $ratios)
{
    $counts = ['train' => 0, 'val' => 0, 'test' => 0];
    $remainders = [];
    $assigned = 0;

    foreach ($ratios as $split => $ratio) {
        $raw = ($total * $ratio) / 100;
        $counts[$split] = (int) floor($raw);
        $remainders[$split] = $raw - $counts[$split];
        $assigned += $counts[$split];
    }

    while ($assigned < $total) {
        arsort($remainders);
        $split = (string) key($remainders);
        $counts[$split]++;
        $remainders[$split] = 0;
        $assigned++;
    }

    return $counts;
}

function handleResultSave(array $post, array $availablePackMap, array $existingResults, $resultHistoryPath)
{
    $errors = [];
    $messages = [];
    $result = null;

    $packId = isset($post['result_pack_id']) ? (string) $post['result_pack_id'] : '';
    $accuracy = parseDecimalPercentage(isset($post['accuracy']) ? $post['accuracy'] : null);
    $precision = parseDecimalPercentage(isset($post['precision']) ? $post['precision'] : null);
    $recall = parseDecimalPercentage(isset($post['recall']) ? $post['recall'] : null);
    $falsePositives = filter_var(isset($post['false_positives']) ? $post['false_positives'] : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $falseNegatives = filter_var(isset($post['false_negatives']) ? $post['false_negatives'] : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $notes = trim(isset($post['notes']) ? (string) $post['notes'] : '');

    if ($packId === '' || !isset($availablePackMap[$packId])) {
        $errors[] = 'Select a valid export pack before saving a result.';
    }

    if ($accuracy === null || $precision === null || $recall === null) {
        $errors[] = 'Accuracy, precision, and recall must be numbers between 0 and 100.';
    }

    if ($falsePositives === false || $falseNegatives === false) {
        $errors[] = 'False positive and false negative counts must be whole numbers of 0 or more.';
    }

    if (!empty($errors)) {
        return ['errors' => $errors, 'messages' => $messages, 'result' => $result, 'results' => $existingResults];
    }

    $pack = $availablePackMap[$packId];
    $result = [
        'id' => 'result_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
        'pack_id' => $packId,
        'pack_name' => $pack['pack_name'],
        'pack_side' => isset($pack['side']) ? $pack['side'] : 'mixed',
        'created_at' => date(DATE_ATOM),
        'accuracy' => $accuracy,
        'precision' => $precision,
        'recall' => $recall,
        'false_positives' => $falsePositives,
        'false_negatives' => $falseNegatives,
        'notes' => $notes,
        'pack_size' => isset($pack['pack_size']) ? (int) $pack['pack_size'] : 0,
    ];

    array_unshift($existingResults, $result);

    if (!saveJsonFile($resultHistoryPath, array_values($existingResults), $errors, 'training results')) {
        return ['errors' => $errors, 'messages' => $messages, 'result' => null, 'results' => $existingResults];
    }

    $messages[] = 'Saved training result for ' . $pack['pack_name'] . '.';
    return ['errors' => $errors, 'messages' => $messages, 'result' => $result, 'results' => $existingResults];
}

function parseDecimalPercentage($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    $number = (float) $value;

    if ($number < 0 || $number > 100) {
        return null;
    }

    return $number;
}

function buildRecommendation(array $dataset, array $exportHistory, array $trainingResults, array $allowedClassifications, $side = null)
{
    $groupedSamplesPerCluster = 1;
    $minimumPackSize = countGroupedBuckets($dataset, $side) * $groupedSamplesPerCluster;
    $ungroupableImages = countUngroupableImages($dataset, $side);
    $basePackSize = $minimumPackSize + min($ungroupableImages, max(4, (int) ceil($minimumPackSize * 0.5)));
    $classificationMinimums = buildClassificationMinimums($dataset, $allowedClassifications, $side);
    foreach ($classificationMinimums as $classification => $minimum) {
        $classificationMinimums[$classification] = $minimum * $groupedSamplesPerCluster;
    }
    $classTargets = allocateClassificationTargets(max($minimumPackSize, $basePackSize), $classificationMinimums, buildExportPools($dataset, $allowedClassifications, $side));
    $notes = [
        'Use the same grouped-items-per-folder value across every grouped folder.',
        'Use ungroupable images as the first fill source to improve generalization after cluster coverage is met.',
    ];

    $latestResult = null;
    foreach ($trainingResults as $result) {
        if ($side !== null && $side !== '' && isset($result['pack_side']) && $result['pack_side'] !== $side) {
            continue;
        }

        $latestResult = $result;
        break;
    }

    $packSize = max($minimumPackSize, $basePackSize);

    if ($latestResult !== null) {
        $packSize = max($packSize, (int) $latestResult['pack_size']);

        if ((float) $latestResult['recall'] < 80) {
            $packSize += max(2, (int) ceil($packSize * 0.15));
            $notes[] = 'Recent recall is below 80%, so the recommendation adds more suspicious examples.';
        }

        if ((float) $latestResult['precision'] < 80) {
            $packSize += max(2, (int) ceil($packSize * 0.1));
            $notes[] = 'Recent precision is below 80%, so the recommendation adds more regular hard negatives.';
        }

        if ((int) $latestResult['false_negatives'] > (int) $latestResult['false_positives']) {
            $notes[] = 'False negatives outnumber false positives, so prioritize suspicious coverage in the next pack.';
        } elseif ((int) $latestResult['false_positives'] > (int) $latestResult['false_negatives']) {
            $notes[] = 'False positives outnumber false negatives, so include more regular edge cases next.';
        }
    } else {
        $notes[] = 'No prior results yet, so the starting recommendation is based on complete cluster coverage plus ungroupable fill.';
    }

    $packSize = min(countImagesForSide($dataset, $side), $packSize);
    $poolData = buildExportPools($dataset, $allowedClassifications, $side);
    $classificationMinimums = buildClassificationMinimums($dataset, $allowedClassifications, $side);
    foreach ($classificationMinimums as $classification => $minimum) {
        $classificationMinimums[$classification] = $minimum * $groupedSamplesPerCluster;
    }
    $classTargets = allocateClassificationTargets($packSize, $classificationMinimums, $poolData);

    if ($classTargets === null) {
        $classTargets = ['regular' => 0, 'suspicious' => 0];
    }

    return [
        'minimum_pack_size' => $minimumPackSize,
        'pack_size' => $packSize,
        'class_targets' => $classTargets,
        'grouped_samples_per_cluster' => $groupedSamplesPerCluster,
        'notes' => array_values(array_unique($notes)),
    ];
}

function buildClassificationMinimums(array $dataset, array $allowedClassifications, $side = null)
{
    $minimums = [];

    foreach ($allowedClassifications as $classification) {
        $minimums[$classification] = 0;
    }

    foreach ($dataset['clusters'] as $cluster) {
        if ($side !== null && $side !== '' && $cluster['side'] !== $side) {
            continue;
        }

        if ($cluster['cluster'] === 'ungroupable') {
            continue;
        }

        $minimums[$cluster['classification']]++;
    }

    return $minimums;
}

function countUngroupableImages(array $dataset, $side = null)
{
    $count = 0;

    foreach ($dataset['clusters'] as $cluster) {
        if ($side !== null && $side !== '' && $cluster['side'] !== $side) {
            continue;
        }

        if ($cluster['cluster'] !== 'ungroupable') {
            continue;
        }

        $count += (int) $cluster['count'];
    }

    return $count;
}

function countImagesForSide(array $dataset, $side = null)
{
    if ($side === null || $side === '') {
        return (int) $dataset['stats']['total_images'];
    }

    $count = 0;

    foreach ($dataset['clusters'] as $cluster) {
        if ($cluster['side'] !== $side) {
            continue;
        }

        $count += (int) $cluster['count'];
    }

    return $count;
}

function stickyPostValue($key, $default)
{
    return isset($_POST[$key]) ? (string) $_POST[$key] : $default;
}

function stickySelected($key, $value)
{
    if (!isset($_POST[$key])) {
        return '';
    }

    return (string) $_POST[$key] === $value ? ' selected' : '';
}

function relativeProjectPath($path)
{
    $root = rtrim(str_replace('\\', '/', __DIR__), '/');
    $normalized = str_replace('\\', '/', $path);

    if (strpos($normalized, $root) === 0) {
        return ltrim(substr($normalized, strlen($root)), '/');
    }

    return $normalized;
}

function toWebPath($relativePath)
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

    if ($relativePath === '') {
        return '#';
    }

    $segments = explode('/', $relativePath);
    $encoded = [];

    foreach ($segments as $segment) {
        $encoded[] = rawurlencode($segment);
    }

    return implode('/', $encoded);
}

function formatBytes($bytes)
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / 1048576, 1) . ' MB';
}

function formatDateTime($value)
{
    $timestamp = strtotime((string) $value);

    if ($timestamp === false) {
        return (string) $value;
    }

    return date('Y-m-d H:i', $timestamp);
}
