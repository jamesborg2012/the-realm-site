<?php

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

$membership_cost = get_option("rmm_{$rounded}_months_membership");

$year = date('Y');
if ($rounded === 12) {
    $year++;
}

$expiryDate = new DateTime("$year-12-31");

?>

<div class="rmm-membership-form-container">
    <form class="rmm-new-member-form" action="#" method="POST">
        <div class="form-row">
            <div class="col">
                <input type="text" name="fname" id="fname" placeholder="First Name">
            </div>
            <div class="col">
                <input type="text" name="lname" id="lname" placeholder="Last Name">
            </div>
        </div>
        <div class="form-row">
            <div class="col">
                <input type="text" name="phone" id="phone" placeholder="+356 7777 7777">
            </div>
            <div class="col">
                <input type="email" name="email" id="email" placeholder="email@gmail.com">
            </div>
        </div>
        <div class="form-row-no-flex membership-information">
            <h3 class="membership-cost">Membership cost: €<?= $membership_cost ?></h3>
            <h3 class="membership-length">Membership will last until <?= $expiryDate->format('F j, Y') ?></h3>
        </div>
        <div class="submit-form-row">
            <button type="submit" class="submit-membership-form-btn">Become a Member</button>
        </div>
    </form>
</div>