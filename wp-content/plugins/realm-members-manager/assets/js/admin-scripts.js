function adminScriptsWrapper ($) {
  var adminScripts = {
    init: function () {
      adminScripts.registerEventsHandler()
    },

    registerEventsHandler: function () {
      $('.manage-member-btn').click(adminScripts.enableMemberModal)
    },

    enableMemberModal: function (e) {
      e.preventDefault()

      var buttonClicked = $(this)

      var userId = buttonClicked.attr('data-user-id')

      $.ajax({
        url: rmmAjaxObj.ajaxUrl,
        type: 'POST',
        data: {
          action: 'load_member_data',
          user_id: userId,
          nonce: rmmAjaxObj.ajaxNonce
        },
        success: function (response) {
          if (response.success) {
            $(document)
              .find('.member-manage-modal .modal-content')
              .html(response.data.content)
          }
        }
      })
    }
  }

  $(document).ready(adminScripts.init)
}

adminScriptsWrapper(jQuery)
