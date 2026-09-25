---
target: admin bookings page
total_score: 23
max_score: 40
na_heuristics:
p0_count: 0
p1_count: 3
timestamp: 2026-09-25T01-52-26Z
slug: admin-dashboard-php-page-bookings
---
# Admin bookings critique — baseline before refinement

Method: dual-agent (A: researcher_bookings_design · B: researcher_bookings_evidence)

Mode: Operate. Design health: 23/40, acceptable. Heuristic scores (visibility, real-world match, control, consistency, prevention, recognition, efficiency, minimalism, recovery, help): 2, 3, 2, 2, 2, 3, 3, 2, 2, 2. All ten apply; none are n/a.

Design specificity: Staff actions reflect Sevilla360's actual booking, payment-proof, refund, reschedule and event-quote workflow. The dense status row and equal-weight actions make the task harder to scan.

What's working: Search and filters; booking detail/payment history; responsive table-to-card treatment.

Priority issues:
1. [P1] Eight status filters compete with search and venue. Fix: clearer selected state, reset and result count; later consider a primary action queue. Suggested command: impeccable distill.
2. [P1] Rows display many equally prominent actions. Fix: one contextual primary action with existing secondary actions disclosed on demand. Suggested command: impeccable layout.
3. [P1] Search and venue lacked explicit labels, selected filter state was unclear, and focus outlines were removed. Fix: labels, pressed state, visible focus and live results. Suggested command: impeccable audit.
4. [P2] Loading and network errors replaced table content without useful recovery. Fix: announced status and retry. Suggested command: impeccable harden.

Personas: Frequent staff scan too many actions per row; new staff must decode overlapping status names; keyboard users cannot reliably identify focus or current filter.

Deterministic evidence: Standard CLI detector found no findings; with project config disabled, it flagged approved Inter once. Authenticated admin UI redirected to auth.php, so live desktop/mobile inspection and overlay were unavailable. Findings here are source/CSS/JS verified.

Minor observations: Modal semantics and focus lifecycle vary across the many admin dialogs and deserve a later focused pass.

Questions to consider: Should Action Required become the default staff queue? Which rare actions should be available only from booking details?
