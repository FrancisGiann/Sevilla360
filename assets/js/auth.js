document.addEventListener("DOMContentLoaded", () => {

    // --- View Elements ---
    const viewLogin = document.getElementById("view-user-login");
    const viewRegister = document.getElementById("view-user-register");
    const viewTerms = document.getElementById("view-terms");
    const viewAdmin = document.getElementById("view-admin-login");

    const allViews = [viewLogin, viewRegister, viewTerms, viewAdmin];

    // --- Trigger Elements ---
    const linkGotoRegister = document.getElementById("link-goto-register");
    const linkGotoLogin = document.getElementById("link-goto-login");
    const btnGotoAdmin = document.getElementById("btn-goto-admin");
    const linkBackLogin = document.getElementById("link-back-login");
    const linkGotoTerms = document.getElementById("link-goto-terms");
    const btnAgreeTerms = document.getElementById("btn-agree-terms");
    const agreeCheckbox = document.getElementById("agree-checkbox");

    // --- Switch View Function ---
    function switchView(targetView) {
        // Remove 'active' class from all views
        allViews.forEach(view => {
            view.classList.remove("active");
        });

        // Add 'active' class to the target view
        targetView.classList.add("active");
    }

    // --- Event Listeners for Navigation ---

    // User Login -> User Register
    linkGotoRegister.addEventListener("click", () => switchView(viewRegister));

    // User Register -> User Login
    linkGotoLogin.addEventListener("click", () => switchView(viewLogin));

    // User Login -> Admin Login
    btnGotoAdmin.addEventListener("click", () => switchView(viewAdmin));

    // Admin Login -> User Login
    linkBackLogin.addEventListener("click", () => switchView(viewLogin));

    // User Register -> Terms & Conditions
    linkGotoTerms.addEventListener("click", () => switchView(viewTerms));

    // Terms & Conditions -> Back to Register & Check Box
    btnAgreeTerms.addEventListener("click", () => {
        switchView(viewRegister);
        agreeCheckbox.checked = true; // Automatically checks the agreement box
    });

    // --- Password Show/Hide Toggle Logic ---
    const toggleButtons = document.querySelectorAll(".password-toggle");

    toggleButtons.forEach(button => {
        button.addEventListener("click", function () {
            // Find the input field relative to the clicked button
            const inputField = this.previousElementSibling;

            if (inputField.type === "password") {
                inputField.type = "text";
                this.textContent = "HIDE";
            } else {
                inputField.type = "password";
                this.textContent = "SHOW";
            }
        });
    });

});