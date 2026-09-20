(function () {
    const storageKey = '3dprinterstatus-theme';

    function preferredTheme() {
        let savedTheme = null;
        try {
            savedTheme = localStorage.getItem(storageKey);
        } catch (error) {
            // Storage may be unavailable; use the system preference.
        }

        if (savedTheme === 'light' || savedTheme === 'dark') {
            return savedTheme;
        }
        return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme, persist) {
        document.documentElement.dataset.theme = theme;
        document.querySelectorAll('[data-theme-option]').forEach(function (button) {
            const selected = button.dataset.themeOption === theme;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });

        if (persist) {
            try {
                localStorage.setItem(storageKey, theme);
            } catch (error) {
                // The selected theme still applies for this page load.
            }
        }
    }

    applyTheme(preferredTheme(), false);

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme(document.documentElement.dataset.theme, false);
        document.querySelectorAll('[data-theme-option]').forEach(function (button) {
            button.addEventListener('click', function () {
                applyTheme(button.dataset.themeOption, true);
            });
        });
    });
}());
