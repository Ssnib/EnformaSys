(function () {
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
        return '';
    }

    function buildUrl(url, params) {
        var u = new URL(url, window.location.href);
        if (params) {
            Object.keys(params).forEach(function (k) {
                if (params[k] === undefined || params[k] === null || params[k] === '') return;
                u.searchParams.set(k, params[k]);
            });
        }
        return u.toString();
    }

    async function jsonFetch(url, opts) {
        var o = opts || {};
        o.headers = o.headers || {};
        o.headers['Accept'] = 'application/json';
        if (o.method && o.method.toUpperCase() !== 'GET') {
            o.headers['X-CSRF-Token'] = csrfToken();
        }
        var res = await fetch(url, o);
        var text = await res.text();
        var data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (e) {
            throw new Error('Invalid JSON response');
        }
        if (!res.ok || !data || data.ok !== true) {
            var msg = (data && (data.error || data.message)) ? (data.error || data.message) : ('Request failed (' + res.status + ')');
            throw new Error(msg);
        }
        return data;
    }

    async function get(url, params) {
        return jsonFetch(buildUrl(url, params), { method: 'GET' });
    }

    async function post(url, body) {
        return jsonFetch(url, { method: 'POST', body: body });
    }

    window.CapstoneAjax = { get: get, post: post, csrfToken: csrfToken };
})();
