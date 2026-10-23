// Server-rendered pages describe their charts in data attributes; this draws them.
// <div data-chart="bars" data-chart-data='{"labels":[...],"values":[...]}'></div>
const money = (v) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', notation: 'compact', maximumFractionDigits: 1 }).format(v);

export function mountCharts(root) {
    if (!window.Charts) return;

    root.querySelectorAll('[data-chart]').forEach((el) => {
        if (el.dataset.chartMounted) return;
        el.dataset.chartMounted = '1';

        const kind = el.dataset.chart;
        const data = JSON.parse(el.dataset.chartData || '{}');
        const format = data.format === 'money' ? money : window.Charts.fmt.int;

        if (kind === 'bars') {
            window.Charts.bars(el, { ...data, format });
        } else if (kind === 'bar-list') {
            window.Charts.barList(el, data.rows, { format, thin: true });
        } else if (kind === 'plan') {
            window.Charts.plan(el, data.rows, { format, scale: 'row' });
        }
    });
}
