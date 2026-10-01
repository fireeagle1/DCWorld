<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset("utf8mb4");

$userID = $_SESSION['userID'] ?? 0;

/* ---------- colours ---------- */
$sqlColors = "SELECT SettingKey, SettingValue
              FROM UserSettings
              WHERE UserID = ?
              AND SettingKey IN ('DailyOpsColor','EventColor','OnCallColor','DutySheetColor')";
$stmtColors = $link->prepare($sqlColors);
$stmtColors->bind_param('i', $userID);
$stmtColors->execute();
$resColors = $stmtColors->get_result();

$colorSettings = [];
while ($row = $resColors->fetch_assoc()) {
  $colorSettings[$row['SettingKey']] = $row['SettingValue'];
}

$dailyOpsColor  = $colorSettings['DailyOpsColor'] ?? '#29af8f';
$eventColor     = $colorSettings['EventColor'] ?? '#bb3678';
$onCallColor    = $colorSettings['OnCallColor'] ?? '#ff0000';
$dutySheetColor = $colorSettings['DutySheetColor'] ?? '#FFD700';

$birthdayColor  = '#ff8c00';
$bookingColor   = '#9b59b6';
$holidayColor   = '#2563eb';

$link->close();

/* ---------- incoming params ---------- */
$magicKey = $_GET['magic_key'] ?? '';
$ym       = $_GET['ym'] ?? ''; // YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Print Calendar</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>

<style>
:root{
  --grid-border:#d0d7de;
  --text:#0b0f14;
  --muted:#4b5563;
  --bg:#f3f4f6;
  --panel:#ffffff;
  --chip-bg:#f6f8fa;

  --c-dailyops: <?= htmlspecialchars($dailyOpsColor, ENT_QUOTES) ?>;
  --c-regular:  <?= htmlspecialchars($eventColor, ENT_QUOTES) ?>;
  --c-oncall:   <?= htmlspecialchars($onCallColor, ENT_QUOTES) ?>;
  --c-duty:     <?= htmlspecialchars($dutySheetColor, ENT_QUOTES) ?>;
  --c-bday:     <?= htmlspecialchars($birthdayColor, ENT_QUOTES) ?>;
  --c-booking:  <?= htmlspecialchars($bookingColor, ENT_QUOTES) ?>;
  --c-holiday:  <?= htmlspecialchars($holidayColor, ENT_QUOTES) ?>;
}

*{ box-sizing:border-box; }
html, body { height:100%; }
body{
  margin:0;
  background:var(--bg);
  color:var(--text);
  font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
}

/* On-screen wrapper */
.wrap{ max-width: 1200px; margin:0 auto; padding:12px; }
.panel{ background:var(--panel); border:1px solid rgba(0,0,0,.06); border-radius:14px; overflow:hidden; }

/* Controls (hidden in print) */
.controlsbar{
  display:flex;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  align-items:flex-start;
  padding:10px 12px;
  border-bottom:1px solid rgba(0,0,0,.06);
}
.controlsbar h1{ margin:0; font-size:14px; font-weight:800; }
.controlsbar .hint{ font-size:12px; color:var(--muted); margin-top:4px; }

.controls{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
fieldset{
  border:1px solid rgba(0,0,0,.08);
  border-radius:12px;
  padding:8px 10px;
  background:var(--chip-bg);
}
legend{ font-size:12px; padding:0 6px; color:var(--muted); }
.chk{ display:inline-flex; align-items:center; gap:6px; margin:4px 10px 4px 0; font-size:12px; }
.btn{
  border:1px solid rgba(0,0,0,.18);
  background:white;
  padding:6px 10px;
  border-radius:10px;
  font-size:12px;
  cursor:pointer;
}
.btn-primary{ background:#0d6efd; border-color:#0d6efd; color:#fff; }

.navrow{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
#monthPick{
  border:1px solid rgba(0,0,0,.18);
  background:#fff;
  padding:6px 10px;
  border-radius:10px;
  font-size:12px;
}

/* Sheet */
#print-sheet{ padding:12px; }
.card{
  background:#fff;
  border:1px solid rgba(0,0,0,.06);
  border-radius:14px;
  overflow:hidden;
}

/* Header inside sheet */
#sheetHeader{
  padding:12px 14px;
  border-bottom:1px solid rgba(0,0,0,.06);
}
#sheetTitle{
  font-size:22px;
  font-weight:900;
  letter-spacing:-0.02em;
  margin:0;
}
#sheetSub{ font-size:12px; color:var(--muted); margin:2px 0 0 0; }

#calendar{ padding:10px 12px 12px 12px; }

/* FullCalendar */
.fc-header-toolbar{ display:none; }
.fc-theme-standard .fc-scrollgrid,
.fc-theme-standard td, .fc-theme-standard th{ border-color: var(--grid-border); }
.fc .fc-day-today{ background: transparent !important; }

.fc .fc-col-header-cell-cushion{ font-size:12px; font-weight:900; color:#111827; }
.fc .fc-daygrid-day-number{ font-size:12px; color:#111827; }

/* Events: filled background, wrap, consistent sizing */
.fc-event{
  border-radius:8px;
  border:1px solid rgba(0,0,0,.14) !important;
  box-shadow:none !important;
}
.fc-event .fc-event-main,
.fc-event .fc-event-title,
.fc-event .fc-event-title-container{
  font-size:11px !important;
  line-height:1.18;
  font-weight:650;
  white-space:normal !important;
  overflow:visible !important;
}
.fc-event .fc-event-main{ padding:2px 6px 2px 8px; }

/* Default fill mapping (text colour is applied dynamically in JS for proper contrast) */
.fc-event[data-type="work_location"],
.fc-event[data-type="night_location"]{
  background: var(--c-dailyops) !important;
  border-color: var(--c-dailyops) !important;
}
.fc-event[data-type="regular"]{
  background: var(--c-regular) !important;
  border-color: var(--c-regular) !important;
}
.fc-event[data-type="oncall"]{
  background: var(--c-oncall) !important;
  border-color: var(--c-oncall) !important;
}
.fc-event[data-type="dutysheet"]{
  background: var(--c-duty) !important;
  border-color: var(--c-duty) !important;
}
.fc-event[data-type="booking"]{
  background: var(--c-booking) !important;
  border-color: var(--c-booking) !important;
}
.fc-event[data-type="birthday"]{
  background: var(--c-bday) !important;
  border-color: var(--c-bday) !important;
}
.fc-event[data-type="holiday"]{
  background: var(--c-holiday) !important;
  border-color: var(--c-holiday) !important;
}

/* PRINT: force one-page A4 landscape, and keep colours */
@page{
  size: A4 landscape;
  margin: 8mm;
}
@media print{
  *{
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  body{ background:#fff !important; }
  .wrap{ max-width:none !important; padding:0 !important; }
  .panel{ border:none !important; border-radius:0 !important; }
  .controlsbar{ display:none !important; }
  #print-sheet{ padding:0 !important; }

  /* A4 landscape printable height: 210 - 16 = 194mm */
  .card{
    border:1px solid rgba(0,0,0,.10) !important;
    border-radius:10px !important;
    height:194mm;
    display:flex;
    flex-direction:column;
  }

  #sheetHeader{ padding:4mm 4mm 3mm 4mm !important; }
  #sheetTitle{ font-size:18pt !important; }
  #sheetSub{ font-size:10pt !important; }

  #calendar{
    padding:3mm !important;
    flex:1 1 auto;
    min-height:0;
  }

  /* Liquid grid (print only) so rows distribute evenly */
  .fc, .fc-view-harness, .fc-view-harness-active, .fc-daygrid, .fc-scrollgrid{
    height:100% !important;
  }
  .fc .fc-scrollgrid,
  .fc .fc-scrollgrid-section,
  .fc .fc-scrollgrid-section > td,
  .fc .fc-daygrid-body,
  .fc .fc-daygrid-body table{
    height:100% !important;
  }
  .fc .fc-daygrid-body table{ table-layout: fixed; }
  .fc .fc-daygrid-day{ height: calc(100% / 6); } /* fixedWeekCount */

  .fc .fc-col-header-cell-cushion{ font-size:10pt !important; }
  .fc .fc-daygrid-day-number{ font-size:10pt !important; }
  .fc-event .fc-event-main,
  .fc-event .fc-event-title{ font-size:8.6pt !important; }

  .fc-event, .fc-daygrid-event{ break-inside:avoid; page-break-inside:avoid; }
  a[href]:after{ content:""; }
}
</style>
</head>

<body>
  <div class="wrap">
    <div class="panel">

      <div class="controlsbar">
        <div>
          <h1>Print Calendar</h1>
          <div class="hint">Disable “Headers and footers” in the print dialog for a clean fridge print.</div>
        </div>

        <div class="controls">
          <fieldset>
            <legend>Month</legend>
            <div class="navrow">
              <button class="btn" id="prevBtn" type="button">◀</button>
              <button class="btn" id="todayBtn" type="button">Today</button>
              <button class="btn" id="nextBtn" type="button">▶</button>
              <input type="month" id="monthPick">
            </div>
          </fieldset>

          <fieldset>
            <legend>Include</legend>
            <label class="chk"><input type="checkbox" id="incWork" checked> Work</label>
            <label class="chk"><input type="checkbox" id="incNight" checked> Night</label>
            <label class="chk"><input type="checkbox" id="incEvents" checked> Events</label>
            <label class="chk"><input type="checkbox" id="incDuty" checked> Duty</label>
            <label class="chk"><input type="checkbox" id="incOnCall" checked> On-call</label>
            <label class="chk"><input type="checkbox" id="incBookings" checked> Bookings</label>
            <label class="chk"><input type="checkbox" id="incBirthdays" checked> Birthdays</label>
            <label class="chk"><input type="checkbox" id="incHolidays" checked> UK Holidays</label>
          </fieldset>

          <button class="btn" id="refreshBtn" type="button">Refresh</button>
          <button class="btn btn-primary" id="printBtn" type="button">Print</button>
        </div>
      </div>

      <div id="print-sheet">
        <div class="card">
          <div id="sheetHeader">
            <p id="sheetTitle">.</p>
          </div>
          <div id="calendar"></div>
        </div>
      </div>

    </div>
  </div>

<script>
(function(){
  const magicKey = <?= json_encode($magicKey) ?>;
  const ymParam  = <?= json_encode($ym) ?>;

  // Map eventType -> fill colour (hex). Used to compute readable text colour.
  const typeColor = {
    work_location:  <?= json_encode($dailyOpsColor) ?>,
    night_location: <?= json_encode($dailyOpsColor) ?>,
    regular:        <?= json_encode($eventColor) ?>,
    oncall:         <?= json_encode($onCallColor) ?>,
    dutysheet:      <?= json_encode($dutySheetColor) ?>,
    booking:        <?= json_encode($bookingColor) ?>,
    birthday:       <?= json_encode($birthdayColor) ?>,
    holiday:        <?= json_encode($holidayColor) ?>
  };

  function pad2(n){ return String(n).padStart(2,'0'); }
  function ymFromDate(date){ return date.getFullYear() + '-' + pad2(date.getMonth()+1); }
  function fmtMonthTitle(date){ return date.toLocaleDateString('en-GB', { month:'long', year:'numeric' }); }

  const startYM = (/^\d{4}-\d{2}$/.test(ymParam)) ? ymParam : ymFromDate(new Date());
  const monthStartISO = startYM + '-01';

  function escapeHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function normalizeAllDayInclusiveEnd(ev){
    if (!ev || !ev.start || !ev.end) return ev;
    try{
      const s = (ev.start instanceof Date) ? ev.start : new Date(ev.start);
      const e = (ev.end   instanceof Date) ? ev.end   : new Date(ev.end);
      if (isNaN(s.getTime()) || isNaN(e.getTime())) return ev;

      const sIsMidnight = (s.getHours()===0 && s.getMinutes()===0);
      const eIs2359 = (e.getHours()===23 && e.getMinutes()===59);
      if (sIsMidnight && eIs2359){
        ev.allDay = true;
        ev.end = new Date(e.getTime() + 60*1000);
      }
    }catch(_e){}
    return ev;
  }

  function hexToRgb(hex){
    hex = String(hex || '').trim().replace('#','');
    if (hex.length === 3) hex = hex.split('').map(x => x + x).join('');
    if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
    const num = parseInt(hex, 16);
    return { r:(num>>16)&255, g:(num>>8)&255, b:num&255 };
  }

  // Returns '#000' or '#fff' based on perceived luminance
  function contrastText(hex){
    const rgb = hexToRgb(hex);
    if (!rgb) return '#000';
    const lum = (0.299*rgb.r + 0.587*rgb.g + 0.114*rgb.b) / 255;
    return lum > 0.60 ? '#000' : '#fff';
  }

  function formatTimeHM(date){
    if (!date) return '';
    return date.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
  }

  function getIncludeState(){
    return {
      work: document.getElementById('incWork').checked,
      night: document.getElementById('incNight').checked,
      events: document.getElementById('incEvents').checked,
      duty: document.getElementById('incDuty').checked,
      oncall: document.getElementById('incOnCall').checked,
      bookings: document.getElementById('incBookings').checked,
      birthdays: document.getElementById('incBirthdays').checked,
      holidays: document.getElementById('incHolidays').checked
    };
  }

  // Cache GOV holidays per page load
  let __ukHolidaysAll = null;
  async function fetchUkBankHolidaysAll(){
    if (__ukHolidaysAll) return __ukHolidaysAll;
    const resp = await fetch('https://www.gov.uk/bank-holidays.json', { mode:'cors' });
    if (!resp.ok) throw new Error('bank holidays fetch failed');
    __ukHolidaysAll = await resp.json();
    return __ukHolidaysAll;
  }

  async function getUkHolidayEventsForYM(ym){
    const s = getIncludeState();
    if (!s.holidays) return [];

    const json = await fetchUkBankHolidaysAll();
    const region = (json && (json['england-and-wales'] || json['scotland'] || json['northern-ireland']));
    const events = (region && region.events) ? region.events : [];
    const [yy, mm] = ym.split('-').map(Number);

    return events
      .filter(e => {
        const d = new Date((e.date || '') + 'T00:00:00');
        return !isNaN(d.getTime()) && d.getFullYear() === yy && (d.getMonth()+1) === mm;
      })
      .map(e => ({
        title: e.title || 'Holiday',
        start: e.date,
        allDay: true,
        extendedProps: { eventType: 'holiday' }
      }));
  }

  function setHeader(anchorDate){
    document.getElementById('sheetTitle').textContent = fmtMonthTitle(anchorDate);
  }

  const calendarEl = document.getElementById('calendar');

  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    initialDate: monthStartISO,
    timeZone: 'local',
    firstDay: 1,
    locale: 'en-gb',

    height: 'auto',
    fixedWeekCount: true,
    dayMaxEventRows: 4,
    headerToolbar: false,

    eventSources: [
      {
        id: 'main',
        url: 'fetch_events.php',
        method: 'GET',
        extraParams: function(){
          const s = getIncludeState();
          return {
            magic_key: magicKey,
            pill_work:    s.work   ? 1 : 0,
            pill_night:   s.night  ? 1 : 0,
            pill_regular: s.events ? 1 : 0,
            pill_duty:    s.duty   ? 1 : 0,
            pill_oncall:  s.oncall ? 1 : 0
          };
        }
      },
      {
        id: 'bookings',
        url: 'fetch_bookings.php',
        method: 'GET',
        extraParams: function(){
          const s = getIncludeState();
          return { magic_key: magicKey, enabled: s.bookings ? 1 : 0 };
        }
      },
      {
        id: 'birthdays',
        url: 'fetch_birthdays.php',
        method: 'GET',
        extraParams: function(){
          const s = getIncludeState();
          return { magic_key: magicKey, enabled: s.birthdays ? 1 : 0 };
        }
      },
      {
        id: 'holidays',
        events: async function(_fetchInfo, success, failure){
          try{
            const ym = ymFromDate(calendar.getDate());
            const evs = await getUkHolidayEventsForYM(ym);
            success(evs);
          } catch (e){
            failure(e);
          }
        }
      }
    ],

    eventDataTransform: function(raw){ return normalizeAllDayInclusiveEnd(raw); },

    // Timed events show time after title; all-day unchanged
    eventContent: function(arg){
      const ev = arg.event;
      const title = ev.title || '';

      if (ev.allDay){
        return { html: '<div class="fc-event-title">' + escapeHtml(title) + '</div>' };
      }

      const st = formatTimeHM(ev.start);
      const en = formatTimeHM(ev.end);
      const range = (st && en) ? `${st}–${en}` : (st ? st : '');
      const suffix = range ? ` (${range})` : '';
      return { html: '<div class="fc-event-title">' + escapeHtml(title + suffix) + '</div>' };
    },

    eventDidMount: function(info){
      const t = info.event.extendedProps.eventType || '';
      if (t) info.el.setAttribute('data-type', t);

      // Compute text colour from the configured fill for this type
      const fill = typeColor[t] || '#ffffff';
      const tc = contrastText(fill);

      info.el.style.color = tc;
      const main = info.el.querySelector('.fc-event-main');
      if (main) main.style.color = tc;
      const title = info.el.querySelector('.fc-event-title');
      if (title) title.style.color = tc;
    },

    datesSet: function(){
      setHeader(calendar.getDate());
      document.getElementById('monthPick').value = ymFromDate(calendar.getDate());
      calendar.getEventSourceById('holidays').refetch();
    }
  });

  calendar.render();
  setHeader(calendar.getDate());
  document.getElementById('monthPick').value = startYM;

  // Month navigation
  document.getElementById('prevBtn').addEventListener('click', () => calendar.prev());
  document.getElementById('nextBtn').addEventListener('click', () => calendar.next());
  document.getElementById('todayBtn').addEventListener('click', () => calendar.today());
  document.getElementById('monthPick').addEventListener('change', function(){
    if (!this.value) return;
    calendar.gotoDate(this.value + '-01');
  });

  function refetchAll(){
    calendar.getEventSourceById('main').refetch();
    calendar.getEventSourceById('bookings').refetch();
    calendar.getEventSourceById('birthdays').refetch();
    calendar.getEventSourceById('holidays').refetch();
  }

  document.getElementById('refreshBtn').addEventListener('click', refetchAll);
  ['incWork','incNight','incEvents','incDuty','incOnCall','incBookings','incBirthdays','incHolidays']
    .forEach(id => document.getElementById(id).addEventListener('change', refetchAll));

  document.getElementById('printBtn').addEventListener('click', function(){
    refetchAll();
    setTimeout(() => window.print(), 250);
  });

})();
</script>

</body>
</html>
