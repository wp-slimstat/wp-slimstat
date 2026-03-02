/**
 * Admin Bar Realtime Update
 * Updates online visitors count every minute
 */
(function () {
    "use strict";

    var lastTriggerMinute = -1;

    function updateOnlineVisitors() {
        var adminbarHeaderElement = document.getElementById("slimstat-adminbar-online-header");
        var adminbarCountElement = document.getElementById("slimstat-adminbar-online-count");

        if (!adminbarHeaderElement && !adminbarCountElement) {
            return;
        }

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
                    if (response.success && response.data && response.data.formatted) {
                        var newValue = response.data.count;
                        var formattedValue = response.data.formatted;

                        var animateElement = function (element) {
                            if (!element) return;

                            var currentValue = element.textContent.replace(/,/g, "");
                            if (parseInt(currentValue, 10) !== newValue) {
                                element.style.transition = "transform 0.1s ease-out";
                                element.style.transform = "scale(1.05)";

                                setTimeout(function () {
                                    element.textContent = formattedValue;
                                    element.style.transform = "scale(1)";
                                }, 100);
                            }
                        };

                        animateElement(adminbarHeaderElement);
                        animateElement(adminbarCountElement);
                    }
                } catch (e) {
                    console.error("SlimStat AdminBar: Failed to parse response", e);
                }
            }
        };

        xhr.send("action=slimstat_get_online_visitors&security=" + encodeURIComponent(SlimStatAdminBar.security));
    }

    function checkMinutePulse() {
        var now = new Date();
        var currentSeconds = now.getSeconds();
        var currentMinute = now.getMinutes();

        if (currentSeconds === 0 && lastTriggerMinute !== currentMinute) {
            lastTriggerMinute = currentMinute;
            updateOnlineVisitors();
        }
    }

    // Start checking every 200ms
    document.addEventListener("DOMContentLoaded", function () {
        // Check if admin bar elements exist
        var hasAdminBar = document.getElementById("slimstat-adminbar-online-header") ||
                          document.getElementById("slimstat-adminbar-online-count");

        if (hasAdminBar) {
            setInterval(checkMinutePulse, 200);
        }
    });
})();
