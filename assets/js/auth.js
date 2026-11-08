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
  const registrationTermsContent = document.getElementById('registration-terms-content');
  const registrationTermsHint = document.getElementById('registration-terms-hint');
  const registrationConsentStatus = document.getElementById('registration-consent-status');
  const registrationConsentGroup = agreeCheckbox?.closest('.terms-checkbox-group');
  let registrationTermsRead = false;
  
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
    targetView.closest('.auth-card')?.classList.toggle('is-registering', targetView === viewRegister);
    if (targetView !== viewRegister && targetView !== viewTerms) emailCheckVersion++;

    if (targetView === viewRegister) showRegistrationStep(currentRegistrationStep, { focus: true });
    else targetView.querySelector('input:not([type="hidden"]):not([name="website_url_honeypot"]), button')?.focus();
  }

  // Credentials and contact details are grouped; verification is the final stage.
  const email = document.getElementById('reg-email');
  const errEmail = document.getElementById('err-email');
  let emailInteracted = false;
  let emailCheckVersion = 0;
  let emailCheckPending = false;
  let activeEmailCheckVersion = 0;
  const registrationForm = document.getElementById('form-register');
  const registrationSubmit = registrationForm?.querySelector('button[type="submit"]');
  const accountGroup = document.getElementById('registration-account');
  const contactGroup = document.getElementById('registration-contact');
  const registrationContinue = document.getElementById('registration-continue');
  const registrationBack = document.getElementById('registration-back');
  let currentRegistrationStep = 1;
  let checkedRegistrationEmail = '';
  function showRegistrationStep(step, { focus = false } = {}) {
      currentRegistrationStep = step === 2 ? 2 : 1;
      if (accountGroup) accountGroup.hidden = currentRegistrationStep !== 1;
      if (contactGroup) contactGroup.hidden = currentRegistrationStep !== 2;
      document.querySelectorAll('[data-registration-stage]').forEach(item => {
          const stage = Number(item.dataset.registrationStage);
          item.classList.toggle('is-complete', stage < currentRegistrationStep);
          if (stage === currentRegistrationStep) item.setAttribute('aria-current', 'step');
          else item.removeAttribute('aria-current');
      });
      if (focus) document.getElementById(currentRegistrationStep === 1 ? 'registration-account-title' : 'registration-contact-title')?.focus();
  }
  if (registrationForm && accountGroup && contactGroup) {
      // Step validation is handled below so hidden required fields cannot trap
      // native validation/focus. All constraints remain on the inputs and server.
      registrationForm.noValidate = true;
      document.getElementById('registration-account-actions').hidden = false;
      registrationBack.hidden = false;
      showRegistrationStep(1);
  }
  registrationBack?.addEventListener('click', () => {
      if (registrationSubmit.disabled) return;
      emailCheckVersion++;
      showRegistrationStep(1, { focus: true });
  });
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
          checkedRegistrationEmail = '';
          emailInteracted = true;
          updateRegistrationEmailState();
      });
  }
  
  function validateRegistrationPasswords() {
      const pass = document.getElementById('reg-password');
      const confPass = document.getElementById('reg-confirm-password');
      const errPass = document.getElementById('err-password');
      const errConf = document.getElementById('err-confirm-password');
      const passwordResult = window.SevillaPasswordPolicy?.validate(pass.value);
      const passwordIssue = !pass.value
          ? 'Password is required.'
          : passwordResult?.valid
              ? ''
              : 'Use a capital letter, lowercase letter, number, and symbol (like ! or @).';
      const confirmationIssue = !confPass.value
          ? 'Please confirm your password.'
          : pass.value !== confPass.value ? 'Passwords do not match.' : '';
      [[pass, errPass, passwordIssue], [confPass, errConf, confirmationIssue]].forEach(([field, error, message]) => {
          error.textContent = message;
          error.style.display = message ? 'block' : 'none';
          field.setAttribute('aria-invalid', message ? 'true' : 'false');
      });
      return passwordIssue ? pass : (confirmationIssue ? confPass : null);
  }

  function validateAccountGroup() {
      emailInteracted = true;
      const emailIsValid = updateRegistrationEmailState({ normalize: true });
      const passwordIssue = validateRegistrationPasswords();
      if (!emailIsValid || passwordIssue) {
          showRegistrationStep(1);
          if (!emailIsValid) email.focus();
          else passwordIssue.focus();
          return false;
      }
      return true;
  }
  async function continueRegistration() {
      if (emailCheckPending || registrationSubmit.disabled) return;
      if (!validateAccountGroup()) return;
      const submittedEmail = email.value.trim();
      const version = ++emailCheckVersion;
      emailCheckPending = true;
      activeEmailCheckVersion = version;
      registrationContinue.disabled = true;
      registrationForm.setAttribute('aria-busy', 'true');
      try {
          const emailState = await checkRegistrationEmail(submittedEmail);
          if (version !== emailCheckVersion || email.value.trim() !== submittedEmail || currentRegistrationStep !== 1 || !viewRegister?.classList.contains('active')) return;
          if (emailState === 'unavailable') {
              showEmailCheckMessage(unavailableEmailMessage);
              email.focus();
              return;
          }
          checkedRegistrationEmail = submittedEmail;
          showRegistrationStep(2, { focus: true });
      } catch (error) {
          if (version === emailCheckVersion && currentRegistrationStep === 1) {
              showEmailCheckMessage('We could not check this email right now. Please try again.');
              if (viewRegister?.classList.contains('active')) email.focus();
          }
      } finally {
          if (activeEmailCheckVersion === version) {
              emailCheckPending = false;
              registrationContinue.disabled = false;
              registrationForm.removeAttribute('aria-busy');
          }
      }
  }
  registrationContinue?.addEventListener('click', continueRegistration);
  registrationForm?.addEventListener('submit', async event => {
      event.preventDefault();
      if (currentRegistrationStep === 1) { await continueRegistration(); return; }
      if (registrationSubmit.disabled || emailCheckPending) return;
      if (!validateAccountGroup()) return;
      const submittedEmail = email.value.trim();
      if (checkedRegistrationEmail !== submittedEmail) { showRegistrationStep(1, { focus: true }); return; }
      const nameFields = ['reg-first-name', 'reg-last-name'].map(id => document.getElementById(id));
      nameFields.forEach(field => {
          filterAndValidateRegistrationName(field);
          field.value = field.value.trim();
          field.setCustomValidity(!field.value ? 'Please enter your name.' : registrationNameIssue(field.value));
      });
      const invalidName = nameFields.find(field => !field.checkValidity());
      const registrationPhone = document.getElementById('reg-phone');
      if (registrationPhone) filterAndValidateRegistrationPhone(registrationPhone);
      if (invalidName || !registrationForm.checkValidity()) {
          showRegistrationStep(2);
          if (invalidName) invalidName.reportValidity();
          else if (registrationPhone && !registrationPhone.checkValidity()) registrationPhone.reportValidity();
          else registrationForm.reportValidity();
          return;
      }
      if (!agreeCheckbox?.checked || !registrationTermsRead) {
          showRegistrationStep(2);
          setRegistrationTermsMessage(!registrationTermsRead
              ? 'Please read the Terms of Service to the bottom before agreeing.'
              : 'Select the consent checkbox to continue.');
          if (!registrationTermsRead) linkGotoTerms?.focus();
          else agreeCheckbox?.focus();
          return;
      }
      registrationSubmit.disabled = true;
      registrationBack.disabled = true;
      registrationForm.setAttribute('aria-busy', 'true');
      let redirecting = false;
      try {
          const response = await fetch(registrationForm.action, {
              method: 'POST', credentials: 'same-origin', cache: 'no-store',
              headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(registrationForm)
          });
          const result = await response.json();
          if (response.status === 409 && result.state === 'unavailable') {
              checkedRegistrationEmail = '';
              showRegistrationStep(1);
              if (email.value.trim() === submittedEmail) { showEmailCheckMessage(unavailableEmailMessage); email.focus(); }
              return;
          }
          if (result.state === 'error' && typeof result.message === 'string' && !response.ok) {
              showAlert('Notice', result.message, 'warning');
              return;
          }
          if (result.state === 'redirect' && typeof result.url === 'string' && response.ok) {
              const target = new URL(result.url, registrationForm.action);
              if (target.origin !== window.location.origin || !target.pathname.endsWith('/auth.php')) throw new Error('Unexpected registration destination');
              redirecting = true;
              window.location.assign(target.href);
              return;
          }
          throw new Error('Registration unavailable');
      } catch (error) {
          showEmailCheckMessage('We could not create your account right now. Please try again.');
          showRegistrationStep(1);
          email.focus();
      } finally {
          registrationSubmit.disabled = redirecting;
          registrationBack.disabled = redirecting;
          if (!redirecting) registrationForm.removeAttribute('aria-busy');
      }
  });
  function registrationNameIssue(value) {
      return /^[\p{L}][\p{L}\p{M}]*(?:[ '\u2019\u2010-\u2015-][\p{L}][\p{L}\p{M}]*)*$/u.test(value)
          ? ''
          : 'Use letters, spaces, apostrophes, or hyphens in your name.';
  }

  function setRegistrationTermsMessage(message) {
      if (registrationTermsHint) registrationTermsHint.textContent = message;
      if (registrationConsentStatus) registrationConsentStatus.textContent = message;
  }

  function updateRegistrationTermsReadState() {
      if (!viewTerms?.classList.contains('active') || !registrationTermsContent) return;
      const atBottom = registrationTermsContent.scrollHeight <= registrationTermsContent.clientHeight + 2
          || registrationTermsContent.scrollTop + registrationTermsContent.clientHeight >= registrationTermsContent.scrollHeight - 2;
      if (atBottom) registrationTermsRead = true;
      if (btnAgreeTerms) btnAgreeTerms.disabled = !registrationTermsRead;
      if (agreeCheckbox) agreeCheckbox.disabled = !registrationTermsRead;
      setRegistrationTermsMessage(registrationTermsRead
          ? 'You have reached the end of the Terms of Service. Choose I Agree to enable consent.'
          : 'Scroll to the bottom of these terms to enable I Agree.');
  }

  function openRegistrationTerms() {
      registrationTermsRead = false;
      if (agreeCheckbox) {
          agreeCheckbox.checked = false;
          agreeCheckbox.disabled = true;
      }
      if (btnAgreeTerms) btnAgreeTerms.disabled = true;
      if (registrationTermsContent) registrationTermsContent.scrollTop = 0;
      setRegistrationTermsMessage('Scroll to the bottom of these terms to enable I Agree.');
      switchView(viewTerms);
      registrationTermsContent?.focus({ preventScroll: true });
      updateRegistrationTermsReadState();
  }

  function registrationPhoneIssue(value) {
      return /^[0-9+(). \-]*$/u.test(value)
          ? ''
          : 'Use digits with optional +, spaces, parentheses, dots, or hyphens.';
  }
  function filterRegistrationInput(input, disallowedCharacters) {
      const originalValue = input.value;
      const selectionStart = input.selectionStart;
      const selectionEnd = input.selectionEnd;
      const filter = value => value.replace(disallowedCharacters, '');
      const filteredValue = filter(originalValue);
      if (filteredValue === originalValue) return;
      const start = Number.isInteger(selectionStart) ? filter(originalValue.slice(0, selectionStart)).length : filteredValue.length;
      const end = Number.isInteger(selectionEnd) ? filter(originalValue.slice(0, selectionEnd)).length : start;
      input.value = filteredValue;
      try { input.setSelectionRange(start, end); } catch (_) { /* Some autofill controls do not expose a text selection. */ }
  }
  function filterAndValidateRegistrationName(input) {
      filterRegistrationInput(input, /[^\p{L}\p{M} '\u2019\u2010-\u2015-]/gu);
      const value = input.value.trim();
      input.setCustomValidity(value ? registrationNameIssue(value) : '');
  }
  function filterAndValidateRegistrationPhone(input) {
      filterRegistrationInput(input, /[^0-9+(). \-]/gu);
      input.setCustomValidity(registrationPhoneIssue(input.value));
  }
  ['reg-first-name', 'reg-last-name'].forEach(id => {
      const field = document.getElementById(id);
      if (!field) return;
      const filter = event => {
          if (event.isComposing) return;
          filterAndValidateRegistrationName(field);
      };
      field.addEventListener('input', filter);
      field.addEventListener('change', () => filterAndValidateRegistrationName(field));
      field.addEventListener('compositionend', () => filterAndValidateRegistrationName(field));
  });
  const registrationPhone = document.getElementById('reg-phone');
  if (registrationPhone) {
      registrationPhone.addEventListener('input', event => {
          if (!event.isComposing) filterAndValidateRegistrationPhone(registrationPhone);
      });
      registrationPhone.addEventListener('change', () => filterAndValidateRegistrationPhone(registrationPhone));
      registrationPhone.addEventListener('compositionend', () => filterAndValidateRegistrationPhone(registrationPhone));
  }
  ['reg-password', 'reg-confirm-password'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', () => {
          if (document.getElementById('err-password').textContent || document.getElementById('err-confirm-password').textContent) {
              validateRegistrationPasswords();
          }
      });
  });

  // --- Event Listeners for Navigation ---
  if(linkGotoRegister) linkGotoRegister.addEventListener("click", () => switchView(viewRegister));
  if(linkGotoLogin) linkGotoLogin.addEventListener("click", () => switchView(viewLogin));
  linkGotoTerms?.addEventListener("click", openRegistrationTerms);
  registrationTermsContent?.addEventListener('scroll', updateRegistrationTermsReadState);
  window.addEventListener('resize', updateRegistrationTermsReadState);
  viewTerms?.addEventListener('pointerdown', event => {
      if (!registrationTermsRead && (event.target === btnAgreeTerms || btnAgreeTerms?.contains(event.target))) {
          setRegistrationTermsMessage('Please scroll to the bottom of the Terms of Service before agreeing.');
      }
  });
  registrationConsentGroup?.addEventListener('pointerdown', event => {
      if (!registrationTermsRead && !linkGotoTerms?.contains(event.target)) {
          setRegistrationTermsMessage('Please read the Terms of Service to the bottom before agreeing.');
      }
  });
  agreeCheckbox?.addEventListener('change', () => {
      if (!registrationTermsRead) {
          agreeCheckbox.checked = false;
          agreeCheckbox.disabled = true;
          setRegistrationTermsMessage('Please read the Terms of Service to the bottom before agreeing.');
          return;
      }
      setRegistrationTermsMessage(agreeCheckbox.checked
          ? ''
          : 'Select the consent checkbox to continue.');
  });
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
        if (!registrationTermsRead) {
          setRegistrationTermsMessage('Please scroll to the bottom of the Terms of Service before agreeing.');
          return;
        }
        switchView(viewRegister);
        if(agreeCheckbox) {
          agreeCheckbox.disabled = false;
          agreeCheckbox.checked = true;
          agreeCheckbox.focus();
          setRegistrationTermsMessage('');
        }
      });
  }

  document.getElementById('btn-back-from-terms')?.addEventListener('click', () => {
      switchView(viewRegister);
      setRegistrationTermsMessage(registrationTermsRead
          ? 'You have reached the end of the Terms of Service. Choose I Agree to enable consent.'
          : 'Open the Terms of Service and scroll to the bottom before agreeing.');
      linkGotoTerms?.focus();
  });

  // --- Password Show/Hide Toggle Logic ---
  const toggleButtons = document.querySelectorAll(".password-toggle");
  toggleButtons.forEach((button) => {
    const controlledInput = button.previousElementSibling;
    button.setAttribute('aria-controls', controlledInput.id);
    button.setAttribute('aria-label', `Show ${controlledInput.id === 'reg-confirm-password' ? 'password confirmation' : 'password'}`);
    button.addEventListener("click", function () {
      const inputField = this.previousElementSibling;
      if (inputField.type === "password") {
        inputField.type = "text";
        this.textContent = "HIDE";
      } else {
        inputField.type = "password";
        this.textContent = "SHOW";
      }
      this.setAttribute('aria-pressed', inputField.type === 'text' ? 'true' : 'false');
      this.setAttribute('aria-label', `${inputField.type === 'text' ? 'Hide' : 'Show'} ${inputField.id === 'reg-confirm-password' ? 'password confirmation' : 'password'}`);
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

  const verificationModal = document.getElementById('verification-modal');
  function closeVerification() {
      verificationModal.style.display = 'none';
      // Dismiss the dialog without losing account details or the server's
      // interrupted-booking destination. Never persist passwords in storage.
      const url = new URL(window.location.href);
      url.searchParams.delete('verify_email');
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
      switchView(viewLogin);
      const loginEmail = document.getElementById('login-email');
      if (loginEmail && verifyEmail) loginEmail.value = verifyEmail;
  }
  document.getElementById('btn-close-verification')?.addEventListener('click', closeVerification);
  verificationModal?.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
          event.preventDefault();
          closeVerification();
      } else if (event.key === 'Tab') {
          const controls = Array.from(verificationModal.querySelectorAll('button:not([disabled]), input:not([type="hidden"])')).filter(control => !control.closest('[hidden]'));
          const first = controls[0];
          const last = controls[controls.length - 1];
          if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
          else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
      }
  });

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
      resendBtn.disabled = true;
      resendBtn.textContent = `Resend code in ${remaining}s`;
      resendTimer = setInterval(() => {
          remaining--;
          if (remaining <= 0) {
              clearInterval(resendTimer);
              resendTimer = null;
              resendBtn.removeAttribute('aria-disabled');
              resendBtn.disabled = false;
              resendBtn.textContent = phoneSignup ? 'Resend SMS code' : 'Resend verification email';
          } else resendBtn.textContent = `Resend code in ${remaining}s`;
      }, 1000);
  }
  resendBtn?.addEventListener('click', async event => {
      event.preventDefault();
      if (resendPending || resendTimer || !verifyEmail) return;
      resendPending = true;
      resendBtn.setAttribute('aria-disabled', 'true');
      resendBtn.disabled = true;
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
          else document.getElementById('resend-code-status').textContent = message;
          if (response.ok && data.success) startResendCooldown();
      } catch (_) {
          if (phoneSignup) verificationStatus(codeStatus, 'Connection issue. Could not request a new code.');
          else document.getElementById('resend-code-status').textContent = 'Connection issue. Could not request a new code.';
      } finally {
          resendPending = false;
          if (!resendTimer) { resendBtn.removeAttribute('aria-disabled'); resendBtn.disabled = false; }
      }
  });

  // --- Form Submission Loading Spinners ---
  const handleFormLoading = (formId, loadingText) => {
      const form = document.getElementById(formId);
      if (!form) return;
      form.addEventListener("submit", function (e) {
          if (e.defaultPrevented) return;
          const submitBtn = form.querySelector('button[type="submit"]');
          if (submitBtn) {
              if (submitBtn.disabled) {
                  e.preventDefault();
                  return false;
              }
              submitBtn.dataset.idleHtml = submitBtn.innerHTML;
              submitBtn.disabled = true;
              form.setAttribute('aria-busy', 'true');
              submitBtn.style.display = "inline-flex";
              submitBtn.style.alignItems = "center";
              submitBtn.style.justifyContent = "center";
              submitBtn.style.gap = "8px";
              submitBtn.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin" style="font-size: 0.95rem; line-height: 1;"></i><span>${loadingText}</span>`;
          }
      });
  };

  // BFCache restores the DOM including disabled loading buttons.
  window.addEventListener('pageshow', event => {
      if (!event.persisted) return;
      emailCheckVersion++;
      emailCheckPending = false;
      if (registrationContinue) registrationContinue.disabled = false;
      if (registrationBack) registrationBack.disabled = false;
      if (registrationSubmit) { registrationSubmit.disabled = false; registrationForm.removeAttribute('aria-busy'); }
      document.querySelectorAll('button[data-idle-html]').forEach(button => {
          button.innerHTML = button.dataset.idleHtml;
          button.disabled = false;
          button.closest('form')?.removeAttribute('aria-busy');
      });
  });

  handleFormLoading("form-login", "SIGNING IN...");
  handleFormLoading("form-verify", "VERIFYING ACCOUNT...");
  handleFormLoading("form-admin", "LOGGING IN...");
  handleFormLoading("form-forgot", "REQUESTING...");

});
