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
        return players;
    }

    function sliceConstraintDefaults(enabled) {
        return {
            minimumInfluence: 4,
            minimumResources: 2.5,
            minimumTotal: 9,
            maximumTotal: 13,
        };
    }

    function resolveSliceTile(mode, position, tileIndex, originalTile) {
        if (!mode || !mode.enabled || Number(tileIndex) !== Number(mode.equidistant_index)) {
            return { tile: originalTile, label: null, minor: false };
        }

        if (mode.status === 'resolved') {
            const assignment = (mode.assignments || []).find(
                (candidate) => Number(candidate.position) === Number(position),
            );

            if (
                assignment &&
                typeof assignment.faction === 'string' && assignment.faction.length > 0 &&
                typeof assignment.home_system === 'string' && assignment.home_system.length > 0
            ) {
                return {
                    tile: assignment.home_system,
                    label: assignment.faction,
                    minor: true,
                };
            }
        }

        return { tile: 0, label: 'Minor Faction', minor: true };
    }

    return {
        minimumFactionCount,
        sliceConstraintDefaults,
        resolveSliceTile,
    };
}));
