document.addEventListener('DOMContentLoaded', function () {

    // ── Boutons preset (cooldown, ip_max) ────────────────────────────────────
    document.querySelectorAll('.kpopify-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.dataset.target);
            if (target) target.value = btn.dataset.value;
        });
    });

    // ── Confirmation deux étapes pour la réinitialisation du burst ───────────
    var resetBtn   = document.getElementById('kpopify-ping-reset-btn');
    var confirmBtn = document.getElementById('kpopify-ping-reset-confirm');

    if (resetBtn && confirmBtn) {
        resetBtn.addEventListener('click', function () {
            resetBtn.style.display   = 'none';
            confirmBtn.style.display = 'inline-block';
        });

        document.addEventListener('click', function (e) {
            if (e.target !== confirmBtn && e.target !== resetBtn) {
                resetBtn.style.display   = 'inline-block';
                confirmBtn.style.display = 'none';
            }
        });
    }

});
