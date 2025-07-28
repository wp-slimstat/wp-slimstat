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

        console.log(data);

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
        const chart = createChart(ctx, labels, prevLabels, datasets, prevDatasets, args.granularity, data.today, translations, daysBetween, chartId);
        charts.set(chartId, chart);

        renderCustomLegend(chart, chartId, datasets, prevDatasets, labels, data.today, translations);
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

                    const { args, data, prev_data, days_between, chart_labels, translations } = result.data;
                    const labels = data.labels;
                    const datasets = prepareDatasets(data.datasets, chart_labels, labels, data.today);
                    const prevDatasets = prepareDatasets(prev_data.datasets, chart_labels, prev_data.labels, null, true);

                    // Destroy previous chart and create a new one to ensure correct tick callback
                    const chartCanvas = document.getElementById(`slimstat_chart_${chartId}`);
                    const prevChart = charts.get(chartId);
                    if (prevChart) prevChart.destroy();
                    const ctx = chartCanvas.getContext("2d");
                    const chart = createChart(ctx, labels, data.prev_labels, datasets, prevDatasets, granularity, data.today, translations, days_between, chartId);
                    charts.set(chartId, chart);

                    renderCustomLegend(chart, chartId, datasets, prevDatasets, labels, data.today, translations);

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
            if (!Array.isArray(values)) {
                values = Object.values(values);
            }

            // Fix: Safely access chartLabels[i]
            let labelText = key;
            if (Array.isArray(chartLabels) && typeof chartLabels[i] !== "undefined" && chartLabels[i] !== null) {
                labelText = chartLabels[i];
            }

            return {
                label: isPrevious ? `Previous ${labelText}` : labelText,
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

    function createChart(ctx, labels, prevLabels, datasets, prevDatasets, unitTime, today, translations, daysBetween, chartId) {
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
                maxTicks = 6;
                xAutoSkip = true;
            }
            if (unitTime === "monthly") {
                maxTicks = 6;
                xAutoSkip = true;
            }

            if (labels.length % 2 !== 0) {
                maxTicks += 1;
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
            xTickRotation = 20;
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

    function renderCustomLegend(chart, chartId, datasets, prevDatasets, labels, today, translations) {
        const legendContainer = document.getElementById(`slimstat-postbox-custom-legend_${chartId}`);
        legendContainer.innerHTML = "";

        chart.data.datasets.forEach((dataset, index) => {
            if (!dataset.label.includes("Previous")) {
                const datasetData = datasets[index]?.data;
                let value = 0,
                    prevValue = 0;

                datasetData.forEach((data, i) => {
                    value += data;
                });

                if (prevDatasets[index]) {
                    prevDatasets[index].data.forEach((data) => {
                        prevValue += data;
                    });
                }

                const legendItem = document.createElement("div");
                legendItem.classList.add("slimstat-postbox-chart--item");

                legendItem.innerHTML = `
                    <span class="slimstat-postbox-chart--item-label">${dataset.label}</span>
                    <span class="slimstat-postbox-chart--item--color" style="background-color: ${dataset.borderColor}"></span>
                    <span class="slimstat-postbox-chart--item-value">${value.toLocaleString()}</span>
                    ${
                        prevValue && prevValue !== value
                            ? `
                        <span class="slimstat-postbox-chart--item--color" style="background-image: repeating-linear-gradient(to right, ${dataset.borderColor}, ${dataset.borderColor} 4px, transparent 0px, transparent 6px); background-size: auto 6px; height: 2px; margin-bottom: 0px; margin-left: 10px;"></span>
                        <span class="slimstat-postbox-chart--item-value">${prevValue.toLocaleString()}</span>
                    `
                            : ""
                    }
                `;

                legendItem.addEventListener("click", () => {
                    const meta = chart.getDatasetMeta(index);
                    meta.hidden = !meta.hidden;
                    legendItem.classList.toggle("slimstat-postbox-chart--item-hidden");
                    const prevIndex = index + datasets.length;
                    const prevMeta = chart.getDatasetMeta(prevIndex);
                    const prevToggleBtn = document.querySelector(`.slimstat-toggle-prev-datasets.slimstat-postbox-chart--item-${chartId}`);
                    const prevActive = prevToggleBtn && prevToggleBtn.classList.contains("active");
                    if (prevActive) {
                        prevMeta.hidden = meta.hidden;
                    } else {
                        prevMeta.hidden = true;
                    }
                    chart.update();
                });

                legendContainer.appendChild(legendItem);
            }
        });

        const hasPrevData = prevDatasets.length > 0 && prevDatasets.some((dataset) => dataset.data.some((value) => value > 0));

        if (hasPrevData) {
            const oldToggleButtons = document.querySelectorAll("#slimstat-postbox-custom-legend_" + chartId + " .slimstat-toggle-prev-datasets");
            oldToggleButtons.forEach(function (button) {
                button.remove();
            });
            var toggleButton = document.createElement("span");
            toggleButton.innerText = translations.previous_period;
            toggleButton.classList.add("active", "slimstat-toggle-prev-datasets", "slimstat-postbox-chart--item-" + chartId, "more-info-icon", "slimstat-tooltip-trigger", "corner");
            toggleButton.title = translations.previous_period_tooltip || "Tap here to show/hide comparison.";
            if (!toggleButton.querySelector(".slimstat-tooltip-content")) {
                var tooltipContent = document.createElement("span");
                tooltipContent.className = "slimstat-tooltip-content";
                tooltipContent.innerHTML = "<strong>" + translations.previous_period + "</strong><br>" + (translations.previous_period_tooltip || "Tap here to show/hide comparison.");
                toggleButton.appendChild(tooltipContent);
            }
            var prevDatasetsVisible = true;
            toggleButton.addEventListener("click", function () {
                prevDatasetsVisible = !prevDatasetsVisible;
                chart.data.datasets.forEach(function (dataset, index) {
                    if (dataset.label.indexOf("Previous") !== -1) {
                        var mainIndex = index - datasets.length;
                        var mainMeta = chart.getDatasetMeta(mainIndex);
                        if (prevDatasetsVisible && mainMeta && !mainMeta.hidden) {
                            chart.getDatasetMeta(index).hidden = false;
                        } else {
                            chart.getDatasetMeta(index).hidden = true;
                        }
                    }
                });
                toggleButton.classList.toggle("active");
                chart.update();
            });

            legendContainer.parentNode.insertBefore(toggleButton, legendContainer);
        }
    }

    function slimstatGetLabel(label, long = true, unitTime, translations, justTranslation = false) {
        label = label.replace(/'/g, "");
        if (unitTime === "monthly") {
            const date = new Date(`${justTranslation ? justTranslation : label} 1`);
            const month = date.toLocaleString("default", { month: "long" });
            const year = date.getFullYear();
            const isThisMonth = new Date().getMonth() === date.getMonth() && new Date().getFullYear() === year;
            const formatted_label = isThisMonth ? `${month}, ${year} (${translations.now})` : `${label} <span class="slimstat-postbox-chart--item--prev">${month}, ${year}</span>`;
            return justTranslation ? formatted_label : long && isThisMonth ? `${formatted_label}` : `${month}, ${year}`;
        } else if (unitTime === "weekly") {
            const [weekNumber, year] = (justTranslation ? justTranslation : label).split(",").map((s) => Number(s.trim()));
            const firstDayOfYear = new Date(year, 0, 1);
            const firstDayOfWeek = new Date(year, 0, 1 + (weekNumber - 1) * 7 - firstDayOfYear.getDay());
            const calculatedLastDayOfWeek = new Date(firstDayOfWeek.getTime() + 6 * 24 * 60 * 60 * 1000);
            const today = new Date();
            let lastDayOfWeek;
            if (today >= firstDayOfWeek && today <= calculatedLastDayOfWeek) {
                lastDayOfWeek = today;
            } else {
                lastDayOfWeek = calculatedLastDayOfWeek;
            }
            const firstStr = long ? firstDayOfWeek.toLocaleString("default", { weekday: "short", month: "long", day: "numeric" }) : firstDayOfWeek.toLocaleString("default", { month: "short", day: "numeric" });
            const lastStr = long ? lastDayOfWeek.toLocaleString("default", { weekday: "short", month: "long", day: "numeric" }) : lastDayOfWeek.toLocaleString("default", { month: "short", day: "numeric" });
            const formatted_label = `${label} <span class="slimstat-postbox-chart--item--prev">${year} &#8226; ${firstStr} - ${lastStr}</span>`;
            return justTranslation ? `${formatted_label}` : `${firstStr} - ${lastStr}`;
        } else if (unitTime === "daily") {
            var date = new Date(label);
            if (justTranslation) {
                date = new Date(justTranslation);
                const isToday = new Date().toDateString() === date.toDateString();
                const formatted = long ? date.toLocaleString("default", { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : date.toLocaleDateString("default", { month: "short", day: "2-digit" }).replaceAll("-", "/");
                const formatted_label = `${label} <span class="slimstat-postbox-chart--item--prev">${formatted}</span>`;
                return long && isToday ? `${formatted_label} (${translations.today})` : formatted_label;
            }
            const isToday = new Date().toDateString() === date.toDateString();
            const formatted = long ? date.toLocaleString("default", { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : date.toLocaleDateString("default", { month: "short", day: "2-digit" }).replaceAll("-", "/");
            return long && isToday ? `${formatted} (Today)` : formatted;
        } else if (unitTime === "hourly") {
            const date = new Date(justTranslation ? justTranslation.replace(/(\d+)-(\d+)-(\d+) (\d+):00/, "$1/$2/$3 $4:00") : label.replace(/(\d+)-(\d+)-(\d+) (\d+):00/, "$1/$2/$3 $4:00"));
            const hour = date.getHours();
            const minutes = date.getMinutes() < 10 ? `0${date.getMinutes()}` : date.getMinutes();
            const isToday = new Date().toLocaleString("default", { year: "numeric", month: "numeric", day: "numeric", hour: "numeric", hourCycle: "h23" }) === date.toLocaleString("default", { year: "numeric", month: "numeric", day: "numeric", hour: "numeric", hourCycle: "h23" });
            const formatted = long ? date.toLocaleString("default", { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : date.toLocaleDateString("default", { month: "short", day: "2-digit" }).replaceAll("-", "/");
            const now = new Date().toLocaleString("default", { hour: "numeric", minute: "2-digit", hourCycle: "h23" });
            const formatted_label = `${label} <span class="slimstat-postbox-chart--item--prev">${formatted} ${hour}:${minutes}</span>`;
            if (justTranslation) {
                return formatted_label;
            }
            return long ? (isToday ? `${formatted} ${hour}:${minutes}-${now} (${translations.now})` : `${formatted} ${hour}:${minutes}-${hour + 1}:${minutes}`) : `${formatted} ${hour}:${minutes}`;
        } else if (unitTime === "yearly") {
            const date = new Date(justTranslation ? justTranslation : label);
            const year = date.getFullYear();
            const isThisYear = new Date().getFullYear() === year;
            if (justTranslation) {
                const formatted_label = `${label} <span class="slimstat-postbox-chart--item--prev">${year}</span>`;
                return formatted_label;
            }
            return isThisYear && long ? `${year} (This Year)` : `${year}`;
        }
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
                innerHtml += `<tr class="slimstat-postbox-chart--item"><td><div class="slimstat-postbox-chart--item--color" style="background-color: ${color.backgroundColor}; margin-bottom: 3px; margin-right: 10px;"></div><span class="tooltip-item-title">${item.label}</span>: <span class="tooltip-item-content">${item.value}</span>`;
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