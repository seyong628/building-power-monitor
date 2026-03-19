<?php
// api.php - JSON API 엔드포인트

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action      = $_GET['action']      ?? 'dashboard';
$building_id = $_GET['building_id'] ?? null;

try {
    $pdo = get_pdo();

    switch ($action) {

        // ── dashboard (기본) ──────────────────────────────────────────
        case 'dashboard':
            // 1. 건물별 최신 측정값
            $sql_latest = "
                SELECT b.building_id, b.building_name, b.capacity_kw,
                       r.voltage, r.current_a, r.power_kw, r.power_kwh,
                       r.power_factor, r.co2_kg, r.recorded_at
                FROM buildings b
                LEFT JOIN power_readings r
                    ON r.id = (
                        SELECT id FROM power_readings
                        WHERE building_id = b.building_id
                        ORDER BY recorded_at DESC
                        LIMIT 1
                    )
                ORDER BY b.building_id
            ";
            $latest = $pdo->query($sql_latest)->fetchAll();

            // 2. 최근 30분 kW 추이
            $sql_trend = "
                SELECT building_id, power_kw, recorded_at
                FROM power_readings
                WHERE recorded_at >= NOW() - INTERVAL 30 MINUTE
                ORDER BY recorded_at ASC
            ";
            $trend = $pdo->query($sql_trend)->fetchAll();

            // 3. 미해결 알람 최신 10건
            $sql_alerts = "
                SELECT building_id, floor, alert_type, value, threshold, message, created_at
                FROM power_alerts
                WHERE is_resolved = 0
                ORDER BY created_at DESC
                LIMIT 10
            ";
            $alerts = $pdo->query($sql_alerts)->fetchAll();

            // 4. 전체 합계 통계
            $sql_stats = "
                SELECT
                    COALESCE(SUM(r.power_kw),  0) AS total_kw,
                    COALESCE(SUM(r.power_kwh), 0) AS total_kwh,
                    COALESCE(SUM(r.co2_kg),    0) AS total_co2
                FROM buildings b
                LEFT JOIN power_readings r
                    ON r.id = (
                        SELECT id FROM power_readings
                        WHERE building_id = b.building_id
                        ORDER BY recorded_at DESC
                        LIMIT 1
                    )
            ";
            $stats_row = $pdo->query($sql_stats)->fetch();

            $sql_alert_count = "SELECT COUNT(*) AS cnt FROM power_alerts WHERE is_resolved = 0";
            $alert_count = (int)$pdo->query($sql_alert_count)->fetchColumn();

            $stats = [
                'total_kw'    => (float)$stats_row['total_kw'],
                'total_kwh'   => (float)$stats_row['total_kwh'],
                'total_co2'   => (float)$stats_row['total_co2'],
                'alert_count' => $alert_count,
            ];

            echo json_encode([
                'status'  => 'ok',
                'latest'  => $latest,
                'trend'   => $trend,
                'alerts'  => $alerts,
                'stats'   => $stats,
                'updated' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ── history ───────────────────────────────────────────────────
        case 'history':
            if (!$building_id) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'building_id required']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT building_id, floor, zone, voltage, current_a,
                       power_kw, power_kwh, power_factor, co2_kg, recorded_at
                FROM power_readings
                WHERE building_id = ?
                ORDER BY recorded_at DESC
                LIMIT 50
            ");
            $stmt->execute([$building_id]);
            $rows = $stmt->fetchAll();

            echo json_encode([
                'status'      => 'ok',
                'building_id' => $building_id,
                'rows'        => $rows,
                'count'       => count($rows),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ── unknown ───────────────────────────────────────────────────
        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
