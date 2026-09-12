<div class="modal-overlay manual-payment-overlay" id="modal-manual-payment" role="dialog" aria-modal="true" aria-labelledby="manual-payment-title" aria-describedby="manual-payment-intro">
    <div class="modal-box manual-payment-modal">
        <button type="button" class="manual-payment-close close-modal" aria-label="Close payment form"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        <h2 class="modal-title" id="manual-payment-title">Submit payment proof</h2>
        <p id="manual-payment-intro">Your booking is saved and reserved while awaiting payment proof. Review the amount and deadline, pay using a listed option, then submit the transfer reference and receipt image.</p>
        <div class="manual-payment-summary" aria-live="polite" aria-busy="false">
            <p><span>Booking</span><strong id="manual-payment-booking">—</strong></p>
            <p><span>Amount due now</span><strong id="manual-payment-amount">—</strong></p>
            <p><span>Payment deadline</span><strong id="manual-payment-deadline">—</strong></p>
        </div>
        <form id="manual-payment-form" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="booking_id" id="manual-payment-booking-id">
            <label for="manual-payment-method">Payment method</label>
            <select name="method" id="manual-payment-method" required disabled></select>
            <section class="manual-payment-instructions" aria-live="polite" aria-label="Selected payment instructions">
                <div id="manual-payment-account"></div>
                <img id="manual-payment-qr" class="manual-payment-qr" src="assets/img/Logo.png" alt="" hidden>
            </section>
            <label for="manual-payment-reference">Transaction or reference number</label>
            <input type="text" name="transaction_reference" id="manual-payment-reference" minlength="4" maxlength="64" autocomplete="off" required aria-describedby="manual-payment-reference-help">
            <small id="manual-payment-reference-help">Use 4–64 letters or numbers; spaces, hyphens, underscores and periods are allowed.</small>
            <label for="manual-payment-receipt">Receipt image <span>(JPEG, PNG or WebP; max 5 MiB)</span></label>
            <input type="file" name="receipt_image" id="manual-payment-receipt" accept="image/jpeg,image/png,image/webp" required>
            <div id="manual-payment-status" class="manual-payment-status" role="status" aria-live="polite" hidden>
                <span id="manual-payment-status-message"></span>
                <a id="manual-payment-contact" href="support.php" hidden>Contact the resort</a>
                <button type="button" id="manual-payment-retry" hidden>Retry loading payment details</button>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-go-back close-modal">Close</button>
                <button type="submit" class="btn-modal btn-confirm" id="manual-payment-submit" disabled>Submit for verification</button>
            </div>
        </form>
    </div>
</div>
