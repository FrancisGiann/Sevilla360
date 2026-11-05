#!/usr/bin/env python3
"""Exercise real signup endpoints with disposable MariaDB/PHP and stubbed delivery.
The fixture never loads the application's .env or connects to its database.
"""
import hashlib
import hmac
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
CHECKS = 0


def check(value, label):
    global CHECKS
    if not value:
        raise AssertionError(label)
    CHECKS += 1
    print('PASS ' + label, flush=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


with tempfile.TemporaryDirectory(prefix='sevilla-signup-') as directory:
    temp = Path(directory)
    app = temp / 'app'
    sessions = temp / 'sessions'
    sessions.mkdir()
    names = ['actions/auth/' + name + '.php' for name in [
        'register_process', 'verify_process', 'resend_code', 'login_process',
        'phone_register_process', 'phone_verify_process', 'phone_resend_process']]
    names += ['includes/' + name + '.php' for name in [
        'session_init', 'request_context', 'rate_limit', 'password_policy',
        'customer_login_recovery', 'booking_intent', 'contact_verification',
        'unisms_sms', 'site_metadata']]
    names += ['auth.php', 'assets/css/ui-refinement.css']
    for name in names:
        target = app / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    # Keep the real challenge issuance, HMAC, provider acceptance, throttles,
    # transaction and consumption. Stub only the outbound transport here.
    contact = app / 'includes/contact_verification.php'
    source = contact.read_text()
    start = source.index('function contact_sms_transport(')
    end = source.index('function contact_send_sms(', start)
    source = source[:start] + r'''function contact_sms_transport(string $endpoint,array $payload): array {
        if (file_exists(__DIR__.'/../fail-sms')) return ['http_status'=>422,'body'=>[]];
        file_put_contents(__DIR__.'/../sms.jsonl',json_encode($payload)."\n",FILE_APPEND);
        return ['http_status'=>201,'body'=>['message'=>['reference_id'=>'msg_synthetic','status'=>'pending']]];
    }
    ''' + source[end:]
    # Exercise a state change after the OTP commits but before session issuance.
    source = source.replace('$db->commit(); return true;', "$db->commit(); if (file_exists(__DIR__.'/../suspend-after-verify')) $db->query(\"UPDATE users SET status='suspended' WHERE id=\".$userId); return true;")
    contact.write_text(source)
    (app / 'config').mkdir()
    (app / 'config/env.php').write_text("<?php $_ENV=['APP_KEY'=>str_repeat('synthetic-key-',4),'PHONE_SIGNUP_ENABLED'=>'1','UNISMS_SMS_ENABLED'=>'1','UNISMS_DAILY_SMS_LIMIT'=>'100','UNISMS_SECRET_KEY'=>'synthetic-only'];")
    (app / 'includes/database_backup.php').write_text('<?php function database_backup_enforce_web_write_gate() {}')
    (app / 'includes/mailer.php').write_text('<?php function send_custom_email(...$args) {return true;} function send_welcome_email(...$args) {return true;}')
    (app / 'includes/notifications.php').write_text('<?php function create_user_notification(...$args) {return true;}')
    socket_path = temp / 'db.sock'
    (app / 'config/db_connect.php').write_text("<?php date_default_timezone_set('UTC'); $conn=new mysqli('localhost','root','','signup_test',0," + repr(str(socket_path)) + ');')
    (app / 'probe.php').write_text("<?php require 'includes/session_init.php'; require 'includes/booking_intent.php'; booking_auth_capture_request(); header('Content-Type: application/json'); echo json_encode(['session'=>$_SESSION,'id'=>session_id()]);")
    subprocess.run(['mariadb-install-db', '--no-defaults', '--datadir=' + str(temp / 'db'), '--tmpdir=' + str(temp), '--auth-root-authentication-method=normal', '--skip-test-db'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    log = (temp / 'server.log').open('w')
    db = subprocess.Popen(['mariadbd', '--no-defaults', '--datadir=' + str(temp / 'db'), '--socket=' + str(socket_path), '--skip-networking', '--default-time-zone=+00:00', '--tmpdir=' + str(temp), '--pid-file=' + str(temp / 'db.pid'), '--log-error=' + str(temp / 'db.log')], stdout=log, stderr=log)
    server = None

    def sql(statement):
        return subprocess.check_output(['mariadb', '--no-defaults', '--socket=' + str(socket_path), '-uroot', '-N', '-B'], input=statement.encode()).decode().strip()

    def query(statement):
        return sql('USE signup_test; ' + statement)

    try:
        for _ in range(100):
            if socket_path.exists():
                break
            if db.poll() is not None:
                raise RuntimeError((temp / 'db.log').read_text())
            time.sleep(.05)
        sql('''CREATE DATABASE signup_test; USE signup_test;
CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY,email VARCHAR(255) UNIQUE,password_hash VARCHAR(255),role VARCHAR(20),status VARCHAR(20) DEFAULT 'active',is_verified BOOL DEFAULT FALSE,verification_code VARCHAR(6),verification_expires_at DATETIME,consented_at DATETIME,auth_version INT DEFAULT 1,reset_token VARCHAR(255),reset_token_hash CHAR(64),reset_expires_at DATETIME);
CREATE TABLE customers (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,first_name VARCHAR(100),last_name VARCHAR(100),email VARCHAR(255),phone VARCHAR(32));
CREATE TABLE staff (user_id INT,status VARCHAR(20));
CREATE TABLE rate_limits (ip_address VARCHAR(64),action_name VARCHAR(100),attempts INT,first_attempt DATETIME,last_attempt DATETIME,UNIQUE(ip_address,action_name));
CREATE TABLE media_cms (slot_assignment VARCHAR(100),file_path VARCHAR(255));''')
        query((ROOT / 'migrations/034_customer_contact_verification.sql').read_text())
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
        server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-S', '127.0.0.1:' + str(port), '-t', str(app)], cwd=app, stdout=log, stderr=log, env={'PATH': os.environ['PATH']})
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), .1):
                    break
            except OSError:
                time.sleep(.05)

        def client():
            return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

        def request(browser, path, data=None, headers=None):
            req = urllib.request.Request('http://127.0.0.1:' + str(port) + '/' + path, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers=headers or {})
            try:
                response = browser.open(req)
            except urllib.error.HTTPError as error:
                response = error
            return response.status, response.headers, response.read().decode()

        def state(browser, suffix=''):
            return json.loads(request(browser, 'probe.php' + suffix)[2])

        def verify(browser, email, code, csrf=None):
            return request(browser, 'actions/auth/verify_process.php', {'email': email, 'verification_code': code, 'csrf_token': state(browser)['session']['csrf_token'] if csrf is None else csrf})

        sequence = 0

        def last_code():
            return json.loads((app / 'sms.jsonl').read_text().splitlines()[-1])['content'].split('code: ')[1][:6]

        def create(email, xhr=False):
            global sequence
            sequence += 1
            query('DELETE FROM rate_limits; DELETE FROM contact_throttles')
            browser = client()
            result = request(browser, 'actions/auth/register_process.php', dict(first_name='Synthetic', last_name='Test', email=email, phone='0917' + str(sequence).zfill(7), password='Test1!password', confirm_password='Test1!password', consent='1', csrf_token=state(browser)['session']['csrf_token']), {'X-Requested-With': 'XMLHttpRequest'} if xhr else {})
            check(result[0] == (200 if xhr else 302), 'registration accepts ' + ('AJAX' if xhr else 'form') + ' account')
            check(not state(browser)['session'].get('auth_alert'), 'accepted send sets no blocking queued alert')
            return browser, last_code()

        browser, code = create('one@example.invalid', True)
        before = state(browser)
        verify_url = 'auth.php?verify_email=one%40example.invalid'
        html = request(browser, verify_url)[2]
        check('Verify Your Phone' in html and 'showAlert(' not in html and 'verification-code' in html, 'phone code entry without queued modal')
        verify(browser, 'one@example.invalid', code, 'wrong')
        check(not state(browser)['session'].get('logged_in'), 'CSRF failure never authenticates')
        verify(browser, 'one@example.invalid', '000000' if code != '000000' else '111111')
        check(not state(browser)['session'].get('logged_in'), 'wrong code never authenticates')
        html = request(browser, verify_url)[2]
        check('showAlert(' not in html and 'invalid, expired, or already used' in html, 'wrong-code feedback is inline')
        result = verify(browser, 'one@example.invalid', code)
        after = state(browser)
        check(result[1]['Location'] == '../../user_dashboard.php', 'direct signup returns to dashboard')
        check(after['session']['logged_in'] is True and after['session']['role'] == 'customer' and after['session']['auth_version'] == 1, 'trusted customer session includes current account version')
        check(after['id'] != before['id'] and after['session']['csrf_token'] != before['session']['csrf_token'], 'session and CSRF rotate')
        check(after['session']['signup_welcome'] is True and 'auth_alert' not in after['session'], 'welcome uses a nonblocking one-time status')
        check(after['session']['auth_started_at'] > 0 and 'pending_phone_user_id' not in after['session'], 'policy initialized and pending grant cleared')
        check(query("SELECT is_verified FROM users WHERE email='one@example.invalid'") == '1', 'account activated server-side')
        anonymous = client()
        verify(anonymous, 'one@example.invalid', code)
        check(not state(anonymous)['session'].get('logged_in'), 'replayed code cannot authenticate another session')
        bound, bound_code = create('bound@example.invalid')
        outsider = client()
        verify(outsider, 'bound@example.invalid', bound_code)
        check(not state(outsider)['session'].get('logged_in'), 'missing pending-session binding cannot authenticate')
        mismatch, mismatch_code = create('mismatch@example.invalid')
        verify(bound, 'mismatch@example.invalid', mismatch_code)
        check(not state(bound)['session'].get('logged_in') and query("SELECT is_verified FROM users WHERE email='mismatch@example.invalid'") == '0', 'mismatched pending user cannot consume another account code')
        browser, old = create('two@example.invalid')
        state(browser, '?destination=booking_resume')
        query("UPDATE contact_challenges SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=(SELECT id FROM users WHERE email='two@example.invalid')")
        verify(browser, 'two@example.invalid', old)
        check(not state(browser)['session'].get('logged_in'), 'expired code cannot authenticate')
        query('DELETE FROM contact_throttles')
        resent = request(browser, 'actions/auth/resend_code.php', {'email': 'two@example.invalid'}, {'X-CSRF-Token': state(browser)['session']['csrf_token']})
        check(json.loads(resent[2])['success'] is True, 'resend accepted with CSRF')
        new = last_code()
        if old != new:
            verify(browser, 'two@example.invalid', old)
            check(not state(browser)['session'].get('logged_in'), 'resend invalidates the previous code')
        check(verify(browser, 'two@example.invalid', new)[1]['Location'] == '../../booking.php?resume=1', 'interrupted booking uses allowlisted return')
        check(state(browser)['session']['booking_resume_marker'] is True and 'post_auth_destination' not in state(browser)['session'], 'resume marker is server-issued and one-time')
        for number, destination in enumerate(['https://evil.invalid', '//evil.invalid', 'booking.php?redirect=https://evil.invalid']):
            email = 'redirect' + str(number) + '@example.invalid'
            browser, code = create(email)
            state(browser, '?destination=' + urllib.parse.quote(destination))
            check(verify(browser, email, code)[1]['Location'] == '../../user_dashboard.php', 'reject untrusted redirect ' + destination)
        browser = client()
        result = request(browser, 'actions/auth/login_process.php', {'email': 'one@example.invalid', 'password': 'Test1!password', 'login_type': 'customer', 'csrf_token': state(browser)['session']['csrf_token']})
        check(result[1]['Location'] == '../../user_dashboard.php' and state(browser)['session']['logged_in'] is True, 'normal existing login unchanged')
        browser, code = create('limit@example.invalid')
        for _ in range(5):
            verify(browser, 'limit@example.invalid', '000000' if code != '000000' else '111111')
        verify(browser, 'limit@example.invalid', code)
        check(not state(browser)['session'].get('logged_in'), 'valid code cannot bypass exhausted attempts')
        for role, status in [('staff', 'active'), ('customer', 'suspended')]:
            email = role + '@example.invalid'
            browser, code = create(email)
            query("UPDATE users SET role='" + role + "',status='" + status + "' WHERE email='" + email + "'")
            verify(browser, email, code)
            check(not state(browser)['session'].get('logged_in'), role + '/' + status + ' cannot sign in through signup')
        (app / 'fail-sms').touch()
        query('DELETE FROM rate_limits; DELETE FROM contact_throttles')
        delivery = client()
        result = request(delivery, 'actions/auth/register_process.php', dict(first_name='Synthetic', last_name='Failure', email='delivery@example.invalid', phone='09179999998', password='Test1!password', confirm_password='Test1!password', consent='1', csrf_token=state(delivery)['session']['csrf_token']))
        check(result[0] == 302 and '?verify_email=' in result[1]['Location'], 'delivery failure keeps the pending account on code entry')
        html = request(delivery, 'auth.php?verify_email=delivery%40example.invalid')[2]
        check('showAlert(' not in html and 'code could not be sent' in html and 'verification-code' in html, 'delivery warning is accessible inline')
        user_id = int(query("SELECT id FROM users WHERE email='delivery@example.invalid'"))
        target = query('SELECT phone_e164 FROM customer_verified_phones WHERE user_id=' + str(user_id))
        code_hash = hmac.new(('synthetic-key-' * 4).encode(), (str(user_id) + '\0signup_phone\0' + target + '\0' + '123456').encode(), hashlib.sha256).hexdigest()
        query("UPDATE contact_challenges SET code_hash='" + code_hash + "' WHERE user_id=" + str(user_id))
        verify(delivery, 'delivery@example.invalid', '123456')
        check(not state(delivery)['session'].get('logged_in'), 'provider-rejected code cannot authenticate')
        (app / 'fail-sms').unlink()
        browser, code = create('state-change@example.invalid')
        (app / 'suspend-after-verify').touch()
        verify(browser, 'state-change@example.invalid', code)
        check(not state(browser)['session'].get('logged_in'), 'suspension after OTP commit prevents session creation')
        (app / 'suspend-after-verify').unlink()
        browser, code = create('failure@example.invalid')
        query('DROP TABLE customers')
        verify(browser, 'failure@example.invalid', code)
        check(not state(browser)['session'].get('logged_in'), 'transaction failure never authenticates')
        check(query("SELECT is_verified FROM users WHERE email='failure@example.invalid'") == '0', 'verification failure rolls back activation')
        print(str(CHECKS) + ' HTTP/database checks passed; delivery transport stubbed.', flush=True)
    finally:
        if server is not None:
            server.terminate()
            server.wait(timeout=5)
        db.terminate()
        db.wait(timeout=10)
        log.close()
