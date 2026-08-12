document.addEventListener("DOMContentLoaded", () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const hotspotModal = document.getElementById("hotspotModal");
    if (!hotspotModal) return;

    let viewer = null;
    let currentPanoMesh = null;
    let currentMediaId = null;
    let currentSlot = null;
    let pendingPoint = null;
    let raycastEnabled = false;

    // NEW: Variables to track visual pins on the screen
    let tempSpot = null; 
    let renderedSpots = [];

    const panoContainer = document.getElementById("hotspot-pano-container");
    const loadingEl = document.getElementById("hotspot-loading");
    const formWrapper = document.getElementById("hotspot-form-wrapper");
    const listEl = document.getElementById("hotspot-list");
    const typeSelect = document.getElementById("hs-type");
    const descWrapper = document.getElementById("hs-desc-wrapper");
    const targetWrapper = document.getElementById("hs-target-wrapper");
    const targetSelect = document.getElementById("hs-target-index");

    document.querySelectorAll(".btn-place-hotspots").forEach(btn => {
        btn.addEventListener("click", () => {
            currentMediaId = btn.getAttribute("data-media-id");
            currentSlot = btn.getAttribute("data-slot");

            const photos = (window.panoDataOrdered && window.panoDataOrdered[currentSlot]) || [];
            if (photos.length === 0) return alert("No panorama found for this slot.");

            document.getElementById("hotspot-modal-title").innerText = "Place Hotspots — " + currentSlot.replace('venue_', '').replace(/_/g, ' ');

            targetSelect.innerHTML = '';
            photos.forEach((p, idx) => {
                const opt = document.createElement("option");
                opt.value = idx;
                opt.innerText = `View ${idx + 1}`;
                targetSelect.appendChild(opt);
            });

            hotspotModal.classList.add("active");
            formWrapper.classList.add("hidden");
            loadingEl.style.display = "flex";

            initViewer(photos[0].file_path);
            loadExistingHotspots(currentMediaId);
        });
    });

    document.getElementById("btnCloseHotspotModal")?.addEventListener("click", () => {
        hotspotModal.classList.remove("active");
        formWrapper.classList.add("hidden");
        if (viewer) {
            viewer.dispose();
            viewer = null;
        }
    });

    function initViewer(imageUrl) {
        if (viewer) {
            viewer.dispose();
            panoContainer.innerHTML = '';
        }

        viewer = new PANOLENS.Viewer({
            container: panoContainer,
            controlBar: false,
            autoRotate: false,
        });

        const pano = new PANOLENS.ImagePanorama(imageUrl);
        pano.addEventListener("load", () => {
            loadingEl.style.display = "none";
            currentPanoMesh = pano;
            raycastEnabled = true;

            // Re-draw saved hotspots after panorama loads
            loadExistingHotspots(currentMediaId);
        });

        viewer.add(pano);
        viewer.setPanorama(pano);

        panoContainer.addEventListener("click", handlePanoClick);
    }

    function handlePanoClick(event) {
        if (!raycastEnabled || !viewer || !currentPanoMesh) return;

        const raycaster = new THREE.Raycaster();
        const mouse = new THREE.Vector2();
        const rect = panoContainer.getBoundingClientRect();

        mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, viewer.camera);
        const intersects = raycaster.intersectObject(currentPanoMesh, true);

        if (intersects.length > 0) {
            pendingPoint = intersects[0].point;
            
            // FIX: Visually place a temporary pin where they clicked
            if (!tempSpot) {
                tempSpot = new PANOLENS.Infospot(300);
                currentPanoMesh.add(tempSpot);
            }
            tempSpot.position.copy(pendingPoint);
            tempSpot.show();

            formWrapper.classList.remove("hidden");
            document.getElementById("hs-title").value = '';
            document.getElementById("hs-description").value = '';
            typeSelect.value = 'info';
            toggleTypeFields();
        }
    }

    typeSelect?.addEventListener("change", toggleTypeFields);
    function toggleTypeFields() {
        const isNav = typeSelect.value === 'nav';
        descWrapper.classList.toggle('hidden', isNav);
        targetWrapper.classList.toggle('hidden', !isNav);
    }

    // FIX: Remove temporary pin if they click cancel
    document.getElementById("btn-cancel-hotspot")?.addEventListener("click", () => {
        formWrapper.classList.add("hidden");
        pendingPoint = null;
        if (tempSpot && currentPanoMesh) {
            currentPanoMesh.remove(tempSpot);
            tempSpot = null;
        }
    });

    document.getElementById("btn-save-hotspot")?.addEventListener("click", () => {
        const title = document.getElementById("hs-title").value.trim();
        if (!title) return alert("Please enter a title/label for this hotspot.");
        if (!pendingPoint) return alert("No position captured — click on the panorama first.");

        const payload = {
            media_id: currentMediaId,
            type: typeSelect.value,
            title: title,
            description: document.getElementById("hs-description").value.trim(),
            x: pendingPoint.x,
            y: pendingPoint.y,
            z: pendingPoint.z,
            target_pano_index: typeSelect.value === 'nav' ? targetSelect.value : null
        };

        fetch("actions/admin/save_hotspot.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
            body: JSON.stringify(payload)
        })
        .then(res => {
            if (!res.ok) throw new Error("HTTP Status " + res.status);
            return res.json();
        })
        .then(data => {
            if (data.success) {
                formWrapper.classList.add("hidden");
                pendingPoint = null;
                if (tempSpot && currentPanoMesh) {
                    currentPanoMesh.remove(tempSpot);
                    tempSpot = null;
                }
                loadExistingHotspots(currentMediaId);
            } else {
                alert("Database Error: " + data.message);
            }
        })
        .catch(err => {
            console.error("Save Fetch Error:", err);
            alert("Network Error: Make sure your PHP file is named exactly 'save_hotspot.php' (singular) inside actions/admin/. Check console for details.");
        });
    });

    function loadExistingHotspots(mediaId) {
        if (!mediaId) return;

        fetch(`actions/admin/get_hotspots.php?media_id=${mediaId}`)
        .then(res => {
            if (!res.ok) throw new Error("HTTP Status " + res.status);
            return res.json();
        })
        .then(data => {
            listEl.innerHTML = '';

            // FIX: Clear old visual pins from the screen before drawing new ones
            if (currentPanoMesh) {
                renderedSpots.forEach(spot => currentPanoMesh.remove(spot));
            }
            renderedSpots = [];

            if (!data.success || data.hotspots.length === 0) {
                listEl.innerHTML = '<p style="font-size:0.85rem; color:#888;">No hotspots placed yet.</p>';
                return;
            }

            data.hotspots.forEach(h => {
                // 1. Add to text list
                listEl.insertAdjacentHTML('beforeend', `
                <div class="hotspot-list-item" data-id="${h.id}">
                    <div class="hs-info">
                        <span class="hs-type-badge ${h.type}">${h.type}</span>
                        <strong>${h.title}</strong>
                    </div>
                    <button class="btn-delete-hotspot" data-id="${h.id}" title="Delete">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                `);

                // 2. FIX: Actually draw the saved pins on the 360 viewer for the admin!
                if (currentPanoMesh) {
                    const spot = new PANOLENS.Infospot(300);
                    spot.position.set(parseFloat(h.position_x), parseFloat(h.position_y), parseFloat(h.position_z));
                    spot.addHoverText(h.title);
                    
                    // Color code the pins: Nav = Red, Info = Green
                    if (h.type === 'nav') spot.material.color.setHex(0xe06666); 
                    else spot.material.color.setHex(0x4ade80);

                    currentPanoMesh.add(spot);
                    renderedSpots.push(spot);
                }
            });

            document.querySelectorAll(".btn-delete-hotspot").forEach(btn => {
                btn.addEventListener("click", function() {
                    if (!confirm("Delete this hotspot?")) return;
                    fetch("actions/admin/delete_hotspot.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                        body: JSON.stringify({ id: this.getAttribute("data-id") })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) loadExistingHotspots(currentMediaId);
                        else alert("Error: " + data.message);
                    })
                    .catch(err => alert("Network Error: Make sure your file is named delete_hotspot.php"));
                });
            });
        })
        .catch(err => console.error("Load Fetch Error:", err));
    }
});