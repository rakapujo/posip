<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useNotification } from '@/composables/useNotification';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const settingsStore = useSettingsStore();
const notify = useNotification();

const email = ref('');
const password = ref('');
const checked = ref(false);
const loading = ref(false);
const errorMessage = ref('');
const mounted = ref(false);

onMounted(() => {
    setTimeout(() => (mounted.value = true), 50);
});

const handleLogin = async () => {
    errorMessage.value = '';

    if (!email.value || !password.value) {
        errorMessage.value = 'Email dan password wajib diisi';
        return;
    }

    loading.value = true;

    try {
        const result = await authStore.login({
            email: email.value,
            password: password.value
        });

        if (result.success) {
            notify.success(`Selamat datang, ${authStore.displayName}!`, 'Login Berhasil');
            const redirectTo = route.query.redirect || '/app';
            router.push(redirectTo);
        } else {
            errorMessage.value = result.message;
            notify.error(result.message, 'Login Gagal');
        }
    } catch (error) {
        errorMessage.value = 'Terjadi kesalahan. Silakan coba lagi.';
        notify.error('Terjadi kesalahan. Silakan coba lagi.');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="login-container" :class="{ 'is-ready': mounted }">
        <!-- Left Panel - Brand/Visual -->
        <div class="brand-panel">
            <!-- Background with image -->
            <div class="brand-bg">
                <img :src="settingsStore.loginBackground || '/image-loginpage.jpg'" alt="" class="brand-bg-img" />
                <div class="brand-overlay"></div>
            </div>

            <!-- Floating shapes -->
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>

            <!-- Brand Content -->
            <div class="brand-content">
                <h1 class="brand-name">{{ settingsStore.storeName }}</h1>
                <p class="brand-tagline">Point of Sale Solution</p>

                <!-- Stats/Features -->
                <div class="brand-stats">
                    <div class="stat-item">
                        <span class="stat-value">99.9%</span>
                        <span class="stat-label">Uptime</span>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-item">
                        <span class="stat-value">256-bit</span>
                        <span class="stat-label">Encryption</span>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-item">
                        <span class="stat-value">24/7</span>
                        <span class="stat-label">Support</span>
                    </div>
                </div>
            </div>

            <!-- Bottom decoration -->
            <div class="brand-footer">
                <div class="pulse-ring"></div>
                <span>Secure Connection</span>
            </div>
        </div>

        <!-- Right Panel - Login Form -->
        <div class="form-panel">
            <!-- Mobile header -->
            <div class="mobile-brand">
                <img :src="settingsStore.storeLogo || '/logo.svg'" :alt="settingsStore.storeName" class="mobile-logo" />
                <span class="mobile-name">{{ settingsStore.storeName }}</span>
            </div>

            <div class="form-wrapper">
                <div class="form-inner">
                    <!-- Header -->
                    <div class="form-header">
                        <img :src="settingsStore.storeLogo || '/logo.svg'" :alt="settingsStore.storeName" class="form-logo" />
                        <h2 class="form-title">Welcome Back</h2>
                        <p class="form-subtitle">Sign in to continue to your dashboard</p>
                    </div>

                    <!-- Error Message -->
                    <Transition name="slide-fade">
                        <div v-if="errorMessage" class="error-alert">
                            <i class="pi pi-exclamation-circle"></i>
                            <span>{{ errorMessage }}</span>
                        </div>
                    </Transition>

                    <!-- Form -->
                    <form @submit.prevent="handleLogin" class="login-form">
                        <div class="field">
                            <label for="email">Email</label>
                            <div class="input-wrapper">
                                <i class="pi pi-at field-icon"></i>
                                <InputText id="email" type="email" v-model="email" placeholder="name@company.com" autocomplete="email" :disabled="loading" class="field-input" />
                            </div>
                        </div>

                        <div class="field">
                            <div class="field-header">
                                <label for="password">Password</label>
                                <a href="#" class="forgot-link">Forgot?</a>
                            </div>
                            <div class="input-wrapper">
                                <i class="pi pi-lock field-icon"></i>
                                <Password id="password" v-model="password" placeholder="Enter your password" :toggleMask="true" :feedback="false" autocomplete="current-password" :disabled="loading" inputClass="field-input has-icon" fluid />
                            </div>
                        </div>

                        <div class="remember-row">
                            <label class="remember-label">
                                <Checkbox v-model="checked" binary :disabled="loading" />
                                <span>Keep me signed in</span>
                            </label>
                        </div>

                        <Button type="submit" :loading="loading" class="submit-button">
                            <template #default>
                                <span>Sign In</span>
                                <i class="pi pi-arrow-right"></i>
                            </template>
                            <template #loadingicon>
                                <i class="pi pi-spin pi-spinner"></i>
                            </template>
                        </Button>
                    </form>

                    <!-- Footer -->
                    <div class="form-footer">
                        <div class="security-badge">
                            <i class="pi pi-shield"></i>
                            <span>Protected by enterprise-grade security</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="login-footer">
                Build With Love
                <a href="https://siapngeweb.com" target="_blank" rel="noopener noreferrer">Siapngeweb.com</a>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ============================================
   CONTAINER
============================================ */
.login-container {
    position: fixed;
    inset: 0;
    display: flex;
    background: var(--p-surface-50);
    font-family:
        'Inter',
        -apple-system,
        BlinkMacSystemFont,
        sans-serif;
    opacity: 0;
    transition: opacity 0.5s ease;
}

.login-container.is-ready {
    opacity: 1;
}

/* ============================================
   BRAND PANEL (LEFT)
============================================ */
.brand-panel {
    display: none;
    position: relative;
    width: 65%;
    min-width: 500px;
    overflow: hidden;
}

@media (min-width: 1100px) {
    .brand-panel {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
}

.brand-bg {
    position: absolute;
    inset: 0;
}

.brand-bg-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.brand-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.85) 0%, rgba(30, 41, 59, 0.75) 50%, rgba(15, 23, 42, 0.85) 100%);
}

/* Floating shapes */
.shape {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.05);
    animation: float-shape 20s ease-in-out infinite;
}

.shape-1 {
    width: 400px;
    height: 400px;
    top: -100px;
    right: -100px;
    animation-delay: 0s;
}

.shape-2 {
    width: 300px;
    height: 300px;
    bottom: -50px;
    left: -80px;
    animation-delay: -7s;
}

.shape-3 {
    width: 200px;
    height: 200px;
    top: 40%;
    left: 20%;
    animation-delay: -14s;
}

@keyframes float-shape {
    0%,
    100% {
        transform: translate(0, 0) rotate(0deg);
    }
    25% {
        transform: translate(20px, -20px) rotate(5deg);
    }
    50% {
        transform: translate(-10px, 15px) rotate(-3deg);
    }
    75% {
        transform: translate(15px, 10px) rotate(3deg);
    }
}

/* Brand content */
.brand-content {
    position: relative;
    z-index: 1;
    text-align: center;
    color: white;
    padding: 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.brand-name {
    font-size: 3.5rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: 0.75rem;
    color: #ffffff;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
}

.brand-tagline {
    font-size: 0.875rem;
    font-weight: 500;
    letter-spacing: 0.25em;
    text-transform: uppercase;
    margin-bottom: 3rem;
    color: rgba(255, 255, 255, 0.9);
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 1.25rem;
}

.brand-tagline::before,
.brand-tagline::after {
    content: '';
    width: 50px;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
}

/* Stats */
.brand-stats {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2rem;
    padding: 1.5rem 2.5rem;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.375rem;
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #ffffff;
}

.stat-label {
    font-size: 0.6875rem;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

.stat-divider {
    width: 1px;
    height: 40px;
    background: rgba(255, 255, 255, 0.25);
}

/* Brand footer */
.brand-footer {
    position: absolute;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.8125rem;
    font-weight: 500;
}

.pulse-ring {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--p-green-500);
    position: relative;
}

.pulse-ring::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 2px solid var(--p-green-500);
    animation: pulse-ring 2s ease-out infinite;
}

@keyframes pulse-ring {
    0% {
        transform: scale(1);
        opacity: 1;
    }
    100% {
        transform: scale(2);
        opacity: 0;
    }
}

/* ============================================
   FORM PANEL (RIGHT)
============================================ */
.form-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--p-surface-0);
    position: relative;
    overflow-y: auto;
    border-radius: 2rem 0 0 2rem;
    box-shadow: -10px 0 40px rgba(0, 0, 0, 0.15);
    z-index: 1;
}

/* Mobile brand header */
.mobile-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, var(--p-primary-500), var(--p-primary-600));
    color: var(--p-primary-contrast-color);
}

@media (min-width: 1100px) {
    .mobile-brand {
        display: none;
    }
}

.mobile-logo {
    height: 32px;
    width: auto;
    object-fit: contain;
}

.mobile-name {
    font-size: 1.125rem;
    font-weight: 600;
}

/* Form wrapper */
.form-wrapper {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}

@media (min-width: 640px) {
    .form-wrapper {
        padding: 3rem;
    }
}

.form-inner {
    width: 100%;
    max-width: 400px;
}

/* Form header */
.form-header {
    text-align: center;
    margin-bottom: 2rem;
}

.form-logo {
    display: block;
    height: 56px;
    max-width: 180px;
    object-fit: contain;
    margin: 0 auto 1.5rem auto;
}

.form-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--p-surface-900);
    margin-bottom: 0.5rem;
    letter-spacing: -0.02em;
}

.form-subtitle {
    font-size: 0.9375rem;
    color: var(--p-surface-500);
}

/* Error alert */
.error-alert {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    background: var(--p-red-50);
    border: 1px solid var(--p-red-200);
    border-radius: 12px;
    color: var(--p-red-600);
    font-size: 0.875rem;
    margin-bottom: 1.5rem;
}

.error-alert i {
    font-size: 1.125rem;
    flex-shrink: 0;
}

.slide-fade-enter-active {
    transition: all 0.3s ease;
}

.slide-fade-leave-active {
    transition: all 0.2s ease;
}

.slide-fade-enter-from,
.slide-fade-leave-to {
    transform: translateY(-10px);
    opacity: 0;
}

/* Login form */
.login-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.field-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.field label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--p-surface-700);
}

.forgot-link {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--p-primary-500);
    text-decoration: none;
    transition: color 0.2s;
}

.forgot-link:hover {
    color: var(--p-primary-600);
}

.input-wrapper {
    position: relative;
}

.field-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--p-surface-400);
    font-size: 1rem;
    z-index: 1;
    pointer-events: none;
}

.field-input,
:deep(.field-input) {
    width: 100%;
    padding: 0.875rem 1rem 0.875rem 2.75rem !important;
    font-size: 0.9375rem;
    border: 2px solid var(--p-surface-200);
    border-radius: 12px;
    background: var(--p-surface-50);
    transition: all 0.2s ease;
    color: var(--p-surface-900);
}

.field-input::placeholder,
:deep(.field-input::placeholder) {
    color: var(--p-surface-400);
}

.field-input:hover,
:deep(.field-input:hover) {
    border-color: var(--p-surface-300);
    background: var(--p-surface-0);
}

.field-input:focus,
:deep(.field-input:focus) {
    border-color: var(--p-primary-500);
    background: var(--p-surface-0);
    outline: none;
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--p-primary-500) 10%, transparent);
}

/* Remember row */
.remember-row {
    padding-top: 0.25rem;
}

.remember-label {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    cursor: pointer;
}

.remember-label span {
    font-size: 0.875rem;
    color: var(--p-surface-600);
}

/* Submit button - use PrimeVue default styling */
.submit-button {
    width: 100%;
    padding: 1rem 1.5rem;
    margin-top: 0.5rem;
    font-size: 0.9375rem;
    font-weight: 600;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.625rem;
    transition: all 0.3s ease;
}

.submit-button:not(:disabled):hover {
    transform: translateY(-2px);
}

.submit-button:not(:disabled):active {
    transform: translateY(0);
}

.submit-button i {
    font-size: 0.875rem;
    transition: transform 0.2s;
}

.submit-button:hover i {
    transform: translateX(4px);
}

/* Form footer */
.form-footer {
    margin-top: 2rem;
    text-align: center;
}

.security-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    background: var(--p-surface-100);
    border-radius: 100px;
    font-size: 0.75rem;
    color: var(--p-surface-500);
}

.security-badge i {
    color: var(--p-green-500);
}

/* Footer */
.login-footer {
    padding: 1.5rem;
    text-align: center;
    border-top: 1px solid var(--p-surface-100);
    font-size: 0.875rem;
    color: var(--p-surface-500);
}

.login-footer a {
    color: var(--p-primary-500);
    font-weight: 700;
    text-decoration: none;
    margin-left: 0.25rem;
}

.login-footer a:hover {
    text-decoration: underline;
}

/* ============================================
   RESPONSIVE
============================================ */
@media (max-width: 480px) {
    .form-wrapper {
        padding: 1.5rem;
    }

    .form-title {
        font-size: 1.5rem;
    }

    .brand-stats {
        flex-direction: column;
        gap: 1rem;
    }

    .stat-divider {
        width: 60px;
        height: 1px;
    }
}
</style>
