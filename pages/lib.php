<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$currentPage = 'defect-library';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function routeLabel(?string $route): string
{
    return match ((string)$route) {
        'REC_WHATNOT'      => 'Whatnot',
        'REC_WHOLESALE'    => 'Wholesale',
        'REC_REPAIR'       => 'Repair',
        'REC_HARVEST'      => 'Harvest',
        'REVIEW_REQUIRED'  => 'Review Required',
        'SCRAP',
        'REC_SCRAP'        => 'Scrap',
        default            => (string)$route,
    };
}

function routeClass(?string $route): string
{
    return match ((string)$route) {
        'REC_WHATNOT'      => 'route-whatnot',
        'REC_WHOLESALE'    => 'route-wholesale',
        'REC_REPAIR'       => 'route-repair',
        'REC_HARVEST'      => 'route-harvest',
        'REVIEW_REQUIRED'  => 'route-review',
        'SCRAP',
        'REC_SCRAP'        => 'route-scrap',
        default            => 'route-unknown',
    };
}

/*
|--------------------------------------------------------------------------
| Short Description Column Detection
|--------------------------------------------------------------------------
|
| Detect the actual short-description column without assuming its exact
| database name. This keeps the page working even if the column is named
| differently or is not present yet.
|
*/

$shortDescriptionColumn = null;

try {
    $columnStmt = $pdo->query("SHOW COLUMNS FROM defect_library");
    $columnNames = $columnStmt->fetchAll(PDO::FETCH_COLUMN);

    $preferredShortColumns = [
        'short_description',
        'short_definition',
        'short_desc',
        'definition_short',
        'short_description_en',
    ];

    foreach ($preferredShortColumns as $candidate) {
        if (in_array($candidate, $columnNames, true)) {
            $shortDescriptionColumn = $candidate;
            break;
        }
    }

    if ($shortDescriptionColumn === null) {
        foreach ($columnNames as $columnName) {
            if (preg_match('/(?:short.*(?:description|definition)|(?:description|definition).*short)/i', (string)$columnName)) {
                $shortDescriptionColumn = (string)$columnName;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $shortDescriptionColumn = null;
}

$shortDescriptionSql = $shortDescriptionColumn !== null
    ? '`' . str_replace('`', '``', $shortDescriptionColumn) . '`'
    : null;

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search   = trim((string)($_GET['search'] ?? ''));
$family   = trim((string)($_GET['family'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$route    = trim((string)($_GET['route'] ?? ''));

$where = ['is_active = 1'];
$params = [];

if ($search !== '') {
    $searchConditions = [
        'defect_code LIKE :search_code',
        'device_family LIKE :search_family',
        'main_category LIKE :search_category',
        'subcategory LIKE :search_subcategory',
        'erp_description LIKE :search_erp_description',
        'definition_en LIKE :search_definition_en',
        'definition_es LIKE :search_definition_es',
        'use_when LIKE :search_use_when',
        'do_not_use_when LIKE :search_do_not_use',
        'search_keywords LIKE :search_keywords',
    ];

    if ($shortDescriptionSql !== null) {
        $searchConditions[] = $shortDescriptionSql . ' LIKE :search_short_description';
    }

    $where[] = '(' . implode(' OR ', $searchConditions) . ')';

    $like = '%' . $search . '%';

    $params['search_code'] = $like;
    $params['search_family'] = $like;
    $params['search_category'] = $like;
    $params['search_subcategory'] = $like;
    $params['search_erp_description'] = $like;

    if ($shortDescriptionSql !== null) {
        $params['search_short_description'] = $like;
    }

    $params['search_definition_en'] = $like;
    $params['search_definition_es'] = $like;
    $params['search_use_when'] = $like;
    $params['search_do_not_use'] = $like;
    $params['search_keywords'] = $like;
}

if ($family !== '') {
    $where[] = 'device_family = :filter_family';
    $params['filter_family'] = $family;
}

if ($category !== '') {
    $where[] = 'main_category = :filter_category';
    $params['filter_category'] = $category;
}

if ($route !== '') {
    $where[] = 'recovery_hub_route = :filter_route';
    $params['filter_route'] = $route;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Library
|--------------------------------------------------------------------------
*/

$shortDescriptionSelect = $shortDescriptionSql !== null
    ? $shortDescriptionSql . ' AS short_description'
    : 'NULL AS short_description';

$sql = "
    SELECT
        id,
        erp_defect_id,
        defect_code,
        device_family,
        main_category,
        subcategory,
        defect_type,
        erp_description,
        {$shortDescriptionSelect},
        variance_percentage,
        repair_tier,
        erp_recovery_type,
        recovery_hub_route,
        definition_en,
        definition_es,
        use_when,
        do_not_use_when,
        search_keywords
    FROM defect_library
    {$whereSql}
    ORDER BY
        device_family,
        main_category,
        subcategory,
        defect_code
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$defects = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$families = $pdo->query("
    SELECT DISTINCT device_family
    FROM defect_library
    WHERE is_active = 1
      AND device_family IS NOT NULL
      AND device_family <> ''
    ORDER BY device_family
")->fetchAll(PDO::FETCH_COLUMN);

$categories = $pdo->query("
    SELECT DISTINCT main_category
    FROM defect_library
    WHERE is_active = 1
      AND main_category IS NOT NULL
      AND main_category <> ''
    ORDER BY main_category
")->fetchAll(PDO::FETCH_COLUMN);

$routes = $pdo->query("
    SELECT DISTINCT recovery_hub_route
    FROM defect_library
    WHERE is_active = 1
      AND recovery_hub_route IS NOT NULL
      AND recovery_hub_route <> ''
    ORDER BY recovery_hub_route
")->fetchAll(PDO::FETCH_COLUMN);

$totalLibrary = (int)$pdo->query("
    SELECT COUNT(*)
    FROM defect_library
    WHERE is_active = 1
")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Defect Library | Recovery Hub</title>

    <link
        rel="stylesheet"
        href="/assets/css/erp-theme-v5.css"
    >

    <style>
        .library-page {
            max-width:1600px;
            margin:0 auto;
        }

        .library-intro {
            margin-bottom:18px;
        }

        .library-intro p {
            color:#596174;
            margin:6px 0 0;
        }

        .filter-card {
            padding:16px;
            margin-bottom:18px;
        }

        .filters {
            display:grid;
            grid-template-columns:minmax(280px,2fr) 1fr 1fr 1fr auto;
            gap:12px;
            align-items:end;
        }

        .field label {
            display:block;
            margin-bottom:6px;
            font-size:12px;
            font-weight:600;
            color:#596174;
        }

        .field input,
        .field select {
            width:100%;
            height:40px;
            border:1px solid #d9dde5;
            border-radius:5px;
            background:#fff;
            color:#26304a;
            padding:0 11px;
            outline:none;
        }

        .field input:focus,
        .field select:focus {
            border-color:#1683f8;
            box-shadow:0 0 0 2px rgba(22,131,248,.10);
        }

        .btn {
            height:40px;
            border-radius:5px;
            padding:0 17px;
            cursor:pointer;
            font-weight:600;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-primary {
            border:0;
            background:#1976d2;
            color:#fff;
        }

        .btn-secondary {
            background:#fff;
            color:#26304a;
            border:1px solid #d9dde5;
        }

        .filter-actions {
            display:flex;
            gap:8px;
        }

        .library-toolbar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:14px 16px;
            margin-bottom:12px;
        }

        .library-toolbar-title {
            font-size:14px;
            font-weight:700;
            color:#26304a;
        }

        .library-toolbar-meta {
            font-size:12px;
            color:#858998;
        }

        .defect-grid {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }

        .defect-card {
            background:#fff;
            border:1px solid #e4e7ec;
            border-radius:7px;
            overflow:hidden;
        }

        .defect-card summary {
            list-style:none;
            cursor:pointer;
            padding:16px;
        }

        .defect-card summary::-webkit-details-marker {
            display:none;
        }

        .defect-card summary:hover {
            background:#f8fbff;
        }

        .defect-heading {
            display:flex;
            justify-content:space-between;
            gap:16px;
            align-items:flex-start;
        }

        .defect-code {
            color:#26304a;
            font-size:15px;
            font-weight:700;
            word-break:break-word;
        }

        .defect-family {
            margin-top:5px;
            color:#858998;
            font-size:12px;
        }

        /*
        |--------------------------------------------------------------------------
        | Recovery Route Badges
        |--------------------------------------------------------------------------
        |
        | Soft fluorescent/highlighter effect.
        | Designed to stand out without breaking the discreet ERP theme.
        |
        */

        .route-badge {
            display:inline-flex;
            align-items:center;
            min-height:26px;
            padding:4px 10px;
            border-radius:6px;
            font-size:11px;
            font-weight:700;
            letter-spacing:.2px;
            line-height:1.2;
            white-space:nowrap;
            transition:
                box-shadow .15s ease,
                transform .15s ease;
        }

        /* WHATNOT — fluorescent green */

        .route-whatnot {
            background:#B9FFCC;
            color:#087A36;
            border:1px solid #72F59A;
            box-shadow:0 0 7px rgba(72,255,128,.16);
        }

        /* WHOLESALE — electric blue */

        .route-wholesale {
            background:#C7E8FF;
            color:#075A9C;
            border:1px solid #72C8FF;
            box-shadow:0 0 7px rgba(55,170,255,.14);
        }

        /* REPAIR — fluorescent orange */

        .route-repair {
            background:#FFE2A8;
            color:#9A4B00;
            border:1px solid #FFBE55;
            box-shadow:0 0 7px rgba(255,166,0,.15);
        }

        /* HARVEST — fluorescent violet */

        .route-harvest {
            background:#E4CCFF;
            color:#6521A8;
            border:1px solid #C58BFF;
            box-shadow:0 0 7px rgba(170,75,255,.14);
        }

        /* REVIEW REQUIRED — highlighter yellow */

        .route-review {
            background:#FFF59A;
            color:#786000;
            border:1px solid #F4DC52;
            box-shadow:0 0 7px rgba(255,225,50,.14);
        }

        /* SCRAP — fluorescent coral */

        .route-scrap {
            background:#FFC1C1;
            color:#9B2020;
            border:1px solid #FF8585;
            box-shadow:0 0 7px rgba(255,70,70,.13);
        }

        /* Unknown / future route */

        .route-unknown {
            background:#E9EDF2;
            color:#58616D;
            border:1px solid #CDD3DA;
            box-shadow:none;
        }

        .defect-card summary:hover .route-badge {
            box-shadow:0 0 10px rgba(22,131,248,.12);
        }

        .defect-summary {
            margin-top:12px;
            color:#596174;
            font-size:13px;
            line-height:1.45;
        }

        .defect-body {
            padding:0 16px 16px;
            border-top:1px solid #edf0f3;
        }

        .meta-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            margin:14px 0;
        }

        .meta-box {
            background:#fafbfc;
            border:1px solid #edf0f3;
            border-radius:5px;
            padding:9px;
        }

        .meta-label {
            display:block;
            color:#858998;
            font-size:10px;
            font-weight:700;
            text-transform:uppercase;
            margin-bottom:4px;
        }

        .meta-value {
            color:#26304a;
            font-size:12px;
            font-weight:600;
        }

        .definition-block {
            margin-top:14px;
        }

        .definition-title {
            margin-bottom:5px;
            color:#26304a;
            font-size:12px;
            font-weight:700;
        }

        .definition-text {
            color:#596174;
            font-size:13px;
            line-height:1.5;
        }

        .usage-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:12px;
            margin-top:14px;
        }

        .usage-box {
            padding:12px;
            border-radius:5px;
            font-size:12px;
            line-height:1.45;
        }

        .use-box {
            background:#f2faf5;
            border:1px solid #d7eddf;
        }

        .avoid-box {
            background:#fff7f6;
            border:1px solid #f2ddda;
        }

        .usage-title {
            font-weight:700;
            margin-bottom:5px;
            color:#26304a;
        }

        .empty-state {
            grid-column:1/-1;
            padding:50px 20px;
            text-align:center;
            color:#858998;
            background:#fff;
            border:1px solid #e4e7ec;
            border-radius:7px;
        }

        @media (max-width:1100px) {
            .filters {
                grid-template-columns:1fr 1fr;
            }

            .defect-grid {
                grid-template-columns:1fr;
            }
        }

        @media (max-width:650px) {
            .filters,
            .meta-grid,
            .usage-grid {
                grid-template-columns:1fr;
            }

            .filter-actions {
                width:100%;
            }

            .filter-actions .btn {
                flex:1;
            }
        }
    </style>
</head>

<body class="recovery-app">
<?php require __DIR__.'/../includes/header.php'; ?>

<div class="erp-content">
    <div class="library-page">

        <div class="library-intro">
            <h1>Defect Library</h1>

            <p>
                Search ERP defect codes by code, category, description,
                English or Spanish terminology, and related words.
            </p>
        </div>

        <div class="card filter-card">

            <form method="get">

                <div class="filters">

                    <div class="field">
                        <label for="search">Search</label>

                        <input
                            id="search"
                            type="text"
                            name="search"
                            value="<?= h($search) ?>"
                            placeholder="Example: screen, pantalla, battery, no carga..."
                            autocomplete="off"
                        >
                    </div>

                    <div class="field">
                        <label for="family">Device Family</label>

                        <select id="family" name="family">
                            <option value="">All Families</option>

                            <?php foreach ($families as $option): ?>
                                <option
                                    value="<?= h((string)$option) ?>"
                                    <?= $family === (string)$option ? 'selected' : '' ?>
                                >
                                    <?= h((string)$option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="category">Category</label>

                        <select id="category" name="category">
                            <option value="">All Categories</option>

                            <?php foreach ($categories as $option): ?>
                                <option
                                    value="<?= h((string)$option) ?>"
                                    <?= $category === (string)$option ? 'selected' : '' ?>
                                >
                                    <?= h((string)$option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="route">Recovery Route</label>

                        <select id="route" name="route">
                            <option value="">All Routes</option>

                            <?php foreach ($routes as $option): ?>
                                <option
                                    value="<?= h((string)$option) ?>"
                                    <?= $route === (string)$option ? 'selected' : '' ?>
                                >
                                    <?= h(routeLabel((string)$option)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Search
                        </button>

                        <a
                            href="/pages/defect-library.php"
                            class="btn btn-secondary"
                        >
                            Clear
                        </a>
                    </div>

                </div>

            </form>

        </div>

        <div class="card library-toolbar">

            <div class="library-toolbar-title">
                Defect Library Results
            </div>

            <div class="library-toolbar-meta">
                <?= number_format(count($defects)) ?>
                result<?= count($defects) === 1 ? '' : 's' ?>

                ·

                <?= number_format($totalLibrary) ?>
                active defects
            </div>

        </div>

        <div class="defect-grid">

            <?php if (!$defects): ?>

                <div class="empty-state">
                    <strong>No matching defects found.</strong>

                    <div style="margin-top:7px;">
                        Try another code, symptom, English word,
                        Spanish word, category, or device family.
                    </div>
                </div>

            <?php else: ?>

                <?php foreach ($defects as $defect): ?>

                    <details
                        class="defect-card"
                        data-keywords="<?= h($defect['search_keywords']) ?>"
                    >

                        <summary>

                            <div class="defect-heading">

                                <div>
                                    <div class="defect-code">
                                        <?= h($defect['defect_code']) ?>
                                    </div>

                                    <div class="defect-family">
                                        <?= h($defect['device_family']) ?>

                                        <?php if (!empty($defect['main_category'])): ?>
                                            · <?= h($defect['main_category']) ?>
                                        <?php endif; ?>

                                        <?php if (!empty($defect['subcategory'])): ?>
                                            · <?= h($defect['subcategory']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($defect['recovery_hub_route'])): ?>
                                    <span class="route-badge <?= h(routeClass($defect['recovery_hub_route'])) ?>">
                                        <?= h(routeLabel($defect['recovery_hub_route'])) ?>
                                    </span>
                                <?php endif; ?>

                            </div>

                            <div class="defect-summary">
                                <?= h(!empty($defect['short_description']) ? $defect['short_description'] : $defect['definition_en']) ?>
                            </div>

                        </summary>

                        <div class="defect-body">

                            <div class="meta-grid">

                                <div class="meta-box">
                                    <span class="meta-label">ERP ID</span>

                                    <span class="meta-value">
                                        <?= h((string)$defect['erp_defect_id']) ?>
                                    </span>
                                </div>

                                <div class="meta-box">
                                    <span class="meta-label">Variance</span>

                                    <span class="meta-value">
                                        <?= $defect['variance_percentage'] !== null
                                            ? h(rtrim(rtrim(number_format((float)$defect['variance_percentage'], 2, '.', ''), '0'), '.')) . '%'
                                            : '—'
                                        ?>
                                    </span>
                                </div>

                                <div class="meta-box">
                                    <span class="meta-label">Repair Tier</span>

                                    <span class="meta-value">
                                        <?= $defect['repair_tier'] !== null
                                            ? 'Tier ' . h((string)$defect['repair_tier'])
                                            : '—'
                                        ?>
                                    </span>
                                </div>

                                <div class="meta-box">
                                    <span class="meta-label">ERP Recovery</span>

                                    <span class="meta-value">
                                        <?= h($defect['erp_recovery_type'] ?: '—') ?>
                                    </span>
                                </div>

                            </div>

                            <div class="definition-block">
                                <div class="definition-title">
                                    English Definition
                                </div>

                                <div class="definition-text">
                                    <?= h($defect['definition_en']) ?>
                                </div>
                            </div>

                            <div class="definition-block">
                                <div class="definition-title">
                                    Definición en Español
                                </div>

                                <div class="definition-text">
                                    <?= h($defect['definition_es']) ?>
                                </div>
                            </div>

                            <div class="usage-grid">

                                <div class="usage-box use-box">
                                    <div class="usage-title">
                                        USE WHEN
                                    </div>

                                    <?= h($defect['use_when']) ?>
                                </div>

                                <div class="usage-box avoid-box">
                                    <div class="usage-title">
                                        DO NOT USE WHEN
                                    </div>

                                    <?= h($defect['do_not_use_when']) ?>
                                </div>

                            </div>

                        </div>

                    </details>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('search');
    const cards = Array.from(document.querySelectorAll('.defect-card'));
    const grid = document.querySelector('.defect-grid');
    const resultMeta = document.querySelector('.library-toolbar-meta');

    if (!searchInput || !cards.length) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize text
    |--------------------------------------------------------------------------
    */

    function normalizeText(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    /*
    |--------------------------------------------------------------------------
    | Build searchable text
    |--------------------------------------------------------------------------
    */

    cards.forEach(function (card) {

        const visibleText = card.innerText;
        const keywords = card.dataset.keywords || '';

        card.dataset.searchText = normalizeText(
            visibleText + ' ' + keywords
        );

    });

    /*
    |--------------------------------------------------------------------------
    | Live empty state
    |--------------------------------------------------------------------------
    */

    const liveEmpty = document.createElement('div');

    liveEmpty.className = 'empty-state';

    liveEmpty.innerHTML = `
        <strong>No matching defects found.</strong>
        <div style="margin-top:7px;">
            Try another code, symptom, English word,
            Spanish word, category, or device family.
        </div>
    `;

    liveEmpty.style.display = 'none';

    grid.appendChild(liveEmpty);

    /*
    |--------------------------------------------------------------------------
    | Live Search
    |--------------------------------------------------------------------------
    */

    function filterDefects() {

        const query = normalizeText(searchInput.value);

        const words = query
            .split(/\s+/)
            .filter(Boolean);

        let visible = 0;

        cards.forEach(function (card) {

            const searchableText = card.dataset.searchText || '';

            const matches = words.every(function (word) {
                return searchableText.includes(word);
            });

            if (matches) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }

        });

        /*
        |--------------------------------------------------------------------------
        | Result counter
        |--------------------------------------------------------------------------
        */

        if (resultMeta) {

            const total = cards.length;

            resultMeta.textContent =
                visible.toLocaleString() +
                (visible === 1 ? ' result' : ' results') +
                ' · ' +
                total.toLocaleString() +
                ' loaded defects';
        }

        /*
        |--------------------------------------------------------------------------
        | Empty results
        |--------------------------------------------------------------------------
        */

        liveEmpty.style.display =
            visible === 0 ? 'block' : 'none';
    }

    /*
    |--------------------------------------------------------------------------
    | Search while typing
    |--------------------------------------------------------------------------
    */

    searchInput.addEventListener('input', filterDefects);

});
</script>

</body>
</html>