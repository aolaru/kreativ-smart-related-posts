(function () {
    const config = window.srpAdmin || {};

    function parseIds(value) {
        return String(value || '')
            .split(',')
            .map(function (id) {
                return parseInt(id, 10);
            })
            .filter(function (id, index, ids) {
                return id > 0 && ids.indexOf(id) === index;
            });
    }

    function updateHidden(picker, ids) {
        picker.querySelector('.srp-post-picker-value').value = ids.join(',');
    }

    function renderToken(picker, item) {
        const selected = picker.querySelector('.srp-post-picker-selected');
        const button = document.createElement('button');
        const title = document.createElement('span');
        const remove = document.createElement('span');

        button.type = 'button';
        button.className = 'srp-post-picker-token';
        button.dataset.id = item.id;
        button.setAttribute('aria-label', (config.strings && config.strings.remove ? config.strings.remove : 'Remove') + ': ' + item.title);

        title.textContent = item.title;
        remove.className = 'srp-post-picker-remove';
        remove.setAttribute('aria-hidden', 'true');
        remove.textContent = 'x';

        button.appendChild(title);
        button.appendChild(remove);
        selected.appendChild(button);
    }

    function getSelectedIds(picker) {
        return parseIds(picker.querySelector('.srp-post-picker-value').value);
    }

    function addItem(picker, item) {
        const ids = getSelectedIds(picker);
        const id = parseInt(item.id, 10);

        if (!id || ids.indexOf(id) !== -1) {
            return;
        }

        ids.push(id);
        updateHidden(picker, ids);
        renderToken(picker, { id: id, title: item.title });
    }

    function clearResults(results) {
        results.hidden = true;
        results.innerHTML = '';
    }

    function renderResults(picker, posts) {
        const results = picker.querySelector('.srp-post-picker-results');
        const selected = getSelectedIds(picker);
        const available = posts.filter(function (post) {
            return selected.indexOf(parseInt(post.id, 10)) === -1;
        });

        results.innerHTML = '';
        results.hidden = false;

        if (!available.length) {
            const empty = document.createElement('div');
            empty.className = 'srp-post-picker-empty';
            empty.textContent = config.strings && config.strings.noResults ? config.strings.noResults : 'No posts found.';
            results.appendChild(empty);
            return;
        }

        available.forEach(function (post) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'srp-post-picker-result';
            button.dataset.id = post.id;
            button.dataset.title = post.title;
            button.textContent = post.title;
            results.appendChild(button);
        });
    }

    function searchPosts(picker, term) {
        const results = picker.querySelector('.srp-post-picker-results');
        const params = new URLSearchParams({
            action: 'srp_search_posts',
            nonce: config.nonce || '',
            term: term
        });

        results.hidden = false;
        results.innerHTML = '<div class="srp-post-picker-empty">' + (config.strings && config.strings.searching ? config.strings.searching : 'Searching...') + '</div>';

        window.fetch((config.ajaxUrl || window.ajaxurl) + '?' + params.toString(), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                renderResults(picker, payload && payload.success && Array.isArray(payload.data) ? payload.data : []);
            })
            .catch(function () {
                renderResults(picker, []);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.srp-post-picker').forEach(function (picker) {
            let timer = null;
            const search = picker.querySelector('.srp-post-picker-search');
            const results = picker.querySelector('.srp-post-picker-results');

            picker.addEventListener('click', function (event) {
                const token = event.target.closest('.srp-post-picker-token');
                const result = event.target.closest('.srp-post-picker-result');

                if (token && picker.contains(token)) {
                    const id = parseInt(token.dataset.id, 10);
                    const ids = getSelectedIds(picker).filter(function (selectedId) {
                        return selectedId !== id;
                    });
                    token.remove();
                    updateHidden(picker, ids);
                    return;
                }

                if (result && picker.contains(result)) {
                    addItem(picker, {
                        id: result.dataset.id,
                        title: result.dataset.title
                    });
                    search.value = '';
                    clearResults(results);
                }
            });

            search.addEventListener('input', function () {
                const term = search.value.trim();
                window.clearTimeout(timer);

                if (term.length < 2) {
                    clearResults(results);
                    return;
                }

                timer = window.setTimeout(function () {
                    searchPosts(picker, term);
                }, 250);
            });
        });
    });
}());
