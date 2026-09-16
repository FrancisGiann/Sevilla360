---
target: user dashboard
total_score: 25
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
timestamp: 2026-09-13T04-41-41Z
slug: user-dashboard-php
---
# User Dashboard Design Critique

## Design Health Score

| # | Heuristic | Score | Key issue |
|---|---|---:|---|
| 1 | Visibility of System Status | 3 | Most booking actions show progress and results, but notification refresh failures are silent. |
| 2 | Match System / Real World | 3 | Customer-facing language is generally clear, but “Pending Payment” does not match the booking-status value being counted. |
| 3 | User Control and Freedom | 3 | Modals support cancel, Escape, and focus restoration; notification read actions have no undo. |
| 4 | Consistency and Standards | 2 | The polished Overview and denser legacy Booking History do not feel fully unified. |
| 5 | Error Prevention | 3 | Cancellation and rescheduling require reasons and policy acknowledgement, with refund information shown before confirmation. |
| 6 | Recognition Rather Than Recall | 3 | Navigation and recent items are visible, but grouped booking actions reduce “View details” to an icon. |
| 7 | Flexibility and Efficiency | 2 | Filters, pagination, and the collapsible sidebar help, but there are no accelerators or batch-oriented features. |
| 8 | Aesthetic and Minimalist Design | 3 | The Overview is focused; Booking History exposes six status choices simultaneously. |
| 9 | Error Recovery | 2 | Errors are displayed, but messages such as “Network error occurred” do not explain recovery. |
| 10 | Help and Documentation | 1 | Some policy guidance is contextual, but the dashboard has no visible help or support entry. |
| **Total** |  | **25/40** | **Acceptable — sound foundation with important clarity and accessibility work remaining.** |

## Design Specificity Verdict

The customer dashboard feels authored for Sevilla360 rather than category-interchangeable. Its personalized welcome, upcoming stay, outstanding balance, attention items, and recent reservations support real resort-booking tasks. The warm ivory, dark ink, restrained gold, and sage status colors also follow the approved editorial-luxury direction. The main weakness is unevenness: the Overview is calm and focused, while Booking History and Settings retain denser legacy patterns.

The deterministic scan returned `[]`—zero findings in `user_dashboard.php`. That clean result does not invalidate the source-review issues: the detector did not catch the semantic mismatch between the pending-booking query and “Pending Payment,” missing label associations, or click-only notification containers. Browser inspection redirected to authentication, so no reliable dashboard overlay was available.

## Overall Impression

The dashboard is okay and its Overview is strong. Customers can quickly understand what is coming up, whether attention is needed, and where to create a booking. The biggest opportunity is trust and accessibility: labels and controls should communicate the exact booking/payment state and remain usable without a mouse.

## What’s Working

- The Overview follows the customer’s natural task order: welcome, key status, next stay, attention items, then recent bookings.
- Empty states explain what is absent and usually provide a useful next step instead of leaving blank panels.
- Cancellation and rescheduling expose consequential policy/refund details before submission, and the modal implementation includes Escape handling and focus restoration.

## Priority Issues

1. **[P1] “Pending Payment” can mislead customers.** The metric counts bookings whose `booking_status` is `Pending`, not bookings whose payment status is pending. This can erode trust in financial information. Rename the metric to match the query, or intentionally calculate a payment-status metric. Suggested command: `$impeccable clarify`.

2. **[P1] Settings labels are not programmatically associated with inputs.** Several labels lack `for` attributes and the email control lacks a matching ID, weakening screen-reader and click-target behavior. Bind every label to its control while preserving current form behavior. Suggested command: `$impeccable audit`.

3. **[P1] Notification items are click-only `<div>` elements.** Customers using a keyboard cannot reliably open individual notifications, and read-state changes are not announced. Use semantic buttons or links with accessible names and keyboard behavior. Suggested command: `$impeccable harden`.

4. **[P2] The mobile drawer does not contain or restore focus.** The drawer can be opened and dismissed visually, but there is no Escape/focus-management contract comparable to the booking modals. Add focus containment, Escape close, and focus restoration. Suggested command: `$impeccable adapt`.

5. **[P2] Booking History presents six desktop status filters at once.** This is the only observed decision point exceeding four simultaneous options and makes the denser tab feel busier than the Overview. Group the statuses or use one compact status selector while retaining every filter. Suggested command: `$impeccable distill`.

## Cognitive Load

The Overview has low cognitive load: seven of eight checklist items pass. The content is chunked, the primary “Book a Venue” action is visible, and detailed booking information is progressively disclosed. The failed item is minimal choices: Booking History displays six filter pills together. No other decision point clearly exceeded four visible options.

## Emotional Journey

The personalized welcome and upcoming-stay panel create a reassuring entry. Refund calculations and acknowledgements provide useful confidence during cancellation. The emotional low point is financial ambiguity: “Payment action may be needed” offers no direct next step, while “Pending Payment” may describe the wrong state.

## Persona Red Flags

- **Jordan, first-timer:** “Payment action may be needed” does not explain where to pay or what requires attention. Six booking filters assume familiarity with the system’s status vocabulary.
- **Sam, accessibility-dependent:** Settings labels may not be announced reliably, individual notifications are not keyboard controls, and the mobile drawer lacks focus containment.
- **Casey, distracted mobile user:** Responsive cards and usable tap sizes are present in source, but the drawer’s focus behavior is incomplete and could be disorienting after interruption.

## Minor Observations

- “New Booking” nests a `<button>` inside a link; one semantic interactive element should own the action.
- The Booking History empty-state phrase “Time to plan a vacation!” is less precise than the Overview’s task-focused first-booking prompt.
- When “View details” appears beside other row actions, its text disappears and relies on the icon, reducing immediate recognition.

## Questions to Consider

- Should “Pending” mean awaiting booking approval, awaiting quotation, or money currently due?
- When an outstanding balance exists, should the dashboard provide a direct payment action or route the customer to booking details?
- Should a notification open its message, navigate to the related booking, or offer both actions explicitly?
