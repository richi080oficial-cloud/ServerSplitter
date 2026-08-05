/**
 * ServerSplitter - interacciones de la interfaz de cliente.
 *
 * Sin dependencias externas. Se encarga de:
 *  1. Confirmar acciones destructivas (data-confirm en el <form>).
 *  2. Evitar envios duplicados (doble clic) deshabilitando el boton.
 *  3. Mostrar en vivo cuantos recursos le quedarian al servidor padre
 *     y bloquear el envio si se incumple la reserva minima.
 */
(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    function toInt(value) {
        var parsed = parseInt(value, 10);

        return isNaN(parsed) ? 0 : parsed;
    }

    function formatAmount(value, unit) {
        return value === 0 ? 'ilimitado' : value + ' ' + unit;
    }

    /** Deshabilita los botones de envio de un formulario ya enviado. */
    function lockForm(form) {
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');

            if (button.tagName === 'BUTTON' && !button.dataset.originalLabel) {
                button.dataset.originalLabel = button.textContent;
                button.textContent = 'Procesando\u2026';
            }
        });

        form.setAttribute('aria-busy', 'true');
    }

    function initConfirmations() {
        var forms = document.querySelectorAll('form[data-confirm]');

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitted === '1') {
                    event.preventDefault();

                    return;
                }

                var message = form.getAttribute('data-confirm') || '\u00bfConfirmar la accion?';

                if (!window.confirm(message)) {
                    event.preventDefault();

                    return;
                }

                form.dataset.submitted = '1';
                lockForm(form);
            });
        });
    }

    function initPreview() {
        var form = document.getElementById('ss-form');

        if (!form) {
            return;
        }

        var preview = document.getElementById('ss-preview');
        var submit = form.querySelector('button[type="submit"]');

        if (!preview || form.dataset.mode === 'distribute') {
            return;
        }

        var resources = [
            { key: 'memory', label: 'RAM', unit: 'MiB' },
            { key: 'disk', label: 'disco', unit: 'MiB' },
            { key: 'cpu', label: 'CPU', unit: '%' }
        ];

        var inputs = [];

        resources.forEach(function (resource) {
            var input = form.querySelector('[data-resource="' + resource.key + '"]');

            if (input) {
                inputs.push({ input: input, resource: resource });
            }
        });

        if (inputs.length === 0) {
            return;
        }

        function update() {
            var messages = [];
            var problems = [];

            inputs.forEach(function (entry) {
                var key = entry.resource.key;
                var parent = toInt(form.dataset['parent' + key.charAt(0).toUpperCase() + key.slice(1)]);
                var reserve = toInt(form.dataset['reserve' + key.charAt(0).toUpperCase() + key.slice(1)]);
                var requested = toInt(entry.input.value);
                var invalid = false;

                if (parent === 0) {
                    // 0 en Pterodactyl significa ilimitado: no hay nada que restar.
                    messages.push(entry.resource.label + ': el padre seguira sin limite');
                } else {
                    var remaining = parent - requested;

                    if (remaining < 0) {
                        problems.push(
                            'La ' + entry.resource.label + ' pedida (' + requested + ') supera lo disponible (' + parent + ').'
                        );
                        invalid = true;
                    } else if (remaining < reserve) {
                        problems.push(
                            entry.resource.label + ': el padre debe conservar al menos ' + reserve +
                            ' y quedarian ' + remaining + '.'
                        );
                        invalid = true;
                    }

                    messages.push(
                        entry.resource.label + ': al padre le quedarian ' +
                        formatAmount(Math.max(0, remaining), entry.resource.unit)
                    );
                }

                entry.input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
            });

            if (problems.length > 0) {
                preview.textContent = problems.join(' ');
                preview.classList.add('ss-preview--bad');
            } else {
                preview.textContent = messages.join(' \u00b7 ');
                preview.classList.remove('ss-preview--bad');
            }

            if (submit) {
                submit.disabled = problems.length > 0;
                submit.setAttribute('aria-disabled', problems.length > 0 ? 'true' : 'false');
            }
        }

        inputs.forEach(function (entry) {
            entry.input.addEventListener('input', update);
            entry.input.addEventListener('change', update);
        });

        update();
    }

    ready(function () {
        initConfirmations();
        initPreview();
    });
})();