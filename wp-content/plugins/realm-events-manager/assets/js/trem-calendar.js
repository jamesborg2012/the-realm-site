jQuery(document).ready(function ($) {
  $(document).on('click', '.trem-calendar__nav', function (e) {
    e.preventDefault()

    const $calendar = $(this).closest('.trem-calendar')
    let month = parseInt($calendar.data('month'))
    let year = parseInt($calendar.data('year'))

    const isNext = $(this).hasClass('trem-calendar__next')
    const isPrev = $(this).hasClass('trem-calendar__prev')

    const today = new Date()
    const currentMonth = today.getMonth() + 1
    const currentYear = today.getFullYear()

    // Adjust month/year
    if (isNext) {
      month++
      if (month > 12) {
        month = 1
        year++
      }
    } else if (isPrev) {
      month--
      if (month < 1) {
        month = 12
        year--
      }
    }

    // Prevent going before current month or more than 12 months ahead
    const diffMonths = (year - currentYear) * 12 + (month - currentMonth)
    if (diffMonths < 0 || diffMonths > 12) {
      return
    }

    $.ajax({
      url: tremCalendar.ajaxUrl,
      type: 'POST',
      data: {
        action: 'trem_load_calendar',
        month: month,
        year: year
      },
      beforeSend: function () {
        $calendar.addClass('trem-calendar--loading')
      },
      success: function (response) {
        if (response.success) {
          $calendar.replaceWith(response.data.html)
        }
      },
      complete: function () {
        $calendar.removeClass('trem-calendar--loading')
      }
    })
  })

  // Load event details under the calendar
  jQuery(document).on('click', '.trem-calendar__events a', function (e) {
    e.preventDefault()
    const eventId = $(this)
      .attr('href')
      .match(/post=(\d+)/)
      ? RegExp.$1
      : null
    const $calendar = $(this).closest('.trem-calendar')

    // Prefer safer: store ID in data-attr instead of parsing URL (update PHP output)
    const id = $(this).data('event-id')
    if (!id) return

    $.ajax({
      url: tremCalendar.ajaxUrl,
      type: 'POST',
      data: {
        action: 'trem_load_event',
        event_id: id
      },
      beforeSend: function () {
        $('.trem-event-details').slideUp(200, function () {
          $(this).remove()
        })
      },
      success: function (response) {
        if (response.success) {
          $calendar.after(response.data.html)
          $('html, body').animate(
            {
              scrollTop:
                $calendar.next('.trem-event-details').offset().top - 100
            },
            400
          )
        }
      }
    })
  })
})
