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
    allViews.forEach((view) => {
      view.classList.remove("active");
    });
    targetView.classList.add("active");
  }

  // --- Event Listeners for Navigation ---
  if(linkGotoRegister) linkGotoRegister.addEventListener("click", () => switchView(viewRegister));
  if(linkGotoLogin) linkGotoLogin.addEventListener("click", () => switchView(viewLogin));
  if(btnGotoAdmin) btnGotoAdmin.addEventListener("click", () => switchView(viewAdmin));
  if(linkBackLogin) linkBackLogin.addEventListener("click", () => switchView(viewLogin));
  if(linkGotoTerms) linkGotoTerms.addEventListener("click", () => switchView(viewTerms));

  if(btnAgreeTerms) {
      btnAgreeTerms.addEventListener("click", () => {
        switchView(viewRegister);
        if(agreeCheckbox) agreeCheckbox.checked = true;
      });
  }

  // --- Password Show/Hide Toggle Logic ---
  const toggleButtons = document.querySelectorAll(".password-toggle");
  toggleButtons.forEach((button) => {
    button.addEventListener("click", function () {
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

  // --- Verification Modal Logic ---
  const urlParams = new URLSearchParams(window.location.search);
  const verifyEmail = urlParams.get('verify_email');

  if (verifyEmail) {
      // 1. Show the modal by changing display from 'none' to 'flex'
      const modal = document.getElementById('verification-modal');
      if (modal) {
          modal.style.display = 'flex';
      }
      
      // 2. Update the text to show their email
      const emailDisplay = document.getElementById('verify-email-display');
      if (emailDisplay) {
          emailDisplay.innerText = verifyEmail;
      }
      
      // 3. Put the email into the hidden input so the form can send it to PHP
      const emailInput = document.getElementById('verify-email-input');
      if (emailInput) {
          emailInput.value = verifyEmail;
      }
  }

});