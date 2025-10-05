(function ($) {
    function renderList(steps) {
        var $ul = $("#slimstat-migration-list").empty();
        // Render diagnostics first, then planned steps
        var diag = (SlimstatMigration && SlimstatMigration.diagnostics) || [];
        if (diag.length) {
            diag.forEach(function (d) {
                var $li = $("<li/>");
                var icon = d.exists ? "yes" : "warning";
                var color = d.exists ? "green" : "#d63638";
                $li.append('<span class="dashicons dashicons-' + icon + '" style="color:' + color + ';margin-right:6px;"></span>');
                $li.append("<code>" + d.key + "</code> ");
                $li.append('<span style="color:#666;">(' + d.columns + ")</span>");
                $ul.append($li);
            });
            // Separator line
            $ul.append('<li style="border-bottom:1px solid #eee;margin:6px 0;"></li>');
        }
        steps.forEach(function (s) {
            var $li = $("<li/>", { id: "slimstat-step-" + s.id });
            $li.append($("<div/>", { class: "label", html: s.name + " — " + s.desc }));
            $li.append($("<span/>", { class: "status" }));
            $ul.append($li);
        });
    }
    function setProgress(done, total) {
        var pct = total ? Math.round((done / total) * 100) : 0;
        var $wrap = $(".slimstat-migration .progress");
        $(".slimstat-migration .bar").css("width", pct + "%");
        $("#slimstat-progress-percent").text(pct + "%");
        $wrap.attr("aria-valuenow", pct);
    }
    function updateMetrics(done, total, startTs) {
        var remaining = Math.max(total - done, 0);
        $("#slimstat-metrics-total").text(total);
        $("#slimstat-metrics-completed").text(done);
        $("#slimstat-metrics-remaining").text(remaining);
        if (startTs) {
            var elapsedMs = Date.now() - startTs;
            var sec = Math.floor(elapsedMs / 1000);
            var mm = String(Math.floor(sec / 60)).padStart(2, "0");
            var ss = String(sec % 60).padStart(2, "0");
            $("#slimstat-metrics-elapsed").text(mm + ":" + ss);
        }
    }
    function setStatusBadge(state) {
        var $badge = $(".slimstat-status-badge");
        $badge.removeClass("slimstat-badge-idle slimstat-badge-running slimstat-badge-success slimstat-badge-error");
        if (state === "running") $badge.addClass("slimstat-badge-running").text(SlimstatMigration.labels.runningShort || "Running");
        else if (state === "success") $badge.addClass("slimstat-badge-success").text(SlimstatMigration.labels.done || "Done");
        else if (state === "error") $badge.addClass("slimstat-badge-error").text(SlimstatMigration.labels.failed || "Failed");
        else $badge.addClass("slimstat-badge-idle").text(SlimstatMigration.labels.idle || "Idle");
    }
    function setStatusText(state) {
        var $text = $(".slimstat-status-text");
        var label = $text.data("label-" + state) || "";
        if (label) $text.text(label);
    }
    function runAll() {
        var steps = (SlimstatMigration && SlimstatMigration.steps) || [];
        var i = 0,
            done = 0,
            total = steps.length;
        var startTs = Date.now();
        setProgress(0, total);
        updateMetrics(0, total, startTs);
        setStatusBadge("running");
        setStatusText("running");
        updateStatus(SlimstatMigration.labels.running);
        var elapsedTimer = setInterval(function () {
            updateMetrics(done, total, startTs);
        }, 1000);
        function next() {
            if (i >= total) {
                // All individual steps completed, now run the final migration
                $.post(SlimstatMigration.ajaxUrl, { action: "slimstat_run_migrations", _ajax_nonce: SlimstatMigration.nonce }, function (resp) {
                    var success = !!(resp && resp.success);
                    var data = resp && resp.data;

                    if (success && data && data.all_complete) {
                        updateStatus(data.message || SlimstatMigration.labels.allFinished);
                        $("#slimstat-back-dashboard").show();
                        $("#slimstat-start-migration").hide();
                        $(".spinner").removeClass("is-active");
                        setStatusBadge("success");
                        setStatusText("done");
                        updateMetrics(done, total, startTs);
                        clearInterval(elapsedTimer);

                        // Show completion message and redirect after a delay
                        setTimeout(function () {
                            alert(data.message || SlimstatMigration.labels.allFinished);
                            window.location.href = $("#slimstat-back-dashboard").attr("href") || "admin.php?page=" + ((wp_slimstat_admin && wp_slimstat_admin.main_menu_slug) || "slimview1");
                        }, 2000);
                    } else {
                        updateStatus(SlimstatMigration.labels.allFinished);
                        $("#slimstat-back-dashboard").show();
                        $("#slimstat-start-migration").hide();
                        $(".spinner").removeClass("is-active");
                        setStatusBadge("success");
                        setStatusText("done");
                        updateMetrics(done, total, startTs);
                        clearInterval(elapsedTimer);
                    }
                });
                return;
            }
            var step = steps[i];
            var $row = $("#slimstat-step-" + step.id + " .status");
            $row.html('<span style="color:#0073aa;">' + SlimstatMigration.labels.inProgress + '</span> <span class="spinner is-active"></span>');
            $.post(SlimstatMigration.ajaxUrl, { action: "slimstat_run_migrations", _ajax_nonce: SlimstatMigration.nonce, migration: step.id }, function (resp) {
                var ok = !!(resp && resp.success);
                $row.html(ok ? '<span style="color:green;">' + SlimstatMigration.labels.done + "</span>" : '<span style="color:red;">' + SlimstatMigration.labels.failed + "</span>");
                done += ok ? 1 : 0;
                setProgress(done, total);
                updateMetrics(done, total, startTs);
                if (!ok) {
                    setStatusBadge("error");
                    setStatusText("failed");
                    $("#slimstat-status-note")
                        .removeClass("notice-info")
                        .addClass("notice-error")
                        .text(SlimstatMigration.labels.failedHelp || "A step failed. Please check logs and retry.");
                }
                i++;
                next();
            });
        }
        next();
    }
    function updateStatus(text) {
        $("#slimstat-status-note").text(text);
    }
    $(function () {
        renderList((SlimstatMigration && SlimstatMigration.steps) || []);
        // initialize metrics from preset steps
        var initialTotal = (SlimstatMigration && SlimstatMigration.steps && SlimstatMigration.steps.length) || 0;
        updateMetrics(0, initialTotal, null);
        setProgress(0, initialTotal);
        $("#slimstat-start-migration").on("click", function (e) {
            e.preventDefault();
            $(this).prop("disabled", true);
            $(this).find(".spinner").addClass("is-active");
            runAll();
        });

        // Notice dismissal
        $(document).on("click", ".slimstat-migration-notice .notice-dismiss", function () {
            $.post(ajaxurl, {
                action: "slimstat_migration_dismiss",
                _ajax_nonce: $(this).closest(".slimstat-migration-notice").data("nonce"),
            });
        });
    });
})(jQuery);
