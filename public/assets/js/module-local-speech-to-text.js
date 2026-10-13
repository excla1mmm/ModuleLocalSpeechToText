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
    $('.module-local-speech-to-text .ui.dropdown').dropdown();
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

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIiwibmFtZXMiOlsidHJhbnNsYXRpb24iLCJrZXkiLCJmYWxsYmFjayIsImdsb2JhbFRyYW5zbGF0ZSIsInJlcXVlc3RlZFRhYiIsInBhcmFtcyIsIlVSTFNlYXJjaFBhcmFtcyIsIndpbmRvdyIsImxvY2F0aW9uIiwic2VhcmNoIiwidGFiTmFtZSIsImdldCIsImhhc2giLCJyZXBsYWNlIiwidGVzdCIsImNhbGVuZGFyTG9jYWxlIiwiZGF5cyIsIm1vbnRocyIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiY2FsZW5kYXJUZXh0IiwiZm9ybWF0Iiwic2VwYXJhdG9yIiwiYXBwbHlMYWJlbCIsImNhbmNlbExhYmVsIiwiZnJvbUxhYmVsIiwidG9MYWJlbCIsImN1c3RvbVJhbmdlTGFiZWwiLCJkYXlzT2ZXZWVrIiwibW9udGhOYW1lcyIsImZpcnN0RGF5IiwibW9tZW50RGF0ZSIsInZhbHVlIiwiZGF0ZSIsIm1vbWVudCIsImlzVmFsaWQiLCJjbG9uZSIsImluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlciIsIiRmb3JtIiwiJCIsIiRzZWxlY3RvciIsImxlbmd0aCIsImZuIiwiZGF0ZXJhbmdlcGlja2VyIiwiJGRhdGVGcm9tIiwiZmluZCIsIiRkYXRlVG8iLCIkc2VhcmNoIiwic2VhcmNoRGVib3VuY2VUaW1lciIsInRvZGF5Iiwic3RhcnREYXRlIiwidmFsIiwiZW5kRGF0ZSIsImlzQWZ0ZXIiLCJzd2FwRGF0ZSIsInVwZGF0ZUhpZGRlbklucHV0cyIsInN0YXJ0IiwiZW5kIiwiY2FuY2VsU2VhcmNoRGVib3VuY2UiLCJjbGVhclRpbWVvdXQiLCJzdWJtaXRUcmFuc2NyaXB0U2VhcmNoIiwidHJpZ2dlciIsInJhbmdlcyIsIl9kZWZpbmVQcm9wZXJ0eSIsInN1YnRyYWN0Iiwic3RhcnRPZiIsImVuZE9mIiwiYWx3YXlzU2hvd0NhbGVuZGFycyIsImF1dG9VcGRhdGVJbnB1dCIsImxpbmtlZENhbGVuZGFycyIsIm1heERhdGUiLCJsb2NhbGUiLCJvbiIsInBpY2tlciIsImRhdGEiLCJzZXRUaW1lb3V0IiwiZXZlbnQiLCJ3aGljaCIsInByZXZlbnREZWZhdWx0IiwiU3RyaW5nIiwidHJpbSIsImluaXRpYWxpemVNb2R1bGVMb2dzIiwiJHNlZ21lbnQiLCIkcmVzdWx0cyIsIiRzdGF0ZSIsIiRyZXN1bHRDb3VudCIsIiR1cGRhdGVkQXQiLCIkbGV2ZWxGaWx0ZXIiLCIkY29tcG9uZW50RmlsdGVyIiwiZGF0YVVybCIsIiRwZXJpb2RCdXR0b25zIiwiJHJlZnJlc2hCdXR0b24iLCJjdXJyZW50UGVyaW9kIiwicGFyc2VJbnQiLCJmaWx0ZXIiLCJsb2FkZWRPbmNlIiwicmVxdWVzdCIsInJlcXVlc3RHZW5lcmF0aW9uIiwiY3VycmVudEVudHJpZXMiLCJjdXJyZW50UmVzcG9uc2UiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsImxldmVsTmFtZSIsImxldmVsIiwibGV2ZWxzIiwiZGVidWciLCJpbmZvIiwibm90aWNlIiwid2FybmluZyIsImVycm9yIiwiaXRlbSIsImNvbXBvbmVudE5hbWUiLCJjb21wb25lbnQiLCJhbGlhc2VzIiwiYXBpIiwicXVldWUiLCJzY2FubmVyIiwid29ya2VyIiwid29ya2VybG9jYWxzcGVlY2h0b3RleHRqb2JzIiwic2V0dGluZ3MiLCJ3ZWJfdWkiLCJzZXR1cCIsInN0b3JhZ2UiLCJ0cmFuc2NyaXB0Iiwic3lzdGVtIiwibG9jYWxzcGVlY2h0b3RleHRtYWluIiwibG9nZ2VyIiwibGFiZWxzIiwib3RoZXIiLCJsYWJlbCIsImZvcm1hdFRpbWVzdGFtcCIsInRpbWVzdGFtcCIsIkRhdGUiLCJpc05hTiIsImdldFRpbWUiLCJ0b0xvY2FsZVN0cmluZyIsInJlbmRlclN0YXRlIiwia2luZCIsIm1lc3NhZ2UiLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJ0ZXh0IiwiYWRkQ2xhc3MiLCJmaWx0ZXJFbnRyaWVzIiwiZW50cmllcyIsInF1ZXJ5IiwidG9Mb3dlckNhc2UiLCJlbnRyeSIsImhheXN0YWNrIiwic2V2ZXJpdHkiLCJwaWQiLCJKU09OIiwic3RyaW5naWZ5IiwiY29udGV4dCIsImpvaW4iLCJpbmRleE9mIiwicmVuZGVyRW50cmllcyIsImVtcHR5IiwiZm9yRWFjaCIsIiRjYXJkIiwiJGhlYWRlciIsIiRpZGVudGl0eSIsIiRsZXZlbCIsIiRjb21wb25lbnQiLCIkdGltZSIsInRzIiwiJG1lc3NhZ2UiLCIkZGV0YWlscyIsIiRzdW1tYXJ5IiwiZGV0YWlsVGV4dCIsInVuZGVmaW5lZCIsIk9iamVjdCIsImtleXMiLCJhcHBlbmQiLCJ1cGRhdGVDb21wb25lbnRPcHRpb25zIiwic2VsZWN0ZWQiLCJjb21wb25lbnRzIiwicHVzaCIsInNvcnQiLCJsZWZ0IiwicmlnaHQiLCJsb2NhbGVDb21wYXJlIiwidXBkYXRlTWV0YSIsImNvdW50IiwicmVzcG9uc2UiLCJmb3VuZCIsInVwZGF0ZWQiLCJnZW5lcmF0ZWRBdCIsInJlbmRlckN1cnJlbnQiLCJmaWx0ZXJlZCIsInNvdXJjZVN0YXR1cyIsIm5vdGljZXMiLCJtYWxmb3JtZWRDb3VudCIsInRydW5jYXRlZCIsInNldExvYWRpbmciLCJsb2FkTG9ncyIsImZvcmNlIiwicmVxdWVzdElkIiwiYWJvcnQiLCJjdXJyZW50UmVxdWVzdCIsImFqYXgiLCJ1cmwiLCJtZXRob2QiLCJkYXRhVHlwZSIsInBlcmlvZCIsImRvbmUiLCJBcnJheSIsImlzQXJyYXkiLCJmYWlsIiwieGhyIiwic3RhdHVzIiwiYWx3YXlzIiwiJGJ1dHRvbiIsImFkZCIsImNsb3Nlc3QiLCJoYXNDbGFzcyIsImluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyIiwiJHNldHRpbmdzRm9ybSIsIiRzbGlkZXIiLCIkaW5wdXQiLCJzbGlkZXIiLCJ2YWx1ZXMiLCJjdXJyZW50VmFsdWUiLCJpbml0aWFsSW5kZXgiLCJpbml0aWFsaXplZCIsIm1pbiIsIm1heCIsInN0ZXAiLCJzbW9vdGgiLCJhdXRvQWRqdXN0TGFiZWxzIiwiaW50ZXJwcmV0TGFiZWwiLCJvbkNoYW5nZSIsInVwZGF0ZVJldGVudGlvbkNvbmZsaWN0IiwiRm9ybSIsImNoZWNrVmFsdWVzIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIiLCJyZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nIiwicHJvY2Vzc2luZ0RheXMiLCJyZXRlbnRpb25EYXlzIiwiY29tcGF0aWJsZSIsInRvZ2dsZUNsYXNzIiwiYXR0ciIsImluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlIiwiJG1vZGVsRm9ybSIsIiRzYXZlQnV0dG9uIiwiJG1vZGVsSW5wdXRzIiwiJGNvbXBhdGliaWxpdHlNZXNzYWdlIiwic2VsZWN0ZWRMYW5ndWFnZSIsIiRwbGF0Zm9ybUJ1dHRvbnMiLCJpbml0aWFsTW9kZWxzIiwiZWFjaCIsImNoYW5nZWRJbnB1dHMiLCJtb2RlbFN1cHBvcnRzTGFuZ3VhZ2UiLCJzdXBwb3J0ZWRMYW5ndWFnZXMiLCJzcGxpdCIsIm1hcCIsImxhbmd1YWdlIiwic2hvd1BsYXRmb3JtIiwicGxhdGZvcm0iLCJhY3RpdmUiLCJ1cGRhdGVTYXZlQnV0dG9uU3RhdGUiLCIkY2hhbmdlZCIsImhhc0NoYW5nZXMiLCJhY2NvcmRpb24iLCJ0YXJnZXQiLCJjaGVja2JveCIsIiRyb3ciLCJzaWJsaW5ncyIsImluaXRpYWxpemVDb25maXJtTW9kYWwiLCIkbW9kYWwiLCJtb2RhbCIsInNob3dDb25maXJtIiwib25Db25maXJtIiwiY2xvc2FibGUiLCJvbkFwcHJvdmUiLCJiaW5kQ29uZmlybWFibGVGb3JtIiwic3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uIiwiJHNlbGYiLCJvZmYiLCJpbml0aWFsaXplVHJhbnNjcmlwdERlbGV0aW9uIiwiJGZvcm1zIiwiJGFja25vd2xlZGdlIiwiJGFwcHJvdmUiLCJwZW5kaW5nRm9ybSIsInNldEFwcHJvdmVFbmFibGVkIiwiZW5hYmxlZCIsImZvcm0iLCJ0eXBlIiwibmFtZSIsImFwcGVuZFRvIiwic3VibWl0Iiwib25IaWRkZW4iLCJpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MiLCJkZXN0aW5hdGlvbiIsImhyZWYiLCJpbml0aWFsaXplVHJhbnNjcmlwdEFjdGlvbk1lbnUiLCIkbWVudXMiLCJyZW1vdmVBdHRyIiwiZG9jdW1lbnQiLCJjb250YWlucyIsImluaXRpYWxpemVUcmFuc2NyaXB0TGVnVGFicyIsInRyYW5zY3JpcHRMb2NhdGlvbiIsIlN0dFRyYW5zY3JpcHRWaWV3IiwiJHJldmlldyIsIiR0YWJzIiwiJHBhbmVscyIsInBhbmVsTGVnIiwiJGVsZW1lbnQiLCJzaG93IiwiY2FsbExlZyIsImhpZGRlbiIsInBhdXNlIiwiYXVkaW8iLCJyZWFkIiwiJHN0YWdlIiwic3RhZ2UiLCJjaGFuZ2VzIiwibGVnIiwid3JpdGUiLCJpbml0aWFsIiwiJHRhcmdldCIsIiR0YWJNZW51IiwiJHRhYkl0ZW1zIiwidGFiIiwib25WaXNpYmxlIiwibm90IiwiZHJvcGRvd24iLCJwb3B1cCIsInJ1bGVzIiwic3R0UHJvY2Vzc2luZ1JhbmdlIiwicmFuZ2UiLCJsaW1pdHMiLCJudW1iZXIiLCJzdHRQcm9jZXNzaW5nT3ZlcmxhcCIsIm92ZXJsYXBNUyIsIk1hdGgiLCJyb3VuZCIsImR1cmF0aW9uTVMiLCJzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5IiwicHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMiLCJ0ZW1wZXJhdHVyZSIsImlkZW50aWZpZXIiLCJ0ZW1wZXJhdHVyZV9mYWxsYmFja19zdGVwIiwidGVtcGVyYXR1cmVfZmFsbGJhY2tfY291bnQiLCJ0b3BfayIsImNvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZCIsImxvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQiLCJmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkIiwibm9fc3BlZWNoX3RocmVzaG9sZCIsIm1heF9zZWdtZW50X2R1cmF0aW9uX3NlYyIsInNlZ21lbnRfb3ZlcmxhcF9zZWMiLCJ1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMiLCJpcyIsImNvcGllZCIsIm5hdmlnYXRvciIsImNsaXBib2FyZCIsIndyaXRlVGV4dCIsInRoZW4iLCIkYnVmZmVyIiwiY3NzIiwicG9zaXRpb24iLCJ0b3AiLCJzZWxlY3QiLCJleGVjQ29tbWFuZCIsInJlbW92ZSIsIiRkb3dubG9hZCIsIiRmb3JtT2JqIiwidmFsaWRhdGVSdWxlcyIsImFzc2lnbiIsImRlZmF1bHRfbGFuZ3VhZ2UiLCJjZHJfaGlzdG9yeV9kYXlzIiwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cyIsInByb21wdCIsImNiQmVmb3JlU2VuZEZvcm0iLCJjYkFmdGVyU2VuZEZvcm0iLCJhcGlTZXR0aW5ncyIsImFwaU9iamVjdCIsInNlbmRPbmx5Q2hhbmdlZCIsImVuYWJsZURpcnJpdHkiLCJpbml0aWFsaXplIiwiZmllbGRzIl0sInNvdXJjZXMiOlsiLi4vbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIl0sInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAoKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgZnVuY3Rpb24gdHJhbnNsYXRpb24oa2V5LCBmYWxsYmFjaykge1xuICAgICAgICBpZiAodHlwZW9mIGdsb2JhbFRyYW5zbGF0ZSAhPT0gJ3VuZGVmaW5lZCcgJiYgZ2xvYmFsVHJhbnNsYXRlW2tleV0pIHtcbiAgICAgICAgICAgIHJldHVybiBnbG9iYWxUcmFuc2xhdGVba2V5XTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiBmYWxsYmFjaztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiByZXF1ZXN0ZWRUYWIoKSB7XG4gICAgICAgIHZhciBwYXJhbXMgPSBuZXcgVVJMU2VhcmNoUGFyYW1zKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICB2YXIgdGFiTmFtZSA9IHBhcmFtcy5nZXQoJ3RhYicpIHx8IHdpbmRvdy5sb2NhdGlvbi5oYXNoLnJlcGxhY2UoL14jLywgJycpO1xuXG4gICAgICAgIHJldHVybiAvXlthLXowLTlfLV0rJC8udGVzdCh0YWJOYW1lKSA/IHRhYk5hbWUgOiAnJztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjYWxlbmRhckxvY2FsZSgpIHtcbiAgICAgICAgdmFyIGRheXMgPSBbJ1N1JywgJ01vJywgJ1R1JywgJ1dlJywgJ1RoJywgJ0ZyJywgJ1NhJ107XG4gICAgICAgIHZhciBtb250aHMgPSBbXG4gICAgICAgICAgICAnSmFudWFyeScsXG4gICAgICAgICAgICAnRmVicnVhcnknLFxuICAgICAgICAgICAgJ01hcmNoJyxcbiAgICAgICAgICAgICdBcHJpbCcsXG4gICAgICAgICAgICAnTWF5JyxcbiAgICAgICAgICAgICdKdW5lJyxcbiAgICAgICAgICAgICdKdWx5JyxcbiAgICAgICAgICAgICdBdWd1c3QnLFxuICAgICAgICAgICAgJ1NlcHRlbWJlcicsXG4gICAgICAgICAgICAnT2N0b2JlcicsXG4gICAgICAgICAgICAnTm92ZW1iZXInLFxuICAgICAgICAgICAgJ0RlY2VtYmVyJ1xuICAgICAgICBdO1xuXG4gICAgICAgIGlmICh0eXBlb2YgU2VtYW50aWNMb2NhbGl6YXRpb24gIT09ICd1bmRlZmluZWQnICYmIFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dCkge1xuICAgICAgICAgICAgZGF5cyA9IFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dC5kYXlzIHx8IGRheXM7XG4gICAgICAgICAgICBtb250aHMgPSBTZW1hbnRpY0xvY2FsaXphdGlvbi5jYWxlbmRhclRleHQubW9udGhzIHx8IG1vbnRocztcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiB7XG4gICAgICAgICAgICBmb3JtYXQ6ICdERC9NTS9ZWVlZJyxcbiAgICAgICAgICAgIHNlcGFyYXRvcjogJyAtICcsXG4gICAgICAgICAgICBhcHBseUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0FwcGx5QnRuJywgJ0FwcGx5JyksXG4gICAgICAgICAgICBjYW5jZWxMYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9DYW5jZWxCdG4nLCAnQ2FuY2VsJyksXG4gICAgICAgICAgICBmcm9tTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfZnJvbScsICdGcm9tJyksXG4gICAgICAgICAgICB0b0xhYmVsOiB0cmFuc2xhdGlvbignY2FsX3RvJywgJ1RvJyksXG4gICAgICAgICAgICBjdXN0b21SYW5nZUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0N1c3RvbVBlcmlvZCcsICdDdXN0b20gcmFuZ2UnKSxcbiAgICAgICAgICAgIGRheXNPZldlZWs6IGRheXMsXG4gICAgICAgICAgICBtb250aE5hbWVzOiBtb250aHMsXG4gICAgICAgICAgICBmaXJzdERheTogMVxuICAgICAgICB9O1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIG1vbWVudERhdGUodmFsdWUsIGZhbGxiYWNrKSB7XG4gICAgICAgIHZhciBkYXRlID0gbW9tZW50KHZhbHVlLCAnWVlZWS1NTS1ERCcsIHRydWUpO1xuXG4gICAgICAgIHJldHVybiBkYXRlLmlzVmFsaWQoKSA/IGRhdGUgOiBmYWxsYmFjay5jbG9uZSgpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlcigpIHtcbiAgICAgICAgdmFyICRmb3JtID0gJCgnI3N0dC10cmFuc2NyaXB0cy1maWx0ZXItZm9ybScpO1xuICAgICAgICB2YXIgJHNlbGVjdG9yID0gJCgnI3N0dC10cmFuc2NyaXB0cy1kYXRlLXJhbmdlLXNlbGVjdG9yJyk7XG5cbiAgICAgICAgaWYgKCRmb3JtLmxlbmd0aCA9PT0gMCB8fCAkc2VsZWN0b3IubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHR5cGVvZiBtb21lbnQgPT09ICd1bmRlZmluZWQnIHx8IHR5cGVvZiAkLmZuLmRhdGVyYW5nZXBpY2tlciA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciAkZGF0ZUZyb20gPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiZGF0ZV9mcm9tXCJdJyk7XG4gICAgICAgIHZhciAkZGF0ZVRvID0gJGZvcm0uZmluZCgnaW5wdXRbbmFtZT1cImRhdGVfdG9cIl0nKTtcbiAgICAgICAgdmFyICRzZWFyY2ggPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwic2VhcmNoXCJdJyk7XG4gICAgICAgIHZhciBzZWFyY2hEZWJvdW5jZVRpbWVyID0gbnVsbDtcbiAgICAgICAgdmFyIHRvZGF5ID0gbW9tZW50KCk7XG4gICAgICAgIHZhciBzdGFydERhdGUgPSBtb21lbnREYXRlKCRkYXRlRnJvbS52YWwoKSwgdG9kYXkpO1xuICAgICAgICB2YXIgZW5kRGF0ZSA9IG1vbWVudERhdGUoJGRhdGVUby52YWwoKSwgc3RhcnREYXRlKTtcblxuICAgICAgICBpZiAoc3RhcnREYXRlLmlzQWZ0ZXIoZW5kRGF0ZSkpIHtcbiAgICAgICAgICAgIHZhciBzd2FwRGF0ZSA9IHN0YXJ0RGF0ZTtcbiAgICAgICAgICAgIHN0YXJ0RGF0ZSA9IGVuZERhdGU7XG4gICAgICAgICAgICBlbmREYXRlID0gc3dhcERhdGU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCkge1xuICAgICAgICAgICAgJGRhdGVGcm9tLnZhbChzdGFydC5mb3JtYXQoJ1lZWVktTU0tREQnKSk7XG4gICAgICAgICAgICAkZGF0ZVRvLnZhbChlbmQuZm9ybWF0KCdZWVlZLU1NLUREJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY2FuY2VsU2VhcmNoRGVib3VuY2UoKSB7XG4gICAgICAgICAgICBpZiAoc2VhcmNoRGVib3VuY2VUaW1lciAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5jbGVhclRpbWVvdXQoc2VhcmNoRGVib3VuY2VUaW1lcik7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzdWJtaXRUcmFuc2NyaXB0U2VhcmNoKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgICRmb3JtLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHN0YXJ0RGF0ZSwgZW5kRGF0ZSk7XG4gICAgICAgICRzZWxlY3Rvci5kYXRlcmFuZ2VwaWNrZXIoe1xuICAgICAgICAgICAgcmFuZ2VzOiB7XG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVG9kYXknLCAnVG9kYXknKV06IFttb21lbnQoKSwgbW9tZW50KCldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX1llc3RlcmRheScsICdZZXN0ZXJkYXknKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0V2VlaycsICdMYXN0IDcgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDYsICdkYXlzJyksIG1vbWVudCgpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0MzBEYXlzJywgJ0xhc3QgMzAgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDI5LCAnZGF5cycpLCBtb21lbnQoKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVGhpc01vbnRoJywgJ1RoaXMgbW9udGgnKV06IFttb21lbnQoKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5lbmRPZignbW9udGgnKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfTGFzdE1vbnRoJywgJ0xhc3QgbW9udGgnKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5lbmRPZignbW9udGgnKV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBhbHdheXNTaG93Q2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgYXV0b1VwZGF0ZUlucHV0OiB0cnVlLFxuICAgICAgICAgICAgbGlua2VkQ2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgbWF4RGF0ZTogbW9tZW50KCksXG4gICAgICAgICAgICBzdGFydERhdGU6IHN0YXJ0RGF0ZS5zdGFydE9mKCdkYXknKSxcbiAgICAgICAgICAgIGVuZERhdGU6IGVuZERhdGUuZW5kT2YoJ2RheScpLFxuICAgICAgICAgICAgbG9jYWxlOiBjYWxlbmRhckxvY2FsZSgpXG4gICAgICAgIH0sIGZ1bmN0aW9uIChzdGFydCwgZW5kKSB7XG4gICAgICAgICAgICB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCk7XG4gICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGNhbmNlbFNlYXJjaERlYm91bmNlKCk7XG4gICAgICAgICAgICB2YXIgcGlja2VyID0gJHNlbGVjdG9yLmRhdGEoJ2RhdGVyYW5nZXBpY2tlcicpO1xuICAgICAgICAgICAgaWYgKHBpY2tlciAmJiBwaWNrZXIuc3RhcnREYXRlICYmIHBpY2tlci5lbmREYXRlKSB7XG4gICAgICAgICAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHBpY2tlci5zdGFydERhdGUsIHBpY2tlci5lbmREYXRlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ2lucHV0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgIHNlYXJjaERlYm91bmNlVGltZXIgPSB3aW5kb3cuc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICAgICAgJGZvcm0udHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgICAgICB9LCA0MDApO1xuICAgICAgICB9KTtcbiAgICAgICAgJHNlYXJjaC5vbigna2V5ZG93bicsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKGV2ZW50LndoaWNoICE9PSAxMykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ3NlYXJjaCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGlmIChTdHJpbmcoJHNlYXJjaC52YWwoKSB8fCAnJykudHJpbSgpID09PSAnJykge1xuICAgICAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZU1vZHVsZUxvZ3MoKSB7XG4gICAgICAgIHZhciAkZm9ybSA9ICQoJyNzdHQtbW9kdWxlLWxvZ3MtZm9ybScpO1xuICAgICAgICB2YXIgJHNlZ21lbnQgPSAkKCcjc3lzdGVtLWxvZ3Mtc2VnbWVudCcpO1xuICAgICAgICB2YXIgJHJlc3VsdHMgPSAkKCcjc3R0LWxvZy1yZXN1bHRzJyk7XG4gICAgICAgIHZhciAkc3RhdGUgPSAkKCcjc3R0LWxvZy1zdGF0ZScpO1xuICAgICAgICB2YXIgJHJlc3VsdENvdW50ID0gJCgnI3N0dC1sb2ctcmVzdWx0LWNvdW50Jyk7XG4gICAgICAgIHZhciAkdXBkYXRlZEF0ID0gJCgnI3N0dC1sb2ctdXBkYXRlZC1hdCcpO1xuICAgICAgICB2YXIgJGxldmVsRmlsdGVyID0gJCgnI3N0dC1sb2ctbGV2ZWwtZmlsdGVyJyk7XG4gICAgICAgIHZhciAkY29tcG9uZW50RmlsdGVyID0gJCgnI3N0dC1sb2ctY29tcG9uZW50LWZpbHRlcicpO1xuICAgICAgICB2YXIgJHNlYXJjaCA9ICQoJyNzdHQtbG9nLXNlYXJjaCcpO1xuXG4gICAgICAgIGlmICgkZm9ybS5sZW5ndGggPT09IDAgfHwgJHJlc3VsdHMubGVuZ3RoID09PSAwIHx8ICRzdGF0ZS5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciBkYXRhVXJsID0gJGZvcm0uZGF0YSgndXJsJyk7XG4gICAgICAgIHZhciAkcGVyaW9kQnV0dG9ucyA9ICRmb3JtLmZpbmQoJy5wZXJpb2QtYnRuJyk7XG4gICAgICAgIHZhciAkcmVmcmVzaEJ1dHRvbiA9ICRmb3JtLmZpbmQoJy5zdHQtbG9nLXJlZnJlc2gnKTtcbiAgICAgICAgdmFyIGN1cnJlbnRQZXJpb2QgPSBwYXJzZUludCgkcGVyaW9kQnV0dG9ucy5maWx0ZXIoJy5hY3RpdmUnKS5kYXRhKCdwZXJpb2QnKSwgMTApO1xuICAgICAgICB2YXIgbG9hZGVkT25jZSA9IGZhbHNlO1xuICAgICAgICB2YXIgcmVxdWVzdCA9IG51bGw7XG4gICAgICAgIHZhciByZXF1ZXN0R2VuZXJhdGlvbiA9IDA7XG4gICAgICAgIHZhciBjdXJyZW50RW50cmllcyA9IFtdO1xuICAgICAgICB2YXIgY3VycmVudFJlc3BvbnNlID0gbnVsbDtcblxuICAgICAgICBpZiAoIU51bWJlci5pc0Zpbml0ZShjdXJyZW50UGVyaW9kKSkge1xuICAgICAgICAgICAgY3VycmVudFBlcmlvZCA9IDM2MDA7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBsZXZlbE5hbWUobGV2ZWwpIHtcbiAgICAgICAgICAgIHZhciBsZXZlbHMgPSB7XG4gICAgICAgICAgICAgICAgZGVidWc6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsRGVidWcnLCAnRGVidWcnXSxcbiAgICAgICAgICAgICAgICBpbmZvOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbEluZm8nLCAnSW5mbyddLFxuICAgICAgICAgICAgICAgIG5vdGljZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxOb3RpY2UnLCAnTm90aWNlJ10sXG4gICAgICAgICAgICAgICAgd2FybmluZzogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxXYXJuaW5nJywgJ1dhcm5pbmcnXSxcbiAgICAgICAgICAgICAgICBlcnJvcjogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxFcnJvcicsICdFcnJvciddXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGl0ZW0gPSBsZXZlbHNbbGV2ZWxdIHx8IGxldmVscy5pbmZvO1xuXG4gICAgICAgICAgICByZXR1cm4gdHJhbnNsYXRpb24oaXRlbVswXSwgaXRlbVsxXSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjb21wb25lbnROYW1lKGNvbXBvbmVudCkge1xuICAgICAgICAgICAgdmFyIGFsaWFzZXMgPSB7XG4gICAgICAgICAgICAgICAgYXBpOiAnYXBpJyxcbiAgICAgICAgICAgICAgICBxdWV1ZTogJ3F1ZXVlJyxcbiAgICAgICAgICAgICAgICBzY2FubmVyOiAnc2Nhbm5lcicsXG4gICAgICAgICAgICAgICAgd29ya2VyOiAnd29ya2VyJyxcbiAgICAgICAgICAgICAgICB3b3JrZXJsb2NhbHNwZWVjaHRvdGV4dGpvYnM6ICd3b3JrZXInLFxuICAgICAgICAgICAgICAgIHNldHRpbmdzOiAnc2V0dGluZ3MnLFxuICAgICAgICAgICAgICAgIHdlYl91aTogJ3NldHRpbmdzJyxcbiAgICAgICAgICAgICAgICBzZXR1cDogJ3NldHVwJyxcbiAgICAgICAgICAgICAgICBzdG9yYWdlOiAnc3RvcmFnZScsXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdDogJ3RyYW5zY3JpcHQnLFxuICAgICAgICAgICAgICAgIHN5c3RlbTogJ3N5c3RlbScsXG4gICAgICAgICAgICAgICAgbG9jYWxzcGVlY2h0b3RleHRtYWluOiAnc3lzdGVtJyxcbiAgICAgICAgICAgICAgICBsb2dnZXI6ICdzeXN0ZW0nXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgICAgICBhcGk6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudEFwaScsICdBUEknXSxcbiAgICAgICAgICAgICAgICBxdWV1ZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50UXVldWUnLCAnUXVldWUnXSxcbiAgICAgICAgICAgICAgICBzY2FubmVyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTY2FubmVyJywgJ1JlY29yZGluZyBzY2FubmVyJ10sXG4gICAgICAgICAgICAgICAgd29ya2VyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRXb3JrZXInLCAnV29ya2VyJ10sXG4gICAgICAgICAgICAgICAgc2V0dGluZ3M6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNldHRpbmdzJywgJ1NldHRpbmdzJ10sXG4gICAgICAgICAgICAgICAgc2V0dXA6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNldHVwJywgJ1NldHVwJ10sXG4gICAgICAgICAgICAgICAgc3RvcmFnZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U3RvcmFnZScsICdTdG9yYWdlJ10sXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdDogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50VHJhbnNjcmlwdCcsICdUcmFuc2NyaXB0cyddLFxuICAgICAgICAgICAgICAgIHN5c3RlbTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U3lzdGVtJywgJ1N5c3RlbSddLFxuICAgICAgICAgICAgICAgIG90aGVyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRPdGhlcicsICdPdGhlciddXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGxhYmVsID0gbGFiZWxzW2FsaWFzZXNbY29tcG9uZW50XSB8fCAnb3RoZXInXTtcblxuICAgICAgICAgICAgcmV0dXJuIHRyYW5zbGF0aW9uKGxhYmVsWzBdLCBsYWJlbFsxXSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmb3JtYXRUaW1lc3RhbXAodGltZXN0YW1wKSB7XG4gICAgICAgICAgICB2YXIgZGF0ZSA9IG5ldyBEYXRlKHRpbWVzdGFtcCk7XG4gICAgICAgICAgICBpZiAoTnVtYmVyLmlzTmFOKGRhdGUuZ2V0VGltZSgpKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aW1lc3RhbXAgfHwgJ+KAlCc7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHJldHVybiBkYXRlLnRvTG9jYWxlU3RyaW5nKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJTdGF0ZShraW5kLCBtZXNzYWdlKSB7XG4gICAgICAgICAgICBpZiAoIW1lc3NhZ2UpIHtcbiAgICAgICAgICAgICAgICAkc3RhdGUucHJvcCgnaGlkZGVuJywgdHJ1ZSkucmVtb3ZlQ2xhc3MoJ2luZm8gd2FybmluZyBlcnJvcicpLnRleHQoJycpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgJHN0YXRlXG4gICAgICAgICAgICAgICAgLnJlbW92ZUNsYXNzKCdpbmZvIHdhcm5pbmcgZXJyb3InKVxuICAgICAgICAgICAgICAgIC5hZGRDbGFzcyhraW5kIHx8ICdpbmZvJylcbiAgICAgICAgICAgICAgICAudGV4dChtZXNzYWdlKVxuICAgICAgICAgICAgICAgIC5wcm9wKCdoaWRkZW4nLCBmYWxzZSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmaWx0ZXJFbnRyaWVzKGVudHJpZXMpIHtcbiAgICAgICAgICAgIHZhciBsZXZlbCA9IFN0cmluZygkbGV2ZWxGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIGNvbXBvbmVudCA9IFN0cmluZygkY29tcG9uZW50RmlsdGVyLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBxdWVyeSA9IFN0cmluZygkc2VhcmNoLnZhbCgpIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICAgICAgcmV0dXJuIGVudHJpZXMuZmlsdGVyKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIGlmIChsZXZlbCAmJiBlbnRyeS5sZXZlbCAhPT0gbGV2ZWwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoY29tcG9uZW50ICYmIGVudHJ5LmNvbXBvbmVudCAhPT0gY29tcG9uZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKCFxdWVyeSkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICB2YXIgaGF5c3RhY2sgPSBbXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5Lm1lc3NhZ2UsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LmNvbXBvbmVudCxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuZXZlbnQsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LnNldmVyaXR5LFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5waWQsXG4gICAgICAgICAgICAgICAgICAgIEpTT04uc3RyaW5naWZ5KGVudHJ5LmNvbnRleHQgfHwge30pXG4gICAgICAgICAgICAgICAgXS5qb2luKCcgJykudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBoYXlzdGFjay5pbmRleE9mKHF1ZXJ5KSAhPT0gLTE7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlckVudHJpZXMoZW50cmllcykge1xuICAgICAgICAgICAgJHJlc3VsdHMuZW1wdHkoKTtcbiAgICAgICAgICAgIGVudHJpZXMuZm9yRWFjaChmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICB2YXIgJGNhcmQgPSAkKCc8YXJ0aWNsZT4nKS5hZGRDbGFzcygnc3R0LWxvZy1lbnRyeSBzdHQtbG9nLWxldmVsLScgKyBlbnRyeS5sZXZlbCk7XG4gICAgICAgICAgICAgICAgdmFyICRoZWFkZXIgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5LWhlYWRlcicpO1xuICAgICAgICAgICAgICAgIHZhciAkaWRlbnRpdHkgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5LWlkZW50aXR5Jyk7XG4gICAgICAgICAgICAgICAgdmFyICRsZXZlbCA9ICQoJzxzcGFuPicpLmFkZENsYXNzKCd1aSB0aW55IGxhYmVsIHN0dC1sb2ctbGV2ZWwtbGFiZWwnKS50ZXh0KGxldmVsTmFtZShlbnRyeS5sZXZlbCkpO1xuICAgICAgICAgICAgICAgIHZhciAkY29tcG9uZW50ID0gJCgnPHNwYW4+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctY29tcG9uZW50JykudGV4dChjb21wb25lbnROYW1lKGVudHJ5LmNvbXBvbmVudCkpO1xuICAgICAgICAgICAgICAgIHZhciAkdGltZSA9ICQoJzx0aW1lPicpLmFkZENsYXNzKCdzdHQtbG9nLXRpbWUnKS50ZXh0KGZvcm1hdFRpbWVzdGFtcChlbnRyeS50cykpO1xuICAgICAgICAgICAgICAgIHZhciAkbWVzc2FnZSA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctbWVzc2FnZScpLnRleHQoZW50cnkubWVzc2FnZSB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgdmFyICRkZXRhaWxzID0gJCgnPGRldGFpbHM+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZGV0YWlscycpO1xuICAgICAgICAgICAgICAgIHZhciAkc3VtbWFyeSA9ICQoJzxzdW1tYXJ5PicpLnRleHQodHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1RlY2huaWNhbERldGFpbHMnLFxuICAgICAgICAgICAgICAgICAgICAnVGVjaG5pY2FsIGRldGFpbHMnXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgdmFyIGRldGFpbFRleHQgPSB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NFdmVudCcsICdFdmVudCcpXG4gICAgICAgICAgICAgICAgICAgICsgJzogJyArIChlbnRyeS5ldmVudCB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxuc2V2ZXJpdHk6ICcgKyAoZW50cnkuc2V2ZXJpdHkgfHwgJ+KAlCcpO1xuICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbnBpZDogJyArIChlbnRyeS5waWQgPT09IHVuZGVmaW5lZCA/ICfigJQnIDogZW50cnkucGlkKTtcbiAgICAgICAgICAgICAgICBpZiAoZW50cnkuY29udGV4dCAmJiBPYmplY3Qua2V5cyhlbnRyeS5jb250ZXh0KS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbicgKyBKU09OLnN0cmluZ2lmeShlbnRyeS5jb250ZXh0LCBudWxsLCAyKTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICAkaWRlbnRpdHkuYXBwZW5kKCRsZXZlbCwgJGNvbXBvbmVudCk7XG4gICAgICAgICAgICAgICAgJGhlYWRlci5hcHBlbmQoJGlkZW50aXR5LCAkdGltZSk7XG4gICAgICAgICAgICAgICAgJGRldGFpbHMuYXBwZW5kKCRzdW1tYXJ5LCAkKCc8cHJlPicpLnRleHQoZGV0YWlsVGV4dCkpO1xuICAgICAgICAgICAgICAgICRjYXJkLmFwcGVuZCgkaGVhZGVyLCAkbWVzc2FnZSwgJGRldGFpbHMpO1xuICAgICAgICAgICAgICAgICRyZXN1bHRzLmFwcGVuZCgkY2FyZCk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZUNvbXBvbmVudE9wdGlvbnMoZW50cmllcykge1xuICAgICAgICAgICAgdmFyIHNlbGVjdGVkID0gU3RyaW5nKCRjb21wb25lbnRGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIGNvbXBvbmVudHMgPSBbXTtcbiAgICAgICAgICAgIGVudHJpZXMuZm9yRWFjaChmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICBpZiAoZW50cnkuY29tcG9uZW50ICYmIGNvbXBvbmVudHMuaW5kZXhPZihlbnRyeS5jb21wb25lbnQpID09PSAtMSkge1xuICAgICAgICAgICAgICAgICAgICBjb21wb25lbnRzLnB1c2goZW50cnkuY29tcG9uZW50KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIGNvbXBvbmVudHMuc29ydChmdW5jdGlvbiAobGVmdCwgcmlnaHQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gY29tcG9uZW50TmFtZShsZWZ0KS5sb2NhbGVDb21wYXJlKGNvbXBvbmVudE5hbWUocmlnaHQpKTtcbiAgICAgICAgICAgIH0pO1xuXG4gICAgICAgICAgICAkY29tcG9uZW50RmlsdGVyLmVtcHR5KCkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxvcHRpb24+JykudmFsKCcnKS50ZXh0KHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NDb21wb25lbnRBbGwnLFxuICAgICAgICAgICAgICAgICAgICAnQWxsIGNvbXBvbmVudHMnXG4gICAgICAgICAgICAgICAgKSlcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICBjb21wb25lbnRzLmZvckVhY2goZnVuY3Rpb24gKGNvbXBvbmVudCkge1xuICAgICAgICAgICAgICAgICRjb21wb25lbnRGaWx0ZXIuYXBwZW5kKCQoJzxvcHRpb24+JykudmFsKGNvbXBvbmVudCkudGV4dChjb21wb25lbnROYW1lKGNvbXBvbmVudCkpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgaWYgKHNlbGVjdGVkICYmIGNvbXBvbmVudHMuaW5kZXhPZihzZWxlY3RlZCkgIT09IC0xKSB7XG4gICAgICAgICAgICAgICAgJGNvbXBvbmVudEZpbHRlci52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlTWV0YShjb3VudCwgcmVzcG9uc2UpIHtcbiAgICAgICAgICAgIHZhciBmb3VuZCA9IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0ZvdW5kJywgJyVjb3VudCUgZXZlbnRzJylcbiAgICAgICAgICAgICAgICAucmVwbGFjZSgnJWNvdW50JScsIGNvdW50KTtcbiAgICAgICAgICAgIHZhciB1cGRhdGVkID0gdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVXBkYXRlZCcsICdVcGRhdGVkOiAldGltZSUnKVxuICAgICAgICAgICAgICAgIC5yZXBsYWNlKCcldGltZSUnLCByZXNwb25zZSAmJiByZXNwb25zZS5nZW5lcmF0ZWRBdFxuICAgICAgICAgICAgICAgICAgICA/IHJlc3BvbnNlLmdlbmVyYXRlZEF0XG4gICAgICAgICAgICAgICAgICAgIDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzTmV2ZXInLCAnbmV2ZXInKSk7XG4gICAgICAgICAgICAkcmVzdWx0Q291bnQudGV4dChmb3VuZCk7XG4gICAgICAgICAgICAkdXBkYXRlZEF0LnRleHQodXBkYXRlZCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJDdXJyZW50KCkge1xuICAgICAgICAgICAgaWYgKCFjdXJyZW50UmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgZmlsdGVyZWQgPSBmaWx0ZXJFbnRyaWVzKGN1cnJlbnRFbnRyaWVzKTtcbiAgICAgICAgICAgIHJlbmRlckVudHJpZXMoZmlsdGVyZWQpO1xuICAgICAgICAgICAgdXBkYXRlTWV0YShmaWx0ZXJlZC5sZW5ndGgsIGN1cnJlbnRSZXNwb25zZSk7XG5cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAnbWlzc2luZycpIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NNaXNzaW5nJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBtb2R1bGUgam91cm5hbCBoYXMgbm90IGJlZW4gY3JlYXRlZCB5ZXQuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAndW5hdmFpbGFibGUnKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1VuYXZhaWxhYmxlJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBtb2R1bGUgam91cm5hbCBpcyB1bmF2YWlsYWJsZS4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdjb3JydXB0Jykge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdlcnJvcicsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NDb3JydXB0JyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBqb3VybmFsIGNvbnRhaW5zIG5vIHJlYWRhYmxlIGV2ZW50cy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRFbnRyaWVzLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0VtcHR5JyxcbiAgICAgICAgICAgICAgICAgICAgJ05vIG1vZHVsZSBldmVudHMgd2VyZSBmb3VuZCBmb3IgdGhpcyBwZXJpb2QuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChmaWx0ZXJlZC5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NGaWx0ZXJlZEVtcHR5JyxcbiAgICAgICAgICAgICAgICAgICAgJ05vIGV2ZW50cyBtYXRjaCB0aGUgc2VsZWN0ZWQgZmlsdGVycy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICB2YXIgbm90aWNlcyA9IFtdO1xuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdwYXJ0aWFsJykge1xuICAgICAgICAgICAgICAgIG5vdGljZXMucHVzaCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzUGFydGlhbCcsXG4gICAgICAgICAgICAgICAgICAgICclY291bnQlIGRhbWFnZWQgam91cm5hbCBsaW5lcyB3ZXJlIHNraXBwZWQuJ1xuICAgICAgICAgICAgICAgICkucmVwbGFjZSgnJWNvdW50JScsIGN1cnJlbnRSZXNwb25zZS5tYWxmb3JtZWRDb3VudCB8fCAwKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnRydW5jYXRlZCkge1xuICAgICAgICAgICAgICAgIG5vdGljZXMucHVzaCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVHJ1bmNhdGVkJyxcbiAgICAgICAgICAgICAgICAgICAgJ09ubHkgdGhlIG5ld2VzdCAxMDAwIGV2ZW50cyBhcmUgc2hvd24uJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmVuZGVyU3RhdGUobm90aWNlcy5sZW5ndGggPiAwID8gJ3dhcm5pbmcnIDogJycsIG5vdGljZXMuam9pbignICcpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNldExvYWRpbmcoKSB7XG4gICAgICAgICAgICAkc2VnbWVudC5hZGRDbGFzcygnc3R0LWxvZ3MtbG9hZGluZycpO1xuICAgICAgICAgICAgJHJlc3VsdHMuZW1wdHkoKTtcbiAgICAgICAgICAgIHVwZGF0ZU1ldGEoMCwgbnVsbCk7XG4gICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0xvYWRpbmcnLFxuICAgICAgICAgICAgICAgICdMb2FkaW5nIG1vZHVsZSBldmVudHMuLi4nXG4gICAgICAgICAgICApKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGxvYWRMb2dzKGZvcmNlKSB7XG4gICAgICAgICAgICBpZiAoIWZvcmNlICYmIGxvYWRlZE9uY2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoIWRhdGFVcmwpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXF1ZXN0R2VuZXJhdGlvbiArPSAxO1xuICAgICAgICAgICAgdmFyIHJlcXVlc3RJZCA9IHJlcXVlc3RHZW5lcmF0aW9uO1xuICAgICAgICAgICAgaWYgKHJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICByZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHNldExvYWRpbmcoKTtcbiAgICAgICAgICAgICRyZWZyZXNoQnV0dG9uLmFkZENsYXNzKCdsb2FkaW5nIGRpc2FibGVkJyk7XG4gICAgICAgICAgICB2YXIgY3VycmVudFJlcXVlc3QgPSAkLmFqYXgoe1xuICAgICAgICAgICAgICAgIHVybDogZGF0YVVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdHRVQnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YToge1xuICAgICAgICAgICAgICAgICAgICBwZXJpb2Q6IGN1cnJlbnRQZXJpb2RcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5kb25lKGZ1bmN0aW9uIChyZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY3VycmVudFJlc3BvbnNlID0gcmVzcG9uc2UgfHwge307XG4gICAgICAgICAgICAgICAgY3VycmVudEVudHJpZXMgPSBBcnJheS5pc0FycmF5KGN1cnJlbnRSZXNwb25zZS5lbnRyaWVzKSA/IGN1cnJlbnRSZXNwb25zZS5lbnRyaWVzIDogW107XG4gICAgICAgICAgICAgICAgdXBkYXRlQ29tcG9uZW50T3B0aW9ucyhjdXJyZW50RW50cmllcyk7XG4gICAgICAgICAgICAgICAgcmVuZGVyQ3VycmVudCgpO1xuICAgICAgICAgICAgICAgIGxvYWRlZE9uY2UgPSB0cnVlO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyLCBzdGF0dXMpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbiB8fCBzdGF0dXMgPT09ICdhYm9ydCcpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjdXJyZW50UmVzcG9uc2UgPSBudWxsO1xuICAgICAgICAgICAgICAgIGN1cnJlbnRFbnRyaWVzID0gW107XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0Vycm9yJyxcbiAgICAgICAgICAgICAgICAgICAgJ1VuYWJsZSB0byBsb2FkIG1vZHVsZSBldmVudHMuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgfSkuYWx3YXlzKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0ID09PSBjdXJyZW50UmVxdWVzdCkge1xuICAgICAgICAgICAgICAgICAgICByZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgJHNlZ21lbnQucmVtb3ZlQ2xhc3MoJ3N0dC1sb2dzLWxvYWRpbmcnKTtcbiAgICAgICAgICAgICAgICAkcmVmcmVzaEJ1dHRvbi5yZW1vdmVDbGFzcygnbG9hZGluZyBkaXNhYmxlZCcpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICByZXF1ZXN0ID0gY3VycmVudFJlcXVlc3Q7XG4gICAgICAgIH1cblxuICAgICAgICAkcGVyaW9kQnV0dG9ucy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJGJ1dHRvbiA9ICQodGhpcyk7XG4gICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gcGFyc2VJbnQoJGJ1dHRvbi5kYXRhKCdwZXJpb2QnKSwgMTApO1xuICAgICAgICAgICAgaWYgKCFOdW1iZXIuaXNGaW5pdGUoY3VycmVudFBlcmlvZCkpIHtcbiAgICAgICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gMzYwMDtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgICRwZXJpb2RCdXR0b25zLnJlbW92ZUNsYXNzKCdhY3RpdmUnKTtcbiAgICAgICAgICAgICRidXR0b24uYWRkQ2xhc3MoJ2FjdGl2ZScpO1xuICAgICAgICAgICAgbG9hZGVkT25jZSA9IGZhbHNlO1xuICAgICAgICAgICAgbG9hZExvZ3ModHJ1ZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRyZWZyZXNoQnV0dG9uLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGxvYWRMb2dzKHRydWUpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbGV2ZWxGaWx0ZXIuYWRkKCRjb21wb25lbnRGaWx0ZXIpLm9uKCdjaGFuZ2UnLCByZW5kZXJDdXJyZW50KTtcbiAgICAgICAgJHNlYXJjaC5vbignaW5wdXQnLCByZW5kZXJDdXJyZW50KTtcblxuICAgICAgICAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1lbnUgLml0ZW1bZGF0YS10YWI9XCJsb2dzXCJdJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgd2luZG93LnNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGxvYWRMb2dzKGZhbHNlKTtcbiAgICAgICAgICAgIH0sIDApO1xuICAgICAgICB9KTtcblxuICAgICAgICB1cGRhdGVNZXRhKDAsIG51bGwpO1xuICAgICAgICBpZiAocmVxdWVzdGVkVGFiKCkgPT09ICdsb2dzJyB8fCAkZm9ybS5jbG9zZXN0KCcudGFiLnNlZ21lbnQnKS5oYXNDbGFzcygnYWN0aXZlJykpIHtcbiAgICAgICAgICAgIGxvYWRMb2dzKGZhbHNlKTtcbiAgICAgICAgfVxuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyKCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyICRzbGlkZXIgPSAkKCcjc3R0LWNkci1oaXN0b3J5LWRheXMtc2xpZGVyJyk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJjZHJfaGlzdG9yeV9kYXlzXCJdJyk7XG5cbiAgICAgICAgaWYgKCRzbGlkZXIubGVuZ3RoID09PSAwIHx8ICRpbnB1dC5sZW5ndGggPT09IDAgfHwgdHlwZW9mICRzbGlkZXIuc2xpZGVyICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgdmFsdWVzID0gWycxJywgJzcnLCAnMzAnLCAnOTAnLCAnMTgwJywgJzM2NScsICcwJ107XG4gICAgICAgIHZhciBsYWJlbHMgPSB7XG4gICAgICAgICAgICAwOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkxRGF5JywgJzEgZGF5JyksXG4gICAgICAgICAgICAxOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3Rvcnk3RGF5cycsICc3IGRheXMnKSxcbiAgICAgICAgICAgIDI6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTMwRGF5cycsICczMCBkYXlzJyksXG4gICAgICAgICAgICAzOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3Rvcnk5MERheXMnLCAnOTAgZGF5cycpLFxuICAgICAgICAgICAgNDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MTgwRGF5cycsICc2IG1vbnRocycpLFxuICAgICAgICAgICAgNTogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MzY1RGF5cycsICcxIHllYXInKSxcbiAgICAgICAgICAgIDY6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeUFsbCcsICdBbGwnKVxuICAgICAgICB9O1xuICAgICAgICB2YXIgY3VycmVudFZhbHVlID0gU3RyaW5nKCRpbnB1dC52YWwoKSB8fCAnMzAnKTtcbiAgICAgICAgdmFyIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKGN1cnJlbnRWYWx1ZSk7XG4gICAgICAgIHZhciBpbml0aWFsaXplZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmIChpbml0aWFsSW5kZXggPCAwKSB7XG4gICAgICAgICAgICBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZignMzAnKTtcbiAgICAgICAgICAgICRpbnB1dC52YWwoJzMwJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2xpZGVyLnNsaWRlcih7XG4gICAgICAgICAgICBtaW46IDAsXG4gICAgICAgICAgICBtYXg6IHZhbHVlcy5sZW5ndGggLSAxLFxuICAgICAgICAgICAgc3RlcDogMSxcbiAgICAgICAgICAgIHNtb290aDogdHJ1ZSxcbiAgICAgICAgICAgIGF1dG9BZGp1c3RMYWJlbHM6IGZhbHNlLFxuICAgICAgICAgICAgaW50ZXJwcmV0TGFiZWw6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBsYWJlbHNbdmFsdWVdIHx8ICcnO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG9uQ2hhbmdlOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgc2VsZWN0ZWQgPSB2YWx1ZXNbdmFsdWVdIHx8ICczMCc7XG5cbiAgICAgICAgICAgICAgICAkaW5wdXQudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgICAgICBpZiAoaW5pdGlhbGl6ZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgJGlucHV0LnRyaWdnZXIoJ2NoYW5nZScpO1xuICAgICAgICAgICAgICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiB0eXBlb2YgRm9ybS5jaGVja1ZhbHVlcyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgRm9ybS5jaGVja1ZhbHVlcygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNsaWRlci5zbGlkZXIoJ3NldCB2YWx1ZScsIGluaXRpYWxJbmRleCwgZmFsc2UpO1xuICAgICAgICBpbml0aWFsaXplZCA9IHRydWU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgJHNsaWRlciA9ICQoJyNzdHQtdHJhbnNjcmlwdC1yZXRlbnRpb24tZGF5cy1zbGlkZXInKTtcbiAgICAgICAgdmFyICRpbnB1dCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXNcIl0nKTtcblxuICAgICAgICBpZiAoJHNsaWRlci5sZW5ndGggPT09IDAgfHwgJGlucHV0Lmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJHNsaWRlci5zbGlkZXIgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciB2YWx1ZXMgPSBbJzMwJywgJzkwJywgJzE4MCcsICczNjUnLCAnMCddO1xuICAgICAgICB2YXIgbGFiZWxzID0ge1xuICAgICAgICAgICAgMDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uMzBEYXlzJywgJzMwIGRheXMnKSxcbiAgICAgICAgICAgIDE6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjkwRGF5cycsICc5MCBkYXlzJyksXG4gICAgICAgICAgICAyOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24xODBEYXlzJywgJzE4MCBkYXlzJyksXG4gICAgICAgICAgICAzOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24zNjVEYXlzJywgJzEgeWVhcicpLFxuICAgICAgICAgICAgNDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uVW5saW1pdGVkJywgJ1VubGltaXRlZCcpXG4gICAgICAgIH07XG4gICAgICAgIHZhciBjdXJyZW50VmFsdWUgPSBTdHJpbmcoJGlucHV0LnZhbCgpIHx8ICczNjUnKTtcbiAgICAgICAgdmFyIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKGN1cnJlbnRWYWx1ZSk7XG4gICAgICAgIHZhciBpbml0aWFsaXplZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmIChpbml0aWFsSW5kZXggPCAwKSB7XG4gICAgICAgICAgICBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZignMzY1Jyk7XG4gICAgICAgICAgICAkaW5wdXQudmFsKCczNjUnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzbGlkZXIuc2xpZGVyKHtcbiAgICAgICAgICAgIG1pbjogMCxcbiAgICAgICAgICAgIG1heDogdmFsdWVzLmxlbmd0aCAtIDEsXG4gICAgICAgICAgICBzdGVwOiAxLFxuICAgICAgICAgICAgc21vb3RoOiB0cnVlLFxuICAgICAgICAgICAgYXV0b0FkanVzdExhYmVsczogZmFsc2UsXG4gICAgICAgICAgICBpbnRlcnByZXRMYWJlbDogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGxhYmVsc1t2YWx1ZV0gfHwgJyc7XG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgb25DaGFuZ2U6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBzZWxlY3RlZCA9IHZhbHVlc1t2YWx1ZV0gfHwgJzM2NSc7XG5cbiAgICAgICAgICAgICAgICAkaW5wdXQudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgICAgICBpZiAoaW5pdGlhbGl6ZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgJGlucHV0LnRyaWdnZXIoJ2NoYW5nZScpO1xuICAgICAgICAgICAgICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiB0eXBlb2YgRm9ybS5jaGVja1ZhbHVlcyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgRm9ybS5jaGVja1ZhbHVlcygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNsaWRlci5zbGlkZXIoJ3NldCB2YWx1ZScsIGluaXRpYWxJbmRleCwgZmFsc2UpO1xuICAgICAgICBpbml0aWFsaXplZCA9IHRydWU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gcmV0ZW50aW9uQWxsb3dzUHJvY2Vzc2luZyhwcm9jZXNzaW5nRGF5cywgcmV0ZW50aW9uRGF5cykge1xuICAgICAgICByZXR1cm4gcmV0ZW50aW9uRGF5cyA9PT0gMCB8fCAocHJvY2Vzc2luZ0RheXMgPiAwICYmIHByb2Nlc3NpbmdEYXlzIDw9IHJldGVudGlvbkRheXMpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyIHByb2Nlc3NpbmdEYXlzID0gTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cImNkcl9oaXN0b3J5X2RheXNcIl0nKS52YWwoKSk7XG4gICAgICAgIHZhciByZXRlbnRpb25EYXlzID0gTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXNcIl0nKS52YWwoKSk7XG4gICAgICAgIHZhciBjb21wYXRpYmxlID0gTnVtYmVyLmlzRmluaXRlKHByb2Nlc3NpbmdEYXlzKVxuICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKHJldGVudGlvbkRheXMpXG4gICAgICAgICAgICAmJiByZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nKHByb2Nlc3NpbmdEYXlzLCByZXRlbnRpb25EYXlzKTtcbiAgICAgICAgdmFyICRtZXNzYWdlID0gJHNldHRpbmdzRm9ybS5maW5kKCcuc3R0LXJldGVudGlvbi1jb25mbGljdC1tZXNzYWdlJyk7XG5cbiAgICAgICAgJG1lc3NhZ2UudG9nZ2xlQ2xhc3MoJ2hpZGRlbicsIGNvbXBhdGlibGUpLmF0dHIoJ2FyaWEtaGlkZGVuJywgY29tcGF0aWJsZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuXG4gICAgICAgIHJldHVybiBjb21wYXRpYmxlO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlKCRtb2RlbEZvcm0pIHtcbiAgICAgICAgdmFyICRzYXZlQnV0dG9uID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tb2RlbC1zYXZlJyk7XG4gICAgICAgIHZhciAkbW9kZWxJbnB1dHMgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgaW5wdXRbdHlwZT1cInJhZGlvXCJdW2RhdGEtcGxhdGZvcm1dJyk7XG4gICAgICAgIHZhciAkY29tcGF0aWJpbGl0eU1lc3NhZ2UgPSAkKCcjc3R0LW1vZGVsLWxhbmd1YWdlLWNvbXBhdGliaWxpdHknKTtcbiAgICAgICAgdmFyIHNlbGVjdGVkTGFuZ3VhZ2UgPSBTdHJpbmcoJG1vZGVsRm9ybS5kYXRhKCdzZWxlY3RlZC1sYW5ndWFnZScpIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTtcbiAgICAgICAgdmFyICRwbGF0Zm9ybUJ1dHRvbnMgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtcGxhdGZvcm0tc3dpdGNoIFtkYXRhLXBsYXRmb3JtXScpO1xuICAgICAgICAvLyBFdmVyeSBwbGF0Zm9ybSBrZWVwcyBpdHMgb3duIG1vZGVsOyBTYXZlIHN0b3JlcyB0aGUgcGxhdGZvcm1zIHdob3NlIGNob2ljZSBjaGFuZ2VkLlxuICAgICAgICB2YXIgaW5pdGlhbE1vZGVscyA9IHt9O1xuXG4gICAgICAgICRtb2RlbElucHV0cy5maWx0ZXIoJzpjaGVja2VkJykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpbml0aWFsTW9kZWxzW1N0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSldID0gU3RyaW5nKCQodGhpcykudmFsKCkpO1xuICAgICAgICB9KTtcblxuICAgICAgICBmdW5jdGlvbiBjaGFuZ2VkSW5wdXRzKCkge1xuICAgICAgICAgICAgcmV0dXJuICRtb2RlbElucHV0cy5maWx0ZXIoJzpjaGVja2VkJykuZmlsdGVyKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gaW5pdGlhbE1vZGVsc1tTdHJpbmcoJCh0aGlzKS5hdHRyKCdkYXRhLXBsYXRmb3JtJykpXSAhPT0gU3RyaW5nKCQodGhpcykudmFsKCkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBtb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoJGlucHV0KSB7XG4gICAgICAgICAgICB2YXIgc3VwcG9ydGVkTGFuZ3VhZ2VzO1xuXG4gICAgICAgICAgICBpZiAoc2VsZWN0ZWRMYW5ndWFnZSA9PT0gJycgfHwgc2VsZWN0ZWRMYW5ndWFnZSA9PT0gJ2F1dG8nKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHN1cHBvcnRlZExhbmd1YWdlcyA9IFN0cmluZygkaW5wdXQuYXR0cignZGF0YS1zdXBwb3J0ZWQtbGFuZ3VhZ2VzJykgfHwgJycpXG4gICAgICAgICAgICAgICAgLnNwbGl0KCcsJylcbiAgICAgICAgICAgICAgICAubWFwKGZ1bmN0aW9uIChsYW5ndWFnZSkgeyByZXR1cm4gbGFuZ3VhZ2UudHJpbSgpLnRvTG93ZXJDYXNlKCk7IH0pXG4gICAgICAgICAgICAgICAgLmZpbHRlcihmdW5jdGlvbiAobGFuZ3VhZ2UpIHsgcmV0dXJuIGxhbmd1YWdlICE9PSAnJzsgfSk7XG5cbiAgICAgICAgICAgIHJldHVybiBzdXBwb3J0ZWRMYW5ndWFnZXMuaW5kZXhPZihzZWxlY3RlZExhbmd1YWdlKSAhPT0gLTE7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzaG93UGxhdGZvcm0ocGxhdGZvcm0pIHtcbiAgICAgICAgICAgICRwbGF0Zm9ybUJ1dHRvbnMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGFjdGl2ZSA9IFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkgPT09IHBsYXRmb3JtO1xuXG4gICAgICAgICAgICAgICAgJCh0aGlzKS50b2dnbGVDbGFzcygnYWN0aXZlJywgYWN0aXZlKS5hdHRyKCdhcmlhLXByZXNzZWQnLCBhY3RpdmUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LW1vZGVsLW1hcmtldHBsYWNlLXRhYmxlIHRib2R5IHRyJykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgJCh0aGlzKS5wcm9wKCdoaWRkZW4nLCBTdHJpbmcoJCh0aGlzKS5hdHRyKCdkYXRhLXBsYXRmb3JtJykpICE9PSBwbGF0Zm9ybSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciAkY2hhbmdlZCA9IGNoYW5nZWRJbnB1dHMoKTtcbiAgICAgICAgICAgIHZhciBjb21wYXRpYmxlID0gdHJ1ZTtcbiAgICAgICAgICAgIHZhciBoYXNDaGFuZ2VzO1xuXG4gICAgICAgICAgICAkY2hhbmdlZC5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBjb21wYXRpYmxlID0gY29tcGF0aWJsZSAmJiBtb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoJCh0aGlzKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICRjb21wYXRpYmlsaXR5TWVzc2FnZVxuICAgICAgICAgICAgICAgIC50b2dnbGVDbGFzcygnaGlkZGVuJywgY29tcGF0aWJsZSlcbiAgICAgICAgICAgICAgICAuYXR0cignYXJpYS1oaWRkZW4nLCBjb21wYXRpYmxlID8gJ3RydWUnIDogJ2ZhbHNlJyk7XG5cbiAgICAgICAgICAgIGhhc0NoYW5nZXMgPSAkY2hhbmdlZC5sZW5ndGggPiAwICYmIGNvbXBhdGlibGU7XG4gICAgICAgICAgICAkc2F2ZUJ1dHRvbi50b2dnbGVDbGFzcygnZGlzYWJsZWQnLCAhaGFzQ2hhbmdlcyk7XG4gICAgICAgICAgICAkc2F2ZUJ1dHRvbi5wcm9wKCdkaXNhYmxlZCcsICFoYXNDaGFuZ2VzKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGlmICh0eXBlb2YgJC5mbi5hY2NvcmRpb24gPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1hZHZhbmNlZC1zZXR0aW5ncy1kcm9wZG93bicpLmFjY29yZGlvbigpO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LW1vZGVsLW1hcmtldHBsYWNlLXRhYmxlIHRib2R5IHRyJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBpZiAoJChldmVudC50YXJnZXQpLmNsb3Nlc3QoJ2EsIGJ1dHRvbiwgZm9ybSwgaW5wdXQsIGxhYmVsJykubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgJCh0aGlzKS5maW5kKCcudWkucmFkaW8uY2hlY2tib3gnKS5jaGVja2JveCgnY2hlY2snKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJHBsYXRmb3JtQnV0dG9ucy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzaG93UGxhdGZvcm0oU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRtb2RlbElucHV0cy5vbignY2hhbmdlJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyICRyb3cgPSAkKHRoaXMpLmNsb3Nlc3QoJ3RyJyk7XG5cbiAgICAgICAgICAgICRyb3cuYWRkQ2xhc3MoJ3Bvc2l0aXZlJylcbiAgICAgICAgICAgICAgICAuc2libGluZ3MoJ1tkYXRhLXBsYXRmb3JtPVwiJyArIFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkgKyAnXCJdJylcbiAgICAgICAgICAgICAgICAucmVtb3ZlQ2xhc3MoJ3Bvc2l0aXZlJyk7XG4gICAgICAgICAgICB1cGRhdGVTYXZlQnV0dG9uU3RhdGUoKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJG1vZGVsRm9ybS5vbignc3VibWl0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKCRzYXZlQnV0dG9uLnByb3AoJ2Rpc2FibGVkJykpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoJHNhdmVCdXR0b24ubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICRzYXZlQnV0dG9uLmFkZENsYXNzKCdsb2FkaW5nIGRpc2FibGVkIGFjdGl2ZScpO1xuICAgICAgICAgICAgICAgICRzYXZlQnV0dG9uLnByb3AoJ2Rpc2FibGVkJywgdHJ1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVDb25maXJtTW9kYWwoKSB7XG4gICAgICAgIHZhciAkbW9kYWwgPSAkKCcjc3R0LWNvbmZpcm0tbW9kYWwnKTtcblxuICAgICAgICBpZiAoJG1vZGFsLmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJG1vZGFsLm1vZGFsICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICAvLyBQcmUtaW5pdGlhbGl6ZSBzbyBTZW1hbnRpYyBVSSBtb3ZlcyB0aGUgZWxlbWVudCB0byA8Ym9keT4gYW5kIGhpZGVzIGl0IHByb3Blcmx5XG4gICAgICAgICRtb2RhbC5tb2RhbCh7fSk7XG5cbiAgICAgICAgZnVuY3Rpb24gc2hvd0NvbmZpcm0obWVzc2FnZSwgb25Db25maXJtKSB7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jb25maXJtLW1lc3NhZ2UnKS50ZXh0KG1lc3NhZ2UpO1xuICAgICAgICAgICAgJG1vZGFsLm1vZGFsKHtcbiAgICAgICAgICAgICAgICBjbG9zYWJsZTogdHJ1ZSxcbiAgICAgICAgICAgICAgICBvbkFwcHJvdmU6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgb25Db25maXJtKCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSkubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGJpbmRDb25maXJtYWJsZUZvcm0oJGZvcm0pIHtcbiAgICAgICAgICAgICRmb3JtLm9uKCdzdWJtaXQuc3R0LWNvbmZpcm0nLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgICAgICB2YXIgbWVzc2FnZSA9ICQodGhpcykuZGF0YSgnY29uZmlybScpIHx8ICcnO1xuXG4gICAgICAgICAgICAgICAgaWYgKG1lc3NhZ2UgPT09ICcnKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgIGV2ZW50LnN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbigpO1xuXG4gICAgICAgICAgICAgICAgdmFyICRzZWxmID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICBzaG93Q29uZmlybShtZXNzYWdlLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICRzZWxmLm9mZignc3VibWl0LnN0dC1jb25maXJtJykudHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1jb25maXJtYWJsZS1mb3JtJykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBiaW5kQ29uZmlybWFibGVGb3JtKCQodGhpcykpO1xuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICAvLyBUcmFuc2NyaXB0IGRlbGV0aW9uIGlzIGlycmV2ZXJzaWJsZSwgc28gaXQgbmVlZHMgYW4gZXhwbGljaXRcbiAgICAvLyBhY2tub3dsZWRnZW1lbnQ6IHRoZSBkaWFsb2cgbGlzdHMgd2hhdCBpcyBhbmQgaXMgbm90IHJlbW92ZWQsIHRoZVxuICAgIC8vIERlbGV0ZSBidXR0b24gc3RheXMgZGlzYWJsZWQgdW50aWwgdGhlIGNoZWNrYm94IGlzIHRpY2tlZCwgYW5kIG9ubHkgdGhlblxuICAgIC8vIGlzIGNvbmZpcm1fZGVsZXRlIGFkZGVkIHRvIHRoZSBmb3JtIHRoZSBzZXJ2ZXIgcmVxdWlyZXMgaXQgb24uXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHREZWxldGlvbigpIHtcbiAgICAgICAgdmFyICRtb2RhbCA9ICQoJyNzdHQtZGVsZXRlLXRyYW5zY3JpcHQtbW9kYWwnKTtcbiAgICAgICAgdmFyICRmb3JtcyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1kZWxldGUtdHJhbnNjcmlwdC1mb3JtJyk7XG5cbiAgICAgICAgaWYgKCRtb2RhbC5sZW5ndGggPT09IDAgfHwgJGZvcm1zLmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJG1vZGFsLm1vZGFsICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgJGFja25vd2xlZGdlID0gJG1vZGFsLmZpbmQoJyNzdHQtZGVsZXRlLXRyYW5zY3JpcHQtYWNrbm93bGVkZ2UnKTtcbiAgICAgICAgdmFyICRhcHByb3ZlID0gJG1vZGFsLmZpbmQoJy5hcHByb3ZlLmJ1dHRvbicpO1xuICAgICAgICB2YXIgcGVuZGluZ0Zvcm0gPSBudWxsO1xuXG4gICAgICAgIGZ1bmN0aW9uIHNldEFwcHJvdmVFbmFibGVkKGVuYWJsZWQpIHtcbiAgICAgICAgICAgICRhcHByb3ZlLnRvZ2dsZUNsYXNzKCdkaXNhYmxlZCcsICFlbmFibGVkKS5wcm9wKCdkaXNhYmxlZCcsICFlbmFibGVkKTtcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFByZS1pbml0aWFsaXplIHNvIFNlbWFudGljIFVJIG1vdmVzIHRoZSBlbGVtZW50IHRvIDxib2R5PiBhbmQgaGlkZXMgaXQgcHJvcGVybHlcbiAgICAgICAgJG1vZGFsLm1vZGFsKHt9KTtcbiAgICAgICAgJGFja25vd2xlZGdlLm9uKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZXRBcHByb3ZlRW5hYmxlZCgkYWNrbm93bGVkZ2UucHJvcCgnY2hlY2tlZCcpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGZvcm1zLm9uKCdzdWJtaXQuc3R0LWRlbGV0ZS10cmFuc2NyaXB0JywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgcGVuZGluZ0Zvcm0gPSB0aGlzO1xuICAgICAgICAgICAgJCh0aGlzKS5jbG9zZXN0KCdkZXRhaWxzJykucHJvcCgnb3BlbicsIGZhbHNlKTtcbiAgICAgICAgICAgICRhY2tub3dsZWRnZS5wcm9wKCdjaGVja2VkJywgZmFsc2UpO1xuICAgICAgICAgICAgc2V0QXBwcm92ZUVuYWJsZWQoZmFsc2UpO1xuICAgICAgICAgICAgJG1vZGFsLm1vZGFsKHtcbiAgICAgICAgICAgICAgICBjbG9zYWJsZTogdHJ1ZSxcbiAgICAgICAgICAgICAgICBvbkFwcHJvdmU6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKCEkYWNrbm93bGVkZ2UucHJvcCgnY2hlY2tlZCcpIHx8IHBlbmRpbmdGb3JtID09PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgdmFyIGZvcm0gPSBwZW5kaW5nRm9ybTtcbiAgICAgICAgICAgICAgICAgICAgcGVuZGluZ0Zvcm0gPSBudWxsO1xuICAgICAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+JywgeyB0eXBlOiAnaGlkZGVuJywgbmFtZTogJ2NvbmZpcm1fZGVsZXRlJywgdmFsdWU6ICcxJyB9KS5hcHBlbmRUbyhmb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgLy8gVGhlIG5hdGl2ZSBzdWJtaXQgc2tpcHMgdGhpcyBoYW5kbGVyLCBzbyB0aGUgZGlhbG9nIGlzIG5vdCBzaG93biBhZ2Fpbi5cbiAgICAgICAgICAgICAgICAgICAgZm9ybS5zdWJtaXQoKTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICAgICAgfSxcbiAgICAgICAgICAgICAgICBvbkhpZGRlbjogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBwZW5kaW5nRm9ybSA9IG51bGw7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSkubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRSb3dzKCkge1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtdHJhbnNjcmlwdC1yb3cnKS5vbignY2xpY2snLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmICgkKGV2ZW50LnRhcmdldCkuY2xvc2VzdCgnYSwgYnV0dG9uLCBpbnB1dCwgc2VsZWN0LCB0ZXh0YXJlYScpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHZhciBkZXN0aW5hdGlvbiA9ICQodGhpcykuZGF0YSgndHJhbnNjcmlwdC11cmwnKTtcbiAgICAgICAgICAgIGlmIChkZXN0aW5hdGlvbikge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5sb2NhdGlvbi5ocmVmID0gZGVzdGluYXRpb247XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0QWN0aW9uTWVudSgpIHtcbiAgICAgICAgdmFyICRtZW51cyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC10cmFuc2NyaXB0LWFjdGlvbnMnKTtcblxuICAgICAgICAkbWVudXMuZmluZCgnLm1lbnUgYSwgLm1lbnUgYnV0dG9uJykub24oJ2NsaWNrLnN0dC10cmFuc2NyaXB0LWFjdGlvbnMnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAkKHRoaXMpLmNsb3Nlc3QoJ2RldGFpbHMnKS5yZW1vdmVBdHRyKCdvcGVuJyk7XG4gICAgICAgIH0pO1xuICAgICAgICAkKGRvY3VtZW50KS5vbignY2xpY2suc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgJG1lbnVzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGlmICh0aGlzICE9PSBldmVudC50YXJnZXQgJiYgIXRoaXMuY29udGFpbnMoZXZlbnQudGFyZ2V0KSkge1xuICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnJlbW92ZUF0dHIoJ29wZW4nKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRMZWdUYWJzKCkge1xuICAgICAgICB2YXIgdHJhbnNjcmlwdExvY2F0aW9uID0gd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3ICYmIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5sb2NhdGlvbjtcblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtY2FsbC1sZWctcmV2aWV3JykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJHJldmlldyA9ICQodGhpcyk7XG4gICAgICAgICAgICB2YXIgJHRhYnMgPSAkcmV2aWV3LmZpbmQoJ1tkYXRhLXN0dC1jYWxsLWxlZ10nKTtcbiAgICAgICAgICAgIHZhciAkcGFuZWxzID0gJHJldmlldy5maW5kKCdbZGF0YS1zdHQtY2FsbC1sZWctcGFuZWxdJyk7XG5cbiAgICAgICAgICAgIGZ1bmN0aW9uIHBhbmVsTGVnKCRlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIFN0cmluZygkZWxlbWVudC5jbG9zZXN0KCdbZGF0YS1zdHQtY2FsbC1sZWctcGFuZWxdJykuZGF0YSgnc3R0LWNhbGwtbGVnLXBhbmVsJykgfHwgJycpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBmdW5jdGlvbiBzaG93KGNhbGxMZWcpIHtcbiAgICAgICAgICAgICAgICAkdGFicy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgdmFyIGFjdGl2ZSA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3N0dC1jYWxsLWxlZycpIHx8ICcnKSA9PT0gY2FsbExlZztcbiAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS50b2dnbGVDbGFzcygnYWN0aXZlJywgYWN0aXZlKS5hdHRyKCdhcmlhLXNlbGVjdGVkJywgYWN0aXZlID8gJ3RydWUnIDogJ2ZhbHNlJyk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgJHBhbmVscy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgdmFyIGhpZGRlbiA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3N0dC1jYWxsLWxlZy1wYW5lbCcpIHx8ICcnKSAhPT0gY2FsbExlZztcblxuICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnByb3AoJ2hpZGRlbicsIGhpZGRlbik7XG4gICAgICAgICAgICAgICAgICAgIGlmIChoaWRkZW4gJiYgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3ICYmIHR5cGVvZiB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcucGF1c2UgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5wYXVzZSh0aGlzKTtcbiAgICAgICAgICAgICAgICAgICAgfSBlbHNlIGlmIChoaWRkZW4pIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICQodGhpcykuZmluZCgnYXVkaW8nKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICB2YXIgYXVkaW8gPSB0aGlzO1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIGF1ZGlvLnBhdXNlKCk7XG4gICAgICAgICAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAkdGFicy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGNhbGxMZWcgPSBTdHJpbmcoJCh0aGlzKS5kYXRhKCdzdHQtY2FsbC1sZWcnKSB8fCAnJyk7XG5cbiAgICAgICAgICAgICAgICBzaG93KGNhbGxMZWcpO1xuICAgICAgICAgICAgICAgIGlmICh0cmFuc2NyaXB0TG9jYXRpb24pIHtcbiAgICAgICAgICAgICAgICAgICAgLy8gQSBoZWxkIG1vbWVudCBpbiB0aGUgb3RoZXIgbGVnIG5vIGxvbmdlciBkZXNjcmliZXMgd2hhdCBpcyBvbiBzY3JlZW4uXG4gICAgICAgICAgICAgICAgICAgIHZhciBtb21lbnQgPSB0cmFuc2NyaXB0TG9jYXRpb24ucmVhZCh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgICAgICAgICAgICAgdmFyICRzdGFnZSA9IG1vbWVudC5zdGFnZSA/ICRyZXZpZXcuZmluZCgnLnN0dC10cmFuc2NyaXB0LXZpZXdbZGF0YS1zdHQtc3RhZ2U9XCInICsgbW9tZW50LnN0YWdlICsgJ1wiXScpIDogJCgpO1xuICAgICAgICAgICAgICAgICAgICB2YXIgY2hhbmdlcyA9IHsgbGVnOiBjYWxsTGVnIH07XG4gICAgICAgICAgICAgICAgICAgIGlmICgkc3RhZ2UubGVuZ3RoID4gMCAmJiBwYW5lbExlZygkc3RhZ2UpICE9PSBjYWxsTGVnKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjaGFuZ2VzLnN0YWdlID0gJyc7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgdHJhbnNjcmlwdExvY2F0aW9uLndyaXRlKGNoYW5nZXMpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuXG4gICAgICAgICAgICAvLyBUaGUgYWRkcmVzcyBwaWNrcyB0aGUgbGVnOiB0aGUgbGVnIG9mIGEgc2hhcmVkIG1vbWVudCBmaXJzdCwgdGhlbiBhbiBleHBsaWNpdCBsZWcuXG4gICAgICAgICAgICBpZiAodHJhbnNjcmlwdExvY2F0aW9uKSB7XG4gICAgICAgICAgICAgICAgdmFyIGluaXRpYWwgPSB0cmFuc2NyaXB0TG9jYXRpb24ucmVhZCh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgICAgICAgICB2YXIgJHRhcmdldCA9IGluaXRpYWwuc3RhZ2UgPyAkcmV2aWV3LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC12aWV3W2RhdGEtc3R0LXN0YWdlPVwiJyArIGluaXRpYWwuc3RhZ2UgKyAnXCJdJykgOiAkKCk7XG4gICAgICAgICAgICAgICAgdmFyIGxlZyA9ICR0YXJnZXQubGVuZ3RoID4gMCA/IHBhbmVsTGVnKCR0YXJnZXQpIDogaW5pdGlhbC5sZWc7XG4gICAgICAgICAgICAgICAgaWYgKGxlZyAhPT0gJycgJiYgJHRhYnMuZmlsdGVyKCdbZGF0YS1zdHQtY2FsbC1sZWc9XCInICsgbGVnICsgJ1wiXScpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgc2hvdyhsZWcpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgJChmdW5jdGlvbiAoKSB7XG4gICAgICAgIC8vIE9ubHkgaXRlbXMgd2l0aCBhIGRhdGEtdGFiIHRhcmdldCBhcmUgY2xpZW50LXNpZGUgdGFicy4gVGhlXG4gICAgICAgIC8vIFRyYW5zY3JpcHRzIGl0ZW0gaXMgYSByZWFsIGxpbmsgKD90YWI9dHJhbnNjcmlwdHMsIGZ1bGwgcGFnZVxuICAgICAgICAvLyBuYXZpZ2F0aW9uKSBiZWNhdXNlIGl0cyBjb250ZW50IGlzIG9ubHkgcmVuZGVyZWQgc2VydmVyLXNpZGUgd2hlblxuICAgICAgICAvLyB0aGF0IHRhYiBpcyB0aGUgb25lIGJlaW5nIGxvYWRlZCAtIHNlZSBpbmRleC52b2x0L0luZGV4Vmlld1NlcnZpY2UuXG4gICAgICAgIHZhciAkdGFiTWVudSA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbWVudScpO1xuICAgICAgICB2YXIgJHRhYkl0ZW1zID0gJHRhYk1lbnUuZmluZCgnLml0ZW1bZGF0YS10YWJdJyk7XG4gICAgICAgIHZhciAkc2V0dGluZ3NGb3JtID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1mb3JtJyk7XG4gICAgICAgIHZhciAkbW9kZWxGb3JtID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tb2RlbC1mb3JtJyk7XG4gICAgICAgICR0YWJJdGVtcy50YWIoe1xuICAgICAgICAgICAgb25WaXNpYmxlOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgLy8gU2VtYW50aWMgVUkncyB0YWIgbW9kdWxlICgyLjQuMSwgc2VlXG4gICAgICAgICAgICAgICAgLy8gcHVibGljL2Fzc2V0cy9qcy92ZW5kb3IgZXF1aXZhbGVudCB1bmRlciBDb3JlKSBvbmx5XG4gICAgICAgICAgICAgICAgLy8gKGRlKWFjdGl2YXRlcyBtZW51IGl0ZW1zIGl0IHdhcyBpbml0aWFsaXplZCBvblxuICAgICAgICAgICAgICAgIC8vICgkdGFiSXRlbXMsIGkuZS4gaXRlbXMgd2l0aCBhIGRhdGEtdGFiKSAtIHNlZSBpdHNcbiAgICAgICAgICAgICAgICAvLyBhY3RpdmF0ZS5uYXZpZ2F0aW9uKCkuIFRoZSBUcmFuc2NyaXB0cyBpdGVtIGlzXG4gICAgICAgICAgICAgICAgLy8gZGVsaWJlcmF0ZWx5IG91dHNpZGUgdGhhdCBzZXQsIHNvIGEgbm9ybWFsIHRhYiBzd2l0Y2hcbiAgICAgICAgICAgICAgICAvLyBuZXZlciByZW1vdmVzIGl0cyAnYWN0aXZlJyBjbGFzczogd2l0aG91dCB0aGlzLCBsYW5kaW5nXG4gICAgICAgICAgICAgICAgLy8gb24gP3RhYj10cmFuc2NyaXB0cyAod2hpY2ggc2VydmVyLXJlbmRlcnMgaXQgYWN0aXZlKSBhbmRcbiAgICAgICAgICAgICAgICAvLyB0aGVuIGNsaWNraW5nIGFueSBvdGhlciB0YWIgd291bGQgbGVhdmUgVHJhbnNjcmlwdHNcbiAgICAgICAgICAgICAgICAvLyBoaWdobGlnaHRlZCBhbG9uZ3NpZGUgaXQuXG4gICAgICAgICAgICAgICAgJHRhYk1lbnUuZmluZCgnLml0ZW0nKS5ub3QoJ1tkYXRhLXRhYl0nKS5yZW1vdmVDbGFzcygnYWN0aXZlJyk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICBpZiAocmVxdWVzdGVkVGFiKCkgIT09ICcnICYmIHJlcXVlc3RlZFRhYigpICE9PSAndHJhbnNjcmlwdHMnKSB7XG4gICAgICAgICAgICAvLyAndHJhbnNjcmlwdHMnIGhhcyBubyBtYW5hZ2VkIG5hdiBpdGVtIChubyBkYXRhLXRhYikgYW55IG1vcmU6XG4gICAgICAgICAgICAvLyBpdHMgcGFuZWwgYW5kIGl0cyBtZW51IGxpbmsgYXJlIGFscmVhZHkgcmVuZGVyZWQgc2VydmVyLXNpZGVcbiAgICAgICAgICAgIC8vIGFzIGFjdGl2ZSBvbiA/dGFiPXRyYW5zY3JpcHRzIChzZWUgaW5kZXgudm9sdCksIHNvIHRoZXJlIGlzXG4gICAgICAgICAgICAvLyBub3RoaW5nIGhlcmUgZm9yIHRoZSB0YWIgbW9kdWxlIHRvIHN3aXRjaCB0byAtIHNraXAgdGhlIGNhbGxcbiAgICAgICAgICAgIC8vIGluc3RlYWQgb2YgcmVseWluZyBvbiBpdCBiZWluZyBhIGhhcm1sZXNzIG5vLW9wLlxuICAgICAgICAgICAgJHRhYkl0ZW1zLnRhYignY2hhbmdlIHRhYicsIHJlcXVlc3RlZFRhYigpKTtcbiAgICAgICAgfVxuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnVpLmRyb3Bkb3duJykuZHJvcGRvd24oKTtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAudWkuY2hlY2tib3gnKS5jaGVja2JveCgpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IFtkYXRhLWNvbnRlbnRdJykucG9wdXAoKTtcbiAgICAgICAgaW5pdGlhbGl6ZU1vZGVsTWFya2V0cGxhY2UoJG1vZGVsRm9ybSk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlcigpO1xuICAgICAgICBpbml0aWFsaXplTW9kdWxlTG9ncygpO1xuICAgICAgICBpbml0aWFsaXplQ2RySGlzdG9yeVNsaWRlcigkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICBpbml0aWFsaXplQ29uZmlybU1vZGFsKCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0RGVsZXRpb24oKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRSb3dzKCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0QWN0aW9uTWVudSgpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdExlZ1RhYnMoKTtcblxuICAgICAgICBpZiAoJC5mbi5mb3JtICYmICQuZm4uZm9ybS5zZXR0aW5ncyAmJiAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMpIHtcbiAgICAgICAgICAgICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcy5zdHRQcm9jZXNzaW5nUmFuZ2UgPSBmdW5jdGlvbiAodmFsdWUsIHJhbmdlKSB7XG4gICAgICAgICAgICAgICAgdmFyIGxpbWl0cyA9IFN0cmluZyhyYW5nZSB8fCAnJykuc3BsaXQoJywnKS5tYXAoTnVtYmVyKTtcbiAgICAgICAgICAgICAgICB2YXIgbnVtYmVyID0gTnVtYmVyKHZhbHVlKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBsaW1pdHMubGVuZ3RoID09PSAyXG4gICAgICAgICAgICAgICAgICAgICYmIE51bWJlci5pc0Zpbml0ZShudW1iZXIpXG4gICAgICAgICAgICAgICAgICAgICYmIG51bWJlciA+PSBsaW1pdHNbMF1cbiAgICAgICAgICAgICAgICAgICAgJiYgbnVtYmVyIDw9IGxpbWl0c1sxXTtcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMuc3R0UHJvY2Vzc2luZ092ZXJsYXAgPSBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgb3ZlcmxhcE1TID0gTWF0aC5yb3VuZChOdW1iZXIodmFsdWUpICogMTAwMCk7XG4gICAgICAgICAgICAgICAgdmFyIGR1cmF0aW9uTVMgPSBNYXRoLnJvdW5kKE51bWJlcigkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwibWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjXCJdJykudmFsKCkpICogMTAwMCk7XG5cbiAgICAgICAgICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKG92ZXJsYXBNUylcbiAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKGR1cmF0aW9uTVMpXG4gICAgICAgICAgICAgICAgICAgICYmIG92ZXJsYXBNUyA+PSAwXG4gICAgICAgICAgICAgICAgICAgICYmIG92ZXJsYXBNUyA8IGR1cmF0aW9uTVM7XG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzLnN0dFJldGVudGlvbkNvbXBhdGliaWxpdHkgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcyA9IHtcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RlbXBlcmF0dXJlJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlX2ZhbGxiYWNrX3N0ZXA6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzAuLjEwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0b3Bfazoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0b3BfaycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzEuLjEwMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgY29tcHJlc3Npb25fcmF0aW9fdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2NvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVstMTAsMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgZmlyc3RfdG9rZW5fbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVstMTAsMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbm9fc3BlZWNoX3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdub19zcGVlY2hfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG1heF9zZWdtZW50X2R1cmF0aW9uX3NlYzoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWMnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzEsMTIwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZWdtZW50X292ZXJsYXBfc2VjOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3NlZ21lbnRfb3ZlcmxhcF9zZWMnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbXG4gICAgICAgICAgICAgICAgICAgIHsgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDEwXScgfSxcbiAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ092ZXJsYXAnIH1cbiAgICAgICAgICAgICAgICBdXG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKCkge1xuICAgICAgICAgICAgdmFyIGVuYWJsZWQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwidm9pY2VfYWN0aXZpdHlfZGV0ZWN0aW9uXCJdJykuaXMoJzpjaGVja2VkJyk7XG5cbiAgICAgICAgICAgICRzZXR0aW5nc0Zvcm0uZmluZCgnLnN0dC12YWQtZGVwZW5kZW50JylcbiAgICAgICAgICAgICAgICAudG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWVuYWJsZWQpXG4gICAgICAgICAgICAgICAgLmF0dHIoJ2FyaWEtZGlzYWJsZWQnLCBlbmFibGVkID8gJ2ZhbHNlJyA6ICd0cnVlJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwidm9pY2VfYWN0aXZpdHlfZGV0ZWN0aW9uXCJdJykub24oJ2NoYW5nZScsIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcyk7XG4gICAgICAgIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcygpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtY29weS1idXR0b24nKS5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgdGV4dCA9IFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtY2xpcGJvYXJkLXRleHQnKSB8fCAnJyk7XG4gICAgICAgICAgICB2YXIgJGJ1dHRvbiA9ICQodGhpcyk7XG4gICAgICAgICAgICBmdW5jdGlvbiBjb3BpZWQoKSB7XG4gICAgICAgICAgICAgICAgJGJ1dHRvbi5hZGRDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgICAgICBzZXRUaW1lb3V0KGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgJGJ1dHRvbi5yZW1vdmVDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgICAgICB9LCA5MDApO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBpZiAobmF2aWdhdG9yLmNsaXBib2FyZCAmJiBuYXZpZ2F0b3IuY2xpcGJvYXJkLndyaXRlVGV4dCkge1xuICAgICAgICAgICAgICAgIG5hdmlnYXRvci5jbGlwYm9hcmQud3JpdGVUZXh0KHRleHQpLnRoZW4oY29waWVkKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAvLyBPdmVyIHBsYWluIEhUVFAgdGhlIENsaXBib2FyZCBBUEkgaXMgbWlzc2luZzsgYSBzZWxlY3RlZCB0ZXh0YXJlYSBzdGlsbCBjb3BpZXMuXG4gICAgICAgICAgICB2YXIgJGJ1ZmZlciA9ICQoJzx0ZXh0YXJlYSByZWFkb25seT48L3RleHRhcmVhPicpLnZhbCh0ZXh0KS5jc3MoeyBwb3NpdGlvbjogJ2ZpeGVkJywgdG9wOiAwLCBsZWZ0OiAnLTk5OTlweCcgfSkuYXBwZW5kVG8oJ2JvZHknKTtcbiAgICAgICAgICAgICRidWZmZXJbMF0uc2VsZWN0KCk7XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGlmIChkb2N1bWVudC5leGVjQ29tbWFuZCgnY29weScpKSB7XG4gICAgICAgICAgICAgICAgICAgIGNvcGllZCgpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgLy8gVGhlIHRleHQgc3RheXMgc2VsZWN0YWJsZSBpbiB0aGUgcGFnZTsgbm90aGluZyBlbHNlIHRvIGRvLlxuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJGJ1ZmZlci5yZW1vdmUoKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LXdvcmtlci1wbGF0Zm9ybS1zd2l0Y2ggW2RhdGEtcGxhdGZvcm1dJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyIHBsYXRmb3JtID0gU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKTtcbiAgICAgICAgICAgIHZhciAkZG93bmxvYWQgPSAkKHRoaXMpLmNsb3Nlc3QoJy5zdHQtd29ya2VyLWRvd25sb2FkJyk7XG5cbiAgICAgICAgICAgICQodGhpcykuYWRkQ2xhc3MoJ2FjdGl2ZScpLmF0dHIoJ2FyaWEtcHJlc3NlZCcsICd0cnVlJylcbiAgICAgICAgICAgICAgICAuc2libGluZ3MoKS5yZW1vdmVDbGFzcygnYWN0aXZlJykuYXR0cignYXJpYS1wcmVzc2VkJywgJ2ZhbHNlJyk7XG4gICAgICAgICAgICAkZG93bmxvYWQuZmluZCgnLnN0dC13b3JrZXItcGxhdGZvcm0tcGFuZWwnKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAkKHRoaXMpLnByb3AoJ2hpZGRlbicsIFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkgIT09IHBsYXRmb3JtKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJGRvd25sb2FkLmNsb3Nlc3QoJ1tkYXRhLXdvcmtlci1wbGF0Zm9ybV0nKS5hdHRyKCdkYXRhLXdvcmtlci1wbGF0Zm9ybScsIHBsYXRmb3JtKVxuICAgICAgICAgICAgICAgIC5maW5kKCdpbnB1dFtuYW1lPVwid29ya2VyX3BsYXRmb3JtXCJdJykudmFsKHBsYXRmb3JtKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiAkc2V0dGluZ3NGb3JtLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgIEZvcm0uJGZvcm1PYmogPSAkc2V0dGluZ3NGb3JtO1xuICAgICAgICAgICAgRm9ybS51cmwgPSAkc2V0dGluZ3NGb3JtLmF0dHIoJ2FjdGlvbicpO1xuICAgICAgICAgICAgRm9ybS5tZXRob2QgPSAnUE9TVCc7XG4gICAgICAgICAgICBGb3JtLnZhbGlkYXRlUnVsZXMgPSBPYmplY3QuYXNzaWduKHtcbiAgICAgICAgICAgICAgICBkZWZhdWx0X2xhbmd1YWdlOiB7XG4gICAgICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdkZWZhdWx0X2xhbmd1YWdlJyxcbiAgICAgICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdlbXB0eScgfV1cbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgICAgIGNkcl9oaXN0b3J5X2RheXM6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2Nkcl9oaXN0b3J5X2RheXMnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2ludGVnZXJbMC4uMzY1XScgfV1cbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgICAgIHRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXM6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXMnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW1xuICAgICAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnaW50ZWdlclswLi4zNjVdJyB9LFxuICAgICAgICAgICAgICAgICAgICAgICAge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBwcm9tcHQ6IHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25Db25mbGljdCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdUaGUgcmVjb3JkaW5nIHByb2Nlc3Npbmcgd2luZG93IGNhbm5vdCBleGNlZWQgdHJhbnNjcmlwdCByZXRlbnRpb24uIEluY3JlYXNlIHJldGVudGlvbiBvciByZWR1Y2UgdGhlIHByb2Nlc3Npbmcgd2luZG93LidcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LCBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcyk7XG4gICAgICAgICAgICBGb3JtLmNiQmVmb3JlU2VuZEZvcm0gPSBmdW5jdGlvbiAoc2V0dGluZ3MpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gc2V0dGluZ3M7XG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgRm9ybS5jYkFmdGVyU2VuZEZvcm0gPSBmdW5jdGlvbiAoKSB7fTtcbiAgICAgICAgICAgIEZvcm0uYXBpU2V0dGluZ3MuZW5hYmxlZCA9IGZhbHNlO1xuICAgICAgICAgICAgRm9ybS5hcGlTZXR0aW5ncy5hcGlPYmplY3QgPSBudWxsO1xuICAgICAgICAgICAgRm9ybS5zZW5kT25seUNoYW5nZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIEZvcm0uZW5hYmxlRGlycml0eSA9IHRydWU7XG4gICAgICAgICAgICBGb3JtLmluaXRpYWxpemUoKTtcbiAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICRzZXR0aW5nc0Zvcm0uZm9ybSh7XG4gICAgICAgICAgICAgICAgZmllbGRzOiBPYmplY3QuYXNzaWduKHtcbiAgICAgICAgICAgICAgICAgICAgZGVmYXVsdF9sYW5ndWFnZTogWydlbXB0eSddLFxuICAgICAgICAgICAgICAgICAgICBjZHJfaGlzdG9yeV9kYXlzOiBbJ2ludGVnZXJbMC4uMzY1XSddLFxuICAgICAgICAgICAgICAgICAgICB0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzOiBbXG4gICAgICAgICAgICAgICAgICAgICAgICAnaW50ZWdlclswLi4zNjVdJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICB0eXBlOiAnc3R0UmV0ZW50aW9uQ29tcGF0aWJpbGl0eScsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgcHJvbXB0OiB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uQ29uZmxpY3QnLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnVGhlIHJlY29yZGluZyBwcm9jZXNzaW5nIHdpbmRvdyBjYW5ub3QgZXhjZWVkIHRyYW5zY3JpcHQgcmV0ZW50aW9uLiBJbmNyZWFzZSByZXRlbnRpb24gb3IgcmVkdWNlIHRoZSBwcm9jZXNzaW5nIHdpbmRvdy4nXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBdXG4gICAgICAgICAgICAgICAgfSwgcHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMpXG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgfSk7XG59KCkpO1xuIl0sIm1hcHBpbmdzIjoiOzs7O0FBQUMsYUFBWTtFQUNULFlBQVk7O0VBRVosU0FBU0EsV0FBV0EsQ0FBQ0MsR0FBRyxFQUFFQyxRQUFRLEVBQUU7SUFDaEMsSUFBSSxPQUFPQyxlQUFlLEtBQUssV0FBVyxJQUFJQSxlQUFlLENBQUNGLEdBQUcsQ0FBQyxFQUFFO01BQ2hFLE9BQU9FLGVBQWUsQ0FBQ0YsR0FBRyxDQUFDO0lBQy9CO0lBRUEsT0FBT0MsUUFBUTtFQUNuQjtFQUVBLFNBQVNFLFlBQVlBLENBQUEsRUFBRztJQUNwQixJQUFJQyxNQUFNLEdBQUcsSUFBSUMsZUFBZSxDQUFDQyxNQUFNLENBQUNDLFFBQVEsQ0FBQ0MsTUFBTSxDQUFDO0lBQ3hELElBQUlDLE9BQU8sR0FBR0wsTUFBTSxDQUFDTSxHQUFHLENBQUMsS0FBSyxDQUFDLElBQUlKLE1BQU0sQ0FBQ0MsUUFBUSxDQUFDSSxJQUFJLENBQUNDLE9BQU8sQ0FBQyxJQUFJLEVBQUUsRUFBRSxDQUFDO0lBRXpFLE9BQU8sZUFBZSxDQUFDQyxJQUFJLENBQUNKLE9BQU8sQ0FBQyxHQUFHQSxPQUFPLEdBQUcsRUFBRTtFQUN2RDtFQUVBLFNBQVNLLGNBQWNBLENBQUEsRUFBRztJQUN0QixJQUFJQyxJQUFJLEdBQUcsQ0FBQyxJQUFJLEVBQUUsSUFBSSxFQUFFLElBQUksRUFBRSxJQUFJLEVBQUUsSUFBSSxFQUFFLElBQUksRUFBRSxJQUFJLENBQUM7SUFDckQsSUFBSUMsTUFBTSxHQUFHLENBQ1QsU0FBUyxFQUNULFVBQVUsRUFDVixPQUFPLEVBQ1AsT0FBTyxFQUNQLEtBQUssRUFDTCxNQUFNLEVBQ04sTUFBTSxFQUNOLFFBQVEsRUFDUixXQUFXLEVBQ1gsU0FBUyxFQUNULFVBQVUsRUFDVixVQUFVLENBQ2I7SUFFRCxJQUFJLE9BQU9DLG9CQUFvQixLQUFLLFdBQVcsSUFBSUEsb0JBQW9CLENBQUNDLFlBQVksRUFBRTtNQUNsRkgsSUFBSSxHQUFHRSxvQkFBb0IsQ0FBQ0MsWUFBWSxDQUFDSCxJQUFJLElBQUlBLElBQUk7TUFDckRDLE1BQU0sR0FBR0Msb0JBQW9CLENBQUNDLFlBQVksQ0FBQ0YsTUFBTSxJQUFJQSxNQUFNO0lBQy9EO0lBRUEsT0FBTztNQUNIRyxNQUFNLEVBQUUsWUFBWTtNQUNwQkMsU0FBUyxFQUFFLEtBQUs7TUFDaEJDLFVBQVUsRUFBRXRCLFdBQVcsQ0FBQyxjQUFjLEVBQUUsT0FBTyxDQUFDO01BQ2hEdUIsV0FBVyxFQUFFdkIsV0FBVyxDQUFDLGVBQWUsRUFBRSxRQUFRLENBQUM7TUFDbkR3QixTQUFTLEVBQUV4QixXQUFXLENBQUMsVUFBVSxFQUFFLE1BQU0sQ0FBQztNQUMxQ3lCLE9BQU8sRUFBRXpCLFdBQVcsQ0FBQyxRQUFRLEVBQUUsSUFBSSxDQUFDO01BQ3BDMEIsZ0JBQWdCLEVBQUUxQixXQUFXLENBQUMsa0JBQWtCLEVBQUUsY0FBYyxDQUFDO01BQ2pFMkIsVUFBVSxFQUFFWCxJQUFJO01BQ2hCWSxVQUFVLEVBQUVYLE1BQU07TUFDbEJZLFFBQVEsRUFBRTtJQUNkLENBQUM7RUFDTDtFQUVBLFNBQVNDLFVBQVVBLENBQUNDLEtBQUssRUFBRTdCLFFBQVEsRUFBRTtJQUNqQyxJQUFJOEIsSUFBSSxHQUFHQyxNQUFNLENBQUNGLEtBQUssRUFBRSxZQUFZLEVBQUUsSUFBSSxDQUFDO0lBRTVDLE9BQU9DLElBQUksQ0FBQ0UsT0FBTyxDQUFDLENBQUMsR0FBR0YsSUFBSSxHQUFHOUIsUUFBUSxDQUFDaUMsS0FBSyxDQUFDLENBQUM7RUFDbkQ7RUFFQSxTQUFTQyw4QkFBOEJBLENBQUEsRUFBRztJQUN0QyxJQUFJQyxLQUFLLEdBQUdDLENBQUMsQ0FBQyw4QkFBOEIsQ0FBQztJQUM3QyxJQUFJQyxTQUFTLEdBQUdELENBQUMsQ0FBQyxzQ0FBc0MsQ0FBQztJQUV6RCxJQUFJRCxLQUFLLENBQUNHLE1BQU0sS0FBSyxDQUFDLElBQUlELFNBQVMsQ0FBQ0MsTUFBTSxLQUFLLENBQUMsRUFBRTtNQUM5QztJQUNKO0lBQ0EsSUFBSSxPQUFPUCxNQUFNLEtBQUssV0FBVyxJQUFJLE9BQU9LLENBQUMsQ0FBQ0csRUFBRSxDQUFDQyxlQUFlLEtBQUssV0FBVyxFQUFFO01BQzlFO0lBQ0o7SUFFQSxJQUFJQyxTQUFTLEdBQUdOLEtBQUssQ0FBQ08sSUFBSSxDQUFDLHlCQUF5QixDQUFDO0lBQ3JELElBQUlDLE9BQU8sR0FBR1IsS0FBSyxDQUFDTyxJQUFJLENBQUMsdUJBQXVCLENBQUM7SUFDakQsSUFBSUUsT0FBTyxHQUFHVCxLQUFLLENBQUNPLElBQUksQ0FBQyxzQkFBc0IsQ0FBQztJQUNoRCxJQUFJRyxtQkFBbUIsR0FBRyxJQUFJO0lBQzlCLElBQUlDLEtBQUssR0FBR2YsTUFBTSxDQUFDLENBQUM7SUFDcEIsSUFBSWdCLFNBQVMsR0FBR25CLFVBQVUsQ0FBQ2EsU0FBUyxDQUFDTyxHQUFHLENBQUMsQ0FBQyxFQUFFRixLQUFLLENBQUM7SUFDbEQsSUFBSUcsT0FBTyxHQUFHckIsVUFBVSxDQUFDZSxPQUFPLENBQUNLLEdBQUcsQ0FBQyxDQUFDLEVBQUVELFNBQVMsQ0FBQztJQUVsRCxJQUFJQSxTQUFTLENBQUNHLE9BQU8sQ0FBQ0QsT0FBTyxDQUFDLEVBQUU7TUFDNUIsSUFBSUUsUUFBUSxHQUFHSixTQUFTO01BQ3hCQSxTQUFTLEdBQUdFLE9BQU87TUFDbkJBLE9BQU8sR0FBR0UsUUFBUTtJQUN0QjtJQUVBLFNBQVNDLGtCQUFrQkEsQ0FBQ0MsS0FBSyxFQUFFQyxHQUFHLEVBQUU7TUFDcENiLFNBQVMsQ0FBQ08sR0FBRyxDQUFDSyxLQUFLLENBQUNuQyxNQUFNLENBQUMsWUFBWSxDQUFDLENBQUM7TUFDekN5QixPQUFPLENBQUNLLEdBQUcsQ0FBQ00sR0FBRyxDQUFDcEMsTUFBTSxDQUFDLFlBQVksQ0FBQyxDQUFDO0lBQ3pDO0lBRUEsU0FBU3FDLG9CQUFvQkEsQ0FBQSxFQUFHO01BQzVCLElBQUlWLG1CQUFtQixLQUFLLElBQUksRUFBRTtRQUM5QnhDLE1BQU0sQ0FBQ21ELFlBQVksQ0FBQ1gsbUJBQW1CLENBQUM7UUFDeENBLG1CQUFtQixHQUFHLElBQUk7TUFDOUI7SUFDSjtJQUVBLFNBQVNZLHNCQUFzQkEsQ0FBQSxFQUFHO01BQzlCRixvQkFBb0IsQ0FBQyxDQUFDO01BQ3RCcEIsS0FBSyxDQUFDdUIsT0FBTyxDQUFDLFFBQVEsQ0FBQztJQUMzQjtJQUVBTixrQkFBa0IsQ0FBQ0wsU0FBUyxFQUFFRSxPQUFPLENBQUM7SUFDdENaLFNBQVMsQ0FBQ0csZUFBZSxDQUFDO01BQ3RCbUIsTUFBTSxFQUFBQyxlQUFBLENBQUFBLGVBQUEsQ0FBQUEsZUFBQSxDQUFBQSxlQUFBLENBQUFBLGVBQUEsQ0FBQUEsZUFBQSxLQUNEOUQsV0FBVyxDQUFDLFdBQVcsRUFBRSxPQUFPLENBQUMsRUFBRyxDQUFDaUMsTUFBTSxDQUFDLENBQUMsRUFBRUEsTUFBTSxDQUFDLENBQUMsQ0FBQyxHQUN4RGpDLFdBQVcsQ0FBQyxlQUFlLEVBQUUsV0FBVyxDQUFDLEVBQUcsQ0FBQ2lDLE1BQU0sQ0FBQyxDQUFDLENBQUM4QixRQUFRLENBQUMsQ0FBQyxFQUFFLE1BQU0sQ0FBQyxFQUFFOUIsTUFBTSxDQUFDLENBQUMsQ0FBQzhCLFFBQVEsQ0FBQyxDQUFDLEVBQUUsTUFBTSxDQUFDLENBQUMsR0FDeEcvRCxXQUFXLENBQUMsY0FBYyxFQUFFLGFBQWEsQ0FBQyxFQUFHLENBQUNpQyxNQUFNLENBQUMsQ0FBQyxDQUFDOEIsUUFBUSxDQUFDLENBQUMsRUFBRSxNQUFNLENBQUMsRUFBRTlCLE1BQU0sQ0FBQyxDQUFDLENBQUMsR0FDckZqQyxXQUFXLENBQUMsZ0JBQWdCLEVBQUUsY0FBYyxDQUFDLEVBQUcsQ0FBQ2lDLE1BQU0sQ0FBQyxDQUFDLENBQUM4QixRQUFRLENBQUMsRUFBRSxFQUFFLE1BQU0sQ0FBQyxFQUFFOUIsTUFBTSxDQUFDLENBQUMsQ0FBQyxHQUN6RmpDLFdBQVcsQ0FBQyxlQUFlLEVBQUUsWUFBWSxDQUFDLEVBQUcsQ0FBQ2lDLE1BQU0sQ0FBQyxDQUFDLENBQUMrQixPQUFPLENBQUMsT0FBTyxDQUFDLEVBQUUvQixNQUFNLENBQUMsQ0FBQyxDQUFDZ0MsS0FBSyxDQUFDLE9BQU8sQ0FBQyxDQUFDLEdBQ2pHakUsV0FBVyxDQUFDLGVBQWUsRUFBRSxZQUFZLENBQUMsRUFBRyxDQUFDaUMsTUFBTSxDQUFDLENBQUMsQ0FBQzhCLFFBQVEsQ0FBQyxDQUFDLEVBQUUsT0FBTyxDQUFDLENBQUNDLE9BQU8sQ0FBQyxPQUFPLENBQUMsRUFBRS9CLE1BQU0sQ0FBQyxDQUFDLENBQUM4QixRQUFRLENBQUMsQ0FBQyxFQUFFLE9BQU8sQ0FBQyxDQUFDRSxLQUFLLENBQUMsT0FBTyxDQUFDLENBQUMsQ0FDL0k7TUFDREMsbUJBQW1CLEVBQUUsSUFBSTtNQUN6QkMsZUFBZSxFQUFFLElBQUk7TUFDckJDLGVBQWUsRUFBRSxJQUFJO01BQ3JCQyxPQUFPLEVBQUVwQyxNQUFNLENBQUMsQ0FBQztNQUNqQmdCLFNBQVMsRUFBRUEsU0FBUyxDQUFDZSxPQUFPLENBQUMsS0FBSyxDQUFDO01BQ25DYixPQUFPLEVBQUVBLE9BQU8sQ0FBQ2MsS0FBSyxDQUFDLEtBQUssQ0FBQztNQUM3QkssTUFBTSxFQUFFdkQsY0FBYyxDQUFDO0lBQzNCLENBQUMsRUFBRSxVQUFVd0MsS0FBSyxFQUFFQyxHQUFHLEVBQUU7TUFDckJGLGtCQUFrQixDQUFDQyxLQUFLLEVBQUVDLEdBQUcsQ0FBQztNQUM5Qm5CLEtBQUssQ0FBQ3VCLE9BQU8sQ0FBQyxRQUFRLENBQUM7SUFDM0IsQ0FBQyxDQUFDO0lBRUZ2QixLQUFLLENBQUNrQyxFQUFFLENBQUMsUUFBUSxFQUFFLFlBQVk7TUFDM0JkLG9CQUFvQixDQUFDLENBQUM7TUFDdEIsSUFBSWUsTUFBTSxHQUFHakMsU0FBUyxDQUFDa0MsSUFBSSxDQUFDLGlCQUFpQixDQUFDO01BQzlDLElBQUlELE1BQU0sSUFBSUEsTUFBTSxDQUFDdkIsU0FBUyxJQUFJdUIsTUFBTSxDQUFDckIsT0FBTyxFQUFFO1FBQzlDRyxrQkFBa0IsQ0FBQ2tCLE1BQU0sQ0FBQ3ZCLFNBQVMsRUFBRXVCLE1BQU0sQ0FBQ3JCLE9BQU8sQ0FBQztNQUN4RDtJQUNKLENBQUMsQ0FBQztJQUNGTCxPQUFPLENBQUN5QixFQUFFLENBQUMsT0FBTyxFQUFFLFlBQVk7TUFDNUJkLG9CQUFvQixDQUFDLENBQUM7TUFDdEJWLG1CQUFtQixHQUFHeEMsTUFBTSxDQUFDbUUsVUFBVSxDQUFDLFlBQVk7UUFDaEQzQixtQkFBbUIsR0FBRyxJQUFJO1FBQzFCVixLQUFLLENBQUN1QixPQUFPLENBQUMsUUFBUSxDQUFDO01BQzNCLENBQUMsRUFBRSxHQUFHLENBQUM7SUFDWCxDQUFDLENBQUM7SUFDRmQsT0FBTyxDQUFDeUIsRUFBRSxDQUFDLFNBQVMsRUFBRSxVQUFVSSxLQUFLLEVBQUU7TUFDbkMsSUFBSUEsS0FBSyxDQUFDQyxLQUFLLEtBQUssRUFBRSxFQUFFO1FBQ3BCO01BQ0o7TUFFQUQsS0FBSyxDQUFDRSxjQUFjLENBQUMsQ0FBQztNQUN0QmxCLHNCQUFzQixDQUFDLENBQUM7SUFDNUIsQ0FBQyxDQUFDO0lBQ0ZiLE9BQU8sQ0FBQ3lCLEVBQUUsQ0FBQyxRQUFRLEVBQUUsWUFBWTtNQUM3QixJQUFJTyxNQUFNLENBQUNoQyxPQUFPLENBQUNJLEdBQUcsQ0FBQyxDQUFDLElBQUksRUFBRSxDQUFDLENBQUM2QixJQUFJLENBQUMsQ0FBQyxLQUFLLEVBQUUsRUFBRTtRQUMzQ3BCLHNCQUFzQixDQUFDLENBQUM7TUFDNUI7SUFDSixDQUFDLENBQUM7RUFDTjtFQUVBLFNBQVNxQixvQkFBb0JBLENBQUEsRUFBRztJQUM1QixJQUFJM0MsS0FBSyxHQUFHQyxDQUFDLENBQUMsdUJBQXVCLENBQUM7SUFDdEMsSUFBSTJDLFFBQVEsR0FBRzNDLENBQUMsQ0FBQyxzQkFBc0IsQ0FBQztJQUN4QyxJQUFJNEMsUUFBUSxHQUFHNUMsQ0FBQyxDQUFDLGtCQUFrQixDQUFDO0lBQ3BDLElBQUk2QyxNQUFNLEdBQUc3QyxDQUFDLENBQUMsZ0JBQWdCLENBQUM7SUFDaEMsSUFBSThDLFlBQVksR0FBRzlDLENBQUMsQ0FBQyx1QkFBdUIsQ0FBQztJQUM3QyxJQUFJK0MsVUFBVSxHQUFHL0MsQ0FBQyxDQUFDLHFCQUFxQixDQUFDO0lBQ3pDLElBQUlnRCxZQUFZLEdBQUdoRCxDQUFDLENBQUMsdUJBQXVCLENBQUM7SUFDN0MsSUFBSWlELGdCQUFnQixHQUFHakQsQ0FBQyxDQUFDLDJCQUEyQixDQUFDO0lBQ3JELElBQUlRLE9BQU8sR0FBR1IsQ0FBQyxDQUFDLGlCQUFpQixDQUFDO0lBRWxDLElBQUlELEtBQUssQ0FBQ0csTUFBTSxLQUFLLENBQUMsSUFBSTBDLFFBQVEsQ0FBQzFDLE1BQU0sS0FBSyxDQUFDLElBQUkyQyxNQUFNLENBQUMzQyxNQUFNLEtBQUssQ0FBQyxFQUFFO01BQ3BFO0lBQ0o7SUFFQSxJQUFJZ0QsT0FBTyxHQUFHbkQsS0FBSyxDQUFDb0MsSUFBSSxDQUFDLEtBQUssQ0FBQztJQUMvQixJQUFJZ0IsY0FBYyxHQUFHcEQsS0FBSyxDQUFDTyxJQUFJLENBQUMsYUFBYSxDQUFDO0lBQzlDLElBQUk4QyxjQUFjLEdBQUdyRCxLQUFLLENBQUNPLElBQUksQ0FBQyxrQkFBa0IsQ0FBQztJQUNuRCxJQUFJK0MsYUFBYSxHQUFHQyxRQUFRLENBQUNILGNBQWMsQ0FBQ0ksTUFBTSxDQUFDLFNBQVMsQ0FBQyxDQUFDcEIsSUFBSSxDQUFDLFFBQVEsQ0FBQyxFQUFFLEVBQUUsQ0FBQztJQUNqRixJQUFJcUIsVUFBVSxHQUFHLEtBQUs7SUFDdEIsSUFBSUMsT0FBTyxHQUFHLElBQUk7SUFDbEIsSUFBSUMsaUJBQWlCLEdBQUcsQ0FBQztJQUN6QixJQUFJQyxjQUFjLEdBQUcsRUFBRTtJQUN2QixJQUFJQyxlQUFlLEdBQUcsSUFBSTtJQUUxQixJQUFJLENBQUNDLE1BQU0sQ0FBQ0MsUUFBUSxDQUFDVCxhQUFhLENBQUMsRUFBRTtNQUNqQ0EsYUFBYSxHQUFHLElBQUk7SUFDeEI7SUFFQSxTQUFTVSxTQUFTQSxDQUFDQyxLQUFLLEVBQUU7TUFDdEIsSUFBSUMsTUFBTSxHQUFHO1FBQ1RDLEtBQUssRUFBRSxDQUFDLDJDQUEyQyxFQUFFLE9BQU8sQ0FBQztRQUM3REMsSUFBSSxFQUFFLENBQUMsMENBQTBDLEVBQUUsTUFBTSxDQUFDO1FBQzFEQyxNQUFNLEVBQUUsQ0FBQyw0Q0FBNEMsRUFBRSxRQUFRLENBQUM7UUFDaEVDLE9BQU8sRUFBRSxDQUFDLDZDQUE2QyxFQUFFLFNBQVMsQ0FBQztRQUNuRUMsS0FBSyxFQUFFLENBQUMsMkNBQTJDLEVBQUUsT0FBTztNQUNoRSxDQUFDO01BQ0QsSUFBSUMsSUFBSSxHQUFHTixNQUFNLENBQUNELEtBQUssQ0FBQyxJQUFJQyxNQUFNLENBQUNFLElBQUk7TUFFdkMsT0FBT3pHLFdBQVcsQ0FBQzZHLElBQUksQ0FBQyxDQUFDLENBQUMsRUFBRUEsSUFBSSxDQUFDLENBQUMsQ0FBQyxDQUFDO0lBQ3hDO0lBRUEsU0FBU0MsYUFBYUEsQ0FBQ0MsU0FBUyxFQUFFO01BQzlCLElBQUlDLE9BQU8sR0FBRztRQUNWQyxHQUFHLEVBQUUsS0FBSztRQUNWQyxLQUFLLEVBQUUsT0FBTztRQUNkQyxPQUFPLEVBQUUsU0FBUztRQUNsQkMsTUFBTSxFQUFFLFFBQVE7UUFDaEJDLDJCQUEyQixFQUFFLFFBQVE7UUFDckNDLFFBQVEsRUFBRSxVQUFVO1FBQ3BCQyxNQUFNLEVBQUUsVUFBVTtRQUNsQkMsS0FBSyxFQUFFLE9BQU87UUFDZEMsT0FBTyxFQUFFLFNBQVM7UUFDbEJDLFVBQVUsRUFBRSxZQUFZO1FBQ3hCQyxNQUFNLEVBQUUsUUFBUTtRQUNoQkMscUJBQXFCLEVBQUUsUUFBUTtRQUMvQkMsTUFBTSxFQUFFO01BQ1osQ0FBQztNQUNELElBQUlDLE1BQU0sR0FBRztRQUNUYixHQUFHLEVBQUUsQ0FBQyw2Q0FBNkMsRUFBRSxLQUFLLENBQUM7UUFDM0RDLEtBQUssRUFBRSxDQUFDLCtDQUErQyxFQUFFLE9BQU8sQ0FBQztRQUNqRUMsT0FBTyxFQUFFLENBQUMsaURBQWlELEVBQUUsbUJBQW1CLENBQUM7UUFDakZDLE1BQU0sRUFBRSxDQUFDLGdEQUFnRCxFQUFFLFFBQVEsQ0FBQztRQUNwRUUsUUFBUSxFQUFFLENBQUMsa0RBQWtELEVBQUUsVUFBVSxDQUFDO1FBQzFFRSxLQUFLLEVBQUUsQ0FBQywrQ0FBK0MsRUFBRSxPQUFPLENBQUM7UUFDakVDLE9BQU8sRUFBRSxDQUFDLGlEQUFpRCxFQUFFLFNBQVMsQ0FBQztRQUN2RUMsVUFBVSxFQUFFLENBQUMsb0RBQW9ELEVBQUUsYUFBYSxDQUFDO1FBQ2pGQyxNQUFNLEVBQUUsQ0FBQyxnREFBZ0QsRUFBRSxRQUFRLENBQUM7UUFDcEVJLEtBQUssRUFBRSxDQUFDLCtDQUErQyxFQUFFLE9BQU87TUFDcEUsQ0FBQztNQUNELElBQUlDLEtBQUssR0FBR0YsTUFBTSxDQUFDZCxPQUFPLENBQUNELFNBQVMsQ0FBQyxJQUFJLE9BQU8sQ0FBQztNQUVqRCxPQUFPL0csV0FBVyxDQUFDZ0ksS0FBSyxDQUFDLENBQUMsQ0FBQyxFQUFFQSxLQUFLLENBQUMsQ0FBQyxDQUFDLENBQUM7SUFDMUM7SUFFQSxTQUFTQyxlQUFlQSxDQUFDQyxTQUFTLEVBQUU7TUFDaEMsSUFBSWxHLElBQUksR0FBRyxJQUFJbUcsSUFBSSxDQUFDRCxTQUFTLENBQUM7TUFDOUIsSUFBSS9CLE1BQU0sQ0FBQ2lDLEtBQUssQ0FBQ3BHLElBQUksQ0FBQ3FHLE9BQU8sQ0FBQyxDQUFDLENBQUMsRUFBRTtRQUM5QixPQUFPSCxTQUFTLElBQUksR0FBRztNQUMzQjtNQUVBLE9BQU9sRyxJQUFJLENBQUNzRyxjQUFjLENBQUMsQ0FBQztJQUNoQztJQUVBLFNBQVNDLFdBQVdBLENBQUNDLElBQUksRUFBRUMsT0FBTyxFQUFFO01BQ2hDLElBQUksQ0FBQ0EsT0FBTyxFQUFFO1FBQ1Z0RCxNQUFNLENBQUN1RCxJQUFJLENBQUMsUUFBUSxFQUFFLElBQUksQ0FBQyxDQUFDQyxXQUFXLENBQUMsb0JBQW9CLENBQUMsQ0FBQ0MsSUFBSSxDQUFDLEVBQUUsQ0FBQztRQUN0RTtNQUNKO01BRUF6RCxNQUFNLENBQ0R3RCxXQUFXLENBQUMsb0JBQW9CLENBQUMsQ0FDakNFLFFBQVEsQ0FBQ0wsSUFBSSxJQUFJLE1BQU0sQ0FBQyxDQUN4QkksSUFBSSxDQUFDSCxPQUFPLENBQUMsQ0FDYkMsSUFBSSxDQUFDLFFBQVEsRUFBRSxLQUFLLENBQUM7SUFDOUI7SUFFQSxTQUFTSSxhQUFhQSxDQUFDQyxPQUFPLEVBQUU7TUFDNUIsSUFBSXpDLEtBQUssR0FBR3hCLE1BQU0sQ0FBQ1EsWUFBWSxDQUFDcEMsR0FBRyxDQUFDLENBQUMsSUFBSSxFQUFFLENBQUM7TUFDNUMsSUFBSTZELFNBQVMsR0FBR2pDLE1BQU0sQ0FBQ1MsZ0JBQWdCLENBQUNyQyxHQUFHLENBQUMsQ0FBQyxJQUFJLEVBQUUsQ0FBQztNQUNwRCxJQUFJOEYsS0FBSyxHQUFHbEUsTUFBTSxDQUFDaEMsT0FBTyxDQUFDSSxHQUFHLENBQUMsQ0FBQyxJQUFJLEVBQUUsQ0FBQyxDQUFDNkIsSUFBSSxDQUFDLENBQUMsQ0FBQ2tFLFdBQVcsQ0FBQyxDQUFDO01BRTVELE9BQU9GLE9BQU8sQ0FBQ2xELE1BQU0sQ0FBQyxVQUFVcUQsS0FBSyxFQUFFO1FBQ25DLElBQUk1QyxLQUFLLElBQUk0QyxLQUFLLENBQUM1QyxLQUFLLEtBQUtBLEtBQUssRUFBRTtVQUNoQyxPQUFPLEtBQUs7UUFDaEI7UUFDQSxJQUFJUyxTQUFTLElBQUltQyxLQUFLLENBQUNuQyxTQUFTLEtBQUtBLFNBQVMsRUFBRTtVQUM1QyxPQUFPLEtBQUs7UUFDaEI7UUFDQSxJQUFJLENBQUNpQyxLQUFLLEVBQUU7VUFDUixPQUFPLElBQUk7UUFDZjtRQUVBLElBQUlHLFFBQVEsR0FBRyxDQUNYRCxLQUFLLENBQUNULE9BQU8sRUFDYlMsS0FBSyxDQUFDbkMsU0FBUyxFQUNmbUMsS0FBSyxDQUFDdkUsS0FBSyxFQUNYdUUsS0FBSyxDQUFDRSxRQUFRLEVBQ2RGLEtBQUssQ0FBQ0csR0FBRyxFQUNUQyxJQUFJLENBQUNDLFNBQVMsQ0FBQ0wsS0FBSyxDQUFDTSxPQUFPLElBQUksQ0FBQyxDQUFDLENBQUMsQ0FDdEMsQ0FBQ0MsSUFBSSxDQUFDLEdBQUcsQ0FBQyxDQUFDUixXQUFXLENBQUMsQ0FBQztRQUV6QixPQUFPRSxRQUFRLENBQUNPLE9BQU8sQ0FBQ1YsS0FBSyxDQUFDLEtBQUssQ0FBQyxDQUFDO01BQ3pDLENBQUMsQ0FBQztJQUNOO0lBRUEsU0FBU1csYUFBYUEsQ0FBQ1osT0FBTyxFQUFFO01BQzVCN0QsUUFBUSxDQUFDMEUsS0FBSyxDQUFDLENBQUM7TUFDaEJiLE9BQU8sQ0FBQ2MsT0FBTyxDQUFDLFVBQVVYLEtBQUssRUFBRTtRQUM3QixJQUFJWSxLQUFLLEdBQUd4SCxDQUFDLENBQUMsV0FBVyxDQUFDLENBQUN1RyxRQUFRLENBQUMsOEJBQThCLEdBQUdLLEtBQUssQ0FBQzVDLEtBQUssQ0FBQztRQUNqRixJQUFJeUQsT0FBTyxHQUFHekgsQ0FBQyxDQUFDLE9BQU8sQ0FBQyxDQUFDdUcsUUFBUSxDQUFDLHNCQUFzQixDQUFDO1FBQ3pELElBQUltQixTQUFTLEdBQUcxSCxDQUFDLENBQUMsT0FBTyxDQUFDLENBQUN1RyxRQUFRLENBQUMsd0JBQXdCLENBQUM7UUFDN0QsSUFBSW9CLE1BQU0sR0FBRzNILENBQUMsQ0FBQyxRQUFRLENBQUMsQ0FBQ3VHLFFBQVEsQ0FBQyxtQ0FBbUMsQ0FBQyxDQUFDRCxJQUFJLENBQUN2QyxTQUFTLENBQUM2QyxLQUFLLENBQUM1QyxLQUFLLENBQUMsQ0FBQztRQUNuRyxJQUFJNEQsVUFBVSxHQUFHNUgsQ0FBQyxDQUFDLFFBQVEsQ0FBQyxDQUFDdUcsUUFBUSxDQUFDLG1CQUFtQixDQUFDLENBQUNELElBQUksQ0FBQzlCLGFBQWEsQ0FBQ29DLEtBQUssQ0FBQ25DLFNBQVMsQ0FBQyxDQUFDO1FBQy9GLElBQUlvRCxLQUFLLEdBQUc3SCxDQUFDLENBQUMsUUFBUSxDQUFDLENBQUN1RyxRQUFRLENBQUMsY0FBYyxDQUFDLENBQUNELElBQUksQ0FBQ1gsZUFBZSxDQUFDaUIsS0FBSyxDQUFDa0IsRUFBRSxDQUFDLENBQUM7UUFDaEYsSUFBSUMsUUFBUSxHQUFHL0gsQ0FBQyxDQUFDLE9BQU8sQ0FBQyxDQUFDdUcsUUFBUSxDQUFDLGlCQUFpQixDQUFDLENBQUNELElBQUksQ0FBQ00sS0FBSyxDQUFDVCxPQUFPLElBQUksR0FBRyxDQUFDO1FBQ2hGLElBQUk2QixRQUFRLEdBQUdoSSxDQUFDLENBQUMsV0FBVyxDQUFDLENBQUN1RyxRQUFRLENBQUMsaUJBQWlCLENBQUM7UUFDekQsSUFBSTBCLFFBQVEsR0FBR2pJLENBQUMsQ0FBQyxXQUFXLENBQUMsQ0FBQ3NHLElBQUksQ0FBQzVJLFdBQVcsQ0FDMUMsa0RBQWtELEVBQ2xELG1CQUNKLENBQUMsQ0FBQztRQUNGLElBQUl3SyxVQUFVLEdBQUd4SyxXQUFXLENBQUMsdUNBQXVDLEVBQUUsT0FBTyxDQUFDLEdBQ3hFLElBQUksSUFBSWtKLEtBQUssQ0FBQ3ZFLEtBQUssSUFBSSxHQUFHLENBQUM7UUFDakM2RixVQUFVLElBQUksY0FBYyxJQUFJdEIsS0FBSyxDQUFDRSxRQUFRLElBQUksR0FBRyxDQUFDO1FBQ3REb0IsVUFBVSxJQUFJLFNBQVMsSUFBSXRCLEtBQUssQ0FBQ0csR0FBRyxLQUFLb0IsU0FBUyxHQUFHLEdBQUcsR0FBR3ZCLEtBQUssQ0FBQ0csR0FBRyxDQUFDO1FBQ3JFLElBQUlILEtBQUssQ0FBQ00sT0FBTyxJQUFJa0IsTUFBTSxDQUFDQyxJQUFJLENBQUN6QixLQUFLLENBQUNNLE9BQU8sQ0FBQyxDQUFDaEgsTUFBTSxHQUFHLENBQUMsRUFBRTtVQUN4RGdJLFVBQVUsSUFBSSxJQUFJLEdBQUdsQixJQUFJLENBQUNDLFNBQVMsQ0FBQ0wsS0FBSyxDQUFDTSxPQUFPLEVBQUUsSUFBSSxFQUFFLENBQUMsQ0FBQztRQUMvRDtRQUVBUSxTQUFTLENBQUNZLE1BQU0sQ0FBQ1gsTUFBTSxFQUFFQyxVQUFVLENBQUM7UUFDcENILE9BQU8sQ0FBQ2EsTUFBTSxDQUFDWixTQUFTLEVBQUVHLEtBQUssQ0FBQztRQUNoQ0csUUFBUSxDQUFDTSxNQUFNLENBQUNMLFFBQVEsRUFBRWpJLENBQUMsQ0FBQyxPQUFPLENBQUMsQ0FBQ3NHLElBQUksQ0FBQzRCLFVBQVUsQ0FBQyxDQUFDO1FBQ3REVixLQUFLLENBQUNjLE1BQU0sQ0FBQ2IsT0FBTyxFQUFFTSxRQUFRLEVBQUVDLFFBQVEsQ0FBQztRQUN6Q3BGLFFBQVEsQ0FBQzBGLE1BQU0sQ0FBQ2QsS0FBSyxDQUFDO01BQzFCLENBQUMsQ0FBQztJQUNOO0lBRUEsU0FBU2Usc0JBQXNCQSxDQUFDOUIsT0FBTyxFQUFFO01BQ3JDLElBQUkrQixRQUFRLEdBQUdoRyxNQUFNLENBQUNTLGdCQUFnQixDQUFDckMsR0FBRyxDQUFDLENBQUMsSUFBSSxFQUFFLENBQUM7TUFDbkQsSUFBSTZILFVBQVUsR0FBRyxFQUFFO01BQ25CaEMsT0FBTyxDQUFDYyxPQUFPLENBQUMsVUFBVVgsS0FBSyxFQUFFO1FBQzdCLElBQUlBLEtBQUssQ0FBQ25DLFNBQVMsSUFBSWdFLFVBQVUsQ0FBQ3JCLE9BQU8sQ0FBQ1IsS0FBSyxDQUFDbkMsU0FBUyxDQUFDLEtBQUssQ0FBQyxDQUFDLEVBQUU7VUFDL0RnRSxVQUFVLENBQUNDLElBQUksQ0FBQzlCLEtBQUssQ0FBQ25DLFNBQVMsQ0FBQztRQUNwQztNQUNKLENBQUMsQ0FBQztNQUNGZ0UsVUFBVSxDQUFDRSxJQUFJLENBQUMsVUFBVUMsSUFBSSxFQUFFQyxLQUFLLEVBQUU7UUFDbkMsT0FBT3JFLGFBQWEsQ0FBQ29FLElBQUksQ0FBQyxDQUFDRSxhQUFhLENBQUN0RSxhQUFhLENBQUNxRSxLQUFLLENBQUMsQ0FBQztNQUNsRSxDQUFDLENBQUM7TUFFRjVGLGdCQUFnQixDQUFDcUUsS0FBSyxDQUFDLENBQUMsQ0FBQ2dCLE1BQU0sQ0FDM0J0SSxDQUFDLENBQUMsVUFBVSxDQUFDLENBQUNZLEdBQUcsQ0FBQyxFQUFFLENBQUMsQ0FBQzBGLElBQUksQ0FBQzVJLFdBQVcsQ0FDbEMsOENBQThDLEVBQzlDLGdCQUNKLENBQUMsQ0FDTCxDQUFDO01BQ0QrSyxVQUFVLENBQUNsQixPQUFPLENBQUMsVUFBVTlDLFNBQVMsRUFBRTtRQUNwQ3hCLGdCQUFnQixDQUFDcUYsTUFBTSxDQUFDdEksQ0FBQyxDQUFDLFVBQVUsQ0FBQyxDQUFDWSxHQUFHLENBQUM2RCxTQUFTLENBQUMsQ0FBQzZCLElBQUksQ0FBQzlCLGFBQWEsQ0FBQ0MsU0FBUyxDQUFDLENBQUMsQ0FBQztNQUN4RixDQUFDLENBQUM7TUFDRixJQUFJK0QsUUFBUSxJQUFJQyxVQUFVLENBQUNyQixPQUFPLENBQUNvQixRQUFRLENBQUMsS0FBSyxDQUFDLENBQUMsRUFBRTtRQUNqRHZGLGdCQUFnQixDQUFDckMsR0FBRyxDQUFDNEgsUUFBUSxDQUFDO01BQ2xDO0lBQ0o7SUFFQSxTQUFTTyxVQUFVQSxDQUFDQyxLQUFLLEVBQUVDLFFBQVEsRUFBRTtNQUNqQyxJQUFJQyxLQUFLLEdBQUd4TCxXQUFXLENBQUMsdUNBQXVDLEVBQUUsZ0JBQWdCLENBQUMsQ0FDN0VhLE9BQU8sQ0FBQyxTQUFTLEVBQUV5SyxLQUFLLENBQUM7TUFDOUIsSUFBSUcsT0FBTyxHQUFHekwsV0FBVyxDQUFDLHlDQUF5QyxFQUFFLGlCQUFpQixDQUFDLENBQ2xGYSxPQUFPLENBQUMsUUFBUSxFQUFFMEssUUFBUSxJQUFJQSxRQUFRLENBQUNHLFdBQVcsR0FDN0NILFFBQVEsQ0FBQ0csV0FBVyxHQUNwQjFMLFdBQVcsQ0FBQyx1Q0FBdUMsRUFBRSxPQUFPLENBQUMsQ0FBQztNQUN4RW9GLFlBQVksQ0FBQ3dELElBQUksQ0FBQzRDLEtBQUssQ0FBQztNQUN4Qm5HLFVBQVUsQ0FBQ3VELElBQUksQ0FBQzZDLE9BQU8sQ0FBQztJQUM1QjtJQUVBLFNBQVNFLGFBQWFBLENBQUEsRUFBRztNQUNyQixJQUFJLENBQUN6RixlQUFlLEVBQUU7UUFDbEI7TUFDSjtNQUNBLElBQUkwRixRQUFRLEdBQUc5QyxhQUFhLENBQUM3QyxjQUFjLENBQUM7TUFDNUMwRCxhQUFhLENBQUNpQyxRQUFRLENBQUM7TUFDdkJQLFVBQVUsQ0FBQ08sUUFBUSxDQUFDcEosTUFBTSxFQUFFMEQsZUFBZSxDQUFDO01BRTVDLElBQUlBLGVBQWUsQ0FBQzJGLFlBQVksS0FBSyxTQUFTLEVBQUU7UUFDNUN0RCxXQUFXLENBQUMsTUFBTSxFQUFFdkksV0FBVyxDQUMzQix5Q0FBeUMsRUFDekMsOENBQ0osQ0FBQyxDQUFDO1FBQ0Y7TUFDSjtNQUNBLElBQUlrRyxlQUFlLENBQUMyRixZQUFZLEtBQUssYUFBYSxFQUFFO1FBQ2hEdEQsV0FBVyxDQUFDLE9BQU8sRUFBRXZJLFdBQVcsQ0FDNUIsNkNBQTZDLEVBQzdDLG9DQUNKLENBQUMsQ0FBQztRQUNGO01BQ0o7TUFDQSxJQUFJa0csZUFBZSxDQUFDMkYsWUFBWSxLQUFLLFNBQVMsRUFBRTtRQUM1Q3RELFdBQVcsQ0FBQyxPQUFPLEVBQUV2SSxXQUFXLENBQzVCLHlDQUF5QyxFQUN6QywwQ0FDSixDQUFDLENBQUM7UUFDRjtNQUNKO01BQ0EsSUFBSWlHLGNBQWMsQ0FBQ3pELE1BQU0sS0FBSyxDQUFDLEVBQUU7UUFDN0IrRixXQUFXLENBQUMsTUFBTSxFQUFFdkksV0FBVyxDQUMzQix1Q0FBdUMsRUFDdkMsOENBQ0osQ0FBQyxDQUFDO1FBQ0Y7TUFDSjtNQUNBLElBQUk0TCxRQUFRLENBQUNwSixNQUFNLEtBQUssQ0FBQyxFQUFFO1FBQ3ZCK0YsV0FBVyxDQUFDLE1BQU0sRUFBRXZJLFdBQVcsQ0FDM0IsK0NBQStDLEVBQy9DLHVDQUNKLENBQUMsQ0FBQztRQUNGO01BQ0o7TUFFQSxJQUFJOEwsT0FBTyxHQUFHLEVBQUU7TUFDaEIsSUFBSTVGLGVBQWUsQ0FBQzJGLFlBQVksS0FBSyxTQUFTLEVBQUU7UUFDNUNDLE9BQU8sQ0FBQ2QsSUFBSSxDQUFDaEwsV0FBVyxDQUNwQix5Q0FBeUMsRUFDekMsNkNBQ0osQ0FBQyxDQUFDYSxPQUFPLENBQUMsU0FBUyxFQUFFcUYsZUFBZSxDQUFDNkYsY0FBYyxJQUFJLENBQUMsQ0FBQyxDQUFDO01BQzlEO01BQ0EsSUFBSTdGLGVBQWUsQ0FBQzhGLFNBQVMsRUFBRTtRQUMzQkYsT0FBTyxDQUFDZCxJQUFJLENBQUNoTCxXQUFXLENBQ3BCLDJDQUEyQyxFQUMzQyx3Q0FDSixDQUFDLENBQUM7TUFDTjtNQUNBdUksV0FBVyxDQUFDdUQsT0FBTyxDQUFDdEosTUFBTSxHQUFHLENBQUMsR0FBRyxTQUFTLEdBQUcsRUFBRSxFQUFFc0osT0FBTyxDQUFDckMsSUFBSSxDQUFDLEdBQUcsQ0FBQyxDQUFDO0lBQ3ZFO0lBRUEsU0FBU3dDLFVBQVVBLENBQUEsRUFBRztNQUNsQmhILFFBQVEsQ0FBQzRELFFBQVEsQ0FBQyxrQkFBa0IsQ0FBQztNQUNyQzNELFFBQVEsQ0FBQzBFLEtBQUssQ0FBQyxDQUFDO01BQ2hCeUIsVUFBVSxDQUFDLENBQUMsRUFBRSxJQUFJLENBQUM7TUFDbkI5QyxXQUFXLENBQUMsTUFBTSxFQUFFdkksV0FBVyxDQUMzQix5Q0FBeUMsRUFDekMsMEJBQ0osQ0FBQyxDQUFDO0lBQ047SUFFQSxTQUFTa00sUUFBUUEsQ0FBQ0MsS0FBSyxFQUFFO01BQ3JCLElBQUksQ0FBQ0EsS0FBSyxJQUFJckcsVUFBVSxFQUFFO1FBQ3RCO01BQ0o7TUFDQSxJQUFJLENBQUNOLE9BQU8sRUFBRTtRQUNWO01BQ0o7TUFDQVEsaUJBQWlCLElBQUksQ0FBQztNQUN0QixJQUFJb0csU0FBUyxHQUFHcEcsaUJBQWlCO01BQ2pDLElBQUlELE9BQU8sS0FBSyxJQUFJLEVBQUU7UUFDbEJBLE9BQU8sQ0FBQ3NHLEtBQUssQ0FBQyxDQUFDO01BQ25CO01BRUFKLFVBQVUsQ0FBQyxDQUFDO01BQ1p2RyxjQUFjLENBQUNtRCxRQUFRLENBQUMsa0JBQWtCLENBQUM7TUFDM0MsSUFBSXlELGNBQWMsR0FBR2hLLENBQUMsQ0FBQ2lLLElBQUksQ0FBQztRQUN4QkMsR0FBRyxFQUFFaEgsT0FBTztRQUNaaUgsTUFBTSxFQUFFLEtBQUs7UUFDYkMsUUFBUSxFQUFFLE1BQU07UUFDaEJqSSxJQUFJLEVBQUU7VUFDRmtJLE1BQU0sRUFBRWhIO1FBQ1o7TUFDSixDQUFDLENBQUMsQ0FBQ2lILElBQUksQ0FBQyxVQUFVckIsUUFBUSxFQUFFO1FBQ3hCLElBQUlhLFNBQVMsS0FBS3BHLGlCQUFpQixFQUFFO1VBQ2pDO1FBQ0o7UUFDQUUsZUFBZSxHQUFHcUYsUUFBUSxJQUFJLENBQUMsQ0FBQztRQUNoQ3RGLGNBQWMsR0FBRzRHLEtBQUssQ0FBQ0MsT0FBTyxDQUFDNUcsZUFBZSxDQUFDNkMsT0FBTyxDQUFDLEdBQUc3QyxlQUFlLENBQUM2QyxPQUFPLEdBQUcsRUFBRTtRQUN0RjhCLHNCQUFzQixDQUFDNUUsY0FBYyxDQUFDO1FBQ3RDMEYsYUFBYSxDQUFDLENBQUM7UUFDZjdGLFVBQVUsR0FBRyxJQUFJO01BQ3JCLENBQUMsQ0FBQyxDQUFDaUgsSUFBSSxDQUFDLFVBQVVDLEdBQUcsRUFBRUMsTUFBTSxFQUFFO1FBQzNCLElBQUliLFNBQVMsS0FBS3BHLGlCQUFpQixJQUFJaUgsTUFBTSxLQUFLLE9BQU8sRUFBRTtVQUN2RDtRQUNKO1FBQ0EvRyxlQUFlLEdBQUcsSUFBSTtRQUN0QkQsY0FBYyxHQUFHLEVBQUU7UUFDbkJzQyxXQUFXLENBQUMsT0FBTyxFQUFFdkksV0FBVyxDQUM1Qix1Q0FBdUMsRUFDdkMsK0JBQ0osQ0FBQyxDQUFDO01BQ04sQ0FBQyxDQUFDLENBQUNrTixNQUFNLENBQUMsWUFBWTtRQUNsQixJQUFJZCxTQUFTLEtBQUtwRyxpQkFBaUIsRUFBRTtVQUNqQztRQUNKO1FBQ0EsSUFBSUQsT0FBTyxLQUFLdUcsY0FBYyxFQUFFO1VBQzVCdkcsT0FBTyxHQUFHLElBQUk7UUFDbEI7UUFDQWQsUUFBUSxDQUFDMEQsV0FBVyxDQUFDLGtCQUFrQixDQUFDO1FBQ3hDakQsY0FBYyxDQUFDaUQsV0FBVyxDQUFDLGtCQUFrQixDQUFDO01BQ2xELENBQUMsQ0FBQztNQUNGNUMsT0FBTyxHQUFHdUcsY0FBYztJQUM1QjtJQUVBN0csY0FBYyxDQUFDbEIsRUFBRSxDQUFDLE9BQU8sRUFBRSxZQUFZO01BQ25DLElBQUk0SSxPQUFPLEdBQUc3SyxDQUFDLENBQUMsSUFBSSxDQUFDO01BQ3JCcUQsYUFBYSxHQUFHQyxRQUFRLENBQUN1SCxPQUFPLENBQUMxSSxJQUFJLENBQUMsUUFBUSxDQUFDLEVBQUUsRUFBRSxDQUFDO01BQ3BELElBQUksQ0FBQzBCLE1BQU0sQ0FBQ0MsUUFBUSxDQUFDVCxhQUFhLENBQUMsRUFBRTtRQUNqQ0EsYUFBYSxHQUFHLElBQUk7TUFDeEI7TUFDQUYsY0FBYyxDQUFDa0QsV0FBVyxDQUFDLFFBQVEsQ0FBQztNQUNwQ3dFLE9BQU8sQ0FBQ3RFLFFBQVEsQ0FBQyxRQUFRLENBQUM7TUFDMUIvQyxVQUFVLEdBQUcsS0FBSztNQUNsQm9HLFFBQVEsQ0FBQyxJQUFJLENBQUM7SUFDbEIsQ0FBQyxDQUFDO0lBRUZ4RyxjQUFjLENBQUNuQixFQUFFLENBQUMsT0FBTyxFQUFFLFlBQVk7TUFDbkMySCxRQUFRLENBQUMsSUFBSSxDQUFDO0lBQ2xCLENBQUMsQ0FBQztJQUVGNUcsWUFBWSxDQUFDOEgsR0FBRyxDQUFDN0gsZ0JBQWdCLENBQUMsQ0FBQ2hCLEVBQUUsQ0FBQyxRQUFRLEVBQUVvSCxhQUFhLENBQUM7SUFDOUQ3SSxPQUFPLENBQUN5QixFQUFFLENBQUMsT0FBTyxFQUFFb0gsYUFBYSxDQUFDO0lBRWxDckosQ0FBQyxDQUFDLDBEQUEwRCxDQUFDLENBQUNpQyxFQUFFLENBQUMsT0FBTyxFQUFFLFlBQVk7TUFDbEZoRSxNQUFNLENBQUNtRSxVQUFVLENBQUMsWUFBWTtRQUMxQndILFFBQVEsQ0FBQyxLQUFLLENBQUM7TUFDbkIsQ0FBQyxFQUFFLENBQUMsQ0FBQztJQUNULENBQUMsQ0FBQztJQUVGYixVQUFVLENBQUMsQ0FBQyxFQUFFLElBQUksQ0FBQztJQUNuQixJQUFJakwsWUFBWSxDQUFDLENBQUMsS0FBSyxNQUFNLElBQUlpQyxLQUFLLENBQUNnTCxPQUFPLENBQUMsY0FBYyxDQUFDLENBQUNDLFFBQVEsQ0FBQyxRQUFRLENBQUMsRUFBRTtNQUMvRXBCLFFBQVEsQ0FBQyxLQUFLLENBQUM7SUFDbkI7RUFDSjtFQUVBLFNBQVNxQiwwQkFBMEJBLENBQUNDLGFBQWEsRUFBRTtJQUMvQyxJQUFJQyxPQUFPLEdBQUduTCxDQUFDLENBQUMsOEJBQThCLENBQUM7SUFDL0MsSUFBSW9MLE1BQU0sR0FBR0YsYUFBYSxDQUFDNUssSUFBSSxDQUFDLGdDQUFnQyxDQUFDO0lBRWpFLElBQUk2SyxPQUFPLENBQUNqTCxNQUFNLEtBQUssQ0FBQyxJQUFJa0wsTUFBTSxDQUFDbEwsTUFBTSxLQUFLLENBQUMsSUFBSSxPQUFPaUwsT0FBTyxDQUFDRSxNQUFNLEtBQUssVUFBVSxFQUFFO01BQ3JGO0lBQ0o7SUFFQSxJQUFJQyxNQUFNLEdBQUcsQ0FBQyxHQUFHLEVBQUUsR0FBRyxFQUFFLElBQUksRUFBRSxJQUFJLEVBQUUsS0FBSyxFQUFFLEtBQUssRUFBRSxHQUFHLENBQUM7SUFDdEQsSUFBSTlGLE1BQU0sR0FBRztNQUNULENBQUMsRUFBRTlILFdBQVcsQ0FBQyw0Q0FBNEMsRUFBRSxPQUFPLENBQUM7TUFDckUsQ0FBQyxFQUFFQSxXQUFXLENBQUMsNkNBQTZDLEVBQUUsUUFBUSxDQUFDO01BQ3ZFLENBQUMsRUFBRUEsV0FBVyxDQUFDLDhDQUE4QyxFQUFFLFNBQVMsQ0FBQztNQUN6RSxDQUFDLEVBQUVBLFdBQVcsQ0FBQyw4Q0FBOEMsRUFBRSxTQUFTLENBQUM7TUFDekUsQ0FBQyxFQUFFQSxXQUFXLENBQUMsK0NBQStDLEVBQUUsVUFBVSxDQUFDO01BQzNFLENBQUMsRUFBRUEsV0FBVyxDQUFDLCtDQUErQyxFQUFFLFFBQVEsQ0FBQztNQUN6RSxDQUFDLEVBQUVBLFdBQVcsQ0FBQywyQ0FBMkMsRUFBRSxLQUFLO0lBQ3JFLENBQUM7SUFDRCxJQUFJNk4sWUFBWSxHQUFHL0ksTUFBTSxDQUFDNEksTUFBTSxDQUFDeEssR0FBRyxDQUFDLENBQUMsSUFBSSxJQUFJLENBQUM7SUFDL0MsSUFBSTRLLFlBQVksR0FBR0YsTUFBTSxDQUFDbEUsT0FBTyxDQUFDbUUsWUFBWSxDQUFDO0lBQy9DLElBQUlFLFdBQVcsR0FBRyxLQUFLO0lBRXZCLElBQUlELFlBQVksR0FBRyxDQUFDLEVBQUU7TUFDbEJBLFlBQVksR0FBR0YsTUFBTSxDQUFDbEUsT0FBTyxDQUFDLElBQUksQ0FBQztNQUNuQ2dFLE1BQU0sQ0FBQ3hLLEdBQUcsQ0FBQyxJQUFJLENBQUM7SUFDcEI7SUFFQXVLLE9BQU8sQ0FBQ0UsTUFBTSxDQUFDO01BQ1hLLEdBQUcsRUFBRSxDQUFDO01BQ05DLEdBQUcsRUFBRUwsTUFBTSxDQUFDcEwsTUFBTSxHQUFHLENBQUM7TUFDdEIwTCxJQUFJLEVBQUUsQ0FBQztNQUNQQyxNQUFNLEVBQUUsSUFBSTtNQUNaQyxnQkFBZ0IsRUFBRSxLQUFLO01BQ3ZCQyxjQUFjLEVBQUUsU0FBQUEsQ0FBVXRNLEtBQUssRUFBRTtRQUM3QixPQUFPK0YsTUFBTSxDQUFDL0YsS0FBSyxDQUFDLElBQUksRUFBRTtNQUM5QixDQUFDO01BQ0R1TSxRQUFRLEVBQUUsU0FBQUEsQ0FBVXZNLEtBQUssRUFBRTtRQUN2QixJQUFJK0ksUUFBUSxHQUFHOEMsTUFBTSxDQUFDN0wsS0FBSyxDQUFDLElBQUksSUFBSTtRQUVwQzJMLE1BQU0sQ0FBQ3hLLEdBQUcsQ0FBQzRILFFBQVEsQ0FBQztRQUNwQixJQUFJaUQsV0FBVyxFQUFFO1VBQ2JMLE1BQU0sQ0FBQzlKLE9BQU8sQ0FBQyxRQUFRLENBQUM7VUFDeEIySyx1QkFBdUIsQ0FBQ2YsYUFBYSxDQUFDO1VBQ3RDLElBQUksT0FBT2dCLElBQUksS0FBSyxXQUFXLElBQUksT0FBT0EsSUFBSSxDQUFDQyxXQUFXLEtBQUssVUFBVSxFQUFFO1lBQ3ZFRCxJQUFJLENBQUNDLFdBQVcsQ0FBQyxDQUFDO1VBQ3RCO1FBQ0o7TUFDSjtJQUNKLENBQUMsQ0FBQztJQUNGaEIsT0FBTyxDQUFDRSxNQUFNLENBQUMsV0FBVyxFQUFFRyxZQUFZLEVBQUUsS0FBSyxDQUFDO0lBQ2hEQyxXQUFXLEdBQUcsSUFBSTtFQUN0QjtFQUVBLFNBQVNXLG1DQUFtQ0EsQ0FBQ2xCLGFBQWEsRUFBRTtJQUN4RCxJQUFJQyxPQUFPLEdBQUduTCxDQUFDLENBQUMsdUNBQXVDLENBQUM7SUFDeEQsSUFBSW9MLE1BQU0sR0FBR0YsYUFBYSxDQUFDNUssSUFBSSxDQUFDLHlDQUF5QyxDQUFDO0lBRTFFLElBQUk2SyxPQUFPLENBQUNqTCxNQUFNLEtBQUssQ0FBQyxJQUFJa0wsTUFBTSxDQUFDbEwsTUFBTSxLQUFLLENBQUMsSUFBSSxPQUFPaUwsT0FBTyxDQUFDRSxNQUFNLEtBQUssVUFBVSxFQUFFO01BQ3JGO0lBQ0o7SUFFQSxJQUFJQyxNQUFNLEdBQUcsQ0FBQyxJQUFJLEVBQUUsSUFBSSxFQUFFLEtBQUssRUFBRSxLQUFLLEVBQUUsR0FBRyxDQUFDO0lBQzVDLElBQUk5RixNQUFNLEdBQUc7TUFDVCxDQUFDLEVBQUU5SCxXQUFXLENBQUMsdURBQXVELEVBQUUsU0FBUyxDQUFDO01BQ2xGLENBQUMsRUFBRUEsV0FBVyxDQUFDLHVEQUF1RCxFQUFFLFNBQVMsQ0FBQztNQUNsRixDQUFDLEVBQUVBLFdBQVcsQ0FBQyx3REFBd0QsRUFBRSxVQUFVLENBQUM7TUFDcEYsQ0FBQyxFQUFFQSxXQUFXLENBQUMsd0RBQXdELEVBQUUsUUFBUSxDQUFDO01BQ2xGLENBQUMsRUFBRUEsV0FBVyxDQUFDLDBEQUEwRCxFQUFFLFdBQVc7SUFDMUYsQ0FBQztJQUNELElBQUk2TixZQUFZLEdBQUcvSSxNQUFNLENBQUM0SSxNQUFNLENBQUN4SyxHQUFHLENBQUMsQ0FBQyxJQUFJLEtBQUssQ0FBQztJQUNoRCxJQUFJNEssWUFBWSxHQUFHRixNQUFNLENBQUNsRSxPQUFPLENBQUNtRSxZQUFZLENBQUM7SUFDL0MsSUFBSUUsV0FBVyxHQUFHLEtBQUs7SUFFdkIsSUFBSUQsWUFBWSxHQUFHLENBQUMsRUFBRTtNQUNsQkEsWUFBWSxHQUFHRixNQUFNLENBQUNsRSxPQUFPLENBQUMsS0FBSyxDQUFDO01BQ3BDZ0UsTUFBTSxDQUFDeEssR0FBRyxDQUFDLEtBQUssQ0FBQztJQUNyQjtJQUVBdUssT0FBTyxDQUFDRSxNQUFNLENBQUM7TUFDWEssR0FBRyxFQUFFLENBQUM7TUFDTkMsR0FBRyxFQUFFTCxNQUFNLENBQUNwTCxNQUFNLEdBQUcsQ0FBQztNQUN0QjBMLElBQUksRUFBRSxDQUFDO01BQ1BDLE1BQU0sRUFBRSxJQUFJO01BQ1pDLGdCQUFnQixFQUFFLEtBQUs7TUFDdkJDLGNBQWMsRUFBRSxTQUFBQSxDQUFVdE0sS0FBSyxFQUFFO1FBQzdCLE9BQU8rRixNQUFNLENBQUMvRixLQUFLLENBQUMsSUFBSSxFQUFFO01BQzlCLENBQUM7TUFDRHVNLFFBQVEsRUFBRSxTQUFBQSxDQUFVdk0sS0FBSyxFQUFFO1FBQ3ZCLElBQUkrSSxRQUFRLEdBQUc4QyxNQUFNLENBQUM3TCxLQUFLLENBQUMsSUFBSSxLQUFLO1FBRXJDMkwsTUFBTSxDQUFDeEssR0FBRyxDQUFDNEgsUUFBUSxDQUFDO1FBQ3BCLElBQUlpRCxXQUFXLEVBQUU7VUFDYkwsTUFBTSxDQUFDOUosT0FBTyxDQUFDLFFBQVEsQ0FBQztVQUN4QjJLLHVCQUF1QixDQUFDZixhQUFhLENBQUM7VUFDdEMsSUFBSSxPQUFPZ0IsSUFBSSxLQUFLLFdBQVcsSUFBSSxPQUFPQSxJQUFJLENBQUNDLFdBQVcsS0FBSyxVQUFVLEVBQUU7WUFDdkVELElBQUksQ0FBQ0MsV0FBVyxDQUFDLENBQUM7VUFDdEI7UUFDSjtNQUNKO0lBQ0osQ0FBQyxDQUFDO0lBQ0ZoQixPQUFPLENBQUNFLE1BQU0sQ0FBQyxXQUFXLEVBQUVHLFlBQVksRUFBRSxLQUFLLENBQUM7SUFDaERDLFdBQVcsR0FBRyxJQUFJO0VBQ3RCO0VBRUEsU0FBU1kseUJBQXlCQSxDQUFDQyxjQUFjLEVBQUVDLGFBQWEsRUFBRTtJQUM5RCxPQUFPQSxhQUFhLEtBQUssQ0FBQyxJQUFLRCxjQUFjLEdBQUcsQ0FBQyxJQUFJQSxjQUFjLElBQUlDLGFBQWM7RUFDekY7RUFFQSxTQUFTTix1QkFBdUJBLENBQUNmLGFBQWEsRUFBRTtJQUM1QyxJQUFJb0IsY0FBYyxHQUFHekksTUFBTSxDQUFDcUgsYUFBYSxDQUFDNUssSUFBSSxDQUFDLGdDQUFnQyxDQUFDLENBQUNNLEdBQUcsQ0FBQyxDQUFDLENBQUM7SUFDdkYsSUFBSTJMLGFBQWEsR0FBRzFJLE1BQU0sQ0FBQ3FILGFBQWEsQ0FBQzVLLElBQUksQ0FBQyx5Q0FBeUMsQ0FBQyxDQUFDTSxHQUFHLENBQUMsQ0FBQyxDQUFDO0lBQy9GLElBQUk0TCxVQUFVLEdBQUczSSxNQUFNLENBQUNDLFFBQVEsQ0FBQ3dJLGNBQWMsQ0FBQyxJQUN6Q3pJLE1BQU0sQ0FBQ0MsUUFBUSxDQUFDeUksYUFBYSxDQUFDLElBQzlCRix5QkFBeUIsQ0FBQ0MsY0FBYyxFQUFFQyxhQUFhLENBQUM7SUFDL0QsSUFBSXhFLFFBQVEsR0FBR21ELGFBQWEsQ0FBQzVLLElBQUksQ0FBQyxpQ0FBaUMsQ0FBQztJQUVwRXlILFFBQVEsQ0FBQzBFLFdBQVcsQ0FBQyxRQUFRLEVBQUVELFVBQVUsQ0FBQyxDQUFDRSxJQUFJLENBQUMsYUFBYSxFQUFFRixVQUFVLEdBQUcsTUFBTSxHQUFHLE9BQU8sQ0FBQztJQUU3RixPQUFPQSxVQUFVO0VBQ3JCO0VBRUEsU0FBU0csMEJBQTBCQSxDQUFDQyxVQUFVLEVBQUU7SUFDNUMsSUFBSUMsV0FBVyxHQUFHN00sQ0FBQyxDQUFDLHlDQUF5QyxDQUFDO0lBQzlELElBQUk4TSxZQUFZLEdBQUc5TSxDQUFDLENBQUMsOEZBQThGLENBQUM7SUFDcEgsSUFBSStNLHFCQUFxQixHQUFHL00sQ0FBQyxDQUFDLG1DQUFtQyxDQUFDO0lBQ2xFLElBQUlnTixnQkFBZ0IsR0FBR3hLLE1BQU0sQ0FBQ29LLFVBQVUsQ0FBQ3pLLElBQUksQ0FBQyxtQkFBbUIsQ0FBQyxJQUFJLEVBQUUsQ0FBQyxDQUFDTSxJQUFJLENBQUMsQ0FBQyxDQUFDa0UsV0FBVyxDQUFDLENBQUM7SUFDOUYsSUFBSXNHLGdCQUFnQixHQUFHak4sQ0FBQyxDQUFDLHlFQUF5RSxDQUFDO0lBQ25HO0lBQ0EsSUFBSWtOLGFBQWEsR0FBRyxDQUFDLENBQUM7SUFFdEJKLFlBQVksQ0FBQ3ZKLE1BQU0sQ0FBQyxVQUFVLENBQUMsQ0FBQzRKLElBQUksQ0FBQyxZQUFZO01BQzdDRCxhQUFhLENBQUMxSyxNQUFNLENBQUN4QyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMwTSxJQUFJLENBQUMsZUFBZSxDQUFDLENBQUMsQ0FBQyxHQUFHbEssTUFBTSxDQUFDeEMsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDWSxHQUFHLENBQUMsQ0FBQyxDQUFDO0lBQ2hGLENBQUMsQ0FBQztJQUVGLFNBQVN3TSxhQUFhQSxDQUFBLEVBQUc7TUFDckIsT0FBT04sWUFBWSxDQUFDdkosTUFBTSxDQUFDLFVBQVUsQ0FBQyxDQUFDQSxNQUFNLENBQUMsWUFBWTtRQUN0RCxPQUFPMkosYUFBYSxDQUFDMUssTUFBTSxDQUFDeEMsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDME0sSUFBSSxDQUFDLGVBQWUsQ0FBQyxDQUFDLENBQUMsS0FBS2xLLE1BQU0sQ0FBQ3hDLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ1ksR0FBRyxDQUFDLENBQUMsQ0FBQztNQUN6RixDQUFDLENBQUM7SUFDTjtJQUVBLFNBQVN5TSxxQkFBcUJBLENBQUNqQyxNQUFNLEVBQUU7TUFDbkMsSUFBSWtDLGtCQUFrQjtNQUV0QixJQUFJTixnQkFBZ0IsS0FBSyxFQUFFLElBQUlBLGdCQUFnQixLQUFLLE1BQU0sRUFBRTtRQUN4RCxPQUFPLElBQUk7TUFDZjtNQUVBTSxrQkFBa0IsR0FBRzlLLE1BQU0sQ0FBQzRJLE1BQU0sQ0FBQ3NCLElBQUksQ0FBQywwQkFBMEIsQ0FBQyxJQUFJLEVBQUUsQ0FBQyxDQUNyRWEsS0FBSyxDQUFDLEdBQUcsQ0FBQyxDQUNWQyxHQUFHLENBQUMsVUFBVUMsUUFBUSxFQUFFO1FBQUUsT0FBT0EsUUFBUSxDQUFDaEwsSUFBSSxDQUFDLENBQUMsQ0FBQ2tFLFdBQVcsQ0FBQyxDQUFDO01BQUUsQ0FBQyxDQUFDLENBQ2xFcEQsTUFBTSxDQUFDLFVBQVVrSyxRQUFRLEVBQUU7UUFBRSxPQUFPQSxRQUFRLEtBQUssRUFBRTtNQUFFLENBQUMsQ0FBQztNQUU1RCxPQUFPSCxrQkFBa0IsQ0FBQ2xHLE9BQU8sQ0FBQzRGLGdCQUFnQixDQUFDLEtBQUssQ0FBQyxDQUFDO0lBQzlEO0lBRUEsU0FBU1UsWUFBWUEsQ0FBQ0MsUUFBUSxFQUFFO01BQzVCVixnQkFBZ0IsQ0FBQ0UsSUFBSSxDQUFDLFlBQVk7UUFDOUIsSUFBSVMsTUFBTSxHQUFHcEwsTUFBTSxDQUFDeEMsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDME0sSUFBSSxDQUFDLGVBQWUsQ0FBQyxDQUFDLEtBQUtpQixRQUFRO1FBRS9EM04sQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDeU0sV0FBVyxDQUFDLFFBQVEsRUFBRW1CLE1BQU0sQ0FBQyxDQUFDbEIsSUFBSSxDQUFDLGNBQWMsRUFBRWtCLE1BQU0sR0FBRyxNQUFNLEdBQUcsT0FBTyxDQUFDO01BQ3pGLENBQUMsQ0FBQztNQUNGNU4sQ0FBQyxDQUFDLG9FQUFvRSxDQUFDLENBQUNtTixJQUFJLENBQUMsWUFBWTtRQUNyRm5OLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ29HLElBQUksQ0FBQyxRQUFRLEVBQUU1RCxNQUFNLENBQUN4QyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMwTSxJQUFJLENBQUMsZUFBZSxDQUFDLENBQUMsS0FBS2lCLFFBQVEsQ0FBQztNQUM5RSxDQUFDLENBQUM7SUFDTjtJQUVBLFNBQVNFLHFCQUFxQkEsQ0FBQSxFQUFHO01BQzdCLElBQUlDLFFBQVEsR0FBR1YsYUFBYSxDQUFDLENBQUM7TUFDOUIsSUFBSVosVUFBVSxHQUFHLElBQUk7TUFDckIsSUFBSXVCLFVBQVU7TUFFZEQsUUFBUSxDQUFDWCxJQUFJLENBQUMsWUFBWTtRQUN0QlgsVUFBVSxHQUFHQSxVQUFVLElBQUlhLHFCQUFxQixDQUFDck4sQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDO01BQzdELENBQUMsQ0FBQztNQUNGK00scUJBQXFCLENBQ2hCTixXQUFXLENBQUMsUUFBUSxFQUFFRCxVQUFVLENBQUMsQ0FDakNFLElBQUksQ0FBQyxhQUFhLEVBQUVGLFVBQVUsR0FBRyxNQUFNLEdBQUcsT0FBTyxDQUFDO01BRXZEdUIsVUFBVSxHQUFHRCxRQUFRLENBQUM1TixNQUFNLEdBQUcsQ0FBQyxJQUFJc00sVUFBVTtNQUM5Q0ssV0FBVyxDQUFDSixXQUFXLENBQUMsVUFBVSxFQUFFLENBQUNzQixVQUFVLENBQUM7TUFDaERsQixXQUFXLENBQUN6RyxJQUFJLENBQUMsVUFBVSxFQUFFLENBQUMySCxVQUFVLENBQUM7SUFDN0M7SUFFQSxJQUFJLE9BQU8vTixDQUFDLENBQUNHLEVBQUUsQ0FBQzZOLFNBQVMsS0FBSyxVQUFVLEVBQUU7TUFDdENoTyxDQUFDLENBQUMsOERBQThELENBQUMsQ0FBQ2dPLFNBQVMsQ0FBQyxDQUFDO0lBQ2pGO0lBRUFoTyxDQUFDLENBQUMsb0VBQW9FLENBQUMsQ0FBQ2lDLEVBQUUsQ0FBQyxPQUFPLEVBQUUsVUFBVUksS0FBSyxFQUFFO01BQ2pHLElBQUlyQyxDQUFDLENBQUNxQyxLQUFLLENBQUM0TCxNQUFNLENBQUMsQ0FBQ2xELE9BQU8sQ0FBQywrQkFBK0IsQ0FBQyxDQUFDN0ssTUFBTSxHQUFHLENBQUMsRUFBRTtRQUNyRTtNQUNKO01BRUFGLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ00sSUFBSSxDQUFDLG9CQUFvQixDQUFDLENBQUM0TixRQUFRLENBQUMsT0FBTyxDQUFDO0lBQ3hELENBQUMsQ0FBQztJQUVGakIsZ0JBQWdCLENBQUNoTCxFQUFFLENBQUMsT0FBTyxFQUFFLFlBQVk7TUFDckN5TCxZQUFZLENBQUNsTCxNQUFNLENBQUN4QyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMwTSxJQUFJLENBQUMsZUFBZSxDQUFDLENBQUMsQ0FBQztJQUN2RCxDQUFDLENBQUM7SUFFRkksWUFBWSxDQUFDN0ssRUFBRSxDQUFDLFFBQVEsRUFBRSxZQUFZO01BQ2xDLElBQUlrTSxJQUFJLEdBQUduTyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMrSyxPQUFPLENBQUMsSUFBSSxDQUFDO01BRWhDb0QsSUFBSSxDQUFDNUgsUUFBUSxDQUFDLFVBQVUsQ0FBQyxDQUNwQjZILFFBQVEsQ0FBQyxrQkFBa0IsR0FBRzVMLE1BQU0sQ0FBQ3hDLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQzBNLElBQUksQ0FBQyxlQUFlLENBQUMsQ0FBQyxHQUFHLElBQUksQ0FBQyxDQUMzRXJHLFdBQVcsQ0FBQyxVQUFVLENBQUM7TUFDNUJ3SCxxQkFBcUIsQ0FBQyxDQUFDO0lBQzNCLENBQUMsQ0FBQztJQUVGakIsVUFBVSxDQUFDM0ssRUFBRSxDQUFDLFFBQVEsRUFBRSxZQUFZO01BQ2hDLElBQUk0SyxXQUFXLENBQUN6RyxJQUFJLENBQUMsVUFBVSxDQUFDLEVBQUU7UUFDOUIsT0FBTyxLQUFLO01BQ2hCO01BQ0EsSUFBSXlHLFdBQVcsQ0FBQzNNLE1BQU0sR0FBRyxDQUFDLEVBQUU7UUFDeEIyTSxXQUFXLENBQUN0RyxRQUFRLENBQUMseUJBQXlCLENBQUM7UUFDL0NzRyxXQUFXLENBQUN6RyxJQUFJLENBQUMsVUFBVSxFQUFFLElBQUksQ0FBQztNQUN0QztJQUNKLENBQUMsQ0FBQztJQUVGeUgscUJBQXFCLENBQUMsQ0FBQztFQUMzQjtFQUVBLFNBQVNRLHNCQUFzQkEsQ0FBQSxFQUFHO0lBQzlCLElBQUlDLE1BQU0sR0FBR3RPLENBQUMsQ0FBQyxvQkFBb0IsQ0FBQztJQUVwQyxJQUFJc08sTUFBTSxDQUFDcE8sTUFBTSxLQUFLLENBQUMsSUFBSSxPQUFPb08sTUFBTSxDQUFDQyxLQUFLLEtBQUssVUFBVSxFQUFFO01BQzNEO0lBQ0o7O0lBRUE7SUFDQUQsTUFBTSxDQUFDQyxLQUFLLENBQUMsQ0FBQyxDQUFDLENBQUM7SUFFaEIsU0FBU0MsV0FBV0EsQ0FBQ3JJLE9BQU8sRUFBRXNJLFNBQVMsRUFBRTtNQUNyQ0gsTUFBTSxDQUFDaE8sSUFBSSxDQUFDLHNCQUFzQixDQUFDLENBQUNnRyxJQUFJLENBQUNILE9BQU8sQ0FBQztNQUNqRG1JLE1BQU0sQ0FBQ0MsS0FBSyxDQUFDO1FBQ1RHLFFBQVEsRUFBRSxJQUFJO1FBQ2RDLFNBQVMsRUFBRSxTQUFBQSxDQUFBLEVBQVk7VUFDbkJGLFNBQVMsQ0FBQyxDQUFDO1FBQ2Y7TUFDSixDQUFDLENBQUMsQ0FBQ0YsS0FBSyxDQUFDLE1BQU0sQ0FBQztJQUNwQjtJQUVBLFNBQVNLLG1CQUFtQkEsQ0FBQzdPLEtBQUssRUFBRTtNQUNoQ0EsS0FBSyxDQUFDa0MsRUFBRSxDQUFDLG9CQUFvQixFQUFFLFVBQVVJLEtBQUssRUFBRTtRQUM1QyxJQUFJOEQsT0FBTyxHQUFHbkcsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDbUMsSUFBSSxDQUFDLFNBQVMsQ0FBQyxJQUFJLEVBQUU7UUFFM0MsSUFBSWdFLE9BQU8sS0FBSyxFQUFFLEVBQUU7VUFDaEI7UUFDSjtRQUVBOUQsS0FBSyxDQUFDRSxjQUFjLENBQUMsQ0FBQztRQUN0QkYsS0FBSyxDQUFDd00sd0JBQXdCLENBQUMsQ0FBQztRQUVoQyxJQUFJQyxLQUFLLEdBQUc5TyxDQUFDLENBQUMsSUFBSSxDQUFDO1FBQ25Cd08sV0FBVyxDQUFDckksT0FBTyxFQUFFLFlBQVk7VUFDN0IySSxLQUFLLENBQUNDLEdBQUcsQ0FBQyxvQkFBb0IsQ0FBQyxDQUFDek4sT0FBTyxDQUFDLFFBQVEsQ0FBQztRQUNyRCxDQUFDLENBQUM7TUFDTixDQUFDLENBQUM7SUFDTjtJQUVBdEIsQ0FBQyxDQUFDLG9EQUFvRCxDQUFDLENBQUNtTixJQUFJLENBQUMsWUFBWTtNQUNyRXlCLG1CQUFtQixDQUFDNU8sQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDO0lBQ2hDLENBQUMsQ0FBQztFQUNOOztFQUVBO0VBQ0E7RUFDQTtFQUNBO0VBQ0EsU0FBU2dQLDRCQUE0QkEsQ0FBQSxFQUFHO0lBQ3BDLElBQUlWLE1BQU0sR0FBR3RPLENBQUMsQ0FBQyw4QkFBOEIsQ0FBQztJQUM5QyxJQUFJaVAsTUFBTSxHQUFHalAsQ0FBQyxDQUFDLDBEQUEwRCxDQUFDO0lBRTFFLElBQUlzTyxNQUFNLENBQUNwTyxNQUFNLEtBQUssQ0FBQyxJQUFJK08sTUFBTSxDQUFDL08sTUFBTSxLQUFLLENBQUMsSUFBSSxPQUFPb08sTUFBTSxDQUFDQyxLQUFLLEtBQUssVUFBVSxFQUFFO01BQ2xGO0lBQ0o7SUFFQSxJQUFJVyxZQUFZLEdBQUdaLE1BQU0sQ0FBQ2hPLElBQUksQ0FBQyxvQ0FBb0MsQ0FBQztJQUNwRSxJQUFJNk8sUUFBUSxHQUFHYixNQUFNLENBQUNoTyxJQUFJLENBQUMsaUJBQWlCLENBQUM7SUFDN0MsSUFBSThPLFdBQVcsR0FBRyxJQUFJO0lBRXRCLFNBQVNDLGlCQUFpQkEsQ0FBQ0MsT0FBTyxFQUFFO01BQ2hDSCxRQUFRLENBQUMxQyxXQUFXLENBQUMsVUFBVSxFQUFFLENBQUM2QyxPQUFPLENBQUMsQ0FBQ2xKLElBQUksQ0FBQyxVQUFVLEVBQUUsQ0FBQ2tKLE9BQU8sQ0FBQztJQUN6RTs7SUFFQTtJQUNBaEIsTUFBTSxDQUFDQyxLQUFLLENBQUMsQ0FBQyxDQUFDLENBQUM7SUFDaEJXLFlBQVksQ0FBQ2pOLEVBQUUsQ0FBQyxRQUFRLEVBQUUsWUFBWTtNQUNsQ29OLGlCQUFpQixDQUFDSCxZQUFZLENBQUM5SSxJQUFJLENBQUMsU0FBUyxDQUFDLENBQUM7SUFDbkQsQ0FBQyxDQUFDO0lBRUY2SSxNQUFNLENBQUNoTixFQUFFLENBQUMsOEJBQThCLEVBQUUsVUFBVUksS0FBSyxFQUFFO01BQ3ZEQSxLQUFLLENBQUNFLGNBQWMsQ0FBQyxDQUFDO01BQ3RCNk0sV0FBVyxHQUFHLElBQUk7TUFDbEJwUCxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMrSyxPQUFPLENBQUMsU0FBUyxDQUFDLENBQUMzRSxJQUFJLENBQUMsTUFBTSxFQUFFLEtBQUssQ0FBQztNQUM5QzhJLFlBQVksQ0FBQzlJLElBQUksQ0FBQyxTQUFTLEVBQUUsS0FBSyxDQUFDO01BQ25DaUosaUJBQWlCLENBQUMsS0FBSyxDQUFDO01BQ3hCZixNQUFNLENBQUNDLEtBQUssQ0FBQztRQUNURyxRQUFRLEVBQUUsSUFBSTtRQUNkQyxTQUFTLEVBQUUsU0FBQUEsQ0FBQSxFQUFZO1VBQ25CLElBQUksQ0FBQ08sWUFBWSxDQUFDOUksSUFBSSxDQUFDLFNBQVMsQ0FBQyxJQUFJZ0osV0FBVyxLQUFLLElBQUksRUFBRTtZQUN2RCxPQUFPLEtBQUs7VUFDaEI7VUFDQSxJQUFJRyxJQUFJLEdBQUdILFdBQVc7VUFDdEJBLFdBQVcsR0FBRyxJQUFJO1VBQ2xCcFAsQ0FBQyxDQUFDLFNBQVMsRUFBRTtZQUFFd1AsSUFBSSxFQUFFLFFBQVE7WUFBRUMsSUFBSSxFQUFFLGdCQUFnQjtZQUFFaFEsS0FBSyxFQUFFO1VBQUksQ0FBQyxDQUFDLENBQUNpUSxRQUFRLENBQUNILElBQUksQ0FBQztVQUNuRjtVQUNBQSxJQUFJLENBQUNJLE1BQU0sQ0FBQyxDQUFDO1VBQ2IsT0FBTyxJQUFJO1FBQ2YsQ0FBQztRQUNEQyxRQUFRLEVBQUUsU0FBQUEsQ0FBQSxFQUFZO1VBQ2xCUixXQUFXLEdBQUcsSUFBSTtRQUN0QjtNQUNKLENBQUMsQ0FBQyxDQUFDYixLQUFLLENBQUMsTUFBTSxDQUFDO0lBQ3BCLENBQUMsQ0FBQztFQUNOO0VBRUEsU0FBU3NCLHdCQUF3QkEsQ0FBQSxFQUFHO0lBQ2hDN1AsQ0FBQyxDQUFDLGtEQUFrRCxDQUFDLENBQUNpQyxFQUFFLENBQUMsT0FBTyxFQUFFLFVBQVVJLEtBQUssRUFBRTtNQUMvRSxJQUFJckMsQ0FBQyxDQUFDcUMsS0FBSyxDQUFDNEwsTUFBTSxDQUFDLENBQUNsRCxPQUFPLENBQUMsb0NBQW9DLENBQUMsQ0FBQzdLLE1BQU0sR0FBRyxDQUFDLEVBQUU7UUFDMUU7TUFDSjtNQUVBLElBQUk0UCxXQUFXLEdBQUc5UCxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUNtQyxJQUFJLENBQUMsZ0JBQWdCLENBQUM7TUFDaEQsSUFBSTJOLFdBQVcsRUFBRTtRQUNiN1IsTUFBTSxDQUFDQyxRQUFRLENBQUM2UixJQUFJLEdBQUdELFdBQVc7TUFDdEM7SUFDSixDQUFDLENBQUM7RUFDTjtFQUVBLFNBQVNFLDhCQUE4QkEsQ0FBQSxFQUFHO0lBQ3RDLElBQUlDLE1BQU0sR0FBR2pRLENBQUMsQ0FBQyxzREFBc0QsQ0FBQztJQUV0RWlRLE1BQU0sQ0FBQzNQLElBQUksQ0FBQyx1QkFBdUIsQ0FBQyxDQUFDMkIsRUFBRSxDQUFDLDhCQUE4QixFQUFFLFlBQVk7TUFDaEZqQyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMrSyxPQUFPLENBQUMsU0FBUyxDQUFDLENBQUNtRixVQUFVLENBQUMsTUFBTSxDQUFDO0lBQ2pELENBQUMsQ0FBQztJQUNGbFEsQ0FBQyxDQUFDbVEsUUFBUSxDQUFDLENBQUNsTyxFQUFFLENBQUMsOEJBQThCLEVBQUUsVUFBVUksS0FBSyxFQUFFO01BQzVENE4sTUFBTSxDQUFDOUMsSUFBSSxDQUFDLFlBQVk7UUFDcEIsSUFBSSxJQUFJLEtBQUs5SyxLQUFLLENBQUM0TCxNQUFNLElBQUksQ0FBQyxJQUFJLENBQUNtQyxRQUFRLENBQUMvTixLQUFLLENBQUM0TCxNQUFNLENBQUMsRUFBRTtVQUN2RGpPLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ2tRLFVBQVUsQ0FBQyxNQUFNLENBQUM7UUFDOUI7TUFDSixDQUFDLENBQUM7SUFDTixDQUFDLENBQUM7RUFDTjtFQUVBLFNBQVNHLDJCQUEyQkEsQ0FBQSxFQUFHO0lBQ25DLElBQUlDLGtCQUFrQixHQUFHclMsTUFBTSxDQUFDc1MsaUJBQWlCLElBQUl0UyxNQUFNLENBQUNzUyxpQkFBaUIsQ0FBQ3JTLFFBQVE7SUFFdEY4QixDQUFDLENBQUMsbURBQW1ELENBQUMsQ0FBQ21OLElBQUksQ0FBQyxZQUFZO01BQ3BFLElBQUlxRCxPQUFPLEdBQUd4USxDQUFDLENBQUMsSUFBSSxDQUFDO01BQ3JCLElBQUl5USxLQUFLLEdBQUdELE9BQU8sQ0FBQ2xRLElBQUksQ0FBQyxxQkFBcUIsQ0FBQztNQUMvQyxJQUFJb1EsT0FBTyxHQUFHRixPQUFPLENBQUNsUSxJQUFJLENBQUMsMkJBQTJCLENBQUM7TUFFdkQsU0FBU3FRLFFBQVFBLENBQUNDLFFBQVEsRUFBRTtRQUN4QixPQUFPcE8sTUFBTSxDQUFDb08sUUFBUSxDQUFDN0YsT0FBTyxDQUFDLDJCQUEyQixDQUFDLENBQUM1SSxJQUFJLENBQUMsb0JBQW9CLENBQUMsSUFBSSxFQUFFLENBQUM7TUFDakc7TUFFQSxTQUFTME8sSUFBSUEsQ0FBQ0MsT0FBTyxFQUFFO1FBQ25CTCxLQUFLLENBQUN0RCxJQUFJLENBQUMsWUFBWTtVQUNuQixJQUFJUyxNQUFNLEdBQUdwTCxNQUFNLENBQUN4QyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUNtQyxJQUFJLENBQUMsY0FBYyxDQUFDLElBQUksRUFBRSxDQUFDLEtBQUsyTyxPQUFPO1VBQ25FOVEsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDeU0sV0FBVyxDQUFDLFFBQVEsRUFBRW1CLE1BQU0sQ0FBQyxDQUFDbEIsSUFBSSxDQUFDLGVBQWUsRUFBRWtCLE1BQU0sR0FBRyxNQUFNLEdBQUcsT0FBTyxDQUFDO1FBQzFGLENBQUMsQ0FBQztRQUNGOEMsT0FBTyxDQUFDdkQsSUFBSSxDQUFDLFlBQVk7VUFDckIsSUFBSTRELE1BQU0sR0FBR3ZPLE1BQU0sQ0FBQ3hDLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ21DLElBQUksQ0FBQyxvQkFBb0IsQ0FBQyxJQUFJLEVBQUUsQ0FBQyxLQUFLMk8sT0FBTztVQUV6RTlRLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ29HLElBQUksQ0FBQyxRQUFRLEVBQUUySyxNQUFNLENBQUM7VUFDOUIsSUFBSUEsTUFBTSxJQUFJOVMsTUFBTSxDQUFDc1MsaUJBQWlCLElBQUksT0FBT3RTLE1BQU0sQ0FBQ3NTLGlCQUFpQixDQUFDUyxLQUFLLEtBQUssVUFBVSxFQUFFO1lBQzVGL1MsTUFBTSxDQUFDc1MsaUJBQWlCLENBQUNTLEtBQUssQ0FBQyxJQUFJLENBQUM7VUFDeEMsQ0FBQyxNQUFNLElBQUlELE1BQU0sRUFBRTtZQUNmL1EsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDTSxJQUFJLENBQUMsT0FBTyxDQUFDLENBQUM2TSxJQUFJLENBQUMsWUFBWTtjQUNuQyxJQUFJOEQsS0FBSyxHQUFHLElBQUk7Y0FDaEJBLEtBQUssQ0FBQ0QsS0FBSyxDQUFDLENBQUM7WUFDakIsQ0FBQyxDQUFDO1VBQ047UUFDSixDQUFDLENBQUM7TUFDTjtNQUVBUCxLQUFLLENBQUN4TyxFQUFFLENBQUMsT0FBTyxFQUFFLFlBQVk7UUFDMUIsSUFBSTZPLE9BQU8sR0FBR3RPLE1BQU0sQ0FBQ3hDLENBQUMsQ0FBQyxJQUFJLENBQUMsQ0FBQ21DLElBQUksQ0FBQyxjQUFjLENBQUMsSUFBSSxFQUFFLENBQUM7UUFFeEQwTyxJQUFJLENBQUNDLE9BQU8sQ0FBQztRQUNiLElBQUlSLGtCQUFrQixFQUFFO1VBQ3BCO1VBQ0EsSUFBSTNRLE1BQU0sR0FBRzJRLGtCQUFrQixDQUFDWSxJQUFJLENBQUNqVCxNQUFNLENBQUNDLFFBQVEsQ0FBQ0MsTUFBTSxDQUFDO1VBQzVELElBQUlnVCxNQUFNLEdBQUd4UixNQUFNLENBQUN5UixLQUFLLEdBQUdaLE9BQU8sQ0FBQ2xRLElBQUksQ0FBQyx1Q0FBdUMsR0FBR1gsTUFBTSxDQUFDeVIsS0FBSyxHQUFHLElBQUksQ0FBQyxHQUFHcFIsQ0FBQyxDQUFDLENBQUM7VUFDN0csSUFBSXFSLE9BQU8sR0FBRztZQUFFQyxHQUFHLEVBQUVSO1VBQVEsQ0FBQztVQUM5QixJQUFJSyxNQUFNLENBQUNqUixNQUFNLEdBQUcsQ0FBQyxJQUFJeVEsUUFBUSxDQUFDUSxNQUFNLENBQUMsS0FBS0wsT0FBTyxFQUFFO1lBQ25ETyxPQUFPLENBQUNELEtBQUssR0FBRyxFQUFFO1VBQ3RCO1VBQ0FkLGtCQUFrQixDQUFDaUIsS0FBSyxDQUFDRixPQUFPLENBQUM7UUFDckM7TUFDSixDQUFDLENBQUM7O01BRUY7TUFDQSxJQUFJZixrQkFBa0IsRUFBRTtRQUNwQixJQUFJa0IsT0FBTyxHQUFHbEIsa0JBQWtCLENBQUNZLElBQUksQ0FBQ2pULE1BQU0sQ0FBQ0MsUUFBUSxDQUFDQyxNQUFNLENBQUM7UUFDN0QsSUFBSXNULE9BQU8sR0FBR0QsT0FBTyxDQUFDSixLQUFLLEdBQUdaLE9BQU8sQ0FBQ2xRLElBQUksQ0FBQyx1Q0FBdUMsR0FBR2tSLE9BQU8sQ0FBQ0osS0FBSyxHQUFHLElBQUksQ0FBQyxHQUFHcFIsQ0FBQyxDQUFDLENBQUM7UUFDaEgsSUFBSXNSLEdBQUcsR0FBR0csT0FBTyxDQUFDdlIsTUFBTSxHQUFHLENBQUMsR0FBR3lRLFFBQVEsQ0FBQ2MsT0FBTyxDQUFDLEdBQUdELE9BQU8sQ0FBQ0YsR0FBRztRQUM5RCxJQUFJQSxHQUFHLEtBQUssRUFBRSxJQUFJYixLQUFLLENBQUNsTixNQUFNLENBQUMsc0JBQXNCLEdBQUcrTixHQUFHLEdBQUcsSUFBSSxDQUFDLENBQUNwUixNQUFNLEdBQUcsQ0FBQyxFQUFFO1VBQzVFMlEsSUFBSSxDQUFDUyxHQUFHLENBQUM7UUFDYjtNQUNKO0lBQ0osQ0FBQyxDQUFDO0VBQ047RUFFQXRSLENBQUMsQ0FBQyxZQUFZO0lBQ1Y7SUFDQTtJQUNBO0lBQ0E7SUFDQSxJQUFJMFIsUUFBUSxHQUFHMVIsQ0FBQyxDQUFDLG1DQUFtQyxDQUFDO0lBQ3JELElBQUkyUixTQUFTLEdBQUdELFFBQVEsQ0FBQ3BSLElBQUksQ0FBQyxpQkFBaUIsQ0FBQztJQUNoRCxJQUFJNEssYUFBYSxHQUFHbEwsQ0FBQyxDQUFDLG1DQUFtQyxDQUFDO0lBQzFELElBQUk0TSxVQUFVLEdBQUc1TSxDQUFDLENBQUMseUNBQXlDLENBQUM7SUFDN0QyUixTQUFTLENBQUNDLEdBQUcsQ0FBQztNQUNWQyxTQUFTLEVBQUUsU0FBQUEsQ0FBQSxFQUFZO1FBQ25CO1FBQ0E7UUFDQTtRQUNBO1FBQ0E7UUFDQTtRQUNBO1FBQ0E7UUFDQTtRQUNBO1FBQ0FILFFBQVEsQ0FBQ3BSLElBQUksQ0FBQyxPQUFPLENBQUMsQ0FBQ3dSLEdBQUcsQ0FBQyxZQUFZLENBQUMsQ0FBQ3pMLFdBQVcsQ0FBQyxRQUFRLENBQUM7TUFDbEU7SUFDSixDQUFDLENBQUM7SUFDRixJQUFJdkksWUFBWSxDQUFDLENBQUMsS0FBSyxFQUFFLElBQUlBLFlBQVksQ0FBQyxDQUFDLEtBQUssYUFBYSxFQUFFO01BQzNEO01BQ0E7TUFDQTtNQUNBO01BQ0E7TUFDQTZULFNBQVMsQ0FBQ0MsR0FBRyxDQUFDLFlBQVksRUFBRTlULFlBQVksQ0FBQyxDQUFDLENBQUM7SUFDL0M7SUFFQWtDLENBQUMsQ0FBQywyQ0FBMkMsQ0FBQyxDQUFDK1IsUUFBUSxDQUFDLENBQUM7SUFDekQvUixDQUFDLENBQUMsMkNBQTJDLENBQUMsQ0FBQ2tPLFFBQVEsQ0FBQyxDQUFDO0lBQ3pEbE8sQ0FBQyxDQUFDLDZDQUE2QyxDQUFDLENBQUNnUyxLQUFLLENBQUMsQ0FBQztJQUN4RHJGLDBCQUEwQixDQUFDQyxVQUFVLENBQUM7SUFDdEM5TSw4QkFBOEIsQ0FBQyxDQUFDO0lBQ2hDNEMsb0JBQW9CLENBQUMsQ0FBQztJQUN0QnVJLDBCQUEwQixDQUFDQyxhQUFhLENBQUM7SUFDekNrQixtQ0FBbUMsQ0FBQ2xCLGFBQWEsQ0FBQztJQUNsRGUsdUJBQXVCLENBQUNmLGFBQWEsQ0FBQztJQUN0Q21ELHNCQUFzQixDQUFDLENBQUM7SUFDeEJXLDRCQUE0QixDQUFDLENBQUM7SUFDOUJhLHdCQUF3QixDQUFDLENBQUM7SUFDMUJHLDhCQUE4QixDQUFDLENBQUM7SUFDaENLLDJCQUEyQixDQUFDLENBQUM7SUFFN0IsSUFBSXJRLENBQUMsQ0FBQ0csRUFBRSxDQUFDb1AsSUFBSSxJQUFJdlAsQ0FBQyxDQUFDRyxFQUFFLENBQUNvUCxJQUFJLENBQUN2SyxRQUFRLElBQUloRixDQUFDLENBQUNHLEVBQUUsQ0FBQ29QLElBQUksQ0FBQ3ZLLFFBQVEsQ0FBQ2lOLEtBQUssRUFBRTtNQUM3RGpTLENBQUMsQ0FBQ0csRUFBRSxDQUFDb1AsSUFBSSxDQUFDdkssUUFBUSxDQUFDaU4sS0FBSyxDQUFDQyxrQkFBa0IsR0FBRyxVQUFVelMsS0FBSyxFQUFFMFMsS0FBSyxFQUFFO1FBQ2xFLElBQUlDLE1BQU0sR0FBRzVQLE1BQU0sQ0FBQzJQLEtBQUssSUFBSSxFQUFFLENBQUMsQ0FBQzVFLEtBQUssQ0FBQyxHQUFHLENBQUMsQ0FBQ0MsR0FBRyxDQUFDM0osTUFBTSxDQUFDO1FBQ3ZELElBQUl3TyxNQUFNLEdBQUd4TyxNQUFNLENBQUNwRSxLQUFLLENBQUM7UUFFMUIsT0FBTzJTLE1BQU0sQ0FBQ2xTLE1BQU0sS0FBSyxDQUFDLElBQ25CMkQsTUFBTSxDQUFDQyxRQUFRLENBQUN1TyxNQUFNLENBQUMsSUFDdkJBLE1BQU0sSUFBSUQsTUFBTSxDQUFDLENBQUMsQ0FBQyxJQUNuQkMsTUFBTSxJQUFJRCxNQUFNLENBQUMsQ0FBQyxDQUFDO01BQzlCLENBQUM7TUFDRHBTLENBQUMsQ0FBQ0csRUFBRSxDQUFDb1AsSUFBSSxDQUFDdkssUUFBUSxDQUFDaU4sS0FBSyxDQUFDSyxvQkFBb0IsR0FBRyxVQUFVN1MsS0FBSyxFQUFFO1FBQzdELElBQUk4UyxTQUFTLEdBQUdDLElBQUksQ0FBQ0MsS0FBSyxDQUFDNU8sTUFBTSxDQUFDcEUsS0FBSyxDQUFDLEdBQUcsSUFBSSxDQUFDO1FBQ2hELElBQUlpVCxVQUFVLEdBQUdGLElBQUksQ0FBQ0MsS0FBSyxDQUFDNU8sTUFBTSxDQUFDcUgsYUFBYSxDQUFDNUssSUFBSSxDQUFDLG1DQUFtQyxDQUFDLENBQUNNLEdBQUcsQ0FBQyxDQUFDLENBQUMsR0FBRyxJQUFJLENBQUM7UUFFekcsT0FBT2lELE1BQU0sQ0FBQ0MsUUFBUSxDQUFDeU8sU0FBUyxDQUFDLElBQzFCMU8sTUFBTSxDQUFDQyxRQUFRLENBQUM0TyxVQUFVLENBQUMsSUFDM0JILFNBQVMsSUFBSSxDQUFDLElBQ2RBLFNBQVMsR0FBR0csVUFBVTtNQUNqQyxDQUFDO01BQ0QxUyxDQUFDLENBQUNHLEVBQUUsQ0FBQ29QLElBQUksQ0FBQ3ZLLFFBQVEsQ0FBQ2lOLEtBQUssQ0FBQ1UseUJBQXlCLEdBQUcsWUFBWTtRQUM3RCxPQUFPMUcsdUJBQXVCLENBQUNmLGFBQWEsQ0FBQztNQUNqRCxDQUFDO0lBQ0w7SUFFQSxJQUFJMEgsMEJBQTBCLEdBQUc7TUFDN0JDLFdBQVcsRUFBRTtRQUNUQyxVQUFVLEVBQUUsYUFBYTtRQUN6QmIsS0FBSyxFQUFFLENBQUM7VUFBRXpDLElBQUksRUFBRTtRQUEwQixDQUFDO01BQy9DLENBQUM7TUFDRHVELHlCQUF5QixFQUFFO1FBQ3ZCRCxVQUFVLEVBQUUsMkJBQTJCO1FBQ3ZDYixLQUFLLEVBQUUsQ0FBQztVQUFFekMsSUFBSSxFQUFFO1FBQTBCLENBQUM7TUFDL0MsQ0FBQztNQUNEd0QsMEJBQTBCLEVBQUU7UUFDeEJGLFVBQVUsRUFBRSw0QkFBNEI7UUFDeENiLEtBQUssRUFBRSxDQUFDO1VBQUV6QyxJQUFJLEVBQUU7UUFBaUIsQ0FBQztNQUN0QyxDQUFDO01BQ0R5RCxLQUFLLEVBQUU7UUFDSEgsVUFBVSxFQUFFLE9BQU87UUFDbkJiLEtBQUssRUFBRSxDQUFDO1VBQUV6QyxJQUFJLEVBQUU7UUFBa0IsQ0FBQztNQUN2QyxDQUFDO01BQ0QwRCwyQkFBMkIsRUFBRTtRQUN6QkosVUFBVSxFQUFFLDZCQUE2QjtRQUN6Q2IsS0FBSyxFQUFFLENBQUM7VUFBRXpDLElBQUksRUFBRTtRQUEyQixDQUFDO01BQ2hELENBQUM7TUFDRDJELHlCQUF5QixFQUFFO1FBQ3ZCTCxVQUFVLEVBQUUsMkJBQTJCO1FBQ3ZDYixLQUFLLEVBQUUsQ0FBQztVQUFFekMsSUFBSSxFQUFFO1FBQTRCLENBQUM7TUFDakQsQ0FBQztNQUNENEQscUNBQXFDLEVBQUU7UUFDbkNOLFVBQVUsRUFBRSx1Q0FBdUM7UUFDbkRiLEtBQUssRUFBRSxDQUFDO1VBQUV6QyxJQUFJLEVBQUU7UUFBNEIsQ0FBQztNQUNqRCxDQUFDO01BQ0Q2RCxtQkFBbUIsRUFBRTtRQUNqQlAsVUFBVSxFQUFFLHFCQUFxQjtRQUNqQ2IsS0FBSyxFQUFFLENBQUM7VUFBRXpDLElBQUksRUFBRTtRQUEwQixDQUFDO01BQy9DLENBQUM7TUFDRDhELHdCQUF3QixFQUFFO1FBQ3RCUixVQUFVLEVBQUUsMEJBQTBCO1FBQ3RDYixLQUFLLEVBQUUsQ0FBQztVQUFFekMsSUFBSSxFQUFFO1FBQTRCLENBQUM7TUFDakQsQ0FBQztNQUNEK0QsbUJBQW1CLEVBQUU7UUFDakJULFVBQVUsRUFBRSxxQkFBcUI7UUFDakNiLEtBQUssRUFBRSxDQUNIO1VBQUV6QyxJQUFJLEVBQUU7UUFBMkIsQ0FBQyxFQUNwQztVQUFFQSxJQUFJLEVBQUU7UUFBdUIsQ0FBQztNQUV4QztJQUNKLENBQUM7SUFFRCxTQUFTZ0Usd0JBQXdCQSxDQUFBLEVBQUc7TUFDaEMsSUFBSWxFLE9BQU8sR0FBR3BFLGFBQWEsQ0FBQzVLLElBQUksQ0FBQyxtQ0FBbUMsQ0FBQyxDQUFDbVQsRUFBRSxDQUFDLFVBQVUsQ0FBQztNQUVwRnZJLGFBQWEsQ0FBQzVLLElBQUksQ0FBQyxvQkFBb0IsQ0FBQyxDQUNuQ21NLFdBQVcsQ0FBQyxVQUFVLEVBQUUsQ0FBQzZDLE9BQU8sQ0FBQyxDQUNqQzVDLElBQUksQ0FBQyxlQUFlLEVBQUU0QyxPQUFPLEdBQUcsT0FBTyxHQUFHLE1BQU0sQ0FBQztJQUMxRDtJQUVBcEUsYUFBYSxDQUFDNUssSUFBSSxDQUFDLG1DQUFtQyxDQUFDLENBQUMyQixFQUFFLENBQUMsUUFBUSxFQUFFdVIsd0JBQXdCLENBQUM7SUFDOUZBLHdCQUF3QixDQUFDLENBQUM7SUFDMUJ4VCxDQUFDLENBQUMsK0NBQStDLENBQUMsQ0FBQ2lDLEVBQUUsQ0FBQyxPQUFPLEVBQUUsWUFBWTtNQUN2RSxJQUFJcUUsSUFBSSxHQUFHOUQsTUFBTSxDQUFDeEMsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDME0sSUFBSSxDQUFDLHFCQUFxQixDQUFDLElBQUksRUFBRSxDQUFDO01BQzVELElBQUk3QixPQUFPLEdBQUc3SyxDQUFDLENBQUMsSUFBSSxDQUFDO01BQ3JCLFNBQVMwVCxNQUFNQSxDQUFBLEVBQUc7UUFDZDdJLE9BQU8sQ0FBQ3RFLFFBQVEsQ0FBQyxVQUFVLENBQUM7UUFDNUJuRSxVQUFVLENBQUMsWUFBWTtVQUNuQnlJLE9BQU8sQ0FBQ3hFLFdBQVcsQ0FBQyxVQUFVLENBQUM7UUFDbkMsQ0FBQyxFQUFFLEdBQUcsQ0FBQztNQUNYO01BRUEsSUFBSXNOLFNBQVMsQ0FBQ0MsU0FBUyxJQUFJRCxTQUFTLENBQUNDLFNBQVMsQ0FBQ0MsU0FBUyxFQUFFO1FBQ3RERixTQUFTLENBQUNDLFNBQVMsQ0FBQ0MsU0FBUyxDQUFDdk4sSUFBSSxDQUFDLENBQUN3TixJQUFJLENBQUNKLE1BQU0sQ0FBQztRQUNoRDtNQUNKO01BQ0E7TUFDQSxJQUFJSyxPQUFPLEdBQUcvVCxDQUFDLENBQUMsZ0NBQWdDLENBQUMsQ0FBQ1ksR0FBRyxDQUFDMEYsSUFBSSxDQUFDLENBQUMwTixHQUFHLENBQUM7UUFBRUMsUUFBUSxFQUFFLE9BQU87UUFBRUMsR0FBRyxFQUFFLENBQUM7UUFBRXRMLElBQUksRUFBRTtNQUFVLENBQUMsQ0FBQyxDQUFDOEcsUUFBUSxDQUFDLE1BQU0sQ0FBQztNQUNoSXFFLE9BQU8sQ0FBQyxDQUFDLENBQUMsQ0FBQ0ksTUFBTSxDQUFDLENBQUM7TUFDbkIsSUFBSTtRQUNBLElBQUloRSxRQUFRLENBQUNpRSxXQUFXLENBQUMsTUFBTSxDQUFDLEVBQUU7VUFDOUJWLE1BQU0sQ0FBQyxDQUFDO1FBQ1o7TUFDSixDQUFDLENBQUMsT0FBT3BQLEtBQUssRUFBRTtRQUNaO01BQUE7TUFFSnlQLE9BQU8sQ0FBQ00sTUFBTSxDQUFDLENBQUM7SUFDcEIsQ0FBQyxDQUFDO0lBRUZyVSxDQUFDLENBQUMsMEVBQTBFLENBQUMsQ0FBQ2lDLEVBQUUsQ0FBQyxPQUFPLEVBQUUsWUFBWTtNQUNsRyxJQUFJMEwsUUFBUSxHQUFHbkwsTUFBTSxDQUFDeEMsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDME0sSUFBSSxDQUFDLGVBQWUsQ0FBQyxDQUFDO01BQ3BELElBQUk0SCxTQUFTLEdBQUd0VSxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUMrSyxPQUFPLENBQUMsc0JBQXNCLENBQUM7TUFFdkQvSyxDQUFDLENBQUMsSUFBSSxDQUFDLENBQUN1RyxRQUFRLENBQUMsUUFBUSxDQUFDLENBQUNtRyxJQUFJLENBQUMsY0FBYyxFQUFFLE1BQU0sQ0FBQyxDQUNsRDBCLFFBQVEsQ0FBQyxDQUFDLENBQUMvSCxXQUFXLENBQUMsUUFBUSxDQUFDLENBQUNxRyxJQUFJLENBQUMsY0FBYyxFQUFFLE9BQU8sQ0FBQztNQUNuRTRILFNBQVMsQ0FBQ2hVLElBQUksQ0FBQyw0QkFBNEIsQ0FBQyxDQUFDNk0sSUFBSSxDQUFDLFlBQVk7UUFDMURuTixDQUFDLENBQUMsSUFBSSxDQUFDLENBQUNvRyxJQUFJLENBQUMsUUFBUSxFQUFFNUQsTUFBTSxDQUFDeEMsQ0FBQyxDQUFDLElBQUksQ0FBQyxDQUFDME0sSUFBSSxDQUFDLGVBQWUsQ0FBQyxDQUFDLEtBQUtpQixRQUFRLENBQUM7TUFDOUUsQ0FBQyxDQUFDO01BQ0YyRyxTQUFTLENBQUN2SixPQUFPLENBQUMsd0JBQXdCLENBQUMsQ0FBQzJCLElBQUksQ0FBQyxzQkFBc0IsRUFBRWlCLFFBQVEsQ0FBQyxDQUM3RXJOLElBQUksQ0FBQywrQkFBK0IsQ0FBQyxDQUFDTSxHQUFHLENBQUMrTSxRQUFRLENBQUM7SUFDNUQsQ0FBQyxDQUFDO0lBRUYsSUFBSSxPQUFPekIsSUFBSSxLQUFLLFdBQVcsSUFBSWhCLGFBQWEsQ0FBQ2hMLE1BQU0sR0FBRyxDQUFDLEVBQUU7TUFDekRnTSxJQUFJLENBQUNxSSxRQUFRLEdBQUdySixhQUFhO01BQzdCZ0IsSUFBSSxDQUFDaEMsR0FBRyxHQUFHZ0IsYUFBYSxDQUFDd0IsSUFBSSxDQUFDLFFBQVEsQ0FBQztNQUN2Q1IsSUFBSSxDQUFDL0IsTUFBTSxHQUFHLE1BQU07TUFDcEIrQixJQUFJLENBQUNzSSxhQUFhLEdBQUdwTSxNQUFNLENBQUNxTSxNQUFNLENBQUM7UUFDL0JDLGdCQUFnQixFQUFFO1VBQ2Q1QixVQUFVLEVBQUUsa0JBQWtCO1VBQzlCYixLQUFLLEVBQUUsQ0FBQztZQUFFekMsSUFBSSxFQUFFO1VBQVEsQ0FBQztRQUM3QixDQUFDO1FBQ0RtRixnQkFBZ0IsRUFBRTtVQUNkN0IsVUFBVSxFQUFFLGtCQUFrQjtVQUM5QmIsS0FBSyxFQUFFLENBQUM7WUFBRXpDLElBQUksRUFBRTtVQUFrQixDQUFDO1FBQ3ZDLENBQUM7UUFDRG9GLHlCQUF5QixFQUFFO1VBQ3ZCOUIsVUFBVSxFQUFFLDJCQUEyQjtVQUN2Q2IsS0FBSyxFQUFFLENBQ0g7WUFBRXpDLElBQUksRUFBRTtVQUFrQixDQUFDLEVBQzNCO1lBQ0lBLElBQUksRUFBRSwyQkFBMkI7WUFDakNxRixNQUFNLEVBQUVuWCxXQUFXLENBQ2YseURBQXlELEVBQ3pELHlIQUNKO1VBQ0osQ0FBQztRQUVUO01BQ0osQ0FBQyxFQUFFa1YsMEJBQTBCLENBQUM7TUFDOUIxRyxJQUFJLENBQUM0SSxnQkFBZ0IsR0FBRyxVQUFVOVAsUUFBUSxFQUFFO1FBQ3hDLE9BQU9BLFFBQVE7TUFDbkIsQ0FBQztNQUNEa0gsSUFBSSxDQUFDNkksZUFBZSxHQUFHLFlBQVksQ0FBQyxDQUFDO01BQ3JDN0ksSUFBSSxDQUFDOEksV0FBVyxDQUFDMUYsT0FBTyxHQUFHLEtBQUs7TUFDaENwRCxJQUFJLENBQUM4SSxXQUFXLENBQUNDLFNBQVMsR0FBRyxJQUFJO01BQ2pDL0ksSUFBSSxDQUFDZ0osZUFBZSxHQUFHLEtBQUs7TUFDNUJoSixJQUFJLENBQUNpSixhQUFhLEdBQUcsSUFBSTtNQUN6QmpKLElBQUksQ0FBQ2tKLFVBQVUsQ0FBQyxDQUFDO0lBQ3JCLENBQUMsTUFBTTtNQUNIbEssYUFBYSxDQUFDcUUsSUFBSSxDQUFDO1FBQ2Y4RixNQUFNLEVBQUVqTixNQUFNLENBQUNxTSxNQUFNLENBQUM7VUFDbEJDLGdCQUFnQixFQUFFLENBQUMsT0FBTyxDQUFDO1VBQzNCQyxnQkFBZ0IsRUFBRSxDQUFDLGlCQUFpQixDQUFDO1VBQ3JDQyx5QkFBeUIsRUFBRSxDQUN2QixpQkFBaUIsRUFDakI7WUFDSXBGLElBQUksRUFBRSwyQkFBMkI7WUFDakNxRixNQUFNLEVBQUVuWCxXQUFXLENBQ2YseURBQXlELEVBQ3pELHlIQUNKO1VBQ0osQ0FBQztRQUVULENBQUMsRUFBRWtWLDBCQUEwQjtNQUNqQyxDQUFDLENBQUM7SUFDTjtFQUVKLENBQUMsQ0FBQztBQUNOLENBQUMsRUFBQyxDQUFDIiwiaWdub3JlTGlzdCI6W119