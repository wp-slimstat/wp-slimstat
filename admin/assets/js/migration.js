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
            // An OFFERED step gets its own control and is skipped by "Start Migration".
            // Without the button it would be listed and unstartable, which is how the first
            // version of this shipped: excluded from the run loop and given nothing to replace
            // it, so "optional" meant "visible and impossible".
            if (s.optional) {
                $li.addClass("slimstat-step-optional");
                $li.append(
                    $("<button/>", {
                        class: "button slimstat-run-step",
                        type: "button",
                        "data-migration": s.id,
                        text: SlimstatMigration.labels.runThisStep || "Run this step"
                    })
                );
            }
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
        // OWED ONLY. "Start Migration" applies what the site is owed; an offered step has its
        // own button. Filtering here rather than in the loop keeps `total` and the progress
        // bar honest — counting steps that will never run made the bar stop short of 100%.
        var steps = ((SlimstatMigration && SlimstatMigration.steps) || []).filter(function (s) {
            return !s.optional;
        });
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
        // Both migration requests: same endpoint, same nonce, same budget. The per-step call
        // is the one that matters — MigrationManager::runOne() is where the ALGORITHM=COPY
        // rebuild happens, so it is the request that meets a proxy's 504, not the final sweep.
        function postMigration(extra) {
            return $.ajax({
                url: SlimstatMigration.ajaxUrl,
                type: "POST",
                data: $.extend({ action: "slimstat_run_migrations", _ajax_nonce: SlimstatMigration.nonce }, extra || {}),
                // Without a timeout the request never settles and the spinner reads as
                // "still working" forever. 15 minutes is a rebuild budget, not a guess.
                timeout: 15 * 60 * 1000
            });
        }

        function transportMessage(xhr, textStatus) {
            if ("timeout" === textStatus) {
                return SlimstatMigration.labels.timedOut;
            }
            return SlimstatMigration.labels.requestFailed + " (" + ((xhr && xhr.status) || textStatus) + ")";
        }

        // One exit for "the run is over". Badge, text and button state are decided here and
        // nowhere else — two hand-written copies is how the else branch came to claim success.
        function finishRun(state, message) {
            updateStatus(message);
            $("#slimstat-back-dashboard").show();
            $("#slimstat-start-migration").toggle("error" === state);
            $(".spinner").removeClass("is-active");
            setStatusBadge(state);
            setStatusText("error" === state ? "failed" : "done");
            updateMetrics(done, total, startTs);
            clearInterval(elapsedTimer);
        }

        function next() {
            if (i >= total) {
                // All individual steps completed, now run the final migration
                postMigration().done(function (resp) {
                    var success = !!(resp && resp.success);
                    var data = resp && resp.data;

                    if (success && data && data.all_complete) {
                        finishRun("success", data.message || SlimstatMigration.labels.allFinished);

                        // Show completion message and redirect after a delay
                        setTimeout(function () {
                            alert(data.message || SlimstatMigration.labels.allFinished);
                            window.location.href = $("#slimstat-back-dashboard").attr("href") || "admin.php?page=" + ((wp_slimstat_admin && wp_slimstat_admin.main_menu_slug) || "slimview1");
                        }, 2000);
                    } else {
                        // Reached exactly when the run did not complete.
                        finishRun("error", (data && data.message) || SlimstatMigration.labels.notComplete);
                    }
                }).fail(function (xhr, textStatus) {
                    finishRun("error", transportMessage(xhr, textStatus));
                });
                return;
            }
            var step = steps[i];
            var $row = $("#slimstat-step-" + step.id + " .status");
            $row.html('<span style="color:#0073aa;">' + SlimstatMigration.labels.inProgress + '</span> <span class="spinner is-active"></span>');
            postMigration({ migration: step.id }).done(function (resp) {
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
            }).fail(function (xhr, textStatus) {
                $row.html('<span style="color:red;">' + SlimstatMigration.labels.failed + "</span>");
                // Deliberately does NOT call next(). A transport failure means this step's
                // outcome is unknown — it may still be running server-side — and starting the
                // next rebuild concurrently with one that may be alive is the exact contention
                // the single-flight claim exists to prevent.
                finishRun("error", transportMessage(xhr, textStatus));
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

        // One OFFERED step, started deliberately by the admin. Posts the same endpoint with the
        // same nonce and the same 15-minute budget as a step inside the run loop — the work is
        // identical, only the decision to do it differs.
        $(document).on("click", ".slimstat-run-step", function (e) {
            e.preventDefault();

            var $btn = $(this).prop("disabled", true);
            var id = $btn.data("migration");
            var $status = $("#slimstat-step-" + id + " .status");

            $status.html(
                '<span style="color:#0073aa;">' + SlimstatMigration.labels.inProgress + "</span> " +
                '<span class="spinner is-active"></span>'
            );

            $.ajax({
                url: SlimstatMigration.ajaxUrl,
                type: "POST",
                data: { action: "slimstat_run_migrations", _ajax_nonce: SlimstatMigration.nonce, migration: id },
                timeout: 15 * 60 * 1000
            })
                .done(function (resp) {
                    var ok = !!(resp && resp.success);
                    $status.html(
                        ok
                            ? '<span style="color:green;">' + SlimstatMigration.labels.done + "</span>"
                            : '<span style="color:red;">' + SlimstatMigration.labels.failed + "</span>"
                    );
                    // Re-enabled only on failure, so a completed step cannot be started twice by
                    // a second click while the page still shows it.
                    $btn.prop("disabled", ok);
                })
                .fail(function (xhr, textStatus) {
                    $status.html(
                        '<span style="color:red;">' + SlimstatMigration.labels.failed + "</span> " +
                        '<span style="color:#666;">' +
                        ("timeout" === textStatus
                            ? SlimstatMigration.labels.timedOut
                            : SlimstatMigration.labels.requestFailed) +
                        "</span>"
                    );
                    $btn.prop("disabled", false);
                });
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
