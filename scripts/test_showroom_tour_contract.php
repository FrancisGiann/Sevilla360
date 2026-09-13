<?php
/** Focused contracts for panorama framing, stable tour targets and nav pins. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/showroom_tour.php';
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$checks = [];
$checks['admin authorization is role-scoped'] = showroom_tour_is_admin('admin') && !showroom_tour_is_admin('customer') && !showroom_tour_is_admin(null);
$checks['only 360 media can accept a saved view'] = showroom_tour_is_panorama('360') && !showroom_tour_is_panorama('standard') && !showroom_tour_is_panorama(null);
$hotel_fixture = [
    'hotel_standard_zeta' => ['room_type' => 'Standard', 'venue_name' => 'Zeta Building'],
    'hotel_suite' => ['room_type' => 'Suite', 'venue_name' => 'Suite Building'],
    'hotel_standard_alpha' => ['room_type' => 'Standard', 'venue_name' => 'Alpha Building'],
    'hotel_unspecified' => ['room_type' => '', 'venue_name' => 'Other Building'],
];
$hotel_groups = showroom_tour_group_hotel_venues($hotel_fixture);
$hotel_grouped_ids = [];
foreach ($hotel_groups as $room_venues) {
    foreach ($room_venues as $venue_id => $_venue) $hotel_grouped_ids[] = (string)$venue_id;
}
$hotel_expected_ids = array_map('strval', array_keys($hotel_fixture));
sort($hotel_grouped_ids);
sort($hotel_expected_ids);
$checks['hotel venue groups sort by room type and building while preserving each stable ID exactly once'] = array_keys($hotel_groups) === ['Other room types', 'Standard', 'Suite']
    && array_keys($hotel_groups['Standard']) === ['hotel_standard_alpha', 'hotel_standard_zeta']
    && $hotel_grouped_ids === $hotel_expected_ids
    && count($hotel_grouped_ids) === count(array_unique($hotel_grouped_ids));

$valid_view = showroom_tour_validate_view_request((object)[
    'media_id' => 7,
    'view' => (object)['x' => 1.25, 'y' => -2, 'z' => 497.5, 'fov' => 85]
]);
$checks['valid view coordinates and FOV are accepted'] = $valid_view['media_id'] === 7 && $valid_view['view'] === ['x' => 1.25, 'y' => -2.0, 'z' => 497.5, 'fov' => 85.0];
$checks['null view explicitly clears the default'] = showroom_tour_validate_view_request((object)['media_id' => 7, 'view' => null]) === ['media_id' => 7, 'view' => null];

$rejects = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};
$checks['invalid view media ID is rejected before saving'] = $rejects(static fn() => showroom_tour_validate_view_request((object)['media_id' => '7', 'view' => null]));
$checks['invalid FOV boundaries and empty vectors are rejected'] = $rejects(static fn() => showroom_tour_validate_view_request((object)['media_id' => 7, 'view' => (object)['x' => 0, 'y' => 0, 'z' => -1, 'fov' => 101]]))
    && $rejects(static fn() => showroom_tour_validate_view_request((object)['media_id' => 7, 'view' => (object)['x' => 0, 'y' => 0, 'z' => 0, 'fov' => 85]]));
$checks['non-finite and out-of-range vectors are rejected'] = $rejects(static fn() => showroom_tour_validate_view_request((object)['media_id' => 7, 'view' => (object)['x' => INF, 'y' => 0, 'z' => -1, 'fov' => 85]]))
    && $rejects(static fn() => showroom_tour_validate_view_request((object)['media_id' => 7, 'view' => (object)['x' => 10001, 'y' => 0, 'z' => -1, 'fov' => 85]]));

$rotation_checks = true;
foreach ([0, 1, 359] as $rotation) {
    $rotation_checks = $rotation_checks && showroom_tour_validate_arrow_rotation($rotation) === $rotation;
}
$checks['arrow rotations 0, 1 and 359 are accepted'] = $rotation_checks;
$checks['arrow rotations reject negative, 360, fractional and string values'] = $rejects(static fn() => showroom_tour_validate_arrow_rotation(-1))
    && $rejects(static fn() => showroom_tour_validate_arrow_rotation(360))
    && $rejects(static fn() => showroom_tour_validate_arrow_rotation(1.5))
    && $rejects(static fn() => showroom_tour_validate_arrow_rotation('1'));

$checks['stable target IDs survive primary-first display reordering'] = showroom_tour_resolve_target_media_id(101, 303, 0, [101, 202, 303], [303, 101, 202]) === 303;
$checks['invalid stable targets do not fall back to a stale positional target'] = showroom_tour_resolve_target_media_id(101, 999, 2, [101, 202, 303], [303, 101, 202]) === null;
$checks['legacy positional targets resolve only when no stable ID exists'] = showroom_tour_resolve_target_media_id(101, null, '2', [101, 202, 303], [303, 101, 202]) === 303;
$checks['self-target and missing legacy targets are rejected'] = showroom_tour_resolve_target_media_id(101, 101, 1, [101, 202, 303], [101, 202, 303]) === null
    && showroom_tour_resolve_target_media_id(101, null, 9, [101, 202, 303], [101, 202, 303]) === null;

$view_endpoint = $read('actions/admin/save_panorama_view.php');
$hotspot_endpoint = $read('actions/admin/save_hotspot.php');
$admin_hotspots = $read('assets/js/admin-page/admin_hotspots.js');
$admin_cms = $read('assets/js/admin-page/admin_cms.js');
$admin_dashboard_php = $read('admin_dashboard.php');
$admin_cms_php = $read('includes/admin-page/admin_cms.php');
$view_compat = $read('assets/js/panorama-view-compat.js');
$public_php = $read('showroom.php');
$showroom_navigation_css = $read('assets/css/showroom.css');
$header_css = $read('assets/css/header.css');
$public_js = $read('assets/js/showroom.js');
$public_viewer_markup = $read('showroom.php');
$hotel_branch_start = strpos($public_php, '<?php if ($is_hotel_menu): ?>');
$hotel_flat_branch_start = $hotel_branch_start === false ? false : strpos($public_php, '<?php else: ?>', $hotel_branch_start);
$hotel_branch = $hotel_branch_start === false || $hotel_flat_branch_start === false ? '' : substr($public_php, $hotel_branch_start, $hotel_flat_branch_start - $hotel_branch_start);
$flat_branch_end = $hotel_flat_branch_start === false ? false : strpos($public_php, '<?php endif; ?>', $hotel_flat_branch_start);
$flat_branch = $hotel_flat_branch_start === false || $flat_branch_end === false ? '' : substr($public_php, $hotel_flat_branch_start, $flat_branch_end - $hotel_flat_branch_start);
$open_start = strpos($admin_hotspots, 'document.querySelectorAll(".btn-place-hotspots")');
$open_end = $open_start === false ? false : strpos($admin_hotspots, '// REFRESH WALK-TO TARGET DROPDOWN', $open_start);
$open_handler = $open_start === false || $open_end === false ? '' : substr($admin_hotspots, $open_start, $open_end - $open_start);
$switch_start = strpos($admin_hotspots, 'adminViewSelector?.addEventListener("change"');
$switch_end = $switch_start === false ? false : strpos($admin_hotspots, '// CLOSE MODAL', $switch_start);
$switch_handler = $switch_start === false || $switch_end === false ? '' : substr($admin_hotspots, $switch_start, $switch_end - $switch_start);
$retry_start = strpos($admin_hotspots, 'retryViewerButton?.addEventListener(\'click\'');
$retry_end = $retry_start === false ? false : strpos($admin_hotspots, '// INITIALIZE PANOLENS VIEWER', $retry_start);
$retry_handler = $retry_start === false || $retry_end === false ? '' : substr($admin_hotspots, $retry_start, $retry_end - $retry_start);
$init_start = strpos($admin_hotspots, 'function initViewer(');
$init_end = $init_start === false ? false : strpos($admin_hotspots, 'function syncPanoramaReadiness(', $init_start);
$init_viewer = $init_start === false || $init_end === false ? '' : substr($admin_hotspots, $init_start, $init_end - $init_start);

$checks['view endpoint requires POST, JSON, admin, CSRF and strict helper validation'] = str_contains($view_endpoint, "REQUEST_METHOD'] ?? '') !== 'POST'")
    && str_contains($view_endpoint, "Content-Type must be application/json")
    && str_contains($view_endpoint, 'showroom_tour_is_admin')
    && str_contains($view_endpoint, 'hash_equals')
    && str_contains($view_endpoint, 'showroom_tour_validate_view_request')
    && str_contains($view_endpoint, "showroom_tour_is_panorama((string)\$media['media_type'])")
    && str_contains($view_endpoint, 'UPDATE media_cms SET showroom_view_x = ?, showroom_view_y = ?, showroom_view_z = ?, showroom_fov = ? WHERE id = ?');
$checks['view endpoint returns precise 401, 403, 404, 415 and 422 responses'] = str_contains($view_endpoint, "'Administrator access is required.'" )
    && str_contains($view_endpoint, "'CSRF validation failed.'")
    && str_contains($view_endpoint, "'Panorama was not found.'")
    && str_contains($view_endpoint, '], 415)')
    && str_contains($view_endpoint, '], 422)');
$admin_gate_position = strpos($view_endpoint, 'if (!showroom_tour_is_admin(');
$csrf_gate_position = strpos($view_endpoint, 'hash_equals(');
$lookup_position = strpos($view_endpoint, "SELECT media_type FROM media_cms WHERE id = ? LIMIT 1");
$checks['authorized admin view saves pass CSRF before reaching the prepared media lookup'] = $admin_gate_position !== false
    && $csrf_gate_position !== false
    && $lookup_position !== false
    && $admin_gate_position < $csrf_gate_position
    && $csrf_gate_position < $lookup_position;
$non_panorama_position = strpos($view_endpoint, "if (!showroom_tour_is_panorama((string)\$media['media_type']))");
$update_position = strpos($view_endpoint, 'UPDATE media_cms SET showroom_view_x = ?, showroom_view_y = ?, showroom_view_z = ?, showroom_fov = ? WHERE id = ?');
$checks['authorized save for a non-panorama is rejected before any framing update'] = $non_panorama_position !== false
    && $update_position !== false
    && $non_panorama_position < $update_position
    && str_contains($view_endpoint, "'Saved views are only available for 360 panoramas.'" )
    && str_contains($view_endpoint, '], 422)');
$checks['hotspot endpoint validates and persists arrow rotation'] = str_contains($hotspot_endpoint, 'showroom_tour_validate_arrow_rotation')
    && str_contains($hotspot_endpoint, 'arrow_rotation = ?')
    && str_contains($hotspot_endpoint, 'arrow_rotation, target_pano_index');
$checks['admin exposes tour view and starting scene controls only in tour editor'] = str_contains($admin_cms_php, 'btn-place-hotspots')
    && str_contains($admin_hotspots, 'btn-make-starting-scene')
    && str_contains($admin_hotspots, 'btn-set-current-view')
    && str_contains($admin_hotspots, 'btn-preview-saved-view')
    && str_contains($admin_hotspots, 'btn-clear-panorama-view')
    && str_contains($admin_hotspots, 'getPanoramaViewCenter()')
    && str_contains($admin_hotspots, 'View unsaved')
    && str_contains($admin_cms, "currentManageType === '360'")
    && str_contains($admin_cms, 'Starting scene is set in Tour Setup');
$checks['tour editor reports broken targets and rebuilds saved sprites without duplicate fetches'] = str_contains($read('actions/admin/get_hotspots.php'), 'target_valid')
    && str_contains($admin_hotspots, 'Destination unavailable — edit to repair')
    && str_contains($admin_hotspots, 'clearSavedHotspotSpots();')
    && !str_contains($admin_hotspots, 'loadExistingHotspots(currentMediaId);\n\n            // Initialize viewer');
$checks['tour editor captures the center ray from the active panorama without undocumented Panolens getters'] = str_contains($admin_hotspots, 'function getPanoramaViewCenter()')
    && str_contains($admin_hotspots, 'raycaster.setFromCamera(new THREE.Vector2(0, 0), viewer.camera)')
    && str_contains($admin_hotspots, 'raycaster.intersectObject(currentPanoMesh, true).find(hit => !isHotspotObject(hit.object))')
    && !str_contains($admin_hotspots, 'getRaycastViewCenter');
$admin_apply_start = strpos($admin_hotspots, 'function applyView(');
$admin_apply_end = $admin_apply_start === false ? false : strpos($admin_hotspots, 'function getPanoramaViewCenter(', $admin_apply_start);
$admin_apply_view = $admin_apply_start === false || $admin_apply_end === false ? '' : substr($admin_hotspots, $admin_apply_start, $admin_apply_end - $admin_apply_start);
$public_apply_start = strpos($public_js, 'function applyPanoramaView(');
$public_apply_end = $public_apply_start === false ? false : strpos($public_js, 'function synchronizePanoramaState(', $public_apply_start);
$public_apply_view = $public_apply_start === false || $public_apply_end === false ? '' : substr($public_js, $public_apply_start, $public_apply_end - $public_apply_start);
$checks['saved views share the coordinate-space compatibility helper in admin and public paths'] = str_contains($admin_apply_view, 'window.PanoramaViewCompat?.applyControlCenter(viewer, center, duration)')
    && str_contains($admin_apply_view, 'viewer.setCameraFov(fov)')
    && str_contains($public_apply_view, 'window.PanoramaViewCompat?.applyControlCenter(viewer, center, reducedMotionQuery.matches ? 0 : duration)')
    && str_contains($public_apply_view, 'viewer.setCameraFov(fov)')
    && str_contains($admin_hotspots, 'applyView(savedView, reducedMotion ? 0 : 650)')
    && str_contains($public_js, 'applyPanoramaView(room, index, 650)')
    && str_contains($public_js, 'applyPanoramaView(room, currentPanoIndex, reducedMotionQuery.matches ? 0 : 650)');
$admin_compat_position = strpos($admin_dashboard_php, 'assets/js/panorama-view-compat.js');
$admin_hotspots_script_position = strpos($admin_dashboard_php, 'assets/js/admin-page/admin_hotspots.js');
$checks['shared view compatibility helper is loaded before both callers'] = str_contains($admin_dashboard_php, 'assets/js/panorama-view-compat.js')
    && $admin_compat_position !== false
    && $admin_hotspots_script_position !== false
    && $admin_compat_position < $admin_hotspots_script_position
    && str_contains($read('showroom.php'), 'assets/js/panorama-view-compat.js?v=')
    && str_contains($read('showroom.php'), 'assets/js/showroom.js?v=');
$checks['Panolens compatibility math negates only a cloned X for tween fallback'] = str_contains($view_compat, 'function toPanolensTweenCenter(worldCenter)')
    && str_contains($view_compat, 'const tweenCenter = worldCenter.clone();')
    && str_contains($view_compat, 'tweenCenter.x *= -1;')
    && str_contains($view_compat, 'viewer.setControlCenter(worldCenter);')
    && str_contains($view_compat, 'viewer.tweenControlCenter(tweenCenter, duration);');
$checks['tour editor handles cached or missed panorama load events from real viewer state'] = str_contains($admin_hotspots, 'function syncPanoramaReadiness(')
    && str_contains($admin_hotspots, 'viewer.panorama !== pano')
    && str_contains($admin_hotspots, 'pano.loaded !== true')
    && str_contains($admin_hotspots, '!pano.material?.map')
    && str_contains($admin_hotspots, 'pano.addEventListener("load", () => syncPanoramaReadiness(pano, requestToken))')
    && str_contains($admin_hotspots, 'syncPanoramaReadiness(pano, requestToken);')
    && !str_contains($admin_hotspots, 'viewer.setPanorama(pano)');
$checks['open, view switch and retry each initialize exactly one panorama viewer'] = $open_start !== false
    && $open_end !== false
    && substr_count($open_handler, 'initViewer(') === 1
    && $switch_start !== false
    && $switch_end !== false
    && substr_count($switch_handler, 'initViewer(') === 1
    && $retry_start !== false
    && $retry_end !== false
    && substr_count($retry_handler, 'initViewer(') === 1;
$checks['initViewer relies on temporary-spot cleanup instead of clearing the same spot twice'] = $init_start !== false
    && $init_end !== false
    && str_contains($init_viewer, 'removeTemporarySpot();')
    && !str_contains($init_viewer, 'pendingSpot = null;');
$checks['tour viewer cleanup cancels rendering and removes Panolens input listeners'] = str_contains($admin_hotspots, 'function destroyViewer(instance)')
    && str_contains($admin_hotspots, 'instance.destroy()')
    && str_contains($admin_hotspots, 'instance.unregisterMouseAndTouchEvents?.()')
    && str_contains($admin_hotspots, 'window.cancelAnimationFrame(instance.requestAnimationId)')
    && str_contains($admin_hotspots, 'destroyViewer(viewer);');
$checks['tour editor uses inline live feedback and has no native alert or confirm calls'] = str_contains($admin_cms_php, 'id="hs-editor-status" class="hotspot-editor-status" role="status" aria-live="polite"')
    && str_contains($admin_hotspots, 'function setEditorStatus(')
    && str_contains($admin_hotspots, 'window.showConfirm(title, message)')
    && preg_match('/\b(?:window\.)?(?:alert|confirm)\s*\(/', $admin_hotspots) !== 1
    && !str_contains($admin_hotspots, 'showAlert(');
$checks['tour editor busy labels and status feedback are wired to core actions'] = str_contains($admin_hotspots, "setTourBusy(true, setStartingSceneButton, 'Setting…')")
    && str_contains($admin_hotspots, "setTourBusy(true, view === null ? clearViewButton : saveViewButton")
    && str_contains($admin_hotspots, "setTourBusy(true, saveHotspotButton, 'Saving…')")
    && str_contains($admin_hotspots, "askCustomConfirm('Confirm Deletion', 'Delete this hotspot?')")
    && str_contains($admin_cms_php, 'id="btn-retry-hotspot-view"');
$busy_start = strpos($admin_hotspots, 'function setTourBusy(');
$busy_end = $busy_start === false ? false : strpos($admin_hotspots, 'function refreshViewOptions(', $busy_start);
$busy_function = $busy_start === false || $busy_end === false ? '' : substr($admin_hotspots, $busy_start, $busy_end - $busy_start);
$checks['single active tour operation restores its original button on every finally path'] = str_contains($admin_hotspots, 'let tourBusyButton = null;')
    && str_contains($busy_function, 'if (tourBusy) return false;')
    && str_contains($busy_function, 'tourBusyButton = activeButton;')
    && str_contains($busy_function, 'const completedButton = tourBusyButton;')
    && str_contains($busy_function, 'setButtonBusy(completedButton, false);')
    && substr_count($admin_hotspots, "finally {\n            setTourBusy(false);") >= 4;
$button_busy_start = strpos($admin_hotspots, 'function setButtonBusy(');
$button_busy_end = $button_busy_start === false ? false : strpos($admin_hotspots, 'function abortHotspotListRequest(', $button_busy_start);
$button_busy_function = $button_busy_start === false || $button_busy_end === false ? '' : substr($admin_hotspots, $button_busy_start, $button_busy_end - $button_busy_start);
$checks['icon-only delete action stays compact, accessible and uses a centered busy spinner'] = str_contains($admin_hotspots, "remove.innerHTML = '<i class=\"fa-solid fa-trash\" aria-hidden=\"true\"></i>'")
    && !str_contains($admin_hotspots, 'class="visually-hidden">Delete')
    && str_contains($admin_hotspots, "remove.setAttribute('aria-label', `Delete ")
    && str_contains($button_busy_function, 'fa-circle-notch fa-spin')
    && str_contains($button_busy_function, 'button.replaceChildren(spinner)')
    && str_contains($admin_hotspots, 'button.innerHTML = button.dataset.idleHtml;')
    && str_contains($read('assets/css/admin-page/admin_cms.css'), 'flex: 0 0 44px; width: 44px; height: 44px;')
    && str_contains($read('assets/css/admin-page/admin_cms.css'), '.hotspot-list-item .hs-info { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }');
$post_json_start = strpos($admin_hotspots, 'async function postJson(');
$post_json_end = $post_json_start === false ? false : strpos($admin_hotspots, 'function setTourBusy(', $post_json_start);
$post_json_function = $post_json_start === false || $post_json_end === false ? '' : substr($admin_hotspots, $post_json_start, $post_json_end - $post_json_start);
$list_request_start = strpos($admin_hotspots, 'async function loadExistingHotspots(');
$list_request_function = $list_request_start === false ? '' : substr($admin_hotspots, $list_request_start);
$close_start = strpos($admin_hotspots, 'async function closeHotspotModal(');
$close_end = $close_start === false ? false : strpos($admin_hotspots, "closeModalButton?.addEventListener", $close_start);
$close_function = $close_start === false || $close_end === false ? '' : substr($admin_hotspots, $close_start, $close_end - $close_start);
$checks['tour requests have bounded timeouts, preserve drafts on failure and abort stale lists'] = str_contains($post_json_function, 'new AbortController()')
    && str_contains($post_json_function, 'window.setTimeout(')
    && str_contains($post_json_function, 'signal: controller.signal')
    && str_contains($post_json_function, 'window.clearTimeout(timeoutId)')
    && str_contains($post_json_function, 'Your changes are still here; try again.')
    && str_contains($list_request_function, 'const controller = new AbortController();')
    && str_contains($list_request_function, 'signal: controller.signal')
    && str_contains($list_request_function, 'timedOut ?')
    && str_contains($list_request_function, 'window.clearTimeout(timeoutId)')
    && str_contains($admin_hotspots, 'function abortHotspotListRequest()')
    && str_contains($init_viewer, 'abortHotspotListRequest();')
    && str_contains($close_function, 'abortHotspotListRequest();')
    && str_contains($list_request_function, 'abortHotspotListRequest();');
$checks['public order is primary-first and media IDs remain the view-settings key'] = str_contains($public_php, 'ORDER BY is_primary DESC, id ASC')
    && str_contains($public_php, "'pano_urls'")
    && str_contains($public_php, "'pano_media_ids'")
    && str_contains($public_php, "[(string)(int)\$m['id']]");
$checks['public data resolves stable navigation and omits broken pins'] = str_contains($public_php, 'showroom_tour_resolve_target_media_id')
    && str_contains($public_php, 'if ($resolved_target_media_id === null)')
    && str_contains($public_php, "'arrow_rotation' => max(0, min(359, (int)\$h['arrow_rotation']))");
$checks['hotel dropdown uses labelled room-type groups with one direct stable-ID button template per venue'] = str_contains($public_php, "showroom_tour_group_hotel_venues(\$grouped_showroom['Hotel Room'])")
    && str_contains($public_php, 'hotel-room-groups-menu')
    && str_contains($public_php, 'foreach ($grouped_hotel_rooms as $room_type => $room_venues)')
    && str_contains($hotel_branch, '<div class="hotel-room-group" role="group" aria-labelledby=')
    && !str_contains($hotel_branch, '<section class="hotel-room-group"')
    && str_contains($hotel_branch, 'hotel-room-group-title')
    && substr_count($hotel_branch, 'class="dropdown-item') === 1
    && str_contains($hotel_branch, 'data-room="<?php echo htmlspecialchars((string)$id')
    && str_contains($hotel_branch, 'aria-current="true"')
    && str_contains($flat_branch, 'foreach($venues as $id => $data)')
    && !str_contains($flat_branch, 'hotel-room-groups');
$checks['hotel menu is viewport-bounded, internally scrollable, two-column on desktop and one-column when compact'] = str_contains($showroom_navigation_css, 'grid-template-columns: repeat(2, minmax(0, 1fr));')
    && str_contains($showroom_navigation_css, 'grid-template-columns: minmax(0, 1fr);')
    && str_contains($showroom_navigation_css, 'max-height: var(--hotel-menu-max-height')
    && str_contains($showroom_navigation_css, 'overflow: auto;')
    && str_contains($showroom_navigation_css, 'max-width: calc(100vw - 24px);')
    && str_contains($showroom_navigation_css, 'overflow-wrap: anywhere;')
    && str_contains($public_js, 'function positionHotelMenu(wrapper)')
    && str_contains($public_js, 'window.addEventListener(\'resize\', repositionOpenHotelMenu)');
$checks['hotel dropdown clears a visible fixed header and remains above content but below the header'] = str_contains($public_js, 'function getHotelMenuSafeTop(viewportHeight, padding)')
    && str_contains($public_js, "document.getElementById('siteHeader')")
    && str_contains($public_js, "typeof window.getComputedStyle !== 'function'")
    && str_contains($public_js, "headerStyle.position !== 'fixed' && headerStyle.position !== 'sticky'")
    && str_contains($public_js, 'headerBounds.bottom + padding')
    && str_contains($public_js, 'bounds.top - gap - safeTop')
    && str_contains($public_js, 'const belowTop = Math.max(safeTop, bounds.bottom + gap)')
    && str_contains($public_js, 'viewportHeight - safeTop - padding')
    && str_contains($showroom_navigation_css, 'z-index: 51;')
    && str_contains($header_css, 'z-index: 9999;');
$checks['showroom navigation keeps direct keyboard selection, expanded state and close behavior'] = str_contains($public_php, 'aria-controls="<?php echo htmlspecialchars($menu_id')
    && str_contains($public_php, 'aria-expanded="false"')
    && str_contains($public_php, 'type="button" class="dropdown-item')
    && str_contains($public_js, 'item.setAttribute(\'aria-current\', \'true\')')
    && str_contains($public_js, 'this.setAttribute(\'aria-expanded\', \'true\')')
    && str_contains($public_js, "event.key !== 'Escape'")
    && str_contains($public_js, "roomNavigation?.addEventListener('focusout'")
    && str_contains($public_js, 'roomNavigation.contains(event.relatedTarget)')
    && str_contains($public_js, 'window.addEventListener("click", closeAllMenus)')
    && str_contains($public_js, 'urlParams.get(\'cat\')')
    && str_contains($public_js, 'window.location.hash.replace(\'#\', \'\')');
$checks['guest destinations, saved camera view and reset control are wired'] = str_contains($public_viewer_markup, 'btn-showroom-destinations')
    && str_contains($public_viewer_markup, 'btn-reset-view')
    && str_contains($public_js, 'roomData?.showroom_views?.[String(mediaId)]')
    && str_contains($public_js, 'window.PanoramaViewCompat?.applyControlCenter(viewer, center, reducedMotionQuery.matches ? 0 : duration)')
    && str_contains($public_js, 'activeDestinations(roomData, viewIndex)')
    && str_contains($public_js, 'spot.material.rotation = rotation * Math.PI / 180');
$enter_reset_listener_position = strpos($public_js, "pano.addEventListener('enter-fade-start'");
$viewer_add_position = strpos($public_js, 'viewer.add(pano);');
$initial_cache_assignment = strpos($public_js, 'panoCache[roomId] = panoramas;');
$initial_activation_position = strpos($public_js, 'if (!canApplyImmediately) enterPanorama(initialPanorama);');
$checks['public framing waits until Panolens resets controls at entry and avoids duplicate tweening'] = str_contains($public_js, 'function synchronizePanoramaState(')
    && str_contains($public_js, 'transitionReady = false')
    && str_contains($public_js, 'if (!transitionReady) return true;')
    && str_contains($public_js, 'lastFramedActivationToken === activationToken')
    && str_contains($public_js, 'viewer.panorama !== pano')
    && str_contains($public_js, "pano.addEventListener('enter-fade-start'")
    && str_contains($public_js, 'synchronizePanoramaState(roomId, room, index, pano, panoramas, venueActivationToken, true);')
    && str_contains($public_js, 'const panoramaControlReady = new WeakSet();')
    && str_contains($public_js, 'panoramaControlReady.add(pano);')
    && str_contains($public_js, 'function enterPanorama(pano, viewerRef = viewer)')
    && str_contains($public_js, 'panoramaControlReady.delete(pano);')
    && str_contains($public_js, 'const canApplyImmediately = alreadyCurrent && panoramaControlReady.has(initialPanorama) && initialPanorama.loaded === true && Boolean(initialPanorama.material?.map);')
    && substr_count($public_js, 'const canApplyImmediately = alreadyCurrent && panoramaControlReady.has(initialPanorama) && initialPanorama.loaded === true && Boolean(initialPanorama.material?.map);') === 2
    && substr_count($public_js, 'if (!canApplyImmediately) enterPanorama(initialPanorama);') === 2
    && str_contains($public_js, 'enterPanorama(panoramasRef[targetIndex], viewerRef);')
    && str_contains($public_js, 'enterPanorama(activePanoramas[index]);')
    && $initial_cache_assignment !== false
    && $initial_activation_position !== false
    && $initial_cache_assignment < $initial_activation_position
    && $viewer_add_position !== false
    && $enter_reset_listener_position !== false
    && $viewer_add_position < $enter_reset_listener_position
    && str_contains($view_compat, 'Panolens 0.12.1')
    && !str_contains($public_apply_view, 'center.x *= -1')
    && str_contains($public_js, 'scheduleAutoRotation();');
$checks['admin reports per-panorama entry behavior and shows non-blocking success toasts'] = str_contains($admin_hotspots, "'View Set · Applies on entry'")
    && str_contains($admin_hotspots, "showTourToast(successMessage, viewDetail)")
    && str_contains($admin_hotspots, "showTourToast('Starting scene updated.')")
    && str_contains($admin_hotspots, "showTourToast(payload.id ? 'Hotspot updated.' : 'Hotspot saved.')")
    && str_contains($admin_hotspots, "showTourToast('Hotspot deleted.')")
    && str_contains($admin_cms_php, 'id="hotspot-tour-toast" class="hotspot-tour-toast" role="status" aria-live="polite" aria-atomic="true"')
    && str_contains($read('assets/css/admin-page/admin_cms.css'), '.hotspot-tour-toast.show')
    && str_contains($read('assets/css/admin-page/admin_cms.css'), '.hotspot-tour-toast { transition: none;');
$checks['auto-rotation is delayed, activity-paused and reduced-motion safe'] = str_contains($public_js, 'autoRotate: false')
    && str_contains($public_js, '}, 5000)')
    && str_contains($public_js, "prefers-reduced-motion: reduce")
    && str_contains($public_js, "addEventListener('wheel'")
    && !str_contains($public_js, 'hasAutoReloaded')
    && !str_contains($public_js, 'btn-reload-pano');
$arrow_asset_path = $root . '/assets/img/hotspot-arrow.png';
$arrow_asset_header = is_file($arrow_asset_path) ? file_get_contents($arrow_asset_path, false, null, 0, 26) : false;
$arrow_asset_mode = is_file($arrow_asset_path) ? fileperms($arrow_asset_path) : false;
$arrow_directory_mode = fileperms(dirname($arrow_asset_path));
$checks['premium walk marker uses a readable transparent raster with the existing info marker preserved'] = is_file($arrow_asset_path)
    && is_readable($arrow_asset_path)
    && is_string($arrow_asset_header)
    && strlen($arrow_asset_header) >= 26
    && substr($arrow_asset_header, 0, 8) === "\x89PNG\r\n\x1a\n"
    && ord($arrow_asset_header[25]) === 6
    && $arrow_asset_mode !== false
    && ($arrow_asset_mode & 0444) !== 0
    && $arrow_directory_mode !== false
    && ($arrow_directory_mode & 0111) !== 0
    && str_contains($admin_hotspots, 'assets/img/hotspot-arrow.png')
    && str_contains($public_js, 'assets/img/hotspot-arrow.png')
    && str_contains($admin_hotspots, 'assets/img/hotspot-info.png')
    && str_contains($public_js, 'assets/img/hotspot-info.png')
    && !str_contains($admin_hotspots, 'assets/img/hotspot-arrow.svg')
    && !str_contains($public_js, 'assets/img/hotspot-arrow.svg');

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . "|$label\n";
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
