// 取「漂亮」的刻度上限與間距：0 / 1,000 / 2,000 …
export function niceScale(max, tickCount = 4) {
    if (max <= 0) return { max: 1, step: 1 };
    const rough = max / tickCount;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough);
    return { max: step * Math.ceil(max / step), step };
}

// 金額縮寫給軸線用：12,000 → 1.2萬
export function compactMoney(value) {
    if (value >= 10000) return `${Number((value / 10000).toFixed(value >= 100000 ? 0 : 1))}萬`;
    return value.toLocaleString('zh-TW');
}
