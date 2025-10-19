/**
 * Live Analytics JavaScript
 *
 * @package SlimStat
 * @since 5.4.0
 */

class LiveAnalytics {
    constructor(config) {
        this.config = config;
        this.chart = null;
        this.refreshInterval = null;
        this.progressInterval = null;
        this.progressStartTime = null;
        this.lastUpdateTime = null;
        this.pausedAt = null;
        this.isUpdating = false;
        this.currentMetric = config.current_metric || "users";
        this.isDestroyed = false;
        this.abortController = null;

        this.init = this.init.bind(this);
        this.updateData = this.updateData.bind(this);
        this.showLoading = this.showLoading.bind(this);
        this.hideLoading = this.hideLoading.bind(this);
        this.createChart = this.createChart.bind(this);
        this.updateChart = this.updateChart.bind(this);
        this.switchMetric = this.switchMetric.bind(this);
        this.scheduleNextUpdate = this.scheduleNextUpdate.bind(this);
        this.resumeAutoRefresh = this.resumeAutoRefresh.bind(this);
        this.cancelCurrentUpdate = this.cancelCurrentUpdate.bind(this);
    }

    /**
     * Initialize the Live Analytics component
     */
    init() {
        this.createChart();
        this.addEventListeners();
        this.initMetricSwitching();
        this.initializeEmptyState();

        // Start auto-refresh after initialization
        if (this.config.auto_refresh) {
            this.scheduleNextUpdate();
        }
    }

    /**
     * Initialize empty state based on initial data
     */
    initializeEmptyState() {
        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const emptyState = container.querySelector(".empty-state");
        if (!emptyState) return;

        // Check if we have initial data for the current metric
        let hasData = false;
        if (this.currentMetric === "users") {
            hasData = this.config.chart_data && this.config.chart_data.some((value) => value > 0);
        }

        if (!hasData) {
            emptyState.style.display = "block";
        } else {
            emptyState.style.display = "none";
        }
    }

    /**
     * Create the bar chart
     */
    createChart() {
        if (typeof Chart === "undefined") {
            return;
        }

        const canvas = document.getElementById(this.config.chart_id);
        if (!canvas) {
            return;
        }

        const ctx = canvas.getContext("2d");
        if (!ctx) {
            return;
        }

        const chartData = this.config.chart_data || [];
        const chartLabels = this.config.chart_labels || [];

        if (chartData.length === 0) {
            for (let i = 29; i >= 0; i--) {
                chartData.push(0);
                if (i === 29) {
                    chartLabels.push("-30 Min");
                } else if (i === 25) {
                    chartLabels.push("-25 Min");
                } else if (i === 20) {
                    chartLabels.push("-20 Min");
                } else if (i === 15) {
                    chartLabels.push("-15 Min");
                } else if (i === 10) {
                    chartLabels.push("-10 Min");
                } else if (i === 5) {
                    chartLabels.push("-5 Min");
                } else if (i === 0) {
                    chartLabels.push("-1 Min");
                } else {
                    chartLabels.push("");
                }
            }
        }

        const datasets = [
            {
                label: this.getMetricLabel(this.currentMetric),
                data: chartData,
                backgroundColor: chartData.map(() => "#E6E6E6"),
                hoverBackgroundColor: chartData.map(() => "#E7294B"),
                borderColor: "transparent",
                borderWidth: 0,
                borderRadius: {
                    topLeft: 100,
                    topRight: 100,
                    bottomLeft: 0,
                    bottomRight: 0,
                },
                borderSkipped: false,
                barPercentage: 0.7,
                categoryPercentage: 0.8,
                minBarLength: 3,
            },
        ];

        // Register the custom dashed grid plugin
        const customDashedGridPlugin = {
            id: "customDashedGrid",
            afterDraw: (chart) => {
                const ctx = chart.ctx;
                const yAxis = chart.scales.y;
                const xAxis = chart.scales.x;

                if (!yAxis || !xAxis) return;

                ctx.save();
                ctx.strokeStyle = "#E5E5E5";
                ctx.lineWidth = 1;
                ctx.setLineDash([5, 5]);

                // Draw horizontal dashed grid lines
                yAxis.ticks.forEach((tick, index) => {
                    const y = yAxis.getPixelForTick(index);
                    if (y >= yAxis.top && y <= yAxis.bottom) {
                        ctx.beginPath();
                        ctx.moveTo(xAxis.left, y);
                        ctx.lineTo(xAxis.right, y);
                        ctx.stroke();
                    }
                });

                ctx.restore();
            },
        };

        this.chart = new Chart(ctx, {
            type: "bar",
            data: {
                labels: chartLabels,
                datasets: datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        top: 0,
                        bottom: 0,
                        left: 20,
                        right: 20,
                    },
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        enabled: false,
                        external: this.createCustomTooltip(),
                    },
                },
                scales: {
                    x: {
                        display: true,
                        position: "bottom",
                        grid: {
                            display: false,
                            drawBorder: false,
                        },
                        border: {
                            display: true,
                            color: "#EBEBEB",
                            dash: [5, 5],
                        },
                        ticks: {
                            color: "#9BA1A6",
                            font: {
                                size: 12,
                                weight: "600",
                                family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            },
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false,
                            maxTicksLimit: 30,
                            align: "start",
                        },
                        offset: true,
                    },
                    y: {
                        display: true,
                        position: "right",
                        beginAtZero: true,
                        min: 0,
                        max: Math.max(10, Math.ceil((this.config.max_value || 0) * 1.02)),
                        grid: {
                            display: false,
                            color: "#E5E5E5",
                            drawBorder: false,
                            borderDash: [5, 5],
                            lineWidth: 1,
                        },
                        border: {
                            display: false,
                        },
                        ticks: {
                            color: "#9BA1A6",
                            font: {
                                size: 12,
                                weight: "600",
                                family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            },
                            padding: 5,
                            count: 3,
                            autoSkip: false,
                            callback: function (value, index, ticks) {
                                if (value === 0) {
                                    return "";
                                }

                                if (value >= 10000) {
                                    return (value / 1000).toFixed(0) + "K";
                                } else if (value >= 1000) {
                                    return (value / 1000).toFixed(1) + "K";
                                }
                                return Math.floor(value);
                            },
                        },
                        afterFit: (axis) => {
                            axis.bottom += 6;
                        },
                    },
                },
                animation: {
                    duration: 800,
                    easing: "easeInOutQuart",
                    delay: 0,
                },
                transitions: {
                    active: {
                        animation: {
                            duration: 400,
                            easing: "easeOutQuart",
                        },
                    },
                },
                interaction: {
                    intersect: false,
                    mode: "index",
                },
            },
            plugins: [customDashedGridPlugin],
        });
    }

    /**
     * Update chart with new data
     */
    updateChart(newData) {
        if (!this.chart) {
            return;
        }

        const chartData = newData.data || newData.chart_data || [];
        const chartLabels = newData.labels || newData.chart_labels || [];
        const maxValue = newData.max_value || Math.max(...chartData) || 0;

        this.chart.data.labels = chartLabels;
        this.chart.data.datasets[0].data = chartData;
        this.chart.data.datasets[0].label = this.getMetricLabel(this.currentMetric);

        // Always set all bars to gray
        this.chart.data.datasets[0].backgroundColor = chartData.map(() => "#E6E6E6");
        this.chart.data.datasets[0].hoverBackgroundColor = chartData.map(() => "#E7294B");

        // Update Y axis range to match new data
        this.chart.options.scales.y.min = 0;
        this.chart.options.scales.y.max = Math.max(10, Math.ceil(maxValue * 1.02));
        this.chart.options.scales.y.ticks.count = 3;
        this.chart.options.scales.y.ticks.autoSkip = false;

        // Use smooth animation for live updates
        this.chart.update({
            duration: 800,
            easing: "easeInOutQuart",
        });
    }

    /**
     * Schedule the next auto-update
     */
    scheduleNextUpdate() {
        // Clear any existing interval
        this.stopAutoRefresh();

        if (!this.config.auto_refresh || this.isDestroyed) {
            return;
        }

        // Record when we scheduled this update
        this.lastUpdateTime = Date.now();

        // Schedule next update
        this.refreshInterval = setTimeout(() => {
            if (!this.isDestroyed) {
                this.performAutoUpdate();
            }
        }, this.config.refresh_interval);
    }

    /**
     * Perform auto-update
     */
    async performAutoUpdate() {
        if (this.isUpdating || this.isDestroyed) {
            return;
        }

        await this.updateData();

        // Schedule next update after this one completes
        if (this.config.auto_refresh && !this.isDestroyed) {
            this.scheduleNextUpdate();
        }
    }

    /**
     * Stop auto-refresh
     */
    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearTimeout(this.refreshInterval);
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }

        // Record when we paused
        this.pausedAt = Date.now();
    }

    /**
     * Resume auto-refresh after pause
     */
    resumeAutoRefresh() {
        if (this.isDestroyed || !this.config.auto_refresh) {
            return;
        }

        const now = Date.now();

        // If we have a last update time and paused time, check how long we've been paused
        if (this.lastUpdateTime && this.pausedAt) {
            const timeSinceLastUpdate = now - this.lastUpdateTime;

            // If enough time has passed since the last scheduled update, update immediately
            if (timeSinceLastUpdate >= this.config.refresh_interval) {
                this.performAutoUpdate().then(() => {
                    // After immediate update, schedule the next one
                    if (this.config.auto_refresh && !this.isDestroyed) {
                        this.scheduleNextUpdate();
                    }
                });
            } else {
                // Otherwise, schedule based on remaining time
                const remainingTime = this.config.refresh_interval - timeSinceLastUpdate;

                // Stop any existing interval
                if (this.refreshInterval) {
                    clearTimeout(this.refreshInterval);
                    this.refreshInterval = null;
                }

                // Continue with remaining time

                // Schedule next update with remaining time
                this.refreshInterval = setTimeout(() => {
                    if (!this.isDestroyed) {
                        this.performAutoUpdate();
                    }
                }, remainingTime);
            }
        } else {
            // No previous timing info, just schedule normally
            this.scheduleNextUpdate();
        }

        // Clear pause time
        this.pausedAt = null;
    }

    /**
     * Cancel current update in progress
     */
    cancelCurrentUpdate() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }

        this.isUpdating = false;
        this.hideLoading();

        // Re-enable metric items if they were disabled
        const container = document.getElementById(this.config.report_id);
        if (container) {
            const metricItems = container.querySelectorAll(".clickable-metric");
            metricItems.forEach((item) => {
                item.classList.remove("disabled");
            });
        }
    }

    /**
     * Update data from server
     */
    async updateData() {
        if (this.isUpdating || this.isDestroyed) {
            return;
        }

        this.isUpdating = true;
        this.showLoading();

        // Update in progress

        // Create new AbortController for this request
        this.abortController = new AbortController();

        try {
            const response = await fetch(this.config.ajax_url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: new URLSearchParams({
                    action: "slimstat_get_live_analytics_data",
                    nonce: this.config.nonce,
                    report_id: this.config.report_id,
                    metric: this.currentMetric,
                }),
                signal: this.abortController.signal,
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.data?.message || "Unknown error");
            }

            this.updateUI(result.data);
        } catch (error) {
            // Don't handle abort errors - they're intentional
            if (error.name === "AbortError") {
                return;
            }

            // Re-enable metric items on error
            const container = document.getElementById(this.config.report_id);
            if (container) {
                const metricItems = container.querySelectorAll(".clickable-metric");
                metricItems.forEach((item) => {
                    item.classList.remove("disabled");
                });
            }
        } finally {
            this.abortController = null;
            this.hideLoading();
            this.isUpdating = false;
        }
    }

    /**
     * Update UI with new data
     */
    updateUI(data) {
        const usersElement = document.querySelector(`#${this.config.report_id} .users-value`);
        const pagesElement = document.querySelector(`#${this.config.report_id} .pages-value`);
        const countriesElement = document.querySelector(`#${this.config.report_id} .countries-value`);

        // Update metric values - animate only if value changed
        if (usersElement && typeof data.users_live !== "undefined") {
            this.animateValue(usersElement, data.users_live);
        }
        if (pagesElement && typeof data.pages_live !== "undefined") {
            this.animateValue(pagesElement, data.pages_live);
        }
        if (countriesElement && typeof data.countries_live !== "undefined") {
            this.animateValue(countriesElement, data.countries_live);
        }

        // Update current metric from server response to ensure sync
        if (data.selected_metric) {
            if (data.selected_metric !== this.currentMetric) {
                this.currentMetric = data.selected_metric;
                this.updateChartTitle(data.selected_metric);

                // Update active metric visual state
                const container = document.getElementById(this.config.report_id);
                if (container) {
                    const metricItems = container.querySelectorAll(".clickable-metric");
                    metricItems.forEach((item) => {
                        item.classList.remove("active");
                        if (item.dataset.metric === data.selected_metric) {
                            item.classList.add("active");
                        }
                    });
                }
            }
        }

        if (data.active_users_per_minute) {
            this.updateChart(data.active_users_per_minute);
        }

        this.handleEmptyState(data);
    }

    /**
     * Handle empty state display
     */
    handleEmptyState(data) {
        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const emptyState = container.querySelector(".empty-state");
        const chartContainer = container.querySelector(".chart-container");

        if (!emptyState || !chartContainer) return;

        // Check if current metric has data
        let hasData = false;
        if (this.currentMetric === "users") {
            hasData = data.users_live > 0;
        } else if (this.currentMetric === "pages") {
            hasData = data.pages_live > 0;
        } else if (this.currentMetric === "countries") {
            hasData = data.countries_live > 0;
        }

        if (!hasData) {
            emptyState.style.display = "block";
        } else {
            emptyState.style.display = "none";
        }
    }

    /**
     * Format number with commas
     */
    formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    /**
     * Animate value change for metric numbers
     * @param {HTMLElement} element - The element to animate
     * @param {number} newValue - The new value to animate to
     */
    animateValue(element, newValue) {
        // Get current value from element (remove commas first)
        const currentText = element.textContent.replace(/,/g, "");
        const currentValue = parseInt(currentText) || 0;

        // If values are the same, no need to animate
        if (currentValue === newValue) {
            return;
        }

        // Store original color
        const originalColor = window.getComputedStyle(element).color;

        // Add subtle highlight during animation
        element.style.transition = "transform 0.3s ease-out, color 0.3s ease-out";
        element.style.transform = "scale(1.05)";
        element.style.color = "#E7294B"; // Highlight color

        // Animation duration in milliseconds
        const duration = 600;
        const startTime = performance.now();
        const difference = newValue - currentValue;

        // Easing function (easeOutQuart)
        const easeOutQuart = (t) => 1 - Math.pow(1 - t, 4);

        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easedProgress = easeOutQuart(progress);

            const currentAnimatedValue = Math.round(currentValue + difference * easedProgress);
            element.textContent = this.formatNumber(currentAnimatedValue);

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                // Ensure final value is exactly right
                element.textContent = this.formatNumber(newValue);

                // Remove highlight after animation completes
                setTimeout(() => {
                    element.style.transform = "scale(1)";
                    element.style.color = originalColor;
                }, 50);
            }
        };

        requestAnimationFrame(animate);
    }

    /**
     * Show loading state
     */
    showLoading() {
        if (!this.chart) return;

        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const chartContainer = container.querySelector(".chart-container");
        if (!chartContainer) return;

        // Blink the last bar during updates
        this.startBlinkingAnimation();
    }

    /**
     * Hide loading state
     */
    hideLoading() {
        if (!this.chart) return;

        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const chartContainer = container.querySelector(".chart-container");
        if (!chartContainer) return;

        // Hide overlay
        const overlay = chartContainer.querySelector(".chart-loading-overlay");
        if (overlay) {
            overlay.style.display = "none";
        }

        // Stop blinking animation
        this.stopBlinkingAnimation();
    }

    /**
     * Start blinking animation for the last bar
     */
    startBlinkingAnimation() {
        if (!this.chart) return;

        const datasets = this.chart.data.datasets[0];
        if (datasets.data.length > 0) {
            const lastIndex = datasets.data.length - 1;

            if (!this.originalLastBarColor) {
                this.originalLastBarColor = datasets.backgroundColor[lastIndex];
            }

            this.blinkingInterval = setInterval(() => {
                const currentColor = datasets.backgroundColor[lastIndex];
                const newColor = currentColor === this.originalLastBarColor ? "#E7294B" : this.originalLastBarColor;
                datasets.backgroundColor[lastIndex] = newColor;
                // Use smooth animation for color transition
                this.chart.update({
                    duration: 300,
                    easing: "easeInOutQuad",
                });
            }, 600);
        }
    }

    /**
     * Stop blinking animation for the last bar
     */
    stopBlinkingAnimation() {
        if (this.blinkingInterval) {
            clearInterval(this.blinkingInterval);
            this.blinkingInterval = null;
        }

        if (this.chart && this.originalLastBarColor) {
            const datasets = this.chart.data.datasets[0];
            if (datasets.data.length > 0) {
                const lastIndex = datasets.data.length - 1;
                datasets.backgroundColor[lastIndex] = this.originalLastBarColor;
                // Smooth transition when stopping blink
                this.chart.update({
                    duration: 200,
                    easing: "easeOutQuad",
                });
                this.originalLastBarColor = null;
            }
        }
    }

    /**
     * Add event listeners
     */
    addEventListeners() {
        // Pause auto-refresh when tab is not visible
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) {
                this.stopAutoRefresh();
            } else if (this.config.auto_refresh && !this.isDestroyed) {
                this.resumeAutoRefresh();
            }
        });

        // Resume auto-refresh when window regains focus
        window.addEventListener("focus", () => {
            if (this.config.auto_refresh && !this.refreshInterval && !this.isDestroyed) {
                this.resumeAutoRefresh();
            }
        });

        // Pause auto-refresh when window loses focus
        window.addEventListener("blur", () => {
            this.stopAutoRefresh();
        });
    }

    /**
     * Initialize metric switching
     */
    initMetricSwitching() {
        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const metricItems = container.querySelectorAll(".clickable-metric");
        metricItems.forEach((item) => {
            item.addEventListener("click", (e) => {
                e.preventDefault();
                const metric = item.dataset.metric;
                this.switchMetric(metric);
            });
        });
    }

    /**
     * Switch to a different metric
     */
    async switchMetric(metric) {
        if (this.currentMetric === metric) {
            return;
        }

        // If already updating (even auto-update), cancel it - user action takes priority
        if (this.isUpdating) {
            this.cancelCurrentUpdate();
            // Wait a tick for cleanup
            await new Promise((resolve) => setTimeout(resolve, 0));
        }

        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const metricItems = container.querySelectorAll(".clickable-metric");

        // Disable all metric items during update except the clicked one
        metricItems.forEach((item) => {
            if (item.dataset.metric !== metric) {
                item.classList.add("disabled");
            }
            item.classList.remove("active");
            if (item.dataset.metric === metric) {
                item.classList.add("active");
            }
        });

        // Stop auto-refresh temporarily
        this.stopAutoRefresh();

        this.currentMetric = metric;
        this.updateChartTitle(metric);

        // Update data and then re-enable metric items
        await this.updateData();

        // Re-enable all metric items
        metricItems.forEach((item) => {
            item.classList.remove("disabled");
        });

        // Restart auto-refresh
        if (this.config.auto_refresh) {
            this.scheduleNextUpdate();
        }
    }

    /**
     * Update chart title based on selected metric
     */
    updateChartTitle(metric) {
        const container = document.getElementById(this.config.report_id);
        if (!container) return;

        const titleElement = container.querySelector(".chart-header h4");
        if (!titleElement) return;

        const titles = {
            users: "Active users per minutes",
            pages: "Active pages per minutes",
            countries: "Active countries per minutes",
        };

        titleElement.textContent = titles[metric] || titles.users;
    }

    /**
     * Get metric label for chart
     */
    getMetricLabel(metric) {
        const labels = {
            users: "Active Users",
            pages: "Active Pages",
            countries: "Active Countries",
        };

        return labels[metric] || labels.users;
    }

    /**
     * Generate time range label for tooltip based on bar index
     * Creates labels like "A min ago - 2 minutes ago"
     */
    getTimeRangeLabel(index) {
        // Index 0 is 30 minutes ago, index 29 is 1 minute ago
        const minutesAgo = 30 - index;

        if (minutesAgo === 1) {
            return "A Min Ago";
        } else {
            const current = minutesAgo + " Minutes Ago";
            return current;
        }
    }

    /**
     * Create custom tooltip similar to slimstat-chart.js
     */
    createCustomTooltip() {
        const chartId = this.config.chart_id;
        const self = this;

        return function (context) {
            const chart = context.chart;
            const tooltip = context.tooltip;

            let tooltipEl = document.getElementById("chartjs-tooltip");
            if (!tooltipEl) {
                tooltipEl = document.createElement("div");
                tooltipEl.id = "chartjs-tooltip";
                tooltipEl.innerHTML = "<table></table>";
                document.body.appendChild(tooltipEl);
            }

            if (tooltip.opacity === 0) {
                tooltipEl.style.opacity = 0;
                return;
            }

            tooltipEl.classList.remove("above", "below", "no-transform");
            if (tooltip.yAlign) {
                tooltipEl.classList.add(tooltip.yAlign);
            } else {
                tooltipEl.classList.add("no-transform");
            }

            // Get the data point index
            const dataIndex = tooltip.dataPoints && tooltip.dataPoints.length > 0 ? tooltip.dataPoints[0].dataIndex : 0;

            // Generate custom time range label
            const timeRangeLabel = self.getTimeRangeLabel(dataIndex);

            const bodyLines = tooltip.body ? tooltip.body.map((b) => b.lines) : [];

            let innerHtml = "<thead>";
            innerHtml += '<tr><th style="font-weight: bold; font-size: 14px; padding-bottom: 6px; text-align: left;">' + timeRangeLabel + "</th></tr>";
            innerHtml += "</thead><tbody>";

            for (let i = 0; i < bodyLines.length; i++) {
                const colors = tooltip.labelColors[i];
                innerHtml += '<tr><td><div class="slimstat-postbox-chart--item--color" style="background-color: ' + colors.backgroundColor + '; margin-bottom: 3px; margin-right: 10px; width: 18px; height: 18px; border-radius: 3px; display: inline-block; vertical-align: middle;"></div><span style="vertical-align: middle;">' + bodyLines[i] + "</span></td></tr>";
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

            const chartRect = chart.canvas.getBoundingClientRect();
            const tooltipWidth = tooltipEl.offsetWidth;
            const tooltipHeight = tooltipEl.offsetHeight;
            let left = chartRect.left + window.pageXOffset + tooltip.caretX - tooltipWidth / 2;
            const top = chartRect.top + window.pageYOffset + tooltip.caretY - tooltipHeight - 24;

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
            tooltipEl.style.left = left + "px";
            tooltipEl.style.top = top + "px";
            tooltipEl.style.backgroundColor = "#fff";
            tooltipEl.style.borderColor = "#e0e0e0";
            tooltipEl.style.borderWidth = "1px";
            tooltipEl.style.borderStyle = "solid";
            tooltipEl.style.borderRadius = "8px";
            tooltipEl.style.padding = "12px";
            tooltipEl.style.pointerEvents = "none";
            tooltipEl.style.fontFamily = "'Open Sans', sans-serif";
            tooltipEl.style.fontSize = "13px";
            tooltipEl.style.color = "#222";
            tooltipEl.style.boxShadow = "0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)";

            const alignIndicator = tooltipEl.querySelector(".align-indicator");
            if (alignIndicator) {
                const indicatorWidth = alignIndicator.offsetWidth;
                const tooltipLeft = left;
                const mouseX = chartRect.left + window.pageXOffset + tooltip.caretX;
                let indicatorLeft = mouseX - tooltipLeft - indicatorWidth / 2;
                const minLeft = 4;
                const maxLeft = tooltipWidth - indicatorWidth - 4;
                indicatorLeft = Math.max(minLeft, Math.min(indicatorLeft, maxLeft));
                alignIndicator.style.left = indicatorLeft + "px";
            }
        };
    }

    /**
     * Destroy the component
     */
    destroy() {
        this.isDestroyed = true;
        this.stopAutoRefresh();
        this.stopBlinkingAnimation();

        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}

window.LiveAnalytics = LiveAnalytics;

document.addEventListener("DOMContentLoaded", function () {});
