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

// $hotel_room_groups must be set by the parent page (booking.php or admin_walkin.php)
// Each entry: {building_name, room_type, nightly_rate, base_capacity, total_inventory, image}
$addon_room_groups = $hotel_room_groups ?? [];
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

    <!-- HOTEL ROOMS ADD-ON (Real Inventory) -->
    <div class="addon-block">
        <label class="toggle-label"><input type="checkbox" id="check-rooms"> Reserve Hotel Rooms</label>
        <div class="addon-content hidden" id="rooms-options">
            <p class="addon-note">Choose one shared hotel stay for every room in this event add-on. Rates are per room per night.</p>
            <div class="addon-room-calendar">
                <div class="addon-room-calendar-heading">
                    <strong>Hotel check-in and check-out</strong>
                    <span id="addon-room-date-display">Select a stay of at least 1 night</span>
                </div>
                <?php $calendarId = 'cal-ui-addon-hotel'; include __DIR__ . '/booking_calendar.php'; ?>
            </div>

            <!-- Filter dropdown -->
            <?php 
                $unique_types = [];
                foreach($addon_room_groups as $grp) {
                    $r_type = $grp['room_type'];
                    if (!in_array($r_type, $unique_types)) {
                        $unique_types[] = $r_type;
                    }
                }
            ?>
            <?php if (!empty($unique_types)): ?>
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="addon-type-filter" style="font-size: 0.85rem; font-weight: 600;">Filter by Room Type:</label>
                <select id="addon-type-filter" class="form-control" style="max-width: 300px; padding: 6px; font-size: 0.9rem;">
                    <option value="all">All Room Types</option>
                    <?php foreach($unique_types as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Room group catalog: rendered from real DB data -->
            <div class="room-groups-catalog" id="room-groups-catalog">
                <?php foreach($addon_room_groups as $grp): 
                    $group_key = $grp['building_name'] . '|' . $grp['room_type'];
                    $safe_key  = htmlspecialchars($group_key);
                ?>
                <div class="room-group-card"
                    data-building="<?php echo htmlspecialchars($grp['building_name']); ?>"
                    data-room-type="<?php echo htmlspecialchars($grp['room_type']); ?>"
                    data-rate="<?php echo $grp['nightly_rate']; ?>"
                    data-capacity="<?php echo $grp['base_capacity']; ?>"
                    data-inventory="<?php echo $grp['total_inventory']; ?>"
                    data-group-key="<?php echo $safe_key; ?>"
                    style="display: flex; align-items: center; justify-content: space-between; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 10px; background: #fff;">
                    <div class="room-group-info" style="display: flex; align-items: center; gap: 12px;">
                        <img src="<?php echo htmlspecialchars($grp['image']); ?>"
                             alt="<?php echo htmlspecialchars($grp['building_name']); ?>"
                             class="room-group-thumb"
                             style="width: 80px; height: 60px; object-fit: cover; border-radius: 4px; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"
                             onerror="this.src='assets/img/placeholder.jpg'">
                        <div>
                            <strong style="font-size: 0.95rem; color: #333;"><?php echo htmlspecialchars($grp['building_name']); ?> — <?php echo htmlspecialchars($grp['room_type']); ?></strong>
                            <br>
                            <small style="color: #666;">₱<?php echo number_format($grp['nightly_rate']); ?>/night &nbsp;|&nbsp; <?php echo $grp['base_capacity']; ?> pax base</small>
                            <br>
                            <!-- Availability shown only after dates are locked via JS -->
                            <small class="room-avail-label" style="color:#888;">
                                (<?php echo $grp['total_inventory']; ?> total units — select dates to check availability)
                            </small>
                        </div>
                    </div>
                    <button type="button" class="btn-add-room-group"
                        data-group-key="<?php echo $safe_key; ?>"
                        style="padding: 8px 16px; background: var(--primary, #d6a870); color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; transition: 0.2s; white-space: nowrap;">+ Add</button>
                </div>
                <?php endforeach; ?>
                <?php if (empty($addon_room_groups)): ?>
                <p style="color:#aaa; font-size:0.9rem;">No hotel rooms available.</p>
                <?php endif; ?>
            </div>

            <!-- Selected room groups with quantity controls -->
            <div id="selected-room-groups" style="margin-top:12px;"></div>
        </div>
    </div>

    <!-- A/V SETUP ADD-ON -->
    <div class="addon-block">
        <!-- ADDED DYNAMIC VALUE DIRECTLY TO CHECKBOX -->
        <label class="toggle-label"><input type="checkbox" id="check-av" value="<?php echo $av_setup; ?>"> Premium A/V
            Setup (+₱<?php echo number_format($av_setup); ?>)</label>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const filter = document.getElementById("addon-type-filter");
    if (filter) {
        filter.addEventListener("change", function() {
            const selected = this.value;
            const cards = document.querySelectorAll(".room-group-card");
            cards.forEach(card => {
                if (selected === "all" || card.getAttribute("data-room-type") === selected) {
                    card.style.display = "flex";
                } else {
                    card.style.display = "none";
                }
            });
        });
    }
});
</script>
