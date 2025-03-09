function adminScriptsWrapper ($) {
  var adminScripts = {
    init: function () {
      adminScripts.registerEventsHandler()
    },

    registerEventsHandler: function () {
      $('.show-new-member-modal').click(adminScripts.enableNewMemberModal)
      $('.manage-member-btn').click(adminScripts.enableMemberModal)
      $('.create-member').click(adminScripts.createNewMember)
    },

    enableNewMemberModal: function (e) {
      e.preventDefault()

      $(document).find('#create-user-modal').addClass('enable-modal')
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
    },

    createNewMember: function (e) {
      e.preventDefault()

      var buttonClicked = $(this)
      var modal = buttonClicked.closest('#create-user-modal')

      var memberData = {
        member_number: modal.find('#rmm_membership_number').val(),
        member_name: modal.find('#rmm_member_name').val(),
        member_surname: modal.find('#rmm_member_surname').val(),
        member_email: modal.find('#rmm_member_email').val(),
        member_phone: modal.find('#rmm_member_phone').val()
      }

      $.ajax({
        url: rmmAjaxObj.ajaxUrl,
        type: 'POST',
        data: {
          action: 'create_new_member',
          member_data: memberData,
          nonce: rmmAjaxObj.ajaxNonce
        },
        success: function (response) {}
      })
    }
  }

  $(document).ready(adminScripts.init)
}

adminScriptsWrapper(jQuery)
