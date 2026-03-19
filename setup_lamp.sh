#!/bin/bash
# setup_lamp.sh - LAMP Stack 자동 설치 스크립트

set -e

DB_NAME="power_monitor"
DB_USER="power_user"
DB_PASS="Power@1234!"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=========================================="
echo "  LAMP Stack 설치 시작"
echo "=========================================="

# 1. apt update & upgrade
echo "[1/7] 패키지 업데이트..."
apt update && apt upgrade -y

# 2. Apache2 설치
echo "[2/7] Apache2 설치..."
apt install -y apache2
systemctl enable apache2
systemctl start apache2
echo "  Apache2 설치 완료"

# 3. MySQL Server 설치
echo "[3/7] MySQL Server 설치..."
apt install -y mysql-server
systemctl enable mysql
systemctl start mysql
echo "  MySQL 설치 완료"

# 4. PHP 8.3 설치
echo "[4/7] PHP 8.3 설치..."
apt install -y php8.3 php8.3-mysql php8.3-cli libapache2-mod-php8.3
echo "  PHP 8.3 설치 완료"

# 5. Python3 및 pip3 설치
echo "[5/7] Python3 및 pip3 설치..."
apt install -y python3 python3-pip
pip3 install mysql-connector-python --break-system-packages
echo "  Python3 설치 완료"

# 6. MySQL DB/USER 설정
echo "[6/7] MySQL 데이터베이스 및 사용자 설정..."
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
echo "  MySQL 설정 완료"
echo "  DB: ${DB_NAME}, USER: ${DB_USER}"

# 7. 스키마 적용
echo "[7/7] 데이터베이스 스키마 적용..."
if [ -f "${SCRIPT_DIR}/sql/schema.sql" ]; then
    mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SCRIPT_DIR}/sql/schema.sql"
    echo "  스키마 적용 완료"
else
    echo "  [경고] sql/schema.sql 파일을 찾을 수 없습니다."
fi

echo ""
echo "=========================================="
echo "  LAMP Stack 설치 완료!"
echo "=========================================="
echo "  Apache: $(apache2 -v 2>&1 | head -1)"
echo "  MySQL:  $(mysql --version)"
echo "  PHP:    $(php -r 'echo PHP_VERSION;')"
echo "  Python: $(python3 --version)"
echo ""
echo "  다음 단계: ./deploy.sh 실행"
echo "=========================================="
