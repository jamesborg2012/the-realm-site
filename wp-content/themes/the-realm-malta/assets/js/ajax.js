jQuery(document).ready(function($) {
    'use strict';
    
    // Account Creation Form AJAX Handler
    var $form = $('.js-realm-account-form');
    
    if ($form.length === 0) {
        return;
    }
    
    var $submitButton = $form.find('button[type="submit"]');
    var $messageContainer = $('.realm-account-creation__message');
    var $formContainer = $('.realm-account-creation__form-container');
    var $successContainer = $('.realm-account-creation__success-container');
    var $checkbox = $('#is_realm_member');
    var $membershipField = $('.realm-account-creation__membership-field');
    var $phonePrefix = $('#phone_prefix');
    var $mobileNumber = $('#mobile_number');
    
    // Initialize selectWoo for phone prefix dropdown (if available)
    if ($phonePrefix.length && typeof $.fn.selectWoo !== 'undefined') {
        $phonePrefix.selectWoo({
            placeholder: 'Select Phone Prefix',
            allowClear: false,
            width: '100%'
        });
    }
    
    // Membership field toggle
    function toggleMembershipField() {
        if ($checkbox.is(':checked')) {
            $membershipField.removeClass('is-hidden');
            $membershipField.attr('aria-hidden', 'false');
            $checkbox.attr('aria-expanded', 'true');
        } else {
            $membershipField.addClass('is-hidden');
            $membershipField.attr('aria-hidden', 'true');
            $checkbox.attr('aria-expanded', 'false');
        }
    }
    
    // Set initial state and bind toggle
    if ($checkbox.length) {
        $checkbox.attr('aria-expanded', $checkbox.is(':checked') ? 'true' : 'false');
        $checkbox.on('change', toggleMembershipField);
    }
    
    // Form submission handler
    $form.on('submit', function(e) {
        e.preventDefault();
        
        // Clear previous messages
        $messageContainer.html('').removeClass('realm-account-creation__message--error realm-account-creation__message--info');
        
        // Client-side validation: password fields
        var password = $('#password').val();
        var passwordConfirm = $('#password_confirm').val();
        
        if (!password || !passwordConfirm) {
            $messageContainer
                .html('<p>Please enter and confirm your password.</p>')
                .addClass('realm-account-creation__message--error')
                .removeClass('is-hidden');
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $messageContainer.offset().top - 100
            }, 300);
            
            return;
        }
        
        if (password !== passwordConfirm) {
            $messageContainer
                .html('<p>Passwords do not match. Please try again.</p>')
                .addClass('realm-account-creation__message--error')
                .removeClass('is-hidden');

            // Scroll to message
            $('html, body').animate({
                scrollTop: $messageContainer.offset().top - 100
            }, 300);

            return;
        }

        // Client-side validation: password strength requirements
        var passwordErrors = [];
        if (password.length < 8) {
            passwordErrors.push('at least 8 characters');
        }
        if (!/[0-9]/.test(password)) {
            passwordErrors.push('at least one number');
        }
        if (!/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(password)) {
            passwordErrors.push('at least one special character');
        }

        if (passwordErrors.length > 0) {
            $messageContainer
                .html('<p>Password must contain ' + passwordErrors.join(', ') + '.</p>')
                .addClass('realm-account-creation__message--error')
                .removeClass('is-hidden');

            // Scroll to message
            $('html, body').animate({
                scrollTop: $messageContainer.offset().top - 100
            }, 300);

            return;
        }
        
        // Client-side validation: mobile number must NOT start with "+"
        var mobileValue = $mobileNumber.val().trim();
        if (mobileValue.startsWith('+')) {
            $messageContainer
                .html('<p>Please enter your mobile number without the + sign. Use the Phone Prefix dropdown instead.</p>')
                .addClass('realm-account-creation__message--error')
                .removeClass('is-hidden');
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $messageContainer.offset().top - 100
            }, 300);
            
            return;
        }
        
        // Disable submit button and show loading state
        $submitButton.prop('disabled', true).addClass('is-loading');
        $form.addClass('is-submitting');
        
        // Prepare form data
        var formData = {
            action: 'realm_register_customer',
            nonce: trmAjax.nonce,
            first_name: $('#first_name').val().trim(),
            last_name: $('#last_name').val().trim(),
            email: $('#user_email').val().trim(),
            password: $('#password').val(),
            password_confirm: $('#password_confirm').val(),
            phone_prefix: $('#phone_prefix').val().trim(),
            mobile_number: $('#mobile_number').val().trim(),
            is_realm_member: $checkbox.is(':checked') ? '1' : '0',
            membership_number: $('#membership_number').val().trim()
        };
        
        // Send AJAX request
        $.ajax({
            url: trmAjax.ajaxUrl,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Validate user_id is present in response
                    if (!response.data || !response.data.user_id) {
                        handleError('server_error');
                        return;
                    }
                    
                    // Store user_id and membership_token for membership application
                    $successContainer.attr('data-user-id', response.data.user_id);
                    if (response.data.membership_token) {
                        $successContainer.attr('data-membership-token', response.data.membership_token);
                    }
                    
                    // Hide form and show success message with membership offer
                    $formContainer.addClass('is-hidden');
                    $successContainer.removeClass('is-hidden');

                    if (!response.data.membership_number) {
                        $('.realm-membership-offer').removeClass('is-hidden');
                    }
                } else {
                    // Show error message
                    var errorMsg = (response.data && response.data.message) ? response.data.message : null;
                    handleError(response.data ? response.data.code : 'server_error', errorMsg);
                }
            },
            error: function(xhr, status, error) {
                // Network or server error
                handleError('server_error');
            },
            complete: function() {
                // Re-enable submit button
                $submitButton.prop('disabled', false).removeClass('is-loading');
                $form.removeClass('is-submitting');
            }
        });
    });
    
    // Membership Application Handler
    var $membershipButton = $('.js-realm-membership-apply');
    var $membershipMessage = $('.realm-membership-offer__message');
    var $membershipContent = $('.realm-membership-offer__content');
    var membershipSubmitted = false;
    
    $membershipButton.on('click', function(e) {
        e.preventDefault();
        
        // Prevent double submission
        if (membershipSubmitted) {
            return;
        }
        
        // Get stored user_id and token
        var userId = $successContainer.attr('data-user-id');
        var membershipToken = $successContainer.attr('data-membership-token');
        
        // Validate user_id is present
        if (!userId || userId === '') {
            showMembershipMessage(trmAjax.messages.error, 'error');
            return;
        }
        
        // Clear previous messages
        $membershipMessage.html('').removeClass('realm-membership-offer__message--success realm-membership-offer__message--error is-hidden');
        
        // Disable button and show loading state
        $membershipButton.prop('disabled', true).addClass('is-loading');
        
        // Prepare membership application data
        var membershipData = {
            action: 'realm_apply_membership',
            nonce: trmAjax.membershipNonce,
            user_id: userId,
            membership_token: membershipToken
        };
        
        // Send AJAX request
        $.ajax({
            url: trmAjax.ajaxUrl,
            type: 'POST',
            data: membershipData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Mark as submitted to prevent resubmission
                    membershipSubmitted = true;
                    
                    // Hide the membership offer content
                    $membershipContent.addClass('is-hidden');
                    
                    // Show success message
                    showMembershipMessage(trmAjax.messages.membershipSuccess, 'success');
                } else {
                    // Show error message
                    showMembershipMessage(trmAjax.messages.error, 'error');
                    $membershipButton.prop('disabled', false).removeClass('is-loading');
                }
            },
            error: function(xhr, status, error) {
                // Network or server error
                showMembershipMessage(trmAjax.messages.error, 'error');
                $membershipButton.prop('disabled', false).removeClass('is-loading');
            }
        });
    });
    
    // Helper function to show membership messages
    function showMembershipMessage(message, type) {
        $membershipMessage
            .html('<p>' + message + '</p>')
            .addClass('realm-membership-offer__message--' + type)
            .removeClass('is-hidden');
    }
    
    // Error handler
    function handleError(errorCode, errorMessage) {
        console.log(errorCode);
        var message = errorMessage || trmAjax.messages.error;
        
        if (errorCode === 'duplicate') {
            message = trmAjax.messages.duplicate;
        } else if (errorCode === 'validation') {
            message = errorMessage || 'Please fill in all required fields correctly.';
        } else if (errorCode === 'mobile_plus_sign') {
            message = 'Please enter your mobile number without the + sign. Use the Phone Prefix dropdown instead.';
        }
        
        $messageContainer
            .html('<p>' + message + '</p>')
            .addClass('realm-account-creation__message--info')
            .removeClass('is-hidden');
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: $messageContainer.offset().top - 100
        }, 300);
    }
});

