/**
 * SEVILLA360 - Admin Audit Log Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('auditSearch');
  const tableRows = document.querySelectorAll('#auditTable tbody tr');

  if (searchInput && tableRows) {
    // Listen to keystrokes in the search bar
    searchInput.addEventListener('keyup', function(e) {
      const searchTerm = e.target.value.toLowerCase();

      // Loop through all table rows
      tableRows.forEach(row => {
        // Get the entire text content of the row
        const rowText = row.textContent.toLowerCase();

        // Check if row text includes the search term
        if (rowText.includes(searchTerm)) {
          // Show row
          row.style.display = '';
        } else {
          // Hide row
          row.style.display = 'none';
        }
      });
    });
  }
});