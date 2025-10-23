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

      $(document).on(
        'submit',
        '.rmm-new-member-form',
        rmmScripts.registerNewMember
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
          console.log(response)
          if (response.success) {
            $(document.body).trigger('update_checkout')
          } else {
            var data = response.data
            var status = data.status

            alert(data.message)
          }
        }
      })
    },

    registerNewMember: function (e) {
      e.preventDefault()

      var formSubmit = $(this)
      var form = $(document).find('.rmm-new-member-form')
      var submittedData = form.serialize()

      $.ajax({
        url: rmmAjaxObj.ajaxUrl,
        type: 'POST',
        data: {
          action: 'register_new_member',
          form_data: submittedData,
          nonce: rmmAjaxObj.ajaxNonce
        },
        success: function (response) {
          console.log(response)
          if (response.success) {
            alert('Success')
          } else {
            var data = response.data
            var status = data.status

            alert(data.message)
          }
        }
      })
    }
  }

  $(document).ready(rmmScripts.init)
}

rmmScriptsWrapper(jQuery)
