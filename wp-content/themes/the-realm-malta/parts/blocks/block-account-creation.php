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
];

// Get the current month number (1 = January, 12 = December)
$currentMonth = (int) date('n');

// Calculate how many months are left including the current one
$monthsUntilDecember = 12 - $currentMonth + 1;

// Round to the nearest multiple of 3
$rounded = round($monthsUntilDecember / 3) * 3;

// If the result is 1 or 2 after rounding, set it to 12
if ($rounded === 1) {
    $rounded = 12;
}

$donation_amount = get_option("rmm_{$rounded}_months_membership", 'X');

$year = date('Y');
if ($rounded === 12) {
    $year++;
}

$expiryDate = new DateTime("$year-12-31");

?>

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
	<div class="realm-account-creation__success-container is-hidden" data-user-id="" data-membership-token="">
		<div class="realm-account-creation__success">
			<p>Your account is registered and will be finalised soon after review.</p>
		</div>

		<div class="realm-account-creation__login-link">
			<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button">
				Login Now
			</a>
		</div>

		<!-- Membership Offer Section -->
		<div class="realm-membership-offer is-hidden">
			<h3 class="realm-membership-offer__heading">Become a Member of the Realm.</h3>
			
			<div class="realm-membership-offer__content">
				<p class="realm-membership-offer__description">
					Enjoy benefits such as amazing discounts on products and access to gaming tables at the Realm location!
				</p>
				
			<ul class="realm-membership-offer__details">
				<li><strong>Donation:</strong> €<?= $donation_amount ?></li>
				<li><strong>Valid Until:</strong> <?= $expiryDate->format('F j, Y') ?></li>
					<li><strong>Renews yearly at the end of the year</strong></li>
				</ul>
				
				<div class="realm-membership-offer__actions">
					<button type="button" class="button js-realm-membership-apply">
						Become a Member
					</button>
				</div>
				
				<!-- Message placeholder for post-submit confirmation -->
				<div class="realm-membership-offer__message is-hidden"></div>
			</div>
		</div>
	</div>
	
	<!-- Form Container -->
	<div class="realm-account-creation__form-container">
		<form method="post" class="realm-account-creation__form js-realm-account-form" novalidate autocomplete="off">
			
			<?php wp_nonce_field( 'realm_account_creation', 'realm_account_creation_nonce' ); ?>
			<input type="hidden" name="realm_account_creation_action" value="submit" />
			
			<!-- Anti-autofill decoy fields (visually hidden) -->
			<div style="position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden;">
				<input type="text" name="decoy_name" tabindex="-1" autocomplete="off" />
				<input type="email" name="decoy_email" tabindex="-1" autocomplete="off" />
				<input type="tel" name="decoy_phone" tabindex="-1" autocomplete="off" />
			</div>
		
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
					autocomplete="new-password"
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
					autocomplete="new-password"
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
					autocomplete="new-password"
					required 
				/>
			</div>
		</div>
		
		<!-- Row 3: Phone Prefix | Mobile Number -->
		<div class="realm-account-creation__row realm-account-creation__row--two-col">
			<div class="realm-account-creation__field">
				<label for="phone_prefix">
					Phone Prefix
				</label>
				<?php
				// Comprehensive list of country calling codes (E.164 compliant)
				$country_codes = array(
					'Malta' => '+356',
					'Afghanistan' => '+93',
					'Albania' => '+355',
					'Algeria' => '+213',
					'American Samoa' => '+1-684',
					'Andorra' => '+376',
					'Angola' => '+244',
					'Anguilla' => '+1-264',
					'Antarctica' => '+672',
					'Antigua and Barbuda' => '+1-268',
					'Argentina' => '+54',
					'Armenia' => '+374',
					'Aruba' => '+297',
					'Australia' => '+61',
					'Austria' => '+43',
					'Azerbaijan' => '+994',
					'Bahamas' => '+1-242',
					'Bahrain' => '+973',
					'Bangladesh' => '+880',
					'Barbados' => '+1-246',
					'Belarus' => '+375',
					'Belgium' => '+32',
					'Belize' => '+501',
					'Benin' => '+229',
					'Bermuda' => '+1-441',
					'Bhutan' => '+975',
					'Bolivia' => '+591',
					'Bosnia and Herzegovina' => '+387',
					'Botswana' => '+267',
					'Brazil' => '+55',
					'British Indian Ocean Territory' => '+246',
					'British Virgin Islands' => '+1-284',
					'Brunei' => '+673',
					'Bulgaria' => '+359',
					'Burkina Faso' => '+226',
					'Burundi' => '+257',
					'Cambodia' => '+855',
					'Cameroon' => '+237',
					'Canada' => '+1',
					'Cape Verde' => '+238',
					'Cayman Islands' => '+1-345',
					'Central African Republic' => '+236',
					'Chad' => '+235',
					'Chile' => '+56',
					'China' => '+86',
					'Christmas Island' => '+61',
					'Cocos Islands' => '+61',
					'Colombia' => '+57',
					'Comoros' => '+269',
					'Cook Islands' => '+682',
					'Costa Rica' => '+506',
					'Croatia' => '+385',
					'Cuba' => '+53',
					'Curacao' => '+599',
					'Cyprus' => '+357',
					'Czech Republic' => '+420',
					'Democratic Republic of the Congo' => '+243',
					'Denmark' => '+45',
					'Djibouti' => '+253',
					'Dominica' => '+1-767',
					'Dominican Republic' => '+1-809',
					'East Timor' => '+670',
					'Ecuador' => '+593',
					'Egypt' => '+20',
					'El Salvador' => '+503',
					'Equatorial Guinea' => '+240',
					'Eritrea' => '+291',
					'Estonia' => '+372',
					'Ethiopia' => '+251',
					'Falkland Islands' => '+500',
					'Faroe Islands' => '+298',
					'Fiji' => '+679',
					'Finland' => '+358',
					'France' => '+33',
					'French Polynesia' => '+689',
					'Gabon' => '+241',
					'Gambia' => '+220',
					'Georgia' => '+995',
					'Germany' => '+49',
					'Ghana' => '+233',
					'Gibraltar' => '+350',
					'Greece' => '+30',
					'Greenland' => '+299',
					'Grenada' => '+1-473',
					'Guam' => '+1-671',
					'Guatemala' => '+502',
					'Guernsey' => '+44-1481',
					'Guinea' => '+224',
					'Guinea-Bissau' => '+245',
					'Guyana' => '+592',
					'Haiti' => '+509',
					'Honduras' => '+504',
					'Hong Kong' => '+852',
					'Hungary' => '+36',
					'Iceland' => '+354',
					'India' => '+91',
					'Indonesia' => '+62',
					'Iran' => '+98',
					'Iraq' => '+964',
					'Ireland' => '+353',
					'Isle of Man' => '+44-1624',
					'Israel' => '+972',
					'Italy' => '+39',
					'Ivory Coast' => '+225',
					'Jamaica' => '+1-876',
					'Japan' => '+81',
					'Jersey' => '+44-1534',
					'Jordan' => '+962',
					'Kazakhstan' => '+7',
					'Kenya' => '+254',
					'Kiribati' => '+686',
					'Kosovo' => '+383',
					'Kuwait' => '+965',
					'Kyrgyzstan' => '+996',
					'Laos' => '+856',
					'Latvia' => '+371',
					'Lebanon' => '+961',
					'Lesotho' => '+266',
					'Liberia' => '+231',
					'Libya' => '+218',
					'Liechtenstein' => '+423',
					'Lithuania' => '+370',
					'Luxembourg' => '+352',
					'Macau' => '+853',
					'Macedonia' => '+389',
					'Madagascar' => '+261',
					'Malawi' => '+265',
					'Malaysia' => '+60',
					'Maldives' => '+960',
					'Mali' => '+223',
					'Marshall Islands' => '+692',
					'Mauritania' => '+222',
					'Mauritius' => '+230',
					'Mayotte' => '+262',
					'Mexico' => '+52',
					'Micronesia' => '+691',
					'Moldova' => '+373',
					'Monaco' => '+377',
					'Mongolia' => '+976',
					'Montenegro' => '+382',
					'Montserrat' => '+1-664',
					'Morocco' => '+212',
					'Mozambique' => '+258',
					'Myanmar' => '+95',
					'Namibia' => '+264',
					'Nauru' => '+674',
					'Nepal' => '+977',
					'Netherlands' => '+31',
					'Netherlands Antilles' => '+599',
					'New Caledonia' => '+687',
					'New Zealand' => '+64',
					'Nicaragua' => '+505',
					'Niger' => '+227',
					'Nigeria' => '+234',
					'Niue' => '+683',
					'North Korea' => '+850',
					'Northern Mariana Islands' => '+1-670',
					'Norway' => '+47',
					'Oman' => '+968',
					'Pakistan' => '+92',
					'Palau' => '+680',
					'Palestine' => '+970',
					'Panama' => '+507',
					'Papua New Guinea' => '+675',
					'Paraguay' => '+595',
					'Peru' => '+51',
					'Philippines' => '+63',
					'Pitcairn' => '+64',
					'Poland' => '+48',
					'Portugal' => '+351',
					'Puerto Rico' => '+1-787',
					'Qatar' => '+974',
					'Republic of the Congo' => '+242',
					'Reunion' => '+262',
					'Romania' => '+40',
					'Russia' => '+7',
					'Rwanda' => '+250',
					'Saint Barthelemy' => '+590',
					'Saint Helena' => '+290',
					'Saint Kitts and Nevis' => '+1-869',
					'Saint Lucia' => '+1-758',
					'Saint Martin' => '+590',
					'Saint Pierre and Miquelon' => '+508',
					'Saint Vincent and the Grenadines' => '+1-784',
					'Samoa' => '+685',
					'San Marino' => '+378',
					'Sao Tome and Principe' => '+239',
					'Saudi Arabia' => '+966',
					'Senegal' => '+221',
					'Serbia' => '+381',
					'Seychelles' => '+248',
					'Sierra Leone' => '+232',
					'Singapore' => '+65',
					'Sint Maarten' => '+1-721',
					'Slovakia' => '+421',
					'Slovenia' => '+386',
					'Solomon Islands' => '+677',
					'Somalia' => '+252',
					'South Africa' => '+27',
					'South Korea' => '+82',
					'South Sudan' => '+211',
					'Spain' => '+34',
					'Sri Lanka' => '+94',
					'Sudan' => '+249',
					'Suriname' => '+597',
					'Svalbard and Jan Mayen' => '+47',
					'Swaziland' => '+268',
					'Sweden' => '+46',
					'Switzerland' => '+41',
					'Syria' => '+963',
					'Taiwan' => '+886',
					'Tajikistan' => '+992',
					'Tanzania' => '+255',
					'Thailand' => '+66',
					'Togo' => '+228',
					'Tokelau' => '+690',
					'Tonga' => '+676',
					'Trinidad and Tobago' => '+1-868',
					'Tunisia' => '+216',
					'Turkey' => '+90',
					'Turkmenistan' => '+993',
					'Turks and Caicos Islands' => '+1-649',
					'Tuvalu' => '+688',
					'U.S. Virgin Islands' => '+1-340',
					'Uganda' => '+256',
					'Ukraine' => '+380',
					'United Arab Emirates' => '+971',
					'United Kingdom' => '+44',
					'United States' => '+1',
					'Uruguay' => '+598',
					'Uzbekistan' => '+998',
					'Vanuatu' => '+678',
					'Vatican' => '+379',
					'Venezuela' => '+58',
					'Vietnam' => '+84',
					'Wallis and Futuna' => '+681',
					'Western Sahara' => '+212',
					'Yemen' => '+967',
					'Zambia' => '+260',
					'Zimbabwe' => '+263',
				);
				
				// Get selected value (preserve on validation failure)
				$selected_prefix = !empty($form_data['phone_prefix']) ? $form_data['phone_prefix'] : '+356';
				?>
				<select
					id="phone_prefix"
					name="phone_prefix"
					class="realm-phone-prefix-select"
					autocomplete="new-password"
				>
					<option value="">Select Phone Prefix</option>
					<?php
					// Malta first (default)
					$malta_code = $country_codes['Malta'];
					$is_selected = ($selected_prefix === $malta_code) ? ' selected' : '';
					echo '<option value="' . esc_attr($malta_code) . '"' . $is_selected . '>Malta (' . esc_html($malta_code) . ')</option>';
					
					// Remove Malta from array to avoid duplication
					unset($country_codes['Malta']);
					
					// Sort remaining countries alphabetically
					ksort($country_codes);
					
					// Render all other countries
					foreach ($country_codes as $country => $code) {
						$is_selected = ($selected_prefix === $code) ? ' selected' : '';
						echo '<option value="' . esc_attr($code) . '"' . $is_selected . '>' . esc_html($country) . ' (' . esc_html($code) . ')</option>';
					}
					?>
				</select>
			</div>
			
			<div class="realm-account-creation__field">
				<label for="mobile_number">
					Mobile Number
				</label>
				<input
					type="tel"
					id="mobile_number"
					name="mobile_number"
					value="<?php echo esc_attr( $form_data['mobile_number'] ); ?>"
					autocomplete="new-password"
				/>
			</div>
		</div>
		
		<!-- Row 4: Password | Confirm Password -->
		<div class="realm-account-creation__row realm-account-creation__row--two-col">
			<div class="realm-account-creation__field">
				<label for="password">
					Password <span class="required">*</span>
				</label>
				<input
					type="password"
					id="password"
					name="password"
					autocomplete="new-password"
					required
				/>
				<span class="realm-account-creation__field-hint">Minimum 8 characters, including a number and special character.</span>
			</div>

			<div class="realm-account-creation__field">
				<label for="password_confirm">
					Confirm Password <span class="required">*</span>
				</label>
				<input
					type="password"
					id="password_confirm"
					name="password_confirm"
					autocomplete="new-password"
					required
				/>
			</div>
		</div>
		
		<!-- Row 5: Checkbox | Membership Number -->
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
