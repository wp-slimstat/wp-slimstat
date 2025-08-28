var slimstatClosestPoint = false;
document.addEventListener("DOMContentLoaded", function () {
    var chartElements = document.querySelectorAll('[id^="slimstat_chart_data_"]');
    var charts = {};

    function reinitializeCharts(id) {
        var updatedChartElements = document.querySelectorAll('[id^="slimstat_chart_data_' + id + '"]');
        for (var i = 0; i < updatedChartElements.length; i++) {
            var element = updatedChartElements[i];
            var chartId = id;
            var existingChart = charts[chartId];
            if (existingChart && typeof existingChart.destroy === "function") {
                existingChart.destroy();
            }
            initializeChart(element, chartId);
            setupGranularitySelect(chartId);
        }
    }

    window.reinitializeSlimStatCharts = reinitializeCharts;

    Array.prototype.forEach.call(chartElements, function (element) {
        var chartId = element.id.replace("slimstat_chart_data_", "");
        initializeChart(element, chartId);
        setupGranularitySelect(chartId);
    });

    function initializeChart(element, chartId) {
        var args = JSON.parse(element.getAttribute("data-args"));
        var data = JSON.parse(element.getAttribute("data-data"));
        var prevData = JSON.parse(element.getAttribute("data-prev-data"));
        var daysBetween = parseInt(element.getAttribute("data-days-between"));
        var chartLabels = JSON.parse(element.getAttribute("data-chart-labels"));
        var translations = JSON.parse(element.getAttribute("data-translations"));
        var totals = JSON.parse(element.getAttribute("data-totals") || "{}");

        var labels = data.labels;
        var prevLabels = data.prev_labels;

        // Fix: Check for null/undefined datasets before using them
        var datasets = prepareDatasets(data.datasets, chartLabels, labels, data.today);
        var prevDatasets = prepareDatasets(prevData.datasets, chartLabels, prevData.labels, null, true);
        prevDatasets = prevDatasets.filter(function (ds) {
            return (
                Array.isArray(ds.data) &&
                ds.data.some(function (v) {
                    return v > 0;
                })
            );
        });

        // Fix for infinite height: set a default height if not present (for widgets or non-dashboard usage)
        var chartCanvas = document.getElementById("slimstat_chart_" + chartId);
        if (chartCanvas && (!chartCanvas.style.height || chartCanvas.offsetHeight > 2000)) {
            chartCanvas.style.height = "260px";
            chartCanvas.style.maxHeight = "320px";
            chartCanvas.style.minHeight = "180px";
        }
        var ctx = chartCanvas.getContext("2d");
        var chart = createChart(ctx, labels, prevLabels, datasets, prevDatasets, totals, args.granularity, data.today, translations, daysBetween, chartId);
        charts[chartId] = chart;
        renderCustomLegend(chart, chartId, datasets, prevDatasets, totals, translations);
    }

    function setupGranularitySelect(chartId) {
        var select = document.getElementById("slimstat_granularity_" + chartId);
        if (!select) return;

        // Debounce the event listener to reduce server requests
        var debounceTimeout;
        select.addEventListener("change", function () {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(function () {
                var granularity = select.value;
                fetchChartData(chartId, granularity);
            }, 300);
        });
    }

    function fetchChartData(chartId, granularity) {
        var element = document.getElementById("slimstat_chart_data_" + chartId);
        var args = JSON.parse(element.getAttribute("data-args"));
        var inside = document.querySelector(".inside:has(#slimstat_chart_" + chartId + ")");
        var loadingIndicator = document.createElement("p");
        loadingIndicator.classList.add("loading");
        var spinner = document.createElement("i");
        spinner.classList.add("slimstat-font-spin4", "animate-spin");
        loadingIndicator.appendChild(spinner);
        inside.appendChild(loadingIndicator);
        document.querySelector(".slimstat-chart-wrap:has(#slimstat_chart_" + chartId + ")").style.display = "none";

        var xhr = new XMLHttpRequest();
        xhr.open("POST", slimstat_chart_vars.ajax_url, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    var result = JSON.parse(xhr.responseText);
                    if (!result.success) {
                        console.error("AJAX error:", result.data.message);
                        return;
                    }
                    element.dataset.granularity = granularity;

                    var oldToggleButtons = document.querySelectorAll(".slimstat-postbox-chart--item-" + chartId);
                    for (var i = 0; i < oldToggleButtons.length; i++) {
                        oldToggleButtons[i].remove();
                    }

                    var args2 = result.data.args;
                    var data2 = result.data.data;
                    var totals2 = result.data.totals;
                    var prev_data2 = result.data.prev_data;
                    var days_between2 = result.data.days_between;
                    var chart_labels2 = result.data.chart_labels;
                    var translations2 = result.data.translations;

                    var labels = data2.labels;
                    var datasets = prepareDatasets(data2.datasets, chart_labels2, labels, data2.today);
                    var prevDatasets = prepareDatasets(prev_data2.datasets, chart_labels2, prev_data2.labels, null, true);

                    // Destroy previous chart and create a new one to ensure correct tick callback
                    var chartCanvas = document.getElementById("slimstat_chart_" + chartId);
                    var prevChart = charts[chartId];
                    if (prevChart) prevChart.destroy();
                    var ctx = chartCanvas.getContext("2d");
                    var chart = createChart(ctx, labels, data2.prev_labels, datasets, prevDatasets, totals2, granularity, data2.today, translations2, days_between2, chartId);
                    charts[chartId] = chart;

                    renderCustomLegend(chart, chartId, datasets, prevDatasets, totals2, translations2);

                    element.dataset.args = JSON.stringify(args2);
                    element.dataset.data = JSON.stringify(data2);
                    element.dataset.prevData = JSON.stringify(prev_data2);
                    element.dataset.daysBetween = days_between2;
                    element.dataset.chartLabels = JSON.stringify(chart_labels2);
                    element.dataset.translations = JSON.stringify(translations2);

                    inside.removeChild(loadingIndicator);
                    document.querySelector(".slimstat-chart-wrap:has(#slimstat_chart_" + chartId + ")").style.display = "block";
                } else {
                    console.error("XHR error:", xhr.statusText);
                }
            }
        };
        var params = "action=" + encodeURIComponent("slimstat_fetch_chart_data") + "&nonce=" + encodeURIComponent(slimstat_chart_vars.nonce) + "&args=" + encodeURIComponent(JSON.stringify(args)) + "&granularity=" + encodeURIComponent(granularity);
        xhr.send(params);
    }

    function prepareDatasets(rawDatasets, chartLabels, labels, today, isPrevious) {
        if (typeof isPrevious === "undefined") {
            isPrevious = false;
        }
        if (rawDatasets === undefined || rawDatasets === null) {
            return [];
        }

        var colors = ["#e8294c", "#2b76f6", "#ffacb6", "#24cb7d", "#942bf6"];
        var result = [];
        var i = 0;
        for (var key in rawDatasets) {
            if (!Object.prototype.hasOwnProperty.call(rawDatasets, key)) {
                continue;
            }
            var values = rawDatasets[key];
            if (!Array.isArray(values)) {
                var entries = [];
                for (var k in values) {
                    if (Object.prototype.hasOwnProperty.call(values, k) && !isNaN(k) && Number(k) >= 0) {
                        entries.push([k, values[k]]);
                    }
                }
                entries.sort(function (a, b) {
                    return Number(a[0]) - Number(b[0]);
                });
                var mapped = [];
                for (var eIdx = 0; eIdx < entries.length; eIdx++) {
                    mapped.push(entries[eIdx][1]);
                }
                values = mapped;
            }
            if (Array.isArray(values)) {
                values = values.slice(0, labels.length);
            }

            var labelText = key;
            if (Array.isArray(chartLabels) && typeof chartLabels[i] !== "undefined" && chartLabels[i] !== null) {
                labelText = chartLabels[i];
            }

            (function (iCopy, labelTextCopy, valuesCopy, keyCopy) {
                var color = colors[iCopy % colors.length];
                result.push({
                    label: isPrevious ? "Previous " + labelTextCopy : labelTextCopy,
                    key: keyCopy,
                    data: valuesCopy,
                    borderColor: color,
                    borderWidth: isPrevious ? 1 : 2,
                    fill: false,
                    tension: 0.3,
                    pointBorderColor: "transparent",
                    pointBackgroundColor: color,
                    pointBorderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBorderWidth: 2,
                    hitRadius: 10,
                    pointHitRadius: 10,
                    segment: {
                        borderDash: (function (isPrev) {
                            return function (ctx) {
                                if (isPrev) {
                                    return [3, 3];
                                }
                                return labels[ctx.p1DataIndex] === "'" + today + "'" ? [5, 3] : [];
                            };
                        })(isPrevious),
                    },
                });
            })(i, labelText, values, key);
            i++;
        }
        return result;
    }

    function createChart(ctx, labels, prevLabels, datasets, prevDatasets, total, unitTime, today, translations, daysBetween, chartId) {
        var isRTL = document.documentElement.dir === "rtl" || document.body.classList.contains("rtl");

        var customCrosshair = {
            id: "customCrosshair",
            afterEvent: function (chart, args) {
                chart._lastEvent = args.event;
            },
            afterDraw: function (chart) {
                var event = chart._lastEvent;
                if (!event) return;

                var ctx2 = chart.ctx;
                var top = chart.chartArea.top;
                var bottom = chart.chartArea.bottom;
                var activePoints = chart.getElementsAtEventForMode(event.native || event, "nearest", { intersect: false }, false);
                if (!activePoints || !activePoints.length) return;
                var pt = activePoints[0].element;
                if (!pt || typeof pt.x !== "number") return;
                ctx2.save();
                ctx2.lineWidth = 1;
                ctx2.setLineDash([2, 2]);
                ctx2.strokeStyle = "rgba(0, 0, 0, 0.3)";
                ctx2.beginPath();
                ctx2.moveTo(pt.x, top);
                ctx2.lineTo(pt.x, bottom);
                ctx2.stroke();
                ctx2.restore();
            },
        };

        var maxTicks = 8;
        var uniqueTickIndexes = [];
        var xTickRotation = 0;
        var xAutoSkip = false;

        if (["daily", "monthly", "hourly", "weekly"].indexOf(unitTime) !== -1) {
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
                    if (tickIndexes.indexOf(idx) === -1) {
                        tickIndexes.push(idx);
                    } else {
                        var nextIdx = idx + 1;
                        while (nextIdx < labels.length - 1 && tickIndexes.indexOf(nextIdx) !== -1) {
                            nextIdx++;
                        }
                        if (nextIdx < labels.length - 1) {
                            tickIndexes.push(nextIdx);
                        }
                    }
                }
                tickIndexes.push(labels.length - 1);
                var seen = {};
                uniqueTickIndexes = [];
                for (var ui = 0; ui < tickIndexes.length; ui++) {
                    var val = tickIndexes[ui];
                    if (!seen[val]) {
                        seen[val] = true;
                        uniqueTickIndexes.push(val);
                    }
                }
                uniqueTickIndexes.sort(function (a, b) {
                    return a - b;
                });
                if (uniqueTickIndexes.length > maxTicks) {
                    var toRemove = uniqueTickIndexes.length - maxTicks;
                    var middle = Math.floor(uniqueTickIndexes.length / 2);
                    uniqueTickIndexes.splice(middle, toRemove);
                }
            }
        } else {
            uniqueTickIndexes = [];
            for (var k2 = 0; k2 < labels.length; k2++) {
                uniqueTickIndexes.push(k2);
            }
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
            if (labels.length <= maxTicks || uniqueTickIndexes.indexOf(index) !== -1) {
                var label = this.getLabelForValue(value).replace(/'/g, "");
                try {
                    return slimstatGetLabel(label, false, unitTime, translations);
                } catch (e) {
                    console.warn("SlimStat: Error processing label:", label, e);
                    return label; // Return original label if processing fails
                }
            }
            return "";
        }

        return new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: datasets.concat(prevDatasets),
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
        var legendContainer = document.getElementById("slimstat-postbox-custom-legend_" + chartId);
        legendContainer.innerHTML = "";
        for (var di = 0; di < chart.data.datasets.length; di++) {
            (function (index) {
                var dataset = chart.data.datasets[index];
                var isPrevious = dataset.label.indexOf("Previous") !== -1;
                if (isPrevious) {
                    return;
                }

                var key = dataset.key;
                var currentValue = totals.current && totals.current[key] != null ? totals.current[key] : 0;
                var previousValue = totals.previous && totals.previous[key] != null ? totals.previous[key] : 0;

                var legendItem = document.createElement("div");
                legendItem.className = "slimstat-postbox-chart--item";

                var html = "";
                html += '<span class="slimstat-postbox-chart--item-label">' + dataset.label + "</span>";
                html += '<span class="slimstat-postbox-chart--item--color" style="background-color: ' + dataset.borderColor + '"></span>';
                html += '<span class="slimstat-postbox-chart--item-value">' + currentValue.toLocaleString() + "</span>";
                if (previousValue && previousValue !== currentValue) {
                    html += '<span class="slimstat-postbox-chart--item--color" style="background-image: repeating-linear-gradient(to right, ' + dataset.borderColor + ", " + dataset.borderColor + ' 4px, transparent 0px, transparent 6px); background-size: auto 6px; height: 2px; margin-bottom: 0px; margin-left: 10px;"></span>';
                    html += '<span class="slimstat-postbox-chart--item-value">' + previousValue.toLocaleString() + "</span>";
                }
                legendItem.innerHTML = html;

                legendItem.addEventListener("click", function () {
                    var currentMeta = chart.getDatasetMeta(index);
                    var previousMeta = chart.getDatasetMeta(index + currentDatasets.length);
                    var togglePrevBtn = document.querySelector(".slimstat-toggle-prev-datasets.slimstat-postbox-chart--item-" + chartId);
                    var showPrevious = !!(togglePrevBtn && togglePrevBtn.classList.contains("active"));

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
            })(di);
        }

        var hasPreviousData = false;
        for (var pi = 0; pi < previousDatasets.length; pi++) {
            var ds = previousDatasets[pi];
            if (ds && Array.isArray(ds.data)) {
                for (var pv = 0; pv < ds.data.length; pv++) {
                    if (ds.data[pv] > 0) {
                        hasPreviousData = true;
                        break;
                    }
                }
            }
            if (hasPreviousData) break;
        }
        if (!hasPreviousData) return;

        var existingToggles = document.querySelectorAll("#slimstat-postbox-custom-legend_" + chartId + " .slimstat-toggle-prev-datasets");
        for (var et = 0; et < existingToggles.length; et++) {
            existingToggles[et].remove();
        }

        var togglePrevBtn = document.createElement("span");
        togglePrevBtn.innerText = translations.previous_period;
        togglePrevBtn.className = "active slimstat-toggle-prev-datasets slimstat-postbox-chart--item-" + chartId + " more-info-icon slimstat-tooltip-trigger corner";
        togglePrevBtn.title = translations.previous_period_tooltip || "Tap here to show/hide comparison.";

        if (!togglePrevBtn.querySelector(".slimstat-tooltip-content")) {
            var tooltip = document.createElement("span");
            tooltip.className = "slimstat-tooltip-content";
            tooltip.innerHTML = "<strong>" + translations.previous_period + "</strong><br>" + (translations.previous_period_tooltip || "Tap here to show/hide comparison.");
            togglePrevBtn.appendChild(tooltip);
        }

        var previousVisible = true;

        togglePrevBtn.addEventListener("click", function () {
            previousVisible = !previousVisible;

            for (var di2 = 0; di2 < chart.data.datasets.length; di2++) {
                var dataset2 = chart.data.datasets[di2];
                if (dataset2.label.indexOf("Previous") !== -1) {
                    var correspondingCurrentMeta = chart.getDatasetMeta(di2 - currentDatasets.length);
                    chart.getDatasetMeta(di2).hidden = previousVisible ? correspondingCurrentMeta.hidden : true;
                }
            }

            togglePrevBtn.classList.toggle("active");
            chart.update();
        });

        legendContainer.parentNode.insertBefore(togglePrevBtn, legendContainer);
    }

    function getEndOfWeek(dateInput, startOfWeek, respectEndOfPeriod) {
        if (typeof startOfWeek === "undefined") {
            startOfWeek = 1;
        }
        if (typeof respectEndOfPeriod === "undefined") {
            respectEndOfPeriod = false;
        }
        var date = new Date(dateInput);
        var day = date.getDay();
        var diff = (7 - startOfWeek + day) % 7;
        var nextWeek = new Date(date.getTime() + (7 - diff) * 24 * 60 * 60 * 1000);
        if (respectEndOfPeriod) {
            var today2 = new Date(respectEndOfPeriod);

            if (nextWeek.getTime() > today2.getTime()) {
                return new Date(respectEndOfPeriod);
            }
        }
        return nextWeek;
    }

    function slimstatGetLabel(label, long, unitTime, translations, justTranslation) {
        if (typeof long === "undefined") {
            long = true;
        }
        if (typeof justTranslation === "undefined") {
            justTranslation = false;
        }
        label = label.replace(/'/g, "");

        var now = new Date();
        function getMonthYear(date) {
            return {
                month: date.toLocaleString("default", { month: "long" }),
                shortMonth: date.toLocaleString("default", { month: "short" }),
                year: date.getFullYear(),
            };
        }

        function extendObj(base, opts) {
            var res = {};
            for (var k in base) {
                if (Object.prototype.hasOwnProperty.call(base, k)) {
                    res[k] = base[k];
                }
            }
            if (opts) {
                for (var k2 in opts) {
                    if (Object.prototype.hasOwnProperty.call(opts, k2)) {
                        res[k2] = opts[k2];
                    }
                }
            }
            return res;
        }
        function formatDate(date, opts) {
            var options = extendObj({ timeZone: "UTC" }, opts);
            return date.toLocaleDateString("default", options);
        }
        function formatDateTime(date, opts) {
            var options = extendObj({ timeZone: "UTC" }, opts);
            return date.toLocaleString("default", options);
        }

        if (unitTime === "monthly") {
            var labelToParse = justTranslation || label;
            var monthYearRegex = /^([A-Za-z]+)\s+(\d{4})$/;
            var match = (labelToParse || "").match(monthYearRegex);
            if (match) {
                try {
                    var monthName = match[1];
                    var year = parseInt(match[2], 10);
                    var monthIndex = new Date(monthName + " 1, 2000").getMonth();
                    var d = new Date(year, monthIndex, 1);
                    var my = getMonthYear(d);
                    var isThisMonth = now.getMonth() === d.getMonth() && now.getFullYear() === my.year;
                    var baseLabel = my.month + ", " + my.year;
                    var shortBaseLabel = my.shortMonth + ", " + my.year;
                    var extra = isThisMonth ? " (" + translations.now + ")" : "";
                    var formattedLabel = label + ' <span class="slimstat-postbox-chart--item--prev">' + baseLabel + "</span>";

                    if (long) {
                        return justTranslation ? formattedLabel : baseLabel + extra;
                    } else {
                        return justTranslation ? formattedLabel : shortBaseLabel;
                    }
                } catch (e) {
                    console.warn("SlimStat: Error processing monthly label:", label, e);
                    return label; // Return original label if processing fails
                }
            }
            // Debug: Log labels that don't match the expected format
            if (console && console.debug) {
                console.debug("SlimStat: Monthly label does not match expected format:", label);
            }
            // If the label doesn't match the expected format, return it as-is to prevent "Invalid Date, NaN"
            return label;
        } else if (unitTime === "weekly") {
            var rawDate = (justTranslation || label).replace(/\//g, "-");
            var d2 = new Date(rawDate + "T00:00:00Z");
            var weekEnd = getEndOfWeek(rawDate + "T00:00:00Z", slimstat_chart_vars.start_of_week, slimstat_chart_vars.end_date_string.replace(/\//g, "-").replace(" ", "T") + "Z");
            var weekStart = formatDate(d2, { month: long ? "long" : "short", day: "numeric" });
            var weekEndFormatted = formatDate(weekEnd, { month: long ? "long" : "short", day: "numeric" });

            return weekEndFormatted === weekStart ? weekStart : weekStart + " - " + weekEndFormatted;
        } else if (unitTime === "daily") {
            var rawDate2 = (justTranslation || label).replace(/\//g, "-");
            var d3 = new Date(rawDate2 + "T00:00:00Z");
            var isToday = new Date().toISOString().slice(0, 10) === rawDate2;
            var longFormat = formatDateTime(d3, {
                weekday: "long",
                month: "long",
                day: "numeric",
                year: "numeric",
            });
            var shortFormat = formatDate(d3, {
                month: "short",
                day: "2-digit",
            }).replace(/-/g, "/");
            var finalFormatted = long ? longFormat : shortFormat;
            var formattedLabel2 = label + ' <span class="slimstat-postbox-chart--item--prev">' + finalFormatted + "</span>";

            return justTranslation ? (long && isToday ? formattedLabel2 + " (" + translations.today + ")" : formattedLabel2) : long && isToday ? finalFormatted + " (" + translations.today + ")" : finalFormatted;
        } else if (unitTime === "hourly") {
            var rawDate3 = (justTranslation || label).replace(/\//g, "-").replace(" ", "T") + "Z";
            if (!rawDate3) return label;
            var d4 = new Date(rawDate3);
            var hour = d4.getUTCHours();
            var minutes = d4.getUTCMinutes();
            var minutesStr = minutes < 10 ? "0" + minutes : String(minutes);
            var datePart2 = long ? formatDateTime(d4, { weekday: "long", month: "long", day: "numeric", year: "numeric" }) : formatDate(d4, { month: "short", day: "2-digit" }).replace(/-/g, "/");
            var isSameHour = new Date().toISOString().slice(0, 10) === rawDate3 && new Date().getUTCHours() === d4.getUTCHours();
            var nowStr = new Date().toLocaleTimeString("default", { hour: "numeric", minute: "2-digit", hourCycle: "h23" });
            var labelStr = long ? (isSameHour ? datePart2 + " " + hour + ":" + minutesStr + "-" + nowStr + " (" + translations.now + ")" : datePart2 + " " + hour + ":" + minutesStr + "-" + (hour + 1) + ":" + minutesStr) : datePart2 + " " + hour + ":" + minutesStr;
            var formattedLabel3 = label + ' <span class="slimstat-postbox-chart--item--prev">' + datePart2 + " " + hour + ":" + minutesStr + "</span>";

            return justTranslation ? formattedLabel3 : labelStr;
        } else if (unitTime === "yearly") {
            var labelToParse2 = justTranslation || label;
            var yearMatch = (labelToParse2 || "").match(/^(\d{4})$/);
            if (yearMatch) {
                var year2 = parseInt(yearMatch[1], 10);
                var d5 = new Date(year2, 0, 1);
                var isThisYear = now.getFullYear() === d5.getFullYear();
                var formattedLabel4 = label + ' <span class="slimstat-postbox-chart--item--prev">' + d5.getFullYear() + "</span>";

                return justTranslation ? formattedLabel4 : isThisYear && long ? d5.getFullYear() + " (" + (translations.this_year || "This Year") + ")" : "" + d5.getFullYear();
            }
            return label;
        }

        return label;
    }

    function createTooltip(labels, prevLabels, translations, daysBetween, chartId) {
        return function (context) {
            var unitTime = document.getElementById("slimstat_chart_data_" + chartId).dataset.granularity;
            var data = JSON.parse(document.getElementById("slimstat_chart_data_" + chartId).getAttribute("data-data"));
            prevLabels = data.prev_labels;
            var tooltipEl = document.getElementById("chartjs-tooltip");
            if (!tooltipEl) {
                tooltipEl = document.createElement("div");
                tooltipEl.id = "chartjs-tooltip";
                tooltipEl.innerHTML = "<table></table>";
                document.body.appendChild(tooltipEl);
            }

            var chart = context.chart;
            var tooltip = context.tooltip;

            if (tooltip.opacity === 0) {
                tooltipEl.style.opacity = 0;
                return;
            }

            tooltipEl.classList.remove("above", "below", "no-transform");
            if (tooltip.yAlign) tooltipEl.classList.add(tooltip.yAlign);
            else tooltipEl.classList.add("no-transform");

            var titleLines = tooltip.title || [];

            var grouped = [];
            for (var i = 0; i < tooltip.dataPoints.length; i++) {
                var label = tooltip.body[i].lines[0].split(": ")[0];
                var value = tooltip.body[i].lines[0].split(": ")[1];
                if (label.indexOf("Previous ") === 0) continue;
                var prevValue = null,
                    prevDate = null;
                for (var j = 0; j < tooltip.dataPoints.length; j++) {
                    var prevLabel = tooltip.body[j].lines[0].split(": ")[0];
                    if (prevLabel === "Previous " + label) {
                        prevValue = tooltip.body[j].lines[0].split(": ")[1];
                        prevDate = prevLabels[tooltip.dataPoints[j].dataIndex];
                        break;
                    }
                }
                grouped.push({ label: label, value: value, prevValue: prevValue, prevDate: prevDate });
            }

            var innerHtml = "<thead>";
            for (var t = 0; t < titleLines.length; t++) {
                var title = titleLines[t];
                innerHtml += '<tr><th style="font-weight: bold; font-size: 14px; padding-bottom: 6px; text-align: left;">' + slimstatGetLabel(title.replace(/'/g, ""), true, unitTime, translations) + "</th></tr>";
            }
            innerHtml += "</thead><tbody>";

            for (var g = 0; g < grouped.length; g++) {
                var item = grouped[g];
                var color = tooltip.labelColors[g];
                innerHtml += '<tr data-index="' + g + '" class="slimstat-postbox-chart--item"><td><div class="slimstat-postbox-chart--item--color" style="background-color: ' + color.backgroundColor + '; margin-bottom: 3px; margin-right: 10px;"></div><span class="tooltip-item-title">' + item.label + '</span>: <span class="tooltip-item-content">' + item.value + "</span>";
                if (item.prevValue !== null && item.prevDate) {
                    var hex = color.backgroundColor.replace("#", "");
                    var rgb = hex
                        .match(/.{1,2}/g)
                        .map(function (x) {
                            return parseInt(x, 16);
                        })
                        .join(",");
                    innerHtml += '<br><span class="slimstat-postbox-chart--item--color" style="display:inline-block;width:18px;height:2px;background-image:repeating-linear-gradient(to right, rgba(' + rgb + ",0.7), rgba(" + rgb + ',0.7) 4px, transparent 0px, transparent 6px);background-size:auto 6px;opacity:1;margin-bottom:0px;margin-left:0px;vertical-align:middle;"></span> <span class="tooltip-item-title" style="font-size:12px;opacity:.7;">' + slimstatGetLabel(item.prevDate, false, unitTime, translations) + ': </span><span class="tooltip-item-content" style="font-size:12px;opacity:.7;">' + item.prevValue + "</span>";
                }
                innerHtml += "</td></tr>";
            }
            innerHtml += "</tbody>";
            innerHtml +=
                '<div class="align-indicator" style="\
                width: 15px;\
                height: 15px;\
                background-color: #fff;\
                border-bottom-left-radius: 5px;\
                display: inline-block;\
                position: absolute;\
                bottom: -8px;\
                border-bottom: solid 1px #e0e0e0;\
                border-left: solid 1px #e0e0e0;\
                transform: rotate(-45deg);\
                transition: left 0.1s ease;\
            "></div>';

            tooltipEl.querySelector("table").innerHTML = innerHtml;

            var chartRect = chart.canvas.getBoundingClientRect();
            var tooltipWidth = tooltipEl.offsetWidth;
            var tooltipHeight = tooltipEl.offsetHeight;
            var left = chartRect.left + window.pageXOffset + tooltip.caretX - tooltipWidth / 2;
            var dataPointYs = [];
            for (var dp = 0; dp < tooltip.dataPoints.length; dp++) {
                dataPointYs.push(tooltip.dataPoints[dp].element.y);
            }
            var highestY = dataPointYs[0];
            for (var h = 1; h < dataPointYs.length; h++) {
                if (dataPointYs[h] < highestY) highestY = dataPointYs[h];
            }
            var top = chartRect.top + window.pageYOffset + highestY - tooltipHeight - 24;

            var chartLeft = chartRect.left + window.pageXOffset;
            var chartRight = chartRect.right + window.pageXOffset;
            if (left < chartLeft + 4) {
                left = chartLeft + 4;
            }
            if (left + tooltipWidth > chartRight - 4) {
                left = chartRight - tooltipWidth - 4;
            }
            tooltipEl.style.opacity = 1;
            tooltipEl.style.position = "absolute";
            tooltipEl.style.left = left + "px";
            tooltipEl.style.top = top + "px";

            var alignIndicator = tooltipEl.querySelector(".align-indicator");
            if (alignIndicator) {
                var indicatorWidth = alignIndicator.offsetWidth;
                var tooltipLeft = left;
                var mouseX = chartRect.left + window.pageXOffset + tooltip.caretX;
                var indicatorLeft = mouseX - tooltipLeft - indicatorWidth / 2;
                var minLeft = 4;
                var maxLeft = tooltipWidth - indicatorWidth - 4;
                indicatorLeft = Math.max(minLeft, Math.min(indicatorLeft, maxLeft));
                alignIndicator.style.left = indicatorLeft + "px";
            }
        };
    }
});
