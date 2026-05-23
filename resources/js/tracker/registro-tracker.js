const ENDPOINT = '/api/track';
const BATCH_SIZE = 30;
const BATCH_INTERVAL = 2000;

let queue = [];
let timer = null;
let sessionId = sessionStorage.getItem('_tk_session') ?? null;
let scrollFired = new Set();
let intersectionObserver = null;

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
        ...props,
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

// Page exit — flush remaining queue
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush(true);
});
window.addEventListener('pagehide', () => flush(true));

// Livewire SPA navigation
document.addEventListener('livewire:navigated', () => {
    push('page_viewed');
    setupIntersectionTracking();
    setupScrollTracking();
});

// Init
retryFailed();
setupScrollTracking();
setupIntersectionTracking();
push('page_viewed');

export { push, flush };
