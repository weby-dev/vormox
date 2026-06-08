</main>

<script>
// Global helper — every script on the page can read the active session's
// CSRF token here. Read from the <meta> injected by csrf_meta().
window.CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

function toggleTheme() {
    const currentTheme = document.body.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

    // Instantly apply visually
    document.body.setAttribute('data-theme', newTheme);

    // Save to DB asynchronously
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': window.CSRF_TOKEN,
        },
        body: new URLSearchParams({
            action: 'toggle_theme',
            theme: newTheme,
            csrf_token: window.CSRF_TOKEN,
        })
    }).catch(err => console.error('Error saving theme preference:', err));
}
</script>

</body>
</html>