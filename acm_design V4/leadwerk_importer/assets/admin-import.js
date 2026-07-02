(function () {
  const config = window.leadwerkImporter || {};
  const root = document.querySelector("[data-leadwerk-importer-app]");
  if (!root) {
    return;
  }

  let currentState = config.state || {};
  let isStepping = false;

  const els = {
    status: root.querySelector("[data-import-status]"),
    step: root.querySelector("[data-import-step]"),
    item: root.querySelector("[data-import-item]"),
    overallFill: root.querySelector("[data-import-overall-fill]"),
    overallPercent: root.querySelector("[data-import-overall-percent]"),
    steps: root.querySelector("[data-import-steps]"),
    success: root.querySelector("[data-import-success]"),
    warnings: root.querySelector("[data-import-warnings]"),
    errors: root.querySelector("[data-import-errors]"),
    log: root.querySelector("[data-import-log]"),
    summary: root.querySelector("[data-import-summary]"),
  };

  const startButtons = document.querySelectorAll("[data-leadwerk-start-import]");
  const resetButton = document.querySelector("[data-leadwerk-reset-progress]");

  function api(action, data) {
    const body = new FormData();
    body.append("action", action);
    body.append("nonce", config.nonce || "");

    Object.keys(data || {}).forEach(function (key) {
      body.append(key, data[key]);
    });

    return fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body,
    }).then(function (response) {
      return response.text().then(function (text) {
        let parsed = null;
        if (text) {
          try {
            parsed = JSON.parse(text);
          } catch (e) {
            parsed = null;
          }
        }
        if (parsed && typeof parsed.success !== "undefined") {
          return parsed;
        }
        const err = new Error("Ungueltige Server-Antwort (HTTP " + response.status + ")");
        err.httpStatus = response.status;
        err.rawBody = text || "";
        throw err;
      });
    });
  }

  function applyFailedStateFromResponse(result, fallbackMessage) {
    const msg =
      (result && result.data && result.data.message) ||
      fallbackMessage ||
      "Import-Schritt fehlgeschlagen.";
    if (result && result.data && result.data.state) {
      render(result.data.state);
    } else {
      currentState = Object.assign({}, currentState, {
        status: "failed",
        current_item: msg,
      });
      render(currentState);
    }
    if (els.log) {
      const row =
        '<div class="leadwerk-importer-log__row leadwerk-importer-log__row--error">' +
        '<span class="leadwerk-importer-log__message">' +
        escapeHtml(msg) +
        (result && result.data && result.data.technical
          ? " — " + escapeHtml(result.data.technical)
          : "") +
        "</span></div>";
      if (els.log.querySelector(".leadwerk-importer-log__empty")) {
        els.log.innerHTML = "";
      }
      els.log.insertAdjacentHTML("afterbegin", row);
    }
  }

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function stateIsRunning(state) {
    return state && (state.status === "running" || state.status === "booting");
  }

  function renderSteps(steps, currentStep) {
    const entries = Object.entries(steps || {});
    if (!entries.length || !els.steps) {
      return;
    }

    els.steps.innerHTML = entries
      .map(function ([key, step]) {
        const percent = Number(step.step_percent || 0);
        const status = String(step.status || "pending");
        const isActive = key === currentStep;
        return (
          '<div class="leadwerk-importer-step leadwerk-importer-step--' +
          escapeHtml(status) +
          (isActive ? " is-active" : "") +
          '">' +
          '<div class="leadwerk-importer-step__head">' +
          "<strong>" + escapeHtml(step.label || key) + "</strong>" +
          "<span>" + escapeHtml(status) + "</span>" +
          "</div>" +
          '<div class="leadwerk-importer-step__meta">' +
          "<span>" + escapeHtml(String(step.processed || 0)) + " / " + escapeHtml(String(step.total || 0)) + "</span>" +
          "<span>" + escapeHtml(String(percent)) + "%</span>" +
          "</div>" +
          '<div class="leadwerk-importer-step__bar"><div class="leadwerk-importer-step__fill" style="width:' + percent + '%"></div></div>' +
          "</div>"
        );
      })
      .join("");
  }

  function renderLog(logTail) {
    if (!els.log) {
      return;
    }

    const rows = Array.isArray(logTail) ? logTail : [];
    if (!rows.length) {
      els.log.innerHTML = '<div class="leadwerk-importer-log__empty">' + escapeHtml((config.strings || {}).idle || "Idle") + "</div>";
      return;
    }

    els.log.innerHTML = rows
      .map(function (row) {
        return (
          '<div class="leadwerk-importer-log__row leadwerk-importer-log__row--' + escapeHtml(row.level || "info") + '">' +
          '<span class="leadwerk-importer-log__time">' + escapeHtml(row.time || "") + "</span>" +
          '<span class="leadwerk-importer-log__message">' + escapeHtml(row.message || row.line || "") + "</span>" +
          "</div>"
        );
      })
      .join("");
  }

  function renderSummary(state) {
    if (!els.summary) {
      return;
    }

    const blocking = (((state || {}).results || {}).blocking) || [];
    const pages = (((state || {}).results || {}).pages) || {};
    const rows = Object.keys(pages)
      .slice(0, 12)
      .map(function (key) {
        const page = pages[key] || {};
        const de = page.de || {};
        const en = page.en || {};
        const payloadValidation = de.payload_validation || {};
        const readbackValidation = de.readback_validation || {};
        const details = de.failure_details || {};
        const layoutDiagnostics = Array.isArray(de.layout_diagnostics) ? de.layout_diagnostics : [];
        const layoutLines = layoutDiagnostics
          .slice(0, 6)
          .map(function (item) {
            return (
              '<li><strong>' + escapeHtml(item.label || item.layout_key || "-") + '</strong>: ' +
              escapeHtml(item.matched_by || "index") +
              (item.selector_used ? " via " + escapeHtml(item.selector_used) : "") +
              ", visible=" + escapeHtml(item.layout_has_visible_content ? "yes" : "no") +
              ", score=" + escapeHtml(String(item.visible_content_score || 0)) +
              "</li>"
            );
          })
          .join("");
        const expectedLayouts = details.expected_sections || payloadValidation.expected_layout_count || 0;
        const matchedMeta = Number(details.matched_sections || 0);
        let matchedLabel = "matched " + String(matchedMeta);
        if (layoutDiagnostics.length > 0 && matchedMeta === 0) {
          matchedLabel =
            "matched 0 (counts diagnostic[found] only; see layout list below if non-empty)";
        } else if (layoutDiagnostics.length === 0) {
          const parsed = payloadValidation.parsed_section_count;
          matchedLabel =
            "matched n/a" +
            (parsed != null && parsed !== ""
              ? " (parsed HTML sections: " + String(parsed) + ")"
              : " (no layout diagnostics in response)");
        }
        const debugBits = [
          "expected " + String(expectedLayouts),
          matchedLabel,
          "non-empty " + String(details.non_empty_layouts || readbackValidation.non_empty_layout_count || payloadValidation.non_empty_layout_count || 0),
        ];
        if (Array.isArray(details.empty_layouts) && details.empty_layouts.length) {
          debugBits.push("empty " + details.empty_layouts.slice(0, 4).join(", "));
        }
        const deReason =
          de.field_status && de.field_status !== "success" && de.failure_reason
            ? " (" + de.failure_reason + ")"
            : "";
        const enReason = en.blocked_reason ? " (" + en.blocked_reason + ")" : "";
        const hasDetails = layoutLines || de.field_message || en.translation_message || debugBits.length;
        return (
          '<div class="leadwerk-importer-summary__row">' +
          '<div class="leadwerk-importer-summary__row-head">' +
          '<strong>' + escapeHtml(page.title || key) + "</strong>" +
          '<span>DE: ' + escapeHtml(de.field_status || "-") + escapeHtml(deReason) + "</span>" +
          '<span>EN: ' + escapeHtml(en.translation_status || "-") + escapeHtml(enReason) + "</span>" +
          "</div>" +
          (hasDetails
            ? '<details class="leadwerk-importer-summary__details"><summary>Details</summary>' +
              '<div class="leadwerk-importer-summary__meta">' + escapeHtml(debugBits.join(" | ")) + "</div>" +
              (de.field_message ? '<div class="leadwerk-importer-summary__message"><strong>DE</strong>: ' + escapeHtml(de.field_message) + "</div>" : "") +
              (en.translation_message ? '<div class="leadwerk-importer-summary__message"><strong>EN</strong>: ' + escapeHtml(en.translation_message) + "</div>" : "") +
              (layoutLines ? '<ul class="leadwerk-importer-summary__list">' + layoutLines + "</ul>" : "") +
              "</details>"
            : "") +
          "</div>"
        );
      });

    const blockingHtml = blocking.length
      ? '<div class="leadwerk-importer-summary__block leadwerk-importer-summary__block--errors"><strong>Blocking issues</strong><ul>' +
        blocking.map(function (item) { return "<li>" + escapeHtml(item) + "</li>"; }).join("") +
        "</ul></div>"
      : "";

    els.summary.innerHTML =
      blockingHtml +
      '<div class="leadwerk-importer-summary__block"><strong>Page results</strong>' +
      (rows.length ? rows.join("") : "<p>No page results yet.</p>") +
      "</div>";
  }

  function render(state) {
    currentState = state || {};

    if (els.status) {
      els.status.textContent = currentState.status || "idle";
    }
    if (els.step) {
      els.step.textContent = currentState.current_step || "preflight";
    }
    if (els.item) {
      els.item.textContent = currentState.current_item || "";
    }
    if (els.overallFill) {
      els.overallFill.style.width = String(Number(currentState.overall_percent || 0)) + "%";
    }
    if (els.overallPercent) {
      els.overallPercent.textContent = String(Number(currentState.overall_percent || 0));
    }
    if (els.success) {
      els.success.textContent = String(Number(currentState.success_count || 0));
    }
    if (els.warnings) {
      els.warnings.textContent = String(Number(currentState.warning_count || 0));
    }
    if (els.errors) {
      els.errors.textContent = String(Number(currentState.error_count || 0));
    }

    renderSteps(currentState.steps || {}, currentState.current_step || "");
    renderLog(currentState.log_tail || []);
    renderSummary(currentState);

    startButtons.forEach(function (button) {
      button.disabled = stateIsRunning(currentState);
    });
    if (resetButton) {
      resetButton.disabled = stateIsRunning(currentState);
    }
  }

  function stepLoop() {
    if (isStepping || !stateIsRunning(currentState)) {
      return;
    }

    isStepping = true;
    api("leadwerk_import_step", {})
      .then(function (result) {
        if (result && result.success) {
          render(result.data.state || {});
        } else {
          applyFailedStateFromResponse(result, "Import-Schritt abgelehnt.");
        }
      })
      .catch(function (err) {
        var fallback = (err && err.message) ? err.message : "Netzwerk- oder Serverfehler.";
        if (err && err.rawBody) {
          try {
            var j = JSON.parse(err.rawBody);
            if (j && j.data && j.data.state) {
              applyFailedStateFromResponse(j, fallback);
              return;
            }
            if (j && j.data && j.data.message) {
              fallback = j.data.message;
            }
          } catch (e2) {
            /* ignore */
          }
        }
        currentState = Object.assign({}, currentState, {
          status: "failed",
          current_item: fallback,
        });
        render(currentState);
        if (els.log) {
          var row2 =
            '<div class="leadwerk-importer-log__row leadwerk-importer-log__row--error">' +
            '<span class="leadwerk-importer-log__message">' +
            escapeHtml(fallback) +
            "</span></div>";
          if (els.log.querySelector(".leadwerk-importer-log__empty")) {
            els.log.innerHTML = "";
          }
          els.log.insertAdjacentHTML("afterbegin", row2);
        }
      })
      .finally(function () {
        isStepping = false;
        if (stateIsRunning(currentState)) {
          window.setTimeout(stepLoop, 180);
        }
      });
  }

  function refreshState() {
    api("leadwerk_import_state", {})
      .then(function (result) {
        if (result && result.success) {
          render(result.data.state || {});
          if (stateIsRunning(currentState)) {
            stepLoop();
          }
        }
      })
      .catch(function (err) {
        var fb = (err && err.message) ? err.message : "Status-Anfrage fehlgeschlagen.";
        if (els.log) {
          var row3 =
            '<div class="leadwerk-importer-log__row leadwerk-importer-log__row--error">' +
            '<span class="leadwerk-importer-log__message">' +
            escapeHtml(fb) +
            "</span></div>";
          if (els.log.querySelector(".leadwerk-importer-log__empty")) {
            els.log.innerHTML = "";
          }
          els.log.insertAdjacentHTML("afterbegin", row3);
        }
      });
  }

  startButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      const mode = button.getAttribute("data-leadwerk-start-import");
      api("leadwerk_import_start", {
        dry_run: mode === "dry-run" ? "1" : "",
      })
        .then(function (result) {
          if (result && result.success) {
            render(result.data.state || {});
            stepLoop();
          } else {
            applyFailedStateFromResponse(result, "Import konnte nicht gestartet werden.");
          }
        })
        .catch(function (err) {
          var fb = (err && err.message) ? err.message : "Start fehlgeschlagen.";
          currentState = Object.assign({}, currentState, {
            status: "failed",
            current_item: fb,
          });
          render(currentState);
        });
    });
  });

  if (resetButton) {
    resetButton.addEventListener("click", function () {
      api("leadwerk_import_reset", {}).then(function (result) {
        if (result && result.success) {
          render(result.data.state || {});
        }
      });
    });
  }

  render(currentState);
  refreshState();
})();
