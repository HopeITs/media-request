/* Passkeys: sign in with the device (fingerprint, face, PIN), and add or remove one. The address is checked by the browser, so a look-alike site cannot use it. */
window.mrPasskeys = (function () {
    var bytes = function (s) { return Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), function (c) { return c.charCodeAt(0); }); };
    var text = function (b) { return btoa(String.fromCharCode.apply(null, new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); };
    var token = function () { return document.querySelector('meta[name=csrf-token]').content; };

    var call = async function (method, url, body) {
        var r = await fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!r.ok) { var e = new Error('request failed'); e.status = r.status; throw e; }
        return r.status === 204 ? {} : r.json();
    };
    var ids = function (list) { return (list || []).map(function (c) { return Object.assign({}, c, { id: bytes(c.id) }); }); };

    return {
        supported: function () { return !!window.PublicKeyCredential && !!navigator.credentials; },

        /** Returns where to go next. */
        signIn: async function () {
            var o = (await call('GET', '/passkeys/login/options')).options;
            var c = await navigator.credentials.get({ publicKey: Object.assign({}, o, { challenge: bytes(o.challenge), allowCredentials: ids(o.allowCredentials) }) });
            var done = await call('POST', '/passkeys/login', {
                remember: true,
                credential: {
                    id: c.id, rawId: text(c.rawId), type: c.type,
                    response: {
                        authenticatorData: text(c.response.authenticatorData),
                        clientDataJSON: text(c.response.clientDataJSON),
                        signature: text(c.response.signature),
                        userHandle: c.response.userHandle ? text(c.response.userHandle) : null,
                    },
                },
            });
            return done.redirect;
        },

        add: async function (name) {
            var o = (await call('GET', '/user/passkeys/options')).options;
            var c = await navigator.credentials.create({ publicKey: Object.assign({}, o, {
                challenge: bytes(o.challenge),
                user: Object.assign({}, o.user, { id: bytes(o.user.id) }),
                excludeCredentials: ids(o.excludeCredentials),
            }) });
            await call('POST', '/user/passkeys', {
                name: name,
                credential: {
                    id: c.id, rawId: text(c.rawId), type: c.type,
                    response: {
                        clientDataJSON: text(c.response.clientDataJSON),
                        attestationObject: text(c.response.attestationObject),
                        transports: c.response.getTransports ? c.response.getTransports() : [],
                    },
                },
            });
        },

        remove: function (id) { return call('DELETE', '/user/passkeys/' + id); },

        /** A first name for a new passkey: the kind of device. */
        deviceName: function () {
            var u = navigator.userAgent;
            return /iPhone/.test(u) ? 'iPhone' : /iPad/.test(u) ? 'iPad' : /Android/.test(u) ? 'Android' : /Mac/.test(u) ? 'Mac' : /Windows/.test(u) ? 'Windows' : 'Device';
        },

        /** The person cancelled, or the device has none: nothing went wrong. */
        cancelled: function (e) { return e && (e.name === 'NotAllowedError' || e.name === 'AbortError'); },
    };
})();
