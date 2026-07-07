<!-- Confirm Dates Modal -->
<div class="modal-overlay" id="confirm-dates-modal">
    <div class="modal-content">
        <h2 class="modal-title">Confirm Dates</h2>
        <p class="modal-text">You have selected:</p>
        <h3 class="modal-highlight-date" id="confirm-date-display">--</h3>
        <p class="modal-text">Proceeding will lock these dates for 30 minutes while you complete your booking.</p>
        <div class="modal-actions">
            <button class="btn-modal-primary" id="btn-confirm-dates">CONFIRM</button>
            <button class="btn-modal-outline" id="btn-cancel-dates">CANCEL</button>
        </div>
    </div>
</div>

<!-- Change Dates Modal -->
<div class="modal-overlay" id="change-dates-modal">
    <div class="modal-content">
        <h2 class="modal-title">Change Dates?</h2>
        <p class="modal-text">You currently have dates locked for this session.</p>
        <p class="modal-text">Would you like to cancel your current selection and pick new dates?</p>
        <div class="modal-actions">
            <button class="btn-modal-primary" id="btn-override-yes">YES, CHANGE DATES</button>
            <button class="btn-modal-outline" id="btn-override-no">NO, KEEP CURRENT</button>
        </div>
    </div>
</div>