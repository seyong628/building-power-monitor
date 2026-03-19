# 건물 전력 사용량 실시간 모니터링 시스템

> Ubuntu 24.04 + LAMP Stack 위에서 Python으로 가상 전력 데이터를 생성하고, PHP 대시보드로 5개 건물의 전력 사용량을 실시간 시각화하는 모니터링 시스템

![Ubuntu](https://img.shields.io/badge/Ubuntu-24.04_LTS-E95420?style=flat-square&logo=ubuntu&logoColor=white)
![Apache](https://img.shields.io/badge/Apache-2.4-D22128?style=flat-square&logo=apache&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.12-3776AB?style=flat-square&logo=python&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.4-FF6384?style=flat-square&logo=chart.js&logoColor=white)

---

## 주요 기능

- **실시간 데이터 수집**: Python injector가 5초마다 5개 건물의 전력값(전압·전류·kW·역률 등) 생성 및 MySQL INSERT
- **시간대별 부하 패턴**: 업무/야간/점심 시간대를 반영한 사인파 기반 시뮬레이션
- **자동 알람 감지**: 과부하(90%), 피크수요(95%), 전압강하, 역률불량 자동 감지 및 기록
- **다크 테마 대시보드**: 게이지 바, 실시간 라인 차트, 부하율 바 차트 제공
- **이중 갱신 구조**: PHP meta refresh + Chart.js AJAX 폴링으로 부드러운 업데이트
- **탄소배출 환산**: 전력량 × 0.4781 = CO₂ kg 자동 계산

---

## 빠른 시작 (Quick Start)

```bash
# 저장소 클론
git clone https://github.com/yourname/building-power-monitor.git
cd building-power-monitor

# 1. LAMP 설치 (Ubuntu 24.04, root 필요)
sudo bash setup_lamp.sh

# 2. 웹 배포
sudo bash deploy.sh

# 3. 데이터 생성기 실행
python3 python/injector.py --interval 5

# 4. 브라우저 접속
#    http://localhost/power/
```

---

## 스크린샷



<img width="1919" height="966" alt="image" src="https://github.com/user-attachments/assets/7aeae4b6-0a92-4a09-afd9-15775a8d4b3f" />



---

## 상세 문서

구현 상세, ERD, 시스템 블록도, Step별 설명은 **[process.md](process.md)** 를 참고하세요.
