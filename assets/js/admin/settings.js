document.addEventListener('DOMContentLoaded', function() {
    // Message auto‑dismiss (3 sec) and close button
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
});