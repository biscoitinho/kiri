<?php
require_once __DIR__ . '/../app/core/Bootstrap.php';
Auth::requireLogin();
Database::migrate();
BackupService::autoBackupIfNeeded();

$userId = Auth::currentUserId();
$projects = Project::all($userId);
$currentProjectId = (int) ($_GET['project'] ?? ($projects[0]['id'] ?? 0));
$currentProject = $currentProjectId ? Project::find($currentProjectId) : null;

// Sprawdź ownership wybranego projektu
if ($currentProject && !Project::belongsToUser($currentProjectId, $userId)) {
    $currentProjectId = $projects[0]['id'] ?? 0;
    $currentProject = $currentProjectId ? Project::find($currentProjectId) : null;
}

$projectState = $currentProjectId ? ProjectState::get($currentProjectId) : null;
$projectMemory = $currentProjectId ? ProjectMemory::getTop($currentProjectId, 15) : [];
$latestCheckpoint = $currentProjectId ? Checkpoint::getLatest($currentProjectId) : null;
$stats = $currentProjectId ? Project::getStats($currentProjectId) : null;

$csrfToken = csrf_token();

function insightColor(string $type): string {
    $colors = [
        'goal' => 'blue', 'diet' => 'green', 'nutrition' => 'green', 'training' => 'orange',
        'injury' => 'red', 'recovery' => 'purple', 'psychology' => 'cyan',
        'competition' => 'yellow', 'strategy' => 'indigo', 'progress' => 'teal',
    ];
    return $colors[$type] ?? 'secondary';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?><?= $currentProject ? ' - ' . sanitize($currentProject['name']) : '' ?></title>
    <link rel="stylesheet" href="/assets/css/tabler.min.css">
    <link rel="stylesheet" href="/assets/css/tabler-icons.min.css">
    <style>
        .chat-container {
            height: calc(100vh - 250px);
            min-height: 400px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }
        .chat-message {
            margin-bottom: 1rem;
            max-width: 85%;
        }
        .chat-message.user {
            margin-left: auto;
        }
        .chat-message.assistant {
            margin-right: auto;
        }
        .chat-message .card {
            margin-bottom: 0;
        }
        .chat-message.user .card {
            background-color: var(--tblr-primary);
            color: white;
            border: none;
        }
        .chat-input {
            border-top: 1px solid var(--tblr-border-color);
            padding: 1rem;
        }
        .insight-badge {
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .sidebar-insights {
            max-height: calc(100vh - 300px);
            overflow-y: auto;
        }
        @keyframes memoryPulse {
            0% { box-shadow: 0 0 0 0 rgba(247, 191, 59, 0.5); }
            50% { box-shadow: 0 0 12px 4px rgba(247, 191, 59, 0.3); }
            100% { box-shadow: 0 0 0 0 rgba(247, 191, 59, 0); }
        }
        .memory-flash {
            animation: memoryPulse 0.8s ease-in-out 3;
        }
        .typing-indicator {
            display: none;
        }
        .typing-indicator.active {
            display: block;
        }
        .project-item {
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .project-item:hover {
            background-color: var(--tblr-bg-surface-secondary);
        }
        .project-item.active {
            background-color: var(--tblr-primary-lt);
            border-left: 3px solid var(--tblr-primary);
        }
    </style>
</head>
<body class="layout-fluid">
    <div class="page">
        <!-- Navbar -->
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <h1 class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                    <i class="ti ti-barbell me-2"></i>
                    <?= APP_NAME ?>
                </h1>
                <div class="navbar-nav flex-row order-md-last">
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
                            <span class="avatar avatar-sm bg-primary-lt">
                                <i class="ti ti-user"></i>
                            </span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item" href="/logout">
                                <i class="ti ti-logout me-2"></i>Wyloguj
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl">
                    <div class="row g-3">

                        <!-- LEWA KOLUMNA: Projekty -->
                        <div class="col-12 col-md-3 col-lg-2">
                            <div class="card">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <h3 class="card-title">Projekty</h3>
                                    <div>
                                        <a href="/settings" class="btn btn-icon btn-sm btn-ghost-secondary" title="Ustawienia">
                                            <i class="ti ti-settings"></i>
                                        </a>
                                        <button class="btn btn-icon btn-sm btn-ghost-primary" onclick="showNewProjectModal()">
                                            <i class="ti ti-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="list-group list-group-flush" id="projectList">
                                    <?php foreach ($projects as $p): ?>
                                        <a href="/?project=<?= $p['id'] ?>"
                                           class="list-group-item list-group-item-action project-item <?= $p['id'] == $currentProjectId ? 'active' : '' ?>">
                                            <div class="d-flex align-items-center">
                                                <span class="badge bg-<?= $p['status'] === 'active' ? 'green' : 'secondary' ?> badge-empty me-2"></span>
                                                <div>
                                                    <div class="fw-bold"><?= sanitize($p['name']) ?></div>
                                                    <small class="text-secondary"><?= sanitize($p['description'] ?? '') ?></small>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>

                                    <?php if (empty($projects)): ?>
                                        <div class="list-group-item text-center text-secondary py-4">
                                            <i class="ti ti-folder-off mb-2 fs-1"></i>
                                            <div>Brak projektów</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Dziennik (merged checkpoint + quick log) -->
                            <?php if ($currentProject): ?>
                            <div class="card mt-3">
                                <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#dailyLogCollapse">
                                    <h3 class="card-title d-flex align-items-center justify-content-between w-100">
                                        <span><i class="ti ti-clipboard-data me-1"></i>Dziennik</span>
                                        <i class="ti ti-chevron-down ms-auto"></i>
                                    </h3>
                                </div>
                                <div class="collapse" id="dailyLogCollapse">
                                <div class="card-body py-2 px-3">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-scale text-blue me-1"></i>Waga (kg)</label>
                                            <input type="number" class="form-control form-control-sm" id="qlWeight" placeholder="np. 91.2" step="0.1" min="0">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-moon text-indigo me-1"></i>Sen (h)</label>
                                            <input type="number" class="form-control form-control-sm" id="qlSleep" placeholder="np. 7.5" step="0.5" min="0" max="24">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-droplet text-cyan me-1"></i>Woda (ml)</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control" id="qlWater" placeholder="0" step="250" min="0">
                                                <button class="btn btn-outline-cyan btn-sm" onclick="quickLogWater(250)" title="+szklanka">+250</button>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-run text-orange me-1"></i>Aktywność (kcal)</label>
                                            <input type="number" class="form-control form-control-sm" id="qlActivity" placeholder="0" step="50" min="0">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-pizza text-yellow me-1"></i>Głód</label>
                                            <select class="form-select form-select-sm" id="qlHunger">
                                                <option value="">-</option>
                                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                                    <option value="<?= $i ?>"><?= $i ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-bolt text-green me-1"></i>Energia</label>
                                            <select class="form-select form-select-sm" id="qlEnergy">
                                                <option value="">-</option>
                                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                                    <option value="<?= $i ?>"><?= $i ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label form-label-sm mb-0"><i class="ti ti-notes text-secondary me-1"></i>Notatka</label>
                                            <textarea class="form-control form-control-sm" id="qlNotes" rows="2" placeholder="Samopoczucie, uwagi..."></textarea>
                                        </div>
                                    </div>
                                    <button class="btn btn-primary btn-sm w-100 mt-2" onclick="saveQuickLog()">
                                        <i class="ti ti-check me-1"></i>Zapisz
                                    </button>
                                    <div id="qlStatus" class="text-center mt-1" style="font-size:0.7rem;"></div>
                                </div>
                                </div>
                            </div>

                            <!-- Posiłki / Kalorie (zwijany) -->
                            <div class="card mt-3">
                                <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#mealsCollapse">
                                    <h3 class="card-title d-flex align-items-center justify-content-between w-100">
                                        <span><i class="ti ti-tools-kitchen-2 me-1"></i>Posiłki</span>
                                        <span class="badge bg-green-lt ms-auto me-2" id="todayKcalBadge">0 kcal</span>
                                        <i class="ti ti-chevron-down"></i>
                                    </h3>
                                </div>
                                <div class="collapse" id="mealsCollapse">
                                <div class="card-body">
                                    <div class="mb-2">
                                        <div class="input-group input-group-sm">
                                            <input type="text" id="mealInput" class="form-control" placeholder="np. 5 jajek, kawa z mlekiem">
                                            <button class="btn btn-primary" onclick="logMeal()">
                                                <i class="ti ti-plus"></i>
                                            </button>
                                            <button class="btn btn-cyan" onclick="document.getElementById('scanPhotoInput').click()" title="Skanuj etykietę (można wybrać kilka zdjęć)">
                                                <i class="ti ti-camera"></i>
                                            </button>
                                        </div>
                                        <input type="file" id="scanPhotoInput" accept="image/*" capture="environment" class="d-none" onchange="scanFoodLabel(this)">
                                        <div id="scanPreview" class="d-flex gap-1 mt-1 flex-wrap" style="display:none !important;"></div>
                                    </div>
                                    <select id="mealType" class="form-select form-select-sm mb-2">
                                        <option value="sniadanie">Śniadanie</option>
                                        <option value="lunch">Lunch</option>
                                        <option value="obiad">Obiad</option>
                                        <option value="kolacja">Kolacja</option>
                                        <option value="przekaska">Przekąska</option>
                                    </select>

                                    <!-- Dzisiejsze posiłki -->
                                    <div id="todayMeals" class="mb-2"></div>

                                    <!-- Podsumowanie dnia -->
                                    <div id="todayTotals" class="d-none">
                                        <div class="hr my-2"></div>
                                        <div class="row g-1 text-center" style="font-size:0.75rem;">
                                            <div class="col-3">
                                                <div class="fw-bold text-blue" id="totalKcal">0</div>
                                                <div class="text-secondary">kcal</div>
                                            </div>
                                            <div class="col-3">
                                                <div class="fw-bold text-red" id="totalProtein">0</div>
                                                <div class="text-secondary">białko</div>
                                            </div>
                                            <div class="col-3">
                                                <div class="fw-bold text-yellow" id="totalCarbs">0</div>
                                                <div class="text-secondary">węgle</div>
                                            </div>
                                            <div class="col-3">
                                                <div class="fw-bold text-orange" id="totalFat">0</div>
                                                <div class="text-secondary">tłuszcz</div>
                                            </div>
                                        </div>
                                        <!-- Pasek kalorii vs cel -->
                                        <!-- Trening (spalone kalorie) -->
                                        <div class="d-none mt-2" id="trainingSection">
                                            <div class="d-flex align-items-center mb-1" style="font-size:0.7rem;">
                                                <i class="ti ti-flame text-orange me-1"></i>
                                                <span class="text-secondary">Spalone:</span>
                                                <strong class="text-orange ms-1" id="burnedKcal">0</strong>
                                                <span class="text-secondary ms-1">kcal</span>
                                            </div>
                                            <div id="trainingList" style="font-size:0.75rem;"></div>
                                        </div>
                                        <!-- Bilans -->
                                        <div class="mt-2" id="kcalProgressWrap">
                                            <div class="d-flex justify-content-between" style="font-size:0.7rem;">
                                                <span class="text-secondary">Cel: <strong id="kcalTarget">0</strong> kcal</span>
                                                <span id="kcalDiffLabel" class="fw-bold">-</span>
                                            </div>
                                            <div class="progress progress-sm mt-1" style="height:6px;">
                                                <div class="progress-bar" id="kcalProgressBar" role="progressbar" style="width:0%"></div>
                                            </div>
                                            <div class="d-flex justify-content-between mt-1" style="font-size:0.65rem;">
                                                <span class="text-secondary">Mifflin-St Jeor × <span id="kcalActivityLabel">siedzący</span> · <span id="kcalWeight">?</span> kg</span>
                                                <span class="text-secondary d-none" id="netKcalLabel"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                </div>
                            </div>

                            <!-- Kalendarz kaloryczny -->
                            <div class="card mt-3">
                                <div class="card-body py-2 px-3">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span style="font-size:0.7rem;font-weight:600;"><i class="ti ti-calendar-stats me-1"></i>Kalorii</span>
                                        <div class="d-flex align-items-center gap-1" style="font-size:0.55rem;">
                                            <span style="width:7px;height:7px;border-radius:1px;background:#e9ecef;display:inline-block;"></span>
                                            <span style="width:7px;height:7px;border-radius:1px;background:#12b886;display:inline-block;"></span>
                                            <span style="width:7px;height:7px;border-radius:1px;background:#4dabf7;display:inline-block;"></span>
                                            <span style="width:7px;height:7px;border-radius:1px;background:#fa5252;display:inline-block;"></span>
                                        </div>
                                    </div>
                                    <div id="kcalCalendar" style="display:grid;grid-template-columns:16px repeat(7,1fr);gap:1px;"></div>
                                </div>
                            </div>

                            <!-- Wykres korelacji -->
                            <div class="card mt-3">
                                <div class="card-header py-2 d-flex align-items-center justify-content-between cursor-pointer" data-bs-toggle="collapse" data-bs-target="#correlationPanel" aria-expanded="false">
                                    <h3 class="card-title" style="font-size:0.85rem;">
                                        <i class="ti ti-chart-line me-1"></i>Korelacje
                                    </h3>
                                    <div class="d-flex align-items-center gap-2">
                                        <select id="correlationDays" class="form-select form-select-sm" style="width:auto;font-size:0.7rem;padding:1px 6px;" onchange="loadCorrelationChart()">
                                            <option value="14">14 dni</option>
                                            <option value="30" selected>30 dni</option>
                                            <option value="60">60 dni</option>
                                            <option value="90">90 dni</option>
                                        </select>
                                        <i class="ti ti-chevron-down"></i>
                                    </div>
                                </div>
                                <div class="collapse" id="correlationPanel">
                                    <div class="card-body py-2 px-3">
                                        <div id="correlationChart" style="min-height:280px;"></div>
                                        <div id="correlationEmpty" class="text-secondary text-center py-3" style="font-size:0.75rem;display:none;">
                                            Brak danych — loguj dziennik, a wykres się pojawi
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php endif; ?>
                        </div>

                        <!-- ŚRODKOWA KOLUMNA: Chat -->
                        <div class="col-12 col-md-6 col-lg-7">
                            <?php if ($currentProject): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="ti ti-message-circle me-1"></i>
                                        Chat - <?= sanitize($currentProject['name']) ?>
                                    </h3>
                                    <?php if ($stats): ?>
                                    <div class="card-actions">
                                        <span class="badge bg-blue-lt"><?= $stats['message_count'] ?> wiadomości</span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="chat-container">
                                    <div class="chat-messages" id="chatMessages">
                                        <div class="text-center text-secondary py-4" id="chatLoading">
                                            <div class="spinner-border spinner-border-sm me-2"></div>
                                            Ładowanie historii...
                                        </div>
                                    </div>

                                    <div class="typing-indicator px-3 pb-2" id="typingIndicator">
                                        <div class="d-flex align-items-center text-secondary">
                                            <div class="spinner-border spinner-border-sm me-2"></div>
                                            <small>AI myśli...</small>
                                        </div>
                                    </div>

                                    <div class="chat-input">
                                        <form id="chatForm" class="d-flex gap-2">
                                            <textarea id="chatInput" class="form-control" rows="2"
                                                      placeholder="Napisz wiadomość... (Enter = wyślij, Shift+Enter = nowa linia)"
                                                      style="resize: none;"></textarea>
                                            <button type="submit" class="btn btn-primary" id="sendBtn">
                                                <i class="ti ti-send"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="ti ti-message-circle-off mb-3" style="font-size: 3rem; color: var(--tblr-secondary);"></i>
                                    <h3>Wybierz lub utwórz projekt</h3>
                                    <p class="text-secondary">Aby rozpocząć rozmowę z AI, wybierz projekt z listy po lewej.</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- PRAWA KOLUMNA: Insights + Status -->
                        <div class="col-12 col-md-3 col-lg-3">
                            <?php if ($currentProject): ?>

                            <!-- Project State -->
                            <?php if ($projectState): ?>
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="ti ti-target me-1"></i>Stan projektu</h3>
                                </div>
                                <div class="card-body">
                                    <div class="datagrid">
                                        <?php if ($projectState['current_weight_kg']): ?>
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Aktualna waga</div>
                                            <div class="datagrid-content">
                                                <strong class="fs-3" id="stateWeight"><?= $projectState['current_weight_kg'] ?> kg</strong>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($projectState['target_weight_kg']): ?>
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Cel</div>
                                            <div class="datagrid-content"><?= $projectState['target_weight_kg'] ?> kg</div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($projectState['current_phase']): ?>
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Faza</div>
                                            <div class="datagrid-content"><?= sanitize($projectState['current_phase']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($projectState['next_competition']): ?>
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Zawody</div>
                                            <div class="datagrid-content"><?= sanitize($projectState['next_competition']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($projectState['training_mode']): ?>
                                    <div class="mt-2">
                                        <small class="text-secondary">Trening:</small><br>
                                        <small><?= sanitize($projectState['training_mode']) ?></small>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($projectState['injury_status']): ?>
                                    <div class="mt-2">
                                        <small class="text-danger"><i class="ti ti-alert-triangle"></i> Urazy:</small><br>
                                        <small><?= sanitize($projectState['injury_status']) ?></small>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($projectState['diet_mode']): ?>
                                    <div class="mt-2">
                                        <small class="text-secondary">Dieta:</small><br>
                                        <small><?= sanitize($projectState['diet_mode']) ?></small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Durable Memory -->
                            <div class="card" id="memoryCard">
                                <div class="card-header py-2 d-flex align-items-center justify-content-between cursor-pointer" data-bs-toggle="collapse" data-bs-target="#memoryPanel" aria-expanded="false">
                                    <h3 class="card-title" style="font-size:0.85rem;"><i class="ti ti-brain me-1"></i>Pamięć projektu</h3>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-yellow-lt" id="memoryCount"><?= count($projectMemory) ?></span>
                                        <i class="ti ti-chevron-down"></i>
                                    </div>
                                </div>
                                <div class="collapse" id="memoryPanel">
                                    <div class="card-body p-0 sidebar-insights">
                                        <?php if ($projectMemory): ?>
                                            <div class="list-group list-group-flush" id="memoryList">
                                                <?php foreach ($projectMemory as $mem): ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex align-items-center mb-1">
                                                        <span class="badge insight-badge bg-<?= insightColor($mem['category']) ?>-lt me-2">
                                                            <?= sanitize($mem['category']) ?>
                                                        </span>
                                                        <span class="badge bg-secondary-lt insight-badge">
                                                            P:<?= $mem['priority'] ?>
                                                        </span>
                                                    </div>
                                                    <small><?= sanitize($mem['content']) ?></small>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center text-secondary py-4">
                                                <i class="ti ti-brain mb-2 fs-1"></i>
                                                <div>Brak danych w pamięci</div>
                                                <small>Pamięć buduje się automatycznie z rozmów</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Koszty API (admin only) -->
                            <?php if ($userId === 1): ?>
                            <div class="card mt-3">
                                <div class="card-header py-2 d-flex align-items-center justify-content-between cursor-pointer" data-bs-toggle="collapse" data-bs-target="#costPanel" aria-expanded="false">
                                    <h3 class="card-title" style="font-size:0.85rem;">
                                        <i class="ti ti-coin me-1"></i>Koszty API
                                    </h3>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-yellow-lt" id="costToday">...</span>
                                        <i class="ti ti-chevron-down"></i>
                                    </div>
                                </div>
                                <div class="collapse" id="costPanel">
                                    <div class="card-body py-2 px-3" style="font-size:0.75rem;">
                                        <div id="costSummary"></div>
                                        <div id="costRecent" class="mt-2" style="max-height:200px; overflow-y:auto;"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Backup (admin only) -->
                            <?php if ($userId === 1): ?>
                            <div class="card mt-3">
                                <div class="card-header py-2 d-flex align-items-center justify-content-between cursor-pointer" data-bs-toggle="collapse" data-bs-target="#backupPanel" aria-expanded="false">
                                    <h3 class="card-title" style="font-size:0.85rem;">
                                        <i class="ti ti-database-export me-1"></i>Backup
                                    </h3>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-green-lt" id="backupCount">...</span>
                                        <i class="ti ti-chevron-down"></i>
                                    </div>
                                </div>
                                <div class="collapse" id="backupPanel">
                                    <div class="card-body py-2 px-3">
                                        <button class="btn btn-primary btn-sm w-100 mb-2" onclick="createBackup()">
                                            <i class="ti ti-plus me-1"></i>Nowy backup
                                        </button>
                                        <div id="backupList" style="max-height:200px; overflow-y:auto; font-size:0.75rem;"></div>
                                        <div class="text-secondary mt-2" style="font-size:0.6rem;">
                                            Auto-backup co godzinę · max 30 · SQLite + JSON warstw AI
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Nowy Projekt -->
    <div class="modal modal-blur fade" id="newProjectModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nowy projekt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="newProjectForm">
                        <div class="mb-3">
                            <label class="form-label">Nazwa</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opis</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn me-auto" data-bs-dismiss="modal">Anuluj</button>
                    <button type="button" class="btn btn-primary" onclick="createProject()">
                        <i class="ti ti-plus me-1"></i>Utwórz
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/tabler.min.js"></script>
    <script>
    const CSRF_TOKEN = '<?= $csrfToken ?>';
    const PROJECT_ID = <?= $currentProjectId ?: 'null' ?>;

    // Dane do kalkulatora BMR (Mifflin-St Jeor) - z project_state
    const USER_WEIGHT = <?= $projectState['current_weight_kg'] ?? 90 ?>;
    const USER_HEIGHT = <?= $projectState['height_cm'] ?? 175 ?>;
    const USER_AGE = <?= $projectState['age'] ?? 30 ?>;
    const USER_SEX = '<?= $projectState['sex'] ?? 'M' ?>';
    const USER_PAL = <?= $projectState['pal'] ?? 1.2 ?>;

    // Mifflin-St Jeor: M = -5, F = -161
    const SEX_OFFSET = USER_SEX === 'F' ? -161 : -5;
    const BMR = Math.round(10 * USER_WEIGHT + 6.25 * USER_HEIGHT - 5 * USER_AGE + SEX_OFFSET);
    const TDEE = Math.round(BMR * USER_PAL);
    const KCAL_TARGET = TDEE - 500;

    // === CHAT ===
    const chatMessages = document.getElementById('chatMessages');
    const chatForm = document.getElementById('chatForm');
    const chatInput = document.getElementById('chatInput');
    const typingIndicator = document.getElementById('typingIndicator');
    const sendBtn = document.getElementById('sendBtn');

    let lastSeenMsgId = 0; // ID ostatniej widocznej wiadomości
    let pendingSend = false; // czy czekamy na odpowiedź AI
    let recovering = false; // czy trwa recovery (żeby nie odpalić dwóch naraz)

    if (PROJECT_ID) {
        loadMessages();
    }

    // Recovery po powrocie z tła (zablokowany ekran, przełączenie apki)
    document.addEventListener('visibilitychange', async function() {
        if (document.visibilityState !== 'visible' || !PROJECT_ID) return;
        if (!pendingSend || recovering) return; // nie czekamy lub już recovery w toku

        try {
            const res = await fetch(`/api/messages?project_id=${PROJECT_ID}&limit=2`);
            const data = await res.json();
            if (!data.messages || data.messages.length === 0) return;

            const lastMsg = data.messages[data.messages.length - 1];
            if (lastMsg.role === 'assistant' && lastMsg.id > lastSeenMsgId) {
                // AI odpowiedział gdy byliśmy w tle - wyświetl odpowiedź
                pendingSend = false;
                sendBtn.disabled = false;
                typingIndicator.classList.remove('active');

                // Znajdź pusty bubble AI lub utwórz nowy
                const existingStream = document.querySelector('.ai-stream-content');
                const spinner = existingStream?.querySelector('.text-secondary');
                if (existingStream && spinner) {
                    renderFinalResponse(existingStream, lastMsg.content, '');
                } else {
                    appendMessage('assistant', lastMsg.content, lastMsg.created_at);
                }

                lastSeenMsgId = lastMsg.id;
                scrollToBottom();
                // Odśwież panel posiłków i treningów
                loadTodayMeals();
            }
        } catch (e) { /* sieć niedostępna */ }
    });

    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage();
        });
    }

    if (chatInput) {
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }

    async function loadMessages() {
        try {
            const res = await fetch(`/api/messages?project_id=${PROJECT_ID}`);
            const data = await res.json();

            const loading = document.getElementById('chatLoading');
            if (loading) loading.remove();

            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    appendMessage(msg.role, msg.content, msg.created_at);
                    if (msg.id) lastSeenMsgId = Math.max(lastSeenMsgId, msg.id);
                });
            } else {
                chatMessages.innerHTML = `
                    <div class="text-center text-secondary py-5">
                        <i class="ti ti-message-plus mb-2" style="font-size: 2rem;"></i>
                        <div>Rozpocznij rozmowę z AI</div>
                    </div>
                `;
            }
            scrollToBottom();
        } catch (err) {
            console.error('Błąd ładowania wiadomości:', err);
        }
    }

    async function sendMessage() {
        const message = chatInput.value.trim();
        if (!message) return;

        chatInput.value = '';
        chatInput.style.height = 'auto';
        pendingSend = true;

        // Usuń placeholder jeśli jest
        const placeholder = chatMessages.querySelector('.text-center');
        if (placeholder) placeholder.remove();

        appendMessage('user', message);
        scrollToBottom();

        sendBtn.disabled = true;
        typingIndicator.classList.add('active');

        // Utwórz pusty bubble dla AI (będziemy go wypełniać na żywo)
        const aiDiv = createEmptyAssistantBubble();
        const contentEl = aiDiv.querySelector('.ai-stream-content');
        let fullResponse = '';
        let userMsgId = null;

        try {
            const res = await fetch('/api/chat-stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    project_id: PROJECT_ID,
                    message: message,
                }),
            });

            if (!res.ok) {
                const err = await res.json();
                contentEl.innerHTML = '⚠️ Błąd: ' + escapeHtml(err.error || 'Nieznany błąd');
                return;
            }

            typingIndicator.classList.remove('active');

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (value) {
                    buffer += decoder.decode(value, { stream: true });
                }

                const lines = buffer.split('\n');
                buffer = done ? '' : lines.pop();

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (!trimmed || trimmed === 'data: [DONE]') continue;
                    if (!trimmed.startsWith('data: ')) continue;

                    try {
                        const data = JSON.parse(trimmed.slice(6));

                        if (data.type === 'msg_id') {
                            userMsgId = data.id;
                        } else if (data.type === 'token') {
                            fullResponse += data.content;
                            contentEl.innerHTML = formatMarkdown(fullResponse);
                            scrollToBottom();
                        } else if (data.type === 'error') {
                            contentEl.innerHTML = '⚠️ ' + escapeHtml(data.content);
                        } else if (data.type === 'done') {
                            fullResponse = data.content;
                        } else if (data.type === 'memory_updated') {
                            flashMemoryPanel(data.count, data.new);
                        }
                    } catch (e) { /* skip malformed */ }
                }

                if (done) break;
            }

            renderFinalResponse(contentEl, fullResponse, message);

        } catch (err) {
            // Połączenie zerwane (ekran wygaszony, telefon, utrata sieci)
            // Serwer może dalej przetwarzać - spróbuj pobrać odpowiedź
            if (userMsgId) {
                recovering = true;
                contentEl.innerHTML = '<span class="text-muted"><i class="ti ti-refresh"></i> Połączenie przerwane, pobieram odpowiedź...</span>';
                const recovered = await recoverResponse(userMsgId, contentEl, message);
                if (!recovered) {
                    contentEl.innerHTML = '⚠️ Połączenie przerwane. Odśwież stronę - odpowiedź może być już zapisana.';
                }
            } else {
                contentEl.innerHTML = '⚠️ Błąd połączenia z serwerem';
            }
        } finally {
            pendingSend = false;
            recovering = false;
            sendBtn.disabled = false;
            typingIndicator.classList.remove('active');
            scrollToBottom();
        }
    }

    function renderFinalResponse(contentEl, fullResponse, userMessage) {
        if (!fullResponse) return;
        const { text, summary } = parseSummaryBlock(fullResponse);
        contentEl.innerHTML = formatMarkdown(text);
        if (summary) {
            const summaryCard = renderSummaryCard(summary);
            contentEl.closest('.card-body').appendChild(summaryCard);
        }
        scrollToBottom();

        if (summary && summary.meal_log && Array.isArray(summary.meal_log)) {
            autoLogMealsFromChat(summary.meal_log);
        }
        if (summary && summary.training_log && Array.isArray(summary.training_log)) {
            autoLogTrainingFromChat(summary.training_log);
        }
        if (summary && summary.profile_update && typeof summary.profile_update === 'object') {
            saveProfileUpdate(summary.profile_update);
        }
        // Auto-update wagi z podsumowania AI
        if (summary && summary.waga) {
            const w = parseFloat(String(summary.waga).replace(',', '.'));
            if (w > 0 && w < 500) {
                updateWeightFromSummary(w);
            }
        }

        extractInsightsAsync(PROJECT_ID, userMessage, fullResponse);
    }

    async function recoverResponse(afterId, contentEl, userMessage) {
        // Polluj co 2s przez max 120s (AI może jeszcze przetwarzać)
        const maxAttempts = 60;
        for (let i = 0; i < maxAttempts; i++) {
            await new Promise(r => setTimeout(r, 2000));
            try {
                const res = await fetch(`/api/message-recovery?project_id=${PROJECT_ID}&after_id=${afterId}`);
                const data = await res.json();
                if (data.found && data.message) {
                    renderFinalResponse(contentEl, data.message.content, userMessage);
                    return true;
                }
            } catch (e) {
                // Sieć dalej niedostępna - kontynuuj próby
            }
        }
        return false;
    }

    function createEmptyAssistantBubble() {
        const div = document.createElement('div');
        div.className = 'chat-message assistant';
        div.innerHTML = `
            <div class="card card-sm">
                <div class="card-body py-2 px-3">
                    <div class="ai-stream-content"><span class="text-secondary">...</span></div>
                </div>
            </div>
        `;
        chatMessages.appendChild(div);
        return div;
    }

    function formatMarkdown(text) {
        // Usuń blok SUMMARY z wyświetlanego tekstu
        text = text.replace(/<!--SUMMARY[\s\S]*?SUMMARY-->/g, '').trim();
        let html = escapeHtml(text);
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
        html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
        html = html.replace(/\n/g, '<br>');
        return html;
    }

    function parseSummaryBlock(text) {
        const match = text.match(/<!--SUMMARY\s*([\s\S]*?)\s*SUMMARY-->/);
        if (!match) return { text: text, summary: null };

        const cleanText = text.replace(/<!--SUMMARY[\s\S]*?SUMMARY-->/g, '').trim();
        try {
            const summary = JSON.parse(match[1]);
            return { text: cleanText, summary };
        } catch (e) {
            return { text: cleanText, summary: null };
        }
    }

    function renderSummaryCard(summary) {
        const icons = {
            waga:    { icon: 'ti-scale', color: 'blue', label: 'Waga' },
            sen:     { icon: 'ti-moon', color: 'indigo', label: 'Sen' },
            energia: { icon: 'ti-bolt', color: 'yellow', label: 'Energia' },
            glod:    { icon: 'ti-pizza', color: 'orange', label: 'Głód' },
            bol:     { icon: 'ti-first-aid-kit', color: 'red', label: 'Ból' },
            trening: { icon: 'ti-barbell', color: 'green', label: 'Trening' },
            posilki: { icon: 'ti-tools-kitchen-2', color: 'cyan', label: 'Posiłki' },
            nastroj: { icon: 'ti-mood-smile', color: 'purple', label: 'Nastrój' },
            plan:    { icon: 'ti-list-check', color: 'teal', label: 'Plan' },
            uwaga:   { icon: 'ti-alert-triangle', color: 'red', label: 'Uwaga' },
        };

        const items = [];
        for (const [key, value] of Object.entries(summary)) {
            if (!value || value === 'null' || key === 'meal_log' || key === 'training_log' || key === 'profile_update') continue;
            const cfg = icons[key] || { icon: 'ti-info-circle', color: 'secondary', label: key };
            items.push(`
                <div class="d-flex align-items-start mb-2">
                    <span class="avatar avatar-xs bg-${cfg.color}-lt me-2 mt-1" style="min-width:24px;width:24px;height:24px;flex-shrink:0;">
                        <i class="ti ${cfg.icon}" style="font-size:14px;"></i>
                    </span>
                    <div>
                        <div class="text-secondary" style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em;">${cfg.label}</div>
                        <div style="font-size:0.85rem;">${escapeHtml(String(value))}</div>
                    </div>
                </div>
            `);
        }

        if (items.length === 0) return document.createElement('div');

        const card = document.createElement('div');
        card.className = 'mt-3 pt-3';
        card.style.borderTop = '1px solid var(--tblr-border-color)';
        card.innerHTML = `
            <div class="d-flex align-items-center mb-2">
                <i class="ti ti-report-analytics me-1 text-secondary"></i>
                <small class="text-secondary fw-bold" style="text-transform:uppercase; letter-spacing:0.05em;">Podsumowanie</small>
            </div>
            <div class="row g-2">
                <div class="col-12">${items.join('')}</div>
            </div>
        `;
        return card;
    }

    async function extractInsightsAsync(projectId, userMessage, aiResponse) {
        try {
            const res = await fetch('/api/extract-insights', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    project_id: projectId,
                    user_message: userMessage,
                    ai_response: aiResponse,
                }),
            });
            const data = await res.json();
            if (data.new_insights && data.new_insights.length > 0) {
                data.new_insights.forEach(insight => addInsightToSidebar(insight));
            }
        } catch (e) { /* cicho - insighty nie są krytyczne */ }
    }

    function appendMessage(role, content, timestamp) {
        const div = document.createElement('div');
        div.className = `chat-message ${role}`;

        const time = timestamp ? new Date(timestamp).toLocaleTimeString('pl-PL', {hour: '2-digit', minute: '2-digit'}) : '';

        div.innerHTML = `
            <div class="card card-sm">
                <div class="card-body py-2 px-3">
                    <div>${formatMarkdown(content)}</div>
                    ${time ? `<small class="text-secondary">${time}</small>` : ''}
                </div>
            </div>
        `;

        chatMessages.appendChild(div);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function scrollToBottom() {
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    // === INSIGHTS ===
    function flashMemoryPanel(newCount, newItems) {
        const card = document.getElementById('memoryCard');
        const badge = document.getElementById('memoryCount');
        const list = document.getElementById('memoryList');
        if (!card) return;

        // Aktualizuj licznik
        if (badge) badge.textContent = newCount;

        // Dodaj nowe wpisy na górę listy
        if (list && newItems) {
            const colors = {
                goal: 'blue', diet: 'green', nutrition: 'green', training: 'orange',
                injury: 'red', recovery: 'purple', psychology: 'cyan',
                competition: 'yellow', strategy: 'indigo', progress: 'teal',
            };
            newItems.forEach(mem => {
                const div = document.createElement('div');
                div.className = 'list-group-item';
                div.innerHTML = `
                    <div class="d-flex align-items-center mb-1">
                        <span class="badge insight-badge bg-${colors[mem.category] || 'secondary'}-lt me-2">${escapeHtml(mem.category)}</span>
                        <span class="badge bg-secondary-lt insight-badge">P:${mem.priority}</span>
                    </div>
                    <small>${escapeHtml(mem.content)}</small>
                `;
                list.prepend(div);
            });
        }

        // Flash
        card.classList.add('memory-flash');
        setTimeout(() => card.classList.remove('memory-flash'), 2500);
    }

    function addInsightToSidebar(insight) {
        const container = document.querySelector('.sidebar-insights .list-group');
        if (!container) return;

        // Usuń placeholder jeśli jest
        const placeholder = container.closest('.card-body')?.querySelector('.text-center');
        if (placeholder) placeholder.remove();

        const colors = {
            goal: 'blue', diet: 'green', nutrition: 'green', training: 'orange',
            injury: 'red', recovery: 'purple', psychology: 'cyan',
            competition: 'yellow', strategy: 'indigo', progress: 'teal',
        };

        const div = document.createElement('div');
        div.className = 'list-group-item';
        div.innerHTML = `
            <div class="d-flex align-items-center mb-1">
                <span class="badge insight-badge bg-${colors[insight.type] || 'secondary'}-lt me-2">${escapeHtml(insight.type)}</span>
                <span class="badge bg-secondary-lt insight-badge">P:${insight.priority}</span>
                <span class="badge bg-green-lt insight-badge ms-1">NOWY</span>
            </div>
            <small>${escapeHtml(insight.content)}</small>
        `;
        container.prepend(div);

        // Aktualizuj licznik
        const counter = document.querySelector('.sidebar-insights')?.closest('.card')?.querySelector('.badge.bg-yellow-lt');
        if (counter) {
            counter.textContent = parseInt(counter.textContent) + 1;
        }
    }

    // === CHECKPOINT (legacy - zapis przez dziennik) ===

    // === PROJEKTY ===
    function showNewProjectModal() {
        const el = document.getElementById('newProjectModal');
        const ModalClass = (typeof bootstrap !== 'undefined' && bootstrap.Modal)
            || (typeof tabler !== 'undefined' && tabler.Modal);
        if (ModalClass) {
            const modal = new ModalClass(el);
            modal.show();
        } else {
            // Fallback
            el.classList.add('show');
            el.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    async function createProject() {
        const form = document.getElementById('newProjectForm');
        const name = form.querySelector('[name="name"]').value.trim();
        const description = form.querySelector('[name="description"]').value.trim();

        if (!name) return alert('Podaj nazwę projektu');

        try {
            const res = await fetch('/api/projects', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    action: 'create',
                    name: name,
                    description: description,
                }),
            });

            const data = await res.json();
            if (data.id) {
                window.location.href = '/?project=' + data.id;
            } else {
                alert('Błąd: ' + (data.error || 'Nieznany błąd'));
            }
        } catch (err) {
            alert('Błąd połączenia');
        }
    }

    // === POSIŁKI ===
    const mealInput = document.getElementById('mealInput');
    const mealType = document.getElementById('mealType');

    if (mealInput) {
        mealInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') logMeal();
        });
        // Załaduj dzisiejsze posiłki i kalendarz
        if (PROJECT_ID) {
            loadTodayMeals();
            loadKcalCalendar();
        }
    }

    async function logMeal() {
        const desc = mealInput?.value.trim();
        if (!desc) return;

        mealInput.value = '';
        const type = mealType?.value || 'other';

        // Pokaż loading
        const container = document.getElementById('todayMeals');
        const loadingEl = document.createElement('div');
        loadingEl.className = 'text-secondary small mb-1';
        loadingEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Szukam w bazie...';
        container.appendChild(loadingEl);

        try {
            const res = await fetch('/api/meals', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    action: 'log',
                    project_id: PROJECT_ID,
                    description: desc,
                    meal_type: type,
                }),
            });

            const data = await res.json();
            loadingEl.remove();

            if (data.source === 'local') {
                addMealToList(desc, data.nutrition, 'DB', data.id);
            } else if (data.source === 'openfoodfacts') {
                addMealToList(desc, data.nutrition, 'OFF', data.id);
            } else if (data.source === 'ai_estimate') {
                addMealToList(desc, data.estimate.total, 'AI', data.id);
            } else {
                addMealToList(desc, null, '?', data.id);
            }

            if (data.totals) updateTotals(data.totals);

        } catch (err) {
            loadingEl.remove();
            alert('Błąd logowania posiłku');
        }
    }

    function addMealToList(desc, nutrition, source, id) {
        const container = document.getElementById('todayMeals');
        const div = document.createElement('div');
        div.className = 'd-flex align-items-center justify-content-between mb-1 meal-row';
        div.dataset.mealId = id;
        div.style.fontSize = '0.8rem';

        const sourceColors = { 'DB': 'teal', 'OFF': 'green', 'AI': 'blue', '?': 'secondary' };
        const kcalText = nutrition ? `${nutrition.kcal} kcal` : '? kcal';

        div.innerHTML = `
            <div class="d-flex align-items-center">
                <span class="badge bg-${sourceColors[source]}-lt me-1" style="font-size:0.6rem;">${source}</span>
                <span>${escapeHtml(desc)}</span>
            </div>
            <div class="d-flex align-items-center">
                <strong class="text-blue me-1">${kcalText}</strong>
                <button class="btn btn-icon btn-sm btn-ghost-danger" onclick="deleteMeal(${id}, this)" style="width:20px;height:20px;">
                    <i class="ti ti-x" style="font-size:0.7rem;"></i>
                </button>
            </div>
        `;
        container.appendChild(div);
    }

    function updateTotals(totals) {
        const el = document.getElementById('todayTotals');
        if (!el) return;

        if (totals.meal_count > 0) {
            el.classList.remove('d-none');
            document.getElementById('totalKcal').textContent = totals.kcal;
            document.getElementById('totalProtein').textContent = Math.round(totals.protein) + 'g';
            document.getElementById('totalCarbs').textContent = Math.round(totals.carbs) + 'g';
            document.getElementById('totalFat').textContent = Math.round(totals.fat) + 'g';
            document.getElementById('todayKcalBadge').textContent = totals.kcal + ' kcal';

            // Trening / spalone kalorie
            const burned = totals.burned || 0;
            const trainingSection = document.getElementById('trainingSection');
            if (burned > 0) {
                trainingSection.classList.remove('d-none');
                document.getElementById('burnedKcal').textContent = burned;
            }

            // Pasek kalorii vs cel (netto = zjedzone - spalone)
            const eaten = totals.kcal;
            const netEaten = eaten - burned;
            const diff = netEaten - KCAL_TARGET;
            const pct = Math.min(Math.round((netEaten / KCAL_TARGET) * 100), 150);
            const bar = document.getElementById('kcalProgressBar');
            const label = document.getElementById('kcalDiffLabel');

            document.getElementById('kcalTarget').textContent = KCAL_TARGET;
            document.getElementById('kcalWeight').textContent = USER_WEIGHT;

            bar.style.width = Math.min(pct, 100) + '%';

            if (diff < -200) {
                bar.className = 'progress-bar bg-teal';
                label.className = 'fw-bold text-teal';
                label.textContent = diff + ' kcal (deficyt)';
            } else if (diff <= 100) {
                bar.className = 'progress-bar bg-blue';
                label.className = 'fw-bold text-blue';
                label.textContent = (diff >= 0 ? '+' : '') + diff + ' kcal (norma)';
            } else {
                bar.className = 'progress-bar bg-red';
                label.className = 'fw-bold text-red';
                label.textContent = '+' + diff + ' kcal (nadmiar)';
            }

            // Netto info jeśli jest trening
            const netLabel = document.getElementById('netKcalLabel');
            if (burned > 0) {
                netLabel.classList.remove('d-none');
                netLabel.textContent = `netto: ${netEaten} kcal (${eaten} - ${burned})`;
            }

            // Update dzisiejszej kratki w kalendarzu (używamy netto)
            updateTodayCalendarCell(netEaten, diff);
        }
    }

    async function deleteMeal(id, btn) {
        try {
            const res = await fetch('/api/meals', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({ action: 'delete', id: id, project_id: PROJECT_ID }),
            });
            const data = await res.json();
            const row = btn.closest('.meal-row');
            if (row) row.remove();
            if (data.totals) updateTotals(data.totals);
        } catch (e) {}
    }

    async function autoLogMealsFromChat(mealItems) {
        for (const item of mealItems) {
            const action = item.action || 'add';
            const desc = `${item.amount || ''} ${item.name || ''}`.trim();

            try {
                if (action === 'remove') {
                    const res = await fetch('/api/meals', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                        body: JSON.stringify({
                            action: 'remove-by-name',
                            project_id: PROJECT_ID,
                            name: item.name || desc,
                        }),
                    });
                    const data = await res.json();
                    if (data.removed) {
                        removeMealFromList(data.removed);
                    }
                    if (data.totals) updateTotals(data.totals);
                    continue;
                }

                if (action === 'replace') {
                    const res = await fetch('/api/meals', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                        body: JSON.stringify({
                            action: 'replace',
                            project_id: PROJECT_ID,
                            old_name: item.old_name || item.name || '',
                            description: desc,
                            meal_type: item.meal_type || 'przekaska',
                        }),
                    });
                    const data = await res.json();
                    // Usuń stary wpis z listy wizualnej
                    if (data.replaced) {
                        removeMealFromList(data.replaced);
                    }
                    // Dodaj nowy
                    if (data.source === 'local') {
                        addMealToList(desc, data.nutrition, 'DB', data.id);
                    } else if (data.source === 'ai_estimate') {
                        addMealToList(desc, data.estimate?.total, 'AI', data.id);
                    } else {
                        addMealToList(desc, null, '?', data.id);
                    }
                    if (data.totals) updateTotals(data.totals);
                    continue;
                }

                // Domyślnie: action === 'add'
                if (!desc) continue;
                const res = await fetch('/api/meals', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({
                        action: 'log',
                        project_id: PROJECT_ID,
                        description: desc,
                        meal_type: item.meal_type || 'przekaska',
                    }),
                });

                const data = await res.json();

                if (data.source === 'local') {
                    addMealToList(desc, data.nutrition, 'DB', data.id);
                } else if (data.source === 'ai_estimate') {
                    addMealToList(desc, data.estimate?.total, 'AI', data.id);
                } else {
                    addMealToList(desc, null, '?', data.id);
                }

                if (data.totals) updateTotals(data.totals);
            } catch (e) { /* nie blokuj chatu */ }
        }
    }

    async function autoLogTrainingFromChat(trainingItems) {
        for (const item of trainingItems) {
            if (!item.name || !item.kcal_burned) continue;
            try {
                const res = await fetch('/api/meals', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify({
                        action: 'log-training',
                        project_id: PROJECT_ID,
                        name: item.name,
                        duration_min: item.duration_min || 0,
                        kcal_burned: item.kcal_burned,
                        intensity: item.intensity || 'umiarkowana',
                    }),
                });
                const data = await res.json();
                if (data.id) {
                    addTrainingToList(data.description, data.kcal_burned, data.id);
                }
                if (data.totals) updateTotals(data.totals);
            } catch (e) { /* nie blokuj chatu */ }
        }
    }

    async function updateWeightFromSummary(weight) {
        try {
            // Aktualizuj project_state
            await fetch('/api/project-state', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ project_id: PROJECT_ID, current_weight_kg: weight, _csrf: CSRF_TOKEN }),
            });
            // Aktualizuj UI
            const weightEl = document.getElementById('stateWeight');
            if (weightEl) weightEl.textContent = weight + ' kg';
            // Wypełnij pole wagi w checkpoincie
            const checkpointWeight = document.querySelector('input[name="weight"]');
            if (checkpointWeight && !checkpointWeight.value) checkpointWeight.value = weight;
        } catch (e) { /* nie blokuj chatu */ }
    }

    async function saveProfileUpdate(profile) {
        const payload = { project_id: PROJECT_ID, _csrf: CSRF_TOKEN };
        if (profile.height_cm) payload.height_cm = profile.height_cm;
        if (profile.age) payload.age = profile.age;
        if (profile.sex) payload.sex = profile.sex;
        try {
            await fetch('/api/project-state', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify(payload),
            });
        } catch (e) { /* nie blokuj chatu */ }
    }

    function addTrainingToList(desc, kcalBurned, id) {
        const container = document.getElementById('trainingList');
        if (!container) return;
        const div = document.createElement('div');
        div.className = 'd-flex align-items-center justify-content-between meal-row';
        div.dataset.mealId = id;
        div.innerHTML = `
            <span><i class="ti ti-run text-orange me-1"></i>${escapeHtml(desc)}</span>
            <span class="d-flex align-items-center">
                <strong class="text-orange">${kcalBurned} kcal</strong>
                <button class="btn btn-icon btn-sm btn-ghost-danger ms-1" onclick="deleteMeal(${id}, this)" style="width:20px;height:20px;">
                    <i class="ti ti-x" style="font-size:10px;"></i>
                </button>
            </span>
        `;
        container.appendChild(div);
    }

    function removeMealFromList(mealId) {
        const row = document.querySelector(`.meal-row[data-meal-id="${mealId}"]`);
        if (row) row.remove();
    }

    async function loadTodayMeals() {
        try {
            const res = await fetch(`/api/meals?project_id=${PROJECT_ID}&type=today`);
            const data = await res.json();

            if (data.meals && data.meals.length > 0) {
                data.meals.forEach(meal => {
                    if (meal.source === 'training') {
                        addTrainingToList(meal.description, meal.total_kcal, meal.id);
                        return;
                    }
                    const nutrition = meal.total_kcal > 0 ? {
                        kcal: meal.total_kcal,
                        protein: meal.total_protein,
                        carbs: meal.total_carbs,
                        fat: meal.total_fat,
                    } : null;
                    const source = meal.source === 'local' ? 'DB' : meal.source === 'openfoodfacts' ? 'OFF' : meal.source === 'ai_estimate' ? 'AI' : '?';
                    addMealToList(meal.description, nutrition, source, meal.id);
                });
            }
            if (data.totals) updateTotals(data.totals);
        } catch (e) {}
    }

    function kcalColor(kcal) {
        const diff = kcal - KCAL_TARGET;
        if (diff < -200) return '#12b886';
        if (diff <= 100) return '#4dabf7';
        return '#fa5252';
    }

    function todayStr() {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    function updateTodayCalendarCell(kcal, diff) {
        const cell = document.querySelector(`[data-cal-date="${todayStr()}"]`);
        if (!cell) return;
        cell.style.background = kcalColor(kcal);
        cell.title = `${todayStr()}: ${kcal} kcal (${diff >= 0 ? '+' : ''}${diff})`;
    }

    async function loadKcalCalendar() {
        const container = document.getElementById('kcalCalendar');
        if (!container) return;

        try {
            const res = await fetch(`/api/meals?project_id=${PROJECT_ID}&type=calendar&days=56`);
            const data = await res.json();

            const dayData = {};
            if (data.calendar) {
                data.calendar.forEach(d => { dayData[d.date] = d; });
            }

            const today = new Date();
            today.setHours(0,0,0,0);

            // 8 tygodni wstecz, wyrównaj do poniedziałku
            const startDate = new Date(today);
            startDate.setDate(startDate.getDate() - 55);
            while (startDate.getDay() !== 1) startDate.setDate(startDate.getDate() - 1);

            // Oblicz ile tygodni
            const endDate = new Date(today);
            // Dokończ bieżący tydzień (do niedzieli)
            const daysToSunday = (7 - endDate.getDay()) % 7;
            endDate.setDate(endDate.getDate() + daysToSunday);

            const totalDays = Math.round((endDate - startDate) / 86400000) + 1;
            const weeks = Math.ceil(totalDays / 7);

            // Nagłówek: pusty + numery tygodni
            const corner = document.createElement('div');
            corner.style.cssText = 'font-size:0.5rem;color:#adb5bd;';
            container.appendChild(corner);
            ['P','W','Ś','C','P','S','N'].forEach(l => {
                const el = document.createElement('div');
                el.style.cssText = 'font-size:0.5rem;color:#adb5bd;text-align:center;line-height:1;';
                el.textContent = l;
                container.appendChild(el);
            });

            // Zmień grid na tygodnie = wiersze
            const d = new Date(startDate);
            let weekNum = 0;

            while (d <= endDate) {
                const dow = (d.getDay() + 6) % 7; // 0=Pn, 6=Nd

                // Label tygodnia na początku wiersza
                if (dow === 0) {
                    const wl = document.createElement('div');
                    wl.style.cssText = 'font-size:0.45rem;color:#adb5bd;display:flex;align-items:center;justify-content:flex-end;padding-right:2px;';
                    // Pokaż numer miesiąca co 4 tygodnie lub na początku miesiąca
                    if (d.getDate() <= 7) {
                        const months = ['Sty','Lut','Mar','Kwi','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'];
                        wl.textContent = months[d.getMonth()];
                    }
                    container.appendChild(wl);
                    weekNum++;
                }

                const dateStr = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
                const info = dayData[dateStr];
                const isFuture = d > today;
                const isToday = dateStr === todayStr();

                const cell = document.createElement('div');
                cell.setAttribute('data-cal-date', dateStr);
                cell.style.cssText = 'aspect-ratio:1;border-radius:2px;cursor:default;min-height:0;';

                if (isFuture) {
                    cell.style.background = 'transparent';
                } else if (!info || info.meals == 0) {
                    cell.style.background = '#e9ecef';
                } else {
                    const kcal = parseInt(info.kcal);
                    cell.style.background = kcalColor(kcal);
                    const diff = kcal - KCAL_TARGET;
                    cell.title = `${dateStr}: ${kcal} kcal (${diff >= 0 ? '+' : ''}${diff})`;
                }

                if (isToday) {
                    cell.style.outline = '1.5px solid #206bc4';
                    cell.style.outlineOffset = '-1px';
                }

                container.appendChild(cell);
                d.setDate(d.getDate() + 1);
            }
        } catch (e) {}
    }
    // === BACKUP (admin only) ===
    <?php if ($userId === 1): ?>
    loadBackups();
    <?php endif; ?>

    async function loadBackups() {
        try {
            const res = await fetch('/api/backup?action=list');
            const data = await res.json();
            renderBackupList(data.backups || []);
        } catch (e) {}
    }

    function renderBackupList(backups) {
        const countEl = document.getElementById('backupCount');
        const listEl = document.getElementById('backupList');
        if (countEl) countEl.textContent = backups.length + ' kopi';
        if (!listEl) return;

        if (backups.length === 0) {
            listEl.innerHTML = '<div class="text-secondary text-center py-2">Brak backupów</div>';
            return;
        }

        listEl.innerHTML = backups.slice(0, 10).map(b => {
            const labelColor = b.label === 'auto' ? 'blue' : b.label === 'pre-migrate' ? 'orange' : 'green';
            const counts = b.meta?.counts;
            const tooltip = counts
                ? `${counts.messages} wiad. · ${counts.project_memory} pamięć · ${counts.meal_log} posiłki · ${counts.food_cache} produkty`
                : '';
            const jsonFile = b.filename.replace('backup_', 'layers_').replace('.sqlite', '.json');
            return `
                <div class="d-flex align-items-center justify-content-between py-1 border-bottom">
                    <div>
                        <span class="badge bg-${labelColor}-lt me-1">${b.label}</span>
                        <span class="text-secondary">${b.date}</span>
                        <div class="text-muted" style="font-size:0.65rem;" title="${tooltip}">${b.size_human}${counts ? ' · ' + counts.messages + ' wiad.' : ''}</div>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="/api/backup?action=download&file=${encodeURIComponent(b.filename)}" class="btn btn-sm btn-ghost-primary p-1" title="Pobierz SQLite">
                            <i class="ti ti-download" style="font-size:0.8rem;"></i>
                        </a>
                        ${b.has_json ? `<a href="/api/backup?action=download-json&file=${encodeURIComponent(jsonFile)}" class="btn btn-sm btn-ghost-teal p-1" title="Pobierz JSON warstw">
                            <i class="ti ti-file-code" style="font-size:0.8rem;"></i>
                        </a>` : ''}
                        <button onclick="restoreBackup('${b.filename}')" class="btn btn-sm btn-ghost-red p-1" title="Przywróć">
                            <i class="ti ti-restore" style="font-size:0.8rem;"></i>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    async function createBackup() {
        try {
            const res = await fetch('/api/backup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ action: 'create', label: 'manual' }),
            });
            const data = await res.json();
            if (data.backups) renderBackupList(data.backups);
            if (data.message) alert(data.message);
        } catch (e) { alert('Błąd tworzenia backupu'); }
    }

    async function restoreBackup(filename) {
        if (!confirm(`Przywrócić bazę z backupu?\n${filename}\n\nBieżący stan zostanie zbackupowany jako "pre-restore".`)) return;
        try {
            const res = await fetch('/api/backup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify({ action: 'restore', filename }),
            });
            const data = await res.json();
            if (data.message) { alert(data.message); location.reload(); }
            if (data.error) alert(data.error);
        } catch (e) { alert('Błąd przywracania'); }
    }
    // === KOSZTY API (admin only) ===
    <?php if ($userId === 1): ?>
    loadApiCosts();
    <?php endif; ?>

    async function loadApiCosts() {
        try {
            const [summaryRes, recentRes] = await Promise.all([
                fetch('/api/api-usage?type=summary'),
                fetch('/api/api-usage?type=recent&limit=10'),
            ]);
            const summary = await summaryRes.json();
            const recent = await recentRes.json();
            renderCostSummary(summary);
            renderRecentCalls(recent.calls || []);
        } catch (e) {}
    }

    function renderCostSummary(data) {
        const todayEl = document.getElementById('costToday');
        const summaryEl = document.getElementById('costSummary');
        if (!data.today || !data.month) return;

        const t = data.today;
        const m = data.month;
        if (todayEl) todayEl.textContent = '$' + Number(t.cost_usd).toFixed(3);

        if (summaryEl) {
            summaryEl.innerHTML = `
                <div class="d-flex justify-content-between mb-1">
                    <span>Dziś:</span>
                    <strong>${t.calls} wywołań · ${formatTokens(t.total_tokens)} tok · $${Number(t.cost_usd).toFixed(3)}</strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Miesiąc:</span>
                    <strong>${m.calls} wywołań · ${formatTokens(m.total_tokens)} tok · $${Number(m.cost_usd).toFixed(3)}</strong>
                </div>
            `;
        }
    }

    function renderRecentCalls(calls) {
        const el = document.getElementById('costRecent');
        if (!el || calls.length === 0) return;
        el.innerHTML = '<div class="text-secondary fw-bold mb-1">Ostatnie:</div>' + calls.map(c => {
            const time = c.created_at ? c.created_at.slice(11, 16) : '';
            const ctx = c.context || c.endpoint;
            return `<div class="d-flex justify-content-between py-1 border-bottom">
                <span>${time} <span class="badge bg-azure-lt">${ctx}</span></span>
                <span>${formatTokens(c.total_tokens)} tok · $${Number(c.cost_usd).toFixed(4)}</span>
            </div>`;
        }).join('');
    }

    function formatTokens(n) {
        n = Number(n);
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
        return n;
    }

    // === SKAN ETYKIET ===
    async function scanFoodLabel(input) {
        if (!input.files || input.files.length === 0) return;

        const files = Array.from(input.files);
        if (files.length > 5) {
            alert('Max 5 zdjęć na raz');
            input.value = '';
            return;
        }

        // Podgląd zdjęć
        const preview = document.getElementById('scanPreview');
        preview.style.display = '';
        preview.style.setProperty('display', 'flex', 'important');
        preview.innerHTML = files.map((f, i) => {
            const url = URL.createObjectURL(f);
            return `<img src="${url}" style="height:40px; border-radius:4px; border:1px solid var(--tblr-border-color);">`;
        }).join('') + '<span class="badge bg-cyan-lt align-self-center">Analizuję...</span>';

        const mealInput = document.getElementById('mealInput');
        const oldPlaceholder = mealInput.placeholder;
        mealInput.placeholder = `Analizuję ${files.length} zdjęć...`;
        mealInput.disabled = true;

        try {
            const formData = new FormData();
            files.forEach(f => formData.append('photos[]', f));
            formData.append('project_id', PROJECT_ID);

            const res = await fetch('/api/scan-food', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: formData,
            });
            const data = await res.json();

            if (data.error) {
                alert('Błąd: ' + data.error);
                return;
            }

            if (data.product) {
                const p = data.product;
                const portion = p.typical_portion_g || 100;
                mealInput.value = portion + 'g ' + p.name + (p.brand ? ' (' + p.brand + ')' : '');
                preview.innerHTML = `
                    <div class="w-100 p-2 bg-green-lt" style="border-radius:4px; font-size:0.75rem;">
                        <strong>${escapeHtml(p.name)}</strong>${p.brand ? ' <span class="text-muted">(' + escapeHtml(p.brand) + ')</span>' : ''}
                        <div>${p.kcal_100g} kcal/100g · B:${p.protein_100g}g · W:${p.carbs_100g}g · T:${p.fat_100g}g</div>
                        <div class="text-muted">Porcja ${portion}g (${Math.round(p.kcal_100g * portion / 100)} kcal) · kliknij + aby dodać</div>
                    </div>
                `;
            }
        } catch (e) {
            alert('Błąd skanowania');
        } finally {
            mealInput.disabled = false;
            mealInput.placeholder = oldPlaceholder;
            input.value = '';
            <?php if ($userId === 1): ?>loadApiCosts();<?php endif; ?>
            // Ukryj podgląd po 10s jeśli sukces
            setTimeout(() => { preview.style.setProperty('display', 'none', 'important'); }, 10000);
        }
    }

    // === DZIENNIK (Quick Log) ===
    loadQuickLog();

    async function loadQuickLog() {
        try {
            const res = await fetch(`/api/daily-log?project_id=${PROJECT_ID}&type=today`);
            const data = await res.json();
            const dl = data.daily_log;
            if (!dl) return;
            if (dl.water_ml) document.getElementById('qlWater').value = dl.water_ml;
            if (dl.sleep_hours) document.getElementById('qlSleep').value = dl.sleep_hours;
            if (dl.activity_kcal) document.getElementById('qlActivity').value = dl.activity_kcal;
            if (dl.weight) document.getElementById('qlWeight').value = dl.weight;
            if (dl.hunger_level) document.getElementById('qlHunger').value = dl.hunger_level;
            if (dl.energy_level) document.getElementById('qlEnergy').value = dl.energy_level;
            if (dl.notes) document.getElementById('qlNotes').value = dl.notes;
        } catch (e) { /* ignore */ }
    }

    function quickLogWater(amount) {
        const el = document.getElementById('qlWater');
        el.value = (parseInt(el.value) || 0) + amount;
    }

    async function saveQuickLog() {
        const water = parseInt(document.getElementById('qlWater').value) || 0;
        const sleep = parseFloat(document.getElementById('qlSleep').value) || 0;
        const activity = parseInt(document.getElementById('qlActivity').value) || 0;
        const weight = parseFloat(document.getElementById('qlWeight').value) || 0;
        const hunger = parseInt(document.getElementById('qlHunger').value) || 0;
        const energy = parseInt(document.getElementById('qlEnergy').value) || 0;
        const notes = (document.getElementById('qlNotes').value || '').trim();

        if (!water && !sleep && !activity && !weight && !hunger && !energy && !notes) {
            document.getElementById('qlStatus').innerHTML = '<span class="text-warning">Wpisz przynajmniej jedną wartość</span>';
            return;
        }

        const payload = { project_id: PROJECT_ID, _csrf: CSRF_TOKEN };
        if (water) payload.water_ml = water;
        if (sleep) payload.sleep_hours = sleep;
        if (activity) payload.activity_kcal = activity;
        if (weight) payload.weight = weight;
        if (hunger) payload.hunger_level = hunger;
        if (energy) payload.energy_level = energy;
        if (notes) payload.notes = notes;

        try {
            // Zapisz do daily_log
            const res = await fetch('/api/daily-log', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            // Jeśli podano wagę/sen, zapisz też checkpoint (dla historii)
            if (weight || sleep) {
                const cpPayload = { project_id: PROJECT_ID, _csrf: CSRF_TOKEN };
                if (weight) cpPayload.weight = weight;
                if (sleep) cpPayload.sleep_hours = sleep;
                if (hunger) cpPayload.hunger_level = hunger;
                if (energy) cpPayload.energy_level = energy;
                if (notes) cpPayload.notes = notes;
                fetch('/api/checkpoint', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: JSON.stringify(cpPayload),
                }).catch(() => {});
            }

            if (data.id || data.message) {
                document.getElementById('qlStatus').innerHTML = '<span class="text-success"><i class="ti ti-check"></i> Zapisano</span>';
                setTimeout(() => document.getElementById('qlStatus').innerHTML = '', 3000);
                if (weight) {
                    const stateW = document.getElementById('stateWeight');
                    if (stateW) stateW.textContent = weight + ' kg';
                }
            }
        } catch (e) {
            document.getElementById('qlStatus').innerHTML = '<span class="text-danger">Błąd zapisu</span>';
        }
    }

    // === WYKRES KORELACJI ===
    document.getElementById('correlationPanel')?.addEventListener('shown.bs.collapse', () => loadCorrelationChart());

    async function loadCorrelationChart() {
        const days = document.getElementById('correlationDays')?.value || 30;
        const chartEl = document.getElementById('correlationChart');
        const emptyEl = document.getElementById('correlationEmpty');
        if (!chartEl) return;

        try {
            const res = await fetch(`/api/daily-log?project_id=${PROJECT_ID}&type=correlation&days=${days}`);
            const data = await res.json();
            const points = data.correlation || [];

            // Sprawdź czy mamy jakieś dane
            const hasData = points.some(p => p.weight || p.sleep_hours || p.water_ml || p.kcal || p.activity_kcal);
            if (!hasData) {
                chartEl.style.display = 'none';
                emptyEl.style.display = 'block';
                return;
            }
            chartEl.style.display = 'block';
            emptyEl.style.display = 'none';

            renderCorrelationChart(chartEl, points);
        } catch (e) {
            chartEl.innerHTML = '<div class="text-danger text-center py-3" style="font-size:0.75rem;">Błąd ładowania danych</div>';
        }
    }

    function renderCorrelationChart(container, rawPoints) {
        // Przytnij do zakresu z danymi - od pierwszego dnia z jakąkolwiek wartością
        let firstDataIdx = rawPoints.findIndex(p => p.weight || p.kcal || p.sleep_hours || p.water_ml || p.activity_kcal);
        if (firstDataIdx < 0) firstDataIdx = 0;
        const points = rawPoints.slice(firstDataIdx);
        const W = container.clientWidth || 400;
        const H = 280;
        const pad = { t: 20, r: 10, b: 40, l: 38 };
        const cw = W - pad.l - pad.r;
        const ch = H - pad.t - pad.b;

        const series = [
            { key: 'weight', label: 'Waga (kg)', color: '#4dabf7', yMin: null, yMax: null },
            { key: 'kcal', label: 'Kalorie', color: '#51cf66', yMin: 0, yMax: null },
            { key: 'sleep_hours', label: 'Sen (h)', color: '#845ef7', yMin: 0, yMax: 12 },
            { key: 'water_ml', label: 'Woda (ml)', color: '#22b8cf', yMin: 0, yMax: null },
            { key: 'activity_kcal', label: 'Aktywność (kcal)', color: '#ff922b', yMin: 0, yMax: null },
        ];

        // Oblicz zakresy
        series.forEach(s => {
            const vals = points.map(p => p[s.key]).filter(v => v !== null && v !== undefined && v > 0);
            if (vals.length === 0) { s.hidden = true; return; }
            const mn = Math.min(...vals);
            const mx = Math.max(...vals);
            if (s.yMin === null) s.yMin = mn - (mx - mn) * 0.1;
            if (s.yMax === null) s.yMax = mx + (mx - mn) * 0.1;
            if (s.yMax === s.yMin) { s.yMin -= 1; s.yMax += 1; }
        });

        const activeSeries = series.filter(s => !s.hidden);
        if (activeSeries.length === 0) {
            container.innerHTML = '<div class="text-secondary text-center py-3" style="font-size:0.75rem;">Brak danych</div>';
            return;
        }

        // Buduj SVG
        let svg = `<svg width="${W}" height="${H}" style="font-family:inherit;font-size:9px;">`;

        // Siatka pozioma (5 linii)
        for (let i = 0; i <= 4; i++) {
            const y = pad.t + (ch / 4) * i;
            svg += `<line x1="${pad.l}" y1="${y}" x2="${W - pad.r}" y2="${y}" stroke="#e9ecef" stroke-width="1"/>`;
        }

        // Rysuj serie
        activeSeries.forEach(s => {
            let pathD = '';
            let dotsSvg = '';
            points.forEach((p, i) => {
                const v = p[s.key];
                if (v === null || v === undefined || v <= 0) return;
                const x = pad.l + (i / Math.max(1, points.length - 1)) * cw;
                const y = pad.t + ch - ((v - s.yMin) / (s.yMax - s.yMin)) * ch;
                if (!pathD) {
                    pathD = `M${x},${y}`;
                } else {
                    pathD += ` L${x},${y}`;
                }
                dotsSvg += `<circle cx="${x}" cy="${y}" r="2.5" fill="${s.color}" opacity="0.8"><title>${p.date}: ${s.label} = ${v}</title></circle>`;
            });
            if (pathD) {
                svg += `<path d="${pathD}" fill="none" stroke="${s.color}" stroke-width="2" opacity="0.7"/>`;
                svg += dotsSvg;
            }
        });

        // Oś X (daty)
        const step = Math.max(1, Math.floor(points.length / 7));
        points.forEach((p, i) => {
            if (i % step !== 0 && i !== points.length - 1) return;
            const x = pad.l + (i / Math.max(1, points.length - 1)) * cw;
            const label = p.date.slice(5); // MM-DD
            svg += `<text x="${x}" y="${H - 5}" text-anchor="middle" fill="#868e96">${label}</text>`;
        });

        // Legenda
        let lx = pad.l;
        activeSeries.forEach(s => {
            svg += `<rect x="${lx}" y="2" width="8" height="8" rx="1" fill="${s.color}"/>`;
            svg += `<text x="${lx + 11}" y="10" fill="#495057" style="font-size:8px;">${s.label}</text>`;
            lx += s.label.length * 5 + 22;
        });

        svg += '</svg>';
        container.innerHTML = svg;
    }

    </script>
</body>
</html>

