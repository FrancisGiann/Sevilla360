/**
 * ==========================================================================
 * SEVILLA360 - Admin Hotspots Controller
 * ==========================================================================
 */
document.addEventListener("DOMContentLoaded", () => {
    window.SevillaHotspotMaterial?.preload?.((error, url) => setTimeout(() => console.warn('Admin hotspot asset unavailable', url, error), 0));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const hotspotModal = document.getElementById("hotspotModal");
    if (!hotspotModal) return;

    // ============================================================
    // STATE
    // ============================================================
    let viewer = null;
    let currentPanoMesh = null;
    let currentMediaId = null;
    let currentSlot = null;
    let currentPhotosArray = []; // Track all photos for this room
    let pendingPoint = null;
    let pendingSpot = null;
    let raycastEnabled = false;
    let hotspotListRequestToken = 0;
    let viewerRequestToken = 0;
    let savedHotspotSpots = [];
    let savedHotspots = [];
    let savedView = null;
    let viewDraft = null;
    let viewDirty = false;
    let hotspotDirty = false;
    let previousFocus = null;
    let activeViewIndex = 0;
    let savedHotspotCount = 0;
    let tourBusy = false;
    let tourBusyButton = null;
    let discardPromptOpen = false;
    let hotspotListAbortController = null;
    let tourToastTimeout = null;
    const HOTSPOT_REQUEST_TIMEOUT_MS = 15000;

    // ============================================================
    // DOM ELEMENTS
    // ============================================================
    const panoContainer = document.getElementById("hotspot-pano-container");
    const loadingEl = document.getElementById("hotspot-loading");
    const formWrapper = document.getElementById("hotspot-form-wrapper");
    const hotspotSidebar = hotspotModal.querySelector('.hotspot-sidebar');
    const hotspotListSection = hotspotModal.querySelector('.hotspot-list-section');
    const listEl = document.getElementById("hotspot-list");
    const typeSelect = document.getElementById("hs-type");
    const descWrapper = document.getElementById("hs-desc-wrapper");
    const targetWrapper = document.getElementById("hs-target-wrapper");
    const targetSelect = document.getElementById("hs-target-index");
    const formHeading = document.getElementById('hs-form-heading');
    const formState = document.getElementById('hs-form-state');
    const saveLabel = document.getElementById('hs-save-label');
    const adminViewSelector = document.getElementById("hs-admin-view-selector");
    const viewDescription = document.getElementById("hs-view-description");
    const startingSceneStatus = document.getElementById("hs-starting-scene-status");
    const viewPresetStatus = document.getElementById("hs-view-preset-status");
    const hotspotCountStatus = document.getElementById("hs-hotspot-count");
    const saveViewButton = document.getElementById("btn-save-panorama-view");
    const previewViewButton = document.getElementById("btn-preview-saved-view");
    const clearViewButton = document.getElementById("btn-clear-panorama-view");
    const setStartingSceneButton = document.getElementById("btn-make-starting-scene");
    const setCurrentViewButton = document.getElementById("btn-set-current-view");
    const closeModalButton = document.getElementById("btnCloseHotspotModal");
    const loadingMessage = document.getElementById("hotspot-loading-message");
    const editorStatus = document.getElementById('hs-editor-status');
    const tourToast = document.getElementById('hotspot-tour-toast');
    const retryViewerButton = document.getElementById("btn-retry-hotspot-view");
    const rotationWrapper = document.getElementById("hs-arrow-rotation-wrapper");
    const rotationRange = document.getElementById("hs-arrow-rotation-range");
    const rotationInput = document.getElementById("hs-arrow-rotation");
    const saveHotspotButton = document.getElementById("btn-save-hotspot");
    const editorTour = window.SevillaGuideTour?.init({
        helpButton: document.getElementById('btn-hotspot-help'),
        key: `sevilla360-admin-tour-v1-${Number(window.sevillaAdminUserId) || 0}`,
        steps: [
            { title: 'Choose a panorama', copy: 'Select the 360 view you want to publish from the panorama chooser.', target: '#hs-admin-view-selector' },
            { title: 'Set the guest framing', copy: 'Drag and zoom the preview, then save the current guest view. Make a starting scene only when this should open first.', target: '#btn-save-panorama-view' },
            { title: 'Place and publish hotspots', copy: 'Click the panorama, choose information or walk, set a destination when needed, then save the pin.', target: '#hotspot-pano-container' }
        ],
        ready: () => hotspotModal.classList.contains('active') && Boolean(currentPanoMesh)
    });

    // ============================================================
    // CUSTOM HOTSPOT ICONS
    // ============================================================
    const HOTSPOT_INFO_ICON = window.SevillaHotspotMaterial?.assets?.info || "assets/img/hotspot-info-v3.png";
    const HOTSPOT_ARROW_ICON = window.SevillaHotspotMaterial?.assets?.nav || "assets/img/hotspot-nav-v3.png";

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[char]));
    }

    function setFormMode(editing) {
        if (formHeading) formHeading.textContent = editing ? 'Edit Hotspot' : 'New Hotspot';
        if (formState) formState.textContent = editing ? 'Editing saved pin' : 'New pin';
        if (saveLabel) saveLabel.textContent = editing ? 'Update Pin' : 'Save Pin';
        if (saveHotspotButton) saveHotspotButton.title = editing ? 'Update hotspot pin' : 'Save hotspot pin';
    }

    function setHotspotFormVisible(visible, editing = false) {
        if (!formWrapper || !hotspotSidebar) return;
        formWrapper.classList.toggle('hidden', !visible);
        hotspotSidebar.classList.toggle('is-form-active', visible);
        hotspotSidebar.classList.toggle('is-editing', visible && editing);
        if (hotspotListSection) hotspotListSection.setAttribute('aria-hidden', visible ? 'true' : 'false');
        if (visible) formWrapper.scrollTop = 0;
    }

    function currentPhoto() {
        return currentPhotosArray[activeViewIndex] || null;
    }

    function photoLabel(photo, index = 0) {
        if (!photo) return `View ${index + 1}`;
        const fileLabel = String(photo.alt_text || photo.file_name || photo.file_path || '').split('/').pop().trim();
        return fileLabel || `View ${index + 1}`;
    }

    function photoSavedView(photo) {
        if (!photo) return null;
        const keys = ['showroom_view_x', 'showroom_view_y', 'showroom_view_z', 'showroom_fov'];
        const values = keys.map(key => Number(photo[key]));
        if (keys.some(key => photo[key] === null || photo[key] === undefined || photo[key] === '') || values.some(value => !Number.isFinite(value))) return null;
        if (Math.max(Math.abs(values[0]), Math.abs(values[1]), Math.abs(values[2])) > 10000 || values[3] < 30 || values[3] > 100) return null;
        return { x: values[0], y: values[1], z: values[2], fov: values[3] };
    }

    function hasUnsavedChanges() {
        return viewDirty || hotspotDirty || (pendingPoint !== null && !formWrapper.classList.contains('hidden'));
    }

    function setEditorStatus(message, state = 'info') {
        if (!editorStatus) return;
        editorStatus.textContent = String(message || '');
        editorStatus.dataset.state = ['loading', 'success', 'error'].includes(state) ? state : 'info';
    }

    function showTourToast(message, detail = '') {
        if (!tourToast) return;
        const messageEl = tourToast.querySelector('[data-tour-toast-message]');
        const detailEl = tourToast.querySelector('[data-tour-toast-detail]');
        if (!messageEl) return;
        window.clearTimeout(tourToastTimeout);
        messageEl.textContent = String(message || '');
        if (detailEl) {
            detailEl.textContent = String(detail || '');
            detailEl.hidden = !detail;
        }
        tourToast.classList.add('show');
        tourToastTimeout = window.setTimeout(() => tourToast.classList.remove('show'), 3600);
    }

    function hasCustomConfirm() {
        return typeof window.showConfirm === 'function' &&
            Boolean(document.getElementById('globalConfirmModal')) &&
            Boolean(document.getElementById('globalModalOverlay'));
    }

    async function askCustomConfirm(title, message) {
        if (!hasCustomConfirm()) {
            setEditorStatus('The confirmation dialog is unavailable. No changes were made.', 'error');
            return false;
        }
        return window.showConfirm(title, message);
    }

    async function confirmDiscardChanges(action = 'leave this tour setup') {
        if (!hasUnsavedChanges()) return true;
        if (discardPromptOpen) return false;
        discardPromptOpen = true;
        try {
            return await askCustomConfirm('Discard unsaved changes?', `Discard the unsaved hotspot or view changes and ${action}?`);
        } finally {
            discardPromptOpen = false;
        }
    }

    function updateTourStatus(message = '') {
        const photo = currentPhoto();
        if (!photo) return;
        const viewIsSet = savedView !== null;
        const isStartingScene = Number(photo.is_primary) === 1;
        if (startingSceneStatus) {
            startingSceneStatus.textContent = isStartingScene ? 'Starting scene' : 'Not starting scene';
            startingSceneStatus.classList.toggle('is-current', isStartingScene);
        }
        if (viewPresetStatus) {
            viewPresetStatus.textContent = viewDirty ? 'View unsaved' : viewIsSet ? 'View set' : 'View not set';
            viewPresetStatus.classList.toggle('is-unsaved', viewDirty);
            viewPresetStatus.classList.toggle('is-set', viewIsSet && !viewDirty);
            viewPresetStatus.classList.toggle('is-invalid', false);
        }
        if (hotspotCountStatus) hotspotCountStatus.textContent = `${savedHotspotCount} ${savedHotspotCount === 1 ? 'hotspot' : 'hotspots'}`;
        if (viewDescription) {
            const viewStatus = viewDirty
                ? 'View Unsaved'
                : viewIsSet
                    ? (isStartingScene ? 'View Set · Starting Scene' : 'View Set · Applies on entry')
                    : 'View Not Set';
            const parts = [photoLabel(photo, activeViewIndex), isStartingScene ? 'Starting Scene' : 'Not Starting Scene', viewStatus, `${savedHotspotCount} ${savedHotspotCount === 1 ? 'hotspot' : 'hotspots'}`];
            viewDescription.textContent = message ? `${message} · ${parts.join(' · ')}` : parts.join(' · ');
        }
        if (setStartingSceneButton) setStartingSceneButton.disabled = tourBusy || isStartingScene;
        // Keep capture available while loading so it can opportunistically recognize a cached
        // texture even if Panolens' one-shot load event was missed.
        if (setCurrentViewButton) setCurrentViewButton.disabled = tourBusy || !viewer;
        if (adminViewSelector) adminViewSelector.disabled = tourBusy;
        if (closeModalButton) closeModalButton.disabled = tourBusy;
        if (saveViewButton) saveViewButton.disabled = tourBusy || !viewer;
        if (previewViewButton) previewViewButton.disabled = tourBusy || !savedView || !viewer;
        if (clearViewButton) clearViewButton.disabled = tourBusy || (!savedView && !viewDirty);
        formWrapper?.querySelectorAll('button, input, select, textarea').forEach(control => {
            control.disabled = tourBusy || (control === targetSelect && targetSelect.options.length === 0);
        });
        listEl?.querySelectorAll('button').forEach(button => { button.disabled = tourBusy; });
    }

    function setLoading(visible, message = 'Loading panorama…', isError = false) {
        if (loadingEl) loadingEl.style.display = visible ? 'flex' : 'none';
        if (loadingMessage) loadingMessage.textContent = message;
        if (retryViewerButton) retryViewerButton.hidden = !isError;
        loadingEl?.classList.toggle('is-error', isError);
        if (visible) setEditorStatus(message, isError ? 'error' : 'loading');
    }

    function setButtonBusy(button, busy, busyText = 'Working…') {
        if (!button) return;
        const label = button.querySelector('[data-hotspot-button-label]');
        if (busy) {
            if (button.hasAttribute('aria-busy')) return;
            button.dataset.idleHtml = button.innerHTML;
            if (label) {
                label.textContent = busyText;
            } else {
                const spinner = document.createElement('i');
                spinner.className = 'fa-solid fa-circle-notch fa-spin';
                spinner.setAttribute('aria-hidden', 'true');
                button.replaceChildren(spinner);
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        } else {
            if (button.dataset.idleHtml !== undefined) {
                button.innerHTML = button.dataset.idleHtml;
                delete button.dataset.idleHtml;
            }
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    }

    function abortHotspotListRequest() {
        hotspotListRequestToken++;
        if (hotspotListAbortController) {
            hotspotListAbortController.abort();
            hotspotListAbortController = null;
        }
    }

    function selectedArrowRotation() {
        const value = Number(rotationInput?.value ?? 0);
        return Number.isInteger(value) && value >= 0 && value <= 359 ? value : null;
    }

    function applyArrowRotation(spot, degrees) {
        if (!spot?.material || !Number.isInteger(degrees) || degrees < 0 || degrees > 359) return;
        spot.userData = spot.userData || {};
        spot.userData.hotspotRotationDegrees = degrees;
        spot.material.rotation = degrees * Math.PI / 180;
        spot.material.needsUpdate = true;
    }

    function disposeHotspotSpot(spot) {
        if (!spot) return;
        try {
            currentPanoMesh?.remove(spot);
            if (window.SevillaHotspotMaterial?.dispose) {
                window.SevillaHotspotMaterial.dispose(spot);
                return;
            }
            spot.userData = spot.userData || {};
            if (spot.userData.hotspotDisposed) return;
            spot.userData.hotspotDisposed = true;
            spot.userData.hotspotTextureRequest = (Number(spot.userData.hotspotTextureRequest) || 0) + 1;
            spot.visible = false;
            if (spot.material) {
                spot.material.visible = false;
                spot.material.map?.dispose?.();
                spot.material.dispose?.();
            }
        } catch (error) { /* the panorama or texture may already be disposed */ }
    }

    function clearSavedHotspotSpots() {
        savedHotspotSpots.forEach(disposeHotspotSpot);
        savedHotspotSpots = [];
    }

    function takeSavedHotspotSpot(id) {
        const index = savedHotspotSpots.findIndex(spot => String(spot.userData?.hotspotId) === String(id));
        if (index < 0) return null;
        const [spot] = savedHotspotSpots.splice(index, 1);
        return spot;
    }

    function applyView(view, duration = 650) {
        if (!viewer || !view || ![view.x, view.y, view.z, view.fov].every(value => Number.isFinite(Number(value)))) return false;
        const fov = Number(view.fov);
        if (fov < 30 || fov > 100) return false;
        const center = new THREE.Vector3(Number(view.x), Number(view.y), Number(view.z));
        if (!window.PanoramaViewCompat?.applyControlCenter(viewer, center, duration)) return false;
        if (typeof viewer.setCameraFov === 'function') viewer.setCameraFov(fov);
        else if (viewer.camera) {
            viewer.camera.fov = fov;
            viewer.camera.updateProjectionMatrix();
        } else return false;
        return true;
    }

    function getPanoramaViewCenter() {
        if (!viewer || !currentPanoMesh || !viewer.camera || !window.THREE?.Raycaster) return null;
        try {
            viewer.camera.updateMatrixWorld(true);
            currentPanoMesh.updateMatrixWorld(true);
            const raycaster = new THREE.Raycaster();
            raycaster.setFromCamera(new THREE.Vector2(0, 0), viewer.camera);
            const isHotspotObject = object => {
                let node = object;
                while (node && node !== currentPanoMesh) {
                    if (node === pendingSpot || savedHotspotSpots.includes(node)) return true;
                    node = node.parent;
                }
                return false;
            };
            const intersection = raycaster.intersectObject(currentPanoMesh, true).find(hit => !isHotspotObject(hit.object));
            const point = intersection?.point;
            if (!point || ![point.x, point.y, point.z].every(Number.isFinite)) return null;
            const magnitude = Math.hypot(point.x, point.y, point.z);
            if (magnitude < 0.1 || magnitude > 10000) return null;
            return point.clone ? point.clone() : new THREE.Vector3(point.x, point.y, point.z);
        } catch (error) {
            return null;
        }
    }

    // ============================================================
    // CREATE CUSTOM INFOSPOT
    // ============================================================
    function createHotspotSpot(type, position, rotation = 0) {
        const onIconError = () => setEditorStatus('Hotspot icon failed to load. The visible fallback marker remains available; retry after checking the asset path.', 'error');
        if (window.SevillaHotspotMaterial?.create) {
            return window.SevillaHotspotMaterial.create(type, position, rotation, onIconError);
        }
        const icon = type === "nav" ? HOTSPOT_ARROW_ICON : HOTSPOT_INFO_ICON;
        const spot = new PANOLENS.Infospot(350, icon);
        if (position) spot.position.copy(position);
        spot.material && (spot.material.transparent = true, spot.material.alphaTest = 0.08, spot.material.depthWrite = false, spot.material.depthTest = false, spot.material.size = 350, spot.material.needsUpdate = true);
        if (type === 'nav') applyArrowRotation(spot, Number.isInteger(rotation) ? rotation : 0);
        return spot;
    }

    // ============================================================
    // CHANGE TEMPORARY PIN ICON
    // ============================================================
    function updateTemporarySpotIcon() {
        if (!pendingSpot) return;

        const selectedType = typeSelect?.value || "info";
        const targetSpot = pendingSpot;
        const rotation = selectedArrowRotation() ?? 0;
        const onIconError = () => setEditorStatus('Hotspot icon failed to load. The visible fallback marker remains available; retry after checking the asset path.', 'error');
        if (window.SevillaHotspotMaterial?.refresh) {
            window.SevillaHotspotMaterial.refresh(targetSpot, selectedType, rotation, onIconError);
        } else if (targetSpot.material) {
            targetSpot.material.transparent = true;
            targetSpot.material.alphaTest = 0.08;
            targetSpot.material.depthWrite = false;
            targetSpot.material.depthTest = false;
            targetSpot.material.size = 350;
            targetSpot.material.needsUpdate = true;
        }
    }

    // ============================================================
    // REMOVE TEMPORARY PIN
    // ============================================================
    function removeTemporarySpot() {
        disposeHotspotSpot(pendingSpot);
        pendingSpot = null;
    }

    function discardCurrentHotspotDraft(restoreSavedPins = true) {
        setHotspotFormVisible(false);
        removeTemporarySpot();
        pendingPoint = null;
        hotspotDirty = false;
        if (rotationInput) rotationInput.value = '0';
        if (rotationRange) rotationRange.value = '0';
        if (restoreSavedPins && currentPanoMesh && currentMediaId !== null) loadExistingHotspots(currentMediaId);
    }

    // ============================================================
    // PLACE / OPEN HOTSPOT MODAL
    // ============================================================
    document.querySelectorAll(".btn-place-hotspots").forEach((btn) => {
        btn.addEventListener("click", () => {
            currentSlot = btn.getAttribute("data-slot");
            currentPhotosArray = (window.panoDataOrdered && window.panoDataOrdered[currentSlot]) || [];

            if (currentPhotosArray.length === 0) {
                return;
            }

            previousFocus = document.activeElement;
            activeViewIndex = 0;
            currentMediaId = Number(currentPhotosArray[0].id);
            savedView = photoSavedView(currentPhotosArray[0]);
            viewDraft = null;
            viewDirty = false;
            hotspotDirty = false;
            savedHotspotCount = 0;
            savedHotspots = [];
            tourBusy = false;
            setEditorStatus('Loading panorama preview…', 'loading');
            document.getElementById("hotspot-modal-title").textContent = "Tour Setup & Hotspots — " + currentSlot.replace(/^venue_/, "").replace(/_360$/, "").replace(/_/g, " ");

            if (adminViewSelector) {
                adminViewSelector.replaceChildren();
                currentPhotosArray.forEach((p, idx) => {
                    const opt = document.createElement("option");
                    opt.value = idx;
                    const scenePrefix = Number(p.is_primary) === 1 ? 'Starting Scene · ' : '';
                    opt.textContent = `View ${idx + 1} · ${scenePrefix}${photoLabel(p, idx)}`;
                    adminViewSelector.appendChild(opt);
                });
                adminViewSelector.value = '0';
            }

            refreshTargetDropdown(activeViewIndex);
            setHotspotFormVisible(false);
            setFormMode(false);
            pendingPoint = null;
            raycastEnabled = false;
            listEl.innerHTML = '<p class="hotspot-empty-state">Loading saved hotspots…</p>';
            listEl.setAttribute('aria-busy', 'true');
            updateTourStatus();
            hotspotModal.classList.add("active");
            closeModalButton?.focus();
            setEditorStatus('Loading panorama preview…', 'loading');
            setLoading(true);
            initViewer(currentPhotosArray[0].file_path);
        });
    });

    // ============================================================
    // REFRESH WALK-TO TARGET DROPDOWN (excludes the currently editing view)
    // ============================================================
    function refreshTargetDropdown(currentViewIndex) {
        targetSelect.replaceChildren();
        currentPhotosArray.forEach((p, idx) => {
            if (idx === currentViewIndex) return; // Skip the current view
            const opt = document.createElement("option");
            opt.value = Number(p.id);
            opt.textContent = `View ${idx + 1} · ${photoLabel(p, idx)}`;
            targetSelect.appendChild(opt);
        });
        targetSelect.disabled = targetSelect.options.length === 0;
    }

    // ============================================================
    // ADMIN VIEW SWITCHER LOGIC
    // ============================================================
    adminViewSelector?.addEventListener("change", async (e) => {
        const selectedIndex = parseInt(e.target.value, 10);
        const selectedPhoto = currentPhotosArray[selectedIndex];

        if (!selectedPhoto) return;
        if (!await confirmDiscardChanges('switch to another panorama')) {
            adminViewSelector.value = String(activeViewIndex);
            return;
        }

        discardCurrentHotspotDraft(false);
        activeViewIndex = selectedIndex;
        raycastEnabled = false;
        currentMediaId = Number(selectedPhoto.id);
        savedView = photoSavedView(selectedPhoto);
        viewDraft = null;
        viewDirty = false;
        hotspotDirty = false;
        savedHotspotCount = 0;
        listEl.innerHTML = '<p class="hotspot-empty-state">Loading saved hotspots…</p>';
        listEl.setAttribute('aria-busy', 'true');
        refreshTargetDropdown(selectedIndex);
        updateTourStatus();
        setEditorStatus(`Loading ${photoLabel(selectedPhoto, selectedIndex)}…`, 'loading');
        setLoading(true);
        initViewer(selectedPhoto.file_path);
    });

    // ============================================================
    // CLOSE MODAL
    // ============================================================
    function destroyViewer(instance) {
        if (!instance) return;
        try { instance.disableAutoRate?.(); } catch (error) { /* continue cleanup */ }
        try { instance.unregisterMouseAndTouchEvents?.(); } catch (error) { /* continue cleanup */ }
        let destroyed = false;
        if (typeof instance.destroy === 'function') {
            try {
                instance.destroy();
                destroyed = true;
            } catch (error) { /* cancel the render loop manually below */ }
        }
        if (!destroyed) {
            if (Number.isFinite(instance.requestAnimationId)) window.cancelAnimationFrame(instance.requestAnimationId);
            try { instance.dispose?.(); } catch (error) { /* keep remaining control cleanup */ }
        }
        (Array.isArray(instance.controls) ? instance.controls : [instance.control])
            .filter(Boolean)
            .forEach(control => {
                try { control.dispose?.(); } catch (error) { /* controls may already be disposed */ }
            });
    }

    async function closeHotspotModal() {
        if (tourBusy) {
            setEditorStatus('Please wait for the current save to finish before closing the tour editor.', 'loading');
            return;
        }
        if (!await confirmDiscardChanges('close this editor')) return;
        hotspotModal.classList.remove("active");
        setHotspotFormVisible(false);
        removeTemporarySpot();
        clearSavedHotspotSpots();
        pendingPoint = null;
        raycastEnabled = false;
        viewerRequestToken++;
        abortHotspotListRequest();
        destroyViewer(viewer);
        viewer = null;
        panoContainer.onclick = null;
        panoContainer.replaceChildren();
        currentPanoMesh = null;
        currentMediaId = null;
        savedView = null;
        viewDraft = null;
        viewDirty = false;
        hotspotDirty = false;
        savedHotspots = [];
        savedHotspotCount = 0;
        listEl.setAttribute('aria-busy', 'false');
        tourBusy = false;
        if (previousFocus?.isConnected) previousFocus.focus();
        previousFocus = null;
    }

    closeModalButton?.addEventListener("click", () => { void closeHotspotModal(); });
    hotspotModal.addEventListener('click', event => {
        if (event.target === hotspotModal) void closeHotspotModal();
    });
    hotspotModal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            void closeHotspotModal();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(hotspotModal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter(element => !element.hidden && !element.closest('.hidden'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    async function postJson(url, body) {
        const controller = new AbortController();
        let timedOut = false;
        const timeoutId = window.setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, HOTSPOT_REQUEST_TIMEOUT_MS);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(body),
                signal: controller.signal
            });
            let data;
            try {
                data = await response.json();
            } catch (error) {
                if (timedOut) throw new Error('The request timed out. Your changes are still here; try again.');
                throw new Error('The server returned an invalid response. Please try again.');
            }
            if (!response.ok || !data || data.success !== true) {
                throw new Error(typeof data?.message === 'string' ? data.message : `Request failed (HTTP ${response.status}).`);
            }
            return data;
        } catch (error) {
            if (timedOut) throw new Error('The request timed out. Your changes are still here; try again.');
            if (error?.name === 'AbortError') throw new Error('The request was cancelled. Your changes are still here; try again.');
            throw error;
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    function setTourBusy(busy, activeButton = null, busyText = 'Working…') {
        if (busy) {
            if (tourBusy) return false;
            tourBusy = true;
            tourBusyButton = activeButton;
            if (tourBusyButton) setButtonBusy(tourBusyButton, true, busyText);
        } else {
            const completedButton = tourBusyButton;
            tourBusyButton = null;
            tourBusy = false;
            if (completedButton) setButtonBusy(completedButton, false);
        }
        updateTourStatus();
        return true;
    }

    function refreshViewOptions() {
        if (!adminViewSelector) return;
        adminViewSelector.replaceChildren();
        currentPhotosArray.forEach((photo, index) => {
            const option = document.createElement('option');
            option.value = index;
            const scenePrefix = Number(photo.is_primary) === 1 ? 'Starting Scene · ' : '';
            option.textContent = `View ${index + 1} · ${scenePrefix}${photoLabel(photo, index)}`;
            adminViewSelector.appendChild(option);
        });
        adminViewSelector.value = String(activeViewIndex);
    }

    setStartingSceneButton?.addEventListener('click', async () => {
        const photo = currentPhoto();
        if (!photo || Number(photo.is_primary) === 1 || !setTourBusy(true, setStartingSceneButton, 'Setting…')) return;
        try {
            const result = await postJson('actions/admin/set_primary_media.php', {
                id: Number(photo.id),
                slot_assignment: currentSlot
            });
            const primaryId = Number(result.primary_id);
            currentPhotosArray.forEach(item => { item.is_primary = Number(item.id) === primaryId ? 1 : 0; });
            refreshViewOptions();
            updateTourStatus('Starting scene updated');
            setEditorStatus('Starting scene updated.', 'success');
            showTourToast('Starting scene updated.');
        } catch (error) {
            setEditorStatus(error.message || 'Unable to update starting scene. Please try again.', 'error');
        } finally {
            setTourBusy(false);
        }
    });

    function captureCurrentView() {
        if (!viewer || !syncPanoramaReadiness()) {
            const failed = loadingEl?.classList.contains('is-error');
            setEditorStatus(failed ? 'Panorama failed to load. Retry the preview before setting a view.' : 'Panorama is still loading. Try again when the preview is ready.', failed ? 'error' : 'loading');
            return null;
        }
        const center = getPanoramaViewCenter();
        if (!center) {
            setEditorStatus('The preview center could not be read. Move the panorama and try again.', 'error');
            return null;
        }
        const view = {
            x: Number(center?.x),
            y: Number(center?.y),
            z: Number(center?.z),
            fov: Number(viewer.camera?.fov)
        };
        const magnitude = Math.hypot(view.x, view.y, view.z);
        if (![view.x, view.y, view.z, view.fov, magnitude].every(Number.isFinite) || magnitude < 0.1 || magnitude > 10000 || view.fov < 30 || view.fov > 100) {
            setEditorStatus('View could not be captured. Move the panorama to a valid direction and try again.', 'error');
            return null;
        }
        viewDraft = view;
        viewDirty = true;
        return view;
    }

    async function savePanoramaView(view) {
        const photo = currentPhoto();
        if (!photo || !setTourBusy(true, view === null ? clearViewButton : saveViewButton, view === null ? 'Clearing…' : 'Saving…')) return;
        try {
            const result = await postJson('actions/admin/save_panorama_view.php', {
                media_id: Number(photo.id),
                view
            });
            if (result.view) {
                photo.showroom_view_x = result.view.x;
                photo.showroom_view_y = result.view.y;
                photo.showroom_view_z = result.view.z;
                photo.showroom_fov = result.view.fov;
                savedView = { ...result.view };
            } else {
                photo.showroom_view_x = null;
                photo.showroom_view_y = null;
                photo.showroom_view_z = null;
                photo.showroom_fov = null;
                savedView = null;
            }
            viewDraft = null;
            viewDirty = false;
            const successMessage = result.message || (view === null ? 'Default view cleared.' : 'Default view saved.');
            const viewDetail = view === null
                ? ''
                : Number(photo.is_primary) === 1
                    ? 'This framing will be used when guests enter the starting scene.'
                    : 'This panorama is not the starting scene; its framing applies when guests enter it.';
            updateTourStatus(successMessage);
            setEditorStatus(viewDetail ? `${successMessage} ${viewDetail}` : successMessage, 'success');
            showTourToast(successMessage, viewDetail);
        } catch (error) {
            setEditorStatus(error.message || 'Unable to save default view. Please try again.', 'error');
        } finally {
            setTourBusy(false);
        }
    }

    saveViewButton?.addEventListener('click', () => {
        if (tourBusy) return;
        const capturedView = captureCurrentView();
        if (capturedView) savePanoramaView(capturedView);
    });

    previewViewButton?.addEventListener('click', () => {
        if (!savedView) return;
        if (!viewer || !syncPanoramaReadiness(undefined, undefined, false)) {
            setEditorStatus('Wait for the panorama preview to finish loading before previewing its saved view.', 'loading');
            return;
        }
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (applyView(savedView, reducedMotion ? 0 : 650)) setEditorStatus('Saved view previewed.', 'success');
        else setEditorStatus('The saved view could not be previewed.', 'error');
    });

    clearViewButton?.addEventListener('click', () => {
        if (savedView || viewDirty) savePanoramaView(null);
    });

    retryViewerButton?.addEventListener('click', () => {
        const photo = currentPhoto();
        if (photo) {
            setEditorStatus(`Retrying ${photoLabel(photo, activeViewIndex)}…`, 'loading');
            initViewer(photo.file_path);
        }
    });

    // ============================================================
    // INITIALIZE PANOLENS VIEWER
    // ============================================================
    function initViewer(imageUrl) {
        abortHotspotListRequest();
        const requestToken = ++viewerRequestToken;
        if (viewer) {
            removeTemporarySpot();
            clearSavedHotspotSpots();
            destroyViewer(viewer);
            viewer = null;
            panoContainer.replaceChildren();
        }

        currentPanoMesh = null;
        savedHotspotSpots = [];
        pendingPoint = null;
        raycastEnabled = false;
        setLoading(true);

        viewer = new PANOLENS.Viewer({
            container: panoContainer,
            controlBar: false,
            autoRotate: false,
            autoHideInfospot: false
        });

        const pano = new PANOLENS.ImagePanorama(imageUrl);

        pano.addEventListener("load", () => syncPanoramaReadiness(pano, requestToken));
        pano.addEventListener('error', () => {
            if (requestToken !== viewerRequestToken || !hotspotModal.classList.contains('active')) return;
            currentPanoMesh = null;
            raycastEnabled = false;
            listEl.replaceChildren();
            const failure = document.createElement('p');
            failure.className = 'hotspot-list-error';
            failure.textContent = 'Saved hotspots are unavailable until this panorama loads.';
            listEl.appendChild(failure);
            listEl.setAttribute('aria-busy', 'false');
            setLoading(true, 'This panorama could not be loaded. Retry it or choose another view.', true);
        });

        // Panolens.Viewer.add() selects the first panorama itself. Calling setPanorama()
        // again is a no-op and can hide initialization-order mistakes.
        viewer.add(pano);
        panoContainer.onclick = handlePanoClick;
        syncPanoramaReadiness(pano, requestToken);
    }

    function syncPanoramaReadiness(pano = viewer?.panorama, requestToken = viewerRequestToken, applySavedFraming = true) {
        if (requestToken !== viewerRequestToken || !hotspotModal.classList.contains('active') ||
            !viewer || !pano || viewer.panorama !== pano || pano.loaded !== true || !pano.material?.map || !viewer.camera) {
            return false;
        }

        if (currentPanoMesh !== pano || !raycastEnabled) {
            currentPanoMesh = pano;
            raycastEnabled = true;
            setLoading(false);
            setEditorStatus('Panorama ready. Click the preview to place a hotspot.', 'success');
            editorTour?.maybeAutoShow();
            savedView = photoSavedView(currentPhoto());
            if (savedView && applySavedFraming) applyView(savedView, 0);
            updateTourStatus();
            void loadExistingHotspots(currentMediaId);
        }
        return true;
    }

    // ============================================================
    // CLICK PANORAMA
    // ============================================================
    function handlePanoClick(event) {
        if (!raycastEnabled || !viewer || !currentPanoMesh) return;

        const rect = panoContainer.getBoundingClientRect();
        const mouse = new THREE.Vector2();
        
        mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        const raycaster = new THREE.Raycaster();
        raycaster.setFromCamera(mouse, viewer.camera);
        const intersects = raycaster.intersectObject(currentPanoMesh, true);

        if (intersects.length === 0) return;

        pendingPoint = intersects[0].point.clone();
        
        // Adjust coordinate orientation for 3D panorama placement
        pendingPoint.x = -pendingPoint.x;

        const selectedType = typeSelect?.value || "info";

        if (pendingSpot) {
            pendingSpot.position.copy(pendingPoint);
            updateTemporarySpotIcon();
        } else {
            pendingSpot = createHotspotSpot(selectedType, pendingPoint);
            currentPanoMesh.add(pendingSpot);
        }

        const wasHidden = formWrapper.classList.contains("hidden");

        if (wasHidden) {
            setFormMode(false);
            document.getElementById("hs-title").value = "";
            document.getElementById("hs-description").value = "";
            if (rotationInput) rotationInput.value = '0';
            if (rotationRange) rotationRange.value = '0';
        }
        setHotspotFormVisible(true, false);

        hotspotDirty = true;
        toggleTypeFields();
        updateTourStatus('Hotspot changes are not saved');
    }

    // ============================================================
    // DROPDOWN CHANGE
    // ============================================================
    typeSelect?.addEventListener("change", () => {
        toggleTypeFields();
        updateTemporarySpotIcon();
        if (!formWrapper.classList.contains('hidden')) hotspotDirty = true;
    });

    function toggleTypeFields() {
        const isNav = typeSelect.value === "nav";
        descWrapper.classList.toggle("hidden", isNav);
        targetWrapper.classList.toggle("hidden", !isNav);
        rotationWrapper?.classList.toggle('hidden', !isNav);
    }

    // ============================================================
    // CANCEL HOTSPOT
    // ============================================================
    document.getElementById("btn-cancel-hotspot")?.addEventListener("click", () => {
        discardCurrentHotspotDraft(true);
        setFormMode(false);
        updateTourStatus();
    });

    document.querySelectorAll('#hotspot-form-wrapper input, #hotspot-form-wrapper textarea, #hotspot-form-wrapper select')
        .forEach(field => field.addEventListener('input', () => {
            if (formWrapper.classList.contains('hidden')) return;
            hotspotDirty = true;
            if (field === rotationRange) {
                if (rotationInput) rotationInput.value = rotationRange.value;
                applyArrowRotation(pendingSpot, Number(rotationRange.value));
            } else if (field === rotationInput) {
                const rotation = selectedArrowRotation();
                if (rotation !== null) {
                    if (rotationRange) rotationRange.value = String(rotation);
                    applyArrowRotation(pendingSpot, rotation);
                }
            }
            updateTourStatus('Hotspot changes are not saved');
        }));

    rotationInput?.addEventListener('blur', () => {
        const value = Number(rotationInput.value);
        if (!Number.isInteger(value) || value < 0 || value > 359) {
            rotationInput.value = String(Math.max(0, Math.min(359, Number.isFinite(value) ? Math.round(value) : 0)));
            if (rotationRange) rotationRange.value = rotationInput.value;
            applyArrowRotation(pendingSpot, Number(rotationInput.value));
        }
    });

    document.getElementById('btn-reset-arrow-rotation')?.addEventListener('click', () => {
        if (rotationInput) rotationInput.value = '0';
        if (rotationRange) rotationRange.value = '0';
        applyArrowRotation(pendingSpot, 0);
        hotspotDirty = true;
        updateTourStatus('Hotspot changes are not saved');
    });

    // ============================================================
    // SAVE HOTSPOT
    // ============================================================
    saveHotspotButton?.addEventListener("click", async () => {
        if (saveHotspotButton.disabled || tourBusy) return;
        const title = document.getElementById("hs-title").value.trim();
        if (!title) {
            setEditorStatus('Enter a guest label before saving this hotspot.', 'error');
            document.getElementById('hs-title').focus();
            return;
        }
        if (!pendingPoint) {
            setEditorStatus('No position captured. Click the panorama preview to place the pin.', 'error');
            return;
        }

        const selectedType = typeSelect.value;
        const arrowRotation = selectedType === 'nav' ? selectedArrowRotation() : 0;
        if (selectedType === 'nav' && arrowRotation === null) {
            setEditorStatus('Arrow direction must be a whole number from 0 to 359 degrees.', 'error');
            rotationInput?.focus();
            return;
        }
        const targetMediaId = selectedType === 'nav' ? Number(targetSelect.value) : null;
        if (selectedType === 'nav' && (!Number.isSafeInteger(targetMediaId) || targetMediaId < 1 || targetSelect.disabled)) {
            setEditorStatus('Add another panorama in this venue before creating a destination pin.', 'error');
            return;
        }

        const payload = {
            media_id: currentMediaId,
            type: selectedType,
            title,
            description: document.getElementById("hs-description").value.trim(),
            x: pendingPoint.x,
            y: pendingPoint.y,
            z: pendingPoint.z,
            arrow_rotation: arrowRotation,
            id: pendingSpot?.userData?.hotspotId || null,
            target_media_id: targetMediaId,
            target_pano_index: null
        };

        if (!setTourBusy(true, saveHotspotButton, 'Saving…')) return;
        setEditorStatus('Saving hotspot…', 'loading');
        try {
            await postJson('actions/admin/save_hotspot.php', payload);
            setHotspotFormVisible(false);
            setFormMode(false);
            pendingPoint = null;
            hotspotDirty = false;
            removeTemporarySpot();
            await loadExistingHotspots(currentMediaId);
            updateTourStatus('Hotspot saved');
            setEditorStatus('Hotspot saved.', 'success');
            showTourToast(payload.id ? 'Hotspot updated.' : 'Hotspot saved.');
        } catch (error) {
            setEditorStatus(error.message || 'Unable to save hotspot. Please try again.', 'error');
        } finally {
            setTourBusy(false);
        }
    });

    listEl.addEventListener('click', async event => {
        const retryButton = event.target.closest('[data-retry-hotspots]');
        if (retryButton) {
            if (tourBusy) return;
            retryButton.disabled = true;
            setEditorStatus('Loading saved hotspots…', 'loading');
            await loadExistingHotspots(currentMediaId);
            return;
        }

        const editButton = event.target.closest('.btn-edit-hotspot');
        if (editButton) {
            const hotspot = savedHotspots.find(item => String(item.id) === String(editButton.dataset.id));
            if (!hotspot || !currentPanoMesh) return;
            if (hasUnsavedChanges()) {
                if (!await confirmDiscardChanges('edit a saved hotspot')) return;
                const wasEditingSavedSpot = Boolean(pendingSpot?.userData?.hotspotId);
                discardCurrentHotspotDraft(false);
                if (wasEditingSavedSpot) await loadExistingHotspots(currentMediaId);
            }

            document.getElementById('hs-title').value = hotspot.title || '';
            document.getElementById('hs-description').value = hotspot.description || '';
            typeSelect.value = hotspot.type === 'nav' ? 'nav' : 'info';
            const rotation = Number(hotspot.arrow_rotation);
            if (rotationInput) rotationInput.value = String(Number.isInteger(rotation) && rotation >= 0 && rotation <= 359 ? rotation : 0);
            if (rotationRange) rotationRange.value = rotationInput?.value || '0';
            toggleTypeFields();
            if (hotspot.type === 'nav') {
                const targetId = Number(hotspot.resolved_target_media_id || hotspot.target_media_id || 0);
                targetSelect.value = targetId > 0 ? String(targetId) : '';
            }
            pendingPoint = new THREE.Vector3(Number(hotspot.position_x), Number(hotspot.position_y), Number(hotspot.position_z));
            removeTemporarySpot();
            const savedSpot = takeSavedHotspotSpot(hotspot.id);
            pendingSpot = savedSpot || createHotspotSpot(typeSelect.value, pendingPoint, Number(rotationInput?.value || 0));
            pendingSpot.userData.hotspotId = hotspot.id;
            pendingSpot.position.copy(pendingPoint);
            if (pendingSpot.parent !== currentPanoMesh) currentPanoMesh.add(pendingSpot);
            updateTemporarySpotIcon();
            setFormMode(true);
            setHotspotFormVisible(true, true);
            hotspotDirty = true;
            updateTourStatus('Editing an unsaved hotspot');
            document.getElementById('hs-title').focus();
            return;
        }

        const deleteButton = event.target.closest('.btn-delete-hotspot');
        if (!deleteButton) return;
        if (tourBusy) return;
        const hotspotId = deleteButton.dataset.id;
        const confirmed = await askCustomConfirm('Confirm Deletion', 'Delete this hotspot?');
        if (!confirmed) return;
        if (!setTourBusy(true, deleteButton, 'Deleting…')) return;
        setEditorStatus('Deleting hotspot…', 'loading');
        try {
            await postJson('actions/admin/delete_hotspot.php', { id: hotspotId });
            if (String(pendingSpot?.userData?.hotspotId || '') === String(hotspotId)) discardCurrentHotspotDraft(false);
            await loadExistingHotspots(currentMediaId);
            updateTourStatus('Hotspot deleted');
            setEditorStatus('Hotspot deleted.', 'success');
            showTourToast('Hotspot deleted.');
        } catch (error) {
            setEditorStatus(error.message || 'Unable to delete hotspot. Please try again.', 'error');
        } finally {
            setTourBusy(false);
        }
    });

    // ============================================================
    // LOAD EXISTING HOTSPOTS
    // ============================================================
    async function loadExistingHotspots(mediaId) {
        abortHotspotListRequest();
        if (!mediaId) return;
        const requestToken = ++hotspotListRequestToken;
        const controller = new AbortController();
        let timedOut = false;
        hotspotListAbortController = controller;
        const timeoutId = window.setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, HOTSPOT_REQUEST_TIMEOUT_MS);
        listEl.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(`actions/admin/get_hotspots.php?media_id=${encodeURIComponent(mediaId)}`, { signal: controller.signal });
            const data = await response.json();
            if (!response.ok || !data?.success || !Array.isArray(data.hotspots)) {
                throw new Error(typeof data?.message === 'string' ? data.message : 'Saved hotspots could not be loaded.');
            }
            if (requestToken !== hotspotListRequestToken || String(mediaId) !== String(currentMediaId)) return;

            clearSavedHotspotSpots();
            savedHotspots = data.hotspots;
            savedHotspotCount = data.hotspots.length;
            listEl.replaceChildren();
            if (data.hotspots.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'hotspot-empty-state';
                empty.textContent = 'No hotspots on this panorama yet. Click the preview to place one.';
                listEl.appendChild(empty);
            }

            data.hotspots.forEach(hotspot => {
                const item = document.createElement('div');
                item.className = 'hotspot-list-item';
                item.dataset.id = String(hotspot.id);

                const info = document.createElement('div');
                info.className = 'hs-info';
                const badge = document.createElement('span');
                badge.className = `hs-type-badge ${hotspot.type === 'nav' ? 'nav' : 'info'}`;
                badge.textContent = hotspot.type === 'nav' ? 'Navigation' : 'Information';
                const title = document.createElement('strong');
                title.textContent = String(hotspot.title || 'Untitled hotspot');
                info.append(badge, title);
                if (hotspot.type === 'nav' && Number(hotspot.target_valid) !== 1) {
                    const warning = document.createElement('span');
                    warning.className = 'hotspot-destination-warning';
                    warning.textContent = 'Destination unavailable — edit to repair';
                    info.appendChild(warning);
                } else if (hotspot.type === 'nav' && hotspot.target_file_name) {
                    const destination = document.createElement('span');
                    destination.className = 'hotspot-list-destination';
                    destination.textContent = `To: ${String(hotspot.target_file_name)}`;
                    info.appendChild(destination);
                }

                const actions = document.createElement('div');
                actions.className = 'hotspot-list-actions';
                const edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'hotspot-list-action btn-edit-hotspot';
                edit.dataset.id = String(hotspot.id);
                edit.title = 'Edit or move hotspot';
                edit.setAttribute('aria-label', `Edit ${String(hotspot.title || 'hotspot')}`);
                edit.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'hotspot-list-action btn-delete-hotspot';
                remove.dataset.id = String(hotspot.id);
                remove.title = 'Delete hotspot';
                remove.setAttribute('aria-label', `Delete ${String(hotspot.title || 'hotspot')}`);
                remove.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
                actions.append(edit, remove);
                item.append(info, actions);
                listEl.appendChild(item);

                if (currentPanoMesh) {
                    try {
                        const spot = createHotspotSpot(hotspot.type, {
                            x: Number(hotspot.position_x),
                            y: Number(hotspot.position_y),
                            z: Number(hotspot.position_z)
                        }, Number(hotspot.arrow_rotation));
                        spot.userData.hotspotId = hotspot.id;
                        spot.addHoverText(escapeHtml(hotspot.title));
                        currentPanoMesh.add(spot);
                        savedHotspotSpots.push(spot);
                    } catch (error) {
                        console.error('Failed to render a hotspot in 3D:', error);
                    }
                }
            });
            listEl.setAttribute('aria-busy', 'false');
            updateTourStatus();
            if (!editorStatus?.textContent) setEditorStatus('Saved hotspots loaded.', 'success');
        } catch (error) {
            if (requestToken !== hotspotListRequestToken || String(mediaId) !== String(currentMediaId)) return;
            listEl.replaceChildren();
            const failure = document.createElement('p');
            failure.className = 'hotspot-list-error';
            failure.textContent = timedOut ? 'Loading saved hotspots timed out. Your current edits are unchanged; try again.' : error.message || 'Saved hotspots could not be loaded.';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'hotspot-btn hotspot-btn-secondary';
            retry.dataset.retryHotspots = 'true';
            retry.textContent = 'Retry loading hotspots';
            listEl.append(failure, retry);
            listEl.setAttribute('aria-busy', 'false');
            setEditorStatus(timedOut ? 'Loading saved hotspots timed out. Please retry.' : error.message || 'Saved hotspots could not be loaded.', 'error');
        } finally {
            window.clearTimeout(timeoutId);
            if (hotspotListAbortController === controller) hotspotListAbortController = null;
        }
    }
});
