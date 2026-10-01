/*  guest_manager.js — behaviour for the Guest Manager page
    ----------------------------------------------------------------
    Reads configuration from window.GM_CONFIG (set inline by the page):
      { base, feedsUrl, magicKey }

    Responsibilities:
      • FullCalendar wiring (range-fed from guest_feeds.php?feed=calendar)
      • Calendar loading overlay
      • Global booking search dropdown (guest_feeds.php?feed=search)
          – debounced, with loading/empty states
          – full keyboard navigation (� ↓ Enter Esc)
      • magic_key propagation onto any forms on the page
    ---------------------------------------------------------------- */

(function () {
  'use strict';

  const CFG = window.GM_CONFIG || {};
  const BASE = CFG.base || '';
  const FEEDS = CFG.feedsUrl || (BASE + '/guest_feeds.php');
  const MAGIC_KEY = CFG.magicKey || null;

  /* ── shared helpers ─────────────────────────────────────── */

  function buildViewUrl(bookingID) {
    let url = BASE + '/view_booking.php?BookingID=' + encodeURIComponent(bookingID ?? '');
    if (MAGIC_KEY) url += '&magic_key=' + encodeURIComponent(MAGIC_KEY);
    return url;
  }

  function feedUrl(feed, extraParams) {
    const qs = new URLSearchParams({ feed });
    if (extraParams) {
      for (const [k, v] of Object.entries(extraParams)) qs.set(k, v);
    }
    if (MAGIC_KEY) qs.set('magic_key', MAGIC_KEY);
    return FEEDS + '?' + qs.toString();
  }

  /**
   * Short guest display from a comma-separated name string.
   * Mirrors gm_guest_summary() in guest_helpers.php so server and
   * client never disagree.
   */
  function guestSummary(namesCsv, totalCount) {
    const names = (namesCsv || '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);

    const count = Number.isFinite(totalCount) && totalCount > 0 ? totalCount : names.length;

    if (count <= 0 || names.length === 0) return 'Guest booking';
    if (count <= 2 || names.length <= 2) return names.slice(0, 2).join(', ');

    const others = Math.max(0, count - 2);
    return `${names[0]}, ${names[1]} and ${others} ${others === 1 ? 'other' : 'others'}`;
  }

  function formatRange(startStr, endStr) {
    try {
      const s = new Date(startStr);
      const e = new Date(endStr);
      const dOpt = { weekday: 'short', month: 'short', day: 'numeric' };
      const tOpt = { hour: '2-digit', minute: '2-digit' };
      return `${s.toLocaleDateString(undefined, dOpt)} ${s.toLocaleTimeString(undefined, tOpt)} → ` +
             `${e.toLocaleDateString(undefined, dOpt)} ${e.toLocaleTimeString(undefined, tOpt)}`;
    } catch {
      return `${startStr} → ${endStr}`;
    }
  }

  /* ── calendar ───────────────────────────────────────────── */

  function initCalendar() {
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl || typeof FullCalendar === 'undefined') return;

    const wrap = calendarEl.closest('.calendar-wrap');
    const labelEl = document.getElementById('calLabel');

    const setLoading = (on) => {
      if (wrap) wrap.classList.toggle('is-loading', !!on);
    };

    const calendar = new FullCalendar.Calendar(calendarEl, {
      height: 'auto',
      selectable: false,
      expandRows: true,
      initialView: 'dayGridMonth',
      headerToolbar: false,
      firstDay: 1,
      nowIndicator: true,
      dayMaxEventRows: 3,

      events: async (fetchInfo, successCallback, failureCallback) => {
        setLoading(true);
        try {
          const resp = await fetch(
            feedUrl('calendar', { start: fetchInfo.startStr, end: fetchInfo.endStr }),
            { credentials: 'same-origin' }
          );
          if (!resp.ok) throw new Error('Failed to load events');
          const raw = await resp.json();

          const events = (Array.isArray(raw) ? raw : []).map((ev) => ({
            title: 'Booking',
            start: ev.start,
            end: ev.end,
            url: buildViewUrl(ev.bookingID),
            backgroundColor: ev.color,
            borderColor: ev.color,
            extendedProps: {
              bookingID: ev.bookingID,
              status: ev.status,
              room: ev.room,
              occasion: ev.occasion,
              guestNames: ev.guestNames,
              guestCount: ev.guestCount,
            },
          }));

          successCallback(events);
        } catch (e) {
          failureCallback(e);
        } finally {
          setLoading(false);
        }
      },

      eventContent: (arg) => {
        const ep = arg.event.extendedProps || {};
        const wrapEl = document.createElement('div');
        wrapEl.className = 'event-line';

        const title = document.createElement('div');
        title.className = 'event-title';
        title.textContent = guestSummary(ep.guestNames || '', Number(ep.guestCount || 0));

        const meta = document.createElement('div');
        meta.className = 'event-meta';
        meta.textContent = [(ep.occasion || '').trim(), (ep.room || '').trim()]
          .filter(Boolean)
          .join(' · ');

        wrapEl.appendChild(title);
        if (meta.textContent) wrapEl.appendChild(meta);
        return { domNodes: [wrapEl] };
      },

      eventDidMount: (info) => {
        if (info.event.extendedProps.status === 'Cancelled') {
          info.el.style.opacity = '0.55';
        }
      },

      datesSet: (arg) => {
        try {
          labelEl.textContent = arg.start.toLocaleDateString(undefined, {
            month: 'long',
            year: 'numeric',
          });
        } catch { /* no-op */ }
      },
    });

    calendar.render();

    const monthBtn = document.getElementById('viewMonth');
    const listBtn = document.getElementById('viewList');
    const prevBtn = document.getElementById('prevBtn');
    const todayBtn = document.getElementById('todayBtn');
    const nextBtn = document.getElementById('nextBtn');

    prevBtn?.addEventListener('click', () => calendar.prev());
    todayBtn?.addEventListener('click', () => calendar.today());
    nextBtn?.addEventListener('click', () => calendar.next());

    monthBtn?.addEventListener('click', () => {
      calendar.changeView('dayGridMonth');
      monthBtn.setAttribute('aria-pressed', 'true');
      listBtn?.setAttribute('aria-pressed', 'false');
    });

    listBtn?.addEventListener('click', () => {
      calendar.changeView('listMonth');
      listBtn.setAttribute('aria-pressed', 'true');
      monthBtn?.setAttribute('aria-pressed', 'false');
    });
  }

  /* ── global search dropdown ─────────────────────────────── */

  function initSearch() {
    const input = document.getElementById('globalSearch');
    const dd = document.getElementById('globalSearchDd');
    if (!input || !dd) return;

    let debounceTimer = null;
    let lastQuery = '';
    let activeIndex = -1; // roving selection within the dropdown

    const items = () => Array.from(dd.querySelectorAll('.item'));

    const openDd = () => { dd.style.display = 'block'; };
    const closeDd = () => {
      dd.style.display = 'none';
      dd.innerHTML = '';
      activeIndex = -1;
      input.setAttribute('aria-expanded', 'false');
    };

    const setActive = (idx) => {
      const list = items();
      if (!list.length) return;
      activeIndex = (idx + list.length) % list.length;
      list.forEach((el, i) => {
        const on = i === activeIndex;
        el.classList.toggle('is-active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) el.scrollIntoView({ block: 'nearest' });
      });
    };

    const showMessage = (cls, text) => {
      dd.innerHTML = `<div class="${cls}">${text}</div>`;
      activeIndex = -1;
      openDd();
      input.setAttribute('aria-expanded', 'true');
    };

    function renderResults(list) {
      dd.innerHTML = '';
      activeIndex = -1;

      if (!Array.isArray(list) || list.length === 0) {
        showMessage('empty', 'No bookings found.');
        return;
      }

      const frag = document.createDocumentFragment();
      list.forEach((it) => {
        const div = document.createElement('div');
        div.className = 'item';
        div.setAttribute('role', 'option');
        div.setAttribute('aria-selected', 'false');

        const titleParts = [
          guestSummary(it.guests, Number(it.guestCount || 0)),
          (it.room || '').trim(),
        ].filter(Boolean);

        const title = document.createElement('div');
        title.className = 'title';
        title.textContent = titleParts.join(' • ');

        const metaParts = [
          (it.occasion || '').trim() ? `Occasion: ${(it.occasion || '').trim()}` : '',
          formatRange(it.start, it.end),
          it.status || 'Status',
        ].filter(Boolean);

        const meta = document.createElement('div');
        meta.className = 'meta';
        meta.textContent = metaParts.join(' · ');

        div.appendChild(title);
        div.appendChild(meta);
        div.addEventListener('click', () => {
          window.location.href = buildViewUrl(it.bookingID);
        });
        div.addEventListener('mousemove', () => {
          setActive(items().indexOf(div));
        });

        frag.appendChild(div);
      });

      dd.appendChild(frag);
      openDd();
      input.setAttribute('aria-expanded', 'true');
    }

    async function runSearch(query) {
      const resp = await fetch(feedUrl('search', { q: query }), { credentials: 'same-origin' });
      if (!resp.ok) throw new Error('Search failed');
      return resp.json();
    }

    input.addEventListener('input', () => {
      const query = (input.value || '').trim();
      if (debounceTimer) clearTimeout(debounceTimer);

      if (query.length < 2) {
        lastQuery = '';
        closeDd();
        return;
      }

      showMessage('loading', 'Searching…');

      debounceTimer = setTimeout(async () => {
        if (query === lastQuery) return;
        lastQuery = query;
        try {
          renderResults(await runSearch(query));
        } catch {
          showMessage('empty', 'Search unavailable.');
        }
      }, 200);
    });

    input.addEventListener('keydown', (e) => {
      const list = items();
      switch (e.key) {
        case 'ArrowDown':
          if (list.length) { e.preventDefault(); setActive(activeIndex + 1); }
          break;
        case 'ArrowUp':
          if (list.length) { e.preventDefault(); setActive(activeIndex - 1); }
          break;
        case 'Enter':
          if (activeIndex >= 0 && list[activeIndex]) {
            e.preventDefault();
            list[activeIndex].click();
          }
          break;
        case 'Escape':
          closeDd();
          break;
        default:
          break;
      }
    });

    document.addEventListener('click', (e) => {
      if (!dd.contains(e.target) && e.target !== input) closeDd();
    });

    input.addEventListener('focus', () => {
      if (dd.innerHTML.trim() !== '') openDd();
    });
  }

  /* ── magic_key propagation onto forms ───────────────────── */

  function initMagicKeyForms() {
    if (!MAGIC_KEY) return;
    document.querySelectorAll('form').forEach((form) => {
      if (!form.querySelector("input[name='magic_key']")) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'magic_key';
        hidden.value = MAGIC_KEY;
        form.appendChild(hidden);
      }
    });
  }

  /* ── boot ───────────────────────────────────────────────── */

  document.addEventListener('DOMContentLoaded', () => {
    initCalendar();
    initSearch();
    initMagicKeyForms();
  });
})();
