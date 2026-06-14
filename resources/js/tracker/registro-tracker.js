const ENDPOINT = '/api/track';
const BATCH_SIZE = 30;
const BATCH_INTERVAL = 2000;
const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

let queue = [];
let timer = null;
let sessionId = sessionStorage.getItem('_tk_session') ?? null;
let scrollFired = new Set();
let intersectionObserver = null;
let exitFired = false;

// UTM capture: first-touch in localStorage, last-touch in sessionStorage
function captureUtm() {
    const params = new URLSearchParams(window.location.search);
    const utm = {};
    UTM_KEYS.forEach((k) => {
        if (params.has(k)) utm[k] = params.get(k);
    });
    if (Object.keys(utm).length) {
        if (!localStorage.getItem('_tk_utm_ft')) {
            localStorage.setItem('_tk_utm_ft', JSON.stringify({ ...utm, _ts: Date.now() }));
        }
        sessionStorage.setItem('_tk_utm_lt', JSON.stringify({ ...utm, _ts: Date.now() }));
    }
}

function getUtm() {
    const lt = sessionStorage.getItem('_tk_utm_lt');
    return lt ? JSON.parse(lt) : {};
}

// Time on page tracking
let pageStart = Date.now();
let activeMs = 0;
let lastVisible = document.visibilityState === 'visible' ? Date.now() : null;

function getDeviceType() {
    if (window.innerWidth < 768) return 'mobile';
    if (window.innerWidth < 1024) return 'tablet';
    return 'desktop';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function push(eventName, props = {}) {
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1') {
        return;
    }

    queue.push({
        event: eventName,
        session_id: sessionId,
        url: location.href,
        referrer: document.referrer || null,
        page_type: document.body?.dataset.pageType ?? 'unknown',
        device_type: getDeviceType(),
        viewport_w: window.innerWidth,
        timestamp: new Date().toISOString(),
        properties: { ...getUtm(), ...props },
    });

    if (queue.length >= BATCH_SIZE) {
        flush();
    } else if (!timer) {
        timer = setTimeout(flush, BATCH_INTERVAL);
    }
}

function flush(useBeacon = false) {
    clearTimeout(timer);
    timer = null;
    if (!queue.length) return;

    const payload = JSON.stringify({ events: queue.splice(0) });

    if (useBeacon && navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        if (!navigator.sendBeacon(ENDPOINT, blob)) {
            sendFetch(payload, true);
        }
    } else {
        sendFetch(payload, useBeacon);
    }
}

function sendFetch(payload, keepalive = false) {
    fetch(ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: payload,
        keepalive,
    })
        .then((res) => {
            if (res.ok) {
                return res.json().then((data) => {
                    if (data.session_id && !sessionId) {
                        sessionId = data.session_id;
                        sessionStorage.setItem('_tk_session', sessionId);
                    }
                });
            }
        })
        .catch(() => {
            sessionStorage.setItem('_tk_failed', payload);
        });
}

function retryFailed() {
    const failed = sessionStorage.getItem('_tk_failed');
    if (!failed) return;
    sessionStorage.removeItem('_tk_failed');
    sendFetch(failed);
}

function setupScrollTracking() {
    scrollFired = new Set();
    const milestones = [25, 50, 75, 90, 100];

    let ticking = false;
    const handler = () => {
        if (ticking) return;
        ticking = true;
        setTimeout(() => {
            ticking = false;
            const pct = Math.round(
                ((window.scrollY + window.innerHeight) / document.documentElement.scrollHeight) * 100
            );
            milestones.forEach((m) => {
                if (pct >= m && !scrollFired.has(m)) {
                    scrollFired.add(m);
                    push(`scroll_${m}`);
                }
            });
        }, 200);
    };

    window.removeEventListener('scroll', window._tkScrollHandler);
    window._tkScrollHandler = handler;
    window.addEventListener('scroll', handler, { passive: true });
}

function setupIntersectionTracking() {
    if (intersectionObserver) {
        intersectionObserver.disconnect();
    }

    const fired = new Set();

    intersectionObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const el = entry.target;
                const key = `${el.dataset.trackSection}:${el.dataset.trackPosition ?? '0'}`;
                if (fired.has(key)) return;
                fired.add(key);
                push('section_visible', {
                    section_name: el.dataset.trackSection,
                    block_type: el.dataset.trackBlock ?? 'unknown',
                    section_position: parseInt(el.dataset.trackPosition ?? '0', 10),
                });
            });
        },
        { threshold: 0.4 }
    );

    document.querySelectorAll('[data-track-section]').forEach((el) => {
        intersectionObserver.observe(el);
    });
}

// Page exit — flush remaining queue + record time spent
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        if (lastVisible) {
            activeMs += Date.now() - lastVisible;
            lastVisible = null;
        }
        push('page.time_spent', { seconds: Math.round(activeMs / 1000) });
        flush(true);
    } else {
        lastVisible = Date.now();
    }
});
window.addEventListener('pagehide', () => flush(true));

// Exit intent (desktop only — mouseleave at top of viewport)
document.addEventListener('mouseleave', (e) => {
    if (e.clientY <= 0 && !exitFired) {
        exitFired = true;
        push('exit_intent', { page_type: document.body?.dataset.pageType });
    }
});
window.addEventListener('pageshow', () => {
    exitFired = false;
    pageStart = Date.now();
    activeMs = 0;
    lastVisible = document.visibilityState === 'visible' ? Date.now() : null;
});

// Rage click detection (3+ clicks within 750ms in 100px radius)
const rageClicks = [];
document.addEventListener('click', (e) => {
    const now = Date.now();
    rageClicks.push({ x: e.clientX, y: e.clientY, t: now, target: e.target });
    const recent = rageClicks.filter((c) => now - c.t <= 750);
    rageClicks.splice(0, rageClicks.length, ...recent.slice(-10));

    if (recent.length >= 3) {
        const last = recent[recent.length - 1];
        const allNear = recent.every((c) => {
            const dx = c.x - last.x;
            const dy = c.y - last.y;
            return Math.sqrt(dx * dx + dy * dy) <= 100;
        });
        if (allNear) {
            const selector = e.target.id ? '#' + e.target.id : e.target.tagName.toLowerCase();
            push('rage_click', { selector, count: recent.length });
            rageClicks.splice(0);
        }
    }
});

// Livewire SPA navigation
document.addEventListener('livewire:navigated', () => {
    exitFired = false;
    pageStart = Date.now();
    activeMs = 0;
    lastVisible = Date.now();
    push('page_viewed');
    setupIntersectionTracking();
    setupScrollTracking();
});

// Init
captureUtm();
retryFailed();
setupScrollTracking();
setupIntersectionTracking();
push('page_viewed');

export { push, flush };
