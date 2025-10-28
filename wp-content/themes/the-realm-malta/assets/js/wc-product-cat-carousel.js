jQuery(document).ready(function($){
    $('.product-category-list').slick({
        slidesToShow: 5,        // Number of visible slides
        slidesToScroll: 5,      // How many slides to move per scroll
        autoplay: false,        // Pagination dots
        infinite: true,
        responsive: [
            {
                breakpoint: 1024,
                settings: { slidesToShow: 3, slidesToScroll: 3 }
            },
            {
                breakpoint: 600,
                settings: { slidesToShow: 2, slidesToScroll: 2 }
            }
        ]
    });
});