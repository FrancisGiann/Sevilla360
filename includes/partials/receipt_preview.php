<dialog class="receipt-preview-dialog" id="receipt-preview-dialog" aria-modal="true" aria-labelledby="receipt-preview-title" aria-describedby="receipt-preview-context">
    <div class="receipt-preview-dialog__surface">
        <header class="receipt-preview-dialog__header">
            <div>
                <h2 id="receipt-preview-title">PDF receipt</h2>
                <p id="receipt-preview-context">Booking receipt</p>
            </div>
            <button class="receipt-preview-dialog__icon-close" type="button" data-receipt-preview-close aria-label="Close PDF receipt preview">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"/></svg>
            </button>
        </header>
        <div class="receipt-preview-dialog__stage" data-receipt-preview-stage aria-busy="true">
            <p class="receipt-preview-dialog__loading" data-receipt-preview-loading role="status">
                <span class="receipt-preview-dialog__spinner" aria-hidden="true"></span>Preparing your receipt…
            </p>
            <div class="receipt-preview-dialog__error" data-receipt-preview-error role="alert" hidden>
                <p data-receipt-preview-error-message></p>
                <button class="receipt-preview-dialog__button receipt-preview-dialog__button--quiet" type="button" data-receipt-preview-retry>Try again</button>
            </div>
            <div class="receipt-preview-dialog__fallback" data-receipt-preview-fallback role="status" aria-live="polite" hidden>
                <h3>Preview unavailable in this browser</h3>
                <p>Your receipt is ready. Download the PDF to open or print it with a viewer on your device.</p>
            </div>
            <iframe class="receipt-preview-dialog__frame" data-receipt-preview-frame title="Booking receipt PDF preview" hidden></iframe>
        </div>
        <p class="receipt-preview-dialog__hint" role="status" aria-live="polite">Use the PDF viewer controls to zoom or print. Download a copy to keep the receipt.</p>
        <footer class="receipt-preview-dialog__actions">
            <button class="receipt-preview-dialog__button receipt-preview-dialog__button--quiet" type="button" data-receipt-preview-close>Close</button>
            <a class="receipt-preview-dialog__button receipt-preview-dialog__button--primary" data-receipt-preview-download hidden>Download PDF</a>
        </footer>
    </div>
</dialog>
