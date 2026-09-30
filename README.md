# 건강 대시보드 (HealthDashboard)

애플워치와 아이폰이 **건강(HealthKit)** 에 기록한 정보를 한 화면에서 볼 수 있는 iPhone 앱입니다.
SwiftUI + HealthKit + Swift Charts로 만들었고, 데이터를 **읽기만** 하며 기기 밖으로 보내지 않습니다.

## 볼 수 있는 정보

| 구분 | 항목 |
| --- | --- |
| 활동 링 | 움직이기 / 운동하기 / 일어서기 (오늘 + 최근 7일) |
| 활동 | 걸음 수, 걷기+달리기 거리, 활동/휴식 에너지, 운동 시간, 일어서기 시간, 오른 층수, 사이클 거리, 햇빛 노출 시간 |
| 심장 | 심박수, 안정 시 심박수, 걷기 평균 심박수, 심박 변이(HRV), 심박 회복, 심폐 체력(VO₂ max) |
| 호흡 | 혈중 산소, 호흡수 |
| 활력 징후 | 수면 중 손목 온도, 체온, 혈압(수축기/이완기), 혈당 |
| 신체 측정 | 체중, BMI, 체지방률, 제지방량, 신장 |
| 이동성 | 보행 속도, 보폭, 보행 비대칭성, 양발 지지 시간, 보행 안정성, 6분 걷기 거리 |
| 청각 | 환경 소음, 헤드폰 오디오 |
| 영양 | 수분, 섭취 칼로리, 카페인 |
| 수면 | 지난밤 수면 시간 · 단계(깊은/코어/렘/깨어 있음), 최근 14일 차트 |
| 마음챙김 | 오늘 마음챙김 시간 |
| 운동 | 최근 운동 기록 (시간, 거리, 칼로리, 평균 심박수) |

- 각 지표 카드를 누르면 **7 / 30 / 90일 추세 차트**와 평균·최저·최고가 나옵니다.
- 걸음 수처럼 쌓이는 값은 "오늘 합계", 심박수처럼 측정하는 값은 "가장 최근 측정값"을 보여줍니다.
- 기록이 없는 항목은 기본적으로 숨기며, 설정에서 모두 표시할 수 있습니다.
- 시뮬레이터에서 화면을 확인할 수 있도록 **샘플 데이터 모드**가 있습니다.

## 빌드 방법 (Mac 필요)

1. Xcode 15 이상 설치 (iOS 17 이상 대상)
2. [XcodeGen](https://github.com/yonaskolb/XcodeGen) 설치 후 프로젝트 생성
   ```bash
   brew install xcodegen
   cd <저장소 폴더>
   xcodegen generate
   open HealthDashboard.xcodeproj
   ```
3. Xcode에서 `HealthDashboard` 타깃 → **Signing & Capabilities**
   - Team: 본인 Apple ID(개인 팀) 선택
   - Bundle Identifier를 고유한 값으로 변경 (예: `com.<내이름>.healthdashboard`)
   - HealthKit capability가 포함되어 있는지 확인 (`project.yml`에서 자동 설정됨)
4. 아이폰을 연결하고 실행(⌘R) → 처음 실행 시 **"건강 데이터 연결하기"** 를 눌러 읽기 권한을 허용

> 실제 데이터는 **실기기**에서만 보입니다. 시뮬레이터에서는 "샘플 데이터로 둘러보기"를 사용하세요.
> 이미 권한을 거부했다면 설정 앱 › 건강 › 데이터 접근 및 기기 › 건강 대시보드에서 다시 켤 수 있습니다.

## 프로젝트 구조

```
project.yml                     XcodeGen 설정 (Info.plist 권한 문구, HealthKit entitlement 포함)
HealthDashboard/
├── App/HealthDashboardApp.swift    앱 진입점, 첫 화면 분기
├── Models/
│   ├── HealthMetric.swift          지표 정의 (HealthKit 타입, 단위, 카테고리, 아이콘)
│   └── HealthData.swift            활동 링 / 수면 / 운동 / 추세 데이터 모델
├── Services/
│   ├── HealthKitService.swift      HealthKit 권한 요청과 조회
│   ├── DashboardModel.swift        화면 상태 (@Observable), 새로고침
│   └── DemoData.swift              샘플 데이터
└── Views/
    ├── DashboardView.swift         메인 요약 화면
    ├── MetricDetailView.swift      지표별 추세 차트
    ├── SleepDetailView.swift       수면 상세
    ├── SettingsView.swift          설정
    ├── WelcomeView.swift           첫 실행 화면
    └── Components/                 카드, 활동 링, 수면 막대, 운동 목록
```

## 지표 추가하기

`HealthMetric.all` 배열에 한 줄을 추가하면 권한 요청, 대시보드 카드, 추세 차트에 자동으로 반영됩니다.

```swift
HealthMetric(id: .distanceSwimming, title: "수영 거리", symbol: "figure.pool.swim", category: .activity,
             unit: .meter(), unitLabel: "m", aggregation: .cumulative)
```

## 다음에 해볼 만한 것

- 홈 화면 위젯 (WidgetKit)으로 활동 링 / 걸음 수 표시
- 심전도(ECG), 불규칙한 심장 리듬 알림 기록 표시
- `HKObserverQuery` + 백그라운드 전달로 새 데이터가 들어오면 자동 갱신
- 목표 설정 및 주간 리포트
