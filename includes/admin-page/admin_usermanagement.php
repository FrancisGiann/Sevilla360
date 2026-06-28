<!-- User Management Container -->
<div class="um-container">

    <!-- Top Header Section (Tabs & Controls) -->
    <div class="um-header">
        <div class="um-tabs">
            <button class="um-tab active" data-target="staffTable">Staff Accounts</button>
            <button class="um-tab" data-target="customerTable">Customer Accounts</button>
        </div>

        <div class="um-controls">
            <div class="um-search-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" placeholder="Search accounts...">
            </div>
            <button class="btn btn-primary" id="openAddStaffBtn">+ Add New Staff</button>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="um-card">

        <!-- STAFF TABLE (Default Active) -->
        <table class="um-table active" id="staffTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Isabella Rossi</td>
                    <td>isabella@sevilla360.com</td>
                    <td>Super Admin</td>
                    <td><span class="um-pill pill-active">Active</span></td>
                    <td class="um-actions">
                        <button class="action-edit btn-staff-modal">Edit</button>
                        <button class="action-delete">Delete</button>
                    </td>
                </tr>
                <tr>
                    <td>Marcus Thorne</td>
                    <td>marcus@sevilla360.com</td>
                    <td>Admin</td>
                    <td><span class="um-pill pill-active">Active</span></td>
                    <td class="um-actions">
                        <button class="action-edit btn-staff-modal">Edit</button>
                        <button class="action-delete">Delete</button>
                    </td>
                </tr>
                <tr>
                    <td>Elena Vance</td>
                    <td>elena.v@sevilla360.com</td>
                    <td>Admin</td>
                    <td><span class="um-pill pill-inactive">Inactive</span></td>
                    <td class="um-actions">
                        <button class="action-edit btn-staff-modal">Edit</button>
                        <button class="action-delete">Delete</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- CUSTOMER TABLE (Hidden by Default) -->
        <table class="um-table" id="customerTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Total Bookings</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Julian Sterling</td>
                    <td>j.sterling@example.com</td>
                    <td>4</td>
                    <td class="um-actions">
                        <button class="action-view btn-history-modal">View History</button>
                    </td>
                </tr>
                <tr>
                    <td>Sophia Laurent</td>
                    <td>slaurent92@example.com</td>
                    <td>1</td>
                    <td class="um-actions">
                        <button class="action-view btn-history-modal">View History</button>
                    </td>
                </tr>
                <tr>
                    <td>David Chen</td>
                    <td>david.c.business@example.com</td>
                    <td>7</td>
                    <td class="um-actions">
                        <button class="action-view btn-history-modal">View History</button>
                    </td>
                </tr>
                <tr>
                    <td>Amelia Hart</td>
                    <td>amelia.hart@example.com</td>
                    <td>2</td>
                    <td class="um-actions">
                        <button class="action-view btn-history-modal">View History</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ==============================================
     MODALS 
     ============================================== -->

<!-- Add/Edit Staff Modal -->
<div class="um-modal-overlay" id="staffModal">
    <div class="um-modal-content">
        <h3 class="um-modal-title">Staff Account</h3>
        <form class="um-form">
            <div class="um-form-group">
                <label>Full Name</label>
                <input type="text" placeholder="Enter full name" required>
            </div>
            <div class="um-form-group">
                <label>Email Address</label>
                <input type="email" placeholder="Enter email address" required>
            </div>
            <div class="um-form-group">
                <label>Password</label>
                <input type="password" placeholder="Enter password (leave blank to keep current)">
            </div>
            <div class="um-form-group">
                <label>Assign Role</label>
                <select required>
                    <option value="admin">Admin</option>
                    <option value="superadmin">Super Admin</option>
                </select>
            </div>
            <div class="um-modal-actions">
                <button type="button" class="btn btn-outline close-staff-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Customer History Modal -->
<div class="um-modal-overlay" id="historyModal">
    <div class="um-modal-content um-modal-large">
        <h3 class="um-modal-title">Booking History</h3>

        <div class="um-history-table-wrapper">
            <table class="um-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Amount Spent</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Oct 12, 2023</td>
                        <td>Grand Ballroom</td>
                        <td>$4,500.00</td>
                    </tr>
                    <tr>
                        <td>Jun 05, 2023</td>
                        <td>Garden Pavilion</td>
                        <td>$2,200.00</td>
                    </tr>
                    <tr>
                        <td>Dec 18, 2022</td>
                        <td>Rooftop Lounge</td>
                        <td>$1,850.00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="um-modal-actions">
            <button type="button" class="btn btn-outline close-history-modal">Close</button>
        </div>
    </div>
</div>