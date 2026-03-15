/**
 * Admin Bar Realtime Update
 * Updates all admin bar modal stats (online, sessions, views, referrals, chart) every minute.
 *
 * On frontend: self-polls via 200ms minute-boundary check.
 * On admin: defers to admin.js slimstat:minute_pulse (no self-polling).
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

        // Online Users
        if (data.online) {
            animateElement(document.getElementById("slimstat-adminbar-online-header"), data.online.formatted);
            animateElement(document.getElementById("slimstat-adminbar-online-count"), data.online.formatted);
        }

        // Sessions Today
        if (data.sessions) {
            animateElement(document.getElementById("slimstat-adminbar-sessions-count"), data.sessions.formatted);
            var sessionsCompare = document.getElementById("slimstat-adminbar-sessions-compare");
            if (sessionsCompare && data.sessions.yesterday && typeof SlimStatAdminBar !== "undefined" && SlimStatAdminBar.i18n) {
                sessionsCompare.textContent = SlimStatAdminBar.i18n.was_last_day.replace("%s", data.sessions.yesterday);
            }
        }

        // Views Today (Pro only)
        if (data.is_pro && data.views) {
            animateElement(document.getElementById("slimstat-adminbar-views-count"), data.views.formatted);
            var viewsCompare = document.getElementById("slimstat-adminbar-views-compare");
            if (viewsCompare && data.views.yesterday && typeof SlimStatAdminBar !== "undefined" && SlimStatAdminBar.i18n) {
                viewsCompare.textContent = SlimStatAdminBar.i18n.was_last_day.replace("%s", data.views.yesterday);
            }
        }

        // Referrals Today (Pro only)
        if (data.is_pro && data.referrals) {
            animateElement(document.getElementById("slimstat-adminbar-referrals-count"), data.referrals.formatted);
            var referralsCompare = document.getElementById("slimstat-adminbar-referrals-compare");
            if (referralsCompare && data.referrals.yesterday && typeof SlimStatAdminBar !== "undefined" && SlimStatAdminBar.i18n) {
                referralsCompare.textContent = SlimStatAdminBar.i18n.was_last_day.replace("%s", data.referrals.yesterday);
            }
        }

        // Chart bars (Pro only)
        if (data.is_pro && data.chart && data.chart.data) {
            var container = document.getElementById("slimstat-adminbar-chart-bars");
            if (container) {
                var bars = container.querySelectorAll(".slimstat-adminbar__chart-bar");
                var chartData = data.chart.data;
                var maxVal = data.chart.max_value || 1;
                var peakIdx = data.chart.peak_index;
                var i18n = (typeof SlimStatAdminBar !== "undefined" && SlimStatAdminBar.i18n) ? SlimStatAdminBar.i18n : {};

                bars.forEach(function (bar, i) {
                    if (i >= chartData.length) return;

                    var count = chartData[i];
                    var heightPct = Math.max(Math.round((count / maxVal) * 100), 3);

                    bar.style.height = heightPct + "%";
                    bar.setAttribute("data-count", count);

                    // Toggle peak class
                    if (i === peakIdx && count > 0) {
                        bar.classList.add("slimstat-adminbar__chart-bar--peak");
                    } else {
                        bar.classList.remove("slimstat-adminbar__chart-bar--peak");
                    }

                    // Update tooltip
                    var tooltip = bar.querySelector(".slimstat-adminbar__chart-tooltip");
                    if (tooltip) {
                        var minutesAgo = parseInt(bar.getAttribute("data-minutes-ago"), 10);
                        var timeText = minutesAgo === 0
                            ? (i18n.now || "Now")
                            : minutesAgo + " " + (i18n.min_ago || "min ago");
                        tooltip.innerHTML = "<strong>" + (i18n.online_users || "Online Users") + "</strong>"
                            + (i18n.count_label || "Count") + ": " + count + "<br>"
                            + timeText;
                    }
                });
            }
        }
    }

    // Expose globally for admin.js
    window.slimstatUpdateAdminBar = updateAdminBar;

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

    function checkMinutePulse() {
        var now = new Date();
        var currentSeconds = now.getSeconds();
        var currentMinute = now.getMinutes();

        if (currentSeconds === 0 && lastTriggerMinute !== currentMinute) {
            lastTriggerMinute = currentMinute;
            fetchAdminBarStats();
        }
    }

    // Start polling on DOMContentLoaded (frontend only — admin.js handles admin context)
    document.addEventListener("DOMContentLoaded", function () {
        var hasAdminBar = document.querySelector(".slimstat-adminbar__stats-grid");
        if (!hasAdminBar) return;

        // In admin context, admin.js dispatches slimstat:minute_pulse and calls
        // window.slimstatUpdateAdminBar() directly — no self-polling needed.
        if (typeof SlimStatAdmin !== "undefined") {
            return;
        }

        setInterval(checkMinutePulse, 200);
    });
})();
