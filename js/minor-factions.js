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

    function resolveSliceTile(slice, tileIndex, minorFactionsEnabled = false) {
        const originalTile = slice && Array.isArray(slice.tiles) ? slice.tiles[tileIndex] : undefined;
        const assignment = slice && slice.minor_faction;
        const equidistant = slice && slice.equidistant;

        if (!assignment && !equidistant) {
            if (minorFactionsEnabled && Number(tileIndex) === 3) {
                throw new Error('Invalid persisted Minor Faction slice data');
            }
            return { tile: originalTile, label: null, minor: false };
        }

        if (Number(tileIndex) !== 3) {
            return { tile: originalTile, label: null, minor: false };
        }

        if (
            assignment &&
            typeof assignment.name === 'string' && assignment.name.trim().length > 0 &&
            typeof assignment.tile_id === 'string' && assignment.tile_id.trim().length > 0 &&
            typeof assignment.render_token === 'string' && assignment.render_token.trim().length > 0 &&
            assignment.tile_id === originalTile &&
            equidistant && Number(equidistant.index) === 3 &&
            Number(equidistant.q) === -1 && Number(equidistant.r) === 0
        ) {
            return {
                tile: assignment.render_token,
                label: assignment.name,
                minor: true,
            };
        }

        throw new Error('Invalid persisted Minor Faction slice data');
    }

    return {
        minimumFactionCount,
        sliceConstraintDefaults,
        resolveSliceTile,
    };
}));
