# Web Push 通知評估（第二階段）

> 撰寫：2026-09-27。結論：**第二階段不實作 Web Push**，改以「控制 LINE 推播則數」處理成本，並在試用後依實際則數再評估。

## 要回答的問題

第一階段已用 LINE 推播＋站內通知。計畫書把 Web Push 列為「若不做原生 App，評估作為 App 內通知的替代方案」。評估的是：Web Push 能不能讓這群客戶**更確實收到通知**，或**省下 LINE 訊息費**。

## 事實

### 1. 這群客戶的使用環境收不到 Web Push
- 客戶從 LINE 聊天室的連結開啟網頁，也就是 **LINE 內建瀏覽器**。
- iPhone：Web Push **只支援「加入主畫面」後以 web app 開啟的網站**；Safari 分頁、其他瀏覽器、App 內建瀏覽器都沒有 Push API（iOS 16.4 起）。
- Android：Chrome 分頁可以收 Web Push；但 App 內建瀏覽器（WebView）一般不支援 Push API，需請客戶改用 Chrome 開啟。*（一般已知限制，建議上線前以實機確認）*

要讓客戶收到 Web Push，必須教 50–60 歲的師傅「用 Safari 開啟 → 分享 → 加入主畫面 → 從主畫面圖示打開 → 允許通知」。這與 `elderly-friendly-self-service-ui` 的核心原則直接衝突：**通知要走他們每天在用的管道，不要指望他們打開一個新 App**。

### 2. LINE 推播的費用（2026-11-01 起）

| 方案 | 月費 | 每月免費訊息 | 超過後 |
|---|---|---|---|
| 輕用量 | 0 元 | 200 則 | 用完即止，不可加購 |
| 中用量 | 1,000 元（原 800） | 3,000 則 | 用完即止，不可加購 |
| 高用量 | 1,400 元（原 1,200） | 6,000 則 | 每則 0.2 元（5 萬則起 0.15 元） |

- 本系統使用的 Messaging API **push 計入則數**；**Reply API（回覆客戶訊息）免費**。
- 推播給一位客戶一次算一則（一般計算方式，官方調整公告頁未逐項載明）。

### 3. 本案則數估算

| 情境 | 每月則數 |
|---|---|
| 訂單確認：已綁定客戶的訂單才推播 | 已綁定客戶數 × 每月叫貨次數 |
| 預留到期提醒 | 預留筆數（每筆最多一次） |

例：50 家中 25 家綁定、每家每月叫貨 8 次、預留 20 筆 → 約 **220 則／月**，略超過輕用量的 200 則。
- 用完即止代表**超過後當月的訂單確認通知會發送失敗**；系統辨識出「額度用完」後會立即標記失敗並寫明原因（不做無用的重試），站內通知仍會送達，後台「通知紀錄」看得到，但客戶收不到 LINE。
- 升級中用量（1,000 元／月、3,000 則）即可涵蓋此規模數倍的成長。

## 選項比較

| 選項 | 客戶收得到嗎 | 成本 | 開發量 |
|---|---|---|---|
| A. 實作 Web Push 取代部分 LINE | 需客戶改變使用習慣，預期大多數收不到 | 省下 0–1,000 元／月 | 中（Service Worker、訂閱管理、VAPID 金鑰、iOS 引導頁） |
| B. 維持 LINE，控制則數 | 收得到 | 0 或 1,000 元／月 | 小 |
| C. 維持 LINE，直接用中用量 | 收得到 | 1,000 元／月 | 無 |

## 建議

1. **不實作 Web Push**（選項 B，必要時轉 C）。對這群客戶，能不能收到比省 1,000 元重要；Web Push 的收到率在這個使用環境下預期很低。
2. **先用輕用量上線，試用期間每月看實際則數**：後台總覽已顯示「本月 LINE 訊息 已用／上限」（取自 LINE 官方 quota API，含從官方帳號後台手動發送的訊息），達 80% 會提醒。
3. 則數接近 200 時再升級中用量；以本案規模，**中用量足以長期使用**。
4. 若之後出現「客戶很多、每月超過 3,000 則」或「年輕客戶主動要求用網頁 App」，再重新評估 Web Push。

## 來源

- LINE Biz-Solutions，[2026 年 LINE 官方帳號方案價格調整](https://tw.linebiz.com/column/LINEOA-2026-Price-Plan/)
- 賴管家，[LINE 官方帳號訊息費怎麼算？2026 年 11 月 1 日新方案與則數計算](https://lineoa.batmobile.com.tw/blogs/lineoa_message_counting)
- WebKit，[Web Push for Web Apps on iOS and iPadOS](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/)
- MagicBell，[PWA iOS Limitations and Safari Support (2026)](https://www.magicbell.com/blog/pwa-ios-limitations-safari-support-complete-guide)
