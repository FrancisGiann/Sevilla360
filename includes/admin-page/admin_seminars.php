<section class="seminars-page" id="seminars-app" data-endpoint="actions/admin/seminars.php" data-payments-endpoint="actions/admin/seminar_payments.php">
    <header class="seminars-heading">
        <div>
            <h1>Seminar rooming</h1>
            <p>Build an attendee roster, hold the Event Hall and hotel rooms, then prepare room sheets.</p>
        </div>
    </header>
    <div class="seminar-feedback" id="seminar-feedback" role="status" aria-live="polite" aria-atomic="true" hidden>
        <p class="seminar-feedback__message" data-feedback-message></p>
        <button class="seminar-feedback__action seminar-button seminar-button--quiet" type="button" data-feedback-action hidden></button>
        <button class="seminar-feedback__dismiss" type="button" data-feedback-dismiss aria-label="Dismiss message">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"/></svg>
        </button>
    </div>
    <div class="seminar-layout">
        <main class="seminar-workspace" id="seminar-workspace">
            <nav class="seminar-wizard-progress" aria-label="Seminar setup steps">
                <div class="seminar-wizard-progress__step is-current" aria-current="step"><span>1</span><strong>Import attendees</strong></div>
                <div class="seminar-wizard-progress__step"><span>2</span><strong>Seminar details</strong></div>
                <div class="seminar-wizard-progress__step"><span>3</span><strong>Select rooms</strong></div>
                <div class="seminar-wizard-progress__step"><span>4</span><strong>Review and print</strong></div>
            </nav>
            <div class="seminar-flow-head">
                <h2>Upload the attendee roster</h2>
                <p>CSV and XLSX files up to 5 MB. Required columns: name, gender and location.</p>
            </div>
            <section class="seminar-upload-area" aria-label="Upload attendee roster">
                <form id="seminar-upload-form" class="seminar-upload-form">
                    <label class="seminar-dropzone seminar-file-picker">
                        <input class="seminar-file-input" type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                        <span class="seminar-file-picker__button">Choose roster file</span>
                        <span class="seminar-upload-copy"><strong data-file-name>Select the client roster to preview it</strong><small>CSV or XLSX, up to 5 MB. Name, gender and location are required; contact is optional.</small></span>
                    </label>
                    <p class="seminar-upload-status" data-upload-status role="status" aria-live="polite" hidden></p>
                </form>
            </section>
            <div class="seminar-actions-row"><button class="seminar-button seminar-button--primary" id="seminar-continue" type="button" disabled>Continue to seminar details</button></div>
        </main>
        <section class="seminar-saved-plans" id="seminar-saved-plans" aria-labelledby="seminar-saved-plans-title" hidden>
            <header class="seminar-saved-plans__heading">
                <h2 id="seminar-saved-plans-title">Saved seminar plans</h2>
                <p>Open a plan to review or update its room assignments.</p>
            </header>
            <div id="seminar-list" class="seminar-list"></div>
        </section>
    </div>
    <dialog class="seminar-confirm-dialog" id="seminar-confirm-dialog" aria-modal="true" aria-labelledby="seminar-confirm-title" aria-describedby="seminar-confirm-message">
        <div class="seminar-confirm-dialog__surface">
            <h2 id="seminar-confirm-title">Confirm this action</h2>
            <p id="seminar-confirm-message"></p>
            <div class="seminar-confirm-dialog__actions">
                <button class="seminar-button seminar-button--quiet" type="button" data-confirm-cancel>Keep working</button>
                <button class="seminar-button seminar-button--primary" type="button" data-confirm-accept>Continue</button>
            </div>
        </div>
    </dialog>
    <dialog class="seminar-confirm-dialog seminar-payment-void-dialog" id="seminar-payment-void-dialog" aria-modal="true" aria-labelledby="seminar-payment-void-title" aria-describedby="seminar-payment-void-copy">
        <form class="seminar-confirm-dialog__surface seminar-payment-void-dialog__surface" id="seminar-payment-void-form" novalidate>
            <h2 id="seminar-payment-void-title">Correct this payment</h2>
            <p id="seminar-payment-void-copy">This is an accounting correction only; it does not return funds. The original receipt stays in history and its amount is removed from the paid total. Record any replacement separately.</p>
            <label class="seminar-field seminar-payment-void-reason"><span>Correction reason</span><textarea name="reason" rows="3" maxlength="500" required></textarea></label>
            <p class="seminar-payment-void-status" id="seminar-payment-void-status" role="status" aria-live="polite" hidden></p>
            <div class="seminar-confirm-dialog__actions">
                <button class="seminar-button seminar-button--quiet" type="button" data-payment-void-cancel>Keep payment</button>
                <button class="seminar-button seminar-button--danger" type="submit">Apply accounting correction</button>
            </div>
        </form>
    </dialog>
    <dialog class="seminar-pdf-dialog" id="seminar-pdf-dialog" aria-modal="true" aria-labelledby="seminar-pdf-title" aria-describedby="seminar-pdf-context">
        <div class="seminar-pdf-dialog__surface">
            <header class="seminar-pdf-dialog__header">
                <div>
                    <h2 id="seminar-pdf-title">PDF preview</h2>
                    <p id="seminar-pdf-context" data-pdf-context></p>
                </div>
                <button class="seminar-pdf-dialog__icon-close" type="button" data-pdf-close aria-label="Close PDF preview">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"/></svg>
                </button>
            </header>
            <div class="seminar-pdf-preview" data-pdf-stage aria-busy="true">
                <p class="seminar-pdf-preview__loading" data-pdf-loading role="status"><span class="seminar-loading-indicator" aria-hidden="true"></span>Preparing your PDF preview…</p>
                <div class="seminar-pdf-preview__error" data-pdf-error role="alert" hidden>
                    <p data-pdf-error-message></p>
                    <button class="seminar-button seminar-button--quiet" type="button" data-pdf-retry>Try again</button>
                </div>
                <div class="seminar-pdf-preview__fallback" data-pdf-fallback role="status" aria-live="polite" hidden>
                    <h3>Preview unavailable in this browser</h3>
                    <p>The PDF was created successfully. Download it below to open and print it with a PDF viewer on your device.</p>
                </div>
                <iframe class="seminar-pdf-preview__frame" data-pdf-frame title="Seminar PDF preview" hidden></iframe>
            </div>
            <p class="seminar-pdf-dialog__hint" id="seminar-pdf-print-help" data-pdf-print-help role="status" aria-live="polite">If the Print button does not open a print dialog, use the PDF viewer’s print control or download the file and print it from your device.</p>
            <div class="seminar-pdf-dialog__actions">
                <button class="seminar-button seminar-button--quiet" type="button" data-pdf-close>Close</button>
                <a class="seminar-button seminar-button--quiet" data-pdf-download hidden>Download PDF</a>
                <button class="seminar-button seminar-button--primary" type="button" data-pdf-print disabled>Print</button>
            </div>
        </div>
    </dialog>
</section>
