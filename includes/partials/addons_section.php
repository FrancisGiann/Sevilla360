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
                    <input type="radio" name="catering-tier" value="750" checked>
                    <div class="tier-header">
                        <h4>Silver Tier</h4>
                    </div>
                    <p class="tier-desc">Standard Buffet</p>
                    <span class="tier-price">₱750 / head</span>
                    <ul class="tier-menu">
                        <li>1 Soup, 1 Salad</li>
                        <li>3 Main Courses</li>
                        <li>1 Dessert, Iced Tea</li>
                    </ul>
                </label>
                <label class="tier-card">
                    <input type="radio" name="catering-tier" value="1200">
                    <div class="tier-header">
                        <h4>Gold Tier</h4>
                    </div>
                    <p class="tier-desc">Premium Course</p>
                    <span class="tier-price">₱1,200 / head</span>
                    <ul class="tier-menu">
                        <li>Premium Soup & Salad</li>
                        <li>4 Main Courses</li>
                        <li>2 Desserts, Drinks</li>
                    </ul>
                </label>
                <label class="tier-card">
                    <input type="radio" name="catering-tier" value="1800">
                    <div class="tier-header">
                        <h4>Platinum Tier</h4>
                    </div>
                    <p class="tier-desc">Luxury Dining</p>
                    <span class="tier-price">₱1,800 / head</span>
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
        <label class="toggle-label">
            <input type="checkbox" id="check-rooms"> Reserve Hotel Rooms
        </label>
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
        <label class="toggle-label"><input type="checkbox" id="check-av"> Premium A/V Setup</label>
    </div>
</div>