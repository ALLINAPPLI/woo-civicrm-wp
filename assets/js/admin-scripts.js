jQuery(document).ready(function ($) {
  console.log("CiviCRM ADMIN-SCRIPT.JS root loaded"); // Add debug logging

  // Tab functionality - simplified
  function setupTabs() {
    console.log("Setting up tabs");

    // Hide all tabs first
    $(".wc-civicrm-tab-content").hide();

    // Show only the first tab by default
    $(".wc-civicrm-tab-content:first").show();
    $(".wc-civicrm-tab-button:first").addClass("active");

    // Handle tab clicks
    $(".wc-civicrm-tab-button").on("click", function (e) {
      e.preventDefault();
      var targetTab = $(this).data("tab");

      console.log("Tab clicked:", targetTab);

      // Deactivate all tabs
      $(".wc-civicrm-tab-button").removeClass("active");
      $(".wc-civicrm-tab-content").hide();

      // Activate clicked tab
      $(this).addClass("active");
      $("#" + targetTab).show();
    });
  }

  // Initialize tabs
  setupTabs();

  // Password toggle
  $(".toggle-password").on("click", function () {
    var targetId = $(this).data("target");
    var input = $("#" + targetId);
    var icon = $(this).find(".dashicons");

    if (input.attr("type") === "password") {
      input.attr("type", "text");
      icon.removeClass("dashicons-visibility").addClass("dashicons-hidden");
    } else {
      input.attr("type", "password");
      icon.removeClass("dashicons-hidden").addClass("dashicons-visibility");
    }
  });

  // Test connection handling
  $("#test-civicrm-connection").on("click", function () {
    var button = $(this);
    var resultDiv = $("#connection-test-result");

    // Set status to connecting/testing
    updateConnectionStatus("connecting", "Testing connection...");

    button.addClass("updating-message").prop("disabled", true);
    resultDiv.removeClass("success error").html("Testing connection...");

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "test_civicrm_connection",
        nonce: wc_civicrm_admin_params.test_connection_nonce,
      },
      success: function (response) {
        button.removeClass("updating-message").prop("disabled", false);

        if (response.success) {
          resultDiv
            .addClass("success")
            .html(
              '<span class="dashicons dashicons-yes-alt"></span> ' +
                response.data.message
            );
          updateConnectionStatus("connected", "Connected");
        } else {
          resultDiv
            .addClass("error")
            .html(
              '<span class="dashicons dashicons-warning"></span> ' +
                response.data.message
            );
          updateConnectionStatus("disconnected", "Disconnected");
        }
      },
      error: function (xhr) {
        button.removeClass("updating-message").prop("disabled", false);

        var errorMsg = "Connection test failed";
        if (xhr.responseJSON && xhr.responseJSON.data) {
          errorMsg = xhr.responseJSON.data.message;
        }

        resultDiv
          .addClass("error")
          .html(
            '<span class="dashicons dashicons-warning"></span> ' + errorMsg
          );
        updateConnectionStatus("disconnected", "Disconnected");
      },
    });
  });

  // Contact creation test
  $("#test-contact-creation").on("click", function () {
    var button = $(this);
    var resultDiv = $("#contact-creation-test-result");

    button.addClass("updating-message").prop("disabled", true);
    resultDiv.removeClass("success error").html("Testing contact creation...");

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "test_contact_creation",
        nonce: wc_civicrm_admin_params.test_contact_creation_nonce,
      },
      success: function (response) {
        button.removeClass("updating-message").prop("disabled", false);

        if (response.success) {
          resultDiv
            .addClass("success")
            .html(
              '<span class="dashicons dashicons-yes-alt"></span> ' +
                response.data.message
            );
        } else {
          resultDiv
            .addClass("error")
            .html(
              '<span class="dashicons dashicons-warning"></span> ' +
                response.data.message
            );
        }
      },
      error: function () {
        button.removeClass("updating-message").prop("disabled", false);
        resultDiv
          .addClass("error")
          .html(
            '<span class="dashicons dashicons-warning"></span> Contact creation test failed'
          );
      },
    });
  });

  // Contribution creation test
  $("#test-contribution-creation").on("click", function () {
    var button = $(this);
    var resultDiv = $("#contribution-creation-test-result");

    button.addClass("updating-message").prop("disabled", true);
    resultDiv
      .removeClass("success error")
      .html("Testing contribution creation...");

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "test_contribution_creation",
        nonce: wc_civicrm_admin_params.test_contribution_creation_nonce,
      },
      success: function (response) {
        button.removeClass("updating-message").prop("disabled", false);

        if (response.success) {
          resultDiv
            .addClass("success")
            .html(
              '<span class="dashicons dashicons-yes-alt"></span> ' +
                response.data.message
            );
        } else {
          resultDiv
            .addClass("error")
            .html(
              '<span class="dashicons dashicons-warning"></span> ' +
                response.data.message
            );
        }
      },
      error: function () {
        button.removeClass("updating-message").prop("disabled", false);
        resultDiv
          .addClass("error")
          .html(
            '<span class="dashicons dashicons-warning"></span> Contribution creation test failed'
          );
      },
    });
  });

  // Add mapping
  $("#add-mapping").on("click", function () {
    $("#field-mappings-table tbody").append(getNewRow());
  });

  // Remove mapping
  $(document).on("click", ".remove-mapping", function () {
    $(this).closest("tr").remove();
  });

  // Fetch fields
  $("#fetch-fields").on("click", function () {
    var button = $(this);
    var resultSpan = $("#fetch-result");

    button.addClass("updating-message").prop("disabled", true);
    resultSpan.html(
      '<span class="spinner is-active"></span> Fetching fields...'
    );

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "fetch_civicrm_fields",
        _ajax_nonce: wc_civicrm_admin_params.fetch_fields_nonce,
      },
      success: function (response) {
        button.removeClass("updating-message").prop("disabled", false);

        if (response.success) {
          resultSpan.html(
            '<span class="dashicons dashicons-yes-alt success"></span> Fields fetched successfully'
          );
          updateFieldSelects(response.data);
        } else {
          resultSpan.html(
            '<span class="dashicons dashicons-warning error"></span> ' +
              response.data
          );
        }

        setTimeout(function () {
          resultSpan.html("");
        }, 5000);
      },
      error: function () {
        button.removeClass("updating-message").prop("disabled", false);
        resultSpan.html(
          '<span class="dashicons dashicons-warning error"></span> Network error'
        );

        setTimeout(function () {
          resultSpan.html("");
        }, 5000);
      },
    });
  });

  function getNewRow() {
    // This would be populated from PHP via wp_localize_script in a real implementation
    const wc_fields = {
      billing_first_name: "Billing First Name",
      billing_last_name: "Billing Last Name",
      billing_email: "Billing Email",
      // etc.
    };

    let options = '<option value="">Select WooCommerce Field</option>';
    for (const [value, label] of Object.entries(wc_fields)) {
      options += `<option value="${value}">${label}</option>`;
    }

    const rowId = "new_" + Math.random().toString(36).substr(2, 9);
    return `
            <tr>
                <td>
                    <select name="wc_civicrm_field_mappings[${rowId}][wc_field]" class="regular-text">
                        ${options}
                    </select>
                </td>
                <td>
                    <select name="wc_civicrm_field_mappings[${rowId}][civicrm]" 
                            class="regular-text civicrm-field-select"
                            data-original-value=""
                            data-original-type="">
                        <option value="">Select CiviCRM Field</option>
                    </select>
                    <input type="hidden" name="wc_civicrm_field_mappings[${rowId}][type]" 
                           class="field-type-input" value="">
                </td>
                <td class="field-type">
                    <span class="field-type-badge"></span>
                </td>
                <td>
                    <button type="button" class="button button-small remove-mapping">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>`;
  }

  function updateFieldSelects(fields) {
    const contactFields = fields.contact_fields || [];
    const contributionFields = fields.contribution_fields || [];

    $(".civicrm-field-select").each(function () {
      const select = $(this);
      const originalValue = select.data("original-value");
      const originalType = select.data("original-type");
      const typeInput = select.siblings(".field-type-input");

      // Clear existing options except the first one
      select.find("option:not(:first)").remove();

      // Add option groups
      select.append('<optgroup label="Contact Fields">');
      contactFields.forEach(function (field) {
        select.append(
          `<option value="${field.name}" data-type="Contact">${field.label}</option>`
        );
      });
      select.append("</optgroup>");

      select.append('<optgroup label="Contribution Fields">');
      contributionFields.forEach(function (field) {
        select.append(
          `<option value="${field.name}" data-type="Contribution">${field.label}</option>`
        );
      });
      select.append("</optgroup>");

      // Re-select original value if it exists
      if (originalValue) {
        select.val(originalValue);
      }

      // Set change event to update field type
      select.off("change").on("change", function () {
        const selected = $(this).find("option:selected");
        const fieldType = selected.data("type") || "";
        typeInput.val(fieldType);
        select.closest("tr").find(".field-type-badge").text(fieldType);
      });
    });
  }

  // Update connection status indicators
  function updateConnectionStatus(status, statusText) {
    $("#main-connection-indicator, #connection-indicator")
      .removeClass("connected disconnected connecting")
      .addClass(status);

    $("#main-connection-status-text, #connection-status-text").text(statusText);

    $(".wc-civicrm-connection-status")
      .removeClass("connected disconnected")
      .addClass(status);
  }

  // Initial connection check on page load
  var initialStatus = $(".connection-indicator").first().attr("class");
  if (initialStatus && initialStatus.indexOf("unknown") === -1) {
    // Use existing status
  } else {
    // Auto-check connection if status is unknown
    setTimeout(function () {
      $("#test-civicrm-connection").trigger("click");
    }, 1000);
  }

  // Handle financial types refresh
  $("#refresh-financial-types").on("click", function (e) {
    e.preventDefault();

    const $button = $(this);
    const $message = $("#financial-types-message");

    // Update button text and disable it
    $button.text("Loading...").prop("disabled", true);
    $message.html("").removeClass("notice-success notice-error");

    // Send AJAX request
    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "fetch_financial_types",
        nonce: wc_civicrm_admin_params.fetch_financial_types_nonce,
      },
      success: function (response) {
        if (response.success) {
          // Update the dropdown with new options
          const $select = $("#wc_civicrm_contribution_type_id");
          const currentValue = $select.val();

          $select.empty();

          if (response.data.types && response.data.types.length > 0) {
            $.each(response.data.types, function (index, type) {
              const selected = type.id == currentValue ? "selected" : "";
              $select.append(
                `<option value="${type.id}" ${selected}>${type.name}</option>`
              );
            });
          } else {
            $select.append("<option value='1'>Donation</option>");
          }

          $message
            .addClass("notice-success")
            .html("<p>" + response.data.message + "</p>");
        } else {
          $message
            .addClass("notice-error")
            .html("<p>Error: " + response.data.message + "</p>");
        }
      },
      error: function (xhr, status, error) {
        $message
          .addClass("notice-error")
          .html("<p>Error: Unable to connect to server</p>");
      },
      complete: function () {
        // Restore button text and enable it
        $button.text("Refresh Types").prop("disabled", false);
      },
    });
  });

  // Handle save contribution settings button
  $("#save-contribution-settings").on("click", function (e) {
    e.preventDefault();

    const financialTypeId = $("#wc_civicrm_contribution_type_id").val();

    // Send AJAX request to save settings
    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "update_option",
        option_name: "wc_civicrm_contribution_type_id",
        option_value: financialTypeId,
        nonce: wc_civicrm_admin_params.update_option_nonce,
      },
      success: function (response) {
        if (response.success) {
          alert("Settings saved successfully!");
        } else {
          alert("Error saving settings: " + response.data.message);
        }
      },
      error: function () {
        alert("Error connecting to server");
      },
    });
  });

  // Payment method mapping (WooCommerce ↔ CiviCRM payment instruments)
  (function initPaymentMethodMapping() {
    if (!$("#payment-method-mappings-table").length) {
      return;
    }

    let wcMethods = wc_civicrm_admin_params.wc_payment_methods || {};
    let instruments = wc_civicrm_admin_params.payment_instruments || [];

    function escapeHtml(text) {
      return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function buildWcOptions(selected) {
      let html = '<option value="">Sélectionner…</option>';
      Object.keys(wcMethods).forEach(function (id) {
        const sel = id === selected ? " selected" : "";
        html +=
          '<option value="' +
          escapeHtml(id) +
          '"' +
          sel +
          ">" +
          escapeHtml(wcMethods[id] + " (" + id + ")") +
          "</option>";
      });
      return html;
    }

    function buildInstrumentOptions(selected) {
      let html = '<option value="">Sélectionner…</option>';
      (instruments || []).forEach(function (item) {
        const sel = String(item.value) === String(selected) ? " selected" : "";
        html +=
          '<option value="' +
          escapeHtml(item.value) +
          '"' +
          sel +
          ">" +
          escapeHtml(item.label + " (#" + item.value + ")") +
          "</option>";
      });
      return html;
    }

    function refreshInstrumentSelects() {
      $(".civicrm-payment-instrument-select").each(function () {
        const current = $(this).val();
        $(this).html(buildInstrumentOptions(current));
        if (current) {
          $(this).val(current);
        }
      });
    }

    function getNewPaymentRow() {
      const rowId = "new_" + Math.random().toString(36).substr(2, 9);
      return `
        <tr>
          <td>
            <select name="wc_civicrm_payment_method_map[${rowId}][wc_method]" class="regular-text">
              ${buildWcOptions("")}
            </select>
          </td>
          <td>
            <select name="wc_civicrm_payment_method_map[${rowId}][civicrm_instrument]" class="regular-text civicrm-payment-instrument-select">
              ${buildInstrumentOptions("")}
            </select>
          </td>
          <td>
            <button type="button" class="button button-small remove-payment-mapping">
              <span class="dashicons dashicons-trash"></span>
            </button>
          </td>
        </tr>
      `;
    }

    $("#add-payment-mapping").on("click", function () {
      $("#payment-method-mappings-table tbody").append(getNewPaymentRow());
    });

    $(document).on("click", ".remove-payment-mapping", function () {
      $(this).closest("tr").remove();
    });

    if ($("#payment-method-mappings-table tbody tr").length === 0) {
      $("#add-payment-mapping").trigger("click");
    }

    $("#refresh-payment-instruments").on("click", function (e) {
      e.preventDefault();
      const $button = $(this);
      const $message = $("#payment-instruments-message");
      $button.prop("disabled", true);
      $message.text("Chargement…");

      $.ajax({
        url: ajaxurl,
        type: "POST",
        data: {
          action: "fetch_payment_instruments",
          nonce: wc_civicrm_admin_params.fetch_payment_instruments_nonce,
        },
        success: function (response) {
          $button.prop("disabled", false);
          if (response.success && response.data.instruments) {
            instruments = response.data.instruments;
            wc_civicrm_admin_params.payment_instruments = instruments;
            refreshInstrumentSelects();
            $message.text(
              response.data.message || "Instruments mis à jour."
            );
          } else {
            $message.text(
              response.data && response.data.message
                ? response.data.message
                : "Erreur"
            );
          }
        },
        error: function () {
          $button.prop("disabled", false);
          $message.text("Erreur AJAX");
        },
      });
    });
  })();
});
