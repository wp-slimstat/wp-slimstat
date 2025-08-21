var slimstatClosestPoint = false;
document.addEventListener("DOMContentLoaded", function () {
    const chartElements = document.querySelectorAll('[id^="slimstat_chart_data_"]');
    const charts = new Map();

    function reinitializeCharts(id) {
        const updatedChartElements = document.querySelectorAll(`[id^="slimstat_chart_data_${id}"]`);
        updatedChartElements.forEach((element) => {
            const chartId = id;
            if (!charts.has(chartId)) {
                initializeChart(element, chartId);
                setupGranularitySelect(chartId);
            } else {
                const existingChart = charts.get(chartId);
                if (existingChart) {
                    existingChart.destroy();
                }
                initializeChart(element, chartId);
                setupGranularitySelect(chartId);
            }
        });
    }

    window.reinitializeSlimStatCharts = reinitializeCharts;

    chartElements.forEach((element) => {
        const chartId = element.id.replace("slimstat_chart_data_", "");
        initializeChart(element, chartId);
        setupGranularitySelect(chartId);
    });

    function initializeChart(element, chartId) {
        const args = JSON.parse(element.getAttribute("data-args"));
        const data = JSON.parse(element.getAttribute("data-data"));
        const prevData = JSON.parse(element.getAttribute("data-prev-data"));
        const daysBetween = parseInt(element.getAttribute("data-days-between"));
        const chartLabels = JSON.parse(element.getAttribute("data-chart-labels"));
        const translations = JSON.parse(element.getAttribute("data-translations"));
        const totals = JSON.parse(element.getAttribute("data-totals") || "{}");

        const labels = data.labels;
        const prevLabels = data.prev_labels;

        // Fix: Check for null/undefined datasets before using them
        const datasets = prepareDatasets(data.datasets, chartLabels, labels, data.today);
        let prevDatasets = prepareDatasets(prevData.datasets, chartLabels, prevData.labels, null, true);
        prevDatasets = prevDatasets.filter((ds) => Array.isArray(ds.data) && ds.data.some((v) => v > 0));

        // Fix for infinite height: set a default height if not present (for widgets or non-dashboard usage)
        const chartCanvas = document.getElementById(`slimstat_chart_${chartId}`);
        if (chartCanvas && (!chartCanvas.style.height || chartCanvas.offsetHeight > 2000)) {
            chartCanvas.style.height = "260px";
            chartCanvas.style.maxHeight = "320px";
            chartCanvas.style.minHeight = "180px";
        }
        const ctx = chartCanvas.getContext("2d");
        const chart = createChart(ctx, labels, prevLabels, datasets, prevDatasets, totals, args.granularity, data.today, translations, daysBetween, chartId);
        charts.set(chartId, chart);
        renderCustomLegend(chart, chartId, datasets, prevDatasets, totals, translations);
    }

    function setupGranularitySelect(chartId) {
        const select = document.getElementById(`slimstat_granularity_${chartId}`);
        if (!select) return;

        // Debounce the event listener to reduce server requests
        let debounceTimeout;
        select.addEventListener("change", () => {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(() => {
                const granularity = select.value;
                fetchChartData(chartId, granularity);
            }, 300); // 300ms debounce
        });
    }

    function fetchChartData(chartId, granularity) {
        const element = document.getElementById(`slimstat_chart_data_${chartId}`);
        const args = JSON.parse(element.getAttribute("data-args"));
        const inside = document.querySelector(`.inside:has(#slimstat_chart_${chartId})`);
        const loadingIndicator = document.createElement("p");
        loadingIndicator.classList.add("loading");
        const spinner = document.createElement("i");
        spinner.classList.add("slimstat-font-spin4", "animate-spin");
        loadingIndicator.appendChild(spinner);
        inside.appendChild(loadingIndicator);
        document.querySelector(`.slimstat-chart-wrap:has(#slimstat_chart_${chartId})`).style.display = "none";

        const xhr = new XMLHttpRequest();
        xhr.open("POST", slimstat_chart_vars.ajax_url, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    const result = JSON.parse(xhr.responseText);
                    if (!result.success) {
                        console.error("AJAX error:", result.data.message);
                        return;
                    }
                    element.dataset.granularity = granularity;

                    const oldToggleButtons = document.querySelectorAll(`.slimstat-postbox-chart--item-${chartId}`);
                    oldToggleButtons.forEach((button) => button.remove());

                    const { args, data, totals, prev_data, days_between, chart_labels, translations } = result.data;

                    const labels = data.labels;
                    const datasets = prepareDatasets(data.datasets, chart_labels, labels, data.today);
                    const prevDatasets = prepareDatasets(prev_data.datasets, chart_labels, prev_data.labels, null, true);

                    // Destroy previous chart and create a new one to ensure correct tick callback
                    const chartCanvas = document.getElementById(`slimstat_chart_${chartId}`);
                    const prevChart = charts.get(chartId);
                    if (prevChart) prevChart.destroy();
                    const ctx = chartCanvas.getContext("2d");
                    const chart = createChart(ctx, labels, data.prev_labels, datasets, prevDatasets, totals, granularity, data.today, translations, days_between, chartId);
                    charts.set(chartId, chart);

                    renderCustomLegend(chart, chartId, datasets, prevDatasets, totals, translations);

                    element.dataset.args = JSON.stringify(args);
                    element.dataset.data = JSON.stringify(data);
                    element.dataset.prevData = JSON.stringify(prev_data);
                    element.dataset.daysBetween = days_between;
                    element.dataset.chartLabels = JSON.stringify(chart_labels);
                    element.dataset.translations = JSON.stringify(translations);

                    inside.removeChild(loadingIndicator);
                    document.querySelector(`.slimstat-chart-wrap:has(#slimstat_chart_${chartId})`).style.display = "block";
                } else {
                    console.error("XHR error:", xhr.statusText);
                }
            }
        };
        xhr.send(
            new URLSearchParams({
                action: "slimstat_fetch_chart_data",
                nonce: slimstat_chart_vars.nonce,
                args: JSON.stringify(args),
                granularity: granularity,
            }).toString()
        );
    }

    function prepareDatasets(rawDatasets, chartLabels, labels, today, isPrevious = false) {
        if (rawDatasets === undefined || rawDatasets === null) {
            return [];
        }

        const colors = ["#e8294c", "#2b76f6", "#ffacb6", "#24cb7d", "#942bf6"];
        return Object.entries(rawDatasets).map(([key, values], i) => {
            // Remove negative keys and ensure array length matches labels
            if (!Array.isArray(values)) {
                values = Object.entries(values)
                    .filter(([k, v]) => !isNaN(k) && Number(k) >= 0)
                    .sort((a, b) => Number(a[0]) - Number(b[0]))
                    .map(([k, v]) => v);
            }
            if (Array.isArray(values)) {
                values = values.slice(0, labels.length);
            }

            let labelText = key;
            if (Array.isArray(chartLabels) && typeof chartLabels[i] !== "undefined" && chartLabels[i] !== null) {
                labelText = chartLabels[i];
            }

            return {
                label: isPrevious ? `Previous ${labelText}` : labelText,
                key: key,
                data: values,
                borderColor: colors[i % colors.length],
                borderWidth: isPrevious ? 1 : 2,
                fill: false,
                tension: 0.3,
                pointBorderColor: "transparent",
                pointBackgroundColor: colors[i % colors.length],
                pointBorderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointHoverBorderWidth: 2,
                hitRadius: 10,
                pointHitRadius: 10,
                segment: {
                    borderDash: isPrevious ? () => [3, 3] : (ctx) => (labels[ctx.p1DataIndex] === `'${today}'` ? [5, 3] : []),
                },
            };
        });
    }

    function createChart(ctx, labels, prevLabels, datasets, prevDatasets, total, unitTime, today, translations, daysBetween, chartId) {
        const isRTL = document.documentElement.dir === "rtl" || document.body.classList.contains("rtl");

        const customCrosshair = {
            id: "customCrosshair",
            afterEvent(chart, args) {
                chart._lastEvent = args.event;
            },
            afterDraw(chart) {
                const event = chart._lastEvent;
                if (!event) return;

                const ctx = chart.ctx;
                const { top, bottom } = chart.chartArea;
                const activePoints = chart.getElementsAtEventForMode(event.native || event, "nearest", { intersect: false }, false);
                if (!activePoints || !activePoints.length) return;
                const pt = activePoints[0].element;
                if (!pt || typeof pt.x !== "number") return;
                ctx.save();
                ctx.lineWidth = 1;
                ctx.setLineDash([2, 2]);
                ctx.strokeStyle = "rgba(0, 0, 0, 0.3)";
                ctx.beginPath();
                ctx.moveTo(pt.x, top);
                ctx.lineTo(pt.x, chart.chartArea.bottom);
                ctx.stroke();
                ctx.restore();
            },
        };

        var maxTicks = 8;
        var uniqueTickIndexes = [];
        var xTickRotation = 0;
        var xAutoSkip = false;

        if (["daily", "monthly", "hourly", "weekly"].includes(unitTime)) {
            if (unitTime === "weekly") {
                maxTicks = 8;
            }
            if (unitTime === "monthly") {
                maxTicks = 6;
            }

            if (labels.length % 2 !== 0) {
                maxTicks += 1;
            }

            if (jQuery("body").hasClass("index-php")) {
                maxTicks = 5;
            }

            if (labels.length > maxTicks) {
                var tickIndexes = [0];
                var step = (labels.length - 1) / (maxTicks - 1);
                for (var i = 1; i < maxTicks - 1; i++) {
                    var idx = Math.round(i * step);
                    if (!tickIndexes.includes(idx)) {
                        tickIndexes.push(idx);
                    } else {
                        var nextIdx = idx + 1;
                        while (nextIdx < labels.length - 1 && tickIndexes.includes(nextIdx)) {
                            nextIdx++;
                        }
                        if (nextIdx < labels.length - 1) {
                            tickIndexes.push(nextIdx);
                        }
                    }
                }
                tickIndexes.push(labels.length - 1);
                uniqueTickIndexes = Array.from(new Set(tickIndexes)).sort((a, b) => a - b);
                if (uniqueTickIndexes.length > maxTicks) {
                    var toRemove = uniqueTickIndexes.length - maxTicks;
                    var middle = Math.floor(uniqueTickIndexes.length / 2);
                    uniqueTickIndexes.splice(middle, toRemove);
                }
            }
        } else {
            uniqueTickIndexes = Array.from(Array(labels.length).keys());
        }

        var minLabelSpacingPx = 60;
        var chartWidth = ctx.canvas.offsetWidth || ctx.canvas.width;
        var tickCount = labels.length <= maxTicks ? labels.length : uniqueTickIndexes.length;
        var approxSpacing = chartWidth / (tickCount - 1);

        if (approxSpacing > minLabelSpacingPx) {
            xTickRotation = 0;
        } else if (window.innerWidth < 600) {
            xTickRotation = 35;
        } else if (unitTime === "weekly") {
            xTickRotation = 0;
        }

        function customTickCallback(value, index, values) {
            if (labels.length <= maxTicks || uniqueTickIndexes.includes(index)) {
                var label = this.getLabelForValue(value).replace(/'/g, "");
                return slimstatGetLabel(label, false, unitTime, translations);
            }
            return "";
        }

        return new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [...datasets, ...prevDatasets],
            },
            options: {
                layout: { padding: 20 },
                locale: "en-US",
                direction: isRTL ? "rtl" : "ltr",
                plugins: {
                    legend: {
                        display: false,
                        rtl: isRTL,
                        textDirection: isRTL ? "rtl" : "ltr",
                        labels: {
                            textAlign: isRTL ? "right" : "left",
                            font: {
                                family: "Open Sans, sans-serif",
                            },
                            color: "#222",
                        },
                    },
                    tooltip: {
                        enabled: false,
                        external: createTooltip(labels, prevLabels, translations, daysBetween, chartId),
                        rtl: isRTL,
                        textDirection: isRTL ? "rtl" : "ltr",
                        bodyAlign: isRTL ? "right" : "left",
                        titleAlign: isRTL ? "right" : "left",
                        footerAlign: isRTL ? "right" : "left",
                        backgroundColor: "#fff",
                        borderColor: "#e0e0e0",
                        borderWidth: 1,
                        titleFont: {
                            family: "Open Sans, sans-serif",
                        },
                        bodyFont: {
                            family: "Open Sans, sans-serif",
                        },
                        titleColor: "#222",
                        bodyColor: "#222",
                    },
                },
                scales: {
                    x: {
                        reverse: isRTL,
                        ticks: {
                            callback: customTickCallback,
                            minRotation: 0,
                            maxRotation: xTickRotation,
                            autoSkip: xAutoSkip,
                            maxTicksLimit: labels.length,
                            align: "center",
                            font: {
                                family: "Open Sans, sans-serif",
                            },
                            color: "#222",
                        },
                        grid: {
                            display: true,
                        },
                    },
                    y: {
                        ticks: {
                            font: {
                                family: "Open Sans, sans-serif",
                            },
                            color: "#222",
                        },
                        grid: {
                            display: false,
                        },
                    },
                },
                maintainAspectRatio: false,
                responsive: true,
                animations: {
                    x: {
                        duration: 250,
                        easing: "easeOutCubic",
                    },
                },
                interaction: {
                    intersect: false,
                    mode: "index",
                },
            },
            plugins: [customCrosshair],
        });
    }

    function renderCustomLegend(chart, chartId, currentDatasets, previousDatasets, totals, translations) {
        const legendContainer = document.getElementById(`slimstat-postbox-custom-legend_${chartId}`);
        legendContainer.innerHTML = "";
        chart.data.datasets.forEach((dataset, index) => {
            const isPrevious = dataset.label.includes("Previous");
            if (isPrevious) return;

            const key = dataset.key;
            const currentValue = totals.current[key] ?? 0;
            const previousValue = totals.previous[key] ?? 0;

            const legendItem = document.createElement("div");
            legendItem.className = "slimstat-postbox-chart--item";

            // Current value and optional previous comparison
            legendItem.innerHTML = `
            <span class="slimstat-postbox-chart--item-label">${dataset.label}</span>
            <span class="slimstat-postbox-chart--item--color" style="background-color: ${dataset.borderColor}"></span>
            <span class="slimstat-postbox-chart--item-value">${currentValue.toLocaleString()}</span>
            ${
                previousValue && previousValue !== currentValue
                    ? `
                <span class="slimstat-postbox-chart--item--color" style="background-image: repeating-linear-gradient(to right, ${dataset.borderColor}, ${dataset.borderColor} 4px, transparent 0px, transparent 6px); background-size: auto 6px; height: 2px; margin-bottom: 0px; margin-left: 10px;"></span>
                <span class="slimstat-postbox-chart--item-value">${previousValue.toLocaleString()}</span>
                `
                    : ""
            }
        `;

            // Click toggle for current dataset + corresponding previous
            legendItem.addEventListener("click", () => {
                const currentMeta = chart.getDatasetMeta(index);
                const previousMeta = chart.getDatasetMeta(index + currentDatasets.length);
                const togglePrevBtn = document.querySelector(`.slimstat-toggle-prev-datasets.slimstat-postbox-chart--item-${chartId}`);
                const showPrevious = togglePrevBtn?.classList.contains("active");

                currentMeta.hidden = !currentMeta.hidden;
                legendItem.classList.toggle("slimstat-postbox-chart--item-hidden");

                if (showPrevious) {
                    previousMeta.hidden = currentMeta.hidden;
                } else {
                    previousMeta.hidden = true;
                }

                chart.update();
            });

            legendContainer.appendChild(legendItem);
        });

        // Check if previous data is available
        const hasPreviousData = previousDatasets.length > 0 && previousDatasets.some((ds) => ds.data.some((value) => value > 0));
        if (!hasPreviousData) return;

        // Remove existing toggle buttons
        document.querySelectorAll(`#slimstat-postbox-custom-legend_${chartId} .slimstat-toggle-prev-datasets`).forEach((btn) => btn.remove());

        const togglePrevBtn = document.createElement("span");
        togglePrevBtn.innerText = translations.previous_period;
        togglePrevBtn.className = `active slimstat-toggle-prev-datasets slimstat-postbox-chart--item-${chartId} more-info-icon slimstat-tooltip-trigger corner`;
        togglePrevBtn.title = translations.previous_period_tooltip || "Tap here to show/hide comparison.";

        // Tooltip
        if (!togglePrevBtn.querySelector(".slimstat-tooltip-content")) {
            const tooltip = document.createElement("span");
            tooltip.className = "slimstat-tooltip-content";
            tooltip.innerHTML = `<strong>${translations.previous_period}</strong><br>${translations.previous_period_tooltip || "Tap here to show/hide comparison."}`;
            togglePrevBtn.appendChild(tooltip);
        }

        let previousVisible = true;

        togglePrevBtn.addEventListener("click", () => {
            previousVisible = !previousVisible;

            chart.data.datasets.forEach((dataset, index) => {
                if (dataset.label.includes("Previous")) {
                    const correspondingCurrentMeta = chart.getDatasetMeta(index - currentDatasets.length);
                    chart.getDatasetMeta(index).hidden = previousVisible ? correspondingCurrentMeta.hidden : true;
                }
            });

            togglePrevBtn.classList.toggle("active");
            chart.update();
        });

        // Insert the toggle button before the legend
        legendContainer.parentNode.insertBefore(togglePrevBtn, legendContainer);
    }

    function getEndOfWeek(dateInput, startOfWeek = 1, respectEndOfPeriod = false) {
        const date = new Date(dateInput);
        const day = date.getDay();
        const diff = (7 - startOfWeek + day) % 7;
        const nextWeek = new Date(date.getTime() + (7 - diff) * 24 * 60 * 60 * 1000);
        if (respectEndOfPeriod) {
            const today = new Date(respectEndOfPeriod);

            if (nextWeek.getTime() > today.getTime()) {
                return new Date(respectEndOfPeriod);
            }
        }
        return nextWeek;
    }

    function slimstatGetLabel(label, long = true, unitTime, translations, justTranslation = false) {
        label = label.replace(/'/g, "");

        const now = new Date();
        const getMonthYear = (date) => ({
            month: date.toLocaleString("default", { month: "long" }),
            year: date.getFullYear(),
        });

        const formatDate = (date, opts = {}) => date.toLocaleDateString("default", { timeZone: "UTC", ...opts });
        const formatDateTime = (date, opts = {}) => date.toLocaleString("default", { timeZone: "UTC", ...opts });

        if (unitTime === "monthly") {
            const labelToParse = justTranslation || label;
            const date = new Date(`${labelToParse} 1`);
            const { month, year } = getMonthYear(date);
            const isThisMonth = now.getMonth() === date.getMonth() && now.getFullYear() === year;
            const baseLabel = `${month}, ${year}`;
            const extra = isThisMonth ? ` (${translations.now})` : "";
            const formattedLabel = `${label} <span class="slimstat-postbox-chart--item--prev">${baseLabel}</span>`;

            return justTranslation ? formattedLabel : long && isThisMonth ? baseLabel + extra : baseLabel;
        } else if (unitTime === "weekly") {
            const rawDate = (justTranslation || label).replace(/\//g, "-");
            const date = new Date(rawDate + "T00:00:00Z");
            const weekEnd = getEndOfWeek(rawDate + "T00:00:00Z", slimstat_chart_vars.start_of_week, slimstat_chart_vars.end_date_string.replace(/\//g, "-").replace(" ", "T") + "Z");
            const weekStart = formatDate(date, { month: long ? "long" : "short", day: "numeric" });
            const weekEndFormatted = formatDate(weekEnd, { month: long ? "long" : "short", day: "numeric" });

            return weekEndFormatted === weekStart ? weekStart : weekStart + " - " + weekEndFormatted;
        } else if (unitTime === "daily") {
            const rawDate = (justTranslation || label).replace(/\//g, "-");
            const date = new Date(rawDate + "T00:00:00Z");
            const isToday = new Date().toISOString().slice(0, 10) === rawDate;
            const longFormat = formatDateTime(date, {
                weekday: "long",
                month: "long",
                day: "numeric",
                year: "numeric",
            });
            const shortFormat = formatDate(date, {
                month: "short",
                day: "2-digit",
            }).replaceAll("-", "/");
            const finalFormatted = long ? longFormat : shortFormat;
            const formattedLabel = `${label} <span class="slimstat-postbox-chart--item--prev">${finalFormatted}</span>`;

            return justTranslation ? (long && isToday ? `${formattedLabel} (${translations.today})` : formattedLabel) : long && isToday ? `${finalFormatted} (${translations.today})` : finalFormatted;
        } else if (unitTime === "hourly") {
            const rawDate = (justTranslation || label).replace(/\//g, "-").replace(" ", "T") + "Z"; // ISO UTC
            if (!rawDate) return label; // Handle empty label case
            const date = new Date(rawDate);
            const hour = date.getUTCHours();
            const minutes = date.getUTCMinutes().toString().padStart(2, "0");
            const datePart = long ? formatDateTime(date, { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : formatDate(date, { month: "short", day: "2-digit" }).replaceAll("-", "/");
            const isSameHour = new Date().toISOString().slice(0, 10) === rawDate && new Date().getUTCHours() === date.getUTCHours();
            const nowStr = new Date().toLocaleTimeString("default", { hour: "numeric", minute: "2-digit", hourCycle: "h23" });
            const labelStr = long ? (isSameHour ? `${datePart} ${hour}:${minutes}-${nowStr} (${translations.now})` : `${datePart} ${hour}:${minutes}-${hour + 1}:${minutes}`) : `${datePart} ${hour}:${minutes}`;
            const formattedLabel = `${label} <span class="slimstat-postbox-chart--item--prev">${datePart} ${hour}:${minutes}</span>`;

            return justTranslation ? formattedLabel : labelStr;
        } else if (unitTime === "yearly") {
            const date = new Date(justTranslation || label);
            const year = date.getFullYear();
            const isThisYear = now.getFullYear() === year;
            const formattedLabel = `${label} <span class="slimstat-postbox-chart--item--prev">${year}</span>`;

            return justTranslation ? formattedLabel : isThisYear && long ? `${year} (${translations.this_year || "This Year"})` : `${year}`;
        }

        return label;
    }

    function createTooltip(labels, prevLabels, translations, daysBetween, chartId) {
        return function (context) {
            var unitTime = document.getElementById(`slimstat_chart_data_${chartId}`).dataset.granularity;
            var data = JSON.parse(document.getElementById(`slimstat_chart_data_${chartId}`).getAttribute("data-data"));
            prevLabels = data.prev_labels;
            let tooltipEl = document.getElementById("chartjs-tooltip");
            if (!tooltipEl) {
                tooltipEl = document.createElement("div");
                tooltipEl.id = "chartjs-tooltip";
                tooltipEl.innerHTML = "<table></table>";
                document.body.appendChild(tooltipEl);
            }

            const { chart, tooltip } = context;

            if (tooltip.opacity === 0) {
                tooltipEl.style.opacity = 0;
                return;
            }

            tooltipEl.classList.remove("above", "below", "no-transform");
            if (tooltip.yAlign) tooltipEl.classList.add(tooltip.yAlign);
            else tooltipEl.classList.add("no-transform");

            const titleLines = tooltip.title || [];

            let grouped = [];
            tooltip.dataPoints.forEach((dp, i) => {
                const label = tooltip.body[i].lines[0].split(": ")[0];
                const value = tooltip.body[i].lines[0].split(": ")[1];
                if (label.startsWith("Previous ")) return;
                let prevValue = null,
                    prevDate = null;
                for (let j = 0; j < tooltip.dataPoints.length; j++) {
                    const prevLabel = tooltip.body[j].lines[0].split(": ")[0];
                    if (prevLabel === `Previous ${label}`) {
                        prevValue = tooltip.body[j].lines[0].split(": ")[1];
                        prevDate = prevLabels[tooltip.dataPoints[j].dataIndex];
                        break;
                    }
                }
                grouped.push({ label, value, prevValue, prevDate });
            });

            let innerHtml = "<thead>";
            titleLines.forEach((title) => {
                innerHtml += `<tr><th style="font-weight: bold; font-size: 14px; padding-bottom: 6px; text-align: left;">${slimstatGetLabel(title.replace(/'/g, ""), true, unitTime, translations)}</th></tr>`;
            });
            innerHtml += "</thead><tbody>";

            grouped.forEach((item, idx) => {
                const color = tooltip.labelColors[idx];
                innerHtml += `<tr data-index="${idx}" class="slimstat-postbox-chart--item"><td><div class="slimstat-postbox-chart--item--color" style="background-color: ${color.backgroundColor}; margin-bottom: 3px; margin-right: 10px;"></div><span class="tooltip-item-title">${item.label}</span>: <span class="tooltip-item-content">${item.value}</span>`;
                if (item.prevValue !== null && item.prevDate) {
                    innerHtml += `<br><span class=\"slimstat-postbox-chart--item--color\" style=\"display:inline-block;width:18px;height:2px;background-image:repeating-linear-gradient(to right, rgba(${color.backgroundColor
                        .replace("#", "")
                        .match(/.{1,2}/g)
                        .map((x) => parseInt(x, 16))
                        .join(",")},0.7), rgba(${color.backgroundColor
                        .replace("#", "")
                        .match(/.{1,2}/g)
                        .map((x) => parseInt(x, 16))
                        .join(",")},0.7) 4px, transparent 0px, transparent 6px);background-size:auto 6px;opacity:1;margin-bottom:0px;margin-left:0px;vertical-align:middle;\"></span> <span class=\"tooltip-item-title\" style=\"font-size:12px;opacity:.7;\">${slimstatGetLabel(item.prevDate, false, unitTime, translations)}: </span><span class=\"tooltip-item-content\" style=\"font-size:12px;opacity:.7;\">${item.prevValue}</span>`;
                }
                innerHtml += `</td></tr>`;
            });
            innerHtml += "</tbody>";
            innerHtml += `<div class="align-indicator" style="
                width: 15px;
                height: 15px;
                background-color: #fff;
                border-bottom-left-radius: 5px;
                display: inline-block;
                position: absolute;
                bottom: -8px;
                border-bottom: solid 1px #e0e0e0;
                border-left: solid 1px #e0e0e0;
                transform: rotate(-45deg);
                transition: left 0.1s ease;
            "></div>`;

            tooltipEl.querySelector("table").innerHTML = innerHtml;

            const chartRect = chart.canvas.getBoundingClientRect();
            const tooltipWidth = tooltipEl.offsetWidth;
            const tooltipHeight = tooltipEl.offsetHeight;
            let left = chartRect.left + window.pageXOffset + tooltip.caretX - tooltipWidth / 2;
            const dataPointYs = tooltip.dataPoints.map((dp) => dp.element.y);
            const highestY = Math.min(...dataPointYs);
            let top = chartRect.top + window.pageYOffset + highestY - tooltipHeight - 24;

            const chartLeft = chartRect.left + window.pageXOffset;
            const chartRight = chartRect.right + window.pageXOffset;
            if (left < chartLeft + 4) {
                left = chartLeft + 4;
            }
            if (left + tooltipWidth > chartRight - 4) {
                left = chartRight - tooltipWidth - 4;
            }
            tooltipEl.style.opacity = 1;
            tooltipEl.style.position = "absolute";
            tooltipEl.style.left = `${left}px`;
            tooltipEl.style.top = `${top}px`;

            const alignIndicator = tooltipEl.querySelector(".align-indicator");
            if (alignIndicator) {
                const indicatorWidth = alignIndicator.offsetWidth;
                const tooltipLeft = left;
                const mouseX = chartRect.left + window.pageXOffset + tooltip.caretX;
                let indicatorLeft = mouseX - tooltipLeft - indicatorWidth / 2;
                const minLeft = 4;
                const maxLeft = tooltipWidth - indicatorWidth - 4;
                indicatorLeft = Math.max(minLeft, Math.min(indicatorLeft, maxLeft));
                alignIndicator.style.left = `${indicatorLeft}px`;
            }
        };
    }
});
