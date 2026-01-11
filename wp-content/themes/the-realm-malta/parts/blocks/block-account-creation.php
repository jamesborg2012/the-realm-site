<?php
/**
 * Account Creation Form Block
 * Supports new user registration and existing Realm Member flow
 */

// Initialize variables
$errors = [];
$success = '';
$form_data = [
	'first_name' => '',
	'last_name' => '',
	'user_email' => '',
	'phone_prefix' => '',
	'mobile_number' => '',
	'is_realm_member' => false,
	'membership_number' => '',
]; ?>

<div class="realm-account-creation">
	
	<!-- AJAX Message Container (initially hidden) -->
	<div class="realm-account-creation__message is-hidden"></div>
	
	<?php if ( ! empty( $errors ) ) : ?>
		<div class="realm-account-creation__errors">
			<ul>
				<?php foreach ( $errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
	
	<?php if ( ! empty( $success ) ) : ?>
		<div class="realm-account-creation__success">
			<p><?php echo esc_html( $success ); ?></p>
		</div>
	<?php endif; ?>
	
	<!-- Success Container (hidden until AJAX success) -->
	<div class="realm-account-creation__success-container is-hidden">
		<div class="realm-account-creation__success">
			<p>Your account is registered and will be finalised soon after review.</p>
		</div>
	</div>
	
	<!-- Form Container -->
	<div class="realm-account-creation__form-container">
		<form method="post" class="realm-account-creation__form js-realm-account-form" novalidate>
			
			<?php wp_nonce_field( 'realm_account_creation', 'realm_account_creation_nonce' ); ?>
			<input type="hidden" name="realm_account_creation_action" value="submit" />
		
		<!-- Row 1: Name | Surname -->
		<div class="realm-account-creation__row realm-account-creation__row--two-col">
			<div class="realm-account-creation__field">
				<label for="first_name">
					Name <span class="required">*</span>
				</label>
				<input 
					type="text" 
					id="first_name" 
					name="first_name" 
					value="<?php echo esc_attr( $form_data['first_name'] ); ?>" 
					required 
				/>
			</div>
			
			<div class="realm-account-creation__field">
				<label for="last_name">
					Surname <span class="required">*</span>
				</label>
				<input 
					type="text" 
					id="last_name" 
					name="last_name" 
					value="<?php echo esc_attr( $form_data['last_name'] ); ?>" 
					required 
				/>
			</div>
		</div>
		
		<!-- Row 2: Email (full width) -->
		<div class="realm-account-creation__row realm-account-creation__row--single-col">
			<div class="realm-account-creation__field">
				<label for="user_email">
					Email <span class="required">*</span>
				</label>
				<input 
					type="email" 
					id="user_email" 
					name="user_email" 
					value="<?php echo esc_attr( $form_data['user_email'] ); ?>" 
					required 
				/>
			</div>
		</div>
		
		<!-- Row 3: Phone Prefix | Mobile Number -->
		<div class="realm-account-creation__row realm-account-creation__row--two-col">
			<div class="realm-account-creation__field">
				<label for="phone_prefix">
					Phone Prefix <span class="required">*</span>
				</label>
				<input 
					type="text" 
					id="phone_prefix" 
					name="phone_prefix" 
					value="<?php echo esc_attr( $form_data['phone_prefix'] ); ?>" 
					placeholder="+356"
					required 
				/>
			</div>
			
			<div class="realm-account-creation__field">
				<label for="mobile_number">
					Mobile Number <span class="required">*</span>
				</label>
				<input 
					type="tel" 
					id="mobile_number" 
					name="mobile_number" 
					value="<?php echo esc_attr( $form_data['mobile_number'] ); ?>" 
					required 
				/>
			</div>
		</div>
		
		<!-- Row 4: Checkbox | Membership Number -->
		<div class="realm-account-creation__row realm-account-creation__row--two-col">
			<div class="realm-account-creation__field realm-account-creation__field--checkbox">
				<label for="is_realm_member">
					<input 
						type="checkbox" 
						id="is_realm_member" 
						name="is_realm_member" 
						value="1"
						<?php checked( $form_data['is_realm_member'], true ); ?>
					/>
					Already a Realm Member?
				</label>
			</div>
			
			<div class="realm-account-creation__field realm-account-creation__membership-field <?php echo $form_data['is_realm_member'] ? '' : 'is-hidden'; ?>" 
			     aria-hidden="<?php echo $form_data['is_realm_member'] ? 'false' : 'true'; ?>">
				<label for="membership_number">
					Membership Number <span class="required membership-required">*</span>
				</label>
				<input 
					type="text" 
					id="membership_number" 
					name="membership_number" 
					value="<?php echo esc_attr( $form_data['membership_number'] ); ?>" 
				/>
			</div>
		</div>
		
		<!-- Submit Button -->
		<div class="realm-account-creation__submit">
			<button type="submit" class="button">
				Register Now
			</button>
		</div>
		
	</form>
	</div><!-- .realm-account-creation__form-container -->
	
</div>

<script>
(function() {
	'use strict';
	
	// Legacy inline toggle script removed - now handled by ajax.js
	// Kept for non-JS fallback if needed
	document.addEventListener('DOMContentLoaded', function() {
		var checkbox = document.getElementById('is_realm_member');
		var membershipField = document.querySelector('.realm-account-creation__membership-field');
		
		if (!checkbox || !membershipField || typeof jQuery !== 'undefined') {
			// Skip if jQuery is available (handled by ajax.js)
			return;
		}
		
		function toggleMembershipField() {
			if (checkbox.checked) {
				membershipField.classList.remove('is-hidden');
				membershipField.setAttribute('aria-hidden', 'false');
				checkbox.setAttribute('aria-expanded', 'true');
			} else {
				membershipField.classList.add('is-hidden');
				membershipField.setAttribute('aria-hidden', 'true');
				checkbox.setAttribute('aria-expanded', 'false');
			}
		}
		
		checkbox.setAttribute('aria-expanded', checkbox.checked ? 'true' : 'false');
		checkbox.addEventListener('change', toggleMembershipField);
	});
})();
</script>
