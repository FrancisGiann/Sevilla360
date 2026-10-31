# Seminar reservations at finalization

Saving a draft saves preferred hall/room dates and attendee assignments. It does not reserve inventory, and payment recording does not change this. Staff can plan against the same dates in multiple drafts. Availability can change before finalization.

**Finalize and reserve** rechecks the hall and every selected physical hotel room while holding the resource mutexes, validates the assignments, and changes the seminar to `finalized` in one transaction. No subset is reserved on failure. Conflicts identify the venue/room, interval and reason; the draft remains editable through **Edit reservations**. Payment is not a prerequisite. Existing attendee-capacity and mixed-gender rules apply; hall overcapacity remains a warning.

Only `finalized` seminar reservations affect public availability/recommendations, calendars, customer/walk-in booking locks and submissions, add-on allocations, maintenance and other seminars. Hotel stays use checkout-exclusive intervals. Hall reservations include both event dates.

For a finalized seminar, **Edit reservations** prepares changes in the browser while the original allocation stays active. **Save and reserve changes** locks the union of old and proposed venue IDs in ascending order, checks all replacements with fresh reads, and commits the replacement atomically. Changing selected room units requires an explicit confirmation because attendee assignments will be regenerated. Insufficient capacity, assignment validation, payment price-floor or inventory conflicts roll the entire amendment back. Date-only changes keep assignments and mixed-gender approvals.

Finalized reservations no longer transition back to draft through the old `reopen` action: that would release committed inventory under the new rules. The UI replaces that action with direct **Edit reservations**. This supports finalized room/date amendments; it does not add finalized roster editing. Cancellation releases the resources and retains the roster/payment history. Cancelled seminars cannot be reopened; create a new draft if needed.

## Rollout

No schema or data migration is required. Existing draft rows in `seminar_reservations` are retained as preferences and stop blocking as soon as the updated predicates are deployed. Existing finalized rows keep blocking. Inform staff that previously saved drafts are no longer exclusive and may require replacement rooms when finalized.

Deploy the status-predicate changes and mutation/availability endpoints together; mixed old/new application nodes would disagree about draft availability. Database transactions explicitly use READ COMMITTED before resource locking, so the server default can remain REPEATABLE READ. Customer and walk-in allocation transactions use the same fresh-read pattern, including a post-lock seminar check for add-ons. Online date selection remains nonexclusive; the existing short hold begins at payment entry. No production data cleanup or migration is required.

## Local verification

The synthetic integration suite refuses to run without an explicit `/tmp/` MariaDB socket and creates a uniquely named disposable database. It never loads application database configuration, sends messages or uses production rows:

```sh
SEVILLA_TEST_DB_SOCKET=/tmp/your-isolated-mariadb.sock php scripts/test_seminar_finalization_transaction.php
SEVILLA360_CONTRACT_STATIC_ONLY=1 php scripts/test_seminar_contract.php
```

The test fixture request runner is CLI-only. The suite checks actual customer/walk-in lock, add-on and rejection endpoints in subprocesses, plus the real seminar transaction functions, 100 attendees, concurrent finalization, late booking races, cancellation, dates and payment history. It drops its database in `finally`.
