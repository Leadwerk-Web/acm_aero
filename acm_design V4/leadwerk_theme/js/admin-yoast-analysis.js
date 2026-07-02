(function() {
  try {
    var data = window.leadwerkYoastAnalysis || {};
    var registered = false;
    var pluginName = "leadwerkRenderedContent";

    function getYoastRegisterApi() {
      var Y = window.YoastSEO;
      if (!Y) {
        return null;
      }
      if (Y.app && typeof Y.app.registerPlugin === "function") {
        return {
          registerPlugin: Y.app.registerPlugin.bind(Y.app),
          registerModification: Y.app.registerModification.bind(Y.app)
        };
      }
      if (typeof Y.registerPlugin === "function" && typeof Y.registerModification === "function") {
        return {
          registerPlugin: Y.registerPlugin.bind(Y),
          registerModification: Y.registerModification.bind(Y)
        };
      }
      return null;
    }

    function registerWithYoast() {
      if (!data.renderedContent) {
        return false;
      }
      if (registered) {
        return true;
      }

      var api = getYoastRegisterApi();
      if (!api) {
        return false;
      }

      try {
        api.registerPlugin(pluginName, {
          status: "ready"
        });
        api.registerModification(
          "content",
          function(content) {
            var baseContent = typeof content === "string" ? content : "";

            if (baseContent.indexOf(data.renderedContent) !== -1) {
              return baseContent;
            }

            return baseContent ? baseContent + " " + data.renderedContent : data.renderedContent;
          },
          pluginName,
          5
        );
      } catch (e) {
        return false;
      }

      registered = true;
      return true;
    }

    function tryRegisterLoop() {
      if (registerWithYoast()) {
        return;
      }
      var attempts = 0;
      var max = 80;
      var id = window.setInterval(function() {
        attempts++;
        if (registerWithYoast() || attempts >= max) {
          window.clearInterval(id);
        }
      }, 250);
    }

    function scheduleTry() {
      window.setTimeout(function() {
        tryRegisterLoop();
      }, 1);
    }

    if (registerWithYoast()) {
      return;
    }

    window.addEventListener("YoastSEO:ready", scheduleTry);

    if (document.readyState === "complete" || document.readyState === "interactive") {
      scheduleTry();
    } else {
      document.addEventListener("DOMContentLoaded", scheduleTry);
    }
  } catch (e) {
    if (window.console && typeof window.console.warn === "function") {
      window.console.warn("[Leadwerk] Yoast analysis bridge failed to init.", e);
    }
  }
})();
