/**
 * PostVote extension for phpBB – client-side AJAX voting.
 *
 * Reads configuration from <meta> tags injected by the listener:
 *   postvote-vote-url      – AJAX endpoint URL
 *   postvote-creation-time – CSRF creation timestamp
 *   postvote-token         – CSRF form token
 *
 * @license GNU General Public License, version 2 (GPL-2.0)
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------
    const meta = {
        voteUrl:      getMeta('postvote-vote-url'),
        creationTime: getMeta('postvote-creation-time'),
        token:        getMeta('postvote-token'),
    };

    if (!meta.voteUrl) {
        // PostVote not active on this page
        return;
    }

    // -------------------------------------------------------------------------
    // Event delegation – handle all vote button clicks
    // -------------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.postvote-btn');
        if (!btn) return;

        e.preventDefault();

        const widget = btn.closest('.postvote-widget');
        if (!widget) return;

        const postId    = parseInt(widget.dataset.postId, 10);
        const voteValue = parseInt(btn.dataset.value, 10);

        if (!postId || isNaN(voteValue)) return;

        // Disable buttons while request is in flight
        setWidgetLoading(widget, true);

        submitVote(postId, voteValue)
            .then(function (data) {
                if (data.success) {
                    updateWidget(widget, data);
                } else {
                    showToast(data.error || 'Error', true);
                }
            })
            .catch(function () {
                showToast('Network error', true);
            })
            .finally(function () {
                setWidgetLoading(widget, false);
            });
    });

    // -------------------------------------------------------------------------
    // AJAX helpers
    // -------------------------------------------------------------------------
    function submitVote(postId, voteValue) {
        const body = new URLSearchParams({
            post_id:       postId,
            vote:          voteValue,
            creation_time: meta.creationTime,
            form_token:    meta.token,
        });

        return fetch(meta.voteUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
        }).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () { return { success: false, error: 'HTTP ' + res.status }; });
            }
            return res.json();
        });
    }

    // -------------------------------------------------------------------------
    // DOM update helpers
    // -------------------------------------------------------------------------
    function updateWidget(widget, data) {
        const upBtn    = widget.querySelector('.postvote-up');
        const downBtn  = widget.querySelector('.postvote-down');
        const scoreEl  = widget.querySelector('.postvote-score');

        if (scoreEl) {
            const score = data.score;
            scoreEl.textContent = score;
            scoreEl.classList.remove('postvote-score-positive', 'postvote-score-negative');
            if (score > 0)       scoreEl.classList.add('postvote-score-positive');
            else if (score < 0)  scoreEl.classList.add('postvote-score-negative');

            // Build title with individual counts
            scoreEl.title = '▲ ' + data.upvotes + ' / ▼ ' + data.downvotes;
        }

        const userVote = data.user_vote;
        if (upBtn)   upBtn.classList.toggle('postvote-active',   userVote === 1);
        if (downBtn) downBtn.classList.toggle('postvote-active', userVote === -1);
    }

    function setWidgetLoading(widget, loading) {
        widget.querySelectorAll('.postvote-btn').forEach(function (btn) {
            btn.disabled = loading;
        });
    }

    // -------------------------------------------------------------------------
    // Toast notification
    // -------------------------------------------------------------------------
    let toastEl = null;
    let toastTimer = null;

    function showToast(message, isError) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'postvote-toast';
            document.body.appendChild(toastEl);
        }

        toastEl.textContent = message;
        toastEl.classList.toggle('error', !!isError);

        // Force reflow so the transition fires
        void toastEl.offsetWidth;
        toastEl.classList.add('show');

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toastEl.classList.remove('show');
        }, 3000);
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------
    function getMeta(name) {
        const el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : null;
    }

}());
