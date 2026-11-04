/** Form reports reuse SlimStat's chart library and server-authorized aggregates. @license GPL-2.0-or-later */
(function () {
    "use strict";
    if (!window.Chart) return;
    function init() {
    document.querySelectorAll("[data-forms-trend]").forEach(function (figure) {
        var canvas = figure.querySelector("canvas");
        if (Chart.getChart(canvas)) return;
        var data;
        try { data = JSON.parse(figure.dataset.formsTrend); } catch (ignore) { return; }
        var colors = getComputedStyle(figure);
        var primary = colors.getPropertyValue("--ss-chart-1").trim();
        var compare = colors.getPropertyValue("--ss-chart-compare").trim();
        var text = colors.getPropertyValue("--ss-text-muted").trim();
        figure.querySelector("[data-forms-chart]").hidden = false;
        var chart = new Chart(figure.querySelector("canvas"), {
            type: "bar",
            data: {
                labels: data.labels,
                datasets: [
                    { label: data.completedLabel, data: data.completed, backgroundColor: primary, borderRadius: 3, maxBarThickness: 28 },
                    { label: data.seenLabel, data: data.seen, type: "line", borderColor: compare, backgroundColor: compare, borderDash: [5, 4], borderWidth: 2, pointRadius: 3, pointHoverRadius: 5, tension: 0, spanGaps: false }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { intersect: false, mode: "index" },
                plugins: { legend: { display: false }, tooltip: { padding: 12 } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: text, maxTicksLimit: 6, maxRotation: 0 }, border: { display: false } },
                    y: { beginAtZero: true, ticks: { color: text, precision: 0, maxTicksLimit: 5 }, grid: { color: colors.getPropertyValue("--ss-border-soft").trim() }, border: { display: false } }
                }
            }
        });
        figure.querySelectorAll("[data-forms-series]").forEach(function (input) {
            input.addEventListener("change", function () {
                chart.setDatasetVisibility(Number(input.dataset.formsSeries), input.checked);
                chart.update("none");
            });
        });
    });
    }
    window.SlimStatFormsReport = { init: init, destroy: function () {
        document.querySelectorAll(".ss-forms canvas").forEach(function (canvas) {
            var chart = Chart.getChart(canvas);
            if (chart) chart.destroy();
        });
    } };
    init();
}());
