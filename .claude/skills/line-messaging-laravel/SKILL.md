---
name: line-messaging-laravel
description: 在 Laravel 專案中串接 LINE Messaging API（官方帳號推播、webhook 接收）。當使用者提到要幫台灣中小企業客戶的系統加上「LINE 通知」「LINE 官方帳號」「LINE 推播」時，務必使用此 skill，即使使用者沒有明確說「Messaging API」或「webhook」這些技術詞彙也一樣要觸發——很多客戶只會說「我想要 LINE 通知我」。特別注意：不要使用 LINE Notify，該服務已於 2025 年正式廢止，任何提到 LINE Notify 的舊教學或程式碼都應改用本 skill 的 Messaging API 做法。
---

# LINE Messaging API 串接（Laravel）

> **狀態：v0.2，程式與測試已於 1 個案子完成**（水電材料批發訂單系統，2026-09，Laravel 13）。以官方規格＋`Http::fake` 驗證，**尚未以真實官方帳號實發**；首次實發後回填「尚未驗證」清單。

## 前置作業（客戶本人操作，開案第一週就要啟動）

1. 申請 LINE 官方帳號，於 LINE Developers Console 建立 Provider。
2. 在**同一個 Provider** 下建立 Messaging API channel（推播）與 LINE Login channel（客戶登入）。userId 是 per-Provider 的：分屬不同 Provider 時，登入取得的 userId 無法用來推播，事後無法補救。
3. 取得 Channel Access Token（long-lived）與 Channel Secret，填入 `.env`。
4. Webhook URL 需 HTTPS，通常要等正式上線後才能設定。

## 做法：Laravel Http client 直接呼叫

用 Laravel `Http` 直接呼叫 API，不裝 `linecorp/line-bot-sdk`：SDK（v8 起為 OpenAPI 產生的 client，舊的 `LINE\LINEBot` 類別已移除）自帶 Guzzle，`Http::fake` 攔不到，失敗情境難以測試；通知需求通常只有「推播」與「驗簽」兩件事，約 50 行。

```php
// config/services.php
'line' => [
    'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
    'channel_secret' => env('LINE_CHANNEL_SECRET'),
],
```

### 推播

```php
$response = Http::withToken(config('services.line.channel_access_token'))
    ->withHeaders(['X-Line-Retry-Key' => $retryKey]) // UUID
    ->timeout(10)
    ->post('https://api.line.me/v2/bot/message/push', [
        'to' => $lineUserId,
        'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 5000)]],
    ]);
```

回應分類（決定 queue 是否重試）：

| 狀態 | 意義 | 處理 |
|---|---|---|
| 2xx | 成功 | 標記已送達 |
| **409** | 同一 retry key 已受理過 | **視為成功** |
| 429、5xx、連線失敗 | 暫時性 | 丟例外讓 queue 重試 |
| 其他 4xx | 參數錯誤、對方封鎖等 | 直接標記失敗，重試無意義 |

### 防重複推播：固定 retry key

每則通知建立時產生一組 UUID 存進通知紀錄，所有重試與人工重送都沿用同一組。第一次其實已送達、只是回應遺失時，LINE 以 409 擋下重複訊息 —— 中高齡客戶收到兩則「訂單已確認」會以為下了兩張單。

### Queue 與交易

- 推播放 queue job，建構子呼叫 `$this->afterCommit()`：交易回滾就不會通知客戶。
- job 的 `failed()` 把紀錄標為失敗並寫入原因；後台提供「通知紀錄」頁顯示原因與重送按鈕。
- 客戶未綁定或系統未設定金鑰時，通知紀錄寫「略過」並附原因，主流程（下單、確認）照常完成。開發期客戶尚未申請完成時，這讓整套系統可以先跑起來。

### Webhook 驗簽

```php
$expected = base64_encode(hash_hmac('sha256', $request->getContent(), config('services.line.channel_secret'), true));
$valid = hash_equals($expected, (string) $request->header('X-Line-Signature'));
```

- 用 `$request->getContent()` 的**原始 body**：先 `json_decode` 再編碼會改變位元組，驗簽必定失敗。
- 驗簽失敗回 400；成功一律盡快回 200（LINE 後台的「驗證」按鈕會送 `events: []`）。
- 路由放 `routes/api.php`，不使用 session／CSRF（LINE 不帶 Referer，Sanctum stateful 不會啟用）。
- 處理 `follow`／`unfollow` 更新客戶「是否仍為好友」，推播失敗時後台看得出是客戶封鎖。

## 測試

- `Http::fake(['api.line.me/*' => ...])` 模擬各種回應；同一測試要多種回應時用 `Http::sequence()->push(...)`，重複呼叫 `Http::fake` 是追加規則、先前規則仍會先命中。
- 驗證：Authorization、`X-Line-Retry-Key`、body；409 視為成功；4xx／5xx 分類；重試沿用同一 retry key；簽章錯誤／缺漏回 400。
- 測試類別內的 helper 不要命名為 `post()` 等，會與 Laravel TestCase 的 HTTP 方法衝突。

## 通知文字（中高齡客群）

短句、一行一件事；標題帶店名；列出單號、品項（超過 10 項摺疊為「…等共 N 項」）、金額、付款方式，匯款單附帳號，結尾附店家電話。

## 尚未驗證（首次以真實帳號實發後回填）

- [ ] 申請流程實際步驟與畫面
- [ ] 對方封鎖時 push API 的實際回應碼
- [ ] Channel Access Token 失效時的錯誤與更新方式
- [ ] 免費訊息則數上限與超量費用（供報價）
