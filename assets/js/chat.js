/* ideaBot v1.1.2 — AI Conversational Chat (Claude-powered) */
(function () {
    'use strict';

    var cfg      = window.ideabotCFG || {};
    var ajax     = cfg.ajaxUrl || '';
    var nonce    = cfg.nonce   || '';
    var M        = cfg.messages || {};
    var display  = cfg.display  || {};
    var ga4Id    = cfg.ga4Id    || '';
    var pixelId  = cfg.pixelId  || '';

    var wrap     = document.getElementById('ib-chat-wrap');
    if (!wrap) return;

    var bubble    = document.getElementById('ib-bubble');
    var chatWin   = document.getElementById('ib-chat-window');
    var closeBtn  = document.getElementById('ib-close');
    var msgArea   = document.getElementById('ib-messages');
    var inputArea = document.getElementById('ib-input-area');

    // ── State ──────────────────────────────────────────────────────
    var history       = [];   // [{role:'user'|'assistant', content:'...'}]
    var exchangeCount = 0;
    var minExchanges  = 3;    // updated from server on first response
    var leadSaved     = false;
    var isOpen        = false;
    var initialized   = false;

    // ── Apply dynamic chat height ──────────────────────────────────
    var chatHeight = parseInt(display.chatHeight || '480', 10);
    if (chatHeight > 0) {
        var styleEl = document.createElement('style');
        styleEl.textContent = '#ib-messages { height:' + (chatHeight - 120) + 'px; }';
        document.head.appendChild(styleEl);
    }

    // ── Business hours check ───────────────────────────────────────
    function isWithinBusinessHours() {
        if (display.hoursEnabled !== '1') return true;
        try {
            var now   = new Date();
            var tz    = display.hoursTimezone || 'America/New_York';
            var parts = new Intl.DateTimeFormat('en-US', {
                timeZone: tz, hour:'2-digit', minute:'2-digit', hour12:false,
                weekday:'short'
            }).formatToParts(now);
            var timeStr = '', dayStr = '';
            parts.forEach(function(p) {
                if (p.type === 'hour')   timeStr = p.value;
                if (p.type === 'minute') timeStr += ':' + p.value;
                if (p.type === 'weekday') dayStr = p.value;
            });
            var dayMap = {Mon:1,Tue:2,Wed:3,Thu:4,Fri:5,Sat:6,Sun:0};
            var dayNum = String(dayMap[dayStr] !== undefined ? dayMap[dayStr] : -1);
            var activeDays = (display.hoursDays || '1,2,3,4,5').split(',');
            if (activeDays.indexOf(dayNum) === -1) return false;
            return timeStr >= (display.hoursStart || '09:00') &&
                   timeStr <  (display.hoursEnd   || '17:00');
        } catch(e) { return true; }
    }

    // ── Build input row ────────────────────────────────────────────
    inputArea.innerHTML =
        '<div class="ib-input-row">' +
            '<input type="text" id="ib-text-input" ' +
                'placeholder="' + safeAttr(M.inputPlaceholder || 'Ask me anything\u2026') + '" ' +
                'autocomplete="off" maxlength="500" aria-label="Type your message">' +
            '<button id="ib-send-btn" aria-label="Send">&#9658;</button>' +
        '</div>';

    var textInput = document.getElementById('ib-text-input');
    var sendBtn   = document.getElementById('ib-send-btn');

    // ── Utility ────────────────────────────────────────────────────
    function safeAttr(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Simple markdown -> HTML (bold, italic, code, newlines)
    function md(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g,     '<em>$1</em>')
            .replace(/`(.+?)`/g,       '<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:0.9em;">$1</code>')
            .replace(/\n/g, '<br>');
    }

    function now12h() {
        var d = new Date();
        var h = d.getHours(), m = d.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
    }

    // ── Analytics helpers ──────────────────────────────────────────
    function gaEvent(name, params) {
        if (ga4Id && typeof gtag === 'function') {
            try { gtag('event', name, params || {}); } catch(e) {}
        }
    }
    function pixelEvent(name) {
        if (pixelId && typeof fbq === 'function') {
            try { fbq('track', name); } catch(e) {}
        }
    }

    // ── Message rendering ──────────────────────────────────────────
    function addMsg(role, content, isHTML) {
        var outer = document.createElement('div');
        outer.className = 'ib-msg ib-msg-' + role;

        var bub = document.createElement('div');
        bub.className = 'ib-msg-bubble';

        if (isHTML) {
            bub.innerHTML = content;
        } else {
            bub.textContent = content;
        }

        if (display.showTimestamps === '1') {
            var ts = document.createElement('div');
            ts.className = 'ib-msg-time';
            ts.textContent = now12h();
            outer.appendChild(bub);
            outer.appendChild(ts);
        } else {
            outer.appendChild(bub);
        }

        msgArea.appendChild(outer);
        msgArea.scrollTop = msgArea.scrollHeight;
        return outer;
    }

    function showTyping() {
        if (display.disableTypingAnim === '1') return;
        var w = document.createElement('div');
        w.id = 'ib-typing';
        w.className = 'ib-msg ib-msg-bot';
        w.innerHTML = '<div class="ib-msg-bubble ib-typing-bubble">' +
            '<span class="ib-dot"></span>' +
            '<span class="ib-dot"></span>' +
            '<span class="ib-dot"></span>' +
            '</div>';
        msgArea.appendChild(w);
        msgArea.scrollTop = msgArea.scrollHeight;
    }

    function hideTyping() {
        var el = document.getElementById('ib-typing');
        if (el) el.remove();
    }

    function setDisabled(flag) {
        textInput.disabled = flag;
        sendBtn.disabled   = flag;
    }

    // ── Welcome message ────────────────────────────────────────────
    function showWelcome() {
        if (initialized) return;
        initialized = true;

        if (display.hoursEnabled === '1' && !isWithinBusinessHours()) {
            var offlineMsg = display.offlineMessage || "We\u2019re offline right now \u2014 drop your email and we\u2019ll follow up first thing.";
            addMsg('bot', md(offlineMsg), true);
            setDisabled(true);
            return;
        }

        var welcome = M.welcome ||
            "Hey! \uD83D\uDC4B I\u2019m ideaBot \u2014 the AI assistant for ideaBoss.\n\n" +
            "Ask me anything about what we do, how we work, or how AI could help your business.";
        addMsg('bot', md(welcome), true);
        history.push({ role: 'assistant', content: welcome });
    }

    // ── Send message ───────────────────────────────────────────────
    function send() {
        var text = textInput.value.trim();
        if (!text || textInput.disabled) return;
        textInput.value = '';
        setDisabled(true);

        addMsg('user', text, false);
        gaEvent('ideabot_message_sent', { exchange: exchangeCount + 1 });

        var prevHistory = history.slice();
        history.push({ role: 'user', content: text });
        exchangeCount++;

        showTyping();

        var fd = new FormData();
        fd.append('action',         'ideabot_chat');
        fd.append('nonce',          nonce);
        fd.append('message',        text);
        fd.append('history',        JSON.stringify(prevHistory));
        fd.append('exchange_count', exchangeCount);

        fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                hideTyping();
                setDisabled(false);
                textInput.focus();

                if (res.success) {
                    var reply = res.data.reply || '';
                    addMsg('bot', md(reply), true);
                    history.push({ role: 'assistant', content: reply });

                    // Sync min_exchanges from server setting
                    if (res.data.min_exchanges) {
                        minExchanges = parseInt(res.data.min_exchanges, 10) || 3;
                    }

                    if (res.data.collected_email && !leadSaved) {
                        leadSaved = true;
                        saveLead(res.data.collected_email, buildTranscript());
                        gaEvent('ideabot_lead_captured', { email: res.data.collected_email });
                        pixelEvent('Lead');
                    }
                } else {
                    var errMsg = (res.data && res.data.message)
                        ? res.data.message
                        : 'Something went wrong \u2014 please try again.';
                    addMsg('bot', md(errMsg), true);
                }
            })
            .catch(function () {
                hideTyping();
                setDisabled(false);
                addMsg('bot', 'Connection error \u2014 please try again.', false);
            });
    }

    function buildTranscript() {
        return history.map(function (m) {
            return (m.role === 'user' ? 'Visitor' : 'ideaBot') + ': ' + m.content;
        }).join('\n\n');
    }

    function saveLead(email, transcript) {
        var fd = new FormData();
        fd.append('action',      'ideabot_save_chat_lead');
        fd.append('nonce',       nonce);
        fd.append('email',       email);
        fd.append('transcript',  transcript);
        fd.append('source_page', window.location.href);
        fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
    }

    // ── Open / close ───────────────────────────────────────────────
    function openChat() {
        if (isOpen) return;
        isOpen = true;
        chatWin.classList.add('ib-open');
        bubble.setAttribute('aria-expanded', 'true');
        showWelcome();
        gaEvent('ideabot_chat_opened');
        setTimeout(function () { if (!textInput.disabled) textInput.focus(); }, 120);
    }

    function closeChat() {
        if (!isOpen) return;
        isOpen = false;
        chatWin.classList.remove('ib-open');
        bubble.setAttribute('aria-expanded', 'false');
    }

    bubble.addEventListener('click', function () { isOpen ? closeChat() : openChat(); });
    closeBtn.addEventListener('click', closeChat);
    sendBtn.addEventListener('click', send);
    textInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });

    document.addEventListener('click', function (e) {
        if (isOpen && !wrap.contains(e.target)) closeChat();
    });

    // ── Auto-open ──────────────────────────────────────────────────
    if (display.autoOpen === '1') {
        var delay = Math.max(0, parseInt(display.openDelay || '0', 10)) * 1000;
        setTimeout(openChat, delay);
    }

    // ── Bubble fade-in ─────────────────────────────────────────────
    wrap.style.opacity    = '0';
    wrap.style.transition = 'opacity 0.5s ease';
    setTimeout(function () { wrap.style.opacity = '1'; }, 400);

})();
