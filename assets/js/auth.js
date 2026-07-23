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

  // --- RESEND CODE 60-SECOND TIMER LOGIC ---
      const resendBtn = document.getElementById('resend-code-btn');
      if (resendBtn) {
          resendBtn.addEventListener('click', function (e) {
              e.preventDefault();
              
              // If the button is disabled (timer is running), do nothing
              if (this.style.pointerEvents === 'none') return;

              // 1. Tell PHP to generate a new code in the background
              fetch('actions/auth/resend_code.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: 'email=' + encodeURIComponent(verifyEmail)
              })
              .then(response => response.text())
              .then(data => {
                  // For testing: Show the new code in an alert
                  alert("DEVELOPMENT MODE:\nYour NEW verification code is: " + data);
              });

              // 2. Disable the button and start the 60-second UI timer
              this.style.pointerEvents = 'none';
              this.style.color = 'gray';
              this.style.textDecoration = 'none';
              
              let timeLeft = 60;
              this.innerText = `Resend code in ${timeLeft}s`;

              const timer = setInterval(() => {
                  timeLeft--;
                  this.innerText = `Resend code in ${timeLeft}s`;
                  
                  if (timeLeft <= 0) {
                      clearInterval(timer);
                      this.style.pointerEvents = 'auto';
                      this.style.color = 'var(--color-dark-light)';
                      this.style.textDecoration = 'underline';
                      this.innerText = 'Resend verification email';
                  }
              }, 1000);
          });
      }

});