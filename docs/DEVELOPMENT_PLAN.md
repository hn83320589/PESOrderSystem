# 開發執行計畫（第一階段 MVP）

> 依據：`reference/水電批發訂單系統_開發計畫書.md`、`CLAUDE.md`、`.claude/skills/*`
> 撰寫日期：2026-09-27
> 本文件是「怎麼做」的執行計畫；「做什麼」以開發計畫書與 CLAUDE.md 為準。

---

## 0. 前提與風險

| 項目 | 說明 |
|---|---|
| 時程 | 2026 中秋節為 9/25，已過。以下以「週」為單位排程，約 4 週完成 MVP，需與客戶重新確認上線日 |
| LINE 帳號 | 需客戶本人申請 LINE 官方帳號、LINE Login channel、Messaging API channel，**兩個 channel 必須在同一個 Provider 下**（userId 才一致）。應在第 1 週就請客戶開始申請，否則 Step 5、7 會卡住 |
| 本機環境 | PHP 8.5、Composer 2.8.8（在 PHP 8.5 下會印 deprecation 警告，不影響功能）、Node 26、SQLite 3.54；本機無 MySQL |
| SQLite vs MySQL | 開發用 SQLite，SQLite 不支援 `SELECT ... FOR UPDATE`，並發防護改用「條件式原子 UPDATE」，兩種資料庫行為一致；上線前需在 MySQL 上重跑完整測試 |

---

## 1. 系統架構

```
Laravel 13（單一專案）
├── routes/api.php
│   ├── /api/admin/*     ← auth:web（內部人員，帳號密碼登入）
│   └── /api/customer/*  ← auth:customer（客戶，LINE Login）
├── routes/web.php
│   ├── /admin/{any}     ← 載入 Vue 管理端 SPA
│   ├── /{any}           ← 載入 Vue 客戶端 SPA
│   ├── /auth/line/*     ← LINE Login OAuth callback
│   └── /line/webhook    ← Messaging API webhook（驗簽）
├── app/Services/        ← 核心商業邏輯（庫存、訂單、預留、對帳），兩個前端共用
└── resources/js/
    ├── admin/           ← Vue 3 管理端（Vite entry 1）
    └── customer/        ← Vue 3 客戶端（Vite entry 2）
```

- **API 認證**：Laravel Sanctum（SPA cookie/session 模式，同網域，不需要 token 管理）
- **兩個 guard**：`web`（`users` 表，內部人員）與 `customer`（`customers` 表），物理上分開，客戶 session 無法呼叫 admin API
- **橫向越權防護**：客戶端所有查詢一律從 `auth('customer')->user()` 出發（`$customer->orders()->...`），不接受 URL 傳入的 customer_id；每個客戶端 API 都有「存取別人資料 → 404」的測試
- **商業邏輯集中在 Service**：Controller 只做驗證與回應，確保內部代客下單與客戶自行下單走同一段扣庫存邏輯
- **LINE Login**：直接用 Laravel `Http` client 實作 OAuth2（authorize → token → verify id_token），約 100 行，不額外引入 Socialite 套件
- **排程**：Laravel Scheduler（每日執行預留提醒、到期釋放）；Queue 用 `database` driver 發送 LINE 訊息

---

## 2. 資料庫設計

| Table | 主要欄位 | 備註 |
|---|---|---|
| `users` | name, email, password | 內部人員，MVP 全部同權限 |
| `products` | name, category, unit, is_active | |
| `product_variants` | product_id, spec, sku, price, is_active | |
| `inventory` | variant_id (unique), on_hand, reserved, allocated | 可用量 = on_hand − reserved − allocated |
| `inventory_movements` | variant_id, type, qty, 各欄位異動後數值, ref_type/ref_id, user_id, note | **新增（已確認）**：每次庫存變動都留紀錄，對帳爭議時可追溯 |
| `customers` | name, phone, address, billing_type(月結/貨到付款), line_user_id, line_bind_token, note | |
| `reservations` | customer_id, variant_id, qty, expires_at, status(active/fulfilled/expired/cancelled), reminded_at, order_id | |
| `orders` | order_no, customer_id, status(pending/confirmed/shipped/expired), payment_method(匯款/現金/支票), total, source(admin/customer), created_by, confirmed_at, shipped_at | |
| `order_items` | order_id, variant_id, product_name, spec, unit_price, qty, subtotal | 品名、規格、單價「快照」，改價不影響舊單與補印 |
| `payments` | order_id, customer_id, method, amount, status(unpaid/paid), paid_at, check_no, check_due_date | |
| `order_prints` | order_id, user_id, printed_at, is_reprint | 記錄補印次數 |
| `notifications_log` | customer_id, channel(line/site), type, title, body, status, error, sent_at, read_at | 站內通知也用這張表 |

### 庫存狀態流轉（核心）

| 動作 | on_hand | reserved | allocated |
|---|---|---|---|
| 進貨/盤點調整 | ± | | |
| 建立預留 | | +q | |
| 預留到期/取消 | | −q | |
| 建立訂單（一般） | | | +q |
| 建立訂單（使用預留） | | −q | +q |
| 訂單失效/取消 | | | −q |
| 出貨 | −q | | −q |

每個動作都是一個 DB transaction：條件式原子 UPDATE（`WHERE on_hand - reserved - allocated >= ?`）+ 寫入 `inventory_movements`。影響列數為 0 → 丟出 `InsufficientStockException`。

---

## 3. 執行步驟

每步完成條件：功能測試通過 + Pint 無警告 + commit。每完成一個模組回報「可放進 README 的哪個章節」。

### Step 1：環境建置與資料庫（第 1 週）
- [x] `git init`、建立 Laravel 13 專案（保留現有 CLAUDE.md、reference/、.claude/）
- [x] 安裝 Sanctum、Vue 3、vue-router、Vite 雙 entry
- [x] 全部 migrations、Models、關聯、Factories
- [x] Seeder：內部帳號、25 種商品×5 規格、10 位客戶、範例訂單
- [x] 測試：migrate:fresh --seed 成功、Model 關聯測試

### Step 2：商品與庫存模組（第 1 週）
- [ ] `InventoryService`：adjust / reserve / releaseReservation / allocate / release / ship
- [ ] **並發測試**：多個 process 同時對同一規格下單，總配置量不超過可用量
- [ ] `ReservationService` + 排程指令 `reservations:remind`（到期前 7 天）、`reservations:expire`
- [ ] Admin API：商品/規格 CRUD、庫存調整、預留 CRUD
- [ ] Admin 前端：登入頁、商品列表、庫存頁、預留頁

### Step 3：內部訂單管理（第 2 週）
- [ ] `OrderService`：建單（自動使用該客戶有效預留）、改單（僅 pending/confirmed 可改，差額重算庫存）、確認、出貨、失效
- [ ] 狀態轉換規則測試（不合法轉換必須被擋下）
- [ ] 老樣子 API：該客戶最近 10 筆訂單 → 一鍵帶入明細（以目前價格與庫存重新檢查）
- [ ] Admin 前端：訂單列表/篩選、建單（代客下單）、訂單詳情

### Step 4：付款與對帳（第 2 週）
- [ ] 付款紀錄 CRUD、標記已收/未收、支票號碼與到期日
- [ ] 月結彙總：指定月份 × 客戶，出貨金額、已收、未收
- [ ] Excel 匯出（maatwebsite/excel），用 `xlsx` skill 驗證
- [ ] Admin 前端：付款頁、月結報表頁

### Step 5：LINE Messaging API（第 3 週）
- [ ] 套件 `linecorp/line-bot-sdk`（實作時查官方文件確認新版 API）
- [ ] `LineNotifier`（Queue job）：推播 + 寫入 notifications_log，失敗記錄錯誤不中斷主流程
- [ ] Webhook：驗證 `X-Line-Signature`，處理 follow 事件（記錄 userId）
- [ ] 推播情境：訂單確認、預留到期前 7 天提醒
- [ ] 測試：用 `Http::fake` 驗證送出內容；簽章錯誤回 400
- [ ] 無金鑰時：記錄為 skipped，不影響訂單流程（開發期客戶尚未申請完成）

### Step 6：PDF 訂單（第 3 週）
- [ ] 實測 dompdf 與 mpdf 的中文顯示，擇一並回填 `chinese-pdf-invoice-laravel` skill
- [ ] 字型：Noto Sans TC（SIL OFL 授權，可嵌入）
- [ ] 下單後產生 PDF 存檔；改單時重新產生；補印直接讀取檔案並寫入 order_prints
- [ ] PDF 內容：單號、日期、客戶、明細、總額、付款方式、匯款帳號
- [ ] 用 `pdf` skill 開啟確認版面

### Step 7：客戶端陽春版（第 4 週）
- [ ] LINE Login + 綁定流程：後台產生一次性綁定連結 → 客戶點開並用 LINE 登入 → 寫入 line_user_id；未綁定的 LINE 帳號只看到「請聯絡店家」
- [ ] 頁面：首頁（大按鈕：下單／老樣子／我的訂單）、下單、老樣子、訂單列表與付款狀態、預留確認、站內通知
- [ ] 依 `elderly-friendly-self-service-ui` 原則：字級 ≥ 18px、按鈕高度 ≥ 48px、送出前確認頁、防止重複送出
- [ ] 越權測試：每支客戶端 API 都測「拿別人的 ID → 404」
- [ ] 用 `frontend-design` skill 檢查手機可用性

### Step 8：測試與試用（第 4 週）
- [ ] 全流程 E2E 手動測試腳本（建單 → 確認 → 出貨 → 收款 → 月結）
- [ ] 2-3 位熟客試用，回饋記錄於 `docs/feedback.md`

### Step 9：部署（第 4 週後）
- [ ] 主機選擇（需與客戶討論預算）、HTTPS 網域（LINE webhook 必須 HTTPS）
- [ ] MySQL 8 上重跑全部測試（含並發測試）
- [ ] cron `schedule:run`、queue worker（Supervisor）
- [ ] 部署文件 `docs/DEPLOY.md`

---

## 4. 簡化決策（MVP 刻意不做）

- 預留的「頻率規則」不做自動產生下一期；後台提供「複製為下一期」按鈕（與「不替使用者自動決定」原則一致）
- 內部人員不分角色權限（CLAUDE.md：三人皆完整權限）
- 前端不寫單元測試，以後端 Feature Test 覆蓋 API 行為 + 手動驗證
- 匯款帳號放 `.env` / config，不做後台設定頁

## 5. 測試策略

- PHPUnit（Laravel 預設）Feature Tests，每個 API 端點至少一個成功案例 + 一個權限案例
- 並發測試：以多個 `php` 子行程對同一個 SQLite 檔案資料庫同時下單
- LINE、PDF 等外部依賴以 `Http::fake`、`Storage::fake` 隔離
