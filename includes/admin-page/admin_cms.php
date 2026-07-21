<!-- CMS Media Container -->
<div class="cms-container">

    <!-- Top Toolbar Section -->
    <div class="cms-toolbar">
        <!-- Left: Filter Pills -->
        <div class="cms-filters">
            <button class="cms-pill active">All Media</button>
            <button class="cms-pill">360 Showroom</button>
            <button class="cms-pill">Hotel Rooms</button>
            <button class="cms-pill">Event Hall</button>
            <button class="cms-pill">Amenities</button>
        </div>

        <!-- Right: Controls -->
        <div class="cms-controls">
            <div class="cms-search-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="cmsSearch" placeholder="Search media...">
            </div>
            <button class="btn btn-primary" id="btnOpenUpload">+ Upload Media</button>
        </div>
    </div>

    <!-- Media Grid -->
    <div class="cms-grid">

        <!-- Card 1: The Main Background Image -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Hero Background">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Landing Page - Hero Banner</h4>
                    <span class="badge badge-gray">Homepage Section</span>
                </div>
                <p class="cms-size">Current File: hero_bg_v2.jpg</p>
                <div class="cms-actions">
                    <!-- Notice we removed the "Delete" button because we ALWAYS need a hero image. We only allow Replace. -->
                    <button class="btn-replace btn-cms-modal">Replace Image</button>
                </div>
            </div>
        </div>

        <!-- Card 2: The Event Hall Section -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1519167758481-83f550bb49b3?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Event Hall Section">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Explore & Reserve - Event Hall</h4>
                    <span class="badge badge-gray">Homepage Section</span>
                </div>
                <p class="cms-size">Current File: event_hall_main.jpg</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace Image</button>
                </div>
            </div>
        </div>

        <!-- Card 3 -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1582719478250-c89404bb8a0e?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Resort Villa 360">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Luxury Resort Villa</h4>
                    <span class="badge badge-gold">360 Panorama</span>
                </div>
                <p class="cms-size">File Size: 6.1 MB</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace</button>
                    <button class="btn-delete">Delete</button>
                </div>
            </div>
        </div>

        <!-- Card 4 -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1576013551627-11dc5f67e4f1?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Main Pool">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Main Pool Area</h4>
                    <span class="badge badge-gray">Standard Image</span>
                </div>
                <p class="cms-size">File Size: 2.4 MB</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace</button>
                    <button class="btn-delete">Delete</button>
                </div>
            </div>
        </div>

        <!-- Card 5 -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Standard Room">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Standard Room View</h4>
                    <span class="badge badge-gray">Standard Image</span>
                </div>
                <p class="cms-size">File Size: 1.5 MB</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace</button>
                    <button class="btn-delete">Delete</button>
                </div>
            </div>
        </div>

        <!-- Card 6 -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1517457210609-b427b3b4dcb8?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Event Hall Setup">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Wedding Setup</h4>
                    <span class="badge badge-gray">Standard Image</span>
                </div>
                <p class="cms-size">File Size: 3.1 MB</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace</button>
                    <button class="btn-delete">Delete</button>
                </div>
            </div>
        </div>

        <!-- Card 7 -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1542314831-c6a4d74c93f4?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Amenities">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Spa & Wellness Center</h4>
                    <span class="badge badge-gold">360 Panorama</span>
                </div>
                <p class="cms-size">File Size: 4.8 MB</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace</button>
                    <button class="btn-delete">Delete</button>
                </div>
            </div>
        </div>

        <!-- Card 8 -->
        <div class="cms-card">
            <div class="cms-img-wrapper">
                <img src="https://images.unsplash.com/photo-1445019980597-93fa8acb246c?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                    alt="Resort Front">
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">Resort Exterior</h4>
                    <span class="badge badge-gray">Standard Image</span>
                </div>
                <p class="cms-size">File Size: 2.9 MB</p>
                <div class="cms-actions">
                    <button class="btn-replace btn-cms-modal">Replace</button>
                    <button class="btn-delete">Delete</button>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ==============================================
     UPLOAD / REPLACE MODAL
     ============================================== -->
<div class="cms-modal-overlay" id="uploadModal">
    <div class="cms-modal-content">
        <h3 class="cms-modal-title">Upload Website Media</h3>

        <form class="cms-form" id="cms-upload-form">

            <!-- Drag & Drop Area -->
            <div class="cms-drag-drop" id="dragDropArea">
                <i class="fa-solid fa-cloud-arrow-up drop-icon"></i>
                <p class="drop-text"><strong>Drag and drop</strong> images here<br>or <span class="highlight">Click to
                        browse</span></p>
                <span class="drop-hint">Accepts JPG/PNG (Max 15MB for 360, 5MB for Standard)</span>
                <input type="file" id="fileInput" accept="image/jpeg, image/png" hidden>
            </div>

            <!-- Dropdown 1: Media Type -->
            <div class="cms-form-group">
                <label>Media Type</label>
                <select name="media_type" required>
                    <option value="" disabled selected>Select media type...</option>
                    <option value="standard">Standard Photo</option>
                    <option value="360">360 Panorama</option>
                </select>
            </div>

            <!-- Dropdown 2: Website Slot Assignment -->
            <div class="cms-form-group">
                <label>Assign to Website Slot</label>
                <select required name="website_slot">
                    <option value="" disabled selected>Select where this image goes...</option>
                    <optgroup label="Homepage">
                        <option value="home-hero">Landing Page - Hero Banner</option>
                        <option value="home-eventhall">Explore & Reserve - Event Hall</option>
                        <option value="home-villa">Explore & Reserve - Villa</option>
                    </optgroup>
                    <optgroup label="Virtual Showroom (360)">
                        <option value="360-eventhall">360 View - Event Hall</option>
                        <option value="360-villa">360 View - Villa</option>
                    </optgroup>
                    <optgroup label="General Gallery">
                        <option value="gallery">General Media / Unassigned</option>
                    </optgroup>
                </select>
            </div>

            <div class="cms-modal-actions">
                <button type="button" class="btn cms-btn-outline" id="btnCloseModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>