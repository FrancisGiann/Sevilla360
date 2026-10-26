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
                <div id="manual-payment-qr-panel" class="manual-payment-qr-panel" hidden>
                    <img id="manual-payment-qr" class="manual-payment-qr" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="" hidden>
                    <div class="manual-payment-qr-actions">
                        <button type="button" id="manual-payment-qr-view" class="manual-payment-qr-action" aria-controls="manual-payment-qr-lightbox" aria-expanded="false" hidden>Enlarge QR</button>
                        <a id="manual-payment-qr-save" class="manual-payment-qr-action" hidden>Save QR image</a>
                    </div>
                    <p id="manual-payment-qr-guidance" class="manual-payment-qr-guidance"></p>
                </div>
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
    <div id="manual-payment-qr-lightbox" class="manual-payment-qr-lightbox" role="region" aria-label="Enlarged payment QR code" aria-hidden="true" hidden>
        <button type="button" id="manual-payment-qr-close" class="manual-payment-qr-lightbox-close" aria-label="Close enlarged QR code"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        <div id="manual-payment-qr-stage" class="manual-payment-qr-stage"></div>
    </div>
</div>
