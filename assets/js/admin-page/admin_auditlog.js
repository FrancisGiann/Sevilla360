/**
 * SEVILLA360 - Admin Audit Log Scripts
 * Handles Real-time Text Search and Date Filtering
 */

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('auditSearch');
    const dateInput = document.getElementById('auditDate');
    const tableRows = document.querySelectorAll('#auditTable tbody tr');
  
    function filterTable() {
        if (!tableRows) return;

        const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
        const dateTerm = dateInput ? dateInput.value : ''; // Format: YYYY-MM-DD
  
        tableRows.forEach(row => {
            // Skip the "No logs found" row
            if (row.querySelector("td[colspan]")) return;

            const rowText = row.textContent.toLowerCase();
            const rowDate = row.getAttribute('data-date') || '';
  
            // Check Conditions
            const matchesText = rowText.includes(searchTerm);
            const matchesDate = (dateTerm === '') || (rowDate === dateTerm);
  
            // If it matches BOTH, show it
            if (matchesText && matchesDate) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
  
    // Listen to keystrokes in the search bar
    if (searchInput) {
        searchInput.addEventListener('keyup', filterTable);
    }

    // Listen to changes in the date picker
    if (dateInput) {
        dateInput.addEventListener('change', filterTable);
    }
});