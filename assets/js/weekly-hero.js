(function () {
  function initWeeklyHeroCarousel(carousel) {
    if (carousel.dataset.cloudariWeeklyHeroReady === "1" || typeof Swiper === "undefined") {
      return;
    }

    carousel.dataset.cloudariWeeklyHeroReady = "1";

    new Swiper(carousel, {
      loop: true,
      slidesPerView: 1,
      speed: 650,
      initialSlide: 0,
      autoplay: {
        delay: Number(carousel.dataset.autoplayDelay || 5000),
        disableOnInteraction: false,
        pauseOnMouseEnter: true
      },
      navigation: {
        prevEl: carousel.querySelector(".cloudari-weekly-hero__arrow--prev"),
        nextEl: carousel.querySelector(".cloudari-weekly-hero__arrow--next")
      },
      watchOverflow: true,
      preloadImages: false,
      lazyPreloadPrevNext: 1
    });
  }

  function init() {
    document.querySelectorAll("[data-cloudari-weekly-hero]").forEach(initWeeklyHeroCarousel);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
