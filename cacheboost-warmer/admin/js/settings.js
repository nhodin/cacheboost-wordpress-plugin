(function () {
    // ── Boost dropdown ────────────────────────────────────────────────────

    var boostSelect = document.getElementById('cb_boost_id');
    var noBoostEl   = document.getElementById('cb-no-boost-msg');
    var savedBoostId = CacheBoostSettings.savedBoostId;
    var boostI18n = {
        loading:     CacheBoostSettings.i18n.loading,
        noApiKey:    CacheBoostSettings.i18n.noApiKey,
        noSiteId:    CacheBoostSettings.i18n.noSiteId,
        loadFailed:  CacheBoostSettings.i18n.loadFailed,
        noBoosts:    CacheBoostSettings.i18n.noBoosts,
        stale:       CacheBoostSettings.i18n.stale,
    };

    function loadBoosts() {
        boostSelect.disabled = true;
        boostSelect.innerHTML = '<option value="">' + boostI18n.loading + '</option>';
        noBoostEl.style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'cacheboost_fetch_boosts');
        fd.append('nonce',  CacheBoostSettings.nonce);

        fetch(CacheBoostSettings.ajaxUrl, {
            method: 'POST', body: fd, credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (!json.success) {
                var code = json.data ? json.data.code : 'api_error';
                var msg  = code === 'no_api_key' ? boostI18n.noApiKey
                         : code === 'no_site_id' ? boostI18n.noSiteId
                         : boostI18n.loadFailed;
                boostSelect.innerHTML = '<option value="">' + msg + '</option>';
                boostSelect.disabled = true;
                return;
            }
            populateBoosts(json.data.boosts || []);
        })
        .catch(function () {
            boostSelect.innerHTML = '<option value="">' + boostI18n.loadFailed + '</option>';
            boostSelect.disabled = false;
        });
    }

    function populateBoosts(boosts) {
        boostSelect.innerHTML = '';
        if (boosts.length === 0) {
            boostSelect.innerHTML = '<option value="">' + boostI18n.noBoosts + '</option>';
            boostSelect.disabled = true;
            noBoostEl.style.display = 'block';
            return;
        }

        var found = false;
        boosts.forEach(function (boost) {
            var opt = document.createElement('option');
            opt.value = boost.id;
            opt.textContent = boost.name + ' (#' + boost.id + ')';
            if (String(boost.id) === savedBoostId) { opt.selected = true; found = true; }
            boostSelect.appendChild(opt);
        });

        if (!found && savedBoostId) {
            var stale = document.createElement('option');
            stale.value = '';
            stale.textContent = boostI18n.stale;
            stale.selected = true;
            boostSelect.insertBefore(stale, boostSelect.firstChild);
        }
        boostSelect.disabled = false;
    }

    if (boostSelect) loadBoosts();

    // ── Test connection ───────────────────────────────────────────────────

    var btn    = document.getElementById('cb-test-connection');
    var result = document.getElementById('cb-test-result');

    btn.addEventListener('click', function () {
        btn.disabled     = true;
        result.textContent = CacheBoostSettings.i18n.testing;
        result.style.color = '';

        var data = new FormData();
        data.append('action', 'cacheboost_test_connection');
        data.append('nonce',  CacheBoostSettings.nonce);

        fetch(CacheBoostSettings.ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.success) {
                result.textContent = json.data.message;
                result.style.color = 'green';
                if (json.data.regions && json.data.regions.length > 0) {
                    cbRenderRegions(json.data.regions);
                }
            } else {
                result.textContent = json.data.message;
                result.style.color = '#cc0000';
            }
        })
        .catch(function () {
            result.textContent = CacheBoostSettings.i18n.requestFailed;
            result.style.color = '#cc0000';
        })
        .finally(function () {
            btn.disabled = false;
        });
    });

    var clearBtn = document.getElementById('cb-clear-logs');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            clearBtn.disabled = true;
            var data = new FormData();
            data.append('action', 'cacheboost_clear_logs');
            data.append('nonce',  CacheBoostSettings.clearLogsNonce);
            fetch(CacheBoostSettings.ajaxUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
            }).then(function () {
                document.getElementById('cb-logs-accordion').querySelector('table')?.remove();
                clearBtn.remove();
                var acc = document.getElementById('cb-logs-accordion');
                var p = document.createElement('p');
                p.className = 'description';
                p.textContent = CacheBoostSettings.i18n.noActivity;
                acc.appendChild(p);
            });
        });
    }

    function cbEsc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function cbRenderRegions(available) {
        var labels = CacheBoostSettings.regionLabels;
        // Read the current checked state from the live form (not the PHP-baked saved state)
        var currentChecked = Array.from(document.querySelectorAll('input[name="cacheboost_options[regions][]"]:checked')).map(function(el) { return el.value; });
        var saved = CacheBoostSettings.selectedRegions;
        var selected = currentChecked.length > 0 ? currentChecked : saved;
        var td = document.querySelector('#cb-regions-row td');
        var html = '<fieldset>';
        available.forEach(function (slug) {
            var label = labels[slug] || slug.toUpperCase();
            var checked = selected.indexOf(slug) !== -1 ? ' checked' : '';
            html += '<label style="margin-right:16px">'
                  + '<input type="checkbox" name="cacheboost_options[regions][]" value="' + cbEsc(slug) + '"' + checked + '> '
                  + cbEsc(label) + '</label>';
        });
        html += '</fieldset>';
        html += '<p class="description">'
              + CacheBoostSettings.i18n.regionsHelp
              + '</p>';
        td.innerHTML = html;
    }
})();
