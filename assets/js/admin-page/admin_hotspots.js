/**
 * ==========================================================================
 * SEVILLA360 - Admin Hotspots Controller
 * ==========================================================================
 */
document.addEventListener("DOMContentLoaded", () => {
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

    // ============================================================
    // DOM ELEMENTS
    // ============================================================
    const panoContainer = document.getElementById("hotspot-pano-container");
    const loadingEl = document.getElementById("hotspot-loading");
    const formWrapper = document.getElementById("hotspot-form-wrapper");
    const listEl = document.getElementById("hotspot-list");
    const typeSelect = document.getElementById("hs-type");
    const descWrapper = document.getElementById("hs-desc-wrapper");
    const targetWrapper = document.getElementById("hs-target-wrapper");
    const targetSelect = document.getElementById("hs-target-index");
    const formHeading = document.getElementById('hs-form-heading');
    const saveLabel = document.getElementById('hs-save-label');
    
    // Admin view switcher dropdown
    const adminViewSelector = document.getElementById("hs-admin-view-selector");

    // ============================================================
    // CUSTOM HOTSPOT ICONS
    // ============================================================
    const HOTSPOT_INFO_ICON = "assets/img/hotspot-info.png";
    const HOTSPOT_ARROW_ICON = "assets/img/hotspot-arrow.png";

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[char]));
    }

    function setFormMode(editing) {
        if (formHeading) formHeading.textContent = editing ? 'Edit Hotspot' : 'New Hotspot';
        if (saveLabel) saveLabel.textContent = editing ? 'Update Pin' : 'Save Pin';
        const saveButton = document.getElementById('btn-save-hotspot');
        if (saveButton) saveButton.title = editing ? 'Update hotspot pin' : 'Save hotspot pin';
    }

    // ============================================================
    // CREATE CUSTOM INFOSPOT
    // ============================================================
    function createHotspotSpot(type, position) {
        const icon = type === "nav" ? HOTSPOT_ARROW_ICON : HOTSPOT_INFO_ICON;
        const spot = new PANOLENS.Infospot(350, icon);

        if (position) {
            spot.position.copy(position);
        }

        if (spot.material) {
            spot.material.transparent = true;
            spot.material.alphaTest = 0.5;
            spot.material.depthWrite = false;
            spot.material.needsUpdate = true;
        }

        if (spot.material && spot.material.map) {
            spot.material.map.needsUpdate = true;
        }

        return spot;
    }

    // ============================================================
    // CHANGE TEMPORARY PIN ICON
    // ============================================================
    function updateTemporarySpotIcon() {
        if (!pendingSpot) return;

        const selectedType = typeSelect?.value || "info";
        const icon = selectedType === "nav" ? HOTSPOT_ARROW_ICON : HOTSPOT_INFO_ICON;
        const loader = new THREE.TextureLoader();

        loader.load(icon, (texture) => {
            texture.needsUpdate = true;

            if (!pendingSpot.material) return;

            if (pendingSpot.material.map) {
                pendingSpot.material.map.dispose();
            }

            pendingSpot.material.map = texture;
            pendingSpot.material.transparent = true;
            pendingSpot.material.alphaTest = 0.5;
            pendingSpot.material.depthWrite = false;
            pendingSpot.material.needsUpdate = true;
        });
    }

    // ============================================================
    // REMOVE TEMPORARY PIN
    // ============================================================
    function removeTemporarySpot() {
        if (pendingSpot && currentPanoMesh) {
            currentPanoMesh.remove(pendingSpot);
            if (pendingSpot.material) {
                if (pendingSpot.material.map) pendingSpot.material.map.dispose();
                pendingSpot.material.dispose();
            }
        }
        pendingSpot = null;
    }

    // ============================================================
    // PLACE / OPEN HOTSPOT MODAL
    // ============================================================
    document.querySelectorAll(".btn-place-hotspots").forEach((btn) => {
        btn.addEventListener("click", () => {
            currentSlot = btn.getAttribute("data-slot");
            currentPhotosArray = (window.panoDataOrdered && window.panoDataOrdered[currentSlot]) || [];

            if (currentPhotosArray.length === 0) {
                showAlert("Notice", "No panorama found for this slot.");
                return;
            }

            // Sync the ID perfectly to the first photo
            currentMediaId = currentPhotosArray[0].id;

            document.getElementById("hotspot-modal-title").innerText =
                "Place Hotspots — " + currentSlot.replace(/^venue_/, "").replace(/_360$/, "").replace(/_/g, " ");

            // Populate Walk-To target dropdown (excluding the current view, set to 0 by default)
            refreshTargetDropdown(0);

            // Populate the admin view switcher
            if (adminViewSelector) {
                adminViewSelector.innerHTML = "";
                currentPhotosArray.forEach((p, idx) => {
                    const opt = document.createElement("option");
                    opt.value = idx;
                    opt.innerText = `Editing: View ${idx + 1}`;
                    adminViewSelector.appendChild(opt);
                });
                
                // Only show the dropdown if there is more than 1 image
                const wrapper = document.getElementById("hs-admin-view-switcher-wrapper");
                if (wrapper) {
                    wrapper.style.display = currentPhotosArray.length > 1 ? "block" : "none";
                }
                adminViewSelector.style.display = "block"; // Keep the select itself visible within the wrapper
                adminViewSelector.value = 0; // Reset to first view
            }

            // Open modal
            hotspotModal.classList.add("active");
            formWrapper.classList.add("hidden");
            setFormMode(false);
            loadingEl.style.display = "flex";

            // Clear any old temporary pin
            pendingPoint = null;
            pendingSpot = null;
            raycastEnabled = false;

            // Load the existing hotspots list immediately (independent of 3D viewer)
            loadExistingHotspots(currentMediaId);

            // Initialize viewer
            initViewer(currentPhotosArray[0].file_path);
        });
    });

    // ============================================================
    // REFRESH WALK-TO TARGET DROPDOWN (excludes the currently editing view)
    // ============================================================
    function refreshTargetDropdown(currentViewIndex) {
        targetSelect.innerHTML = "";
        currentPhotosArray.forEach((p, idx) => {
            if (idx === currentViewIndex) return; // Skip the current view
            const opt = document.createElement("option");
            opt.value = p.id;
            opt.innerText = `View ${idx + 1}`;
            targetSelect.appendChild(opt);
        });
    }

    // ============================================================
    // ADMIN VIEW SWITCHER LOGIC
    // ============================================================
    adminViewSelector?.addEventListener("change", (e) => {
        const selectedIndex = parseInt(e.target.value, 10);
        const selectedPhoto = currentPhotosArray[selectedIndex];

        if (!selectedPhoto) return;

        // Clean up unsaved pins
        formWrapper.classList.add("hidden");
        removeTemporarySpot();
        pendingPoint = null;
        raycastEnabled = false;

        // Sync the ID perfectly to the newly selected photo
        currentMediaId = selectedPhoto.id;

        // Immediately reload the list for the newly selected view
        loadExistingHotspots(currentMediaId);

        // Rebuild Walk-To dropdown excluding the newly active view
        refreshTargetDropdown(selectedIndex);

        // Show loading screen and initialize the new viewer
        loadingEl.style.display = "flex";
        initViewer(selectedPhoto.file_path);
    });

    // ============================================================
    // CLOSE MODAL
    // ============================================================
    document.getElementById("btnCloseHotspotModal")?.addEventListener("click", () => {
        hotspotModal.classList.remove("active");
        formWrapper.classList.add("hidden");
        
        removeTemporarySpot();
        pendingPoint = null;
        raycastEnabled = false;

        if (viewer) {
            viewer.dispose();
            viewer = null;
        }
        currentPanoMesh = null;
    });

    // ============================================================
    // INITIALIZE PANOLENS VIEWER
    // ============================================================
    function initViewer(imageUrl) {
        const requestToken = ++viewerRequestToken;
        if (viewer) {
            viewer.dispose();
            viewer = null;
            panoContainer.innerHTML = "";
        }

        currentPanoMesh = null;
        pendingPoint = null;
        pendingSpot = null;
        raycastEnabled = false;

        viewer = new PANOLENS.Viewer({
            container: panoContainer,
            controlBar: false,
            autoRotate: false
        });

        const pano = new PANOLENS.ImagePanorama(imageUrl);

        pano.addEventListener("load", () => {
            if (requestToken !== viewerRequestToken || !hotspotModal.classList.contains('active')) return;
            loadingEl.style.display = "none";
            currentPanoMesh = pano;
            raycastEnabled = true;

            // Load saved hotspots for this specific view
            loadExistingHotspots(currentMediaId);
        });
        pano.addEventListener('error', () => {
            if (requestToken !== viewerRequestToken) return;
            loadingEl.style.display = 'none';
            raycastEnabled = false;
            showAlert('Notice', 'This panorama could not be loaded. Choose another view or try again.');
        });

        viewer.add(pano);
        viewer.setPanorama(pano);

        panoContainer.onclick = handlePanoClick;
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
        formWrapper.classList.remove("hidden");

        if (wasHidden) {
            setFormMode(false);
            document.getElementById("hs-title").value = "";
            document.getElementById("hs-description").value = "";
        }

        toggleTypeFields();
    }

    // ============================================================
    // DROPDOWN CHANGE
    // ============================================================
    typeSelect?.addEventListener("change", () => {
        toggleTypeFields();
        updateTemporarySpotIcon();
    });

    function toggleTypeFields() {
        const isNav = typeSelect.value === "nav";
        descWrapper.classList.toggle("hidden", isNav);
        targetWrapper.classList.toggle("hidden", !isNav);
    }

    // ============================================================
    // CANCEL HOTSPOT
    // ============================================================
    document.getElementById("btn-cancel-hotspot")?.addEventListener("click", () => {
        formWrapper.classList.add("hidden");
        setFormMode(false);
        pendingPoint = null;
        removeTemporarySpot();
    });

    // ============================================================
    // SAVE HOTSPOT
    // ============================================================
    document.getElementById("btn-save-hotspot")?.addEventListener("click", () => {
        const title = document.getElementById("hs-title").value.trim();

        if (!title) {
            showAlert("Notice", "Please enter a title/label for this hotspot.");
            return;
        }

        if (!pendingPoint) {
            showAlert("Notice", "No position captured — click on the panorama first.");
            return;
        }

        const selectedType = typeSelect.value;

        const payload = {
            media_id: currentMediaId,
            type: selectedType,
            title: title,
            description: document.getElementById("hs-description").value.trim(),
            x: pendingPoint.x,
            y: pendingPoint.y,
            z: pendingPoint.z,
            id: pendingSpot?.userData?.hotspotId || null,
            target_media_id: selectedType === "nav" ? parseInt(targetSelect.value, 10) : null,
            target_pano_index: null
        };

        fetch("actions/admin/save_hotspot.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrfToken
            },
            body: JSON.stringify(payload)
        })
        .then((res) => res.json())
        .then((data) => {
            if (data.success) {
                formWrapper.classList.add("hidden");
                setFormMode(false);
                pendingPoint = null;
                removeTemporarySpot();
                loadExistingHotspots(currentMediaId);
            } else {
                showAlert("Notice", "Error: " + data.message);
            }
        })
        .catch(() => {
            showAlert("Notice", "Network error saving hotspot.");
        });
    });

    // ============================================================
    // LOAD EXISTING HOTSPOTS
    // ============================================================
    function loadExistingHotspots(mediaId) {
        const requestToken = ++hotspotListRequestToken;
        fetch(`actions/admin/get_hotspots.php?media_id=${mediaId}`)
        .then((res) => res.json())
        .then((data) => {
            if (requestToken !== hotspotListRequestToken || String(mediaId) !== String(currentMediaId)) return;
            listEl.innerHTML = "";

            if (!data.success || data.hotspots.length === 0) {
                listEl.innerHTML = '<p style="font-size:0.85rem; color:#888;">No hotspots placed yet.</p>';
                return;
            }

            data.hotspots.forEach((h) => {
                listEl.insertAdjacentHTML(
                    "beforeend",
                    `
                    <div class="hotspot-list-item" data-id="${escapeHtml(h.id)}">
                        <div class="hs-info">
                            <span class="hs-type-badge ${h.type === 'nav' ? 'nav' : 'info'}">
                                ${escapeHtml(h.type === 'nav' ? 'Navigation' : 'Info')}
                            </span>
                            <strong>
                                ${escapeHtml(h.title)}
                            </strong>
                        </div>
                        <div class="hotspot-list-actions">
                        <button type="button" class="hotspot-list-action btn-edit-hotspot" data-id="${escapeHtml(h.id)}" title="Edit or move hotspot" aria-label="Edit or move hotspot"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                        <button type="button" class="hotspot-list-action btn-delete-hotspot" data-id="${escapeHtml(h.id)}" title="Delete hotspot" aria-label="Delete hotspot">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                        </div>
                    </div>
                    `
                );

                if (currentPanoMesh) {
                    try {
                        const spot = createHotspotSpot(h.type, {
                            x: parseFloat(h.position_x),
                            y: parseFloat(h.position_y),
                            z: parseFloat(h.position_z)
                        });
                        
                        spot.addHoverText(escapeHtml(h.title));
                        currentPanoMesh.add(spot);
                    } catch(err) {
                        console.error("Failed to render a hotspot in 3D: ", err);
                    }
                }
            });

            document.querySelectorAll(".btn-edit-hotspot").forEach((btn) => {
                btn.addEventListener("click", function () {
                    const hotspot = data.hotspots.find(item => String(item.id) === String(btn.getAttribute("data-id")));
                    if (!hotspot || !currentPanoMesh) return;
                    document.getElementById("hs-title").value = hotspot.title || "";
                    document.getElementById("hs-description").value = hotspot.description || "";
                    typeSelect.value = hotspot.type === "nav" ? "nav" : "info";
                    toggleTypeFields();
                    if (hotspot.type === "nav") {
                        const legacyPhotos = [...currentPhotosArray].sort((a, b) => Number(a.id) - Number(b.id));
                        const legacyIndex = parseInt(hotspot.target_pano_index, 10);
                        const legacyTarget = hotspot.target_media_id || legacyPhotos[Number.isInteger(legacyIndex) && legacyIndex >= 0 ? legacyIndex : -1]?.id;
                        if (legacyTarget) targetSelect.value = String(legacyTarget);
                    }
                    pendingPoint = new THREE.Vector3(parseFloat(hotspot.position_x), parseFloat(hotspot.position_y), parseFloat(hotspot.position_z));
                    removeTemporarySpot();
                    pendingSpot = createHotspotSpot(typeSelect.value, pendingPoint);
                    pendingSpot.userData.hotspotId = hotspot.id;
                    currentPanoMesh.add(pendingSpot);
                    setFormMode(true);
                    formWrapper.classList.remove("hidden");
                });
            });

            document.querySelectorAll(".btn-delete-hotspot").forEach((btn) => {
                btn.addEventListener("click", function () {
                    showConfirm("Confirm Deletion", "Delete this hotspot?").then(confirmed => {
                        if (!confirmed) return;

                        fetch("actions/admin/delete_hotspot.php", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                                "X-CSRF-Token": csrfToken
                            },
                            body: JSON.stringify({ id: btn.getAttribute("data-id") })
                        })
                        .then((res) => res.json())
                        .then((data) => {
                            if (data.success) {
                                initViewer(currentPhotosArray[adminViewSelector.value].file_path);
                            } else {
                                showAlert("Notice", "Error: " + data.message);
                            }
                        });
                    });
                });
            });
        })
        .catch((error) => {
            console.error("Error loading hotspots:", error);
        });
    }
});
