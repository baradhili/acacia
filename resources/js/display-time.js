// Server-rendered <time data-display-time> stamps carry the UTC
// instant and start out in the configured APP_DISPLAY_TIMEZONE (the
// no-JS fallback, PHP-abbreviated: AEST/AEDT). Re-render them in the
// viewer's own browser timezone so every reader sees their local
// wall-clock time, still labelled with the zone name their
// environment reports — EST, GMT+11, ... (modern ICU reports
// offsets, not AEST/AEDT-style abbreviations, for many zones). The
// bundle loads as a deferred module, so the DOM is parsed by the
// time this runs.
const stamps = document.querySelectorAll('time[data-display-time]');

if (stamps.length > 0) {
    const formatter = new Intl.DateTimeFormat('en', {
        timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZoneName: 'short',
    });

    stamps.forEach((stamp) => {
        const instant = new Date(stamp.getAttribute('datetime'));

        if (Number.isNaN(instant.getTime())) {
            return;
        }

        const parts = Object.fromEntries(
            formatter.formatToParts(instant).map((part) => [part.type, part.value]),
        );

        stamp.textContent = `${parts.day} ${parts.month} ${parts.year} ${parts.hour}:${parts.minute} ${parts.timeZoneName}`;
    });
}
