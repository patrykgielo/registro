const ENDPOINT = '/api/track';
const BATCH_SIZE = 30;
const BATCH_INTERVAL = 2000;
const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

let queue = [];
let timer = null;
let scrollFired = new Set();
let intersectionObserver = null;
let exitFired = false;

// Storage guard — Safari ITP and some privacy extensions block storage access
function safeGet(storage, key) {
    try { return storage.getItem(key); } catch { return null; }
}
function safeSet(storage, key, val) {
    try { storage.setItem(key, val); } catch { /* storage blocked */ }
}

let sessionId = safeGet(sessionStorage, '_tk_session');

// Anonymous ID — persists across sessions in localStorage, survives cookie clearance
function makeUuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}
// anonymous_id is lazy — only created on first push() call when DNT is not active.
// Initialising it unconditionally at module load would write to localStorage before
// the DNT check in push(), violating ePrivacy Directive Art. 5(3).
let anonymousId = null;

function getOrCreateAnonymousId() {
    if (!anonymousId) {
        anonymousId = safeGet(localStorage, '_tk_anon_id');
        if (!anonymousId) {
            anonymousId = makeUuid();
            safeSet(localStorage, '_tk_anon_id', anonymousId);
        }
    }
    return anonymousId;
}

// UTM capture: first-touch in localStorage, last-touch in sessionStorage.
// Skipped when DNT is active — writing UTM to localStorage before the push() guard
// would store identifiable campaign data for users who opted out.
function captureUtm() {
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1') {
        return;
    }
    const params = new URLSearchParams(window.location.search);
    const utm = {};
    UTM_KEYS.forEach((k) => {
        if (params.has(k)) utm[k] = params.get(k);
    });
    if (Object.keys(utm).length) {
        if (!safeGet(localStorage, '_tk_utm_ft')) {
            safeSet(localStorage, '_tk_utm_ft', JSON.stringify({ ...utm, _ts: Date.now() }));
        }
        safeSet(sessionStorage, '_tk_utm_lt', JSON.stringify({ ...utm, _ts: Date.now() }));
    }
}

// Returns last-touch UTM for every event payload.
// First-touch (_tk_utm_ft in localStorage) is intentionally not sent per-event —
// it is reserved for Phase 3 PostHog integration where it will be sent once on session start.
function getUtm() {
    const lt = safeGet(sessionStorage, '_tk_utm_lt');
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
        anonymous_id: getOrCreateAnonymousId(),
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
                        safeSet(sessionStorage, '_tk_session', sessionId);
                    }
                });
            }
        })
        .catch(() => {
            safeSet(sessionStorage, '_tk_failed', payload);
        });
}

function retryFailed() {
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;
    const failed = safeGet(sessionStorage, '_tk_failed');
    if (!failed) return;
    try { sessionStorage.removeItem('_tk_failed'); } catch { /* blocked */ }
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

// Checkout form tracking — field focus + abandon detection
function setupCheckoutTracking() {
    if (!location.pathname.includes('koszyk/zamowienie') &&
        !location.pathname.includes('koszyk/checkout')) {
        return;
    }

    let orderCompleted = false;
    let lastField = null;
    let fieldCount = 0;
    const tracked = new Set();

    document.querySelectorAll('[data-checkout-form] input, [data-checkout-form] select, [data-checkout-form] textarea')
        .forEach((el) => {
            el.addEventListener('focus', () => {
                const fieldName = el.name || el.id || 'unknown';
                if (!tracked.has(fieldName)) {
                    tracked.add(fieldName);
                    lastField = fieldName;
                    fieldCount++;
                    push('form_field_focused', { field: fieldName });
                }
            });
        });

    // Alpine dispatches this when form passes validation and will submit
    window.addEventListener('checkout:submitted', () => {
        orderCompleted = true;
    });

    // Abandon detection — must call flush(true) because the existing visibilitychange
    // handler runs first and flushes before this listener fires
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden' && fieldCount > 0 && !orderCompleted) {
            push('form_abandoned', {
                last_field: lastField,
                fields_interacted: fieldCount,
                page: 'checkout',
            });
            flush(true);
        }
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

// Back navigation — track previous URL before Livewire SPA navigation
document.addEventListener('livewire:navigate', () => {
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;
    safeSet(sessionStorage, '_tk_prev_url', location.href);
});

// Back navigation (browser back button through checkout funnel)
// document.referrer doesn't update on popstate; use _tk_prev_url for SPA,
// fall back to referrer for full page loads.
window.addEventListener('popstate', () => {
    const prevUrl = safeGet(sessionStorage, '_tk_prev_url') || document.referrer;
    if (prevUrl.includes('koszyk')) {
        push('back_navigation', {
            from_page: 'checkout',
            to_url: location.href,
        });
    }
});

// Calendar date selection — dispatched by Alpine component in services/show.blade.php
window.addEventListener('calendar:date-selected', (e) => {
    push('calendar_interacted', {
        action: 'date_selected',
        service_slug: e.detail?.slug ?? null,
    });
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
    setupCheckoutTracking();
    if (document.body?.dataset.pageType === 'service') {
        push('product_viewed', {
            service_slug: document.body.dataset.serviceSlug ?? null,
            service_id: document.body.dataset.serviceId ? parseInt(document.body.dataset.serviceId, 10) : null,
            price: document.body.dataset.servicePrice ? parseFloat(document.body.dataset.servicePrice) : null,
            currency: 'PLN',
        });
    }
});

// Init
captureUtm();
retryFailed();
setupScrollTracking();
setupIntersectionTracking();
setupCheckoutTracking();
push('page_viewed');

// product_viewed — fires on service detail pages
if (document.body?.dataset.pageType === 'service') {
    push('product_viewed', {
        service_slug: document.body.dataset.serviceSlug ?? null,
        service_id: document.body.dataset.serviceId ? parseInt(document.body.dataset.serviceId, 10) : null,
        price: document.body.dataset.servicePrice ? parseFloat(document.body.dataset.servicePrice) : null,
        currency: 'PLN',
    });
}

// add_to_cart then cart_viewed (funnel order: item added before cart is viewed)
if (
    location.pathname.includes('/koszyk') &&
    !location.pathname.includes('/koszyk/zamowienie') &&
    document.referrer.includes('/uslugi/')
) {
    const slug = document.referrer.split('/uslugi/')[1]?.split('?')[0] ?? null;
    push('add_to_cart', { referrer_service: slug });
}

if (location.pathname.replace(/\/$/, '') === '/koszyk') {
    push('cart_viewed');
}

export { push, flush };
