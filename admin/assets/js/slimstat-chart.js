document.addEventListener("DOMContentLoaded", () => {
    const chartElements = document.querySelectorAll('[id^="slimstat_chart_data_"]');
    const charts = new Map();

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
        const datasets = prepareDatasets(data.datasets, chartLabels, labels, data.today);
        const prevDatasets = prepareDatasets(prevData.datasets, chartLabels, prevData.labels, null, true);

        const ctx = document.getElementById(`slimstat_chart_${chartId}`).getContext("2d");
        const chart = createChart(ctx, labels, datasets, prevDatasets, args.granularity, data.today, translations, daysBetween, chartId);
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

        fetch(slimstat_chart_vars.ajax_url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                action: "slimstat_fetch_chart_data",
                nonce: slimstat_chart_vars.nonce,
                args: JSON.stringify(args),
                granularity: granularity,
            }),
        })
            .then((response) => response.json())
            .then((result) => {
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

                const chart = charts.get(chartId);
                chart.data.labels = labels;
                chart.data.datasets = [...datasets, ...prevDatasets];
                chart.options.scales.x.ticks.callback = function (value) {
                    const label = this.getLabelForValue(value).replace(/'/g, "");
                    return slimstatGetLabel(label, false, granularity); // Ensure updated granularity is passed
                };
                chart.update();

                renderCustomLegend(chart, chartId, datasets, prevDatasets, labels, data.today, translations);

                element.dataset.args = JSON.stringify(args);
                element.dataset.data = JSON.stringify(data);
                element.dataset.prevData = JSON.stringify(prev_data);
                element.dataset.daysBetween = days_between;
                element.dataset.chartLabels = JSON.stringify(chart_labels);
                element.dataset.translations = JSON.stringify(translations);

                inside.removeChild(loadingIndicator);
                document.querySelector(`.slimstat-chart-wrap:has(#slimstat_chart_${chartId})`).style.display = "block";
            })
            .catch((error) => console.error("Fetch error:", error));
    }

    function prepareDatasets(rawDatasets, chartLabels, labels, today, isPrevious = false) {
        const colors = ["#2b76f6", "#ffacb6", "#24cb7d", "#e8294c", "#942bf6"];
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

    function createChart(ctx, labels, datasets, prevDatasets, unitTime, today, translations, daysBetween, chartId) {
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
                            callback: function (value) {
                                const label = this.getLabelForValue(value).replace(/'/g, "");
                                return slimstatGetLabel(label, false, unitTime);
                            },
                        },
                    },
                },
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: false,
                    tooltip: {
                        enabled: false,
                        external: createTooltip(labels, translations, daysBetween, chartId),
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
                    newValue = 0;

                datasetData.forEach((data, i) => {
                    if (labels[i] !== `'${today}'`) value += data;
                    newValue += data;
                });

                const legendItem = document.createElement("div");
                legendItem.classList.add("slimstat-postbox-chart--item");

                legendItem.innerHTML = `
                    <span class="slimstat-postbox-chart--item-label">${dataset.label}</span>
                    <span class="slimstat-postbox-chart--item--color" style="background-color: ${dataset.borderColor}"></span>
                    <span class="slimstat-postbox-chart--item-value">${value}</span>
                    ${
                        value !== newValue
                            ? `
                        <span class="slimstat-postbox-chart--item--color" style="background-image: linear-gradient(to right, ${dataset.borderColor} 80%, transparent 20%); background-size: 10px 6px; opacity: 0.8;"></span>
                        <span class="slimstat-postbox-chart--item-value" style="opacity: 0.8;">${newValue}</span>
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
                    prevMeta.hidden = meta.hidden;
                    chart.update();
                });

                legendContainer.appendChild(legendItem);
            }
        });

        const hasPrevData = prevDatasets.some((dataset) => dataset.data.some((value) => value > 0));

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
                        chart.getDatasetMeta(index).hidden = !prevDatasetsVisible;
                    }
                });
                toggleButton.classList.toggle("active");
                chart.update();
            });

            legendContainer.parentNode.insertBefore(toggleButton, legendContainer);
        }
    }

    function slimstatGetLabel(label, long = true, unitTime, translations = false) {
        if (unitTime === "monthly") {
            if (translations) return `${label} <span class="slimstat-postbox-chart--item--prev">${translations["30_days_ago"]}</span>`;
            const date = new Date(`${label} 1`);
            const month = date.toLocaleString("default", { month: "long" });
            const year = date.getFullYear();
            const isThisMonth = new Date().getMonth() === date.getMonth() && new Date().getFullYear() === year;
            return isThisMonth && long ? `${month}, ${year} (This Month)` : `${month}, ${year}`;
        } else if (unitTime === "weekly") {
            if (translations) return `${label} <span class="slimstat-postbox-chart--item--prev">${translations["30_days_ago"]}</span>`;
            const [weekNumber, year] = label.split(", ").map(Number);
            const firstDayOfYear = new Date(year, 0, 1);
            const firstDayOfWeek = new Date(year, 0, 1 + (weekNumber - 1) * 7 - firstDayOfYear.getDay());
            const lastDayOfWeek = new Date(firstDayOfWeek.getTime() + 6 * 24 * 60 * 60 * 1000);
            const firstStr = long ? firstDayOfWeek.toLocaleString("default", { weekday: "short", month: "long", day: "numeric" }) : firstDayOfWeek.toLocaleString("default", { month: "short", day: "numeric" });
            const lastStr = long ? lastDayOfWeek.toLocaleString("default", { weekday: "short", month: "long", day: "numeric" }) : lastDayOfWeek.toLocaleString("default", { month: "short", day: "numeric" });
            return `${firstStr} - ${lastStr}`;
        } else if (unitTime === "daily") {
            if (translations) return `${label} <span class="slimstat-postbox-chart--item--prev">${translations.days_ago}</span>`;
            const date = new Date(label);
            const isToday = new Date().toDateString() === date.toDateString();
            const formatted = long ? date.toLocaleString("default", { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : date.toLocaleDateString("default", { month: "short", day: "2-digit" }).replaceAll("-", "/");
            return long && isToday ? `${formatted} (Today)` : formatted;
        } else if (unitTime === "hourly") {
            if (translations) return `${label} <span class="slimstat-postbox-chart--item--prev">${translations.day_ago}</span>`;
            const date = new Date(label.replace(/(\d+)-(\d+)-(\d+) (\d+):00/, "$1/$2/$3 $4:00"));
            const hour = date.getHours();
            const minutes = date.getMinutes() < 10 ? `0${date.getMinutes()}` : date.getMinutes();
            const isToday = new Date().toDateString() === date.toDateString();
            const formatted = long ? date.toLocaleString("default", { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : date.toLocaleDateString("default", { month: "short", day: "2-digit" }).replaceAll("-", "/");
            return long && isToday ? `${formatted} ${hour}:00 (This Hour)` : `${formatted} ${hour}:${minutes}`;
        } else if (unitTime === "yearly") {
            if (translations) return `${label} <span class="slimstat-postbox-chart--item--prev">${translations.year_ago}</span>`;
            const date = new Date(label);
            const year = date.getFullYear();
            const isThisYear = new Date().getFullYear() === year;
            return isThisYear && long ? `${year} (This Year)` : `${year}`;
        }
    }

    function createTooltip(labels, translations, daysBetween, chartId) {
        return function (context) {
            var unitTime = document.getElementById(`slimstat_chart_data_${chartId}`).dataset.granularity;
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
            const bodyLines = tooltip.body.map((bodyItem) => {
                const [label, value] = bodyItem.lines[0].split(": ");
                const isPrev = label.includes("Previous");
                const formattedLabel = isPrev ? slimstatGetLabel(label.split("Previous ")[1].trim(), false, unitTime, translations) : label;
                return `<span class="tooltip-item-title ${isPrev ? "slimstat-postbox-chart--prev-item--title" : ""}">${formattedLabel}</span>: <span class="tooltip-item-content">${value}</span>`;
            });

            let innerHtml = "<thead>";
            titleLines.forEach((title) => {
                innerHtml += `<tr><th style="font-weight: bold; font-size: 14px; padding-bottom: 6px; text-align: left;">${slimstatGetLabel(title.replace(/'/g, ""), true, unitTime)}</th></tr>`;
            });
            innerHtml += "</thead><tbody>";

            bodyLines.forEach((body, i) => {
                const color = tooltip.labelColors[i];
                const style = body.includes("slimstat-postbox-chart--prev-item--title") ? `background-image: repeating-linear-gradient(to right, ${color.backgroundColor}, ${color.backgroundColor} 4px, transparent 0px, transparent 6px); background-size: auto 6px; opacity: 0.8; height: 2px;` : `background-color: ${color.backgroundColor};`;
                innerHtml += `<tr class="slimstat-postbox-chart--item"><td><div class="slimstat-postbox-chart--item--color" style="${style}; margin-bottom: 3px; margin-right: 10px;"></div>${body}</td></tr>`;
            });
            innerHtml += "</tbody>";
            innerHtml += '<div class="align-indicator" style="width: 15px; height: 15px; background-color: #fff; border-radius: 3px; display: inline-block; position: absolute; left: -8px; top: calc(50% - 8px); border-bottom: solid 1px #f0f0f0; border-left: solid 1px #f0f0f0; transform: rotate(45deg);"></div>';

            tooltipEl.querySelector("table").innerHTML = innerHtml;

            const position = chart.canvas.getBoundingClientRect();
            tooltipEl.style.opacity = 1;
            tooltipEl.style.position = "absolute";
            tooltipEl.style.left = `${position.left + window.pageXOffset + tooltip.caretX + 20}px`;
            tooltipEl.style.top = `${position.top + window.pageYOffset + tooltip.caretY - tooltipEl.offsetHeight + 61}px`;
        };
    }

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

    window.reinitializeCharts = reinitializeCharts;
});
