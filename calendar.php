<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset("utf8mb4");

$userID = $_SESSION['userID'] ?? 0;
$isUser3 = ($userID === 4);
$isKioskDefault = $isUser3;

/* ---------- colours ---------- */
$sqlColors = "SELECT SettingKey, SettingValue FROM UserSettings
              WHERE UserID = ?
              AND SettingKey IN ('DailyOpsColor','EventColor','OnCallColor','DutySheetColor')";
$stmtColors = $link->prepare($sqlColors);
$stmtColors->bind_param('i', $userID);
$stmtColors->execute();
$resColors = $stmtColors->get_result();
$colorSettings = [];
while ($row = $resColors->fetch_assoc()) $colorSettings[$row['SettingKey']] = $row['SettingValue'];

$dailyOpsColor  = $colorSettings['DailyOpsColor'] ?? '#29af8f';
$eventColor     = $colorSettings['EventColor'] ?? '#bb3678';
$onCallColor    = $colorSettings['OnCallColor'] ?? '#ff0000';
$dutySheetColor = $colorSettings['DutySheetColor'] ?? '#FFD700';
$birthdayColor  = '#ff8c00';

/* ---------- preferences ---------- */
$sqlPrefs = "SELECT SettingKey, SettingValue FROM UserSettings
             WHERE UserID = ? AND SettingKey IN
             ('ShowWorkLocation','ShowNightLocation','ShowEvents','ShowOnCall','ShowDutySheet')";
$stmtPrefs = $link->prepare($sqlPrefs);
$stmtPrefs->bind_param('i', $userID);
$stmtPrefs->execute();
$resPrefs = $stmtPrefs->get_result();
$pref = [];
while ($row = $resPrefs->fetch_assoc()) $pref[$row['SettingKey']] = $row['SettingValue'];

/* user 3 defaults */
$showWorkLocation  = $isUser3 ? false : !empty($pref['ShowWorkLocation']);
$showNightLocation = $isUser3 ? false : !empty($pref['ShowNightLocation']);
$showEvents        = $isUser3 ? true  : !empty($pref['ShowEvents']);
$showOnCall        = $isUser3 ? true  : !empty($pref['ShowOnCall']);
$showDutySheet     = $isUser3 ? true  : !empty($pref['ShowDutySheet']);

$link->close();

/* ---------- iPhone calendar sync (ICS feed) ---------- */
$icsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$icsHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$icsDir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$icsFeedUrl = $icsScheme . '://' . $icsHost . $icsDir . '/calendar_ics.php?token=' . rawurlencode(MAGIC_KEY);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Calendar</title>
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<base target="_self">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
<style>
:root{
  --grid-border:#d0d7de;
  --chip-bg:#f6f8fa;
  --text:#0a0a0a;
  --bg:#f3f4f6;
  --panel:#ffffff;
}

html, body { min-height:100%; }
body { margin:0; background:var(--bg); }
main.page-pad { padding:10px; }

/* Constrain content width on desktop; keep full width on mobile */
.content-wrap{
  max-width: 1200px;
  margin: 0 auto;
}
@media (max-width: 576px){
  main.page-pad { padding:8px; }
  .content-wrap{ max-width: 100%; }
}

/* top bar */
#topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:8px;
  margin-bottom:6px;
}
#monthTitle{
  font-size:1.05rem;
  font-weight:600;
  color:var(--text);
}

/* legend */
#legend{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
  margin-bottom:8px;
}
.legend-chip{
  cursor:pointer;
  border:1px solid rgba(0,0,0,.08);
  border-radius:999px;
  padding:3px 12px 3px 10px;
  font-size:12px;
  background:var(--chip-bg);
  display:flex;
  gap:4px;
  align-items:center;
  transition:background .1s;
}
.legend-chip::before{
  content:"";
  width:8px;
  height:8px;
  border-radius:999px;
  background:#666;
}
.legend-chip.off{ opacity:.45; }
.legend-chip[data-src="work_location"]::before{ background:<?= htmlspecialchars($dailyOpsColor) ?>; }
.legend-chip[data-src="night_location"]::before{ background:<?= htmlspecialchars($dailyOpsColor) ?>; }
.legend-chip[data-src="regular"]::before{ background:<?= htmlspecialchars($eventColor) ?>; }
.legend-chip[data-src="dutysheet"]::before{ background:<?= htmlspecialchars($dutySheetColor) ?>; }
.legend-chip[data-src="oncall"]::before{ background:<?= htmlspecialchars($onCallColor) ?>; }
.legend-chip[data-src="booking"]::before{ background:#9b59b6; }
.legend-chip[data-src="birthday"]::before{ background:<?= htmlspecialchars($birthdayColor) ?>; }

/* calendar wrapper */
#calendar-shell{
  background:var(--panel);
  border:1px solid rgba(0,0,0,.05);
  border-radius:10px;
  margin-bottom:10px;
  overflow:hidden;
}
#calendar{
  width:100%;
}

/* FullCalendar visuals */
.fc { width:100%; }
.fc-header-toolbar{
  padding:6px 10px 0 10px;
}
.fc .fc-toolbar-title{
  font-size:1rem;
  font-weight:600;
  color:var(--text);
}
.fc-theme-standard .fc-scrollgrid,
.fc-theme-standard td, .fc-theme-standard th{
  border-color:var(--grid-border);
}
.fc-event{
  border:none!important;
  box-shadow:0 1px 0 rgba(0,0,0,.08);
  border-radius:6px;
}
.fc-event .fc-event-main{
  padding:2px 8px 2px 12px;
  font-size:12.5px;
  line-height:1.25;
  font-weight:500;
  white-space:normal;
  word-break:break-word;
}

/* coloured bars */
.fc-event[data-type="booking"]{ border-left:4px solid #9b59b6!important; }
.fc-event[data-type="regular"]{ border-left:4px solid <?= htmlspecialchars($eventColor) ?>!important; }
.fc-event[data-type="dutysheet"]{ border-left:4px solid <?= htmlspecialchars($dutySheetColor) ?>!important; }
.fc-event[data-type="oncall"]{ border-left:4px solid <?= htmlspecialchars($onCallColor) ?>!important; }
.fc-event[data-type="work_location"],
.fc-event[data-type="night_location"]{ border-left:4px solid <?= htmlspecialchars($dailyOpsColor) ?>!important; }
.fc-event[data-type="birthday"]{ border-left:4px solid <?= htmlspecialchars($birthdayColor) ?>!important; }

/* kiosk hides bottom items */
.fc-kiosk-on #legend,
.fc-kiosk-on #bottom-accordion,
.fc-kiosk-on .navbar,
.fc-kiosk-on footer{ display:none!important; }

#bottom-accordion{ margin-bottom:10px; }

#loading-spinner{
  display:none;
  position:fixed;
  z-index:9999;
  top:50%;
  left:50%;
  transform:translate(-50%, -50%);
}
.spinner-border{ width:3rem; height:3rem; border-width:.3em; }

#js-error{
  display:none;
  position:fixed;
  z-index:10000;
  top:8px;
  left:50%;
  transform:translateX(-50%);
  background:#b00020;
  color:#fff;
  padding:6px 10px;
  border-radius:6px;
  font-size:12px;
  max-width: calc(100vw - 16px);
}

@media (max-width: 768px){
  #topbar{ flex-wrap:wrap; }
  .fc .fc-toolbar{ flex-wrap:wrap; gap:6px; }
  .fc .fc-toolbar-title{ width:100%; text-align:left; }
}
</style>
</head>
<body class="<?= $isKioskDefault ? 'fc-kiosk-on' : 'fc-kiosk-off' ?>">

<?php include 'header.php'; ?>

<main class="page-pad">
  <div class="content-wrap">
    <div id="js-error"></div>

    <div id="topbar">
      <div id="monthTitle">.</div>
      <div>
        <button id="kioskBtn" class="btn btn-sm btn-outline-secondary" type="button">Kiosk</button>
        <a href="create_event.php" class="btn btn-sm btn-primary ml-1 btn-create">Create</a>
        <a id="printBtn"
   href="print_calendar.php"
   class="btn btn-sm btn-outline-primary ml-1">
  Print
</a>

        <a href="https://aceso.dcworld.uk/crons/import_dutysheet_events.php" class="btn btn-sm btn-info ml-1">Refresh Duties</a>
        <button id="syncBtn" class="btn btn-sm btn-outline-dark ml-1" type="button" data-toggle="modal" data-target="#syncModal">Sync to iPhone</button>
      </div>
    </div>

    <!-- iPhone / iCal sync modal -->
    <div class="modal fade" id="syncModal" tabindex="-1" role="dialog" aria-labelledby="syncModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="syncModalLabel">Sync with your iPhone calendar</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <p class="mb-2">Add these events to your iPhone's Calendar app. Events stay up to date automatically.</p>
            <ol class="pl-3 mb-3" style="font-size:14px;">
              <li>Tap <strong>Subscribe on iPhone</strong> below (on your phone).</li>
              <li>iOS opens the Calendar app and asks to add a subscribed calendar — tap <strong>Subscribe</strong>.</li>
              <li>New and changed events will refresh on their own.</li>
            </ol>

            <a id="webcalLink" class="btn btn-primary btn-block mb-3" href="#">Subscribe on iPhone</a>

            <label class="small text-muted mb-1">Or copy this subscription link and add it manually<br>(Settings &rarr; Calendar &rarr; Accounts &rarr; Add Subscribed Calendar):</label>
            <div class="input-group input-group-sm mb-3">
              <input type="text" id="syncUrl" class="form-control" readonly value="">
              <div class="input-group-append">
                <button class="btn btn-outline-secondary" type="button" id="copySyncUrl">Copy</button>
              </div>
            </div>

            <a id="downloadIcs" class="btn btn-outline-secondary btn-sm" href="#">Download a one-off .ics file</a>
          </div>
        </div>
      </div>
    </div>

    <div id="legend">
      <span class="legend-chip<?= $showWorkLocation ? '' : ' off' ?>" data-src="work_location">Work</span>
      <span class="legend-chip<?= $showNightLocation ? '' : ' off' ?>" data-src="night_location">Night</span>
      <span class="legend-chip<?= $showEvents ? '' : ' off' ?>" data-src="regular">Events</span>
      <span class="legend-chip<?= $showDutySheet ? '' : ' off' ?>" data-src="dutysheet">Duty</span>
      <span class="legend-chip<?= $showOnCall ? '' : ' off' ?>" data-src="oncall">On-call</span>
      <span class="legend-chip" data-src="booking">Bookings</span>
      <span class="legend-chip" data-src="birthday">Birthdays</span>
    </div>

    <div id="loading-spinner"><div class="spinner-border text-primary" role="status" aria-label="loading"></div></div>

    <div id="calendar-shell">
      <div id="calendar"></div>
    </div>

    <div id="bottom-accordion" class="accordion mb-3">
      <div class="card">
        <div class="card-header p-2" id="headingOne">
          <h2 class="mb-0">
            <button class="btn btn-link btn-sm" type="button" data-toggle="collapse" data-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
              Settings &amp; Errors
            </button>
          </h2>
        </div>
        <div id="collapseOne" class="collapse" aria-labelledby="headingOne" data-parent="#bottom-accordion">
          <div class="card-body">
            <h6>Colors</h6>
            <form id="colorForm" class="row">
              <div class="form-group col-6 col-md-3">
                <label>DailyOps</label>
                <input type="color" id="dailyOpsColor" value="<?= htmlspecialchars($dailyOpsColor) ?>" class="form-control">
              </div>
              <div class="form-group col-6 col-md-3">
                <label>Regular</label>
                <input type="color" id="eventColor" value="<?= htmlspecialchars($eventColor) ?>" class="form-control">
              </div>
              <div class="form-group col-6 col-md-3">
                <label>On-call</label>
                <input type="color" id="onCallColor" value="<?= htmlspecialchars($onCallColor) ?>" class="form-control">
              </div>
              <div class="form-group col-6 col-md-3">
                <label>DutySheet</label>
                <input type="color" id="dutySheetColor" value="<?= htmlspecialchars($dutySheetColor) ?>" class="form-control">
              </div>
              <div class="col-12">
                <button type="button" class="btn btn-sm btn-primary" id="saveColorsButton">Save Colors</button>
              </div>
            </form>
            <hr>
            <p class="mb-0">Errors will appear at the top.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include 'footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
(function(){
  window.addEventListener('error', function(e){
    var box = document.getElementById('js-error');
    if (!box) return;
    box.textContent = 'Script error: ' + (e.message || 'unknown');
    box.style.display = 'block';
    var sp = document.getElementById('loading-spinner');
    if (sp) sp.style.display = 'none';
  });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var calendarEl = document.getElementById('calendar');
  var spinner = document.getElementById('loading-spinner');
  var magicKey = new URLSearchParams(window.location.search).get('magic_key') || '';

  var state = {
    kiosk: document.body.classList.contains('fc-kiosk-on'),
    pills: {
      work_location:  <?= $showWorkLocation  ? 'true':'false' ?>,
      night_location: <?= $showNightLocation ? 'true':'false' ?>,
      regular:        <?= $showEvents        ? 'true':'false' ?>,
      dutysheet:      <?= $showDutySheet     ? 'true':'false' ?>,
      oncall:         <?= $showOnCall        ? 'true':'false' ?>,
      booking:        true,
      birthday:       true
    }
  };

  function showJsError(msg){
    var box = document.getElementById('js-error');
    if (!box) return;
    box.textContent = msg;
    box.style.display = 'block';
  }

  // Fix for multi-day "all day" items that come back as:
  // start 00:00 and end 23:59 (inclusive) — FullCalendar treats end as exclusive.
  function normalizeAllDayInclusiveEnd(ev){
    if (!ev) return ev;

    var start = ev.start;
    var end = ev.end;

    // If the feed uses strings, FullCalendar will parse them later; this transform is invoked after parsing.
    // Still, be defensive.
    try {
      if (!start || !end) return ev;

      var s = (start instanceof Date) ? start : new Date(start);
      var e = (end instanceof Date) ? end : new Date(end);
      if (isNaN(s.getTime()) || isNaN(e.getTime())) return ev;

      var sIsMidnight = (s.getHours() === 0 && s.getMinutes() === 0);
      var eIs2359 = (e.getHours() === 23 && e.getMinutes() === 59);

      // Only apply when it looks like an "inclusive" all-day range.
      if (sIsMidnight && eIs2359) {
        // Make it all-day and convert inclusive end to exclusive by adding 1 minute (rolls to next day 00:00)
        ev.allDay = true;
        var e2 = new Date(e.getTime() + 60 * 1000);
        ev.end = e2;
      }

      return ev;
    } catch (_err) {
      return ev;
    }
  }

  var calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    timeZone: 'local',
    firstDay: 1,
    locale: 'en-gb',

    // Do not force full-screen height; allow natural page scroll
    height: 'auto',
    contentHeight: 'auto',
    expandRows: false,

    dayMaxEventRows: 4,
    headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,customThreeDay,timeGridWeek,timeGridDay' },
    views: {
      customThreeDay: { type:'timeGrid', duration:{ days:3 }, buttonText:'3 days' }
    },

    eventSources: [
      {
        id: 'main',
        url: 'fetch_events.php',
        method: 'GET',
        extraParams: function(){
          return {
            magic_key: magicKey,
            pill_work: state.pills.work_location ? 1:0,
            pill_night: state.pills.night_location ? 1:0,
            pill_regular: state.pills.regular ? 1:0,
            pill_duty: state.pills.dutysheet ? 1:0,
            pill_oncall: state.pills.oncall ? 1:0
          };
        },
        failure: function(){ showJsError('Failed to load events'); }
      },
      {
        id: 'bookings',
        url: 'fetch_bookings.php',
        method: 'GET',
        extraParams: function(){ return { magic_key: magicKey, enabled: state.pills.booking ? 1:0 }; }
      },
      {
        id: 'birthdays',
        url: 'fetch_birthdays.php',
        method: 'GET',
        extraParams: function(){ return { magic_key: magicKey, enabled: state.pills.birthday ? 1:0 }; }
      }
    ],

    // Normalize incoming data to fix inclusive end timestamps
    eventDataTransform: function(rawEventData){
      return normalizeAllDayInclusiveEnd(rawEventData);
    },

    loading: function(isLoading){
      if (spinner) spinner.style.display = isLoading ? 'block' : 'none';
    },

    eventDidMount: function(info){
      var t = info.event.extendedProps.eventType || '';
      if (t) info.el.setAttribute('data-type', t);

      var loc = info.event.extendedProps.location ? (' @ ' + info.event.extendedProps.location) : '';
      info.el.title = (info.event.title || '') + loc;
    },

    datesSet: function(arg){
      var mt = document.getElementById('monthTitle');
      if (mt) mt.textContent = arg.view.title;
    }
  });

  calendar.render();
  document.getElementById('monthTitle').textContent = calendar.view.title;

  // kiosk
  var kioskBtn = document.getElementById('kioskBtn');
  function setKioskLabel(){ kioskBtn.textContent = state.kiosk ? 'Exit Kiosk' : 'Kiosk'; }
  setKioskLabel();
  kioskBtn.addEventListener('click', function(){
    state.kiosk = !state.kiosk;
    document.body.classList.toggle('fc-kiosk-on', state.kiosk);
    document.body.classList.toggle('fc-kiosk-off', !state.kiosk);
    setKioskLabel();
  });

  // iPhone / iCal sync modal wiring
  (function(){
    var feedUrl = <?= json_encode($icsFeedUrl) ?>;          // https://.../calendar_ics.php?token=...
    var webcalUrl = feedUrl.replace(/^https?:\/\//i, 'webcal://'); // iOS opens Calendar app

    var webcalLink = document.getElementById('webcalLink');
    var syncUrlInput = document.getElementById('syncUrl');
    var downloadIcs = document.getElementById('downloadIcs');
    var copyBtn = document.getElementById('copySyncUrl');

    if (webcalLink) webcalLink.setAttribute('href', webcalUrl);
    if (syncUrlInput) syncUrlInput.value = feedUrl;
    if (downloadIcs) downloadIcs.setAttribute('href', feedUrl + '&download=1');

    if (copyBtn && syncUrlInput) {
      copyBtn.addEventListener('click', function(){
        syncUrlInput.focus();
        syncUrlInput.select();
        var done = function(){
          var old = copyBtn.textContent;
          copyBtn.textContent = 'Copied';
          setTimeout(function(){ copyBtn.textContent = old; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(syncUrlInput.value).then(done, function(){
            try { document.execCommand('copy'); done(); } catch(e){}
          });
        } else {
          try { document.execCommand('copy'); done(); } catch(e){}
        }
      });
    }
  })();

  // legend
  document.getElementById('legend').addEventListener('click', function(e){
    var chip = e.target.closest('.legend-chip'); if (!chip) return;
    var key = chip.dataset.src;
    state.pills[key] = !state.pills[key];
    chip.classList.toggle('off', !state.pills[key]);

    if (key === 'booking') {
      calendar.getEventSourceById('bookings').refetch();
    } else if (key === 'birthday') {
      calendar.getEventSourceById('birthdays').refetch();
    } else {
      calendar.getEventSourceById('main').refetch();
      // persist
      var fd = new FormData();
      fd.append('ShowWorkLocation',  state.pills.work_location ? 1:0);
      fd.append('ShowNightLocation', state.pills.night_location ? 1:0);
      fd.append('ShowEvents',        state.pills.regular ? 1:0);
      fd.append('ShowOnCall',        state.pills.oncall ? 1:0);
      fd.append('ShowDutySheet',     state.pills.dutysheet ? 1:0);
      fetch('save_event_preferences.php', { method:'POST', body:fd });
    }
  });

  // save colors
  document.getElementById('saveColorsButton').addEventListener('click', function(){
    var fd = new FormData();
    fd.append('dailyOpsColor', document.getElementById('dailyOpsColor').value);
    fd.append('eventColor',    document.getElementById('eventColor').value);
    fd.append('onCallColor',   document.getElementById('onCallColor').value);
    fd.append('dutySheetColor',document.getElementById('dutySheetColor').value);
    fetch('save_colors.php', { method:'POST', body:fd })
      .then(r=>r.text())
      .then(t=>{ alert(t==='success'?'Colors updated.':'Failed to save colors.'); if (t==='success') location.reload(); });
  });

  // propagate magic_key
  (function(){
    if (!magicKey) return;
    document.querySelectorAll("a[href]").forEach(function(link){
      var href = link.getAttribute("href");
      if (!href || href.startsWith("http") || href.indexOf("magic_key=")!==-1) return;
      var sep = href.indexOf("?")>-1 ? "&" : "?";
      link.setAttribute("href", href + sep + "magic_key=" + encodeURIComponent(magicKey));
    });
    document.querySelectorAll("form").forEach(function(form){
      if (!form.querySelector("input[name='magic_key']")) {
        var hidden = document.createElement("input");
        hidden.type="hidden"; hidden.name="magic_key"; hidden.value=magicKey;
        form.appendChild(hidden);
      }
    });
  })();

  // ---- Print button: include current month (YYYY-MM) + magic_key ----
  (function setupPrintButton(){
    var printA = document.getElementById('printBtn');
    if (!printA) return;

    function pad2(n){ return (n < 10 ? '0' : '') + n; }

    function ymFromDate(d){
      // month shown by FC view uses a date range; use currentDate as the anchor
      var y = d.getFullYear();
      var m = d.getMonth() + 1;
      return y + '-' + pad2(m);
    }

    function updatePrintHref(){
      // Use calendar.getDate() which tracks the current navigated date
      var d = calendar.getDate();
      var ym = ymFromDate(d);

      var url = new URL('print_calendar.php', window.location.origin + window.location.pathname);
      url.pathname = url.pathname.replace(/\/[^\/]*$/, '/print_calendar.php'); // keep same directory

      if (magicKey) url.searchParams.set('magic_key', magicKey);
      url.searchParams.set('ym', ym);

      printA.setAttribute('href', url.pathname + '?' + url.searchParams.toString());
    }

    // Initial set
    updatePrintHref();

    // Keep updated as user navigates months/weeks/days
    var oldDatesSet = calendar.getOption('datesSet');
    calendar.setOption('datesSet', function(arg){
      if (typeof oldDatesSet === 'function') oldDatesSet(arg);
      updatePrintHref();
    });
  })();

});

</script>
</body>
</html>
