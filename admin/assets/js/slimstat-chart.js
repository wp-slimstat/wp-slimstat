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

    window.reinitializeSlimstatCharts = reinitializeCharts;

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

        const labels = data.labels;
        const prev_labels = data.prev_labels;

        const datasets = prepareDatasets(data.datasets, chartLabels, labels, data.today);
        let prevDatasets = prepareDatasets(prevData.datasets, chartLabels, prevData.labels, null, true);
        prevDatasets = prevDatasets.filter((ds) => Array.isArray(ds.data) && ds.data.some((v) => v > 0));

        const ctx = document.getElementById(`slimstat_chart_${chartId}`).getContext("2d");
        const chart = createChart(ctx, labels, prev_labels, datasets, prevDatasets, args.granularity, data.today, translations, daysBetween, chartId);
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

            return {
                label: isPrevious ? `Previous ${chartLabels[i] ?? key}` : chartLabels[i] ?? key,
                data: values,
                borderColor: colors[i % colors.length],
                borderWidth: isPrevious ? 1 : 2,
                fill: false,
                tension: 0.3,
                pointBorderColor: "transparent",
                pointBackgroundColor: colors[i % colors.length],
                pointBorderWidth: 2,
                hoverPointRadius: 6,
                pointHoverBorderWidth: 4,
                hitRadius: 10,
                segment: {
                    borderDash: isPrevious ? () => [3, 3] : (ctx) => (labels[ctx.p1DataIndex] === `'${today}'` ? [5, 3] : []),
                },
            };
        });
    }

    function createChart(ctx, labels, prev_labels, datasets, prevDatasets, unitTime, today, translations, daysBetween, chartId) {
        let xTickRotation = undefined;
        let customTickCallback;
        if (labels.length > 15) {
            const maxTicks = labels.length === 30 || labels.length === 31 ? 10 : labels.length > 45 ? Math.round(labels.length / 4) : labels.length >= 15 ? 8 : labels.length;
            let tickInterval = 1;
            if (labels.length >= 15) {
                tickInterval = Math.floor((labels.length - 1) / (maxTicks - 1));
            }
            xTickRotation = 0;
            customTickCallback = function (value, index, values) {
                if (value % tickInterval === 0) {
                    const label = this.getLabelForValue(value).replace(/'/g, "");
                    return slimstatGetLabel(label, false, unitTime, translations);
                }
                return "";
            };
        } else {
            xTickRotation = 30;
            customTickCallback = function (value, index, values) {
                const label = this.getLabelForValue(value).replace(/'/g, "");
                return slimstatGetLabel(label, false, unitTime, translations);
            };
        }
        return new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [...datasets, ...prevDatasets],
            },
            options: {
                layout: { padding: 20 },
                scales: {
                    x: {
                        ticks: {
                            callback: customTickCallback,
                            minRotation: 0,
                            maxRotation: xTickRotation,
                        },
                        grid: {
                            display: true,
                        },
                    },
                    y: {
                        grid: {
                            display: false,
                        },
                    },
                },
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: false,
                    tooltip: {
                        enabled: false,
                        external: createTooltip(labels, prev_labels, translations, daysBetween, chartId),
                    },
                },
                animations: {
                    radius: {
                        duration: 400,
                        easing: "linear",
                        loop: (context) => context.active,
                    },
                },
                interaction: {
                    intersect: false,
                    mode: "index",
                },
            },
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
                    <span class="slimstat-postbox-chart--item-value">${value}</span>
                    ${
                        prevValue !== value
                            ? `
                        <span class="slimstat-postbox-chart--item--color" style="background-image: repeating-linear-gradient(to right, ${dataset.borderColor}, ${dataset.borderColor} 4px, transparent 0px, transparent 6px); background-size: auto 6px; height: 2px; margin-bottom: 0px; margin-left: 10px;"></span>
                        <span class="slimstat-postbox-chart--item-value">${prevValue}</span>
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
            const oldToggleButtons = document.querySelectorAll(`#slimstat-postbox-custom-legend_${chartId} .slimstat-toggle-prev-datasets`);
            oldToggleButtons.forEach((button) => button.remove());
            const toggleButton = document.createElement("span");
            toggleButton.innerText = translations.previous_period;
            toggleButton.classList.add("active", "slimstat-toggle-prev-datasets", `slimstat-postbox-chart--item-${chartId}`, "more-info-icon", "slimstat-tooltip-trigger", "corner");
            toggleButton.title = translations.previous_period_tooltip || "Click to Show or Hide data from the previous period for comparison.";
            const tooltipContent = document.createElement("span");
            tooltipContent.classList.add("slimstat-tooltip-content");
            tooltipContent.innerHTML = `<strong>${translations.previous_period}</strong><br>${translations.previous_period_tooltip || "Shows data from the previous period for comparison."}`;
            toggleButton.appendChild(tooltipContent);

            let prevDatasetsVisible = true;

            toggleButton.addEventListener("click", () => {
                prevDatasetsVisible = !prevDatasetsVisible;
                chart.data.datasets.forEach((dataset, index) => {
                    if (dataset.label.includes("Previous")) {
                        const mainIndex = index - datasets.length;
                        const mainMeta = chart.getDatasetMeta(mainIndex);
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
            const lastDayOfWeek = new Date(firstDayOfWeek.getTime() + 6 * 24 * 60 * 60 * 1000);
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

    function createTooltip(labels, prev_labels, translations, daysBetween, chartId) {
        return function (context) {
            var unitTime = document.getElementById(`slimstat_chart_data_${chartId}`).dataset.granularity;
            var data = JSON.parse(document.getElementById(`slimstat_chart_data_${chartId}`).getAttribute("data-data"));
            prev_labels = data.prev_labels;
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

            const bodyLines = tooltip.body.map((bodyItem, i) => {
                const [label, value] = bodyItem.lines[0].split(": ");
                const itemDate = prev_labels[tooltip.dataPoints[i].dataIndex] ? prev_labels[tooltip.dataPoints[i].dataIndex] : false;
                const isPrev = label.includes("Previous");
                const formattedLabel = isPrev ? slimstatGetLabel(label.split("Previous ")[1].trim(), false, unitTime, translations, itemDate) : label;
                return `<span class="tooltip-item-title ${isPrev ? "slimstat-postbox-chart--prev-item--title" : ""}">${formattedLabel}</span>: <span class="tooltip-item-content">${value}</span>`;
            });

            let innerHtml = "<thead>";
            titleLines.forEach((title) => {
                innerHtml += `<tr><th style="font-weight: bold; font-size: 14px; padding-bottom: 6px; text-align: left;">${slimstatGetLabel(title.replace(/'/g, ""), true, unitTime, translations)}</th></tr>`;
            });
            innerHtml += "</thead><tbody>";

            bodyLines.forEach((body, i) => {
                const color = tooltip.labelColors[i];
                const style = body.includes("slimstat-postbox-chart--prev-item--title") ? `background-image: repeating-linear-gradient(to right, ${color.backgroundColor}, ${color.backgroundColor} 4px, transparent 0px, transparent 6px); background-size: auto 6px; opacity: 0.8; height: 2px;` : `background-color: ${color.backgroundColor};`;
                innerHtml += `<tr class="slimstat-postbox-chart--item"><td><div class="slimstat-postbox-chart--item--color" style="${style}; margin-bottom: 3px; margin-right: 10px;"></div>${body}</td></tr>`;
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

            const position = chart.canvas.getBoundingClientRect();
            const tooltipWidth = tooltipEl.offsetWidth;
            const tooltipHeight = tooltipEl.offsetHeight;
            let left = position.left + window.pageXOffset + tooltip.caretX - tooltipWidth / 2;
            const dataPointYs = tooltip.dataPoints.map((dp) => dp.element.y);
            const highestY = Math.min(...dataPointYs);
            let top = position.top + window.pageYOffset + highestY - tooltipHeight - 24;

            if (left + tooltipWidth > window.innerWidth - 10) {
                left = window.innerWidth - tooltipWidth - 10;
            }
            if (left < 10) {
                left = 10;
            }
            if (top < 10) {
                top = 10;
            }

            tooltipEl.style.opacity = 1;
            tooltipEl.style.position = "absolute";
            tooltipEl.style.left = `${left}px`;
            tooltipEl.style.top = `${top}px`;

            const alignIndicator = tooltipEl.querySelector(".align-indicator");
            if (alignIndicator) {
                const indicatorWidth = alignIndicator.offsetWidth;
                const tooltipLeft = left;
                const tooltipRight = left + tooltipWidth;
                const mouseX = position.left + window.pageXOffset + tooltip.caretX;
                let indicatorLeft = mouseX - tooltipLeft - indicatorWidth / 2;
                const minLeft = 4;
                const maxLeft = tooltipWidth - indicatorWidth - 4;
                indicatorLeft = Math.max(minLeft, Math.min(indicatorLeft, maxLeft));
                alignIndicator.style.left = `${indicatorLeft}px`;
            }
        };
    }
});
