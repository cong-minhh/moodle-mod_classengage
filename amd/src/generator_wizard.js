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
        }.bind(this)
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
            }
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
            }
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
            }
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
              '<div class="alert alert-danger">' + response.error + "</div>"
            );
          }
        },
        error: function () {
          self.modal.setBody(
            '<div class="alert alert-danger">Network error during inspection.</div>'
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
            })
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
          })
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
        // Get page number from data-page of parent row
        var row = $(this).closest(".slide-row");
        includeSlides.push(row.data("page"));
      });

      var includeImages = [];
      root.find(".image-toggle:checked").each(function () {
        // Prefer imageId (new format), fallback to source (legacy)
        var imageId = $(this).data("imageid");
        if (imageId) {
          includeImages.push(imageId);
        } else {
          // Fallback: use source from data attribute or parse from name
          var source = $(this).data("source");
          if (source) {
            includeImages.push(source);
          } else {
            var name = $(this).attr("name");
            if (name) {
              var match = name.match(/include_image\[(.*)\]/);
              if (match && match[1]) {
                includeImages.push(match[1]);
              }
            }
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
        .html('<i class="fa fa-spinner fa-spin"></i> Generating...');

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
          if (response.success) {
            // Show success summary before closing
            var summaryHtml =
              '<div class="alert alert-success">' +
              '<h5><i class="fa fa-check-circle"></i> Generation Complete</h5>' +
              '<div class="row mt-3">' +
              '<div class="col-6">' +
              "<strong>Questions Generated:</strong> " +
              response.count;

            if (response.expected && response.count !== response.expected) {
              summaryHtml +=
                ' <small class="text-warning">(requested: ' +
                response.expected +
                ")</small>";
            }

            summaryHtml += "</div>";

            if (response.provider) {
              summaryHtml +=
                '<div class="col-6">' +
                '<strong>Provider:</strong> <span class="badge badge-dark">' +
                response.provider +
                "</span>";
              if (response.model) {
                summaryHtml +=
                  ' <small class="text-muted">(' + response.model + ")</small>";
              }
              summaryHtml += "</div>";
            }

            summaryHtml += "</div>";

            // Show distribution plan if available
            if (response.plan) {
              summaryHtml +=
                '<div class="mt-2"><small class="text-muted">' +
                '<i class="fa fa-list"></i> Distribution: ' +
                Object.keys(response.plan).length +
                " categories" +
                "</small></div>";
            }

            summaryHtml +=
              '<p class="mt-3 mb-0 text-muted"><small>Redirecting in 3 seconds...</small></p>' +
              "</div>";

            root.find("#generator-content").html(summaryHtml);
            root.find("#btn-generate-confirm").hide();

            // Redirect after 3 seconds
            setTimeout(function () {
              self.modal.hide();
              window.location.reload();
            }, 3000);
          } else {
            alert("Error: " + response.error);
            root
              .find("#btn-generate-confirm")
              .prop("disabled", false)
              .text("Generate");
          }
        },
        error: function () {
          alert("Network error during generation.");
          root
            .find("#btn-generate-confirm")
            .prop("disabled", false)
            .text("Generate");
        },
      });
    },
  };

  return GeneratorWizard;
});
