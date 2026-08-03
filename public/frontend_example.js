/**
 * BYD AI Voice Assistant - Secure Frontend Integration Flow
 *
 * This file demonstrates how to securely initialize a voice assistant session
 * by solving a Captcha challenge, obtaining a secure JWT session cookie,
 * and authorizing the Vapi.ai Web SDK using the integration layer.
 *
 * Dependencies:
 *  - Cloudflare Turnstile (or Google reCAPTCHA)
 *  - Vapi Web SDK (https://unpkg.com/@vapi-ai/web)
 */

class BydVoiceAssistantManager {
    constructor(config = {}) {
        this.apiBaseUrl = config.apiBaseUrl || '';
        this.csrfToken = null;
        this.sessionId = null;
        this.vapi = null;
        this.captchaToken = null;

        // Turnstile Site Key (replace with your actual site key)
        this.turnstileSiteKey = config.turnstileSiteKey || '1x00000000000000000000AA';
    }

    /**
     * Step 1: Initialize the Captcha widget on the page
     * Can be Cloudflare Turnstile or Google reCAPTCHA v3
     */
    initCaptcha(containerId) {
        if (typeof turnstile === 'undefined') {
            console.error('Cloudflare Turnstile script is not loaded.');
            return;
        }

        turnstile.render(containerId, {
            sitekey: this.turnstileSiteKey,
            callback: (token) => {
                console.log('Captcha solved successfully.');
                this.captchaToken = token;
                this.onCaptchaSuccess(token);
            },
            'error-callback': (err) => {
                console.error('Captcha challenge failed:', err);
                this.onCaptchaError(err);
            }
        });
    }

    /**
     * Step 2: Send Captcha token to backend to initialize secure session
     * Backend validates Captcha, sets HttpOnly JWT Cookie, and returns a CSRF Token.
     */
    async initializeSession() {
        if (!this.captchaToken) {
            throw new Error('Please solve the Captcha challenge first.');
        }

        console.log('Initializing secure session on backend...');
        const response = await fetch(`${this.apiBaseUrl}/api/init-session`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                captcha_token: this.captchaToken
            })
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Failed to initialize session');
        }

        // Store CSRF token in memory and session ID
        this.csrfToken = data.csrf_token;
        this.sessionId = data.session_id;

        console.log('Session initialized. Secure JWT Cookie set. CSRF token acquired.');
        return data;
    }

    /**
     * Step 3: Request authorization to start the Vapi voice session
     * Backend checks JWT cookie, CSRF token, and Rate Limiting before granting permission.
     */
    async authorizeVapiCall() {
        if (!this.csrfToken) {
            throw new Error('Session is not initialized. Call initializeSession() first.');
        }

        console.log('Requesting Vapi call authorization from backend...');
        const response = await fetch(`${this.apiBaseUrl}/api/vapi-auth`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken // Secure CSRF validation
            }
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Vapi call authorization denied');
        }

        console.log('Vapi call authorized successfully.');
        return data; // Contains publicKey, assistantId, and sessionId
    }

    /**
     * Step 4: Initialize and start the Vapi.ai Voice Call
     */
    async startVoiceCall(authData) {
        if (typeof Vapi === 'undefined') {
            throw new Error('Vapi SDK is not loaded. Please include the SDK script.');
        }

        console.log('Starting voice assistant call...');
        // Initialize Vapi SDK with backend-supplied credentials
        this.vapi = new Vapi(authData.publicKey);

        // Start call with authorized Assistant ID and session metadata
        await this.vapi.start(authData.assistantId, {
            variableValues: {
                externalCallId: authData.sessionId
            }
        });

        // Event hooks
        this.vapi.on('call-start', () => console.log('Voice assistant call connected!'));
        this.vapi.on('call-end', () => console.log('Voice assistant call ended.'));
        this.vapi.on('error', (err) => console.error('Vapi SDK error:', err));
    }

    // Callbacks to override
    onCaptchaSuccess(token) {}
    onCaptchaError(err) {}
}

// ─── Usage Example ───────────────────────────────────────────────────────────
/*
// 1. Instantiate the manager
const assistantManager = new BydVoiceAssistantManager({
    apiBaseUrl: window.location.origin,
    turnstileSiteKey: 'YOUR_CLOUDFLARE_TURNSTILE_SITE_KEY'
});

// 2. Render captcha on page load
window.addEventListener('DOMContentLoaded', () => {
    assistantManager.initCaptcha('#captcha-container');
});

// 3. User clicks "Start Call" button
async function handleStartCallButtonClick() {
    try {
        // A. Initialize secure session (validates captcha & gets CSRF/JWT)
        await assistantManager.initializeSession();

        // B. Request voice authorization (verifies JWT, CSRF, and Rate limits)
        const authData = await assistantManager.authorizeVapiCall();

        // C. Launch Vapi call safely
        await assistantManager.startVoiceCall(authData);

    } catch (error) {
        alert(`Error starting call: ${error.message}`);
    }
}
*/
