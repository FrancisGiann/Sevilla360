<?php
require_once dirname(__DIR__) . '/includes/profile_fields.php';
$checks = 0;
function expect_profile_rejection(callable $action): void {
    global $checks;
    try { $action(); } catch (InvalidArgumentException $error) { $checks++; return; }
    throw new RuntimeException('Expected profile input rejection.');
}
expect_profile_rejection(fn() => profile_input_text(str_repeat('É',151), 'Full name',150));
expect_profile_rejection(fn() => profile_input_text([], 'Full name',150));
expect_profile_rejection(fn() => profile_input_text("Bad\nName", 'Full name',150));
expect_profile_rejection(fn() => profile_input_text('', 'First name',100));
expect_profile_rejection(fn() => profile_input_phone(str_repeat('1',21)));
expect_profile_rejection(fn() => profile_input_phone('not-a-phone'));
foreach ([str_repeat('É',150), 'Dela Cruz-Santos', "O’Connor"] as $name) {
    if (profile_input_text($name, 'Full name',150) !== $name) throw new RuntimeException('Valid name changed.');
    $checks++;
}
if (profile_input_phone('') !== '' || profile_input_phone('+63 (912) 345-6789') !== '+63 (912) 345-6789') throw new RuntimeException('Valid contact changed.');
$checks++;
echo "$checks profile-field checks: PASS\n";
