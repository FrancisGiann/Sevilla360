<!-- Date Confirmation Modal -->
<div class="modal-overlay" id="date-confirm-modal">
    <div class="modal-content">
        <h3 style="font-family: var(--font-heading); font-size: 1.8rem; margin-bottom: 15px;">Confirm Dates</h3>
        <div class="modal-body" style="text-align: center; margin-bottom: 25px;">
            <p style="font-size: 1.1rem; color: var(--color-dark);">You have selected:<br><strong
                    id="selected-date-text"
                    style="color: var(--color-gold); display: block; margin-top: 10px; font-size: 1.2rem;"></strong>
            </p>
            <p style="font-size: 0.9rem; margin-top: 15px;">Proceeding will lock these dates for 30 minutes while
                you complete your booking.</p>
        </div>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button class="btn btn-primary" id="btn-confirm-date">Confirm</button>
            <button class="btn btn-outline" id="btn-cancel-date"
                style="color: var(--color-dark); border-color: var(--color-dark);">Cancel</button>
        </div>
    </div>
</div>

<!-- Override Lock Date Modal -->
<div class="modal-overlay" id="override-date-modal">
    <div class="modal-content">
        <h3 style="font-family: var(--font-heading); font-size: 1.8rem; margin-bottom: 15px;">Change Dates?</h3>
        <div class="modal-body" style="text-align: center; margin-bottom: 25px;">
            <p style="font-size: 1.1rem; color: var(--color-dark);">You currently have dates locked for this
                session.</p>
            <p style="font-size: 0.9rem; margin-top: 10px;">Would you like to cancel your current selection and pick
                new dates?</p>
        </div>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button class="btn btn-primary" id="btn-override-yes">Yes, Change Dates</button>
            <button class="btn btn-outline" id="btn-override-no"
                style="color: var(--color-dark); border-color: var(--color-dark);">No, Keep Current</button>
        </div>
    </div>
</div>

<!-- T&C Modal -->
<div class="modal-overlay" id="tnc-modal">
    <div class="modal-content">
        <h3>Terms and Conditions</h3>
        <div class="modal-body">
            <p>Welcome to Sevilla360 Booking System. By proceeding, you agree to our standard reservation rules,
                cancellation policies, and resort etiquette guidelines.</p>
            <p>1. All bookings are final upon payment processing.</p>
            <p>2. Maximum capacities are strictly implemented.</p>
            <p>3. Damage to resort property will be billed to the client's account.</p>
        </div>
        <button class="btn btn-primary" id="btn-agree">I Agree</button>
    </div>
</div>