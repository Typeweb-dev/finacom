<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php?redirected=true");
    exit;
}
require_once 'config/db.php';
require_once 'clases/Analizador.php';
require_once 'clases/IADeepSeek.php';

$ia = new IADeepSeek("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");
$analizador = new Analizador($pdo);

// === EMPRESAS ===
$stmt = $pdo->query("SELECT id_empresa, nombre_comercial FROM empresas ORDER BY nombre_comercial");
$empresas = $stmt->fetchAll();

// === SELECCIÓN DE EMPRESA Y PERIODO ===
$empresa_id = filter_var($_GET['empresa'] ?? null, FILTER_VALIDATE_INT);
$periodo_id  = filter_var($_GET['periodo']  ?? null, FILTER_VALIDATE_INT);
$periodo     = null;
$periodos    = [];
$has_data    = false;

if ($empresa_id) {
    // cargar todos los periodos de la empresa (para el select)
    $stmt = $pdo->prepare("
        SELECT id_periodo, nombre_periodo, fecha_inicio, fecha_fin, cerrado
        FROM periodos
        WHERE id_empresa = ?
        ORDER BY fecha_inicio DESC
    ");
    $stmt->execute([$empresa_id]);
    $periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($periodo_id) {
        // Si se pidió un periodo específico, cargarlo (y validar que pertenezca a la empresa)
        $stmt = $pdo->prepare("
            SELECT p.id_periodo, p.nombre_periodo, e.nombre_comercial
            FROM periodos p
            JOIN empresas e ON p.id_empresa = e.id_empresa
            WHERE p.id_periodo = ? AND e.id_empresa = ?
            LIMIT 1
        ");
        $stmt->execute([$periodo_id, $empresa_id]);
        $periodo = $stmt->fetch();
        $has_data = !!$periodo;
    }

    // si no se especificó periodo, usar el último abierto (comportamiento previo)
    if (!$periodo && !$periodo_id) {
        $stmt = $pdo->prepare("
            SELECT p.id_periodo, p.nombre_periodo, e.nombre_comercial
            FROM periodos p
            JOIN empresas e ON p.id_empresa = e.id_empresa
            WHERE e.id_empresa = ? AND p.cerrado = 0
            ORDER BY p.id_periodo DESC LIMIT 1
        ");
        $stmt->execute([$empresa_id]);
        $periodo = $stmt->fetch();
        $has_data = !!$periodo;
    }
}

// === CÁLCULOS ===
$razones = $has_data && isset($periodo['id_periodo']) ? $analizador->getRazonesCompletas($periodo['id_periodo']) : [];

// === INTERPRETACIÓN IA ===
$interpretacion = $has_data && isset($periodo['id_periodo']) ? $ia->interpretar(
    "Liquidez Corriente",
    $razones['liquidez_corriente'] ?? 0,
    null,
    "Empresa: {$periodo['nombre_comercial']}. Período: {$periodo['nombre_periodo']}"
) : "Selecciona una empresa y período para ver el análisis inteligente.";

// === GRÁFICO LIQUIDEZ: últimos 6 periodos de la empresa ===
$liquidez_datos = [];
if ($empresa_id) {
    $stmt = $pdo->prepare("
        SELECT p.nombre_periodo, COALESCE(r.valor, 0) AS valor
        FROM periodos p
        LEFT JOIN razones_financieras r ON r.id_periodo = p.id_periodo AND r.nombre_razon = 'Liquidez Corriente'
        WHERE p.id_empresa = ?
        ORDER BY p.fecha_inicio DESC LIMIT 6
    ");
    $stmt->execute([$empresa_id]);
    $liquidez_datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$labels = array_reverse(array_column($liquidez_datos, 'nombre_periodo')) ?: [];
$valores = array_reverse(array_column($liquidez_datos, 'valor')) ?: [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinanCom • Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --gray-100: #f8fafc;
            --gray-200: #e2e8f0;
            --gray-800: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            min-height: 100vh;
            color: var(--gray-800);
        }

        /* Sidebar Premium */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 280px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(99,102,241,0.2);
            padding: 2rem 1.5rem;
            z-index: 1050;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 10px 40px rgba(99,102,241,0.15);
        }
        .sidebar.collapsed {
            transform: translateX(-100%);
        }
        .sidebar-header h1 {
            font-weight: 800;
            font-size: 1.8rem;
            background: linear-gradient(90deg, var(--primary), #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            border-radius: 16px;
            color: var(--gray-800);
            font-weight: 500;
            transition: all 0.3s ease;
            margin: 8px 0;
        }
        .nav-link:hover, .nav-link.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1));
            color: var(--primary);
            transform: translateX(8px);
            box-shadow: 0 8px 25px rgba(99,102,241,0.2);
        }
        .nav-link i { font-size: 1.3rem; }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 3rem 2.5rem;
            transition: margin-left 0.4s ease;
        }
        .main-content.expanded {
            margin-left: 0;
        }

        /* Botón menú */
        .menu-btn {
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 1060;
            background: white;
            border: none;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            box-shadow: 0 10px 30px rgba(99,102,241,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .menu-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 15px 40px rgba(99,102,241,0.4);
        }
        .menu-btn i { font-size: 1.6rem; color: var(--primary); }

        /* Header */
        .header-title {
            font-weight: 800;
            font-size: 2.6rem;
            background: linear-gradient(90deg, var(--primary), #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Selector empresa */
        .empresa-selector-container {
            position: relative;
            max-width: 760px;
        }
        .empresa-selector {
            padding: 1rem 1.2rem 1rem 3.5rem;
            border: 2px solid transparent;
            border-radius: 20px;
            background: white;
            backdrop-filter: blur(12px);
            box-shadow: 0 10px 30px rgba(99,102,241,0.2);
            font-weight: 600;
            font-size: 1.05rem;
            transition: all 0.3s;
        }
        .empresa-selector:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 5px rgba(99,102,241,0.25);
            transform: translateY(-2px);
        }
        .selector-icon {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            font-size: 1.4rem;
            pointer-events: none;
        }

        /* Tarjetas indicadores */
        .indicator-card {
            background: white;
            border-radius: 1.6rem;
            padding: 2rem;
            box-shadow: 0 15px 35px rgba(99,102,241,0.12);
            transition: all 0.4s ease;
            border: 1px solid rgba(99,102,241,0.1);
            text-align: center;
        }
        .indicator-card:hover {
            transform: translateY(-12px);
            box-shadow: 0 25px 50px rgba(99,102,241,0.25);
        }
        .indicator-title {
            font-size: 1rem;
            color: var(--gray-800);
            font-weight: 500;
            margin-bottom: 1rem;
        }
        .indicator-value {
            font-size: 2.6rem;
            font-weight: 800;
            background: linear-gradient(90deg, var(--primary), #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Gráfico e IA */
        .chart-card, .ia-card {
            background: white;
            border-radius: 1.8rem;
            padding: 2.2rem;
            box-shadow: 0 20px 50px rgba(99,102,241,0.15);
            border: 1px solid rgba(99,102,241,0.1);
        }
        .ia-output {
            background: rgba(99,102,241,0.05);
            border-radius: 1.2rem;
            padding: 1.8rem;
            font-size: 0.98rem;
            line-height: 1.8;
            color: var(--gray-800);
            max-height: 400px;
            overflow-y: auto;
            border: 1px dashed rgba(99,102,241,0.2);
        }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0 !important; padding: 2rem 1.5rem; }
            .menu-btn { display: flex !important; }
        }
        .periodo-wrapper {
    position: relative;
    width: 260px;
    background: linear-gradient(135deg, #ffffff 0%, #fafaff 100%);
    padding: 1rem 1.2rem 1rem 3.2rem;
    border-radius: 18px;
    box-shadow: 0 10px 25px rgba(99,102,241,0.15);
    border: 2px solid transparent;
    transition: all .3s ease;
}

/* Icono */
.period-icon {
    position: absolute;
    left: 1.1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #4f46e5;
    font-size: 1.25rem;
    opacity: .9;
}

/* Select estilizado */
.periodo-selector {
    border: none;
    background: transparent;
    font-weight: 600;
    font-size: 1rem;
    padding-left: .5rem;
    color: #1e293b;
    cursor: pointer;
    outline: none !important;
    box-shadow: none !important;
}

/* Hover efecto premium */
.periodo-wrapper:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 35px rgba(99,102,241,0.25);
    border-color: rgba(99,102,241,0.35);
}

/* Focus: cuando se selecciona */
.periodo-selector:focus + .period-icon,
.periodo-wrapper:focus-within {
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99,102,241,0.25);
}

/* Disabled visual */
.periodo-selector:disabled {
    opacity: .5;
    cursor: not-allowed;
}
    </style>
</head>
<body>

<!-- Botón menú (visible en móvil y cuando sidebar está oculta) -->
<button class="menu-btn" id="menuBtn">
    <i class="bi bi-list"></i>
</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header text-center mb-5">
        <h1><i class="bi bi-pie-chart-fill me-2"></i> FinanCom</h1>
        <p class="small text-muted mt-2">Hola, <?= htmlspecialchars($_SESSION['user']['nombre'] ?? 'Usuario') ?></p>
    </div>
    <nav>
        <a href="dashboard.php" class="nav-link active"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="views/ingresar_datos/empresa.php" class="nav-link"><i class="bi bi-building"></i> Empresas</a>
        <a href="views/ingresar_datos/estados_financieros.php" class="nav-link"><i class="bi bi-table"></i> Estados Financieros</a>
        <a href="views/analisis/compare_periodos.php" class="nav-link"><i class="bi bi-calculator"></i> Analisis</a>
        
    </nav>
    <div class="mt-auto text-center pt-4">
        <img src="assets/img/admin.png" class="rounded-circle mb-3" width="70" alt="User">
        <p class="fw-medium"><?= htmlspecialchars($_SESSION['user']['nombre'] ?? 'Usuario') ?></p>
        
    </div>
</div>

<!-- Main Content -->
<div class="main-content" id="mainContent">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-4 mb-5">
        <div>
            <h1 class="header-title mb-2">Análisis Financiero</h1>
            <p class="text-muted fs-5">
                <strong><?= htmlspecialchars($periodo['nombre_comercial'] ?? 'Selecciona una empresa') ?></strong>
                <?= $periodo ? ' • ' . htmlspecialchars($periodo['nombre_periodo']) : '' ?>
            </p>
        </div>

        <!-- SELECT: Empresa + Período -->
        <div class="empresa-selector-container d-flex align-items-center gap-3">
            <div style="flex:1; min-width:220px; position:relative;">
                <select class="form-select empresa-selector" id="empresaSelect" aria-label="Seleccionar empresa">
                    <option value="">Seleccionar empresa</option>
                    <?php foreach ($empresas as $e): ?>
                        <option value="<?= (int)$e['id_empresa'] ?>" <?= $empresa_id == $e['id_empresa'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['nombre_comercial']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="selector-icon"><i class="bi bi-building"></i></div>
            </div>

            <div class="periodo-wrapper">
    <i class="bi bi-calendar3 period-icon"></i>
    <select class="form-select periodo-selector" id="periodoSelect" aria-label="Seleccionar período" 
            <?= empty($periodos) ? 'disabled' : '' ?>>
        <option value="">Seleccionar período</option>
        <?php foreach ($periodos as $p): ?>
            <option value="<?= (int)$p['id_periodo'] ?>"
                <?= (($periodo && $periodo['id_periodo'] == $p['id_periodo']) || ($periodo_id == $p['id_periodo'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['nombre_periodo'] . ' — ' . date('Y-m-d', strtotime($p['fecha_inicio']))) ?>
                <?= $p['cerrado'] ? ' (Cerrado)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
        </div>
    </div>

    <!-- Indicadores -->
    <div class="row g-3 g-xl-5 mb-5">
        <?php
        $indicadores = [
            ['Liquidez Corriente', $razones['liquidez_corriente'] ?? null],
            ['Margen Neto', isset($razones['margen_neto']) ? (round($razones['margen_neto']*100,2).' %') : null],
            ['Endeudamiento', $razones['endeudamiento'] ?? null],
            ['Rotacion de Inventario', $razones['rotacion_inventarios'] ?? null],
            
        ];
        foreach ($indicadores as $i): ?>
            <div class="col-md-6 col-lg-3">
                <div class="indicator-card">
                    <div class="indicator-title"><?= htmlspecialchars($i[0]) ?></div>
                    <div class="indicator-value">
                        <?= $has_data && $i[1] !== null ? htmlspecialchars($i[1]) : '--' ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Gráfico + IA -->
    
        <div class="col-lg-4">
            <div class="ia-card h-100 d-flex flex-column">
                <h4 class="fw-bold mb-4 text-primary"><i class="bi bi-stars me-2"></i>Interpretación General</h4>
                <div class="ia-output flex-grow-1">
                    <?= nl2br(htmlspecialchars($interpretacion)) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar toggle
document.getElementById('menuBtn').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
});

// Cambiar empresa
document.getElementById('empresaSelect').addEventListener('change', function() {
    const url = new URL(window.location);
    if (this.value) url.searchParams.set('empresa', this.value);
    else url.searchParams.delete('empresa');

    // al cambiar empresa, quitar el parámetro periodo para forzar recarga del listado
    url.searchParams.delete('periodo');
    window.location = url;
});

// Cambiar periodo
const periodoSelect = document.getElementById('periodoSelect');
if (periodoSelect) {
    periodoSelect.addEventListener('change', function() {
        const url = new URL(window.location);
        if (this.value) url.searchParams.set('periodo', this.value);
        else url.searchParams.delete('periodo');

        // conservar empresa actual si existe
        const empresaSel = document.getElementById('empresaSelect');
        if (empresaSel && empresaSel.value) url.searchParams.set('empresa', empresaSel.value);

        window.location = url;
    });
}

// Gráfico (Chart.js)
new Chart(document.getElementById('liquidezChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?: '[]' ?>,
        datasets: [{
            label: 'Liquidez Corriente',
            data: <?= json_encode($valores) ?: '[]' ?>,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,0.1)',
            fill: true,
            tension: 0.4,
            borderWidth: 4,
            pointRadius: 7,
            pointHoverRadius: 10,
            pointBackgroundColor: '#6366f1'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
</body>
</html>
