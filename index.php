<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$requestPage = isset($_GET['page']) ? strtolower((string) $_GET['page']) : 'landing';

$projectRoot = $appConfig['project_root'] ?? __DIR__;
$datasetRoot = $appConfig['dataset_root'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dummy_data');
$exportRoot = $appConfig['export_root'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'exports');
$appStorageRoot = $appConfig['app_storage_root'] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app');
$allowedSides = ['front', 'back'];
$allowedClassifications = ['regular', 'suspicious'];
$allowedImageExtensions = ['png', 'jpg', 'jpeg', 'bmp', 'gif', 'webp', 'tif', 'tiff'];
$organizerPageSize = 100;

$errors = [];
$messages = [];
$recentExport = null;
$recentResult = null;

ensureDirectory($exportRoot, $errors, 'export storage');
ensureDirectory($appStorageRoot, $errors, 'application storage');

$pdo = getDbConnection($appConfig);
ensureSchema($pdo);
bootstrapIndexIfNeeded($pdo, $datasetRoot, $allowedSides, $allowedClassifications, $allowedImageExtensions, $messages);

$datasetStats = getDatasetStats($pdo, $allowedSides, $allowedClassifications);
$clusterSummary = getClusterSummary($pdo);
$exportHistory = getExportPacks($pdo);
$trainingResults = getTrainingResults($pdo);
$availablePackMap = indexBy($exportHistory, 'job_id');

$organizerSide = isset($_GET['organizer_side']) ? strtolower((string) $_GET['organizer_side']) : $allowedSides[0];
if (!in_array($organizerSide, $allowedSides, true)) {
    $organizerSide = $allowedSides[0];
}

$organizerClassification = isset($_GET['organizer_classification']) ? strtolower((string) $_GET['organizer_classification']) : $allowedClassifications[0];
if (!in_array($organizerClassification, $allowedClassifications, true)) {
    $organizerClassification = $allowedClassifications[0];
}

$organizerClusterChoices = clusterChoicesFor($clusterSummary, $organizerSide, $organizerClassification);

$organizerCluster = isset($_GET['organizer_cluster']) ? strtolower((string) $_GET['organizer_cluster']) : '';
if (!in_array($organizerCluster, $organizerClusterChoices, true)) {
    $organizerCluster = !empty($organizerClusterChoices) ? $organizerClusterChoices[0] : '';
}

$organizerPage = isset($_GET['organizer_page']) ? max(1, (int) $_GET['organizer_page']) : 1;

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
            reindexClusterFolder($pdo, $datasetRoot, $uploadResult['side'], $uploadResult['classification'], $uploadResult['cluster'], $allowedImageExtensions);
            $datasetStats = getDatasetStats($pdo, $allowedSides, $allowedClassifications);
            $clusterSummary = getClusterSummary($pdo);
            $organizerClusterChoices = clusterChoicesFor($clusterSummary, $organizerSide, $organizerClassification);
        }
    } elseif ($action === 'rescan-dataset') {
        $rescanResult = reindexDataset($pdo, $datasetRoot, $allowedSides, $allowedClassifications, $allowedImageExtensions);
        $messages[] = 'Dataset rescanned: ' . $rescanResult['scanned'] . ' image(s) indexed, ' . $rescanResult['removed'] . ' stale record(s) removed.';
        $datasetStats = getDatasetStats($pdo, $allowedSides, $allowedClassifications);
        $clusterSummary = getClusterSummary($pdo);
        $organizerClusterChoices = clusterChoicesFor($clusterSummary, $organizerSide, $organizerClassification);
    } elseif ($action === 'create-pack') {
        $packResult = handlePackCreation(
            $pdo,
            $_POST,
            $datasetRoot,
            $exportRoot,
            $allowedSides,
            $allowedClassifications
        );

        $errors = array_merge($errors, $packResult['errors']);
        $messages = array_merge($messages, $packResult['messages']);

        if ($packResult['pack'] !== null) {
            $recentExport = $packResult['pack'];
            $exportHistory = getExportPacks($pdo);
            $availablePackMap = indexBy($exportHistory, 'job_id');
        }
    } elseif ($action === 'save-result') {
        $resultSave = handleResultSave(
            $pdo,
            $_POST,
            $availablePackMap
        );

        $errors = array_merge($errors, $resultSave['errors']);
        $messages = array_merge($messages, $resultSave['messages']);

        if ($resultSave['result'] !== null) {
            $recentResult = $resultSave['result'];
            $trainingResults = getTrainingResults($pdo);
        }
    }
}

$organizerClusterChoices = clusterChoicesFor($clusterSummary, $organizerSide, $organizerClassification);

if (!in_array($organizerCluster, $organizerClusterChoices, true)) {
    $organizerCluster = !empty($organizerClusterChoices) ? $organizerClusterChoices[0] : '';
}

$organizerSelectedCard = null;
if ($organizerCluster !== '') {
    $organizerSelectedCard = getOrganizerCluster($pdo, $datasetRoot, $organizerSide, $organizerClassification, $organizerCluster, $organizerPage, $organizerPageSize);
}

$exportSideValue = stickyPostValue('export_side', $allowedSides[0]);
if (!in_array($exportSideValue, $allowedSides, true)) {
    $exportSideValue = $allowedSides[0];
}

$recommendation = buildRecommendation($pdo, $allowedClassifications, $exportSideValue);
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

            <form method="post" class="stack-form" style="margin-bottom: 1rem;">
                <input type="hidden" name="action" value="rescan-dataset">
                <button type="submit" class="button">Rescan dataset</button>
                <p class="help-text">Run after adding files outside the app (e.g. copying folders directly onto disk).</p>
            </form>

            <div class="stat-grid">
                <article class="stat-card">
                    <span class="stat-card__label">Total images</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) $datasetStats['total_images'], ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
                <article class="stat-card">
                    <span class="stat-card__label">Grouped images</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) $datasetStats['grouped_images'], ENT_QUOTES, 'UTF-8') ?></strong>
                </article>
                <article class="stat-card">
                    <span class="stat-card__label">Ungroupable images</span>
                    <strong class="stat-card__value"><?= htmlspecialchars((string) $datasetStats['ungroupable_images'], ENT_QUOTES, 'UTF-8') ?></strong>
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
                                    <strong><?= htmlspecialchars((string) ($datasetStats['by_side'][$side][$classification] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong>
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
                                    <strong><?= htmlspecialchars((string) ($datasetStats['by_classification'][$classification][$side] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong>
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
                        <?php if ($organizerSelectedCard['total_pages'] > 1) : ?>
                            <nav class="pagination" aria-label="Cluster image pages">
                                <?php for ($pageNumber = 1; $pageNumber <= $organizerSelectedCard['total_pages']; $pageNumber++) : ?>
                                    <a
                                        class="<?= $pageNumber === $organizerSelectedCard['page'] ? 'is-active' : '' ?>"
                                        href="organizer.php?organizer_side=<?= urlencode($organizerSide) ?>&organizer_classification=<?= urlencode($organizerClassification) ?>&organizer_cluster=<?= urlencode($organizerCluster) ?>&organizer_page=<?= $pageNumber ?>"
                                    ><?= $pageNumber ?></a>
                                <?php endfor; ?>
                            </nav>
                        <?php endif; ?>
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

function getDatasetStats(PDO $pdo, array $allowedSides, array $allowedClassifications)
{
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

    $stmt = $pdo->query('SELECT side, classification, cluster, COUNT(*) AS image_count FROM images GROUP BY side, classification, cluster');

    foreach ($stmt as $row) {
        $side = $row['side'];
        $classification = $row['classification'];
        $count = (int) $row['image_count'];

        $stats['total_images'] += $count;

        if (isset($stats['by_side'][$side][$classification])) {
            $stats['by_side'][$side][$classification] += $count;
        }

        if (isset($stats['by_classification'][$classification][$side])) {
            $stats['by_classification'][$classification][$side] += $count;
        }

        if ($row['cluster'] === 'ungroupable') {
            $stats['ungroupable_images'] += $count;
        } else {
            $stats['grouped_images'] += $count;
        }
    }

    return $stats;
}

/**
 * Cheap cluster summary (side, classification, cluster, count) used for the
 * organizer dropdown and pool sizing. Never loads individual image rows.
 */
function getClusterSummary(PDO $pdo)
{
    $stmt = $pdo->query('SELECT side, classification, cluster, COUNT(*) AS image_count FROM images GROUP BY side, classification, cluster ORDER BY side, classification, cluster');
    $summary = [];

    foreach ($stmt as $row) {
        $summary[] = [
            'key' => $row['side'] . '/' . $row['classification'] . '/' . $row['cluster'],
            'side' => $row['side'],
            'classification' => $row['classification'],
            'cluster' => $row['cluster'],
            'count' => (int) $row['image_count'],
        ];
    }

    return $summary;
}

function clusterChoicesFor(array $clusterSummary, $side, $classification)
{
    $choices = [];

    foreach ($clusterSummary as $cluster) {
        if ($cluster['side'] !== $side || $cluster['classification'] !== $classification) {
            continue;
        }

        $choices[] = $cluster['cluster'];
    }

    sort($choices, SORT_NATURAL | SORT_FLAG_CASE);

    return $choices;
}

/**
 * Loads a single page of images for one side/classification/cluster bucket.
 * Only this one bucket is ever fully loaded into PHP, keeping organizer page
 * loads fast regardless of overall dataset size.
 */
function getOrganizerCluster(PDO $pdo, $datasetRoot, $side, $classification, $cluster, $page, $pageSize)
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE side = :side AND classification = :classification AND cluster = :cluster');
    $countStmt->execute(['side' => $side, 'classification' => $classification, 'cluster' => $cluster]);
    $total = (int) $countStmt->fetchColumn();

    if ($total === 0) {
        return null;
    }

    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $pageSize;

    $stmt = $pdo->prepare(
        'SELECT filename, relative_path FROM images '
        . 'WHERE side = :side AND classification = :classification AND cluster = :cluster '
        . 'ORDER BY filename ASC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue('side', $side);
    $stmt->bindValue('classification', $classification);
    $stmt->bindValue('cluster', $cluster);
    $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $images = [];
    foreach ($stmt as $row) {
        $images[] = [
            'filename' => $row['filename'],
            'relative_path' => datasetRelativeWebPath($datasetRoot, $row['relative_path']),
            'web_path' => toWebPath(datasetRelativeWebPath($datasetRoot, $row['relative_path'])),
        ];
    }

    $clusterDirectory = $datasetRoot . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $classification . DIRECTORY_SEPARATOR . $cluster;

    return [
        'key' => $side . '/' . $classification . '/' . $cluster,
        'side' => $side,
        'classification' => $classification,
        'cluster' => $cluster,
        'count' => $total,
        'relative_directory' => relativeProjectPath($clusterDirectory),
        'images' => $images,
        'page' => $page,
        'total_pages' => $totalPages,
    ];
}

/**
 * Builds the web-servable path for an image given its path relative to the
 * dataset root (side/classification/cluster/filename form stored in the DB).
 */
function datasetRelativeWebPath($datasetRoot, $relativePathFromDatasetRoot)
{
    $projectRelativeDatasetRoot = relativeProjectPath($datasetRoot);

    if ($projectRelativeDatasetRoot === '') {
        return $relativePathFromDatasetRoot;
    }

    return rtrim($projectRelativeDatasetRoot, '/') . '/' . $relativePathFromDatasetRoot;
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

function getExportPacks(PDO $pdo)
{
    $stmt = $pdo->query('SELECT * FROM export_packs ORDER BY created_at DESC');
    $packs = [];

    foreach ($stmt as $row) {
        $packs[] = mapExportPackRow($row);
    }

    return $packs;
}

function mapExportPackRow(array $row)
{
    $manifestRelativePath = (string) $row['manifest_relative_path'];
    $folderRelativePath = (string) $row['folder_relative_path'];

    return [
        'job_id' => $row['job_id'],
        'pack_name' => $row['pack_name'],
        'side' => $row['side'],
        'pack_size' => (int) $row['pack_size'],
        'created_at' => $row['created_at'],
        'grouped_samples_per_cluster' => (int) $row['grouped_samples_per_cluster'],
        'split_counts' => [
            'train' => (int) $row['train_count'],
            'val' => (int) $row['val_count'],
            'test' => (int) $row['test_count'],
        ],
        'classification_counts' => [
            'regular' => (int) $row['regular_count'],
            'suspicious' => (int) $row['suspicious_count'],
        ],
        'class_targets' => json_decode((string) $row['class_targets_json'], true) ?: [],
        'manifest_relative_path' => $manifestRelativePath,
        'manifest_web_path' => toWebPath($manifestRelativePath),
        'folder_relative_path' => $folderRelativePath,
        'folder_web_path' => toWebPath($folderRelativePath),
    ];
}

function getTrainingResults(PDO $pdo)
{
    $stmt = $pdo->query('SELECT * FROM training_results ORDER BY created_at DESC');
    $results = [];

    foreach ($stmt as $row) {
        $results[] = [
            'id' => $row['id'],
            'pack_id' => $row['pack_id'],
            'pack_name' => $row['pack_name'],
            'pack_side' => $row['pack_side'],
            'created_at' => $row['created_at'],
            'accuracy' => (float) $row['accuracy'],
            'precision' => (float) $row['precision_value'],
            'recall' => (float) $row['recall_value'],
            'false_positives' => (int) $row['false_positives'],
            'false_negatives' => (int) $row['false_negatives'],
            'notes' => $row['notes'],
            'pack_size' => (int) $row['pack_size'],
        ];
    }

    return $results;
}

/**
 * Reconciles the export_packs table against manifest folders found on disk.
 * On-demand only (not run on every page load) since filesystem discovery was
 * one of the original performance offenders.
 */
function reconcileExports(PDO $pdo, $exportRoot)
{
    if (!is_dir($exportRoot)) {
        return 0;
    }

    $entries = scandir($exportRoot);

    if (!is_array($entries)) {
        return 0;
    }

    $existingStmt = $pdo->query('SELECT job_id FROM export_packs');
    $existingJobIds = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));
    $imported = 0;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || isset($existingJobIds[$entry])) {
            continue;
        }

        $jobDirectory = $exportRoot . DIRECTORY_SEPARATOR . $entry;

        if (!is_dir($jobDirectory)) {
            continue;
        }

        $packPath = $jobDirectory . DIRECTORY_SEPARATOR . 'pack.json';
        $manifestPath = $jobDirectory . DIRECTORY_SEPARATOR . 'manifest.csv';
        $pack = null;

        if (is_file($packPath)) {
            $decoded = json_decode((string) file_get_contents($packPath), true);

            if (is_array($decoded)) {
                $pack = $decoded;
            }
        }

        if ($pack === null && is_file($manifestPath)) {
            $parsed = parseManifestCounts($manifestPath);
            $pack = [
                'job_id' => $entry,
                'pack_name' => $entry,
                'side' => $parsed['side'],
                'pack_size' => $parsed['pack_size'],
                'created_at' => date(DATE_ATOM, (int) filemtime($manifestPath)),
                'split_counts' => $parsed['split_counts'],
                'classification_counts' => $parsed['classification_counts'],
                'manifest_relative_path' => relativeProjectPath($manifestPath),
                'folder_relative_path' => relativeProjectPath($jobDirectory),
            ];
        }

        if ($pack === null) {
            continue;
        }

        insertExportPack($pdo, $pack);
        $imported++;
    }

    return $imported;
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

function insertExportPack(PDO $pdo, array $pack)
{
    $splitCounts = is_array($pack['split_counts'] ?? null) ? $pack['split_counts'] : ['train' => 0, 'val' => 0, 'test' => 0];
    $classificationCounts = is_array($pack['classification_counts'] ?? null) ? $pack['classification_counts'] : ['regular' => 0, 'suspicious' => 0];
    $side = in_array($pack['side'] ?? null, ['front', 'back'], true) ? $pack['side'] : 'mixed';
    $createdAt = isset($pack['created_at']) ? date('Y-m-d H:i:s', strtotime((string) $pack['created_at']) ?: time()) : date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
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

    $stmt->execute([
        'job_id' => $pack['job_id'],
        'pack_name' => $pack['pack_name'] ?? $pack['job_id'],
        'side' => $side,
        'pack_size' => (int) ($pack['pack_size'] ?? 0),
        'grouped_samples_per_cluster' => (int) ($pack['grouped_samples_per_cluster'] ?? 0),
        'train_ratio' => (int) ($pack['train_ratio'] ?? 0),
        'val_ratio' => (int) ($pack['val_ratio'] ?? 0),
        'test_ratio' => (int) ($pack['test_ratio'] ?? 0),
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
        return ['errors' => $errors, 'messages' => $messages, 'uploaded' => $uploaded, 'side' => $side, 'classification' => $classification, 'cluster' => $cluster];
    }

    $targetDirectory = $datasetRoot . DIRECTORY_SEPARATOR . $side . DIRECTORY_SEPARATOR . $classification . DIRECTORY_SEPARATOR . $cluster;

    if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0777, true)) {
        $errors[] = 'Unable to create the target cluster directory at ' . relativeProjectPath($targetDirectory) . '.';
        return ['errors' => $errors, 'messages' => $messages, 'uploaded' => $uploaded, 'side' => $side, 'classification' => $classification, 'cluster' => $cluster];
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

    return ['errors' => $errors, 'messages' => $messages, 'uploaded' => $uploaded, 'side' => $side, 'classification' => $classification, 'cluster' => $cluster];
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

function handlePackCreation(PDO $pdo, array $post, $datasetRoot, $exportRoot, array $allowedSides, array $allowedClassifications)
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

    $groupedBucketCount = countGroupedBuckets($pdo, $exportSide);

    if ($groupedSamplesPerCluster === false || $groupedSamplesPerCluster === null) {
        $errors[] = 'Enter a valid grouped-items-per-folder value.';
    } else {
        $minimumPackSize = $groupedBucketCount * $groupedSamplesPerCluster;
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

    $pools = buildExportPools($pdo, $allowedClassifications, $exportSide);
    $availableImageCount = 0;
    $minimumByClassification = [];

    $insufficientGroupedBuckets = [];

    foreach ($allowedClassifications as $classification) {
        $availableImageCount += $pools[$classification]['total_available'];

        if ($groupedSamplesPerCluster !== false && $groupedSamplesPerCluster !== null) {
            foreach ($pools[$classification]['grouped_buckets'] as $bucket) {
                if ($bucket['count'] < $groupedSamplesPerCluster) {
                    $insufficientGroupedBuckets[] = $bucket['key'] . ' (' . $bucket['count'] . ' available)';
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
        $selection = selectImagesForClassification($pdo, $exportSide, $classification, $pools[$classification], $classTargets[$classification], (int) $groupedSamplesPerCluster);

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

        $sourcePath = $datasetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['relative_path']);

        $targetName = buildUniqueFileName(
            $targetDirectory,
            sanitizeFileBase($item['side'] . '__' . $item['cluster'] . '__' . pathinfo($item['filename'], PATHINFO_FILENAME)),
            strtolower((string) pathinfo($item['filename'], PATHINFO_EXTENSION))
        );
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $targetName;

        if (!copy($sourcePath, $targetPath)) {
            $errors[] = 'Unable to copy ' . $item['relative_path'] . ' into the export pack.';
            return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
        }

        $classificationCounts[$item['classification']]++;
        $manifestRows[] = [
            relativeProjectPath($sourcePath),
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
        'train_ratio' => $trainRatio,
        'val_ratio' => $valRatio,
        'test_ratio' => $testRatio,
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

    insertExportPack($pdo, $pack);

    $messages[] = 'Created ' . $exportSide . '-side export pack ' . $packName . ' with ' . count($selectedItems) . ' images.';
    return ['errors' => $errors, 'messages' => $messages, 'pack' => $pack];
}

function parsePercentage($value)
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
    return $parsed === false ? null : $parsed;
}

function countGroupedBuckets(PDO $pdo, $side = null)
{
    $sql = "SELECT COUNT(*) FROM (SELECT 1 FROM images WHERE cluster != 'ungroupable'";
    $params = [];

    if ($side !== null && $side !== '') {
        $sql .= ' AND side = :side';
        $params['side'] = $side;
    }

    $sql .= ' GROUP BY side, classification, cluster) AS grouped_buckets';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * Builds per-classification pools with grouped-bucket counts (not full image
 * lists) plus ungroupable/total counts, used for pack-size validation and
 * quota allocation. Actual image rows are only fetched later in
 * selectImagesForClassification via targeted, LIMITed queries.
 */
function buildExportPools(PDO $pdo, array $allowedClassifications, $side = null)
{
    $pools = [];

    foreach ($allowedClassifications as $classification) {
        $pools[$classification] = [
            'grouped_buckets' => [],
            'grouped_bucket_count' => 0,
            'ungroupable_count' => 0,
            'total_available' => 0,
        ];
    }

    $sql = 'SELECT side, classification, cluster, COUNT(*) AS image_count FROM images';
    $params = [];

    if ($side !== null && $side !== '') {
        $sql .= ' WHERE side = :side';
        $params['side'] = $side;
    }

    $sql .= ' GROUP BY side, classification, cluster';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt as $row) {
        $classification = $row['classification'];

        if (!isset($pools[$classification])) {
            continue;
        }

        $count = (int) $row['image_count'];
        $pools[$classification]['total_available'] += $count;

        if ($row['cluster'] === 'ungroupable') {
            $pools[$classification]['ungroupable_count'] += $count;
            continue;
        }

        $pools[$classification]['grouped_bucket_count']++;
        $pools[$classification]['grouped_buckets'][] = [
            'key' => $row['side'] . '/' . $row['classification'] . '/' . $row['cluster'],
            'side' => $row['side'],
            'cluster' => $row['cluster'],
            'count' => $count,
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

/**
 * Row count above which ORDER BY RAND() LIMIT N is considered too expensive
 * (it forces MySQL to score and sort every matching row). Buckets at or
 * above this size are sampled via random id offsets instead, which only
 * touches the indexed id column and stays cheap regardless of table size.
 */
const RANDOM_SORT_ROW_THRESHOLD = 5000;

/**
 * Samples up to $limit image rows matching the given WHERE clause, avoiding
 * ORDER BY RAND() on large result sets. Strategy:
 *  - If the matching set is small (<= RANDOM_SORT_ROW_THRESHOLD), a plain
 *    ORDER BY RAND() LIMIT is simplest and fast enough.
 *  - Otherwise, fetch just the matching ids (a cheap, fully index-covered
 *    scan since (side, classification, cluster) is indexed and InnoDB
 *    secondary indexes implicitly carry the primary key), pick random ids in
 *    PHP, then fetch those specific rows in one `WHERE id IN (...)` lookup.
 *    This avoids both sorting the full row set and the O(offset) rescans
 *    that a naive LIMIT/OFFSET approach would incur, so it stays fast even
 *    well past 50k+ rows (e.g. a large ungroupable bucket).
 *
 * $whereSql must reference only :side/:classification/:cluster-style named
 * params supplied via $params; an optional list of already-used ids can be
 * excluded via $excludeIds.
 */
function sampleRandomImageRows(PDO $pdo, $whereSql, array $params, $limit, array $excludeIds = [])
{
    $limit = (int) $limit;

    if ($limit <= 0) {
        return [];
    }

    $excludeSql = empty($excludeIds) ? '' : (' AND id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')');
    $fullWhere = $whereSql . $excludeSql;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE ' . $fullWhere);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    if ($total <= 0) {
        return [];
    }

    // If we need everything (or more than exists), just take it all in bulk.
    if ($limit >= $total) {
        $stmt = $pdo->prepare(
            'SELECT id, filename, relative_path, side, classification, cluster FROM images '
            . 'WHERE ' . $fullWhere . ' ORDER BY id LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $total, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($total <= RANDOM_SORT_ROW_THRESHOLD) {
        $stmt = $pdo->prepare(
            'SELECT id, filename, relative_path, side, classification, cluster FROM images '
            . 'WHERE ' . $fullWhere . ' ORDER BY RAND() LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Large bucket: fetch only the matching ids (covered by the index on
    // side/classification/cluster, so no full row/table scan is needed),
    // sample randomly in PHP, then fetch the chosen rows by primary key.
    $idStmt = $pdo->prepare('SELECT id FROM images WHERE ' . $fullWhere);
    $idStmt->execute($params);
    $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    if (empty($ids)) {
        return [];
    }

    $sampleCount = min($limit, count($ids));
    $chosenKeys = array_rand($ids, $sampleCount);

    if (!is_array($chosenKeys)) {
        $chosenKeys = [$chosenKeys];
    }

    $chosenIds = [];
    foreach ($chosenKeys as $key) {
        $chosenIds[] = (int) $ids[$key];
    }

    $placeholders = implode(',', array_fill(0, count($chosenIds), '?'));
    $rowStmt = $pdo->prepare(
        'SELECT id, filename, relative_path, side, classification, cluster FROM images '
        . 'WHERE id IN (' . $placeholders . ')'
    );
    $rowStmt->execute($chosenIds);

    return $rowStmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Selects images for one classification directly via SQL: takes
 * groupedSamplesPerCluster from every grouped bucket first, then fills from
 * ungroupable, then from any grouped leftovers. All sampling is delegated to
 * sampleRandomImageRows(), which switches from ORDER BY RAND() to random-
 * offset sampling once a bucket grows past RANDOM_SORT_ROW_THRESHOLD rows
 * (e.g. large ungroupable buckets), avoiding a full-table sort in that case.
 */
function selectImagesForClassification(PDO $pdo, $side, $classification, array $pool, $targetCount, $groupedSamplesPerCluster)
{
    $selected = [];
    $usedIds = [];

    $groupedBuckets = $pool['grouped_buckets'];
    shuffle($groupedBuckets);

    foreach ($groupedBuckets as $bucket) {
        if (count($selected) >= $targetCount) {
            break;
        }

        $mustTake = min((int) $groupedSamplesPerCluster, $bucket['count']);

        if ($mustTake <= 0) {
            continue;
        }

        $rows = sampleRandomImageRows(
            $pdo,
            'side = :side AND classification = :classification AND cluster = :cluster',
            ['side' => $side, 'classification' => $classification, 'cluster' => $bucket['cluster']],
            $mustTake,
            array_keys($usedIds)
        );

        foreach ($rows as $row) {
            $selected[] = $row;
            $usedIds[$row['id']] = true;

            if (count($selected) === $targetCount) {
                return $selected;
            }
        }
    }

    if (count($selected) < $targetCount) {
        $remaining = $targetCount - count($selected);
        $rows = sampleRandomImageRows(
            $pdo,
            "side = :side AND classification = :classification AND cluster = 'ungroupable'",
            ['side' => $side, 'classification' => $classification],
            $remaining,
            array_keys($usedIds)
        );

        foreach ($rows as $row) {
            $selected[] = $row;
            $usedIds[$row['id']] = true;
        }
    }

    if (count($selected) < $targetCount) {
        $remaining = $targetCount - count($selected);
        $rows = sampleRandomImageRows(
            $pdo,
            "side = :side AND classification = :classification AND cluster != 'ungroupable'",
            ['side' => $side, 'classification' => $classification],
            $remaining,
            array_keys($usedIds)
        );

        foreach ($rows as $row) {
            $selected[] = $row;
        }
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

function handleResultSave(PDO $pdo, array $post, array $availablePackMap)
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
        return ['errors' => $errors, 'messages' => $messages, 'result' => $result];
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

    $stmt = $pdo->prepare(
        'INSERT INTO training_results ('
        . 'id, pack_id, pack_name, pack_side, pack_size, accuracy, precision_value, recall_value, '
        . 'false_positives, false_negatives, notes, created_at'
        . ') VALUES ('
        . ':id, :pack_id, :pack_name, :pack_side, :pack_size, :accuracy, :precision_value, :recall_value, '
        . ':false_positives, :false_negatives, :notes, :created_at'
        . ')'
    );

    $stmt->execute([
        'id' => $result['id'],
        'pack_id' => $result['pack_id'],
        'pack_name' => $result['pack_name'],
        'pack_side' => $result['pack_side'],
        'pack_size' => $result['pack_size'],
        'accuracy' => $result['accuracy'],
        'precision_value' => $result['precision'],
        'recall_value' => $result['recall'],
        'false_positives' => $result['false_positives'],
        'false_negatives' => $result['false_negatives'],
        'notes' => $result['notes'],
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $messages[] = 'Saved training result for ' . $pack['pack_name'] . '.';
    return ['errors' => $errors, 'messages' => $messages, 'result' => $result];
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

function buildRecommendation(PDO $pdo, array $allowedClassifications, $side = null)
{
    $groupedSamplesPerCluster = 1;
    $minimumPackSize = countGroupedBuckets($pdo, $side) * $groupedSamplesPerCluster;
    $ungroupableImages = countUngroupableImages($pdo, $side);
    $basePackSize = $minimumPackSize + min($ungroupableImages, max(4, (int) ceil($minimumPackSize * 0.5)));
    $classificationMinimums = buildClassificationMinimums($pdo, $allowedClassifications, $side);
    foreach ($classificationMinimums as $classification => $minimum) {
        $classificationMinimums[$classification] = $minimum * $groupedSamplesPerCluster;
    }
    $classTargets = allocateClassificationTargets(max($minimumPackSize, $basePackSize), $classificationMinimums, buildExportPools($pdo, $allowedClassifications, $side));
    $notes = [
        'Use the same grouped-items-per-folder value across every grouped folder.',
        'Use ungroupable images as the first fill source to improve generalization after cluster coverage is met.',
    ];

    $latestResult = null;
    $stmt = $pdo->prepare('SELECT * FROM training_results WHERE (:side IS NULL OR :side2 = \'\' OR pack_side = :side3) ORDER BY created_at DESC LIMIT 1');
    $stmt->execute(['side' => $side, 'side2' => (string) $side, 'side3' => (string) $side]);
    $latestResultRow = $stmt->fetch();

    if ($latestResultRow !== false) {
        $latestResult = [
            'recall' => (float) $latestResultRow['recall_value'],
            'precision' => (float) $latestResultRow['precision_value'],
            'false_negatives' => (int) $latestResultRow['false_negatives'],
            'false_positives' => (int) $latestResultRow['false_positives'],
            'pack_size' => (int) $latestResultRow['pack_size'],
        ];
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

    $packSize = min(countImagesForSide($pdo, $side), $packSize);
    $poolData = buildExportPools($pdo, $allowedClassifications, $side);
    $classificationMinimums = buildClassificationMinimums($pdo, $allowedClassifications, $side);
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

function buildClassificationMinimums(PDO $pdo, array $allowedClassifications, $side = null)
{
    $minimums = [];

    foreach ($allowedClassifications as $classification) {
        $minimums[$classification] = 0;
    }

    $sql = "SELECT classification, COUNT(*) AS bucket_count FROM (SELECT classification, side, cluster FROM images WHERE cluster != 'ungroupable'";
    $params = [];

    if ($side !== null && $side !== '') {
        $sql .= ' AND side = :side';
        $params['side'] = $side;
    }

    $sql .= ' GROUP BY side, classification, cluster) AS buckets GROUP BY classification';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt as $row) {
        if (isset($minimums[$row['classification']])) {
            $minimums[$row['classification']] = (int) $row['bucket_count'];
        }
    }

    return $minimums;
}

function countUngroupableImages(PDO $pdo, $side = null)
{
    $sql = "SELECT COUNT(*) FROM images WHERE cluster = 'ungroupable'";
    $params = [];

    if ($side !== null && $side !== '') {
        $sql .= ' AND side = :side';
        $params['side'] = $side;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function countImagesForSide(PDO $pdo, $side = null)
{
    $sql = 'SELECT COUNT(*) FROM images';
    $params = [];

    if ($side !== null && $side !== '') {
        $sql .= ' WHERE side = :side';
        $params['side'] = $side;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
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
