-- schema.sql - 건물 전력 모니터링 데이터베이스 스키마

-- 테이블 1: buildings
CREATE TABLE IF NOT EXISTS buildings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    building_id   VARCHAR(20) UNIQUE NOT NULL,
    building_name VARCHAR(100) NOT NULL,
    floors        INT NOT NULL,
    capacity_kw   DECIMAL(8,2) NOT NULL,
    location      VARCHAR(100),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 테이블 2: power_readings
CREATE TABLE IF NOT EXISTS power_readings (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    building_id  VARCHAR(20) NOT NULL,
    floor        INT NOT NULL,
    zone         VARCHAR(20) NOT NULL,
    voltage      DECIMAL(6,2),
    current_a    DECIMAL(7,3),
    power_kw     DECIMAL(8,3),
    power_kwh    DECIMAL(10,3),
    power_factor DECIMAL(4,3),
    co2_kg       DECIMAL(8,3),
    recorded_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_building_recorded (building_id, recorded_at),
    CONSTRAINT fk_readings_building
        FOREIGN KEY (building_id) REFERENCES buildings(building_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 테이블 3: power_alerts
CREATE TABLE IF NOT EXISTS power_alerts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    building_id VARCHAR(20) NOT NULL,
    floor       INT,
    alert_type  VARCHAR(50) NOT NULL,
    value       DECIMAL(8,3),
    threshold   DECIMAL(8,3),
    message     VARCHAR(255),
    is_resolved TINYINT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- buildings 초기 데이터
INSERT IGNORE INTO buildings (building_id, building_name, floors, capacity_kw, location) VALUES
('BLDG-A', '본관',       10, 500.00,  '서울시 강남구'),
('BLDG-B', '별관',        6, 300.00,  '서울시 강남구'),
('BLDG-C', '공장동',      3, 800.00,  '서울시 강남구'),
('BLDG-D', '주차타워',    5, 150.00,  '서울시 강남구'),
('BLDG-E', '데이터센터',  2, 1000.00, '서울시 강남구');
