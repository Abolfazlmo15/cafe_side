document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // INLINE UPLOAD: show Save button after file selection
    // ============================================================
    document.querySelectorAll('.qr-file-input').forEach(input => {
        input.addEventListener('change', function() {
            const form = this.closest('.upload-form');
            const saveBtn = form.querySelector('.btn-upload-save');
            if (this.files.length > 0) {
                saveBtn.classList.add('visible');
            } else {
                saveBtn.classList.remove('visible');
            }
        });
    });

    window.triggerFileInput = function(btn) {
        const form = btn.closest('.upload-form');
        const fileInput = form.querySelector('.qr-file-input');
        fileInput.click();
    };

    // ============================================================
    // CLICK HANDLING – only on columns with class 'clickable-col'
    // ============================================================
    document.querySelectorAll('.clickable-col').forEach(col => {
        col.addEventListener('click', function(e) {
            if (e.target.closest('a') || e.target.closest('button')) return;
            const tableNum = this.dataset.table;
            if (tableNum) window.location.href = 'qr.php?edit=' + tableNum;
        });
    });

    // ============================================================
    // PREVIEW MODAL
    // ============================================================
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    const modalClose = document.getElementById('modalClose');

    document.querySelectorAll('.qr-preview').forEach(img => {
        img.addEventListener('click', function(e) {
            e.stopPropagation();
            const fullSrc = this.dataset.full;
            if (fullSrc) {
                modalImg.src = fullSrc;
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
    });

    function closeModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => { modalImg.src = ''; }, 300);
    }

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.classList.contains('active')) closeModal(); });

    // ============================================================
    // EDIT FORM PREVIEW
    // ============================================================
    window.previewEditImage = function(input) {
        const container = document.getElementById('editPreviewContainer');
        const previewImg = document.getElementById('editPreviewImage');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                container.style.display = 'inline-flex';
                container.style.alignItems = 'center';
                container.style.gap = '0.3rem';
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            container.style.display = 'none';
            previewImg.src = '#';
        }
    };

    // ============================================================
    // AUTO-SCROLL TO EDIT FORM
    // ============================================================
    const editForm = document.getElementById('editForm');
    if (editForm) editForm.scrollIntoView({ behavior: 'smooth', block: 'center' });

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
});