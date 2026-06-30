(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('maishapay_payment_form');
        if (!form) return;
        form.addEventListener('submit', function () {
            var btn = document.getElementById('submit_maishapay_payment_form');
            if (btn) {
                btn.disabled = true;
                btn.value = 'Redirection en cours...';
            }
        });
    });
})();