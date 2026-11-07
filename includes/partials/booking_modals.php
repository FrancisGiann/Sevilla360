<!-- Date Confirmation Modal -->
<div class="modal-overlay" id="date-confirm-modal">
    <div class="modal-content">
        <h3 class="modal-title">Confirm Dates</h3>
        <div class="modal-body modal-text-center">
            <p>You have selected:<br>
                <strong id="selected-date-text" class="modal-date-highlight"></strong>
            </p>
            <p class="modal-subtext">These dates are available now. Continue to review your reservation.
            </p>
        </div>
        <div class="modal-actions-center">
            <button class="btn btn-primary" id="btn-confirm-date">Confirm</button>
            <button class="btn btn-outline btn-modal-cancel" id="btn-cancel-date">Cancel</button>
        </div>
    </div>
</div>

<!-- Override Lock Date Modal -->
<div class="modal-overlay" id="override-date-modal">
    <div class="modal-content">
        <h3 class="modal-title">Change Dates?</h3>
        <div class="modal-body modal-text-center">
            <p>You currently have dates locked for this session.</p>
            <p class="modal-subtext">Would you like to cancel your current selection and pick new dates?</p>
        </div>
        <div class="modal-actions-center">
            <button class="btn btn-primary" id="btn-override-yes">Yes, Change Dates</button>
            <button class="btn btn-outline btn-modal-cancel" id="btn-override-no">No, Keep Current</button>
        </div>
    </div>
</div>

<!-- T&C Modal -->
<div class="modal-overlay" id="tnc-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="tnc-title">
    <div class="modal-content">
        <h3 class="modal-title" id="tnc-title">Terms and Conditions</h3>
        <div class="modal-body terms-content" id="terms-content" tabindex="0" role="region" aria-label="Terms and Conditions">
            <p>Welcome to Sevilla360 Booking System. By proceeding, you agree to our standard reservation rules,
                cancellation policies, and resort etiquette guidelines.</p>
            <p>1. Payment confirms the reservation subject to the displayed cancellation and refund policy.</p>
            <p>2. Approved PayMongo refunds return to the original payment method. The actual PayMongo processing fee is confirmed with the payment and deducted once from an approved refund; the fee varies by payment method and is not estimated before payment. Refund timing depends on the provider.</p>
            <p>3. Payments made outside PayMongo follow the existing cancellation and refund arrangements shown for that payment method.</p>
            <p>4. Maximum capacities are strictly implemented.</p>
            <p>5. Damage to resort property will be billed to the client's account.</p>
        </div>
        <div class="modal-actions-center">
            <button type="button" class="btn btn-outline" id="btn-close-terms">Close</button>
            <button type="button" class="btn btn-primary" id="btn-agree" aria-describedby="terms-scroll-hint" disabled>I Agree</button>
        </div>
        <p class="terms-scroll-hint" id="terms-scroll-hint" role="status" aria-live="polite">Scroll to the bottom of these terms to enable I Agree.</p>
    </div>
</div>

<!-- Switch Tab Warning Modal -->
<div class="modal-overlay" id="switch-tab-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="switch-tab-title" aria-describedby="switch-tab-description">
    <div class="modal-content modal-sm">
        <i class="fa-solid fa-triangle-exclamation modal-icon-warning" aria-hidden="true"></i>
        <h3 class="modal-title" id="switch-tab-title">Change Service?</h3>
        <div class="modal-body modal-text-center">
            <p id="switch-tab-description">You are currently booking a space. Switching tabs will cancel your current selection and release any
                locked dates. Do you want to proceed?</p>
        </div>
        <div class="modal-actions-center">
            <button class="btn btn-outline btn-modal-cancel" id="btn-cancel-switch">No, Cancel</button>
            <button class="btn btn-primary btn-modal-danger" id="btn-confirm-switch">Yes, Switch</button>
        </div>
    </div>
</div>
