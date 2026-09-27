---
name: chinese-pdf-invoice-laravel
description: 在 Laravel 專案中產生含中文內容的 PDF 單據（訂單、估價單、收據、對帳單）。當使用者需要「下單後自動產生 PDF」「印出訂單/估價單」「補印單據」等功能時務必使用此 skill，即使沒有明確提到 dompdf 或 mpdf 這些套件名稱。中文 PDF 常見的坑是字型嵌入失敗導致中文顯示成方塊或亂碼，這是本 skill 要優先解決的問題。
---

# 中文 PDF 單據產生（Laravel）

> **狀態：v0.2，已於 1 個案子實戰驗證**（水電材料批發訂單系統，2026-09，Laravel 13 / PHP 8.5 / mpdf 8.3.1）。

## 套件：用 mpdf

以同一份 HTML（長品名、長備註、`1/2"`、`mm²`、40 列跨頁表格）實測：

| | dompdf 3.1.6 | mpdf 8.3.1 |
|---|---|---|
| 中文與特殊符號 | ✅ | ✅ |
| **無空白的中文長句換行** | ❌ 超出頁面被截斷 | ✅ 正常換行，標點不落行首 |
| 全形空白「　」 | 被壓縮 | 保留 |
| 跨頁重複表頭（`thead`） | ✅ | ✅ |
| 速度／檔案大小（字型自動子集化） | 0.15s／41KB | 0.09s／52KB |

dompdf 只在空白處斷行，中文句子整段被當成一個字 —— 這是演算法限制，換字型無效。地址、備註、長品名都會觸發，單據資訊會被截掉。

直接用 `mpdf/mpdf`，不需要 Laravel 包裝套件。

## 字型：Noto Sans TC 靜態檔

1. 下載 `https://github.com/google/fonts/raw/main/ofl/notosanstc/NotoSansTC%5Bwght%5D.ttf` 與同目錄 `OFL.txt`（SIL OFL，可嵌入散佈；授權檔需一併放進專案）。
2. Google Fonts 只提供**可變字型**，PDF 函式庫會用到預設字重（Thin）。以 fonttools 切出靜態檔，**必須加 `--update-name-table`**：
   ```bash
   fonttools varLib.instancer NotoSansTC[wght].ttf wght=400 --update-name-table -o NotoSansTC-Regular.ttf
   fonttools varLib.instancer NotoSansTC[wght].ttf wght=700 --update-name-table -o NotoSansTC-Bold.ttf
   ```
   沒加時兩檔內部名稱都是 `NotoSansTC-Thin`，mpdf 視為同一字型只嵌入一個，粗體失效。
3. 放在 `resources/fonts/NotoSansTC/`（每檔約 7MB，進版本庫以確保任何主機都能產出）。

## mpdf 設定

```php
$defaultConfig = (new \Mpdf\Config\ConfigVariables)->getDefaults();
$defaultFonts = (new \Mpdf\Config\FontVariables)->getDefaults();

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'tempDir' => storage_path('framework/cache/mpdf'),
    'fontDir' => [...$defaultConfig['fontDir'], resource_path('fonts/NotoSansTC')],
    'fontdata' => $defaultFonts['fontdata'] + [
        'notosanstc' => ['R' => 'NotoSansTC-Regular.ttf', 'B' => 'NotoSansTC-Bold.ttf'],
    ],
    'default_font' => 'notosanstc',
    'defaultfooterfontstyle' => '', // 中文無斜體，預設粗斜體頁尾會被人工傾斜
]);
$mpdf->SetFooter('{PAGENO} / {nbpg}');
$mpdf->WriteHTML(view('pdf.order', [...])->render());
$binary = $mpdf->Output('', 'S');
```

Blade 樣板用 `font-family: notosanstc`，表頭加 `thead { display: table-header-group; }` 讓跨頁重複。

**記憶體**：字型資料約常駐 64MB，單張峰值約 66MB（主機 PHP `memory_limit` 至少 128M）。整套測試連續產出 PDF 會累積超過 128MB，在 `phpunit.xml` 的 `<php>` 加 `<ini name="memory_limit" value="512M"/>`，僅影響測試。

## 補印：讀存檔，不重新 render

- 下單（及改單）時產生 PDF **存檔**並記錄路徑；列印／補印一律回傳存檔。重新 render 會吃到日後的改價、店家帳號變更，補印就不再是原單。
- 訂單明細存品名、規格、單價**快照**，檔案遺失時才以快照重新產生，並 `report()` 留下紀錄。
- 每次列印寫一筆紀錄（人員、是否補印），爭議時可查印過幾次。
- 回應用 `Content-Disposition: inline`，瀏覽器直接開啟、按列印即可。

## 驗證

- 內容測試：測 Blade 產出的 HTML 含單號、品名、金額、匯款帳號（PDF 內文字串流是壓縮的，直接搜尋 PDF 位元組不可靠）。
- 產出測試：檔案以 `%PDF` 開頭、含字型名稱 `NotoSansTC`；補印兩次內容逐位元組相同。
- 目視：以 PyMuPDF 轉 PNG 檢查版面（`page.get_pixmap(dpi=80).save(...)`），並列出嵌入字型確認 Regular／Bold 皆在；可在暫存 venv 安裝，不需系統層級的 poppler。

## 尚未驗證

- [ ] 生僻字（客戶姓名罕用字）是否都在 Noto Sans TC 字集內
- [ ] 熱感應／點陣印表機實印效果
- [ ] 單據量大時的儲存空間規劃（目前每張約 40KB）
