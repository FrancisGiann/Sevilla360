<?php
declare(strict_types=1);
// Exercise the controller's actual delivery boundary with no database or transport.
$source = (string)file_get_contents(dirname(__DIR__) . '/actions/admin/update_booking_status.php');
$queueStart = strpos($source, '        // Invoice delivery must not escape');
$queueEnd = strpos($source, '        create_user_notification', $queueStart);
$boundaryStart = strpos($source, "    if (!\$conn->commit())");
$boundaryEnd = strpos($source, "    echo json_encode(['success' => true", $boundaryStart);
if ($queueStart === false || $queueEnd === false || $boundaryStart === false || $boundaryEnd === false) throw new RuntimeException('Invoice transaction fixture boundaries unavailable.');
$queue = substr($source, $queueStart, $queueEnd - $queueStart);
$delivery = substr($source, $boundaryStart, $boundaryEnd - $boundaryStart);
if (strpos($source, 'send_invoice_ready_email(') < $boundaryStart) throw new RuntimeException('Invoice delivery escaped before commit.');
function site_metadata_absolute_url($path) { return $GLOBALS['qa_invoice_base']; }
function send_invoice_ready_email(...$args) { $GLOBALS['qa_invoice_sends'][] = $args; if ($GLOBALS['qa_invoice_fail_send']) throw new RuntimeException('Fixture transport failure'); }
$booking_id = 55; $c_email = 'qa@example.test'; $c_name = 'QA Guest'; $ref_no = 'QA-INVOICE'; $new_total = 12000;
foreach ([[true, false, 'https://example.test/user_dashboard.php', 1], [false, false, 'https://example.test/user_dashboard.php', 0], [true, false, null, 0], [true, true, 'https://example.test/user_dashboard.php', 1]] as [$commit, $failSend, $base, $expected]) {
    $postCommitActions = [];
    $GLOBALS['qa_invoice_sends'] = []; $GLOBALS['qa_invoice_base'] = $base; $GLOBALS['qa_invoice_fail_send'] = $failSend;
    eval($queue);
    if (count($postCommitActions) !== 1 || $GLOBALS['qa_invoice_sends']) throw new RuntimeException('Queueing must not send.');
    $conn = new class($commit) { public bool $committed = false; public function __construct(private bool $succeed) {} public function commit() { $this->committed = $this->succeed; return $this->succeed; } };
    try { eval($delivery); if (!$commit) throw new RuntimeException('Failed commit was ignored.'); }
    catch (Exception $error) { if ($commit || $error->getMessage() !== 'Unable to commit the booking update.') throw $error; }
    if (count($GLOBALS['qa_invoice_sends']) !== $expected || $conn->committed !== $commit) throw new RuntimeException('Invoice commit/send boundary failed.');
    if ($expected && $GLOBALS['qa_invoice_sends'][0] !== [$c_email, $c_name, $ref_no, $new_total, $base]) throw new RuntimeException('Invoice recipient/content context changed.');
}
echo "Invoice post-commit scenarios passed (success, commit failure, invalid URL, transport failure).\n";
