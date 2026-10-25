<script>
    /**
     * Prevención de FOUC (Flash of Unstyled Content) y sincronización de tema.
     * Se ejecuta de forma síncrona en el <head> antes de que el navegador pinte el DOM.
     */
    (function() {
        try {
            const savedTheme = localStorage.getItem('theme') || 'system';
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme === 'dark' || (savedTheme === 'system' && systemPrefersDark)) {
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-theme', 'light');
            }
        } catch (e) {
            // Manejo de error silencioso en caso de cookies/localStorage deshabilitados
        }

        // Estado del menú lateral (solo escritorio): se aplica antes del primer pintado.
        try {
            document.documentElement.dataset.sidebar =
                localStorage.getItem('sidebar') === 'collapsed' ? 'collapsed' : 'expanded';
        } catch (e) {}
    })();
</script>
