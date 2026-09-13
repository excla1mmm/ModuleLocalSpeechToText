"use strict";

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
      interpretLabel: function interpretLabel(value) {
        return labels[value] || '';
      },
      onChange: function onChange(value) {
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
      interpretLabel: function interpretLabel(value) {
        return labels[value] || '';
      },
      onChange: function onChange(value) {
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
    function selectedModel() {
      return String($modelInputs.filter(':checked').val() || '');
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
        onApprove: function onApprove() {
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
    $('.module-local-speech-to-text .stt-delete-api-key-form').each(function () {
      bindConfirmableForm($(this));
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
  $(function () {
    var $tabItems = $('#module-local-speech-to-text-menu .item');
    var $settingsForm = $('#module-local-speech-to-text-form');
    var $modelForm = $('#module-local-speech-to-text-model-form');
    $tabItems.tab();
    if (requestedTab() !== '') {
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
    initializeTranscriptRows();
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

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIiwibmFtZXMiOlsidHJhbnNsYXRpb24iLCJrZXkiLCJmYWxsYmFjayIsImdsb2JhbFRyYW5zbGF0ZSIsInJlcXVlc3RlZFRhYiIsInBhcmFtcyIsIlVSTFNlYXJjaFBhcmFtcyIsIndpbmRvdyIsImxvY2F0aW9uIiwic2VhcmNoIiwidGFiTmFtZSIsImdldCIsImhhc2giLCJyZXBsYWNlIiwidGVzdCIsImNhbGVuZGFyTG9jYWxlIiwiZGF5cyIsIm1vbnRocyIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiY2FsZW5kYXJUZXh0IiwiZm9ybWF0Iiwic2VwYXJhdG9yIiwiYXBwbHlMYWJlbCIsImNhbmNlbExhYmVsIiwiZnJvbUxhYmVsIiwidG9MYWJlbCIsImN1c3RvbVJhbmdlTGFiZWwiLCJkYXlzT2ZXZWVrIiwibW9udGhOYW1lcyIsImZpcnN0RGF5IiwibW9tZW50RGF0ZSIsInZhbHVlIiwiZGF0ZSIsIm1vbWVudCIsImlzVmFsaWQiLCJjbG9uZSIsImluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlciIsIiRmb3JtIiwiJCIsIiRzZWxlY3RvciIsImxlbmd0aCIsImZuIiwiZGF0ZXJhbmdlcGlja2VyIiwiJGRhdGVGcm9tIiwiZmluZCIsIiRkYXRlVG8iLCIkc2VhcmNoIiwic2VhcmNoRGVib3VuY2VUaW1lciIsInRvZGF5Iiwic3RhcnREYXRlIiwidmFsIiwiZW5kRGF0ZSIsImlzQWZ0ZXIiLCJzd2FwRGF0ZSIsInVwZGF0ZUhpZGRlbklucHV0cyIsInN0YXJ0IiwiZW5kIiwiY2FuY2VsU2VhcmNoRGVib3VuY2UiLCJjbGVhclRpbWVvdXQiLCJzdWJtaXRUcmFuc2NyaXB0U2VhcmNoIiwidHJpZ2dlciIsInJhbmdlcyIsIl9kZWZpbmVQcm9wZXJ0eSIsInN1YnRyYWN0Iiwic3RhcnRPZiIsImVuZE9mIiwiYWx3YXlzU2hvd0NhbGVuZGFycyIsImF1dG9VcGRhdGVJbnB1dCIsImxpbmtlZENhbGVuZGFycyIsIm1heERhdGUiLCJsb2NhbGUiLCJvbiIsInBpY2tlciIsImRhdGEiLCJzZXRUaW1lb3V0IiwiZXZlbnQiLCJ3aGljaCIsInByZXZlbnREZWZhdWx0IiwiU3RyaW5nIiwidHJpbSIsImluaXRpYWxpemVNb2R1bGVMb2dzIiwiJHNlZ21lbnQiLCIkcmVzdWx0cyIsIiRzdGF0ZSIsIiRyZXN1bHRDb3VudCIsIiR1cGRhdGVkQXQiLCIkbGV2ZWxGaWx0ZXIiLCIkY29tcG9uZW50RmlsdGVyIiwiZGF0YVVybCIsIiRwZXJpb2RCdXR0b25zIiwiJHJlZnJlc2hCdXR0b24iLCJjdXJyZW50UGVyaW9kIiwicGFyc2VJbnQiLCJmaWx0ZXIiLCJsb2FkZWRPbmNlIiwicmVxdWVzdCIsInJlcXVlc3RHZW5lcmF0aW9uIiwiY3VycmVudEVudHJpZXMiLCJjdXJyZW50UmVzcG9uc2UiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsImxldmVsTmFtZSIsImxldmVsIiwibGV2ZWxzIiwiZGVidWciLCJpbmZvIiwibm90aWNlIiwid2FybmluZyIsImVycm9yIiwiaXRlbSIsImNvbXBvbmVudE5hbWUiLCJjb21wb25lbnQiLCJhbGlhc2VzIiwiYXBpIiwicXVldWUiLCJzY2FubmVyIiwid29ya2VyIiwid29ya2VybG9jYWxzcGVlY2h0b3RleHRqb2JzIiwic2V0dGluZ3MiLCJ3ZWJfdWkiLCJzZXR1cCIsInN0b3JhZ2UiLCJ0cmFuc2NyaXB0Iiwic3lzdGVtIiwibG9jYWxzcGVlY2h0b3RleHRtYWluIiwibG9nZ2VyIiwibGFiZWxzIiwib3RoZXIiLCJsYWJlbCIsImZvcm1hdFRpbWVzdGFtcCIsInRpbWVzdGFtcCIsIkRhdGUiLCJpc05hTiIsImdldFRpbWUiLCJ0b0xvY2FsZVN0cmluZyIsInJlbmRlclN0YXRlIiwia2luZCIsIm1lc3NhZ2UiLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJ0ZXh0IiwiYWRkQ2xhc3MiLCJmaWx0ZXJFbnRyaWVzIiwiZW50cmllcyIsInF1ZXJ5IiwidG9Mb3dlckNhc2UiLCJlbnRyeSIsImhheXN0YWNrIiwic2V2ZXJpdHkiLCJwaWQiLCJKU09OIiwic3RyaW5naWZ5IiwiY29udGV4dCIsImpvaW4iLCJpbmRleE9mIiwicmVuZGVyRW50cmllcyIsImVtcHR5IiwiZm9yRWFjaCIsIiRjYXJkIiwiJGhlYWRlciIsIiRpZGVudGl0eSIsIiRsZXZlbCIsIiRjb21wb25lbnQiLCIkdGltZSIsInRzIiwiJG1lc3NhZ2UiLCIkZGV0YWlscyIsIiRzdW1tYXJ5IiwiZGV0YWlsVGV4dCIsInVuZGVmaW5lZCIsIk9iamVjdCIsImtleXMiLCJhcHBlbmQiLCJ1cGRhdGVDb21wb25lbnRPcHRpb25zIiwic2VsZWN0ZWQiLCJjb21wb25lbnRzIiwicHVzaCIsInNvcnQiLCJsZWZ0IiwicmlnaHQiLCJsb2NhbGVDb21wYXJlIiwidXBkYXRlTWV0YSIsImNvdW50IiwicmVzcG9uc2UiLCJmb3VuZCIsInVwZGF0ZWQiLCJnZW5lcmF0ZWRBdCIsInJlbmRlckN1cnJlbnQiLCJmaWx0ZXJlZCIsInNvdXJjZVN0YXR1cyIsIm5vdGljZXMiLCJtYWxmb3JtZWRDb3VudCIsInRydW5jYXRlZCIsInNldExvYWRpbmciLCJsb2FkTG9ncyIsImZvcmNlIiwicmVxdWVzdElkIiwiYWJvcnQiLCJjdXJyZW50UmVxdWVzdCIsImFqYXgiLCJ1cmwiLCJtZXRob2QiLCJkYXRhVHlwZSIsInBlcmlvZCIsImRvbmUiLCJBcnJheSIsImlzQXJyYXkiLCJmYWlsIiwieGhyIiwic3RhdHVzIiwiYWx3YXlzIiwiJGJ1dHRvbiIsImFkZCIsImNsb3Nlc3QiLCJoYXNDbGFzcyIsImluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyIiwiJHNldHRpbmdzRm9ybSIsIiRzbGlkZXIiLCIkaW5wdXQiLCJzbGlkZXIiLCJ2YWx1ZXMiLCJjdXJyZW50VmFsdWUiLCJpbml0aWFsSW5kZXgiLCJpbml0aWFsaXplZCIsIm1pbiIsIm1heCIsInN0ZXAiLCJzbW9vdGgiLCJhdXRvQWRqdXN0TGFiZWxzIiwiaW50ZXJwcmV0TGFiZWwiLCJvbkNoYW5nZSIsInVwZGF0ZVJldGVudGlvbkNvbmZsaWN0IiwiRm9ybSIsImNoZWNrVmFsdWVzIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIiLCJyZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nIiwicHJvY2Vzc2luZ0RheXMiLCJyZXRlbnRpb25EYXlzIiwiY29tcGF0aWJsZSIsInRvZ2dsZUNsYXNzIiwiYXR0ciIsImluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlIiwiJG1vZGVsRm9ybSIsIiRzYXZlQnV0dG9uIiwiJG1vZGVsSW5wdXRzIiwiJGNvbXBhdGliaWxpdHlNZXNzYWdlIiwiaW5pdGlhbFNlbGVjdGVkTW9kZWwiLCJzZWxlY3RlZExhbmd1YWdlIiwic2VsZWN0ZWRNb2RlbCIsInNlbGVjdGVkTW9kZWxTdXBwb3J0c0xhbmd1YWdlIiwiJHNlbGVjdGVkSW5wdXQiLCJzdXBwb3J0ZWRMYW5ndWFnZXMiLCJzcGxpdCIsIm1hcCIsImxhbmd1YWdlIiwidXBkYXRlQ29tcGF0aWJpbGl0eVN0YXRlIiwidXBkYXRlU2F2ZUJ1dHRvblN0YXRlIiwiaGFzQ2hhbmdlcyIsImFjY29yZGlvbiIsInRhcmdldCIsImNoZWNrYm94IiwiJHJvdyIsInNpYmxpbmdzIiwiaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCIsIiRtb2RhbCIsIm1vZGFsIiwic2hvd0NvbmZpcm0iLCJvbkNvbmZpcm0iLCJjbG9zYWJsZSIsIm9uQXBwcm92ZSIsImJpbmRDb25maXJtYWJsZUZvcm0iLCJzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24iLCIkc2VsZiIsIm9mZiIsImVhY2giLCJpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MiLCJkZXN0aW5hdGlvbiIsImhyZWYiLCIkdGFiSXRlbXMiLCJ0YWIiLCJkcm9wZG93biIsInBvcHVwIiwiZm9ybSIsInJ1bGVzIiwic3R0UHJvY2Vzc2luZ1JhbmdlIiwicmFuZ2UiLCJsaW1pdHMiLCJudW1iZXIiLCJzdHRQcm9jZXNzaW5nT3ZlcmxhcCIsIm92ZXJsYXBNUyIsIk1hdGgiLCJyb3VuZCIsImR1cmF0aW9uTVMiLCJzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5IiwicHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMiLCJ0ZW1wZXJhdHVyZSIsImlkZW50aWZpZXIiLCJ0eXBlIiwidGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcCIsInRlbXBlcmF0dXJlX2ZhbGxiYWNrX2NvdW50IiwidG9wX2siLCJjb21wcmVzc2lvbl9yYXRpb190aHJlc2hvbGQiLCJsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkIiwiZmlyc3RfdG9rZW5fbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZCIsIm5vX3NwZWVjaF90aHJlc2hvbGQiLCJtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWMiLCJzZWdtZW50X292ZXJsYXBfc2VjIiwidXBkYXRlVmFkRGVwZW5kZW50RmllbGRzIiwiZW5hYmxlZCIsImlzIiwibmF2aWdhdG9yIiwiY2xpcGJvYXJkIiwid3JpdGVUZXh0IiwidGhlbiIsIiRmb3JtT2JqIiwidmFsaWRhdGVSdWxlcyIsImFzc2lnbiIsImRlZmF1bHRfbGFuZ3VhZ2UiLCJjZHJfaGlzdG9yeV9kYXlzIiwidHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cyIsInByb21wdCIsImNiQmVmb3JlU2VuZEZvcm0iLCJjYkFmdGVyU2VuZEZvcm0iLCJhcGlTZXR0aW5ncyIsImFwaU9iamVjdCIsInNlbmRPbmx5Q2hhbmdlZCIsImVuYWJsZURpcnJpdHkiLCJpbml0aWFsaXplIiwiZmllbGRzIiwic2VsZWN0ZWRfbW9kZWwiXSwic291cmNlcyI6WyJzcmMvbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIl0sInNvdXJjZXNDb250ZW50IjpbIihmdW5jdGlvbiAoKSB7XG4gICAgJ3VzZSBzdHJpY3QnO1xuXG4gICAgZnVuY3Rpb24gdHJhbnNsYXRpb24oa2V5LCBmYWxsYmFjaykge1xuICAgICAgICBpZiAodHlwZW9mIGdsb2JhbFRyYW5zbGF0ZSAhPT0gJ3VuZGVmaW5lZCcgJiYgZ2xvYmFsVHJhbnNsYXRlW2tleV0pIHtcbiAgICAgICAgICAgIHJldHVybiBnbG9iYWxUcmFuc2xhdGVba2V5XTtcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiBmYWxsYmFjaztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiByZXF1ZXN0ZWRUYWIoKSB7XG4gICAgICAgIHZhciBwYXJhbXMgPSBuZXcgVVJMU2VhcmNoUGFyYW1zKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpO1xuICAgICAgICB2YXIgdGFiTmFtZSA9IHBhcmFtcy5nZXQoJ3RhYicpIHx8IHdpbmRvdy5sb2NhdGlvbi5oYXNoLnJlcGxhY2UoL14jLywgJycpO1xuXG4gICAgICAgIHJldHVybiAvXlthLXowLTlfLV0rJC8udGVzdCh0YWJOYW1lKSA/IHRhYk5hbWUgOiAnJztcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBjYWxlbmRhckxvY2FsZSgpIHtcbiAgICAgICAgdmFyIGRheXMgPSBbJ1N1JywgJ01vJywgJ1R1JywgJ1dlJywgJ1RoJywgJ0ZyJywgJ1NhJ107XG4gICAgICAgIHZhciBtb250aHMgPSBbXG4gICAgICAgICAgICAnSmFudWFyeScsXG4gICAgICAgICAgICAnRmVicnVhcnknLFxuICAgICAgICAgICAgJ01hcmNoJyxcbiAgICAgICAgICAgICdBcHJpbCcsXG4gICAgICAgICAgICAnTWF5JyxcbiAgICAgICAgICAgICdKdW5lJyxcbiAgICAgICAgICAgICdKdWx5JyxcbiAgICAgICAgICAgICdBdWd1c3QnLFxuICAgICAgICAgICAgJ1NlcHRlbWJlcicsXG4gICAgICAgICAgICAnT2N0b2JlcicsXG4gICAgICAgICAgICAnTm92ZW1iZXInLFxuICAgICAgICAgICAgJ0RlY2VtYmVyJ1xuICAgICAgICBdO1xuXG4gICAgICAgIGlmICh0eXBlb2YgU2VtYW50aWNMb2NhbGl6YXRpb24gIT09ICd1bmRlZmluZWQnICYmIFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dCkge1xuICAgICAgICAgICAgZGF5cyA9IFNlbWFudGljTG9jYWxpemF0aW9uLmNhbGVuZGFyVGV4dC5kYXlzIHx8IGRheXM7XG4gICAgICAgICAgICBtb250aHMgPSBTZW1hbnRpY0xvY2FsaXphdGlvbi5jYWxlbmRhclRleHQubW9udGhzIHx8IG1vbnRocztcbiAgICAgICAgfVxuXG4gICAgICAgIHJldHVybiB7XG4gICAgICAgICAgICBmb3JtYXQ6ICdERC9NTS9ZWVlZJyxcbiAgICAgICAgICAgIHNlcGFyYXRvcjogJyAtICcsXG4gICAgICAgICAgICBhcHBseUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0FwcGx5QnRuJywgJ0FwcGx5JyksXG4gICAgICAgICAgICBjYW5jZWxMYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9DYW5jZWxCdG4nLCAnQ2FuY2VsJyksXG4gICAgICAgICAgICBmcm9tTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfZnJvbScsICdGcm9tJyksXG4gICAgICAgICAgICB0b0xhYmVsOiB0cmFuc2xhdGlvbignY2FsX3RvJywgJ1RvJyksXG4gICAgICAgICAgICBjdXN0b21SYW5nZUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX0N1c3RvbVBlcmlvZCcsICdDdXN0b20gcmFuZ2UnKSxcbiAgICAgICAgICAgIGRheXNPZldlZWs6IGRheXMsXG4gICAgICAgICAgICBtb250aE5hbWVzOiBtb250aHMsXG4gICAgICAgICAgICBmaXJzdERheTogMVxuICAgICAgICB9O1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIG1vbWVudERhdGUodmFsdWUsIGZhbGxiYWNrKSB7XG4gICAgICAgIHZhciBkYXRlID0gbW9tZW50KHZhbHVlLCAnWVlZWS1NTS1ERCcsIHRydWUpO1xuXG4gICAgICAgIHJldHVybiBkYXRlLmlzVmFsaWQoKSA/IGRhdGUgOiBmYWxsYmFjay5jbG9uZSgpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlcigpIHtcbiAgICAgICAgdmFyICRmb3JtID0gJCgnI3N0dC10cmFuc2NyaXB0cy1maWx0ZXItZm9ybScpO1xuICAgICAgICB2YXIgJHNlbGVjdG9yID0gJCgnI3N0dC10cmFuc2NyaXB0cy1kYXRlLXJhbmdlLXNlbGVjdG9yJyk7XG5cbiAgICAgICAgaWYgKCRmb3JtLmxlbmd0aCA9PT0gMCB8fCAkc2VsZWN0b3IubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHR5cGVvZiBtb21lbnQgPT09ICd1bmRlZmluZWQnIHx8IHR5cGVvZiAkLmZuLmRhdGVyYW5nZXBpY2tlciA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciAkZGF0ZUZyb20gPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiZGF0ZV9mcm9tXCJdJyk7XG4gICAgICAgIHZhciAkZGF0ZVRvID0gJGZvcm0uZmluZCgnaW5wdXRbbmFtZT1cImRhdGVfdG9cIl0nKTtcbiAgICAgICAgdmFyICRzZWFyY2ggPSAkZm9ybS5maW5kKCdpbnB1dFtuYW1lPVwic2VhcmNoXCJdJyk7XG4gICAgICAgIHZhciBzZWFyY2hEZWJvdW5jZVRpbWVyID0gbnVsbDtcbiAgICAgICAgdmFyIHRvZGF5ID0gbW9tZW50KCk7XG4gICAgICAgIHZhciBzdGFydERhdGUgPSBtb21lbnREYXRlKCRkYXRlRnJvbS52YWwoKSwgdG9kYXkpO1xuICAgICAgICB2YXIgZW5kRGF0ZSA9IG1vbWVudERhdGUoJGRhdGVUby52YWwoKSwgc3RhcnREYXRlKTtcblxuICAgICAgICBpZiAoc3RhcnREYXRlLmlzQWZ0ZXIoZW5kRGF0ZSkpIHtcbiAgICAgICAgICAgIHZhciBzd2FwRGF0ZSA9IHN0YXJ0RGF0ZTtcbiAgICAgICAgICAgIHN0YXJ0RGF0ZSA9IGVuZERhdGU7XG4gICAgICAgICAgICBlbmREYXRlID0gc3dhcERhdGU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCkge1xuICAgICAgICAgICAgJGRhdGVGcm9tLnZhbChzdGFydC5mb3JtYXQoJ1lZWVktTU0tREQnKSk7XG4gICAgICAgICAgICAkZGF0ZVRvLnZhbChlbmQuZm9ybWF0KCdZWVlZLU1NLUREJykpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY2FuY2VsU2VhcmNoRGVib3VuY2UoKSB7XG4gICAgICAgICAgICBpZiAoc2VhcmNoRGVib3VuY2VUaW1lciAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5jbGVhclRpbWVvdXQoc2VhcmNoRGVib3VuY2VUaW1lcik7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzdWJtaXRUcmFuc2NyaXB0U2VhcmNoKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgICRmb3JtLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHN0YXJ0RGF0ZSwgZW5kRGF0ZSk7XG4gICAgICAgICRzZWxlY3Rvci5kYXRlcmFuZ2VwaWNrZXIoe1xuICAgICAgICAgICAgcmFuZ2VzOiB7XG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVG9kYXknLCAnVG9kYXknKV06IFttb21lbnQoKSwgbW9tZW50KCldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX1llc3RlcmRheScsICdZZXN0ZXJkYXknKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnZGF5cycpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0V2VlaycsICdMYXN0IDcgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDYsICdkYXlzJyksIG1vbWVudCgpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9MYXN0MzBEYXlzJywgJ0xhc3QgMzAgZGF5cycpXTogW21vbWVudCgpLnN1YnRyYWN0KDI5LCAnZGF5cycpLCBtb21lbnQoKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfVGhpc01vbnRoJywgJ1RoaXMgbW9udGgnKV06IFttb21lbnQoKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5lbmRPZignbW9udGgnKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfTGFzdE1vbnRoJywgJ0xhc3QgbW9udGgnKV06IFttb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5zdGFydE9mKCdtb250aCcpLCBtb21lbnQoKS5zdWJ0cmFjdCgxLCAnbW9udGgnKS5lbmRPZignbW9udGgnKV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBhbHdheXNTaG93Q2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgYXV0b1VwZGF0ZUlucHV0OiB0cnVlLFxuICAgICAgICAgICAgbGlua2VkQ2FsZW5kYXJzOiB0cnVlLFxuICAgICAgICAgICAgbWF4RGF0ZTogbW9tZW50KCksXG4gICAgICAgICAgICBzdGFydERhdGU6IHN0YXJ0RGF0ZS5zdGFydE9mKCdkYXknKSxcbiAgICAgICAgICAgIGVuZERhdGU6IGVuZERhdGUuZW5kT2YoJ2RheScpLFxuICAgICAgICAgICAgbG9jYWxlOiBjYWxlbmRhckxvY2FsZSgpXG4gICAgICAgIH0sIGZ1bmN0aW9uIChzdGFydCwgZW5kKSB7XG4gICAgICAgICAgICB1cGRhdGVIaWRkZW5JbnB1dHMoc3RhcnQsIGVuZCk7XG4gICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGNhbmNlbFNlYXJjaERlYm91bmNlKCk7XG4gICAgICAgICAgICB2YXIgcGlja2VyID0gJHNlbGVjdG9yLmRhdGEoJ2RhdGVyYW5nZXBpY2tlcicpO1xuICAgICAgICAgICAgaWYgKHBpY2tlciAmJiBwaWNrZXIuc3RhcnREYXRlICYmIHBpY2tlci5lbmREYXRlKSB7XG4gICAgICAgICAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHBpY2tlci5zdGFydERhdGUsIHBpY2tlci5lbmREYXRlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ2lucHV0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgY2FuY2VsU2VhcmNoRGVib3VuY2UoKTtcbiAgICAgICAgICAgIHNlYXJjaERlYm91bmNlVGltZXIgPSB3aW5kb3cuc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgICAgICAgICAgJGZvcm0udHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgICAgICB9LCA0MDApO1xuICAgICAgICB9KTtcbiAgICAgICAgJHNlYXJjaC5vbigna2V5ZG93bicsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKGV2ZW50LndoaWNoICE9PSAxMykge1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ3NlYXJjaCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGlmIChTdHJpbmcoJHNlYXJjaC52YWwoKSB8fCAnJykudHJpbSgpID09PSAnJykge1xuICAgICAgICAgICAgICAgIHN1Ym1pdFRyYW5zY3JpcHRTZWFyY2goKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZU1vZHVsZUxvZ3MoKSB7XG4gICAgICAgIHZhciAkZm9ybSA9ICQoJyNzdHQtbW9kdWxlLWxvZ3MtZm9ybScpO1xuICAgICAgICB2YXIgJHNlZ21lbnQgPSAkKCcjc3lzdGVtLWxvZ3Mtc2VnbWVudCcpO1xuICAgICAgICB2YXIgJHJlc3VsdHMgPSAkKCcjc3R0LWxvZy1yZXN1bHRzJyk7XG4gICAgICAgIHZhciAkc3RhdGUgPSAkKCcjc3R0LWxvZy1zdGF0ZScpO1xuICAgICAgICB2YXIgJHJlc3VsdENvdW50ID0gJCgnI3N0dC1sb2ctcmVzdWx0LWNvdW50Jyk7XG4gICAgICAgIHZhciAkdXBkYXRlZEF0ID0gJCgnI3N0dC1sb2ctdXBkYXRlZC1hdCcpO1xuICAgICAgICB2YXIgJGxldmVsRmlsdGVyID0gJCgnI3N0dC1sb2ctbGV2ZWwtZmlsdGVyJyk7XG4gICAgICAgIHZhciAkY29tcG9uZW50RmlsdGVyID0gJCgnI3N0dC1sb2ctY29tcG9uZW50LWZpbHRlcicpO1xuICAgICAgICB2YXIgJHNlYXJjaCA9ICQoJyNzdHQtbG9nLXNlYXJjaCcpO1xuXG4gICAgICAgIGlmICgkZm9ybS5sZW5ndGggPT09IDAgfHwgJHJlc3VsdHMubGVuZ3RoID09PSAwIHx8ICRzdGF0ZS5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciBkYXRhVXJsID0gJGZvcm0uZGF0YSgndXJsJyk7XG4gICAgICAgIHZhciAkcGVyaW9kQnV0dG9ucyA9ICRmb3JtLmZpbmQoJy5wZXJpb2QtYnRuJyk7XG4gICAgICAgIHZhciAkcmVmcmVzaEJ1dHRvbiA9ICRmb3JtLmZpbmQoJy5zdHQtbG9nLXJlZnJlc2gnKTtcbiAgICAgICAgdmFyIGN1cnJlbnRQZXJpb2QgPSBwYXJzZUludCgkcGVyaW9kQnV0dG9ucy5maWx0ZXIoJy5hY3RpdmUnKS5kYXRhKCdwZXJpb2QnKSwgMTApO1xuICAgICAgICB2YXIgbG9hZGVkT25jZSA9IGZhbHNlO1xuICAgICAgICB2YXIgcmVxdWVzdCA9IG51bGw7XG4gICAgICAgIHZhciByZXF1ZXN0R2VuZXJhdGlvbiA9IDA7XG4gICAgICAgIHZhciBjdXJyZW50RW50cmllcyA9IFtdO1xuICAgICAgICB2YXIgY3VycmVudFJlc3BvbnNlID0gbnVsbDtcblxuICAgICAgICBpZiAoIU51bWJlci5pc0Zpbml0ZShjdXJyZW50UGVyaW9kKSkge1xuICAgICAgICAgICAgY3VycmVudFBlcmlvZCA9IDM2MDA7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBsZXZlbE5hbWUobGV2ZWwpIHtcbiAgICAgICAgICAgIHZhciBsZXZlbHMgPSB7XG4gICAgICAgICAgICAgICAgZGVidWc6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsRGVidWcnLCAnRGVidWcnXSxcbiAgICAgICAgICAgICAgICBpbmZvOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbEluZm8nLCAnSW5mbyddLFxuICAgICAgICAgICAgICAgIG5vdGljZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxOb3RpY2UnLCAnTm90aWNlJ10sXG4gICAgICAgICAgICAgICAgd2FybmluZzogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxXYXJuaW5nJywgJ1dhcm5pbmcnXSxcbiAgICAgICAgICAgICAgICBlcnJvcjogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxFcnJvcicsICdFcnJvciddXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGl0ZW0gPSBsZXZlbHNbbGV2ZWxdIHx8IGxldmVscy5pbmZvO1xuXG4gICAgICAgICAgICByZXR1cm4gdHJhbnNsYXRpb24oaXRlbVswXSwgaXRlbVsxXSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBjb21wb25lbnROYW1lKGNvbXBvbmVudCkge1xuICAgICAgICAgICAgdmFyIGFsaWFzZXMgPSB7XG4gICAgICAgICAgICAgICAgYXBpOiAnYXBpJyxcbiAgICAgICAgICAgICAgICBxdWV1ZTogJ3F1ZXVlJyxcbiAgICAgICAgICAgICAgICBzY2FubmVyOiAnc2Nhbm5lcicsXG4gICAgICAgICAgICAgICAgd29ya2VyOiAnd29ya2VyJyxcbiAgICAgICAgICAgICAgICB3b3JrZXJsb2NhbHNwZWVjaHRvdGV4dGpvYnM6ICd3b3JrZXInLFxuICAgICAgICAgICAgICAgIHNldHRpbmdzOiAnc2V0dGluZ3MnLFxuICAgICAgICAgICAgICAgIHdlYl91aTogJ3NldHRpbmdzJyxcbiAgICAgICAgICAgICAgICBzZXR1cDogJ3NldHVwJyxcbiAgICAgICAgICAgICAgICBzdG9yYWdlOiAnc3RvcmFnZScsXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdDogJ3RyYW5zY3JpcHQnLFxuICAgICAgICAgICAgICAgIHN5c3RlbTogJ3N5c3RlbScsXG4gICAgICAgICAgICAgICAgbG9jYWxzcGVlY2h0b3RleHRtYWluOiAnc3lzdGVtJyxcbiAgICAgICAgICAgICAgICBsb2dnZXI6ICdzeXN0ZW0nXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgICAgICBhcGk6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudEFwaScsICdBUEknXSxcbiAgICAgICAgICAgICAgICBxdWV1ZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50UXVldWUnLCAnUXVldWUnXSxcbiAgICAgICAgICAgICAgICBzY2FubmVyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTY2FubmVyJywgJ1JlY29yZGluZyBzY2FubmVyJ10sXG4gICAgICAgICAgICAgICAgd29ya2VyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRXb3JrZXInLCAnV29ya2VyJ10sXG4gICAgICAgICAgICAgICAgc2V0dGluZ3M6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNldHRpbmdzJywgJ1NldHRpbmdzJ10sXG4gICAgICAgICAgICAgICAgc2V0dXA6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFNldHVwJywgJ1NldHVwJ10sXG4gICAgICAgICAgICAgICAgc3RvcmFnZTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U3RvcmFnZScsICdTdG9yYWdlJ10sXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdDogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50VHJhbnNjcmlwdCcsICdUcmFuc2NyaXB0cyddLFxuICAgICAgICAgICAgICAgIHN5c3RlbTogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U3lzdGVtJywgJ1N5c3RlbSddLFxuICAgICAgICAgICAgICAgIG90aGVyOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRPdGhlcicsICdPdGhlciddXG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgdmFyIGxhYmVsID0gbGFiZWxzW2FsaWFzZXNbY29tcG9uZW50XSB8fCAnb3RoZXInXTtcblxuICAgICAgICAgICAgcmV0dXJuIHRyYW5zbGF0aW9uKGxhYmVsWzBdLCBsYWJlbFsxXSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmb3JtYXRUaW1lc3RhbXAodGltZXN0YW1wKSB7XG4gICAgICAgICAgICB2YXIgZGF0ZSA9IG5ldyBEYXRlKHRpbWVzdGFtcCk7XG4gICAgICAgICAgICBpZiAoTnVtYmVyLmlzTmFOKGRhdGUuZ2V0VGltZSgpKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aW1lc3RhbXAgfHwgJ+KAlCc7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHJldHVybiBkYXRlLnRvTG9jYWxlU3RyaW5nKCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJTdGF0ZShraW5kLCBtZXNzYWdlKSB7XG4gICAgICAgICAgICBpZiAoIW1lc3NhZ2UpIHtcbiAgICAgICAgICAgICAgICAkc3RhdGUucHJvcCgnaGlkZGVuJywgdHJ1ZSkucmVtb3ZlQ2xhc3MoJ2luZm8gd2FybmluZyBlcnJvcicpLnRleHQoJycpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgJHN0YXRlXG4gICAgICAgICAgICAgICAgLnJlbW92ZUNsYXNzKCdpbmZvIHdhcm5pbmcgZXJyb3InKVxuICAgICAgICAgICAgICAgIC5hZGRDbGFzcyhraW5kIHx8ICdpbmZvJylcbiAgICAgICAgICAgICAgICAudGV4dChtZXNzYWdlKVxuICAgICAgICAgICAgICAgIC5wcm9wKCdoaWRkZW4nLCBmYWxzZSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBmaWx0ZXJFbnRyaWVzKGVudHJpZXMpIHtcbiAgICAgICAgICAgIHZhciBsZXZlbCA9IFN0cmluZygkbGV2ZWxGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIGNvbXBvbmVudCA9IFN0cmluZygkY29tcG9uZW50RmlsdGVyLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBxdWVyeSA9IFN0cmluZygkc2VhcmNoLnZhbCgpIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICAgICAgcmV0dXJuIGVudHJpZXMuZmlsdGVyKGZ1bmN0aW9uIChlbnRyeSkge1xuICAgICAgICAgICAgICAgIGlmIChsZXZlbCAmJiBlbnRyeS5sZXZlbCAhPT0gbGV2ZWwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoY29tcG9uZW50ICYmIGVudHJ5LmNvbXBvbmVudCAhPT0gY29tcG9uZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKCFxdWVyeSkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICB2YXIgaGF5c3RhY2sgPSBbXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5Lm1lc3NhZ2UsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LmNvbXBvbmVudCxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkuZXZlbnQsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LnNldmVyaXR5LFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5waWQsXG4gICAgICAgICAgICAgICAgICAgIEpTT04uc3RyaW5naWZ5KGVudHJ5LmNvbnRleHQgfHwge30pXG4gICAgICAgICAgICAgICAgXS5qb2luKCcgJykudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBoYXlzdGFjay5pbmRleE9mKHF1ZXJ5KSAhPT0gLTE7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHJlbmRlckVudHJpZXMoZW50cmllcykge1xuICAgICAgICAgICAgJHJlc3VsdHMuZW1wdHkoKTtcbiAgICAgICAgICAgIGVudHJpZXMuZm9yRWFjaChmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICB2YXIgJGNhcmQgPSAkKCc8YXJ0aWNsZT4nKS5hZGRDbGFzcygnc3R0LWxvZy1lbnRyeSBzdHQtbG9nLWxldmVsLScgKyBlbnRyeS5sZXZlbCk7XG4gICAgICAgICAgICAgICAgdmFyICRoZWFkZXIgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5LWhlYWRlcicpO1xuICAgICAgICAgICAgICAgIHZhciAkaWRlbnRpdHkgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLWVudHJ5LWlkZW50aXR5Jyk7XG4gICAgICAgICAgICAgICAgdmFyICRsZXZlbCA9ICQoJzxzcGFuPicpLmFkZENsYXNzKCd1aSB0aW55IGxhYmVsIHN0dC1sb2ctbGV2ZWwtbGFiZWwnKS50ZXh0KGxldmVsTmFtZShlbnRyeS5sZXZlbCkpO1xuICAgICAgICAgICAgICAgIHZhciAkY29tcG9uZW50ID0gJCgnPHNwYW4+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctY29tcG9uZW50JykudGV4dChjb21wb25lbnROYW1lKGVudHJ5LmNvbXBvbmVudCkpO1xuICAgICAgICAgICAgICAgIHZhciAkdGltZSA9ICQoJzx0aW1lPicpLmFkZENsYXNzKCdzdHQtbG9nLXRpbWUnKS50ZXh0KGZvcm1hdFRpbWVzdGFtcChlbnRyeS50cykpO1xuICAgICAgICAgICAgICAgIHZhciAkbWVzc2FnZSA9ICQoJzxkaXY+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctbWVzc2FnZScpLnRleHQoZW50cnkubWVzc2FnZSB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgdmFyICRkZXRhaWxzID0gJCgnPGRldGFpbHM+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZGV0YWlscycpO1xuICAgICAgICAgICAgICAgIHZhciAkc3VtbWFyeSA9ICQoJzxzdW1tYXJ5PicpLnRleHQodHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1RlY2huaWNhbERldGFpbHMnLFxuICAgICAgICAgICAgICAgICAgICAnVGVjaG5pY2FsIGRldGFpbHMnXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgdmFyIGRldGFpbFRleHQgPSB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NFdmVudCcsICdFdmVudCcpXG4gICAgICAgICAgICAgICAgICAgICsgJzogJyArIChlbnRyeS5ldmVudCB8fCAn4oCUJyk7XG4gICAgICAgICAgICAgICAgZGV0YWlsVGV4dCArPSAnXFxuc2V2ZXJpdHk6ICcgKyAoZW50cnkuc2V2ZXJpdHkgfHwgJ+KAlCcpO1xuICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbnBpZDogJyArIChlbnRyeS5waWQgPT09IHVuZGVmaW5lZCA/ICfigJQnIDogZW50cnkucGlkKTtcbiAgICAgICAgICAgICAgICBpZiAoZW50cnkuY29udGV4dCAmJiBPYmplY3Qua2V5cyhlbnRyeS5jb250ZXh0KS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbicgKyBKU09OLnN0cmluZ2lmeShlbnRyeS5jb250ZXh0LCBudWxsLCAyKTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICAkaWRlbnRpdHkuYXBwZW5kKCRsZXZlbCwgJGNvbXBvbmVudCk7XG4gICAgICAgICAgICAgICAgJGhlYWRlci5hcHBlbmQoJGlkZW50aXR5LCAkdGltZSk7XG4gICAgICAgICAgICAgICAgJGRldGFpbHMuYXBwZW5kKCRzdW1tYXJ5LCAkKCc8cHJlPicpLnRleHQoZGV0YWlsVGV4dCkpO1xuICAgICAgICAgICAgICAgICRjYXJkLmFwcGVuZCgkaGVhZGVyLCAkbWVzc2FnZSwgJGRldGFpbHMpO1xuICAgICAgICAgICAgICAgICRyZXN1bHRzLmFwcGVuZCgkY2FyZCk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZUNvbXBvbmVudE9wdGlvbnMoZW50cmllcykge1xuICAgICAgICAgICAgdmFyIHNlbGVjdGVkID0gU3RyaW5nKCRjb21wb25lbnRGaWx0ZXIudmFsKCkgfHwgJycpO1xuICAgICAgICAgICAgdmFyIGNvbXBvbmVudHMgPSBbXTtcbiAgICAgICAgICAgIGVudHJpZXMuZm9yRWFjaChmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICBpZiAoZW50cnkuY29tcG9uZW50ICYmIGNvbXBvbmVudHMuaW5kZXhPZihlbnRyeS5jb21wb25lbnQpID09PSAtMSkge1xuICAgICAgICAgICAgICAgICAgICBjb21wb25lbnRzLnB1c2goZW50cnkuY29tcG9uZW50KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIGNvbXBvbmVudHMuc29ydChmdW5jdGlvbiAobGVmdCwgcmlnaHQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gY29tcG9uZW50TmFtZShsZWZ0KS5sb2NhbGVDb21wYXJlKGNvbXBvbmVudE5hbWUocmlnaHQpKTtcbiAgICAgICAgICAgIH0pO1xuXG4gICAgICAgICAgICAkY29tcG9uZW50RmlsdGVyLmVtcHR5KCkuYXBwZW5kKFxuICAgICAgICAgICAgICAgICQoJzxvcHRpb24+JykudmFsKCcnKS50ZXh0KHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NDb21wb25lbnRBbGwnLFxuICAgICAgICAgICAgICAgICAgICAnQWxsIGNvbXBvbmVudHMnXG4gICAgICAgICAgICAgICAgKSlcbiAgICAgICAgICAgICk7XG4gICAgICAgICAgICBjb21wb25lbnRzLmZvckVhY2goZnVuY3Rpb24gKGNvbXBvbmVudCkge1xuICAgICAgICAgICAgICAgICRjb21wb25lbnRGaWx0ZXIuYXBwZW5kKCQoJzxvcHRpb24+JykudmFsKGNvbXBvbmVudCkudGV4dChjb21wb25lbnROYW1lKGNvbXBvbmVudCkpKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgaWYgKHNlbGVjdGVkICYmIGNvbXBvbmVudHMuaW5kZXhPZihzZWxlY3RlZCkgIT09IC0xKSB7XG4gICAgICAgICAgICAgICAgJGNvbXBvbmVudEZpbHRlci52YWwoc2VsZWN0ZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlTWV0YShjb3VudCwgcmVzcG9uc2UpIHtcbiAgICAgICAgICAgIHZhciBmb3VuZCA9IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0ZvdW5kJywgJyVjb3VudCUgZXZlbnRzJylcbiAgICAgICAgICAgICAgICAucmVwbGFjZSgnJWNvdW50JScsIGNvdW50KTtcbiAgICAgICAgICAgIHZhciB1cGRhdGVkID0gdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVXBkYXRlZCcsICdVcGRhdGVkOiAldGltZSUnKVxuICAgICAgICAgICAgICAgIC5yZXBsYWNlKCcldGltZSUnLCByZXNwb25zZSAmJiByZXNwb25zZS5nZW5lcmF0ZWRBdFxuICAgICAgICAgICAgICAgICAgICA/IHJlc3BvbnNlLmdlbmVyYXRlZEF0XG4gICAgICAgICAgICAgICAgICAgIDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzTmV2ZXInLCAnbmV2ZXInKSk7XG4gICAgICAgICAgICAkcmVzdWx0Q291bnQudGV4dChmb3VuZCk7XG4gICAgICAgICAgICAkdXBkYXRlZEF0LnRleHQodXBkYXRlZCk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJDdXJyZW50KCkge1xuICAgICAgICAgICAgaWYgKCFjdXJyZW50UmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICB2YXIgZmlsdGVyZWQgPSBmaWx0ZXJFbnRyaWVzKGN1cnJlbnRFbnRyaWVzKTtcbiAgICAgICAgICAgIHJlbmRlckVudHJpZXMoZmlsdGVyZWQpO1xuICAgICAgICAgICAgdXBkYXRlTWV0YShmaWx0ZXJlZC5sZW5ndGgsIGN1cnJlbnRSZXNwb25zZSk7XG5cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAnbWlzc2luZycpIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NNaXNzaW5nJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBtb2R1bGUgam91cm5hbCBoYXMgbm90IGJlZW4gY3JlYXRlZCB5ZXQuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAndW5hdmFpbGFibGUnKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1VuYXZhaWxhYmxlJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBtb2R1bGUgam91cm5hbCBpcyB1bmF2YWlsYWJsZS4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdjb3JydXB0Jykge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdlcnJvcicsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NDb3JydXB0JyxcbiAgICAgICAgICAgICAgICAgICAgJ1RoZSBqb3VybmFsIGNvbnRhaW5zIG5vIHJlYWRhYmxlIGV2ZW50cy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRFbnRyaWVzLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdpbmZvJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0VtcHR5JyxcbiAgICAgICAgICAgICAgICAgICAgJ05vIG1vZHVsZSBldmVudHMgd2VyZSBmb3VuZCBmb3IgdGhpcyBwZXJpb2QuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChmaWx0ZXJlZC5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NGaWx0ZXJlZEVtcHR5JyxcbiAgICAgICAgICAgICAgICAgICAgJ05vIGV2ZW50cyBtYXRjaCB0aGUgc2VsZWN0ZWQgZmlsdGVycy4nXG4gICAgICAgICAgICAgICAgKSk7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICB2YXIgbm90aWNlcyA9IFtdO1xuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS5zb3VyY2VTdGF0dXMgPT09ICdwYXJ0aWFsJykge1xuICAgICAgICAgICAgICAgIG5vdGljZXMucHVzaCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzUGFydGlhbCcsXG4gICAgICAgICAgICAgICAgICAgICclY291bnQlIGRhbWFnZWQgam91cm5hbCBsaW5lcyB3ZXJlIHNraXBwZWQuJ1xuICAgICAgICAgICAgICAgICkucmVwbGFjZSgnJWNvdW50JScsIGN1cnJlbnRSZXNwb25zZS5tYWxmb3JtZWRDb3VudCB8fCAwKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnRydW5jYXRlZCkge1xuICAgICAgICAgICAgICAgIG5vdGljZXMucHVzaCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzVHJ1bmNhdGVkJyxcbiAgICAgICAgICAgICAgICAgICAgJ09ubHkgdGhlIG5ld2VzdCAxMDAwIGV2ZW50cyBhcmUgc2hvd24uJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmVuZGVyU3RhdGUobm90aWNlcy5sZW5ndGggPiAwID8gJ3dhcm5pbmcnIDogJycsIG5vdGljZXMuam9pbignICcpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNldExvYWRpbmcoKSB7XG4gICAgICAgICAgICAkc2VnbWVudC5hZGRDbGFzcygnc3R0LWxvZ3MtbG9hZGluZycpO1xuICAgICAgICAgICAgJHJlc3VsdHMuZW1wdHkoKTtcbiAgICAgICAgICAgIHVwZGF0ZU1ldGEoMCwgbnVsbCk7XG4gICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0xvYWRpbmcnLFxuICAgICAgICAgICAgICAgICdMb2FkaW5nIG1vZHVsZSBldmVudHMuLi4nXG4gICAgICAgICAgICApKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGxvYWRMb2dzKGZvcmNlKSB7XG4gICAgICAgICAgICBpZiAoIWZvcmNlICYmIGxvYWRlZE9uY2UpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoIWRhdGFVcmwpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXF1ZXN0R2VuZXJhdGlvbiArPSAxO1xuICAgICAgICAgICAgdmFyIHJlcXVlc3RJZCA9IHJlcXVlc3RHZW5lcmF0aW9uO1xuICAgICAgICAgICAgaWYgKHJlcXVlc3QgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICByZXF1ZXN0LmFib3J0KCk7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHNldExvYWRpbmcoKTtcbiAgICAgICAgICAgICRyZWZyZXNoQnV0dG9uLmFkZENsYXNzKCdsb2FkaW5nIGRpc2FibGVkJyk7XG4gICAgICAgICAgICB2YXIgY3VycmVudFJlcXVlc3QgPSAkLmFqYXgoe1xuICAgICAgICAgICAgICAgIHVybDogZGF0YVVybCxcbiAgICAgICAgICAgICAgICBtZXRob2Q6ICdHRVQnLFxuICAgICAgICAgICAgICAgIGRhdGFUeXBlOiAnanNvbicsXG4gICAgICAgICAgICAgICAgZGF0YToge1xuICAgICAgICAgICAgICAgICAgICBwZXJpb2Q6IGN1cnJlbnRQZXJpb2RcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5kb25lKGZ1bmN0aW9uIChyZXNwb25zZSkge1xuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0SWQgIT09IHJlcXVlc3RHZW5lcmF0aW9uKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY3VycmVudFJlc3BvbnNlID0gcmVzcG9uc2UgfHwge307XG4gICAgICAgICAgICAgICAgY3VycmVudEVudHJpZXMgPSBBcnJheS5pc0FycmF5KGN1cnJlbnRSZXNwb25zZS5lbnRyaWVzKSA/IGN1cnJlbnRSZXNwb25zZS5lbnRyaWVzIDogW107XG4gICAgICAgICAgICAgICAgdXBkYXRlQ29tcG9uZW50T3B0aW9ucyhjdXJyZW50RW50cmllcyk7XG4gICAgICAgICAgICAgICAgcmVuZGVyQ3VycmVudCgpO1xuICAgICAgICAgICAgICAgIGxvYWRlZE9uY2UgPSB0cnVlO1xuICAgICAgICAgICAgfSkuZmFpbChmdW5jdGlvbiAoeGhyLCBzdGF0dXMpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbiB8fCBzdGF0dXMgPT09ICdhYm9ydCcpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjdXJyZW50UmVzcG9uc2UgPSBudWxsO1xuICAgICAgICAgICAgICAgIGN1cnJlbnRFbnRyaWVzID0gW107XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2Vycm9yJywgdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc0Vycm9yJyxcbiAgICAgICAgICAgICAgICAgICAgJ1VuYWJsZSB0byBsb2FkIG1vZHVsZSBldmVudHMuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgfSkuYWx3YXlzKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmIChyZXF1ZXN0ID09PSBjdXJyZW50UmVxdWVzdCkge1xuICAgICAgICAgICAgICAgICAgICByZXF1ZXN0ID0gbnVsbDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgJHNlZ21lbnQucmVtb3ZlQ2xhc3MoJ3N0dC1sb2dzLWxvYWRpbmcnKTtcbiAgICAgICAgICAgICAgICAkcmVmcmVzaEJ1dHRvbi5yZW1vdmVDbGFzcygnbG9hZGluZyBkaXNhYmxlZCcpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICByZXF1ZXN0ID0gY3VycmVudFJlcXVlc3Q7XG4gICAgICAgIH1cblxuICAgICAgICAkcGVyaW9kQnV0dG9ucy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJGJ1dHRvbiA9ICQodGhpcyk7XG4gICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gcGFyc2VJbnQoJGJ1dHRvbi5kYXRhKCdwZXJpb2QnKSwgMTApO1xuICAgICAgICAgICAgaWYgKCFOdW1iZXIuaXNGaW5pdGUoY3VycmVudFBlcmlvZCkpIHtcbiAgICAgICAgICAgICAgICBjdXJyZW50UGVyaW9kID0gMzYwMDtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgICRwZXJpb2RCdXR0b25zLnJlbW92ZUNsYXNzKCdhY3RpdmUnKTtcbiAgICAgICAgICAgICRidXR0b24uYWRkQ2xhc3MoJ2FjdGl2ZScpO1xuICAgICAgICAgICAgbG9hZGVkT25jZSA9IGZhbHNlO1xuICAgICAgICAgICAgbG9hZExvZ3ModHJ1ZSk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRyZWZyZXNoQnV0dG9uLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGxvYWRMb2dzKHRydWUpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbGV2ZWxGaWx0ZXIuYWRkKCRjb21wb25lbnRGaWx0ZXIpLm9uKCdjaGFuZ2UnLCByZW5kZXJDdXJyZW50KTtcbiAgICAgICAgJHNlYXJjaC5vbignaW5wdXQnLCByZW5kZXJDdXJyZW50KTtcblxuICAgICAgICAkKCcjbW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LW1lbnUgLml0ZW1bZGF0YS10YWI9XCJsb2dzXCJdJykub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgd2luZG93LnNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGxvYWRMb2dzKGZhbHNlKTtcbiAgICAgICAgICAgIH0sIDApO1xuICAgICAgICB9KTtcblxuICAgICAgICB1cGRhdGVNZXRhKDAsIG51bGwpO1xuICAgICAgICBpZiAocmVxdWVzdGVkVGFiKCkgPT09ICdsb2dzJyB8fCAkZm9ybS5jbG9zZXN0KCcudGFiLnNlZ21lbnQnKS5oYXNDbGFzcygnYWN0aXZlJykpIHtcbiAgICAgICAgICAgIGxvYWRMb2dzKGZhbHNlKTtcbiAgICAgICAgfVxuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyKCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyICRzbGlkZXIgPSAkKCcjc3R0LWNkci1oaXN0b3J5LWRheXMtc2xpZGVyJyk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJjZHJfaGlzdG9yeV9kYXlzXCJdJyk7XG5cbiAgICAgICAgaWYgKCRzbGlkZXIubGVuZ3RoID09PSAwIHx8ICRpbnB1dC5sZW5ndGggPT09IDAgfHwgdHlwZW9mICRzbGlkZXIuc2xpZGVyICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgdmFsdWVzID0gWycxJywgJzcnLCAnMzAnLCAnOTAnLCAnMTgwJywgJzM2NScsICcwJ107XG4gICAgICAgIHZhciBsYWJlbHMgPSB7XG4gICAgICAgICAgICAwOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkxRGF5JywgJzEgZGF5JyksXG4gICAgICAgICAgICAxOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3Rvcnk3RGF5cycsICc3IGRheXMnKSxcbiAgICAgICAgICAgIDI6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTMwRGF5cycsICczMCBkYXlzJyksXG4gICAgICAgICAgICAzOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3Rvcnk5MERheXMnLCAnOTAgZGF5cycpLFxuICAgICAgICAgICAgNDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MTgwRGF5cycsICc2IG1vbnRocycpLFxuICAgICAgICAgICAgNTogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MzY1RGF5cycsICcxIHllYXInKSxcbiAgICAgICAgICAgIDY6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeUFsbCcsICdBbGwnKVxuICAgICAgICB9O1xuICAgICAgICB2YXIgY3VycmVudFZhbHVlID0gU3RyaW5nKCRpbnB1dC52YWwoKSB8fCAnMzAnKTtcbiAgICAgICAgdmFyIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKGN1cnJlbnRWYWx1ZSk7XG4gICAgICAgIHZhciBpbml0aWFsaXplZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmIChpbml0aWFsSW5kZXggPCAwKSB7XG4gICAgICAgICAgICBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZignMzAnKTtcbiAgICAgICAgICAgICRpbnB1dC52YWwoJzMwJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2xpZGVyLnNsaWRlcih7XG4gICAgICAgICAgICBtaW46IDAsXG4gICAgICAgICAgICBtYXg6IHZhbHVlcy5sZW5ndGggLSAxLFxuICAgICAgICAgICAgc3RlcDogMSxcbiAgICAgICAgICAgIHNtb290aDogdHJ1ZSxcbiAgICAgICAgICAgIGF1dG9BZGp1c3RMYWJlbHM6IGZhbHNlLFxuICAgICAgICAgICAgaW50ZXJwcmV0TGFiZWw6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBsYWJlbHNbdmFsdWVdIHx8ICcnO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG9uQ2hhbmdlOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgc2VsZWN0ZWQgPSB2YWx1ZXNbdmFsdWVdIHx8ICczMCc7XG5cbiAgICAgICAgICAgICAgICAkaW5wdXQudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgICAgICBpZiAoaW5pdGlhbGl6ZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgJGlucHV0LnRyaWdnZXIoJ2NoYW5nZScpO1xuICAgICAgICAgICAgICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiB0eXBlb2YgRm9ybS5jaGVja1ZhbHVlcyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgRm9ybS5jaGVja1ZhbHVlcygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNsaWRlci5zbGlkZXIoJ3NldCB2YWx1ZScsIGluaXRpYWxJbmRleCwgZmFsc2UpO1xuICAgICAgICBpbml0aWFsaXplZCA9IHRydWU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIoJHNldHRpbmdzRm9ybSkge1xuICAgICAgICB2YXIgJHNsaWRlciA9ICQoJyNzdHQtdHJhbnNjcmlwdC1yZXRlbnRpb24tZGF5cy1zbGlkZXInKTtcbiAgICAgICAgdmFyICRpbnB1dCA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXNcIl0nKTtcblxuICAgICAgICBpZiAoJHNsaWRlci5sZW5ndGggPT09IDAgfHwgJGlucHV0Lmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJHNsaWRlci5zbGlkZXIgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciB2YWx1ZXMgPSBbJzMwJywgJzkwJywgJzE4MCcsICczNjUnLCAnMCddO1xuICAgICAgICB2YXIgbGFiZWxzID0ge1xuICAgICAgICAgICAgMDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uMzBEYXlzJywgJzMwIGRheXMnKSxcbiAgICAgICAgICAgIDE6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjkwRGF5cycsICc5MCBkYXlzJyksXG4gICAgICAgICAgICAyOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24xODBEYXlzJywgJzE4MCBkYXlzJyksXG4gICAgICAgICAgICAzOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb24zNjVEYXlzJywgJzEgeWVhcicpLFxuICAgICAgICAgICAgNDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uVW5saW1pdGVkJywgJ1VubGltaXRlZCcpXG4gICAgICAgIH07XG4gICAgICAgIHZhciBjdXJyZW50VmFsdWUgPSBTdHJpbmcoJGlucHV0LnZhbCgpIHx8ICczNjUnKTtcbiAgICAgICAgdmFyIGluaXRpYWxJbmRleCA9IHZhbHVlcy5pbmRleE9mKGN1cnJlbnRWYWx1ZSk7XG4gICAgICAgIHZhciBpbml0aWFsaXplZCA9IGZhbHNlO1xuXG4gICAgICAgIGlmIChpbml0aWFsSW5kZXggPCAwKSB7XG4gICAgICAgICAgICBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZignMzY1Jyk7XG4gICAgICAgICAgICAkaW5wdXQudmFsKCczNjUnKTtcbiAgICAgICAgfVxuXG4gICAgICAgICRzbGlkZXIuc2xpZGVyKHtcbiAgICAgICAgICAgIG1pbjogMCxcbiAgICAgICAgICAgIG1heDogdmFsdWVzLmxlbmd0aCAtIDEsXG4gICAgICAgICAgICBzdGVwOiAxLFxuICAgICAgICAgICAgc21vb3RoOiB0cnVlLFxuICAgICAgICAgICAgYXV0b0FkanVzdExhYmVsczogZmFsc2UsXG4gICAgICAgICAgICBpbnRlcnByZXRMYWJlbDogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGxhYmVsc1t2YWx1ZV0gfHwgJyc7XG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgb25DaGFuZ2U6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHZhciBzZWxlY3RlZCA9IHZhbHVlc1t2YWx1ZV0gfHwgJzM2NSc7XG5cbiAgICAgICAgICAgICAgICAkaW5wdXQudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgICAgICBpZiAoaW5pdGlhbGl6ZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgJGlucHV0LnRyaWdnZXIoJ2NoYW5nZScpO1xuICAgICAgICAgICAgICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKHR5cGVvZiBGb3JtICE9PSAndW5kZWZpbmVkJyAmJiB0eXBlb2YgRm9ybS5jaGVja1ZhbHVlcyA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgICAgICAgICAgICAgRm9ybS5jaGVja1ZhbHVlcygpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICAgICAgJHNsaWRlci5zbGlkZXIoJ3NldCB2YWx1ZScsIGluaXRpYWxJbmRleCwgZmFsc2UpO1xuICAgICAgICBpbml0aWFsaXplZCA9IHRydWU7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gcmV0ZW50aW9uQWxsb3dzUHJvY2Vzc2luZyhwcm9jZXNzaW5nRGF5cywgcmV0ZW50aW9uRGF5cykge1xuICAgICAgICByZXR1cm4gcmV0ZW50aW9uRGF5cyA9PT0gMCB8fCAocHJvY2Vzc2luZ0RheXMgPiAwICYmIHByb2Nlc3NpbmdEYXlzIDw9IHJldGVudGlvbkRheXMpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyIHByb2Nlc3NpbmdEYXlzID0gTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cImNkcl9oaXN0b3J5X2RheXNcIl0nKS52YWwoKSk7XG4gICAgICAgIHZhciByZXRlbnRpb25EYXlzID0gTnVtYmVyKCRzZXR0aW5nc0Zvcm0uZmluZCgnaW5wdXRbbmFtZT1cInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXNcIl0nKS52YWwoKSk7XG4gICAgICAgIHZhciBjb21wYXRpYmxlID0gTnVtYmVyLmlzRmluaXRlKHByb2Nlc3NpbmdEYXlzKVxuICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKHJldGVudGlvbkRheXMpXG4gICAgICAgICAgICAmJiByZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nKHByb2Nlc3NpbmdEYXlzLCByZXRlbnRpb25EYXlzKTtcbiAgICAgICAgdmFyICRtZXNzYWdlID0gJHNldHRpbmdzRm9ybS5maW5kKCcuc3R0LXJldGVudGlvbi1jb25mbGljdC1tZXNzYWdlJyk7XG5cbiAgICAgICAgJG1lc3NhZ2UudG9nZ2xlQ2xhc3MoJ2hpZGRlbicsIGNvbXBhdGlibGUpLmF0dHIoJ2FyaWEtaGlkZGVuJywgY29tcGF0aWJsZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuXG4gICAgICAgIHJldHVybiBjb21wYXRpYmxlO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlKCRtb2RlbEZvcm0pIHtcbiAgICAgICAgdmFyICRzYXZlQnV0dG9uID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tb2RlbC1zYXZlJyk7XG4gICAgICAgIHZhciAkbW9kZWxJbnB1dHMgPSAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgaW5wdXRbbmFtZT1cInNlbGVjdGVkX21vZGVsXCJdJyk7XG4gICAgICAgIHZhciAkY29tcGF0aWJpbGl0eU1lc3NhZ2UgPSAkKCcjc3R0LW1vZGVsLWxhbmd1YWdlLWNvbXBhdGliaWxpdHknKTtcbiAgICAgICAgdmFyIGluaXRpYWxTZWxlY3RlZE1vZGVsID0gU3RyaW5nKCRtb2RlbEZvcm0uZGF0YSgnaW5pdGlhbC1zZWxlY3RlZC1tb2RlbCcpIHx8ICRtb2RlbElucHV0cy5maWx0ZXIoJzpjaGVja2VkJykudmFsKCkgfHwgJycpO1xuICAgICAgICB2YXIgc2VsZWN0ZWRMYW5ndWFnZSA9IFN0cmluZygkbW9kZWxGb3JtLmRhdGEoJ3NlbGVjdGVkLWxhbmd1YWdlJykgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpO1xuXG4gICAgICAgIGZ1bmN0aW9uIHNlbGVjdGVkTW9kZWwoKSB7XG4gICAgICAgICAgICByZXR1cm4gU3RyaW5nKCRtb2RlbElucHV0cy5maWx0ZXIoJzpjaGVja2VkJykudmFsKCkgfHwgJycpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc2VsZWN0ZWRNb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoKSB7XG4gICAgICAgICAgICB2YXIgJHNlbGVjdGVkSW5wdXQgPSAkbW9kZWxJbnB1dHMuZmlsdGVyKCc6Y2hlY2tlZCcpO1xuICAgICAgICAgICAgdmFyIHN1cHBvcnRlZExhbmd1YWdlcztcblxuICAgICAgICAgICAgaWYgKHNlbGVjdGVkTGFuZ3VhZ2UgPT09ICcnIHx8IHNlbGVjdGVkTGFuZ3VhZ2UgPT09ICdhdXRvJykge1xuICAgICAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKCRzZWxlY3RlZElucHV0Lmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgc3VwcG9ydGVkTGFuZ3VhZ2VzID0gU3RyaW5nKCRzZWxlY3RlZElucHV0LmF0dHIoJ2RhdGEtc3VwcG9ydGVkLWxhbmd1YWdlcycpIHx8ICcnKVxuICAgICAgICAgICAgICAgIC5zcGxpdCgnLCcpXG4gICAgICAgICAgICAgICAgLm1hcChmdW5jdGlvbiAobGFuZ3VhZ2UpIHsgcmV0dXJuIGxhbmd1YWdlLnRyaW0oKS50b0xvd2VyQ2FzZSgpOyB9KVxuICAgICAgICAgICAgICAgIC5maWx0ZXIoZnVuY3Rpb24gKGxhbmd1YWdlKSB7IHJldHVybiBsYW5ndWFnZSAhPT0gJyc7IH0pO1xuXG4gICAgICAgICAgICByZXR1cm4gc3VwcG9ydGVkTGFuZ3VhZ2VzLmluZGV4T2Yoc2VsZWN0ZWRMYW5ndWFnZSkgIT09IC0xO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlQ29tcGF0aWJpbGl0eVN0YXRlKCkge1xuICAgICAgICAgICAgdmFyIGNvbXBhdGlibGUgPSBzZWxlY3RlZE1vZGVsU3VwcG9ydHNMYW5ndWFnZSgpO1xuXG4gICAgICAgICAgICAkY29tcGF0aWJpbGl0eU1lc3NhZ2VcbiAgICAgICAgICAgICAgICAudG9nZ2xlQ2xhc3MoJ2hpZGRlbicsIGNvbXBhdGlibGUpXG4gICAgICAgICAgICAgICAgLmF0dHIoJ2FyaWEtaGlkZGVuJywgY29tcGF0aWJsZSA/ICd0cnVlJyA6ICdmYWxzZScpO1xuXG4gICAgICAgICAgICByZXR1cm4gY29tcGF0aWJsZTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciBjb21wYXRpYmxlID0gdXBkYXRlQ29tcGF0aWJpbGl0eVN0YXRlKCk7XG4gICAgICAgICAgICB2YXIgaGFzQ2hhbmdlcyA9IHNlbGVjdGVkTW9kZWwoKSAhPT0gJydcbiAgICAgICAgICAgICAgICAmJiBzZWxlY3RlZE1vZGVsKCkgIT09IGluaXRpYWxTZWxlY3RlZE1vZGVsXG4gICAgICAgICAgICAgICAgJiYgY29tcGF0aWJsZTtcblxuICAgICAgICAgICAgJHNhdmVCdXR0b24udG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWhhc0NoYW5nZXMpO1xuICAgICAgICAgICAgJHNhdmVCdXR0b24ucHJvcCgnZGlzYWJsZWQnLCAhaGFzQ2hhbmdlcyk7XG4gICAgICAgIH1cblxuICAgICAgICBpZiAodHlwZW9mICQuZm4uYWNjb3JkaW9uID09PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtYWR2YW5jZWQtc2V0dGluZ3MtZHJvcGRvd24nKS5hY2NvcmRpb24oKTtcbiAgICAgICAgfVxuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1tb2RlbC1tYXJrZXRwbGFjZS10YWJsZSB0Ym9keSB0cicpLm9uKCdjbGljaycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgaWYgKCQoZXZlbnQudGFyZ2V0KS5jbG9zZXN0KCdhLCBidXR0b24sIGZvcm0sIGlucHV0LCBsYWJlbCcpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICQodGhpcykuZmluZCgnLnVpLnJhZGlvLmNoZWNrYm94JykuY2hlY2tib3goJ2NoZWNrJyk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRtb2RlbElucHV0cy5vbignY2hhbmdlJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyICRyb3cgPSAkKHRoaXMpLmNsb3Nlc3QoJ3RyJyk7XG5cbiAgICAgICAgICAgICRyb3cuYWRkQ2xhc3MoJ3Bvc2l0aXZlJykuc2libGluZ3MoKS5yZW1vdmVDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbW9kZWxGb3JtLm9uKCdzdWJtaXQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoIXNlbGVjdGVkTW9kZWxTdXBwb3J0c0xhbmd1YWdlKClcbiAgICAgICAgICAgICAgICB8fCBzZWxlY3RlZE1vZGVsKCkgPT09ICcnXG4gICAgICAgICAgICAgICAgfHwgc2VsZWN0ZWRNb2RlbCgpID09PSBpbml0aWFsU2VsZWN0ZWRNb2RlbCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICgkc2F2ZUJ1dHRvbi5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgJHNhdmVCdXR0b24uYWRkQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQgYWN0aXZlJyk7XG4gICAgICAgICAgICAgICAgJHNhdmVCdXR0b24ucHJvcCgnZGlzYWJsZWQnLCB0cnVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG5cbiAgICAgICAgdXBkYXRlU2F2ZUJ1dHRvblN0YXRlKCk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCgpIHtcbiAgICAgICAgdmFyICRtb2RhbCA9ICQoJyNzdHQtY29uZmlybS1tb2RhbCcpO1xuXG4gICAgICAgIGlmICgkbW9kYWwubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkbW9kYWwubW9kYWwgIT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFByZS1pbml0aWFsaXplIHNvIFNlbWFudGljIFVJIG1vdmVzIHRoZSBlbGVtZW50IHRvIDxib2R5PiBhbmQgaGlkZXMgaXQgcHJvcGVybHlcbiAgICAgICAgJG1vZGFsLm1vZGFsKHt9KTtcblxuICAgICAgICBmdW5jdGlvbiBzaG93Q29uZmlybShtZXNzYWdlLCBvbkNvbmZpcm0pIHtcbiAgICAgICAgICAgICRtb2RhbC5maW5kKCcuc3R0LWNvbmZpcm0tbWVzc2FnZScpLnRleHQobWVzc2FnZSk7XG4gICAgICAgICAgICAkbW9kYWwubW9kYWwoe1xuICAgICAgICAgICAgICAgIGNsb3NhYmxlOiB0cnVlLFxuICAgICAgICAgICAgICAgIG9uQXBwcm92ZTogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBvbkNvbmZpcm0oKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KS5tb2RhbCgnc2hvdycpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gYmluZENvbmZpcm1hYmxlRm9ybSgkZm9ybSkge1xuICAgICAgICAgICAgJGZvcm0ub24oJ3N1Ym1pdC5zdHQtY29uZmlybScsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgICAgIHZhciBtZXNzYWdlID0gJCh0aGlzKS5kYXRhKCdjb25maXJtJykgfHwgJyc7XG5cbiAgICAgICAgICAgICAgICBpZiAobWVzc2FnZSA9PT0gJycpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICAgICAgZXZlbnQuc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCk7XG5cbiAgICAgICAgICAgICAgICB2YXIgJHNlbGYgPSAkKHRoaXMpO1xuICAgICAgICAgICAgICAgIHNob3dDb25maXJtKG1lc3NhZ2UsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgJHNlbGYub2ZmKCdzdWJtaXQuc3R0LWNvbmZpcm0nKS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWRlbGV0ZS1hcGkta2V5LWZvcm0nKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGJpbmRDb25maXJtYWJsZUZvcm0oJCh0aGlzKSk7XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0Um93cygpIHtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LXRyYW5zY3JpcHQtcm93Jykub24oJ2NsaWNrJywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBpZiAoJChldmVudC50YXJnZXQpLmNsb3Nlc3QoJ2EsIGJ1dHRvbiwgaW5wdXQsIHNlbGVjdCwgdGV4dGFyZWEnKS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICB2YXIgZGVzdGluYXRpb24gPSAkKHRoaXMpLmRhdGEoJ3RyYW5zY3JpcHQtdXJsJyk7XG4gICAgICAgICAgICBpZiAoZGVzdGluYXRpb24pIHtcbiAgICAgICAgICAgICAgICB3aW5kb3cubG9jYXRpb24uaHJlZiA9IGRlc3RpbmF0aW9uO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICAkKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgdmFyICR0YWJJdGVtcyA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbWVudSAuaXRlbScpO1xuICAgICAgICB2YXIgJHNldHRpbmdzRm9ybSA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtZm9ybScpO1xuICAgICAgICB2YXIgJG1vZGVsRm9ybSA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbW9kZWwtZm9ybScpO1xuICAgICAgICAkdGFiSXRlbXMudGFiKCk7XG4gICAgICAgIGlmIChyZXF1ZXN0ZWRUYWIoKSAhPT0gJycpIHtcbiAgICAgICAgICAgICR0YWJJdGVtcy50YWIoJ2NoYW5nZSB0YWInLCByZXF1ZXN0ZWRUYWIoKSk7XG4gICAgICAgIH1cblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC51aS5kcm9wZG93bicpLmRyb3Bkb3duKCk7XG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnVpLmNoZWNrYm94JykuY2hlY2tib3goKTtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCBbZGF0YS1jb250ZW50XScpLnBvcHVwKCk7XG4gICAgICAgIGluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlKCRtb2RlbEZvcm0pO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdERhdGVGaWx0ZXIoKTtcbiAgICAgICAgaW5pdGlhbGl6ZU1vZHVsZUxvZ3MoKTtcbiAgICAgICAgaW5pdGlhbGl6ZUNkckhpc3RvcnlTbGlkZXIoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0UmV0ZW50aW9uU2xpZGVyKCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCgpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MoKTtcblxuICAgICAgICBpZiAoJC5mbi5mb3JtICYmICQuZm4uZm9ybS5zZXR0aW5ncyAmJiAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMpIHtcbiAgICAgICAgICAgICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcy5zdHRQcm9jZXNzaW5nUmFuZ2UgPSBmdW5jdGlvbiAodmFsdWUsIHJhbmdlKSB7XG4gICAgICAgICAgICAgICAgdmFyIGxpbWl0cyA9IFN0cmluZyhyYW5nZSB8fCAnJykuc3BsaXQoJywnKS5tYXAoTnVtYmVyKTtcbiAgICAgICAgICAgICAgICB2YXIgbnVtYmVyID0gTnVtYmVyKHZhbHVlKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBsaW1pdHMubGVuZ3RoID09PSAyXG4gICAgICAgICAgICAgICAgICAgICYmIE51bWJlci5pc0Zpbml0ZShudW1iZXIpXG4gICAgICAgICAgICAgICAgICAgICYmIG51bWJlciA+PSBsaW1pdHNbMF1cbiAgICAgICAgICAgICAgICAgICAgJiYgbnVtYmVyIDw9IGxpbWl0c1sxXTtcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMuc3R0UHJvY2Vzc2luZ092ZXJsYXAgPSBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgb3ZlcmxhcE1TID0gTWF0aC5yb3VuZChOdW1iZXIodmFsdWUpICogMTAwMCk7XG4gICAgICAgICAgICAgICAgdmFyIGR1cmF0aW9uTVMgPSBNYXRoLnJvdW5kKE51bWJlcigkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwibWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjXCJdJykudmFsKCkpICogMTAwMCk7XG5cbiAgICAgICAgICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKG92ZXJsYXBNUylcbiAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKGR1cmF0aW9uTVMpXG4gICAgICAgICAgICAgICAgICAgICYmIG92ZXJsYXBNUyA+PSAwXG4gICAgICAgICAgICAgICAgICAgICYmIG92ZXJsYXBNUyA8IGR1cmF0aW9uTVM7XG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzLnN0dFJldGVudGlvbkNvbXBhdGliaWxpdHkgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcyA9IHtcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RlbXBlcmF0dXJlJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlX2ZhbGxiYWNrX3N0ZXA6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzAuLjEwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0b3Bfazoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0b3BfaycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzEuLjEwMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgY29tcHJlc3Npb25fcmF0aW9fdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2NvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVstMTAsMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgZmlyc3RfdG9rZW5fbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVstMTAsMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbm9fc3BlZWNoX3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdub19zcGVlY2hfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG1heF9zZWdtZW50X2R1cmF0aW9uX3NlYzoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWMnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzEsMTIwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZWdtZW50X292ZXJsYXBfc2VjOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3NlZ21lbnRfb3ZlcmxhcF9zZWMnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbXG4gICAgICAgICAgICAgICAgICAgIHsgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDEwXScgfSxcbiAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ092ZXJsYXAnIH1cbiAgICAgICAgICAgICAgICBdXG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKCkge1xuICAgICAgICAgICAgdmFyIGVuYWJsZWQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwidm9pY2VfYWN0aXZpdHlfZGV0ZWN0aW9uXCJdJykuaXMoJzpjaGVja2VkJyk7XG5cbiAgICAgICAgICAgICRzZXR0aW5nc0Zvcm0uZmluZCgnLnN0dC12YWQtZGVwZW5kZW50JylcbiAgICAgICAgICAgICAgICAudG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWVuYWJsZWQpXG4gICAgICAgICAgICAgICAgLmF0dHIoJ2FyaWEtZGlzYWJsZWQnLCBlbmFibGVkID8gJ2ZhbHNlJyA6ICd0cnVlJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwidm9pY2VfYWN0aXZpdHlfZGV0ZWN0aW9uXCJdJykub24oJ2NoYW5nZScsIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcyk7XG4gICAgICAgIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcygpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtY29weS1idXR0b24nKS5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgdGV4dCA9ICQodGhpcykuZGF0YSgnY2xpcGJvYXJkLXRleHQnKSB8fCAnJztcbiAgICAgICAgICAgIHZhciAkYnV0dG9uID0gJCh0aGlzKTtcblxuICAgICAgICAgICAgaWYgKG5hdmlnYXRvci5jbGlwYm9hcmQgJiYgbmF2aWdhdG9yLmNsaXBib2FyZC53cml0ZVRleHQpIHtcbiAgICAgICAgICAgICAgICBuYXZpZ2F0b3IuY2xpcGJvYXJkLndyaXRlVGV4dCh0ZXh0KS50aGVuKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgJGJ1dHRvbi5hZGRDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgICAgICAgICAgc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkYnV0dG9uLnJlbW92ZUNsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgICAgICAgICB9LCA5MDApO1xuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcblxuICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmICRzZXR0aW5nc0Zvcm0ubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgRm9ybS4kZm9ybU9iaiA9ICRzZXR0aW5nc0Zvcm07XG4gICAgICAgICAgICBGb3JtLnVybCA9ICRzZXR0aW5nc0Zvcm0uYXR0cignYWN0aW9uJyk7XG4gICAgICAgICAgICBGb3JtLm1ldGhvZCA9ICdQT1NUJztcbiAgICAgICAgICAgIEZvcm0udmFsaWRhdGVSdWxlcyA9IE9iamVjdC5hc3NpZ24oe1xuICAgICAgICAgICAgICAgIGRlZmF1bHRfbGFuZ3VhZ2U6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2RlZmF1bHRfbGFuZ3VhZ2UnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2VtcHR5JyB9XVxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgY2RyX2hpc3RvcnlfZGF5czoge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnY2RyX2hpc3RvcnlfZGF5cycsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnaW50ZWdlclswLi4zNjVdJyB9XVxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5czoge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cycsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbXG4gICAgICAgICAgICAgICAgICAgICAgICB7IHR5cGU6ICdpbnRlZ2VyWzAuLjM2NV0nIH0sXG4gICAgICAgICAgICAgICAgICAgICAgICB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3N0dFJldGVudGlvbkNvbXBhdGliaWxpdHknLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHByb21wdDogdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbkNvbmZsaWN0JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ1RoZSByZWNvcmRpbmcgcHJvY2Vzc2luZyB3aW5kb3cgY2Fubm90IGV4Y2VlZCB0cmFuc2NyaXB0IHJldGVudGlvbi4gSW5jcmVhc2UgcmV0ZW50aW9uIG9yIHJlZHVjZSB0aGUgcHJvY2Vzc2luZyB3aW5kb3cuJ1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgXVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sIHByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzKTtcbiAgICAgICAgICAgIEZvcm0uY2JCZWZvcmVTZW5kRm9ybSA9IGZ1bmN0aW9uIChzZXR0aW5ncykge1xuICAgICAgICAgICAgICAgIHJldHVybiBzZXR0aW5ncztcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICBGb3JtLmNiQWZ0ZXJTZW5kRm9ybSA9IGZ1bmN0aW9uICgpIHt9O1xuICAgICAgICAgICAgRm9ybS5hcGlTZXR0aW5ncy5lbmFibGVkID0gZmFsc2U7XG4gICAgICAgICAgICBGb3JtLmFwaVNldHRpbmdzLmFwaU9iamVjdCA9IG51bGw7XG4gICAgICAgICAgICBGb3JtLnNlbmRPbmx5Q2hhbmdlZCA9IGZhbHNlO1xuICAgICAgICAgICAgRm9ybS5lbmFibGVEaXJyaXR5ID0gdHJ1ZTtcbiAgICAgICAgICAgIEZvcm0uaW5pdGlhbGl6ZSgpO1xuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgJHNldHRpbmdzRm9ybS5mb3JtKHtcbiAgICAgICAgICAgICAgICBmaWVsZHM6IE9iamVjdC5hc3NpZ24oe1xuICAgICAgICAgICAgICAgICAgICBkZWZhdWx0X2xhbmd1YWdlOiBbJ2VtcHR5J10sXG4gICAgICAgICAgICAgICAgICAgIGNkcl9oaXN0b3J5X2RheXM6IFsnaW50ZWdlclswLi4zNjVdJ10sXG4gICAgICAgICAgICAgICAgICAgIHRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXM6IFtcbiAgICAgICAgICAgICAgICAgICAgICAgICdpbnRlZ2VyWzAuLjM2NV0nLFxuICAgICAgICAgICAgICAgICAgICAgICAge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBwcm9tcHQ6IHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25Db25mbGljdCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdUaGUgcmVjb3JkaW5nIHByb2Nlc3Npbmcgd2luZG93IGNhbm5vdCBleGNlZWQgdHJhbnNjcmlwdCByZXRlbnRpb24uIEluY3JlYXNlIHJldGVudGlvbiBvciByZWR1Y2UgdGhlIHByb2Nlc3Npbmcgd2luZG93LidcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgICAgICB9LCBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcylcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgJG1vZGVsRm9ybS5mb3JtKHtcbiAgICAgICAgICAgIGZpZWxkczoge1xuICAgICAgICAgICAgICAgIHNlbGVjdGVkX21vZGVsOiBbJ2VtcHR5J11cbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfSk7XG59KCkpO1xuIl0sIm1hcHBpbmdzIjoiOzs7Ozs7QUFBQyxhQUFZO0VBQ1QsWUFBWTs7RUFFWixTQUFTQSxXQUFXQSxDQUFDQyxHQUFHLEVBQUVDLFFBQVEsRUFBRTtJ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