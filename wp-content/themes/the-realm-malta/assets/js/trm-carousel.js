/**
 * Product carousels (item 33) — shared by the New Releases and Pre-Orders ACF blocks.
 *
 * Initialises Slick on every `.trm-carousel__track` (one per block instance, either variant). Guards
 * against double-init with `.not('.slick-initialized')` so multiple instances — or a re-run — are safe.
 *
 * Config: 4-up on desktop, scrolling/stopping 4 at a time (infinite:false), stepping down to 3/2/1
 * on narrower viewports with slidesToScroll matched to slidesToShow. Arrows on, dots off, no
 * autoplay. Under infinite:false Slick disables the arrows itself once the last slide is reached and
 * when the set is shorter than slidesToShow — so a short set renders left-aligned with inert arrows.
 */
(function ($) {
    'use strict';

    function initCarousels() {
        $('.trm-carousel__track').not('.slick-initialized').each(function () {
            $(this).slick({
                slidesToShow: 4,
                slidesToScroll: 4,
                infinite: false,
                arrows: true,
                dots: false,
                autoplay: false,
                responsive: [
                    {
                        breakpoint: 1024,
                        settings: { slidesToShow: 3, slidesToScroll: 3 }
                    },
                    {
                        breakpoint: 768,
                        settings: { slidesToShow: 2, slidesToScroll: 2 }
                    },
                    {
                        breakpoint: 480,
                        settings: { slidesToShow: 1, slidesToScroll: 1 }
                    }
                ]
            });
        });
    }

    $(function () {
        if (typeof $.fn.slick !== 'function') {
            return;
        }
        initCarousels();
    });
})(jQuery);
