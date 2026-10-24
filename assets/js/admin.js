(function () {
    'use strict';

    var cache = new Map();
    var prefetchTimer = null;

    function isPlainClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    function normalizeUrl(url) {
        var parsed = new URL(url, window.location.href);
        parsed.hash = '';
        return parsed.toString();
    }

    function fetchPage(url) {
        url = normalizeUrl(url);
        if (cache.has(url)) return cache.get(url);
        var promise = fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-MG-SaoLuu-Tab': '1' }
        }).then(function (response) {
            if (!response.ok) throw new Error('Không tải được tab.');
            return response.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var content = doc.querySelector('#mgsl-tab-content');
            var nav = doc.querySelector('[data-mgsl-tabs]');
            if (!content || !nav) throw new Error('Nội dung tab không hợp lệ.');
            return { content: content.innerHTML, nav: nav.innerHTML, title: doc.title };
        }).catch(function (error) {
            cache.delete(url);
            throw error;
        });
        cache.set(url, promise);
        return promise;
    }

    function setLoading(loading) {
        var indicator = document.getElementById('mgsl-tab-loading');
        var content = document.getElementById('mgsl-tab-content');
        if (indicator) indicator.hidden = !loading;
        if (content) content.classList.toggle('mgsl-is-loading', loading);
    }

    function loadTab(url, pushState) {
        setLoading(true);
        fetchPage(url).then(function (page) {
            var content = document.getElementById('mgsl-tab-content');
            var nav = document.querySelector('[data-mgsl-tabs]');
            if (!content || !nav) { window.location.href = url; return; }
            content.innerHTML = page.content;
            nav.innerHTML = page.nav;
            if (page.title) document.title = page.title;
            if (pushState) history.pushState({ mgslTab: true }, '', url);
            window.scrollTo({ top: Math.max(0, nav.getBoundingClientRect().top + window.scrollY - 42), behavior: 'auto' });
        }).catch(function () {
            window.location.href = url;
        }).finally(function () {
            setLoading(false);
        });
    }

    document.addEventListener('mouseover', function (event) {
        var link = event.target.closest('[data-mgsl-tab]');
        if (!link) return;
        clearTimeout(prefetchTimer);
        prefetchTimer = setTimeout(function () { fetchPage(link.href); }, 180);
    }, { passive: true });

    document.addEventListener('mouseout', function (event) {
        if (event.target.closest('[data-mgsl-tab]')) clearTimeout(prefetchTimer);
    }, { passive: true });

    document.addEventListener('focusin', function (event) {
        var link = event.target.closest('[data-mgsl-tab]');
        if (link) fetchPage(link.href);
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-mgsl-tab]');
        if (!link || !isPlainClick(event)) return;
        event.preventDefault();
        loadTab(link.href, true);
    });

    window.addEventListener('popstate', function () {
        if (document.querySelector('[data-mgsl-tabs]')) loadTab(window.location.href, false);
    });
})();
