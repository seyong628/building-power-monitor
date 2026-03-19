<?php
// config.php - 데이터베이스 설정 및 PDO 연결

define('DB_HOST',     'localhost');
define('DB_PORT',     3306);
define('DB_NAME',     'power_monitor');
define('DB_USER',     'power_user');
define('DB_PASS',     'Power@1234!');
define('REFRESH_SEC', 5);

/**
 * PDO 싱글턴 연결 반환
 */
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}
