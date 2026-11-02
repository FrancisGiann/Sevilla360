# Catering package modal

This release replaces the long inline catering editors with compact package summaries and an Edit button for each package. The native modal keeps its header/Close control and Save/Cancel footer visible while its body scrolls. Names, prices, and ordered menu rows remain editable; existing Add, Remove, Up, Down, and validation behavior is retained.

Modal drafts are separate from the Preferences form. Clean Cancel/Escape closes the editor; dirty close requests offer Keep editing or Discard changes. Page-unload protection also covers unsaved modal work. Keyboard focus returns to the package's Edit button, and the native dialog confines interaction to the editor while open.

Save package uses `actions/admin/save_catering_package.php` to validate and persist only that package's name, rate, and menu JSON, plus an audit entry in the same transaction. It retains the existing administrator, active-account, and CSRF checks. A failed save retains the draft for retry. A successful save updates the summary and hidden Preferences values without clearing unrelated unsaved preference edits. Opening the modal is blocked while a whole-Preferences save is in flight.

No schema migration or price reset is included. Existing package settings, bookings, payments, and the Database Backup page are preserved. The previously approved live price update is a separate operation; publishing this UI does not apply it.

## Verification and release boundaries

The focused catering and admin Settings contracts, PHP lint, JavaScript syntax, and whitespace checks pass. Isolated-database checks passed selected-package saves, invalid-input rejection, staff/CSRF rejection, and preservation of unrelated settings. Browser checks passed save/reload, ordered menu edits, failure/retry, clean and dirty Escape, discard, focus restoration, unload protection, and 30-row desktop/mobile scrolling with visible header/footer and no horizontal overflow. An explicit Tab guard fixes a reproduced browser boundary issue; the final browser check passed Save → Tab → Close and Close → Shift+Tab → Save. Publication receipts and live-verification results are recorded separately from this source document.

The release scope is the catering Settings markup/styles/controller, the shared package validator, the new scoped save endpoint, focused tests, and this note. Rollback requires reverting only this release onto the then-current main; no schema rollback is needed. Retain the existing catering settings when reverting the UI.
