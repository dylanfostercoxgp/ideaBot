/**
 * ideaBot — Conversational Chat Flow
 * Version: 1.0.1 | ideaboss.io
 * All text, choices, and behaviour driven by WordPress settings.
 */
(function () {
    'use strict';

    // ----------------------------------------------------------------
    // CONFIG — from wp_localize_script('ideabot', 'ideabotCFG', {...})
    // ----------------------------------------------------------------
    const cfg     = window.ideabotCFG || {};
    const AJAX    = cfg.ajaxUrl     || '';
    const NONCE   = cfg.nonce       || '';
    const M       = cfg.messages    || {};
    const DISP    = cfg.display     || {};
    const ACCENT  = cfg.accentColor || '#00C2FF';

    // Apply accent colour
    document.documentElement.style.setProperty('--ib-accent', ACCENT);

    // ----------------------------------------------------------------
    // HELPERS — pull from settings with fallback to hardcoded defaults
    // ----------------------------------------------------------------
    function m(key, def)       { return (M[key] !== undefined && M[key] !== '') ? M[key] : def; }
    function mArr(key, defArr) { return (Array.isArray(M[key]) && M[key].length) ? M[key] : defArr; }

    // ----------------------------------------------------------------
    // STEPS — built dynamically from WordPress settings
    // ----------------------------------------------------------------
    const STEPS = [
        {
            id:   'welcome',
            type: 'message',
            text: m('welcome', "Hey there! 👋 I'm <strong>ideaBot</strong> from ideaBoss.<br><br>We install AI systems that turn ideas into revenue — and I'd love to learn about <em>your</em> business."),
            next: 'q_name',
        },
        {
            id:          'q_name',
            type:        'text',
            bot:         m('qName',     "Let's start simple — what's your first name?"),
            placeholder: m('qNamePh',   'Your first name'),
            key:         'first_name',
            next:        'q_industry',
        },
        {
            id:          'q_industry',
            type:        'text',
            bot:         m('qIndustry', "Great to meet you, {first_name}! What industry are you in?"),
            placeholder: m('qIndustryPh', 'e.g. Real Estate, HVAC, Healthcare, SaaS…'),
            key:         'industry',
            next:        'q_revenue',
        },
        {
            id:      'q_revenue',
            type:    'choices',
            bot:     m('qRevenue', 'Got it. Roughly where is your business in terms of annual revenue?'),
            key:     'revenue_range',
            choices: mArr('qRevenueOpts', ['Under $500K','$500K – $2M','$2M – $10M','$10M – $50M','$50M+']),
            next:    'q_challenge',
        },
        {
            id:      'q_challenge',
            type:    'choices',
            bot:     m('qChallenge', "What's your biggest challenge right now?"),
            key:     'biggest_challenge',
            choices: mArr('qChallengeOpts', [
                'Repetitive tasks eating time & margin',
                'Hard to stay visible online',
                'Leads going cold without follow-up',
                "Can't scale without doing everything myself",
                "Strategy exists but execution doesn't",
            ]),
            next: 'q_ai_exp',
        },
        {
            id:      'q_ai_exp',
            type:    'choices',
            bot:     m('qAiExp', 'Have you used AI tools in your business before?'),
            key:     'ai_experience',
            choices: mArr('qAiExpOpts', [
                'Yes — actively using AI tools',
                'Tried a few things, nothing stuck',
                'Not yet — just getting started',
            ]),
            next: 'q_team',
        },
        {
            id:      'q_team',
            type:    'choices',
            bot:     m('qTeam', 'How big is your team right now?'),
            key:     'team_size',
            choices: mArr('qTeamOpts', [
                'Just me (solo founder)',
                '2 – 10 people',
                '11 – 50 people',
                '50+ people',
            ]),
            next: 'q_timeline',
        },
        {
            id:      'q_timeline',
            type:    'choices',
            bot:     m('qTimeline', 'How soon are you looking to make a change?'),
            key:     'timeline',
            choices: mArr('qTimelineOpts', [
                'ASAP — this is urgent',
                'Within the next 1 – 3 months',
                'Just exploring for now',
            ]),
            next: 'q_win',
        },
        {
            id:          'q_win',
            type:        'text',
            multiline:   true,
            bot:         m('qWin', 'Last thing before I grab your contact info — what would a big win look like for your business in the next 90 days?'),
            placeholder: m('qWinPh', 'e.g. Close 5 new deals, automate onboarding, stay top-of-mind with leads…'),
            key:         'win_definition',
            next:        'q_email',
        },
        {
            id:         'q_email',
            type:       'text',
            input_type: 'email',
            bot:        m('qEmail', "Love it, {first_name}. What's the best email to send you some ideas?"),
            placeholder:'you@yourbusiness.com',
            key:        'email',
            next:       'q_phone',
        },
        {
            id:         'q_phone',
            type:       'text',
            input_type: 'tel',
            bot:        m('qPhone', 'And a phone number? (totally optional — skip if you prefer)'),
            placeholder:'Phone number (optional)',
            key:        'phone',
            optional:   true,
            next:       'submit',
        },
    ];

    // Non-optional question steps for progress bar
    const PROGRESS_STEPS = STEPS.filter(s => s.type !== 'message' && !s.optional);

    // ----------------------------------------------------------------
    // STATE
    // ----------------------------------------------------------------
    const answers = {};
    let submitted = false;

    // ----------------------------------------------------------------
    // DOM REFS
    // ----------------------------------------------------------------
    let $wrap, $bubble, $win, $msgs, $inp;

    // ----------------------------------------------------------------
    // INIT
    // ----------------------------------------------------------------
    function init() {
        $wrap   = document.getElementById('ib-chat-wrap');
        $bubble = document.getElementById('ib-bubble');
        $win    = document.getElementById('ib-chat-window');
        $msgs   = document.getElementById('ib-messages');
        $inp    = document.getElementById('ib-input-area');

        if (!$wrap) return;

        $bubble.addEventListener('click', toggleChat);
        document.getElementById('ib-close').addEventListener('click', closeChat);

        // Apply display rules
        const delay = parseInt(DISP.openDelay || '0', 10) * 1000;
        if (DISP.autoOpen === '1') {
            setTimeout(openChat, delay);
        } else if (delay > 0) {
            // Just delay the bubble appearance
            $wrap.style.opacity = '0';
            $wrap.style.pointerEvents = 'none';
            setTimeout(() => {
                $wrap.style.transition = 'opacity 0.4s ease';
                $wrap.style.opacity = '1';
                $wrap.style.pointerEvents = '';
            }, delay);
        }
    }

    // ----------------------------------------------------------------
    // OPEN / CLOSE
    // ----------------------------------------------------------------
    function toggleChat() {
        $win.classList.contains('ib-open') ? closeChat() : openChat();
    }

    function openChat() {
        $win.classList.add('ib-open');
        $bubble.setAttribute('aria-expanded', 'true');
        if ($msgs.children.length === 0) advanceTo('welcome');
    }

    function closeChat() {
        $win.classList.remove('ib-open');
        $bubble.setAttribute('aria-expanded', 'false');
    }

    // ----------------------------------------------------------------
    // FLOW
    // ----------------------------------------------------------------
    function advanceTo(stepId) {
        const step = STEPS.find(s => s.id === stepId);
        if (!step) return;

        const text = interp(step.bot || step.text || '');

        if (step.type === 'message') {
            addBotMsg(text, () => advanceTo(step.next));
        } else {
            addBotMsg(text, () => renderInput(step));
        }
    }

    function interp(str) {
        return str.replace(/\{first_name\}/g, answers.first_name || 'there');
    }

    // ----------------------------------------------------------------
    // RENDER INPUT
    // ----------------------------------------------------------------
    function renderInput(step) {
        $inp.innerHTML = '';

        // Progress bar
        const answered = PROGRESS_STEPS.filter(s => answers[s.key] !== undefined).length;
        const dots = PROGRESS_STEPS.map((s, i) =>
            `<div class="ib-progress-dot${i < answered ? ' active' : ''}"></div>`
        ).join('');
        const progress = `<div class="ib-progress">${dots}</div>`;

        if (step.type === 'choices') {
            const btns = step.choices.map(c =>
                `<button class="ib-choice-btn" data-val="${escAttr(c)}">${escHtml(c)}</button>`
            ).join('');
            $inp.innerHTML = progress + `<div class="ib-choices">${btns}</div>`;
            $inp.querySelectorAll('.ib-choice-btn').forEach(b =>
                b.addEventListener('click', () => handleAnswer(step, b.getAttribute('data-val')))
            );

        } else {
            const itype = step.input_type || 'text';
            const multi = !!step.multiline;

            let html;
            if (multi) {
                html = `<div class="ib-text-row ib-multiline">
                    <textarea class="ib-text-input" rows="3"
                        placeholder="${escAttr(step.placeholder || '')}"
                        aria-label="${escAttr(stripHtml(step.bot || ''))}"></textarea>
                    <button class="ib-send-btn ib-full">Send →</button>
                </div>`;
            } else {
                html = `<div class="ib-text-row">
                    <input type="${itype}" class="ib-text-input"
                        placeholder="${escAttr(step.placeholder || '')}"
                        autocomplete="${itype === 'email' ? 'email' : itype === 'tel' ? 'tel' : 'off'}"
                        aria-label="${escAttr(stripHtml(step.bot || ''))}">
                    <button class="ib-send-btn">→</button>
                </div>
                ${step.optional ? '<div class="ib-skip-link">Skip this one</div>' : ''}`;
            }

            $inp.innerHTML = progress + html;

            const $input   = $inp.querySelector('.ib-text-input');
            const $btn     = $inp.querySelector('.ib-send-btn');
            const $skip    = $inp.querySelector('.ib-skip-link');

            const doSubmit = () => {
                const val = $input.value.trim();
                if (!val && !step.optional) {
                    $input.style.borderColor = '#ff4d4d';
                    $input.focus();
                    setTimeout(() => { $input.style.borderColor = ''; }, 1200);
                    return;
                }
                if (itype === 'email' && val && !validEmail(val)) {
                    $input.style.borderColor = '#ff4d4d';
                    addBotMsg("That email doesn't look quite right — mind double-checking?", () => {
                        $input.style.borderColor = '';
                        $input.focus();
                    });
                    return;
                }
                handleAnswer(step, val);
            };

            $btn.addEventListener('click', doSubmit);
            $input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !multi) { e.preventDefault(); doSubmit(); }
            });
            if ($skip) $skip.addEventListener('click', () => handleAnswer(step, ''));

            setTimeout(() => $input.focus(), 80);
        }

        scrollDown();
    }

    // ----------------------------------------------------------------
    // HANDLE ANSWER
    // ----------------------------------------------------------------
    function handleAnswer(step, value) {
        if (value) {
            answers[step.key] = value;
            addUserMsg(value);
        }
        $inp.innerHTML = '';

        if (step.next === 'submit') {
            setTimeout(submitForm, 380);
        } else {
            setTimeout(() => advanceTo(step.next), 420);
        }
    }

    // ----------------------------------------------------------------
    // SUBMIT
    // ----------------------------------------------------------------
    function submitForm() {
        if (submitted) return;

        if (!answers.email || !validEmail(answers.email)) {
            const s = STEPS.find(s => s.id === 'q_email');
            addBotMsg("Let me double-check that email — could you re-enter it?", () => renderInput(s));
            return;
        }

        submitted = true;
        addBotMsg("Give me just a second… ✨");

        const fd = new FormData();
        fd.append('action', 'ideabot_submit');
        fd.append('nonce', NONCE);
        Object.entries(answers).forEach(([k, v]) => fd.append(k, v));

        fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showSuccess();
                } else {
                    submitted = false;
                    const ce = m('contactEmail', 'hello@ideaboss.io');
                    addBotMsg(`Something went wrong on our end. Reach us at <a href="mailto:${escHtml(ce)}" style="color:var(--ib-accent);">${escHtml(ce)}</a>.`);
                }
            })
            .catch(() => {
                submitted = false;
                const ce = m('contactEmail', 'hello@ideaboss.io');
                addBotMsg(`Network hiccup — try again or email <a href="mailto:${escHtml(ce)}" style="color:var(--ib-accent);">${escHtml(ce)}</a>.`);
            });
    }

    // ----------------------------------------------------------------
    // SUCCESS
    // ----------------------------------------------------------------
    function showSuccess() {
        const name = escHtml(answers.first_name || 'there');
        const s1   = interp( m('success1', "You're all set, {first_name}! 🎯") ).replace('{first_name}', name);
        const s2   = m('success2', "Check your inbox — we just sent you a quick intro. Someone from the ideaBoss team will follow up within <strong>1 business day</strong>.<br><br><em>Act. Build. Repeat.</em> 💡");
        const cta  = m('successCta', 'Explore ideaboss.io →');
        const url  = escAttr( m('ctaUrl', 'https://ideaboss.io') );

        setTimeout(() => addBotMsg(s1),  500);
        setTimeout(() => addBotMsg(s2), 1500);
        setTimeout(() => {
            $inp.innerHTML = `<div style="text-align:center;padding:10px 0 4px;">
                <a href="${url}" target="_blank" rel="noopener"
                   style="color:var(--ib-accent);font-size:13px;font-weight:600;text-decoration:none;">
                   ${escHtml(cta)}
                </a>
            </div>`;
        }, 2700);
    }

    // ----------------------------------------------------------------
    // UI HELPERS
    // ----------------------------------------------------------------
    function addBotMsg(html, callback) {
        const $t = document.createElement('div');
        $t.className = 'ib-msg ib-msg-bot ib-typing';
        $t.innerHTML = '<span></span><span></span><span></span>';
        $msgs.appendChild($t);
        scrollDown();

        const delay = Math.min(600 + stripHtml(html).length * 4, 1400);
        setTimeout(() => {
            if ($t.parentNode) $msgs.removeChild($t);
            const $b = document.createElement('div');
            $b.className = 'ib-msg ib-msg-bot';
            $b.innerHTML = html;
            $msgs.appendChild($b);
            scrollDown();
            if (callback) setTimeout(callback, 180);
        }, delay);
    }

    function addUserMsg(text) {
        const $b = document.createElement('div');
        $b.className = 'ib-msg ib-msg-user';
        $b.textContent = text;
        $msgs.appendChild($b);
        scrollDown();
    }

    function scrollDown() {
        requestAnimationFrame(() => { $msgs.scrollTop = $msgs.scrollHeight; });
    }

    // ----------------------------------------------------------------
    // UTILS
    // ----------------------------------------------------------------
    function validEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); }
    function escHtml(s)    { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function escAttr(s)    { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function stripHtml(s)  { return String(s).replace(/<[^>]*>/g,''); }

    // ----------------------------------------------------------------
    // BOOT
    // ----------------------------------------------------------------
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
