(function (root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.MinorFactions = api;
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function minimumFactionCount(playerCount, enabled) {
        const players = Math.max(0, Number.parseInt(playerCount, 10) || 0);
        return enabled ? players * 2 : players;
    }

    return {
        minimumFactionCount,
    };
}));
