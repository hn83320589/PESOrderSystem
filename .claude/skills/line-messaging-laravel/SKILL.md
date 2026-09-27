---
name: line-messaging-laravel
description: 在 Laravel 專案中串接 LINE Messaging API（官方帳號推播、webhook 接收）。當使用者提到要幫台灣中小企業客戶的系統加上「LINE 通知」「LINE 官方帳號」「LINE 推播」時，務必使用此 skill，即使使用者沒有明確說「Messaging API」或「webhook」這些技術詞彙也一樣要觸發——很多客戶只會說「我想要 LINE 通知我」。特別注意：不要使用 LINE Notify，該服務已於 2025 年正式廢止，任何提到 LINE Notify 的舊教學或程式碼都應改用本 skill 的 Messaging API 做法。
---

# LINE Messaging API 串接（Laravel）

> **狀態：draft-v0.1，尚未經過實戰驗證。** 本 skill 為骨架版本，內容基於規劃階段的已知資訊整理，尚未經過實際串接驗證。第一次實作後應回填：實際遇到的錯誤訊息、申請流程的真實步驟畫面、webhook 驗證失敗的具體排查方法。

## 何時使用

客戶（通常是不熟技術的中小企業主）想要：
- 訂單/預約/到期提醒用 LINE 通知客戶
- 系統事件（如：庫存到期、付款提醒）要推播
- 客戶端要用 LINE 登入（此情境也可參考本 skill 的帳號設定部分，但登入邏輯屬於 LINE Login，非 Messaging API，兩者是不同產品，不要混用）

## 核心決策：Messaging API，不是 LINE Notify

LINE Notify 已於 2025 年廢止服務。任何舊文章、舊教學提到 `notify-api.line.me` 都已失效，一律改用：
- LINE Official Account（官方帳號）
- LINE Messaging API
- 需要 Channel Access Token + Channel Secret

## 前置作業（需客戶配合，非純開發工作）

這一步**必須在時程規劃時就排入**，因為需要客戶本人操作，開發者無法代辦：

1. 客戶需申請 LINE Official Account Manager 帳號
2. 在 LINE Developers Console 建立 Provider 與 Messaging API Channel
3. 取得 Channel Access Token（long-lived）與 Channel Secret
4. 設定 Webhook URL（需先有可對外的網址，本機開發階段可用 ngrok 之類工具暫代）

> 提醒使用者：這一步如果拖到開發後期才問客戶要，會直接卡住整條時程，開案初期就要主動跟客戶要這些資訊或協助他申請。

## Laravel 實作骨架

### 安裝套件
```bash
composer require linecorp/line-bot-sdk
```
（開發時查詢當下最新版本號，不要寫死舊版本）

### 環境變數（.env）
```
LINE_CHANNEL_ACCESS_TOKEN=
LINE_CHANNEL_SECRET=
```

### 推播範例（Push Message）
```php
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;

$httpClient = new CurlHTTPClient(config('services.line.channel_access_token'));
$bot = new LINEBot($httpClient, ['channelSecret' => config('services.line.channel_secret')]);

$bot->pushMessage($lineUserId, new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($message));
```

> 待補：實際串接後確認 SDK 版本是否有 breaking change（LINE SDK 版本更新頻繁，此範例語法需在實作時重新核對官方文件）。

### Webhook 接收（用於驗證客戶身分、取得 LINE User ID）
```php
Route::post('/line/webhook', [LineWebhookController::class, 'handle']);
```

webhook 需驗證 `X-Line-Signature` header，避免偽造請求。實作時務必加上簽章驗證，不要略過。

## 常見情境對應

| 客戶需求 | 對應功能 |
|---|---|
| 訂單提醒 | Push Message（主動推播） |
| 客戶登入系統 | LINE Login（不是本 skill 範圍，是獨立產品） |
| 取得客戶 LINE User ID 以便日後推播 | 客戶第一次加官方帳號好友，或透過 LINE Login 取得 sub（user id）並存進資料庫 |

## 待補充（下次實戰後更新）

- [ ] 實際申請流程的畫面截圖與步驟細節
- [ ] Webhook 驗證失敗的常見原因與排查步驟
- [ ] Channel Access Token 過期/失效時的處理方式
- [ ] 免費額度限制（訊息則數）與超過後的成本估算，供報價參考
