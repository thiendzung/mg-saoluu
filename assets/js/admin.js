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

    // Real feedback for synchronous POST requests; no invented percentages or polling.
    var operations = {
        backup: 'Đang sao lưu và kiểm tra…',
        pack_backup: 'Đang tạo Pack mã hóa…',
        verify_backup: 'Đang xác minh…',
        verify_restore_upload: 'Đang upload và xác minh file sao lưu…',
        pack_restore: 'Đang xác minh Pack và chuẩn bị Restore…',
        pack_restore_upload: 'Đang upload, xác minh Pack và chuẩn bị Restore…',
        restore_saved: 'Đang khôi phục bản đang lưu…',
        restore_zip_upload: 'Đang tải file lên và khôi phục…',
        restore_sql_upload: 'Đang nhập SQL…'
    };

    function mgslValidateUploadField(field) {
        var limit = Number(field.getAttribute('data-mgsl-max-upload')) || 0;
        var picked = field.files && field.files[0];
        var allowed = (field.getAttribute('data-mgsl-extensions') || '')
            .toLowerCase().split(',').map(function (item) { return item.trim(); }).filter(Boolean);
        var name = picked ? String(picked.name || '').toLowerCase() : '';
        var tooLarge = !!(picked && limit && picked.size > limit);
        var wrongType = !!(picked && allowed.length && !allowed.some(function (extension) {
            return name.endsWith(extension);
        }));
        var message = '';
        if (tooLarge) message = 'File vượt giới hạn upload hiện tại.';
        else if (wrongType) message = 'Loại file không được hỗ trợ. Chỉ nhận: ' + allowed.join(', ') + '.';
        field.setCustomValidity(message);

        var form = field.closest('form');
        var zone = field.closest('[data-mgsl-dropzone]');
        if (zone) {
            zone.classList.toggle('has-file', !!picked && !message);
            var fileName = zone.querySelector('[data-mgsl-upload-name]');
            if (fileName) fileName.textContent = picked ? picked.name : 'Chưa chọn file';
        }
        if (form) {
            var submit = form.querySelector('button[type="submit"], input[type="submit"], button.button-primary');
            if (submit) {
                if (message) {
                    if (!submit.disabled) submit.dataset.mgslUploadDisabled = '1';
                    submit.disabled = true;
                } else if (submit.dataset.mgslUploadDisabled === '1') {
                    submit.disabled = false;
                    delete submit.dataset.mgslUploadDisabled;
                }
            }
            var warning = form.querySelector('[data-mgsl-upload-warning]');
            if (warning) {
                warning.textContent = message;
                warning.hidden = !message;
            }
        }
        if (message) field.reportValidity();
    }

    document.addEventListener('change', function (event) {
        var field = event.target.closest('input[type="file"][data-mgsl-max-upload]');
        if (field) mgslValidateUploadField(field);
    });

    document.addEventListener('dragenter', function (event) {
        var zone = event.target.closest('[data-mgsl-dropzone]');
        if (!zone) return;
        event.preventDefault();
        zone.classList.add('is-dragging');
    });

    document.addEventListener('dragover', function (event) {
        var zone = event.target.closest('[data-mgsl-dropzone]');
        if (!zone) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
        zone.classList.add('is-dragging');
    });

    document.addEventListener('dragleave', function (event) {
        var zone = event.target.closest('[data-mgsl-dropzone]');
        if (!zone) return;
        var related = event.relatedTarget;
        if (!related || !zone.contains(related)) zone.classList.remove('is-dragging');
    });

    document.addEventListener('drop', function (event) {
        var zone = event.target.closest('[data-mgsl-dropzone]');
        if (!zone) return;
        event.preventDefault();
        zone.classList.remove('is-dragging');
        var field = zone.querySelector('input[type="file"][data-mgsl-max-upload]');
        var files = event.dataTransfer && event.dataTransfer.files;
        if (!field || !files || !files.length) return;
        try {
            var transfer = new DataTransfer();
            transfer.items.add(files[0]);
            field.files = transfer.files;
        } catch (error) {
            try { field.files = files; } catch (ignored) { return; }
        }
        field.dispatchEvent(new Event('change', { bubbles: true }));
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        var actionField = form.querySelector('input[name="mg_action"]');
        var action = actionField && actionField.value;
        if (!Object.prototype.hasOwnProperty.call(operations, action)) return;
        if (!form.checkValidity()) return;
        if (form.dataset.mgslSubmitting === '1') { event.preventDefault(); return; }
        form.dataset.mgslSubmitting = '1';
        var status = document.createElement('p');
        status.className = 'mgsl-operation-pending';
        status.setAttribute('role', 'status');
        status.textContent = operations[action] + ' Vui lòng chờ máy chủ trả kết quả; không gửi lại.';
        form.appendChild(status);
        // One-off callback after native form submit, no status polling.
        window.setTimeout(function () {
            form.querySelectorAll('button').forEach(function (button) {
                if (!button.disabled) {
                    button.disabled = true;
                    button.dataset.mgslTemporaryDisabled = '1';
                }
            });
        }, 0);
    });

    window.addEventListener('pageshow', function () {
        // Restore controls after browser back/forward cache (never unlock originally disabled buttons).
        document.querySelectorAll('form[data-mgsl-submitting]').forEach(function (form) {
            delete form.dataset.mgslSubmitting;
            form.querySelectorAll('.mgsl-operation-pending').forEach(function (item) { item.remove(); });
            form.querySelectorAll('[data-mgsl-temporary-disabled]').forEach(function (button) {
                button.disabled = false;
                delete button.dataset.mgslTemporaryDisabled;
            });
        });
    });
})();
