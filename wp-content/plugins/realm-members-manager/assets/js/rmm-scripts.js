function rmmScriptsWrapper ($) {
  var rmmScripts = {
    init: function () {
      rmmScripts.registerEventsHandler()
    },

    registerEventsHandler: function () {
      $(document).on(
        'click',
        '.submit-membership-number',
        rmmScripts.applyMembershipNumber
      )
    },

    applyMembershipNumber: function (e) {
      e.preventDefault()

      var buttonClicked = $(this)
      var membershipNumber = buttonClicked
        .closest('.membership-number-form-container')
        .find('#membership-number')
        .val()

      $.ajax({
        url: rmmAjaxObj.ajaxUrl,
        type: 'POST',
        data: {
          action: 'apply_membership_number',
          member_number: membershipNumber,
          nonce: rmmAjaxObj.ajaxNonce
        },
        success: function (response) {
          if (response.success) {
            $(document.body).trigger('update_checkout')
          }
        }
      })
    }
  }

  $(document).ready(rmmScripts.init)
}

rmmScriptsWrapper(jQuery)
