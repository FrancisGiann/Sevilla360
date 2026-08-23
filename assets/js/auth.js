document.addEventListener("DOMContentLoaded", () => {

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  // --- View Elements ---
  const viewLogin = document.getElementById("view-user-login");
  const viewRegister = document.getElementById("view-user-register");
  const viewTerms = document.getElementById("view-terms");
  const viewAdmin = document.getElementById("view-admin-login");
  const viewForgot = document.getElementById("view-forgot-password");

  const allViews = [viewLogin, viewRegister, viewTerms, viewAdmin, viewForgot];

  // --- Trigger Elements ---
  const linkGotoRegister = document.getElementById("link-goto-register");
  const linkGotoLogin = document.getElementById("link-goto-login");
  const btnGotoAdmin = document.getElementById("btn-goto-admin");
  const linkBackLogin = document.getElementById("link-back-login");
  const linkGotoTerms = document.getElementById("link-goto-terms");
  const btnAgreeTerms = document.getElementById("btn-agree-terms");
  const agreeCheckbox = document.getElementById("agree-checkbox");
  
  const linkForgot = document.querySelector(".forgot-link");
  const linkBackLoginForgot = document.getElementById("link-back-login-from-forgot");

  // --- Switch View Function ---
  function switchView(targetView) {
    allViews.forEach((view) => {
      view.classList.remove("active");
    });
    targetView.classList.add("active");

    // Reset registration to Step 1 if navigating away, UNLESS target is viewTerms
    if (targetView !== viewRegister && targetView !== viewTerms) {
        showRegisterStep(1);
    }
  }

  // --- Progressive Registration Logic ---
  const step1 = document.getElementById('register-step-1');
  const step2 = document.getElementById('register-step-2');
  const stepSeg1 = document.getElementById('step-seg-1');
  const stepSeg2 = document.getElementById('step-seg-2');
  const btnNext = document.getElementById('btn-next-step');
  const btnPrev = document.getElementById('btn-prev-step');
  const registerSubtitle = document.getElementById('register-subtitle');
  
  // Initialization: Hide Step 2 via JS so it degrades gracefully if JS fails
  if (step2) step2.style.display = 'none';

  function showRegisterStep(step) {
      if(!step1 || !step2) return;
      if (step === 1) {
          step1.classList.add('active');
          step1.style.display = 'block';
          step2.classList.remove('active');
          step2.style.display = 'none';
          
          // Restore required attributes for Step 1
          const prevReqFields = step1.querySelectorAll('[data-was-required="true"]');
          prevReqFields.forEach(f => f.setAttribute('required', 'required'));
          
          if(stepSeg1) stepSeg1.classList.add('active');
          if(stepSeg2) stepSeg2.classList.remove('active');
          if(registerSubtitle) registerSubtitle.innerText = 'Create your account';
      } else {
          step2.classList.add('active');
          step2.style.display = 'block';
          step1.classList.remove('active');
          step1.style.display = 'none';
          
          // Remove required attributes from Step 1 so the form can submit while hidden
          const reqFields = step1.querySelectorAll('[required]');
          reqFields.forEach(f => {
              f.removeAttribute('required');
              f.dataset.wasRequired = 'true';
          });
          
          if(stepSeg1) stepSeg1.classList.add('active');
          if(stepSeg2) stepSeg2.classList.add('active');
          if(registerSubtitle) registerSubtitle.innerText = 'Tell us about yourself';
      }
  }

  if (btnNext) {
      btnNext.addEventListener('click', () => {
          let isValid = true;
          
          const email = document.getElementById('reg-email');
          const pass = document.getElementById('reg-password');
          const confPass = document.getElementById('reg-confirm-password');
          
          const errEmail = document.getElementById('err-email');
          const errPass = document.getElementById('err-password');
          const errConf = document.getElementById('err-confirm-password');
          
          errEmail.style.display = 'none';
          errPass.style.display = 'none';
          errConf.style.display = 'none';
          
          const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          if (!email.value || !emailRegex.test(email.value)) {
              errEmail.innerText = 'Please enter a valid email address.';
              errEmail.style.display = 'block';
              isValid = false;
          }
          
          if (!pass.value) {
              errPass.innerText = 'Password is required.';
              errPass.style.display = 'block';
              isValid = false;
          } else if (pass.value.length < 8) {
              errPass.innerText = 'Password must be at least 8 characters.';
              errPass.style.display = 'block';
              isValid = false;
          }
          
          if (pass.value !== confPass.value) {
              errConf.innerText = 'Passwords do not match.';
              errConf.style.display = 'block';
              isValid = false;
          }
          
          if (isValid) {
              showRegisterStep(2);
          }
      });
  }

  if (btnPrev) {
      btnPrev.addEventListener('click', () => {
          showRegisterStep(1);
      });
  }

  // --- Event Listeners for Navigation ---
  if(linkGotoRegister) linkGotoRegister.addEventListener("click", () => switchView(viewRegister));
  if(linkGotoLogin) linkGotoLogin.addEventListener("click", () => switchView(viewLogin));
  if(btnGotoAdmin) btnGotoAdmin.addEventListener("click", () => switchView(viewAdmin));
  if(linkBackLogin) linkBackLogin.addEventListener("click", () => switchView(viewLogin));
  if(linkGotoTerms) linkGotoTerms.addEventListener("click", () => switchView(viewTerms));
  if(linkForgot) linkForgot.addEventListener("click", (e) => { e.preventDefault(); switchView(viewForgot); });
  if(linkBackLoginForgot) linkBackLoginForgot.addEventListener("click", () => switchView(viewLogin));

  if(btnAgreeTerms) {
      btnAgreeTerms.addEventListener("click", () => {
        switchView(viewRegister);
        showRegisterStep(2); // Return to Step 2, where the Terms link lives
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

              // 1. Tell PHP to generate and email a new code in the background
              fetch('actions/auth/resend_code.php', {
                  method: 'POST',
                  headers: { 
                      'Content-Type': 'application/x-www-form-urlencoded',
                      'X-CSRF-Token': csrfToken
                  },
                  body: 'email=' + encodeURIComponent(verifyEmail)
              })
              .then(response => response.json())
              .then(data => {
                  if (data.success) {
                      showAlert("Notice", data.message || "A new verification code has been sent to your email inbox!", "success");
                  } else {
                      showAlert("Notice", data.message || "Could not resend code.", "error");
                  }
              })
              .catch(err => {
                  showAlert("Notice", "Network error. Failed to resend verification code.", "error");
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

  // --- Form Submission Loading Spinners ---
  const handleFormLoading = (formId, loadingText) => {
      const form = document.getElementById(formId);
      if (!form) return;
      form.addEventListener("submit", function (e) {
          const submitBtn = form.querySelector('button[type="submit"]');
          if (submitBtn) {
              if (submitBtn.disabled) {
                  e.preventDefault();
                  return false;
              }
              submitBtn.disabled = true;
              submitBtn.style.display = "inline-flex";
              submitBtn.style.alignItems = "center";
              submitBtn.style.justifyContent = "center";
              submitBtn.style.gap = "8px";
              submitBtn.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin" style="font-size: 0.95rem; line-height: 1;"></i><span>${loadingText}</span>`;
          }
      });
  };

  handleFormLoading("form-login", "SIGNING IN...");
  handleFormLoading("form-register", "CREATING ACCOUNT...");
  handleFormLoading("form-verify", "VERIFYING ACCOUNT...");
  handleFormLoading("form-admin", "LOGGING IN...");
  handleFormLoading("form-forgot", "SENDING LINK...");

});
