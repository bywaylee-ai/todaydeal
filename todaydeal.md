# TodayDeal WordPress 플러그인 기능정의서

## 1. 문서 개요

| 항목 | 내용 |
|---|---|
| 문서명 | TodayDeal WordPress 플러그인 기능정의서 |
| 플러그인명 | todaydeal |
| 저자 | dugital <duigital@gmail.com> |
| URL | https://todaydeal.app |
| REST 네임스페이스 | `/wp-json/todaydeal/v1` |
| 대상 환경 | WordPress + WooCommerce(카테고리 taxonomy 용도) |
| 목적 | 기존 플러그인을 모두 제거한 환경에서 TodayDeal 앱에 필요한 기능을 하나의 전용 플러그인으로 제공 |
| 개정 사항 | 팔아요·구해요를 단일 포스트타입 `todaydeal_deal`으로 통합, 두 유형 모두 거래 약속 대상으로 확장, WooCommerce는 카테고리 taxonomy로만 사용, 카테고리 카운트는 자체 카운트 테이블로 확정, 거래글 검색 메타는 postmeta 유지로 확정, 매니저 중재·현장 점검 체크리스트 기능 추가. **1~4단계 구현 완료 및 확장 기능(카테고리별 추가 필드, 관리자 등록/수정 화면, 사용자용 프런트 등록 폼, 약속 상세주소·좌표) 반영 — 자세한 구현 현황은 1.1절 참고.** |
| 구현 상태 | 초안(설계 문서) → **구현 진행 중** (2026-09-14 기준) |

### 1.1 구현 현황

27장 출시 우선순위 기준 **1~4단계(Core/설치검사/인증/회원, `todaydeal_deal` 포스트타입+taxonomy 어댑터, 거래글 CRUD+유형별 검증+상태, 거래 약속+역할파생+단일확정 제약)를 구현 완료**했다. 5~10단계(자체 다국어 대체 체인, 웹훅, 매니저·체크리스트·분쟁, 카테고리 카운트 재계산, 웹훅 재처리 등 관리자 운영도구)는 아직 구현하지 않았다.

구현 과정에서 이 문서의 원래 범위를 벗어나 아래 4가지가 사용자 요청으로 추가되었다. 각 항목의 상세 규칙은 해당 장에 인라인으로 반영했다.

| 확장 기능 | 내용 | 관련 장 |
|---|---|---|
| 카테고리별 추가 필드 | 관리자가 카테고리(product_cat)마다 추가 입력 필드(텍스트/숫자/날짜/선택/체크박스)를 정의하고 필수·선택을 지정. 거래글 등록·수정 시 검증됨 | 10.2 |
| 관리자 등록/수정 화면 | wp-admin에 거래글 생성·수정 메타박스(기본정보/위치/미디어/카테고리별 추가필드) 추가. 원래 문서 9.1의 "목록 화면만 제공" 원칙에서 확장됨 | 9.1, 19 |
| 사용자용 프런트 등록 폼 | `[todaydeal_listing_form]` 숏코드로 로그인 회원이 프런트에서 거래글 등록 가능. 원래 문서 기본 전제 #8(프런트 화면 불필요)에서 예외적으로 추가됨 | 2, 9.1 |
| 약속 상세주소·좌표 | 거래 약속의 `meet_place`(장소명)에 더해 `meet_address`(상세주소), `meet_latitude`/`meet_longitude`(좌표)를 추가로 저장 | 13.1, 20 |

REST API 계약(엔드포인트·오류코드·응답 포맷)은 원 문서와 동일하며, 위 확장은 기존 필드에 선택 필드를 추가하거나(약속 좌표), 기존 API 응답에 필드를 더하는(카테고리별 추가값 `extra_fields`) 방식으로 이루어져 하위 호환을 유지한다.

## 2. 기본 전제

1. 기존 플러그인의 API, 메타키, 숏코드, 훅에 의존하지 않고 새로 구축한다.
2. **팔아요와 구해요는 하나의 전용 포스트타입 `todaydeal_deal`에 저장하고 `listing_type` 값으로 구분한다.** 목록·검색·거리·번역·상태·약속 로직을 공통으로 사용한다.
3. **거래글은 WooCommerce `product`를 사용하지 않는다.** 개인 간 중고거래는 상점 상품 판매와 의미가 다르며, 가격·재고·구매가능 여부·장바구니 속성이 두 유형 모두에 부적합하다.
4. **WooCommerce는 상품 카테고리 taxonomy(`product_cat`) 제공 목적으로만 사용한다.** 주문, 재고, 결제, 고객 데이터는 사용하지 않는다.
5. 회원은 WordPress 기본 사용자, 미디어는 WordPress 기본 첨부파일을 사용한다.
6. TodayDeal 앱은 WordPress 또는 WooCommerce 기본 REST API를 직접 호출하지 않고 본 플러그인의 API만 호출한다.
7. 플러그인은 인증, 권한, 입력 검증, 데이터 변환, 다국어 연결, 웹훅, 운영도구를 책임진다.
8. 테마와 무관하게 동작하며 프런트용 화면이나 숏코드는 필수 범위에 포함하지 않는다. (구현 시 예외: 사용자 편의를 위해 `[todaydeal_listing_form]` 숏코드를 추가로 제공한다. 이 폼은 별도의 저장 로직을 갖지 않고 15장의 REST API를 쿠키·nonce 인증으로 그대로 호출하는 얇은 클라이언트다. 1.1절 참고.)
9. **구매는 별도 엔티티로 모델링하지 않는다.** 거래 의사는 채팅에서 시작하여 거래 약속(appointment) 레코드로 확정되며, 거래글은 그 결과를 반영하는 상태만 갖는다.
10. 앱 내 결제와 배송은 범위에 포함하지 않는다.
11. **매니저 중재는 선택 기능이며 채팅방 초대로만 개입한다.** 매니저는 거래 당사자가 아니고 거래 상태를 직접 변경하지 않으며, 초대된 채팅방과 그에 연결된 약속에만 접근한다.

## 3. 플러그인 목표

- 앱에 안정적이고 버전 관리 가능한 전용 REST API 제공
- 팔아요·구해요를 하나의 데이터 모델과 하나의 상태 규칙으로 관리
- 두 유형 모두에서 동일한 거래 약속 흐름 제공
- 거래글 작성자 소유권과 회원 권한의 서버 측 강제
- 다국어, 위치, 거래 상태를 플러그인 자체 규칙으로 관리
- 하나의 거래글에 여러 상대가 동시에 접근하는 C2C 특성을 데이터 정합성 있게 처리
- 제3자 매니저의 장소 확인·현장 점검·중재를 기록 가능한 데이터로 관리
- 미디어 업로드 보안 강화
- 앱과 WordPress 간 변경사항을 서명된 웹훅으로 동기화
- 관리자에게 설정, 상태, 로그, 재처리 도구 제공

## 4. 의존성과 설치 조건

| 항목 | 조건 |
|---|---|
| WordPress | 운영 시점의 지원 버전 명시 |
| PHP | WordPress 지원 정책에 맞는 버전 |
| WooCommerce | 필수. `product_cat` taxonomy 제공 목적 |
| HTTPS | 운영환경 필수 |
| 영구 링크 | WordPress REST API가 동작해야 함 |
| 다국어 | 특정 외부 다국어 플러그인 없이 자체 번역 연결 모델 제공 |

### 4.1 WooCommerce 의존 범위

플러그인이 WooCommerce에서 사용하는 것은 `product_cat` taxonomy 하나다. 상품, 주문, 재고, 결제, 세금, 배송, 쿠폰, 고객 기능은 사용하지 않는다.

- WooCommerce가 비활성화되면 `product_cat`이 등록 해제되어 카테고리 조회와 거래글 등록이 실패한다. 이 경우 API는 `TAXONOMY_UNAVAILABLE` 오류를 반환하고 관리자 화면에 원인을 표시한다.
- 카테고리를 제외한 조회 API는 카테고리 필드를 비운 상태로 계속 동작해야 한다.
- WooCommerce 의존을 향후 제거할 가능성에 대비해 카테고리 접근은 전용 어댑터 클래스를 통해서만 수행하고, taxonomy 슬러그를 코드 전반에 직접 노출하지 않는다.

활성화 시 필수 환경을 검사하며 조건이 충족되지 않으면 원인을 관리자 화면에 표시한다.

## 5. 구성 모듈

| 모듈 | 기능 |
|---|---|
| Core | 플러그인 부팅, 버전, 활성화·비활성화, 데이터베이스 마이그레이션 |
| REST API | `/todaydeal/v1` 라우트 등록, 요청 검증, 응답 직렬화 |
| Authentication | 사용자 로그인, 토큰 발급·갱신·폐기, 앱 서버 인증 |
| Users | 회원가입, 프로필 조회·수정, 탈퇴 처리 |
| Listings | 거래글 포스트타입 등록, 유형별 검증, 목록·상세·등록·수정·상태·만료 |
| Taxonomy Adapter | WooCommerce `product_cat` 연결, 거래글 카운트, 다국어 표시명 |
| Media | 이미지 검증, 업로드, 파생 이미지, 소유권 관리 |
| Translations | 거래글·카테고리 번역 그룹 관리 |
| Appointments | 거래 약속 생성·상태 관리, 역할 파생, 거래글 상태 파생 처리 |
| Managers | 매니저 프로필, 후보 조회, 초대·수락·해제, 접근 범위 제어 |
| Checklists | 체크리스트 템플릿, 현장 점검 기록, 제출·확인·정정 이력 |
| Disputes | 분쟁 개시·소견·권고·에스컬레이션·종결 |
| Webhooks | 변경 이벤트 발송, 서명, 재시도 |
| Admin | 설정, 상태, 로그, 웹훅 재처리, 데이터 정리. **(구현) 거래글 등록·수정 메타박스, 카테고리별 추가 필드 관리 화면 포함** |
| Observability | 요청 ID, 감사 로그, 상태 점검 |
| Category Fields *(구현 추가)* | 카테고리별 추가 입력 필드 정의(관리자)·값 검증/저장(거래글) — 10.2절 |
| Frontend *(구현 추가)* | `[todaydeal_listing_form]` 숏코드 — REST API를 쿠키·nonce로 호출하는 얇은 클라이언트 |

## 6. 역할 및 권한

| 역할 | 권한 |
|---|---|
| 비회원 | 공개 거래글·카테고리 조회 |
| TodayDeal 회원 | 프로필, 미디어 업로드, 거래글 등록, 거래 약속 참여 |
| 거래글 소유자 | 본인 거래글 수정·상태 변경·삭제, 약속 수락·거절 |
| TodayDeal 매니저 | 초대받아 참여한 채팅방의 약속 장소 확인, 현장 점검 체크리스트 작성, 중재 소견 기록 |
| TodayDeal Staff | 전체 거래글·약속 관리, 매니저 지정, 분쟁 처리 |
| Administrator | 플러그인 설정, 키 관리, 로그, 데이터 정리 |
| 앱 서버 | 허용된 서버 API 호출 및 웹훅 수신 확인 |

커스텀 capability를 정의하고 WordPress 역할에 매핑한다.

- `todaydeal_create_listings`
- `todaydeal_edit_own_listings`
- `todaydeal_manage_all_listings`
- `todaydeal_upload_media`
- `todaydeal_manage_appointments`
- `todaydeal_act_as_manager`
- `todaydeal_write_checklists`
- `todaydeal_assign_managers`
- `todaydeal_manage_checklist_templates`
- `todaydeal_resolve_disputes`
- `todaydeal_manage_settings`
- `todaydeal_view_logs`

거래글 유형별로 capability를 나누지 않는다. 팔아요와 구해요는 동일한 권한 체계를 사용한다.

`todaydeal_act_as_manager`는 매니저로 활동할 수 있는 자격만 부여한다. 실제 접근 권한은 해당 채팅방에 수락 상태로 참여 중인지를 매 요청마다 확인하여 결정한다. capability 보유만으로는 어떤 거래에도 접근할 수 없다.

## 7. 인증 기능

### 7.1 사용자 인증

| ID | 기능 | 정의 |
|---|---|---|
| WP-AUTH-001 | 로그인 | 이메일 또는 사용자명과 비밀번호를 검증한다. |
| WP-AUTH-002 | 액세스 토큰 | 짧은 만료시간의 서명된 액세스 토큰을 발급한다. |
| WP-AUTH-003 | 갱신 토큰 | 회전 가능한 갱신 토큰을 발급하고 해시만 저장한다. |
| WP-AUTH-004 | 토큰 갱신 | 정상 갱신 토큰으로 액세스 토큰을 재발급하며 이전 토큰은 폐기한다. |
| WP-AUTH-005 | 로그아웃 | 해당 기기의 갱신 토큰을 폐기한다. |
| WP-AUTH-006 | 전체 로그아웃 | 비밀번호 변경·탈퇴 시 모든 갱신 토큰을 폐기한다. |
| WP-AUTH-007 | 공격 방지 | 로그인 실패 횟수 제한, IP·계정 기준 지연 및 감사를 적용한다. |

토큰에는 최소한 사용자 ID, 권한, 발급시각, 만료시각, 발급자, 대상 시스템, 토큰 ID를 포함한다.

### 7.2 앱 서버 인증

웹훅 등록, 상태 점검 등 서버 간 기능은 사용자 토큰과 분리한다. 요청 본문 해시, 타임스탬프, nonce를 포함한 HMAC 서명을 사용하고 허용 시간 범위를 초과하거나 nonce가 재사용된 요청은 차단한다.

## 8. 회원 기능

| ID | 기능 | 정의 |
|---|---|---|
| WP-USER-001 | 회원가입 | 이메일, 닉네임, 비밀번호, 국가, 도시, 기본 언어로 WordPress 사용자를 생성한다. |
| WP-USER-002 | 중복 방지 | 이메일과 사용자명 중복을 검사한다. |
| WP-USER-003 | 프로필 조회 | 공개 프로필과 본인 전용 프로필 응답을 구분한다. |
| WP-USER-004 | 프로필 수정 | 닉네임, 국가, 도시, 기본 언어 등 허용 필드만 수정한다. |
| WP-USER-005 | 평점 | 평균 평점과 평가 수를 읽기 전용으로 제공한다. |
| WP-USER-006 | 내 거래글 | 사용자 소유 거래글을 유형별·상태별로 조회한다. |
| WP-USER-007 | 내 약속 | 사용자가 참여한 거래 약속을 역할별·상태별로 조회한다. |
| WP-USER-008 | 탈퇴 | 개인정보 익명화·삭제 정책에 따라 계정을 처리하고 토큰을 폐기한다. 보유 거래글과 진행 중 약속의 처리 규칙을 함께 정의한다. |
| WP-USER-009 | 앱 사용자 연결 | 앱이 저장할 수 있도록 안정적인 WordPress 사용자 ID를 반환한다. |

## 9. 거래글 기능

팔아요와 구해요는 개인이 올리는 거래 제안이라는 점에서 동일하다. 저장소, 목록 쿼리, 상태 규칙, 번역 규칙, 약속 흐름을 공통으로 사용하고 `listing_type`으로만 구분한다.

### 9.1 포스트타입 정의

| 항목 | 값 |
|---|---|
| 포스트타입 | `todaydeal_deal` |
| public | false |
| show_in_rest | false (플러그인 전용 API로만 노출) |
| supports | title, editor, author, custom-fields |
| taxonomy | WooCommerce `product_cat` 공유 등록 |
| 소유자 | `post_author` 및 `_todaydeal_owner_user_id` |
| 메타 접두사 | `_todaydeal_` |

포스트타입은 프런트 단일 페이지나 아카이브를 생성하지 않는다. (구현 시 확장: 관리자 목록 화면에 더해 등록·수정용 메타박스 화면도 제공한다 — 기본정보/위치·약속/미디어/카테고리별 추가 필드/상태 정보. 저장 시에는 REST API(15장)와 동일한 `TD_Listings` 검증 로직을 거치므로 유형별 필드 규칙·상태 전이 규칙이 어긋나지 않는다.)

소유권은 `post_author`와 `_todaydeal_owner_user_id`에 이중 저장하되, 권한 검증은 항상 `_todaydeal_owner_user_id`를 기준으로 한다. 관리자 조작이나 마이그레이션 과정에서 `post_author`가 변경될 수 있기 때문이다.

### 9.2 거래글 유형

| listing_type | 의미 | 작성자 역할 | 상대방 역할 |
|---|---|---|---|
| `sell` | 팔아요 | 판매자 | 구매자 |
| `buy` | 구해요 | 구매자 | 판매자 |

`listing_type`은 등록 시 확정하며 이후 변경할 수 없다. 변경 요청은 `LISTING_TYPE_IMMUTABLE`로 거부한다. 유형이 바뀌면 가격의 의미와 기존 약속 이력의 역할이 모두 뒤집히기 때문이다.

### 9.3 거래글 상태

상태값은 두 유형이 공유하고, 앱에 표시하는 문구만 유형별로 달라진다.

| 상태 | WordPress 표현 | sell 표시 | buy 표시 | 변경 주체 |
|---|---|---|---|---|
| `draft` | draft | 작성중 | 작성중 | 소유자 |
| `open` | publish | 판매중 | 구하는 중 | 소유자 |
| `reserved` | publish + 상태 메타 | 예약중 | 예약중 | 시스템 파생 |
| `completed` | publish + 상태 메타 | 판매완료 | 구매완료 | 소유자 또는 시스템 파생 |
| `hidden` | publish + 상태 메타 | 숨김 | 숨김 | 소유자 |
| `expired` | publish + 상태 메타 | 기간 만료 | 기간 만료 | 시스템 파생 |
| `deleted` | trash | 삭제됨 | 삭제됨 | 소유자 또는 관리자 |

표시 문구는 플러그인이 요청 언어의 라벨로 함께 반환하여 앱이 유형별 분기를 하지 않아도 되게 한다.

`hidden`을 `private`이 아닌 `publish + 상태 메타`로 두는 이유는 목록 쿼리를 `post_status = publish` 하나로 유지하고 노출 여부를 상태 메타 조건으로 통일하기 위해서다.

**파생 상태 규칙**

1. `reserved`는 소유자가 직접 지정할 수 없다. 해당 거래글에 `accepted` 상태의 약속이 존재할 때만 성립한다.
2. `accepted` 약속이 `rejected`, `cancelled`, `expired`로 전이하면 거래글은 `open`으로 자동 복귀한다.
3. 약속이 `completed`로 전이하면 거래글은 `completed`로 전이한다.
4. 소유자가 `completed`를 직접 선택하는 경로도 허용하되, 이 경우 진행 중인 약속을 함께 정리한다.
5. `expires_at` 경과 시 예약 작업이 `expired`로 전이한다. 단 `reserved` 상태의 거래글은 만료시키지 않고 약속 종료 후 재평가한다.
6. 파생 전이는 모두 감사 로그에 `actor = system` 및 원인 약속 ID와 함께 기록한다.

### 9.4 거래글 필드

| 그룹 | 필드 |
|---|---|
| 식별 | listing_id, listing_type, owner_user_id, translation_group_id |
| 기본정보 | title, description, category_ids |
| 가격 | price_min, price_max, currency, price_negotiable |
| 조건 | condition, condition_preference, quantity, item_usage_period, available_languages |
| 위치 | country, city, place_name, latitude, longitude, search_radius_km |
| 거래 | preferred_place, available_time |
| 미디어 | media_ids, 대표 이미지 ID, 이미지 순서 |
| 상태 | status, expires_at, created_at, updated_at |
| 번역 | source_language, language, 번역별 title·description |
| 약속 연계 | active_appointment_id, appointment_count (읽기 전용 파생값) |

가격은 `price_min`과 `price_max` 두 필드로 통일한다. 팔아요는 두 값을 같게 저장하고 응답에서 `price` 단일값을 파생 필드로 함께 제공한다. 구해요는 예산 범위로 사용한다. 두 값 모두 문자열 또는 최소 통화단위 정수로 전달하며 부동소수점을 사용하지 않는다.

내부 메타키는 `_todaydeal_` 접두사를 사용하고 외부 API 응답에서는 의미 있는 필드명으로 변환한다. 앱이 WordPress 메타 배열을 직접 해석하게 하지 않는다.

### 9.5 유형별 필드 규칙

| 필드 | sell | buy |
|---|---|---|
| price_min | 필수 | 선택 |
| price_max | 서버가 price_min과 동일하게 설정 | 선택, price_min 이상 |
| price_negotiable | 선택 | 선택 |
| condition | 선택 (실제 물품 상태) | 사용 안 함 |
| condition_preference | 사용 안 함 | 선택 (희망 상태) |
| item_usage_period | 선택 | 사용 안 함 |
| quantity | 1 고정 | 선택, 기본 1 |
| search_radius_km | 사용 안 함 | 선택 (희망 거래 반경) |
| media_ids | 필수 1장 이상, 최대 5장 | 선택, 최대 3장 |
| expires_at | 선택 | 필수 |
| preferred_place / available_time | 선택 | 선택 |
| location | 필수 | 필수 |

"사용 안 함" 필드가 요청에 포함되면 `INVALID_LISTING_TYPE_FIELD`로 거부한다. 조용히 무시하지 않고 명시적으로 실패시켜 앱의 잘못된 화면 분기를 조기에 드러낸다.

### 9.6 기능 요구사항

| ID | 기능 | 정의 |
|---|---|---|
| WP-LST-001 | 목록 조회 | 공개 가능한 거래글을 유형·언어·국가·카테고리·검색·거리·가격·상태 조건으로 조회한다. |
| WP-LST-002 | 유형 필터 | `type` 파라미터로 sell, buy, all을 선택한다. 미지정 시 기본값은 sell이다. |
| WP-LST-003 | 상세 조회 | 정규화된 상세정보, 유형별 상태 라벨, 작성자 공개정보를 반환한다. |
| WP-LST-004 | 등록 | 인증 사용자 소유로 거래글과 번역, 미디어 연결을 트랜잭션성 있게 생성한다. |
| WP-LST-005 | 유형별 검증 | 9.5 규칙에 따라 필수·선택·금지 필드를 검증한다. |
| WP-LST-006 | 수정 | 요청 사용자와 실제 소유자를 비교하고 허용 필드만 수정한다. `listing_type` 변경은 거부한다. |
| WP-LST-007 | 상태 변경 | 허용된 전이만 수행한다. 파생 상태인 `reserved`, `expired`는 직접 지정 요청을 거부한다. |
| WP-LST-008 | 삭제 | 기본은 휴지통 이동이며 관리자만 영구 삭제할 수 있다. 진행 중인 약속이 있으면 경고 후 함께 취소한다. |
| WP-LST-009 | 내 거래글 | 로그인 사용자의 거래글을 비공개 상태까지 포함하여 조회한다. |
| WP-LST-010 | 거리 조회 | 좌표와 반경이 주어지면 서버에서 거리 필터·정렬을 지원한다. |
| WP-LST-011 | 등록 제한 | 사용자당 동시 `open` 거래글 수와 일일 등록 수를 유형별로 제한한다. |
| WP-LST-012 | 자동 만료 | 예약 작업이 `expires_at` 경과 글을 `expired`로 전이하고 웹훅을 발송한다. |
| WP-LST-013 | 재개시 | 소유자가 `expired` 또는 `completed` 글을 `open`으로 되돌리면 유효기간을 갱신한다. |
| WP-LST-014 | 중복 방지 | 생성 요청의 `Idempotency-Key`를 저장해 동일 요청에 동일 결과를 반환한다. |
| WP-LST-015 | 동시 수정 | 버전 또는 수정일 조건을 확인하여 덮어쓰기 충돌을 방지한다. |
| WP-LST-016 | 다국어 | 번역 그룹과 언어 대체 규칙을 적용한다. |
| WP-LST-017 | 상대 현황 | 소유자에게 해당 거래글의 진행 중 약속 수와 확정 여부를 제공한다. 타 사용자에게는 노출하지 않는다. |
| WP-LST-018 | 참여자 검증 | 앱 서버가 채팅방을 생성할 때 거래글 유효성과 소유자 ID를 검증하는 엔드포인트를 제공한다. |
| WP-LST-019 | 자기 거래 차단 | 소유자 본인은 자신의 거래글에 약속을 생성할 수 없다. |
| WP-LST-020 | 신고·비활성 | 관리자가 개별 글을 비공개 처리할 수 있고 사유를 감사 로그에 남긴다. |

## 10. 카테고리 기능

카테고리는 WooCommerce `product_cat` taxonomy를 `todaydeal_deal`에 공유 등록하여 사용한다. 팔아요와 구해요는 같은 분류 체계를 쓴다.

| ID | 기능 | 정의 |
|---|---|---|
| WP-CAT-001 | 공유 등록 | WooCommerce 초기화 이후 `product_cat`을 `todaydeal_deal`에 등록한다. |
| WP-CAT-002 | 계층 구조 | 부모·자식 구조를 그대로 제공한다. |
| WP-CAT-003 | 다국어 이름 | 언어별 이름, 설명, 슬러그를 플러그인 자체 번역으로 관리한다. |
| WP-CAT-004 | 활성 상태 | 앱에 표시할 카테고리만 반환한다. |
| WP-CAT-005 | 정렬 | 관리자 지정 순서를 지원한다. |
| WP-CAT-006 | 거래글 수 | 카테고리별 공개 거래글 수를 sell·buy로 나누어 선택적으로 반환한다. |
| WP-CAT-007 | 자체 카운트 | 카운트는 플러그인 전용 카운트 테이블에서만 산출한다. WordPress term의 기본 `count` 컬럼은 조회에 사용하지 않는다. |
| WP-CAT-008 | 증분 갱신 | 거래글의 생성·상태 전이·카테고리 변경·삭제 시점에 해당 term의 카운트를 증감한다. |
| WP-CAT-009 | 전체 재계산 | 관리자 도구에서 전체 또는 특정 term의 카운트를 재계산한다. |
| WP-CAT-010 | 어댑터 격리 | taxonomy 접근을 전용 어댑터로 감싸 slug 변경이나 의존 제거에 대비한다. |

### 10.1 자체 카운트 방식

WooCommerce는 `product_cat`의 term count를 `_wc_term_recount`로 재계산하며 `product` 포스트타입만 집계한다. `todaydeal_deal`을 같은 taxonomy에 연결해도 WordPress 기본 `count` 컬럼에는 반영되지 않는다. 따라서 카운트는 플러그인이 직접 관리한다.

**저장 위치**

전용 카운트 테이블을 사용한다. 스키마는 20장을 따른다.

| 컬럼 | 설명 |
|---|---|
| term_id | 카테고리 term ID |
| listing_type | `sell` 또는 `buy` |
| count | 공개 상태 거래글 수 |
| updated_at | 최종 갱신 시각 |

`term_id + listing_type` 조합을 기본 키로 하여 한 카테고리에 두 행이 존재한다.

**집계 대상**

`status`가 `open` 또는 `reserved`인 거래글만 집계한다. `draft`, `hidden`, `completed`, `expired`, `deleted`는 제외한다. 계층 구조에서 상위 카테고리 카운트는 하위 카테고리 합계를 포함한다.

**갱신 시점**

다음 이벤트에서 관련 term의 카운트를 증감한다.

1. 거래글 생성 시 집계 대상 상태이면 증가
2. 상태 전이로 집계 대상에 진입하면 증가, 이탈하면 감소
3. 카테고리 변경 시 이전 term 감소, 새 term 증가
4. 휴지통 이동 또는 삭제 시 감소
5. 상위 카테고리는 해당 term의 조상 경로를 따라 함께 갱신

증분 갱신은 사용자 요청 처리를 지연시키지 않도록 비동기 작업으로 수행한다. 갱신 실패가 거래글 저장을 실패시켜서는 안 된다.

**재계산과 정합성**

- 관리자 도구에서 전체 재계산과 특정 term 재계산을 제공한다.
- 데이터 이관, 대량 상태 변경, 스키마 마이그레이션 이후에는 전체 재계산을 수행한다.
- 예약 작업으로 주기적 재계산을 수행하여 증분 누락을 보정한다. 주기는 관리자 설정으로 조정한다.
- 재계산은 중단 후 재실행해도 동일 결과가 나오도록 멱등하게 구현한다.

**응답 처리**

카테고리 목록 API는 `count_sell`과 `count_buy`를 별도 필드로 반환하며, 카운트 조회는 선택 파라미터로 제어한다. 카운트가 아직 계산되지 않은 term은 0이 아니라 `null`로 반환하여 실제 0건과 구분한다.

**관리자 안내**

WooCommerce 관리자 화면에서는 이 taxonomy가 "상품 카테고리"로 표시되고 그 화면의 카운트는 WooCommerce 상품 기준이다. 관리자에게 해당 분류가 TodayDeal 거래글에도 함께 사용되며 거래글 카운트는 플러그인 화면에서 확인해야 한다는 안내를 표시한다.

### 10.2 카테고리별 추가 입력 필드 (구현 확장 기능)

원 설계에는 없던 기능으로, 카테고리(품목)마다 서로 다른 부가 정보를 요구할 수 있도록 관리자가 필드를 직접 정의하는 기능을 구현 시 추가했다. WooCommerce 자체 상품 속성(product attributes)과는 별개의 TodayDeal 전용 메커니즘이다.

**필드 정의**

관리자는 WooCommerce 카테고리(`product_cat`) 편집 화면의 "TodayDeal 추가 필드" 영역에서 카테고리별로 필드를 추가·삭제한다. 각 필드는 다음을 갖는다.

| 속성 | 설명 |
|---|---|
| key | 영문 키 (예: `wheel_size`) |
| label | 표시명 (예: "휠 사이즈") |
| type | `text`, `number`, `date`, `select`, `checkbox` 중 하나 |
| required | 필수 여부 |
| options | `type=select`일 때의 선택지 목록 |

필드 정의는 해당 카테고리 term의 메타(`_todaydeal_extra_fields`, JSON)로 저장한다. 별도 테이블을 두지 않은 이유는 term meta가 이미 카테고리별 키-값 저장에 충분하기 때문이다.

**값 저장과 검증**

거래글에 카테고리를 여러 개 지정하면, 지정된 모든 카테고리의 필드 정의를 합쳐서(중복 key는 하나로 병합하되 하나라도 필수면 필수로 취급) 검증한다. 필수 필드가 비어 있으면 `VALIDATION_ERROR`(`field: extra_fields.<key>`)로 거부한다. 통과한 값은 거래글의 `_todaydeal_extra_values` 메타(JSON)에 저장되고, 상세 조회 응답에 `extra_fields` 객체로 포함된다.

**API 노출**

`GET /categories` 응답의 각 카테고리 항목에 `extra_fields` 배열(정의만, 값 아님)을 포함해, 앱이나 프런트 폼이 카테고리 선택에 따라 동적으로 입력 필드를 렌더링할 수 있게 한다.

## 11. 다국어 기능

외부 WPML 또는 Polylang 없이도 앱의 핵심 언어를 지원하도록 플러그인 자체 번역 연결을 제공한다.

1. 지원 언어는 `ko`, `en`, `vi`로 시작하며 관리자 설정으로 활성화한다.
2. 각 거래글은 하나의 `translation_group_id`를 갖는다.
3. 언어별 레코드를 만들거나 별도 번역 테이블을 사용할 수 있으나 API 응답 계약은 동일하게 유지한다.
4. 요청 언어 번역이 없으면 원문, 기본 언어 순으로 대체한다.
5. API 응답에는 실제 반환 언어와 대체 여부를 표시한다.
6. 카테고리도 동일한 언어 대체 규칙을 따른다.
7. 상태 라벨과 유형 라벨은 플러그인이 요청 언어로 제공한다.
8. 번역 그룹 테이블은 `entity_type`으로 `listing`과 `category`를 구분한다.

## 12. 미디어 기능

| ID | 기능 | 정의 |
|---|---|---|
| WP-MED-001 | 업로드 | 인증된 사용자만 이미지 업로드가 가능하다. |
| WP-MED-002 | 파일 검사 | 확장자가 아닌 magic bytes 기준으로 JPEG, PNG, WebP만 허용한다. |
| WP-MED-003 | 제한 | 파일당 크기, 사용자별 일일 용량, sell 최대 5장, buy 최대 3장을 제한한다. |
| WP-MED-004 | 재인코딩 | 이미지를 안전한 형식으로 재인코딩하고 EXIF 위치정보를 제거한다. |
| WP-MED-005 | 파생 이미지 | 썸네일과 목록·상세용 크기를 생성한다. |
| WP-MED-006 | 소유권 | 업로드한 사용자 ID와 사용 중인 거래글 ID를 기록한다. |
| WP-MED-007 | 임시 미디어 | 거래글에 연결되지 않은 업로드는 임시 상태로 저장 후 일정 기간 뒤 정리한다. |
| WP-MED-008 | 삭제 보호 | 다른 거래글에서 사용 중인 미디어는 임의 삭제하지 않는다. |
| WP-MED-009 | 응답 | media_id, URL, width, height, mime, bytes를 반환한다. |

SVG, 실행 파일, 스크립트 포함 파일은 허용하지 않는다. 원본 파일명은 저장 경로로 직접 사용하지 않는다.

## 13. 거래 약속 기능

두 유형 모두 거래 약속의 대상이다. 하나의 거래글에 여러 상대가 동시에 접근하는 것이 정상 상황이므로, 병존 가능한 협상과 단 하나의 확정을 구분하는 것이 이 모듈의 핵심 규칙이다.

### 13.1 참여자 모델

약속은 판매자·구매자가 아니라 **거래글 소유자(owner)와 상대방(counterpart)** 으로 저장한다. 실제 역할은 거래글의 `listing_type`에서 파생한다.

| listing_type | owner_user_id | counterpart_user_id |
|---|---|---|
| `sell` | 판매자 | 구매자 |
| `buy` | 구매자 | 판매자 |

API 응답에는 `seller_id`와 `buyer_id`를 파생 필드로 함께 제공하여 앱이 유형별 분기를 하지 않아도 되게 한다. 저장의 진실은 owner/counterpart이며 파생 필드는 읽기 전용이다.

약속 제안은 counterpart가 시작하고 수락·거절 권한은 owner가 갖는다. 변경 제안은 양쪽 모두 가능하며 변경 제안 시 상태는 `proposed`로 되돌아간다.

**약속 장소 필드 (구현 확장)**

`meet_place`(장소명, 문자열) 하나만 정의했던 원 설계에 상세주소·좌표를 추가로 둔다. 둘 다 선택 필드이며, 거래글 자체의 `location`(9.4)과는 별개로 "약속을 어디서 만날지"를 나타낸다.

| 필드 | 설명 |
|---|---|
| meet_place | 장소명 (예: "Chợ Bến Thành") |
| meet_address | 상세 주소 (선택) |
| meet_latitude / meet_longitude | 좌표 (선택). 거래글 좌표와 동일하게 부호 포함 소수점 6자리 고정 문자열로 저장한다 |

변경 제안(`propose_change`)으로 세 필드 모두 함께 갱신할 수 있으며, 이때도 상태는 `proposed`로 되돌아간다.

### 13.2 동시성 원칙

1. 하나의 거래글에 서로 다른 상대의 `proposed` 약속은 여러 건 병존할 수 있다.
2. 하나의 거래글에 `accepted` 약속은 최대 1건만 존재할 수 있다.
3. 거래글의 `reserved` 상태는 2번 조건의 파생 결과이며 독립적으로 저장되는 값이 아니다.
4. 확정과 취소는 거래글 행 잠금 아래에서 처리하여 동시 수락으로 인한 중복 확정을 방지한다.

### 13.3 기능 요구사항

| ID | 기능 | 정의 |
|---|---|---|
| WP-APPT-001 | 약속 생성 | 거래글, 소유자, 상대방, 날짜, 시간, 장소를 기록한다. 장소는 `meet_place`(장소명), `meet_address`(상세주소, 선택), `meet_latitude`/`meet_longitude`(좌표, 선택)로 구성된다. |
| WP-APPT-002 | 참여자 검증 | 상대방이 해당 거래글·채팅의 유효한 참여자인지 확인한다. |
| WP-APPT-003 | 자기 거래 차단 | owner와 counterpart가 동일하면 `APPOINTMENT_SELF_NOT_ALLOWED`를 반환한다. |
| WP-APPT-004 | 역할 파생 | `listing_type`으로 판매자·구매자를 결정하고 응답에 파생 필드로 제공한다. |
| WP-APPT-005 | 상태 전이 | proposed, accepted, rejected, cancelled, completed, expired 상태를 관리한다. |
| WP-APPT-006 | 대상 상태 | `open` 또는 `reserved` 상태의 거래글에만 약속을 생성할 수 있다. |
| WP-APPT-007 | 중복 방지 | 멱등성 키로 중복 약속 생성을 차단한다. |
| WP-APPT-008 | 동일 조합 제한 | 동일 거래글·상대방 조합에 진행 중(proposed 또는 accepted) 약속은 1건만 허용한다. |
| WP-APPT-009 | 단일 확정 | 거래글당 `accepted` 약속은 1건만 허용하며 데이터베이스 제약과 행 잠금으로 강제한다. 이미 확정 건이 있으면 `APPOINTMENT_ALREADY_ACCEPTED`를 반환한다. |
| WP-APPT-010 | 거래글 예약 | 약속 수락 시 거래글을 `reserved`로 전이시킨다. |
| WP-APPT-011 | 상태 복귀 | 확정 약속이 rejected, cancelled, expired로 전이하면 거래글을 `open`으로 자동 복귀시킨다. |
| WP-APPT-012 | 거래 완료 | 약속이 `completed`로 전이하면 거래글을 `completed`로 전이시키고 동일 거래글의 나머지 진행 중 약속을 사유와 함께 자동 취소한다. |
| WP-APPT-013 | 완료 확인 | 완료 처리는 양측 확인 또는 소유자 확인 중 하나를 관리자 설정으로 선택한다. |
| WP-APPT-014 | 자동 만료 | 약속 시각 경과 후 일정 시간 내에 완료 처리되지 않은 약속을 `expired`로 전이시킨다. |
| WP-APPT-015 | 감사 기록 | 상태 변경자, 이전 상태, 변경 시각, 자동 전이 여부, 사유를 기록한다. |
| WP-APPT-016 | 다중 통보 | 상태 변경 시 해당 약속의 참여자뿐 아니라 자동 취소된 다른 약속의 참여자에게도 웹훅을 발송한다. 앱 서버가 각 채팅방에 시스템 메시지를 표시할 수 있어야 한다. |
| WP-APPT-017 | 소유자 조회 | 소유자가 자신의 거래글에 걸린 약속 목록을 상태별로 조회할 수 있다. |
| WP-APPT-018 | 매니저 연계 | 채팅방에 활성 매니저가 있으면 약속에 매니저 ID와 참석 예정 여부를 기록한다. |
| WP-APPT-019 | 완료 선행 조건 | 체크리스트 제출을 완료 선행 조건으로 설정한 경우, 미제출 상태의 완료 요청을 `CHECKLIST_REQUIRED`로 거부한다. |
| WP-APPT-020 | 웹훅 | 약속 변경을 앱 서버에 통보한다. |

## 14. 매니저 중재 기능

todaydeal.app은 당사자 간 거래에 제3자인 매니저가 개입할 수 있다. 매니저는 약속 장소를 사전 확인하고, 거래 현장에서 상품을 함께 점검하며, 그 결과를 체크리스트로 남긴다. 이 기능은 당근마켓형 순수 P2P 거래와 todaydeal.app을 구분하는 핵심 요소다.

### 14.1 기본 원칙

1. 매니저 개입은 **선택 기능**이다. 매니저 없이도 모든 거래 흐름이 완결되어야 한다.
2. 매니저는 **채팅방 초대**로만 개입한다. 시스템이 임의로 배정하지 않는다.
3. 매니저는 **거래 당사자가 아니다.** 거래글 소유권, 약속 확정, 거래 완료 결정 권한을 갖지 않는다.
4. 매니저의 결과물은 **판정이 아니라 기록과 권고**다. 상태를 강제로 바꾸는 권한은 운영 관리자에게만 있다.
5. 매니저는 **초대된 채팅방과 그에 연결된 약속에만** 접근한다. 다른 거래글, 다른 채팅방, 다른 사용자 정보에 접근할 수 없다.
6. 매니저의 모든 열람과 기록 행위는 감사 로그에 남긴다.

### 14.2 매니저 자격과 프로필

관리자가 특정 사용자에게 `todaydeal_act_as_manager` capability를 부여하여 매니저로 지정한다. 매니저는 별도 회원 유형이 아니라 권한이 추가된 일반 회원이며, 본인이 당사자인 거래에서는 매니저로 활동할 수 없다.

| 필드 | 설명 |
|---|---|
| manager_user_id | WordPress 사용자 ID |
| active_country / active_cities | 활동 지역 |
| active_categories | 활동 카테고리 (미지정 시 전체) |
| languages | 대응 가능 언어 |
| availability | 활동 가능 요일·시간대 |
| status | `active`, `paused`, `suspended` |
| rating / review_count | 당사자 평가 결과 (읽기 전용) |
| assigned_count | 진행 중 배정 건수 |

| ID | 기능 | 정의 |
|---|---|---|
| WP-MGR-001 | 매니저 지정 | 관리자가 사용자에게 매니저 권한을 부여하고 프로필을 등록한다. |
| WP-MGR-002 | 후보 조회 | 거래글의 국가·좌표·카테고리·언어를 기준으로 매니저 후보 목록을 반환한다. |
| WP-MGR-003 | 가용성 반영 | `paused`, `suspended` 또는 동시 배정 한도를 초과한 매니저는 후보에서 제외한다. |
| WP-MGR-004 | 이해충돌 배제 | 해당 거래글의 소유자 또는 상대방인 사용자는 후보에서 제외한다. |
| WP-MGR-005 | 프로필 공개 | 당사자에게는 닉네임, 활동 지역, 언어, 평점만 공개한다. |

### 14.3 초대와 참여

| ID | 기능 | 정의 |
|---|---|---|
| WP-MGR-010 | 초대 | 채팅 참여자(소유자 또는 상대방)가 매니저를 초대한다. |
| WP-MGR-011 | 상대 동의 | 초대 성립에 상대 당사자의 동의를 요구한다. 동의 필수 여부는 관리자 설정으로 조정한다. |
| WP-MGR-012 | 수락·거절 | 매니저가 초대를 수락하거나 거절한다. 미응답 초대는 설정된 시간 후 만료된다. |
| WP-MGR-013 | 단일 매니저 | 하나의 채팅방에 활성 매니저는 1명만 존재한다. |
| WP-MGR-014 | 열람 범위 | 매니저는 수락 시점 이후의 메시지만 열람한다. 이전 대화는 양측 당사자가 모두 동의한 경우에만 공개한다. |
| WP-MGR-015 | 해제 | 양측 당사자 동의, 매니저 본인의 사퇴, 관리자의 조치로 해제한다. 한쪽 당사자 단독 해제는 허용하지 않는다. |
| WP-MGR-016 | 교체 | 해제 후 다른 매니저를 다시 초대할 수 있다. 이전 참여 이력과 체크리스트는 보존한다. |
| WP-MGR-017 | 자동 종료 | 거래글이 완료·삭제되거나 약속이 종료되면 매니저 접근 권한을 자동 종료한다. 기록 열람은 유지한다. |
| WP-MGR-018 | 참여 이력 | 초대·수락·거절·해제·사퇴 시각과 행위자를 기록한다. |

초대 상태는 `invited`, `accepted`, `declined`, `expired`, `released`로 관리한다.

### 14.4 약속 장소 확인

매니저는 거래 약속이 잡히면 장소를 사전 검토한다.

| ID | 기능 | 정의 |
|---|---|---|
| WP-MGR-020 | 장소 검토 | 매니저가 제안된 약속 장소를 검토하고 결과를 기록한다. |
| WP-MGR-021 | 확인 결과 | `confirmed`, `hold`, `rejected` 중 하나와 사유를 기록한다. |
| WP-MGR-022 | 대체 제안 | 매니저가 대체 장소를 제안할 수 있다. 채택 여부는 당사자가 결정하며 매니저가 변경할 수 없다. |
| WP-MGR-023 | 권장 장소 | 관리자가 등록한 권장 거래 장소 목록에서 선택할 수 있다. |
| WP-MGR-024 | 변경 감지 | 약속 장소가 변경되면 확인 결과를 초기화하고 재확인을 요청한다. |
| WP-MGR-025 | 임박 경고 | 매니저가 참여 중인데 장소가 미확인 상태로 약속 시각이 임박하면 양측과 매니저에게 알림을 보낸다. |
| WP-MGR-026 | 참석 여부 | 매니저의 현장 참석 예정 여부를 약속에 기록한다. |

권장 거래 장소는 관리자가 국가·도시별로 등록하며 이름, 좌표, 운영 시간, 안내 문구를 갖는다.

### 14.5 현장 점검 체크리스트

매니저는 거래 현장에서 제3자 관점으로 상품을 함께 점검하고 체크리스트를 작성한다.

**템플릿**

| ID | 기능 | 정의 |
|---|---|---|
| WP-MGR-030 | 템플릿 정의 | 관리자가 카테고리별 체크리스트 템플릿을 정의한다. |
| WP-MGR-031 | 항목 구성 | 항목은 제목, 설명, 필수 여부, 입력 유형(선택/텍스트/사진)을 갖는다. |
| WP-MGR-032 | 다국어 | 템플릿과 항목은 지원 언어별 표시명을 갖는다. |
| WP-MGR-033 | 버전 고정 | 체크리스트 생성 시 템플릿 버전을 스냅샷으로 복사한다. 이후 템플릿이 바뀌어도 기존 기록은 변하지 않는다. |
| WP-MGR-034 | 기본 템플릿 | 카테고리에 지정된 템플릿이 없으면 공통 기본 템플릿을 사용한다. |

**작성과 제출**

| ID | 기능 | 정의 |
|---|---|---|
| WP-MGR-040 | 생성 | 약속당 체크리스트는 1건이며 참여 중인 매니저만 생성한다. |
| WP-MGR-041 | 작성 권한 | 매니저만 작성·수정한다. 당사자는 열람만 가능하다. |
| WP-MGR-042 | 항목 결과 | 항목별로 `pass`, `fail`, `na`와 메모를 기록한다. |
| WP-MGR-043 | 사진 첨부 | 항목별로 점검 사진을 첨부한다. 미디어 규칙은 13장을 따른다. |
| WP-MGR-044 | 필수 검증 | 필수 항목이 비어 있으면 제출을 거부한다. |
| WP-MGR-045 | 제출 잠금 | 제출 후에는 수정할 수 없다. 정정이 필요하면 사유와 함께 정정 이력을 추가한다. |
| WP-MGR-046 | 당사자 확인 | 양측 당사자가 내용을 확인했음을 각각 기록한다. |
| WP-MGR-047 | 이의 제기 | 당사자가 체크리스트 내용에 이의를 제기하면 분쟁으로 전환한다. |
| WP-MGR-048 | 완료 선행 조건 | 매니저가 참여한 약속에서 체크리스트 제출을 거래 완료의 선행 조건으로 요구할지 관리자 설정으로 결정한다. |
| WP-MGR-049 | 보존 | 체크리스트는 약속에 종속되며 거래 종료 후에도 보존한다. 당사자와 매니저는 이후에도 열람할 수 있다. |
| WP-MGR-050 | 오프라인 대비 | 현장 통신 불량에 대비해 임시 저장을 지원하고 제출 시 서버에서 최종 검증한다. |

체크리스트 상태는 `draft`, `submitted`, `acknowledged`, `disputed`로 관리한다.

### 14.6 중재

| ID | 기능 | 정의 |
|---|---|---|
| WP-MGR-060 | 분쟁 개시 | 당사자 또는 매니저가 분쟁을 개시하고 사유를 기록한다. |
| WP-MGR-061 | 소견 기록 | 매니저가 소견과 근거(체크리스트 항목, 사진)를 기록한다. |
| WP-MGR-062 | 권고 | 매니저는 결정이 아닌 권고를 남긴다. 거래 상태를 직접 바꾸지 않는다. |
| WP-MGR-063 | 약속 조정 | 매니저는 일정·장소 변경을 제안할 수 있으나 확정은 거래글 소유자가 한다. |
| WP-MGR-064 | 에스컬레이션 | 설정된 기간 내 해결되지 않으면 운영 관리자에게 이관한다. |
| WP-MGR-065 | 관리자 조치 | 약속 강제 취소, 거래글 비공개, 계정 제재는 운영 관리자만 수행한다. |
| WP-MGR-066 | 종결 | 분쟁을 `resolved` 또는 `closed`로 종결하고 처리 결과를 기록한다. |
| WP-MGR-067 | 매니저 평가 | 거래 종료 후 당사자가 매니저를 평가한다. 평점은 매니저 프로필에 반영한다. |
| WP-MGR-068 | 이해충돌 신고 | 당사자가 매니저의 편향을 신고할 수 있고 관리자가 검토한다. |

분쟁 상태는 `open`, `escalated`, `resolved`, `closed`로 관리한다.

### 14.7 권한 요약

| 행위 | 거래글 소유자 | 상대방 | 매니저 | 운영 관리자 |
|---|---|---|---|---|
| 거래글 수정·상태 변경 | O | X | X | O |
| 약속 제안 | X | O | 제안만 | O |
| 약속 수락·거절 | O | X | X | O |
| 약속 장소 확인 기록 | X | X | O | O |
| 대체 장소 제안 | O | O | O | O |
| 체크리스트 작성·제출 | X | X | O | X |
| 체크리스트 열람 | O | O | O | O |
| 체크리스트 확인 기록 | O | O | X | X |
| 분쟁 개시 | O | O | O | O |
| 약속 강제 취소 | X | X | X | O |
| 매니저 해제 | 양측 동의 | 양측 동의 | 사퇴 가능 | O |

## 15. REST API 정의

### 15.1 공통 규칙

- 기본 경로: `/wp-json/todaydeal/v1`
- 요청·응답 형식: UTF-8 JSON
- 날짜: ISO 8601 UTC
- 가격: 문자열 또는 최소 통화단위 정수로 전달하며 부동소수점 사용 금지
- 목록: `items`, `next_cursor`, `has_more` 구조 사용
- 변경 요청: `Idempotency-Key` 지원
- 모든 응답: `request_id`, `api_version` 포함
- 인증 사용자 ID는 요청 본문이 아닌 검증된 토큰에서 결정

### 15.2 인증·회원 API

| Method | 경로 | 설명 | 권한 |
|---|---|---|---|
| POST | `/auth/register` | 회원가입 | 공개 |
| POST | `/auth/login` | 로그인 | 공개 |
| POST | `/auth/refresh` | 토큰 갱신 | 갱신 토큰 |
| POST | `/auth/logout` | 현재 토큰 폐기 | 회원 |
| POST | `/auth/logout-all` | 전체 토큰 폐기 | 회원 |
| GET | `/users/me` | 내 프로필 | 회원 |
| PATCH | `/users/me` | 내 프로필 수정 | 회원 |
| DELETE | `/users/me` | 탈퇴 요청 | 회원 |
| GET | `/users/:id/public` | 상대방 공개정보 | 공개 |
| GET | `/users/me/listings` | 내 거래글 | 회원 |
| GET | `/users/me/appointments` | 내 약속 | 회원 |

### 15.3 거래글 API

| Method | 경로 | 설명 | 권한 |
|---|---|---|---|
| GET | `/listings` | 거래글 목록·검색·필터 | 공개 |
| GET | `/listings/:id` | 거래글 상세 | 공개 |
| POST | `/listings` | 거래글 등록 | 회원 |
| PUT | `/listings/:id` | 전체 수정 | 소유자 |
| PATCH | `/listings/:id` | 일부 수정 | 소유자 |
| PATCH | `/listings/:id/status` | 상태 변경 | 소유자 |
| DELETE | `/listings/:id` | 휴지통 이동 | 소유자 |
| GET | `/listings/:id/appointments` | 해당 거래글의 약속 목록 | 소유자 |

`GET /listings`는 다음 파라미터를 지원한다.

- `type` : `sell`, `buy`, `all` (기본값 `sell`)
- `lang`
- `country`
- `category`
- `search`
- `lat`, `lng`, `radius_km`
- `price_min`, `price_max`
- `status`
- `sort` : `recent`, `distance`, `price_asc`, `price_desc`
- `cursor`, `limit`

가격 필터와 가격 정렬은 sell은 `price_min`, buy는 `price_max`를 기준으로 적용한다. `type=all`인 경우 기준이 혼합되므로 응답 meta에 적용 기준을 명시한다.

### 15.4 카테고리·미디어·약속 API

| Method | 경로 | 설명 | 권한 |
|---|---|---|---|
| GET | `/categories` | 카테고리 목록 | 공개 |
| POST | `/media` | 이미지 업로드 | 회원 |
| DELETE | `/media/:id` | 미사용 본인 이미지 삭제 | 소유자 |
| POST | `/appointments` | 거래 약속 생성 | 참여자 |
| GET | `/appointments/:id` | 거래 약속 조회 | 참여자 |
| PATCH | `/appointments/:id` | 약속 응답·상태 변경 | 참여자 |
| GET | `/health` | 플러그인 상태 | 앱 서버 또는 관리자 |

### 15.5 매니저·체크리스트·분쟁 API

| Method | 경로 | 설명 | 권한 |
|---|---|---|---|
| GET | `/managers` | 거래글 조건에 맞는 매니저 후보 목록 | 참여자 |
| GET | `/managers/:id` | 매니저 공개 프로필 | 참여자 |
| GET | `/managers/me/assignments` | 내가 참여 중인 배정 목록 | 매니저 |
| POST | `/manager-invitations` | 매니저 초대 | 참여자 |
| PATCH | `/manager-invitations/:id` | 초대 동의·수락·거절 | 상대 당사자 또는 매니저 |
| DELETE | `/manager-invitations/:id` | 매니저 해제 또는 사퇴 | 양측 동의, 매니저, 관리자 |
| POST | `/appointments/:id/place-review` | 약속 장소 확인 결과 등록 | 매니저 |
| GET | `/recommended-places` | 권장 거래 장소 목록 | 공개 |
| GET | `/checklist-templates` | 카테고리별 체크리스트 템플릿 | 매니저 |
| POST | `/appointments/:id/checklist` | 체크리스트 생성 | 매니저 |
| PATCH | `/checklists/:id` | 항목 작성·임시 저장 | 매니저 |
| POST | `/checklists/:id/submit` | 체크리스트 제출 | 매니저 |
| POST | `/checklists/:id/acknowledge` | 당사자 확인 기록 | 참여자 |
| GET | `/checklists/:id` | 체크리스트 조회 | 참여자, 매니저 |
| POST | `/disputes` | 분쟁 개시 | 참여자, 매니저 |
| PATCH | `/disputes/:id` | 소견·권고·상태 변경 | 매니저, 관리자 |

채팅방은 앱 서버가 소유하므로 플러그인은 채팅방 ID를 알지 못한다. 초대 생성 요청은 `listing_id`와 상대 당사자 ID 조합으로 대상을 특정하고, 앱 서버가 자신의 채팅방과 연결한다.

## 16. 요청 예시

### 16.1 팔아요 등록

```json
{
  "listing_type": "sell",
  "source_language": "vi",
  "translations": {
    "vi": {
      "title": "Xe đạp cũ còn tốt",
      "description": "Đã dùng 1 năm, phanh và lốp còn tốt."
    },
    "en": {
      "title": "Used bicycle in good condition",
      "description": "One year of use, brakes and tires in good shape."
    }
  },
  "price_min": "1800000",
  "currency": "VND",
  "price_negotiable": true,
  "condition": "good",
  "item_usage_period": "1년",
  "category_ids": [12],
  "media_ids": [101, 102],
  "location": {
    "country": "VN",
    "city": "Ho Chi Minh City",
    "place_name": "Quận 1",
    "latitude": 10.7769,
    "longitude": 106.7009
  },
  "preferred_place": "Chợ Bến Thành",
  "available_time": "평일 저녁",
  "available_languages": ["vi", "en"]
}
```

`price_max`는 서버가 `price_min`과 동일하게 설정한다.

### 16.2 구해요 등록

```json
{
  "listing_type": "buy",
  "source_language": "vi",
  "translations": {
    "vi": {
      "title": "Cần mua xe đạp cũ",
      "description": "Tìm xe đạp còn tốt, khu vực Quận 1."
    },
    "en": {
      "title": "Looking for a used bicycle",
      "description": "Good condition preferred, District 1 area."
    }
  },
  "price_min": "1000000",
  "price_max": "2500000",
  "currency": "VND",
  "condition_preference": "good",
  "quantity": 1,
  "category_ids": [12],
  "location": {
    "country": "VN",
    "city": "Ho Chi Minh City",
    "place_name": "Quận 1",
    "latitude": 10.7769,
    "longitude": 106.7009,
    "search_radius_km": 10
  },
  "available_languages": ["vi", "en"],
  "expires_at": "2026-10-31T23:59:59Z"
}
```

### 16.3 약속 응답 예시

```json
{
  "data": {
    "appointment_id": 5012,
    "listing_id": 3301,
    "listing_type": "buy",
    "owner_user_id": 88,
    "counterpart_user_id": 214,
    "seller_id": 214,
    "buyer_id": 88,
    "status": "accepted",
    "meet_at": "2026-09-20T10:00:00Z",
    "meet_place": "Chợ Bến Thành",
    "meet_address": "Lê Lợi, Bến Thành, Quận 1",
    "meet_latitude": 10.7724,
    "meet_longitude": 106.6980
  },
  "meta": {
    "api_version": "1",
    "request_id": "요청 추적 ID"
  }
}
```

`seller_id`와 `buyer_id`는 `listing_type`에서 파생된 읽기 전용 필드다.

## 17. 응답 및 오류 규격

### 성공

```json
{
  "data": {},
  "meta": {
    "api_version": "1",
    "request_id": "요청 추적 ID"
  }
}
```

### 실패

```json
{
  "error": {
    "code": "FORBIDDEN_LISTING_OWNER",
    "message": "이 거래글을 수정할 권한이 없습니다.",
    "details": {},
    "request_id": "요청 추적 ID"
  }
}
```

| 오류 코드 | HTTP | 의미 |
|---|---:|---|
| VALIDATION_ERROR | 400 | 필드 검증 실패 |
| INVALID_LISTING_TYPE_FIELD | 400 | 해당 유형에서 사용할 수 없는 필드 포함 |
| AUTHENTICATION_REQUIRED | 401 | 인증 필요 |
| INVALID_CREDENTIALS | 401 | 로그인 정보 오류 |
| TOKEN_EXPIRED | 401 | 토큰 만료 |
| FORBIDDEN_LISTING_OWNER | 403 | 거래글 소유권 없음 |
| APPOINTMENT_SELF_NOT_ALLOWED | 403 | 본인 거래글에 약속 생성 불가 |
| LISTING_NOT_FOUND | 404 | 거래글 없음 |
| MEDIA_NOT_FOUND | 404 | 미디어 없음 |
| APPOINTMENT_NOT_FOUND | 404 | 약속 없음 |
| DUPLICATE_REQUEST | 409 | 중복 또는 멱등성 충돌 |
| VERSION_CONFLICT | 409 | 동시 수정 충돌 |
| LISTING_TYPE_IMMUTABLE | 409 | 거래글 유형 변경 불가 |
| INVALID_STATUS_TRANSITION | 409 | 허용되지 않은 상태 전이 또는 파생 상태 직접 지정 |
| APPOINTMENT_ALREADY_ACCEPTED | 409 | 해당 거래글에 이미 확정된 약속이 존재 |
| APPOINTMENT_DUPLICATE_PARTY | 409 | 동일 거래글·상대방의 진행 중 약속이 이미 존재 |
| LISTING_LIMIT_EXCEEDED | 409 | 사용자별 거래글 등록 한도 초과 |
| MANAGER_NOT_ELIGIBLE | 403 | 매니저 자격 없음 또는 이해충돌 |
| MANAGER_NOT_ASSIGNED | 403 | 해당 거래에 참여 중인 매니저가 아님 |
| MANAGER_ALREADY_ASSIGNED | 409 | 이미 활성 매니저가 존재 |
| MANAGER_CONSENT_REQUIRED | 409 | 상대 당사자의 동의 필요 |
| INVITATION_EXPIRED | 409 | 초대 유효기간 경과 |
| CHECKLIST_ALREADY_SUBMITTED | 409 | 제출된 체크리스트는 수정 불가 |
| CHECKLIST_REQUIRED_ITEM_MISSING | 400 | 필수 점검 항목 누락 |
| CHECKLIST_REQUIRED | 409 | 체크리스트 미제출 상태로 거래 완료 불가 |
| DISPUTE_ALREADY_OPEN | 409 | 해당 약속에 진행 중인 분쟁 존재 |
| RATE_LIMITED | 429 | 요청 제한 초과 |
| INTERNAL_ERROR | 500 | 내부 오류 |
| TAXONOMY_UNAVAILABLE | 503 | WooCommerce 비활성으로 카테고리 사용 불가 |

PHP 오류 메시지, 데이터베이스 오류, 파일 경로, 스택 정보는 API 응답에 포함하지 않는다.

## 18. 웹훅 기능

### 18.1 이벤트

- `user.updated`
- `user.deleted`
- `listing.created`
- `listing.updated`
- `listing.status_changed`
- `listing.expired`
- `listing.deleted`
- `media.deleted`
- `appointment.created`
- `appointment.status_changed`
- `appointment.auto_cancelled`
- `manager.invited`
- `manager.accepted`
- `manager.declined`
- `manager.released`
- `appointment.place_reviewed`
- `checklist.submitted`
- `checklist.acknowledged`
- `dispute.opened`
- `dispute.escalated`
- `dispute.resolved`

거래글 이벤트 페이로드에는 `listing_type`을 항상 포함하여 앱 서버가 유형별 처리를 분기할 수 있게 한다.

### 18.2 전달 규칙

1. 이벤트 ID, 이벤트 종류, 발생시각, 데이터 버전, 대상 ID를 포함한다.
2. `listing.status_changed`와 `appointment.status_changed`에는 전이 원인(사용자 조작인지 파생 전이인지)과 관련 약속 ID를 포함한다.
3. 매니저·체크리스트 이벤트에는 대상 거래글 ID, 약속 ID, 매니저 ID를 포함하되 체크리스트 항목 내용은 포함하지 않는다. 앱 서버는 필요 시 API로 조회한다.
3. 본문과 타임스탬프를 HMAC으로 서명한다.
4. 앱 서버는 이벤트 ID로 중복 수신을 제거한다.
5. 2xx가 아니면 지수형 간격으로 제한 횟수만큼 재시도한다.
6. 반복 실패 이벤트는 실패 대기열에 보관하고 관리자가 재전송할 수 있게 한다.
7. 웹훅 비밀키는 관리자 화면에 평문으로 재표시하지 않는다.

## 19. 관리자 화면

| 메뉴 | 기능 |
|---|---|
| 대시보드 | 플러그인 버전, API 상태, WooCommerce·taxonomy 상태, 최근 오류 |
| 일반 설정 | 지원 국가·언어, 기본 언어, 유형별 등록 한도, 기본 유효기간, 이미지 제한 |
| 인증 설정 | 토큰 만료시간, 서버 인증 설정, 키 교체 |
| 웹훅 | 대상 URL, 활성 이벤트, 최근 전송, 실패 재처리 |
| 거래글 | 유형·상태별 목록, 번역·위치 메타 점검, 비공개 처리, 고아 데이터 정리. **(구현) 등록·수정 메타박스 화면도 제공하며 저장 시 REST API와 동일한 검증을 거친다.** |
| 거래 약속 | 거래글별 약속 현황, 확정 중복 점검, 강제 취소 |
| 정합성 점검 | 파생 상태 불일치 검사 및 일괄 정정 |
| 매니저 | 매니저 지정·해제, 프로필, 활동 지역·카테고리, 상태, 동시 배정 한도 |
| 매니저 활동 | 배정 현황, 응답 시간, 체크리스트 제출률, 당사자 평가, 이해충돌 신고 |
| 체크리스트 템플릿 | 카테고리별 템플릿과 항목 편집, 다국어 표시명, 버전 관리 |
| 권장 거래 장소 | 국가·도시별 장소 등록, 좌표, 운영 시간, 안내 문구 |
| 분쟁 | 진행 중·에스컬레이션 분쟁 목록, 처리, 강제 조치, 종결 |
| 카테고리 | 다국어 표시명, 정렬, 거래글 카운트 재계산. **(구현) 카테고리별 추가 입력 필드(키/표시명/유형/필수여부) 관리 — 10.2절 참고** |
| 미디어 | 임시·고아 미디어 조회 및 안전한 정리 |
| 로그 | 요청 ID, 이벤트, 결과, 지연시간 중심의 감사 로그 |
| 도구 | 연결 테스트, 캐시 비우기, 데이터 마이그레이션, 진단정보 내보내기 |

관리자 화면은 `manage_options` 및 전용 capability를 확인하고 모든 변경 폼에 nonce를 적용한다.

정합성 점검은 `reserved` 거래글 중 확정 약속이 없는 건, 확정 약속이 있으나 `reserved`가 아닌 거래글, 유형과 어긋나는 필드를 가진 거래글을 검출한다.

## 20. 데이터 저장

거래글은 `todaydeal_deal` 포스트타입과 `_todaydeal_` 메타로 저장한다. 관계·이력·재시도가 중요한 데이터는 전용 테이블을 사용한다.

| 테이블 용도 | 주요 필드 |
|---|---|
| 번역 그룹 | entity_type(listing/category), entity_id, language, group_id |
| 토큰 세션 | user_id, token_hash, expires_at, revoked_at, device |
| 멱등성 | key_hash, user_id, operation, response_ref, expires_at |
| 거래 약속 | listing_id, listing_type, owner_user_id, counterpart_user_id, meet_at, meet_place, meet_address, meet_latitude, meet_longitude, status, accepted_listing_id, owner_completed_at, counterpart_completed_at |
| 약속 이력 | appointment_id, actor_id, from_status, to_status, is_system, reason, created_at |
| 카테고리 카운트 | term_id, listing_type, count, updated_at |
| 웹훅 대기열 | event_id, event_type, payload, attempts, next_attempt_at, status |
| 매니저 프로필 | manager_user_id, active_country, active_cities, active_categories, languages, availability, status, assigned_limit |
| 매니저 배정 | listing_id, appointment_id, manager_user_id, invited_by, status, invited_at, accepted_at, released_at, released_by |
| 장소 확인 | appointment_id, manager_user_id, result, reason, suggested_place, reviewed_at |
| 권장 장소 | country, city, name, latitude, longitude, hours, notice, active |
| 체크리스트 | appointment_id, manager_user_id, template_snapshot, status, submitted_at |
| 체크리스트 항목 | checklist_id, item_key, result, memo, media_ids, sort_order |
| 체크리스트 이력 | checklist_id, actor_id, action, reason, created_at |
| 분쟁 | appointment_id, opened_by, status, reason, recommendation, escalated_at, resolved_at, resolution |
| 매니저 평가 | appointment_id, rater_user_id, manager_user_id, score, comment |
| 감사 로그 | actor_id, action, object_type, object_id, request_id, result |

**단일 확정 제약 구현**

거래 약속 테이블에 `accepted_listing_id` 컬럼을 둔다. `status`가 `accepted`일 때만 `listing_id` 값을 갖고 그 외 상태에서는 NULL이며, 이 컬럼에 UNIQUE 인덱스를 건다. MySQL은 부분 유니크 인덱스를 지원하지 않으므로 생성 컬럼 또는 애플리케이션 레벨 동기화로 값을 유지한다. 수락 처리는 거래글 행을 잠근 상태에서 수행한다. (구현: 생성 컬럼 대신 **애플리케이션 레벨 동기화**를 선택했다 — dbDelta/여러 DB 백엔드 호환성을 위해 상태 전이 시 애플리케이션 코드가 이 컬럼을 직접 NULL/listing_id로 갱신하고, 트랜잭션 내 재확인 후 UNIQUE 인덱스 충돌을 2차 방어선으로 사용한다.)

약속 테이블의 `listing_type`은 조회 성능용 비정규화 사본이며 진실은 거래글 쪽이다.

**매니저 배정 제약**

하나의 거래글·상대방 조합에 `accepted` 상태의 매니저 배정은 1건만 허용한다. 약속 확정과 동일하게 생성 컬럼 + UNIQUE 인덱스로 강제한다. 체크리스트는 약속당 1건이므로 `appointment_id`에 UNIQUE 인덱스를 건다.

**템플릿 스냅샷**

체크리스트 생성 시 템플릿 정의를 JSON으로 복사해 저장한다. 템플릿이 이후 변경되거나 삭제되어도 이미 작성된 점검 기록의 항목 구성과 표시명은 그대로 유지되어야 한다.

### 20.1 거래글 검색 메타 저장 방식

`latitude`, `longitude`, `status`, `listing_type`, `country`, `expires_at`은 **postmeta에 저장한다.** 별도 검색 테이블을 만들지 않는다. WordPress 표준 저장 방식을 유지하여 백업·복원, 이관, 관리자 도구, 개인정보 내보내기가 기본 동작을 그대로 따르게 하기 위해서다.

대신 postmeta 조회 특성에 맞춘 아래 규칙을 필수로 적용한다.

**저장 형식 고정**

| 메타키 | 저장 형식 |
|---|---|
| `_todaydeal_latitude` / `_todaydeal_longitude` | 부호 포함 고정 소수점 문자열, 소수점 이하 6자리 |
| `_todaydeal_status` | 소문자 짧은 문자열 |
| `_todaydeal_listing_type` | `sell` 또는 `buy` |
| `_todaydeal_country` | ISO 3166-1 alpha-2 |
| `_todaydeal_expires_at` | `YYYY-MM-DD HH:MM:SS` UTC |

좌표는 정렬·비교가 문자열 기준으로 이뤄지므로 자릿수를 반드시 고정한다. 자릿수가 들쭉날쭉하면 범위 비교가 어긋난다. 수치 비교가 필요한 쿼리에서는 `CAST(... AS DECIMAL(10,6))`을 사용한다.

**필터 복합 키**

`status`, `listing_type`, `country`를 각각 조건에 넣으면 meta 조인이 3회 발생한다. 이를 피하기 위해 세 값을 결합한 복합 메타키를 함께 저장한다.

- `_todaydeal_filter_key` : `{listing_type}|{status}|{country}` (예: `sell|open|VN`)

목록 조회는 이 단일 메타로 1차 필터링하고, 개별 값은 응답 변환에만 사용한다. 원본 메타 3종은 관리자 화면과 정합성 점검을 위해 그대로 유지한다. 복합 키는 값 변경 시 함께 갱신하며, 불일치는 정합성 점검 도구에서 검출한다.

**인덱스**

WordPress 기본 `wp_postmeta`에는 `post_id`와 `meta_key(191)` 인덱스만 존재한다. 플러그인 활성화 시 아래 보조 인덱스를 추가한다.

- `(meta_key(64), meta_value(32), post_id)` 형태의 접두 인덱스

인덱스 추가는 테이블 크기에 따라 시간이 걸리므로 활성화 시 즉시 수행하지 않고 마이그레이션 작업으로 처리하며 진행 상태를 관리자 화면에 표시한다. 인덱스 추가 실패는 치명적 오류로 다루지 않되 관리자에게 경고한다.

**거리 조회 절차**

1. 요청 좌표와 반경으로 위도·경도 경계 상자를 계산한다.
2. `_todaydeal_filter_key`로 1차 필터링하고 경계 상자 범위 조건으로 후보를 좁힌다.
3. 후보 집합에 대해서만 정확한 거리를 계산한다.
4. 거리 정렬은 계산된 값 기준으로 수행하며 결과 수를 `limit`로 제한한다.

경계 상자 없이 전체 거래글에 거리 함수를 적용하는 방식은 금지한다.

**조회 제한**

- 목록 조회는 항상 `limit`와 커서를 적용하며 무제한 조회를 허용하지 않는다.
- 정렬 기준은 한 번에 하나만 사용한다. 다중 메타 정렬은 허용하지 않는다.
- 공개 목록 응답은 캐시하고 거래글 변경 시 관련 키를 무효화한다.

이 방식으로도 조회가 느려지는 시점이 오면 저장 구조를 바꾸기 전에 캐시 계층 강화와 인덱스 조정을 먼저 검토한다.

테이블명은 WordPress prefix를 적용하고 스키마 버전을 옵션에 저장한다.

## 21. 보안 요구사항

1. 모든 REST 라우트에 명시적인 `permission_callback`을 등록한다.
2. 사용자 ID와 거래글 소유자는 인증 토큰 및 WordPress 데이터에서 결정한다.
3. `listing_type`과 역할은 요청 본문이 아니라 저장된 거래글에서 결정한다.
4. 입력 필드를 allowlist 방식으로 검증하고 WordPress 정제 함수를 적용한다.
5. SQL이 필요한 경우 `$wpdb->prepare()`를 사용한다.
6. 비밀번호와 토큰 원문을 저장하거나 로그에 기록하지 않는다.
7. 토큰 서명키와 웹훅 키는 분리하고 주기적으로 교체할 수 있어야 한다.
8. 로그인·회원가입·미디어·거래글 등록·약속 생성에 속도 제한을 적용한다.
9. 업로드 파일은 실제 내용 검증과 재인코딩을 거친다.
10. REST CORS는 허용된 앱 서버 출처만 허용한다. 브라우저의 WordPress 직접 호출은 기본적으로 허용하지 않는다.
11. 관리자 작업, 소유권 변경, 거래글 상태 변경, 약속 강제 취소, 키 교체를 감사 로그에 남긴다.
12. 웹훅은 타임스탬프와 nonce를 검사해 재전송 공격을 방지한다.
13. 거래글의 상대방 수와 약속 목록은 소유자와 관리자에게만 노출한다.
14. 개인정보 삭제·내보내기용 WordPress 도구와 연동하며 거래글, 약속 이력, 체크리스트를 대상에 포함한다.
15. 매니저 권한은 capability 보유가 아니라 해당 거래에 `accepted` 상태로 배정되어 있는지를 매 요청마다 확인하여 판단한다.
16. 매니저는 수락 시점 이후 메시지와 배정된 약속에만 접근한다. 과거 대화 열람은 양측 동의 기록이 있을 때만 허용한다.
17. 매니저의 열람·기록 행위, 초대·수락·해제, 체크리스트 제출·정정, 분쟁 처리를 모두 감사 로그에 남긴다.
18. 매니저 후보 조회 응답에 매니저의 이메일, 연락처, 정확한 위치를 포함하지 않는다.
19. 체크리스트 사진은 다른 미디어와 동일한 검증·재인코딩·EXIF 제거를 거치며 참여자와 매니저에게만 노출한다.
20. 매니저 본인이 당사자인 거래에는 매니저로 배정될 수 없도록 이해충돌을 서버에서 차단한다.

## 22. 성능 및 안정성

| 항목 | 요구사항 |
|---|---|
| 목록 조회 | 기본 limit 20, 최대 100, 무제한 조회 금지 |
| 거래글 조회 | postmeta 복합 필터 키와 접두 인덱스를 사용하고, 거리 조회는 경계 상자 선필터를 거친다 (20.1) |
| 약속 조회 | listing_id, owner_user_id, counterpart_user_id, status 조합 인덱스 제공 |
| 매니저 조회 | 활동 국가·카테고리·상태 인덱스 제공, 후보 목록은 캐시하고 배정 변경 시 무효화 |
| 체크리스트 | 항목과 사진은 상세 조회에서만 로드하고 목록 응답에 포함하지 않는다 |
| 카테고리 카운트 | 전용 카운트 테이블에서 조회하며 term의 기본 count를 사용하지 않는다 (10.1) |
| 캐시 | 공개 거래글·카테고리 응답 캐시 및 변경 시 관련 캐시 무효화 |
| 파생 전이 | 약속 확정·취소 시 거래글 캐시와 카테고리 카운트를 함께 무효화 |
| 외부 호출 | REST 요청 처리 중 불필요한 외부 서비스 동기 호출 금지 |
| 웹훅 | 비동기 발송, 사용자 요청 성공 여부와 분리 |
| 이미지 | 파생 크기 사용, 원본 이미지를 목록에 직접 제공하지 않음 |
| 장애 격리 | 웹훅 실패가 거래글 저장이나 약속 확정을 실패시키지 않음 |
| 정리 작업 | WP-Cron 또는 Action Scheduler로 토큰·임시 미디어·만료 거래글·만료 약속·로그 정리 |

## 23. 로그 및 관측성

다음 항목을 구조화하여 기록한다.

- request_id, route, method, 상태 코드, 처리시간
- 인증 성공·실패 유형
- 거래글·미디어·약속 변경 결과와 `listing_type`
- 파생 상태 전이 발생 건수와 원인 약속 ID
- 단일 확정 제약 위반 시도 횟수
- 유형별 필드 검증 실패 건수
- 매니저 초대·수락·거절·해제 건수와 응답 소요시간
- 장소 확인 결과 분포와 재확인 발생 건수
- 체크리스트 제출률, 항목 실패 비율, 정정 발생 건수
- 분쟁 개시·에스컬레이션·종결 건수와 처리 소요시간
- 웹훅 이벤트 ID, 시도 횟수, 응답 코드, 처리시간
- 스키마 마이그레이션 결과

비밀번호, 토큰, 쿠키, 웹훅 서명키, 전체 요청 본문, 개인정보는 기록하지 않는다. 체크리스트 메모와 분쟁 사유의 본문도 로그에 기록하지 않으며 식별자만 남긴다. 보존기간과 자동 삭제 정책을 관리자 설정으로 제공한다.

## 24. 설치·활성화·삭제

### 활성화

1. WordPress와 PHP 버전을 검사한다.
2. WooCommerce 활성 여부와 `product_cat` 등록 여부를 검사한다.
3. `todaydeal_deal` 포스트타입을 등록하고 `product_cat`을 연결한 뒤 rewrite를 갱신한다.
4. 전용 테이블과 capability를 생성한다.
5. postmeta 보조 인덱스 추가를 마이그레이션 작업으로 예약한다.
6. 카테고리 카운트 초기 재계산을 예약한다.
7. 매니저·체크리스트 관련 capability와 기본 체크리스트 템플릿을 생성한다.
8. REST 라우트와 예약 정리 작업을 등록한다.
9. 초기 스키마 버전과 설정 기본값을 저장한다.

**스키마 자동 업그레이드 (구현 확장)**: 활성화 시점 외에도, 저장된 스키마 버전이 코드의 최신 버전보다 낮으면 매 요청마다(가벼운 옵션 조회 1회) 자동으로 테이블 마이그레이션과 capability 재등록을 수행한다. 이미 활성화된 사이트에서 스키마를 바꿔야 할 때 비활성화·재활성화 없이 반영하기 위함이다.

### 비활성화

- 예약 작업을 중지한다.
- 데이터와 설정은 유지한다.
- API는 비활성 상태 오류를 반환한다.
- 포스트타입 미등록으로 거래글이 관리자 화면에서 보이지 않음을 경고한다.

### 삭제

플러그인 제거 시 데이터 삭제 여부를 관리자가 사전에 선택할 수 있게 한다. 운영 데이터는 명시적 확인 없이 자동 삭제하지 않는다. 거래글은 포스트타입이 사라지면 조회가 어려우므로 삭제 옵션에서 별도 항목으로 표시한다.

## 25. 기존 플러그인 제거 및 데이터 전환 절차

기존 데이터가 WooCommerce `product`에 저장되어 있다면 `todaydeal_deal`으로 이관해야 한다.

1. WordPress 전체 백업과 DB·미디어 복구 테스트
2. 현재 사용자, 상품, 카테고리, 미디어, 번역, 작성자, 위치 메타 목록 추출
3. 신규 플러그인 스테이징 설치 및 API 계약 테스트
4. 기존 `product` 게시물을 `todaydeal_deal`으로 변환
   - `post_type` 변경, `listing_type = sell` 설정
   - 기존 `_price`를 `price_min`과 `price_max`에 동일값으로 이관
   - `_stock_status`, 재고·배송·세금 메타 제거
   - `product_cat` term 연결은 그대로 유지
   - `post_author`를 `_todaydeal_owner_user_id`에 복사
5. 기존 데이터에 구해요 성격의 글이 섞여 있으면 `listing_type = buy`로 분리하고 예산 필드를 재구성
6. 카테고리 카운트 전체 재계산
7. 거래글 수, 유형 분포, 사용자 수, 이미지 연결, 작성자, 가격, 상태 검증
8. 앱 서버의 연동 URL을 `/todaydeal/v1`로 전환
9. 읽기 전용 점검 후 신규 등록·수정·미디어 업로드·약속 생성 테스트
10. 웹훅과 동기화 지연 확인
11. 기존 플러그인 비활성화
12. 안정화 기간 후 기존 플러그인 제거

운영 전환은 즉시 삭제 방식이 아니라 `백업 → 병행 검증 → 비활성화 → 안정화 → 제거` 순서로 진행한다. 이관 스크립트는 중단 후 재실행해도 동일 결과가 나오도록 멱등하게 작성한다.

## 26. 테스트 및 인수 기준

### 기능 테스트

- 회원가입·로그인·토큰 갱신·로그아웃
- 팔아요·구해요 등록·수정·상태·삭제·자동 만료
- 유형별 필수·선택·금지 필드 검증
- `listing_type` 변경 요청 거부
- 사용자별 등록 한도 초과 처리
- 본인과 타 사용자 거래글 권한
- 이미지 정상·오류·대용량·위장 파일, 유형별 장수 제한
- 카테고리 계층·정렬·다국어와 sell·buy 카운트 분리
- 두 유형 모두에서 거래 약속 전체 상태 전이
- 웹훅 성공·중복·지연·실패 재시도

### 역할 파생 테스트

- sell 거래글의 약속에서 owner가 seller로, counterpart가 buyer로 파생되는지 확인
- buy 거래글의 약속에서 owner가 buyer로, counterpart가 seller로 파생되는지 확인
- 응답의 `seller_id`, `buyer_id`가 읽기 전용이며 요청으로 조작되지 않는지 확인
- 본인 거래글에 약속 생성이 차단되는지 확인

### 동시성 테스트

- 동일 거래글에 서로 다른 상대의 약속이 병존하는지 확인
- 두 상대의 수락 요청이 동시에 들어올 때 1건만 확정되고 나머지는 `APPOINTMENT_ALREADY_ACCEPTED`를 받는지 확인
- 확정 취소 후 거래글이 `open`으로 정확히 복귀하는지 확인
- 거래 완료 시 나머지 진행 약속이 모두 자동 취소되고 각 참여자에게 웹훅이 발송되는지 확인
- `reserved`, `expired` 직접 지정 요청이 거부되는지 확인
- `reserved` 상태 거래글이 만료 작업에 의해 잘못 만료되지 않는지 확인
- 확정 약속과 거래글 상태가 어긋난 데이터가 정합성 점검 도구에서 검출되는지 확인

### 매니저 중재 테스트

- 매니저 자격이 없는 사용자의 초대 수락이 차단되는지 확인
- 본인이 당사자인 거래에 매니저로 배정되지 않는지 확인
- 채팅방에 활성 매니저가 1명만 존재하는지 확인
- 상대 당사자 동의 없이 초대가 성립하지 않는지 확인 (동의 필수 설정 시)
- 한쪽 당사자 단독으로 매니저를 해제할 수 없는지 확인
- 매니저가 수락 이전 메시지를 열람할 수 없는지 확인
- 배정되지 않은 다른 거래에 매니저가 접근할 수 없는지 확인
- 매니저 해제 후 새 매니저 초대가 가능하고 이전 기록이 보존되는지 확인
- 거래 종료 시 매니저 접근이 자동 종료되고 기록 열람은 유지되는지 확인

### 장소 확인·체크리스트 테스트

- 장소 확인 결과가 confirmed, hold, rejected로 기록되는지 확인
- 약속 장소 변경 시 확인 결과가 초기화되고 재확인이 요청되는지 확인
- 매니저가 약속 장소를 직접 변경할 수 없는지 확인
- 체크리스트가 약속당 1건만 생성되는지 확인
- 당사자가 체크리스트를 작성·수정할 수 없고 열람만 되는지 확인
- 필수 항목 누락 시 제출이 거부되는지 확인
- 제출 후 수정이 차단되고 정정이 이력으로 남는지 확인
- 템플릿 변경 후에도 기존 체크리스트의 항목 구성과 표시명이 유지되는지 확인
- 완료 선행 조건 설정 시 미제출 상태의 거래 완료가 거부되는지 확인
- 임시 저장 후 재접속하여 이어서 작성할 수 있는지 확인

### 분쟁 테스트

- 당사자와 매니저 모두 분쟁을 개시할 수 있는지 확인
- 매니저가 약속을 강제 취소하거나 거래글 상태를 바꿀 수 없는지 확인
- 미해결 분쟁이 설정 기간 후 관리자에게 에스컬레이션되는지 확인
- 관리자만 강제 취소와 계정 제재를 수행할 수 있는지 확인
- 매니저 평가가 프로필 평점에 반영되는지 확인

### 계약 테스트

- TodayDeal 앱 서버와 요청·응답 스키마 자동 검증
- 지원 API 버전 불일치 처리
- 모든 오류 코드와 HTTP 상태 일치
- 언어 대체와 실제 반환 언어 표시
- 유형별 상태 라벨이 요청 언어로 반환되는지 확인
- 멱등성 요청 재전송 결과 일치

### 의존성 테스트

- WooCommerce 비활성화 시 카테고리 API가 `TAXONOMY_UNAVAILABLE`을 반환하는지 확인
- 같은 조건에서 거래글 조회 API가 카테고리 필드를 비운 채 동작하는지 확인
- WooCommerce 재활성화 후 taxonomy 연결과 카운트가 복구되는지 확인

### 보안 테스트

- 인증 우회와 수평 권한 상승
- 다른 사용자 거래글·미디어·약속 접근
- 타 사용자 거래글의 상대방 현황 조회 차단
- 요청 본문으로 소유자·역할·유형을 위조하는 시도 차단
- REST nonce·토큰·서명 위변조
- XSS, SQL Injection, 악성 이미지
- 로그인·업로드·거래글 등록·약속 생성 속도 제한
- 웹훅 재전송 공격

### 카운트·조회 정합성 테스트

- 거래글 생성·상태 전이·카테고리 변경·삭제 시 카운트가 정확히 증감하는지 확인
- 상위 카테고리 카운트에 하위 합계가 반영되는지 확인
- 증분 갱신 실패 후 전체 재계산으로 값이 복구되는지 확인
- 재계산을 중단 후 재실행해도 동일 결과가 나오는지 확인
- 미계산 term이 0이 아닌 `null`로 반환되는지 확인
- WooCommerce 상품 카테고리 화면의 카운트와 플러그인 카운트가 서로 간섭하지 않는지 확인
- `_todaydeal_filter_key`가 원본 메타 3종과 항상 일치하는지, 불일치가 정합성 점검에서 검출되는지 확인
- 좌표 자릿수가 고정되어 경계 상자 범위 비교가 정확한지 확인
- 경계 상자 선필터 없이 전체 거리 계산이 수행되는 경로가 없는지 확인

### 성능·복구 테스트

- 거래글 목록과 검색 부하, `type=all` 혼합 정렬 부하
- postmeta 보조 인덱스 유무에 따른 목록·거리 조회 응답시간 비교
- 대량 데이터에서 인덱스 마이그레이션 중단 후 재실행
- 인기 거래글에 약속이 다수 몰릴 때의 잠금 경합
- 대량 이미지 처리
- 웹훅 대기열 누적·재처리
- 스키마 마이그레이션 중단 및 재실행
- 기존 `product` 이관 스크립트 중단 후 재실행
- 백업 복원 후 데이터 일치

## 27. 출시 우선순위

| 단계 | 구현 범위 | 상태 |
|---|---|---|
| 1단계 | Core, 설치 검사, 인증, 회원 | ✅ 구현 완료 |
| 2단계 | `todaydeal_deal` 포스트타입, taxonomy 어댑터, 거래글 조회와 정규화 응답 | ✅ 구현 완료 |
| 3단계 | 미디어, 거래글 등록·수정·소유권·유형별 검증·상태 | ✅ 구현 완료 |
| 4단계 | 거래 약속, 역할 파생, 단일 확정 제약, 거래글 파생 상태 처리 | ✅ 구현 완료 |
| 5단계 | 자체 다국어 연결, 상태·유형 라벨, 기존 데이터 이관 | ⬜ 미구현 (상태·유형 라벨은 4단계에서 선행 구현됨) |
| 6단계 | 웹훅, 관리자 운영도구, 정합성 점검, 카운트 재계산 | ⬜ 미구현 (거래글 등록·수정 메타박스 화면은 예외적으로 선행 구현됨 — 1.1절) |
| 7단계 | 매니저 자격·초대·접근 제어, 약속 장소 확인 | ⬜ 미구현 |
| 8단계 | 체크리스트 템플릿·현장 점검·제출·확인 | ⬜ 미구현 |
| 9단계 | 분쟁 중재, 매니저 평가, 운영 도구 | ⬜ 미구현 |
| 10단계 | 보안·부하·복구 검증 및 운영 전환 | ⬜ 미구현 |

이 표와 별개로, 사용자 요청에 따라 카테고리별 추가 필드(10.2)와 사용자용 프런트 등록 폼(1.1)이 원래 계획에 없던 확장으로 1~4단계 구현과 함께 추가되었다.

거래 약속과 파생 상태는 거래글 정합성을 좌우하므로 다국어와 운영도구보다 앞에 배치한다. 매니저 기능은 거래 약속 위에 얹히는 구조이므로 약속 흐름이 안정된 이후 단계로 둔다. 매니저 접근 제어는 체크리스트보다 먼저 완성해야 하며, 권한 경계가 확정되지 않은 상태에서 점검 기록을 먼저 붙이면 열람 범위를 나중에 좁히기 어렵다.

## 28. 제외 범위

- WordPress 테마 및 공개 쇼핑몰 화면 제작
- WooCommerce 상품·주문·재고·장바구니·결제 기능 사용
- 결제대행사 연동과 배송
- 외부 번역 서비스 자동 번역
- 채팅 메시지의 WordPress 저장
- 앱의 WebSocket 및 Web Push 발송
- 팔아요와 구해요의 자동 매칭·추천
- 거래글 유형 간 상호 전환
- 매니저 자동 배정과 알고리즘 기반 매칭
- 매니저 보수 정산과 수수료 처리
- 매니저의 물품 보관·대리 수령·배송 대행
- 체크리스트 결과에 근거한 자동 환불·보상 판정
- 매니저의 법적 감정·품질 보증

채팅과 푸시 알림은 TodayDeal 앱 서버가 담당하며, WordPress 플러그인은 거래글·회원·미디어·거래 약속·매니저 배정·체크리스트·분쟁의 기준 데이터와 API를 담당한다.

매니저는 거래를 원활하게 돕고 상태를 기록하는 역할이며 거래 결과를 보증하지 않는다. 체크리스트는 점검 시점의 관찰 기록이지 품질 보증서가 아니라는 점을 서비스 약관과 앱 화면에 명시해야 한다.
