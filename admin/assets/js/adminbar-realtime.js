/**
 * Admin Bar Realtime Update
 * Updates all admin bar modal stats (online, sessions, views, referrals, chart) every minute.
 *
 * Self-polls via 200ms minute-boundary check on all pages.
 * On admin pages with Live Analytics, skips fetch when admin.js pulse already handled it.
 *
 * @since 5.4.3 — Extended from online-only to full admin bar refresh (#223/#224)
 */
(function () {
    "use strict";

    var lastTriggerMinute = -1;

    /**
     * Animate a stat element when its value changes.
     */
    function animateElement(element, formattedValue) {
        if (!element) return;

        if (element.textContent !== formattedValue) {
            element.style.transition = "transform 0.1s ease-out";
            element.style.transform = "scale(1.05)";

            setTimeout(function () {
                element.textContent = formattedValue;
                element.style.transform = "scale(1)";
            }, 100);
        }
    }

    /**
     * Update all admin bar elements from AJAX response data.
     * Exposed globally so admin.js can call it too.
     *
     * @param {Object} data — response.data from slimstat_get_adminbar_stats
     */
    function updateAdminBar(data) {
        if (!data) return;

        var i18n = (typeof SlimStatAdminBar !== "undefined" && SlimStatAdminBar.i18n) ? SlimStatAdminBar.i18n : {};

        // Online Users
        if (data.online) {
            animateElement(document.getElementById("slimstat-adminbar-online-header"), data.online.formatted);
            animateElement(document.getElementById("slimstat-adminbar-online-count"), data.online.formatted);
        }

        // Stat cards: sessions (always), views + referrals (Pro only)
        var statCards = [
            { key: "sessions", proOnly: false },
            { key: "views",    proOnly: true },
            { key: "referrals", proOnly: true }
        ];

        statCards.forEach(function (card) {
            if (card.proOnly && !data.is_pro) return;
            var stat = data[card.key];
            if (!stat) return;

            animateElement(document.getElementById("slimstat-adminbar-" + card.key + "-count"), stat.formatted);
            var compareEl = document.getElementById("slimstat-adminbar-" + card.key + "-compare");
            if (compareEl && stat.yesterday && i18n.was_last_day) {
                compareEl.textContent = i18n.was_last_day.replace("%s", stat.yesterday);
            }
        });

        // Chart bars (Pro only)
        if (data.is_pro && data.chart && data.chart.data) {
            var container = document.getElementById("slimstat-adminbar-chart-bars");
            if (container) {
                var bars = container.querySelectorAll(".slimstat-adminbar__chart-bar");
                var chartData = data.chart.data;
                var maxVal = data.chart.max_value || 1;
                var peakIdx = data.chart.peak_index;

                bars.forEach(function (bar, i) {
                    if (i >= chartData.length) return;

                    var count = chartData[i];
                    var heightPct = count > 0 ? Math.max(Math.round((count / maxVal) * 100), 3) : 0;

                    bar.style.height = heightPct + "%";
                    bar.setAttribute("data-count", count);

                    // Toggle peak class
                    if (i === peakIdx && count > 0) {
                        bar.classList.add("slimstat-adminbar__chart-bar--peak");
                    } else {
                        bar.classList.remove("slimstat-adminbar__chart-bar--peak");
                    }

                    // Update tooltip (DOM-safe, no innerHTML)
                    var tooltip = bar.querySelector(".slimstat-adminbar__chart-tooltip");
                    if (tooltip) {
                        var minutesAgo = parseInt(bar.getAttribute("data-minutes-ago"), 10);
                        var timeText = minutesAgo === 0
                            ? (i18n.now || "Now")
                            : minutesAgo + " " + (i18n.min_ago || "min ago");
                        tooltip.textContent = "";
                        var strong = document.createElement("strong");
                        strong.textContent = i18n.online_users || "Online Users";
                        tooltip.appendChild(strong);
                        tooltip.appendChild(document.createTextNode(
                            (i18n.count_label || "Count") + ": " + parseInt(count, 10)
                        ));
                        tooltip.appendChild(document.createElement("br"));
                        tooltip.appendChild(document.createTextNode(timeText));
                    }
                });
            }
        }
    }

    // Expose globally for admin.js
    window.slimstatUpdateAdminBar = updateAdminBar;
    window.slimstatAnimateElement = animateElement;

    /**
     * Fetch all admin bar stats via AJAX and apply updates.
     */
    function fetchAdminBarStats() {
        if (typeof SlimStatAdminBar === "undefined") {
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open("POST", SlimStatAdminBar.ajax_url, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.data) {
                        updateAdminBar(response.data);
                    }
                } catch (e) {
                    console.error("SlimStat AdminBar: Failed to parse response", e);
                }
            }
        };

        xhr.send("action=slimstat_get_adminbar_stats&security=" + encodeURIComponent(SlimStatAdminBar.security));
    }

    // Start polling on DOMContentLoaded
    document.addEventListener("DOMContentLoaded", function () {
        var hasAdminBar = document.querySelector(".slimstat-adminbar__stats-grid");
        if (!hasAdminBar) return;

        // When admin.js fires slimstat:minute_pulse (only on pages with a
        // .refresh-timer, i.e. Live Analytics), it already calls
        // slimstatUpdateAdminBar(). Mark the minute as handled to avoid
        // a duplicate fetch.
        window.addEventListener("slimstat:minute_pulse", function () {
            lastTriggerMinute = new Date().getMinutes();
        });

        // Poll every 200ms, fetch at :00 of each new minute unless
        // admin.js already handled this minute via the pulse event.
        setInterval(function () {
            var now = new Date();
            if (now.getSeconds() === 0 && lastTriggerMinute !== now.getMinutes()) {
                lastTriggerMinute = now.getMinutes();
                fetchAdminBarStats();
            }
        }, 200);
    });
})();
