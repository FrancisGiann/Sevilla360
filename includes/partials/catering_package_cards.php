<?php foreach ($cateringPackages as $package): ?>
<label class="tier-card">
    <input type="radio" name="catering-tier"
        value="<?php echo htmlspecialchars($package['rate'], ENT_QUOTES, 'UTF-8'); ?>"
        data-package-id="<?php echo htmlspecialchars($package['package_id'], ENT_QUOTES, 'UTF-8'); ?>"
        <?php echo $package['key'] === 'silver' ? 'checked' : ''; ?>>
    <div class="tier-header">
        <h4><?php echo htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8'); ?></h4>
    </div>
    <span class="tier-price">₱<?php echo htmlspecialchars(catering_package_format_rate($package['rate']), ENT_QUOTES, 'UTF-8'); ?> / pax</span>
    <ul class="tier-menu">
        <?php foreach ($package['menu'] as $item): ?>
        <li><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
    </ul>
</label>
<?php endforeach; ?>
