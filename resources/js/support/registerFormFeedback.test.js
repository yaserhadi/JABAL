import { describe, expect, it } from 'vitest';
import { passwordFieldErrors, passwordsMatch } from './registerFormFeedback.js';

describe('registerFormFeedback', () => {
    it('passes through Laravel password errors', () => {
        const result = passwordFieldErrors({
            password: ['The password field must be at least 8 characters.'],
        });

        expect(result.password).toEqual(['The password field must be at least 8 characters.']);
        expect(result.password_confirmation).toEqual([]);
    });

    it('adds live mismatch when both fields are filled and differ', () => {
        const result = passwordFieldErrors({}, 'Secret123!', 'Secret123');

        expect(result.password_confirmation).toContain('Passwords do not match.');
    });

    it('does not add mismatch when confirmation is still empty', () => {
        const result = passwordFieldErrors({}, 'Secret123!', '');

        expect(result.password_confirmation).toEqual([]);
    });

    it('does not duplicate mismatch when server already reported it', () => {
        const result = passwordFieldErrors(
            { password_confirmation: ['Passwords do not match.'] },
            'a',
            'b'
        );

        expect(result.password_confirmation).toEqual(['Passwords do not match.']);
    });

    it('passwordsMatch requires both non-empty and equal', () => {
        expect(passwordsMatch('x', 'x')).toBe(true);
        expect(passwordsMatch('x', 'y')).toBe(false);
        expect(passwordsMatch('x', '')).toBe(false);
        expect(passwordsMatch('', '')).toBe(false);
    });
});
