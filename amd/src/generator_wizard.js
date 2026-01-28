define([
  "jquery",
  "core/modal_factory",
  "core/modal_events",
  "core/templates",
  "core/ajax",
  "core/notification",
  "core/str",
], function ($, ModalFactory, ModalEvents, Templates, Ajax, Notification, Str) {
  var GeneratorWizard = {
    modal: null,
    cmid: null,
    slideid: null,
    docid: null,
    pages: [],

    init: function (cmid) {
      this.cmid = cmid;

      // Event delegation for the Generate button on slides page
      $(document).on(
        "click",
        '[data-action="open-generator"]',
        function (e) {
          e.preventDefault();
          var btn = $(e.currentTarget);
          this.slideid = btn.data("slideid");
          this.openWizard();
        }.bind(this),
      );
    },

    openWizard: function () {
      var self = this;

      ModalFactory.create({
        type: ModalFactory.types.DEFAULT,
        large: true,
      })
        .then(function (modal) {
          self.modal = modal;

          // Set Title
          Str.get_string("generatefromdocument", "mod_classengage").then(
            function (title) {
              modal.setTitle(title);
            },
          );

          // Set Body
          Templates.render("mod_classengage/generator_wizard", {
            numquestions_options: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15, 20],
          }).then(function (html, js) {
            modal.setBody(html);
            Templates.runTemplateJS(js);
          });

          // Set Footer
          Templates.render("mod_classengage/generator_wizard_footer", {}).then(
            function (html, js) {
              modal.setFooter(html);
              Templates.runTemplateJS(js);
            },
          );

          modal.show();

          // Start Inspection
          self.inspectDocument();

          // Bind Modal Events
          var root = modal.getRoot();

          // Range Input Handler
          root.on("change", "#id_pagerange", function () {
            self.updateSelectionFromRange($(this).val());
          });

          // Select All Handler
          root.on("change", "#select-all-slides", function () {
            var checked = $(this).is(":checked");
            root.find(".slide-toggle").prop("checked", checked);
          });

          // Select All Images Handler
          root.on("change", "#select-all-images", function () {
            var checked = $(this).is(":checked");
            root.find(".image-toggle").prop("checked", checked);
          });

          // Generate Confirm Click
          // Note: Footer is separate now, so we need to target it specifically or search in root
          // Modal root usually contains footer
          root.on("click", "#btn-generate-confirm", function () {
            self.triggerGeneration();
          });

          // Cancel Button Handler - works at all stages
          root.on("click", '[data-action="cancel"]', function () {
            self.modal.hide();
            $("#generator-floater").remove();
          });

          // Show More Text
          root.on("click", ".show-more-text", function (e) {
            e.preventDefault();
            var link = $(this);
            alert(link.data("fulltext"));
          });

          // Auto-Show Distribution on Dropdown Change
          root.on(
            "change",
            "#id_difficulty, #id_cognitive, #id_numquestions",
            function () {
              self.checkDistributionVisibility();
            },
          );

          // Manual Input Change -> Validate
          root.on("input", ".dist-input", function () {
            self.validateDistribution();
          });
        })
        .fail(Notification.exception);
    },

    inspectDocument: function () {
      var self = this;

      Ajax.call([
        {
          methodname: "mod_classengage_inspect_document", // We don't have a webservice, using direct AJAX script
          args: {}, // Not used for script call
        },
      ]);

      // Using direct fetch to slides_api.php as we did before, but cleaner
      // Moodle 4.x prefers service calls, but we are using existing pattern for now.
      // Actually, we should use Ajax.call if it was a WS. Since it's a script, we use jQuery.ajax
      // BUT core/ajax is better for session handling.
      // Let's use simple $.ajax to our endpoint.

      $.ajax({
        url: M.cfg.wwwroot + "/mod/classengage/slides_api.php",
        data: {
          action: "inspect",
          slideid: self.slideid,
          sesskey: M.cfg.sesskey,
        },
        dataType: "json",
        success: function (response) {
          if (response.success) {
            self.docid = response.docid;
            self.pages = response.pages;
            self.renderPages();
          } else {
            self.modal.setBody(
              '<div class="alert alert-danger">' + response.error + "</div>",
            );
          }
        },
        error: function () {
          self.modal.setBody(
            '<div class="alert alert-danger">Network error during inspection.</div>',
          );
        },
      });
    },

    renderPages: function () {
      var self = this;
      var container = self.modal.getRoot().find("#slides-container");
      container.empty();

      var context = { pages: [] };

      // Process pages for template
      this.pages.forEach(function (page) {
        var shorttext = page.text.replace(/<[^>]+>/g, "").substring(0, 100);
        var images = [];

        if (page.images) {
          page.images.forEach(function (img) {
            // We need safe source for ID
            // We can't use bin2hex easily in JS consistently with PHP without library
            // But we can just use a sanitised string or index if needed.
            // Actually, let's just use the source string and hope it works in HTML attribute (it should if quoted)
            // Wait, previous issue was PHP array keys. We are sending JSON now.
            // JSON keys can be anything. So we don't need hex encoding here!
            images.push({
              source: img.source,
              safe_source: img.source, // No hex needed for JSON payload
              mediaType: img.mediaType,
              data: img.data,
            });
          });
        }

        self.modal
          .getRoot()
          .find("#slides-container")
          .append(
            Templates.render("mod_classengage/generator_slide_row", {
              pagenum: page.page,
              shorttext: shorttext,
              fulltext: page.text, // For popup
              hasmore: page.text.length > 100,
              images: images,
            }),
          );
      });

      // Since Templates.render returns a promise when directly appended, we might have issues?
      // "Templates.render" returns a promise that resolves to (html, js).
      // Better to use renderForPromise logic or wait.

      // Correct approach:
      var promises = [];
      this.pages.forEach(function (page) {
        var shorttext = page.text.replace(/<[^>]+>/g, "").substring(0, 100);
        var images = [];
        if (page.images) {
          page.images.forEach(function (img) {
            images.push({
              imageId: img.imageId || img.source, // Use imageId if available, fallback to source
              source: img.source,
              url: img.url, // URL for image delivery
              label: img.label,
              mediaType: img.mediaType,
              data: img.data, // Legacy Base64 fallback
            });
          });
        }

        promises.push(
          Templates.render("mod_classengage/generator_slide_row", {
            pagenum: page.page,
            shorttext: shorttext,
            fulltext: page.text,
            hasmore: page.text.length > 100,
            images: images,
          }),
        );
      });

      $.when.apply($, promises).done(function () {
        var args = arguments;
        // args is array of [html, js] pairs if multiple, or just [html, js] if one?
        // jQuery when with array is tricky.

        // Let's iterate simply.
        // Actually, renderForPromise is safer if we want to batch.
        // But let's just append sequentially for simplicity in this V1.

        // Reset content
        container.empty();
        // Show content, hide loading
        self.modal.getRoot().find("#generator-loading").addClass("d-none");
        self.modal.getRoot().find("#generator-content").removeClass("d-none");
        self.modal
          .getRoot()
          .find("#btn-generate-confirm")
          .prop("disabled", false);

        for (var i = 0; i < promises.length; i++) {
          // If multiple promises, 'arguments' has subarray for each
          // If one promise, 'arguments' is [html, js]
          var result = promises.length > 1 ? arguments[i] : arguments;
          container.append(result[0]);
          Templates.runTemplateJS(result[1]);
        }
      });
    },

    checkDistributionVisibility: function () {
      var root = this.modal.getRoot();
      var diff = root.find("#id_difficulty").val();
      var bloom = root.find("#id_cognitive").val();
      var num = parseInt(root.find("#id_numquestions").val());

      var showPanel = false;

      // Difficulty Distribution
      if (diff === "mixed") {
        root.find("#difficulty-dist").removeClass("d-none").addClass("d-block");
        showPanel = true;
        // Only distribute if total is 0 (first time show) or if total doesn't match
        if (this.getSum("difficulty") !== num) {
          this.distributeEvenly("difficulty", num);
        }
      } else {
        root.find("#difficulty-dist").removeClass("d-block").addClass("d-none");
      }

      // Bloom Distribution
      if (bloom === "mixed") {
        root.find("#cognitive-dist").removeClass("d-none").addClass("d-block");
        showPanel = true;
        if (this.getSum("bloom") !== num) {
          this.distributeEvenly("bloom", num);
        }
      } else {
        root.find("#cognitive-dist").removeClass("d-block").addClass("d-none");
      }

      if (showPanel) {
        root.find("#advanced-distribution-panel").removeClass("d-none");
      } else {
        root.find("#advanced-distribution-panel").addClass("d-none");
      }

      this.validateDistribution();
    },

    getSum: function (type) {
      var sum = 0;
      this.modal
        .getRoot()
        .find('.dist-input[data-type="' + type + '"]')
        .each(function () {
          sum += parseInt($(this).val()) || 0;
        });
      return sum;
    },

    distributeEvenly: function (type, total) {
      var inputs = this.modal
        .getRoot()
        .find('.dist-input[data-type="' + type + '"]');
      var count = inputs.length;
      var base = Math.floor(total / count);
      var remainder = total % count;

      inputs.each(function (index) {
        var val = base + (index < remainder ? 1 : 0);
        $(this).val(val);
      });
    },

    validateDistribution: function () {
      var root = this.modal.getRoot();
      var totalTarget = parseInt(root.find("#id_numquestions").val());
      var valid = true;

      // Check if panel is visible
      if (root.find("#advanced-distribution-panel").hasClass("d-none")) {
        root.find("#btn-generate-confirm").prop("disabled", false);
        return true;
      }

      // Validate Difficulty if visible
      if (!root.find("#difficulty-dist").hasClass("d-none")) {
        var diffSum = this.getSum("difficulty");
        if (diffSum !== totalTarget) {
          root
            .find("#diff-validation-msg")
            .removeClass("d-none")
            .text("Sum: " + diffSum + " / " + totalTarget);
          valid = false;
        } else {
          root.find("#diff-validation-msg").addClass("d-none");
        }
      }

      // Validate Bloom if visible
      if (!root.find("#cognitive-dist").hasClass("d-none")) {
        var bloomSum = this.getSum("bloom");
        if (bloomSum !== totalTarget) {
          root
            .find("#bloom-validation-msg")
            .removeClass("d-none")
            .text("Sum: " + bloomSum + " / " + totalTarget);
          valid = false;
        } else {
          root.find("#bloom-validation-msg").addClass("d-none");
        }
      }

      root.find("#btn-generate-confirm").prop("disabled", !valid);
      return valid;
    },

    updateSelectionFromRange: function (rangeStr) {
      if (!rangeStr) {
        return;
      }

      var root = this.modal.getRoot();
      // Uncheck all first
      root.find(".slide-toggle").prop("checked", false);

      var parts = rangeStr.split(",");
      parts.forEach(function (part) {
        var bounds = part.trim().split("-");
        if (bounds.length === 2) {
          var start = parseInt(bounds[0]);
          var end = parseInt(bounds[1]);
          for (var i = start; i <= end; i++) {
            root
              .find('.slide-row[data-page="' + i + '"] .slide-toggle')
              .prop("checked", true);
          }
        } else if (bounds.length === 1 && bounds[0] !== "") {
          var p = parseInt(bounds[0]);
          root
            .find('.slide-row[data-page="' + p + '"] .slide-toggle')
            .prop("checked", true);
        }
      });
    },

    triggerGeneration: function () {
      var self = this;
      var root = self.modal.getRoot();

      // Collect Options
      var numQuestions = parseInt(root.find("#id_numquestions").val());
      var difficulty = root.find("#id_difficulty").val();
      var bloomLevel = root.find("#id_cognitive").val();

      var difficultyDistribution = null;
      var bloomDistribution = null;

      if (difficulty === "mixed") {
        difficultyDistribution = {};
        root.find('.dist-input[data-type="difficulty"]').each(function () {
          difficultyDistribution[$(this).data("key")] =
            parseInt($(this).val()) || 0;
        });
      }

      if (bloomLevel === "mixed") {
        bloomDistribution = {};
        root.find('.dist-input[data-type="bloom"]').each(function () {
          bloomDistribution[$(this).data("key")] = parseInt($(this).val()) || 0;
        });
      }

      var includeSlides = [];
      root.find(".slide-toggle:checked").each(function () {
        var row = $(this).closest(".slide-row");
        includeSlides.push(row.data("page"));
      });

      var includeImages = [];
      root.find(".image-toggle:checked").each(function () {
        var imageId = $(this).data("imageid");
        if (imageId) {
          includeImages.push(imageId);
        } else {
          var source = $(this).data("source");
          if (source) {
            includeImages.push(source);
          }
        }
      });

      var options = {
        numQuestions: numQuestions,
        difficulty: difficulty,
        bloomLevel: bloomLevel,
        includeSlides: includeSlides,
        includeImages: includeImages,
      };

      if (difficultyDistribution) {
        options.difficultyDistribution = difficultyDistribution;
      }
      if (bloomDistribution) {
        options.bloomDistribution = bloomDistribution;
      }

      // UI Loading state
      root
        .find("#btn-generate-confirm")
        .prop("disabled", true)
        .html('<i class="fa fa-spinner fa-spin"></i> Initializing...');

      // Switch to Progress UI
      root.find("#generator-content").addClass("d-none");
      root.find("#generator-progress").removeClass("d-none");
      self.modal.getFooter().find("#btn-generate-confirm").hide();

      // Initialize Progress
      if (self.updateProgress) {
        self.updateProgress(0, "Sending request...");
      }

      // Bind Minimize Button
      root
        .off("click", "#btn-minimize-progress")
        .on("click", "#btn-minimize-progress", function () {
          self.minimizeModal();
        });

      // Step 1: Start the Job (Non-blocking)
      $.ajax({
        url: M.cfg.wwwroot + "/mod/classengage/slides_api.php",
        type: "POST",
        data: {
          action: "generate_from_options",
          slideid: self.slideid,
          docid: self.docid,
          options: JSON.stringify(options),
          sesskey: M.cfg.sesskey,
        },
        dataType: "json",
        success: function (response) {
          if (response.success && response.status === "running") {
            // Job started, begin Smart Polling
            self.updateProgress(5, "Job queued...");
            self.pollStatus(0);
          } else if (response.success && response.status === "completed") {
            self.updateProgress(100, "Done!");
            self.showSuccess(response);
          } else {
            self.showError(
              response.error || "Unknown error starting generation",
            );
          }
        },
        error: function () {
          self.showError("Network error starting generation.");
        },
      });
    },

    updateProgress: function (percent, statusText) {
      var root = this.modal.getRoot();
      var bar = root.find("#progress-bar");
      bar.css("width", percent + "%");
      bar.text(percent + "%");
      bar.attr("aria-valuenow", percent);

      if (statusText) {
        root.find("#progress-status-text").text(statusText);
        if (percent < 20) {
          root.find("#progress-detail").text("Initializing NLP Engine...");
        } else if (percent < 50) {
          root.find("#progress-detail").text("Analyzing content...");
        } else if (percent < 80) {
          root.find("#progress-detail").text("Generating questions...");
        } else if (percent < 100) {
          root.find("#progress-detail").text("Finalizing...");
        }
      }
    },

    minimizeModal: function () {
      var self = this;
      this.modal.hide();

      if ($("#generator-floater").length === 0) {
        $("body").append(
          '<div id="generator-floater" style="position: fixed; bottom: 20px; ' +
            'right: 20px; z-index: 1050; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer;">' +
            '  <button class="btn btn-primary rounded-pill px-4 py-2 font-weight-bold">' +
            '    <i class="fa fa-spinner fa-spin mr-2"></i> Generating <span id="floater-percent">0%</span>' +
            "  </button>" +
            "</div>",
        );

        $("#generator-floater").on("click", function () {
          self.modal.show();
          $(this).hide();
        });
      }

      var percent =
        this.modal.getRoot().find("#progress-bar").attr("aria-valuenow") || 0;
      $("#floater-percent").text(percent + "%");
      $("#generator-floater").show();
    },

    // Recursive Smart Polling
    pollStatus: function (retryCount) {
      var self = this;
      var root = self.modal.getRoot();

      // Use wait=true to enable server-side long polling (15s hold)
      $.ajax({
        url: M.cfg.wwwroot + "/mod/classengage/slides_api.php",
        type: "GET",
        data: {
          action: "nlpstatus",
          slideid: self.slideid,
          sesskey: M.cfg.sesskey,
          wait: true, // Enable Long Polling
        },
        dataType: "json",
        timeout: 40000, // 40s timeout (server holds for 15s + buffer)
        success: function (response) {
          if (response.success) {
            if (response.status === "completed") {
              self.updateProgress(100, "Generation Complete!");
              self.showSuccess(response);
              if ($("#generator-floater").is(":visible")) {
                $("#generator-floater").html(
                  '<button class="btn btn-success rounded-pill px-4 py-2 font-weight-bold">' +
                    '<i class="fa fa-check"></i> Complete!</button>',
                );
              }
            } else if (response.status === "failed") {
              self.showError(response.error);
            } else {
              // Still running - update UI and poll again IMMEDIATELY
              var progress = response.progress || 0;
              if (progress < 10) {
                progress = 10;
              }

              self.updateProgress(progress, "Generating...");
              $("#floater-percent").text(progress + "%");

              // Call immediately for next long-poll window
              self.pollStatus(0);
            }
          } else {
            // Logic error - retry with backoff
            setTimeout(function () {
              self.pollStatus(retryCount);
            }, 2000);
          }
        },
        error: function (xhr, status, error) {
          // Network error or timeout - retry with backoff
          if (retryCount > 10) {
            self.showError("Connection lost. Please refresh the page.");
          } else {
            console.warn("Poll failed, retrying...", status, error);
            setTimeout(
              function () {
                self.pollStatus(retryCount + 1);
              },
              2000 + retryCount * 1000,
            );
          }
        },
      });
    },

    showError: function (msg) {
      var root = this.modal.getRoot();
      // Switch back to error UI in progress container
      root.find("#progress-status-text").text("Error Failed");
      root
        .find("#progress-bar")
        .addClass("bg-danger")
        .removeClass("progress-bar-animated");
      root.find("#progress-detail").text(msg).addClass("text-danger");
      alert("Error: " + msg);
    },

    showSuccess: function (response) {
      var self = this;
      var root = self.modal.getRoot();

      // Build success summary - clean, professional layout
      var summaryHtml =
        '<div class="text-center p-4">' +
        '<div class="text-success mb-3"><i class="fa fa-check-circle fa-4x"></i></div>' +
        "<h4>Questions Ready!</h4>" +
        '<p class="lead mb-3">' +
        "<strong>" +
        response.count +
        "</strong> questions have been generated.</p>";

      // Info line: provider + duration in a clean row
      summaryHtml += '<div class="mb-4">';

      // Provider + Model badge
      if (response.provider) {
        var providerText = response.provider;
        if (response.model) {
          providerText += " (" + response.model + ")";
        }
        summaryHtml +=
          '<span class="badge badge-dark mr-2">' +
          '<i class="fa fa-robot mr-1"></i>' +
          providerText +
          "</span>";
      }

      // Duration
      if (response.duration) {
        var mins = Math.floor(response.duration / 60);
        var secs = response.duration % 60;
        var durationStr = mins > 0 ? mins + "m " + secs + "s" : secs + "s";
        summaryHtml +=
          '<span class="text-muted small">' +
          '<i class="fa fa-clock-o mr-1"></i>Generated in ' +
          durationStr +
          "</span>";
      }

      summaryHtml += "</div>";

      // View Questions button - navigate to questions.php with highlight
      var questionsUrl =
        M.cfg.wwwroot +
        "/mod/classengage/questions.php?id=" +
        self.cmid +
        "&highlight=slide_" +
        self.slideid;

      summaryHtml +=
        '<div class="mt-3">' +
        '<a href="' +
        questionsUrl +
        '" class="btn btn-primary btn-lg">' +
        '<i class="fa fa-eye mr-2"></i>View Questions</a>' +
        "</div>" +
        "</div>";

      root.find("#generator-progress").html(summaryHtml);

      root.find("#btn-minimize-progress").remove();
      $("#generator-floater").remove();
    },
  };

  return GeneratorWizard;
});
