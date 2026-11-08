<?php
if (($_SESSION['logged_in'] ?? false) === true) {
    $sessionWatchUrl = site_metadata_asset_url('assets/js/session_watch.js');
    echo '<script src="' . htmlspecialchars($sessionWatchUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" defer></script>';
}
