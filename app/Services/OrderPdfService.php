<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPrint;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * PDF 訂單：下單與改單時產生並存檔，列印/補印一律讀取存檔，
 * 確保補印內容與原單完全相同（不受日後改價、改帳號影響）。
 *
 * 選用 mpdf 而非 dompdf：實測 dompdf 無法在無空白的中文句子中換行，長文字會超出頁面被截斷。
 */
class OrderPdfService
{
    private const DISK = 'local';

    public function generate(Order $order): string
    {
        $path = "orders/{$order->order_no}.pdf";
        Storage::disk(self::DISK)->put($path, $this->render($order));
        $order->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * 取得要列印的 PDF 並記錄列印。檔案遺失時以訂單快照重新產生。
     */
    public function contentForPrint(Order $order, ?User $user = null, ?int $customerId = null): string
    {
        $disk = Storage::disk(self::DISK);
        if (! $order->pdf_path || ! $disk->exists($order->pdf_path)) {
            report(new \RuntimeException("訂單 {$order->order_no} 的 PDF 檔案不存在，已重新產生"));
            $this->generate($order);
        }

        OrderPrint::create([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'customer_id' => $customerId,
            'is_reprint' => $order->prints()->exists(),
        ]);

        return $disk->get($order->pdf_path);
    }

    public function renderHtml(Order $order): string
    {
        $order->loadMissing('items', 'customer');

        return view('pdf.order', [
            'order' => $order,
            'shop' => config('shop'),
        ])->render();
    }

    private function render(Order $order): string
    {
        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFonts = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => storage_path('framework/cache/mpdf'),
            'fontDir' => [...$defaultConfig['fontDir'], resource_path('fonts/NotoSansTC')],
            'fontdata' => $defaultFonts['fontdata'] + [
                'notosanstc' => ['R' => 'NotoSansTC-Regular.ttf', 'B' => 'NotoSansTC-Bold.ttf'],
            ],
            'default_font' => 'notosanstc',
            'margin_top' => 12,
            'margin_bottom' => 14,
            'margin_left' => 12,
            'margin_right' => 12,
            // 中文沒有斜體，mpdf 預設頁尾為粗斜體會以人工傾斜呈現，改為一般字
            'defaultfooterfontstyle' => '',
            'defaultfooterfontsize' => 9,
        ]);
        $mpdf->SetTitle("訂貨單 {$order->order_no}");
        $mpdf->SetFooter("{$order->order_no}｜第 {PAGENO} / {nbpg} 頁");
        $mpdf->WriteHTML($this->renderHtml($order));

        return $mpdf->Output('', 'S');
    }
}
