/**
 * PC Customer ID - front office behaviour.
 *
 * This script is UX only: it shows/hides the "dni" field and toggles its
 * required/asterisk state so the customer sees the requirement immediately.
 * The actual enforcement always happens server-side in
 * Pccustomerid::hookActionValidateCustomerAddressForm().
 */
(function () {
    'use strict';

    function getConfig() {
        return window.pcCustomerIdConfig || null;
    }

    function findDniInput(scope) {
        return (scope || document).querySelector('input[name="dni"]');
    }

    function findContainer(input) {
        return input.closest('.form-group') || input.closest('.form-control-comment') || input.parentElement;
    }

    function toInt(value) {
        var n = parseInt(value, 10);
        return isNaN(n) ? 0 : n;
    }

    function findFieldValue(form, name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }

    function matchesCanaryPostcode(postcode, pattern) {
        if (!pattern || !postcode) {
            return false;
        }
        try {
            return new RegExp(pattern).test(postcode);
        } catch (e) {
            return false;
        }
    }

    function isRequiredDestination(config, idCountry, idState, postcode) {
        if (!idCountry) {
            return false;
        }
        var countries = config.countries || [];
        if (countries.indexOf(idCountry) !== -1) {
            return true;
        }
        if (config.countryES && idCountry === config.countryES) {
            var canaryStateIds = config.canaryStateIds || [];
            if (idState && canaryStateIds.indexOf(idState) !== -1) {
                return true;
            }
            return matchesCanaryPostcode(postcode.replace(/\s+/g, ''), config.canaryPostcodeRegex);
        }
        return false;
    }

    function ensureHelp(container, config) {
        var inputColumn = container.querySelector('.js-input-column') || container;
        var help = inputColumn.querySelector('.form-control-comment');
        if (!help) {
            help = document.createElement('span');
            help.className = 'form-control-comment';
            help.textContent = config.helpText || '';
            inputColumn.appendChild(help);
        }
        help.classList.add('pc-customer-id-help');
        return help;
    }

    function ensureRequiredMark(container, input) {
        var label = null;
        if (input.id) {
            label = container.querySelector('label[for="' + input.id + '"]');
        }
        if (!label) {
            label = container.querySelector('label');
        }
        if (!label) {
            return null;
        }
        var mark = label.querySelector('.pc-customer-id-required-mark');
        if (!mark) {
            mark = document.createElement('span');
            mark.className = 'pc-customer-id-required-mark';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = ' *';
            label.appendChild(mark);
        }
        return mark;
    }

    function findOptionalIndicator(container) {
        var candidates = container.querySelectorAll('.form-control-comment');
        for (var i = 0; i < candidates.length; i++) {
            if (!candidates[i].classList.contains('pc-customer-id-help')) {
                return candidates[i];
            }
        }
        return null;
    }

    function applyState(form, config) {
        var input = findDniInput(form);
        if (!input) {
            return;
        }

        var container = findContainer(input);
        var idCountry = toInt(findFieldValue(form, 'id_country'));
        var idState = toInt(findFieldValue(form, 'id_state'));
        var postcode = String(findFieldValue(form, 'postcode') || '');
        var required = isRequiredDestination(config, idCountry, idState, postcode);

        if (input.placeholder !== config.placeholder) {
            input.placeholder = config.placeholder || '';
        }

        var help = ensureHelp(container, config);
        var mark = ensureRequiredMark(container, input);
        var optionalIndicator = findOptionalIndicator(container);

        container.classList.toggle('pc-customer-id-visible', required);
        container.classList.toggle('pc-customer-id-field-hidden', !required);

        input.required = required;
        if (required) {
            input.setAttribute('aria-required', 'true');
        } else {
            input.removeAttribute('aria-required');
        }

        if (mark) {
            mark.style.display = required ? '' : 'none';
        }
        if (help) {
            help.style.display = required ? '' : 'none';
        }
        if (optionalIndicator) {
            optionalIndicator.style.display = required ? 'none' : '';
        }
    }

    function init(scope) {
        var config = getConfig();
        if (!config || !config.enabled) {
            return;
        }

        var root = scope && scope.querySelectorAll ? scope : document;
        var forms = root.querySelectorAll('form');

        Array.prototype.forEach.call(forms, function (form) {
            if (findDniInput(form)) {
                applyState(form, config);
            }
        });

        // The scope itself may be the address form (e.g. after an AJAX refresh
        // that replaced a wrapper without a <form> ancestor being re-scanned).
        if (root !== document && root.tagName === 'FORM' && findDniInput(root)) {
            applyState(root, config);
        }
    }

    function onRelevantFieldEvent(event) {
        var target = event.target;
        if (!target || !target.name) {
            return;
        }
        if (['id_country', 'id_state', 'postcode'].indexOf(target.name) === -1) {
            return;
        }
        var form = target.closest('form');
        if (form) {
            init(form);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
    document.addEventListener('change', onRelevantFieldEvent, true);
    document.addEventListener('input', onRelevantFieldEvent, true);

    if (window.prestashop && window.prestashop.on) {
        window.prestashop.on('updatedAddressForm', function () {
            init(document);
        });
    }
})();
