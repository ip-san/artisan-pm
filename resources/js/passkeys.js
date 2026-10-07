// Passkeys (WebAuthn) against laravel/passkeys' endpoints. The server speaks the standard
// JSON form of the WebAuthn options and credentials, with binary fields as base64url, so this
// converts in both directions and nothing else; no library needed.
const toBuffer = (base64url) => {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64url.length / 4) * 4, '=');

    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
};

const toBase64url = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content
    ?? document.querySelector('script[data-csrf]')?.dataset.csrf
    ?? '';

async function request(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        ...options,
    });

    if (response.status === 423) {
        throw new Error('password-confirmation');
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message ?? Object.values(body.errors ?? {}).flat()[0] ?? `HTTP ${response.status}`);
    }

    return body;
}

export const supported = () => typeof window.PublicKeyCredential !== 'undefined';

/** Registers a new passkey for the signed-in user under the given name. */
export async function register(name, urls) {
    const { options } = await request(urls.options);
    const publicKey = {
        ...options,
        challenge: toBuffer(options.challenge),
        user: { ...options.user, id: toBuffer(options.user.id) },
        excludeCredentials: (options.excludeCredentials ?? []).map((credential) => ({ ...credential, id: toBuffer(credential.id) })),
    };
    const credential = await navigator.credentials.create({ publicKey });

    return request(urls.store, {
        method: 'POST',
        body: JSON.stringify({
            name,
            credential: {
                id: credential.id,
                rawId: toBase64url(credential.rawId),
                type: credential.type,
                authenticatorAttachment: credential.authenticatorAttachment ?? undefined,
                response: {
                    clientDataJSON: toBase64url(credential.response.clientDataJSON),
                    attestationObject: toBase64url(credential.response.attestationObject),
                    transports: credential.response.getTransports?.() ?? [],
                },
            },
        }),
    });
}

/** Signs in with a passkey and goes where the server says. */
export async function login(urls, remember = false) {
    const { options } = await request(urls.options);
    const publicKey = {
        ...options,
        challenge: toBuffer(options.challenge),
        allowCredentials: (options.allowCredentials ?? []).map((credential) => ({ ...credential, id: toBuffer(credential.id) })),
    };
    const credential = await navigator.credentials.get({ publicKey });
    const body = await request(urls.login, {
        method: 'POST',
        body: JSON.stringify({
            remember,
            credential: {
                id: credential.id,
                rawId: toBase64url(credential.rawId),
                type: credential.type,
                authenticatorAttachment: credential.authenticatorAttachment ?? undefined,
                response: {
                    clientDataJSON: toBase64url(credential.response.clientDataJSON),
                    authenticatorData: toBase64url(credential.response.authenticatorData),
                    signature: toBase64url(credential.response.signature),
                    userHandle: credential.response.userHandle ? toBase64url(credential.response.userHandle) : null,
                },
            },
        }),
    });

    window.location.href = body.redirect ?? '/';
}

window.ArtisanPasskeys = { supported, register, login };
