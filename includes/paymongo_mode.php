<?php
require_once __DIR__.'/../config/env.php';
function paymongo_capstone_enabled(): bool {
    return ($_ENV['PAYMONGO_CAPSTONE_MODE'] ?? getenv('PAYMONGO_CAPSTONE_MODE')) === 'test';
}
if (!defined('SEVILLA_PM_FULL_APP') && paymongo_capstone_enabled()) define('SEVILLA_PM_FULL_APP', true);
