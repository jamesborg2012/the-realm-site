function adminScriptsWrapper ($) {
  var adminScripts = {
    init: function () {
      adminScripts.registerEventsHandler()
    },

    registerEventsHandler: function () {
      $('.show-new-member-modal').click(adminScripts.enableNewMemberModal)
      $('.manage-member-btn').click(adminScripts.enableMemberModal)
      $('.create-member').click(adminScripts.createNewMember)
      $('.modal-close-button').click(adminScripts.closeModal)
    },

    enableNewMemberModal: function (e) {
      e.preventDefault()

      var buttonClicked = $(this)
      var settingsPage = buttonClicked.closest('#wpbody')

      settingsPage.find('#create-user-modal').addClass('enable-modal')
      settingsPage.closest('body').addClass('modal-open')
    },

    enableMemberModal: function (e) {
      e.preventDefault()

      var buttonClicked = $(this)
      var settingsPage = buttonClicked.closest('#wpbody')

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
            var modal = settingsPage.find('#manage-member-modal')
            modal.addClass('enable-modal')
            modal.find('.modal-content').html(response.data.content)
            modal.closest('body').addClass('modal-open')
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
        member_phone: modal.find('#rmm_member_phone').val(),
        member_status: modal.find('#rmm_member_status').val(),
        member_expiry_date: modal.find('#rmm_membership_expire').val()
      }

      $.ajax({
        url: rmmAjaxObj.ajaxUrl,
        type: 'POST',
        data: {
          action: 'create_new_member',
          member_data: memberData,
          nonce: rmmAjaxObj.ajaxNonce
        },
        success: function (response) {
          if (response.data.message) {
            alert(response.data.message)
          } else {
            if (response.success) {
              alert('Member successfully created!')
            } else {
              alert('Something went wrong, please try again!')
            }
          }
        }
      })
    },

    closeModal: function (e) {
      e.preventDefault()

      var closeButton = $(this)
      var modal = closeButton.closest('.rmm-modal')
      modal.removeClass('enable-modal')
      modal.closest('body').removeClass('modal-open')
    }
  }

  $(document).ready(adminScripts.init)
}

adminScriptsWrapper(jQuery)
