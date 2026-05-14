(function () {
  function updateSlideNumbers(list) {
    list.querySelectorAll("[data-cloudari-hero-card]").forEach(function (card, index) {
      var number = card.querySelector("[data-cloudari-slide-number]");

      card.dataset.slideIndex = String(index);

      if (number) {
        number.textContent = String(index + 1);
      }
    });
  }

  function addSlide() {
    var list = document.querySelector(".cloudari-weekly-hero-list");
    var template = document.getElementById("cloudari-weekly-hero-slide-template");

    if (!list || !template) {
      return;
    }

    var index = list.querySelectorAll("[data-cloudari-hero-card]").length;
    var html = template.innerHTML.replace(/__INDEX__/g, String(index));
    var wrapper = document.createElement("div");

    wrapper.innerHTML = html.trim();

    if (wrapper.firstElementChild) {
      list.appendChild(wrapper.firstElementChild);
      updateSlideNumbers(list);
    }
  }

  function openMediaLibrary(button) {
    if (!window.wp || !wp.media) {
      return;
    }

    var target = document.getElementById(button.dataset.target || "");
    var altTarget = document.getElementById(button.dataset.altTarget || "");
    var frame = wp.media({
      title: "Seleccionar imagen",
      button: {
        text: "Usar esta imagen"
      },
      multiple: false,
      library: {
        type: "image"
      }
    });

    frame.on("select", function () {
      var attachment = frame.state().get("selection").first();
      var data = attachment ? attachment.toJSON() : {};

      if (target && data.url) {
        target.value = data.url;
        target.dispatchEvent(new Event("change", { bubbles: true }));
      }

      if (altTarget) {
        altTarget.value = data.alt || data.title || "";
        altTarget.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });

    frame.open();
  }

  document.addEventListener("click", function (event) {
    var addButton = event.target.closest("[data-cloudari-add-hero-slide]");
    var mediaButton = event.target.closest("[data-cloudari-media-button]");

    if (addButton) {
      event.preventDefault();
      addSlide();
      return;
    }

    if (mediaButton) {
      event.preventDefault();
      openMediaLibrary(mediaButton);
    }
  });

  document.addEventListener("DOMContentLoaded", function () {
    var list = document.querySelector(".cloudari-weekly-hero-list");

    if (list) {
      updateSlideNumbers(list);
    }
  });
})();
