(function () {
    'use strict';

    var toggle = document.querySelector('.admin-menu-toggle');
    var sidebar = document.querySelector('.admin-sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });

        document.addEventListener('click', function (event) {
            if (window.innerWidth > 900) return;
            if (!sidebar.classList.contains('open')) return;
            if (sidebar.contains(event.target) || toggle.contains(event.target)) return;
            sidebar.classList.remove('open');
        });
    }

    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        var previewEl = document.getElementById(input.dataset.preview);
        if (!previewEl) return;

        // Checkbox "Remover imagem atual" da mesma .field — escolher um arquivo novo
        // substitui a imagem de qualquer forma, então desmarca pra evitar confusão.
        var removeCheck = document.querySelector('[data-remove-preview="' + input.dataset.preview + '"]');

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;

            if (removeCheck) removeCheck.checked = false;

            var reader = new FileReader();
            reader.onload = function (e) {
                previewEl.src = e.target.result;
                previewEl.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    });

    document.querySelectorAll('[data-remove-preview]').forEach(function (checkbox) {
        var previewEl = document.getElementById(checkbox.dataset.removePreview);
        if (!previewEl) return;

        checkbox.addEventListener('change', function () {
            previewEl.style.display = checkbox.checked ? 'none' : 'block';
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    window.showToast = function (message, type) {
        var container = document.getElementById('toasts');
        if (!container) return;

        var toast = document.createElement('div');
        toast.className = 'toast' + (type === 'error' ? ' error' : '');
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.25s ease';
            setTimeout(function () { toast.remove(); }, 250);
        }, 3500);
    };

    document.querySelectorAll('[data-flash-success]').forEach(function (el) {
        window.showToast(el.dataset.flashSuccess, 'success');
    });

    document.querySelectorAll('[data-flash-error]').forEach(function (el) {
        window.showToast(el.dataset.flashError, 'error');
    });

    // ---- Máscaras de campo (CNPJ e telefone/WhatsApp com DDI) ----
    var mascaras = {
        cnpj: function (digitos) {
            digitos = digitos.slice(0, 14);
            var partes = [];
            if (digitos.length > 0) partes.push(digitos.slice(0, 2));
            if (digitos.length > 2) partes[0] += '.' + digitos.slice(2, 5);
            if (digitos.length > 5) partes[0] += '.' + digitos.slice(5, 8);
            if (digitos.length > 8) partes[0] += '/' + digitos.slice(8, 12);
            if (digitos.length > 12) partes[0] += '-' + digitos.slice(12, 14);
            return partes[0] || '';
        },
        telefone: function (digitos) {
            digitos = digitos.slice(0, 13);
            if (digitos.length <= 11) {
                // sem DDI: (DD) 9XXXX-XXXX
                var out = '';
                if (digitos.length > 0) out = '(' + digitos.slice(0, 2);
                if (digitos.length >= 2) out += ') ';
                if (digitos.length > 2) out += digitos.slice(2, digitos.length > 10 ? 7 : 6);
                if (digitos.length > (digitos.length > 10 ? 7 : 6)) {
                    out += '-' + digitos.slice(digitos.length > 10 ? 7 : 6);
                }
                return out;
            }
            // com DDI: +XX (DD) 9XXXX-XXXX
            var ddi = digitos.slice(0, digitos.length - 11);
            var resto = digitos.slice(digitos.length - 11);
            var out2 = '+' + ddi + ' (' + resto.slice(0, 2) + ') ' + resto.slice(2, 7);
            if (resto.length > 7) out2 += '-' + resto.slice(7);
            return out2;
        }
    };

    // ---- Seletor de cor sincronizado com o campo de texto ----
    document.querySelectorAll('[data-color-field]').forEach(function (wrapper) {
        var colorInput = wrapper.querySelector('input[type="color"]');
        var textInput = wrapper.querySelector('input[type="text"]');
        if (!colorInput || !textInput) return;

        colorInput.addEventListener('input', function () {
            textInput.value = colorInput.value;
        });

        textInput.addEventListener('input', function () {
            if (/^#([0-9a-f]{6})$/i.test(textInput.value.trim())) {
                colorInput.value = textInput.value.trim();
            }
        });
    });

    document.querySelectorAll('input[data-mask]').forEach(function (input) {
        var tipo = input.dataset.mask;
        var fn = mascaras[tipo];
        if (!fn) return;

        input.addEventListener('input', function () {
            var digitos = input.value.replace(/\D/g, '');
            input.value = fn(digitos);
        });

        if (input.value) {
            input.value = fn(input.value.replace(/\D/g, ''));
        }
    });
})();
