<?php
require_once __DIR__.'/auth.php';
$recoveryCsrfValue = recoveryCsrf();
?>
<script>
// Existing screens use fetch; apply the session's CSRF token to same-origin writes.
if (!window.recoveryFetchInstalled) {
    window.recoveryFetchInstalled = true;
    window.recoveryCsrf = <?= json_encode($recoveryCsrfValue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, options = {}) => {
        const url = new URL(input instanceof Request ? input.url : input, location.href);
        const method = (options.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        if (url.origin === location.origin && !['GET', 'HEAD'].includes(method)) {
            const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-CSRF-Token', window.recoveryCsrf);
            options = {...options, headers};
        }
        return originalFetch(input, options);
    };
}
</script>
