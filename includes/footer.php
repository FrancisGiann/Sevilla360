<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-col">
                <h4 style="font-family: var(--font-heading);">SEVILLA360</h4>
                <p>Experience minimal luxury and warm scandinavian comfort in every stay.</p>
            </div>
            <div class="footer-col">
                <h4>Quick Links</h4>
                <a href="index.php#accommodations">Accommodations</a>
                <a href="showroom.php">Virtual Showroom</a>
                <a href="index.php#events">Event Spaces</a>
            </div>
            <div class="footer-col">
                <h4>Support</h4>
                <a href="#">Contact Us</a>
                <a href="#">FAQ</a>
                <a href="#">Booking Policy</a>
            </div>
            <div class="footer-col">
                <h4>Connect</h4>
                <a href="#">Instagram</a>
                <a href="#">Facebook</a>
                <a href="#">Twitter</a>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date("Y"); ?> SEVILLA360. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Global Scripts (Nav Menu etc) -->
<script src="assets/js/index.js?v=<?php echo time(); ?>"></script>

<!-- Page Specific Script (Loads dynamically) -->
<?php if (isset($extra_js) && !empty($extra_js) && $extra_js !== 'assets/js/index.js'): ?>
<script src="<?php echo $extra_js; ?>"></script>
<?php endif; ?>

</body>

</html>