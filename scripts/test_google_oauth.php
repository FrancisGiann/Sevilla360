<?php
require_once __DIR__ . '/../includes/google_oauth.php';

$config = ['client_id' => 'client-test', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.test/oauth/callback'];
$valid = [
    'iss' => 'https://accounts.google.com', 'aud' => 'client-test', 'exp' => 200,
    'iat' => 100, 'sub' => '12345678', 'email' => 'guest@example.test',
    'email_verified' => 'true', 'nonce' => 'nonce-test',
];
if (!google_oauth_claims_valid($valid, $config, 'nonce-test', 150)) exit("valid claim test failed\n");
$invalid = $valid; $invalid['nonce'] = 'wrong';
if (google_oauth_claims_valid($invalid, $config, 'nonce-test', 150)) exit("nonce test failed\n");
$invalid = $valid; $invalid['aud'] = 'other-client';
if (google_oauth_claims_valid($invalid, $config, 'nonce-test', 150)) exit("audience test failed\n");
$invalid = $valid; $invalid['email_verified'] = 'false';
if (google_oauth_claims_valid($invalid, $config, 'nonce-test', 150)) exit("email verification test failed\n");
echo "Google OAuth claim tests passed\n";
