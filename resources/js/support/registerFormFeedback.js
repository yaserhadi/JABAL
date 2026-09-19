/**
 * Shared password field feedback for public onboarding (/register + invitation activation).
 * Merges Laravel validation errors with live confirm-match messaging.
 */

function asMessages(value) {
    if (value == null || value === '') {
        return [];
    }
    if (Array.isArray(value)) {
        return value.map(String).filter(Boolean);
    }

    return [String(value)];
}

/**
 * @param {Record<string, string|string[]|undefined>} serverErrors
 * @param {string} [password]
 * @param {string} [confirmation]
 * @returns {{ password: string[], password_confirmation: string[] }}
 */
export function passwordFieldErrors(serverErrors = {}, password = '', confirmation = '') {
    const passwordMessages = asMessages(serverErrors.password);
    const confirmationMessages = asMessages(serverErrors.password_confirmation);

    const pwd = String(password ?? '');
    const conf = String(confirmation ?? '');

    if (pwd.length > 0 && conf.length > 0 && pwd !== conf) {
        const mismatch = 'Passwords do not match.';
        if (!confirmationMessages.includes(mismatch)) {
            confirmationMessages.push(mismatch);
        }
    }

    return {
        password: passwordMessages,
        password_confirmation: confirmationMessages,
    };
}

/**
 * @param {string} password
 * @param {string} confirmation
 * @returns {boolean}
 */
export function passwordsMatch(password, confirmation) {
    const pwd = String(password ?? '');
    const conf = String(confirmation ?? '');

    return pwd.length > 0 && conf.length > 0 && pwd === conf;
}
