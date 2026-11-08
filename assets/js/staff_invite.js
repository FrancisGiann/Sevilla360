document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("staff-invite-form");
    if (!form) return;

    const status = document.getElementById("staff-invite-status");
    const submit = document.getElementById("staff-invite-submit");
    const password = document.getElementById("staff-invite-password");
    const confirmation = document.getElementById("staff-invite-confirm");
    const token = document.getElementById("staff-invite-token").value;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        status.textContent = "";
        status.dataset.state = "";
        if (password.value !== confirmation.value) {
            status.textContent = "Passwords do not match.";
            status.dataset.state = "error";
            confirmation.focus();
            return;
        }
        const policy = window.SevillaPasswordPolicy?.validate(password.value);
        if (!policy?.valid) {
            status.textContent = policy?.message || "Password does not meet the required policy.";
            status.dataset.state = "error";
            password.focus();
            return;
        }

        submit.disabled = true;
        const originalText = submit.textContent;
        submit.textContent = "Activating…";
        try {
            const response = await fetch("actions/auth/complete_staff_invite.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                body: JSON.stringify({ token, password: password.value, confirm_password: confirmation.value })
            });
            const data = await response.json();
            if (!response.ok || data?.success !== true) throw new Error(data?.message || "Account setup could not be completed.");
            form.hidden = true;
            status.textContent = data.message;
            status.dataset.state = "success";
            const signIn = document.createElement("a");
            signIn.href = "auth.php";
            signIn.className = "btn btn-primary btn-full";
            signIn.textContent = "Go to sign in";
            form.insertAdjacentElement("afterend", signIn);
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : "Account setup could not be completed. Try again or ask for a new invitation.";
            status.dataset.state = "error";
            submit.disabled = false;
            submit.textContent = originalText;
        }
    });
});
