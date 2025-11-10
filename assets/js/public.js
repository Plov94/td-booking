// TD Booking Public JS
jQuery(document).ready(function($){
  var $form = $('.td-booking-form');
  if (!$form.length) return;
  
  var $slots = $form.find('.td-booking-slots');
  var $calendar = $form.find('.td-booking-calendar');
  var $msg = $form.find('.td-booking-message');
  var $submitBtn = $form.find('button[type="submit"]');
  var slots = [];
  // If a date is selected before availability is loaded, remember it and render once loaded
  var pendingRenderDate = null;
  // Safe config access for cross-browser support (avoid optional chaining)
  var cfg = (typeof window !== 'undefined' && window.tdBooking) ? window.tdBooking : {};
  var labels = cfg.labels || {};
  var rules = cfg.rules || {};
  var restUrl = cfg.restUrl || '';
  var wpNonce = cfg.nonce || '';
  var wcEnabled = $form.data('wc') == 1 || !!cfg.wcEnabled;
  var stepsEnabled = ($form.data('steps') == 1) || !!cfg.stepsEnabled;
  var staffLimit = parseInt(cfg.staffLimit || '0', 10) || 0;
  var staffAgnostic = !!cfg.staffAgnostic;
  var staffSelectEnabled = !!cfg.staffSelectEnabled;
  var locale = cfg.locale || (navigator.language || 'en-US');
  // For time display, prefer browser locale when auto mode is enabled
  var fmtLocale = (cfg.timeFormat === 'auto') ? (navigator.language || locale) : locale;
  var startOfWeek = (typeof cfg.startOfWeek === 'number') ? cfg.startOfWeek : 1;
  var defaultDurationMin = (cfg.rules && typeof cfg.rules.defaultDurationMin === 'number') ? cfg.rules.defaultDurationMin : 30;

  function safeIncludes(str, sub){
    if (str == null) return false;
    return String(str).indexOf(sub) !== -1;
  }

  // Initialize form structure for better UX
  initializeForm();
  // Terms modal support
  (function initTermsModal(){
    var termsCfg = cfg.terms || {};
    if (termsCfg.mode !== 'modal') return;
    // Inject modal container once
    if (!document.getElementById('td-terms-modal')) {
      var modal = document.createElement('div');
      modal.id = 'td-terms-modal';
      modal.setAttribute('role','dialog');
      modal.setAttribute('aria-modal','true');
      modal.style.cssText = 'position:fixed;inset:0;display:none;background:rgba(0,0,0,0.45);z-index:99999;';
      modal.innerHTML = ''+
        '<div class="td-terms-dialog" style="max-width:720px;margin:5vh auto;background:#fff;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.2);overflow:auto;max-height:90vh;">'
        +'<div class="td-terms-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb;">'
        +'<strong>' + (labels.termsTitle || 'Terms & Conditions') + '</strong>'
        +'<button type="button" class="td-terms-close" aria-label="' + (labels.close || 'Close') + '" style="border:0;background:transparent;font-size:20px;line-height:1;cursor:pointer">×</button>'
        +'</div>'
        +'<div class="td-terms-body" style="padding:16px;">' + (labels.loadingText || 'Loading…') + '</div>'
        +'</div>';
      document.body.appendChild(modal);
      modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
      modal.querySelector('.td-terms-close').addEventListener('click', closeModal);
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
    }
    // Open handler
    $form.on('click', '.td-terms-link', function(e){
      e.preventDefault();
  openModal(labels.loadingText || 'Loading…');
      // Prefer server-side endpoint to avoid CORS issues
      fetch((cfg.restUrl || '') + 'terms', {
        headers: { 'X-WP-Nonce': (cfg.nonce || '') },
        credentials: 'include'
      })
      .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
      .then(function(data){
        var html = (data && (data.html || data.content)) || '';
        if (!html) throw new Error('no terms');
        openModal(html);
      })
      .catch(function(){
        // As a final fallback, open configured href in new tab if available
        var href = $(e.currentTarget).data('terms-href') || termsCfg.href;
        closeModal();
        if (href) window.open(href, '_blank', 'noopener');
      });
    });

    function openModal(content){
      var modal = document.getElementById('td-terms-modal');
      if (!modal) return;
      modal.querySelector('.td-terms-body').innerHTML = content;
      modal.style.display = 'block';
      // Focus trap minimal: focus close button
      var btn = modal.querySelector('.td-terms-close');
      if (btn) btn.focus();
      document.body.style.overflow = 'hidden';
    }
    function closeModal(){
      var modal = document.getElementById('td-terms-modal');
      if (!modal) return;
      modal.style.display = 'none';
      document.body.style.overflow = '';
    }
  })();

  function initializeForm() {
    // Step-by-step gating: hide later sections until selections made
    if (stepsEnabled) {
      try {
        // Sections: service-selection -> calendar/slots -> customer-info -> submit
        var $serviceSel = $form.find('.service-selection');
        var $calendarWrap = $calendar.closest('.td-booking-calendar');
        var $slotWrap = $slots;
        var $customerInfo = $form.find('.customer-info');
        var $termsWrap = $form.find('.checkbox-label');
        // Initial state
        $calendarWrap.hide();
        $slotWrap.hide();
        $customerInfo.hide();
        if ($termsWrap.length) { $termsWrap.hide(); }
        $submitBtn.closest('p,div,section,form').find('button[type="submit"]').prop('disabled', true);
        // If service is preselected (service/staff shortcodes), reveal the calendar immediately
        var preselectedService = $form.find('[name=service_id]').val();
        if (preselectedService) {
          $calendarWrap.show();
          // Keep slots and customer info hidden until a date/slot is chosen
        }
        // If staff-only (staffAgnostic) with a fixed/selected staff, reveal the calendar as well
        var hasStaffFixed = !!staffLimit;
        var hasStaffSelected = hasStaffFixed || (staffSelectEnabled && parseInt(($form.find('[name=staff_id]').val()||'0'),10)>0);
        if (staffAgnostic && hasStaffSelected) {
          $calendarWrap.show();
        }
        // On service change: show calendar and refresh staff list when enabled
        $form.on('change', '[name=service_id]', function(){
          $calendarWrap.show();
          $slotWrap.hide();
          $customerInfo.hide();
          if ($termsWrap.length) { $termsWrap.hide(); }
          $submitBtn.prop('disabled', true);
          if (staffSelectEnabled) { populateStaffDropdown(); }
        });
        // On slot select: show customer info
        $form.on('change', 'input[name=slot]', function(){
          $slotWrap.show();
          $customerInfo.show();
          if ($termsWrap.length) { $termsWrap.show(); }
        });
        // Enable submit when required fields ready
        $form.on('input change', 'input, select, textarea', function(){
          var serviceOk = !!$form.find('[name=service_id]').val();
          if (staffAgnostic) { serviceOk = true; }
          var ready = !!(serviceOk && $form.find('[name=slot]').val() && $form.find('[name=name]').val() && $form.find('[name=email]').val() && $form.find('[name=terms]').is(':checked'));
          $submitBtn.prop('disabled', !ready);
        });
      } catch(e) {}
    }

    // Group size +/- buttons
    $form.on('click', '.td-qty-btn', function(){
      var op = $(this).data('op');
      var $qty = $('#td-booking-participants');
      if (!$qty.length) return;
      var v = parseInt($qty.val() || '1', 10);
      if (op === '+') v = Math.min(99, v + 1); else v = Math.max(1, v - 1);
      $qty.val(v).trigger('change');
    });
  // Add loading class to slots container
    $slots.addClass('loading');
    
    // Avoid creating duplicate wrappers/headers; rely on server-rendered markup
    // Only wrap if markup isn't already grouped
    var customerFields = $form.find('input[name="name"], input[name="email"], input[name="phone"], input[name="address"]');
    if (!$form.find('.customer-info').length && customerFields.length > 0) {
      customerFields.wrapAll('<div class="customer-info"></div>');
    }

    // Wrap group size field only if not already wrapped
    var groupField = $form.find('input[name="participants"]').first();
    if (groupField.length > 0 && !groupField.closest('.group-size-field').length) {
      groupField.wrap('<div class="group-size-field"></div>');
    }

    // Dynamic additional participants fields
  var $partContainer = $form.find('.customer-info .td-participants, .td-participants');
    function renderParticipants(count){
      if (!$partContainer.length) return;
      $partContainer.empty();
      if (count <= 1) { $partContainer.hide(); return; }
      for (var i=2;i<=count;i++) {
        var block = document.createElement('div');
        block.className = 'td-participant-block';
        block.innerHTML = ''
          + '<label>' + (labels.participant || 'Participant') + ' #' + i + '</label>'
          + '<input type="text" name="p_name[]" placeholder="' + (labels.name || 'Name') + '" />'
          + '<input type="email" name="p_email[]" placeholder="' + (labels.email || 'Email') + '" />'
          + '<input type="tel" name="p_phone[]" class="full-row" placeholder="' + (labels.phone || 'Phone') + '" />';
        $partContainer.append(block);
      }
      $partContainer.show();
    }
    // Initial participants render
    if (groupField.length) {
      var initial = parseInt(groupField.val() || '1', 10) || 1;
      renderParticipants(initial);
      $form.on('change input', '#td-booking-participants', function(){
        var c = parseInt(this.value || '1', 10) || 1;
        c = Math.max(1, Math.min(99, c));
        renderParticipants(c);
      });
    }

    // Wrap service selection only if not already wrapped
    var serviceField = $form.find('select[name="service_id"], input[name="service_id"]').first();
    if (serviceField.length > 0 && !serviceField.closest('.service-selection').length) {
      serviceField.wrap('<div class="service-selection"></div>');
    }

    // Add required markers to existing labels only; do NOT create new labels
    $form.find('[required]').each(function() {
      var $field = $(this);
      var id = $field.attr('id');
      if (!id) return; // skip if no id
      var $label = $form.find('label[for="' + id + '"]');
      if ($label.length && !$label.find('.required').length) {
        $label.append(' <span class="required">*</span>');
      }
    });

    // Style the terms checkbox
    var $termsCheckbox = $form.find('input[name="terms"]');
    if ($termsCheckbox.length > 0) {
      $termsCheckbox.wrap('<div class="checkbox-label"></div>');
    }
  }

  // Populate staff dropdown filtered by selected service
  function populateStaffDropdown() {
    if (!staffSelectEnabled) return;
    var $dropdown = $form.find('#td-booking-staff');
    if (!$dropdown.length) return;
    var service_id = $form.find('[name=service_id]').val();
    $dropdown.prop('disabled', true).empty().append('<option value="">' + (labels.chooseStaff || 'Choose a staff member') + '</option>');
    if (!service_id) return;
    $.get(restUrl + 'staff', { service_id: service_id })
      .done(function(items){
        if (!items || !items.length) return;
        items.forEach(function(it){
          $dropdown.append('<option value="' + it.id + '">' + it.name + '</option>');
        });
        $dropdown.prop('disabled', false);
      })
      .fail(function(){ /* ignore */ });
  }

  function showMessage(text, type) {
    $msg.removeClass('success error info').addClass(type).text(text).show();
    // Auto-hide success messages after 5 seconds
    if (type === 'success') {
      setTimeout(function() {
        $msg.fadeOut();
      }, 5000);
    }
  }

  function setLoading(isLoading) {
    if (isLoading) {
      $submitBtn.prop('disabled', true).text(labels.loading || 'Loading...');
      $form.addClass('loading');
    } else {
      $submitBtn.prop('disabled', false).text(labels.book || 'Book');
      $form.removeClass('loading');
    }
  }

  function prefers24h() {
    // auto-detect 24h by formatting a reference time and checking for AM/PM
    try {
      var ref = new Date(Date.UTC(2000,0,1,13,0,0));
      var fmt = new Intl.DateTimeFormat(fmtLocale, { hour: 'numeric' }).format(ref);
      // If it contains AM/PM letters, it's 12h; otherwise 24h
      return !(/[AP]\.?M/i).test(fmt);
    } catch(e) {
      // Fallback: infer from locale common patterns
      return /(^..|-[A-Z]{2})?-(?:[A-Z]{2})?/.test(locale) ? false : true;
    }
  }

  function formatSlotTime(utcString) {
    try {
      var date = new Date(utcString + (safeIncludes(utcString, 'Z') ? '' : 'Z'));
      // Adjust display to site timezone if configured
      if (cfg.displayTz === 'site' && typeof cfg.siteTzOffsetMin === 'number') {
        // date is in local browser tz; we want site tz: shift by (siteOffset - localOffset)
        try {
          var localOffsetMin = -date.getTimezoneOffset(); // minutes east of UTC
          var diffMin = (cfg.siteTzOffsetMin - localOffsetMin);
          date = new Date(date.getTime() + diffMin * 60000);
        } catch (e3) {}
      }
      // Determine hourCycle based on config
      var tf = (cfg.timeFormat || 'auto');
      var use24 = (tf === '24') || (tf === 'auto' && prefers24h());
      var options = {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      };
      // Prefer widely-supported hour12 flag; keep hourCycle when available as hint
      options.hour12 = !use24;
  if (typeof options.hourCycle !== 'undefined') { options.hourCycle = use24 ? 'h23' : 'h12'; }
      // Some older browsers may not support the full options bag; try/catch
      try {
  return new Intl.DateTimeFormat(fmtLocale, options).format(date);
      } catch (e2) {
        // Fallback format YYYY-MM-DD HH:mm
        var y = date.getFullYear();
        var m = ('0' + (date.getMonth()+1)).slice(-2);
        var d = ('0' + date.getDate()).slice(-2);
        var hhNum = date.getHours();
        var mm = ('0' + date.getMinutes()).slice(-2);
        var hh = ('0' + (use24 ? hhNum : ((hhNum % 12) || 12))).slice(-2);
        var suffix = '';
        if (!use24) { suffix = (hhNum < 12 ? ' AM' : ' PM'); }
        return y + '-' + m + '-' + d + ' ' + hh + ':' + mm + suffix;
      }
    } catch (e) {
      return utcString.replace('T', ' ').replace('Z', '');
    }
  }

  // Parse UTC string into a Date adjusted for display timezone
  function parseDisplayDate(utcString) {
    var date = new Date(utcString + (safeIncludes(utcString, 'Z') ? '' : 'Z'));
    if (cfg.displayTz === 'site' && typeof cfg.siteTzOffsetMin === 'number') {
      try {
        var localOffsetMin = -date.getTimezoneOffset();
        var diffMin = (cfg.siteTzOffsetMin - localOffsetMin);
        date = new Date(date.getTime() + diffMin * 60000);
      } catch (e) {}
    }
    return date;
  }

  // Format a slot range compactly. If start and end are on the same local day,
  // show the date once and a time range. Otherwise, show both dates.
  function formatSlotRange(startUtc, endUtc) {
    try {
      var start = parseDisplayDate(startUtc);
      var end = parseDisplayDate(endUtc);
      var tf = (cfg.timeFormat || 'auto');
      var use24 = (tf === '24') || (tf === 'auto' && prefers24h());
      var dateFmtOpts = { weekday: 'short', month: 'short', day: 'numeric' };
      var timeFmtOpts = { hour: '2-digit', minute: '2-digit', hour12: !use24 };
      if (typeof timeFmtOpts.hourCycle !== 'undefined') { timeFmtOpts.hourCycle = use24 ? 'h23' : 'h12'; }
      var dateFormatter = new Intl.DateTimeFormat(fmtLocale, dateFmtOpts);
      var timeFormatter = new Intl.DateTimeFormat(fmtLocale, timeFmtOpts);
      var sameDay = start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth() && start.getDate() === end.getDate();
      if (sameDay) {
        // Example: Fri, Oct 17, 09:00 – 09:30
        return dateFormatter.format(start) + ', ' + timeFormatter.format(start) + ' - ' + timeFormatter.format(end);
      }
      // Cross-day fallback: show both
      return dateFormatter.format(start) + ', ' + timeFormatter.format(start) + ' - ' + dateFormatter.format(end) + ', ' + timeFormatter.format(end);
    } catch (e) {
      // Fallback to two individual timestamps
      return formatSlotTime(startUtc) + ' - ' + formatSlotTime(endUtc);
    }
  }

  function fetchSlots() {
    $slots.empty().addClass('loading');
    $msg.hide();
    
    var service_id = $form.find('[name=service_id]').val();
    if (!service_id && !(staffAgnostic && (staffLimit || (staffSelectEnabled && parseInt(($form.find('[name=staff_id]').val()||'0'),10)>0)))) {
      $slots.removeClass('loading');
      return;
    }

    // Show loading message
  $slots.html('<div class="loading-slots"><em>' + (labels.loadingSlots || 'Loading available times...') + '</em></div>');
    
  // Calculate date range aligned with business hours: start at next BH start after lead time
  var now = new Date();
  var leadMs = (rules.leadTimeMin || 60) * 60 * 1000;
  var horizonDays = Math.max(1, rules.horizonDays || 30);
  var bh = rules.businessHours || {};
  var enforce = rules.hoursEnforcement || 'restrict';
  function pad2(n){ return String(n).padStart(2,'0'); }
  function findNextBusinessStart(base) {
    // If no business hours or enforcement off, start from base
    if (!bh || Object.keys(bh).length === 0 || enforce === 'off') {
      var f = new Date(base);
      f.setSeconds(0,0);
      return f;
    }
    // Iterate up to horizonDays+7 to find a day with hours
    for (var i=0;i<Math.min(horizonDays+7, 60);i++) {
      var d = new Date(base.getTime() + i*24*60*60*1000);
      var w = d.getDay(); // 0..6
      var ranges = bh[w] || [];
      if (!ranges.length) continue;
      // Choose first range start of that day
      var parts = (ranges[0].start || '').split(':');
      if (parts.length < 2) continue;
      var start = new Date(d.getFullYear(), d.getMonth(), d.getDate(), parseInt(parts[0],10), parseInt(parts[1],10), 0, 0);
      if (start.getTime() >= base.getTime()) {
        return start;
      }
    }
    // Fallback
    var ff = new Date(base);
    ff.setSeconds(0,0);
    return ff;
  }
  var base = new Date(now.getTime() + leadMs);
  var from = findNextBusinessStart(base);
  var to = new Date(now.getTime() + (horizonDays * 24 * 60 * 60 * 1000));
  to.setHours(23,59,59,999);

    var availParams = {
      service_id: service_id || 0,
      from: from.toISOString().slice(0,19).replace('T',' '),
      to: to.toISOString().slice(0,19).replace('T',' ')
    };
    if (!service_id && staffAgnostic) {
      availParams.duration = defaultDurationMin;
      availParams.agnostic = 1;
    }
    var selectedStaff = 0;
    // Prefer explicit dropdown selection when enabled; else use staffLimit
    if (staffSelectEnabled) {
      selectedStaff = parseInt(($form.find('[name=staff_id]').val() || '0'), 10) || 0;
    } else if (staffLimit) {
      selectedStaff = staffLimit;
    }
    if (selectedStaff) {
      availParams.with_staff = 1;
      availParams.staff_id = selectedStaff;
      if (staffLimit && staffAgnostic) { availParams.agnostic = 1; }
    }
    $.get(restUrl + 'availability', availParams)
    .done(function(resp){
      $slots.removeClass('loading');
      slots = resp || [];
      // If staffLimit is set and API returned staff_id (when engine is configured to), filter client-side
      if (selectedStaff && slots.length && typeof slots[0].staff_id !== 'undefined') {
        slots = slots.filter(function(s){ return parseInt(s.staff_id,10) === selectedStaff; });
      }
      
      if (!slots.length) {
        $slots.html('<em>' + (labels.noSlots || 'No available time slots found. Please try different dates or contact us directly.') + '</em>');
        return;
      }

  // Group slots by LOCAL date
      var slotsByDate = {};
      function localKeyFromUtc(utcStr) {
        try {
          var d = new Date(utcStr + (safeIncludes(utcStr, 'Z') ? '' : 'Z'));
          var y = d.getFullYear();
          var m = String(d.getMonth() + 1).padStart(2, '0');
          var day = String(d.getDate()).padStart(2, '0');
          return y + '-' + m + '-' + day;
        } catch (e) {
          return utcStr.split('T')[0];
        }
      }
      slots.forEach(function(slot) {
        var date = localKeyFromUtc(slot.start_utc);
        if (!slotsByDate[date]) {
          slotsByDate[date] = [];
        }
        slotsByDate[date].push(slot);
      });

  // Update calendar day indicators for days with slots
  updateCalendarIndicators(slotsByDate);
  // Do NOT auto-select any date; wait for user interaction
  $calendar.removeData('selectedDate');
  $calendar.find('.td-cal-cell.day').removeClass('selected').removeAttr('aria-selected');
  $slots.html('<em>' + (labels.selectDatePrompt || 'Select a date to see available times') + '</em>');
      // If the user already clicked a date while we were loading, render that day now
      if (pendingRenderDate) {
        var iso = pendingRenderDate;
        pendingRenderDate = null;
        renderSlotsForDate(iso, slots);
      }
    })
    .fail(function(xhr) {
      $slots.removeClass('loading');
  var errorMsg = labels.errorLoadingSlots || 'Error loading available times. Please try again.';
      if (xhr.responseJSON && xhr.responseJSON.message) {
        errorMsg = xhr.responseJSON.message;
      }
      $slots.html('<em style="color: #dc2626;">' + errorMsg + '</em>');
    });
  }

  // Inline Calendar: minimal month view that highlights days with business hours and within horizon
  function buildCalendar(current) {
    if (!$calendar.length) return;
    $calendar.empty();

  var leadMs = (rules.leadTimeMin || 60) * 60 * 1000;
  var bh = rules.businessHours || {};
  var enforce = rules.hoursEnforcement || 'restrict';
  var horizonDays = Math.max(1, rules.horizonDays || 30);
    var today = new Date();
    var minDate = new Date(today.getTime() + leadMs);
    var maxDate = new Date(today.getTime() + horizonDays * 24 * 60 * 60 * 1000);
    maxDate.setHours(23,59,59,999);

  var year = current.getFullYear();
  var month = current.getMonth();
  var firstDay = new Date(year, month, 1);
  var startWeekday = firstDay.getDay(); // 0..6 (Sun=0)
    var daysInMonth = new Date(year, month + 1, 0).getDate();

  var header = $('<div class="td-cal-header"></div>');
  var prevBtn = $('<button type="button" class="td-cal-nav prev" aria-label="' + (labels.prevMonth || 'Previous month') + '">\u2039</button>');
  var nextBtn = $('<button type="button" class="td-cal-nav next" aria-label="' + (labels.nextMonth || 'Next month') + '">\u203A</button>');
  var title = $('<div class="td-cal-title"></div>').text(new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(current));
    header.append(prevBtn, title, nextBtn);
    $calendar.append(header);

  var grid = $('<div class="td-cal-grid" role="grid" aria-label="' + (labels.calendar || 'Calendar') + '"></div>');
  var weekdayFormatter;
  try { weekdayFormatter = new Intl.DateTimeFormat(locale, { weekday: 'short' }); } catch (e) {
    weekdayFormatter = { format: function(d){ return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()]; } };
  }
  var weekdays = [];
  for (var i=0;i<7;i++) {
    var base = new Date(2020, 0, 5 + i); // 2020-01-05 is a Sunday
    weekdays.push(weekdayFormatter.format(base));
  }
  if (startOfWeek > 0) {
    weekdays = weekdays.slice(startOfWeek).concat(weekdays.slice(0, startOfWeek));
  }
  weekdays.forEach(function(d){ grid.append('<div class="td-cal-dow" role="columnheader">' + d + '</div>'); });
    // Adjust leading empty cells according to startOfWeek
    var leading = (startWeekday - startOfWeek + 7) % 7;
    for (var i=0;i<leading;i++) grid.append('<div class="td-cal-cell empty"></div>');
    for (var day=1;day<=daysInMonth;day++) {
      var d = new Date(year, month, day);
      var iso = [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');
      var cell = $('<button type="button" class="td-cal-cell day" tabindex="0" role="gridcell"></button>').text(day).attr('data-date', iso);
      // Accessible label for screen readers
      try {
        var monthName = new Intl.DateTimeFormat(locale, { month: 'long' }).format(d);
        var weekdayName = new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(d);
        var aria = weekdayName + ', ' + monthName + ' ' + day + ', ' + d.getFullYear();
        cell.attr('aria-label', aria).attr('data-aria-label', aria);
      } catch(e) {}
      // Disable outside min/max
      if (d < new Date(minDate.getFullYear(), minDate.getMonth(), minDate.getDate()) || d > maxDate) {
        cell.prop('disabled', true).addClass('disabled').attr('aria-disabled', 'true').attr('tabindex', '-1');
      }
      // If enforcing business hours, disable days without BH
      if (enforce !== 'off') {
        var w = d.getDay();
        var ranges = bh[w] || [];
        if (!ranges.length) {
          cell.prop('disabled', true).addClass('no-hours').attr('aria-disabled', 'true').attr('tabindex', '-1');
        }
      }
      // Highlight today
      var isToday = (d.toDateString() === (new Date()).toDateString());
      if (isToday) cell.addClass('today');
      // Selected
  if ($calendar.data('selectedDate') === iso) cell.addClass('selected').attr('aria-selected', 'true');
      grid.append(cell);
    }
    $calendar.append(grid);

    // If we already have slots loaded, mark days that have slots
    try {
      var map = {};
      (slots || []).forEach(function(s){
        var su = (s.start_utc || '');
        var d = new Date(su.replace(' ', 'T') + (su && safeIncludes(su, 'Z') ? '' : 'Z'));
        var key = [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');
        map[key] = true;
      });
      updateCalendarIndicators(map);
    } catch(e) {}

    // Navigation
    prevBtn.on('click', function(){
      var prev = new Date(year, month - 1, 1);
      // Prevent navigating before minDate month if entire month is < minDate
      var minMonthStart = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
      if (prev < minMonthStart) return;
      buildCalendar(prev);
    });
    nextBtn.on('click', function(){
      var next = new Date(year, month + 1, 1);
      var maxMonthStart = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
      if (next > maxMonthStart) return;
      buildCalendar(next);
    });

    // Day click -> fetch slots for that date range and render only those
    grid.on('click', '.td-cal-cell.day:not(.disabled):not(.no-hours)', function(){
      var iso = $(this).attr('data-date');
      $calendar.data('selectedDate', iso);
      grid.find('.td-cal-cell.day').removeClass('selected').removeAttr('aria-selected');
      $(this).addClass('selected').attr('aria-selected','true');
      fetchSlotsForDate(iso);
    });

    // Keyboard navigation within calendar grid
    function moveFocus(fromIndex, delta) {
      var $cells = grid.find('.td-cal-cell.day');
      var idx = fromIndex + delta;
      var safety = 0;
      while (idx >= 0 && idx < $cells.length && safety < 100) {
        var $cand = $cells.eq(idx);
        if (!$cand.is('.disabled, .no-hours')) { $cand.focus(); return; }
        idx += (delta > 0 ? 1 : -1);
        safety++;
      }
    }
    grid.on('keydown', '.td-cal-cell.day', function(e){
      var key = e.key;
      var $btns = grid.find('.td-cal-cell.day');
      var currentIndex = $btns.index(this);
      if (key === 'ArrowLeft') { e.preventDefault(); moveFocus(currentIndex, -1); }
      else if (key === 'ArrowRight') { e.preventDefault(); moveFocus(currentIndex, 1); }
      else if (key === 'ArrowUp') { e.preventDefault(); moveFocus(currentIndex, -7); }
      else if (key === 'ArrowDown') { e.preventDefault(); moveFocus(currentIndex, 7); }
      else if (key === 'Enter' || key === ' ') {
        e.preventDefault();
        if (!$(this).is('.disabled, .no-hours')) { $(this).trigger('click'); }
      }
    });
  }

  function updateCalendarIndicators(slotsByDate) {
    if (!$calendar.length || !slotsByDate) return;
    $calendar.find('.td-cal-cell.day').each(function(){
      var $cell = $(this);
      var iso = $cell.attr('data-date');
      if (!iso) return;
      var val = slotsByDate[iso];
      var count = 0;
      if (typeof val === 'number') count = val;
      else if ((Array.isArray ? Array.isArray(val) : Object.prototype.toString.call(val) === '[object Array]')) count = val.length;
      else if (!!val) count = 1;
      if (count > 0) {
        $cell.addClass('has-slots');
        var $b = $cell.find('.slot-badge');
        if (!$b.length) { $b = $('<span class="slot-badge" aria-hidden="true"></span>'); $cell.append($b); }
        var txt = String(count > 99 ? '99+' : count);
        $b.text(txt);
        // Update aria-label with count
        var base = $cell.attr('data-aria-label') || $cell.attr('aria-label') || '';
        if (base) {
          var idx = base.indexOf('(');
          if (idx !== -1) base = base.substring(0, idx).trim();
          $cell.attr('aria-label', base + ' (' + txt + ' ' + ((labels && labels.slots) || 'slots') + ')');
        }
      } else {
        $cell.removeClass('has-slots');
        $cell.find('.slot-badge').remove();
      }
    });
  }

  function fetchSlotsForDate(isoDate) {
    var service_id = $form.find('[name=service_id]').val();
    var hasStaffSelected = !!staffLimit || (staffSelectEnabled && parseInt(($form.find('[name=staff_id]').val()||'0'),10) > 0);
    if (!service_id && !(staffAgnostic && hasStaffSelected)) return;
    // If slots are not yet loaded, first load them, then filter
    if (!slots || slots.length === 0) {
      pendingRenderDate = isoDate;
      fetchSlots();
      // render will be called once loaded via pendingRenderDate
      return;
    }
    $slots.addClass('loading').html('<div class="loading-slots"><em>' + (labels.loadingSlots || 'Loading available times...') + '</em></div>').show();
    renderSlotsForDate(isoDate, slots);
  }

  function renderSlotsForDate(isoDate, allSlots) {
  var daySlots = [];
    function localKeyFromUtc(utcStr) {
      try {
        var d = new Date(utcStr + (safeIncludes(utcStr, 'Z') ? '' : 'Z'));
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
      } catch (e) {
        return utcStr.split('T')[0];
      }
    }
    (allSlots || slots || []).forEach(function(slot){
      if (localKeyFromUtc(slot.start_utc) === isoDate) daySlots.push(slot);
    });
    $slots.removeClass('loading');
    if (!daySlots.length) {
      $slots.html('<em>' + (labels.noSlots || 'No available time slots found. Please try different dates or contact us directly.') + '</em>');
      return;
    }
    // Inline slot buttons
    var html = '';
    html += '<div class="td-slot-header">' + (labels.selectTime || 'Select appointment time') + ' <span class="required">*</span></div>';
    html += '<input type="hidden" name="slot" required />';
    html += '<div class="td-slot-grid" role="list">';
    daySlots.forEach(function(slot, idx){
      var timeLabel = formatSlotRange(slot.start_utc, slot.end_utc);
      var id = 'td-slot-' + idx;
      html += '<button type="button" class="td-slot-btn" role="listitem" data-value="' + slot.start_utc + '" aria-pressed="false" aria-label="' + timeLabel.replace(/\"/g,'&quot;') + '">' + timeLabel + '</button>';
    });
    html += '</div>';
  $slots.html(html).show();
    // Wire selection
    var $hidden = $slots.find('input[name="slot"]');
    $slots.find('.td-slot-btn').on('click keydown', function(e){
      if (e.type === 'keydown' && !(e.key === 'Enter' || e.key === ' ')) return;
      e.preventDefault();
      var $btn = $(this);
      $slots.find('.td-slot-btn').removeClass('selected').attr('aria-pressed','false');
      $btn.addClass('selected').attr('aria-pressed','true');
      $hidden.val($btn.data('value')).trigger('change');
    });
  }

  // Event handlers
  $form.on('change', '[name=service_id]', function(){
    // Reset selected date and rebuild calendar at next business start month
    $calendar.removeData('selectedDate');
    var base = new Date(new Date().getTime() + (rules.leadTimeMin || 60) * 60 * 1000);
    buildCalendar(new Date(base.getFullYear(), base.getMonth(), 1));
    fetchSlots();
  });

  // If staff selection dropdown is enabled, refetch availability on change
  if (staffSelectEnabled) {
    $form.on('change', '[name=staff_id]', function(){
      pendingRenderDate = null;
      $calendar.removeData('selectedDate');
      var base = new Date(new Date().getTime() + (rules.leadTimeMin || 60) * 60 * 1000);
      buildCalendar(new Date(base.getFullYear(), base.getMonth(), 1));
      fetchSlots();
    });
  }
  
  // Load slots if service is pre-selected
  if ($form.find('[name=service_id]').val()) {
    var base = new Date(new Date().getTime() + (rules.leadTimeMin || 60) * 60 * 1000);
    buildCalendar(new Date(base.getFullYear(), base.getMonth(), 1));
    if (staffSelectEnabled) { populateStaffDropdown(); }
    fetchSlots();
  } else {
    // If staff-only mode with known staff, go ahead and load availability without service
    var base0 = new Date(new Date().getTime() + (rules.leadTimeMin || 60) * 60 * 1000);
    buildCalendar(new Date(base0.getFullYear(), base0.getMonth(), 1));
    var hasStaffFixed2 = !!staffLimit;
    var hasStaffSelected2 = hasStaffFixed2 || (staffSelectEnabled && parseInt(($form.find('[name=staff_id]').val()||'0'),10)>0);
    if (staffAgnostic && hasStaffSelected2) {
      fetchSlots();
    }
  }

  // Form validation
  $form.on('input change', 'input, select', function() {
    var $field = $(this);
    $field.removeClass('error');
    
    // Basic email validation
    if ($field.attr('type') === 'email' && $field.val()) {
      var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test($field.val())) {
        $field.addClass('error');
      }
    }
  });

  // Form submission
  $form.on('submit', function(e){
    e.preventDefault();
    $msg.hide();
    
    // Collect form data
    var data = {
      service_id: $form.find('[name=service_id]').val() || (staffAgnostic ? 0 : ''),
      start_utc: $form.find('[name=slot]').val(),
      customer: {
        name: $form.find('[name=name]').val(),
        email: $form.find('[name=email]').val(),
        phone: $form.find('[name=phone]').val(),
        address: $form.find('[name=address]').val()
      },
      terms_accepted: $form.find('[name=terms]').is(':checked')
    };

    // Add group size if enabled (map participants -> group_size for backend)
    var participants = $form.find('[name=participants]').val();
    if (participants) {
      data.group_size = parseInt(participants);
    }

    // Include additional participants info if present
    var extra = [];
    // Include optional notes
    var notesVal = $form.find('[name=notes]').val();
    if (typeof notesVal === 'string' && notesVal.trim().length) {
      data.notes = notesVal.trim();
    }
    $form.find('.td-participant-block').each(function(){
      var $b = $(this);
      var p = {
        name: $b.find('input[name="p_name[]"]').val(),
        email: $b.find('input[name="p_email[]"]').val(),
        phone: $b.find('input[name="p_phone[]"]').val()
      };
      if (p.name || p.email || p.phone) extra.push(p);
    });
    if (extra.length) {
      data.participants = extra;
    }

    // Basic validation
    var missingFields = [];
  if (!data.service_id && !staffAgnostic) missingFields.push(labels.service || 'Service');
  if (!data.start_utc) missingFields.push(labels.timeSlot || 'Time slot');
  if (!data.customer.name) missingFields.push(labels.name || 'Name');
  if (!data.customer.email) missingFields.push(labels.email || 'Email');
  if (!data.terms_accepted) missingFields.push(labels.terms || 'Terms acceptance');

    if (missingFields.length > 0) {
  showMessage((labels.missingFields || 'Please fill in the following required fields: ') + missingFields.join(', '), 'error');
      // Highlight missing fields
      $form.find('[required]').each(function() {
        var $field = $(this);
        var isEmpty = !$field.val() || ($field.attr('type') === 'checkbox' && !$field.is(':checked'));
        $field.toggleClass('error', isEmpty);
      });
      return;
    }

    // Email validation
    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(data.customer.email)) {
      showMessage(labels.invalidEmail || 'Please enter a valid email address.', 'error');
      $form.find('[name=email]').addClass('error').focus();
      return;
    }

    setLoading(true);

    var selectedStaffId = 0;
    if (staffSelectEnabled) {
      selectedStaffId = parseInt(($form.find('[name=staff_id]').val() || '0'), 10) || 0;
    } else if (staffLimit) { selectedStaffId = staffLimit; }
    if (selectedStaffId) { data.staff_id = selectedStaffId; }
    if (staffAgnostic && (staffLimit || selectedStaffId)) { data.agnostic = 1; }
    var submitData = wcEnabled ? $.extend({}, data, {wc: 1}) : data;

    $.ajax({
  url: restUrl + 'book',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(submitData),
      beforeSend: function(xhr){ 
        xhr.setRequestHeader('X-WP-Nonce', wpNonce); 
      },
      success: function(resp){
        setLoading(false);
        
        if (wcEnabled && resp && resp.checkout_url) {
          showMessage(labels.redirectingCheckout || 'Redirecting to checkout...', 'info');
          setTimeout(function() {
            window.location.href = resp.checkout_url;
          }, 1000);
        } else {
          showMessage(labels.bookingSuccess || 'Booking successful! You will receive a confirmation email shortly.', 'success');
          $form[0].reset();
          $slots.empty();
          
          // Scroll to success message
          $msg[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      },
      error: function(xhr){
        setLoading(false);
        var errorMsg = labels.bookingError || 'Error creating booking. Please try again.';
        
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMsg = xhr.responseJSON.message;
        } else if (xhr.status === 0) {
          errorMsg = labels.networkError || 'Network error. Please check your connection and try again.';
        }
        
        showMessage(errorMsg, 'error');
      }
    });
  });

  // Add keyboard navigation support
  $form.on('keydown', function(e) {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit') {
      e.preventDefault();
      // Move to next field
      var formElements = $form.find('input, select, textarea, button').filter(':visible');
      var currentIndex = formElements.index(e.target);
      if (currentIndex > -1 && currentIndex < formElements.length - 1) {
        formElements.eq(currentIndex + 1).focus();
      }
    }
  });
});
