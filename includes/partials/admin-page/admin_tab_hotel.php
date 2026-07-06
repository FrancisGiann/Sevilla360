<!-- HOTEL ROOMS TAB -->
<div class="tab-content" id="tab-hotel">
    <div class="calendar-ui" id="cal-ui-hotel">
        <div class="cal-header">
            <button class="cal-nav prev-month"><i class="fa-solid fa-arrow-left"></i></button>
            <h4 class="cal-month-year">Month Year</h4>
            <button class="cal-nav next-month"><i class="fa-solid fa-arrow-right"></i></button>
        </div>
        <div class="cal-weekdays">
            <span>SUN</span><span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span>SAT</span>
        </div>
        <div class="cal-days-grid"></div>
    </div>

    <div class="dynamic-img-wrapper">
        <img id="hotel-img"
            src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80"
            alt="Hotel Room">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label>Select Room Category</label>
            <select id="hotel-room-type">
                <option value="" disabled selected>Select category...</option>
                <?php foreach(array_keys($grouped_hotel_rooms) as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Select Specific Room</label>
            <!-- Disabled until they pick a category -->
            <select id="hotel-room-name" disabled>
                <option value="" disabled selected>Select category first...</option>
            </select>
        </div>
    </div>

    <div class="form-row" style="margin-top: 15px;">
        <div class="form-group">
            <label>Number of Guests <span class="extra-pax-note">Exceeding base: ₱800/head</span></label>
            <input type="number" id="hotel-guests" min="1" value="2">
            <small id="hotel-extra-fee" class="hidden">Extra Pax Fee: ₱0</small>
        </div>
    </div>

    <!-- Pass the grouped data to Javascript! -->
    <script>
    // We MUST attach it to 'window' so external JS files can access it!
    window.hotelRoomData = <?php echo json_encode($grouped_hotel_rooms); ?>;
    </script>
</div>