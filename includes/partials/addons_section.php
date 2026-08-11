<?php
// Fetch system settings safely
if (!isset($sys_settings)) {
    $sys_query = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    $sys_settings = [];
    if ($sys_query) {
        while($r = $sys_query->fetch_assoc()) $sys_settings[$r['setting_key']] = $r['setting_value'];
    }
}
$cat_silv = $sys_settings['catering_silver'] ?? 750;
$cat_gold = $sys_settings['catering_gold'] ?? 1200;
$cat_plat = $sys_settings['catering_platinum'] ?? 1800;
$av_setup = $sys_settings['av_setup'] ?? 5000;
?>

<!-- Enhance Your Event (Shared Add-ons Partial) -->
<div class="addons-section">
    <h4 class="addon-title">Enhance Your Event</h4>

    <!-- CATERING ADD-ON -->
    <div class="addon-block">
        <label class="toggle-label">
            <input type="checkbox" id="check-catering"> Include Catering
        </label>
        <div class="addon-content hidden" id="catering-options">
            <div class="tier-cards">
                <label class="tier-card">
                    <input type="radio" name="catering-tier" value="<?php echo $cat_silv; ?>" checked>
                    <div class="tier-header">
                        <h4>Silver Tier</h4>
                    </div>
                    <p class="tier-desc">Standard Buffet</p>
                    <span class="tier-price">₱<?php echo number_format($cat_silv); ?> / head</span>
                    <ul class="tier-menu">
                        <li>1 Soup, 1 Salad</li>
                        <li>3 Main Courses</li>
                        <li>1 Dessert, Iced Tea</li>
                    </ul>
                </label>
                <label class="tier-card">
                    <input type="radio" name="catering-tier" value="<?php echo $cat_gold; ?>">
                    <div class="tier-header">
                        <h4>Gold Tier</h4>
                    </div>
                    <p class="tier-desc">Premium Course</p>
                    <span class="tier-price">₱<?php echo number_format($cat_gold); ?> / head</span>
                    <ul class="tier-menu">
                        <li>Premium Soup & Salad</li>
                        <li>4 Main Courses</li>
                        <li>2 Desserts, Drinks</li>
                    </ul>
                </label>
                <label class="tier-card">
                    <input type="radio" name="catering-tier" value="<?php echo $cat_plat; ?>">
                    <div class="tier-header">
                        <h4>Platinum Tier</h4>
                    </div>
                    <p class="tier-desc">Luxury Dining</p>
                    <span class="tier-price">₱<?php echo number_format($cat_plat); ?> / head</span>
                    <ul class="tier-menu">
                        <li>Gourmet Appetizers</li>
                        <li>5 Main Courses</li>
                        <li>Dessert Buffet & Wine</li>
                    </ul>
                </label>
            </div>
            <div class="form-group" style="margin-top: 1.5rem;">
                <label for="catering-notes">Catering Notes / Special Requests</label>
                <textarea id="catering-notes" placeholder="e.g., Peanut allergies, vegetarian meals for 5 pax..."
                    rows="3"></textarea>
            </div>
        </div>
    </div>

    <!-- HOTEL ROOMS ADD-ON -->
    <div class="addon-block">
        <label class="toggle-label"><input type="checkbox" id="check-rooms"> Reserve Hotel Rooms</label>
        <div class="addon-content hidden" id="rooms-options">
            <div class="mix-match">
                <div class="mix-row">
                    <div class="mix-info">
                        <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=150"
                            alt="Deluxe">
                        <div>
                            <h5>Deluxe Room</h5>
                            <p>2 Pax | ₱4,500 / night</p>
                        </div>
                    </div>
                    <div class="counter">
                        <button type="button" class="btn-minus" data-target="qty-deluxe">-</button>
                        <span class="val" id="qty-deluxe">0</span>
                        <button type="button" class="btn-plus" data-target="qty-deluxe">+</button>
                    </div>
                </div>
                <div class="mix-row">
                    <div class="mix-info">
                        <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=150"
                            alt="VIP">
                        <div>
                            <h5>VIP Suite</h5>
                            <p>4 Pax | ₱8,500 / night</p>
                        </div>
                    </div>
                    <div class="counter">
                        <button type="button" class="btn-minus" data-target="qty-vip">-</button>
                        <span class="val" id="qty-vip">0</span>
                        <button type="button" class="btn-plus" data-target="qty-vip">+</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- A/V SETUP ADD-ON -->
    <div class="addon-block">
        <!-- ADDED DYNAMIC VALUE DIRECTLY TO CHECKBOX -->
        <label class="toggle-label"><input type="checkbox" id="check-av" value="<?php echo $av_setup; ?>"> Premium A/V
            Setup (+₱<?php echo number_format($av_setup); ?>)</label>
    </div>
</div>