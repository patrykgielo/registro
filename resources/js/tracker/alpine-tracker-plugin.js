import { push } from './registro-tracker.js';

export default function AlpineTrackerPlugin(Alpine) {
    Alpine.magic('track', (el) => {
        return (eventName, props = {}) => {
            const context = {
                section_name: el.closest('[data-track-section]')?.dataset.trackSection ?? null,
                block_type: el.closest('[data-track-block]')?.dataset.trackBlock ?? null,
                ...props,
            };
            push(eventName, context);
        };
    });

    Alpine.store('tracker', {
        funnelStep: null,
        setStep(step) {
            this.funnelStep = step;
        },
    });
}
