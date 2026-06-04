</main>

<script>
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
        },
        body: new URLSearchParams({
            action: 'toggle_theme',
            theme: newTheme
        })
    }).catch(err => console.error('Error saving theme preference:', err));
}
</script>

</body>
</html>