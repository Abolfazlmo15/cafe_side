document.addEventListener('DOMContentLoaded', function() {
    // --- markReady function (global) ---
    window.markReady = function(orderId) {
        if (!confirm('Mark order #' + orderId + ' as ready?')) return;
        fetch('?mark_ready_ajax=1&id=' + orderId + '&_=' + Date.now())
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const row = document.querySelector('tr[data-id="' + orderId + '"]');
                    if (row) {
                        const badge = row.querySelector('.badge');
                        if (badge) {
                            badge.className = 'badge badge-ready';
                            badge.textContent = 'Ready';
                        }
                        const actionCell = row.querySelector('td:last-child');
                        if (actionCell) {
                            actionCell.innerHTML = '<span style="color:#22c55e;"><i class="fas fa-check-circle"></i> Done</span>';
                        }
                        row.className = 'ready';
                        row.dataset.ready = '1';
                        if (!window.showCompleted) {
                            row.style.display = 'none';
                        }
                    }
                } else {
                    alert('Error marking order as ready. Please try again.');
                }
            })
            .catch(err => {
                console.warn('AJAX error:', err);
                alert('An error occurred. Please reload and try again.');
            });
    };

    // --- Toggle completed ---
    window.showCompleted = true;
    window.toggleCompleted = function() {
        window.showCompleted = !window.showCompleted;
        applyToggleState();
        const btn = document.getElementById('toggleCompleted');
        btn.innerHTML = window.showCompleted ? '<i class="fas fa-eye-slash"></i> Hide Completed' : '<i class="fas fa-eye"></i> Show Completed';
        btn.style.background = window.showCompleted ? '#e2e8f0' : '#3b82f6';
        btn.style.color = window.showCompleted ? '#1e293b' : 'white';
    };
    function applyToggleState() {
        document.querySelectorAll('#orderTable tbody tr').forEach(row => {
            if (row.dataset.ready === '1') row.style.display = window.showCompleted ? '' : 'none';
        });
    }

    // --- Polling (robust version) ---
    function fetchOrders() {
        const baseUrl = window.urlAdminOrders || 'orders.php';
        const date = window.selectedDate || '';
        const url = baseUrl + '?ajax=1&date=' + date + '&_=' + Date.now();

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.text();
            })
            .then(html => {
                const tbody = document.getElementById('orderTableBody');
                if (tbody) {
                    tbody.innerHTML = html;
                    if (!window.showCompleted) {
                        document.querySelectorAll('#orderTable tbody tr').forEach(row => {
                            if (row.dataset.ready === '1') row.style.display = 'none';
                        });
                    }
                }
            })
            .catch(err => {
                console.warn('Polling error:', err);
            });
    }

    setInterval(fetchOrders, 5000);
    fetchOrders();
    applyToggleState();

    // ================================================================
    // ====== JALALI CALENDAR ======
    // ================================================================
    let calendarVisible = false;
    let currentJMonth = window.jalaliMonth || 1;
    let currentJYear = window.jalaliYear || 1400;

    function loadCalendarGrid(jYear, jMonth) {
        const selected = window.jalaliSelectedDate || '';
        const minDate = window.jalaliMinDate || '';
        const statusMap = window.statusMap || {};
        const url = '?calendar=1&jYear=' + jYear + '&jMonth=' + jMonth +
                    '&selected=' + encodeURIComponent(selected) +
                    '&minDate=' + encodeURIComponent(minDate) +
                    '&statusMap=' + encodeURIComponent(JSON.stringify(statusMap)) +
                    '&_=' + Date.now();
        fetch(url)
            .then(r => r.text())
            .then(html => {
                const grid = document.getElementById('calendarGrid');
                if (grid) grid.innerHTML = html;
                const monthYear = document.getElementById('calendarMonthYear');
                if (monthYear) {
                    const monthNames = ['Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar',
                                       'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand'];
                    monthYear.textContent = monthNames[jMonth - 1] + ' ' + jYear;
                }
            })
            .catch(err => console.warn('Calendar load error:', err));
    }

    window.toggleCalendar = function(e) {
        e.stopPropagation();
        const popup = document.getElementById('calendarPopup');
        if (calendarVisible) {
            popup.style.display = 'none';
            calendarVisible = false;
        } else {
            popup.style.display = 'block';
            calendarVisible = true;
            loadCalendarGrid(currentJYear, currentJMonth);
        }
    };

    document.addEventListener('click', function(e) {
        const popup = document.getElementById('calendarPopup');
        const toggle = document.getElementById('calendarToggle');
        if (popup && toggle) {
            if (!popup.contains(e.target) && !toggle.contains(e.target)) {
                popup.style.display = 'none';
                calendarVisible = false;
            }
        }
    });

    window.changeMonth = function(delta) {
        currentJMonth += delta;
        if (currentJMonth > 12) {
            currentJMonth = 1;
            currentJYear++;
        } else if (currentJMonth < 1) {
            currentJMonth = 12;
            currentJYear--;
        }
        loadCalendarGrid(currentJYear, currentJMonth);
    };

    window.selectJalaliDate = function(jDateStr) {
        const map = window.jalaliToGregorianMap || {};
        if (map[jDateStr]) {
            window.location.href = '?date=' + map[jDateStr];
        } else {
            fetch('?convert_date=1&jDate=' + encodeURIComponent(jDateStr))
                .then(r => r.text())
                .then(gDate => {
                    if (gDate) window.location.href = '?date=' + gDate;
                })
                .catch(err => console.warn('Conversion error:', err));
        }
    };

    // ================================================================
    // ====== ORDER ITEMS MODAL ======
    // ================================================================
    const modal = document.getElementById('orderItemsModal');
    const modalBody = document.getElementById('orderModalBody');
    const modalClose = document.getElementById('orderModalClose');

    function openOrderModal(orderId) {
        console.log('Opening modal for order #', orderId);
        const row = document.querySelector('tr[data-id="' + orderId + '"]');
        if (!row) {
            console.warn('Row not found for order', orderId);
            return;
        }
        const itemsCell = row.querySelector('.order-items');
        if (!itemsCell) {
            console.warn('Items cell not found');
            return;
        }
        const itemsJson = itemsCell.dataset.items;
        if (!itemsJson) {
            console.warn('No items data');
            return;
        }

        let items;
        try {
            items = JSON.parse(itemsJson);
        } catch (e) {
            modalBody.innerHTML = '<p style="color:#991b1b;">Invalid order data.</p>';
            modal.classList.add('active');
            return;
        }

        let html = '';
        const menuMap = window.menuMap || {};
        let totalQty = 0;
        let totalPrice = 0;

        html += '<h2><i class="fas fa-receipt"></i> Order #' + orderId + '</h2>';
        html += '<div class="order-meta">';
        const tableNum = row.querySelector('td:nth-child(2)') ? row.querySelector('td:nth-child(2)').textContent.trim() : '—';
        html += '<span><i class="fas fa-chair"></i> Table ' + tableNum + '</span>';
        const timeCell = row.querySelector('.order-time');
        if (timeCell) html += '<span><i class="fas fa-clock"></i> ' + timeCell.textContent.trim() + '</span>';
        html += '</div>';

        html += '<ul>';
        for (const [id, qty] of Object.entries(items)) {
            const name = menuMap[id] ? menuMap[id].name : 'Unknown (# ' + id + ')';
            const price = menuMap[id] ? menuMap[id].price : 0;
            const subtotal = price * qty;
            totalQty += qty;
            totalPrice += subtotal;
            html += '<li>';
            html += '<span class="item-name">' + name + '</span>';
            html += '<span class="item-qty">× ' + qty + '</span>';
            html += '<span class="item-subtotal">' + numberFormat(subtotal) + ' T</span>';
            html += '</li>';
        }
        html += '</ul>';

        html += '<div class="order-total">';
        html += '<span><i class="fas fa-cubes"></i> ' + totalQty + ' items</span>';
        html += '<span><i class="fas fa-coins"></i> ' + numberFormat(totalPrice) + ' T</span>';
        html += '</div>';

        const noteCell = row.querySelector('td:nth-child(4)');
        if (noteCell) {
            const note = noteCell.textContent.trim();
            if (note && note !== '—') {
                html += '<div class="order-note"><i class="fas fa-pen"></i> ' + note + '</div>';
            }
        }

        modalBody.innerHTML = html;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeOrderModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    const tableBody = document.getElementById('orderTableBody');
    if (tableBody) {
        tableBody.addEventListener('click', function(e) {
            const cell = e.target.closest('.order-items');
            if (cell) {
                console.log('Clicked order-items cell');
                const row = cell.closest('tr');
                if (row) {
                    const orderId = row.dataset.id;
                    if (orderId) {
                        openOrderModal(orderId);
                    }
                }
            }
        });
    } else {
        console.warn('orderTableBody not found');
    }

    modalClose.addEventListener('click', closeOrderModal);
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeOrderModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeOrderModal();
    });

    function numberFormat(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // ================================================================
    // ====== SCROLL‑TO‑TOP BUTTON (FIXED) ======
    // ================================================================
    const scrollBtn = document.getElementById('scrollTopBtn');
    console.log('Scroll button element:', scrollBtn); // DEBUG

    if (scrollBtn) {
        function toggleScrollButton() {
            const scrollY = window.scrollY;
            // Use a fixed threshold of 300px (for testing) – change to 50% of page height if you prefer
            const threshold = 300;
            // OR use percentage: const threshold = (document.documentElement.scrollHeight - window.innerHeight) * 0.5;
            console.log('ScrollY:', scrollY, 'Threshold:', threshold); // DEBUG

            if (scrollY > threshold) {
                scrollBtn.classList.add('visible');
                console.log('Button shown'); // DEBUG
            } else {
                scrollBtn.classList.remove('visible');
            }
        }

        // Run once immediately to check initial state
        toggleScrollButton();

        // Throttled scroll listener
        let ticking = false;
        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    toggleScrollButton();
                    ticking = false;
                });
                ticking = true;
            }
        });

        scrollBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    } else {
        console.error('Scroll button element NOT found! Check if #scrollTopBtn exists in the HTML.');
    }
});