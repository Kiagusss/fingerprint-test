/*
 * WebAuthn / Windows Hello client for the PoC.
 *
 * Design notes
 * ------------
 * - No cryptography is implemented here. The browser performs the ceremony via
 *   navigator.credentials; this file only marshals JSON to/from base64url and
 *   talks to the laravel/passkeys endpoints.
 * - The server issues and verifies the challenge. This client never decides
 *   anything security-relevant: it cannot set the user, the role, or the RP ID.
 * - Server contract (matches Laravel\Passkeys\Support\WebAuthn::toBrowserArray
 *   and the package's FormRequests): every binary field travels as an unpadded
 *   base64url string in both directions.
 */

const b64urlToBuf = (value) => {
    const s = String(value).replace(/-/g, '+').replace(/_/g, '/');
    const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
    const bin = atob(s + pad);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i += 1) bytes[i] = bin.charCodeAt(i);
    return bytes.buffer;
};

const bufToB64url = (buf) => {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.length; i += 1) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

/** Decode the fields the browser needs as ArrayBuffers from a server options blob. */
const decodeCreationOptions = (options) => ({
    ...options,
    challenge: b64urlToBuf(options.challenge),
    user: { ...options.user, id: b64urlToBuf(options.user.id) },
    excludeCredentials: (options.excludeCredentials ?? []).map((c) => ({
        ...c,
        id: b64urlToBuf(c.id),
    })),
});

const decodeRequestOptions = (options) => ({
    ...options,
    challenge: b64urlToBuf(options.challenge),
    allowCredentials: (options.allowCredentials ?? []).map((c) => ({
        ...c,
        id: b64urlToBuf(c.id),
    })),
});

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const jsonFetch = async (url, { method = 'GET', body } = {}) => {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    let payload = null;
    try {
        payload = await res.json();
    } catch {
        payload = null;
    }

    if (!res.ok) {
        const message =
            payload?.message ??
            (payload?.errors ? Object.values(payload.errors).flat()[0] : null) ??
            `Permintaan gagal (HTTP ${res.status}).`;
        const error = new Error(message);
        error.status = res.status;
        error.payload = payload;
        throw error;
    }

    return payload;
};

/** Browser + platform capability report used by the landing page. */
export const detectSupport = async () => {
    const secure = window.isSecureContext === true;

    const hasApi =
        typeof window.PublicKeyCredential === 'function' &&
        !!navigator.credentials &&
        typeof navigator.credentials.create === 'function';

    let platformAuthenticator = false;
    let conditionalMediation = false;

    if (hasApi) {
        try {
            platformAuthenticator =
                await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        } catch {
            platformAuthenticator = false;
        }

        if (typeof window.PublicKeyCredential.isConditionalMediationAvailable === 'function') {
            try {
                conditionalMediation = await window.PublicKeyCredential.isConditionalMediationAvailable();
            } catch {
                conditionalMediation = false;
            }
        }
    }

    return {
        secureContext: secure,
        webauthnApi: hasApi,
        platformAuthenticator,
        conditionalMediation,
        https: window.location.protocol === 'https:',
        origin: window.location.origin,
        userAgent: navigator.userAgent,
    };
};

/** Register a new passkey for the authenticated user. Requires a prior confirm. */
export const registerPasskey = async (name) => {
    const { options } = await jsonFetch('/user/passkeys/options');

    const publicKey = decodeCreationOptions(options);

    let credential;
    try {
        credential = await navigator.credentials.create({ publicKey });
    } catch (e) {
        throw new Error(e?.name === 'NotAllowedError'
            ? 'Pendaftaran dibatalkan atau waktu Windows Hello habis.'
            : `Pendaftaran gagal: ${e?.message ?? e}`);
    }

    return jsonFetch('/user/passkeys', {
        method: 'POST',
        body: { name, credential: credentialToJson(credential) },
    });
};

/** Passwordless login. Passkey identity resolves to the owning user server-side. */
export const loginWithPasskey = async () => {
    const { options } = await jsonFetch('/passkeys/login/options');

    const publicKey = decodeRequestOptions(options);

    let assertion;
    try {
        assertion = await navigator.credentials.get({ publicKey });
    } catch (e) {
        throw new Error(e?.name === 'NotAllowedError'
            ? 'Login Windows Hello dibatalkan atau waktu habis.'
            : `Login Windows Hello gagal: ${e?.message ?? e}`);
    }

    return jsonFetch('/passkeys/login', {
        method: 'POST',
        body: { credential: credentialToJson(assertion), remember: false },
    });
};

/** Re-authentication for passkey management (password.confirm replacement). */
export const confirmWithPasskey = async () => {
    const { options } = await jsonFetch('/passkeys/confirm/options');

    const publicKey = decodeRequestOptions(options);

    let assertion;
    try {
        assertion = await navigator.credentials.get({ publicKey });
    } catch (e) {
        throw new Error(e?.name === 'NotAllowedError'
            ? 'Konfirmasi dibatalkan atau waktu habis.'
            : `Konfirmasi gagal: ${e?.message ?? e}`);
    }

    return jsonFetch('/passkeys/confirm', {
        method: 'POST',
        body: { credential: credentialToJson(assertion) },
    });
};

export const deletePasskey = (id) => jsonFetch(`/user/passkeys/${id}`, { method: 'DELETE' });

// ---------------------------------------------------------------------------
// Pendaftaran sidik jari karyawan (dilakukan oleh admin, lintas pengguna).
//
// Berbeda dari registerPasskey() yang selalu mendaftarkan untuk user yang
// sedang login, dua fungsi ini menyasar karyawan yang dipilih admin. Otorisasi
// sepenuhnya di server: id karyawan diteruskan dan divalidasi di sana, sidik
// jari tetap diverifikasi oleh validator WebAuthn paket.
// ---------------------------------------------------------------------------

/**
 * Ambil opsi registrasi untuk karyawan target.
 *
 * @param {number|string} employeeId
 */
export const requestEmployeeEnrollmentOptions = async (employeeId) => {
    const { options } = await jsonFetch(`/employees/${employeeId}/options`, { method: 'POST' });

    return options;
};

/**
 * Daftarkan passkey milik karyawan target (sidik jari ditempel di perangkatnya).
 */
export const enrollEmployeePasskey = async (employeeId, name) => {
    const options = await requestEmployeeEnrollmentOptions(employeeId);

    const publicKey = decodeCreationOptions(options);

    let credential;
    try {
        credential = await navigator.credentials.create({ publicKey });
    } catch (e) {
        throw new Error(e?.name === 'NotAllowedError'
            ? 'Pendaftaran dibatalkan atau waktu Windows Hello habis.'
            : `Pendaftaran gagal: ${e?.message ?? e}`);
    }

    return jsonFetch(`/employees/${employeeId}/passkey`, {
        method: 'POST',
        body: { name, credential: credentialToJson(credential) },
    });
};

/** Cabut passkey milik karyawan. */
export const revokeEmployeePasskey = (passkeyId) =>
    jsonFetch(`/employees/passkeys/${passkeyId}`, { method: 'DELETE' });

/**
 * Buat credential saat bootstrap (belum ada user login sama sekali).
 *
 * Endpoint bootstrap mengembalikan opsi registrasi langsung, jadi fungsi ini
 * hanya menjalankan ceremony dan mengembalikan credential dalam bentuk JSON siap
 * kirim — pemanggil yang mengirimkannya sendiri ke /bootstrap/passkey.
 */
export const bootstrapPublicKey = async (options) => {
    const publicKey = decodeCreationOptions(options);

    let credential;
    try {
        credential = await navigator.credentials.create({ publicKey });
    } catch (e) {
        throw new Error(e?.name === 'NotAllowedError'
            ? 'Pendaftaran dibatalkan atau waktu Windows Hello habis.'
            : `Pendaftaran gagal: ${e?.message ?? e}`);
    }

    return credentialToJson(credential);
};

/** Flatten a PublicKeyCredential into the base64url JSON the server expects. */
const credentialToJson = (cred) => {
    const response = cred.response;

    const json = {
        id: cred.id,
        rawId: bufToB64url(cred.rawId),
        type: cred.type,
        response: {},
    };

    const copyIfPresent = (key) => {
        if (response[key] !== undefined && response[key] !== null) {
            json.response[key] = response[key] instanceof ArrayBuffer
                ? bufToB64url(response[key])
                : response[key];
        }
    };

    ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle'].forEach(copyIfPresent);

    if (typeof cred.authenticatorAttachment === 'string') {
        json.authenticatorAttachment = cred.authenticatorAttachment;
    }

    if (typeof response.getTransports === 'function') {
        try {
            json.response.transports = response.getTransports();
        } catch {
            /* optional */
        }
    }

    return json;
};

export default {
    detectSupport,
    registerPasskey,
    loginWithPasskey,
    confirmWithPasskey,
    deletePasskey,
    requestEmployeeEnrollmentOptions,
    enrollEmployeePasskey,
    revokeEmployeePasskey,
    bootstrapPublicKey,
};
