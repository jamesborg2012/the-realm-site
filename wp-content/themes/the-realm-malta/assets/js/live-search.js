/**
 * Header live-search dropdown.
 *
 * Driven by woocommerce/product-searchform.php (renders the wrapper + panel) and
 * TRM_WC_Hooks::handle_live_search() (returns the panel HTML).
 *
 * Behaviour:
 *   - Debounced input (default 250 ms).
 *   - Minimum query length read from window.trmLiveSearch.minLen (default 2).
 *   - In-flight requests are aborted on the next keystroke.
 *   - Dropdown opens on focus when there are cached results; closes on outside click, ESC, or blur (delayed).
 *   - Arrow keys navigate the visible result list; Enter follows the highlighted link.
 */
(function ($) {
    'use strict';

    if (typeof window.trmLiveSearch === 'undefined') {
        return;
    }

    var cfg = $.extend({
        ajaxUrl: '',
        nonce: '',
        minLen: 2,
        debounceMs: 250
    }, window.trmLiveSearch);

    function debounce(fn, wait) {
        var t;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    $(document).ready(function () {
        $('[data-trm-live-search]').each(function () {
            initWrapper($(this));
        });
    });

    function initWrapper($wrapper) {
        var $input = $wrapper.find('.trm-live-search__input');
        var $panel = $wrapper.find('.trm-live-search__panel');
        var $clear = $wrapper.find('.trm-live-search__clear');

        if (!$input.length || !$panel.length) {
            return;
        }

        var state = {
            xhr: null,
            lastTerm: '',
            highlighted: -1
        };

        function syncClearVisibility() {
            if ($input.val().length > 0) {
                $clear.removeAttr('hidden');
            } else {
                $clear.attr('hidden', 'hidden');
            }
        }

        syncClearVisibility();

        function open() {
            if ($panel.children().length === 0) {
                return;
            }
            $panel.removeAttr('hidden');
            $input.attr('aria-expanded', 'true');
        }

        function close() {
            $panel.attr('hidden', 'hidden');
            $input.attr('aria-expanded', 'false');
            clearHighlight();
        }

        function clearHighlight() {
            state.highlighted = -1;
            $panel.find('.trm-live-search__item.is-active').removeClass('is-active');
        }

        function renderLoading() {
            $panel.html(
                '<div class="trm-live-search__loading" aria-live="polite">' +
                '<span class="trm-live-search__spinner" aria-hidden="true"></span>' +
                '</div>'
            );
            open();
        }

        function fetchResults(term) {
            if (state.xhr) {
                state.xhr.abort();
            }

            renderLoading();

            state.xhr = $.ajax({
                url: cfg.ajaxUrl,
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'trm_live_search',
                    nonce: cfg.nonce,
                    q: term
                }
            }).done(function (response) {
                if (!response || !response.success || !response.data) {
                    close();
                    return;
                }
                // Stale response — ignore if the user has since typed something different.
                if (response.data.term !== $input.val().trim()) {
                    return;
                }
                $panel.html(response.data.html);
                if (response.data.html) {
                    open();
                } else {
                    close();
                }
            }).fail(function (jqXHR, textStatus) {
                if (textStatus === 'abort') {
                    return;
                }
                close();
            });
        }

        var handleInput = debounce(function () {
            var term = $input.val().trim();
            if (term === state.lastTerm) {
                return;
            }
            state.lastTerm = term;

            if (term.length < cfg.minLen) {
                if (state.xhr) { state.xhr.abort(); }
                $panel.empty();
                close();
                return;
            }
            fetchResults(term);
        }, cfg.debounceMs);

        $input.on('input', function () {
            syncClearVisibility();
            handleInput();
        });

        $clear.on('click', function (e) {
            e.preventDefault();
            $input.val('');
            state.lastTerm = '';
            if (state.xhr) { state.xhr.abort(); }
            $panel.empty();
            close();
            syncClearVisibility();
            $input.trigger('focus');
        });

        $input.on('focus', function () {
            if ($panel.children().length > 0 && $input.val().trim().length >= cfg.minLen) {
                open();
            }
        });

        // Outside click closes; click within the panel must not close before the link fires.
        $(document).on('mousedown.trmLiveSearch', function (e) {
            if (!$wrapper.is(e.target) && !$wrapper.has(e.target).length) {
                close();
            }
        });

        $input.on('keydown', function (e) {
            var $items = $panel.find('.trm-live-search__item');
            if (!$items.length) {
                return;
            }

            switch (e.key) {
                case 'Escape':
                    close();
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    state.highlighted = (state.highlighted + 1) % $items.length;
                    setActive($items);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    state.highlighted = (state.highlighted - 1 + $items.length) % $items.length;
                    setActive($items);
                    break;
                case 'Enter':
                    if (state.highlighted >= 0) {
                        e.preventDefault();
                        var href = $items.eq(state.highlighted).find('a').attr('href');
                        if (href) {
                            window.location.href = href;
                        }
                    }
                    break;
            }
        });

        function setActive($items) {
            $items.removeClass('is-active');
            var $active = $items.eq(state.highlighted).addClass('is-active');
            // Scroll into view inside the result list (the list is the scroll container, not the panel).
            var listEl = $panel.find('.trm-live-search__list').get(0);
            var itemEl = $active.get(0);
            if (listEl && itemEl) {
                var itemTop = itemEl.offsetTop;
                var itemBottom = itemTop + itemEl.offsetHeight;
                if (itemTop < listEl.scrollTop) {
                    listEl.scrollTop = itemTop;
                } else if (itemBottom > listEl.scrollTop + listEl.clientHeight) {
                    listEl.scrollTop = itemBottom - listEl.clientHeight;
                }
            }
        }
    }
})(jQuery);
