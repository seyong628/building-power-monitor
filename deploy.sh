#!/bin/bash
# deploy.sh - 웹 서버 배포 스크립트

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_DIR="/var/www/html/power"
APACHE_CONF="/etc/apache2/sites-available/power-monitor.conf"

echo "=========================================="
echo "  배포 시작"
echo "=========================================="

# 1. 웹 디렉토리 생성 및 PHP 파일 복사
echo "[1/4] PHP 파일 복사..."
mkdir -p "${WEB_DIR}"
cp "${SCRIPT_DIR}/php/"*.php "${WEB_DIR}/"
chmod 644 "${WEB_DIR}/"*.php
echo "  복사 완료: ${WEB_DIR}"

# 2. Apache 설정 파일 복사
echo "[2/4] Apache 설정 파일 복사..."
cp "${SCRIPT_DIR}/apache/power-monitor.conf" "${APACHE_CONF}"
echo "  복사 완료: ${APACHE_CONF}"

# 3. 사이트 활성화
echo "[3/4] 사이트 활성화..."
a2ensite power-monitor.conf

# 4. Apache 재로드
echo "[4/4] Apache 재로드..."
systemctl reload apache2

echo ""
echo "=========================================="
echo "  배포 완료!"
echo "=========================================="
echo ""
echo "  접속 주소: http://localhost/power/"
echo ""
echo "  injector 실행:"
echo "  python3 ~/projects/building-power-monitor/python/injector.py"
echo ""
echo "  옵션 예시:"
echo "  python3 injector.py --interval 5           # 5초마다"
echo "  python3 injector.py --interval 3 --count 10 # 3초마다 10회"
echo "  python3 injector.py --once                  # 1회 실행"
echo "=========================================="
