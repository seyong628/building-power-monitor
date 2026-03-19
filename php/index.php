<?php
// index.php - 건물 전력 사용량 실시간 모니터링 대시보드

require_once __DIR__ . '/config.php';

// 초기 데이터 로드
$pdo = get_pdo();

// 건물별 최신값
$latest = $pdo->query("
    SELECT b.building_id, b.building_name, b.capacity_kw,
           r.voltage, r.current_a, r.power_kw, r.power_kwh,
           r.power_factor, r.co2_kg, r.recorded_at
    FROM buildings b
    LEFT JOIN power_readings r
        ON r.id = (
            SELECT id FROM power_readings
            WHERE building_id = b.building_id
            ORDER BY recorded_at DESC LIMIT 1
        )
    ORDER BY b.building_id
")->fetchAll();

// 통계
$stats_row = $pdo->query("
    SELECT
        COALESCE(SUM(r.power_kw),  0) AS total_kw,
        COALESCE(SUM(r.power_kwh), 0) AS total_kwh,
        COALESCE(SUM(r.co2_kg),    0) AS total_co2
    FROM buildings b
    LEFT JOIN power_readings r
        ON r.id = (
            SELECT id FROM power_readings
            WHERE building_id = b.building_id
            ORDER BY recorded_at DESC LIMIT 1
        )
")->fetch();

$alert_count = (int)$pdo->query("SELECT COUNT(*) FROM power_alerts WHERE is_resolved=0")->fetchColumn();

// 알람
$alerts = $pdo->query("
    SELECT building_id, floor, alert_type, value, threshold, message, created_at
    FROM power_alerts
    WHERE is_resolved = 0
    ORDER BY created_at DESC LIMIT 10
")->fetchAll();

// 상태 판정 함수
function get_status(float $kw, float $cap): string {
    $ratio = ($cap > 0) ? ($kw / $cap) : 0;
    if ($ratio >= 0.90) return 'CRITICAL';
    if ($ratio >= 0.70) return 'WARNING';
    return 'NORMAL';
}

function status_class(string $s): string {
    return match($s) {
        'CRITICAL' => 'badge-critical',
        'WARNING'  => 'badge-warning',
        default    => 'badge-normal',
    };
}

function alert_class(string $t): string {
    return match($t) {
        'over_load'    => 'alert-red',
        'voltage_drop' => 'alert-orange',
        'low_pf'       => 'alert-yellow',
        'peak_demand'  => 'alert-purple',
        default        => '',
    };
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="<?= REFRESH_SEC ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>건물 전력 모니터링 시스템</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #0f1117;
    --surface:  #1a1d2e;
    --border:   #2a2d3e;
    --text:     #e2e8f0;
    --muted:    #94a3b8;
    --blue:     #3b82f6;
    --green:    #22c55e;
    --orange:   #f97316;
    --red:      #ef4444;
    --yellow:   #eab308;
    --purple:   #a855f7;
    --sidebar:  220px;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 14px;
    display: flex;
    min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
    width: var(--sidebar);
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
}

.sidebar-title {
    padding: 24px 20px 16px;
    font-size: 18px;
    font-weight: 700;
    color: var(--blue);
    border-bottom: 1px solid var(--border);
}

.nav { padding: 12px 0; flex: 1; }
.nav-item {
    display: block;
    padding: 10px 20px;
    color: var(--muted);
    text-decoration: none;
    cursor: pointer;
    transition: background .15s, color .15s;
    border-left: 3px solid transparent;
}
.nav-item:hover, .nav-item.active {
    background: rgba(59,130,246,.12);
    color: var(--text);
    border-left-color: var(--blue);
}

.sidebar-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--muted);
}
.live-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--green);
    margin-right: 6px;
    animation: blink 1s infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Main ── */
.main {
    margin-left: var(--sidebar);
    flex: 1;
    padding: 24px;
    overflow-x: hidden;
}

/* ── Header ── */
.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 8px;
}
.header h1 { font-size: 22px; font-weight: 700; }
.header-meta { font-size: 12px; color: var(--muted); text-align: right; }

/* ── Summary cards ── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}
.card-label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
.card-value { font-size: 28px; font-weight: 700; }
.card-unit  { font-size: 13px; color: var(--muted); margin-left: 4px; }
.c-blue   { border-top: 3px solid var(--blue);   }
.c-green  { border-top: 3px solid var(--green);  }
.c-orange { border-top: 3px solid var(--orange); }
.c-red    { border-top: 3px solid var(--red);    }
.c-gray   { border-top: 3px solid var(--border); }

/* ── Building cards ── */
.building-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.building-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
}
.building-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.building-name { font-weight: 600; font-size: 15px; }
.building-id   { font-size: 11px; color: var(--muted); }
.badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 20px;
}
.badge-normal   { background: rgba(34,197,94,.15);  color: var(--green);  }
.badge-warning  { background: rgba(249,115,22,.15); color: var(--orange); }
.badge-critical { background: rgba(239,68,68,.15);  color: var(--red);    }

.gauge-wrap { margin: 10px 0 14px; }
.gauge-info {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 4px;
}
.gauge-bar {
    height: 8px;
    background: var(--border);
    border-radius: 4px;
    overflow: hidden;
}
.gauge-fill {
    height: 100%;
    border-radius: 4px;
    transition: width .5s;
}

.bldg-metrics {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    font-size: 12px;
}
.metric-item { color: var(--muted); }
.metric-item span { color: var(--text); font-weight: 500; }

/* ── Charts ── */
.chart-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.chart-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}
.chart-title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
    color: var(--muted);
}

/* ── Alert panel ── */
.section-title {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}

.alert-table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 24px;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    background: rgba(255,255,255,.04);
    padding: 10px 14px;
    text-align: left;
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
}
td {
    padding: 10px 14px;
    border-top: 1px solid var(--border);
    font-size: 13px;
}
tr:hover td { background: rgba(255,255,255,.02); }

.alert-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}
.alert-red    { background: rgba(239,68,68,.15);   color: var(--red);    }
.alert-orange { background: rgba(249,115,22,.15);  color: var(--orange); }
.alert-yellow { background: rgba(234,179,8,.15);   color: var(--yellow); }
.alert-purple { background: rgba(168,85,247,.15);  color: var(--purple); }

.no-data {
    padding: 20px;
    text-align: center;
    color: var(--muted);
}

@media (max-width: 900px) {
    .sidebar { width: 60px; }
    .sidebar-title, .nav-item { font-size: 0; padding: 14px; }
    .nav-item::before { font-size: 18px; }
    .main { margin-left: 60px; }
    .chart-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav class="sidebar">
    <div class="sidebar-title">⚡ 전력 모니터</div>
    <div class="nav">
        <a class="nav-item active" href="#">📊 대시보드</a>
        <a class="nav-item" href="#">🏢 건물별</a>
        <a class="nav-item" href="#">🔔 알람</a>
        <a class="nav-item" href="#">📋 이력</a>
    </div>
    <div class="sidebar-footer">
        <span class="live-dot"></span>LIVE
        <div style="margin-top:4px">갱신: <?= REFRESH_SEC ?>초마다</div>
    </div>
</nav>

<!-- ── Main ── -->
<main class="main">

    <!-- Header -->
    <div class="header">
        <h1>🏢 건물 전력 사용량 모니터링 시스템</h1>
        <div class="header-meta">
            <div><?= date('Y년 m월 d일 H:i:s') ?></div>
            <div>자동 갱신: <?= REFRESH_SEC ?>초마다</div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="card c-blue">
            <div class="card-label">총 소비전력</div>
            <div class="card-value"><?= number_format((float)$stats_row['total_kw'], 1) ?><span class="card-unit">kW</span></div>
        </div>
        <div class="card c-green">
            <div class="card-label">총 누적전력량</div>
            <div class="card-value"><?= number_format((float)$stats_row['total_kwh'], 1) ?><span class="card-unit">kWh</span></div>
        </div>
        <div class="card c-orange">
            <div class="card-label">총 탄소배출</div>
            <div class="card-value"><?= number_format((float)$stats_row['total_co2'], 1) ?><span class="card-unit">CO₂ kg</span></div>
        </div>
        <div class="card <?= $alert_count > 0 ? 'c-red' : 'c-gray' ?>">
            <div class="card-label">활성 알람</div>
            <div class="card-value" style="color:<?= $alert_count > 0 ? 'var(--red)' : 'var(--text)' ?>">
                <?= $alert_count ?><span class="card-unit">건</span>
            </div>
        </div>
    </div>

    <!-- Building Cards -->
    <div class="building-grid">
    <?php foreach ($latest as $b):
        $kw  = (float)($b['power_kw']  ?? 0);
        $cap = (float)($b['capacity_kw'] ?? 1);
        $pct = min(100, round($kw / $cap * 100, 1));
        $status = get_status($kw, $cap);
        $fill_color = $pct >= 90 ? 'var(--red)' : ($pct >= 70 ? 'var(--orange)' : 'var(--green)');
    ?>
    <div class="building-card">
        <div class="building-header">
            <div>
                <div class="building-name"><?= htmlspecialchars($b['building_name']) ?></div>
                <div class="building-id"><?= htmlspecialchars($b['building_id']) ?></div>
            </div>
            <span class="badge <?= status_class($status) ?>"><?= $status ?></span>
        </div>

        <div class="gauge-wrap">
            <div class="gauge-info">
                <span><?= number_format($kw, 1) ?> kW</span>
                <span><?= $pct ?>% / <?= number_format($cap, 0) ?> kW</span>
            </div>
            <div class="gauge-bar">
                <div class="gauge-fill" style="width:<?= $pct ?>%;background:<?= $fill_color ?>"></div>
            </div>
        </div>

        <div class="bldg-metrics">
            <div class="metric-item">전압 <span><?= number_format((float)($b['voltage'] ?? 0), 1) ?> V</span></div>
            <div class="metric-item">전류 <span><?= number_format((float)($b['current_a'] ?? 0), 2) ?> A</span></div>
            <div class="metric-item">역률 <span><?= number_format((float)($b['power_factor'] ?? 0), 3) ?></span></div>
            <div class="metric-item">누적 <span><?= number_format((float)($b['power_kwh'] ?? 0), 1) ?> kWh</span></div>
            <div class="metric-item">CO₂ <span><?= number_format((float)($b['co2_kg'] ?? 0), 1) ?> kg</span></div>
            <div class="metric-item">갱신 <span style="font-size:11px"><?= $b['recorded_at'] ?? '-' ?></span></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="chart-row">
        <div class="chart-card">
            <div class="chart-title">건물별 실시간 kW 추이 (최근 30분)</div>
            <canvas id="lineChart" height="200"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-title">건물별 현재 부하율 (%)</div>
            <canvas id="barChart" height="200"></canvas>
        </div>
    </div>

    <!-- Alert Panel -->
    <div class="section-title">🔔 활성 알람 (미해결)</div>
    <div class="alert-table-wrap">
        <?php if (empty($alerts)): ?>
        <div class="no-data">현재 활성 알람이 없습니다.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>건물</th>
                    <th>층</th>
                    <th>알람 유형</th>
                    <th>측정값</th>
                    <th>임계값</th>
                    <th>시각</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['building_id']) ?></td>
                <td><?= (int)$a['floor'] ?>F</td>
                <td>
                    <span class="alert-badge <?= alert_class($a['alert_type']) ?>">
                        <?= htmlspecialchars($a['alert_type']) ?>
                    </span>
                </td>
                <td><?= number_format((float)$a['value'], 3) ?></td>
                <td><?= number_format((float)$a['threshold'], 3) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= $a['created_at'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Recent readings table -->
    <div class="section-title">📋 최근 측정값</div>
    <div class="alert-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>건물</th>
                    <th>전압(V)</th>
                    <th>전류(A)</th>
                    <th>kW</th>
                    <th>kWh</th>
                    <th>역률</th>
                    <th>CO₂(kg)</th>
                    <th>상태</th>
                    <th>시각</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($latest as $b):
                $kw     = (float)($b['power_kw']  ?? 0);
                $cap    = (float)($b['capacity_kw'] ?? 1);
                $status = get_status($kw, $cap);
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($b['building_name']) ?></strong><br>
                    <span style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($b['building_id']) ?></span></td>
                <td><?= number_format((float)($b['voltage']      ?? 0), 1) ?></td>
                <td><?= number_format((float)($b['current_a']    ?? 0), 2) ?></td>
                <td><?= number_format((float)($b['power_kw']     ?? 0), 2) ?></td>
                <td><?= number_format((float)($b['power_kwh']    ?? 0), 1) ?></td>
                <td><?= number_format((float)($b['power_factor'] ?? 0), 3) ?></td>
                <td><?= number_format((float)($b['co2_kg']       ?? 0), 2) ?></td>
                <td><span class="badge <?= status_class($status) ?>"><?= $status ?></span></td>
                <td style="font-size:12px;color:var(--muted)"><?= $b['recorded_at'] ?? '-' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>

<!-- ── JavaScript / Chart.js ── -->
<script>
const COLORS = {
    'BLDG-A': '#3b82f6',
    'BLDG-B': '#22c55e',
    'BLDG-C': '#f97316',
    'BLDG-D': '#a855f7',
    'BLDG-E': '#ef4444',
};

const BUILDINGS = <?= json_encode(array_column($latest, 'building_id')) ?>;
const CAPACITIES = <?= json_encode(array_combine(
    array_column($latest, 'building_id'),
    array_column($latest, 'capacity_kw')
)) ?>;

// ── 라인 차트 초기화 ──
const lineCtx = document.getElementById('lineChart').getContext('2d');
const lineChart = new Chart(lineCtx, {
    type: 'line',
    data: { labels: [], datasets: [] },
    options: {
        responsive: true,
        animation: { duration: 400 },
        plugins: { legend: { labels: { color: '#94a3b8', boxWidth: 12 } } },
        scales: {
            x: { ticks: { color: '#94a3b8', maxTicksLimit: 8 }, grid: { color: '#2a2d3e' } },
            y: { ticks: { color: '#94a3b8' }, grid: { color: '#2a2d3e' } }
        }
    }
});

// ── 바 차트 초기화 ──
const barCtx = document.getElementById('barChart').getContext('2d');
const latestKw = <?= json_encode(array_combine(
    array_column($latest, 'building_id'),
    array_map(fn($r) => round((float)($r['power_kw'] ?? 0), 1), $latest)
)) ?>;

const initLoadRatios = BUILDINGS.map(b => {
    const cap = parseFloat(CAPACITIES[b]) || 1;
    return Math.min(100, Math.round((latestKw[b] || 0) / cap * 100 * 10) / 10);
});

const barChart = new Chart(barCtx, {
    type: 'bar',
    data: {
        labels: BUILDINGS,
        datasets: [{
            label: '부하율 (%)',
            data: initLoadRatios,
            backgroundColor: BUILDINGS.map(b => COLORS[b] + 'aa'),
            borderColor: BUILDINGS.map(b => COLORS[b]),
            borderWidth: 1,
            borderRadius: 4,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        animation: { duration: 400 },
        plugins: { legend: { display: false } },
        scales: {
            x: {
                min: 0, max: 100,
                ticks: { color: '#94a3b8', callback: v => v + '%' },
                grid: { color: '#2a2d3e' }
            },
            y: { ticks: { color: '#94a3b8' }, grid: { color: '#2a2d3e' } }
        }
    }
});

// ── AJAX 폴링 (5초마다 차트만 업데이트) ──
function updateCharts() {
    fetch('api.php?action=dashboard')
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'ok') return;

            // 트렌드 데이터 파싱
            const datasets = {};
            const labels   = new Set();
            data.trend.forEach(row => {
                const t = row.recorded_at.substring(11, 16); // HH:MM
                labels.add(t);
                if (!datasets[row.building_id]) datasets[row.building_id] = {};
                datasets[row.building_id][t] = parseFloat(row.power_kw);
            });

            const sortedLabels = Array.from(labels).sort();

            lineChart.data.labels = sortedLabels;
            lineChart.data.datasets = BUILDINGS.map(b => ({
                label: b,
                data: sortedLabels.map(t => datasets[b]?.[t] ?? null),
                borderColor: COLORS[b],
                backgroundColor: COLORS[b] + '22',
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.3,
                fill: false,
                spanGaps: true,
            }));
            lineChart.update();

            // 바 차트 업데이트
            const kws = {};
            data.latest.forEach(row => { kws[row.building_id] = parseFloat(row.power_kw) || 0; });
            barChart.data.datasets[0].data = BUILDINGS.map(b => {
                const cap = parseFloat(CAPACITIES[b]) || 1;
                return Math.min(100, Math.round((kws[b] || 0) / cap * 1000) / 10);
            });
            barChart.update();
        })
        .catch(console.error);
}

updateCharts();
setInterval(updateCharts, <?= REFRESH_SEC * 1000 ?>);
</script>
</body>
</html>
