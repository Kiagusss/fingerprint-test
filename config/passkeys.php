<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party ID
    |--------------------------------------------------------------------------
    |
    | The relying party ID represents your application in the WebAuthn protocol.
    | This is typically your domain (e.g., "example.com"). Passkeys are bound
    | to this ID and can only be verified on matching domains.
    |
    */

    'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | The origins permitted to complete WebAuthn ceremonies. Passkeys bound
    | to the relying party ID above will only verify when the browser
    | reports one of these origins. Defaults to your application URL.
    |
    */

    'allowed_origins' => [
        config('app.url'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Handle Secret
    |--------------------------------------------------------------------------
    |
    | Secret used to derive a stable WebAuthn user handle from each user model.
    | Set this explicitly if you rotate your application key.
    |
    */

    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in milliseconds for WebAuthn operations. This determines
    | how long users have to complete passkey registration or verification.
    |
    */

    'timeout' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    |
    | The authentication guard to use when logging in users with passkeys.
    | This should match your application's primary authentication guard.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Authenticator yang Diizinkan
    |--------------------------------------------------------------------------
    |
    | Ditambahkan oleh aplikasi ini; tidak dibaca paket secara langsung, melainkan
    | oleh App\Passkeys\WindowsHelloRegistrationOptions yang menggantikan action
    | GenerateRegistrationOptions di container.
    |
    |   platform       -> hanya authenticator bawaan perangkat (Windows Hello,
    |                     Touch ID). Ini kebijakan aplikasi: setiap karyawan
    |                     memakai perangkat kerjanya sendiri.
    |   cross-platform -> hanya security key eksternal (YubiKey).
    |   no-preference  -> default paket: apa pun, termasuk ponsel lewat QR dan
    |                     passkey yang disinkronkan ke akun Microsoft/Google.
    |
    */

    'authenticator_attachment' => env('PASSKEYS_AUTHENTICATOR_ATTACHMENT', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn Hints (UI)
    |--------------------------------------------------------------------------
    |
    | Juga ditambahkan aplikasi ini, dibaca oleh action pengganti di App\Passkeys.
    | `hints` mengarahkan UI browser, bukan membatasi authenticator (itu tugas
    | authenticator_attachment di atas). Daftar dipisah koma, urut prioritas:
    |
    |   client-device -> authenticator di perangkat ini: Windows Hello, Touch ID.
    |   security-key  -> security key eksternal (YubiKey).
    |   hybrid        -> ponsel lewat QR / Bluetooth (lintas perangkat).
    |
    | Kosongkan untuk kembali ke perilaku default browser. Perlu diketahui:
    | Chrome mengabaikan hints bila authenticatorAttachment diisi, dan di Windows
    | dialog passkey dimiliki OS sehingga hints mungkin tidak berpengaruh.
    |
    */

    'hints' => env('PASSKEYS_HINTS', 'client-device'),

    /*
    |--------------------------------------------------------------------------
    | Passkeys Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Passkeys will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Passkeys Management Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify the middleware applied to passkey management routes
    | that create or delete passkeys. By default, Laravel's password
    | confirmation middleware is used.
    |
    */

    'management_middleware' => ['passkey.confirm'],

    /*
    |--------------------------------------------------------------------------
    | Passkeys Throttling
    |--------------------------------------------------------------------------
    |
    | Middleware used to throttle passkey endpoints. Set to null to disable.
    |
    */

    'throttle' => 'throttle:6,1',

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Tanpa Konfirmasi (khusus lokal)
    |--------------------------------------------------------------------------
    |
    | Menjalankan ceremony passkey memerlukan Windows Hello, dan Windows Hello
    | memerlukan origin https (secure context). Pada mesin pengembang yang diuji
    | lewat http://127.0.0.1:8099, ceremony tidak bisa berjalan sama sekali
    | sehingga passkey pertama tidak akan pernah bisa didaftarkan.
    |
    | Bila true DAN environment aplikasi `local`, sesi yang sudah terautentikasi
    | boleh mengubah passkey tanpa konfirmasi. Tidak berpengaruh di staging/
    | produksi karena di sana aplikasi selalu https. Setel ke false untuk
    | menutup jalur kenyamanan ini bahkan saat pengembangan.
    |
    */

    'bootstrap_without_confirmation' => env('PASSKEY_BOOTSTRAP_NO_CONFIRM', true),

    /*
    |--------------------------------------------------------------------------
    | Redirect
    |--------------------------------------------------------------------------
    |
    | The path to redirect to after successful passkey verification.
    |
    */

    'redirect' => '/',

];
