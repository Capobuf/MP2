<script>
    (() => {
        // Filament's custom selects hard-code these accessible labels.
        const labels = {
            '.fi-select-input-value-remove-btn[aria-label="Clear selection"]': 'Cancella selezione',
            '.fi-select-input-search-ctn input[aria-label="Search"]': 'Cerca',
        };

        const translate = (root) => {
            if (!(root instanceof Element)) return;

            for (const [selector, label] of Object.entries(labels)) {
                if (root.matches(selector)) root.setAttribute('aria-label', label);
                root.querySelectorAll(selector).forEach((element) => element.setAttribute('aria-label', label));
            }
        };

        translate(document.body);
        new MutationObserver((mutations) => {
            mutations.forEach((mutation) => mutation.addedNodes.forEach(translate));
        }).observe(document.body, { childList: true, subtree: true });
    })();
</script>
