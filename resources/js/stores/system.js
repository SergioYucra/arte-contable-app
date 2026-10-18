import { defineStore } from 'pinia';
import { version as vueVersion } from 'vue';

/**
 * Store global de sistema (demo de Pinia compartida entre islas Vue).
 */
export const useSystemStore = defineStore('system', {
    state: () => ({
        vueVersion,
        mountedAt: new Date(),
        checks: 0,
        lastCheckAt: null,
    }),

    getters: {
        uptimeLabel: (state) => {
            const ref = state.lastCheckAt ?? state.mountedAt;
            return ref.toLocaleTimeString();
        },
    },

    actions: {
        runCheck() {
            this.checks++;
            this.lastCheckAt = new Date();
        },
    },
});
