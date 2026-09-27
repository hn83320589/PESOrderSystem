<script setup>
// 直條圖（可分組）。單一數值軸；兩個以上數列時顯示圖例並直接標示；
// 每組類別是一個滑鼠/鍵盤焦點目標，提示框列出該組所有數列。
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { compactMoney, niceScale } from './chartScale';

const props = defineProps({
    title: { type: String, required: true },
    categories: { type: Array, required: true }, // 顯示用 X 標籤
    series: { type: Array, required: true }, // [{ name, color, values: [] }]
    format: { type: Function, default: (v) => v.toLocaleString('zh-TW') },
});

// 以容器實際寬度作為 SVG 座標寬度（1 單位 = 1px），文字與高度不隨螢幕寬度縮放
const container = ref(null);
const width = ref(720);
let observer;
onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(320, Math.round(entry.contentRect.width));
    });
    observer.observe(container.value);
});
onBeforeUnmount(() => observer?.disconnect());

const plotHeight = 220;
const margin = { top: 22, right: 8, bottom: 28, left: 52 };
const barWidthMax = 24;
const gap = 2;

const scale = computed(() => niceScale(Math.max(0, ...props.series.flatMap((s) => s.values))));
const ticks = computed(() => Array.from({ length: Math.round(scale.value.max / scale.value.step) + 1 }, (_, i) => i * scale.value.step));
const band = computed(() => (width.value - margin.left - margin.right) / props.categories.length);
const barWidth = computed(() => Math.min(barWidthMax, (band.value * 0.6 - gap * (props.series.length - 1)) / props.series.length));
// 空間不足時：終點直接標示改由圖例＋提示框承擔、月份標籤隔一個顯示，避免文字重疊
const showDirectLabels = computed(() => band.value >= 64);
const labelEvery = computed(() => (band.value < 36 ? 2 : 1));
const y = (v) => margin.top + plotHeight - (v / scale.value.max) * plotHeight;
const groupX = (i) => margin.left + band.value * i + (band.value - (barWidth.value * props.series.length + gap * (props.series.length - 1))) / 2;

// 上方圓角 4px、底部直角，從基線長出
function barPath(x, value) {
    const top = y(value);
    const bottom = y(0);
    const h = bottom - top;
    if (h <= 0) return '';
    const r = Math.min(4, h, barWidth.value / 2);
    const w = barWidth.value;
    return `M${x},${bottom}V${top + r}Q${x},${top} ${x + r},${top}H${x + w - r}Q${x + w},${top} ${x + w},${top + r}V${bottom}Z`;
}

const active = ref(null);
// 提示框放在該組的外側：右半邊的組往左放、左半邊往右放，不遮住相鄰資料
const tooltipStyle = computed(() => {
    if (active.value === null) return {};
    const left = margin.left + band.value * active.value;
    const right = left + band.value;
    return right > width.value / 2 ? { right: `${width.value - left + 4}px` } : { left: `${right + 4}px` };
});
const showTable = ref(false);
</script>

<template>
    <figure class="rounded bg-white p-4 shadow">
        <div class="mb-2 flex flex-wrap items-center gap-4">
            <figcaption class="font-bold">{{ title }}</figcaption>
            <ul v-if="series.length > 1" class="flex gap-4 text-sm text-gray-600" aria-label="圖例">
                <li v-for="s in series" :key="s.name" class="flex items-center gap-1.5">
                    <span class="inline-block h-3 w-3 rounded-sm" :style="{ background: s.color }"></span>{{ s.name }}
                </li>
            </ul>
            <button class="ml-auto text-sm text-blue-700 hover:underline" :aria-pressed="showTable" @click="showTable = !showTable">
                {{ showTable ? '看圖表' : '表格檢視' }}
            </button>
        </div>

        <div v-show="!showTable" ref="container" class="relative">
            <svg :viewBox="`0 0 ${width} ${plotHeight + margin.top + margin.bottom}`" class="w-full" role="img" :aria-label="title">
                <g class="text-[11px]" fill="#6b7280">
                    <template v-for="t in ticks" :key="t">
                        <line :x1="margin.left" :x2="width - margin.right" :y1="y(t)" :y2="y(t)" :stroke="t === 0 ? '#c3c2b7' : '#e5e7eb'" stroke-width="1" />
                        <text :x="margin.left - 6" :y="y(t) + 4" text-anchor="end" style="font-variant-numeric: tabular-nums">{{ compactMoney(t) }}</text>
                    </template>
                    <template v-for="(c, i) in categories" :key="c">
                        <text v-if="(categories.length - 1 - i) % labelEvery === 0" :x="margin.left + band * i + band / 2" :y="plotHeight + margin.top + 18" text-anchor="middle">{{ c }}</text>
                    </template>
                </g>
                <g v-for="(c, i) in categories" :key="c" tabindex="0" class="outline-none" :aria-label="`${c}：${series.map((s) => `${s.name} ${format(s.values[i])}`).join('，')}`"
                    @pointerenter="active = i" @pointerleave="active = null" @focus="active = i" @blur="active = null">
                    <rect :x="margin.left + band * i" :y="margin.top" :width="band" :height="plotHeight" :fill="active === i ? '#f3f4f6' : 'transparent'" />
                    <path v-for="(s, k) in series" :key="s.name" :d="barPath(groupX(i) + k * (barWidth + gap), s.values[i])" :fill="s.color" />
                </g>
                <!-- 直接標示只標最新一期（終點）；其餘數值見提示框與表格檢視 -->
                <g v-if="showDirectLabels" class="text-[11px]" fill="#374151" style="font-variant-numeric: tabular-nums">
                    <text v-for="(s, k) in series" :key="s.name" :x="groupX(categories.length - 1) + k * (barWidth + gap) + barWidth / 2"
                        :y="y(s.values[categories.length - 1]) - 5" text-anchor="middle">{{ compactMoney(s.values[categories.length - 1]) }}</text>
                </g>
            </svg>
            <div v-if="active !== null" class="pointer-events-none absolute top-2 rounded border bg-white px-3 py-2 text-sm shadow"
                :style="tooltipStyle">
                <p class="mb-1 text-gray-500">{{ categories[active] }}</p>
                <p v-for="s in series" :key="s.name" class="flex items-center gap-2">
                    <span class="inline-block h-0.5 w-3" :style="{ background: s.color }"></span>
                    <strong class="tabular-nums">{{ format(s.values[active]) }}</strong><span class="text-gray-500">{{ s.name }}</span>
                </p>
            </div>
        </div>

        <table v-if="showTable" class="table">
            <thead><tr><th></th><th v-for="s in series" :key="s.name" class="text-right">{{ s.name }}</th></tr></thead>
            <tbody>
                <tr v-for="(c, i) in categories" :key="c">
                    <td>{{ c }}</td>
                    <td v-for="s in series" :key="s.name" class="text-right tabular-nums">{{ format(s.values[i]) }}</td>
                </tr>
            </tbody>
        </table>
    </figure>
</template>
