/**
 * SEVILLA360 - Global Custom Modals
 * Replaces native alert() and confirm() with styled UI popups.
 */

document.addEventListener("DOMContentLoaded", () => {
    if (!document.getElementById('globalModalOverlay')) {
        const modalHTML = `
        <div class="global-modal-overlay" id="globalModalOverlay" style="z-index: 10000; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); opacity: 0; visibility: hidden; transition: all 0.3s ease;"></div>
        
        <div class="global-admin-modal" id="globalAlertModal" style="display: block; z-index: 10001; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; visibility: hidden; transition: all 0.3s ease; background: white; border-radius: 8px; padding: 25px; width: 90%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center;">
            <i class="fa-solid fa-circle-info" id="ga-icon" style="font-size: 3rem; margin-bottom: 15px; color: #d6a870;"></i>
            <h3 class="modal-title" id="ga-title" style="margin-bottom: 10px; color: #2a2522;">Notice</h3>
            <div class="modal-body" style="margin-bottom: 20px; color: #666; font-size: 0.95rem;">
                <p id="ga-message">Message text goes here.</p>
            </div>
            <div class="modal-actions" style="display: flex; justify-content: center;">
                <button class="btn btn-primary" id="ga-btn-ok" style="width: 100%; padding: 10px; background: #d6a870; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">OK</button>
            </div>
        </div>

        <div class="global-admin-modal" id="globalConfirmModal" style="display: block; z-index: 10001; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; visibility: hidden; transition: all 0.3s ease; background: white; border-radius: 8px; padding: 25px; width: 90%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); text-align: center;">
            <i class="fa-solid fa-circle-exclamation" id="gc-icon" style="font-size: 3rem; margin-bottom: 15px; color: #d6a870;"></i>
            <h3 class="modal-title" id="gc-title" style="margin-bottom: 10px; color: #2a2522;">Confirm</h3>
            <div class="modal-body" style="margin-bottom: 20px; color: #666; font-size: 0.95rem;">
                <p id="gc-message">Are you sure?</p>
            </div>
            <div class="modal-actions" style="display: flex; justify-content: center; gap: 10px;">
                <button class="btn btn-outline" id="gc-btn-cancel" style="flex: 1; padding: 10px; background: white; color: #666; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; font-weight: 600;">Cancel</button>
                <button class="btn btn-primary" id="gc-btn-ok" style="flex: 1; padding: 10px; background: #d6a870; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">OK</button>
            </div>
        </div>

        <div class="global-admin-modal" id="globalLoaderModal" style="display: block; z-index: 10002; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; visibility: hidden; transition: all 0.3s ease; background: transparent; text-align: center;">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 4rem; color: #d6a870; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));"></i>
            <h3 id="gl-message" style="color: white; margin-top: 15px; font-family: 'Inter', sans-serif; font-size: 1.2rem; text-shadow: 0 2px 4px rgba(0,0,0,0.5);">Processing...</h3>
        </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
});

function showModalCSS(el) {
    el.style.visibility = 'visible';
    el.style.opacity = '1';
    el.style.transform = 'translate(-50%, -50%) scale(1)';
}

function hideModalCSS(el) {
    el.style.opacity = '0';
    el.style.transform = 'translate(-50%, -50%) scale(0.9)';
    setTimeout(() => { el.style.visibility = 'hidden'; }, 300);
}

window.showAlert = function(title, message, type = "info", reloadOnClose = false) {
    const alertModal = document.getElementById("globalAlertModal");
    const overlay = document.getElementById("globalModalOverlay");
    if (!alertModal || !overlay) {
        alert(title + ": " + message);
        if (reloadOnClose) window.location.reload();
        return;
    }

    document.getElementById("ga-title").innerText = title;
    document.getElementById("ga-message").innerText = message;

    const iconEl = document.getElementById("ga-icon");
    iconEl.className = "fa-solid " + (type === "error" ? "fa-circle-xmark" : (type === "success" ? "fa-circle-check" : "fa-circle-info"));
    iconEl.style.color = (type === "error") ? "#dc2626" : ((type === "success") ? "#16a34a" : "#d6a870");

    overlay.style.visibility = 'visible';
    overlay.style.opacity = '1';
    showModalCSS(alertModal);

    const okBtn = document.getElementById("ga-btn-ok");
    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);

    newOkBtn.addEventListener("click", () => {
        hideModalCSS(alertModal);
        overlay.style.opacity = '0';
        setTimeout(() => overlay.style.visibility = 'hidden', 300);
        
        if (reloadOnClose === true) {
            window.location.reload();
        }
    });
};

window.showConfirm = function(title, message, type = "warning") {
    return new Promise((resolve) => {
        const confirmModal = document.getElementById("globalConfirmModal");
        const overlay = document.getElementById("globalModalOverlay");
        
        if (!confirmModal || !overlay) {
            resolve(confirm(title + "\\n\\n" + message));
            return;
        }

        document.getElementById("gc-title").innerText = title;
        document.getElementById("gc-message").innerText = message;

        const iconEl = document.getElementById("gc-icon");
        iconEl.className = "fa-solid " + (type === "error" ? "fa-circle-xmark" : (type === "success" ? "fa-circle-check" : "fa-circle-exclamation"));
        iconEl.style.color = (type === "error") ? "#dc2626" : ((type === "success") ? "#16a34a" : "#d6a870");

        overlay.style.visibility = 'visible';
        overlay.style.opacity = '1';
        showModalCSS(confirmModal);

        const okBtn = document.getElementById("gc-btn-ok");
        const cancelBtn = document.getElementById("gc-btn-cancel");
        
        const newOkBtn = okBtn.cloneNode(true);
        const newCancelBtn = cancelBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(newOkBtn, okBtn);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

        newOkBtn.addEventListener("click", () => {
            hideModalCSS(confirmModal);
            overlay.style.opacity = '0';
            setTimeout(() => overlay.style.visibility = 'hidden', 300);
            resolve(true);
        });

        newCancelBtn.addEventListener("click", () => {
            hideModalCSS(confirmModal);
            overlay.style.opacity = '0';
            setTimeout(() => overlay.style.visibility = 'hidden', 300);
            resolve(false);
        });
    });
};

window.showGlobalLoader = function(message = "Processing...") {
    const loader = document.getElementById("globalLoaderModal");
    const overlay = document.getElementById("globalModalOverlay");
    if(!loader || !overlay) return;
    
    document.getElementById("gl-message").innerText = message;
    
    overlay.style.visibility = 'visible';
    overlay.style.opacity = '1';
    showModalCSS(loader);
};

window.hideGlobalLoader = function() {
    const loader = document.getElementById("globalLoaderModal");
    const overlay = document.getElementById("globalModalOverlay");
    if(!loader) return;
    
    hideModalCSS(loader);
    if (overlay) {
        overlay.style.opacity = '0';
        setTimeout(() => overlay.style.visibility = 'hidden', 300);
    }
};
