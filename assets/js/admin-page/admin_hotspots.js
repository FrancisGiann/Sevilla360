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
    let currentPhotosArray = []; // NEW: Keep track of all photos for this room
    let pendingPoint = null;
    let pendingSpot = null;
    let raycastEnabled = false;

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
    
    // NEW: The Admin View Switcher Dropdown
    const adminViewSelector = document.getElementById("hs-admin-view-selector");

    // ============================================================
    // CUSTOM HOTSPOT ICONS
    // ============================================================
    const HOTSPOT_INFO_ICON = "assets/img/hotspot-info.png";
    const HOTSPOT_ARROW_ICON = "assets/img/hotspot-arrow.png";

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
                alert("No panorama found for this slot.");
                return;
            }

            // Sync the ID perfectly to the first photo
            currentMediaId = currentPhotosArray[0].id;

            document.getElementById("hotspot-modal-title").innerText =
                "Place Hotspots — " + currentSlot.replace(/^venue_/, "").replace(/_360$/, "").replace(/_/g, " ");

            // Populate Walk-To target dropdown (excluding the current view, set to 0 by default)
            refreshTargetDropdown(0);

            // NEW: Populate the Admin View Switcher
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
            loadingEl.style.display = "flex";

            // Clear any old temporary pin
            pendingPoint = null;
            pendingSpot = null;
            raycastEnabled = false;

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
            opt.value = idx;
            opt.innerText = `View ${idx + 1}`;
            targetSelect.appendChild(opt);
        });
    }

    // ============================================================
    // NEW: ADMIN VIEW SWITCHER LOGIC
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
            loadingEl.style.display = "none";
            currentPanoMesh = pano;
            raycastEnabled = true;

            // Load saved hotspots for this specific view
            loadExistingHotspots(currentMediaId);
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
        
        // Fix for mirrored coordinate bug
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
        pendingPoint = null;
        removeTemporarySpot();
    });

    // ============================================================
    // SAVE HOTSPOT
    // ============================================================
    document.getElementById("btn-save-hotspot")?.addEventListener("click", () => {
        const title = document.getElementById("hs-title").value.trim();

        if (!title) {
            alert("Please enter a title/label for this hotspot.");
            return;
        }

        if (!pendingPoint) {
            alert("No position captured — click on the panorama first.");
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
            target_pano_index: selectedType === "nav" ? targetSelect.value : null
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
                pendingPoint = null;
                removeTemporarySpot();
                loadExistingHotspots(currentMediaId);
            } else {
                alert("Error: " + data.message);
            }
        })
        .catch(() => {
            alert("Network error saving hotspot.");
        });
    });

    // ============================================================
    // LOAD EXISTING HOTSPOTS
    // ============================================================
    function loadExistingHotspots(mediaId) {
        fetch(`actions/admin/get_hotspots.php?media_id=${mediaId}`)
        .then((res) => res.json())
        .then((data) => {
            listEl.innerHTML = "";

            if (!data.success || data.hotspots.length === 0) {
                listEl.innerHTML = '<p style="font-size:0.85rem; color:#888;">No hotspots placed yet.</p>';
                return;
            }

            data.hotspots.forEach((h) => {
                listEl.insertAdjacentHTML(
                    "beforeend",
                    `
                    <div class="hotspot-list-item" data-id="${h.id}">
                        <div class="hs-info">
                            <span class="hs-type-badge ${h.type}">
                                ${h.type}
                            </span>
                            <strong>
                                ${h.title}
                            </strong>
                        </div>
                        <button class="btn-delete-hotspot" data-id="${h.id}" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                    `
                );

                if (currentPanoMesh) {
                    const spot = createHotspotSpot(h.type, {
                        x: parseFloat(h.position_x),
                        y: parseFloat(h.position_y),
                        z: parseFloat(h.position_z)
                    });
                    
                    spot.addHoverText(h.title);
                    currentPanoMesh.add(spot);
                }
            });

            document.querySelectorAll(".btn-delete-hotspot").forEach((btn) => {
                btn.addEventListener("click", function () {
                    if (!confirm("Delete this hotspot?")) return;

                    fetch("actions/admin/delete_hotspot.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-Token": csrfToken
                        },
                        body: JSON.stringify({ id: this.getAttribute("data-id") })
                    })
                    .then((res) => res.json())
                    .then((data) => {
                        if (data.success) {
                            // Reload the current view so the pin disappears from 3D space
                            initViewer(currentPhotosArray[adminViewSelector.value].file_path);
                        } else {
                            alert("Error: " + data.message);
                        }
                    });
                });
            });
        })
        .catch((error) => {
            console.error("Error loading hotspots:", error);
        });
    }
});