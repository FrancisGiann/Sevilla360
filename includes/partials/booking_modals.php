<!-- Date Confirmation Modal -->
<div class="modal-overlay" id="date-confirm-modal">
    <div class="modal-content">
        <h3 class="modal-title">Confirm Dates</h3>
        <div class="modal-body modal-text-center">
            <p>You have selected:<br>
                <strong id="selected-date-text" class="modal-date-highlight"></strong>
            </p>
            <p class="modal-subtext">Proceeding will lock these dates for 30 minutes while you complete your booking.
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
<div class="modal-overlay" id="tnc-modal">
    <div class="modal-content">
        <h3 class="modal-title">Terms and Conditions</h3>
        <div class="modal-body">
            <p>Welcome to Sevilla360 Booking System. By proceeding, you agree to our standard reservation rules,
                cancellation policies, and resort etiquette guidelines.</p>
            <p>1. All bookings are final upon payment processing.</p>
            <p>2. Maximum capacities are strictly implemented.</p>
            <p>3. Damage to resort property will be billed to the client's account.</p>
        </div>
        <div class="modal-actions-center">
            <button class="btn btn-primary" id="btn-agree">I Agree</button>
        </div>
    </div>
</div>

<!-- Switch Tab Warning Modal -->
<div class="modal-overlay" id="switch-tab-modal">
    <div class="modal-content modal-sm">
        <i class="fa-solid fa-triangle-exclamation modal-icon-warning"></i>
        <h3 class="modal-title">Change Service?</h3>
        <div class="modal-body modal-text-center">
            <p>You are currently booking a space. Switching tabs will cancel your current selection and release any
                locked dates. Do you want to proceed?</p>
        </div>
        <div class="modal-actions-center">
            <button class="btn btn-outline btn-modal-cancel" id="btn-cancel-switch">No, Cancel</button>
            <button class="btn btn-primary btn-modal-danger" id="btn-confirm-switch">Yes, Switch</button>
        </div>
    </div>
</div>