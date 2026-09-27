# 部署指南

一台 Linux 主機（Ubuntu 24.04 為例）＋ Nginx ＋ PHP-FPM ＋ MySQL 8 ＋ Supervisor。本文件記錄本專案特有的設定與陷阱；Laravel 通用部分以[官方部署文件](https://laravel.com/docs/deployment)為準。

---

## 1. 主機需求

| 項目 | 需求 | 說明 |
|---|---|---|
| 規格 | 1–2 vCPU、2 GB RAM 以上 | MySQL 與 PHP-FPM 同機；約 50 家客戶的用量 |
| PHP | 8.3 以上（已於 8.5 開發） | 擴充：`pdo_mysql`、`mbstring`、`gd`、`zip`、`zlib`、`xml`／`dom`／`simplexml`／`xmlreader`／`xmlwriter`、`iconv`、`fileinfo`、`curl`。部署後執行 `composer check-platform-reqs --no-dev` 確認無缺 |
| PHP `memory_limit` | **至少 256M** | 產生一張 PDF 約需 66MB（mpdf 載入中文字型）；預設 128M 雖夠用，保留餘裕 |
| MySQL | 8.0 以上（已以 8.4.11 驗證全部測試） | 字元集 `utf8mb4` |
| HTTPS | **必要** | LINE webhook 與 LINE Login 回呼網址都必須是 HTTPS |
| Node.js | 20.19+ 或 22.12+ | 只在建置前端時需要，可在本機 `npm run build` 後上傳 `public/build` |

---

## 2. 首次部署

```bash
git clone <repo> /var/www/pes && cd /var/www/pes
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

編輯 `.env`（見第 3 節）後：

```bash
php artisan migrate --force
php artisan staff:create boss@example.com 老闆     # 建立內部人員帳號，互動輸入密碼
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
```

> **不要在正式環境執行 `db:seed`**：`DatabaseSeeder` 與 `TrialSeeder` 建立的帳號密碼寫在程式碼裡（`password`、`trial1234`），任何人都能登入。

---

## 3. 正式環境 `.env`

```dotenv
APP_ENV=production
APP_DEBUG=false                  # 必須為 false，否則錯誤頁會外洩設定與金鑰
APP_URL=https://order.example.tw # 正式 HTTPS 網址；排程、queue 等非網頁請求產生的網址以此為準

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pes
DB_USERNAME=pes
DB_PASSWORD=<強密碼>

SESSION_SECURE_COOKIE=true       # 登入 cookie 只走 HTTPS
QUEUE_CONNECTION=database

SHOP_NAME=...                    # 店家資訊與匯款帳號，印在 PDF 與 LINE 通知
SHOP_PHONE=...
SHOP_BANK_NAME=...
SHOP_BANK_ACCOUNT=...
SHOP_BANK_ACCOUNT_NAME=...

LINE_CHANNEL_ACCESS_TOKEN=...    # Messaging API
LINE_CHANNEL_SECRET=...
LINE_LOGIN_CHANNEL_ID=...        # LINE Login（與 Messaging API 同一個 Provider）
LINE_LOGIN_CHANNEL_SECRET=...
```

- **時區**：`config/app.php` 固定 `Asia/Taipei`，資料庫存的是台北時間。**上線後不可更改**，否則既有時間全部位移。
- **位於反向代理或 Cloudflare 後方時**：需在 `bootstrap/app.php` 設定 `$middleware->trustProxies(at: '*')`（或指定代理 IP）。網頁請求中的網址（LINE Login 回呼網址、綁定連結）是依「當次請求」的網域與協定產生；未信任代理時系統會以為連線是 HTTP，產生 `http://` 網址，LINE Login 會因回呼網址不符而失敗。
- 修改 `.env` 後要重新執行 `php artisan config:cache` 才會生效。

---

## 4. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name order.example.tw;
    root /var/www/pes/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/order.example.tw/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/order.example.tw/privkey.pem;

    client_max_body_size 5m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;   # 兩個 Vue SPA 的前端路由都交給 Laravel
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) { deny all; }
}

server {
    listen 80;
    server_name order.example.tw;
    return 301 https://$host$request_uri;
}
```

HTTPS 憑證可用 Let's Encrypt（`certbot --nginx`）。

---

## 5. 背景程序

### Queue worker（LINE 通知）— Supervisor

`/etc/supervisor/conf.d/pes-worker.conf`：

```ini
[program:pes-worker]
command=php /var/www/pes/artisan queue:work --sleep=3 --tries=5 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/www/pes/storage/logs/worker.log
```

`supervisorctl reread && supervisorctl update`。**沒有 worker，LINE 通知會一直停在「發送中」**。

### 排程（預留到期釋放、到期提醒）— cron

```cron
* * * * * cd /var/www/pes && php artisan schedule:run >> /dev/null 2>&1
```

---

## 6. LINE 設定（店家於 LINE Developers Console 操作）

| Channel | 欄位 | 值 |
|---|---|---|
| LINE Login | Callback URL | `https://order.example.tw/auth/line/callback` |
| LINE Login | Linked LINE Official Account | 連結店家官方帳號（登入時才會提示加好友） |
| Messaging API | Webhook URL | `https://order.example.tw/api/line/webhook`，開啟「Use webhook」並按「Verify」 |

設定完成後：傳一則測試通知到自己的 LINE、以綁定連結實際走一次客戶開通（試用劇本 S4）。

---

## 7. 備份

需要備份兩樣東西，缺一不可：

| 內容 | 位置 | 為什麼 |
|---|---|---|
| 資料庫 | MySQL `pes` | 所有訂單、庫存、收款 |
| **訂單 PDF** | `storage/app/private/orders/` | 補印讀的是存檔，內容與原單一致；遺失時會以訂單快照重產，但店家資訊、匯款帳號會變成當下的設定 |

```cron
30 2 * * * mysqldump --single-transaction pes | gzip > /backup/pes-$(date +\%F).sql.gz
40 2 * * * tar czf /backup/pes-pdf-$(date +\%F).tgz -C /var/www/pes/storage/app/private orders
```

備份要放到主機以外（雲端儲存或另一台機器），並定期試還原一次。

---

## 8. 更新流程

```bash
cd /var/www/pes
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart          # 讓 worker 載入新程式
php artisan up
```

---

## 9. 上線前檢查清單

- [ ] `APP_DEBUG=false`、`APP_ENV=production`、`APP_URL` 為 HTTPS 正式網址
- [ ] 以 `staff:create` 建立三位內部人員帳號，**未執行任何 seeder**
- [ ] 開啟 `/dev/customer-login/1` 不會登入任何客戶，而是導向客戶登入頁（此路由只在 local 環境存在）
- [ ] Supervisor worker 執行中；cron 已設定
- [ ] LINE Login 回呼、Webhook「Verify」成功；實際收到一則測試推播
- [ ] 以綁定連結完成一位客戶開通並下單一次
- [ ] 產生一張訂單 PDF，中文與匯款帳號正確
- [ ] 資料庫與 PDF 備份已排程，並試還原一次
- [ ] 店家資訊（`SHOP_*`）正確

---

## 附錄：在 MySQL 上執行測試

開發預設用 SQLite。上線或升級 MySQL 前，建議對 MySQL 跑一次完整測試（含真正並行的防超賣測試）：

```bash
# 需一個空的測試資料庫；並發測試會另建 <DB_DATABASE>_concurrency 並於結束時刪除
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=pes_test \
DB_USERNAME=root DB_PASSWORD= php artisan test
```

2026-09-27 以 MySQL 8.4.11 執行，189 個測試全部通過。
