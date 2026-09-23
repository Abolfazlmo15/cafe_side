// assets/js/admin/qr-vue.js – Vue 3 admin QR page
// ==================================================
// Phase 4.2: Vue-rendered table, modal editing, no reloads, version cursor.

function startQrApp() {
    if (typeof Vue === 'undefined') {
        console.error('[qr-vue] Vue 3 not loaded');
        return;
    }

    const { createApp, reactive, computed } = Vue;
    const initial = window.__QR_DATA__ || {};

    const POLL = {
        baseMs:     5000,
        initialMs:  2000,
        maxMs:      60000,
        multiplier: 2,
    };

    const store = reactive({
        version:       initial.version || 0,
        tables:        (initial.data && initial.data.tables) || [],
        maxTables:     (initial.data && initial.data.max_tables) || 20,
        activeCount:   (initial.data && initial.data.active_count) || 0,
        inactiveCount: (initial.data && initial.data.inactive_count) || 0,
        existingCount: (initial.data && initial.data.existing_count) || 0,
        nextAvailable: (initial.data && initial.data.next_available) || null,

        editModalOpen: false,
        editForm: emptyEditForm(),
        editError: '',
        editSaving: false,
        editSnapshot: null,

        addConfirmOpen: false,
        addInFlight: false,

        deleteTarget: null,
        deleteInFlight: false,

        showDiscardConfirm: false,

        previewImageSrc: null,

        message: null,

        polling: {
            inFlight:          false,
            failures:          0,
            currentIntervalMs: POLL.baseMs,
            timerId:           null,
            isVisible:         typeof document !== 'undefined' ? !document.hidden : true,
        },
    });

    window.__QR_STORE__ = store;

    function emptyEditForm() {
        return {
            tableNumber:      0,
            qrUrl:            '',
            imageFile:        null,
            imagePreview:     null,
            hasExistingImage: false,
            existingThumb:    null,
        };
    }

    function showMessage(text, type) {
        store.message = { text, type };
        setTimeout(() => {
            if (store.message && store.message.text === text) store.message = null;
        }, 3500);
    }

    function serialiseEditForm(f) {
        return JSON.stringify({
            tableNumber: f.tableNumber,
            qrUrl:       f.qrUrl,
            hasNewImage: !!f.imageFile,
        });
    }

    function applyData(data) {
        if (!data) return;
        if (Array.isArray(data.tables))               store.tables = data.tables;
        if (typeof data.max_tables === 'number')      store.maxTables = data.max_tables;
        if (typeof data.active_count === 'number')    store.activeCount = data.active_count;
        if (typeof data.inactive_count === 'number')  store.inactiveCount = data.inactive_count;
        if (typeof data.existing_count === 'number')  store.existingCount = data.existing_count;
        store.nextAvailable = data.next_available;
    }

    const editFormDirty = computed(() => {
        if (!store.editSnapshot) return false;
        return serialiseEditForm(store.editForm) !== store.editSnapshot;
    });

    function openEditModal(table) {
        store.editForm = {
            tableNumber:      table.table_number,
            qrUrl:            table.qr_url,
            imageFile:        null,
            imagePreview:     null,
            hasExistingImage: table.has_image,
            existingThumb:    table.thumb,
        };
        store.editError = '';
        store.editModalOpen = true;
        store.editSnapshot = serialiseEditForm(store.editForm);
        document.body.style.overflow = 'hidden';
    }

    function closeEditModal() {
        if (editFormDirty.value) {
            store.showDiscardConfirm = true;
            return;
        }
        reallyCloseEditModal();
    }

    function reallyCloseEditModal() {
        store.showDiscardConfirm = false;
        store.editModalOpen = false;
        store.editForm = emptyEditForm();
        store.editError = '';
        store.editSnapshot = null;
        document.body.style.overflow = '';
    }

    function cancelDiscard() {
        store.showDiscardConfirm = false;
    }

    function confirmDiscard() {
        reallyCloseEditModal();
    }

    function onEditFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;
        store.editForm.imageFile = file;
        const reader = new FileReader();
        reader.onload = (ev) => { store.editForm.imagePreview = ev.target.result; };
        reader.readAsDataURL(file);
    }

    function clearEditImageSelection() {
        store.editForm.imageFile = null;
        store.editForm.imagePreview = null;
    }

    async function saveEdit() {
        const f = store.editForm;
        if (!f.qrUrl.trim()) {
            store.editError = 'QR URL is required.';
            return;
        }

        store.editError = '';
        store.editSaving = true;

        const fd = new FormData();
        fd.append('table_number', String(f.tableNumber));
        fd.append('qr_url', f.qrUrl.trim());
        if (f.imageFile) fd.append('qr_image', f.imageFile);

        try {
            const res = await fetch('qr.php?api=qr_save', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.error) {
                store.editError = data.error;
                return;
            }
            reallyCloseEditModal();
            showMessage('QR saved for Table #' + data.table_number + '.', 'success');
            await refreshData();
        } catch (err) {
            console.error('[qr-vue] save failed:', err);
            store.editError = 'Network error. Please try again.';
        } finally {
            store.editSaving = false;
        }
    }

    function requestAdd() {
        if (store.nextAvailable === null) {
            showMessage('No available table slots.', 'error');
            return;
        }
        store.addConfirmOpen = true;
        document.body.style.overflow = 'hidden';
    }

    function cancelAdd() {
        store.addConfirmOpen = false;
        document.body.style.overflow = '';
    }

    async function confirmAdd() {
        store.addInFlight = true;
        try {
            const res = await fetch('qr.php?api=qr_add', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.error) {
                showMessage(data.error, 'error');
                return;
            }
            store.addConfirmOpen = false;
            document.body.style.overflow = '';
            showMessage('QR row added for Table #' + data.table_number + '.', 'success');
            await refreshData();
        } catch (err) {
            console.error('[qr-vue] add failed:', err);
            showMessage('Network error. Please try again.', 'error');
        } finally {
            store.addInFlight = false;
        }
    }

    function requestDelete(table) {
        store.deleteTarget = { tableNumber: table.table_number };
        document.body.style.overflow = 'hidden';
    }

    function cancelDelete() {
        store.deleteTarget = null;
        document.body.style.overflow = '';
    }

    async function confirmDelete() {
        if (!store.deleteTarget) return;
        const num = store.deleteTarget.tableNumber;
        store.deleteInFlight = true;

        const fd = new FormData();
        fd.append('table_number', String(num));

        try {
            const res = await fetch('qr.php?api=qr_delete', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.error) {
                showMessage(data.error, 'error');
                return;
            }
            store.deleteTarget = null;
            document.body.style.overflow = '';
            showMessage('QR record deleted for Table #' + num + '.', 'success');
            await refreshData();
        } catch (err) {
            console.error('[qr-vue] delete failed:', err);
            showMessage('Network error. Please try again.', 'error');
        } finally {
            store.deleteInFlight = false;
        }
    }

    function openLightbox(src) {
        if (!src) return;
        store.previewImageSrc = src;
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        store.previewImageSrc = null;
        const anyModal = store.editModalOpen || store.addConfirmOpen || store.deleteTarget || store.showDiscardConfirm;
        document.body.style.overflow = anyModal ? 'hidden' : '';
    }

    function stopPolling() {
        if (store.polling.timerId !== null) {
            clearTimeout(store.polling.timerId);
            store.polling.timerId = null;
        }
    }

    function scheduleNext(delayMs) {
        stopPolling();
        store.polling.timerId = setTimeout(runPollCycle, delayMs);
    }

    function runPollCycle() {
        store.polling.timerId = null;
        pollServer().finally(() => {
            if (store.polling.isVisible) {
                scheduleNext(store.polling.currentIntervalMs);
            }
        });
    }

    async function refreshData() {
        try {
            const url = 'qr.php?api=poll&version=' + encodeURIComponent(store.version) + '&_=' + Date.now();
            const res = await fetch(url);
            if (!res.ok) return;
            const data = await res.json();
            store.polling.failures = 0;
            store.polling.currentIntervalMs = POLL.baseMs;
            if (data.unchanged) return;
            if (typeof data.version === 'number') store.version = data.version;
            applyData(data.data);
        } catch (err) {
            console.warn('[qr-vue] refresh failed:', err);
        }
    }

    function pollServer() {
        if (store.polling.inFlight) return Promise.resolve();
        store.polling.inFlight = true;

        const url = 'qr.php?api=poll&version=' + encodeURIComponent(store.version) + '&_=' + Date.now();

        return fetch(url)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => {
                store.polling.failures = 0;
                store.polling.currentIntervalMs = POLL.baseMs;
                if (data.unchanged) return;
                if (typeof data.version === 'number') store.version = data.version;
                applyData(data.data);
            })
            .catch(err => {
                store.polling.failures++;
                const backoff = POLL.baseMs * Math.pow(POLL.multiplier, store.polling.failures);
                store.polling.currentIntervalMs = Math.min(backoff, POLL.maxMs);
                console.warn(
                    '[qr-vue] Poll failed (attempt ' + store.polling.failures + ') — '
                    + 'next retry in ' + store.polling.currentIntervalMs + 'ms:',
                    err
                );
            })
            .finally(() => { store.polling.inFlight = false; });
    }

    document.addEventListener('visibilitychange', () => {
        const nowVisible = !document.hidden;
        if (nowVisible === store.polling.isVisible) return;
        store.polling.isVisible = nowVisible;
        if (nowVisible) {
            store.polling.failures = 0;
            store.polling.currentIntervalMs = POLL.baseMs;
            runPollCycle();
        } else {
            stopPolling();
        }
    });

    const ReconnectingIndicator = {
        template: '<div v-if="show" class="reconnecting-indicator"><i class="fas fa-circle-notch fa-spin"></i><span>Reconnecting…</span></div>',
        setup() {
            const show = computed(() => store.polling.failures >= 2);
            return { show };
        }
    };

    const MessageBanner = {
        template: '<div v-if="store.message" class="qr-message" :class="\'qr-message-\' + store.message.type"><i :class="store.message.type === \'success\' ? \'fas fa-check-circle\' : \'fas fa-exclamation-circle\'"></i><span>{{ store.message.text }}</span><button type="button" class="qr-message-close" @click="store.message = null" aria-label="Close">&times;</button></div>',
        setup() { return { store }; }
    };

    const StatsHeader = {
        template: [
            '<div class="qr-header">',
            '  <div class="qr-stats">',
            '    <span><i class="fas fa-chair"></i> Max: <strong>{{ store.maxTables }}</strong></span>',
            '    <span><i class="fas fa-check-circle" style="color:#22c55e;"></i> Active: <strong>{{ store.activeCount }}</strong></span>',
            '    <span><i class="fas fa-circle" style="color:#f59e0b;"></i> Inactive: <strong>{{ store.inactiveCount }}</strong></span>',
            '    <span><i class="fas fa-database"></i> Existing: <strong>{{ store.existingCount }}</strong></span>',
            '  </div>',
            '  <div class="qr-actions">',
            '    <button type="button" class="btn-add" @click="add" :disabled="store.nextAvailable === null">',
            '      <i class="fas fa-plus"></i> {{ store.nextAvailable === null ? \'No slots available\' : \'Add New QR\' }}',
            '    </button>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function add() { requestAdd(); }
            return { store, add };
        }
    };

    const QrTable = {
        template: [
            '<div class="qr-table-wrap">',
            '  <table class="qr-table">',
            '    <thead>',
            '      <tr>',
            '        <th style="width:110px;">Table #</th>',
            '        <th>QR URL</th>',
            '        <th style="width:100px;">Image</th>',
            '        <th style="width:160px;">Actions</th>',
            '      </tr>',
            '    </thead>',
            '    <tbody>',
            '      <tr v-if="store.tables.length === 0">',
            '        <td colspan="4" class="empty-row">',
            '          <i class="fas fa-inbox"></i>',
            '          <span>No QR codes added yet. Click "Add New QR" to get started.</span>',
            '        </td>',
            '      </tr>',
            '      <tr v-for="t in store.tables" :key="t.table_number" :data-table="t.table_number">',
            '        <td class="cell-table-num">',
            '          <strong>#{{ t.table_number }}</strong>',
            '          <span class="status-tag" :class="t.has_image ? \'status-active\' : \'status-inactive\'">',
            '            {{ t.has_image ? \'● Active\' : \'○ Inactive\' }}',
            '          </span>',
            '        </td>',
            '        <td class="cell-url"><code>{{ t.qr_url }}</code></td>',
            '        <td>',
            '          <div v-if="t.has_image && t.thumb" class="qr-thumb" @click="openLightbox(t.image)">',
            '            <img :src="t.thumb" :alt="\'QR for table \' + t.table_number">',
            '          </div>',
            '          <span v-else class="no-image"><i class="fas fa-image"></i> none</span>',
            '        </td>',
            '        <td>',
            '          <div class="qr-actions-cell">',
            '            <button type="button" class="btn-action btn-action-edit" @click="edit(t)" :data-tooltip="\'Edit table #\' + t.table_number" aria-label="Edit">',
            '              <i class="fas fa-pen"></i>',
            '            </button>',
            '            <a v-if="t.has_image" :href="t.image" :download="\'table_\' + t.table_number + \'.png\'" class="btn-action btn-action-download" :data-tooltip="\'Download QR image\'" aria-label="Download">',
            '              <i class="fas fa-download"></i>',
            '            </a>',
            '            <button type="button" class="btn-action btn-action-delete" @click="remove(t)" :data-tooltip="\'Delete table #\' + t.table_number" aria-label="Delete">',
            '              <i class="fas fa-trash"></i>',
            '            </button>',
            '          </div>',
            '        </td>',
            '      </tr>',
            '    </tbody>',
            '  </table>',
            '</div>'
        ].join('\n'),
        setup() {
            function edit(t) { openEditModal(t); }
            function remove(t) { requestDelete(t); }
            function openLightbox(src) { openImageLightbox(src); }
            return { store, edit, remove, openLightbox };
        }
    };

    function openImageLightbox(src) { openLightbox(src); }

    const EditModal = {
        template: [
            '<div v-if="store.editModalOpen" class="item-modal active" @click.self="close">',
            '  <div class="item-modal-content">',
            '    <button class="item-modal-close" @click="close" aria-label="Close">&times;</button>',
            '    <h2><i class="fas fa-pen"></i> Edit Table #{{ store.editForm.tableNumber }}</h2>',
            '    <div v-if="store.editError" class="form-error">',
            '      <i class="fas fa-exclamation-circle"></i> {{ store.editError }}',
            '    </div>',
            '    <form @submit.prevent="save">',
            '      <div class="form-group">',
            '        <label>QR URL *</label>',
            '        <input type="text" v-model="store.editForm.qrUrl" required>',
            '        <small class="form-help">The URL customers land on when they scan this QR code.</small>',
            '      </div>',
            '      <div class="form-group">',
            '        <label>Upload New Image (PNG or JPG)</label>',
            '        <div class="file-input-wrap">',
            '          <input type="file" accept=".png,.jpg,.jpeg" @change="onFile" ref="fileInput">',
            '        </div>',
            '        <div class="image-preview-row">',
            '          <div v-if="store.editForm.hasExistingImage && store.editForm.existingThumb && !store.editForm.imagePreview" class="image-preview-box">',
            '            <span class="preview-label">Current:</span>',
            '            <img :src="store.editForm.existingThumb" alt="Current QR">',
            '          </div>',
            '          <div v-if="store.editForm.imagePreview" class="image-preview-box">',
            '            <span class="preview-label">New:</span>',
            '            <img :src="store.editForm.imagePreview" alt="New QR preview">',
            '            <button type="button" class="btn-clear-image" @click="clearImage" aria-label="Clear image">',
            '              <i class="fas fa-times"></i>',
            '            </button>',
            '          </div>',
            '        </div>',
            '      </div>',
            '      <div class="modal-actions">',
            '        <button type="button" class="btn-cancel" @click="close" :disabled="store.editSaving">Cancel</button>',
            '        <button type="submit" class="btn-save" :disabled="store.editSaving">',
            '          <i :class="store.editSaving ? \'fas fa-circle-notch fa-spin\' : \'fas fa-save\'"></i>',
            '          {{ store.editSaving ? \'Saving…\' : \'Save Changes\' }}',
            '        </button>',
            '      </div>',
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function close() { closeEditModal(); }
            function save() { saveEdit(); }
            function onFile(e) { onEditFileSelect(e); }
            function clearImage() { clearEditImageSelection(); }
            return { store, close, save, onFile, clearImage };
        }
    };

    const AddConfirm = {
        template: [
            '<div v-if="store.addConfirmOpen" class="item-modal active" @click.self="cancel">',
            '  <div class="item-modal-content item-modal-small">',
            '    <div class="add-icon"><i class="fas fa-plus-circle"></i></div>',
            '    <h2>Add New QR?</h2>',
            '    <p class="confirm-text">',
            '      This will create a new QR record for <strong>Table #{{ store.nextAvailable }}</strong> with an auto-generated URL. You can edit it right after.',
            '    </p>',
            '    <div class="modal-actions">',
            '      <button type="button" class="btn-cancel" @click="cancel" :disabled="store.addInFlight">Cancel</button>',
            '      <button type="button" class="btn-save" @click="confirm" :disabled="store.addInFlight">',
            '        <i :class="store.addInFlight ? \'fas fa-circle-notch fa-spin\' : \'fas fa-plus\'"></i>',
            '        {{ store.addInFlight ? \'Adding…\' : \'Add QR\' }}',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function cancel() { cancelAdd(); }
            function confirm() { confirmAdd(); }
            return { store, cancel, confirm };
        }
    };

    const DiscardChangesConfirm = {
        template: [
            '<div v-if="store.showDiscardConfirm" class="item-modal active" @click.self="cancel">',
            '  <div class="item-modal-content item-modal-small">',
            '    <div class="discard-icon"><i class="fas fa-exclamation-triangle"></i></div>',
            '    <h2>Discard Changes?</h2>',
            '    <p class="discard-text">You have unsaved changes. If you close now, everything you\'ve edited will be lost.</p>',
            '    <div class="modal-actions">',
            '      <button type="button" class="btn-cancel" @click="cancel">Keep Editing</button>',
            '      <button type="button" class="btn-discard-confirm" @click="confirm"><i class="fas fa-trash"></i> Discard</button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function cancel() { cancelDiscard(); }
            function confirm() { confirmDiscard(); }
            return { store, cancel, confirm };
        }
    };

    const DeleteConfirm = {
        template: [
            '<div v-if="store.deleteTarget" class="item-modal active" @click.self="cancel">',
            '  <div class="item-modal-content item-modal-small">',
            '    <div class="delete-icon"><i class="fas fa-exclamation-triangle"></i></div>',
            '    <h2>Delete QR Record?</h2>',
            '    <p class="delete-text">',
            '      This will permanently delete the QR record for <strong>Table #{{ store.deleteTarget.tableNumber }}</strong> and its image file. This can\'t be undone.',
            '    </p>',
            '    <div class="modal-actions">',
            '      <button type="button" class="btn-cancel" @click="cancel" :disabled="store.deleteInFlight">Cancel</button>',
            '      <button type="button" class="btn-delete-confirm" @click="confirm" :disabled="store.deleteInFlight">',
            '        <i :class="store.deleteInFlight ? \'fas fa-circle-notch fa-spin\' : \'fas fa-trash\'"></i>',
            '        {{ store.deleteInFlight ? \'Deleting…\' : \'Delete\' }}',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function cancel() { cancelDelete(); }
            function confirm() { confirmDelete(); }
            return { store, cancel, confirm };
        }
    };

    const ImageLightbox = {
        template: '<div v-if="src" class="image-lightbox active" @click.self="close"><button class="image-lightbox-close" @click="close" aria-label="Close">&times;</button><img :src="src" alt="QR Code full view"></div>',
        setup() {
            const src = computed(() => store.previewImageSrc);
            function close() { closeLightbox(); }
            return { src, close };
        }
    };

    const QrApp = {
        components: {
            ReconnectingIndicator,
            MessageBanner,
            StatsHeader,
            QrTable,
            EditModal,
            AddConfirm,
            DiscardChangesConfirm,
            DeleteConfirm,
            ImageLightbox,
        },
        template: '<div><ReconnectingIndicator></ReconnectingIndicator><MessageBanner></MessageBanner><StatsHeader></StatsHeader><QrTable></QrTable><EditModal></EditModal><AddConfirm></AddConfirm><DiscardChangesConfirm></DiscardChangesConfirm><DeleteConfirm></DeleteConfirm><ImageLightbox></ImageLightbox></div>'
    };

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (store.previewImageSrc)      { closeLightbox();    return; }
        if (store.deleteTarget)         { cancelDelete();     return; }
        if (store.addConfirmOpen)       { cancelAdd();        return; }
        if (store.showDiscardConfirm)   { cancelDiscard();    return; }
        if (store.editModalOpen)        { closeEditModal(); }
    });

    const mountEl = document.getElementById('vue-qr-root');
    if (mountEl) {
        try {
            createApp(QrApp).mount('#vue-qr-root');
            console.log('[qr-vue] Mounted. Version:', store.version, 'Tables:', store.tables.length);
        } catch (err) {
            console.error('[qr-vue] Mount failed:', err);
        }
    }

    scheduleNext(POLL.initialMs);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startQrApp);
} else {
    startQrApp();
}