function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, e) { if ("object" != _typeof(t) || !t) return t; var r; if ("undefined" != typeof Symbol && void 0 !== (r = t[Symbol.toPrimitive])) { var i = r.call(t, e || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === e ? String : Number)(t); }
(function () {
  'use strict';

  function translation(key, fallback) {
    if (typeof globalTranslate !== 'undefined' && globalTranslate[key]) {
      return globalTranslate[key];
    }
    return fallback;
  }
  function requestedTab() {
    var params = new URLSearchParams(window.location.search);
    var tabName = params.get('tab') || window.location.hash.replace(/^#/, '');
    return /^[a-z0-9_-]+$/.test(tabName) ? tabName : '';
  }
  function calendarLocale() {
    var days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    if (typeof SemanticLocalization !== 'undefined' && SemanticLocalization.calendarText) {
      days = SemanticLocalization.calendarText.days || days;
      months = SemanticLocalization.calendarText.months || months;
    }
    return {
      format: 'DD/MM/YYYY',
      separator: ' - ',
      applyLabel: translation('cal_ApplyBtn', 'Apply'),
      cancelLabel: translation('cal_CancelBtn', 'Cancel'),
      fromLabel: translation('cal_from', 'From'),
      toLabel: translation('cal_to', 'To'),
      customRangeLabel: translation('cal_CustomPeriod', 'Custom range'),
      daysOfWeek: days,
      monthNames: months,
      firstDay: 1
    };
  }
  function momentDate(value, fallback) {
    var date = moment(value, 'YYYY-MM-DD', true);
    return date.isValid() ? date : fallback.clone();
  }
  function initializeTranscriptDateFilter() {
    var $form = $('#stt-transcripts-filter-form');
    var $selector = $('#stt-transcripts-date-range-selector');
    if ($form.length === 0 || $selector.length === 0) {
      return;
    }
    if (typeof moment === 'undefined' || typeof $.fn.daterangepicker === 'undefined') {
      return;
    }
    var $dateFrom = $form.find('input[name="date_from"]');
    var $dateTo = $form.find('input[name="date_to"]');
    var $search = $form.find('input[name="search"]');
    var searchDebounceTimer = null;
    var today = moment();
    var startDate = momentDate($dateFrom.val(), today);
    var endDate = momentDate($dateTo.val(), startDate);
    if (startDate.isAfter(endDate)) {
      var swapDate = startDate;
      startDate = endDate;
      endDate = swapDate;
    }
    function updateHiddenInputs(start, end) {
      $dateFrom.val(start.format('YYYY-MM-DD'));
      $dateTo.val(end.format('YYYY-MM-DD'));
    }
    function cancelSearchDebounce() {
      if (searchDebounceTimer !== null) {
        window.clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
      }
    }
    function submitTranscriptSearch() {
      cancelSearchDebounce();
      $form.trigger('submit');
    }
    updateHiddenInputs(startDate, endDate);
    $selector.daterangepicker({
      ranges: _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty({}, translation('cal_Today', 'Today'), [moment(), moment()]), translation('cal_Yesterday', 'Yesterday'), [moment().subtract(1, 'days'), moment().subtract(1, 'days')]), translation('cal_LastWeek', 'Last 7 days'), [moment().subtract(6, 'days'), moment()]), translation('cal_Last30Days', 'Last 30 days'), [moment().subtract(29, 'days'), moment()]), translation('cal_ThisMonth', 'This month'), [moment().startOf('month'), moment().endOf('month')]), translation('cal_LastMonth', 'Last month'), [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]),
      alwaysShowCalendars: true,
      autoUpdateInput: true,
      linkedCalendars: true,
      maxDate: moment(),
      startDate: startDate.startOf('day'),
      endDate: endDate.endOf('day'),
      locale: calendarLocale()
    }, function (start, end) {
      updateHiddenInputs(start, end);
      $form.trigger('submit');
    });
    $form.on('submit', function () {
      cancelSearchDebounce();
      var picker = $selector.data('daterangepicker');
      if (picker && picker.startDate && picker.endDate) {
        updateHiddenInputs(picker.startDate, picker.endDate);
      }
    });
    $search.on('input', function () {
      cancelSearchDebounce();
      searchDebounceTimer = window.setTimeout(function () {
        searchDebounceTimer = null;
        $form.trigger('submit');
      }, 400);
    });
    $search.on('keydown', function (event) {
      if (event.which !== 13) {
        return;
      }
      event.preventDefault();
      submitTranscriptSearch();
    });
    $search.on('search', function () {
      if (String($search.val() || '').trim() === '') {
        submitTranscriptSearch();
      }
    });
  }
  function formatPackageSize(bytes) {
    return (bytes / 1048576).toFixed(2) + ' MB';
  }

  // The Windows download is one button with an MSI/ZIP menu. File names and sizes come
  // from the release server through the PBX, once, when the Windows panel is first shown.
  function initializeWindowsWorkerDownload() {
    var $dropdown = $('.module-local-speech-to-text .stt-worker-windows-download');
    var requested = false;
    if ($dropdown.length === 0) {
      return function () {};
    }
    $dropdown.dropdown({
      action: function (text, value) {
        var $form = $dropdown.closest('form');
        $dropdown.dropdown('hide');
        if (value === 'msi' || value === 'zip') {
          $form.find('input[name="format"]').val(value);
          $form.trigger('submit');
        }
      }
    });
    return function loadPackages() {
      if (requested || $dropdown.closest('.stt-worker-platform-panel').prop('hidden')) {
        return;
      }
      requested = true;
      $.ajax({
        url: $dropdown.attr('data-packages-url'),
        method: 'GET',
        dataType: 'json'
      }).done(function (response) {
        var packages = response && Array.isArray(response.packages) ? response.packages : [];
        packages.forEach(function (item) {
          if (!item || typeof item.filename !== 'string' || item.filename === '') {
            return;
          }
          var label = item.filename + (typeof item.size === 'number' ? ' (' + formatPackageSize(item.size) + ')' : '');
          $dropdown.find('.item[data-value="' + String(item.format) + '"] .stt-worker-package-name').text(label);
        });
      });
    };
  }
  function initializeModuleLogs() {
    var $form = $('#stt-module-logs-form');
    var $segment = $('#system-logs-segment');
    var $results = $('#stt-log-results');
    var $state = $('#stt-log-state');
    var $resultCount = $('#stt-log-result-count');
    var $updatedAt = $('#stt-log-updated-at');
    var $levelFilter = $('#stt-log-level-filter');
    var $componentFilter = $('#stt-log-component-filter');
    var $search = $('#stt-log-search');
    if ($form.length === 0 || $results.length === 0 || $state.length === 0) {
      return;
    }
    var dataUrl = $form.data('url');
    var $periodButtons = $form.find('.period-btn');
    var $refreshButton = $form.find('.stt-log-refresh');
    var currentPeriod = parseInt($periodButtons.filter('.active').data('period'), 10);
    var loadedOnce = false;
    var request = null;
    var requestGeneration = 0;
    var currentEntries = [];
    var currentResponse = null;
    if (!Number.isFinite(currentPeriod)) {
      currentPeriod = 3600;
    }
    function levelName(level) {
      var levels = {
        debug: ['module_local_speech_to_text_LogLevelDebug', 'Debug'],
        info: ['module_local_speech_to_text_LogLevelInfo', 'Info'],
        notice: ['module_local_speech_to_text_LogLevelNotice', 'Notice'],
        warning: ['module_local_speech_to_text_LogLevelWarning', 'Warning'],
        error: ['module_local_speech_to_text_LogLevelError', 'Error']
      };
      var item = levels[level] || levels.info;
      return translation(item[0], item[1]);
    }
    function componentName(component) {
      var aliases = {
        api: 'api',
        queue: 'queue',
        scanner: 'scanner',
        worker: 'worker',
        workerlocalspeechtotextjobs: 'worker',
        settings: 'settings',
        web_ui: 'settings',
        setup: 'setup',
        storage: 'storage',
        transcript: 'transcript',
        system: 'system',
        localspeechtotextmain: 'system',
        logger: 'system'
      };
      var labels = {
        api: ['module_local_speech_to_text_LogComponentApi', 'API'],
        queue: ['module_local_speech_to_text_LogComponentQueue', 'Queue'],
        scanner: ['module_local_speech_to_text_LogComponentScanner', 'Recording scanner'],
        worker: ['module_local_speech_to_text_LogComponentWorker', 'Worker'],
        settings: ['module_local_speech_to_text_LogComponentSettings', 'Settings'],
        setup: ['module_local_speech_to_text_LogComponentSetup', 'Setup'],
        storage: ['module_local_speech_to_text_LogComponentStorage', 'Storage'],
        transcript: ['module_local_speech_to_text_LogComponentTranscript', 'Transcripts'],
        system: ['module_local_speech_to_text_LogComponentSystem', 'System'],
        other: ['module_local_speech_to_text_LogComponentOther', 'Other']
      };
      var label = labels[aliases[component] || 'other'];
      return translation(label[0], label[1]);
    }
    function formatTimestamp(timestamp) {
      var date = new Date(timestamp);
      if (Number.isNaN(date.getTime())) {
        return timestamp || '—';
      }
      return date.toLocaleString();
    }
    function renderState(kind, message) {
      if (!message) {
        $state.prop('hidden', true).removeClass('info warning error').text('');
        return;
      }
      $state.removeClass('info warning error').addClass(kind || 'info').text(message).prop('hidden', false);
    }
    function filterEntries(entries) {
      var level = String($levelFilter.val() || '');
      var component = String($componentFilter.val() || '');
      var query = String($search.val() || '').trim().toLowerCase();
      return entries.filter(function (entry) {
        if (level && entry.level !== level) {
          return false;
        }
        if (component && entry.component !== component) {
          return false;
        }
        if (!query) {
          return true;
        }
        var haystack = [entry.message, entry.component, entry.event, entry.severity, entry.pid, JSON.stringify(entry.context || {})].join(' ').toLowerCase();
        return haystack.indexOf(query) !== -1;
      });
    }
    function renderEntries(entries) {
      $results.empty();
      entries.forEach(function (entry) {
        var $card = $('<article>').addClass('stt-log-entry stt-log-level-' + entry.level);
        var $header = $('<div>').addClass('stt-log-entry-header');
        var $identity = $('<div>').addClass('stt-log-entry-identity');
        var $level = $('<span>').addClass('ui tiny label stt-log-level-label').text(levelName(entry.level));
        var $component = $('<span>').addClass('stt-log-component').text(componentName(entry.component));
        var $time = $('<time>').addClass('stt-log-time').text(formatTimestamp(entry.ts));
        var $message = $('<div>').addClass('stt-log-message').text(entry.message || '—');
        var $details = $('<details>').addClass('stt-log-details');
        var $summary = $('<summary>').text(translation('module_local_speech_to_text_LogsTechnicalDetails', 'Technical details'));
        var detailText = translation('module_local_speech_to_text_LogsEvent', 'Event') + ': ' + (entry.event || '—');
        detailText += '\nseverity: ' + (entry.severity || '—');
        detailText += '\npid: ' + (entry.pid === undefined ? '—' : entry.pid);
        if (entry.context && Object.keys(entry.context).length > 0) {
          detailText += '\n' + JSON.stringify(entry.context, null, 2);
        }
        $identity.append($level, $component);
        $header.append($identity, $time);
        $details.append($summary, $('<pre>').text(detailText));
        $card.append($header, $message, $details);
        $results.append($card);
      });
    }
    function updateComponentOptions(entries) {
      var selected = String($componentFilter.val() || '');
      var components = [];
      entries.forEach(function (entry) {
        if (entry.component && components.indexOf(entry.component) === -1) {
          components.push(entry.component);
        }
      });
      components.sort(function (left, right) {
        return componentName(left).localeCompare(componentName(right));
      });
      $componentFilter.empty().append($('<option>').val('').text(translation('module_local_speech_to_text_LogsComponentAll', 'All components')));
      components.forEach(function (component) {
        $componentFilter.append($('<option>').val(component).text(componentName(component)));
      });
      if (selected && components.indexOf(selected) !== -1) {
        $componentFilter.val(selected);
      }
    }
    function updateMeta(count, response) {
      var found = translation('module_local_speech_to_text_LogsFound', '%count% events').replace('%count%', count);
      var updated = translation('module_local_speech_to_text_LogsUpdated', 'Updated: %time%').replace('%time%', response && response.generatedAt ? response.generatedAt : translation('module_local_speech_to_text_LogsNever', 'never'));
      $resultCount.text(found);
      $updatedAt.text(updated);
    }
    function renderCurrent() {
      if (!currentResponse) {
        return;
      }
      var filtered = filterEntries(currentEntries);
      renderEntries(filtered);
      updateMeta(filtered.length, currentResponse);
      if (currentResponse.sourceStatus === 'missing') {
        renderState('info', translation('module_local_speech_to_text_LogsMissing', 'The module journal has not been created yet.'));
        return;
      }
      if (currentResponse.sourceStatus === 'unavailable') {
        renderState('error', translation('module_local_speech_to_text_LogsUnavailable', 'The module journal is unavailable.'));
        return;
      }
      if (currentResponse.sourceStatus === 'corrupt') {
        renderState('error', translation('module_local_speech_to_text_LogsCorrupt', 'The journal contains no readable events.'));
        return;
      }
      if (currentEntries.length === 0) {
        renderState('info', translation('module_local_speech_to_text_LogsEmpty', 'No module events were found for this period.'));
        return;
      }
      if (filtered.length === 0) {
        renderState('info', translation('module_local_speech_to_text_LogsFilteredEmpty', 'No events match the selected filters.'));
        return;
      }
      var notices = [];
      if (currentResponse.sourceStatus === 'partial') {
        notices.push(translation('module_local_speech_to_text_LogsPartial', '%count% damaged journal lines were skipped.').replace('%count%', currentResponse.malformedCount || 0));
      }
      if (currentResponse.truncated) {
        notices.push(translation('module_local_speech_to_text_LogsTruncated', 'Only the newest 1000 events are shown.'));
      }
      renderState(notices.length > 0 ? 'warning' : '', notices.join(' '));
    }
    function setLoading() {
      $segment.addClass('stt-logs-loading');
      $results.empty();
      updateMeta(0, null);
      renderState('info', translation('module_local_speech_to_text_LogsLoading', 'Loading module events...'));
    }
    function loadLogs(force) {
      if (!force && loadedOnce) {
        return;
      }
      if (!dataUrl) {
        return;
      }
      requestGeneration += 1;
      var requestId = requestGeneration;
      if (request !== null) {
        request.abort();
      }
      setLoading();
      $refreshButton.addClass('loading disabled');
      var currentRequest = $.ajax({
        url: dataUrl,
        method: 'GET',
        dataType: 'json',
        data: {
          period: currentPeriod
        }
      }).done(function (response) {
        if (requestId !== requestGeneration) {
          return;
        }
        currentResponse = response || {};
        currentEntries = Array.isArray(currentResponse.entries) ? currentResponse.entries : [];
        updateComponentOptions(currentEntries);
        renderCurrent();
        loadedOnce = true;
      }).fail(function (xhr, status) {
        if (requestId !== requestGeneration || status === 'abort') {
          return;
        }
        currentResponse = null;
        currentEntries = [];
        renderState('error', translation('module_local_speech_to_text_LogsError', 'Unable to load module events.'));
      }).always(function () {
        if (requestId !== requestGeneration) {
          return;
        }
        if (request === currentRequest) {
          request = null;
        }
        $segment.removeClass('stt-logs-loading');
        $refreshButton.removeClass('loading disabled');
      });
      request = currentRequest;
    }
    $periodButtons.on('click', function () {
      var $button = $(this);
      currentPeriod = parseInt($button.data('period'), 10);
      if (!Number.isFinite(currentPeriod)) {
        currentPeriod = 3600;
      }
      $periodButtons.removeClass('active');
      $button.addClass('active');
      loadedOnce = false;
      loadLogs(true);
    });
    $refreshButton.on('click', function () {
      loadLogs(true);
    });
    $levelFilter.add($componentFilter).on('change', renderCurrent);
    $search.on('input', renderCurrent);
    $('#module-local-speech-to-text-menu .item[data-tab="logs"]').on('click', function () {
      window.setTimeout(function () {
        loadLogs(false);
      }, 0);
    });
    updateMeta(0, null);
    if (requestedTab() === 'logs' || $form.closest('.tab.segment').hasClass('active')) {
      loadLogs(false);
    }
  }
  function initializeCdrHistorySlider($settingsForm) {
    var $slider = $('#stt-cdr-history-days-slider');
    var $input = $settingsForm.find('input[name="cdr_history_days"]');
    if ($slider.length === 0 || $input.length === 0 || typeof $slider.slider !== 'function') {
      return;
    }
    var values = ['1', '7', '30', '90', '180', '365', '0'];
    var labels = {
      0: translation('module_local_speech_to_text_CdrHistory1Day', '1 day'),
      1: translation('module_local_speech_to_text_CdrHistory7Days', '7 days'),
      2: translation('module_local_speech_to_text_CdrHistory30Days', '30 days'),
      3: translation('module_local_speech_to_text_CdrHistory90Days', '90 days'),
      4: translation('module_local_speech_to_text_CdrHistory180Days', '6 months'),
      5: translation('module_local_speech_to_text_CdrHistory365Days', '1 year'),
      6: translation('module_local_speech_to_text_CdrHistoryAll', 'All')
    };
    var currentValue = String($input.val() || '30');
    var initialIndex = values.indexOf(currentValue);
    var initialized = false;
    if (initialIndex < 0) {
      initialIndex = values.indexOf('30');
      $input.val('30');
    }
    $slider.slider({
      min: 0,
      max: values.length - 1,
      step: 1,
      smooth: true,
      autoAdjustLabels: false,
      interpretLabel: function (value) {
        return labels[value] || '';
      },
      onChange: function (value) {
        var selected = values[value] || '30';
        $input.val(selected);
        if (initialized) {
          $input.trigger('change');
          updateRetentionConflict($settingsForm);
          if (typeof Form !== 'undefined' && typeof Form.checkValues === 'function') {
            Form.checkValues();
          }
        }
      }
    });
    $slider.slider('set value', initialIndex, false);
    initialized = true;
  }
  function initializeTranscriptRetentionSlider($settingsForm) {
    var $slider = $('#stt-transcript-retention-days-slider');
    var $input = $settingsForm.find('input[name="transcript_retention_days"]');
    if ($slider.length === 0 || $input.length === 0 || typeof $slider.slider !== 'function') {
      return;
    }
    var values = ['30', '90', '180', '365', '0'];
    var labels = {
      0: translation('module_local_speech_to_text_TranscriptRetention30Days', '30 days'),
      1: translation('module_local_speech_to_text_TranscriptRetention90Days', '90 days'),
      2: translation('module_local_speech_to_text_TranscriptRetention180Days', '180 days'),
      3: translation('module_local_speech_to_text_TranscriptRetention365Days', '1 year'),
      4: translation('module_local_speech_to_text_TranscriptRetentionUnlimited', 'Unlimited')
    };
    var currentValue = String($input.val() || '365');
    var initialIndex = values.indexOf(currentValue);
    var initialized = false;
    if (initialIndex < 0) {
      initialIndex = values.indexOf('365');
      $input.val('365');
    }
    $slider.slider({
      min: 0,
      max: values.length - 1,
      step: 1,
      smooth: true,
      autoAdjustLabels: false,
      interpretLabel: function (value) {
        return labels[value] || '';
      },
      onChange: function (value) {
        var selected = values[value] || '365';
        $input.val(selected);
        if (initialized) {
          $input.trigger('change');
          updateRetentionConflict($settingsForm);
          if (typeof Form !== 'undefined' && typeof Form.checkValues === 'function') {
            Form.checkValues();
          }
        }
      }
    });
    $slider.slider('set value', initialIndex, false);
    initialized = true;
  }
  function retentionAllowsProcessing(processingDays, retentionDays) {
    return retentionDays === 0 || processingDays > 0 && processingDays <= retentionDays;
  }
  function updateRetentionConflict($settingsForm) {
    var processingDays = Number($settingsForm.find('input[name="cdr_history_days"]').val());
    var retentionDays = Number($settingsForm.find('input[name="transcript_retention_days"]').val());
    var compatible = Number.isFinite(processingDays) && Number.isFinite(retentionDays) && retentionAllowsProcessing(processingDays, retentionDays);
    var $message = $settingsForm.find('.stt-retention-conflict-message');
    $message.toggleClass('hidden', compatible).attr('aria-hidden', compatible ? 'true' : 'false');
    return compatible;
  }
  function initializeModelMarketplace($modelForm) {
    var $saveButton = $('#module-local-speech-to-text-model-save');
    var $modelInputs = $('.module-local-speech-to-text .stt-model-marketplace-table input[type="radio"][data-platform]');
    var $compatibilityMessage = $('#stt-model-language-compatibility');
    var selectedLanguage = String($modelForm.data('selected-language') || '').trim().toLowerCase();
    var $platformButtons = $('.module-local-speech-to-text .stt-model-platform-switch [data-platform]');
    // Every platform keeps its own model; Save stores the platforms whose choice changed.
    var initialModels = {};
    $modelInputs.filter(':checked').each(function () {
      initialModels[String($(this).attr('data-platform'))] = String($(this).val());
    });
    function changedInputs() {
      return $modelInputs.filter(':checked').filter(function () {
        return initialModels[String($(this).attr('data-platform'))] !== String($(this).val());
      });
    }
    function modelSupportsLanguage($input) {
      var supportedLanguages;
      if (selectedLanguage === '' || selectedLanguage === 'auto') {
        return true;
      }
      supportedLanguages = String($input.attr('data-supported-languages') || '').split(',').map(function (language) {
        return language.trim().toLowerCase();
      }).filter(function (language) {
        return language !== '';
      });
      return supportedLanguages.indexOf(selectedLanguage) !== -1;
    }
    function showPlatform(platform) {
      $platformButtons.each(function () {
        var active = String($(this).attr('data-platform')) === platform;
        $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
      });
      $('.module-local-speech-to-text .stt-model-marketplace-table tbody tr').each(function () {
        $(this).prop('hidden', String($(this).attr('data-platform')) !== platform);
      });
    }
    function updateSaveButtonState() {
      var $changed = changedInputs();
      var compatible = true;
      var hasChanges;
      $changed.each(function () {
        compatible = compatible && modelSupportsLanguage($(this));
      });
      $compatibilityMessage.toggleClass('hidden', compatible).attr('aria-hidden', compatible ? 'true' : 'false');
      hasChanges = $changed.length > 0 && compatible;
      $saveButton.toggleClass('disabled', !hasChanges);
      $saveButton.prop('disabled', !hasChanges);
    }
    if (typeof $.fn.accordion === 'function') {
      $('.module-local-speech-to-text .stt-advanced-settings-dropdown').accordion();
    }
    $('.module-local-speech-to-text .stt-model-marketplace-table tbody tr').on('click', function (event) {
      if ($(event.target).closest('a, button, form, input, label').length > 0) {
        return;
      }
      $(this).find('.ui.radio.checkbox').checkbox('check');
    });
    $platformButtons.on('click', function () {
      showPlatform(String($(this).attr('data-platform')));
    });
    $modelInputs.on('change', function () {
      var $row = $(this).closest('tr');
      $row.addClass('positive').siblings('[data-platform="' + String($(this).attr('data-platform')) + '"]').removeClass('positive');
      updateSaveButtonState();
    });
    $modelForm.on('submit', function () {
      if ($saveButton.prop('disabled')) {
        return false;
      }
      if ($saveButton.length > 0) {
        $saveButton.addClass('loading disabled active');
        $saveButton.prop('disabled', true);
      }
    });
    updateSaveButtonState();
  }
  function initializeConfirmModal() {
    var $modal = $('#stt-confirm-modal');
    if ($modal.length === 0 || typeof $modal.modal !== 'function') {
      return;
    }

    // Pre-initialize so Semantic UI moves the element to <body> and hides it properly
    $modal.modal({});
    function showConfirm(message, onConfirm) {
      $modal.find('.stt-confirm-message').text(message);
      $modal.modal({
        closable: true,
        onApprove: function () {
          onConfirm();
        }
      }).modal('show');
    }
    function bindConfirmableForm($form) {
      $form.on('submit.stt-confirm', function (event) {
        var message = $(this).data('confirm') || '';
        if (message === '') {
          return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        var $self = $(this);
        showConfirm(message, function () {
          $self.off('submit.stt-confirm').trigger('submit');
        });
      });
    }
    $('.module-local-speech-to-text .stt-confirmable-form').each(function () {
      bindConfirmableForm($(this));
    });
  }

  // Transcript deletion is irreversible, so it needs an explicit
  // acknowledgement: the dialog lists what is and is not removed, the
  // Delete button stays disabled until the checkbox is ticked, and only then
  // is confirm_delete added to the form the server requires it on.
  function initializeTranscriptDeletion() {
    var $modal = $('#stt-delete-transcript-modal');
    var $forms = $('.module-local-speech-to-text .stt-delete-transcript-form');
    if ($modal.length === 0 || $forms.length === 0 || typeof $modal.modal !== 'function') {
      return;
    }
    var $acknowledge = $modal.find('#stt-delete-transcript-acknowledge');
    var $approve = $modal.find('.approve.button');
    var pendingForm = null;
    function setApproveEnabled(enabled) {
      $approve.toggleClass('disabled', !enabled).prop('disabled', !enabled);
    }

    // Pre-initialize so Semantic UI moves the element to <body> and hides it properly
    $modal.modal({});
    $acknowledge.on('change', function () {
      setApproveEnabled($acknowledge.prop('checked'));
    });
    $forms.on('submit.stt-delete-transcript', function (event) {
      event.preventDefault();
      pendingForm = this;
      $(this).closest('details').prop('open', false);
      $acknowledge.prop('checked', false);
      setApproveEnabled(false);
      $modal.modal({
        closable: true,
        onApprove: function () {
          if (!$acknowledge.prop('checked') || pendingForm === null) {
            return false;
          }
          var form = pendingForm;
          pendingForm = null;
          $('<input>', {
            type: 'hidden',
            name: 'confirm_delete',
            value: '1'
          }).appendTo(form);
          // The native submit skips this handler, so the dialog is not shown again.
          form.submit();
          return true;
        },
        onHidden: function () {
          pendingForm = null;
        }
      }).modal('show');
    });
  }
  function initializeTranscriptRows() {
    $('.module-local-speech-to-text .stt-transcript-row').on('click', function (event) {
      if ($(event.target).closest('a, button, input, select, textarea').length > 0) {
        return;
      }
      var destination = $(this).data('transcript-url');
      if (destination) {
        window.location.href = destination;
      }
    });
  }
  function initializeTranscriptActionMenu() {
    var $menus = $('.module-local-speech-to-text .stt-transcript-actions');
    $menus.find('.menu a, .menu button').on('click.stt-transcript-actions', function () {
      $(this).closest('details').removeAttr('open');
    });
    $(document).on('click.stt-transcript-actions', function (event) {
      $menus.each(function () {
        if (this !== event.target && !this.contains(event.target)) {
          $(this).removeAttr('open');
        }
      });
    });
  }
  function initializeTranscriptLegTabs() {
    var transcriptLocation = window.SttTranscriptView && window.SttTranscriptView.location;
    $('.module-local-speech-to-text .stt-call-leg-review').each(function () {
      var $review = $(this);
      var $tabs = $review.find('[data-stt-call-leg]');
      var $panels = $review.find('[data-stt-call-leg-panel]');
      function panelLeg($element) {
        return String($element.closest('[data-stt-call-leg-panel]').data('stt-call-leg-panel') || '');
      }
      function show(callLeg) {
        $tabs.each(function () {
          var active = String($(this).data('stt-call-leg') || '') === callLeg;
          $(this).toggleClass('active', active).attr('aria-selected', active ? 'true' : 'false');
        });
        $panels.each(function () {
          var hidden = String($(this).data('stt-call-leg-panel') || '') !== callLeg;
          $(this).prop('hidden', hidden);
          if (hidden && window.SttTranscriptView && typeof window.SttTranscriptView.pause === 'function') {
            window.SttTranscriptView.pause(this);
          } else if (hidden) {
            $(this).find('audio').each(function () {
              var audio = this;
              audio.pause();
            });
          }
        });
      }
      $tabs.on('click', function () {
        var callLeg = String($(this).data('stt-call-leg') || '');
        show(callLeg);
        if (transcriptLocation) {
          // A held moment in the other leg no longer describes what is on screen.
          var moment = transcriptLocation.read(window.location.search);
          var $stage = moment.stage ? $review.find('.stt-transcript-view[data-stt-stage="' + moment.stage + '"]') : $();
          var changes = {
            leg: callLeg
          };
          if ($stage.length > 0 && panelLeg($stage) !== callLeg) {
            changes.stage = '';
          }
          transcriptLocation.write(changes);
        }
      });

      // The address picks the leg: the leg of a shared moment first, then an explicit leg.
      if (transcriptLocation) {
        var initial = transcriptLocation.read(window.location.search);
        var $target = initial.stage ? $review.find('.stt-transcript-view[data-stt-stage="' + initial.stage + '"]') : $();
        var leg = $target.length > 0 ? panelLeg($target) : initial.leg;
        if (leg !== '' && $tabs.filter('[data-stt-call-leg="' + leg + '"]').length > 0) {
          show(leg);
        }
      }
    });
  }
  $(function () {
    // Only items with a data-tab target are client-side tabs. The
    // Transcripts item is a real link (?tab=transcripts, full page
    // navigation) because its content is only rendered server-side when
    // that tab is the one being loaded - see index.volt/IndexViewService.
    var $tabMenu = $('#module-local-speech-to-text-menu');
    var $tabItems = $tabMenu.find('.item[data-tab]');
    var $settingsForm = $('#module-local-speech-to-text-form');
    var $modelForm = $('#module-local-speech-to-text-model-form');
    $tabItems.tab({
      onVisible: function () {
        // Semantic UI's tab module (2.4.1, see
        // public/assets/js/vendor equivalent under Core) only
        // (de)activates menu items it was initialized on
        // ($tabItems, i.e. items with a data-tab) - see its
        // activate.navigation(). The Transcripts item is
        // deliberately outside that set, so a normal tab switch
        // never removes its 'active' class: without this, landing
        // on ?tab=transcripts (which server-renders it active) and
        // then clicking any other tab would leave Transcripts
        // highlighted alongside it.
        $tabMenu.find('.item').not('[data-tab]').removeClass('active');
      }
    });
    if (requestedTab() !== '' && requestedTab() !== 'transcripts') {
      // 'transcripts' has no managed nav item (no data-tab) any more:
      // its panel and its menu link are already rendered server-side
      // as active on ?tab=transcripts (see index.volt), so there is
      // nothing here for the tab module to switch to - skip the call
      // instead of relying on it being a harmless no-op.
      $tabItems.tab('change tab', requestedTab());
    }
    $('.module-local-speech-to-text .ui.dropdown').not('.stt-worker-windows-download').dropdown();
    var loadWindowsWorkerPackages = initializeWindowsWorkerDownload();
    loadWindowsWorkerPackages();
    $('.module-local-speech-to-text .ui.checkbox').checkbox();
    $('.module-local-speech-to-text [data-content]').popup();
    initializeModelMarketplace($modelForm);
    initializeTranscriptDateFilter();
    initializeModuleLogs();
    initializeCdrHistorySlider($settingsForm);
    initializeTranscriptRetentionSlider($settingsForm);
    updateRetentionConflict($settingsForm);
    initializeConfirmModal();
    initializeTranscriptDeletion();
    initializeTranscriptRows();
    initializeTranscriptActionMenu();
    initializeTranscriptLegTabs();
    if ($.fn.form && $.fn.form.settings && $.fn.form.settings.rules) {
      $.fn.form.settings.rules.sttProcessingRange = function (value, range) {
        var limits = String(range || '').split(',').map(Number);
        var number = Number(value);
        return limits.length === 2 && Number.isFinite(number) && number >= limits[0] && number <= limits[1];
      };
      $.fn.form.settings.rules.sttProcessingOverlap = function (value) {
        var overlapMS = Math.round(Number(value) * 1000);
        var durationMS = Math.round(Number($settingsForm.find('[name="max_segment_duration_sec"]').val()) * 1000);
        return Number.isFinite(overlapMS) && Number.isFinite(durationMS) && overlapMS >= 0 && overlapMS < durationMS;
      };
      $.fn.form.settings.rules.sttRetentionCompatibility = function () {
        return updateRetentionConflict($settingsForm);
      };
    }
    var processingValidationFields = {
      temperature: {
        identifier: 'temperature',
        rules: [{
          type: 'sttProcessingRange[0,1]'
        }]
      },
      temperature_fallback_step: {
        identifier: 'temperature_fallback_step',
        rules: [{
          type: 'sttProcessingRange[0,1]'
        }]
      },
      temperature_fallback_count: {
        identifier: 'temperature_fallback_count',
        rules: [{
          type: 'integer[0..10]'
        }]
      },
      top_k: {
        identifier: 'top_k',
        rules: [{
          type: 'integer[1..100]'
        }]
      },
      compression_ratio_threshold: {
        identifier: 'compression_ratio_threshold',
        rules: [{
          type: 'sttProcessingRange[0,10]'
        }]
      },
      log_probability_threshold: {
        identifier: 'log_probability_threshold',
        rules: [{
          type: 'sttProcessingRange[-10,0]'
        }]
      },
      first_token_log_probability_threshold: {
        identifier: 'first_token_log_probability_threshold',
        rules: [{
          type: 'sttProcessingRange[-10,0]'
        }]
      },
      no_speech_threshold: {
        identifier: 'no_speech_threshold',
        rules: [{
          type: 'sttProcessingRange[0,1]'
        }]
      },
      max_segment_duration_sec: {
        identifier: 'max_segment_duration_sec',
        rules: [{
          type: 'sttProcessingRange[1,120]'
        }]
      },
      segment_overlap_sec: {
        identifier: 'segment_overlap_sec',
        rules: [{
          type: 'sttProcessingRange[0,10]'
        }, {
          type: 'sttProcessingOverlap'
        }]
      }
    };
    function updateVadDependentFields() {
      var enabled = $settingsForm.find('[name="voice_activity_detection"]').is(':checked');
      $settingsForm.find('.stt-vad-dependent').toggleClass('disabled', !enabled).attr('aria-disabled', enabled ? 'false' : 'true');
    }
    $settingsForm.find('[name="voice_activity_detection"]').on('change', updateVadDependentFields);
    updateVadDependentFields();
    $('.module-local-speech-to-text .stt-copy-button').on('click', function () {
      var text = String($(this).attr('data-clipboard-text') || '');
      var $button = $(this);
      function copied() {
        $button.addClass('positive');
        setTimeout(function () {
          $button.removeClass('positive');
        }, 900);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(copied);
        return;
      }
      // Over plain HTTP the Clipboard API is missing; a selected textarea still copies.
      var $buffer = $('<textarea readonly></textarea>').val(text).css({
        position: 'fixed',
        top: 0,
        left: '-9999px'
      }).appendTo('body');
      $buffer[0].select();
      try {
        if (document.execCommand('copy')) {
          copied();
        }
      } catch (error) {
        // The text stays selectable in the page; nothing else to do.
      }
      $buffer.remove();
    });
    $('.module-local-speech-to-text .stt-worker-platform-switch [data-platform]').on('click', function () {
      var platform = String($(this).attr('data-platform'));
      var $download = $(this).closest('.stt-worker-download');
      $(this).addClass('active').attr('aria-pressed', 'true').siblings().removeClass('active').attr('aria-pressed', 'false');
      $download.find('.stt-worker-platform-panel').each(function () {
        $(this).prop('hidden', String($(this).attr('data-platform')) !== platform);
      });
      $download.closest('[data-worker-platform]').attr('data-worker-platform', platform).find('input[name="worker_platform"]').val(platform);
      loadWindowsWorkerPackages();
    });
    if (typeof Form !== 'undefined' && $settingsForm.length > 0) {
      Form.$formObj = $settingsForm;
      Form.url = $settingsForm.attr('action');
      Form.method = 'POST';
      Form.validateRules = Object.assign({
        default_language: {
          identifier: 'default_language',
          rules: [{
            type: 'empty'
          }]
        },
        cdr_history_days: {
          identifier: 'cdr_history_days',
          rules: [{
            type: 'integer[0..365]'
          }]
        },
        transcript_retention_days: {
          identifier: 'transcript_retention_days',
          rules: [{
            type: 'integer[0..365]'
          }, {
            type: 'sttRetentionCompatibility',
            prompt: translation('module_local_speech_to_text_TranscriptRetentionConflict', 'The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.')
          }]
        }
      }, processingValidationFields);
      Form.cbBeforeSendForm = function (settings) {
        return settings;
      };
      Form.cbAfterSendForm = function () {};
      Form.apiSettings.enabled = false;
      Form.apiSettings.apiObject = null;
      Form.sendOnlyChanged = false;
      Form.enableDirrity = true;
      Form.initialize();
    } else {
      $settingsForm.form({
        fields: Object.assign({
          default_language: ['empty'],
          cdr_history_days: ['integer[0..365]'],
          transcript_retention_days: ['integer[0..365]', {
            type: 'sttRetentionCompatibility',
            prompt: translation('module_local_speech_to_text_TranscriptRetentionConflict', 'The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.')
          }]
        }, processingValidationFields)
      });
    }
  });
})();

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIiwibmFtZXMiOlsidHJhbnNsYXRpb24iLCJrZXkiLCJmYWxsYmFjayIsImdsb2JhbFRyYW5zbGF0ZSIsInJlcXVlc3RlZFRhYiIsInBhcmFtcyIsIlVSTFNlYXJjaFBhcmFtcyIsIndpbmRvdyIsImxvY2F0aW9uIiwic2VhcmNoIiwidGFiTmFtZSIsImdldCIsImhhc2giLCJyZXBsYWNlIiwidGVzdCIsImNhbGVuZGFyTG9jYWxlIiwiZGF5cyIsIm1vbnRocyIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiY2FsZW5kYXJUZXh0IiwiZm9ybWF0Iiwic2VwYXJhdG9yIiwiYXBwbHlMYWJlbCIsImNhbmNlbExhYmVsIiwiZnJvbUxhYmVsIiwidG9MYWJlbCIsImN1c3RvbVJhbmdlTGFiZWwiLCJkYXlzT2ZXZWVrIiwibW9udGhOYW1lcyIsImZpcnN0RGF5IiwibW9tZW50RGF0ZSIsInZhbHVlIiwiZGF0ZSIsIm1vbWVudCIsImlzVmFsaWQiLCJjbG9uZSIsImluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlciIsIiRmb3JtIiwiJCIsIiRzZWxlY3RvciIsImxlbmd0aCIsImZuIiwiZGF0ZXJhbmdlcGlja2VyIiwiJGRhdGVGcm9tIiwiZmluZCIsIiRkYXRlVG8iLCIkc2VhcmNoIiwic2VhcmNoRGVib3VuY2VUaW1lciIsInRvZGF5Iiwic3RhcnREYXRlIiwidmFsIiwiZW5kRGF0ZSIsImlzQWZ0ZXIiLCJzd2FwRGF0ZSIsInVwZGF0ZUhpZGRlbklucHV0cyIsInN0YXJ0IiwiZW5kIiwiY2FuY2VsU2VhcmNoRGVib3VuY2UiLCJjbGVhclRpbWVvdXQiLCJzdWJtaXRUcmFuc2NyaXB0U2VhcmNoIiwidHJpZ2dlciIsInJhbmdlcyIsIl9kZWZpbmVQcm9wZXJ0eSIsInN1YnRyYWN0Iiwic3RhcnRPZiIsImVuZE9mIiwiYWx3YXlzU2hvd0NhbGVuZGFycyIsImF1dG9VcGRhdGVJbnB1dCIsImxpbmtlZENhbGVuZGFycyIsIm1heERhdGUiLCJsb2NhbGUiLCJvbiIsInBpY2tlciIsImRhdGEiLCJzZXRUaW1lb3V0IiwiZXZlbnQiLCJ3aGljaCIsInByZXZlbnREZWZhdWx0IiwiU3RyaW5nIiwidHJpbSIsImZvcm1hdFBhY2thZ2VTaXplIiwiYnl0ZXMiLCJ0b0ZpeGVkIiwiaW5pdGlhbGl6ZVdpbmRvd3NXb3JrZXJEb3dubG9hZCIsIiRkcm9wZG93biIsInJlcXVlc3RlZCIsImRyb3Bkb3duIiwiYWN0aW9uIiwidGV4dCIsImNsb3Nlc3QiLCJsb2FkUGFja2FnZXMiLCJwcm9wIiwiYWpheCIsInVybCIsImF0dHIiLCJtZXRob2QiLCJkYXRhVHlwZSIsImRvbmUiLCJyZXNwb25zZSIsInBhY2thZ2VzIiwiQXJyYXkiLCJpc0FycmF5IiwiZm9yRWFjaCIsIml0ZW0iLCJmaWxlbmFtZSIsImxhYmVsIiwic2l6ZSIsImluaXRpYWxpemVNb2R1bGVMb2dzIiwiJHNlZ21lbnQiLCIkcmVzdWx0cyIsIiRzdGF0ZSIsIiRyZXN1bHRDb3VudCIsIiR1cGRhdGVkQXQiLCIkbGV2ZWxGaWx0ZXIiLCIkY29tcG9uZW50RmlsdGVyIiwiZGF0YVVybCIsIiRwZXJpb2RCdXR0b25zIiwiJHJlZnJlc2hCdXR0b24iLCJjdXJyZW50UGVyaW9kIiwicGFyc2VJbnQiLCJmaWx0ZXIiLCJsb2FkZWRPbmNlIiwicmVxdWVzdCIsInJlcXVlc3RHZW5lcmF0aW9uIiwiY3VycmVudEVudHJpZXMiLCJjdXJyZW50UmVzcG9uc2UiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsImxldmVsTmFtZSIsImxldmVsIiwibGV2ZWxzIiwiZGVidWciLCJpbmZvIiwibm90aWNlIiwid2FybmluZyIsImVycm9yIiwiY29tcG9uZW50TmFtZSIsImNvbXBvbmVudCIsImFsaWFzZXMiLCJhcGkiLCJxdWV1ZSIsInNjYW5uZXIiLCJ3b3JrZXIiLCJ3b3JrZXJsb2NhbHNwZWVjaHRvdGV4dGpvYnMiLCJzZXR0aW5ncyIsIndlYl91aSIsInNldHVwIiwic3RvcmFnZSIsInRyYW5zY3JpcHQiLCJzeXN0ZW0iLCJsb2NhbHNwZWVjaHRvdGV4dG1haW4iLCJsb2dnZXIiLCJsYWJlbHMiLCJvdGhlciIsImZvcm1hdFRpbWVzdGFtcCIsInRpbWVzdGFtcCIsIkRhdGUiLCJpc05hTiIsImdldFRpbWUiLCJ0b0xvY2FsZVN0cmluZyIsInJlbmRlclN0YXRlIiwia2luZCIsIm1lc3NhZ2UiLCJyZW1vdmVDbGFzcyIsImFkZENsYXNzIiwiZmlsdGVyRW50cmllcyIsImVudHJpZXMiLCJxdWVyeSIsInRvTG93ZXJDYXNlIiwiZW50cnkiLCJoYXlzdGFjayIsInNldmVyaXR5IiwicGlkIiwiSlNPTiIsInN0cmluZ2lmeSIsImNvbnRleHQiLCJqb2luIiwiaW5kZXhPZiIsInJlbmRlckVudHJpZXMiLCJlbXB0eSIsIiRjYXJkIiwiJGhlYWRlciIsIiRpZGVudGl0eSIsIiRsZXZlbCIsIiRjb21wb25lbnQiLCIkdGltZSIsInRzIiwiJG1lc3NhZ2UiLCIkZGV0YWlscyIsIiRzdW1tYXJ5IiwiZGV0YWlsVGV4dCIsInVuZGVmaW5lZCIsIk9iamVjdCIsImtleXMiLCJhcHBlbmQiLCJ1cGRhdGVDb21wb25lbnRPcHRpb25zIiwic2VsZWN0ZWQiLCJjb21wb25lbnRzIiwicHVzaCIsInNvcnQiLCJsZWZ0IiwicmlnaHQiLCJsb2NhbGVDb21wYXJlIiwidXBkYXRlTWV0YSIsImNvdW50IiwiZm91bmQiLCJ1cGRhdGVkIiwiZ2VuZXJhdGVkQXQiLCJyZW5kZXJDdXJyZW50IiwiZmlsdGVyZWQiLCJzb3VyY2VTdGF0dXMiLCJub3RpY2VzIiwibWFsZm9ybWVkQ291bnQiLCJ0cnVuY2F0ZWQiLCJzZXRMb2FkaW5nIiwibG9hZExvZ3MiLCJmb3JjZSIsInJlcXVlc3RJZCIsImFib3J0IiwiY3VycmVudFJlcXVlc3QiLCJwZXJpb2QiLCJmYWlsIiwieGhyIiwic3RhdHVzIiwiYWx3YXlzIiwiJGJ1dHRvbiIsImFkZCIsImhhc0NsYXNzIiwiaW5pdGlhbGl6ZUNkckhpc3RvcnlTbGlkZXIiLCIkc2V0dGluZ3NGb3JtIiwiJHNsaWRlciIsIiRpbnB1dCIsInNsaWRlciIsInZhbHVlcyIsImN1cnJlbnRWYWx1ZSIsImluaXRpYWxJbmRleCIsImluaXRpYWxpemVkIiwibWluIiwibWF4Iiwic3RlcCIsInNtb290aCIsImF1dG9BZGp1c3RMYWJlbHMiLCJpbnRlcnByZXRMYWJlbCIsIm9uQ2hhbmdlIiwidXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QiLCJGb3JtIiwiY2hlY2tWYWx1ZXMiLCJpbml0aWFsaXplVHJhbnNjcmlwdFJldGVudGlvblNsaWRlciIsInJldGVudGlvbkFsbG93c1Byb2Nlc3NpbmciLCJwcm9jZXNzaW5nRGF5cyIsInJldGVudGlvbkRheXMiLCJjb21wYXRpYmxlIiwidG9nZ2xlQ2xhc3MiLCJpbml0aWFsaXplTW9kZWxNYXJrZXRwbGFjZSIsIiRtb2RlbEZvcm0iLCIkc2F2ZUJ1dHRvbiIsIiRtb2RlbElucHV0cyIsIiRjb21wYXRpYmlsaXR5TWVzc2FnZSIsInNlbGVjdGVkTGFuZ3VhZ2UiLCIkcGxhdGZvcm1CdXR0b25zIiwiaW5pdGlhbE1vZGVscyIsImVhY2giLCJjaGFuZ2VkSW5wdXRzIiwibW9kZWxTdXBwb3J0c0xhbmd1YWdlIiwic3VwcG9ydGVkTGFuZ3VhZ2VzIiwic3BsaXQiLCJtYXAiLCJsYW5ndWFnZSIsInNob3dQbGF0Zm9ybSIsInBsYXRmb3JtIiwiYWN0aXZlIiwidXBkYXRlU2F2ZUJ1dHRvblN0YXRlIiwiJGNoYW5nZWQiLCJoYXNDaGFuZ2VzIiwiYWNjb3JkaW9uIiwidGFyZ2V0IiwiY2hlY2tib3giLCIkcm93Iiwic2libGluZ3MiLCJpbml0aWFsaXplQ29uZmlybU1vZGFsIiwiJG1vZGFsIiwibW9kYWwiLCJzaG93Q29uZmlybSIsIm9uQ29uZmlybSIsImNsb3NhYmxlIiwib25BcHByb3ZlIiwiYmluZENvbmZpcm1hYmxlRm9ybSIsInN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiIsIiRzZWxmIiwib2ZmIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHREZWxldGlvbiIsIiRmb3JtcyIsIiRhY2tub3dsZWRnZSIsIiRhcHByb3ZlIiwicGVuZGluZ0Zvcm0iLCJzZXRBcHByb3ZlRW5hYmxlZCIsImVuYWJsZWQiLCJmb3JtIiwidHlwZSIsIm5hbWUiLCJhcHBlbmRUbyIsInN1Ym1pdCIsIm9uSGlkZGVuIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSb3dzIiwiZGVzdGluYXRpb24iLCJocmVmIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRBY3Rpb25NZW51IiwiJG1lbnVzIiwicmVtb3ZlQXR0ciIsImRvY3VtZW50IiwiY29udGFpbnMiLCJpbml0aWFsaXplVHJhbnNjcmlwdExlZ1RhYnMiLCJ0cmFuc2NyaXB0TG9jYXRpb24iLCJTdHRUcmFuc2NyaXB0VmlldyIsIiRyZXZpZXciLCIkdGFicyIsIiRwYW5lbHMiLCJwYW5lbExlZyIsIiRlbGVtZW50Iiwic2hvdyIsImNhbGxMZWciLCJoaWRkZW4iLCJwYXVzZSIsImF1ZGlvIiwicmVhZCIsIiRzdGFnZSIsInN0YWdlIiwiY2hhbmdlcyIsImxlZyIsIndyaXRlIiwiaW5pdGlhbCIsIiR0YXJnZXQiLCIkdGFiTWVudSIsIiR0YWJJdGVtcyIsInRhYiIsIm9uVmlzaWJsZSIsIm5vdCIsImxvYWRXaW5kb3dzV29ya2VyUGFja2FnZXMiLCJwb3B1cCIsInJ1bGVzIiwic3R0UHJvY2Vzc2luZ1JhbmdlIiwicmFuZ2UiLCJsaW1pdHMiLCJudW1iZXIiLCJzdHRQcm9jZXNzaW5nT3ZlcmxhcCIsIm92ZXJsYXBNUyIsIk1hdGgiLCJyb3VuZCIsImR1cmF0aW9uTVMiLCJzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5IiwicHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMiLCJ0ZW1wZXJhdHVyZSIsImlkZW50aWZpZXIiLCJ0ZW1wZXJhdHVyZV9mYWxsYmFja19zdGVwIiwidGVtcGVyYXR1cmVfZmFsbGJhY2tfY291bnQiLCJ0b3BfayIsImNvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZCIsImxvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQiLCJmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkIiwibm9fc3BlZWNoX3RocmVzaG9sZCIsIm1heF9zZWdtZW50X2R1cmF0aW9uX3NlYyIsInNlZ21lbnRfb3ZlcmxhcF9zZWMiLCJ1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMiLCJpcyIsImNvcGllZCIsIm5hdmlnYXRvciIsImNsaXBib2FyZCIsIndyaXRlVGV4dCIsInRoZW4iLCIkYnVmZmVyIiwiY3NzIiwicG9zaXRpb24iLCJ0b3AiLCJzZWxlY3QiLCJleGVjQ29tbWFuZCIsInJlbW92ZSIsIiRkb3dubG9hZCIsIiRmb3JtT2JqIiwidmFsaWRhdGVSdWxlcyIsImFzc2lnbiIsImRlZmF1bHRfbGFuZ3VhZ2UiLCJjZHJfaGlzdG9yeV9kYXlzIiwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cyIsInByb21wdCIsImNiQmVmb3JlU2VuZEZvcm0iLCJjYkFmdGVyU2VuZEZvcm0iLCJhcGlTZXR0aW5ncyIsImFwaU9iamVjdCIsInNlbmRPbmx5Q2hhbmdlZCIsImVuYWJsZURpcnJpdHkiLCJpbml0aWFsaXplIiwiZmllbGRzIl0sInNvdXJjZXMiOlsiLi4vbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIl0sInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAoKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgZnVuY3Rpb24gdHJhbnNsYXRpb24oa2V5LCBmYWxsYmFjaykge1xuICAgICAgICBpZiAodHlwZW9mIGdsb2JhbFRyYW5zbGF0ZSAhPT0gJ3VuZGVmaW5lZCcgJiYgZ2xvYmFsVHJhbnNsYXRlW2tleV0pIHtcbiAgICAgICAgICAgIHJldHVybiBnbG9iYWxUcmFuc2xhdGVba2V5XTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiBmYWxsYmFjaztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiByZXF1ZXN0ZWRUYWIoKSB7XG4gICAgICAgIHZhciBwYXJhbXMgPSBuZXcgVVJMU2VhcmNoUGFyYW1zKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICB2YXIgdGFiTmFtZSA9IHBhcmFtcy5nZXQoJ3RhYicpIHx8IHdpbmRvdy5sb2NhdGlvbi5oYXNoLnJlcGxhY2UoL14jLywgJycpO1xuXG4gICAgICAgIHJldHVybiAvXlthLXowLTlfLV0rJC8udGVzdCh0YWJOYW1lKSA/IHRhYk5hbWUgOiAnJztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjYWxlbmRhckxvY2FsZSgpIHtcbiAgICAgICAgdmFyIGRheXMgPSBbJ1N1JywgJ01vJywgJ1R1JywgJ1dlJywgJ1RoJywgJ0ZyJywgJ1NhJ107XG4gICAgICAgIHZhciBtb250aHMgPSBbXG4gICAgICAgICAgICAnSmFudWFyeScsXG4gICAgICAgICAgICAnRmVicnVhcnknLFxuICAgICAgICAgICAgJ01hcmNoJyxcbiAgICAgICAgICAgICdBcHJpbCcsXG4gICAgICAgICAgICAnTWF5JyxcbiAgICAgICAgICAgICdKdW5lJyxcbiAgICAgICAgICAgICdKdWx5JyxcbiAgICAgICAgICAgICdBdWd1c3QnLFxuICAgICAgICAgICAgJ1NlcHRlbWJlcicsXG4gICAgICAgICAgICAnT2N0b2JlcicsXG4gICAgICAgICAgICAnTm92ZW1iZXInLFxuICAgICAgICAgICAgJ0RlY2VtYmVyJ1xuICAgICAgICBdO1xuXG4gICAgICAgIGlmICh0eXBlb2YgU2VtYW50aWNMb2NhbGl6YXRpb24gIT09ICd1bmRlZmluZWQnICYmIFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dCkge1xuICAgICAgICAgICAgZGF5cyA9IFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dC5kYXlzIHx8IGRheXM7XG4gICAgICAgICAgICBtb250aHMgPSBTZW1hbnRpY0xvY2FsaXphdGlvbi5jYWxlbmRhclRleHQubW9udGhzIHx8IG1vbnRocztcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiB7XG4gICAgICAgICAgICBmb3JtYXQ6ICdERC9NTS9ZWVlZJyxcbiAgICAgICAgICAgIHNlcGFyYXRvcjogJyAtICcsXG4gICAgICAgICAgICBhcHBseUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0FwcGx5QnRuJywgJ0FwcGx5JyksXG4gICAgICAgICAgICBjYW5jZWxMYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9DYW5jZWxCdG4nLCAnQ2FuY2VsJyksXG4gICAgICAgICAgICBmcm9tTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfZnJvbScsICdGcm9tJyksXG4gICAgICAgICAgICB0b0xhYmVsOiB0cmFuc2xhdGlvbignY2FsX3RvJywgJ1RvJyksXG4gICAgICAgICAgICBjdXN0b21SYW5nZUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0N1c3RvbVBlcmlvZCcsICdDdXN0b20gcmFuZ2UnKSxcbiAgICAgICAgICAgIGRheXNPZldlZWs6IGRheXMsXG4gICAgICAgICAgICBtb250aE5hbWVzOiBtb250aHMsXG4gICAgICAgICAgICBmaXJzdERheTogMVxuICAgICAgICB9O1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIG1vbWVudERhdGUodmFsdWUsIGZhbGxiYWNrKSB7XG4gICAgICAgIHZhciBkYXRlID0gbW9tZW50KHZhbHVlLCAnWVlZWS1NTS1ERCcsIHRydWUpO1xuXG4gICAgICAgIHJldHVybiBkYXRlLmlzVmFsaWQoKSA/IGRhdGUgOiBmYWxsYmFjay5jbG9uZSgpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlcigpIHtcbiAgICAgICAgdmFyICRmb3JtID0gJCgnI3N0dC10cmFuc2NyaXB0cy1maWx0ZXItZm9ybScpO1xuICAgICAgICB2YXIgJHNlbGVjdG9yID0gJCgnI3N0dC10cmFuc2NyaXB0cy1kYXRlLXJhbmdlLXNlbGVjdG9yJyk7XG5cbiAgICAgICAgaWYgKCRmb3JtLmxlbmd0aCA9PT0gMCB8fCAkc2VsZWN0b3IubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHR5cGVvZiBtb21lbnQgPT09ICd1bmRlZmluZWQnIHx8IHR5cGVvZiAkLmZuLmRhdGVyYW5nZXBpY2tlciA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciAkZGF0ZUZyb20gPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiZGF0ZV9mcm9tXCJdJyk7XG4gICAgICAgIHZhciAkZGF0ZVRvID0gJGZvcm0uZmluZCgnaW5wdXRbbmFtZT1cImRhdGVfdG9cIl0nKTtcbiAgICAgICAgdmFyICRzZWFyY2ggPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwic2VhcmNoXCJdJyk7XG4gICAgICAgIHZhciBzZWFyY2hEZWJvdW5jZVRpbWVyID0gbnVsbDtcbiAgICAgICAgdmFyIHRvZGF5ID0gbW9tZW50KCk7XG4gICAgICAgIHZhciBzdGFydERhdGUgPSBtb21lbnREYXRlKCRkYXRlRnJvbS52YWwoKSwgdG9kYXkpO1xuICAgICAgICB2YXIgZW5kRGF0ZSA9IG1vbWVudERhdGUoJGRhdGVUby52YWwoKSwgc3RhcnREYXRlKTtcblxuICAgICAgICBpZiAoc3RhcnREYXRlLmlzQWZ0ZXIoZW5kRGF0ZSkpIHtcbiAgICAgICAgICAgIHZhciBzd2FwRGF0ZSA9IHN0YXJ0RGF0ZTtcbiAgICAgICAgICAgIHN0YXJ0RGF0ZSA9IGVuZERhdGU7XG4gICAgICAgICAgICBlbmREYXRlID0gc3dhcERhdGU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCkge1xuICAgICAgICAgICAgJGRhdGVGcm9tLnZhbChzdGFydC5mb3JtYXQoJ1lZWVktTU0tREQnKSk7XG4gICAgICAgICAgICAkZGF0ZVRvLnZhbChlbmQuZm9ybWF0KCdZWVlZLU1NLUREJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY2FuY2VsU2VhcmNoRGVib3VuY2UoKSB7XG4gICAgICAgICAgICBpZiAoc2VhcmNoRGVib3VuY2VUaW1lciAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5jbGVhclRpbWVvdXQoc2VhcmNoRGVib3VuY2VUaW1lcik7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzdWJtaXRUcmFuc2NyaXB0U2VhcmNoKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgICRmb3JtLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHN0YXJ0RGF0ZSwgZW5kRGF0ZSk7XG4gICAgICAgICRzZWxlY3Rvci5kYXRlcmFuZ2VwaWNrZXIoe1xuICAgICAgICAgICAgcmFuZ2VzOiB7XG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVG9kYXknLCAnVG9kYXknKV06IFttb21lbnQoKSwgbW9tZW50KCldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX1llc3RlcmRheScsICdZZXN0ZXJkYXknKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0V2VlaycsICdMYXN0IDcgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDYsICdkYXlzJyksIG1vbWVudCgpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0MzBEYXlzJywgJ0xhc3QgMzAgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDI5LCAnZGF5cycpLCBtb21lbnQoKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVGhpc01vbnRoJywgJ1RoaXMgbW9udGgnKV06IFttb21lbnQoKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5lbmRPZignbW9udGgnKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfTGFzdE1vbnRoJywgJ0xhc3QgbW9udGgnKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5lbmRPZignbW9udGgnKV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBhbHdheXNTaG93Q2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgYXV0b1VwZGF0ZUlucHV0OiB0cnVlLFxuICAgICAgICAgICAgbGlua2VkQ2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgbWF4RGF0ZTogbW9tZW50KCksXG4gICAgICAgICAgICBzdGFydERhdGU6IHN0YXJ0RGF0ZS5zdGFydE9mKCdkYXknKSxcbiAgICAgICAgICAgIGVuZERhdGU6IGVuZERhdGUuZW5kT2YoJ2RheScpLFxuICAgICAgICAgICAgbG9jYWxlOiBjYWxlbmRhckxvY2FsZSgpXG4gICAgICAgIH0sIGZ1bmN0aW9uIChzdGFydCwgZW5kKSB7XG4gICAgICAgICAgICB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCk7XG4gICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGNhbmNlbFNlYXJjaERlYm91bmNlKCk7XG4gICAgICAgICAgICB2YXIgcGlja2VyID0gJHNlbGVjdG9yLmRhdGEoJ2RhdGVyYW5nZXBpY2tlcicpO1xuICAgICAgICAgICAgaWYgKHBpY2tlciAmJiBwaWNrZXIuc3RhcnREYXRlICYmIHBpY2tlci5lbmREYXRlKSB7XG4gICAgICAgICAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHBpY2tlci5zdGFydERhdGUsIHBpY2tlci5lbmREYXRlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ2lucHV0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgIHNlYXJjaERlYm91bmNlVGltZXIgPSB3aW5kb3cuc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICAgICAgJGZvcm0udHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgICAgICB9LCA0MDApO1xuICAgICAgICB9KTtcbiAgICAgICAgJHNlYXJjaC5vbigna2V5ZG93bicsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKGV2ZW50LndoaWNoICE9PSAxMykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ3NlYXJjaCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGlmIChTdHJpbmcoJHNlYXJjaC52YWwoKSB8fCAnJykudHJpbSgpID09PSAnJykge1xuICAgICAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gZm9ybWF0UGFja2FnZVNpemUoYnl0ZXMpIHtcbiAgICAgICAgcmV0dXJuIChieXRlcyAvIDEwNDg1NzYpLnRvRml4ZWQoMikgKyAnIE1CJztcbiAgICB9XG5cbiAgICAvLyBUaGUgV2luZG93cyBkb3dubG9hZCBpcyBvbmUgYnV0dG9uIHdpdGggYW4gTVNJL1pJUCBtZW51LiBGaWxlIG5hbWVzIGFuZCBzaXplcyBjb21lXG4gICAgLy8gZnJvbSB0aGUgcmVsZWFzZSBzZXJ2ZXIgdGhyb3VnaCB0aGUgUEJYLCBvbmNlLCB3aGVuIHRoZSBXaW5kb3dzIHBhbmVsIGlzIGZpcnN0IHNob3duLlxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVXaW5kb3dzV29ya2VyRG93bmxvYWQoKSB7XG4gICAgICAgIHZhciAkZHJvcGRvd24gPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtd29ya2VyLXdpbmRvd3MtZG93bmxvYWQnKTtcbiAgICAgICAgdmFyIHJlcXVlc3RlZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmICgkZHJvcGRvd24ubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm4gZnVuY3Rpb24gKCkge307XG4gICAgICAgIH1cblxuICAgICAgICAkZHJvcGRvd24uZHJvcGRvd24oe1xuICAgICAgICAgICAgYWN0aW9uOiBmdW5jdGlvbiAodGV4dCwgdmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgJGZvcm0gPSAkZHJvcGRvd24uY2xvc2VzdCgnZm9ybScpO1xuICAgICAgICAgICAgICAgICRkcm9wZG93bi5kcm9wZG93bignaGlkZScpO1xuICAgICAgICAgICAgICAgIGlmICh2YWx1ZSA9PT0gJ21zaScgfHwgdmFsdWUgPT09ICd6aXAnKSB7XG4gICAgICAgICAgICAgICAgICAgICRmb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJmb3JtYXRcIl0nKS52YWwodmFsdWUpO1xuICAgICAgICAgICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHJldHVybiBmdW5jdGlvbiBsb2FkUGFja2FnZXMoKSB7XG4gICAgICAgICAgICBpZiAocmVxdWVzdGVkIHx8ICRkcm9wZG93bi5jbG9zZXN0KCcuc3R0LXdvcmtlci1wbGF0Zm9ybS1wYW5lbCcpLnByb3AoJ2hpZGRlbicpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmVxdWVzdGVkID0gdHJ1ZTtcbiAgICAgICAgICAgICQuYWpheCh7XG4gICAgICAgICAgICAgICAgdXJsOiAkZHJvcGRvd24uYXR0cignZGF0YS1wYWNrYWdlcy11cmwnKSxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdHRVQnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbidcbiAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgdmFyIHBhY2thZ2VzID0gcmVzcG9uc2UgJiYgQXJyYXkuaXNBcnJheShyZXNwb25zZS5wYWNrYWdlcykgPyByZXNwb25zZS5wYWNrYWdlcyA6IFtdO1xuICAgICAgICAgICAgICAgIHBhY2thZ2VzLmZvckVhY2goZnVuY3Rpb24gKGl0ZW0pIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKCFpdGVtIHx8IHR5cGVvZiBpdGVtLmZpbGVuYW1lICE9PSAnc3RyaW5nJyB8fCBpdGVtLmZpbGVuYW1lID09PSAnJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIHZhciBsYWJlbCA9IGl0ZW0uZmlsZW5hbWUgKyAodHlwZW9mIGl0ZW0uc2l6ZSA9PT0gJ251bWJlcicgPyAnICgnICsgZm9ybWF0UGFja2FnZVNpemUoaXRlbS5zaXplKSArICcpJyA6ICcnKTtcbiAgICAgICAgICAgICAgICAgICAgJGRyb3Bkb3duLmZpbmQoJy5pdGVtW2RhdGEtdmFsdWU9XCInICsgU3RyaW5nKGl0ZW0uZm9ybWF0KSArICdcIl0gLnN0dC13b3JrZXItcGFja2FnZS1uYW1lJykudGV4dChsYWJlbCk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplTW9kdWxlTG9ncygpIHtcbiAgICAgICAgdmFyICRmb3JtID0gJCgnI3N0dC1tb2R1bGUtbG9ncy1mb3JtJyk7XG4gICAgICAgIHZhciAkc2VnbWVudCA9ICQoJyNzeXN0ZW0tbG9ncy1zZWdtZW50Jyk7XG4gICAgICAgIHZhciAkcmVzdWx0cyA9ICQoJyNzdHQtbG9nLXJlc3VsdHMnKTtcbiAgICAgICAgdmFyICRzdGF0ZSA9ICQoJyNzdHQtbG9nLXN0YXRlJyk7XG4gICAgICAgIHZhciAkcmVzdWx0Q291bnQgPSAkKCcjc3R0LWxvZy1yZXN1bHQtY291bnQnKTtcbiAgICAgICAgdmFyICR1cGRhdGVkQXQgPSAkKCcjc3R0LWxvZy11cGRhdGVkLWF0Jyk7XG4gICAgICAgIHZhciAkbGV2ZWxGaWx0ZXIgPSAkKCcjc3R0LWxvZy1sZXZlbC1maWx0ZXInKTtcbiAgICAgICAgdmFyICRjb21wb25lbnRGaWx0ZXIgPSAkKCcjc3R0LWxvZy1jb21wb25lbnQtZmlsdGVyJyk7XG4gICAgICAgIHZhciAkc2VhcmNoID0gJCgnI3N0dC1sb2ctc2VhcmNoJyk7XG5cbiAgICAgICAgaWYgKCRmb3JtLmxlbmd0aCA9PT0gMCB8fCAkcmVzdWx0cy5sZW5ndGggPT09IDAgfHwgJHN0YXRlLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIGRhdGFVcmwgPSAkZm9ybS5kYXRhKCd1cmwnKTtcbiAgICAgICAgdmFyICRwZXJpb2RCdXR0b25zID0gJGZvcm0uZmluZCgnLnBlcmlvZC1idG4nKTtcbiAgICAgICAgdmFyICRyZWZyZXNoQnV0dG9uID0gJGZvcm0uZmluZCgnLnN0dC1sb2ctcmVmcmVzaCcpO1xuICAgICAgICB2YXIgY3VycmVudFBlcmlvZCA9IHBhcnNlSW50KCRwZXJpb2RCdXR0b25zLmZpbHRlcignLmFjdGl2ZScpLmRhdGEoJ3BlcmlvZCcpLCAxMCk7XG4gICAgICAgIHZhciBsb2FkZWRPbmNlID0gZmFsc2U7XG4gICAgICAgIHZhciByZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgdmFyIHJlcXVlc3RHZW5lcmF0aW9uID0gMDtcbiAgICAgICAgdmFyIGN1cnJlbnRFbnRyaWVzID0gW107XG4gICAgICAgIHZhciBjdXJyZW50UmVzcG9uc2UgPSBudWxsO1xuXG4gICAgICAgIGlmICghTnVtYmVyLmlzRmluaXRlKGN1cnJlbnRQZXJpb2QpKSB7XG4gICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gMzYwMDtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGxldmVsTmFtZShsZXZlbCkge1xuICAgICAgICAgICAgdmFyIGxldmVscyA9IHtcbiAgICAgICAgICAgICAgICBkZWJ1ZzogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxEZWJ1ZycsICdEZWJ1ZyddLFxuICAgICAgICAgICAgICAgIGluZm86IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsSW5mbycsICdJbmZvJ10sXG4gICAgICAgICAgICAgICAgbm90aWNlOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbE5vdGljZScsICdOb3RpY2UnXSxcbiAgICAgICAgICAgICAgICB3YXJuaW5nOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbFdhcm5pbmcnLCAnV2FybmluZyddLFxuICAgICAgICAgICAgICAgIGVycm9yOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbEVycm9yJywgJ0Vycm9yJ11cbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICB2YXIgaXRlbSA9IGxldmVsc1tsZXZlbF0gfHwgbGV2ZWxzLmluZm87XG5cbiAgICAgICAgICAgIHJldHVybiB0cmFuc2xhdGlvbihpdGVtWzBdLCBpdGVtWzFdKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGNvbXBvbmVudE5hbWUoY29tcG9uZW50KSB7XG4gICAgICAgICAgICB2YXIgYWxpYXNlcyA9IHtcbiAgICAgICAgICAgICAgICBhcGk6ICdhcGknLFxuICAgICAgICAgICAgICAgIHF1ZXVlOiAncXVldWUnLFxuICAgICAgICAgICAgICAgIHNjYW5uZXI6ICdzY2FubmVyJyxcbiAgICAgICAgICAgICAgICB3b3JrZXI6ICd3b3JrZXInLFxuICAgICAgICAgICAgICAgIHdvcmtlcmxvY2Fsc3BlZWNodG90ZXh0am9iczogJ3dvcmtlcicsXG4gICAgICAgICAgICAgICAgc2V0dGluZ3M6ICdzZXR0aW5ncycsXG4gICAgICAgICAgICAgICAgd2ViX3VpOiAnc2V0dGluZ3MnLFxuICAgICAgICAgICAgICAgIHNldHVwOiAnc2V0dXAnLFxuICAgICAgICAgICAgICAgIHN0b3JhZ2U6ICdzdG9yYWdlJyxcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0OiAndHJhbnNjcmlwdCcsXG4gICAgICAgICAgICAgICAgc3lzdGVtOiAnc3lzdGVtJyxcbiAgICAgICAgICAgICAgICBsb2NhbHNwZWVjaHRvdGV4dG1haW46ICdzeXN0ZW0nLFxuICAgICAgICAgICAgICAgIGxvZ2dlcjogJ3N5c3RlbSdcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICB2YXIgbGFiZWxzID0ge1xuICAgICAgICAgICAgICAgIGFwaTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50QXBpJywgJ0FQSSddLFxuICAgICAgICAgICAgICAgIHF1ZXVlOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRRdWV1ZScsICdRdWV1ZSddLFxuICAgICAgICAgICAgICAgIHNjYW5uZXI6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNjYW5uZXInLCAnUmVjb3JkaW5nIHNjYW5uZXInXSxcbiAgICAgICAgICAgICAgICB3b3JrZXI6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFdvcmtlcicsICdXb3JrZXInXSxcbiAgICAgICAgICAgICAgICBzZXR0aW5nczogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U2V0dGluZ3MnLCAnU2V0dGluZ3MnXSxcbiAgICAgICAgICAgICAgICBzZXR1cDogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U2V0dXAnLCAnU2V0dXAnXSxcbiAgICAgICAgICAgICAgICBzdG9yYWdlOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTdG9yYWdlJywgJ1N0b3JhZ2UnXSxcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0OiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRUcmFuc2NyaXB0JywgJ1RyYW5zY3JpcHRzJ10sXG4gICAgICAgICAgICAgICAgc3lzdGVtOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTeXN0ZW0nLCAnU3lzdGVtJ10sXG4gICAgICAgICAgICAgICAgb3RoZXI6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudE90aGVyJywgJ090aGVyJ11cbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICB2YXIgbGFiZWwgPSBsYWJlbHNbYWxpYXNlc1tjb21wb25lbnRdIHx8ICdvdGhlciddO1xuXG4gICAgICAgICAgICByZXR1cm4gdHJhbnNsYXRpb24obGFiZWxbMF0sIGxhYmVsWzFdKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGZvcm1hdFRpbWVzdGFtcCh0aW1lc3RhbXApIHtcbiAgICAgICAgICAgIHZhciBkYXRlID0gbmV3IERhdGUodGltZXN0YW1wKTtcbiAgICAgICAgICAgIGlmIChOdW1iZXIuaXNOYU4oZGF0ZS5nZXRUaW1lKCkpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRpbWVzdGFtcCB8fCAn4oCUJztcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgcmV0dXJuIGRhdGUudG9Mb2NhbGVTdHJpbmcoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlclN0YXRlKGtpbmQsIG1lc3NhZ2UpIHtcbiAgICAgICAgICAgIGlmICghbWVzc2FnZSkge1xuICAgICAgICAgICAgICAgICRzdGF0ZS5wcm9wKCdoaWRkZW4nLCB0cnVlKS5yZW1vdmVDbGFzcygnaW5mbyB3YXJuaW5nIGVycm9yJykudGV4dCgnJyk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAkc3RhdGVcbiAgICAgICAgICAgICAgICAucmVtb3ZlQ2xhc3MoJ2luZm8gd2FybmluZyBlcnJvcicpXG4gICAgICAgICAgICAgICAgLmFkZENsYXNzKGtpbmQgfHwgJ2luZm8nKVxuICAgICAgICAgICAgICAgIC50ZXh0KG1lc3NhZ2UpXG4gICAgICAgICAgICAgICAgLnByb3AoJ2hpZGRlbicsIGZhbHNlKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGZpbHRlckVudHJpZXMoZW50cmllcykge1xuICAgICAgICAgICAgdmFyIGxldmVsID0gU3RyaW5nKCRsZXZlbEZpbHRlci52YWwoKSB8fCAnJyk7XG4gICAgICAgICAgICB2YXIgY29tcG9uZW50ID0gU3RyaW5nKCRjb21wb25lbnRGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIHF1ZXJ5ID0gU3RyaW5nKCRzZWFyY2gudmFsKCkgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpO1xuXG4gICAgICAgICAgICByZXR1cm4gZW50cmllcy5maWx0ZXIoZnVuY3Rpb24gKGVudHJ5KSB7XG4gICAgICAgICAgICAgICAgaWYgKGxldmVsICYmIGVudHJ5LmxldmVsICE9PSBsZXZlbCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmIChjb21wb25lbnQgJiYgZW50cnkuY29tcG9uZW50ICE9PSBjb21wb25lbnQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoIXF1ZXJ5KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgIHZhciBoYXlzdGFjayA9IFtcbiAgICAgICAgICAgICAgICAgICAgZW50cnkubWVzc2FnZSxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuY29tcG9uZW50LFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5ldmVudCxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuc2V2ZXJpdHksXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LnBpZCxcbiAgICAgICAgICAgICAgICAgICAgSlNPTi5zdHJpbmdpZnkoZW50cnkuY29udGV4dCB8fCB7fSlcbiAgICAgICAgICAgICAgICBdLmpvaW4oJyAnKS50b0xvd2VyQ2FzZSgpO1xuXG4gICAgICAgICAgICAgICAgcmV0dXJuIGhheXN0YWNrLmluZGV4T2YocXVlcnkpICE9PSAtMTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyRW50cmllcyhlbnRyaWVzKSB7XG4gICAgICAgICAgICAkcmVzdWx0cy5lbXB0eSgpO1xuICAgICAgICAgICAgZW50cmllcy5mb3JFYWNoKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIHZhciAkY2FyZCA9ICQoJzxhcnRpY2xlPicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5IHN0dC1sb2ctbGV2ZWwtJyArIGVudHJ5LmxldmVsKTtcbiAgICAgICAgICAgICAgICB2YXIgJGhlYWRlciA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZW50cnktaGVhZGVyJyk7XG4gICAgICAgICAgICAgICAgdmFyICRpZGVudGl0eSA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZW50cnktaWRlbnRpdHknKTtcbiAgICAgICAgICAgICAgICB2YXIgJGxldmVsID0gJCgnPHNwYW4+JykuYWRkQ2xhc3MoJ3VpIHRpbnkgbGFiZWwgc3R0LWxvZy1sZXZlbC1sYWJlbCcpLnRleHQobGV2ZWxOYW1lKGVudHJ5LmxldmVsKSk7XG4gICAgICAgICAgICAgICAgdmFyICRjb21wb25lbnQgPSAkKCc8c3Bhbj4nKS5hZGRDbGFzcygnc3R0LWxvZy1jb21wb25lbnQnKS50ZXh0KGNvbXBvbmVudE5hbWUoZW50cnkuY29tcG9uZW50KSk7XG4gICAgICAgICAgICAgICAgdmFyICR0aW1lID0gJCgnPHRpbWU+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctdGltZScpLnRleHQoZm9ybWF0VGltZXN0YW1wKGVudHJ5LnRzKSk7XG4gICAgICAgICAgICAgICAgdmFyICRtZXNzYWdlID0gJCgnPGRpdj4nKS5hZGRDbGFzcygnc3R0LWxvZy1tZXNzYWdlJykudGV4dChlbnRyeS5tZXNzYWdlIHx8ICfigJQnKTtcbiAgICAgICAgICAgICAgICB2YXIgJGRldGFpbHMgPSAkKCc8ZGV0YWlscz4nKS5hZGRDbGFzcygnc3R0LWxvZy1kZXRhaWxzJyk7XG4gICAgICAgICAgICAgICAgdmFyICRzdW1tYXJ5ID0gJCgnPHN1bW1hcnk+JykudGV4dCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVGVjaG5pY2FsRGV0YWlscycsXG4gICAgICAgICAgICAgICAgICAgICdUZWNobmljYWwgZGV0YWlscydcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICB2YXIgZGV0YWlsVGV4dCA9IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0V2ZW50JywgJ0V2ZW50JylcbiAgICAgICAgICAgICAgICAgICAgKyAnOiAnICsgKGVudHJ5LmV2ZW50IHx8ICfigJQnKTtcbiAgICAgICAgICAgICAgICBkZXRhaWxUZXh0ICs9ICdcXG5zZXZlcml0eTogJyArIChlbnRyeS5zZXZlcml0eSB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxucGlkOiAnICsgKGVudHJ5LnBpZCA9PT0gdW5kZWZpbmVkID8gJ+KAlCcgOiBlbnRyeS5waWQpO1xuICAgICAgICAgICAgICAgIGlmIChlbnRyeS5jb250ZXh0ICYmIE9iamVjdC5rZXlzKGVudHJ5LmNvbnRleHQpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxuJyArIEpTT04uc3RyaW5naWZ5KGVudHJ5LmNvbnRleHQsIG51bGwsIDIpO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgICRpZGVudGl0eS5hcHBlbmQoJGxldmVsLCAkY29tcG9uZW50KTtcbiAgICAgICAgICAgICAgICAkaGVhZGVyLmFwcGVuZCgkaWRlbnRpdHksICR0aW1lKTtcbiAgICAgICAgICAgICAgICAkZGV0YWlscy5hcHBlbmQoJHN1bW1hcnksICQoJzxwcmU+JykudGV4dChkZXRhaWxUZXh0KSk7XG4gICAgICAgICAgICAgICAgJGNhcmQuYXBwZW5kKCRoZWFkZXIsICRtZXNzYWdlLCAkZGV0YWlscyk7XG4gICAgICAgICAgICAgICAgJHJlc3VsdHMuYXBwZW5kKCRjYXJkKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlQ29tcG9uZW50T3B0aW9ucyhlbnRyaWVzKSB7XG4gICAgICAgICAgICB2YXIgc2VsZWN0ZWQgPSBTdHJpbmcoJGNvbXBvbmVudEZpbHRlci52YWwoKSB8fCAnJyk7XG4gICAgICAgICAgICB2YXIgY29tcG9uZW50cyA9IFtdO1xuICAgICAgICAgICAgZW50cmllcy5mb3JFYWNoKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIGlmIChlbnRyeS5jb21wb25lbnQgJiYgY29tcG9uZW50cy5pbmRleE9mKGVudHJ5LmNvbXBvbmVudCkgPT09IC0xKSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbXBvbmVudHMucHVzaChlbnRyeS5jb21wb25lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgY29tcG9uZW50cy5zb3J0KGZ1bmN0aW9uIChsZWZ0LCByaWdodCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBjb21wb25lbnROYW1lKGxlZnQpLmxvY2FsZUNvbXBhcmUoY29tcG9uZW50TmFtZShyaWdodCkpO1xuICAgICAgICAgICAgfSk7XG5cbiAgICAgICAgICAgICRjb21wb25lbnRGaWx0ZXIuZW1wdHkoKS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPG9wdGlvbj4nKS52YWwoJycpLnRleHQodHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0NvbXBvbmVudEFsbCcsXG4gICAgICAgICAgICAgICAgICAgICdBbGwgY29tcG9uZW50cydcbiAgICAgICAgICAgICAgICApKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIGNvbXBvbmVudHMuZm9yRWFjaChmdW5jdGlvbiAoY29tcG9uZW50KSB7XG4gICAgICAgICAgICAgICAgJGNvbXBvbmVudEZpbHRlci5hcHBlbmQoJCgnPG9wdGlvbj4nKS52YWwoY29tcG9uZW50KS50ZXh0KGNvbXBvbmVudE5hbWUoY29tcG9uZW50KSkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBpZiAoc2VsZWN0ZWQgJiYgY29tcG9uZW50cy5pbmRleE9mKHNlbGVjdGVkKSAhPT0gLTEpIHtcbiAgICAgICAgICAgICAgICAkY29tcG9uZW50RmlsdGVyLnZhbChzZWxlY3RlZCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVNZXRhKGNvdW50LCByZXNwb25zZSkge1xuICAgICAgICAgICAgdmFyIGZvdW5kID0gdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRm91bmQnLCAnJWNvdW50JSBldmVudHMnKVxuICAgICAgICAgICAgICAgIC5yZXBsYWNlKCclY291bnQlJywgY291bnQpO1xuICAgICAgICAgICAgdmFyIHVwZGF0ZWQgPSB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NVcGRhdGVkJywgJ1VwZGF0ZWQ6ICV0aW1lJScpXG4gICAgICAgICAgICAgICAgLnJlcGxhY2UoJyV0aW1lJScsIHJlc3BvbnNlICYmIHJlc3BvbnNlLmdlbmVyYXRlZEF0XG4gICAgICAgICAgICAgICAgICAgID8gcmVzcG9uc2UuZ2VuZXJhdGVkQXRcbiAgICAgICAgICAgICAgICAgICAgOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NOZXZlcicsICduZXZlcicpKTtcbiAgICAgICAgICAgICRyZXN1bHRDb3VudC50ZXh0KGZvdW5kKTtcbiAgICAgICAgICAgICR1cGRhdGVkQXQudGV4dCh1cGRhdGVkKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlckN1cnJlbnQoKSB7XG4gICAgICAgICAgICBpZiAoIWN1cnJlbnRSZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHZhciBmaWx0ZXJlZCA9IGZpbHRlckVudHJpZXMoY3VycmVudEVudHJpZXMpO1xuICAgICAgICAgICAgcmVuZGVyRW50cmllcyhmaWx0ZXJlZCk7XG4gICAgICAgICAgICB1cGRhdGVNZXRhKGZpbHRlcmVkLmxlbmd0aCwgY3VycmVudFJlc3BvbnNlKTtcblxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdtaXNzaW5nJykge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc01pc3NpbmcnLFxuICAgICAgICAgICAgICAgICAgICAnVGhlIG1vZHVsZSBqb3VybmFsIGhhcyBub3QgYmVlbiBjcmVhdGVkIHlldC4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICd1bmF2YWlsYWJsZScpIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnZXJyb3InLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVW5hdmFpbGFibGUnLFxuICAgICAgICAgICAgICAgICAgICAnVGhlIG1vZHVsZSBqb3VybmFsIGlzIHVuYXZhaWxhYmxlLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnNvdXJjZVN0YXR1cyA9PT0gJ2NvcnJ1cHQnKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0NvcnJ1cHQnLFxuICAgICAgICAgICAgICAgICAgICAnVGhlIGpvdXJuYWwgY29udGFpbnMgbm8gcmVhZGFibGUgZXZlbnRzLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudEVudHJpZXMubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2luZm8nLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRW1wdHknLFxuICAgICAgICAgICAgICAgICAgICAnTm8gbW9kdWxlIGV2ZW50cyB3ZXJlIGZvdW5kIGZvciB0aGlzIHBlcmlvZC4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGZpbHRlcmVkLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0ZpbHRlcmVkRW1wdHknLFxuICAgICAgICAgICAgICAgICAgICAnTm8gZXZlbnRzIG1hdGNoIHRoZSBzZWxlY3RlZCBmaWx0ZXJzLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHZhciBub3RpY2VzID0gW107XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnNvdXJjZVN0YXR1cyA9PT0gJ3BhcnRpYWwnKSB7XG4gICAgICAgICAgICAgICAgbm90aWNlcy5wdXNoKHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NQYXJ0aWFsJyxcbiAgICAgICAgICAgICAgICAgICAgJyVjb3VudCUgZGFtYWdlZCBqb3VybmFsIGxpbmVzIHdlcmUgc2tpcHBlZC4nXG4gICAgICAgICAgICAgICAgKS5yZXBsYWNlKCclY291bnQlJywgY3VycmVudFJlc3BvbnNlLm1hbGZvcm1lZENvdW50IHx8IDApKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2UudHJ1bmNhdGVkKSB7XG4gICAgICAgICAgICAgICAgbm90aWNlcy5wdXNoKHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NUcnVuY2F0ZWQnLFxuICAgICAgICAgICAgICAgICAgICAnT25seSB0aGUgbmV3ZXN0IDEwMDAgZXZlbnRzIGFyZSBzaG93bi4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZW5kZXJTdGF0ZShub3RpY2VzLmxlbmd0aCA+IDAgPyAnd2FybmluZycgOiAnJywgbm90aWNlcy5qb2luKCcgJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2V0TG9hZGluZygpIHtcbiAgICAgICAgICAgICRzZWdtZW50LmFkZENsYXNzKCdzdHQtbG9ncy1sb2FkaW5nJyk7XG4gICAgICAgICAgICAkcmVzdWx0cy5lbXB0eSgpO1xuICAgICAgICAgICAgdXBkYXRlTWV0YSgwLCBudWxsKTtcbiAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzTG9hZGluZycsXG4gICAgICAgICAgICAgICAgJ0xvYWRpbmcgbW9kdWxlIGV2ZW50cy4uLidcbiAgICAgICAgICAgICkpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbG9hZExvZ3MoZm9yY2UpIHtcbiAgICAgICAgICAgIGlmICghZm9yY2UgJiYgbG9hZGVkT25jZSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICghZGF0YVVybCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlcXVlc3RHZW5lcmF0aW9uICs9IDE7XG4gICAgICAgICAgICB2YXIgcmVxdWVzdElkID0gcmVxdWVzdEdlbmVyYXRpb247XG4gICAgICAgICAgICBpZiAocmVxdWVzdCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHJlcXVlc3QuYWJvcnQoKTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgc2V0TG9hZGluZygpO1xuICAgICAgICAgICAgJHJlZnJlc2hCdXR0b24uYWRkQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQnKTtcbiAgICAgICAgICAgIHZhciBjdXJyZW50UmVxdWVzdCA9ICQuYWpheCh7XG4gICAgICAgICAgICAgICAgdXJsOiBkYXRhVXJsLFxuICAgICAgICAgICAgICAgIG1ldGhvZDogJ0dFVCcsXG4gICAgICAgICAgICAgICAgZGF0YVR5cGU6ICdqc29uJyxcbiAgICAgICAgICAgICAgICBkYXRhOiB7XG4gICAgICAgICAgICAgICAgICAgIHBlcmlvZDogY3VycmVudFBlcmlvZFxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgaWYgKHJlcXVlc3RJZCAhPT0gcmVxdWVzdEdlbmVyYXRpb24pIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjdXJyZW50UmVzcG9uc2UgPSByZXNwb25zZSB8fCB7fTtcbiAgICAgICAgICAgICAgICBjdXJyZW50RW50cmllcyA9IEFycmF5LmlzQXJyYXkoY3VycmVudFJlc3BvbnNlLmVudHJpZXMpID8gY3VycmVudFJlc3BvbnNlLmVudHJpZXMgOiBbXTtcbiAgICAgICAgICAgICAgICB1cGRhdGVDb21wb25lbnRPcHRpb25zKGN1cnJlbnRFbnRyaWVzKTtcbiAgICAgICAgICAgICAgICByZW5kZXJDdXJyZW50KCk7XG4gICAgICAgICAgICAgICAgbG9hZGVkT25jZSA9IHRydWU7XG4gICAgICAgICAgICB9KS5mYWlsKGZ1bmN0aW9uICh4aHIsIHN0YXR1cykge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uIHx8IHN0YXR1cyA9PT0gJ2Fib3J0Jykge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGN1cnJlbnRSZXNwb25zZSA9IG51bGw7XG4gICAgICAgICAgICAgICAgY3VycmVudEVudHJpZXMgPSBbXTtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnZXJyb3InLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRXJyb3InLFxuICAgICAgICAgICAgICAgICAgICAnVW5hYmxlIHRvIGxvYWQgbW9kdWxlIGV2ZW50cy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICB9KS5hbHdheXMoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKHJlcXVlc3QgPT09IGN1cnJlbnRSZXF1ZXN0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJlcXVlc3QgPSBudWxsO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAkc2VnbWVudC5yZW1vdmVDbGFzcygnc3R0LWxvZ3MtbG9hZGluZycpO1xuICAgICAgICAgICAgICAgICRyZWZyZXNoQnV0dG9uLnJlbW92ZUNsYXNzKCdsb2FkaW5nIGRpc2FibGVkJyk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIHJlcXVlc3QgPSBjdXJyZW50UmVxdWVzdDtcbiAgICAgICAgfVxuXG4gICAgICAgICRwZXJpb2RCdXR0b25zLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciAkYnV0dG9uID0gJCh0aGlzKTtcbiAgICAgICAgICAgIGN1cnJlbnRQZXJpb2QgPSBwYXJzZUludCgkYnV0dG9uLmRhdGEoJ3BlcmlvZCcpLCAxMCk7XG4gICAgICAgICAgICBpZiAoIU51bWJlci5pc0Zpbml0ZShjdXJyZW50UGVyaW9kKSkge1xuICAgICAgICAgICAgICAgIGN1cnJlbnRQZXJpb2QgPSAzNjAwO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJHBlcmlvZEJ1dHRvbnMucmVtb3ZlQ2xhc3MoJ2FjdGl2ZScpO1xuICAgICAgICAgICAgJGJ1dHRvbi5hZGRDbGFzcygnYWN0aXZlJyk7XG4gICAgICAgICAgICBsb2FkZWRPbmNlID0gZmFsc2U7XG4gICAgICAgICAgICBsb2FkTG9ncyh0cnVlKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJHJlZnJlc2hCdXR0b24ub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgbG9hZExvZ3ModHJ1ZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRsZXZlbEZpbHRlci5hZGQoJGNvbXBvbmVudEZpbHRlcikub24oJ2NoYW5nZScsIHJlbmRlckN1cnJlbnQpO1xuICAgICAgICAkc2VhcmNoLm9uKCdpbnB1dCcsIHJlbmRlckN1cnJlbnQpO1xuXG4gICAgICAgICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbWVudSAuaXRlbVtkYXRhLXRhYj1cImxvZ3NcIl0nKS5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB3aW5kb3cuc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgbG9hZExvZ3MoZmFsc2UpO1xuICAgICAgICAgICAgfSwgMCk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHVwZGF0ZU1ldGEoMCwgbnVsbCk7XG4gICAgICAgIGlmIChyZXF1ZXN0ZWRUYWIoKSA9PT0gJ2xvZ3MnIHx8ICRmb3JtLmNsb3Nlc3QoJy50YWIuc2VnbWVudCcpLmhhc0NsYXNzKCdhY3RpdmUnKSkge1xuICAgICAgICAgICAgbG9hZExvZ3MoZmFsc2UpO1xuICAgICAgICB9XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZUNkckhpc3RvcnlTbGlkZXIoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgJHNsaWRlciA9ICQoJyNzdHQtY2RyLWhpc3RvcnktZGF5cy1zbGlkZXInKTtcbiAgICAgICAgdmFyICRpbnB1dCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cImNkcl9oaXN0b3J5X2RheXNcIl0nKTtcblxuICAgICAgICBpZiAoJHNsaWRlci5sZW5ndGggPT09IDAgfHwgJGlucHV0Lmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJHNsaWRlci5zbGlkZXIgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciB2YWx1ZXMgPSBbJzEnLCAnNycsICczMCcsICc5MCcsICcxODAnLCAnMzY1JywgJzAnXTtcbiAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgIDA6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTFEYXknLCAnMSBkYXknKSxcbiAgICAgICAgICAgIDE6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTdEYXlzJywgJzcgZGF5cycpLFxuICAgICAgICAgICAgMjogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MzBEYXlzJywgJzMwIGRheXMnKSxcbiAgICAgICAgICAgIDM6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTkwRGF5cycsICc5MCBkYXlzJyksXG4gICAgICAgICAgICA0OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkxODBEYXlzJywgJzYgbW9udGhzJyksXG4gICAgICAgICAgICA1OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkzNjVEYXlzJywgJzEgeWVhcicpLFxuICAgICAgICAgICAgNjogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5QWxsJywgJ0FsbCcpXG4gICAgICAgIH07XG4gICAgICAgIHZhciBjdXJyZW50VmFsdWUgPSBTdHJpbmcoJGlucHV0LnZhbCgpIHx8ICczMCcpO1xuICAgICAgICB2YXIgaW5pdGlhbEluZGV4ID0gdmFsdWVzLmluZGV4T2YoY3VycmVudFZhbHVlKTtcbiAgICAgICAgdmFyIGluaXRpYWxpemVkID0gZmFsc2U7XG5cbiAgICAgICAgaWYgKGluaXRpYWxJbmRleCA8IDApIHtcbiAgICAgICAgICAgIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKCczMCcpO1xuICAgICAgICAgICAgJGlucHV0LnZhbCgnMzAnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzbGlkZXIuc2xpZGVyKHtcbiAgICAgICAgICAgIG1pbjogMCxcbiAgICAgICAgICAgIG1heDogdmFsdWVzLmxlbmd0aCAtIDEsXG4gICAgICAgICAgICBzdGVwOiAxLFxuICAgICAgICAgICAgc21vb3RoOiB0cnVlLFxuICAgICAgICAgICAgYXV0b0FkanVzdExhYmVsczogZmFsc2UsXG4gICAgICAgICAgICBpbnRlcnByZXRMYWJlbDogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGxhYmVsc1t2YWx1ZV0gfHwgJyc7XG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgb25DaGFuZ2U6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBzZWxlY3RlZCA9IHZhbHVlc1t2YWx1ZV0gfHwgJzMwJztcblxuICAgICAgICAgICAgICAgICRpbnB1dC52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgICAgIGlmIChpbml0aWFsaXplZCkge1xuICAgICAgICAgICAgICAgICAgICAkaW5wdXQudHJpZ2dlcignY2hhbmdlJyk7XG4gICAgICAgICAgICAgICAgICAgIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmIHR5cGVvZiBGb3JtLmNoZWNrVmFsdWVzID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBGb3JtLmNoZWNrVmFsdWVzKCk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkc2xpZGVyLnNsaWRlcignc2V0IHZhbHVlJywgaW5pdGlhbEluZGV4LCBmYWxzZSk7XG4gICAgICAgIGluaXRpYWxpemVkID0gdHJ1ZTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdFJldGVudGlvblNsaWRlcigkc2V0dGluZ3NGb3JtKSB7XG4gICAgICAgIHZhciAkc2xpZGVyID0gJCgnI3N0dC10cmFuc2NyaXB0LXJldGVudGlvbi1kYXlzLXNsaWRlcicpO1xuICAgICAgICB2YXIgJGlucHV0ID0gJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5c1wiXScpO1xuXG4gICAgICAgIGlmICgkc2xpZGVyLmxlbmd0aCA9PT0gMCB8fCAkaW5wdXQubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkc2xpZGVyLnNsaWRlciAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIHZhbHVlcyA9IFsnMzAnLCAnOTAnLCAnMTgwJywgJzM2NScsICcwJ107XG4gICAgICAgIHZhciBsYWJlbHMgPSB7XG4gICAgICAgICAgICAwOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24zMERheXMnLCAnMzAgZGF5cycpLFxuICAgICAgICAgICAgMTogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uOTBEYXlzJywgJzkwIGRheXMnKSxcbiAgICAgICAgICAgIDI6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjE4MERheXMnLCAnMTgwIGRheXMnKSxcbiAgICAgICAgICAgIDM6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjM2NURheXMnLCAnMSB5ZWFyJyksXG4gICAgICAgICAgICA0OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25VbmxpbWl0ZWQnLCAnVW5saW1pdGVkJylcbiAgICAgICAgfTtcbiAgICAgICAgdmFyIGN1cnJlbnRWYWx1ZSA9IFN0cmluZygkaW5wdXQudmFsKCkgfHwgJzM2NScpO1xuICAgICAgICB2YXIgaW5pdGlhbEluZGV4ID0gdmFsdWVzLmluZGV4T2YoY3VycmVudFZhbHVlKTtcbiAgICAgICAgdmFyIGluaXRpYWxpemVkID0gZmFsc2U7XG5cbiAgICAgICAgaWYgKGluaXRpYWxJbmRleCA8IDApIHtcbiAgICAgICAgICAgIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKCczNjUnKTtcbiAgICAgICAgICAgICRpbnB1dC52YWwoJzM2NScpO1xuICAgICAgICB9XG5cbiAgICAgICAgJHNsaWRlci5zbGlkZXIoe1xuICAgICAgICAgICAgbWluOiAwLFxuICAgICAgICAgICAgbWF4OiB2YWx1ZXMubGVuZ3RoIC0gMSxcbiAgICAgICAgICAgIHN0ZXA6IDEsXG4gICAgICAgICAgICBzbW9vdGg6IHRydWUsXG4gICAgICAgICAgICBhdXRvQWRqdXN0TGFiZWxzOiBmYWxzZSxcbiAgICAgICAgICAgIGludGVycHJldExhYmVsOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gbGFiZWxzW3ZhbHVlXSB8fCAnJztcbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBvbkNoYW5nZTogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdmFyIHNlbGVjdGVkID0gdmFsdWVzW3ZhbHVlXSB8fCAnMzY1JztcblxuICAgICAgICAgICAgICAgICRpbnB1dC52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgICAgIGlmIChpbml0aWFsaXplZCkge1xuICAgICAgICAgICAgICAgICAgICAkaW5wdXQudHJpZ2dlcignY2hhbmdlJyk7XG4gICAgICAgICAgICAgICAgICAgIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmIHR5cGVvZiBGb3JtLmNoZWNrVmFsdWVzID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBGb3JtLmNoZWNrVmFsdWVzKCk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkc2xpZGVyLnNsaWRlcignc2V0IHZhbHVlJywgaW5pdGlhbEluZGV4LCBmYWxzZSk7XG4gICAgICAgIGluaXRpYWxpemVkID0gdHJ1ZTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiByZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nKHByb2Nlc3NpbmdEYXlzLCByZXRlbnRpb25EYXlzKSB7XG4gICAgICAgIHJldHVybiByZXRlbnRpb25EYXlzID09PSAwIHx8IChwcm9jZXNzaW5nRGF5cyA+IDAgJiYgcHJvY2Vzc2luZ0RheXMgPD0gcmV0ZW50aW9uRGF5cyk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgcHJvY2Vzc2luZ0RheXMgPSBOdW1iZXIoJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiY2RyX2hpc3RvcnlfZGF5c1wiXScpLnZhbCgpKTtcbiAgICAgICAgdmFyIHJldGVudGlvbkRheXMgPSBOdW1iZXIoJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5c1wiXScpLnZhbCgpKTtcbiAgICAgICAgdmFyIGNvbXBhdGlibGUgPSBOdW1iZXIuaXNGaW5pdGUocHJvY2Vzc2luZ0RheXMpXG4gICAgICAgICAgICAmJiBOdW1iZXIuaXNGaW5pdGUocmV0ZW50aW9uRGF5cylcbiAgICAgICAgICAgICYmIHJldGVudGlvbkFsbG93c1Byb2Nlc3NpbmcocHJvY2Vzc2luZ0RheXMsIHJldGVudGlvbkRheXMpO1xuICAgICAgICB2YXIgJG1lc3NhZ2UgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJy5zdHQtcmV0ZW50aW9uLWNvbmZsaWN0LW1lc3NhZ2UnKTtcblxuICAgICAgICAkbWVzc2FnZS50b2dnbGVDbGFzcygnaGlkZGVuJywgY29tcGF0aWJsZSkuYXR0cignYXJpYS1oaWRkZW4nLCBjb21wYXRpYmxlID8gJ3RydWUnIDogJ2ZhbHNlJyk7XG5cbiAgICAgICAgcmV0dXJuIGNvbXBhdGlibGU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZU1vZGVsTWFya2V0cGxhY2UoJG1vZGVsRm9ybSkge1xuICAgICAgICB2YXIgJHNhdmVCdXR0b24gPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1vZGVsLXNhdmUnKTtcbiAgICAgICAgdmFyICRtb2RlbElucHV0cyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1tb2RlbC1tYXJrZXRwbGFjZS10YWJsZSBpbnB1dFt0eXBlPVwicmFkaW9cIl1bZGF0YS1wbGF0Zm9ybV0nKTtcbiAgICAgICAgdmFyICRjb21wYXRpYmlsaXR5TWVzc2FnZSA9ICQoJyNzdHQtbW9kZWwtbGFuZ3VhZ2UtY29tcGF0aWJpbGl0eScpO1xuICAgICAgICB2YXIgc2VsZWN0ZWRMYW5ndWFnZSA9IFN0cmluZygkbW9kZWxGb3JtLmRhdGEoJ3NlbGVjdGVkLWxhbmd1YWdlJykgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpO1xuICAgICAgICB2YXIgJHBsYXRmb3JtQnV0dG9ucyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1tb2RlbC1wbGF0Zm9ybS1zd2l0Y2ggW2RhdGEtcGxhdGZvcm1dJyk7XG4gICAgICAgIC8vIEV2ZXJ5IHBsYXRmb3JtIGtlZXBzIGl0cyBvd24gbW9kZWw7IFNhdmUgc3RvcmVzIHRoZSBwbGF0Zm9ybXMgd2hvc2UgY2hvaWNlIGNoYW5nZWQuXG4gICAgICAgIHZhciBpbml0aWFsTW9kZWxzID0ge307XG5cbiAgICAgICAgJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGluaXRpYWxNb2RlbHNbU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKV0gPSBTdHJpbmcoJCh0aGlzKS52YWwoKSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIGZ1bmN0aW9uIGNoYW5nZWRJbnB1dHMoKSB7XG4gICAgICAgICAgICByZXR1cm4gJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKS5maWx0ZXIoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBpbml0aWFsTW9kZWxzW1N0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSldICE9PSBTdHJpbmcoJCh0aGlzKS52YWwoKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIG1vZGVsU3VwcG9ydHNMYW5ndWFnZSgkaW5wdXQpIHtcbiAgICAgICAgICAgIHZhciBzdXBwb3J0ZWRMYW5ndWFnZXM7XG5cbiAgICAgICAgICAgIGlmIChzZWxlY3RlZExhbmd1YWdlID09PSAnJyB8fCBzZWxlY3RlZExhbmd1YWdlID09PSAnYXV0bycpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgc3VwcG9ydGVkTGFuZ3VhZ2VzID0gU3RyaW5nKCRpbnB1dC5hdHRyKCdkYXRhLXN1cHBvcnRlZC1sYW5ndWFnZXMnKSB8fCAnJylcbiAgICAgICAgICAgICAgICAuc3BsaXQoJywnKVxuICAgICAgICAgICAgICAgIC5tYXAoZnVuY3Rpb24gKGxhbmd1YWdlKSB7IHJldHVybiBsYW5ndWFnZS50cmltKCkudG9Mb3dlckNhc2UoKTsgfSlcbiAgICAgICAgICAgICAgICAuZmlsdGVyKGZ1bmN0aW9uIChsYW5ndWFnZSkgeyByZXR1cm4gbGFuZ3VhZ2UgIT09ICcnOyB9KTtcblxuICAgICAgICAgICAgcmV0dXJuIHN1cHBvcnRlZExhbmd1YWdlcy5pbmRleE9mKHNlbGVjdGVkTGFuZ3VhZ2UpICE9PSAtMTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNob3dQbGF0Zm9ybShwbGF0Zm9ybSkge1xuICAgICAgICAgICAgJHBsYXRmb3JtQnV0dG9ucy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgYWN0aXZlID0gU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKSA9PT0gcGxhdGZvcm07XG5cbiAgICAgICAgICAgICAgICAkKHRoaXMpLnRvZ2dsZUNsYXNzKCdhY3RpdmUnLCBhY3RpdmUpLmF0dHIoJ2FyaWEtcHJlc3NlZCcsIGFjdGl2ZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgdGJvZHkgdHInKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAkKHRoaXMpLnByb3AoJ2hpZGRlbicsIFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkgIT09IHBsYXRmb3JtKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlU2F2ZUJ1dHRvblN0YXRlKCkge1xuICAgICAgICAgICAgdmFyICRjaGFuZ2VkID0gY2hhbmdlZElucHV0cygpO1xuICAgICAgICAgICAgdmFyIGNvbXBhdGlibGUgPSB0cnVlO1xuICAgICAgICAgICAgdmFyIGhhc0NoYW5nZXM7XG5cbiAgICAgICAgICAgICRjaGFuZ2VkLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGNvbXBhdGlibGUgPSBjb21wYXRpYmxlICYmIG1vZGVsU3VwcG9ydHNMYW5ndWFnZSgkKHRoaXMpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGNvbXBhdGliaWxpdHlNZXNzYWdlXG4gICAgICAgICAgICAgICAgLnRvZ2dsZUNsYXNzKCdoaWRkZW4nLCBjb21wYXRpYmxlKVxuICAgICAgICAgICAgICAgIC5hdHRyKCdhcmlhLWhpZGRlbicsIGNvbXBhdGlibGUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcblxuICAgICAgICAgICAgaGFzQ2hhbmdlcyA9ICRjaGFuZ2VkLmxlbmd0aCA+IDAgJiYgY29tcGF0aWJsZTtcbiAgICAgICAgICAgICRzYXZlQnV0dG9uLnRvZ2dsZUNsYXNzKCdkaXNhYmxlZCcsICFoYXNDaGFuZ2VzKTtcbiAgICAgICAgICAgICRzYXZlQnV0dG9uLnByb3AoJ2Rpc2FibGVkJywgIWhhc0NoYW5nZXMpO1xuICAgICAgICB9XG5cbiAgICAgICAgaWYgKHR5cGVvZiAkLmZuLmFjY29yZGlvbiA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWFkdmFuY2VkLXNldHRpbmdzLWRyb3Bkb3duJykuYWNjb3JkaW9uKCk7XG4gICAgICAgIH1cblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgdGJvZHkgdHInKS5vbignY2xpY2snLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmICgkKGV2ZW50LnRhcmdldCkuY2xvc2VzdCgnYSwgYnV0dG9uLCBmb3JtLCBpbnB1dCwgbGFiZWwnKS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAkKHRoaXMpLmZpbmQoJy51aS5yYWRpby5jaGVja2JveCcpLmNoZWNrYm94KCdjaGVjaycpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkcGxhdGZvcm1CdXR0b25zLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNob3dQbGF0Zm9ybShTdHJpbmcoJCh0aGlzKS5hdHRyKCdkYXRhLXBsYXRmb3JtJykpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJG1vZGVsSW5wdXRzLm9uKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJHJvdyA9ICQodGhpcykuY2xvc2VzdCgndHInKTtcblxuICAgICAgICAgICAgJHJvdy5hZGRDbGFzcygncG9zaXRpdmUnKVxuICAgICAgICAgICAgICAgIC5zaWJsaW5ncygnW2RhdGEtcGxhdGZvcm09XCInICsgU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKSArICdcIl0nKVxuICAgICAgICAgICAgICAgIC5yZW1vdmVDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbW9kZWxGb3JtLm9uKCdzdWJtaXQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoJHNhdmVCdXR0b24ucHJvcCgnZGlzYWJsZWQnKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICgkc2F2ZUJ1dHRvbi5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgJHNhdmVCdXR0b24uYWRkQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQgYWN0aXZlJyk7XG4gICAgICAgICAgICAgICAgJHNhdmVCdXR0b24ucHJvcCgnZGlzYWJsZWQnLCB0cnVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG5cbiAgICAgICAgdXBkYXRlU2F2ZUJ1dHRvblN0YXRlKCk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCgpIHtcbiAgICAgICAgdmFyICRtb2RhbCA9ICQoJyNzdHQtY29uZmlybS1tb2RhbCcpO1xuXG4gICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkbW9kYWwubW9kYWwgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFByZS1pbml0aWFsaXplIHNvIFNlbWFudGljIFVJIG1vdmVzIHRoZSBlbGVtZW50IHRvIDxib2R5PiBhbmQgaGlkZXMgaXQgcHJvcGVybHlcbiAgICAgICAgJG1vZGFsLm1vZGFsKHt9KTtcblxuICAgICAgICBmdW5jdGlvbiBzaG93Q29uZmlybShtZXNzYWdlLCBvbkNvbmZpcm0pIHtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNvbmZpcm0tbWVzc2FnZScpLnRleHQobWVzc2FnZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uQXBwcm92ZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBvbkNvbmZpcm0oKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5tb2RhbCgnc2hvdycpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gYmluZENvbmZpcm1hYmxlRm9ybSgkZm9ybSkge1xuICAgICAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdC5zdHQtY29uZmlybScsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgICAgIHZhciBtZXNzYWdlID0gJCh0aGlzKS5kYXRhKCdjb25maXJtJykgfHwgJyc7XG5cbiAgICAgICAgICAgICAgICBpZiAobWVzc2FnZSA9PT0gJycpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgZXZlbnQuc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCk7XG5cbiAgICAgICAgICAgICAgICB2YXIgJHNlbGYgPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIHNob3dDb25maXJtKG1lc3NhZ2UsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgJHNlbGYub2ZmKCdzdWJtaXQuc3R0LWNvbmZpcm0nKS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWNvbmZpcm1hYmxlLWZvcm0nKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGJpbmRDb25maXJtYWJsZUZvcm0oJCh0aGlzKSk7XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIC8vIFRyYW5zY3JpcHQgZGVsZXRpb24gaXMgaXJyZXZlcnNpYmxlLCBzbyBpdCBuZWVkcyBhbiBleHBsaWNpdFxuICAgIC8vIGFja25vd2xlZGdlbWVudDogdGhlIGRpYWxvZyBsaXN0cyB3aGF0IGlzIGFuZCBpcyBub3QgcmVtb3ZlZCwgdGhlXG4gICAgLy8gRGVsZXRlIGJ1dHRvbiBzdGF5cyBkaXNhYmxlZCB1bnRpbCB0aGUgY2hlY2tib3ggaXMgdGlja2VkLCBhbmQgb25seSB0aGVuXG4gICAgLy8gaXMgY29uZmlybV9kZWxldGUgYWRkZWQgdG8gdGhlIGZvcm0gdGhlIHNlcnZlciByZXF1aXJlcyBpdCBvbi5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdERlbGV0aW9uKCkge1xuICAgICAgICB2YXIgJG1vZGFsID0gJCgnI3N0dC1kZWxldGUtdHJhbnNjcmlwdC1tb2RhbCcpO1xuICAgICAgICB2YXIgJGZvcm1zID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWRlbGV0ZS10cmFuc2NyaXB0LWZvcm0nKTtcblxuICAgICAgICBpZiAoJG1vZGFsLmxlbmd0aCA9PT0gMCB8fCAkZm9ybXMubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkbW9kYWwubW9kYWwgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciAkYWNrbm93bGVkZ2UgPSAkbW9kYWwuZmluZCgnI3N0dC1kZWxldGUtdHJhbnNjcmlwdC1hY2tub3dsZWRnZScpO1xuICAgICAgICB2YXIgJGFwcHJvdmUgPSAkbW9kYWwuZmluZCgnLmFwcHJvdmUuYnV0dG9uJyk7XG4gICAgICAgIHZhciBwZW5kaW5nRm9ybSA9IG51bGw7XG5cbiAgICAgICAgZnVuY3Rpb24gc2V0QXBwcm92ZUVuYWJsZWQoZW5hYmxlZCkge1xuICAgICAgICAgICAgJGFwcHJvdmUudG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWVuYWJsZWQpLnByb3AoJ2Rpc2FibGVkJywgIWVuYWJsZWQpO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gUHJlLWluaXRpYWxpemUgc28gU2VtYW50aWMgVUkgbW92ZXMgdGhlIGVsZW1lbnQgdG8gPGJvZHk+IGFuZCBoaWRlcyBpdCBwcm9wZXJseVxuICAgICAgICAkbW9kYWwubW9kYWwoe30pO1xuICAgICAgICAkYWNrbm93bGVkZ2Uub24oJ2NoYW5nZScsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNldEFwcHJvdmVFbmFibGVkKCRhY2tub3dsZWRnZS5wcm9wKCdjaGVja2VkJykpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkZm9ybXMub24oJ3N1Ym1pdC5zdHQtZGVsZXRlLXRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICBwZW5kaW5nRm9ybSA9IHRoaXM7XG4gICAgICAgICAgICAkKHRoaXMpLmNsb3Nlc3QoJ2RldGFpbHMnKS5wcm9wKCdvcGVuJywgZmFsc2UpO1xuICAgICAgICAgICAgJGFja25vd2xlZGdlLnByb3AoJ2NoZWNrZWQnLCBmYWxzZSk7XG4gICAgICAgICAgICBzZXRBcHByb3ZlRW5hYmxlZChmYWxzZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uQXBwcm92ZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoISRhY2tub3dsZWRnZS5wcm9wKCdjaGVja2VkJykgfHwgcGVuZGluZ0Zvcm0gPT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICB2YXIgZm9ybSA9IHBlbmRpbmdGb3JtO1xuICAgICAgICAgICAgICAgICAgICBwZW5kaW5nRm9ybSA9IG51bGw7XG4gICAgICAgICAgICAgICAgICAgICQoJzxpbnB1dD4nLCB7IHR5cGU6ICdoaWRkZW4nLCBuYW1lOiAnY29uZmlybV9kZWxldGUnLCB2YWx1ZTogJzEnIH0pLmFwcGVuZFRvKGZvcm0pO1xuICAgICAgICAgICAgICAgICAgICAvLyBUaGUgbmF0aXZlIHN1Ym1pdCBza2lwcyB0aGlzIGhhbmRsZXIsIHNvIHRoZSBkaWFsb2cgaXMgbm90IHNob3duIGFnYWluLlxuICAgICAgICAgICAgICAgICAgICBmb3JtLnN1Ym1pdCgpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgICAgIG9uSGlkZGVuOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHBlbmRpbmdGb3JtID0gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5tb2RhbCgnc2hvdycpO1xuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MoKSB7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC10cmFuc2NyaXB0LXJvdycpLm9uKCdjbGljaycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKCQoZXZlbnQudGFyZ2V0KS5jbG9zZXN0KCdhLCBidXR0b24sIGlucHV0LCBzZWxlY3QsIHRleHRhcmVhJykubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgdmFyIGRlc3RpbmF0aW9uID0gJCh0aGlzKS5kYXRhKCd0cmFuc2NyaXB0LXVybCcpO1xuICAgICAgICAgICAgaWYgKGRlc3RpbmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgd2luZG93LmxvY2F0aW9uLmhyZWYgPSBkZXN0aW5hdGlvbjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRBY3Rpb25NZW51KCkge1xuICAgICAgICB2YXIgJG1lbnVzID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycpO1xuXG4gICAgICAgICRtZW51cy5maW5kKCcubWVudSBhLCAubWVudSBidXR0b24nKS5vbignY2xpY2suc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICQodGhpcykuY2xvc2VzdCgnZGV0YWlscycpLnJlbW92ZUF0dHIoJ29wZW4nKTtcbiAgICAgICAgfSk7XG4gICAgICAgICQoZG9jdW1lbnQpLm9uKCdjbGljay5zdHQtdHJhbnNjcmlwdC1hY3Rpb25zJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICAkbWVudXMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgaWYgKHRoaXMgIT09IGV2ZW50LnRhcmdldCAmJiAhdGhpcy5jb250YWlucyhldmVudC50YXJnZXQpKSB7XG4gICAgICAgICAgICAgICAgICAgICQodGhpcykucmVtb3ZlQXR0cignb3BlbicpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdExlZ1RhYnMoKSB7XG4gICAgICAgIHZhciB0cmFuc2NyaXB0TG9jYXRpb24gPSB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcgJiYgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LmxvY2F0aW9uO1xuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1jYWxsLWxlZy1yZXZpZXcnKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciAkcmV2aWV3ID0gJCh0aGlzKTtcbiAgICAgICAgICAgIHZhciAkdGFicyA9ICRyZXZpZXcuZmluZCgnW2RhdGEtc3R0LWNhbGwtbGVnXScpO1xuICAgICAgICAgICAgdmFyICRwYW5lbHMgPSAkcmV2aWV3LmZpbmQoJ1tkYXRhLXN0dC1jYWxsLWxlZy1wYW5lbF0nKTtcblxuICAgICAgICAgICAgZnVuY3Rpb24gcGFuZWxMZWcoJGVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gU3RyaW5nKCRlbGVtZW50LmNsb3Nlc3QoJ1tkYXRhLXN0dC1jYWxsLWxlZy1wYW5lbF0nKS5kYXRhKCdzdHQtY2FsbC1sZWctcGFuZWwnKSB8fCAnJyk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGZ1bmN0aW9uIHNob3coY2FsbExlZykge1xuICAgICAgICAgICAgICAgICR0YWJzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICB2YXIgYWN0aXZlID0gU3RyaW5nKCQodGhpcykuZGF0YSgnc3R0LWNhbGwtbGVnJykgfHwgJycpID09PSBjYWxsTGVnO1xuICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnRvZ2dsZUNsYXNzKCdhY3RpdmUnLCBhY3RpdmUpLmF0dHIoJ2FyaWEtc2VsZWN0ZWQnLCBhY3RpdmUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICAgICAkcGFuZWxzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICB2YXIgaGlkZGVuID0gU3RyaW5nKCQodGhpcykuZGF0YSgnc3R0LWNhbGwtbGVnLXBhbmVsJykgfHwgJycpICE9PSBjYWxsTGVnO1xuXG4gICAgICAgICAgICAgICAgICAgICQodGhpcykucHJvcCgnaGlkZGVuJywgaGlkZGVuKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKGhpZGRlbiAmJiB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcgJiYgdHlwZW9mIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5wYXVzZSA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LnBhdXNlKHRoaXMpO1xuICAgICAgICAgICAgICAgICAgICB9IGVsc2UgaWYgKGhpZGRlbikge1xuICAgICAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS5maW5kKCdhdWRpbycpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHZhciBhdWRpbyA9IHRoaXM7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICR0YWJzLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgY2FsbExlZyA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3N0dC1jYWxsLWxlZycpIHx8ICcnKTtcblxuICAgICAgICAgICAgICAgIHNob3coY2FsbExlZyk7XG4gICAgICAgICAgICAgICAgaWYgKHRyYW5zY3JpcHRMb2NhdGlvbikge1xuICAgICAgICAgICAgICAgICAgICAvLyBBIGhlbGQgbW9tZW50IGluIHRoZSBvdGhlciBsZWcgbm8gbG9uZ2VyIGRlc2NyaWJlcyB3aGF0IGlzIG9uIHNjcmVlbi5cbiAgICAgICAgICAgICAgICAgICAgdmFyIG1vbWVudCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICAgICAgICAgICAgICB2YXIgJHN0YWdlID0gbW9tZW50LnN0YWdlID8gJHJldmlldy5maW5kKCcuc3R0LXRyYW5zY3JpcHQtdmlld1tkYXRhLXN0dC1zdGFnZT1cIicgKyBtb21lbnQuc3RhZ2UgKyAnXCJdJykgOiAkKCk7XG4gICAgICAgICAgICAgICAgICAgIHZhciBjaGFuZ2VzID0geyBsZWc6IGNhbGxMZWcgfTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKCRzdGFnZS5sZW5ndGggPiAwICYmIHBhbmVsTGVnKCRzdGFnZSkgIT09IGNhbGxMZWcpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGNoYW5nZXMuc3RhZ2UgPSAnJztcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICB0cmFuc2NyaXB0TG9jYXRpb24ud3JpdGUoY2hhbmdlcyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG5cbiAgICAgICAgICAgIC8vIFRoZSBhZGRyZXNzIHBpY2tzIHRoZSBsZWc6IHRoZSBsZWcgb2YgYSBzaGFyZWQgbW9tZW50IGZpcnN0LCB0aGVuIGFuIGV4cGxpY2l0IGxlZy5cbiAgICAgICAgICAgIGlmICh0cmFuc2NyaXB0TG9jYXRpb24pIHtcbiAgICAgICAgICAgICAgICB2YXIgaW5pdGlhbCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICAgICAgICAgIHZhciAkdGFyZ2V0ID0gaW5pdGlhbC5zdGFnZSA/ICRyZXZpZXcuZmluZCgnLnN0dC10cmFuc2NyaXB0LXZpZXdbZGF0YS1zdHQtc3RhZ2U9XCInICsgaW5pdGlhbC5zdGFnZSArICdcIl0nKSA6ICQoKTtcbiAgICAgICAgICAgICAgICB2YXIgbGVnID0gJHRhcmdldC5sZW5ndGggPiAwID8gcGFuZWxMZWcoJHRhcmdldCkgOiBpbml0aWFsLmxlZztcbiAgICAgICAgICAgICAgICBpZiAobGVnICE9PSAnJyAmJiAkdGFicy5maWx0ZXIoJ1tkYXRhLXN0dC1jYWxsLWxlZz1cIicgKyBsZWcgKyAnXCJdJykubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICBzaG93KGxlZyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgLy8gT25seSBpdGVtcyB3aXRoIGEgZGF0YS10YWIgdGFyZ2V0IGFyZSBjbGllbnQtc2lkZSB0YWJzLiBUaGVcbiAgICAgICAgLy8gVHJhbnNjcmlwdHMgaXRlbSBpcyBhIHJlYWwgbGluayAoP3RhYj10cmFuc2NyaXB0cywgZnVsbCBwYWdlXG4gICAgICAgIC8vIG5hdmlnYXRpb24pIGJlY2F1c2UgaXRzIGNvbnRlbnQgaXMgb25seSByZW5kZXJlZCBzZXJ2ZXItc2lkZSB3aGVuXG4gICAgICAgIC8vIHRoYXQgdGFiIGlzIHRoZSBvbmUgYmVpbmcgbG9hZGVkIC0gc2VlIGluZGV4LnZvbHQvSW5kZXhWaWV3U2VydmljZS5cbiAgICAgICAgdmFyICR0YWJNZW51ID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tZW51Jyk7XG4gICAgICAgIHZhciAkdGFiSXRlbXMgPSAkdGFiTWVudS5maW5kKCcuaXRlbVtkYXRhLXRhYl0nKTtcbiAgICAgICAgdmFyICRzZXR0aW5nc0Zvcm0gPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWZvcm0nKTtcbiAgICAgICAgdmFyICRtb2RlbEZvcm0gPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1vZGVsLWZvcm0nKTtcbiAgICAgICAgJHRhYkl0ZW1zLnRhYih7XG4gICAgICAgICAgICBvblZpc2libGU6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAvLyBTZW1hbnRpYyBVSSdzIHRhYiBtb2R1bGUgKDIuNC4xLCBzZWVcbiAgICAgICAgICAgICAgICAvLyBwdWJsaWMvYXNzZXRzL2pzL3ZlbmRvciBlcXVpdmFsZW50IHVuZGVyIENvcmUpIG9ubHlcbiAgICAgICAgICAgICAgICAvLyAoZGUpYWN0aXZhdGVzIG1lbnUgaXRlbXMgaXQgd2FzIGluaXRpYWxpemVkIG9uXG4gICAgICAgICAgICAgICAgLy8gKCR0YWJJdGVtcywgaS5lLiBpdGVtcyB3aXRoIGEgZGF0YS10YWIpIC0gc2VlIGl0c1xuICAgICAgICAgICAgICAgIC8vIGFjdGl2YXRlLm5hdmlnYXRpb24oKS4gVGhlIFRyYW5zY3JpcHRzIGl0ZW0gaXNcbiAgICAgICAgICAgICAgICAvLyBkZWxpYmVyYXRlbHkgb3V0c2lkZSB0aGF0IHNldCwgc28gYSBub3JtYWwgdGFiIHN3aXRjaFxuICAgICAgICAgICAgICAgIC8vIG5ldmVyIHJlbW92ZXMgaXRzICdhY3RpdmUnIGNsYXNzOiB3aXRob3V0IHRoaXMsIGxhbmRpbmdcbiAgICAgICAgICAgICAgICAvLyBvbiA/dGFiPXRyYW5zY3JpcHRzICh3aGljaCBzZXJ2ZXItcmVuZGVycyBpdCBhY3RpdmUpIGFuZFxuICAgICAgICAgICAgICAgIC8vIHRoZW4gY2xpY2tpbmcgYW55IG90aGVyIHRhYiB3b3VsZCBsZWF2ZSBUcmFuc2NyaXB0c1xuICAgICAgICAgICAgICAgIC8vIGhpZ2hsaWdodGVkIGFsb25nc2lkZSBpdC5cbiAgICAgICAgICAgICAgICAkdGFiTWVudS5maW5kKCcuaXRlbScpLm5vdCgnW2RhdGEtdGFiXScpLnJlbW92ZUNsYXNzKCdhY3RpdmUnKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgIGlmIChyZXF1ZXN0ZWRUYWIoKSAhPT0gJycgJiYgcmVxdWVzdGVkVGFiKCkgIT09ICd0cmFuc2NyaXB0cycpIHtcbiAgICAgICAgICAgIC8vICd0cmFuc2NyaXB0cycgaGFzIG5vIG1hbmFnZWQgbmF2IGl0ZW0gKG5vIGRhdGEtdGFiKSBhbnkgbW9yZTpcbiAgICAgICAgICAgIC8vIGl0cyBwYW5lbCBhbmQgaXRzIG1lbnUgbGluayBhcmUgYWxyZWFkeSByZW5kZXJlZCBzZXJ2ZXItc2lkZVxuICAgICAgICAgICAgLy8gYXMgYWN0aXZlIG9uID90YWI9dHJhbnNjcmlwdHMgKHNlZSBpbmRleC52b2x0KSwgc28gdGhlcmUgaXNcbiAgICAgICAgICAgIC8vIG5vdGhpbmcgaGVyZSBmb3IgdGhlIHRhYiBtb2R1bGUgdG8gc3dpdGNoIHRvIC0gc2tpcCB0aGUgY2FsbFxuICAgICAgICAgICAgLy8gaW5zdGVhZCBvZiByZWx5aW5nIG9uIGl0IGJlaW5nIGEgaGFybWxlc3Mgbm8tb3AuXG4gICAgICAgICAgICAkdGFiSXRlbXMudGFiKCdjaGFuZ2UgdGFiJywgcmVxdWVzdGVkVGFiKCkpO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAudWkuZHJvcGRvd24nKS5ub3QoJy5zdHQtd29ya2VyLXdpbmRvd3MtZG93bmxvYWQnKS5kcm9wZG93bigpO1xuICAgICAgICB2YXIgbG9hZFdpbmRvd3NXb3JrZXJQYWNrYWdlcyA9IGluaXRpYWxpemVXaW5kb3dzV29ya2VyRG93bmxvYWQoKTtcbiAgICAgICAgbG9hZFdpbmRvd3NXb3JrZXJQYWNrYWdlcygpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC51aS5jaGVja2JveCcpLmNoZWNrYm94KCk7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgW2RhdGEtY29udGVudF0nKS5wb3B1cCgpO1xuICAgICAgICBpbml0aWFsaXplTW9kZWxNYXJrZXRwbGFjZSgkbW9kZWxGb3JtKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHREYXRlRmlsdGVyKCk7XG4gICAgICAgIGluaXRpYWxpemVNb2R1bGVMb2dzKCk7XG4gICAgICAgIGluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyKCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdFJldGVudGlvblNsaWRlcigkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgIGluaXRpYWxpemVDb25maXJtTW9kYWwoKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHREZWxldGlvbigpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MoKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRBY3Rpb25NZW51KCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0TGVnVGFicygpO1xuXG4gICAgICAgIGlmICgkLmZuLmZvcm0gJiYgJC5mbi5mb3JtLnNldHRpbmdzICYmICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcykge1xuICAgICAgICAgICAgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzLnN0dFByb2Nlc3NpbmdSYW5nZSA9IGZ1bmN0aW9uICh2YWx1ZSwgcmFuZ2UpIHtcbiAgICAgICAgICAgICAgICB2YXIgbGltaXRzID0gU3RyaW5nKHJhbmdlIHx8ICcnKS5zcGxpdCgnLCcpLm1hcChOdW1iZXIpO1xuICAgICAgICAgICAgICAgIHZhciBudW1iZXIgPSBOdW1iZXIodmFsdWUpO1xuXG4gICAgICAgICAgICAgICAgcmV0dXJuIGxpbWl0cy5sZW5ndGggPT09IDJcbiAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKG51bWJlcilcbiAgICAgICAgICAgICAgICAgICAgJiYgbnVtYmVyID49IGxpbWl0c1swXVxuICAgICAgICAgICAgICAgICAgICAmJiBudW1iZXIgPD0gbGltaXRzWzFdO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcy5zdHRQcm9jZXNzaW5nT3ZlcmxhcCA9IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBvdmVybGFwTVMgPSBNYXRoLnJvdW5kKE51bWJlcih2YWx1ZSkgKiAxMDAwKTtcbiAgICAgICAgICAgICAgICB2YXIgZHVyYXRpb25NUyA9IE1hdGgucm91bmQoTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnW25hbWU9XCJtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWNcIl0nKS52YWwoKSkgKiAxMDAwKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBOdW1iZXIuaXNGaW5pdGUob3ZlcmxhcE1TKVxuICAgICAgICAgICAgICAgICAgICAmJiBOdW1iZXIuaXNGaW5pdGUoZHVyYXRpb25NUylcbiAgICAgICAgICAgICAgICAgICAgJiYgb3ZlcmxhcE1TID49IDBcbiAgICAgICAgICAgICAgICAgICAgJiYgb3ZlcmxhcE1TIDwgZHVyYXRpb25NUztcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMuc3R0UmV0ZW50aW9uQ29tcGF0aWJpbGl0eSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgICAgICB9O1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIHByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzID0ge1xuICAgICAgICAgICAgdGVtcGVyYXR1cmU6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndGVtcGVyYXR1cmUnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMV0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgdGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0ZW1wZXJhdHVyZV9mYWxsYmFja19zdGVwJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlX2ZhbGxiYWNrX2NvdW50OiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RlbXBlcmF0dXJlX2ZhbGxiYWNrX2NvdW50JyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2ludGVnZXJbMC4uMTBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRvcF9rOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RvcF9rJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2ludGVnZXJbMS4uMTAwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBjb21wcmVzc2lvbl9yYXRpb190aHJlc2hvbGQ6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnY29tcHJlc3Npb25fcmF0aW9fdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDEwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2xvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWy0xMCwwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2ZpcnN0X3Rva2VuX2xvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWy0xMCwwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBub19zcGVlY2hfdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ25vX3NwZWVjaF90aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMV0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ21heF9zZWdtZW50X2R1cmF0aW9uX3NlYycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMSwxMjBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHNlZ21lbnRfb3ZlcmxhcF9zZWM6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnc2VnbWVudF9vdmVybGFwX3NlYycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFtcbiAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMTBdJyB9LFxuICAgICAgICAgICAgICAgICAgICB7IHR5cGU6ICdzdHRQcm9jZXNzaW5nT3ZlcmxhcCcgfVxuICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfTtcblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMoKSB7XG4gICAgICAgICAgICB2YXIgZW5hYmxlZCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnW25hbWU9XCJ2b2ljZV9hY3Rpdml0eV9kZXRlY3Rpb25cIl0nKS5pcygnOmNoZWNrZWQnKTtcblxuICAgICAgICAgICAgJHNldHRpbmdzRm9ybS5maW5kKCcuc3R0LXZhZC1kZXBlbmRlbnQnKVxuICAgICAgICAgICAgICAgIC50b2dnbGVDbGFzcygnZGlzYWJsZWQnLCAhZW5hYmxlZClcbiAgICAgICAgICAgICAgICAuYXR0cignYXJpYS1kaXNhYmxlZCcsIGVuYWJsZWQgPyAnZmFsc2UnIDogJ3RydWUnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzZXR0aW5nc0Zvcm0uZmluZCgnW25hbWU9XCJ2b2ljZV9hY3Rpdml0eV9kZXRlY3Rpb25cIl0nKS5vbignY2hhbmdlJywgdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKTtcbiAgICAgICAgdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKCk7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1jb3B5LWJ1dHRvbicpLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciB0ZXh0ID0gU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1jbGlwYm9hcmQtdGV4dCcpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciAkYnV0dG9uID0gJCh0aGlzKTtcbiAgICAgICAgICAgIGZ1bmN0aW9uIGNvcGllZCgpIHtcbiAgICAgICAgICAgICAgICAkYnV0dG9uLmFkZENsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgICAgIHNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAkYnV0dG9uLnJlbW92ZUNsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgICAgIH0sIDkwMCk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGlmIChuYXZpZ2F0b3IuY2xpcGJvYXJkICYmIG5hdmlnYXRvci5jbGlwYm9hcmQud3JpdGVUZXh0KSB7XG4gICAgICAgICAgICAgICAgbmF2aWdhdG9yLmNsaXBib2FyZC53cml0ZVRleHQodGV4dCkudGhlbihjb3BpZWQpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIC8vIE92ZXIgcGxhaW4gSFRUUCB0aGUgQ2xpcGJvYXJkIEFQSSBpcyBtaXNzaW5nOyBhIHNlbGVjdGVkIHRleHRhcmVhIHN0aWxsIGNvcGllcy5cbiAgICAgICAgICAgIHZhciAkYnVmZmVyID0gJCgnPHRleHRhcmVhIHJlYWRvbmx5PjwvdGV4dGFyZWE+JykudmFsKHRleHQpLmNzcyh7IHBvc2l0aW9uOiAnZml4ZWQnLCB0b3A6IDAsIGxlZnQ6ICctOTk5OXB4JyB9KS5hcHBlbmRUbygnYm9keScpO1xuICAgICAgICAgICAgJGJ1ZmZlclswXS5zZWxlY3QoKTtcbiAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgaWYgKGRvY3VtZW50LmV4ZWNDb21tYW5kKCdjb3B5JykpIHtcbiAgICAgICAgICAgICAgICAgICAgY29waWVkKCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICAvLyBUaGUgdGV4dCBzdGF5cyBzZWxlY3RhYmxlIGluIHRoZSBwYWdlOyBub3RoaW5nIGVsc2UgdG8gZG8uXG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkYnVmZmVyLnJlbW92ZSgpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtd29ya2VyLXBsYXRmb3JtLXN3aXRjaCBbZGF0YS1wbGF0Zm9ybV0nKS5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgcGxhdGZvcm0gPSBTdHJpbmcoJCh0aGlzKS5hdHRyKCdkYXRhLXBsYXRmb3JtJykpO1xuICAgICAgICAgICAgdmFyICRkb3dubG9hZCA9ICQodGhpcykuY2xvc2VzdCgnLnN0dC13b3JrZXItZG93bmxvYWQnKTtcblxuICAgICAgICAgICAgJCh0aGlzKS5hZGRDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ3RydWUnKVxuICAgICAgICAgICAgICAgIC5zaWJsaW5ncygpLnJlbW92ZUNsYXNzKCdhY3RpdmUnKS5hdHRyKCdhcmlhLXByZXNzZWQnLCAnZmFsc2UnKTtcbiAgICAgICAgICAgICRkb3dubG9hZC5maW5kKCcuc3R0LXdvcmtlci1wbGF0Zm9ybS1wYW5lbCcpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICQodGhpcykucHJvcCgnaGlkZGVuJywgU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKSAhPT0gcGxhdGZvcm0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkZG93bmxvYWQuY2xvc2VzdCgnW2RhdGEtd29ya2VyLXBsYXRmb3JtXScpLmF0dHIoJ2RhdGEtd29ya2VyLXBsYXRmb3JtJywgcGxhdGZvcm0pXG4gICAgICAgICAgICAgICAgLmZpbmQoJ2lucHV0W25hbWU9XCJ3b3JrZXJfcGxhdGZvcm1cIl0nKS52YWwocGxhdGZvcm0pO1xuICAgICAgICAgICAgbG9hZFdpbmRvd3NXb3JrZXJQYWNrYWdlcygpO1xuICAgICAgICB9KTtcblxuICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmICRzZXR0aW5nc0Zvcm0ubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgRm9ybS4kZm9ybU9iaiA9ICRzZXR0aW5nc0Zvcm07XG4gICAgICAgICAgICBGb3JtLnVybCA9ICRzZXR0aW5nc0Zvcm0uYXR0cignYWN0aW9uJyk7XG4gICAgICAgICAgICBGb3JtLm1ldGhvZCA9ICdQT1NUJztcbiAgICAgICAgICAgIEZvcm0udmFsaWRhdGVSdWxlcyA9IE9iamVjdC5hc3NpZ24oe1xuICAgICAgICAgICAgICAgIGRlZmF1bHRfbGFuZ3VhZ2U6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2RlZmF1bHRfbGFuZ3VhZ2UnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2VtcHR5JyB9XVxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgY2RyX2hpc3RvcnlfZGF5czoge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnY2RyX2hpc3RvcnlfZGF5cycsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnaW50ZWdlclswLi4zNjVdJyB9XVxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5czoge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cycsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbXG4gICAgICAgICAgICAgICAgICAgICAgICB7IHR5cGU6ICdpbnRlZ2VyWzAuLjM2NV0nIH0sXG4gICAgICAgICAgICAgICAgICAgICAgICB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3N0dFJldGVudGlvbkNvbXBhdGliaWxpdHknLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHByb21wdDogdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbkNvbmZsaWN0JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ1RoZSByZWNvcmRpbmcgcHJvY2Vzc2luZyB3aW5kb3cgY2Fubm90IGV4Y2VlZCB0cmFuc2NyaXB0IHJldGVudGlvbi4gSW5jcmVhc2UgcmV0ZW50aW9uIG9yIHJlZHVjZSB0aGUgcHJvY2Vzc2luZyB3aW5kb3cuJ1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgXVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sIHByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzKTtcbiAgICAgICAgICAgIEZvcm0uY2JCZWZvcmVTZW5kRm9ybSA9IGZ1bmN0aW9uIChzZXR0aW5ncykge1xuICAgICAgICAgICAgICAgIHJldHVybiBzZXR0aW5ncztcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICBGb3JtLmNiQWZ0ZXJTZW5kRm9ybSA9IGZ1bmN0aW9uICgpIHt9O1xuICAgICAgICAgICAgRm9ybS5hcGlTZXR0aW5ncy5lbmFibGVkID0gZmFsc2U7XG4gICAgICAgICAgICBGb3JtLmFwaVNldHRpbmdzLmFwaU9iamVjdCA9IG51bGw7XG4gICAgICAgICAgICBGb3JtLnNlbmRPbmx5Q2hhbmdlZCA9IGZhbHNlO1xuICAgICAgICAgICAgRm9ybS5lbmFibGVEaXJyaXR5ID0gdHJ1ZTtcbiAgICAgICAgICAgIEZvcm0uaW5pdGlhbGl6ZSgpO1xuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgJHNldHRpbmdzRm9ybS5mb3JtKHtcbiAgICAgICAgICAgICAgICBmaWVsZHM6IE9iamVjdC5hc3NpZ24oe1xuICAgICAgICAgICAgICAgICAgICBkZWZhdWx0X2xhbmd1YWdlOiBbJ2VtcHR5J10sXG4gICAgICAgICAgICAgICAgICAgIGNkcl9oaXN0b3J5X2RheXM6IFsnaW50ZWdlclswLi4zNjVdJ10sXG4gICAgICAgICAgICAgICAgICAgIHRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXM6IFtcbiAgICAgICAgICAgICAgICAgICAgICAgICdpbnRlZ2VyWzAuLjM2NV0nLFxuICAgICAgICAgICAgICAgICAgICAgICAge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBwcm9tcHQ6IHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25Db25mbGljdCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdUaGUgcmVjb3JkaW5nIHByb2Nlc3Npbmcgd2luZG93IGNhbm5vdCBleGNlZWQgdHJhbnNjcmlwdCByZXRlbnRpb24uIEluY3JlYXNlIHJldGVudGlvbiBvciByZWR1Y2UgdGhlIHByb2Nlc3Npbmcgd2luZG93LidcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgICAgICB9LCBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcylcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICB9KTtcbn0oKSk7XG4iXSwibWFwcGluZ3MiOiI7Ozs7QUFBQyxhQUFZO0VBQ1QsWUFBWTs7RUFFWixTQUFTQSxXQUFXQSxDQUFDQyxHQUFHLEVBQUVDLFFBQVEsRUFBRTtJQUNoQyxJQUFJLE9BQU9DLGVBQWUsS0FBSyxXQUFXLElBQUlBLGVBQWUsQ0FBQ0YsR0FBRyxDQUFDLEVBQUU7TUFDaEUsT0FBT0UsZUFBZSxDQUFDRixHQUFHLENBQUM7SUFDL0I7SUFFQSxPQUFPQyxRQUFRO0VBQ25CO0VBRUEs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