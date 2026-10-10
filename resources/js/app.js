import * as passkeys from './passkey';

window.helloTestPasskeys = passkeys;

// Surface a readable message when the browser cannot do WebAuthn at all.
if (!window.isSecureContext) {
    console.warn('[hello-test] Bukan secure context: WebAuthn tidak akan tersedia. Gunakan https://hello-test.local');
}
