(function () {
    function showBatchPaused(notice, form) {
        var message = notice.querySelector("p");

        if (!message) {
            return;
        }

        message.innerHTML = "<strong>Batch paused.</strong> Generated items remain saved. ";

        if (!form) {
            return;
        }

        var resume = document.createElement("a");
        resume.href = "#";
        resume.textContent = "Resume";
        resume.addEventListener("click", function (event) {
            event.preventDefault();
            form.submit();
        });

        message.appendChild(resume);
    }

    function initAutoBatching() {
        document.querySelectorAll(".bcm-auto-batch-notice").forEach(function (notice) {
            var form = document.getElementById(notice.getAttribute("data-form-id"));
            var timer = null;

            if (form) {
                timer = window.setTimeout(function () {
                    form.submit();
                }, 1800);
            }

            var pause = notice.querySelector(".bcm-pause-batch");
            if (!pause) {
                return;
            }

            pause.addEventListener("click", function (event) {
                event.preventDefault();

                if (timer) {
                    window.clearTimeout(timer);
                }

                showBatchPaused(notice, form);
            });
        });
    }

    function getAjaxGeneratorConfig(form) {
        return {
            action: form.getAttribute("data-bcm-ajax-action"),
            offsetField: form.getAttribute("data-bcm-offset-field"),
            submitName: form.getAttribute("data-bcm-submit-name"),
            itemLabel: form.getAttribute("data-bcm-ajax-generator") === "posts" ? "posts" : "terms"
        };
    }

    function ensureAjaxBatchNotice(form) {
        var notice = form.parentNode.querySelector(".bcm-ajax-batch-notice");

        if (notice) {
            return notice;
        }

        notice = document.createElement("div");
        notice.className = "notice notice-warning bcm-ajax-batch-notice";
        form.parentNode.insertBefore(notice, form);

        return notice;
    }

    function renderAjaxBatchNotice(notice, state, data) {
        data = data || state.lastData;

        var batchText = data
            ? "Batch " + data.currentBatch + " of " + data.totalBatches + " complete. Processed " + data.processed + " of " + data.total + " " + data.itemLabel + "."
            : (window.bcmAdmin && window.bcmAdmin.strings ? window.bcmAdmin.strings.starting : "Preparing generation...");
        var statusText = "";

        if (state.stopped) {
            statusText = window.bcmAdmin && window.bcmAdmin.strings ? window.bcmAdmin.strings.stopping : "Stopping after the current batch...";
        } else if (state.paused) {
            statusText = window.bcmAdmin && window.bcmAdmin.strings ? window.bcmAdmin.strings.paused : "Batch generation paused.";
        } else if (data && data.complete) {
            statusText = window.bcmAdmin && window.bcmAdmin.strings ? window.bcmAdmin.strings.complete : "Final batch complete. Reloading the report...";
        } else if (data) {
            statusText = "Next batch starts automatically.";
        }

        notice.innerHTML = '<p><strong>' + batchText + '</strong> ' + statusText + '</p>'
            + '<p><a href="#" class="bcm-ajax-pause">' + (state.paused ? "Resume" : "Pause") + '</a> | <a href="#" class="bcm-ajax-stop">Stop</a></p>';
    }

    function setAjaxGeneratorDisabled(form, disabled) {
        var submit = form.querySelector('[type="submit"]');

        if (submit) {
            submit.disabled = disabled;
        }
    }

    function redirectStoppedGeneration(state) {
        window.location.assign(state.stopUrl || window.location.href);
    }

    function runAjaxGeneratorBatch(form, state, config, notice) {
        if (state.running || state.paused || state.stopped) {
            return;
        }

        var body = new FormData(form);

        body.set("action", config.action);
        body.set(config.submitName, "1");
        body.set(config.offsetField, state.offset);

        if (state.runId) {
            body.set("bcm_generation_run_id", state.runId);
        }

        state.running = true;

        fetch(window.bcmAdmin.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: body
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (response) {
                var data;

                state.running = false;

                if (!response || !response.success) {
                    throw new Error(response && response.data && response.data.message ? response.data.message : "Generation failed.");
                }

                data = response.data;
                state.runId = data.runId;
                state.offset = data.nextOffset;
                state.stopUrl = data.stopUrl;
                state.lastData = data;
                renderAjaxBatchNotice(notice, state, data);

                if (state.stopped) {
                    redirectStoppedGeneration(state);
                    return;
                }

                if (data.complete) {
                    window.setTimeout(function () {
                        window.location.assign(data.redirectUrl || window.location.href);
                    }, 700);
                    return;
                }

                if (state.paused) {
                    return;
                }

                window.setTimeout(function () {
                    runAjaxGeneratorBatch(form, state, config, notice);
                }, 350);
            })
            .catch(function (error) {
                state.running = false;
                setAjaxGeneratorDisabled(form, false);
                notice.classList.remove("notice-warning");
                notice.classList.add("notice-error");
                notice.innerHTML = '<p><strong>' + (window.bcmAdmin && window.bcmAdmin.strings ? window.bcmAdmin.strings.failed : "Generation could not continue.") + '</strong> ' + error.message + '</p>';
            });
    }

    function initAjaxGenerators() {
        if (!window.bcmAdmin || !window.bcmAdmin.ajaxUrl || !window.FormData || !window.fetch) {
            return;
        }

        document.querySelectorAll("form[data-bcm-ajax-generator]").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                var config = getAjaxGeneratorConfig(form);
                var notice = ensureAjaxBatchNotice(form);
                var state = {
                    offset: 0,
                    runId: "",
                    stopUrl: "",
                    lastData: null,
                    paused: false,
                    stopped: false,
                    running: false
                };

                if (!config.action || !config.offsetField || !config.submitName) {
                    return;
                }

                event.preventDefault();
                setAjaxGeneratorDisabled(form, true);
                renderAjaxBatchNotice(notice, state, null);

                notice.onclick = function (clickEvent) {
                    if (clickEvent.target.classList.contains("bcm-ajax-pause")) {
                        clickEvent.preventDefault();
                        state.paused = !state.paused;
                        renderAjaxBatchNotice(notice, state, null);

                        if (!state.paused) {
                            runAjaxGeneratorBatch(form, state, config, notice);
                        }
                    }

                    if (clickEvent.target.classList.contains("bcm-ajax-stop")) {
                        clickEvent.preventDefault();
                        state.stopped = true;
                        renderAjaxBatchNotice(notice, state, null);

                        if (!state.running) {
                            redirectStoppedGeneration(state);
                        }
                    }
                };

                runAjaxGeneratorBatch(form, state, config, notice);
            });
        });
    }

    function initCollapsibleResults() {
        document.querySelectorAll(".bcm-see-more").forEach(function (link) {
            link.addEventListener("click", function (event) {
                event.preventDefault();

                var list = document.getElementById(link.getAttribute("data-target"));
                if (!list) {
                    return;
                }

                list.querySelectorAll(".bcm-extra-result").forEach(function (item) {
                    item.style.display = "";
                });

                link.style.display = "none";

                var less = link.parentNode.querySelector(".bcm-see-less");
                if (less) {
                    less.style.display = "";
                }
            });
        });

        document.querySelectorAll(".bcm-see-less").forEach(function (link) {
            link.addEventListener("click", function (event) {
                event.preventDefault();

                var list = document.getElementById(link.getAttribute("data-target"));
                if (!list) {
                    return;
                }

                list.querySelectorAll(".bcm-extra-result").forEach(function (item) {
                    item.style.display = "none";
                });

                link.style.display = "none";

                var more = link.parentNode.querySelector(".bcm-see-more");
                if (more) {
                    more.style.display = "";
                }
            });
        });
    }

    function optionLabel(option) {
        return option.textContent || option.innerText || "";
    }

    function createToken(option, onRemove) {
        var token = document.createElement("span");
        var label = document.createElement("span");
        var remove = document.createElement("button");

        token.className = "bcm-token";
        label.textContent = optionLabel(option);
        remove.type = "button";
        remove.className = "bcm-token-remove";
        remove.setAttribute("aria-label", "Remove " + optionLabel(option));
        remove.textContent = "x";
        remove.addEventListener("click", function () {
            onRemove(option);
        });

        token.appendChild(label);
        token.appendChild(remove);

        return token;
    }

    function renderTokenPicker(picker, select, search, dropdown, tokens) {
        tokens.innerHTML = "";
        dropdown.innerHTML = "";

        var query = search.value.trim().toLowerCase();
        var matches = [];

        Array.prototype.forEach.call(select.options, function (option) {
            if (option.disabled || !option.value) {
                return;
            }

            if (option.selected) {
                tokens.appendChild(createToken(option, function (selectedOption) {
                    selectedOption.selected = false;
                    renderTokenPicker(picker, select, search, dropdown, tokens);
                }));
                return;
            }

            if (!query || optionLabel(option).toLowerCase().indexOf(query) !== -1) {
                matches.push(option);
            }
        });

        if (!query && matches.length > 20) {
            matches = matches.slice(0, 20);
        }

        matches.forEach(function (option) {
            var item = document.createElement("button");
            item.type = "button";
            item.className = "bcm-token-option";
            item.textContent = optionLabel(option);
            item.addEventListener("click", function () {
                option.selected = true;
                search.value = "";
                renderTokenPicker(picker, select, search, dropdown, tokens);
                search.focus();
            });
            dropdown.appendChild(item);
        });

        picker.classList.toggle("has-results", matches.length > 0);
        picker.classList.toggle("has-tokens", select.selectedOptions.length > 0);
    }

    function initTokenPickers() {
        document.querySelectorAll(".bcm-token-select").forEach(function (select) {
            var existingSearch = document.querySelector('[data-bcm-token-target="' + select.id + '"]');
            var picker = document.createElement("div");
            var tokens = document.createElement("div");
            var search = document.createElement("input");
            var dropdown = document.createElement("div");

            picker.className = "bcm-token-picker";
            tokens.className = "bcm-token-list";
            search.type = "search";
            search.className = "bcm-token-input";
            search.placeholder = select.getAttribute("data-placeholder") || "Search and choose";
            dropdown.className = "bcm-token-dropdown";

            if (existingSearch) {
                existingSearch.parentNode.removeChild(existingSearch);
            }

            picker.appendChild(tokens);
            picker.appendChild(search);
            picker.appendChild(dropdown);
            select.parentNode.insertBefore(picker, select);
            select.classList.add("bcm-token-select-hidden");

            search.addEventListener("input", function () {
                renderTokenPicker(picker, select, search, dropdown, tokens);
            });

            search.addEventListener("focus", function () {
                renderTokenPicker(picker, select, search, dropdown, tokens);
            });

            document.addEventListener("click", function (event) {
                if (!picker.contains(event.target)) {
                    picker.classList.remove("has-results");
                }
            });

            renderTokenPicker(picker, select, search, dropdown, tokens);
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        initAjaxGenerators();
        initAutoBatching();
        initCollapsibleResults();
        initTokenPickers();
    });
}());
