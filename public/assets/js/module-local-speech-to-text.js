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
      $compatibilityMessage.toggleClass('hidden', compatible).attr('aria-hidden', compatible ? 'true' : 'false');
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

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIiwibmFtZXMiOlsidHJhbnNsYXRpb24iLCJrZXkiLCJmYWxsYmFjayIsImdsb2JhbFRyYW5zbGF0ZSIsInJlcXVlc3RlZFRhYiIsInBhcmFtcyIsIlVSTFNlYXJjaFBhcmFtcyIsIndpbmRvdyIsImxvY2F0aW9uIiwic2VhcmNoIiwidGFiTmFtZSIsImdldCIsImhhc2giLCJyZXBsYWNlIiwidGVzdCIsImNhbGVuZGFyTG9jYWxlIiwiZGF5cyIsIm1vbnRocyIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiY2FsZW5kYXJUZXh0IiwiZm9ybWF0Iiwic2VwYXJhdG9yIiwiYXBwbHlMYWJlbCIsImNhbmNlbExhYmVsIiwiZnJvbUxhYmVsIiwidG9MYWJlbCIsImN1c3RvbVJhbmdlTGFiZWwiLCJkYXlzT2ZXZWVrIiwibW9udGhOYW1lcyIsImZpcnN0RGF5IiwibW9tZW50RGF0ZSIsInZhbHVlIiwiZGF0ZSIsIm1vbWVudCIsImlzVmFsaWQiLCJjbG9uZSIsImluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlciIsIiRmb3JtIiwiJCIsIiRzZWxlY3RvciIsImxlbmd0aCIsImZuIiwiZGF0ZXJhbmdlcGlja2VyIiwiJGRhdGVGcm9tIiwiZmluZCIsIiRkYXRlVG8iLCIkc2VhcmNoIiwic2VhcmNoRGVib3VuY2VUaW1lciIsInRvZGF5Iiwic3RhcnREYXRlIiwidmFsIiwiZW5kRGF0ZSIsImlzQWZ0ZXIiLCJzd2FwRGF0ZSIsInVwZGF0ZUhpZGRlbklucHV0cyIsInN0YXJ0IiwiZW5kIiwiY2FuY2VsU2VhcmNoRGVib3VuY2UiLCJjbGVhclRpbWVvdXQiLCJzdWJtaXRUcmFuc2NyaXB0U2VhcmNoIiwidHJpZ2dlciIsInJhbmdlcyIsIl9kZWZpbmVQcm9wZXJ0eSIsInN1YnRyYWN0Iiwic3RhcnRPZiIsImVuZE9mIiwiYWx3YXlzU2hvd0NhbGVuZGFycyIsImF1dG9VcGRhdGVJbnB1dCIsImxpbmtlZENhbGVuZGFycyIsIm1heERhdGUiLCJsb2NhbGUiLCJvbiIsInBpY2tlciIsImRhdGEiLCJzZXRUaW1lb3V0IiwiZXZlbnQiLCJ3aGljaCIsInByZXZlbnREZWZhdWx0IiwiU3RyaW5nIiwidHJpbSIsImluaXRpYWxpemVNb2R1bGVMb2dzIiwiJHNlZ21lbnQiLCIkcmVzdWx0cyIsIiRzdGF0ZSIsIiRyZXN1bHRDb3VudCIsIiR1cGRhdGVkQXQiLCIkbGV2ZWxGaWx0ZXIiLCIkY29tcG9uZW50RmlsdGVyIiwiZGF0YVVybCIsIiRwZXJpb2RCdXR0b25zIiwiJHJlZnJlc2hCdXR0b24iLCJjdXJyZW50UGVyaW9kIiwicGFyc2VJbnQiLCJmaWx0ZXIiLCJsb2FkZWRPbmNlIiwicmVxdWVzdCIsInJlcXVlc3RHZW5lcmF0aW9uIiwiY3VycmVudEVudHJpZXMiLCJjdXJyZW50UmVzcG9uc2UiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsImxldmVsTmFtZSIsImxldmVsIiwibGV2ZWxzIiwiZGVidWciLCJpbmZvIiwibm90aWNlIiwid2FybmluZyIsImVycm9yIiwiaXRlbSIsImNvbXBvbmVudE5hbWUiLCJjb21wb25lbnQiLCJhbGlhc2VzIiwiYXBpIiwicXVldWUiLCJzY2FubmVyIiwid29ya2VyIiwid29ya2VybG9jYWxzcGVlY2h0b3RleHRqb2JzIiwic2V0dGluZ3MiLCJ3ZWJfdWkiLCJzZXR1cCIsInN0b3JhZ2UiLCJ0cmFuc2NyaXB0Iiwic3lzdGVtIiwibG9jYWxzcGVlY2h0b3RleHRtYWluIiwibG9nZ2VyIiwibGFiZWxzIiwib3RoZXIiLCJsYWJlbCIsImZvcm1hdFRpbWVzdGFtcCIsInRpbWVzdGFtcCIsIkRhdGUiLCJpc05hTiIsImdldFRpbWUiLCJ0b0xvY2FsZVN0cmluZyIsInJlbmRlclN0YXRlIiwia2luZCIsIm1lc3NhZ2UiLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJ0ZXh0IiwiYWRkQ2xhc3MiLCJmaWx0ZXJFbnRyaWVzIiwiZW50cmllcyIsInF1ZXJ5IiwidG9Mb3dlckNhc2UiLCJlbnRyeSIsImhheXN0YWNrIiwic2V2ZXJpdHkiLCJwaWQiLCJKU09OIiwic3RyaW5naWZ5IiwiY29udGV4dCIsImpvaW4iLCJpbmRleE9mIiwicmVuZGVyRW50cmllcyIsImVtcHR5IiwiZm9yRWFjaCIsIiRjYXJkIiwiJGhlYWRlciIsIiRpZGVudGl0eSIsIiRsZXZlbCIsIiRjb21wb25lbnQiLCIkdGltZSIsInRzIiwiJG1lc3NhZ2UiLCIkZGV0YWlscyIsIiRzdW1tYXJ5IiwiZGV0YWlsVGV4dCIsInVuZGVmaW5lZCIsIk9iamVjdCIsImtleXMiLCJhcHBlbmQiLCJ1cGRhdGVDb21wb25lbnRPcHRpb25zIiwic2VsZWN0ZWQiLCJjb21wb25lbnRzIiwicHVzaCIsInNvcnQiLCJsZWZ0IiwicmlnaHQiLCJsb2NhbGVDb21wYXJlIiwidXBkYXRlTWV0YSIsImNvdW50IiwicmVzcG9uc2UiLCJmb3VuZCIsInVwZGF0ZWQiLCJnZW5lcmF0ZWRBdCIsInJlbmRlckN1cnJlbnQiLCJmaWx0ZXJlZCIsInNvdXJjZVN0YXR1cyIsIm5vdGljZXMiLCJtYWxmb3JtZWRDb3VudCIsInRydW5jYXRlZCIsInNldExvYWRpbmciLCJsb2FkTG9ncyIsImZvcmNlIiwicmVxdWVzdElkIiwiYWJvcnQiLCJjdXJyZW50UmVxdWVzdCIsImFqYXgiLCJ1cmwiLCJtZXRob2QiLCJkYXRhVHlwZSIsInBlcmlvZCIsImRvbmUiLCJBcnJheSIsImlzQXJyYXkiLCJmYWlsIiwieGhyIiwic3RhdHVzIiwiYWx3YXlzIiwiJGJ1dHRvbiIsImFkZCIsImNsb3Nlc3QiLCJoYXNDbGFzcyIsImluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyIiwiJHNldHRpbmdzRm9ybSIsIiRzbGlkZXIiLCIkaW5wdXQiLCJzbGlkZXIiLCJ2YWx1ZXMiLCJjdXJyZW50VmFsdWUiLCJpbml0aWFsSW5kZXgiLCJpbml0aWFsaXplZCIsIm1pbiIsIm1heCIsInN0ZXAiLCJzbW9vdGgiLCJhdXRvQWRqdXN0TGFiZWxzIiwiaW50ZXJwcmV0TGFiZWwiLCJvbkNoYW5nZSIsInVwZGF0ZVJldGVudGlvbkNvbmZsaWN0IiwiRm9ybSIsImNoZWNrVmFsdWVzIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIiLCJyZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nIiwicHJvY2Vzc2luZ0RheXMiLCJyZXRlbnRpb25EYXlzIiwiY29tcGF0aWJsZSIsInRvZ2dsZUNsYXNzIiwiYXR0ciIsImluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlIiwiJG1vZGVsRm9ybSIsIiRzYXZlQnV0dG9uIiwiJG1vZGVsSW5wdXRzIiwiJGNvbXBhdGliaWxpdHlNZXNzYWdlIiwiaW5pdGlhbFNlbGVjdGVkTW9kZWwiLCJzZWxlY3RlZExhbmd1YWdlIiwiJHBsYXRmb3JtQnV0dG9ucyIsIiRwbGF0Zm9ybUNoYW5nZU1lc3NhZ2UiLCJpbml0aWFsUGxhdGZvcm0iLCJzZWxlY3RlZE1vZGVsIiwic2VsZWN0ZWRNb2RlbFBsYXRmb3JtIiwidXBkYXRlUGxhdGZvcm1DaGFuZ2VTdGF0ZSIsInBsYXRmb3JtIiwiY2hhbmdlc1BsYXRmb3JtIiwic2hvd1BsYXRmb3JtIiwiJHJvd3MiLCJlYWNoIiwiYWN0aXZlIiwiY2hlY2tib3giLCJzZWxlY3RlZE1vZGVsU3VwcG9ydHNMYW5ndWFnZSIsIiRzZWxlY3RlZElucHV0Iiwic3VwcG9ydGVkTGFuZ3VhZ2VzIiwic3BsaXQiLCJtYXAiLCJsYW5ndWFnZSIsInVwZGF0ZUNvbXBhdGliaWxpdHlTdGF0ZSIsInVwZGF0ZVNhdmVCdXR0b25TdGF0ZSIsImhhc0NoYW5nZXMiLCJhY2NvcmRpb24iLCJ0YXJnZXQiLCIkcm93Iiwic2libGluZ3MiLCJpbml0aWFsaXplQ29uZmlybU1vZGFsIiwiJG1vZGFsIiwibW9kYWwiLCJzaG93Q29uZmlybSIsIm9uQ29uZmlybSIsImNsb3NhYmxlIiwib25BcHByb3ZlIiwiYmluZENvbmZpcm1hYmxlRm9ybSIsInN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiIsIiRzZWxmIiwib2ZmIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHREZWxldGlvbiIsIiRmb3JtcyIsIiRhY2tub3dsZWRnZSIsIiRhcHByb3ZlIiwicGVuZGluZ0Zvcm0iLCJzZXRBcHByb3ZlRW5hYmxlZCIsImVuYWJsZWQiLCJmb3JtIiwidHlwZSIsIm5hbWUiLCJhcHBlbmRUbyIsInN1Ym1pdCIsIm9uSGlkZGVuIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSb3dzIiwiZGVzdGluYXRpb24iLCJocmVmIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRBY3Rpb25NZW51IiwiJG1lbnVzIiwicmVtb3ZlQXR0ciIsImRvY3VtZW50IiwiY29udGFpbnMiLCJpbml0aWFsaXplVHJhbnNjcmlwdExlZ1RhYnMiLCJ0cmFuc2NyaXB0TG9jYXRpb24iLCJTdHRUcmFuc2NyaXB0VmlldyIsIiRyZXZpZXciLCIkdGFicyIsIiRwYW5lbHMiLCJwYW5lbExlZyIsIiRlbGVtZW50Iiwic2hvdyIsImNhbGxMZWciLCJoaWRkZW4iLCJwYXVzZSIsImF1ZGlvIiwicmVhZCIsIiRzdGFnZSIsInN0YWdlIiwiY2hhbmdlcyIsImxlZyIsIndyaXRlIiwiaW5pdGlhbCIsIiR0YXJnZXQiLCIkdGFiTWVudSIsIiR0YWJJdGVtcyIsInRhYiIsIm9uVmlzaWJsZSIsIm5vdCIsImRyb3Bkb3duIiwicG9wdXAiLCJydWxlcyIsInN0dFByb2Nlc3NpbmdSYW5nZSIsInJhbmdlIiwibGltaXRzIiwibnVtYmVyIiwic3R0UHJvY2Vzc2luZ092ZXJsYXAiLCJvdmVybGFwTVMiLCJNYXRoIiwicm91bmQiLCJkdXJhdGlvbk1TIiwic3R0UmV0ZW50aW9uQ29tcGF0aWJpbGl0eSIsInByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzIiwidGVtcGVyYXR1cmUiLCJpZGVudGlmaWVyIiwidGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcCIsInRlbXBlcmF0dXJlX2ZhbGxiYWNrX2NvdW50IiwidG9wX2siLCJjb21wcmVzc2lvbl9yYXRpb190aHJlc2hvbGQiLCJsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkIiwiZmlyc3RfdG9rZW5fbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZCIsIm5vX3NwZWVjaF90aHJlc2hvbGQiLCJtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWMiLCJzZWdtZW50X292ZXJsYXBfc2VjIiwidXBkYXRlVmFkRGVwZW5kZW50RmllbGRzIiwiaXMiLCJuYXZpZ2F0b3IiLCJjbGlwYm9hcmQiLCJ3cml0ZVRleHQiLCJ0aGVuIiwiJGZvcm1PYmoiLCJ2YWxpZGF0ZVJ1bGVzIiwiYXNzaWduIiwiZGVmYXVsdF9sYW5ndWFnZSIsImNkcl9oaXN0b3J5X2RheXMiLCJ0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzIiwicHJvbXB0IiwiY2JCZWZvcmVTZW5kRm9ybSIsImNiQWZ0ZXJTZW5kRm9ybSIsImFwaVNldHRpbmdzIiwiYXBpT2JqZWN0Iiwic2VuZE9ubHlDaGFuZ2VkIiwiZW5hYmxlRGlycml0eSIsImluaXRpYWxpemUiLCJmaWVsZHMiLCJzZWxlY3RlZF9tb2RlbCJdLCJzb3VyY2VzIjpbInNyYy9tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQuanMiXSwic291cmNlc0NvbnRlbnQiOlsiKGZ1bmN0aW9uICgpIHtcbiAgICAndXNlIHN0cmljdCc7XG5cbiAgICBmdW5jdGlvbiB0cmFuc2xhdGlvbihrZXksIGZhbGxiYWNrKSB7XG4gICAgICAgIGlmICh0eXBlb2YgZ2xvYmFsVHJhbnNsYXRlICE9PSAndW5kZWZpbmVkJyAmJiBnbG9iYWxUcmFuc2xhdGVba2V5XSkge1xuICAgICAgICAgICAgcmV0dXJuIGdsb2JhbFRyYW5zbGF0ZVtrZXldO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuIGZhbGxiYWNrO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHJlcXVlc3RlZFRhYigpIHtcbiAgICAgICAgdmFyIHBhcmFtcyA9IG5ldyBVUkxTZWFyY2hQYXJhbXMod2luZG93LmxvY2F0aW9uLnNlYXJjaCk7XG4gICAgICAgIHZhciB0YWJOYW1lID0gcGFyYW1zLmdldCgndGFiJykgfHwgd2luZG93LmxvY2F0aW9uLmhhc2gucmVwbGFjZSgvXiMvLCAnJyk7XG5cbiAgICAgICAgcmV0dXJuIC9eW2EtejAtOV8tXSskLy50ZXN0KHRhYk5hbWUpID8gdGFiTmFtZSA6ICcnO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGNhbGVuZGFyTG9jYWxlKCkge1xuICAgICAgICB2YXIgZGF5cyA9IFsnU3UnLCAnTW8nLCAnVHUnLCAnV2UnLCAnVGgnLCAnRnInLCAnU2EnXTtcbiAgICAgICAgdmFyIG1vbnRocyA9IFtcbiAgICAgICAgICAgICdKYW51YXJ5JyxcbiAgICAgICAgICAgICdGZWJydWFyeScsXG4gICAgICAgICAgICAnTWFyY2gnLFxuICAgICAgICAgICAgJ0FwcmlsJyxcbiAgICAgICAgICAgICdNYXknLFxuICAgICAgICAgICAgJ0p1bmUnLFxuICAgICAgICAgICAgJ0p1bHknLFxuICAgICAgICAgICAgJ0F1Z3VzdCcsXG4gICAgICAgICAgICAnU2VwdGVtYmVyJyxcbiAgICAgICAgICAgICdPY3RvYmVyJyxcbiAgICAgICAgICAgICdOb3ZlbWJlcicsXG4gICAgICAgICAgICAnRGVjZW1iZXInXG4gICAgICAgIF07XG5cbiAgICAgICAgaWYgKHR5cGVvZiBTZW1hbnRpY0xvY2FsaXphdGlvbiAhPT0gJ3VuZGVmaW5lZCcgJiYgU2VtYW50aWNMb2NhbGl6YXRpb24uY2FsZW5kYXJUZXh0KSB7XG4gICAgICAgICAgICBkYXlzID0gU2VtYW50aWNMb2NhbGl6YXRpb24uY2FsZW5kYXJUZXh0LmRheXMgfHwgZGF5cztcbiAgICAgICAgICAgIG1vbnRocyA9IFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dC5tb250aHMgfHwgbW9udGhzO1xuICAgICAgICB9XG5cbiAgICAgICAgcmV0dXJuIHtcbiAgICAgICAgICAgIGZvcm1hdDogJ0REL01NL1lZWVknLFxuICAgICAgICAgICAgc2VwYXJhdG9yOiAnIC0gJyxcbiAgICAgICAgICAgIGFwcGx5TGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfQXBwbHlCdG4nLCAnQXBwbHknKSxcbiAgICAgICAgICAgIGNhbmNlbExhYmVsOiB0cmFuc2xhdGlvbignY2FsX0NhbmNlbEJ0bicsICdDYW5jZWwnKSxcbiAgICAgICAgICAgIGZyb21MYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9mcm9tJywgJ0Zyb20nKSxcbiAgICAgICAgICAgIHRvTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfdG8nLCAnVG8nKSxcbiAgICAgICAgICAgIGN1c3RvbVJhbmdlTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfQ3VzdG9tUGVyaW9kJywgJ0N1c3RvbSByYW5nZScpLFxuICAgICAgICAgICAgZGF5c09mV2VlazogZGF5cyxcbiAgICAgICAgICAgIG1vbnRoTmFtZXM6IG1vbnRocyxcbiAgICAgICAgICAgIGZpcnN0RGF5OiAxXG4gICAgICAgIH07XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gbW9tZW50RGF0ZSh2YWx1ZSwgZmFsbGJhY2spIHtcbiAgICAgICAgdmFyIGRhdGUgPSBtb21lbnQodmFsdWUsICdZWVlZLU1NLUREJywgdHJ1ZSk7XG5cbiAgICAgICAgcmV0dXJuIGRhdGUuaXNWYWxpZCgpID8gZGF0ZSA6IGZhbGxiYWNrLmNsb25lKCk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHREYXRlRmlsdGVyKCkge1xuICAgICAgICB2YXIgJGZvcm0gPSAkKCcjc3R0LXRyYW5zY3JpcHRzLWZpbHRlci1mb3JtJyk7XG4gICAgICAgIHZhciAkc2VsZWN0b3IgPSAkKCcjc3R0LXRyYW5zY3JpcHRzLWRhdGUtcmFuZ2Utc2VsZWN0b3InKTtcblxuICAgICAgICBpZiAoJGZvcm0ubGVuZ3RoID09PSAwIHx8ICRzZWxlY3Rvci5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICBpZiAodHlwZW9mIG1vbWVudCA9PT0gJ3VuZGVmaW5lZCcgfHwgdHlwZW9mICQuZm4uZGF0ZXJhbmdlcGlja2VyID09PSAndW5kZWZpbmVkJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyICRkYXRlRnJvbSA9ICRmb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJkYXRlX2Zyb21cIl0nKTtcbiAgICAgICAgdmFyICRkYXRlVG8gPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiZGF0ZV90b1wiXScpO1xuICAgICAgICB2YXIgJHNlYXJjaCA9ICRmb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJzZWFyY2hcIl0nKTtcbiAgICAgICAgdmFyIHNlYXJjaERlYm91bmNlVGltZXIgPSBudWxsO1xuICAgICAgICB2YXIgdG9kYXkgPSBtb21lbnQoKTtcbiAgICAgICAgdmFyIHN0YXJ0RGF0ZSA9IG1vbWVudERhdGUoJGRhdGVGcm9tLnZhbCgpLCB0b2RheSk7XG4gICAgICAgIHZhciBlbmREYXRlID0gbW9tZW50RGF0ZSgkZGF0ZVRvLnZhbCgpLCBzdGFydERhdGUpO1xuXG4gICAgICAgIGlmIChzdGFydERhdGUuaXNBZnRlcihlbmREYXRlKSkge1xuICAgICAgICAgICAgdmFyIHN3YXBEYXRlID0gc3RhcnREYXRlO1xuICAgICAgICAgICAgc3RhcnREYXRlID0gZW5kRGF0ZTtcbiAgICAgICAgICAgIGVuZERhdGUgPSBzd2FwRGF0ZTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZUhpZGRlbklucHV0cyhzdGFydCwgZW5kKSB7XG4gICAgICAgICAgICAkZGF0ZUZyb20udmFsKHN0YXJ0LmZvcm1hdCgnWVlZWS1NTS1ERCcpKTtcbiAgICAgICAgICAgICRkYXRlVG8udmFsKGVuZC5mb3JtYXQoJ1lZWVktTU0tREQnKSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjYW5jZWxTZWFyY2hEZWJvdW5jZSgpIHtcbiAgICAgICAgICAgIGlmIChzZWFyY2hEZWJvdW5jZVRpbWVyICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgd2luZG93LmNsZWFyVGltZW91dChzZWFyY2hEZWJvdW5jZVRpbWVyKTtcbiAgICAgICAgICAgICAgICBzZWFyY2hEZWJvdW5jZVRpbWVyID0gbnVsbDtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKSB7XG4gICAgICAgICAgICBjYW5jZWxTZWFyY2hEZWJvdW5jZSgpO1xuICAgICAgICAgICAgJGZvcm0udHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgIH1cblxuICAgICAgICB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnREYXRlLCBlbmREYXRlKTtcbiAgICAgICAgJHNlbGVjdG9yLmRhdGVyYW5nZXBpY2tlcih7XG4gICAgICAgICAgICByYW5nZXM6IHtcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9Ub2RheScsICdUb2RheScpXTogW21vbWVudCgpLCBtb21lbnQoKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfWWVzdGVyZGF5JywgJ1llc3RlcmRheScpXTogW21vbWVudCgpLnN1YnRyYWN0KDEsICdkYXlzJyksIG1vbWVudCgpLnN1YnRyYWN0KDEsICdkYXlzJyldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX0xhc3RXZWVrJywgJ0xhc3QgNyBkYXlzJyldOiBbbW9tZW50KCkuc3VidHJhY3QoNiwgJ2RheXMnKSwgbW9tZW50KCldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX0xhc3QzMERheXMnLCAnTGFzdCAzMCBkYXlzJyldOiBbbW9tZW50KCkuc3VidHJhY3QoMjksICdkYXlzJyksIG1vbWVudCgpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9UaGlzTW9udGgnLCAnVGhpcyBtb250aCcpXTogW21vbWVudCgpLnN0YXJ0T2YoJ21vbnRoJyksIG1vbWVudCgpLmVuZE9mKCdtb250aCcpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0TW9udGgnLCAnTGFzdCBtb250aCcpXTogW21vbWVudCgpLnN1YnRyYWN0KDEsICdtb250aCcpLnN0YXJ0T2YoJ21vbnRoJyksIG1vbWVudCgpLnN1YnRyYWN0KDEsICdtb250aCcpLmVuZE9mKCdtb250aCcpXVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIGFsd2F5c1Nob3dDYWxlbmRhcnM6IHRydWUsXG4gICAgICAgICAgICBhdXRvVXBkYXRlSW5wdXQ6IHRydWUsXG4gICAgICAgICAgICBsaW5rZWRDYWxlbmRhcnM6IHRydWUsXG4gICAgICAgICAgICBtYXhEYXRlOiBtb21lbnQoKSxcbiAgICAgICAgICAgIHN0YXJ0RGF0ZTogc3RhcnREYXRlLnN0YXJ0T2YoJ2RheScpLFxuICAgICAgICAgICAgZW5kRGF0ZTogZW5kRGF0ZS5lbmRPZignZGF5JyksXG4gICAgICAgICAgICBsb2NhbGU6IGNhbGVuZGFyTG9jYWxlKClcbiAgICAgICAgfSwgZnVuY3Rpb24gKHN0YXJ0LCBlbmQpIHtcbiAgICAgICAgICAgIHVwZGF0ZUhpZGRlbklucHV0cyhzdGFydCwgZW5kKTtcbiAgICAgICAgICAgICRmb3JtLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkZm9ybS5vbignc3VibWl0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgIHZhciBwaWNrZXIgPSAkc2VsZWN0b3IuZGF0YSgnZGF0ZXJhbmdlcGlja2VyJyk7XG4gICAgICAgICAgICBpZiAocGlja2VyICYmIHBpY2tlci5zdGFydERhdGUgJiYgcGlja2VyLmVuZERhdGUpIHtcbiAgICAgICAgICAgICAgICB1cGRhdGVIaWRkZW5JbnB1dHMocGlja2VyLnN0YXJ0RGF0ZSwgcGlja2VyLmVuZERhdGUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNlYXJjaC5vbignaW5wdXQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBjYW5jZWxTZWFyY2hEZWJvdW5jZSgpO1xuICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IHdpbmRvdy5zZXRUaW1lb3V0KGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBzZWFyY2hEZWJvdW5jZVRpbWVyID0gbnVsbDtcbiAgICAgICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgICAgIH0sIDQwMCk7XG4gICAgICAgIH0pO1xuICAgICAgICAkc2VhcmNoLm9uKCdrZXlkb3duJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBpZiAoZXZlbnQud2hpY2ggIT09IDEzKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgc3VibWl0VHJhbnNjcmlwdFNlYXJjaCgpO1xuICAgICAgICB9KTtcbiAgICAgICAgJHNlYXJjaC5vbignc2VhcmNoJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKFN0cmluZygkc2VhcmNoLnZhbCgpIHx8ICcnKS50cmltKCkgPT09ICcnKSB7XG4gICAgICAgICAgICAgICAgc3VibWl0VHJhbnNjcmlwdFNlYXJjaCgpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplTW9kdWxlTG9ncygpIHtcbiAgICAgICAgdmFyICRmb3JtID0gJCgnI3N0dC1tb2R1bGUtbG9ncy1mb3JtJyk7XG4gICAgICAgIHZhciAkc2VnbWVudCA9ICQoJyNzeXN0ZW0tbG9ncy1zZWdtZW50Jyk7XG4gICAgICAgIHZhciAkcmVzdWx0cyA9ICQoJyNzdHQtbG9nLXJlc3VsdHMnKTtcbiAgICAgICAgdmFyICRzdGF0ZSA9ICQoJyNzdHQtbG9nLXN0YXRlJyk7XG4gICAgICAgIHZhciAkcmVzdWx0Q291bnQgPSAkKCcjc3R0LWxvZy1yZXN1bHQtY291bnQnKTtcbiAgICAgICAgdmFyICR1cGRhdGVkQXQgPSAkKCcjc3R0LWxvZy11cGRhdGVkLWF0Jyk7XG4gICAgICAgIHZhciAkbGV2ZWxGaWx0ZXIgPSAkKCcjc3R0LWxvZy1sZXZlbC1maWx0ZXInKTtcbiAgICAgICAgdmFyICRjb21wb25lbnRGaWx0ZXIgPSAkKCcjc3R0LWxvZy1jb21wb25lbnQtZmlsdGVyJyk7XG4gICAgICAgIHZhciAkc2VhcmNoID0gJCgnI3N0dC1sb2ctc2VhcmNoJyk7XG5cbiAgICAgICAgaWYgKCRmb3JtLmxlbmd0aCA9PT0gMCB8fCAkcmVzdWx0cy5sZW5ndGggPT09IDAgfHwgJHN0YXRlLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIGRhdGFVcmwgPSAkZm9ybS5kYXRhKCd1cmwnKTtcbiAgICAgICAgdmFyICRwZXJpb2RCdXR0b25zID0gJGZvcm0uZmluZCgnLnBlcmlvZC1idG4nKTtcbiAgICAgICAgdmFyICRyZWZyZXNoQnV0dG9uID0gJGZvcm0uZmluZCgnLnN0dC1sb2ctcmVmcmVzaCcpO1xuICAgICAgICB2YXIgY3VycmVudFBlcmlvZCA9IHBhcnNlSW50KCRwZXJpb2RCdXR0b25zLmZpbHRlcignLmFjdGl2ZScpLmRhdGEoJ3BlcmlvZCcpLCAxMCk7XG4gICAgICAgIHZhciBsb2FkZWRPbmNlID0gZmFsc2U7XG4gICAgICAgIHZhciByZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgdmFyIHJlcXVlc3RHZW5lcmF0aW9uID0gMDtcbiAgICAgICAgdmFyIGN1cnJlbnRFbnRyaWVzID0gW107XG4gICAgICAgIHZhciBjdXJyZW50UmVzcG9uc2UgPSBudWxsO1xuXG4gICAgICAgIGlmICghTnVtYmVyLmlzRmluaXRlKGN1cnJlbnRQZXJpb2QpKSB7XG4gICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gMzYwMDtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGxldmVsTmFtZShsZXZlbCkge1xuICAgICAgICAgICAgdmFyIGxldmVscyA9IHtcbiAgICAgICAgICAgICAgICBkZWJ1ZzogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxEZWJ1ZycsICdEZWJ1ZyddLFxuICAgICAgICAgICAgICAgIGluZm86IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsSW5mbycsICdJbmZvJ10sXG4gICAgICAgICAgICAgICAgbm90aWNlOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbE5vdGljZScsICdOb3RpY2UnXSxcbiAgICAgICAgICAgICAgICB3YXJuaW5nOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbFdhcm5pbmcnLCAnV2FybmluZyddLFxuICAgICAgICAgICAgICAgIGVycm9yOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbEVycm9yJywgJ0Vycm9yJ11cbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICB2YXIgaXRlbSA9IGxldmVsc1tsZXZlbF0gfHwgbGV2ZWxzLmluZm87XG5cbiAgICAgICAgICAgIHJldHVybiB0cmFuc2xhdGlvbihpdGVtWzBdLCBpdGVtWzFdKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGNvbXBvbmVudE5hbWUoY29tcG9uZW50KSB7XG4gICAgICAgICAgICB2YXIgYWxpYXNlcyA9IHtcbiAgICAgICAgICAgICAgICBhcGk6ICdhcGknLFxuICAgICAgICAgICAgICAgIHF1ZXVlOiAncXVldWUnLFxuICAgICAgICAgICAgICAgIHNjYW5uZXI6ICdzY2FubmVyJyxcbiAgICAgICAgICAgICAgICB3b3JrZXI6ICd3b3JrZXInLFxuICAgICAgICAgICAgICAgIHdvcmtlcmxvY2Fsc3BlZWNodG90ZXh0am9iczogJ3dvcmtlcicsXG4gICAgICAgICAgICAgICAgc2V0dGluZ3M6ICdzZXR0aW5ncycsXG4gICAgICAgICAgICAgICAgd2ViX3VpOiAnc2V0dGluZ3MnLFxuICAgICAgICAgICAgICAgIHNldHVwOiAnc2V0dXAnLFxuICAgICAgICAgICAgICAgIHN0b3JhZ2U6ICdzdG9yYWdlJyxcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0OiAndHJhbnNjcmlwdCcsXG4gICAgICAgICAgICAgICAgc3lzdGVtOiAnc3lzdGVtJyxcbiAgICAgICAgICAgICAgICBsb2NhbHNwZWVjaHRvdGV4dG1haW46ICdzeXN0ZW0nLFxuICAgICAgICAgICAgICAgIGxvZ2dlcjogJ3N5c3RlbSdcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICB2YXIgbGFiZWxzID0ge1xuICAgICAgICAgICAgICAgIGFwaTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50QXBpJywgJ0FQSSddLFxuICAgICAgICAgICAgICAgIHF1ZXVlOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRRdWV1ZScsICdRdWV1ZSddLFxuICAgICAgICAgICAgICAgIHNjYW5uZXI6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNjYW5uZXInLCAnUmVjb3JkaW5nIHNjYW5uZXInXSxcbiAgICAgICAgICAgICAgICB3b3JrZXI6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFdvcmtlcicsICdXb3JrZXInXSxcbiAgICAgICAgICAgICAgICBzZXR0aW5nczogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U2V0dGluZ3MnLCAnU2V0dGluZ3MnXSxcbiAgICAgICAgICAgICAgICBzZXR1cDogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U2V0dXAnLCAnU2V0dXAnXSxcbiAgICAgICAgICAgICAgICBzdG9yYWdlOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTdG9yYWdlJywgJ1N0b3JhZ2UnXSxcbiAgICAgICAgICAgICAgICB0cmFuc2NyaXB0OiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRUcmFuc2NyaXB0JywgJ1RyYW5zY3JpcHRzJ10sXG4gICAgICAgICAgICAgICAgc3lzdGVtOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTeXN0ZW0nLCAnU3lzdGVtJ10sXG4gICAgICAgICAgICAgICAgb3RoZXI6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudE90aGVyJywgJ090aGVyJ11cbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICB2YXIgbGFiZWwgPSBsYWJlbHNbYWxpYXNlc1tjb21wb25lbnRdIHx8ICdvdGhlciddO1xuXG4gICAgICAgICAgICByZXR1cm4gdHJhbnNsYXRpb24obGFiZWxbMF0sIGxhYmVsWzFdKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGZvcm1hdFRpbWVzdGFtcCh0aW1lc3RhbXApIHtcbiAgICAgICAgICAgIHZhciBkYXRlID0gbmV3IERhdGUodGltZXN0YW1wKTtcbiAgICAgICAgICAgIGlmIChOdW1iZXIuaXNOYU4oZGF0ZS5nZXRUaW1lKCkpKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRpbWVzdGFtcCB8fCAn4oCUJztcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgcmV0dXJuIGRhdGUudG9Mb2NhbGVTdHJpbmcoKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlclN0YXRlKGtpbmQsIG1lc3NhZ2UpIHtcbiAgICAgICAgICAgIGlmICghbWVzc2FnZSkge1xuICAgICAgICAgICAgICAgICRzdGF0ZS5wcm9wKCdoaWRkZW4nLCB0cnVlKS5yZW1vdmVDbGFzcygnaW5mbyB3YXJuaW5nIGVycm9yJykudGV4dCgnJyk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAkc3RhdGVcbiAgICAgICAgICAgICAgICAucmVtb3ZlQ2xhc3MoJ2luZm8gd2FybmluZyBlcnJvcicpXG4gICAgICAgICAgICAgICAgLmFkZENsYXNzKGtpbmQgfHwgJ2luZm8nKVxuICAgICAgICAgICAgICAgIC50ZXh0KG1lc3NhZ2UpXG4gICAgICAgICAgICAgICAgLnByb3AoJ2hpZGRlbicsIGZhbHNlKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGZpbHRlckVudHJpZXMoZW50cmllcykge1xuICAgICAgICAgICAgdmFyIGxldmVsID0gU3RyaW5nKCRsZXZlbEZpbHRlci52YWwoKSB8fCAnJyk7XG4gICAgICAgICAgICB2YXIgY29tcG9uZW50ID0gU3RyaW5nKCRjb21wb25lbnRGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIHF1ZXJ5ID0gU3RyaW5nKCRzZWFyY2gudmFsKCkgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpO1xuXG4gICAgICAgICAgICByZXR1cm4gZW50cmllcy5maWx0ZXIoZnVuY3Rpb24gKGVudHJ5KSB7XG4gICAgICAgICAgICAgICAgaWYgKGxldmVsICYmIGVudHJ5LmxldmVsICE9PSBsZXZlbCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmIChjb21wb25lbnQgJiYgZW50cnkuY29tcG9uZW50ICE9PSBjb21wb25lbnQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoIXF1ZXJ5KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgIHZhciBoYXlzdGFjayA9IFtcbiAgICAgICAgICAgICAgICAgICAgZW50cnkubWVzc2FnZSxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuY29tcG9uZW50LFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5ldmVudCxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuc2V2ZXJpdHksXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LnBpZCxcbiAgICAgICAgICAgICAgICAgICAgSlNPTi5zdHJpbmdpZnkoZW50cnkuY29udGV4dCB8fCB7fSlcbiAgICAgICAgICAgICAgICBdLmpvaW4oJyAnKS50b0xvd2VyQ2FzZSgpO1xuXG4gICAgICAgICAgICAgICAgcmV0dXJuIGhheXN0YWNrLmluZGV4T2YocXVlcnkpICE9PSAtMTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyRW50cmllcyhlbnRyaWVzKSB7XG4gICAgICAgICAgICAkcmVzdWx0cy5lbXB0eSgpO1xuICAgICAgICAgICAgZW50cmllcy5mb3JFYWNoKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIHZhciAkY2FyZCA9ICQoJzxhcnRpY2xlPicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5IHN0dC1sb2ctbGV2ZWwtJyArIGVudHJ5LmxldmVsKTtcbiAgICAgICAgICAgICAgICB2YXIgJGhlYWRlciA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZW50cnktaGVhZGVyJyk7XG4gICAgICAgICAgICAgICAgdmFyICRpZGVudGl0eSA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZW50cnktaWRlbnRpdHknKTtcbiAgICAgICAgICAgICAgICB2YXIgJGxldmVsID0gJCgnPHNwYW4+JykuYWRkQ2xhc3MoJ3VpIHRpbnkgbGFiZWwgc3R0LWxvZy1sZXZlbC1sYWJlbCcpLnRleHQobGV2ZWxOYW1lKGVudHJ5LmxldmVsKSk7XG4gICAgICAgICAgICAgICAgdmFyICRjb21wb25lbnQgPSAkKCc8c3Bhbj4nKS5hZGRDbGFzcygnc3R0LWxvZy1jb21wb25lbnQnKS50ZXh0KGNvbXBvbmVudE5hbWUoZW50cnkuY29tcG9uZW50KSk7XG4gICAgICAgICAgICAgICAgdmFyICR0aW1lID0gJCgnPHRpbWU+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctdGltZScpLnRleHQoZm9ybWF0VGltZXN0YW1wKGVudHJ5LnRzKSk7XG4gICAgICAgICAgICAgICAgdmFyICRtZXNzYWdlID0gJCgnPGRpdj4nKS5hZGRDbGFzcygnc3R0LWxvZy1tZXNzYWdlJykudGV4dChlbnRyeS5tZXNzYWdlIHx8ICfigJQnKTtcbiAgICAgICAgICAgICAgICB2YXIgJGRldGFpbHMgPSAkKCc8ZGV0YWlscz4nKS5hZGRDbGFzcygnc3R0LWxvZy1kZXRhaWxzJyk7XG4gICAgICAgICAgICAgICAgdmFyICRzdW1tYXJ5ID0gJCgnPHN1bW1hcnk+JykudGV4dCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVGVjaG5pY2FsRGV0YWlscycsXG4gICAgICAgICAgICAgICAgICAgICdUZWNobmljYWwgZGV0YWlscydcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICB2YXIgZGV0YWlsVGV4dCA9IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0V2ZW50JywgJ0V2ZW50JylcbiAgICAgICAgICAgICAgICAgICAgKyAnOiAnICsgKGVudHJ5LmV2ZW50IHx8ICfigJQnKTtcbiAgICAgICAgICAgICAgICBkZXRhaWxUZXh0ICs9ICdcXG5zZXZlcml0eTogJyArIChlbnRyeS5zZXZlcml0eSB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxucGlkOiAnICsgKGVudHJ5LnBpZCA9PT0gdW5kZWZpbmVkID8gJ+KAlCcgOiBlbnRyeS5waWQpO1xuICAgICAgICAgICAgICAgIGlmIChlbnRyeS5jb250ZXh0ICYmIE9iamVjdC5rZXlzKGVudHJ5LmNvbnRleHQpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxuJyArIEpTT04uc3RyaW5naWZ5KGVudHJ5LmNvbnRleHQsIG51bGwsIDIpO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgICRpZGVudGl0eS5hcHBlbmQoJGxldmVsLCAkY29tcG9uZW50KTtcbiAgICAgICAgICAgICAgICAkaGVhZGVyLmFwcGVuZCgkaWRlbnRpdHksICR0aW1lKTtcbiAgICAgICAgICAgICAgICAkZGV0YWlscy5hcHBlbmQoJHN1bW1hcnksICQoJzxwcmU+JykudGV4dChkZXRhaWxUZXh0KSk7XG4gICAgICAgICAgICAgICAgJGNhcmQuYXBwZW5kKCRoZWFkZXIsICRtZXNzYWdlLCAkZGV0YWlscyk7XG4gICAgICAgICAgICAgICAgJHJlc3VsdHMuYXBwZW5kKCRjYXJkKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlQ29tcG9uZW50T3B0aW9ucyhlbnRyaWVzKSB7XG4gICAgICAgICAgICB2YXIgc2VsZWN0ZWQgPSBTdHJpbmcoJGNvbXBvbmVudEZpbHRlci52YWwoKSB8fCAnJyk7XG4gICAgICAgICAgICB2YXIgY29tcG9uZW50cyA9IFtdO1xuICAgICAgICAgICAgZW50cmllcy5mb3JFYWNoKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIGlmIChlbnRyeS5jb21wb25lbnQgJiYgY29tcG9uZW50cy5pbmRleE9mKGVudHJ5LmNvbXBvbmVudCkgPT09IC0xKSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbXBvbmVudHMucHVzaChlbnRyeS5jb21wb25lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgY29tcG9uZW50cy5zb3J0KGZ1bmN0aW9uIChsZWZ0LCByaWdodCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBjb21wb25lbnROYW1lKGxlZnQpLmxvY2FsZUNvbXBhcmUoY29tcG9uZW50TmFtZShyaWdodCkpO1xuICAgICAgICAgICAgfSk7XG5cbiAgICAgICAgICAgICRjb21wb25lbnRGaWx0ZXIuZW1wdHkoKS5hcHBlbmQoXG4gICAgICAgICAgICAgICAgJCgnPG9wdGlvbj4nKS52YWwoJycpLnRleHQodHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0NvbXBvbmVudEFsbCcsXG4gICAgICAgICAgICAgICAgICAgICdBbGwgY29tcG9uZW50cydcbiAgICAgICAgICAgICAgICApKVxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIGNvbXBvbmVudHMuZm9yRWFjaChmdW5jdGlvbiAoY29tcG9uZW50KSB7XG4gICAgICAgICAgICAgICAgJGNvbXBvbmVudEZpbHRlci5hcHBlbmQoJCgnPG9wdGlvbj4nKS52YWwoY29tcG9uZW50KS50ZXh0KGNvbXBvbmVudE5hbWUoY29tcG9uZW50KSkpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBpZiAoc2VsZWN0ZWQgJiYgY29tcG9uZW50cy5pbmRleE9mKHNlbGVjdGVkKSAhPT0gLTEpIHtcbiAgICAgICAgICAgICAgICAkY29tcG9uZW50RmlsdGVyLnZhbChzZWxlY3RlZCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVNZXRhKGNvdW50LCByZXNwb25zZSkge1xuICAgICAgICAgICAgdmFyIGZvdW5kID0gdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRm91bmQnLCAnJWNvdW50JSBldmVudHMnKVxuICAgICAgICAgICAgICAgIC5yZXBsYWNlKCclY291bnQlJywgY291bnQpO1xuICAgICAgICAgICAgdmFyIHVwZGF0ZWQgPSB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NVcGRhdGVkJywgJ1VwZGF0ZWQ6ICV0aW1lJScpXG4gICAgICAgICAgICAgICAgLnJlcGxhY2UoJyV0aW1lJScsIHJlc3BvbnNlICYmIHJlc3BvbnNlLmdlbmVyYXRlZEF0XG4gICAgICAgICAgICAgICAgICAgID8gcmVzcG9uc2UuZ2VuZXJhdGVkQXRcbiAgICAgICAgICAgICAgICAgICAgOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NOZXZlcicsICduZXZlcicpKTtcbiAgICAgICAgICAgICRyZXN1bHRDb3VudC50ZXh0KGZvdW5kKTtcbiAgICAgICAgICAgICR1cGRhdGVkQXQudGV4dCh1cGRhdGVkKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlckN1cnJlbnQoKSB7XG4gICAgICAgICAgICBpZiAoIWN1cnJlbnRSZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHZhciBmaWx0ZXJlZCA9IGZpbHRlckVudHJpZXMoY3VycmVudEVudHJpZXMpO1xuICAgICAgICAgICAgcmVuZGVyRW50cmllcyhmaWx0ZXJlZCk7XG4gICAgICAgICAgICB1cGRhdGVNZXRhKGZpbHRlcmVkLmxlbmd0aCwgY3VycmVudFJlc3BvbnNlKTtcblxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdtaXNzaW5nJykge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc01pc3NpbmcnLFxuICAgICAgICAgICAgICAgICAgICAnVGhlIG1vZHVsZSBqb3VybmFsIGhhcyBub3QgYmVlbiBjcmVhdGVkIHlldC4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICd1bmF2YWlsYWJsZScpIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnZXJyb3InLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVW5hdmFpbGFibGUnLFxuICAgICAgICAgICAgICAgICAgICAnVGhlIG1vZHVsZSBqb3VybmFsIGlzIHVuYXZhaWxhYmxlLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnNvdXJjZVN0YXR1cyA9PT0gJ2NvcnJ1cHQnKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0NvcnJ1cHQnLFxuICAgICAgICAgICAgICAgICAgICAnVGhlIGpvdXJuYWwgY29udGFpbnMgbm8gcmVhZGFibGUgZXZlbnRzLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudEVudHJpZXMubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2luZm8nLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRW1wdHknLFxuICAgICAgICAgICAgICAgICAgICAnTm8gbW9kdWxlIGV2ZW50cyB3ZXJlIGZvdW5kIGZvciB0aGlzIHBlcmlvZC4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGZpbHRlcmVkLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0ZpbHRlcmVkRW1wdHknLFxuICAgICAgICAgICAgICAgICAgICAnTm8gZXZlbnRzIG1hdGNoIHRoZSBzZWxlY3RlZCBmaWx0ZXJzLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHZhciBub3RpY2VzID0gW107XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnNvdXJjZVN0YXR1cyA9PT0gJ3BhcnRpYWwnKSB7XG4gICAgICAgICAgICAgICAgbm90aWNlcy5wdXNoKHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NQYXJ0aWFsJyxcbiAgICAgICAgICAgICAgICAgICAgJyVjb3VudCUgZGFtYWdlZCBqb3VybmFsIGxpbmVzIHdlcmUgc2tpcHBlZC4nXG4gICAgICAgICAgICAgICAgKS5yZXBsYWNlKCclY291bnQlJywgY3VycmVudFJlc3BvbnNlLm1hbGZvcm1lZENvdW50IHx8IDApKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2UudHJ1bmNhdGVkKSB7XG4gICAgICAgICAgICAgICAgbm90aWNlcy5wdXNoKHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NUcnVuY2F0ZWQnLFxuICAgICAgICAgICAgICAgICAgICAnT25seSB0aGUgbmV3ZXN0IDEwMDAgZXZlbnRzIGFyZSBzaG93bi4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZW5kZXJTdGF0ZShub3RpY2VzLmxlbmd0aCA+IDAgPyAnd2FybmluZycgOiAnJywgbm90aWNlcy5qb2luKCcgJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2V0TG9hZGluZygpIHtcbiAgICAgICAgICAgICRzZWdtZW50LmFkZENsYXNzKCdzdHQtbG9ncy1sb2FkaW5nJyk7XG4gICAgICAgICAgICAkcmVzdWx0cy5lbXB0eSgpO1xuICAgICAgICAgICAgdXBkYXRlTWV0YSgwLCBudWxsKTtcbiAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzTG9hZGluZycsXG4gICAgICAgICAgICAgICAgJ0xvYWRpbmcgbW9kdWxlIGV2ZW50cy4uLidcbiAgICAgICAgICAgICkpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbG9hZExvZ3MoZm9yY2UpIHtcbiAgICAgICAgICAgIGlmICghZm9yY2UgJiYgbG9hZGVkT25jZSkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICghZGF0YVVybCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlcXVlc3RHZW5lcmF0aW9uICs9IDE7XG4gICAgICAgICAgICB2YXIgcmVxdWVzdElkID0gcmVxdWVzdEdlbmVyYXRpb247XG4gICAgICAgICAgICBpZiAocmVxdWVzdCAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHJlcXVlc3QuYWJvcnQoKTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgc2V0TG9hZGluZygpO1xuICAgICAgICAgICAgJHJlZnJlc2hCdXR0b24uYWRkQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQnKTtcbiAgICAgICAgICAgIHZhciBjdXJyZW50UmVxdWVzdCA9ICQuYWpheCh7XG4gICAgICAgICAgICAgICAgdXJsOiBkYXRhVXJsLFxuICAgICAgICAgICAgICAgIG1ldGhvZDogJ0dFVCcsXG4gICAgICAgICAgICAgICAgZGF0YVR5cGU6ICdqc29uJyxcbiAgICAgICAgICAgICAgICBkYXRhOiB7XG4gICAgICAgICAgICAgICAgICAgIHBlcmlvZDogY3VycmVudFBlcmlvZFxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLmRvbmUoZnVuY3Rpb24gKHJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgaWYgKHJlcXVlc3RJZCAhPT0gcmVxdWVzdEdlbmVyYXRpb24pIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjdXJyZW50UmVzcG9uc2UgPSByZXNwb25zZSB8fCB7fTtcbiAgICAgICAgICAgICAgICBjdXJyZW50RW50cmllcyA9IEFycmF5LmlzQXJyYXkoY3VycmVudFJlc3BvbnNlLmVudHJpZXMpID8gY3VycmVudFJlc3BvbnNlLmVudHJpZXMgOiBbXTtcbiAgICAgICAgICAgICAgICB1cGRhdGVDb21wb25lbnRPcHRpb25zKGN1cnJlbnRFbnRyaWVzKTtcbiAgICAgICAgICAgICAgICByZW5kZXJDdXJyZW50KCk7XG4gICAgICAgICAgICAgICAgbG9hZGVkT25jZSA9IHRydWU7XG4gICAgICAgICAgICB9KS5mYWlsKGZ1bmN0aW9uICh4aHIsIHN0YXR1cykge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uIHx8IHN0YXR1cyA9PT0gJ2Fib3J0Jykge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGN1cnJlbnRSZXNwb25zZSA9IG51bGw7XG4gICAgICAgICAgICAgICAgY3VycmVudEVudHJpZXMgPSBbXTtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnZXJyb3InLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRXJyb3InLFxuICAgICAgICAgICAgICAgICAgICAnVW5hYmxlIHRvIGxvYWQgbW9kdWxlIGV2ZW50cy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICB9KS5hbHdheXMoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKHJlcXVlc3QgPT09IGN1cnJlbnRSZXF1ZXN0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJlcXVlc3QgPSBudWxsO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAkc2VnbWVudC5yZW1vdmVDbGFzcygnc3R0LWxvZ3MtbG9hZGluZycpO1xuICAgICAgICAgICAgICAgICRyZWZyZXNoQnV0dG9uLnJlbW92ZUNsYXNzKCdsb2FkaW5nIGRpc2FibGVkJyk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIHJlcXVlc3QgPSBjdXJyZW50UmVxdWVzdDtcbiAgICAgICAgfVxuXG4gICAgICAgICRwZXJpb2RCdXR0b25zLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciAkYnV0dG9uID0gJCh0aGlzKTtcbiAgICAgICAgICAgIGN1cnJlbnRQZXJpb2QgPSBwYXJzZUludCgkYnV0dG9uLmRhdGEoJ3BlcmlvZCcpLCAxMCk7XG4gICAgICAgICAgICBpZiAoIU51bWJlci5pc0Zpbml0ZShjdXJyZW50UGVyaW9kKSkge1xuICAgICAgICAgICAgICAgIGN1cnJlbnRQZXJpb2QgPSAzNjAwO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgJHBlcmlvZEJ1dHRvbnMucmVtb3ZlQ2xhc3MoJ2FjdGl2ZScpO1xuICAgICAgICAgICAgJGJ1dHRvbi5hZGRDbGFzcygnYWN0aXZlJyk7XG4gICAgICAgICAgICBsb2FkZWRPbmNlID0gZmFsc2U7XG4gICAgICAgICAgICBsb2FkTG9ncyh0cnVlKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJHJlZnJlc2hCdXR0b24ub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgbG9hZExvZ3ModHJ1ZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRsZXZlbEZpbHRlci5hZGQoJGNvbXBvbmVudEZpbHRlcikub24oJ2NoYW5nZScsIHJlbmRlckN1cnJlbnQpO1xuICAgICAgICAkc2VhcmNoLm9uKCdpbnB1dCcsIHJlbmRlckN1cnJlbnQpO1xuXG4gICAgICAgICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbWVudSAuaXRlbVtkYXRhLXRhYj1cImxvZ3NcIl0nKS5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB3aW5kb3cuc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgbG9hZExvZ3MoZmFsc2UpO1xuICAgICAgICAgICAgfSwgMCk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHVwZGF0ZU1ldGEoMCwgbnVsbCk7XG4gICAgICAgIGlmIChyZXF1ZXN0ZWRUYWIoKSA9PT0gJ2xvZ3MnIHx8ICRmb3JtLmNsb3Nlc3QoJy50YWIuc2VnbWVudCcpLmhhc0NsYXNzKCdhY3RpdmUnKSkge1xuICAgICAgICAgICAgbG9hZExvZ3MoZmFsc2UpO1xuICAgICAgICB9XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZUNkckhpc3RvcnlTbGlkZXIoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgJHNsaWRlciA9ICQoJyNzdHQtY2RyLWhpc3RvcnktZGF5cy1zbGlkZXInKTtcbiAgICAgICAgdmFyICRpbnB1dCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cImNkcl9oaXN0b3J5X2RheXNcIl0nKTtcblxuICAgICAgICBpZiAoJHNsaWRlci5sZW5ndGggPT09IDAgfHwgJGlucHV0Lmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJHNsaWRlci5zbGlkZXIgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciB2YWx1ZXMgPSBbJzEnLCAnNycsICczMCcsICc5MCcsICcxODAnLCAnMzY1JywgJzAnXTtcbiAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgIDA6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTFEYXknLCAnMSBkYXknKSxcbiAgICAgICAgICAgIDE6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTdEYXlzJywgJzcgZGF5cycpLFxuICAgICAgICAgICAgMjogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MzBEYXlzJywgJzMwIGRheXMnKSxcbiAgICAgICAgICAgIDM6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTkwRGF5cycsICc5MCBkYXlzJyksXG4gICAgICAgICAgICA0OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkxODBEYXlzJywgJzYgbW9udGhzJyksXG4gICAgICAgICAgICA1OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkzNjVEYXlzJywgJzEgeWVhcicpLFxuICAgICAgICAgICAgNjogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5QWxsJywgJ0FsbCcpXG4gICAgICAgIH07XG4gICAgICAgIHZhciBjdXJyZW50VmFsdWUgPSBTdHJpbmcoJGlucHV0LnZhbCgpIHx8ICczMCcpO1xuICAgICAgICB2YXIgaW5pdGlhbEluZGV4ID0gdmFsdWVzLmluZGV4T2YoY3VycmVudFZhbHVlKTtcbiAgICAgICAgdmFyIGluaXRpYWxpemVkID0gZmFsc2U7XG5cbiAgICAgICAgaWYgKGluaXRpYWxJbmRleCA8IDApIHtcbiAgICAgICAgICAgIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKCczMCcpO1xuICAgICAgICAgICAgJGlucHV0LnZhbCgnMzAnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzbGlkZXIuc2xpZGVyKHtcbiAgICAgICAgICAgIG1pbjogMCxcbiAgICAgICAgICAgIG1heDogdmFsdWVzLmxlbmd0aCAtIDEsXG4gICAgICAgICAgICBzdGVwOiAxLFxuICAgICAgICAgICAgc21vb3RoOiB0cnVlLFxuICAgICAgICAgICAgYXV0b0FkanVzdExhYmVsczogZmFsc2UsXG4gICAgICAgICAgICBpbnRlcnByZXRMYWJlbDogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGxhYmVsc1t2YWx1ZV0gfHwgJyc7XG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgb25DaGFuZ2U6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBzZWxlY3RlZCA9IHZhbHVlc1t2YWx1ZV0gfHwgJzMwJztcblxuICAgICAgICAgICAgICAgICRpbnB1dC52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgICAgIGlmIChpbml0aWFsaXplZCkge1xuICAgICAgICAgICAgICAgICAgICAkaW5wdXQudHJpZ2dlcignY2hhbmdlJyk7XG4gICAgICAgICAgICAgICAgICAgIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmIHR5cGVvZiBGb3JtLmNoZWNrVmFsdWVzID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBGb3JtLmNoZWNrVmFsdWVzKCk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkc2xpZGVyLnNsaWRlcignc2V0IHZhbHVlJywgaW5pdGlhbEluZGV4LCBmYWxzZSk7XG4gICAgICAgIGluaXRpYWxpemVkID0gdHJ1ZTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdFJldGVudGlvblNsaWRlcigkc2V0dGluZ3NGb3JtKSB7XG4gICAgICAgIHZhciAkc2xpZGVyID0gJCgnI3N0dC10cmFuc2NyaXB0LXJldGVudGlvbi1kYXlzLXNsaWRlcicpO1xuICAgICAgICB2YXIgJGlucHV0ID0gJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5c1wiXScpO1xuXG4gICAgICAgIGlmICgkc2xpZGVyLmxlbmd0aCA9PT0gMCB8fCAkaW5wdXQubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkc2xpZGVyLnNsaWRlciAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIHZhbHVlcyA9IFsnMzAnLCAnOTAnLCAnMTgwJywgJzM2NScsICcwJ107XG4gICAgICAgIHZhciBsYWJlbHMgPSB7XG4gICAgICAgICAgICAwOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24zMERheXMnLCAnMzAgZGF5cycpLFxuICAgICAgICAgICAgMTogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uOTBEYXlzJywgJzkwIGRheXMnKSxcbiAgICAgICAgICAgIDI6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjE4MERheXMnLCAnMTgwIGRheXMnKSxcbiAgICAgICAgICAgIDM6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjM2NURheXMnLCAnMSB5ZWFyJyksXG4gICAgICAgICAgICA0OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25VbmxpbWl0ZWQnLCAnVW5saW1pdGVkJylcbiAgICAgICAgfTtcbiAgICAgICAgdmFyIGN1cnJlbnRWYWx1ZSA9IFN0cmluZygkaW5wdXQudmFsKCkgfHwgJzM2NScpO1xuICAgICAgICB2YXIgaW5pdGlhbEluZGV4ID0gdmFsdWVzLmluZGV4T2YoY3VycmVudFZhbHVlKTtcbiAgICAgICAgdmFyIGluaXRpYWxpemVkID0gZmFsc2U7XG5cbiAgICAgICAgaWYgKGluaXRpYWxJbmRleCA8IDApIHtcbiAgICAgICAgICAgIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKCczNjUnKTtcbiAgICAgICAgICAgICRpbnB1dC52YWwoJzM2NScpO1xuICAgICAgICB9XG5cbiAgICAgICAgJHNsaWRlci5zbGlkZXIoe1xuICAgICAgICAgICAgbWluOiAwLFxuICAgICAgICAgICAgbWF4OiB2YWx1ZXMubGVuZ3RoIC0gMSxcbiAgICAgICAgICAgIHN0ZXA6IDEsXG4gICAgICAgICAgICBzbW9vdGg6IHRydWUsXG4gICAgICAgICAgICBhdXRvQWRqdXN0TGFiZWxzOiBmYWxzZSxcbiAgICAgICAgICAgIGludGVycHJldExhYmVsOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gbGFiZWxzW3ZhbHVlXSB8fCAnJztcbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBvbkNoYW5nZTogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdmFyIHNlbGVjdGVkID0gdmFsdWVzW3ZhbHVlXSB8fCAnMzY1JztcblxuICAgICAgICAgICAgICAgICRpbnB1dC52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgICAgIGlmIChpbml0aWFsaXplZCkge1xuICAgICAgICAgICAgICAgICAgICAkaW5wdXQudHJpZ2dlcignY2hhbmdlJyk7XG4gICAgICAgICAgICAgICAgICAgIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmIHR5cGVvZiBGb3JtLmNoZWNrVmFsdWVzID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBGb3JtLmNoZWNrVmFsdWVzKCk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkc2xpZGVyLnNsaWRlcignc2V0IHZhbHVlJywgaW5pdGlhbEluZGV4LCBmYWxzZSk7XG4gICAgICAgIGluaXRpYWxpemVkID0gdHJ1ZTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiByZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nKHByb2Nlc3NpbmdEYXlzLCByZXRlbnRpb25EYXlzKSB7XG4gICAgICAgIHJldHVybiByZXRlbnRpb25EYXlzID09PSAwIHx8IChwcm9jZXNzaW5nRGF5cyA+IDAgJiYgcHJvY2Vzc2luZ0RheXMgPD0gcmV0ZW50aW9uRGF5cyk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgcHJvY2Vzc2luZ0RheXMgPSBOdW1iZXIoJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiY2RyX2hpc3RvcnlfZGF5c1wiXScpLnZhbCgpKTtcbiAgICAgICAgdmFyIHJldGVudGlvbkRheXMgPSBOdW1iZXIoJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5c1wiXScpLnZhbCgpKTtcbiAgICAgICAgdmFyIGNvbXBhdGlibGUgPSBOdW1iZXIuaXNGaW5pdGUocHJvY2Vzc2luZ0RheXMpXG4gICAgICAgICAgICAmJiBOdW1iZXIuaXNGaW5pdGUocmV0ZW50aW9uRGF5cylcbiAgICAgICAgICAgICYmIHJldGVudGlvbkFsbG93c1Byb2Nlc3NpbmcocHJvY2Vzc2luZ0RheXMsIHJldGVudGlvbkRheXMpO1xuICAgICAgICB2YXIgJG1lc3NhZ2UgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJy5zdHQtcmV0ZW50aW9uLWNvbmZsaWN0LW1lc3NhZ2UnKTtcblxuICAgICAgICAkbWVzc2FnZS50b2dnbGVDbGFzcygnaGlkZGVuJywgY29tcGF0aWJsZSkuYXR0cignYXJpYS1oaWRkZW4nLCBjb21wYXRpYmxlID8gJ3RydWUnIDogJ2ZhbHNlJyk7XG5cbiAgICAgICAgcmV0dXJuIGNvbXBhdGlibGU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZU1vZGVsTWFya2V0cGxhY2UoJG1vZGVsRm9ybSkge1xuICAgICAgICB2YXIgJHNhdmVCdXR0b24gPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1vZGVsLXNhdmUnKTtcbiAgICAgICAgdmFyICRtb2RlbElucHV0cyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1tb2RlbC1tYXJrZXRwbGFjZS10YWJsZSBpbnB1dFtuYW1lPVwic2VsZWN0ZWRfbW9kZWxcIl0nKTtcbiAgICAgICAgdmFyICRjb21wYXRpYmlsaXR5TWVzc2FnZSA9ICQoJyNzdHQtbW9kZWwtbGFuZ3VhZ2UtY29tcGF0aWJpbGl0eScpO1xuICAgICAgICB2YXIgaW5pdGlhbFNlbGVjdGVkTW9kZWwgPSBTdHJpbmcoJG1vZGVsRm9ybS5kYXRhKCdpbml0aWFsLXNlbGVjdGVkLW1vZGVsJykgfHwgJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKS52YWwoKSB8fCAnJyk7XG4gICAgICAgIHZhciBzZWxlY3RlZExhbmd1YWdlID0gU3RyaW5nKCRtb2RlbEZvcm0uZGF0YSgnc2VsZWN0ZWQtbGFuZ3VhZ2UnKSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNlKCk7XG4gICAgICAgIHZhciAkcGxhdGZvcm1CdXR0b25zID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LW1vZGVsLXBsYXRmb3JtLXN3aXRjaCBbZGF0YS1wbGF0Zm9ybV0nKTtcbiAgICAgICAgdmFyICRwbGF0Zm9ybUNoYW5nZU1lc3NhZ2UgPSAkKCcjc3R0LW1vZGVsLXBsYXRmb3JtLWNoYW5nZScpO1xuICAgICAgICB2YXIgaW5pdGlhbFBsYXRmb3JtID0gU3RyaW5nKCRtb2RlbEZvcm0uZGF0YSgnc2VsZWN0ZWQtcGxhdGZvcm0nKSB8fCAnbWFjb3MnKTtcblxuICAgICAgICBmdW5jdGlvbiBzZWxlY3RlZE1vZGVsKCkge1xuICAgICAgICAgICAgcmV0dXJuIFN0cmluZygkbW9kZWxJbnB1dHMuZmlsdGVyKCc6Y2hlY2tlZCcpLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNlbGVjdGVkTW9kZWxQbGF0Zm9ybSgpIHtcbiAgICAgICAgICAgIHJldHVybiBTdHJpbmcoJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKS5hdHRyKCdkYXRhLXBsYXRmb3JtJykgfHwgJycpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlUGxhdGZvcm1DaGFuZ2VTdGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciBwbGF0Zm9ybSA9IHNlbGVjdGVkTW9kZWxQbGF0Zm9ybSgpO1xuICAgICAgICAgICAgdmFyIGNoYW5nZXNQbGF0Zm9ybSA9IHBsYXRmb3JtICE9PSAnJyAmJiBwbGF0Zm9ybSAhPT0gaW5pdGlhbFBsYXRmb3JtO1xuXG4gICAgICAgICAgICAkcGxhdGZvcm1DaGFuZ2VNZXNzYWdlXG4gICAgICAgICAgICAgICAgLnRvZ2dsZUNsYXNzKCdoaWRkZW4nLCAhY2hhbmdlc1BsYXRmb3JtKVxuICAgICAgICAgICAgICAgIC5hdHRyKCdhcmlhLWhpZGRlbicsIGNoYW5nZXNQbGF0Zm9ybSA/ICdmYWxzZScgOiAndHJ1ZScpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2hvd1BsYXRmb3JtKHBsYXRmb3JtKSB7XG4gICAgICAgICAgICB2YXIgJHJvd3MgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgdGJvZHkgdHInKTtcblxuICAgICAgICAgICAgJHBsYXRmb3JtQnV0dG9ucy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICB2YXIgYWN0aXZlID0gU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKSA9PT0gcGxhdGZvcm07XG5cbiAgICAgICAgICAgICAgICAkKHRoaXMpLnRvZ2dsZUNsYXNzKCdhY3RpdmUnLCBhY3RpdmUpLmF0dHIoJ2FyaWEtcHJlc3NlZCcsIGFjdGl2ZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAkcm93cy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAkKHRoaXMpLnByb3AoJ2hpZGRlbicsIFN0cmluZygkKHRoaXMpLmF0dHIoJ2RhdGEtcGxhdGZvcm0nKSkgIT09IHBsYXRmb3JtKTtcbiAgICAgICAgICAgIH0pO1xuXG4gICAgICAgICAgICBpZiAoc2VsZWN0ZWRNb2RlbFBsYXRmb3JtKCkgIT09IHBsYXRmb3JtKSB7XG4gICAgICAgICAgICAgICAgJG1vZGVsSW5wdXRzLnByb3AoJ2NoZWNrZWQnLCBmYWxzZSk7XG4gICAgICAgICAgICAgICAgJHJvd3MucmVtb3ZlQ2xhc3MoJ3Bvc2l0aXZlJyk7XG4gICAgICAgICAgICAgICAgaWYgKHBsYXRmb3JtID09PSBpbml0aWFsUGxhdGZvcm0pIHtcbiAgICAgICAgICAgICAgICAgICAgJG1vZGVsSW5wdXRzLmZpbHRlcihmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gU3RyaW5nKCQodGhpcykudmFsKCkpID09PSBpbml0aWFsU2VsZWN0ZWRNb2RlbDtcbiAgICAgICAgICAgICAgICAgICAgfSkuY2xvc2VzdCgnLnVpLnJhZGlvLmNoZWNrYm94JykuY2hlY2tib3goJ2NoZWNrJyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2VsZWN0ZWRNb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoKSB7XG4gICAgICAgICAgICB2YXIgJHNlbGVjdGVkSW5wdXQgPSAkbW9kZWxJbnB1dHMuZmlsdGVyKCc6Y2hlY2tlZCcpO1xuICAgICAgICAgICAgdmFyIHN1cHBvcnRlZExhbmd1YWdlcztcblxuICAgICAgICAgICAgaWYgKHNlbGVjdGVkTGFuZ3VhZ2UgPT09ICcnIHx8IHNlbGVjdGVkTGFuZ3VhZ2UgPT09ICdhdXRvJykge1xuICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKCRzZWxlY3RlZElucHV0Lmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgc3VwcG9ydGVkTGFuZ3VhZ2VzID0gU3RyaW5nKCRzZWxlY3RlZElucHV0LmF0dHIoJ2RhdGEtc3VwcG9ydGVkLWxhbmd1YWdlcycpIHx8ICcnKVxuICAgICAgICAgICAgICAgIC5zcGxpdCgnLCcpXG4gICAgICAgICAgICAgICAgLm1hcChmdW5jdGlvbiAobGFuZ3VhZ2UpIHsgcmV0dXJuIGxhbmd1YWdlLnRyaW0oKS50b0xvd2VyQ2FzZSgpOyB9KVxuICAgICAgICAgICAgICAgIC5maWx0ZXIoZnVuY3Rpb24gKGxhbmd1YWdlKSB7IHJldHVybiBsYW5ndWFnZSAhPT0gJyc7IH0pO1xuXG4gICAgICAgICAgICByZXR1cm4gc3VwcG9ydGVkTGFuZ3VhZ2VzLmluZGV4T2Yoc2VsZWN0ZWRMYW5ndWFnZSkgIT09IC0xO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlQ29tcGF0aWJpbGl0eVN0YXRlKCkge1xuICAgICAgICAgICAgdmFyIGNvbXBhdGlibGUgPSBzZWxlY3RlZE1vZGVsU3VwcG9ydHNMYW5ndWFnZSgpO1xuXG4gICAgICAgICAgICAkY29tcGF0aWJpbGl0eU1lc3NhZ2VcbiAgICAgICAgICAgICAgICAudG9nZ2xlQ2xhc3MoJ2hpZGRlbicsIGNvbXBhdGlibGUpXG4gICAgICAgICAgICAgICAgLmF0dHIoJ2FyaWEtaGlkZGVuJywgY29tcGF0aWJsZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuXG4gICAgICAgICAgICByZXR1cm4gY29tcGF0aWJsZTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciBjb21wYXRpYmxlID0gdXBkYXRlQ29tcGF0aWJpbGl0eVN0YXRlKCk7XG5cbiAgICAgICAgICAgIHVwZGF0ZVBsYXRmb3JtQ2hhbmdlU3RhdGUoKTtcbiAgICAgICAgICAgIHZhciBoYXNDaGFuZ2VzID0gc2VsZWN0ZWRNb2RlbCgpICE9PSAnJ1xuICAgICAgICAgICAgICAgICYmIHNlbGVjdGVkTW9kZWwoKSAhPT0gaW5pdGlhbFNlbGVjdGVkTW9kZWxcbiAgICAgICAgICAgICAgICAmJiBjb21wYXRpYmxlO1xuXG4gICAgICAgICAgICAkc2F2ZUJ1dHRvbi50b2dnbGVDbGFzcygnZGlzYWJsZWQnLCAhaGFzQ2hhbmdlcyk7XG4gICAgICAgICAgICAkc2F2ZUJ1dHRvbi5wcm9wKCdkaXNhYmxlZCcsICFoYXNDaGFuZ2VzKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGlmICh0eXBlb2YgJC5mbi5hY2NvcmRpb24gPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1hZHZhbmNlZC1zZXR0aW5ncy1kcm9wZG93bicpLmFjY29yZGlvbigpO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LW1vZGVsLW1hcmtldHBsYWNlLXRhYmxlIHRib2R5IHRyJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBpZiAoJChldmVudC50YXJnZXQpLmNsb3Nlc3QoJ2EsIGJ1dHRvbiwgZm9ybSwgaW5wdXQsIGxhYmVsJykubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgJCh0aGlzKS5maW5kKCcudWkucmFkaW8uY2hlY2tib3gnKS5jaGVja2JveCgnY2hlY2snKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJHBsYXRmb3JtQnV0dG9ucy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzaG93UGxhdGZvcm0oU3RyaW5nKCQodGhpcykuYXR0cignZGF0YS1wbGF0Zm9ybScpKSk7XG4gICAgICAgICAgICB1cGRhdGVTYXZlQnV0dG9uU3RhdGUoKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJG1vZGVsSW5wdXRzLm9uKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJHJvdyA9ICQodGhpcykuY2xvc2VzdCgndHInKTtcblxuICAgICAgICAgICAgJHJvdy5hZGRDbGFzcygncG9zaXRpdmUnKS5zaWJsaW5ncygpLnJlbW92ZUNsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgdXBkYXRlU2F2ZUJ1dHRvblN0YXRlKCk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRtb2RlbEZvcm0ub24oJ3N1Ym1pdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGlmICghc2VsZWN0ZWRNb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoKVxuICAgICAgICAgICAgICAgIHx8IHNlbGVjdGVkTW9kZWwoKSA9PT0gJydcbiAgICAgICAgICAgICAgICB8fCBzZWxlY3RlZE1vZGVsKCkgPT09IGluaXRpYWxTZWxlY3RlZE1vZGVsKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKCRzYXZlQnV0dG9uLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICAkc2F2ZUJ1dHRvbi5hZGRDbGFzcygnbG9hZGluZyBkaXNhYmxlZCBhY3RpdmUnKTtcbiAgICAgICAgICAgICAgICAkc2F2ZUJ1dHRvbi5wcm9wKCdkaXNhYmxlZCcsIHRydWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcblxuICAgICAgICB1cGRhdGVTYXZlQnV0dG9uU3RhdGUoKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplQ29uZmlybU1vZGFsKCkge1xuICAgICAgICB2YXIgJG1vZGFsID0gJCgnI3N0dC1jb25maXJtLW1vZGFsJyk7XG5cbiAgICAgICAgaWYgKCRtb2RhbC5sZW5ndGggPT09IDAgfHwgdHlwZW9mICRtb2RhbC5tb2RhbCAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgLy8gUHJlLWluaXRpYWxpemUgc28gU2VtYW50aWMgVUkgbW92ZXMgdGhlIGVsZW1lbnQgdG8gPGJvZHk+IGFuZCBoaWRlcyBpdCBwcm9wZXJseVxuICAgICAgICAkbW9kYWwubW9kYWwoe30pO1xuXG4gICAgICAgIGZ1bmN0aW9uIHNob3dDb25maXJtKG1lc3NhZ2UsIG9uQ29uZmlybSkge1xuICAgICAgICAgICAgJG1vZGFsLmZpbmQoJy5zdHQtY29uZmlybS1tZXNzYWdlJykudGV4dChtZXNzYWdlKTtcbiAgICAgICAgICAgICRtb2RhbC5tb2RhbCh7XG4gICAgICAgICAgICAgICAgY2xvc2FibGU6IHRydWUsXG4gICAgICAgICAgICAgICAgb25BcHByb3ZlOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIG9uQ29uZmlybSgpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLm1vZGFsKCdzaG93Jyk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBiaW5kQ29uZmlybWFibGVGb3JtKCRmb3JtKSB7XG4gICAgICAgICAgICAkZm9ybS5vbignc3VibWl0LnN0dC1jb25maXJtJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICAgICAgdmFyIG1lc3NhZ2UgPSAkKHRoaXMpLmRhdGEoJ2NvbmZpcm0nKSB8fCAnJztcblxuICAgICAgICAgICAgICAgIGlmIChtZXNzYWdlID09PSAnJykge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgICAgICBldmVudC5zdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24oKTtcblxuICAgICAgICAgICAgICAgIHZhciAkc2VsZiA9ICQodGhpcyk7XG4gICAgICAgICAgICAgICAgc2hvd0NvbmZpcm0obWVzc2FnZSwgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAkc2VsZi5vZmYoJ3N1Ym1pdC5zdHQtY29uZmlybScpLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtY29uZmlybWFibGUtZm9ybScpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgYmluZENvbmZpcm1hYmxlRm9ybSgkKHRoaXMpKTtcbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgLy8gVHJhbnNjcmlwdCBkZWxldGlvbiBpcyBpcnJldmVyc2libGUsIHNvIGl0IG5lZWRzIGFuIGV4cGxpY2l0XG4gICAgLy8gYWNrbm93bGVkZ2VtZW50OiB0aGUgZGlhbG9nIGxpc3RzIHdoYXQgaXMgYW5kIGlzIG5vdCByZW1vdmVkLCB0aGVcbiAgICAvLyBEZWxldGUgYnV0dG9uIHN0YXlzIGRpc2FibGVkIHVudGlsIHRoZSBjaGVja2JveCBpcyB0aWNrZWQsIGFuZCBvbmx5IHRoZW5cbiAgICAvLyBpcyBjb25maXJtX2RlbGV0ZSBhZGRlZCB0byB0aGUgZm9ybSB0aGUgc2VydmVyIHJlcXVpcmVzIGl0IG9uLlxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0RGVsZXRpb24oKSB7XG4gICAgICAgIHZhciAkbW9kYWwgPSAkKCcjc3R0LWRlbGV0ZS10cmFuc2NyaXB0LW1vZGFsJyk7XG4gICAgICAgIHZhciAkZm9ybXMgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtZGVsZXRlLXRyYW5zY3JpcHQtZm9ybScpO1xuXG4gICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID09PSAwIHx8ICRmb3Jtcy5sZW5ndGggPT09IDAgfHwgdHlwZW9mICRtb2RhbC5tb2RhbCAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyICRhY2tub3dsZWRnZSA9ICRtb2RhbC5maW5kKCcjc3R0LWRlbGV0ZS10cmFuc2NyaXB0LWFja25vd2xlZGdlJyk7XG4gICAgICAgIHZhciAkYXBwcm92ZSA9ICRtb2RhbC5maW5kKCcuYXBwcm92ZS5idXR0b24nKTtcbiAgICAgICAgdmFyIHBlbmRpbmdGb3JtID0gbnVsbDtcblxuICAgICAgICBmdW5jdGlvbiBzZXRBcHByb3ZlRW5hYmxlZChlbmFibGVkKSB7XG4gICAgICAgICAgICAkYXBwcm92ZS50b2dnbGVDbGFzcygnZGlzYWJsZWQnLCAhZW5hYmxlZCkucHJvcCgnZGlzYWJsZWQnLCAhZW5hYmxlZCk7XG4gICAgICAgIH1cblxuICAgICAgICAvLyBQcmUtaW5pdGlhbGl6ZSBzbyBTZW1hbnRpYyBVSSBtb3ZlcyB0aGUgZWxlbWVudCB0byA8Ym9keT4gYW5kIGhpZGVzIGl0IHByb3Blcmx5XG4gICAgICAgICRtb2RhbC5tb2RhbCh7fSk7XG4gICAgICAgICRhY2tub3dsZWRnZS5vbignY2hhbmdlJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgc2V0QXBwcm92ZUVuYWJsZWQoJGFja25vd2xlZGdlLnByb3AoJ2NoZWNrZWQnKSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRmb3Jtcy5vbignc3VibWl0LnN0dC1kZWxldGUtdHJhbnNjcmlwdCcsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgIHBlbmRpbmdGb3JtID0gdGhpcztcbiAgICAgICAgICAgICQodGhpcykuY2xvc2VzdCgnZGV0YWlscycpLnByb3AoJ29wZW4nLCBmYWxzZSk7XG4gICAgICAgICAgICAkYWNrbm93bGVkZ2UucHJvcCgnY2hlY2tlZCcsIGZhbHNlKTtcbiAgICAgICAgICAgIHNldEFwcHJvdmVFbmFibGVkKGZhbHNlKTtcbiAgICAgICAgICAgICRtb2RhbC5tb2RhbCh7XG4gICAgICAgICAgICAgICAgY2xvc2FibGU6IHRydWUsXG4gICAgICAgICAgICAgICAgb25BcHByb3ZlOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmICghJGFja25vd2xlZGdlLnByb3AoJ2NoZWNrZWQnKSB8fCBwZW5kaW5nRm9ybSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIHZhciBmb3JtID0gcGVuZGluZ0Zvcm07XG4gICAgICAgICAgICAgICAgICAgIHBlbmRpbmdGb3JtID0gbnVsbDtcbiAgICAgICAgICAgICAgICAgICAgJCgnPGlucHV0PicsIHsgdHlwZTogJ2hpZGRlbicsIG5hbWU6ICdjb25maXJtX2RlbGV0ZScsIHZhbHVlOiAnMScgfSkuYXBwZW5kVG8oZm9ybSk7XG4gICAgICAgICAgICAgICAgICAgIC8vIFRoZSBuYXRpdmUgc3VibWl0IHNraXBzIHRoaXMgaGFuZGxlciwgc28gdGhlIGRpYWxvZyBpcyBub3Qgc2hvd24gYWdhaW4uXG4gICAgICAgICAgICAgICAgICAgIGZvcm0uc3VibWl0KCk7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgb25IaWRkZW46IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgcGVuZGluZ0Zvcm0gPSBudWxsO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pLm1vZGFsKCdzaG93Jyk7XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0Um93cygpIHtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LXRyYW5zY3JpcHQtcm93Jykub24oJ2NsaWNrJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBpZiAoJChldmVudC50YXJnZXQpLmNsb3Nlc3QoJ2EsIGJ1dHRvbiwgaW5wdXQsIHNlbGVjdCwgdGV4dGFyZWEnKS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICB2YXIgZGVzdGluYXRpb24gPSAkKHRoaXMpLmRhdGEoJ3RyYW5zY3JpcHQtdXJsJyk7XG4gICAgICAgICAgICBpZiAoZGVzdGluYXRpb24pIHtcbiAgICAgICAgICAgICAgICB3aW5kb3cubG9jYXRpb24uaHJlZiA9IGRlc3RpbmF0aW9uO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdEFjdGlvbk1lbnUoKSB7XG4gICAgICAgIHZhciAkbWVudXMgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtdHJhbnNjcmlwdC1hY3Rpb25zJyk7XG5cbiAgICAgICAgJG1lbnVzLmZpbmQoJy5tZW51IGEsIC5tZW51IGJ1dHRvbicpLm9uKCdjbGljay5zdHQtdHJhbnNjcmlwdC1hY3Rpb25zJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgJCh0aGlzKS5jbG9zZXN0KCdkZXRhaWxzJykucmVtb3ZlQXR0cignb3BlbicpO1xuICAgICAgICB9KTtcbiAgICAgICAgJChkb2N1bWVudCkub24oJ2NsaWNrLnN0dC10cmFuc2NyaXB0LWFjdGlvbnMnLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgICRtZW51cy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBpZiAodGhpcyAhPT0gZXZlbnQudGFyZ2V0ICYmICF0aGlzLmNvbnRhaW5zKGV2ZW50LnRhcmdldCkpIHtcbiAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS5yZW1vdmVBdHRyKCdvcGVuJyk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0TGVnVGFicygpIHtcbiAgICAgICAgdmFyIHRyYW5zY3JpcHRMb2NhdGlvbiA9IHdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyAmJiB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcubG9jYXRpb247XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWNhbGwtbGVnLXJldmlldycpLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyICRyZXZpZXcgPSAkKHRoaXMpO1xuICAgICAgICAgICAgdmFyICR0YWJzID0gJHJldmlldy5maW5kKCdbZGF0YS1zdHQtY2FsbC1sZWddJyk7XG4gICAgICAgICAgICB2YXIgJHBhbmVscyA9ICRyZXZpZXcuZmluZCgnW2RhdGEtc3R0LWNhbGwtbGVnLXBhbmVsXScpO1xuXG4gICAgICAgICAgICBmdW5jdGlvbiBwYW5lbExlZygkZWxlbWVudCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBTdHJpbmcoJGVsZW1lbnQuY2xvc2VzdCgnW2RhdGEtc3R0LWNhbGwtbGVnLXBhbmVsXScpLmRhdGEoJ3N0dC1jYWxsLWxlZy1wYW5lbCcpIHx8ICcnKTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgZnVuY3Rpb24gc2hvdyhjYWxsTGVnKSB7XG4gICAgICAgICAgICAgICAgJHRhYnMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHZhciBhY3RpdmUgPSBTdHJpbmcoJCh0aGlzKS5kYXRhKCdzdHQtY2FsbC1sZWcnKSB8fCAnJykgPT09IGNhbGxMZWc7XG4gICAgICAgICAgICAgICAgICAgICQodGhpcykudG9nZ2xlQ2xhc3MoJ2FjdGl2ZScsIGFjdGl2ZSkuYXR0cignYXJpYS1zZWxlY3RlZCcsIGFjdGl2ZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgICAgICRwYW5lbHMuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgIHZhciBoaWRkZW4gPSBTdHJpbmcoJCh0aGlzKS5kYXRhKCdzdHQtY2FsbC1sZWctcGFuZWwnKSB8fCAnJykgIT09IGNhbGxMZWc7XG5cbiAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS5wcm9wKCdoaWRkZW4nLCBoaWRkZW4pO1xuICAgICAgICAgICAgICAgICAgICBpZiAoaGlkZGVuICYmIHdpbmRvdy5TdHRUcmFuc2NyaXB0VmlldyAmJiB0eXBlb2Ygd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3LnBhdXNlID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICB3aW5kb3cuU3R0VHJhbnNjcmlwdFZpZXcucGF1c2UodGhpcyk7XG4gICAgICAgICAgICAgICAgICAgIH0gZWxzZSBpZiAoaGlkZGVuKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLmZpbmQoJ2F1ZGlvJykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgdmFyIGF1ZGlvID0gdGhpcztcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBhdWRpby5wYXVzZSgpO1xuICAgICAgICAgICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgJHRhYnMub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHZhciBjYWxsTGVnID0gU3RyaW5nKCQodGhpcykuZGF0YSgnc3R0LWNhbGwtbGVnJykgfHwgJycpO1xuXG4gICAgICAgICAgICAgICAgc2hvdyhjYWxsTGVnKTtcbiAgICAgICAgICAgICAgICBpZiAodHJhbnNjcmlwdExvY2F0aW9uKSB7XG4gICAgICAgICAgICAgICAgICAgIC8vIEEgaGVsZCBtb21lbnQgaW4gdGhlIG90aGVyIGxlZyBubyBsb25nZXIgZGVzY3JpYmVzIHdoYXQgaXMgb24gc2NyZWVuLlxuICAgICAgICAgICAgICAgICAgICB2YXIgbW9tZW50ID0gdHJhbnNjcmlwdExvY2F0aW9uLnJlYWQod2luZG93LmxvY2F0aW9uLnNlYXJjaCk7XG4gICAgICAgICAgICAgICAgICAgIHZhciAkc3RhZ2UgPSBtb21lbnQuc3RhZ2UgPyAkcmV2aWV3LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC12aWV3W2RhdGEtc3R0LXN0YWdlPVwiJyArIG1vbWVudC5zdGFnZSArICdcIl0nKSA6ICQoKTtcbiAgICAgICAgICAgICAgICAgICAgdmFyIGNoYW5nZXMgPSB7IGxlZzogY2FsbExlZyB9O1xuICAgICAgICAgICAgICAgICAgICBpZiAoJHN0YWdlLmxlbmd0aCA+IDAgJiYgcGFuZWxMZWcoJHN0YWdlKSAhPT0gY2FsbExlZykge1xuICAgICAgICAgICAgICAgICAgICAgICAgY2hhbmdlcy5zdGFnZSA9ICcnO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIHRyYW5zY3JpcHRMb2NhdGlvbi53cml0ZShjaGFuZ2VzKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcblxuICAgICAgICAgICAgLy8gVGhlIGFkZHJlc3MgcGlja3MgdGhlIGxlZzogdGhlIGxlZyBvZiBhIHNoYXJlZCBtb21lbnQgZmlyc3QsIHRoZW4gYW4gZXhwbGljaXQgbGVnLlxuICAgICAgICAgICAgaWYgKHRyYW5zY3JpcHRMb2NhdGlvbikge1xuICAgICAgICAgICAgICAgIHZhciBpbml0aWFsID0gdHJhbnNjcmlwdExvY2F0aW9uLnJlYWQod2luZG93LmxvY2F0aW9uLnNlYXJjaCk7XG4gICAgICAgICAgICAgICAgdmFyICR0YXJnZXQgPSBpbml0aWFsLnN0YWdlID8gJHJldmlldy5maW5kKCcuc3R0LXRyYW5zY3JpcHQtdmlld1tkYXRhLXN0dC1zdGFnZT1cIicgKyBpbml0aWFsLnN0YWdlICsgJ1wiXScpIDogJCgpO1xuICAgICAgICAgICAgICAgIHZhciBsZWcgPSAkdGFyZ2V0Lmxlbmd0aCA+IDAgPyBwYW5lbExlZygkdGFyZ2V0KSA6IGluaXRpYWwubGVnO1xuICAgICAgICAgICAgICAgIGlmIChsZWcgIT09ICcnICYmICR0YWJzLmZpbHRlcignW2RhdGEtc3R0LWNhbGwtbGVnPVwiJyArIGxlZyArICdcIl0nKS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIHNob3cobGVnKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgICQoZnVuY3Rpb24gKCkge1xuICAgICAgICAvLyBPbmx5IGl0ZW1zIHdpdGggYSBkYXRhLXRhYiB0YXJnZXQgYXJlIGNsaWVudC1zaWRlIHRhYnMuIFRoZVxuICAgICAgICAvLyBUcmFuc2NyaXB0cyBpdGVtIGlzIGEgcmVhbCBsaW5rICg/dGFiPXRyYW5zY3JpcHRzLCBmdWxsIHBhZ2VcbiAgICAgICAgLy8gbmF2aWdhdGlvbikgYmVjYXVzZSBpdHMgY29udGVudCBpcyBvbmx5IHJlbmRlcmVkIHNlcnZlci1zaWRlIHdoZW5cbiAgICAgICAgLy8gdGhhdCB0YWIgaXMgdGhlIG9uZSBiZWluZyBsb2FkZWQgLSBzZWUgaW5kZXgudm9sdC9JbmRleFZpZXdTZXJ2aWNlLlxuICAgICAgICB2YXIgJHRhYk1lbnUgPSAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1lbnUnKTtcbiAgICAgICAgdmFyICR0YWJJdGVtcyA9ICR0YWJNZW51LmZpbmQoJy5pdGVtW2RhdGEtdGFiXScpO1xuICAgICAgICB2YXIgJHNldHRpbmdzRm9ybSA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtZm9ybScpO1xuICAgICAgICB2YXIgJG1vZGVsRm9ybSA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbW9kZWwtZm9ybScpO1xuICAgICAgICAkdGFiSXRlbXMudGFiKHtcbiAgICAgICAgICAgIG9uVmlzaWJsZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIC8vIFNlbWFudGljIFVJJ3MgdGFiIG1vZHVsZSAoMi40LjEsIHNlZVxuICAgICAgICAgICAgICAgIC8vIHB1YmxpYy9hc3NldHMvanMvdmVuZG9yIGVxdWl2YWxlbnQgdW5kZXIgQ29yZSkgb25seVxuICAgICAgICAgICAgICAgIC8vIChkZSlhY3RpdmF0ZXMgbWVudSBpdGVtcyBpdCB3YXMgaW5pdGlhbGl6ZWQgb25cbiAgICAgICAgICAgICAgICAvLyAoJHRhYkl0ZW1zLCBpLmUuIGl0ZW1zIHdpdGggYSBkYXRhLXRhYikgLSBzZWUgaXRzXG4gICAgICAgICAgICAgICAgLy8gYWN0aXZhdGUubmF2aWdhdGlvbigpLiBUaGUgVHJhbnNjcmlwdHMgaXRlbSBpc1xuICAgICAgICAgICAgICAgIC8vIGRlbGliZXJhdGVseSBvdXRzaWRlIHRoYXQgc2V0LCBzbyBhIG5vcm1hbCB0YWIgc3dpdGNoXG4gICAgICAgICAgICAgICAgLy8gbmV2ZXIgcmVtb3ZlcyBpdHMgJ2FjdGl2ZScgY2xhc3M6IHdpdGhvdXQgdGhpcywgbGFuZGluZ1xuICAgICAgICAgICAgICAgIC8vIG9uID90YWI9dHJhbnNjcmlwdHMgKHdoaWNoIHNlcnZlci1yZW5kZXJzIGl0IGFjdGl2ZSkgYW5kXG4gICAgICAgICAgICAgICAgLy8gdGhlbiBjbGlja2luZyBhbnkgb3RoZXIgdGFiIHdvdWxkIGxlYXZlIFRyYW5zY3JpcHRzXG4gICAgICAgICAgICAgICAgLy8gaGlnaGxpZ2h0ZWQgYWxvbmdzaWRlIGl0LlxuICAgICAgICAgICAgICAgICR0YWJNZW51LmZpbmQoJy5pdGVtJykubm90KCdbZGF0YS10YWJdJykucmVtb3ZlQ2xhc3MoJ2FjdGl2ZScpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgaWYgKHJlcXVlc3RlZFRhYigpICE9PSAnJyAmJiByZXF1ZXN0ZWRUYWIoKSAhPT0gJ3RyYW5zY3JpcHRzJykge1xuICAgICAgICAgICAgLy8gJ3RyYW5zY3JpcHRzJyBoYXMgbm8gbWFuYWdlZCBuYXYgaXRlbSAobm8gZGF0YS10YWIpIGFueSBtb3JlOlxuICAgICAgICAgICAgLy8gaXRzIHBhbmVsIGFuZCBpdHMgbWVudSBsaW5rIGFyZSBhbHJlYWR5IHJlbmRlcmVkIHNlcnZlci1zaWRlXG4gICAgICAgICAgICAvLyBhcyBhY3RpdmUgb24gP3RhYj10cmFuc2NyaXB0cyAoc2VlIGluZGV4LnZvbHQpLCBzbyB0aGVyZSBpc1xuICAgICAgICAgICAgLy8gbm90aGluZyBoZXJlIGZvciB0aGUgdGFiIG1vZHVsZSB0byBzd2l0Y2ggdG8gLSBza2lwIHRoZSBjYWxsXG4gICAgICAgICAgICAvLyBpbnN0ZWFkIG9mIHJlbHlpbmcgb24gaXQgYmVpbmcgYSBoYXJtbGVzcyBuby1vcC5cbiAgICAgICAgICAgICR0YWJJdGVtcy50YWIoJ2NoYW5nZSB0YWInLCByZXF1ZXN0ZWRUYWIoKSk7XG4gICAgICAgIH1cblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC51aS5kcm9wZG93bicpLmRyb3Bkb3duKCk7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnVpLmNoZWNrYm94JykuY2hlY2tib3goKTtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCBbZGF0YS1jb250ZW50XScpLnBvcHVwKCk7XG4gICAgICAgIGluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlKCRtb2RlbEZvcm0pO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdERhdGVGaWx0ZXIoKTtcbiAgICAgICAgaW5pdGlhbGl6ZU1vZHVsZUxvZ3MoKTtcbiAgICAgICAgaW5pdGlhbGl6ZUNkckhpc3RvcnlTbGlkZXIoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0UmV0ZW50aW9uU2xpZGVyKCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCgpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdERlbGV0aW9uKCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0Um93cygpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdEFjdGlvbk1lbnUoKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRMZWdUYWJzKCk7XG5cbiAgICAgICAgaWYgKCQuZm4uZm9ybSAmJiAkLmZuLmZvcm0uc2V0dGluZ3MgJiYgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzKSB7XG4gICAgICAgICAgICAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMuc3R0UHJvY2Vzc2luZ1JhbmdlID0gZnVuY3Rpb24gKHZhbHVlLCByYW5nZSkge1xuICAgICAgICAgICAgICAgIHZhciBsaW1pdHMgPSBTdHJpbmcocmFuZ2UgfHwgJycpLnNwbGl0KCcsJykubWFwKE51bWJlcik7XG4gICAgICAgICAgICAgICAgdmFyIG51bWJlciA9IE51bWJlcih2YWx1ZSk7XG5cbiAgICAgICAgICAgICAgICByZXR1cm4gbGltaXRzLmxlbmd0aCA9PT0gMlxuICAgICAgICAgICAgICAgICAgICAmJiBOdW1iZXIuaXNGaW5pdGUobnVtYmVyKVxuICAgICAgICAgICAgICAgICAgICAmJiBudW1iZXIgPj0gbGltaXRzWzBdXG4gICAgICAgICAgICAgICAgICAgICYmIG51bWJlciA8PSBsaW1pdHNbMV07XG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzLnN0dFByb2Nlc3NpbmdPdmVybGFwID0gZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdmFyIG92ZXJsYXBNUyA9IE1hdGgucm91bmQoTnVtYmVyKHZhbHVlKSAqIDEwMDApO1xuICAgICAgICAgICAgICAgIHZhciBkdXJhdGlvbk1TID0gTWF0aC5yb3VuZChOdW1iZXIoJHNldHRpbmdzRm9ybS5maW5kKCdbbmFtZT1cIm1heF9zZWdtZW50X2R1cmF0aW9uX3NlY1wiXScpLnZhbCgpKSAqIDEwMDApO1xuXG4gICAgICAgICAgICAgICAgcmV0dXJuIE51bWJlci5pc0Zpbml0ZShvdmVybGFwTVMpXG4gICAgICAgICAgICAgICAgICAgICYmIE51bWJlci5pc0Zpbml0ZShkdXJhdGlvbk1TKVxuICAgICAgICAgICAgICAgICAgICAmJiBvdmVybGFwTVMgPj0gMFxuICAgICAgICAgICAgICAgICAgICAmJiBvdmVybGFwTVMgPCBkdXJhdGlvbk1TO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcy5zdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5ID0gZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgIH07XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgcHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMgPSB7XG4gICAgICAgICAgICB0ZW1wZXJhdHVyZToge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0ZW1wZXJhdHVyZScsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0ZW1wZXJhdHVyZV9mYWxsYmFja19zdGVwOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RlbXBlcmF0dXJlX2ZhbGxiYWNrX3N0ZXAnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMV0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgdGVtcGVyYXR1cmVfZmFsbGJhY2tfY291bnQ6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndGVtcGVyYXR1cmVfZmFsbGJhY2tfY291bnQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnaW50ZWdlclswLi4xMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgdG9wX2s6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndG9wX2snLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnaW50ZWdlclsxLi4xMDBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIGNvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdjb21wcmVzc2lvbl9yYXRpb190aHJlc2hvbGQnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzAsMTBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIGxvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQ6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbLTEwLDBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIGZpcnN0X3Rva2VuX2xvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQ6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnZmlyc3RfdG9rZW5fbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbLTEwLDBdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG5vX3NwZWVjaF90aHJlc2hvbGQ6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnbm9fc3BlZWNoX3RocmVzaG9sZCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWM6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnbWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVsxLDEyMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgc2VnbWVudF9vdmVybGFwX3NlYzoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdzZWdtZW50X292ZXJsYXBfc2VjJyxcbiAgICAgICAgICAgICAgICBydWxlczogW1xuICAgICAgICAgICAgICAgICAgICB7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxMF0nIH0sXG4gICAgICAgICAgICAgICAgICAgIHsgdHlwZTogJ3N0dFByb2Nlc3NpbmdPdmVybGFwJyB9XG4gICAgICAgICAgICAgICAgXVxuICAgICAgICAgICAgfVxuICAgICAgICB9O1xuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcygpIHtcbiAgICAgICAgICAgIHZhciBlbmFibGVkID0gJHNldHRpbmdzRm9ybS5maW5kKCdbbmFtZT1cInZvaWNlX2FjdGl2aXR5X2RldGVjdGlvblwiXScpLmlzKCc6Y2hlY2tlZCcpO1xuXG4gICAgICAgICAgICAkc2V0dGluZ3NGb3JtLmZpbmQoJy5zdHQtdmFkLWRlcGVuZGVudCcpXG4gICAgICAgICAgICAgICAgLnRvZ2dsZUNsYXNzKCdkaXNhYmxlZCcsICFlbmFibGVkKVxuICAgICAgICAgICAgICAgIC5hdHRyKCdhcmlhLWRpc2FibGVkJywgZW5hYmxlZCA/ICdmYWxzZScgOiAndHJ1ZScpO1xuICAgICAgICB9XG5cbiAgICAgICAgJHNldHRpbmdzRm9ybS5maW5kKCdbbmFtZT1cInZvaWNlX2FjdGl2aXR5X2RldGVjdGlvblwiXScpLm9uKCdjaGFuZ2UnLCB1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMpO1xuICAgICAgICB1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMoKTtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWNvcHktYnV0dG9uJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyIHRleHQgPSAkKHRoaXMpLmRhdGEoJ2NsaXBib2FyZC10ZXh0JykgfHwgJyc7XG4gICAgICAgICAgICB2YXIgJGJ1dHRvbiA9ICQodGhpcyk7XG5cbiAgICAgICAgICAgIGlmIChuYXZpZ2F0b3IuY2xpcGJvYXJkICYmIG5hdmlnYXRvci5jbGlwYm9hcmQud3JpdGVUZXh0KSB7XG4gICAgICAgICAgICAgICAgbmF2aWdhdG9yLmNsaXBib2FyZC53cml0ZVRleHQodGV4dCkudGhlbihmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICRidXR0b24uYWRkQ2xhc3MoJ3Bvc2l0aXZlJyk7XG4gICAgICAgICAgICAgICAgICAgIHNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICAgICAgJGJ1dHRvbi5yZW1vdmVDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgICAgICAgICAgfSwgOTAwKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG5cbiAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiAkc2V0dGluZ3NGb3JtLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgIEZvcm0uJGZvcm1PYmogPSAkc2V0dGluZ3NGb3JtO1xuICAgICAgICAgICAgRm9ybS51cmwgPSAkc2V0dGluZ3NGb3JtLmF0dHIoJ2FjdGlvbicpO1xuICAgICAgICAgICAgRm9ybS5tZXRob2QgPSAnUE9TVCc7XG4gICAgICAgICAgICBGb3JtLnZhbGlkYXRlUnVsZXMgPSBPYmplY3QuYXNzaWduKHtcbiAgICAgICAgICAgICAgICBkZWZhdWx0X2xhbmd1YWdlOiB7XG4gICAgICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdkZWZhdWx0X2xhbmd1YWdlJyxcbiAgICAgICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdlbXB0eScgfV1cbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgICAgIGNkcl9oaXN0b3J5X2RheXM6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2Nkcl9oaXN0b3J5X2RheXMnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2ludGVnZXJbMC4uMzY1XScgfV1cbiAgICAgICAgICAgICAgICB9LFxuICAgICAgICAgICAgICAgIHRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXM6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXMnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW1xuICAgICAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnaW50ZWdlclswLi4zNjVdJyB9LFxuICAgICAgICAgICAgICAgICAgICAgICAge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBwcm9tcHQ6IHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25Db25mbGljdCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdUaGUgcmVjb3JkaW5nIHByb2Nlc3Npbmcgd2luZG93IGNhbm5vdCBleGNlZWQgdHJhbnNjcmlwdCByZXRlbnRpb24uIEluY3JlYXNlIHJldGVudGlvbiBvciByZWR1Y2UgdGhlIHByb2Nlc3Npbmcgd2luZG93LidcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LCBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcyk7XG4gICAgICAgICAgICBGb3JtLmNiQmVmb3JlU2VuZEZvcm0gPSBmdW5jdGlvbiAoc2V0dGluZ3MpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gc2V0dGluZ3M7XG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgRm9ybS5jYkFmdGVyU2VuZEZvcm0gPSBmdW5jdGlvbiAoKSB7fTtcbiAgICAgICAgICAgIEZvcm0uYXBpU2V0dGluZ3MuZW5hYmxlZCA9IGZhbHNlO1xuICAgICAgICAgICAgRm9ybS5hcGlTZXR0aW5ncy5hcGlPYmplY3QgPSBudWxsO1xuICAgICAgICAgICAgRm9ybS5zZW5kT25seUNoYW5nZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIEZvcm0uZW5hYmxlRGlycml0eSA9IHRydWU7XG4gICAgICAgICAgICBGb3JtLmluaXRpYWxpemUoKTtcbiAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICRzZXR0aW5nc0Zvcm0uZm9ybSh7XG4gICAgICAgICAgICAgICAgZmllbGRzOiBPYmplY3QuYXNzaWduKHtcbiAgICAgICAgICAgICAgICAgICAgZGVmYXVsdF9sYW5ndWFnZTogWydlbXB0eSddLFxuICAgICAgICAgICAgICAgICAgICBjZHJfaGlzdG9yeV9kYXlzOiBbJ2ludGVnZXJbMC4uMzY1XSddLFxuICAgICAgICAgICAgICAgICAgICB0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzOiBbXG4gICAgICAgICAgICAgICAgICAgICAgICAnaW50ZWdlclswLi4zNjVdJyxcbiAgICAgICAgICAgICAgICAgICAgICAgIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICB0eXBlOiAnc3R0UmV0ZW50aW9uQ29tcGF0aWJpbGl0eScsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgcHJvbXB0OiB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uQ29uZmxpY3QnLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnVGhlIHJlY29yZGluZyBwcm9jZXNzaW5nIHdpbmRvdyBjYW5ub3QgZXhjZWVkIHRyYW5zY3JpcHQgcmV0ZW50aW9uLiBJbmNyZWFzZSByZXRlbnRpb24gb3IgcmVkdWNlIHRoZSBwcm9jZXNzaW5nIHdpbmRvdy4nXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgKVxuICAgICAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICAgICBdXG4gICAgICAgICAgICAgICAgfSwgcHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMpXG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgICRtb2RlbEZvcm0uZm9ybSh7XG4gICAgICAgICAgICBmaWVsZHM6IHtcbiAgICAgICAgICAgICAgICBzZWxlY3RlZF9tb2RlbDogWydlbXB0eSddXG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH0pO1xufSgpKTtcbiJdLCJtYXBwaW5ncyI6Ijs7OztBQUFDLGFBQVk7RUFDVCxZQUFZOztFQUVaLFNBQVNBLFdBQVdBLENBQUNDLEdBQUcsRUFBRUMsUUFBUSxFQUFFO0lBQ2hDLElBQUksT0FBT0MsZUFBZSxLQUFLLFdBQVcsSUFBSUEsZUFBZSxDQUFDRixHQUFHLENBQUMsRUFBRTtNQUNoRSxPQUFPRSxlQUFlLENBQUNGLEdBQUcsQ0FBQztJQUMvQjtJQUVBLE9BQU9DLFFBQVE7RUFDbkI7RUFFQSxTQUFTRSxZQUFZQSxDQUFBLEVBQUc7SUFDcEIsSUFBSUMsTUFBTSxHQUFHLElBQUlDLGVBQWUsQ0FBQ0MsTUFBTSxDQUFDQyxRQUFRLENBQUNDLE1BQU0sQ0FBQztJQUN4RCxJQUFJQyxPQUFPLEdBQUdMLE1BQU0sQ0FBQ00sR0FBRyxDQUFDLEtBQUssQ0FBQyxJQUFJSixNQUFNLENBQUNDLFFBQVEsQ0FBQ0ksSUFBSSxDQUFDQyxPQUFPLENBQUMsSUFBSSxFQUFFLEVBQUUsQ0FBQztJQUV6RSxPQUFPLGVBQWUsQ0FBQ0MsSUFBSSxDQUFDSixPQUFPLENBQUMsR0FBR0EsT0FBTyxHQUFHLEVBQUU7RUFDdkQ7RUFFQSxTQUFTSyxjQUFjQSxDQUFBLEVBQUc7SUFDdEIsSUFBSUMsSUFBSSxHQUFHLENBQUMsSUFBSSxFQUFFLElBQUksRUFBRSxJQUFJLEVBQUUsSUFBSSxFQUFFLElBQUksRUFBRSxJQUFJLEVBQUUsSUFBSSxDQUFDO0lBQ3JELElBQUlDLE1BQU0sR0FBRyxDQUNULFNBQVMsRUFDVCxVQUFVLEVBQ1YsT0FBTyxFQUNQLE9BQU8sRUFDUCxLQUFLLEVBQ0wsTUFBTSxFQUNOLE1B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