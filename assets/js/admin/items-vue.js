// assets/js/admin/items-vue.js – Vue 3 admin items page
// =======================================================
// Phase 4.1: modal add/edit, no reload, version cursor, visibility-aware polling.
// Update: unsaved-changes warning on modal close.
// Update: icon-only action buttons matching qr page.

function startItemsApp() {
    if (typeof Vue === 'undefined') {
        console.error('[items-vue] Vue 3 not loaded');
        return;
    }

    const { createApp, reactive, computed } = Vue;
    const initial = window.__ITEMS_DATA__ || {};

    const POLL = {
        baseMs:     5000,
        initialMs:  2000,
        maxMs:      60000,
        multiplier: 2,
    };

    const store = reactive({
        version:    initial.version || 0,
        items:      initial.items || [],
        categories: initial.categories || [],

        searchQuery: '',

        modalOpen: false,
        modalMode: 'add',
        form: emptyForm(),
        formError: '',
        formSaving: false,
        formSnapshot: null,

        showDiscardConfirm: false,

        deleteTarget: null,
        deleteInFlight: false,

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

    window.__ITEMS_STORE__ = store;

    function emptyForm() {
        return {
            id: 0,
            name: '',
            price: '',
            category: '',
            description: '',
            available: 1,
            sort_order: 0,
            imageFile: null,
            imagePreview: null,
        };
    }

    function formatPrice(n) {
        return ('' + n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function showMessage(text, type) {
        store.message = { text, type };
        setTimeout(() => {
            if (store.message && store.message.text === text) store.message = null;
        }, 3500);
    }

    function serialiseForm(f) {
        return JSON.stringify({
            id:          f.id,
            name:        f.name,
            price:       String(f.price),
            category:    f.category,
            description: f.description,
            available:   f.available,
            sort_order:  f.sort_order,
            hasNewImage: !!f.imageFile,
        });
    }

    function snapshotForm() {
        return serialiseForm(store.form);
    }

    const filteredItems = computed(() => {
        const q = store.searchQuery.trim().toLowerCase();
        if (!q) return store.items;
        return store.items.filter(it =>
            it.name.toLowerCase().includes(q)
            || (it.description || '').toLowerCase().includes(q)
            || it.category.toLowerCase().includes(q)
        );
    });

    const formDirty = computed(() => {
        if (!store.formSnapshot) return false;
        return serialiseForm(store.form) !== store.formSnapshot;
    });

    function openAddModal() {
        store.form = emptyForm();
        store.formError = '';
        store.modalMode = 'add';
        store.modalOpen = true;
        store.formSnapshot = snapshotForm();
        document.body.style.overflow = 'hidden';
    }

    function openEditModal(item) {
        store.form = {
            id: item.id,
            name: item.name,
            price: item.price,
            category: item.category,
            description: item.description || '',
            available: item.available ? 1 : 0,
            sort_order: item.sort_order || 0,
            imageFile: null,
            imagePreview: item.image || null,
        };
        store.formError = '';
        store.modalMode = 'edit';
        store.modalOpen = true;
        store.formSnapshot = snapshotForm();
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        if (formDirty.value) {
            store.showDiscardConfirm = true;
            return;
        }
        reallyCloseModal();
    }

    function reallyCloseModal() {
        store.showDiscardConfirm = false;
        store.modalOpen = false;
        store.form = emptyForm();
        store.formError = '';
        store.formSnapshot = null;
        document.body.style.overflow = '';
    }

    function cancelDiscard() {
        store.showDiscardConfirm = false;
    }

    function confirmDiscard() {
        reallyCloseModal();
    }

    function onFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;
        store.form.imageFile = file;
        const reader = new FileReader();
        reader.onload = (ev) => { store.form.imagePreview = ev.target.result; };
        reader.readAsDataURL(file);
    }

    function clearImageSelection() {
        store.form.imageFile = null;
        store.form.imagePreview = null;
    }

    function openLightbox(src) {
        if (!src) return;
        store.previewImageSrc = src;
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        store.previewImageSrc = null;
        document.body.style.overflow = store.modalOpen ? 'hidden' : '';
    }

    async function saveItem() {
        const f = store.form;
        if (!f.name.trim()) { store.formError = 'Name is required.'; return; }
        const priceNum = parseFloat(f.price);
        if (isNaN(priceNum) || priceNum <= 0) { store.formError = 'Price must be a number greater than 0.'; return; }
        if (!f.category.trim()) { store.formError = 'Category is required.'; return; }

        store.formError = '';
        store.formSaving = true;

        const fd = new FormData();
        fd.append('id', String(f.id));
        fd.append('name', f.name.trim());
        fd.append('price', String(priceNum));
        fd.append('category', f.category.trim());
        fd.append('description', f.description.trim() || 'Served with love');
        fd.append('available', String(f.available));
        fd.append('sort_order', String(f.sort_order));
        if (f.imageFile) fd.append('image', f.imageFile);

        try {
            const res = await fetch('items.php?api=item_save', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.error) {
                store.formError = data.error;
                return;
            }
            reallyCloseModal();
            showMessage(data.action === 'add' ? 'Item added.' : 'Item updated.', 'success');
            await refreshItems();
        } catch (err) {
            console.error('[items-vue] save failed:', err);
            store.formError = 'Network error. Please try again.';
        } finally {
            store.formSaving = false;
        }
    }

    function requestDelete(item) {
        store.deleteTarget = { id: item.id, name: item.name };
    }

    function cancelDelete() {
        store.deleteTarget = null;
    }

    async function confirmDelete() {
        if (!store.deleteTarget) return;
        const id = store.deleteTarget.id;
        store.deleteInFlight = true;

        const fd = new FormData();
        fd.append('id', String(id));

        try {
            const res = await fetch('items.php?api=item_delete', {
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
            showMessage('Item deleted.', 'success');
            await refreshItems();
        } catch (err) {
            console.error('[items-vue] delete failed:', err);
            showMessage('Network error. Please try again.', 'error');
        } finally {
            store.deleteInFlight = false;
        }
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

    async function refreshItems() {
        try {
            const url = 'items.php?api=poll'
                + '&version=' + encodeURIComponent(store.version)
                + '&_=' + Date.now();
            const res = await fetch(url);
            if (!res.ok) return;
            const data = await res.json();
            store.polling.failures = 0;
            store.polling.currentIntervalMs = POLL.baseMs;
            if (data.unchanged) return;
            if (typeof data.version === 'number') store.version = data.version;
            if (Array.isArray(data.items))      store.items = data.items;
            if (Array.isArray(data.categories)) store.categories = data.categories;
        } catch (err) {
            console.warn('[items-vue] refresh failed:', err);
        }
    }

    function pollServer() {
        if (store.polling.inFlight) return Promise.resolve();
        store.polling.inFlight = true;

        const url = 'items.php?api=poll'
            + '&version=' + encodeURIComponent(store.version)
            + '&_=' + Date.now();

        return fetch(url)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => {
                store.polling.failures = 0;
                store.polling.currentIntervalMs = POLL.baseMs;

                if (data.unchanged) return;
                if (typeof data.version === 'number') store.version = data.version;
                if (Array.isArray(data.items))      store.items = data.items;
                if (Array.isArray(data.categories)) store.categories = data.categories;
            })
            .catch(err => {
                store.polling.failures++;
                const backoff = POLL.baseMs * Math.pow(POLL.multiplier, store.polling.failures);
                store.polling.currentIntervalMs = Math.min(backoff, POLL.maxMs);
                console.warn(
                    '[items-vue] Poll failed (attempt ' + store.polling.failures + ') — '
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
        template: '<div v-if="store.message" class="items-message" :class="\'items-message-\' + store.message.type"><i :class="store.message.type === \'success\' ? \'fas fa-check-circle\' : \'fas fa-exclamation-circle\'"></i><span>{{ store.message.text }}</span><button type="button" class="items-message-close" @click="store.message = null" aria-label="Close">&times;</button></div>',
        setup() { return { store }; }
    };

    const Toolbar = {
        template: '<div class="items-toolbar"><div class="toolbar-search"><i class="fas fa-search"></i><input type="text" placeholder="Search items…" v-model="store.searchQuery"></div><div class="toolbar-actions"><button type="button" class="btn-add" @click="openAdd"><i class="fas fa-plus"></i> Add New Item</button></div></div>',
        setup() {
            function openAdd() { openAddModal(); }
            return { store, openAdd };
        }
    };

    const ItemsTable = {
        template: [
            '<div class="items-table-wrap">',
            '  <table class="items-table">',
            '    <thead>',
            '      <tr>',
            '        <th style="width:70px;">Image</th>',
            '        <th>Name</th>',
            '        <th>Description</th>',
            '        <th style="width:110px;">Price (T)</th>',
            '        <th style="width:130px;">Category</th>',
            '        <th style="width:70px;">Sort</th>',
            '        <th style="width:90px;">Status</th>',
            '        <th style="width:120px;">Actions</th>',
            '      </tr>',
            '    </thead>',
            '    <tbody>',
            '      <tr v-if="filteredItems.length === 0">',
            '        <td colspan="8" class="empty-row">',
            '          <i class="fas fa-inbox"></i>',
            '          <span v-if="store.items.length === 0">No items yet. Add one to get started.</span>',
            '          <span v-else>No items match your search.</span>',
            '        </td>',
            '      </tr>',
            '      <tr v-for="item in filteredItems" :key="item.id" :data-id="item.id">',
            '        <td>',
            '          <div v-if="item.image" class="image-cell" @click="openLightbox(item.image)">',
            '            <img :src="item.image" :alt="item.name">',
            '          </div>',
            '          <div v-else class="image-cell image-placeholder">',
            '            <i class="fas fa-image"></i>',
            '          </div>',
            '        </td>',
            '        <td class="cell-name">',
            '          <span class="item-name-link" @click="edit(item)">{{ item.name }}</span>',
            '        </td>',
            '        <td class="cell-desc">{{ item.description || \'—\' }}</td>',
            '        <td>{{ formatPrice(item.price) }}</td>',
            '        <td>{{ item.category }}</td>',
            '        <td>{{ item.sort_order }}</td>',
            '        <td>',
            '          <span class="badge" :class="item.available ? \'badge-active\' : \'badge-inactive\'">',
            '            {{ item.available ? \'Active\' : \'Inactive\' }}',
            '          </span>',
            '        </td>',
            '        <td>',
            '          <div class="items-actions-cell">',
            '            <button type="button" class="btn-action btn-action-edit" @click="edit(item)" :data-tooltip="\'Edit \' + item.name" aria-label="Edit">',
            '              <i class="fas fa-pen"></i>',
            '            </button>',
            '            <button type="button" class="btn-action btn-action-delete" @click="remove(item)" :data-tooltip="\'Delete \' + item.name" aria-label="Delete">',
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
            function edit(item) { openEditModal(item); }
            function remove(item) { requestDelete(item); }
            function openLightbox(src) { openImageLightbox(src); }
            return { store, filteredItems, formatPrice, edit, remove, openLightbox };
        }
    };

    function openImageLightbox(src) { openLightbox(src); }

    const ItemModal = {
        template: [
            '<div v-if="store.modalOpen" class="item-modal active" @click.self="close">',
            '  <div class="item-modal-content">',
            '    <button class="item-modal-close" @click="close" aria-label="Close">&times;</button>',
            '    <h2>',
            '      <i :class="store.modalMode === \'add\' ? \'fas fa-plus-circle\' : \'fas fa-pen\'"></i>',
            '      {{ store.modalMode === \'add\' ? \'Add New Item\' : \'Edit Item\' }}',
            '    </h2>',
            '    <div v-if="store.formError" class="form-error">',
            '      <i class="fas fa-exclamation-circle"></i> {{ store.formError }}',
            '    </div>',
            '    <form @submit.prevent="save">',
            '      <div class="form-row">',
            '        <div class="form-group" style="flex:2;">',
            '          <label>Item Name *</label>',
            '          <input type="text" v-model="store.form.name" required maxlength="100">',
            '        </div>',
            '        <div class="form-group" style="flex:1;">',
            '          <label>Price (Tomans) *</label>',
            '          <input type="number" v-model="store.form.price" step="1" min="0" required>',
            '        </div>',
            '      </div>',
            '      <div class="form-row">',
            '        <div class="form-group" style="flex:1;">',
            '          <label>Category *</label>',
            '          <input type="text" v-model="store.form.category" list="item-category-options" placeholder="Type or pick one" autocomplete="off" required>',
            '          <datalist id="item-category-options">',
            '            <option v-for="cat in store.categories" :key="cat" :value="cat"></option>',
            '          </datalist>',
            '          <small class="form-help">Start typing to see suggestions from existing categories.</small>',
            '        </div>',
            '        <div class="form-group" style="flex:1;">',
            '          <label>Sort Order</label>',
            '          <input type="number" v-model="store.form.sort_order" min="0">',
            '          <small class="form-help">0 = alphabetical. &gt;0 = custom position.</small>',
            '        </div>',
            '      </div>',
            '      <div class="form-group">',
            '        <label>Description</label>',
            '        <textarea v-model="store.form.description" rows="2" placeholder="e.g. Served with love" maxlength="300"></textarea>',
            '      </div>',
            '      <div class="form-row">',
            '        <div class="form-group">',
            '          <label>Available?</label>',
            '          <select v-model.number="store.form.available">',
            '            <option :value="1">Yes — visible on menu</option>',
            '            <option :value="0">No — hidden</option>',
            '          </select>',
            '        </div>',
            '      </div>',
            '      <div class="form-group">',
            '        <label>Image (PNG or JPG)</label>',
            '        <div class="file-input-wrap">',
            '          <input type="file" accept=".png,.jpg,.jpeg" @change="onFile" ref="fileInput">',
            '        </div>',
            '        <div v-if="store.form.imagePreview" class="image-preview">',
            '          <span class="preview-label">Preview:</span>',
            '          <img :src="store.form.imagePreview" alt="Preview">',
            '          <button type="button" class="btn-clear-image" @click="clearImage" aria-label="Clear image">',
            '            <i class="fas fa-times"></i>',
            '          </button>',
            '        </div>',
            '      </div>',
            '      <div class="modal-actions">',
            '        <button type="button" class="btn-cancel" @click="close" :disabled="store.formSaving">Cancel</button>',
            '        <button type="submit" class="btn-save" :disabled="store.formSaving">',
            '          <i :class="store.formSaving ? \'fas fa-circle-notch fa-spin\' : \'fas fa-save\'"></i>',
            '          {{ store.formSaving ? \'Saving…\' : (store.modalMode === \'add\' ? \'Add Item\' : \'Save Changes\') }}',
            '        </button>',
            '      </div>',
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function close() { closeModal(); }
            function save() { saveItem(); }
            function onFile(e) { onFileSelect(e); }
            function clearImage() { clearImageSelection(); }
            return { store, close, save, onFile, clearImage };
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
            '    <h2>Delete Item?</h2>',
            '    <p class="delete-text">This will permanently delete <strong>{{ store.deleteTarget.name }}</strong> and its image file. This can\'t be undone.</p>',
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
        template: '<div v-if="src" class="image-lightbox active" @click.self="close"><button class="image-lightbox-close" @click="close" aria-label="Close">&times;</button><img :src="src" alt="Preview"></div>',
        setup() {
            const src = computed(() => store.previewImageSrc);
            function close() { closeLightbox(); }
            return { src, close };
        }
    };

    const ItemsApp = {
        components: {
            ReconnectingIndicator,
            MessageBanner,
            Toolbar,
            ItemsTable,
            ItemModal,
            DiscardChangesConfirm,
            DeleteConfirm,
            ImageLightbox,
        },
        template: '<div><ReconnectingIndicator></ReconnectingIndicator><MessageBanner></MessageBanner><Toolbar></Toolbar><ItemsTable></ItemsTable><ItemModal></ItemModal><DiscardChangesConfirm></DiscardChangesConfirm><DeleteConfirm></DeleteConfirm><ImageLightbox></ImageLightbox></div>'
    };

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (store.previewImageSrc)      { closeLightbox();   return; }
        if (store.deleteTarget)         { cancelDelete();    return; }
        if (store.showDiscardConfirm)   { cancelDiscard();   return; }
        if (store.modalOpen)            { closeModal(); }
    });

    const mountEl = document.getElementById('vue-items-root');
    if (mountEl) {
        try {
            createApp(ItemsApp).mount('#vue-items-root');
            console.log('[items-vue] Mounted. Version:', store.version, 'Items:', store.items.length);
        } catch (err) {
            console.error('[items-vue] Mount failed:', err);
        }
    }

    scheduleNext(POLL.initialMs);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startItemsApp);
} else {
    startItemsApp();
}