#!/usr/bin/env python3
# injector.py - 가상 전력 데이터 생성기

import argparse
import math
import random
import time
from datetime import datetime

import mysql.connector

# DB 설정
DB_CONFIG = {
    "host": "localhost",
    "port": 3306,
    "database": "power_monitor",
    "user": "power_user",
    "password": "Power@1234!",
}

# 건물별 전력 프로파일
BUILDINGS = {
    "BLDG-A": {"name": "본관",      "power_base": 250, "power_range": 80,  "voltage_base": 220, "capacity_kw": 500,  "floors": 10},
    "BLDG-B": {"name": "별관",      "power_base": 150, "power_range": 50,  "voltage_base": 220, "capacity_kw": 300,  "floors": 6},
    "BLDG-C": {"name": "공장동",    "power_base": 600, "power_range": 150, "voltage_base": 380, "capacity_kw": 800,  "floors": 3},
    "BLDG-D": {"name": "주차타워",  "power_base": 80,  "power_range": 30,  "voltage_base": 220, "capacity_kw": 150,  "floors": 5},
    "BLDG-E": {"name": "데이터센터","power_base": 850, "power_range": 50,  "voltage_base": 220, "capacity_kw": 1000, "floors": 2},
}

ZONES = ["A존", "B존", "공용"]

# 누적 kWh 세션 초기값
cumulative_kwh = {bid: 0.0 for bid in BUILDINGS}


def get_time_factor(now: datetime) -> float:
    """시간대별 부하 계수 반환 (0.3 ~ 1.0)"""
    h = now.hour + now.minute / 60.0

    # 야간 (0~6시): 최소 30%
    if 0 <= h < 6:
        return 0.3

    # 출근 시간대 (6~9시): 30% → 100% 선형 증가
    if 6 <= h < 9:
        return 0.3 + 0.7 * (h - 6) / 3.0

    # 업무 시간 (9~12시): 사인파로 최대 부하
    if 9 <= h < 12:
        return 0.9 + 0.1 * math.sin(math.pi * (h - 9) / 3.0)

    # 점심 (12~13시): 소폭 감소
    if 12 <= h < 13:
        return 0.75

    # 오후 업무 (13~18시): 사인파 최대 부하
    if 13 <= h < 18:
        return 0.9 + 0.1 * math.sin(math.pi * (h - 13) / 5.0)

    # 퇴근 후 (18~22시): 100% → 30% 감소
    if 18 <= h < 22:
        return 1.0 - 0.7 * (h - 18) / 4.0

    # 심야 (22~24시): 30%
    return 0.3


def generate_reading(building_id: str, profile: dict, interval_sec: int) -> dict:
    """단일 측정값 딕셔너리 생성"""
    global cumulative_kwh

    now = datetime.now()
    time_factor = get_time_factor(now)

    voltage = profile["voltage_base"] + random.gauss(0, 2)
    power_factor = random.uniform(0.85, 0.98)
    power_kw = max(0.1, profile["power_base"] * time_factor + random.gauss(0, 5))
    current_a = (power_kw * 1000) / (voltage * power_factor)

    # 누적 kWh: interval 초 동안 소비량 합산
    cumulative_kwh[building_id] += power_kw * (interval_sec / 3600.0)
    power_kwh = cumulative_kwh[building_id]
    co2_kg = power_kwh * 0.4781

    floor = random.randint(1, profile["floors"])
    zone = random.choice(ZONES)

    return {
        "building_id": building_id,
        "floor": floor,
        "zone": zone,
        "voltage": round(voltage, 2),
        "current_a": round(current_a, 3),
        "power_kw": round(power_kw, 3),
        "power_kwh": round(power_kwh, 3),
        "power_factor": round(power_factor, 3),
        "co2_kg": round(co2_kg, 3),
    }


def check_alerts(conn, reading: dict, capacity_kw: float):
    """알람 조건 확인 및 INSERT"""
    alerts = []
    bid = reading["building_id"]
    floor = reading["floor"]
    kw = reading["power_kw"]
    voltage = reading["voltage"]
    pf = reading["power_factor"]

    if kw > capacity_kw * 0.90:
        alerts.append(("over_load", kw, capacity_kw * 0.90,
                        f"{bid} 과부하: {kw:.1f}kW (임계값 {capacity_kw*0.90:.1f}kW)"))

    if kw > capacity_kw * 0.95:
        alerts.append(("peak_demand", kw, capacity_kw * 0.95,
                        f"{bid} 피크수요: {kw:.1f}kW (임계값 {capacity_kw*0.95:.1f}kW)"))

    if voltage < 200:
        alerts.append(("voltage_drop", voltage, 200.0,
                        f"{bid} 전압강하: {voltage:.1f}V (임계값 200V)"))

    if pf < 0.85:
        alerts.append(("low_pf", pf, 0.85,
                        f"{bid} 역률불량: {pf:.3f} (임계값 0.85)"))

    if alerts:
        cursor = conn.cursor()
        for alert_type, value, threshold, message in alerts:
            cursor.execute(
                """INSERT INTO power_alerts
                   (building_id, floor, alert_type, value, threshold, message)
                   VALUES (%s, %s, %s, %s, %s, %s)""",
                (bid, floor, alert_type, value, threshold, message),
            )
        conn.commit()
        cursor.close()


def insert_readings(conn, readings: list):
    """측정값 일괄 INSERT"""
    cursor = conn.cursor()
    cursor.executemany(
        """INSERT INTO power_readings
           (building_id, floor, zone, voltage, current_a,
            power_kw, power_kwh, power_factor, co2_kg)
           VALUES (%(building_id)s, %(floor)s, %(zone)s, %(voltage)s,
                   %(current_a)s, %(power_kw)s, %(power_kwh)s,
                   %(power_factor)s, %(co2_kg)s)""",
        readings,
    )
    conn.commit()
    cursor.close()


def main():
    parser = argparse.ArgumentParser(description="건물 전력 데이터 생성기")
    parser.add_argument("--interval", type=int, default=5, help="측정 간격(초), 기본값 5")
    parser.add_argument("--count", type=int, default=0, help="실행 횟수 (0=무한)")
    parser.add_argument("--once", action="store_true", help="1회 실행 후 종료")
    args = parser.parse_args()

    if args.once:
        args.count = 1

    print("========================================")
    print("  건물 전력 데이터 생성기 시작")
    print(f"  간격: {args.interval}초  |  횟수: {'무한' if args.count == 0 else args.count}")
    print("========================================")

    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        print("  MySQL 연결 성공\n")
    except mysql.connector.Error as e:
        print(f"  [오류] MySQL 연결 실패: {e}")
        return

    iteration = 0
    try:
        while True:
            iteration += 1
            now = datetime.now()
            readings = []
            log_parts = []

            for bid, profile in BUILDINGS.items():
                reading = generate_reading(bid, profile, args.interval)
                readings.append(reading)
                log_parts.append(f"{bid}: {reading['power_kw']:.1f}kW")
                check_alerts(conn, reading, profile["capacity_kw"])

            insert_readings(conn, readings)
            print(f"[{iteration:04d}] {now.strftime('%Y-%m-%d %H:%M:%S')} | " + " | ".join(log_parts))

            if args.count > 0 and iteration >= args.count:
                break

            time.sleep(args.interval)

    except KeyboardInterrupt:
        print("\n  사용자 중단")
    finally:
        conn.close()
        print(f"  총 {iteration}회 실행 완료. DB 연결 종료.")


if __name__ == "__main__":
    main()
