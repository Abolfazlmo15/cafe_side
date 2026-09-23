// assets/js/client/review.js – Interactive order review on thank_you.php
// =========================================================================
// Responsibilities:
//   1. Click on item row → expand it, collapse any other open row.
//   2. Click +/-/delete in expanded row → update state, update DOM, update total.
//   3. Click Confirm → serialize final cart into hidden form, submit.
//   4. Row removed via animation; if cart empties, bounce back to menu.
// =========================================================================

(function () {
    'use strict';

    // ---- Bootstrap data from PHP ----------------------------------------
    var data = window.__REVIEW__;
    if (!data || typeof data !== 'object') return;

    // Local state. Source of truth while the user interacts.
    var state = {
        table: parseInt(data.table, 10) || 0,
        items: Object.assign({}, data.items || {}),   // { id: qty }
        menu:  Object.assign({}, data.menu  || {})    // { id: { name, price } }
    };

    // ---- DOM refs -------------------------------------------------------
    var itemsList    = document.getElementById('itemsList');
    var totalDisplay = document.getElementById('totalDisplay');
    var confirmBtn   = document.getElementById('confirmBtn');
    var confirmForm  = document.getElementById('confirmForm');
    var confirmItems = document.getElementById('confirmItemsJson');
    var confirmNote  = document.getElementById('confirmNote');
    var noteInput    = document.getElementById('reviewNote');

    if (!itemsList) return; // empty review — nothing to wire up

    // ---- Helpers --------------------------------------------------------

    function formatPrice(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function getPrice(id) {
        var m = state.menu[String(id)] || state.menu[id];
        return m ? (parseInt(m.price, 10) || 0) : 0;
    }

    function recalcTotal() {
        var sum = 0;
        Object.keys(state.items).forEach(function (id) {
            var qty = parseInt(state.items[id], 10) || 0;
            sum += getPrice(id) * qty;
        });
        totalDisplay.textContent = formatPrice(sum);

        // Disable Confirm if cart is empty.
        var isEmpty = Object.keys(state.items).length === 0;
        confirmBtn.disabled = isEmpty;
    }

    function updateRowDom(id) {
        var row = itemsList.querySelector('.item-row[data-id="' + id + '"]');
        if (!row) return;
        var qty   = parseInt(state.items[id], 10) || 0;
        var price = getPrice(id);

        var qtyEl    = row.querySelector('.qty-value');
        var ctrlQty  = row.querySelector('.ctrl-qty');
        var subtotal = row.querySelector('.subtotal-value');

        if (qtyEl)    qtyEl.textContent    = qty;
        if (ctrlQty)  ctrlQty.textContent  = qty;
        if (subtotal) subtotal.textContent = formatPrice(qty * price);
    }

    function removeRow(id, immediate) {
        var row = itemsList.querySelector('.item-row[data-id="' + id + '"]');
        if (!row) return;

        if (immediate) {
            row.remove();
            return;
        }

        // Collapse first so the remove animation doesn't jump.
        row.classList.remove('expanded');
        row.classList.add('removing');
        setTimeout(function () {
            row.remove();
        }, 300);
    }

    function toggleRow(row) {
        var wasExpanded = row.classList.contains('expanded');

        // Collapse every other open row.
        itemsList.querySelectorAll('.item-row.expanded').forEach(function (r) {
            r.classList.remove('expanded');
        });

        if (!wasExpanded) row.classList.add('expanded');
    }

    function handleControl(btn) {
        var row = btn.closest('.item-row');
        if (!row) return;

        var id = parseInt(row.dataset.id, 10);
        if (!id || !state.items[id]) return;

        if (btn.classList.contains('ctrl-plus')) {
            state.items[id] = Math.min(99, state.items[id] + 1);
            updateRowDom(id);
            recalcTotal();

        } else if (btn.classList.contains('ctrl-minus')) {
            var next = state.items[id] - 1;
            if (next <= 0) {
                delete state.items[id];
                removeRow(id);
                recalcTotal();
                bounceIfEmpty();
            } else {
                state.items[id] = next;
                updateRowDom(id);
                recalcTotal();
            }

        } else if (btn.classList.contains('ctrl-delete')) {
            delete state.items[id];
            removeRow(id);
            recalcTotal();
            bounceIfEmpty();
        }
    }

    function bounceIfEmpty() {
        if (Object.keys(state.items).length === 0) {
            setTimeout(function () {
                window.location.href = 'menu.php?table=' + state.table;
            }, 500);
        }
    }

    // ---- Event handling (single delegated listener) ---------------------

    itemsList.addEventListener('click', function (e) {
        // Controls take priority over row toggle.
        var ctrl = e.target.closest('.ctrl-btn');
        if (ctrl) {
            e.stopPropagation();
            handleControl(ctrl);
            return;
        }

        var row = e.target.closest('.item-row');
        if (!row) return;
        toggleRow(row);
    });

    // Prevent double-toggle when the controls row itself is clicked.
    itemsList.addEventListener('mousedown', function (e) {
        if (e.target.closest('.item-controls')) e.preventDefault();
    });

    // ---- Confirm handler ------------------------------------------------

    confirmBtn.addEventListener('click', function () {
        var clean = {};
        Object.keys(state.items).forEach(function (id) {
            var qty = parseInt(state.items[id], 10) || 0;
            if (qty > 0) clean[String(id)] = qty;
        });

        if (Object.keys(clean).length === 0) {
            alert('Your order is empty. Add items from the menu.');
            return;
        }

        confirmItems.value = JSON.stringify(clean);
        confirmNote.value  = noteInput ? noteInput.value : '';
        confirmForm.submit();
    });

    // ---- Init -----------------------------------------------------------
    recalcTotal();

})();