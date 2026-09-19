document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // IMAGE MODAL
    // ============================================================
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    const modalClose = document.getElementById('modalClose');

    function openModal(src) {
        modalImg.src = src;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => { modalImg.src = ''; }, 300);
    }

    document.querySelectorAll('.item-img').forEach(img => {
        img.addEventListener('click', function(e) {
            e.stopPropagation();
            const fullSrc = this.dataset.full;
            if (fullSrc) openModal(fullSrc);
        });
    });

    document.querySelectorAll('.clickable-name').forEach(el => {
        el.addEventListener('click', function(e) {
            const id = this.dataset.id;
            if (id) window.location.href = '?edit=' + id;
        });
    });

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.classList.contains('active')) closeModal(); });

    // ============================================================
    // FILE INPUT PREVIEW
    // ============================================================
    const fileInput = document.getElementById('itemImageInput');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('imagePreview');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewContainer.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.style.display = 'none';
                previewImg.src = '#';
            }
        });
    }

    // ============================================================
    // MESSAGE AUTO‑DISMISS (3 sec) AND CLOSE BUTTON
    // ============================================================
    document.querySelectorAll('.message.dismissible').forEach(function(msg) {
        setTimeout(function() {
            msg.classList.add('fade-out');
            setTimeout(function() { if (msg.parentNode) msg.parentNode.removeChild(msg); }, 500);
        }, 3000);
        var closeBtn = msg.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                msg.classList.add('fade-out');
                setTimeout(function() { if (msg.parentNode) msg.parentNode.removeChild(msg); }, 500);
            });
        }
    });

    // ============================================================
    // SCROLL‑TO‑TOP BUTTON
    // ============================================================
    const scrollBtn = document.getElementById('scrollTopBtn');
    if (scrollBtn) {
        function toggleScrollButton() {
            const scrollY = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const threshold = docHeight * 0.5;
            if (scrollY > threshold) {
                scrollBtn.classList.add('visible');
            } else {
                scrollBtn.classList.remove('visible');
            }
        }

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

        toggleScrollButton();
    }

    // ============================================================
    // CATEGORY DROPDOWN (with highlighting)
    // ============================================================
    const categoryInput = document.getElementById('categoryInput');
    const categoryToggle = document.getElementById('categoryToggle');
    const categoryDropdown = document.getElementById('categoryDropdown');

    if (categoryInput && categoryToggle && categoryDropdown) {
        // The current value that is displayed in the input (starts with the DB value)
        let currentValue = categoryInput.value;

        // Get all category items
        const allItems = Array.from(categoryDropdown.querySelectorAll('li'));

        // Highlight the item matching the current value
        function highlightCurrentCategory() {
            // Remove previous highlights
            allItems.forEach(item => item.classList.remove('selected-category'));
            // If currentValue is not empty, find and highlight matching item
            if (currentValue.trim() !== '') {
                allItems.forEach(item => {
                    const itemValue = item.dataset.value || item.textContent;
                    if (itemValue === currentValue) {
                        item.classList.add('selected-category');
                    }
                });
            }
        }

        // Show dropdown, clear input, but keep currentValue in memory
        function openDropdown() {
            // Save the current value before clearing
            // (but we already have it in currentValue)
            // Just clear the input so user can see all categories
            categoryInput.value = '';
            // Show all items (no filter)
            allItems.forEach(li => li.style.display = '');
            // Highlight the current category
            highlightCurrentCategory();
            // Show the dropdown
            categoryDropdown.style.display = 'block';
        }

        // Close dropdown, restore currentValue if nothing was selected
        function closeDropdown() {
            categoryDropdown.style.display = 'none';
            // Restore the current value
            categoryInput.value = currentValue;
        }

        // Toggle dropdown
        function toggleDropdown() {
            if (categoryDropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        }

        // --- Toggle button ---
        categoryToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleDropdown();
            categoryInput.focus();
        });

        // --- Focus on input ---
        categoryInput.addEventListener('focus', function() {
            // Only open if it's not already open
            if (categoryDropdown.style.display !== 'block') {
                openDropdown();
            }
        });

        // --- Typing filters the list (only when open) ---
        categoryInput.addEventListener('input', function() {
            if (categoryDropdown.style.display === 'block') {
                const query = this.value.toLowerCase().trim();
                allItems.forEach(li => {
                    const text = li.textContent.toLowerCase();
                    li.style.display = text.includes(query) ? '' : 'none';
                });
                // Keep highlighting even when filtered
                highlightCurrentCategory();
            }
        });

        // --- Click on a category item ---
        categoryDropdown.addEventListener('mousedown', function(e) {
            e.preventDefault(); // prevent input blur
            const li = e.target.closest('li');
            if (li) {
                const selected = li.dataset.value || li.textContent;
                // Update currentValue
                currentValue = selected;
                // Set input to the selected value
                categoryInput.value = selected;
                // Close dropdown
                categoryDropdown.style.display = 'none';
                categoryInput.focus();
                // Trigger input event
                categoryInput.dispatchEvent(new Event('input'));
            }
        });

        // --- Close on outside click ---
        document.addEventListener('click', function(e) {
            const wrapper = categoryInput.closest('.category-select-wrapper');
            if (wrapper && !wrapper.contains(e.target) && categoryDropdown.style.display === 'block') {
                closeDropdown();
            }
        });

        // --- Close on Escape ---
        categoryInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && categoryDropdown.style.display === 'block') {
                closeDropdown();
                categoryInput.blur();
            }
        });

        // --- Initial state: ensure dropdown is hidden and input shows currentValue ---
        categoryDropdown.style.display = 'none';
        categoryInput.value = currentValue;
    }
});