document.addEventListener("DOMContentLoaded", () => {

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  // --- View Elements ---
  const viewLogin = document.getElementById("view-user-login");
  const viewRegister = document.getElementById("view-user-register");
  const viewTerms = document.getElementById("view-terms");
  const viewForgot = document.getElementById("view-forgot-password");

  const allViews = [viewLogin, viewRegister, viewTerms, viewForgot];

  // --- Trigger Elements ---
  const linkGotoRegister = document.getElementById("link-goto-register");
  const linkGotoLogin = document.getElementById("link-goto-login");
  const linkGotoTerms = document.getElementById("link-goto-terms");
  const btnAgreeTerms = document.getElementById("btn-agree-terms");
  const agreeCheckbox = document.getElementById("agree-checkbox");
  
  const linkBackLoginForgot = document.getElementById("link-back-login-from-forgot");
  const forgotTriggers = document.querySelectorAll("[data-forgot-password]");
  const forgotOriginInput = document.getElementById('forgot-origin');
  let forgotOrigin = forgotOriginInput?.value === 'admin' ? 'admin' : 'customer';

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
  const registrationSteps = [...document.querySelectorAll('#form-register .register-step')];
  const stepSegments = [...document.querySelectorAll('#register-step-indicator .step-segment')];
  const stepIndicator = document.getElementById('register-step-indicator');
  const registerSubtitle = document.getElementById('register-subtitle');
  let currentRegisterStep = 1;
  const email = document.getElementById('reg-email');
  const errEmail = document.getElementById('err-email');
  let emailInteracted = false;
  let emailCheckVersion = 0;
  let emailCheckPending = false;
  const registrationForm = document.getElementById('form-register');
  const registrationSubmit = registrationForm?.querySelector('button[type="submit"]');
  const unavailableEmailMessage = 'This email cannot be used to create an account. Try another email or sign in.';

  function showEmailCheckMessage(message) {
      if (!email || !errEmail) return;
      emailInteracted = true;
      email.classList.add('email-invalid');
      email.classList.remove('email-valid');
      email.setAttribute('aria-invalid', 'true');
      errEmail.textContent = message;
      errEmail.classList.add('email-feedback-invalid');
      errEmail.classList.remove('email-feedback-valid');
      errEmail.hidden = false;
  }

  async function checkRegistrationEmail(value) {
      const response = await fetch('actions/auth/check_registration_email.php', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
          body: new URLSearchParams({ email: value }).toString()
      });
      if (!response.ok) throw new Error('Email check unavailable');
      const result = await response.json();
      if (result.state !== 'available' && result.state !== 'unavailable') throw new Error('Invalid email check');
      return result.state;
  }

  function isRegistrationEmailFormatValid(value, nativeTypeMismatch = false) {
      if (nativeTypeMismatch || typeof value !== 'string' || !value) return false;

      // Match PHP's ordinary-address behavior without accepting Unicode or
      // whitespace in the local part/domain. Quoted/escaped local parts are
      // intentionally outside the registration field's common address scope.
      const atIndex = value.indexOf('@');
      if (atIndex < 1 || atIndex !== value.lastIndexOf('@')) return false;

      const localPart = value.slice(0, atIndex);
      const domain = value.slice(atIndex + 1);
      const localAtom = "[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+";
      if (!new RegExp(`^${localAtom}(?:\\.${localAtom})*$`).test(localPart)) return false;

      const labels = domain.split('.');
      if (labels.length < 2 || labels.some(label =>
          !/^[A-Za-z0-9-]+$/.test(label) || label.startsWith('-') || label.endsWith('-')
      )) return false;

      // FILTER_VALIDATE_EMAIL accepts a one-character TLD but rejects a
      // numeric-only final label (for example, example.1).
      return /[A-Za-z]/.test(labels[labels.length - 1]);
  }

  function updateRegistrationEmailState({ normalize = false } = {}) {
      if (!email || !errEmail) return false;

      const value = email.value.trim();
      const nativeTypeMismatch = email.value === value && email.validity.typeMismatch;
      const state = !value ? 'empty' : (isRegistrationEmailFormatValid(value, nativeTypeMismatch) ? 'valid' : 'invalid');
      const isValid = state === 'valid';
      const message = state === 'empty'
          ? 'Email address is required.'
          : isValid
              ? 'Email address looks valid.'
              : 'Enter a valid email address, such as name@example.com.';

      if (normalize && isValid) email.value = value;

      email.classList.toggle('email-invalid', state === 'empty' || state === 'invalid');
      email.classList.toggle('email-valid', isValid);
      email.setAttribute('aria-invalid', isValid ? 'false' : 'true');

      // Keep the initial field quiet, then only mutate the polite region when
      // its state/message changes so typing does not cause repeated announcements.
      if (!emailInteracted && !normalize) {
          errEmail.hidden = true;
      } else {
          if (errEmail.textContent !== message) errEmail.textContent = message;
          errEmail.classList.toggle('email-feedback-invalid', !isValid);
          errEmail.classList.toggle('email-feedback-valid', isValid);
          errEmail.hidden = false;
      }

      return isValid;
  }

  if (email) {
      email.addEventListener('blur', () => {
          emailInteracted = true;
          updateRegistrationEmailState();
      });
      email.addEventListener('input', () => {
          emailCheckVersion++;
          emailInteracted = true;
          updateRegistrationEmailState();
      });
  }
  
  const stepLabels = ['Email', 'Password', 'Your name', 'Contact details'];
  function showRegisterStep(step, { focus = false } = {}) {
      if (!registrationSteps.length) return;
      currentRegisterStep = step;
      registrationSteps.forEach((panel, index) => {
          const visible = index + 1 === step;
          panel.classList.toggle('active', visible);
          panel.hidden = !visible;
          panel.querySelectorAll('[data-required-field]').forEach(field => { field.required = visible; });
      });
      stepSegments.forEach((segment, index) => segment.classList.toggle('active', index < step));
      stepIndicator?.setAttribute('aria-valuenow', String(step));
      stepIndicator?.setAttribute('aria-valuetext', `${stepLabels[step - 1]}, step ${step} of 4`);
      if (registerSubtitle) registerSubtitle.textContent = stepLabels[step - 1];
      if (focus) registrationSteps[step - 1]?.querySelector('input:not([type="hidden"])')?.focus();
  }
  registrationSteps.forEach(panel => panel.querySelectorAll('[required]').forEach(field => field.dataset.requiredField = 'true'));
  showRegisterStep(1);

  async function advanceRegisterStep() {
      if (emailCheckPending || currentRegisterStep >= 4) return;
      const step = currentRegisterStep;
      const panel = registrationSteps[step - 1];
      if (step === 1) {
          emailInteracted = true;
          if (!updateRegistrationEmailState({ normalize: true })) { email.focus(); return; }
          const checkedEmail = email.value.trim();
          const version = ++emailCheckVersion;
          emailCheckPending = true;
          const next = panel.querySelector('.register-next');
          next.disabled = true;
          try {
              const state = await checkRegistrationEmail(checkedEmail);
              if (version !== emailCheckVersion || email.value.trim() !== checkedEmail || currentRegisterStep !== step || !viewRegister?.classList.contains('active')) return;
              if (state === 'unavailable') { showEmailCheckMessage(unavailableEmailMessage); email.focus(); return; }
              showRegisterStep(2, { focus: true });
          } catch (_) {
              if (version === emailCheckVersion) { showEmailCheckMessage('We could not check this email right now. Please try again.'); email.focus(); }
          } finally { emailCheckPending = false; next.disabled = false; }
          return;
      }
      if (step === 2) {
          const pass = document.getElementById('reg-password');
          const confirm = document.getElementById('reg-confirm-password');
          const errPass = document.getElementById('err-password');
          const errConfirm = document.getElementById('err-confirm-password');
          const result = window.SevillaPasswordPolicy?.validate(pass.value);
          errPass.style.display = result?.valid ? 'none' : 'block';
          if (!result?.valid) errPass.textContent = result?.message || 'Password does not meet the required policy.';
          errConfirm.style.display = pass.value === confirm.value && confirm.value ? 'none' : 'block';
          if (errConfirm.style.display === 'block') errConfirm.textContent = 'Passwords do not match.';
          if (!result?.valid) { pass.focus(); return; }
          if (errConfirm.style.display === 'block') { confirm.focus(); return; }
      } else {
          const invalid = panel.querySelector('input:invalid');
          if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
      }
      showRegisterStep(step + 1, { focus: true });
  }

  document.querySelectorAll('#form-register .register-next').forEach(button =>
      button.addEventListener('click', advanceRegisterStep));
  document.querySelectorAll('#form-register .register-back').forEach(button =>
      button.addEventListener('click', () => showRegisterStep(Number(button.dataset.prevStep), { focus: true })));

  if (registrationForm) {
      registrationForm.addEventListener('submit', async (event) => {
          event.preventDefault();
          if (currentRegisterStep < 4) { await advanceRegisterStep(); return; }
          if (registrationSubmit.disabled || emailCheckPending) return;
          const invalid = registrationSteps[3].querySelector('input:invalid');
          if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
          const submittedEmail = email.value.trim();
          registrationSubmit.disabled = true;
          try {
              const response = await fetch(registrationForm.action, {
                  method: 'POST',
                  credentials: 'same-origin',
                  cache: 'no-store',
                  headers: { 'X-Requested-With': 'XMLHttpRequest' },
                  body: new FormData(registrationForm)
              });
              if (response.status === 409) {
                  const result = await response.json();
                  if (result.state !== 'unavailable') throw new Error('Invalid registration response');
                  showRegisterStep(1);
                  if (email.value.trim() === submittedEmail) {
                      showEmailCheckMessage(unavailableEmailMessage);
                      email.focus();
                  }
                  return;
              }
              const result = await response.json();
              if (result.state === 'error' && typeof result.message === 'string' && !response.ok) {
                  showAlert('Notice', result.message, 'warning');
                  return;
              }
              if (result.state === 'redirect' && typeof result.url === 'string' && response.ok) {
                  const target = new URL(result.url, registrationForm.action);
                  if (target.origin !== window.location.origin || !target.pathname.endsWith('/auth.php')) {
                      throw new Error('Unexpected registration destination');
                  }
                  window.location.assign(target.href);
                  return;
              }
              throw new Error('Registration unavailable');
          } catch (error) {
              showRegisterStep(1);
              showEmailCheckMessage('We could not create your account right now. Please try again.');
              email.focus();
          } finally {
              registrationSubmit.disabled = false;
          }
      });
  }

  // --- Event Listeners for Navigation ---
  if(linkGotoRegister) linkGotoRegister.addEventListener("click", () => switchView(viewRegister));
  if(linkGotoLogin) linkGotoLogin.addEventListener("click", () => switchView(viewLogin));
  if(linkGotoTerms) linkGotoTerms.addEventListener("click", () => switchView(viewTerms));
  const openForgotPassword = (event) => {
      event.preventDefault();
      forgotOrigin = event.currentTarget?.dataset.origin === 'admin' ? 'admin' : 'customer';
      if (forgotOriginInput) forgotOriginInput.value = forgotOrigin;
      switchView(viewForgot);
  };
  forgotTriggers.forEach((trigger) => trigger.addEventListener("click", openForgotPassword));
  if(linkBackLoginForgot) linkBackLoginForgot.addEventListener("click", () => switchView(viewLogin));

  if(btnAgreeTerms) {
      btnAgreeTerms.addEventListener("click", () => {
        switchView(viewRegister);
        showRegisterStep(4); // Return to the consent step
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
  const phoneSignup = document.body.dataset.phoneSignup === '1';

  if (verifyEmail) {

      // 1. Show the modal by changing display from 'none' to 'flex'
      const modal = document.getElementById('verification-modal');
      if (modal) {
          modal.style.display = 'flex';
          document.getElementById('verification-code')?.focus();
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

  const codePanel = document.getElementById('verification-code-panel');
  const changePanel = document.getElementById('verification-change-panel');
  const codeStatus = document.getElementById('verification-status');
  const changeStatus = document.getElementById('verification-change-status');
  function verificationStatus(node, message, success = false) {
      if (!node) return;
      node.textContent = message;
      node.hidden = !message;
      node.classList.toggle('is-success', success);
  }
  function showVerificationPanel(panel) {
      if (!codePanel || !changePanel) return;
      const changing = panel === 'change';
      codePanel.hidden = changing;
      changePanel.hidden = !changing;
      document.getElementById('verification-modal')?.setAttribute('aria-describedby', changing ? 'verification-change-description' : 'verification-description');
      (changing ? document.getElementById('pending-new-phone') : document.getElementById('verification-code'))?.focus();
  }
  document.getElementById('verification-change-open')?.addEventListener('click', () => showVerificationPanel('change'));
  document.getElementById('verification-change-back')?.addEventListener('click', () => showVerificationPanel('code'));

  document.getElementById('pending-change-phone')?.addEventListener('click', async function() {
      if (this.disabled) return;
      const newPhone = document.getElementById('pending-new-phone');
      if (!newPhone?.value.trim()) {
          verificationStatus(changeStatus, 'Enter a Philippine mobile number.');
          newPhone?.focus();
          return;
      }
      this.disabled = true;
      verificationStatus(changeStatus, '');
      try {
          const response = await fetch('actions/auth/change_pending_phone.php', {
              method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
              body: JSON.stringify({email: verifyEmail, phone: newPhone.value})
          });
          const data = await response.json();
          if (data.success) {
              showVerificationPanel('code');
              verificationStatus(codeStatus, data.message || 'Enter the code sent to the new number.', true);
              if (!resendTimer) startResendCooldown();
          } else {
              verificationStatus(changeStatus, data.message || 'No code was sent. Try again later.');
          }
      } catch (_) { verificationStatus(changeStatus, 'Could not request a code. Try again.'); }
      finally { this.disabled = false; }
  });

  const resendBtn = document.getElementById('resend-code-btn');
  let resendPending = false;
  let resendTimer = null;
  function startResendCooldown() {
      if (!resendBtn) return;
      let remaining = 60;
      resendBtn.setAttribute('aria-disabled', 'true');
      resendBtn.textContent = `Resend code in ${remaining}s`;
      resendTimer = setInterval(() => {
          remaining--;
          if (remaining <= 0) {
              clearInterval(resendTimer);
              resendTimer = null;
              resendBtn.removeAttribute('aria-disabled');
              resendBtn.textContent = phoneSignup ? 'Resend SMS code' : 'Resend verification email';
          } else resendBtn.textContent = `Resend code in ${remaining}s`;
      }, 1000);
  }
  resendBtn?.addEventListener('click', async event => {
      event.preventDefault();
      if (resendPending || resendTimer) return;
      resendPending = true;
      resendBtn.setAttribute('aria-disabled', 'true');
      if (phoneSignup) verificationStatus(codeStatus, '');
      try {
          const response = await fetch('actions/auth/resend_code.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
              body: 'email=' + encodeURIComponent(verifyEmail)
          });
          const data = await response.json();
          const message = data.message || (data.success ? (phoneSignup ? 'A new SMS code was queued.' : 'A new verification code was sent.') : 'Could not resend code.');
          if (phoneSignup) verificationStatus(codeStatus, message, !!data.success);
          else showAlert('Notice', message, data.success ? 'success' : 'error');
          if (data.success) startResendCooldown();
      } catch (_) {
          if (phoneSignup) verificationStatus(codeStatus, 'Connection issue. Could not request a new code.');
          else showAlert('Notice', 'Connection issue. Could not request a new code.', 'error');
      } finally {
          resendPending = false;
          if (!resendTimer) resendBtn.removeAttribute('aria-disabled');
      }
  });

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
  handleFormLoading("form-verify", "VERIFYING ACCOUNT...");
  handleFormLoading("form-admin", "LOGGING IN...");
  handleFormLoading("form-forgot", "REQUESTING...");

});
