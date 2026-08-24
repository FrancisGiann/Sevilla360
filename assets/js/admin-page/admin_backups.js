document.addEventListener("DOMContentLoaded", () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    async function parseBackupResponse(response) {
        const responseText = await response.text();
        let data = null;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            // Callers use the HTTP status when the server did not return JSON.
        }
        const rawMessage = data && typeof data.error === 'string'
            ? data.error
            : (data && typeof data.message === 'string' ? data.message : '');
        return {
            data,
            message: rawMessage.trim(),
            success: response.ok && !!data && data.success === true,
            fallback: `Server Error: ${response.status}`
        };
    }
    
    // --- SEARCH FUNCTIONALITY ---
    const searchInput = document.getElementById('backupSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#backupsTable tbody tr');
            
            rows.forEach(row => {
                if (row.children.length === 1) return; // Skip "No backups found" row
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    }

    // --- CREATE BACKUP ---
    const btnCreate = document.getElementById('btnCreateBackup');
    if (btnCreate) {
        btnCreate.addEventListener('click', async () => {
            const btnText = btnCreate.querySelector('.btn-text');
            const btnSpinner = btnCreate.querySelector('.btn-spinner');
            
            btnCreate.disabled = true;
            btnText.classList.add('hidden');
            btnSpinner.classList.remove('hidden');

            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);

                const res = await fetch('actions/admin/create_backup.php', {
                    method: 'POST',
                    body: formData
                });

                const parsed = await parseBackupResponse(res);

                if (parsed.success) {
                    if (window.showAlert) {
                        window.showAlert('Success', parsed.message || 'Backup created successfully!', 'success');
                    } else {
                        alert(parsed.message || 'Backup created successfully!');
                    }
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    const message = parsed.message || parsed.fallback;
                    if (window.showAlert) {
                        window.showAlert('Error', message, 'error');
                    } else {
                        alert(message);
                    }
                }
            } catch (err) {
                console.error(err);
                if (window.showAlert) window.showAlert('Error', 'A network error occurred.', 'error');
            } finally {
                btnCreate.disabled = false;
                btnText.classList.remove('hidden');
                btnSpinner.classList.add('hidden');
            }
        });
    }

    // --- IMPORT BACKUP ---
    const btnImport = document.getElementById('btnImportBackup');
    const importInput = document.getElementById('importBackupInput');
    
    if (btnImport && importInput) {
        btnImport.addEventListener('click', () => {
            importInput.click();
        });

        importInput.addEventListener('change', async function() {
            const file = this.files[0];
            if (!file) return;

            if (!file.name.endsWith('.sql')) {
                if (window.showAlert) window.showAlert('Error', 'Please select a valid .sql backup file.', 'error');
                this.value = ''; // Reset
                return;
            }

            const btnText = btnImport.querySelector('.btn-text');
            const btnSpinner = btnImport.querySelector('.btn-spinner');
            
            btnImport.disabled = true;
            btnText.classList.add('hidden');
            btnSpinner.classList.remove('hidden');

            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('sql_file', file);

                const res = await fetch('actions/admin/import_backup.php', {
                    method: 'POST',
                    body: formData
                });
                
                const parsed = await parseBackupResponse(res);
                
                if (parsed.success) {
                    if (window.showAlert) {
                        window.showAlert('Success', parsed.message || 'Backup imported successfully!', 'success');
                    } else {
                        alert(parsed.message || 'Backup imported successfully!');
                    }
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    const message = parsed.message || parsed.fallback;
                    if (window.showAlert) {
                        window.showAlert('Error', message, 'error');
                    } else {
                        alert(message);
                    }
                }
            } catch (err) {
                console.error(err);
                if (window.showAlert) window.showAlert('Error', 'A network error occurred.', 'error');
            } finally {
                btnImport.disabled = false;
                btnText.classList.remove('hidden');
                btnSpinner.classList.add('hidden');
                this.value = ''; // Reset file input
            }
        });
    }

    // --- DOWNLOAD BACKUP ---
    document.querySelectorAll('.btn-download').forEach(btn => {
        btn.addEventListener('click', function() {
            const filename = this.getAttribute('data-filename');
            window.location.href = `actions/admin/download_backup.php?file=${encodeURIComponent(filename)}`;
        });
    });

    // --- DELETE BACKUP ---
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.getAttribute('data-id');
            const filename = this.getAttribute('data-filename');
            
            if (window.showConfirm) {
                const confirmed = await window.showConfirm(
                    'Delete Backup?', 
                    `Are you sure you want to delete ${filename}? This cannot be undone.`
                );
                if (confirmed) {
                    await deleteBackup(id);
                }
            } else {
                if (confirm(`Are you sure you want to delete ${filename}?`)) {
                    await deleteBackup(id);
                }
            }
        });
    });

    async function deleteBackup(id) {
        try {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('csrf_token', csrfToken);

            const res = await fetch('actions/admin/delete_backup.php', {
                method: 'POST',
                body: formData
            });
            const parsed = await parseBackupResponse(res);
            
            if (parsed.success) {
                window.location.reload();
            } else {
                if (window.showAlert) window.showAlert('Error', parsed.message || parsed.fallback, 'error');
            }
        } catch (err) {
            console.error(err);
            if (window.showAlert) window.showAlert('Error', 'A network error occurred.', 'error');
        }
    }

    // --- RESTORE BACKUP MODAL LOGIC ---
    let targetRestoreId = null;
    let targetRestoreFilename = null;
    const restoreModal = document.getElementById('restoreModal');
    const restoreConfirmInput = document.getElementById('restoreConfirmInput');
    const btnConfirmRestore = document.getElementById('btnConfirmRestore');
    
    document.querySelectorAll('.btn-restore').forEach(btn => {
        btn.addEventListener('click', function() {
            targetRestoreId = this.getAttribute('data-id');
            targetRestoreFilename = this.getAttribute('data-filename');
            
            document.getElementById('restoreFilename').textContent = targetRestoreFilename;
            restoreConfirmInput.value = '';
            btnConfirmRestore.disabled = true;
            
            if (restoreModal) restoreModal.style.display = 'flex';
        });
    });
    
    // Check input match for RESTORE
    if (restoreConfirmInput && btnConfirmRestore) {
        restoreConfirmInput.addEventListener('input', function() {
            if (this.value === 'RESTORE') {
                btnConfirmRestore.disabled = false;
            } else {
                btnConfirmRestore.disabled = true;
            }
        });
    }

    // Cancel / Close Buttons
    const btnCancel = document.getElementById('cancelRestoreBtn');
    const btnClose = document.getElementById('closeRestoreModalBtn');
    
    if (btnCancel) {
        btnCancel.addEventListener('click', closeRestoreModal);
    }
    if (btnClose) {
        btnClose.addEventListener('click', closeRestoreModal);
    }

    // Process Restore
    if (btnConfirmRestore) {
        btnConfirmRestore.addEventListener('click', async () => {
            const btnText = btnConfirmRestore.querySelector('.btn-text');
            const btnSpinner = btnConfirmRestore.querySelector('.btn-spinner');
            
            btnConfirmRestore.disabled = true;
            btnText.classList.add('hidden');
            btnSpinner.classList.remove('hidden');

            try {
                const formData = new FormData();
                formData.append('id', targetRestoreId);
                formData.append('csrf_token', csrfToken);

                const res = await fetch('actions/admin/restore_backup.php', {
                    method: 'POST',
                    body: formData
                });
                
                const parsed = await parseBackupResponse(res);
                
                if (parsed.success) {
                    if (window.showAlert) {
                        window.showAlert('Success', 'Database restored successfully!', 'success');
                    } else {
                        alert('Database restored successfully!');
                    }
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    const message = parsed.message || parsed.fallback;
                    if (window.showAlert) {
                        window.showAlert('Error', message, 'error');
                    } else {
                        alert(message);
                    }
                }
            } catch (err) {
                console.error(err);
                if (window.showAlert) window.showAlert('Error', 'A network error occurred.', 'error');
            } finally {
                btnConfirmRestore.disabled = false;
                btnText.classList.remove('hidden');
                btnSpinner.classList.add('hidden');
                closeRestoreModal();
            }
        });
    }
});

// Global function to close restore modal
window.closeRestoreModal = function() {
    const modal = document.getElementById('restoreModal');
    if (modal) modal.style.display = 'none';
}
