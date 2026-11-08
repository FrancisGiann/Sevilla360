document.addEventListener("DOMContentLoaded", () => {
    const saveButton = document.getElementById("btn-save-profile");
    const saveStatus = document.getElementById("staff-profile-save-status");
    if (!saveButton) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const field = (id) => document.getElementById(id);
    const alertUser = (title, message, type = "info") => {
        if (typeof window.showAlert === "function") window.showAlert(title, message, type);
        else if (saveStatus) saveStatus.textContent = message;
    };

    saveButton.addEventListener("click", async () => {
        const name = field("prof-name").value.trim();
        const phone = field("prof-contact").value.trim();
        const currPass = field("prof-curr-pass").value;
        const newPass = field("prof-new-pass").value;
        const confPass = field("prof-conf-pass").value;

        if (!name || name.length > 150) return alertUser("Check profile", "Enter a full name of 150 characters or fewer.", "error");
        if (phone.length > 20 || (phone && !/^[0-9+().\-\s]+$/.test(phone))) return alertUser("Check profile", "Enter a valid contact number.", "error");
        if ((currPass || newPass || confPass) && (!currPass || !newPass || !confPass)) {
            return alertUser("Check password", "Fill in all password fields to change your password.", "error");
        }
        if (newPass && newPass !== confPass) return alertUser("Check password", "New password and confirmation do not match.", "error");
        if (newPass) {
            const policy = window.SevillaPasswordPolicy?.validate(newPass);
            if (!policy?.valid) return alertUser("Check password", policy?.message || "Password does not meet the required policy.", "error");
        }

        saveButton.disabled = true;
        const oldLabel = saveButton.textContent;
        saveButton.textContent = "Saving…";
        if (saveStatus) saveStatus.textContent = "Saving your account details…";
        try {
            const response = await fetch("actions/admin/save_profile.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                body: JSON.stringify({ name, phone, curr_pass: currPass, new_pass: newPass, conf_pass: confPass })
            });
            const data = await response.json();
            if (!response.ok || data?.success !== true) throw new Error(data?.message || "Profile could not be saved.");
            field("prof-curr-pass").value = "";
            field("prof-new-pass").value = "";
            field("prof-conf-pass").value = "";
            if (saveStatus) saveStatus.textContent = data.message;
            alertUser("Profile updated", data.message, "success");
        } catch (error) {
            const message = error instanceof Error ? error.message : "Profile could not be saved. Try again.";
            if (saveStatus) saveStatus.textContent = message;
            alertUser("Unable to save profile", message, "error");
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = oldLabel;
        }
    });
});
