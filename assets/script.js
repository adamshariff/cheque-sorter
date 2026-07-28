document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('loginForm');
    const email = document.getElementById('email');
    const password = document.getElementById('password');
    const status = document.getElementById('formStatus');

    if (!form || !email || !password || !status) {
        return;
    }

    form.addEventListener('submit', (event) => {
        status.textContent = '';

        const emailValue = email.value.trim();
        const passwordValue = password.value.trim();

        if (!emailValue || !passwordValue) {
            event.preventDefault();
            status.textContent = 'Please enter both email and password.';
            return;
        }

        if (!/^\S+@\S+\.\S+$/.test(emailValue)) {
            event.preventDefault();
            status.textContent = 'Please enter a valid email address.';
            return;
        }

        if (passwordValue.length < 6) {
            event.preventDefault();
            status.textContent = 'Password must be at least 6 characters.';
            return;
        }

        status.textContent = 'Signing in...';
    });
});
