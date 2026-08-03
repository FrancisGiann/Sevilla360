/**
 * SEVILLA360 - Admin Audit Log Scripts
 * Handles Smart Text Search and Date Filtering
 */

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('auditSearch');
    const dateInput = document.getElementById('auditDate');
    const tableRows = document.querySelectorAll('#auditTable tbody tr');
  
    function filterTable() {
        if (!tableRows) return;

        // 1. Get the search terms and split them by spaces (e.g. "Francis Bookings" -> ["francis", "bookings"])
        const searchString = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const searchTerms = searchString.split(/\s+/); 
        const dateTerm = dateInput ? dateInput.value : ''; // Format: YYYY-MM-DD
  
        tableRows.forEach(row => {
            // Skip the "No logs found" row
            if (row.querySelector("td[colspan]")) return;

            const rowText = row.textContent.toLowerCase();
            const rowDate = row.getAttribute('data-date') || '';
  
            // 2. SMART SEARCH: Check if ALL typed words exist anywhere in the row
            let matchesText = true;
            if (searchString !== '') {
                for (let i = 0; i < searchTerms.length; i++) {
                    if (!rowText.includes(searchTerms[i])) {
                        matchesText = false;
                        break; // If even one word is missing, hide the row
                    }
                }
            }

            // 3. Check Date
            const matchesDate = (dateTerm === '') || (rowDate === dateTerm);
  
            // 4. If it matches BOTH the date and ALL search terms, show it
            if (matchesText && matchesDate) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
  
    // Listen to keystrokes in the search bar
    if (searchInput) {
        searchInput.addEventListener('input', filterTable); // Changed from keyup to input (handles copy/paste better)
    }

    // Listen to changes in the date picker
    if (dateInput) {
        dateInput.addEventListener('change', filterTable);
    }

    // --- EXPORT TO CSV LOGIC ---
    const exportBtn = document.getElementById('btnExportCSV');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            let csv = [];
            const rows = document.querySelectorAll('#auditTable tr');
            
            for (let i = 0; i < rows.length; i++) {
                let row = rows[i];
                if (row.style.display === 'none' || row.querySelector("td[colspan]")) continue;
                
                let cols = row.querySelectorAll('td, th');
                let rowArray = [];
                
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                    data = data.replace(/"/g, '""');
                    rowArray.push('"' + data + '"');
                }
                csv.push(rowArray.join(','));
            }
            
            const csvString = csv.join('\n');
            const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', `Sevilla360_Audit_Log_${new Date().toLocaleDateString('en-CA')}.csv`);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
});