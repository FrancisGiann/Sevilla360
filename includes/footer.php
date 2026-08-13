<!-- Footer -->
<footer class="idx-footer">
    <div class="idx-footer-inner">
        <div class="idx-footer-grid">
            <div class="idx-footer-brand">
                <span>Sevilla360</span>
                <p>M.I. Sevilla Resort &amp; Events Place — a private estate for weddings,
                    celebrations and quiet stays.</p>
            </div>

            <nav class="idx-footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="index.php#about">About</a></li>
                    <li><a href="index.php#experiences">Experiences</a></li>
                    <li><a href="index.php#accommodations">Venues</a></li>
                    <li><a href="showroom.php">Virtual Showroom</a></li>
                </ul>
            </nav>

            <nav class="idx-footer-col">
                <h4>Support</h4>
                <ul>
                    <li><a href="#">Contact Us</a></li>
                    <li><a href="#">Booking Policy</a></li>
                    <li><a href="#">FAQs</a></li>
                    <li><a href="#">Privacy</a></li>
                    <li><a href="#">Terms</a></li>
                </ul>
            </nav>

            <nav class="idx-footer-col">
                <h4>Connect</h4>
                <ul>
                    <li><a href="#">Facebook</a></li>
                    <li><a href="#">Instagram</a></li>
                    <li><a href="#">TikTok</a></li>
                    <li><a href="#">Email Us</a></li>
                </ul>
            </nav>
        </div>

        <div class="idx-footer-bottom">
            &copy; <?php echo date("Y"); ?> M.I. Sevilla Resort &amp; Events Place. All rights reserved.
        </div>
    </div>
</footer>

<!-- Global Scripts (Nav Menu etc) -->
<script src="assets/js/global_modals.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/index.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/calendar.js?v=<?php echo time(); ?>"></script>

<!-- Page Specific Script (Loads dynamically) -->
<?php if (isset($extra_js) && !empty($extra_js) && $extra_js !== 'assets/js/index.js'): ?>
<script src="<?php echo $extra_js; ?>"></script>
<?php endif; ?>

</body>

</html>