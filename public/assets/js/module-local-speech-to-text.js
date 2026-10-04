function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
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
    var $modelInputs = $('.module-local-speech-to-text .stt-model-marketplace-table input[name="selected_model"]');
    var $compatibilityMessage = $('#stt-model-language-compatibility');
    var initialSelectedModel = String($modelForm.data('initial-selected-model') || $modelInputs.filter(':checked').val() || '');
    var selectedLanguage = String($modelForm.data('selected-language') || '').trim().toLowerCase();
    var $platformButtons = $('.module-local-speech-to-text .stt-model-platform-switch [data-platform]');
    var $platformChangeMessage = $('#stt-model-platform-change');
    var initialPlatform = String($modelForm.data('selected-platform') || 'macos');
    function selectedModel() {
      return String($modelInputs.filter(':checked').val() || '');
    }
    function selectedModelPlatform() {
      return String($modelInputs.filter(':checked').attr('data-platform') || '');
    }
    function updatePlatformChangeState() {
      var platform = selectedModelPlatform();
      var changesPlatform = platform !== '' && platform !== initialPlatform;
      $platformChangeMessage.toggleClass('hidden', !changesPlatform).attr('aria-hidden', changesPlatform ? 'false' : 'true');
    }
    function showPlatform(platform) {
      var $rows = $('.module-local-speech-to-text .stt-model-marketplace-table tbody tr');
      $platformButtons.each(function () {
        var active = String($(this).attr('data-platform')) === platform;
        $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
      });
      $rows.each(function () {
        $(this).prop('hidden', String($(this).attr('data-platform')) !== platform);
      });
      if (selectedModelPlatform() !== platform) {
        $modelInputs.prop('checked', false);
        $rows.removeClass('positive');
        if (platform === initialPlatform) {
          $modelInputs.filter(function () {
            return String($(this).val()) === initialSelectedModel;
          }).closest('.ui.radio.checkbox').checkbox('check');
        }
      }
    }
    function selectedModelSupportsLanguage() {
      var $selectedInput = $modelInputs.filter(':checked');
      var supportedLanguages;
      if (selectedLanguage === '' || selectedLanguage === 'auto') {
        return true;
      }
      if ($selectedInput.length === 0) {
        return false;
      }
      supportedLanguages = String($selectedInput.attr('data-supported-languages') || '').split(',').map(function (language) {
        return language.trim().toLowerCase();
      }).filter(function (language) {
        return language !== '';
      });
      return supportedLanguages.indexOf(selectedLanguage) !== -1;
    }
    function updateCompatibilityState() {
      var compatible = selectedModelSupportsLanguage();
      // After a platform switch nothing is selected yet; there is no model to warn about.
      var warn = !compatible && selectedModel() !== '';
      $compatibilityMessage.toggleClass('hidden', !warn).attr('aria-hidden', warn ? 'false' : 'true');
      return compatible;
    }
    function updateSaveButtonState() {
      var compatible = updateCompatibilityState();
      updatePlatformChangeState();
      var hasChanges = selectedModel() !== '' && selectedModel() !== initialSelectedModel && compatible;
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
      updateSaveButtonState();
    });
    $modelInputs.on('change', function () {
      var $row = $(this).closest('tr');
      $row.addClass('positive').siblings().removeClass('positive');
      updateSaveButtonState();
    });
    $modelForm.on('submit', function () {
      if (!selectedModelSupportsLanguage() || selectedModel() === '' || selectedModel() === initialSelectedModel) {
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
      var text = $(this).data('clipboard-text') || '';
      var $button = $(this);
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          $button.addClass('positive');
          setTimeout(function () {
            $button.removeClass('positive');
          }, 900);
        });
      }
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
    $modelForm.form({
      fields: {
        selected_model: ['empty']
      }
    });
  });
})();

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIiwibmFtZXMiOlsidHJhbnNsYXRpb24iLCJrZXkiLCJmYWxsYmFjayIsImdsb2JhbFRyYW5zbGF0ZSIsInJlcXVlc3RlZFRhYiIsInBhcmFtcyIsIlVSTFNlYXJjaFBhcmFtcyIsIndpbmRvdyIsImxvY2F0aW9uIiwic2VhcmNoIiwidGFiTmFtZSIsImdldCIsImhhc2giLCJyZXBsYWNlIiwidGVzdCIsImNhbGVuZGFyTG9jYWxlIiwiZGF5cyIsIm1vbnRocyIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiY2FsZW5kYXJUZXh0IiwiZm9ybWF0Iiwic2VwYXJhdG9yIiwiYXBwbHlMYWJlbCIsImNhbmNlbExhYmVsIiwiZnJvbUxhYmVsIiwidG9MYWJlbCIsImN1c3RvbVJhbmdlTGFiZWwiLCJkYXlzT2ZXZWVrIiwibW9udGhOYW1lcyIsImZpcnN0RGF5IiwibW9tZW50RGF0ZSIsInZhbHVlIiwiZGF0ZSIsIm1vbWVudCIsImlzVmFsaWQiLCJjbG9uZSIsImluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlciIsIiRmb3JtIiwiJCIsIiRzZWxlY3RvciIsImxlbmd0aCIsImZuIiwiZGF0ZXJhbmdlcGlja2VyIiwiJGRhdGVGcm9tIiwiZmluZCIsIiRkYXRlVG8iLCIkc2VhcmNoIiwic2VhcmNoRGVib3VuY2VUaW1lciIsInRvZGF5Iiwic3RhcnREYXRlIiwidmFsIiwiZW5kRGF0ZSIsImlzQWZ0ZXIiLCJzd2FwRGF0ZSIsInVwZGF0ZUhpZGRlbklucHV0cyIsInN0YXJ0IiwiZW5kIiwiY2FuY2VsU2VhcmNoRGVib3VuY2UiLCJjbGVhclRpbWVvdXQiLCJzdWJtaXRUcmFuc2NyaXB0U2VhcmNoIiwidHJpZ2dlciIsInJhbmdlcyIsIl9kZWZpbmVQcm9wZXJ0eSIsInN1YnRyYWN0Iiwic3RhcnRPZiIsImVuZE9mIiwiYWx3YXlzU2hvd0NhbGVuZGFycyIsImF1dG9VcGRhdGVJbnB1dCIsImxpbmtlZENhbGVuZGFycyIsIm1heERhdGUiLCJsb2NhbGUiLCJvbiIsInBpY2tlciIsImRhdGEiLCJzZXRUaW1lb3V0IiwiZXZlbnQiLCJ3aGljaCIsInByZXZlbnREZWZhdWx0IiwiU3RyaW5nIiwidHJpbSIsImluaXRpYWxpemVNb2R1bGVMb2dzIiwiJHNlZ21lbnQiLCIkcmVzdWx0cyIsIiRzdGF0ZSIsIiRyZXN1bHRDb3VudCIsIiR1cGRhdGVkQXQiLCIkbGV2ZWxGaWx0ZXIiLCIkY29tcG9uZW50RmlsdGVyIiwiZGF0YVVybCIsIiRwZXJpb2RCdXR0b25zIiwiJHJlZnJlc2hCdXR0b24iLCJjdXJyZW50UGVyaW9kIiwicGFyc2VJbnQiLCJmaWx0ZXIiLCJsb2FkZWRPbmNlIiwicmVxdWVzdCIsInJlcXVlc3RHZW5lcmF0aW9uIiwiY3VycmVudEVudHJpZXMiLCJjdXJyZW50UmVzcG9uc2UiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsImxldmVsTmFtZSIsImxldmVsIiwibGV2ZWxzIiwiZGVidWciLCJpbmZvIiwibm90aWNlIiwid2FybmluZyIsImVycm9yIiwiaXRlbSIsImNvbXBvbmVudE5hbWUiLCJjb21wb25lbnQiLCJhbGlhc2VzIiwiYXBpIiwicXVldWUiLCJzY2FubmVyIiwid29ya2VyIiwid29ya2VybG9jYWxzcGVlY2h0b3RleHRqb2JzIiwic2V0dGluZ3MiLCJ3ZWJfdWkiLCJzZXR1cCIsInN0b3JhZ2UiLCJ0cmFuc2NyaXB0Iiwic3lzdGVtIiwibG9jYWxzcGVlY2h0b3RleHRtYWluIiwibG9nZ2VyIiwibGFiZWxzIiwib3RoZXIiLCJsYWJlbCIsImZvcm1hdFRpbWVzdGFtcCIsInRpbWVzdGFtcCIsIkRhdGUiLCJpc05hTiIsImdldFRpbWUiLCJ0b0xvY2FsZVN0cmluZyIsInJlbmRlclN0YXRlIiwia2luZCIsIm1lc3NhZ2UiLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJ0ZXh0IiwiYWRkQ2xhc3MiLCJmaWx0ZXJFbnRyaWVzIiwiZW50cmllcyIsInF1ZXJ5IiwidG9Mb3dlckNhc2UiLCJlbnRyeSIsImhheXN0YWNrIiwic2V2ZXJpdHkiLCJwaWQiLCJKU09OIiwic3RyaW5naWZ5IiwiY29udGV4dCIsImpvaW4iLCJpbmRleE9mIiwicmVuZGVyRW50cmllcyIsImVtcHR5IiwiZm9yRWFjaCIsIiRjYXJkIiwiJGhlYWRlciIsIiRpZGVudGl0eSIsIiRsZXZlbCIsIiRjb21wb25lbnQiLCIkdGltZSIsInRzIiwiJG1lc3NhZ2UiLCIkZGV0YWlscyIsIiRzdW1tYXJ5IiwiZGV0YWlsVGV4dCIsInVuZGVmaW5lZCIsIk9iamVjdCIsImtleXMiLCJhcHBlbmQiLCJ1cGRhdGVDb21wb25lbnRPcHRpb25zIiwic2VsZWN0ZWQiLCJjb21wb25lbnRzIiwicHVzaCIsInNvcnQiLCJsZWZ0IiwicmlnaHQiLCJsb2NhbGVDb21wYXJlIiwidXBkYXRlTWV0YSIsImNvdW50IiwicmVzcG9uc2UiLCJmb3VuZCIsInVwZGF0ZWQiLCJnZW5lcmF0ZWRBdCIsInJlbmRlckN1cnJlbnQiLCJmaWx0ZXJlZCIsInNvdXJjZVN0YXR1cyIsIm5vdGljZXMiLCJtYWxmb3JtZWRDb3VudCIsInRydW5jYXRlZCIsInNldExvYWRpbmciLCJsb2FkTG9ncyIsImZvcmNlIiwicmVxdWVzdElkIiwiYWJvcnQiLCJjdXJyZW50UmVxdWVzdCIsImFqYXgiLCJ1cmwiLCJtZXRob2QiLCJkYXRhVHlwZSIsInBlcmlvZCIsImRvbmUiLCJBcnJheSIsImlzQXJyYXkiLCJmYWlsIiwieGhyIiwic3RhdHVzIiwiYWx3YXlzIiwiJGJ1dHRvbiIsImFkZCIsImNsb3Nlc3QiLCJoYXNDbGFzcyIsImluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyIiwiJHNldHRpbmdzRm9ybSIsIiRzbGlkZXIiLCIkaW5wdXQiLCJzbGlkZXIiLCJ2YWx1ZXMiLCJjdXJyZW50VmFsdWUiLCJpbml0aWFsSW5kZXgiLCJpbml0aWFsaXplZCIsIm1pbiIsIm1heCIsInN0ZXAiLCJzbW9vdGgiLCJhdXRvQWRqdXN0TGFiZWxzIiwiaW50ZXJwcmV0TGFiZWwiLCJvbkNoYW5nZSIsInVwZGF0ZVJldGVudGlvbkNvbmZsaWN0IiwiRm9ybSIsImNoZWNrVmFsdWVzIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIiLCJyZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nIiwicHJvY2Vzc2luZ0RheXMiLCJyZXRlbnRpb25EYXlzIiwiY29tcGF0aWJsZSIsInRvZ2dsZUNsYXNzIiwiYXR0ciIsImluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlIiwiJG1vZGVsRm9ybSIsIiRzYXZlQnV0dG9uIiwiJG1vZGVsSW5wdXRzIiwiJGNvbXBhdGliaWxpdHlNZXNzYWdlIiwiaW5pdGlhbFNlbGVjdGVkTW9kZWwiLCJzZWxlY3RlZExhbmd1YWdlIiwiJHBsYXRmb3JtQnV0dG9ucyIsIiRwbGF0Zm9ybUNoYW5nZU1lc3NhZ2UiLCJpbml0aWFsUGxhdGZvcm0iLCJzZWxlY3RlZE1vZGVsIiwic2VsZWN0ZWRNb2RlbFBsYXRmb3JtIiwidXBkYXRlUGxhdGZvcm1DaGFuZ2VTdGF0ZSIsInBsYXRmb3JtIiwiY2hhbmdlc1BsYXRmb3JtIiwic2hvd1BsYXRmb3JtIiwiJHJvd3MiLCJlYWNoIiwiYWN0aXZlIiwiY2hlY2tib3giLCJzZWxlY3RlZE1vZGVsU3VwcG9ydHNMYW5ndWFnZSIsIiRzZWxlY3RlZElucHV0Iiwic3VwcG9ydGVkTGFuZ3VhZ2VzIiwic3BsaXQiLCJtYXAiLCJsYW5ndWFnZSIsInVwZGF0ZUNvbXBhdGliaWxpdHlTdGF0ZSIsIndhcm4iLCJ1cGRhdGVTYXZlQnV0dG9uU3RhdGUiLCJoYXNDaGFuZ2VzIiwiYWNjb3JkaW9uIiwidGFyZ2V0IiwiJHJvdyIsInNpYmxpbmdzIiwiaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCIsIiRtb2RhbCIsIm1vZGFsIiwic2hvd0NvbmZpcm0iLCJvbkNvbmZpcm0iLCJjbG9zYWJsZSIsIm9uQXBwcm92ZSIsImJpbmRDb25maXJtYWJsZUZvcm0iLCJzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24iLCIkc2VsZiIsIm9mZiIsImluaXRpYWxpemVUcmFuc2NyaXB0RGVsZXRpb24iLCIkZm9ybXMiLCIkYWNrbm93bGVkZ2UiLCIkYXBwcm92ZSIsInBlbmRpbmdGb3JtIiwic2V0QXBwcm92ZUVuYWJsZWQiLCJlbmFibGVkIiwiZm9ybSIsInR5cGUiLCJuYW1lIiwiYXBwZW5kVG8iLCJzdWJtaXQiLCJvbkhpZGRlbiIsImluaXRpYWxpemVUcmFuc2NyaXB0Um93cyIsImRlc3RpbmF0aW9uIiwiaHJlZiIsImluaXRpYWxpemVUcmFuc2NyaXB0QWN0aW9uTWVudSIsIiRtZW51cyIsInJlbW92ZUF0dHIiLCJkb2N1bWVudCIsImNvbnRhaW5zIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRMZWdUYWJzIiwidHJhbnNjcmlwdExvY2F0aW9uIiwiU3R0VHJhbnNjcmlwdFZpZXciLCIkcmV2aWV3IiwiJHRhYnMiLCIkcGFuZWxzIiwicGFuZWxMZWciLCIkZWxlbWVudCIsInNob3ciLCJjYWxsTGVnIiwiaGlkZGVuIiwicGF1c2UiLCJhdWRpbyIsInJlYWQiLCIkc3RhZ2UiLCJzdGFnZSIsImNoYW5nZXMiLCJsZWciLCJ3cml0ZSIsImluaXRpYWwiLCIkdGFyZ2V0IiwiJHRhYk1lbnUiLCIkdGFiSXRlbXMiLCJ0YWIiLCJvblZpc2libGUiLCJub3QiLCJkcm9wZG93biIsInBvcHVwIiwicnVsZXMiLCJzdHRQcm9jZXNzaW5nUmFuZ2UiLCJyYW5nZSIsImxpbWl0cyIsIm51bWJlciIsInN0dFByb2Nlc3NpbmdPdmVybGFwIiwib3ZlcmxhcE1TIiwiTWF0aCIsInJvdW5kIiwiZHVyYXRpb25NUyIsInN0dFJldGVudGlvbkNvbXBhdGliaWxpdHkiLCJwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcyIsInRlbXBlcmF0dXJlIiwiaWRlbnRpZmllciIsInRlbXBlcmF0dXJlX2ZhbGxiYWNrX3N0ZXAiLCJ0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudCIsInRvcF9rIiwiY29tcHJlc3Npb25fcmF0aW9fdGhyZXNob2xkIiwibG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZCIsImZpcnN0X3Rva2VuX2xvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQiLCJub19zcGVlY2hfdGhyZXNob2xkIiwibWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjIiwic2VnbWVudF9vdmVybGFwX3NlYyIsInVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcyIsImlzIiwibmF2aWdhdG9yIiwiY2xpcGJvYXJkIiwid3JpdGVUZXh0IiwidGhlbiIsIiRmb3JtT2JqIiwidmFsaWRhdGVSdWxlcyIsImFzc2lnbiIsImRlZmF1bHRfbGFuZ3VhZ2UiLCJjZHJfaGlzdG9yeV9kYXlzIiwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cyIsInByb21wdCIsImNiQmVmb3JlU2VuZEZvcm0iLCJjYkFmdGVyU2VuZEZvcm0iLCJhcGlTZXR0aW5ncyIsImFwaU9iamVjdCIsInNlbmRPbmx5Q2hhbmdlZCIsImVuYWJsZURpcnJpdHkiLCJpbml0aWFsaXplIiwiZmllbGRzIiwic2VsZWN0ZWRfbW9kZWwiXSwic291cmNlcyI6WyJzcmMvbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIl0sInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAoKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgZnVuY3Rpb24gdHJhbnNsYXRpb24oa2V5LCBmYWxsYmFjaykge1xuICAgICAgICBpZiAodHlwZW9mIGdsb2JhbFRyYW5zbGF0ZSAhPT0gJ3VuZGVmaW5lZCcgJiYgZ2xvYmFsVHJhbnNsYXRlW2tleV0pIHtcbiAgICAgICAgICAgIHJldHVybiBnbG9iYWxUcmFuc2xhdGVba2V5XTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiBmYWxsYmFjaztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiByZXF1ZXN0ZWRUYWIoKSB7XG4gICAgICAgIHZhciBwYXJhbXMgPSBuZXcgVVJMU2VhcmNoUGFyYW1zKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICB2YXIgdGFiTmFtZSA9IHBhcmFtcy5nZXQoJ3RhYicpIHx8IHdpbmRvdy5sb2NhdGlvbi5oYXNoLnJlcGxhY2UoL14jLywgJycpO1xuXG4gICAgICAgIHJldHVybiAvXlthLXowLTlfLV0rJC8udGVzdCh0YWJOYW1lKSA/IHRhYk5hbWUgOiAnJztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjYWxlbmRhckxvY2FsZSgpIHtcbiAgICAgICAgdmFyIGRheXMgPSBbJ1N1JywgJ01vJywgJ1R1JywgJ1dlJywgJ1RoJywgJ0ZyJywgJ1NhJ107XG4gICAgICAgIHZhciBtb250aHMgPSBbXG4gICAgICAgICAgICAnSmFudWFyeScsXG4gICAgICAgICAgICAnRmVicnVhcnknLFxuICAgICAgICAgICAgJ01hcmNoJyxcbiAgICAgICAgICAgICdBcHJpbCcsXG4gICAgICAgICAgICAnTWF5JyxcbiAgICAgICAgICAgICdKdW5lJyxcbiAgICAgICAgICAgICdKdWx5JyxcbiAgICAgICAgICAgICdBdWd1c3QnLFxuICAgICAgICAgICAgJ1NlcHRlbWJlcicsXG4gICAgICAgICAgICAnT2N0b2JlcicsXG4gICAgICAgICAgICAnTm92ZW1iZXInLFxuICAgICAgICAgICAgJ0RlY2VtYmVyJ1xuICAgICAgICBdO1xuXG4gICAgICAgIGlmICh0eXBlb2YgU2VtYW50aWNMb2NhbGl6YXRpb24gIT09ICd1bmRlZmluZWQnICYmIFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dCkge1xuICAgICAgICAgICAgZGF5cyA9IFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dC5kYXlzIHx8IGRheXM7XG4gICAgICAgICAgICBtb250aHMgPSBTZW1hbnRpY0xvY2FsaXphdGlvbi5jYWxlbmRhclRleHQubW9udGhzIHx8IG1vbnRocztcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiB7XG4gICAgICAgICAgICBmb3JtYXQ6ICdERC9NTS9ZWVlZJyxcbiAgICAgICAgICAgIHNlcGFyYXRvcjogJyAtICcsXG4gICAgICAgICAgICBhcHBseUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0FwcGx5QnRuJywgJ0FwcGx5JyksXG4gICAgICAgICAgICBjYW5jZWxMYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9DYW5jZWxCdG4nLCAnQ2FuY2VsJyksXG4gICAgICAgICAgICBmcm9tTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfZnJvbScsICdGcm9tJyksXG4gICAgICAgICAgICB0b0xhYmVsOiB0cmFuc2xhdGlvbignY2FsX3RvJywgJ1RvJyksXG4gICAgICAgICAgICBjdXN0b21SYW5nZUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0N1c3RvbVBlcmlvZCcsICdDdXN0b20gcmFuZ2UnKSxcbiAgICAgICAgICAgIGRheXNPZldlZWs6IGRheXMsXG4gICAgICAgICAgICBtb250aE5hbWVzOiBtb250aHMsXG4gICAgICAgICAgICBmaXJzdERheTogMVxuICAgICAgICB9O1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIG1vbWVudERhdGUodmFsdWUsIGZhbGxiYWNrKSB7XG4gICAgICAgIHZhciBkYXRlID0gbW9tZW50KHZhbHVlLCAnWVlZWS1NTS1ERCcsIHRydWUpO1xuXG4gICAgICAgIHJldHVybiBkYXRlLmlzVmFsaWQoKSA/IGRhdGUgOiBmYWxsYmFjay5jbG9uZSgpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlcigpIHtcbiAgICAgICAgdmFyICRmb3JtID0gJCgnI3N0dC10cmFuc2NyaXB0cy1maWx0ZXItZm9ybScpO1xuICAgICAgICB2YXIgJHNlbGVjdG9yID0gJCgnI3N0dC10cmFuc2NyaXB0cy1kYXRlLXJhbmdlLXNlbGVjdG9yJyk7XG5cbiAgICAgICAgaWYgKCRmb3JtLmxlbmd0aCA9PT0gMCB8fCAkc2VsZWN0b3IubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHR5cGVvZiBtb21lbnQgPT09ICd1bmRlZmluZWQnIHx8IHR5cGVvZiAkLmZuLmRhdGVyYW5nZXBpY2tlciA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciAkZGF0ZUZyb20gPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiZGF0ZV9mcm9tXCJdJyk7XG4gICAgICAgIHZhciAkZGF0ZVRvID0gJGZvcm0uZmluZCgnaW5wdXRbbmFtZT1cImRhdGVfdG9cIl0nKTtcbiAgICAgICAgdmFyICRzZWFyY2ggPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwic2VhcmNoXCJdJyk7XG4gICAgICAgIHZhciBzZWFyY2hEZWJvdW5jZVRpbWVyID0gbnVsbDtcbiAgICAgICAgdmFyIHRvZGF5ID0gbW9tZW50KCk7XG4gICAgICAgIHZhciBzdGFydERhdGUgPSBtb21lbnREYXRlKCRkYXRlRnJvbS52YWwoKSwgdG9kYXkpO1xuICAgICAgICB2YXIgZW5kRGF0ZSA9IG1vbWVudERhdGUoJGRhdGVUby52YWwoKSwgc3RhcnREYXRlKTtcblxuICAgICAgICBpZiAoc3RhcnREYXRlLmlzQWZ0ZXIoZW5kRGF0ZSkpIHtcbiAgICAgICAgICAgIHZhciBzd2FwRGF0ZSA9IHN0YXJ0RGF0ZTtcbiAgICAgICAgICAgIHN0YXJ0RGF0ZSA9IGVuZERhdGU7XG4gICAgICAgICAgICBlbmREYXRlID0gc3dhcERhdGU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCkge1xuICAgICAgICAgICAgJGRhdGVGcm9tLnZhbChzdGFydC5mb3JtYXQoJ1lZWVktTU0tREQnKSk7XG4gICAgICAgICAgICAkZGF0ZVRvLnZhbChlbmQuZm9ybWF0KCdZWVlZLU1NLUREJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY2FuY2VsU2VhcmNoRGVib3VuY2UoKSB7XG4gICAgICAgICAgICBpZiAoc2VhcmNoRGVib3VuY2VUaW1lciAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5jbGVhclRpbWVvdXQoc2VhcmNoRGVib3VuY2VUaW1lcik7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzdWJtaXRUcmFuc2NyaXB0U2VhcmNoKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgICRmb3JtLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHN0YXJ0RGF0ZSwgZW5kRGF0ZSk7XG4gICAgICAgICRzZWxlY3Rvci5kYXRlcmFuZ2VwaWNrZXIoe1xuICAgICAgICAgICAgcmFuZ2VzOiB7XG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVG9kYXknLCAnVG9kYXknKV06IFttb21lbnQoKSwgbW9tZW50KCldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX1llc3RlcmRheScsICdZZXN0ZXJkYXknKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0V2VlaycsICdMYXN0IDcgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDYsICdkYXlzJyksIG1vbWVudCgpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0MzBEYXlzJywgJ0xhc3QgMzAgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDI5LCAnZGF5cycpLCBtb21lbnQoKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVGhpc01vbnRoJywgJ1RoaXMgbW9udGgnKV06IFttb21lbnQoKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5lbmRPZignbW9udGgnKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfTGFzdE1vbnRoJywgJ0xhc3QgbW9udGgnKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5lbmRPZignbW9udGgnKV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBhbHdheXNTaG93Q2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgYXV0b1VwZGF0ZUlucHV0OiB0cnVlLFxuICAgICAgICAgICAgbGlua2VkQ2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgbWF4RGF0ZTogbW9tZW50KCksXG4gICAgICAgICAgICBzdGFydERhdGU6IHN0YXJ0RGF0ZS5zdGFydE9mKCdkYXknKSxcbiAgICAgICAgICAgIGVuZERhdGU6IGVuZERhdGUuZW5kT2YoJ2RheScpLFxuICAgICAgICAgICAgbG9jYWxlOiBjYWxlbmRhckxvY2FsZSgpXG4gICAgICAgIH0sIGZ1bmN0aW9uIChzdGFydCwgZW5kKSB7XG4gICAgICAgICAgICB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCk7XG4gICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGNhbmNlbFNlYXJjaERlYm91bmNlKCk7XG4gICAgICAgICAgICB2YXIgcGlja2VyID0gJHNlbGVjdG9yLmRhdGEoJ2RhdGVyYW5nZXBpY2tlcicpO1xuICAgICAgICAgICAgaWYgKHBpY2tlciAmJiBwaWNrZXIuc3RhcnREYXRlICYmIHBpY2tlci5lbmREYXRlKSB7XG4gICAgICAgICAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHBpY2tlci5zdGFydERhdGUsIHBpY2tlci5lbmREYXRlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ2lucHV0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgIHNlYXJjaERlYm91bmNlVGltZXIgPSB3aW5kb3cuc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICAgICAgJGZvcm0udHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgICAgICB9LCA0MDApO1xuICAgICAgICB9KTtcbiAgICAgICAgJHNlYXJjaC5vbigna2V5ZG93bicsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKGV2ZW50LndoaWNoICE9PSAxMykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ3NlYXJjaCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGlmIChTdHJpbmcoJHNlYXJjaC52YWwoKSB8fCAnJykudHJpbSgpID09PSAnJykge1xuICAgICAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZU1vZHVsZUxvZ3MoKSB7XG4gICAgICAgIHZhciAkZm9ybSA9ICQoJyNzdHQtbW9kdWxlLWxvZ3MtZm9ybScpO1xuICAgICAgICB2YXIgJHNlZ21lbnQgPSAkKCcjc3lzdGVtLWxvZ3Mtc2VnbWVudCcpO1xuICAgICAgICB2YXIgJHJlc3VsdHMgPSAkKCcjc3R0LWxvZy1yZXN1bHRzJyk7XG4gICAgICAgIHZhciAkc3RhdGUgPSAkKCcjc3R0LWxvZy1zdGF0ZScpO1xuICAgICAgICB2YXIgJHJlc3VsdENvdW50ID0gJCgnI3N0dC1sb2ctcmVzdWx0LWNvdW50Jyk7XG4gICAgICAgIHZhciAkdXBkYXRlZEF0ID0gJCgnI3N0dC1sb2ctdXBkYXRlZC1hdCcpO1xuICAgICAgICB2YXIgJGxldmVsRmlsdGVyID0gJCgnI3N0dC1sb2ctbGV2ZWwtZmlsdGVyJyk7XG4gICAgICAgIHZhciAkY29tcG9uZW50RmlsdGVyID0gJCgnI3N0dC1sb2ctY29tcG9uZW50LWZpbHRlcicpO1xuICAgICAgICB2YXIgJHNlYXJjaCA9ICQoJyNzdHQtbG9nLXNlYXJjaCcpO1xuXG4gICAgICAgIGlmICgkZm9ybS5sZW5ndGggPT09IDAgfHwgJHJlc3VsdHMubGVuZ3RoID09PSAwIHx8ICRzdGF0ZS5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciBkYXRhVXJsID0gJGZvcm0uZGF0YSgndXJsJyk7XG4gICAgICAgIHZhciAkcGVyaW9kQnV0dG9ucyA9ICRmb3JtLmZpbmQoJy5wZXJpb2QtYnRuJyk7XG4gICAgICAgIHZhciAkcmVmcmVzaEJ1dHRvbiA9ICRmb3JtLmZpbmQoJy5zdHQtbG9nLXJlZnJlc2gnKTtcbiAgICAgICAgdmFyIGN1cnJlbnRQZXJpb2QgPSBwYXJzZUludCgkcGVyaW9kQnV0dG9ucy5maWx0ZXIoJy5hY3RpdmUnKS5kYXRhKCdwZXJpb2QnKSwgMTApO1xuICAgICAgICB2YXIgbG9hZGVkT25jZSA9IGZhbHNlO1xuICAgICAgICB2YXIgcmVxdWVzdCA9IG51bGw7XG4gICAgICAgIHZhciByZXF1ZXN0R2VuZXJhdGlvbiA9IDA7XG4gICAgICAgIHZhciBjdXJyZW50RW50cmllcyA9IFtdO1xuICAgICAgICB2YXIgY3VycmVudFJlc3BvbnNlID0gbnVsbDtcblxuICAgICAgICBpZiAoIU51bWJlci5pc0Zpbml0ZShjdXJyZW50UGVyaW9kKSkge1xuICAgICAgICAgICAgY3VycmVudFBlcmlvZCA9IDM2MDA7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBsZXZlbE5hbWUobGV2ZWwpIHtcbiAgICAgICAgICAgIHZhciBsZXZlbHMgPSB7XG4gICAgICAgICAgICAgICAgZGVidWc6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsRGVidWcnLCAnRGVidWcnXSxcbiAgICAgICAgICAgICAgICBpbmZvOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbEluZm8nLCAnSW5mbyddLFxuICAgICAgICAgICAgICAgIG5vdGljZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxOb3RpY2UnLCAnTm90aWNlJ10sXG4gICAgICAgICAgICAgICAgd2FybmluZzogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxXYXJuaW5nJywgJ1dhcm5pbmcnXSxcbiAgICAgICAgICAgICAgICBlcnJvcjogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxFcnJvcicsICdFcnJvciddXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGl0ZW0gPSBsZXZlbHNbbGV2ZWxdIHx8IGxldmVscy5pbmZvO1xuXG4gICAgICAgICAgICByZXR1cm4gdHJhbnNsYXRpb24oaXRlbVswXSwgaXRlbVsxXSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjb21wb25lbnROYW1lKGNvbXBvbmVudCkge1xuICAgICAgICAgICAgdmFyIGFsaWFzZXMgPSB7XG4gICAgICAgICAgICAgICAgYXBpOiAnYXBpJyxcbiAgICAgICAgICAgICAgICBxdWV1ZTogJ3F1ZXVlJyxcbiAgICAgICAgICAgICAgICBzY2FubmVyOiAnc2Nhbm5lcicsXG4gICAgICAgICAgICAgICAgd29ya2VyOiAnd29ya2VyJyxcbiAgICAgICAgICAgICAgICB3b3JrZXJsb2NhbHNwZWVjaHRvdGV4dGpvYnM6ICd3b3JrZXInLFxuICAgICAgICAgICAgICAgIHNldHRpbmdzOiAnc2V0dGluZ3MnLFxuICAgICAgICAgICAgICAgIHdlYl91aTogJ3NldHRpbmdzJyxcbiAgICAgICAgICAgICAgICBzZXR1cDogJ3NldHVwJyxcbiAgICAgICAgICAgICAgICBzdG9yYWdlOiAnc3RvcmFnZScsXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdDogJ3RyYW5zY3JpcHQnLFxuICAgICAgICAgICAgICAgIHN5c3RlbTogJ3N5c3RlbScsXG4gICAgICAgICAgICAgICAgbG9jYWxzcGVlY2h0b3RleHRtYWluOiAnc3lzdGVtJyxcbiAgICAgICAgICAgICAgICBsb2dnZXI6ICdzeXN0ZW0nXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgICAgICBhcGk6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudEFwaScsICdBUEknXSxcbiAgICAgICAgICAgICAgICBxdWV1ZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50UXVldWUnLCAnUXVldWUnXSxcbiAgICAgICAgICAgICAgICBzY2FubmVyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTY2FubmVyJywgJ1JlY29yZGluZyBzY2FubmVyJ10sXG4gICAgICAgICAgICAgICAgd29ya2VyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRXb3JrZXInLCAnV29ya2VyJ10sXG4gICAgICAgICAgICAgICAgc2V0dGluZ3M6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNldHRpbmdzJywgJ1NldHRpbmdzJ10sXG4gICAgICAgICAgICAgICAgc2V0dXA6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNldHVwJywgJ1NldHVwJ10sXG4gICAgICAgICAgICAgICAgc3RvcmFnZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U3RvcmFnZScsICdTdG9yYWdlJ10sXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdDogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50VHJhbnNjcmlwdCcsICdUcmFuc2NyaXB0cyddLFxuICAgICAgICAgICAgICAgIHN5c3RlbTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U3lzdGVtJywgJ1N5c3RlbSddLFxuICAgICAgICAgICAgICAgIG90aGVyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRPdGhlcicsICdPdGhlciddXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGxhYmVsID0gbGFiZWxzW2FsaWFzZXNbY29tcG9uZW50XSB8fCAnb3RoZXInXTtcblxuICAgICAgICAgICAgcmV0dXJuIHRyYW5zbGF0aW9uKGxhYmVsWzBdLCBsYWJlbFsxXSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmb3JtYXRUaW1lc3RhbXAodGltZXN0YW1wKSB7XG4gICAgICAgICAgICB2YXIgZGF0ZSA9IG5ldyBEYXRlKHRpbWVzdGFtcCk7XG4gICAgICAgICAgICBpZiAoTnVtYmVyLmlzTmFOKGRhdGUuZ2V0VGltZSgpKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aW1lc3RhbXAgfHwgJ+KAlCc7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHJldHVybiBkYXRlLnRvTG9jYWxlU3RyaW5nKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJTdGF0ZShraW5kLCBtZXNzYWdlKSB7XG4gICAgICAgICAgICBpZiAoIW1lc3NhZ2UpIHtcbiAgICAgICAgICAgICAgICAkc3RhdGUucHJvcCgnaGlkZGVuJywgdHJ1ZSkucmVtb3ZlQ2xhc3MoJ2luZm8gd2FybmluZyBlcnJvcicpLnRleHQoJycpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgJHN0YXRlXG4gICAgICAgICAgICAgICAgLnJlbW92ZUNsYXNzKCdpbmZvIHdhcm5pbmcgZXJyb3InKVxuICAgICAgICAgICAgICAgIC5hZGRDbGFzcyhraW5kIHx8ICdpbmZvJylcbiAgICAgICAgICAgICAgICAudGV4dChtZXNzYWdlKVxuICAgICAgICAgICAgICAgIC5wcm9wKCdoaWRkZW4nLCBmYWxzZSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmaWx0ZXJFbnRyaWVzKGVudHJpZXMpIHtcbiAgICAgICAgICAgIHZhciBsZXZlbCA9IFN0cmluZygkbGV2ZWxGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIGNvbXBvbmVudCA9IFN0cmluZygkY29tcG9uZW50RmlsdGVyLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBxdWVyeSA9IFN0cmluZygkc2VhcmNoLnZhbCgpIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICAgICAgcmV0dXJuIGVudHJpZXMuZmlsdGVyKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIGlmIChsZXZlbCAmJiBlbnRyeS5sZXZlbCAhPT0gbGV2ZWwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoY29tcG9uZW50ICYmIGVudHJ5LmNvbXBvbmVudCAhPT0gY29tcG9uZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKCFxdWVyeSkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICB2YXIgaGF5c3RhY2sgPSBbXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5Lm1lc3NhZ2UsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LmNvbXBvbmVudCxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuZXZlbnQsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LnNldmVyaXR5LFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5waWQsXG4gICAgICAgICAgICAgICAgICAgIEpTT04uc3RyaW5naWZ5KGVudHJ5LmNvbnRleHQgfHwge30pXG4gICAgICAgICAgICAgICAgXS5qb2luKCcgJykudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBoYXlzdGFjay5pbmRleE9mKHF1ZXJ5KSAhPT0gLTE7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlckVudHJpZXMoZW50cmllcykge1xuICAgICAgICAgICAgJHJlc3VsdHMuZW1wdHkoKTtcbiAgICAgICAgICAgIGVudHJpZXMuZm9yRWFjaChmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICB2YXIgJGNhcmQgPSAkKCc8YXJ0aWNsZT4nKS5hZGRDbGFzcygnc3R0LWxvZy1lbnRyeSBzdHQtbG9nLWxldmVsLScgKyBlbnRyeS5sZXZlbCk7XG4gICAgICAgICAgICAgICAgdmFyICRoZWFkZXIgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5LWhlYWRlcicpO1xuICAgICAgICAgICAgICAgIHZhciAkaWRlbnRpdHkgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5LWlkZW50aXR5Jyk7XG4gICAgICAgICAgICAgICAgdmFyICRsZXZlbCA9ICQoJzxzcGFuPicpLmFkZENsYXNzKCd1aSB0aW55IGxhYmVsIHN0dC1sb2ctbGV2ZWwtbGFiZWwnKS50ZXh0KGxldmVsTmFtZShlbnRyeS5sZXZlbCkpO1xuICAgICAgICAgICAgICAgIHZhciAkY29tcG9uZW50ID0gJCgnPHNwYW4+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctY29tcG9uZW50JykudGV4dChjb21wb25lbnROYW1lKGVudHJ5LmNvbXBvbmVudCkpO1xuICAgICAgICAgICAgICAgIHZhciAkdGltZSA9ICQoJzx0aW1lPicpLmFkZENsYXNzKCdzdHQtbG9nLXRpbWUnKS50ZXh0KGZvcm1hdFRpbWVzdGFtcChlbnRyeS50cykpO1xuICAgICAgICAgICAgICAgIHZhciAkbWVzc2FnZSA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctbWVzc2FnZScpLnRleHQoZW50cnkubWVzc2FnZSB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgdmFyICRkZXRhaWxzID0gJCgnPGRldGFpbHM+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZGV0YWlscycpO1xuICAgICAgICAgICAgICAgIHZhciAkc3VtbWFyeSA9ICQoJzxzdW1tYXJ5PicpLnRleHQodHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1RlY2huaWNhbERldGFpbHMnLFxuICAgICAgICAgICAgICAgICAgICAnVGVjaG5pY2FsIGRldGFpbHMnXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgdmFyIGRldGFpbFRleHQgPSB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NFdmVudCcsICdFdmVudCcpXG4gICAgICAgICAgICAgICAgICAgICsgJzogJyArIChlbnRyeS5ldmVudCB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxuc2V2ZXJpdHk6ICcgKyAoZW50cnkuc2V2ZXJpdHkgfHwgJ+KAlCcpO1xuICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbnBpZDogJyArIChlbnRyeS5waWQgPT09IHVuZGVmaW5lZCA/ICfigJQnIDogZW50cnkucGlkKTtcbiAgICAgICAgICAgICAgICBpZiAoZW50cnkuY29udGV4dCAmJiBPYmplY3Qua2V5cyhlbnRyeS5jb250ZXh0KS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbicgKyBKU09OLnN0cmluZ2lmeShlbnRyeS5jb250ZXh0LCBudWxsLCAyKTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICAkaWRlbnRpdHkuYXBwZW5kKCRsZXZlbCwgJGNvbXBvbmVudCk7XG4gICAgICAgICAgICAgICAgJGhlYWRlci5hcHBlbmQoJGlkZW50aXR5LCAkdGltZSk7XG4gICAgICAgICAgICAgICAgJGRldGFpbHMuYXBwZW5kKCRzdW1tYXJ5LCAkKCc8cHJlPicpLnRleHQoZGV0YWlsVGV4dCkpO1xuICAgICAgICAgICAgICAgICRjYXJkLmFwcGVuZCgkaGVhZGVyLCAkbWVzc2FnZSwgJGRldGFpbHMpO1xuICAgICAgICAgICAgICAgICRyZXN1bHRzLmFwcGVuZCgkY2FyZCk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZUNvbXBvbmVudE9wdGlvbnMoZW50cmllcykge1xuICAgICAgICAgICAgdmFyIHNlbGVjdGVkID0gU3RyaW5nKCRjb21wb25lbnRGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIGNvbXBvbmVudHMgPSBbXTtcbiAgICAgICAgICAgIGVudHJpZXMuZm9yRWFjaChmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICBpZiAoZW50cnkuY29tcG9uZW50ICYmIGNvbXBvbmVudHMuaW5kZXhPZihlbnRyeS5jb21wb25lbnQpID09PSAtMSkge1xuICAgICAgICAgICAgICAgICAgICBjb21wb25lbnRzLnB1c2goZW50cnkuY29tcG9uZW50KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIGNvbXBvbmVudHMuc29ydChmdW5jdGlvbiAobGVmdCwgcmlnaHQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gY29tcG9uZW50TmFtZShsZWZ0KS5sb2NhbGVDb21wYXJlKGNvbXBvbmVudE5hbWUocmlnaHQpKTtcbiAgICAgICAgICAgIH0pO1xuXG4gICAgICAgICAgICAkY29tcG9uZW50RmlsdGVyLmVtcHR5KCkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxvcHRpb24+JykudmFsKCcnKS50ZXh0KHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NDb21wb25lbnRBbGwnLFxuICAgICAgICAgICAgICAgICAgICAnQWxsIGNvbXBvbmVudHMnXG4gICAgICAgICAgICAgICAgKSlcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICBjb21wb25lbnRzLmZvckVhY2goZnVuY3Rpb24gKGNvbXBvbmVudCkge1xuICAgICAgICAgICAgICAgICRjb21wb25lbnRGaWx0ZXIuYXBwZW5kKCQoJzxvcHRpb24+JykudmFsKGNvbXBvbmVudCkudGV4dChjb21wb25lbnROYW1lKGNvbXBvbmVudCkpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgaWYgKHNlbGVjdGVkICYmIGNvbXBvbmVudHMuaW5kZXhPZihzZWxlY3RlZCkgIT09IC0xKSB7XG4gICAgICAgICAgICAgICAgJGNvbXBvbmVudEZpbHRlci52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlTWV0YShjb3VudCwgcmVzcG9uc2UpIHtcbiAgICAgICAgICAgIHZhciBmb3VuZCA9IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0ZvdW5kJywgJyVjb3VudCUgZXZlbnRzJylcbiAgICAgICAgICAgICAgICAucmVwbGFjZSgnJWNvdW50JScsIGNvdW50KTtcbiAgICAgICAgICAgIHZhciB1cGRhdGVkID0gdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVXBkYXRlZCcsICdVcGRhdGVkOiAldGltZSUnKVxuICAgICAgICAgICAgICAgIC5yZXBsYWNlKCcldGltZSUnLCByZXNwb25zZSAmJiByZXNwb25zZS5nZW5lcmF0ZWRBdFxuICAgICAgICAgICAgICAgICAgICA/IHJlc3BvbnNlLmdlbmVyYXRlZEF0XG4gICAgICAgICAgICAgICAgICAgIDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzTmV2ZXInLCAnbmV2ZXInKSk7XG4gICAgICAgICAgICAkcmVzdWx0Q291bnQudGV4dChmb3VuZCk7XG4gICAgICAgICAgICAkdXBkYXRlZEF0LnRleHQodXBkYXRlZCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJDdXJyZW50KCkge1xuICAgICAgICAgICAgaWYgKCFjdXJyZW50UmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgZmlsdGVyZWQgPSBmaWx0ZXJFbnRyaWVzKGN1cnJlbnRFbnRyaWVzKTtcbiAgICAgICAgICAgIHJlbmRlckVudHJpZXMoZmlsdGVyZWQpO1xuICAgICAgICAgICAgdXBkYXRlTWV0YShmaWx0ZXJlZC5sZW5ndGgsIGN1cnJlbnRSZXNwb25zZSk7XG5cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAnbWlzc2luZycpIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NNaXNzaW5nJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBtb2R1bGUgam91cm5hbCBoYXMgbm90IGJlZW4gY3JlYXRlZCB5ZXQuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAndW5hdmFpbGFibGUnKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1VuYXZhaWxhYmxlJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBtb2R1bGUgam91cm5hbCBpcyB1bmF2YWlsYWJsZS4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdjb3JydXB0Jykge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdlcnJvcicsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NDb3JydXB0JyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBqb3VybmFsIGNvbnRhaW5zIG5vIHJlYWRhYmxlIGV2ZW50cy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRFbnRyaWVzLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0VtcHR5JyxcbiAgICAgICAgICAgICAgICAgICAgJ05vIG1vZHVsZSBldmVudHMgd2VyZSBmb3VuZCBmb3IgdGhpcyBwZXJpb2QuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChmaWx0ZXJlZC5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NGaWx0ZXJlZEVtcHR5JyxcbiAgICAgICAgICAgICAgICAgICAgJ05vIGV2ZW50cyBtYXRjaCB0aGUgc2VsZWN0ZWQgZmlsdGVycy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICB2YXIgbm90aWNlcyA9IFtdO1xuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdwYXJ0aWFsJykge1xuICAgICAgICAgICAgICAgIG5vdGljZXMucHVzaCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzUGFydGlhbCcsXG4gICAgICAgICAgICAgICAgICAgICclY291bnQlIGRhbWFnZWQgam91cm5hbCBsaW5lcyB3ZXJlIHNraXBwZWQuJ1xuICAgICAgICAgICAgICAgICkucmVwbGFjZSgnJWNvdW50JScsIGN1cnJlbnRSZXNwb25zZS5tYWxmb3JtZWRDb3VudCB8fCAwKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnRydW5jYXRlZCkge1xuICAgICAgICAgICAgICAgIG5vdGljZXMucHVzaCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVHJ1bmNhdGVkJyxcbiAgICAgICAgICAgICAgICAgICAgJ09ubHkgdGhlIG5ld2VzdCAxMDAwIGV2ZW50cyBhcmUgc2hvd24uJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmVuZGVyU3RhdGUobm90aWNlcy5sZW5ndGggPiAwID8gJ3dhcm5pbmcnIDogJycsIG5vdGljZXMuam9pbignICcpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNldExvYWRpbmcoKSB7XG4gICAgICAgICAgICAkc2VnbWVudC5hZGRDbGFzcygnc3R0LWxvZ3MtbG9hZGluZycpO1xuICAgICAgICAgICAgJHJlc3VsdHMuZW1wdHkoKTtcbiAgICAgICAgICAgIHVwZGF0ZU1ldGEoMCwgbnVsbCk7XG4gICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0xvYWRpbmcnLFxuICAgICAgICAgICAgICAgICdMb2FkaW5nIG1vZHVsZSBldmVudHMuLi4nXG4gICAgICAgICAgICApKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGxvYWRMb2dzKGZvcmNlKSB7XG4gICAgICAgICAgICBpZiAoIWZvcmNlICYmIGxvYWRlZE9uY2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoIWRhdGFVcmwpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXF1ZXN0R2VuZXJhdGlvbiArPSAxO1xuICAgICAgICAgICAgdmFyIHJlcXVlc3RJZCA9IHJlcXVlc3RHZW5lcmF0aW9uO1xuICAgICAgICAgICAgaWYgKHJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICByZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHNldExvYWRpbmcoKTtcbiAgICAgICAgICAgICRyZWZyZXNoQnV0dG9uLmFkZENsYXNzKCdsb2FkaW5nIGRpc2FibGVkJyk7XG4gICAgICAgICAgICB2YXIgY3VycmVudFJlcXVlc3QgPSAkLmFqYXgoe1xuICAgICAgICAgICAgICAgIHVybDogZGF0YVVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdHRVQnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YToge1xuICAgICAgICAgICAgICAgICAgICBwZXJpb2Q6IGN1cnJlbnRQZXJpb2RcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5kb25lKGZ1bmN0aW9uIChyZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY3VycmVudFJlc3BvbnNlID0gcmVzcG9uc2UgfHwge307XG4gICAgICAgICAgICAgICAgY3VycmVudEVudHJpZXMgPSBBcnJheS5pc0FycmF5KGN1cnJlbnRSZXNwb25zZS5lbnRyaWVzKSA/IGN1cnJlbnRSZXNwb25zZS5lbnRyaWVzIDogW107XG4gICAgICAgICAgICAgICAgdXBkYXRlQ29tcG9uZW50T3B0aW9ucyhjdXJyZW50RW50cmllcyk7XG4gICAgICAgICAgICAgICAgcmVuZGVyQ3VycmVudCgpO1xuICAgICAgICAgICAgICAgIGxvYWRlZE9uY2UgPSB0cnVlO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyLCBzdGF0dXMpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbiB8fCBzdGF0dXMgPT09ICdhYm9ydCcpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjdXJyZW50UmVzcG9uc2UgPSBudWxsO1xuICAgICAgICAgICAgICAgIGN1cnJlbnRFbnRyaWVzID0gW107XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0Vycm9yJyxcbiAgICAgICAgICAgICAgICAgICAgJ1VuYWJsZSB0byBsb2FkIG1vZHVsZSBldmVudHMuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgfSkuYWx3YXlzKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0ID09PSBjdXJyZW50UmVxdWVzdCkge1xuICAgICAgICAgICAgICAgICAgICByZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgJHNlZ21lbnQucmVtb3ZlQ2xhc3MoJ3N0dC1sb2dzLWxvYWRpbmcnKTtcbiAgICAgICAgICAgICAgICAkcmVmcmVzaEJ1dHRvbi5yZW1vdmVDbGFzcygnbG9hZGluZyBkaXNhYmxlZCcpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICByZXF1ZXN0ID0gY3VycmVudFJlcXVlc3Q7XG4gICAgICAgIH1cblxuICAgICAgICAkcGVyaW9kQnV0dG9ucy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJGJ1dHRvbiA9ICQodGhpcyk7XG4gICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gcGFyc2VJbnQoJGJ1dHRvbi5kYXRhKCdwZXJpb2QnKSwgMTApO1xuICAgICAgICAgICAgaWYgKCFOdW1iZXIuaXNGaW5pdGUoY3VycmVudFBlcmlvZCkpIHtcbiAgICAgICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gMzYwMDtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgICRwZXJpb2RCdXR0b25zLnJlbW92ZUNsYXNzKCdhY3RpdmUnKTtcbiAgICAgICAgICAgICRidXR0b24uYWRkQ2xhc3MoJ2FjdGl2ZScpO1xuICAgICAgICAgICAgbG9hZGVkT25jZSA9IGZhbHNlO1xuICAgICAgICAgICAgbG9hZExvZ3ModHJ1ZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRyZWZyZXNoQnV0dG9uLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGxvYWRMb2dzKHRydWUpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbGV2ZWxGaWx0ZXIuYWRkKCRjb21wb25lbnRGaWx0ZXIpLm9uKCdjaGFuZ2UnLCByZW5kZXJDdXJyZW50KTtcbiAgICAgICAgJHNlYXJjaC5vbignaW5wdXQnLCByZW5kZXJDdXJyZW50KTtcblxuICAgICAgICAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1lbnUgLml0ZW1bZGF0YS10YWI9XCJsb2dzXCJdJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgd2luZG93LnNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGxvYWRMb2dzKGZhbHNlKTtcbiAgICAgICAgICAgIH0sIDApO1xuICAgICAgICB9KTtcblxuICAgICAgICB1cGRhdGVNZXRhKDAsIG51bGwpO1xuICAgICAgICBpZiAocmVxdWVzdGVkVGFiKCkgPT09ICdsb2dzJyB8fCAkZm9ybS5jbG9zZXN0KCcudGFiLnNlZ21lbnQnKS5oYXNDbGFzcygnYWN0aXZlJykpIHtcbiAgICAgICAgICAgIGxvYWRMb2dzKGZhbHNlKTtcbiAgICAgICAgfVxuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyKCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyICRzbGlkZXIgPSAkKCcjc3R0LWNkci1oaXN0b3J5LWRheXMtc2xpZGVyJyk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJjZHJfaGlzdG9yeV9kYXlzXCJdJyk7XG5cbiAgICAgICAgaWYgKCRzbGlkZXIubGVuZ3RoID09PSAwIHx8ICRpbnB1dC5sZW5ndGggPT09IDAgfHwgdHlwZW9mICRzbGlkZXIuc2xpZGVyICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgdmFsdWVzID0gWycxJywgJzcnLCAnMzAnLCAnOTAnLCAnMTgwJywgJzM2NScsICcwJ107XG4gICAgICAgIHZhciBsYWJlbHMgPSB7XG4gICAgICAgICAgICAwOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkxRGF5JywgJzEgZGF5JyksXG4gICAgICAgICAgICAxOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3Rvcnk3RGF5cycsICc3IGRheXMnKSxcbiAgICAgICAgICAgIDI6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTMwRGF5cycsICczMCBkYXlzJyksXG4gICAgICAgICAgICAzOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3Rvcnk5MERheXMnLCAnOTAgZGF5cycpLFxuICAgICAgICAgICAgNDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MTgwRGF5cycsICc2IG1vbnRocycpLFxuICAgICAgICAgICAgNTogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MzY1RGF5cycsICcxIHllYXInKSxcbiAgICAgICAgICAgIDY6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeUFsbCcsICdBbGwnKVxuICAgICAgICB9O1xuICAgICAgICB2YXIgY3VycmVudFZhbHVlID0gU3RyaW5nKCRpbnB1dC52YWwoKSB8fCAnMzAnKTtcbiAgICAgICAgdmFyIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKGN1cnJlbnRWYWx1ZSk7XG4gICAgICAgIHZhciBpbml0aWFsaXplZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmIChpbml0aWFsSW5kZXggPCAwKSB7XG4gICAgICAgICAgICBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZignMzAnKTtcbiAgICAgICAgICAgICRpbnB1dC52YWwoJzMwJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2xpZGVyLnNsaWRlcih7XG4gICAgICAgICAgICBtaW46IDAsXG4gICAgICAgICAgICBtYXg6IHZhbHVlcy5sZW5ndGggLSAxLFxuICAgICAgICAgICAgc3RlcDogMSxcbiAgICAgICAgICAgIHNtb290aDogdHJ1ZSxcbiAgICAgICAgICAgIGF1dG9BZGp1c3RMYWJlbHM6IGZhbHNlLFxuICAgICAgICAgICAgaW50ZXJwcmV0TGFiZWw6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBsYWJlbHNbdmFsdWVdIHx8ICcnO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG9uQ2hhbmdlOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgc2VsZWN0ZWQgPSB2YWx1ZXNbdmFsdWVdIHx8ICczMCc7XG5cbiAgICAgICAgICAgICAgICAkaW5wdXQudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgICAgICBpZiAoaW5pdGlhbGl6ZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgJGlucHV0LnRyaWdnZXIoJ2NoYW5nZScpO1xuICAgICAgICAgICAgICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiB0eXBlb2YgRm9ybS5jaGVja1ZhbHVlcyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgRm9ybS5jaGVja1ZhbHVlcygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNsaWRlci5zbGlkZXIoJ3NldCB2YWx1ZScsIGluaXRpYWxJbmRleCwgZmFsc2UpO1xuICAgICAgICBpbml0aWFsaXplZCA9IHRydWU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgJHNsaWRlciA9ICQoJyNzdHQtdHJhbnNjcmlwdC1yZXRlbnRpb24tZGF5cy1zbGlkZXInKTtcbiAgICAgICAgdmFyICRpbnB1dCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXNcIl0nKTtcblxuICAgICAgICBpZiAoJHNsaWRlci5sZW5ndGggPT09IDAgfHwgJGlucHV0Lmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJHNsaWRlci5zbGlkZXIgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciB2YWx1ZXMgPSBbJzMwJywgJzkwJywgJzE4MCcsICczNjUnLCAnMCddO1xuICAgICAgICB2YXIgbGFiZWxzID0ge1xuICAgICAgICAgICAgMDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uMzBEYXlzJywgJzMwIGRheXMnKSxcbiAgICAgICAgICAgIDE6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjkwRGF5cycsICc5MCBkYXlzJyksXG4gICAgICAgICAgICAyOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24xODBEYXlzJywgJzE4MCBkYXlzJyksXG4gICAgICAgICAgICAzOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24zNjVEYXlzJywgJzEgeWVhcicpLFxuICAgICAgICAgICAgNDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uVW5saW1pdGVkJywgJ1VubGltaXRlZCcpXG4gICAgICAgIH07XG4gICAgICAgIHZhciBjdXJyZW50VmFsdWUgPSBTdHJpbmcoJGlucHV0LnZhbCgpIHx8ICczNjUnKTtcbiAgICAgICAgdmFyIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKGN1cnJlbnRWYWx1ZSk7XG4gICAgICAgIHZhciBpbml0aWFsaXplZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmIChpbml0aWFsSW5kZXggPCAwKSB7XG4gICAgICAgICAgICBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZignMzY1Jyk7XG4gICAgICAgICAgICAkaW5wdXQudmFsKCczNjUnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzbGlkZXIuc2xpZGVyKHtcbiAgICAgICAgICAgIG1pbjogMCxcbiAgICAgICAgICAgIG1heDogdmFsdWVzLmxlbmd0aCAtIDEsXG4gICAgICAgICAgICBzdGVwOiAxLFxuICAgICAgICAgICAgc21vb3RoOiB0cnVlLFxuICAgICAgICAgICAgYXV0b0FkanVzdExhYmVsczogZmFsc2UsXG4gICAgICAgICAgICBpbnRlcnByZXRMYWJlbDogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGxhYmVsc1t2YWx1ZV0gfHwgJyc7XG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgb25DaGFuZ2U6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBzZWxlY3RlZCA9IHZhbHVlc1t2YWx1ZV0gfHwgJzM2NSc7XG5cbiAgICAgICAgICAgICAgICAkaW5wdXQudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgICAgICBpZiAoaW5pdGlhbGl6ZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgJGlucHV0LnRyaWdnZXIoJ2NoYW5nZScpO1xuICAgICAgICAgICAgICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiB0eXBlb2YgRm9ybS5jaGVja1ZhbHVlcyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgRm9ybS5jaGVja1ZhbHVlcygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNsaWRlci5zbGlkZXIoJ3NldCB2YWx1ZScsIGluaXRpYWxJbmRleCwgZmFsc2UpO1xuICAgICAgICBpbml0aWFsaXplZCA9IHRydWU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gcmV0ZW50aW9uQWxsb3dzUHJvY2Vzc2luZyhwcm9jZXNzaW5nRGF5cywgcmV0ZW50aW9uRGF5cykge1xuICAgICAgICByZXR1cm4gcmV0ZW50aW9uRGF5cyA9PT0gMCB8fCAocHJvY2Vzc2luZ0RheXMgPiAwICYmIHByb2Nlc3NpbmdEYXlzIDw9IHJldGVudGlvbkRheXMpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyIHByb2Nlc3NpbmdEYXlzID0gTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cImNkcl9oaXN0b3J5X2RheXNcIl0nKS52YWwoKSk7XG4gICAgICAgIHZhciByZXRlbnRpb25EYXlzID0gTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXNcIl0nKS52YWwoKSk7XG4gICAgICAgIHZhciBjb21wYXRpYmxlID0gTnVtYmVyLmlzRmluaXRlKHByb2Nlc3NpbmdEYXlzKVxuICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKHJldGVudGlvbkRheXMpXG4gICAgICAgICAgICAmJiByZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nKHByb2Nlc3NpbmdEYXlzLCByZXRlbnRpb25EYXlzKTtcbiAgICAgICAgdmFyICRtZXNzYWdlID0gJHNldHRpbmdzRm9ybS5maW5kKCcuc3R0LXJldGVudGlvbi1jb25mbGljdC1tZXNzYWdlJyk7XG5cbiAgICAgICAgJG1lc3NhZ2UudG9nZ2xlQ2xhc3MoJ2hpZGRlbicsIGNvbXBhdGlibGUpLmF0dHIoJ2FyaWEtaGlkZGVuJywgY29tcGF0aWJsZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuXG4gICAgICAgIHJldHVybiBjb21wYXRpYmxlO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlKCRtb2RlbEZvcm0pIHtcbiAgICAgICAgdmFyICRzYXZlQnV0dG9uID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tb2RlbC1zYXZlJyk7XG4gICAgICAgIHZhciAkbW9kZWxJbnB1dHMgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgaW5wdXRbbmFtZT1cInNlbGVjdGVkX21vZGVsXCJdJyk7XG4gICAgICAgIHZhciAkY29tcGF0aWJpbGl0eU1lc3NhZ2UgPSAkKCcjc3R0LW1vZGVsLWxhbmd1YWdlLWNvbXBhdGliaWxpdHknKTtcbiAgICAgICAgdmFyIGluaXRpYWxTZWxlY3RlZE1vZGVsID0gU3RyaW5nKCRtb2RlbEZvcm0uZGF0YSgnaW5pdGlhbC1zZWxlY3RlZC1tb2RlbCcpIHx8ICRtb2RlbElucHV0cy5maWx0ZXIoJzpjaGVja2VkJykudmFsKCkgfHwgJycpO1xuICAgICAgICB2YXIgc2VsZWN0ZWRMYW5ndWFnZSA9IFN0cmluZygkbW9kZWxGb3JtLmRhdGEoJ3NlbGVjdGVkLWxhbmd1YWdlJykgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpO1xuICAgICAgICB2YXIgJHBsYXRmb3JtQnV0dG9ucyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1tb2RlbC1wbGF0Zm9ybS1zd2l0Y2ggW2RhdGEtcGxhdGZvcm1dJyk7XG4gICAgICAgIHZhciAkcGxhdGZvcm1DaGFuZ2VNZXNzYWdlID0gJCgnI3N0dC1tb2RlbC1wbGF0Zm9ybS1jaGFuZ2UnKTtcbiAgICAgICAgdmFyIGluaXRpYWxQbGF0Zm9ybSA9IFN0cmluZygkbW9kZWxGb3JtLmRhdGEoJ3NlbGVjdGVkLXBsYXRmb3JtJykgfHwgJ21hY29zJyk7XG5cbiAgICAgICAgZnVuY3Rpb24gc2VsZWN0ZWRNb2RlbCgpIHtcbiAgICAgICAgICAgIHJldHVybiBTdHJpbmcoJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKS52YWwoKSB8fCAnJyk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZWxlY3RlZE1vZGVsUGxhdGZvcm0oKSB7XG4gICAgICAgICAgICByZXR1cm4gU3RyaW5nKCRtb2RlbElucHV0cy5maWx0ZXIoJzpjaGVja2VkJykuYXR0cignZGF0YS1wbGF0Zm9ybScpIHx8ICcnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZVBsYXRmb3JtQ2hhbmdlU3RhdGUoKSB7XG4gICAgICAgICAgICB2YXIgcGxhdGZvcm0gPSBzZWxlY3RlZE1vZGVsUGxhdGZvcm0oKTtcbiAgICAgICAgICAgIHZhciBjaGFuZ2VzUGxhdGZvcm0gPSBwbGF0Zm9ybSAhPT0gJycgJiYgcGxhdGZvcm0gIT09IGluaXRpYWxQbGF0Zm9ybTtcblxuICAgICAgICAgICAgJHBsYXRmb3JtQ2hhbmdlTWVzc2FnZVxuICAgICAgICAgICAgICAgIC50b2dnbGVDbGFzcygnaGlkZGVuJywgIWNoYW5nZXNQbGF0Zm9ybSlcbiAgICAgICAgICAgICAgICAuYXR0cignYXJpYS1oaWRkZW4nLCBjaGFuZ2VzUGxhdGZvcm0gPyAnZmFsc2UnIDogJ3RydWUnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNob3dQbGF0Zm9ybShwbGF0Zm9ybSkge1xuICAgICAgICAgICAgdmFyICRyb3dzID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LW1vZGVsLW1hcmtldHBsYWNlLXRhYmxlIHRib2R5IHRyJyk7XG5cbiAgICAgICAgICAgICRwbGF0Zm9ybUJ1dHRvbnMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGFjdGl2ZSA9IFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkgPT09IHBsYXRmb3JtO1xuXG4gICAgICAgICAgICAgICAgJCh0aGlzKS50b2dnbGVDbGFzcygnYWN0aXZlJywgYWN0aXZlKS5hdHRyKCdhcmlhLXByZXNzZWQnLCBhY3RpdmUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgJHJvd3MuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgJCh0aGlzKS5wcm9wKCdoaWRkZW4nLCBTdHJpbmcoJCh0aGlzKS5hdHRyKCdkYXRhLXBsYXRmb3JtJykpICE9PSBwbGF0Zm9ybSk7XG4gICAgICAgICAgICB9KTtcblxuICAgICAgICAgICAgaWYgKHNlbGVjdGVkTW9kZWxQbGF0Zm9ybSgpICE9PSBwbGF0Zm9ybSkge1xuICAgICAgICAgICAgICAgICRtb2RlbElucHV0cy5wcm9wKCdjaGVja2VkJywgZmFsc2UpO1xuICAgICAgICAgICAgICAgICRyb3dzLnJlbW92ZUNsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgICAgIGlmIChwbGF0Zm9ybSA9PT0gaW5pdGlhbFBsYXRmb3JtKSB7XG4gICAgICAgICAgICAgICAgICAgICRtb2RlbElucHV0cy5maWx0ZXIoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIFN0cmluZygkKHRoaXMpLnZhbCgpKSA9PT0gaW5pdGlhbFNlbGVjdGVkTW9kZWw7XG4gICAgICAgICAgICAgICAgICAgIH0pLmNsb3Nlc3QoJy51aS5yYWRpby5jaGVja2JveCcpLmNoZWNrYm94KCdjaGVjaycpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNlbGVjdGVkTW9kZWxTdXBwb3J0c0xhbmd1YWdlKCkge1xuICAgICAgICAgICAgdmFyICRzZWxlY3RlZElucHV0ID0gJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKTtcbiAgICAgICAgICAgIHZhciBzdXBwb3J0ZWRMYW5ndWFnZXM7XG5cbiAgICAgICAgICAgIGlmIChzZWxlY3RlZExhbmd1YWdlID09PSAnJyB8fCBzZWxlY3RlZExhbmd1YWdlID09PSAnYXV0bycpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICgkc2VsZWN0ZWRJbnB1dC5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHN1cHBvcnRlZExhbmd1YWdlcyA9IFN0cmluZygkc2VsZWN0ZWRJbnB1dC5hdHRyKCdkYXRhLXN1cHBvcnRlZC1sYW5ndWFnZXMnKSB8fCAnJylcbiAgICAgICAgICAgICAgICAuc3BsaXQoJywnKVxuICAgICAgICAgICAgICAgIC5tYXAoZnVuY3Rpb24gKGxhbmd1YWdlKSB7IHJldHVybiBsYW5ndWFnZS50cmltKCkudG9Mb3dlckNhc2UoKTsgfSlcbiAgICAgICAgICAgICAgICAuZmlsdGVyKGZ1bmN0aW9uIChsYW5ndWFnZSkgeyByZXR1cm4gbGFuZ3VhZ2UgIT09ICcnOyB9KTtcblxuICAgICAgICAgICAgcmV0dXJuIHN1cHBvcnRlZExhbmd1YWdlcy5pbmRleE9mKHNlbGVjdGVkTGFuZ3VhZ2UpICE9PSAtMTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZUNvbXBhdGliaWxpdHlTdGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciBjb21wYXRpYmxlID0gc2VsZWN0ZWRNb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoKTtcbiAgICAgICAgICAgIC8vIEFmdGVyIGEgcGxhdGZvcm0gc3dpdGNoIG5vdGhpbmcgaXMgc2VsZWN0ZWQgeWV0OyB0aGVyZSBpcyBubyBtb2RlbCB0byB3YXJuIGFib3V0LlxuICAgICAgICAgICAgdmFyIHdhcm4gPSAhY29tcGF0aWJsZSAmJiBzZWxlY3RlZE1vZGVsKCkgIT09ICcnO1xuXG4gICAgICAgICAgICAkY29tcGF0aWJpbGl0eU1lc3NhZ2VcbiAgICAgICAgICAgICAgICAudG9nZ2xlQ2xhc3MoJ2hpZGRlbicsICF3YXJuKVxuICAgICAgICAgICAgICAgIC5hdHRyKCdhcmlhLWhpZGRlbicsIHdhcm4gPyAnZmFsc2UnIDogJ3RydWUnKTtcblxuICAgICAgICAgICAgcmV0dXJuIGNvbXBhdGlibGU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVTYXZlQnV0dG9uU3RhdGUoKSB7XG4gICAgICAgICAgICB2YXIgY29tcGF0aWJsZSA9IHVwZGF0ZUNvbXBhdGliaWxpdHlTdGF0ZSgpO1xuXG4gICAgICAgICAgICB1cGRhdGVQbGF0Zm9ybUNoYW5nZVN0YXRlKCk7XG4gICAgICAgICAgICB2YXIgaGFzQ2hhbmdlcyA9IHNlbGVjdGVkTW9kZWwoKSAhPT0gJydcbiAgICAgICAgICAgICAgICAmJiBzZWxlY3RlZE1vZGVsKCkgIT09IGluaXRpYWxTZWxlY3RlZE1vZGVsXG4gICAgICAgICAgICAgICAgJiYgY29tcGF0aWJsZTtcblxuICAgICAgICAgICAgJHNhdmVCdXR0b24udG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWhhc0NoYW5nZXMpO1xuICAgICAgICAgICAgJHNhdmVCdXR0b24ucHJvcCgnZGlzYWJsZWQnLCAhaGFzQ2hhbmdlcyk7XG4gICAgICAgIH1cblxuICAgICAgICBpZiAodHlwZW9mICQuZm4uYWNjb3JkaW9uID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtYWR2YW5jZWQtc2V0dGluZ3MtZHJvcGRvd24nKS5hY2NvcmRpb24oKTtcbiAgICAgICAgfVxuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1tb2RlbC1tYXJrZXRwbGFjZS10YWJsZSB0Ym9keSB0cicpLm9uKCdjbGljaycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKCQoZXZlbnQudGFyZ2V0KS5jbG9zZXN0KCdhLCBidXR0b24sIGZvcm0sIGlucHV0LCBsYWJlbCcpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICQodGhpcykuZmluZCgnLnVpLnJhZGlvLmNoZWNrYm94JykuY2hlY2tib3goJ2NoZWNrJyk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRwbGF0Zm9ybUJ1dHRvbnMub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgc2hvd1BsYXRmb3JtKFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkpO1xuICAgICAgICAgICAgdXBkYXRlU2F2ZUJ1dHRvblN0YXRlKCk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRtb2RlbElucHV0cy5vbignY2hhbmdlJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyICRyb3cgPSAkKHRoaXMpLmNsb3Nlc3QoJ3RyJyk7XG5cbiAgICAgICAgICAgICRyb3cuYWRkQ2xhc3MoJ3Bvc2l0aXZlJykuc2libGluZ3MoKS5yZW1vdmVDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbW9kZWxGb3JtLm9uKCdzdWJtaXQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoIXNlbGVjdGVkTW9kZWxTdXBwb3J0c0xhbmd1YWdlKClcbiAgICAgICAgICAgICAgICB8fCBzZWxlY3RlZE1vZGVsKCkgPT09ICcnXG4gICAgICAgICAgICAgICAgfHwgc2VsZWN0ZWRNb2RlbCgpID09PSBpbml0aWFsU2VsZWN0ZWRNb2RlbCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICgkc2F2ZUJ1dHRvbi5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgJHNhdmVCdXR0b24uYWRkQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQgYWN0aXZlJyk7XG4gICAgICAgICAgICAgICAgJHNhdmVCdXR0b24ucHJvcCgnZGlzYWJsZWQnLCB0cnVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG5cbiAgICAgICAgdXBkYXRlU2F2ZUJ1dHRvblN0YXRlKCk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCgpIHtcbiAgICAgICAgdmFyICRtb2RhbCA9ICQoJyNzdHQtY29uZmlybS1tb2RhbCcpO1xuXG4gICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkbW9kYWwubW9kYWwgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFByZS1pbml0aWFsaXplIHNvIFNlbWFudGljIFVJIG1vdmVzIHRoZSBlbGVtZW50IHRvIDxib2R5PiBhbmQgaGlkZXMgaXQgcHJvcGVybHlcbiAgICAgICAgJG1vZGFsLm1vZGFsKHt9KTtcblxuICAgICAgICBmdW5jdGlvbiBzaG93Q29uZmlybShtZXNzYWdlLCBvbkNvbmZpcm0pIHtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNvbmZpcm0tbWVzc2FnZScpLnRleHQobWVzc2FnZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uQXBwcm92ZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBvbkNvbmZpcm0oKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5tb2RhbCgnc2hvdycpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gYmluZENvbmZpcm1hYmxlRm9ybSgkZm9ybSkge1xuICAgICAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdC5zdHQtY29uZmlybScsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgICAgIHZhciBtZXNzYWdlID0gJCh0aGlzKS5kYXRhKCdjb25maXJtJykgfHwgJyc7XG5cbiAgICAgICAgICAgICAgICBpZiAobWVzc2FnZSA9PT0gJycpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgZXZlbnQuc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCk7XG5cbiAgICAgICAgICAgICAgICB2YXIgJHNlbGYgPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIHNob3dDb25maXJtKG1lc3NhZ2UsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgJHNlbGYub2ZmKCdzdWJtaXQuc3R0LWNvbmZpcm0nKS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWNvbmZpcm1hYmxlLWZvcm0nKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGJpbmRDb25maXJtYWJsZUZvcm0oJCh0aGlzKSk7XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIC8vIFRyYW5zY3JpcHQgZGVsZXRpb24gaXMgaXJyZXZlcnNpYmxlLCBzbyBpdCBuZWVkcyBhbiBleHBsaWNpdFxuICAgIC8vIGFja25vd2xlZGdlbWVudDogdGhlIGRpYWxvZyBsaXN0cyB3aGF0IGlzIGFuZCBpcyBub3QgcmVtb3ZlZCwgdGhlXG4gICAgLy8gRGVsZXRlIGJ1dHRvbiBzdGF5cyBkaXNhYmxlZCB1bnRpbCB0aGUgY2hlY2tib3ggaXMgdGlja2VkLCBhbmQgb25seSB0aGVuXG4gICAgLy8gaXMgY29uZmlybV9kZWxldGUgYWRkZWQgdG8gdGhlIGZvcm0gdGhlIHNlcnZlciByZXF1aXJlcyBpdCBvbi5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdERlbGV0aW9uKCkge1xuICAgICAgICB2YXIgJG1vZGFsID0gJCgnI3N0dC1kZWxldGUtdHJhbnNjcmlwdC1tb2RhbCcpO1xuICAgICAgICB2YXIgJGZvcm1zID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWRlbGV0ZS10cmFuc2NyaXB0LWZvcm0nKTtcblxuICAgICAgICBpZiAoJG1vZGFsLmxlbmd0aCA9PT0gMCB8fCAkZm9ybXMubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkbW9kYWwubW9kYWwgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciAkYWNrbm93bGVkZ2UgPSAkbW9kYWwuZmluZCgnI3N0dC1kZWxldGUtdHJhbnNjcmlwdC1hY2tub3dsZWRnZScpO1xuICAgICAgICB2YXIgJGFwcHJvdmUgPSAkbW9kYWwuZmluZCgnLmFwcHJvdmUuYnV0dG9uJyk7XG4gICAgICAgIHZhciBwZW5kaW5nRm9ybSA9IG51bGw7XG5cbiAgICAgICAgZnVuY3Rpb24gc2V0QXBwcm92ZUVuYWJsZWQoZW5hYmxlZCkge1xuICAgICAgICAgICAgJGFwcHJvdmUudG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWVuYWJsZWQpLnByb3AoJ2Rpc2FibGVkJywgIWVuYWJsZWQpO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gUHJlLWluaXRpYWxpemUgc28gU2VtYW50aWMgVUkgbW92ZXMgdGhlIGVsZW1lbnQgdG8gPGJvZHk+IGFuZCBoaWRlcyBpdCBwcm9wZXJseVxuICAgICAgICAkbW9kYWwubW9kYWwoe30pO1xuICAgICAgICAkYWNrbm93bGVkZ2Uub24oJ2NoYW5nZScsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHNldEFwcHJvdmVFbmFibGVkKCRhY2tub3dsZWRnZS5wcm9wKCdjaGVja2VkJykpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkZm9ybXMub24oJ3N1Ym1pdC5zdHQtZGVsZXRlLXRyYW5zY3JpcHQnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICBwZW5kaW5nRm9ybSA9IHRoaXM7XG4gICAgICAgICAgICAkKHRoaXMpLmNsb3Nlc3QoJ2RldGFpbHMnKS5wcm9wKCdvcGVuJywgZmFsc2UpO1xuICAgICAgICAgICAgJGFja25vd2xlZGdlLnByb3AoJ2NoZWNrZWQnLCBmYWxzZSk7XG4gICAgICAgICAgICBzZXRBcHByb3ZlRW5hYmxlZChmYWxzZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uQXBwcm92ZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBpZiAoISRhY2tub3dsZWRnZS5wcm9wKCdjaGVja2VkJykgfHwgcGVuZGluZ0Zvcm0gPT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICB2YXIgZm9ybSA9IHBlbmRpbmdGb3JtO1xuICAgICAgICAgICAgICAgICAgICBwZW5kaW5nRm9ybSA9IG51bGw7XG4gICAgICAgICAgICAgICAgICAgICQoJzxpbnB1dD4nLCB7IHR5cGU6ICdoaWRkZW4nLCBuYW1lOiAnY29uZmlybV9kZWxldGUnLCB2YWx1ZTogJzEnIH0pLmFwcGVuZFRvKGZvcm0pO1xuICAgICAgICAgICAgICAgICAgICAvLyBUaGUgbmF0aXZlIHN1Ym1pdCBza2lwcyB0aGlzIGhhbmRsZXIsIHNvIHRoZSBkaWFsb2cgaXMgbm90IHNob3duIGFnYWluLlxuICAgICAgICAgICAgICAgICAgICBmb3JtLnN1Ym1pdCgpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgICAgIG9uSGlkZGVuOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHBlbmRpbmdGb3JtID0gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5tb2RhbCgnc2hvdycpO1xuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MoKSB7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC10cmFuc2NyaXB0LXJvdycpLm9uKCdjbGljaycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKCQoZXZlbnQudGFyZ2V0KS5jbG9zZXN0KCdhLCBidXR0b24sIGlucHV0LCBzZWxlY3QsIHRleHRhcmVhJykubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgdmFyIGRlc3RpbmF0aW9uID0gJCh0aGlzKS5kYXRhKCd0cmFuc2NyaXB0LXVybCcpO1xuICAgICAgICAgICAgaWYgKGRlc3RpbmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgd2luZG93LmxvY2F0aW9uLmhyZWYgPSBkZXN0aW5hdGlvbjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRBY3Rpb25NZW51KCkge1xuICAgICAgICB2YXIgJG1lbnVzID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycpO1xuXG4gICAgICAgICRtZW51cy5maW5kKCcubWVudSBhLCAubWVudSBidXR0b24nKS5vbignY2xpY2suc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICQodGhpcykuY2xvc2VzdCgnZGV0YWlscycpLnJlbW92ZUF0dHIoJ29wZW4nKTtcbiAgICAgICAgfSk7XG4gICAgICAgICQoZG9jdW1lbnQpLm9uKCdjbGljay5zdHQtdHJhbnNjcmlwdC1hY3Rpb25zJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICAkbWVudXMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgaWYgKHRoaXMgIT09IGV2ZW50LnRhcmdldCAmJiAhdGhpcy5jb250YWlucyhldmVudC50YXJnZXQpKSB7XG4gICAgICAgICAgICAgICAgICAgICQodGhpcykucmVtb3ZlQXR0cignb3BlbicpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdExlZ1RhYnMoKSB7XG4gICAgICAgIHZhciB0cmFuc2NyaXB0TG9jYXRpb24gPSB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcgJiYgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LmxvY2F0aW9uO1xuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1jYWxsLWxlZy1yZXZpZXcnKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciAkcmV2aWV3ID0gJCh0aGlzKTtcbiAgICAgICAgICAgIHZhciAkdGFicyA9ICRyZXZpZXcuZmluZCgnW2RhdGEtc3R0LWNhbGwtbGVnXScpO1xuICAgICAgICAgICAgdmFyICRwYW5lbHMgPSAkcmV2aWV3LmZpbmQoJ1tkYXRhLXN0dC1jYWxsLWxlZy1wYW5lbF0nKTtcblxuICAgICAgICAgICAgZnVuY3Rpb24gcGFuZWxMZWcoJGVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gU3RyaW5nKCRlbGVtZW50LmNsb3Nlc3QoJ1tkYXRhLXN0dC1jYWxsLWxlZy1wYW5lbF0nKS5kYXRhKCdzdHQtY2FsbC1sZWctcGFuZWwnKSB8fCAnJyk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGZ1bmN0aW9uIHNob3coY2FsbExlZykge1xuICAgICAgICAgICAgICAgICR0YWJzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICB2YXIgYWN0aXZlID0gU3RyaW5nKCQodGhpcykuZGF0YSgnc3R0LWNhbGwtbGVnJykgfHwgJycpID09PSBjYWxsTGVnO1xuICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnRvZ2dsZUNsYXNzKCdhY3RpdmUnLCBhY3RpdmUpLmF0dHIoJ2FyaWEtc2VsZWN0ZWQnLCBhY3RpdmUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICAgICAkcGFuZWxzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICB2YXIgaGlkZGVuID0gU3RyaW5nKCQodGhpcykuZGF0YSgnc3R0LWNhbGwtbGVnLXBhbmVsJykgfHwgJycpICE9PSBjYWxsTGVnO1xuXG4gICAgICAgICAgICAgICAgICAgICQodGhpcykucHJvcCgnaGlkZGVuJywgaGlkZGVuKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKGhpZGRlbiAmJiB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcgJiYgdHlwZW9mIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5wYXVzZSA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LnBhdXNlKHRoaXMpO1xuICAgICAgICAgICAgICAgICAgICB9IGVsc2UgaWYgKGhpZGRlbikge1xuICAgICAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS5maW5kKCdhdWRpbycpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHZhciBhdWRpbyA9IHRoaXM7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgYXVkaW8ucGF1c2UoKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICR0YWJzLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgY2FsbExlZyA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3N0dC1jYWxsLWxlZycpIHx8ICcnKTtcblxuICAgICAgICAgICAgICAgIHNob3coY2FsbExlZyk7XG4gICAgICAgICAgICAgICAgaWYgKHRyYW5zY3JpcHRMb2NhdGlvbikge1xuICAgICAgICAgICAgICAgICAgICAvLyBBIGhlbGQgbW9tZW50IGluIHRoZSBvdGhlciBsZWcgbm8gbG9uZ2VyIGRlc2NyaWJlcyB3aGF0IGlzIG9uIHNjcmVlbi5cbiAgICAgICAgICAgICAgICAgICAgdmFyIG1vbWVudCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICAgICAgICAgICAgICB2YXIgJHN0YWdlID0gbW9tZW50LnN0YWdlID8gJHJldmlldy5maW5kKCcuc3R0LXRyYW5zY3JpcHQtdmlld1tkYXRhLXN0dC1zdGFnZT1cIicgKyBtb21lbnQuc3RhZ2UgKyAnXCJdJykgOiAkKCk7XG4gICAgICAgICAgICAgICAgICAgIHZhciBjaGFuZ2VzID0geyBsZWc6IGNhbGxMZWcgfTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKCRzdGFnZS5sZW5ndGggPiAwICYmIHBhbmVsTGVnKCRzdGFnZSkgIT09IGNhbGxMZWcpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGNoYW5nZXMuc3RhZ2UgPSAnJztcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICB0cmFuc2NyaXB0TG9jYXRpb24ud3JpdGUoY2hhbmdlcyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG5cbiAgICAgICAgICAgIC8vIFRoZSBhZGRyZXNzIHBpY2tzIHRoZSBsZWc6IHRoZSBsZWcgb2YgYSBzaGFyZWQgbW9tZW50IGZpcnN0LCB0aGVuIGFuIGV4cGxpY2l0IGxlZy5cbiAgICAgICAgICAgIGlmICh0cmFuc2NyaXB0TG9jYXRpb24pIHtcbiAgICAgICAgICAgICAgICB2YXIgaW5pdGlhbCA9IHRyYW5zY3JpcHRMb2NhdGlvbi5yZWFkKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICAgICAgICAgIHZhciAkdGFyZ2V0ID0gaW5pdGlhbC5zdGFnZSA/ICRyZXZpZXcuZmluZCgnLnN0dC10cmFuc2NyaXB0LXZpZXdbZGF0YS1zdHQtc3RhZ2U9XCInICsgaW5pdGlhbC5zdGFnZSArICdcIl0nKSA6ICQoKTtcbiAgICAgICAgICAgICAgICB2YXIgbGVnID0gJHRhcmdldC5sZW5ndGggPiAwID8gcGFuZWxMZWcoJHRhcmdldCkgOiBpbml0aWFsLmxlZztcbiAgICAgICAgICAgICAgICBpZiAobGVnICE9PSAnJyAmJiAkdGFicy5maWx0ZXIoJ1tkYXRhLXN0dC1jYWxsLWxlZz1cIicgKyBsZWcgKyAnXCJdJykubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICBzaG93KGxlZyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgLy8gT25seSBpdGVtcyB3aXRoIGEgZGF0YS10YWIgdGFyZ2V0IGFyZSBjbGllbnQtc2lkZSB0YWJzLiBUaGVcbiAgICAgICAgLy8gVHJhbnNjcmlwdHMgaXRlbSBpcyBhIHJlYWwgbGluayAoP3RhYj10cmFuc2NyaXB0cywgZnVsbCBwYWdlXG4gICAgICAgIC8vIG5hdmlnYXRpb24pIGJlY2F1c2UgaXRzIGNvbnRlbnQgaXMgb25seSByZW5kZXJlZCBzZXJ2ZXItc2lkZSB3aGVuXG4gICAgICAgIC8vIHRoYXQgdGFiIGlzIHRoZSBvbmUgYmVpbmcgbG9hZGVkIC0gc2VlIGluZGV4LnZvbHQvSW5kZXhWaWV3U2VydmljZS5cbiAgICAgICAgdmFyICR0YWJNZW51ID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tZW51Jyk7XG4gICAgICAgIHZhciAkdGFiSXRlbXMgPSAkdGFiTWVudS5maW5kKCcuaXRlbVtkYXRhLXRhYl0nKTtcbiAgICAgICAgdmFyICRzZXR0aW5nc0Zvcm0gPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LWZvcm0nKTtcbiAgICAgICAgdmFyICRtb2RlbEZvcm0gPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1vZGVsLWZvcm0nKTtcbiAgICAgICAgJHRhYkl0ZW1zLnRhYih7XG4gICAgICAgICAgICBvblZpc2libGU6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAvLyBTZW1hbnRpYyBVSSdzIHRhYiBtb2R1bGUgKDIuNC4xLCBzZWVcbiAgICAgICAgICAgICAgICAvLyBwdWJsaWMvYXNzZXRzL2pzL3ZlbmRvciBlcXVpdmFsZW50IHVuZGVyIENvcmUpIG9ubHlcbiAgICAgICAgICAgICAgICAvLyAoZGUpYWN0aXZhdGVzIG1lbnUgaXRlbXMgaXQgd2FzIGluaXRpYWxpemVkIG9uXG4gICAgICAgICAgICAgICAgLy8gKCR0YWJJdGVtcywgaS5lLiBpdGVtcyB3aXRoIGEgZGF0YS10YWIpIC0gc2VlIGl0c1xuICAgICAgICAgICAgICAgIC8vIGFjdGl2YXRlLm5hdmlnYXRpb24oKS4gVGhlIFRyYW5zY3JpcHRzIGl0ZW0gaXNcbiAgICAgICAgICAgICAgICAvLyBkZWxpYmVyYXRlbHkgb3V0c2lkZSB0aGF0IHNldCwgc28gYSBub3JtYWwgdGFiIHN3aXRjaFxuICAgICAgICAgICAgICAgIC8vIG5ldmVyIHJlbW92ZXMgaXRzICdhY3RpdmUnIGNsYXNzOiB3aXRob3V0IHRoaXMsIGxhbmRpbmdcbiAgICAgICAgICAgICAgICAvLyBvbiA/dGFiPXRyYW5zY3JpcHRzICh3aGljaCBzZXJ2ZXItcmVuZGVycyBpdCBhY3RpdmUpIGFuZFxuICAgICAgICAgICAgICAgIC8vIHRoZW4gY2xpY2tpbmcgYW55IG90aGVyIHRhYiB3b3VsZCBsZWF2ZSBUcmFuc2NyaXB0c1xuICAgICAgICAgICAgICAgIC8vIGhpZ2hsaWdodGVkIGFsb25nc2lkZSBpdC5cbiAgICAgICAgICAgICAgICAkdGFiTWVudS5maW5kKCcuaXRlbScpLm5vdCgnW2RhdGEtdGFiXScpLnJlbW92ZUNsYXNzKCdhY3RpdmUnKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgIGlmIChyZXF1ZXN0ZWRUYWIoKSAhPT0gJycgJiYgcmVxdWVzdGVkVGFiKCkgIT09ICd0cmFuc2NyaXB0cycpIHtcbiAgICAgICAgICAgIC8vICd0cmFuc2NyaXB0cycgaGFzIG5vIG1hbmFnZWQgbmF2IGl0ZW0gKG5vIGRhdGEtdGFiKSBhbnkgbW9yZTpcbiAgICAgICAgICAgIC8vIGl0cyBwYW5lbCBhbmQgaXRzIG1lbnUgbGluayBhcmUgYWxyZWFkeSByZW5kZXJlZCBzZXJ2ZXItc2lkZVxuICAgICAgICAgICAgLy8gYXMgYWN0aXZlIG9uID90YWI9dHJhbnNjcmlwdHMgKHNlZSBpbmRleC52b2x0KSwgc28gdGhlcmUgaXNcbiAgICAgICAgICAgIC8vIG5vdGhpbmcgaGVyZSBmb3IgdGhlIHRhYiBtb2R1bGUgdG8gc3dpdGNoIHRvIC0gc2tpcCB0aGUgY2FsbFxuICAgICAgICAgICAgLy8gaW5zdGVhZCBvZiByZWx5aW5nIG9uIGl0IGJlaW5nIGEgaGFybWxlc3Mgbm8tb3AuXG4gICAgICAgICAgICAkdGFiSXRlbXMudGFiKCdjaGFuZ2UgdGFiJywgcmVxdWVzdGVkVGFiKCkpO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAudWkuZHJvcGRvd24nKS5kcm9wZG93bigpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC51aS5jaGVja2JveCcpLmNoZWNrYm94KCk7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgW2RhdGEtY29udGVudF0nKS5wb3B1cCgpO1xuICAgICAgICBpbml0aWFsaXplTW9kZWxNYXJrZXRwbGFjZSgkbW9kZWxGb3JtKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHREYXRlRmlsdGVyKCk7XG4gICAgICAgIGluaXRpYWxpemVNb2R1bGVMb2dzKCk7XG4gICAgICAgIGluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyKCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdFJldGVudGlvblNsaWRlcigkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgIGluaXRpYWxpemVDb25maXJtTW9kYWwoKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHREZWxldGlvbigpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MoKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRBY3Rpb25NZW51KCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0TGVnVGFicygpO1xuXG4gICAgICAgIGlmICgkLmZuLmZvcm0gJiYgJC5mbi5mb3JtLnNldHRpbmdzICYmICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcykge1xuICAgICAgICAgICAgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzLnN0dFByb2Nlc3NpbmdSYW5nZSA9IGZ1bmN0aW9uICh2YWx1ZSwgcmFuZ2UpIHtcbiAgICAgICAgICAgICAgICB2YXIgbGltaXRzID0gU3RyaW5nKHJhbmdlIHx8ICcnKS5zcGxpdCgnLCcpLm1hcChOdW1iZXIpO1xuICAgICAgICAgICAgICAgIHZhciBudW1iZXIgPSBOdW1iZXIodmFsdWUpO1xuXG4gICAgICAgICAgICAgICAgcmV0dXJuIGxpbWl0cy5sZW5ndGggPT09IDJcbiAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKG51bWJlcilcbiAgICAgICAgICAgICAgICAgICAgJiYgbnVtYmVyID49IGxpbWl0c1swXVxuICAgICAgICAgICAgICAgICAgICAmJiBudW1iZXIgPD0gbGltaXRzWzFdO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcy5zdHRQcm9jZXNzaW5nT3ZlcmxhcCA9IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBvdmVybGFwTVMgPSBNYXRoLnJvdW5kKE51bWJlcih2YWx1ZSkgKiAxMDAwKTtcbiAgICAgICAgICAgICAgICB2YXIgZHVyYXRpb25NUyA9IE1hdGgucm91bmQoTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnW25hbWU9XCJtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWNcIl0nKS52YWwoKSkgKiAxMDAwKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBOdW1iZXIuaXNGaW5pdGUob3ZlcmxhcE1TKVxuICAgICAgICAgICAgICAgICAgICAmJiBOdW1iZXIuaXNGaW5pdGUoZHVyYXRpb25NUylcbiAgICAgICAgICAgICAgICAgICAgJiYgb3ZlcmxhcE1TID49IDBcbiAgICAgICAgICAgICAgICAgICAgJiYgb3ZlcmxhcE1TIDwgZHVyYXRpb25NUztcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMuc3R0UmV0ZW50aW9uQ29tcGF0aWJpbGl0eSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgICAgICB9O1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIHByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzID0ge1xuICAgICAgICAgICAgdGVtcGVyYXR1cmU6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndGVtcGVyYXR1cmUnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMV0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgdGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0ZW1wZXJhdHVyZV9mYWxsYmFja19zdGVwJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlX2ZhbGxiYWNrX2NvdW50OiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RlbXBlcmF0dXJlX2ZhbGxiYWNrX2NvdW50JyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2ludGVnZXJbMC4uMTBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRvcF9rOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RvcF9rJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2ludGVnZXJbMS4uMTAwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBjb21wcmVzc2lvbl9yYXRpb190aHJlc2hvbGQ6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnY29tcHJlc3Npb25fcmF0aW9fdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDEwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2xvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWy0xMCwwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2ZpcnN0X3Rva2VuX2xvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWy0xMCwwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBub19zcGVlY2hfdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ25vX3NwZWVjaF90aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMV0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ21heF9zZWdtZW50X2R1cmF0aW9uX3NlYycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMSwxMjBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHNlZ21lbnRfb3ZlcmxhcF9zZWM6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnc2VnbWVudF9vdmVybGFwX3NlYycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFtcbiAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMTBdJyB9LFxuICAgICAgICAgICAgICAgICAgICB7IHR5cGU6ICdzdHRQcm9jZXNzaW5nT3ZlcmxhcCcgfVxuICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfTtcblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMoKSB7XG4gICAgICAgICAgICB2YXIgZW5hYmxlZCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnW25hbWU9XCJ2b2ljZV9hY3Rpdml0eV9kZXRlY3Rpb25cIl0nKS5pcygnOmNoZWNrZWQnKTtcblxuICAgICAgICAgICAgJHNldHRpbmdzRm9ybS5maW5kKCcuc3R0LXZhZC1kZXBlbmRlbnQnKVxuICAgICAgICAgICAgICAgIC50b2dnbGVDbGFzcygnZGlzYWJsZWQnLCAhZW5hYmxlZClcbiAgICAgICAgICAgICAgICAuYXR0cignYXJpYS1kaXNhYmxlZCcsIGVuYWJsZWQgPyAnZmFsc2UnIDogJ3RydWUnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzZXR0aW5nc0Zvcm0uZmluZCgnW25hbWU9XCJ2b2ljZV9hY3Rpdml0eV9kZXRlY3Rpb25cIl0nKS5vbignY2hhbmdlJywgdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKTtcbiAgICAgICAgdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKCk7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1jb3B5LWJ1dHRvbicpLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciB0ZXh0ID0gJCh0aGlzKS5kYXRhKCdjbGlwYm9hcmQtdGV4dCcpIHx8ICcnO1xuICAgICAgICAgICAgdmFyICRidXR0b24gPSAkKHRoaXMpO1xuXG4gICAgICAgICAgICBpZiAobmF2aWdhdG9yLmNsaXBib2FyZCAmJiBuYXZpZ2F0b3IuY2xpcGJvYXJkLndyaXRlVGV4dCkge1xuICAgICAgICAgICAgICAgIG5hdmlnYXRvci5jbGlwYm9hcmQud3JpdGVUZXh0KHRleHQpLnRoZW4oZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAkYnV0dG9uLmFkZENsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgICAgICAgICBzZXRUaW1lb3V0KGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICRidXR0b24ucmVtb3ZlQ2xhc3MoJ3Bvc2l0aXZlJyk7XG4gICAgICAgICAgICAgICAgICAgIH0sIDkwMCk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuXG4gICAgICAgIGlmICh0eXBlb2YgRm9ybSAhPT0gJ3VuZGVmaW5lZCcgJiYgJHNldHRpbmdzRm9ybS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICBGb3JtLiRmb3JtT2JqID0gJHNldHRpbmdzRm9ybTtcbiAgICAgICAgICAgIEZvcm0udXJsID0gJHNldHRpbmdzRm9ybS5hdHRyKCdhY3Rpb24nKTtcbiAgICAgICAgICAgIEZvcm0ubWV0aG9kID0gJ1BPU1QnO1xuICAgICAgICAgICAgRm9ybS52YWxpZGF0ZVJ1bGVzID0gT2JqZWN0LmFzc2lnbih7XG4gICAgICAgICAgICAgICAgZGVmYXVsdF9sYW5ndWFnZToge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnZGVmYXVsdF9sYW5ndWFnZScsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnZW1wdHknIH1dXG4gICAgICAgICAgICAgICAgfSxcbiAgICAgICAgICAgICAgICBjZHJfaGlzdG9yeV9kYXlzOiB7XG4gICAgICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdjZHJfaGlzdG9yeV9kYXlzJyxcbiAgICAgICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzAuLjM2NV0nIH1dXG4gICAgICAgICAgICAgICAgfSxcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzOiB7XG4gICAgICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzJyxcbiAgICAgICAgICAgICAgICAgICAgcnVsZXM6IFtcbiAgICAgICAgICAgICAgICAgICAgICAgIHsgdHlwZTogJ2ludGVnZXJbMC4uMzY1XScgfSxcbiAgICAgICAgICAgICAgICAgICAgICAgIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICB0eXBlOiAnc3R0UmV0ZW50aW9uQ29tcGF0aWJpbGl0eScsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgcHJvbXB0OiB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uQ29uZmxpY3QnLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnVGhlIHJlY29yZGluZyBwcm9jZXNzaW5nIHdpbmRvdyBjYW5ub3QgZXhjZWVkIHRyYW5zY3JpcHQgcmV0ZW50aW9uLiBJbmNyZWFzZSByZXRlbnRpb24gb3IgcmVkdWNlIHRoZSBwcm9jZXNzaW5nIHdpbmRvdy4nXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBdXG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSwgcHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMpO1xuICAgICAgICAgICAgRm9ybS5jYkJlZm9yZVNlbmRGb3JtID0gZnVuY3Rpb24gKHNldHRpbmdzKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHNldHRpbmdzO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIEZvcm0uY2JBZnRlclNlbmRGb3JtID0gZnVuY3Rpb24gKCkge307XG4gICAgICAgICAgICBGb3JtLmFwaVNldHRpbmdzLmVuYWJsZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIEZvcm0uYXBpU2V0dGluZ3MuYXBpT2JqZWN0ID0gbnVsbDtcbiAgICAgICAgICAgIEZvcm0uc2VuZE9ubHlDaGFuZ2VkID0gZmFsc2U7XG4gICAgICAgICAgICBGb3JtLmVuYWJsZURpcnJpdHkgPSB0cnVlO1xuICAgICAgICAgICAgRm9ybS5pbml0aWFsaXplKCk7XG4gICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAkc2V0dGluZ3NGb3JtLmZvcm0oe1xuICAgICAgICAgICAgICAgIGZpZWxkczogT2JqZWN0LmFzc2lnbih7XG4gICAgICAgICAgICAgICAgICAgIGRlZmF1bHRfbGFuZ3VhZ2U6IFsnZW1wdHknXSxcbiAgICAgICAgICAgICAgICAgICAgY2RyX2hpc3RvcnlfZGF5czogWydpbnRlZ2VyWzAuLjM2NV0nXSxcbiAgICAgICAgICAgICAgICAgICAgdHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5czogW1xuICAgICAgICAgICAgICAgICAgICAgICAgJ2ludGVnZXJbMC4uMzY1XScsXG4gICAgICAgICAgICAgICAgICAgICAgICB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3N0dFJldGVudGlvbkNvbXBhdGliaWxpdHknLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHByb21wdDogdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbkNvbmZsaWN0JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ1RoZSByZWNvcmRpbmcgcHJvY2Vzc2luZyB3aW5kb3cgY2Fubm90IGV4Y2VlZCB0cmFuc2NyaXB0IHJldGVudGlvbi4gSW5jcmVhc2UgcmV0ZW50aW9uIG9yIHJlZHVjZSB0aGUgcHJvY2Vzc2luZyB3aW5kb3cuJ1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgXVxuICAgICAgICAgICAgICAgIH0sIHByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzKVxuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICAkbW9kZWxGb3JtLmZvcm0oe1xuICAgICAgICAgICAgZmllbGRzOiB7XG4gICAgICAgICAgICAgICAgc2VsZWN0ZWRfbW9kZWw6IFsnZW1wdHknXVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9KTtcbn0oKSk7XG4iXSwibWFwcGluZ3MiOiI7Ozs7QUFBQyxhQUFZO0VBQ1QsWUFBWTs7RUFFWixTQUFTQSxXQUFXQSxDQUFDQyxHQUFHLEVBQUVDLFFBQVEsRUFBRTtJQUNoQyxJQUFJLE9BQU9DLGVBQWUsS0FBSyxXQUFXLElBQUlBLGVBQWUsQ0FBQ0YsR0FBRyxDQUFDLEVBQUU7TUFDaEUsT0FBT0UsZUFBZSxDQUFDRixHQUFHLENBQUM7SUFDL0I7SUFFQSxPQUFPQyxRQUFRO0VBQ25CO0VBRUEsU0FBU0UsWUFBWUEsQ0FBQSxFQUFHO0lBQ3BCLElBQUlDLE1BQU0sR0FBRyxJQUFJQyxlQUFlLENBQUNDLE1BQU0sQ0FBQ0MsUUFBUSxDQUFDQyxNQUFNLENBQUM7SUFDeEQsSUFBSUMsT0FBTyxHQUFHTCxNQUFNLENBQUNNLEdBQUcsQ0FBQyxLQUFLLENBQUMsSUFBSUosTUFBTSxDQUFDQyxRQUFRLENBQUNJLElBQUksQ0FBQ0MsT0FBTyxDQUFDLElBQUksRUFBRSxFQUFFLENBQUM7SUFFekUsT0FBTyxlQUFlLENBQUNDLElBQUksQ0FBQ0osT0FBTyxDQUFDLEdBQUdBLE9BQU8sR0FBRyxFQUFFO0VBQ3ZEO0VBRUEsU0FBU0ssY0FBY0EsQ0FBQSxFQUFHO0lBQ3RCLElBQUlDLElBQUksR0FBRyxDQUFDLElB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