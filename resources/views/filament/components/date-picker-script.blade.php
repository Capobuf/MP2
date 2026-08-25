<script>
    (() => {
        const triggerSelector = '[data-mp2-date-picker-trigger]';
        const inputSelector = `${triggerSelector} .fi-fo-date-time-picker-display-text-input`;

        const markInvalid = (input, message) => {
            input.setCustomValidity(message);
            input.setAttribute('aria-invalid', 'true');
        };

        const clearInvalid = (input) => {
            input.setCustomValidity('');
            input.removeAttribute('aria-invalid');
        };

        const pickerData = (input) => {
            const root = input.closest('[x-data]');

            return root && window.Alpine ? window.Alpine.$data(root) : null;
        };

        const commitDate = (input) => {
            const picker = pickerData(input);

            if (! picker) {
                return false;
            }

            const value = input.value.trim();

            if (value === '') {
                clearInvalid(input);
                picker.clearState();

                return true;
            }

            const parts = value.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/);

            if (! parts) {
                markInvalid(input, 'Inserisci una data valida nel formato gg/mm/aaaa.');

                return false;
            }

            const day = Number(parts[1]);
            const month = Number(parts[2]);
            const year = Number(parts[3]);
            const check = new Date(Date.UTC(year, month - 1, day));

            if (
                check.getUTCFullYear() !== year ||
                check.getUTCMonth() !== month - 1 ||
                check.getUTCDate() !== day
            ) {
                markInvalid(input, 'Inserisci una data esistente nel formato gg/mm/aaaa.');

                return false;
            }

            const date = window.dayjs(
                `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
            );

            if (picker.dateIsDisabled(date)) {
                markInvalid(input, 'La data inserita non è disponibile.');

                return false;
            }

            clearInvalid(input);
            picker.focusedDate = date;
            picker.setState(date);
            picker.setDisplayText();

            return true;
        };

        const prepareInput = (input) => {
            if (input.dataset.mp2ManualDate === 'true') {
                return;
            }

            input.dataset.mp2ManualDate = 'true';
            input.readOnly = false;
            input.inputMode = 'numeric';
            input.autocomplete = 'off';

            input.addEventListener('input', () => clearInvalid(input));
            input.addEventListener('change', () => commitDate(input));
            input.addEventListener('blur', () => commitDate(input));
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    return;
                }

                event.stopPropagation();

                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                if (commitDate(input)) {
                    const picker = pickerData(input);

                    if (picker?.isOpen()) {
                        picker.togglePanelVisibility();
                    }
                }
            });
        };

        const prepareInputs = (root = document) => {
            if (root.matches?.(inputSelector)) {
                prepareInput(root);
            }

            root.querySelectorAll?.(inputSelector).forEach(prepareInput);
        };

        const positionPanel = (trigger) => {
            const root = trigger.closest('[x-data]');
            const panel = root?.querySelector('.fi-fo-date-time-picker-panel');

            if (! panel || getComputedStyle(panel).display === 'none') {
                return;
            }

            const triggerRect = trigger.getBoundingClientRect();
            const panelRect = panel.getBoundingClientRect();
            const gap = 8;
            const viewportPadding = 8;
            const roomBelow = window.innerHeight - triggerRect.bottom - gap;
            const roomAbove = triggerRect.top - gap;
            const openBelow = roomBelow >= panelRect.height || roomBelow >= roomAbove;
            const viewportTop = openBelow
                ? triggerRect.bottom + gap
                : triggerRect.top - panelRect.height - gap;
            const clampedTop = Math.min(
                Math.max(viewportTop, viewportPadding),
                window.innerHeight - panelRect.height - viewportPadding,
            );
            const clampedLeft = Math.min(
                Math.max(triggerRect.left, viewportPadding),
                window.innerWidth - panelRect.width - viewportPadding,
            );
            const offsetParent = panel.offsetParent ?? document.documentElement;
            const offsetParentRect = offsetParent.getBoundingClientRect();

            panel.style.top = `${clampedTop - offsetParentRect.top + offsetParent.scrollTop}px`;
            panel.style.left = `${clampedLeft - offsetParentRect.left + offsetParent.scrollLeft}px`;
        };

        const positionOpenPanels = () => {
            document.querySelectorAll(triggerSelector).forEach((trigger) => {
                if (trigger.getAttribute('aria-expanded') === 'true') {
                    positionPanel(trigger);
                }
            });
        };

        const settlePanelPosition = (trigger) => {
            requestAnimationFrame(() => {
                positionPanel(trigger);
                requestAnimationFrame(() => positionPanel(trigger));
            });

            setTimeout(() => positionPanel(trigger), 50);
        };

        prepareInputs();

        new MutationObserver((mutations) => {
            mutations.forEach(({ addedNodes }) => {
                addedNodes.forEach((node) => {
                    if (node instanceof Element) {
                        prepareInputs(node);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest?.(triggerSelector);

            if (trigger) {
                settlePanelPosition(trigger);
            }
        });

        window.addEventListener('scroll', () => requestAnimationFrame(positionOpenPanels), true);
        window.addEventListener('resize', () => requestAnimationFrame(positionOpenPanels));
        document.addEventListener('livewire:navigated', () => prepareInputs());
    })();
</script>
