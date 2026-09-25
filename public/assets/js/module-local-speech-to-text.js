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
          if (hidden) {
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

//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoibW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0LmpzIiwibmFtZXMiOlsidHJhbnNsYXRpb24iLCJrZXkiLCJmYWxsYmFjayIsImdsb2JhbFRyYW5zbGF0ZSIsInJlcXVlc3RlZFRhYiIsInBhcmFtcyIsIlVSTFNlYXJjaFBhcmFtcyIsIndpbmRvdyIsImxvY2F0aW9uIiwic2VhcmNoIiwidGFiTmFtZSIsImdldCIsImhhc2giLCJyZXBsYWNlIiwidGVzdCIsImNhbGVuZGFyTG9jYWxlIiwiZGF5cyIsIm1vbnRocyIsIlNlbWFudGljTG9jYWxpemF0aW9uIiwiY2FsZW5kYXJUZXh0IiwiZm9ybWF0Iiwic2VwYXJhdG9yIiwiYXBwbHlMYWJlbCIsImNhbmNlbExhYmVsIiwiZnJvbUxhYmVsIiwidG9MYWJlbCIsImN1c3RvbVJhbmdlTGFiZWwiLCJkYXlzT2ZXZWVrIiwibW9udGhOYW1lcyIsImZpcnN0RGF5IiwibW9tZW50RGF0ZSIsInZhbHVlIiwiZGF0ZSIsIm1vbWVudCIsImlzVmFsaWQiLCJjbG9uZSIsImluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlciIsIiRmb3JtIiwiJCIsIiRzZWxlY3RvciIsImxlbmd0aCIsImZuIiwiZGF0ZXJhbmdlcGlja2VyIiwiJGRhdGVGcm9tIiwiZmluZCIsIiRkYXRlVG8iLCIkc2VhcmNoIiwic2VhcmNoRGVib3VuY2VUaW1lciIsInRvZGF5Iiwic3RhcnREYXRlIiwidmFsIiwiZW5kRGF0ZSIsImlzQWZ0ZXIiLCJzd2FwRGF0ZSIsInVwZGF0ZUhpZGRlbklucHV0cyIsInN0YXJ0IiwiZW5kIiwiY2FuY2VsU2VhcmNoRGVib3VuY2UiLCJjbGVhclRpbWVvdXQiLCJzdWJtaXRUcmFuc2NyaXB0U2VhcmNoIiwidHJpZ2dlciIsInJhbmdlcyIsIl9kZWZpbmVQcm9wZXJ0eSIsInN1YnRyYWN0Iiwic3RhcnRPZiIsImVuZE9mIiwiYWx3YXlzU2hvd0NhbGVuZGFycyIsImF1dG9VcGRhdGVJbnB1dCIsImxpbmtlZENhbGVuZGFycyIsIm1heERhdGUiLCJsb2NhbGUiLCJvbiIsInBpY2tlciIsImRhdGEiLCJzZXRUaW1lb3V0IiwiZXZlbnQiLCJ3aGljaCIsInByZXZlbnREZWZhdWx0IiwiU3RyaW5nIiwidHJpbSIsImluaXRpYWxpemVNb2R1bGVMb2dzIiwiJHNlZ21lbnQiLCIkcmVzdWx0cyIsIiRzdGF0ZSIsIiRyZXN1bHRDb3VudCIsIiR1cGRhdGVkQXQiLCIkbGV2ZWxGaWx0ZXIiLCIkY29tcG9uZW50RmlsdGVyIiwiZGF0YVVybCIsIiRwZXJpb2RCdXR0b25zIiwiJHJlZnJlc2hCdXR0b24iLCJjdXJyZW50UGVyaW9kIiwicGFyc2VJbnQiLCJmaWx0ZXIiLCJsb2FkZWRPbmNlIiwicmVxdWVzdCIsInJlcXVlc3RHZW5lcmF0aW9uIiwiY3VycmVudEVudHJpZXMiLCJjdXJyZW50UmVzcG9uc2UiLCJOdW1iZXIiLCJpc0Zpbml0ZSIsImxldmVsTmFtZSIsImxldmVsIiwibGV2ZWxzIiwiZGVidWciLCJpbmZvIiwibm90aWNlIiwid2FybmluZyIsImVycm9yIiwiaXRlbSIsImNvbXBvbmVudE5hbWUiLCJjb21wb25lbnQiLCJhbGlhc2VzIiwiYXBpIiwicXVldWUiLCJzY2FubmVyIiwid29ya2VyIiwid29ya2VybG9jYWxzcGVlY2h0b3RleHRqb2JzIiwic2V0dGluZ3MiLCJ3ZWJfdWkiLCJzZXR1cCIsInN0b3JhZ2UiLCJ0cmFuc2NyaXB0Iiwic3lzdGVtIiwibG9jYWxzcGVlY2h0b3RleHRtYWluIiwibG9nZ2VyIiwibGFiZWxzIiwib3RoZXIiLCJsYWJlbCIsImZvcm1hdFRpbWVzdGFtcCIsInRpbWVzdGFtcCIsIkRhdGUiLCJpc05hTiIsImdldFRpbWUiLCJ0b0xvY2FsZVN0cmluZyIsInJlbmRlclN0YXRlIiwia2luZCIsIm1lc3NhZ2UiLCJwcm9wIiwicmVtb3ZlQ2xhc3MiLCJ0ZXh0IiwiYWRkQ2xhc3MiLCJmaWx0ZXJFbnRyaWVzIiwiZW50cmllcyIsInF1ZXJ5IiwidG9Mb3dlckNhc2UiLCJlbnRyeSIsImhheXN0YWNrIiwic2V2ZXJpdHkiLCJwaWQiLCJKU09OIiwic3RyaW5naWZ5IiwiY29udGV4dCIsImpvaW4iLCJpbmRleE9mIiwicmVuZGVyRW50cmllcyIsImVtcHR5IiwiZm9yRWFjaCIsIiRjYXJkIiwiJGhlYWRlciIsIiRpZGVudGl0eSIsIiRsZXZlbCIsIiRjb21wb25lbnQiLCIkdGltZSIsInRzIiwiJG1lc3NhZ2UiLCIkZGV0YWlscyIsIiRzdW1tYXJ5IiwiZGV0YWlsVGV4dCIsInVuZGVmaW5lZCIsIk9iamVjdCIsImtleXMiLCJhcHBlbmQiLCJ1cGRhdGVDb21wb25lbnRPcHRpb25zIiwic2VsZWN0ZWQiLCJjb21wb25lbnRzIiwicHVzaCIsInNvcnQiLCJsZWZ0IiwicmlnaHQiLCJsb2NhbGVDb21wYXJlIiwidXBkYXRlTWV0YSIsImNvdW50IiwicmVzcG9uc2UiLCJmb3VuZCIsInVwZGF0ZWQiLCJnZW5lcmF0ZWRBdCIsInJlbmRlckN1cnJlbnQiLCJmaWx0ZXJlZCIsInNvdXJjZVN0YXR1cyIsIm5vdGljZXMiLCJtYWxmb3JtZWRDb3VudCIsInRydW5jYXRlZCIsInNldExvYWRpbmciLCJsb2FkTG9ncyIsImZvcmNlIiwicmVxdWVzdElkIiwiYWJvcnQiLCJjdXJyZW50UmVxdWVzdCIsImFqYXgiLCJ1cmwiLCJtZXRob2QiLCJkYXRhVHlwZSIsInBlcmlvZCIsImRvbmUiLCJBcnJheSIsImlzQXJyYXkiLCJmYWlsIiwieGhyIiwic3RhdHVzIiwiYWx3YXlzIiwiJGJ1dHRvbiIsImFkZCIsImNsb3Nlc3QiLCJoYXNDbGFzcyIsImluaXRpYWxpemVDZHJIaXN0b3J5U2xpZGVyIiwiJHNldHRpbmdzRm9ybSIsIiRzbGlkZXIiLCIkaW5wdXQiLCJzbGlkZXIiLCJ2YWx1ZXMiLCJjdXJyZW50VmFsdWUiLCJpbml0aWFsSW5kZXgiLCJpbml0aWFsaXplZCIsIm1pbiIsIm1heCIsInN0ZXAiLCJzbW9vdGgiLCJhdXRvQWRqdXN0TGFiZWxzIiwiaW50ZXJwcmV0TGFiZWwiLCJvbkNoYW5nZSIsInVwZGF0ZVJldGVudGlvbkNvbmZsaWN0IiwiRm9ybSIsImNoZWNrVmFsdWVzIiwiaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIiLCJyZXRlbnRpb25BbGxvd3NQcm9jZXNzaW5nIiwicHJvY2Vzc2luZ0RheXMiLCJyZXRlbnRpb25EYXlzIiwiY29tcGF0aWJsZSIsInRvZ2dsZUNsYXNzIiwiYXR0ciIsImluaXRpYWxpemVNb2RlbE1hcmtldHBsYWNlIiwiJG1vZGVsRm9ybSIsIiRzYXZlQnV0dG9uIiwiJG1vZGVsSW5wdXRzIiwiJGNvbXBhdGliaWxpdHlNZXNzYWdlIiwiaW5pdGlhbFNlbGVjdGVkTW9kZWwiLCJzZWxlY3RlZExhbmd1YWdlIiwic2VsZWN0ZWRNb2RlbCIsInNlbGVjdGVkTW9kZWxTdXBwb3J0c0xhbmd1YWdlIiwiJHNlbGVjdGVkSW5wdXQiLCJzdXBwb3J0ZWRMYW5ndWFnZXMiLCJzcGxpdCIsIm1hcCIsImxhbmd1YWdlIiwidXBkYXRlQ29tcGF0aWJpbGl0eVN0YXRlIiwidXBkYXRlU2F2ZUJ1dHRvblN0YXRlIiwiaGFzQ2hhbmdlcyIsImFjY29yZGlvbiIsInRhcmdldCIsImNoZWNrYm94IiwiJHJvdyIsInNpYmxpbmdzIiwiaW5pdGlhbGl6ZUNvbmZpcm1Nb2RhbCIsIiRtb2RhbCIsIm1vZGFsIiwic2hvd0NvbmZpcm0iLCJvbkNvbmZpcm0iLCJjbG9zYWJsZSIsIm9uQXBwcm92ZSIsImJpbmRDb25maXJtYWJsZUZvcm0iLCJzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24iLCIkc2VsZiIsIm9mZiIsImVhY2giLCJpbml0aWFsaXplVHJhbnNjcmlwdERlbGV0aW9uIiwiJGZvcm1zIiwiJGFja25vd2xlZGdlIiwiJGFwcHJvdmUiLCJwZW5kaW5nRm9ybSIsInNldEFwcHJvdmVFbmFibGVkIiwiZW5hYmxlZCIsImZvcm0iLCJ0eXBlIiwibmFtZSIsImFwcGVuZFRvIiwic3VibWl0Iiwib25IaWRkZW4iLCJpbml0aWFsaXplVHJhbnNjcmlwdFJvd3MiLCJkZXN0aW5hdGlvbiIsImhyZWYiLCJpbml0aWFsaXplVHJhbnNjcmlwdEFjdGlvbk1lbnUiLCIkbWVudXMiLCJyZW1vdmVBdHRyIiwiZG9jdW1lbnQiLCJjb250YWlucyIsImluaXRpYWxpemVUcmFuc2NyaXB0TGVnVGFicyIsInRyYW5zY3JpcHRMb2NhdGlvbiIsIlN0dFRyYW5zY3JpcHRWaWV3IiwiJHJldmlldyIsIiR0YWJzIiwiJHBhbmVscyIsInBhbmVsTGVnIiwiJGVsZW1lbnQiLCJzaG93IiwiY2FsbExlZyIsImFjdGl2ZSIsImhpZGRlbiIsImF1ZGlvIiwicGF1c2UiLCJyZWFkIiwiJHN0YWdlIiwic3RhZ2UiLCJjaGFuZ2VzIiwibGVnIiwid3JpdGUiLCJpbml0aWFsIiwiJHRhcmdldCIsIiR0YWJNZW51IiwiJHRhYkl0ZW1zIiwidGFiIiwib25WaXNpYmxlIiwibm90IiwiZHJvcGRvd24iLCJwb3B1cCIsInJ1bGVzIiwic3R0UHJvY2Vzc2luZ1JhbmdlIiwicmFuZ2UiLCJsaW1pdHMiLCJudW1iZXIiLCJzdHRQcm9jZXNzaW5nT3ZlcmxhcCIsIm92ZXJsYXBNUyIsIk1hdGgiLCJyb3VuZCIsImR1cmF0aW9uTVMiLCJzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5IiwicHJvY2Vzc2luZ1ZhbGlkYXRpb25GaWVsZHMiLCJ0ZW1wZXJhdHVyZSIsImlkZW50aWZpZXIiLCJ0ZW1wZXJhdHVyZV9mYWxsYmFja19zdGVwIiwidGVtcGVyYXR1cmVfZmFsbGJhY2tfY291bnQiLCJ0b3BfayIsImNvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZCIsImxvZ19wcm9iYWJpbGl0eV90aHJlc2hvbGQiLCJmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkIiwibm9fc3BlZWNoX3RocmVzaG9sZCIsIm1heF9zZWdtZW50X2R1cmF0aW9uX3NlYyIsInNlZ21lbnRfb3ZlcmxhcF9zZWMiLCJ1cGRhdGVWYWREZXBlbmRlbnRGaWVsZHMiLCJpcyIsIm5hdmlnYXRvciIsImNsaXBib2FyZCIsIndyaXRlVGV4dCIsInRoZW4iLCIkZm9ybU9iaiIsInZhbGlkYXRlUnVsZXMiLCJhc3NpZ24iLCJkZWZhdWx0X2xhbmd1YWdlIiwiY2RyX2hpc3RvcnlfZGF5cyIsInRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXMiLCJwcm9tcHQiLCJjYkJlZm9yZVNlbmRGb3JtIiwiY2JBZnRlclNlbmRGb3JtIiwiYXBpU2V0dGluZ3MiLCJhcGlPYmplY3QiLCJzZW5kT25seUNoYW5nZWQiLCJlbmFibGVEaXJyaXR5IiwiaW5pdGlhbGl6ZSIsImZpZWxkcyIsInNlbGVjdGVkX21vZGVsIl0sInNvdXJjZXMiOlsic3JjL21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC5qcyJdLCJzb3VyY2VzQ29udGVudCI6WyIoZnVuY3Rpb24gKCkge1xuICAgICd1c2Ugc3RyaWN0JztcblxuICAgIGZ1bmN0aW9uIHRyYW5zbGF0aW9uKGtleSwgZmFsbGJhY2spIHtcbiAgICAgICAgaWYgKHR5cGVvZiBnbG9iYWxUcmFuc2xhdGUgIT09ICd1bmRlZmluZWQnICYmIGdsb2JhbFRyYW5zbGF0ZVtrZXldKSB7XG4gICAgICAgICAgICByZXR1cm4gZ2xvYmFsVHJhbnNsYXRlW2tleV07XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4gZmFsbGJhY2s7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gcmVxdWVzdGVkVGFiKCkge1xuICAgICAgICB2YXIgcGFyYW1zID0gbmV3IFVSTFNlYXJjaFBhcmFtcyh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgdmFyIHRhYk5hbWUgPSBwYXJhbXMuZ2V0KCd0YWInKSB8fCB3aW5kb3cubG9jYXRpb24uaGFzaC5yZXBsYWNlKC9eIy8sICcnKTtcblxuICAgICAgICByZXR1cm4gL15bYS16MC05Xy1dKyQvLnRlc3QodGFiTmFtZSkgPyB0YWJOYW1lIDogJyc7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gY2FsZW5kYXJMb2NhbGUoKSB7XG4gICAgICAgIHZhciBkYXlzID0gWydTdScsICdNbycsICdUdScsICdXZScsICdUaCcsICdGcicsICdTYSddO1xuICAgICAgICB2YXIgbW9udGhzID0gW1xuICAgICAgICAgICAgJ0phbnVhcnknLFxuICAgICAgICAgICAgJ0ZlYnJ1YXJ5JyxcbiAgICAgICAgICAgICdNYXJjaCcsXG4gICAgICAgICAgICAnQXByaWwnLFxuICAgICAgICAgICAgJ01heScsXG4gICAgICAgICAgICAnSnVuZScsXG4gICAgICAgICAgICAnSnVseScsXG4gICAgICAgICAgICAnQXVndXN0JyxcbiAgICAgICAgICAgICdTZXB0ZW1iZXInLFxuICAgICAgICAgICAgJ09jdG9iZXInLFxuICAgICAgICAgICAgJ05vdmVtYmVyJyxcbiAgICAgICAgICAgICdEZWNlbWJlcidcbiAgICAgICAgXTtcblxuICAgICAgICBpZiAodHlwZW9mIFNlbWFudGljTG9jYWxpemF0aW9uICE9PSAndW5kZWZpbmVkJyAmJiBTZW1hbnRpY0xvY2FsaXphdGlvbi5jYWxlbmRhclRleHQpIHtcbiAgICAgICAgICAgIGRheXMgPSBTZW1hbnRpY0xvY2FsaXphdGlvbi5jYWxlbmRhclRleHQuZGF5cyB8fCBkYXlzO1xuICAgICAgICAgICAgbW9udGhzID0gU2VtYW50aWNMb2NhbGl6YXRpb24uY2FsZW5kYXJUZXh0Lm1vbnRocyB8fCBtb250aHM7XG4gICAgICAgIH1cblxuICAgICAgICByZXR1cm4ge1xuICAgICAgICAgICAgZm9ybWF0OiAnREQvTU0vWVlZWScsXG4gICAgICAgICAgICBzZXBhcmF0b3I6ICcgLSAnLFxuICAgICAgICAgICAgYXBwbHlMYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9BcHBseUJ0bicsICdBcHBseScpLFxuICAgICAgICAgICAgY2FuY2VsTGFiZWw6IHRyYW5zbGF0aW9uKCdjYWxfQ2FuY2VsQnRuJywgJ0NhbmNlbCcpLFxuICAgICAgICAgICAgZnJvbUxhYmVsOiB0cmFuc2xhdGlvbignY2FsX2Zyb20nLCAnRnJvbScpLFxuICAgICAgICAgICAgdG9MYWJlbDogdHJhbnNsYXRpb24oJ2NhbF90bycsICdUbycpLFxuICAgICAgICAgICAgY3VzdG9tUmFuZ2VMYWJlbDogdHJhbnNsYXRpb24oJ2NhbF9DdXN0b21QZXJpb2QnLCAnQ3VzdG9tIHJhbmdlJyksXG4gICAgICAgICAgICBkYXlzT2ZXZWVrOiBkYXlzLFxuICAgICAgICAgICAgbW9udGhOYW1lczogbW9udGhzLFxuICAgICAgICAgICAgZmlyc3REYXk6IDFcbiAgICAgICAgfTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBtb21lbnREYXRlKHZhbHVlLCBmYWxsYmFjaykge1xuICAgICAgICB2YXIgZGF0ZSA9IG1vbWVudCh2YWx1ZSwgJ1lZWVktTU0tREQnLCB0cnVlKTtcblxuICAgICAgICByZXR1cm4gZGF0ZS5pc1ZhbGlkKCkgPyBkYXRlIDogZmFsbGJhY2suY2xvbmUoKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplVHJhbnNjcmlwdERhdGVGaWx0ZXIoKSB7XG4gICAgICAgIHZhciAkZm9ybSA9ICQoJyNzdHQtdHJhbnNjcmlwdHMtZmlsdGVyLWZvcm0nKTtcbiAgICAgICAgdmFyICRzZWxlY3RvciA9ICQoJyNzdHQtdHJhbnNjcmlwdHMtZGF0ZS1yYW5nZS1zZWxlY3RvcicpO1xuXG4gICAgICAgIGlmICgkZm9ybS5sZW5ndGggPT09IDAgfHwgJHNlbGVjdG9yLmxlbmd0aCA9PT0gMCkge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG4gICAgICAgIGlmICh0eXBlb2YgbW9tZW50ID09PSAndW5kZWZpbmVkJyB8fCB0eXBlb2YgJC5mbi5kYXRlcmFuZ2VwaWNrZXIgPT09ICd1bmRlZmluZWQnKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgJGRhdGVGcm9tID0gJGZvcm0uZmluZCgnaW5wdXRbbmFtZT1cImRhdGVfZnJvbVwiXScpO1xuICAgICAgICB2YXIgJGRhdGVUbyA9ICRmb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJkYXRlX3RvXCJdJyk7XG4gICAgICAgIHZhciAkc2VhcmNoID0gJGZvcm0uZmluZCgnaW5wdXRbbmFtZT1cInNlYXJjaFwiXScpO1xuICAgICAgICB2YXIgc2VhcmNoRGVib3VuY2VUaW1lciA9IG51bGw7XG4gICAgICAgIHZhciB0b2RheSA9IG1vbWVudCgpO1xuICAgICAgICB2YXIgc3RhcnREYXRlID0gbW9tZW50RGF0ZSgkZGF0ZUZyb20udmFsKCksIHRvZGF5KTtcbiAgICAgICAgdmFyIGVuZERhdGUgPSBtb21lbnREYXRlKCRkYXRlVG8udmFsKCksIHN0YXJ0RGF0ZSk7XG5cbiAgICAgICAgaWYgKHN0YXJ0RGF0ZS5pc0FmdGVyKGVuZERhdGUpKSB7XG4gICAgICAgICAgICB2YXIgc3dhcERhdGUgPSBzdGFydERhdGU7XG4gICAgICAgICAgICBzdGFydERhdGUgPSBlbmREYXRlO1xuICAgICAgICAgICAgZW5kRGF0ZSA9IHN3YXBEYXRlO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlSGlkZGVuSW5wdXRzKHN0YXJ0LCBlbmQpIHtcbiAgICAgICAgICAgICRkYXRlRnJvbS52YWwoc3RhcnQuZm9ybWF0KCdZWVlZLU1NLUREJykpO1xuICAgICAgICAgICAgJGRhdGVUby52YWwoZW5kLmZvcm1hdCgnWVlZWS1NTS1ERCcpKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGNhbmNlbFNlYXJjaERlYm91bmNlKCkge1xuICAgICAgICAgICAgaWYgKHNlYXJjaERlYm91bmNlVGltZXIgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICB3aW5kb3cuY2xlYXJUaW1lb3V0KHNlYXJjaERlYm91bmNlVGltZXIpO1xuICAgICAgICAgICAgICAgIHNlYXJjaERlYm91bmNlVGltZXIgPSBudWxsO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gc3VibWl0VHJhbnNjcmlwdFNlYXJjaCgpIHtcbiAgICAgICAgICAgIGNhbmNlbFNlYXJjaERlYm91bmNlKCk7XG4gICAgICAgICAgICAkZm9ybS50cmlnZ2VyKCdzdWJtaXQnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIHVwZGF0ZUhpZGRlbklucHV0cyhzdGFydERhdGUsIGVuZERhdGUpO1xuICAgICAgICAkc2VsZWN0b3IuZGF0ZXJhbmdlcGlja2VyKHtcbiAgICAgICAgICAgIHJhbmdlczoge1xuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX1RvZGF5JywgJ1RvZGF5JyldOiBbbW9tZW50KCksIG1vbWVudCgpXSxcbiAgICAgICAgICAgICAgICBbdHJhbnNsYXRpb24oJ2NhbF9ZZXN0ZXJkYXknLCAnWWVzdGVyZGF5JyldOiBbbW9tZW50KCkuc3VidHJhY3QoMSwgJ2RheXMnKSwgbW9tZW50KCkuc3VidHJhY3QoMSwgJ2RheXMnKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfTGFzdFdlZWsnLCAnTGFzdCA3IGRheXMnKV06IFttb21lbnQoKS5zdWJ0cmFjdCg2LCAnZGF5cycpLCBtb21lbnQoKV0sXG4gICAgICAgICAgICAgICAgW3RyYW5zbGF0aW9uKCdjYWxfTGFzdDMwRGF5cycsICdMYXN0IDMwIGRheXMnKV06IFttb21lbnQoKS5zdWJ0cmFjdCgyOSwgJ2RheXMnKSwgbW9tZW50KCldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX1RoaXNNb250aCcsICdUaGlzIG1vbnRoJyldOiBbbW9tZW50KCkuc3RhcnRPZignbW9udGgnKSwgbW9tZW50KCkuZW5kT2YoJ21vbnRoJyldLFxuICAgICAgICAgICAgICAgIFt0cmFuc2xhdGlvbignY2FsX0xhc3RNb250aCcsICdMYXN0IG1vbnRoJyldOiBbbW9tZW50KCkuc3VidHJhY3QoMSwgJ21vbnRoJykuc3RhcnRPZignbW9udGgnKSwgbW9tZW50KCkuc3VidHJhY3QoMSwgJ21vbnRoJykuZW5kT2YoJ21vbnRoJyldXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgYWx3YXlzU2hvd0NhbGVuZGFyczogdHJ1ZSxcbiAgICAgICAgICAgIGF1dG9VcGRhdGVJbnB1dDogdHJ1ZSxcbiAgICAgICAgICAgIGxpbmtlZENhbGVuZGFyczogdHJ1ZSxcbiAgICAgICAgICAgIG1heERhdGU6IG1vbWVudCgpLFxuICAgICAgICAgICAgc3RhcnREYXRlOiBzdGFydERhdGUuc3RhcnRPZignZGF5JyksXG4gICAgICAgICAgICBlbmREYXRlOiBlbmREYXRlLmVuZE9mKCdkYXknKSxcbiAgICAgICAgICAgIGxvY2FsZTogY2FsZW5kYXJMb2NhbGUoKVxuICAgICAgICB9LCBmdW5jdGlvbiAoc3RhcnQsIGVuZCkge1xuICAgICAgICAgICAgdXBkYXRlSGlkZGVuSW5wdXRzKHN0YXJ0LCBlbmQpO1xuICAgICAgICAgICAgJGZvcm0udHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgIH0pO1xuXG4gICAgICAgICRmb3JtLm9uKCdzdWJtaXQnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBjYW5jZWxTZWFyY2hEZWJvdW5jZSgpO1xuICAgICAgICAgICAgdmFyIHBpY2tlciA9ICRzZWxlY3Rvci5kYXRhKCdkYXRlcmFuZ2VwaWNrZXInKTtcbiAgICAgICAgICAgIGlmIChwaWNrZXIgJiYgcGlja2VyLnN0YXJ0RGF0ZSAmJiBwaWNrZXIuZW5kRGF0ZSkge1xuICAgICAgICAgICAgICAgIHVwZGF0ZUhpZGRlbklucHV0cyhwaWNrZXIuc3RhcnREYXRlLCBwaWNrZXIuZW5kRGF0ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICAkc2VhcmNoLm9uKCdpbnB1dCcsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIGNhbmNlbFNlYXJjaERlYm91bmNlKCk7XG4gICAgICAgICAgICBzZWFyY2hEZWJvdW5jZVRpbWVyID0gd2luZG93LnNldFRpbWVvdXQoZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIHNlYXJjaERlYm91bmNlVGltZXIgPSBudWxsO1xuICAgICAgICAgICAgICAgICRmb3JtLnRyaWdnZXIoJ3N1Ym1pdCcpO1xuICAgICAgICAgICAgfSwgNDAwKTtcbiAgICAgICAgfSk7XG4gICAgICAgICRzZWFyY2gub24oJ2tleWRvd24nLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmIChldmVudC53aGljaCAhPT0gMTMpIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgICAgICBzdWJtaXRUcmFuc2NyaXB0U2VhcmNoKCk7XG4gICAgICAgIH0pO1xuICAgICAgICAkc2VhcmNoLm9uKCdzZWFyY2gnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBpZiAoU3RyaW5nKCRzZWFyY2gudmFsKCkgfHwgJycpLnRyaW0oKSA9PT0gJycpIHtcbiAgICAgICAgICAgICAgICBzdWJtaXRUcmFuc2NyaXB0U2VhcmNoKCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVNb2R1bGVMb2dzKCkge1xuICAgICAgICB2YXIgJGZvcm0gPSAkKCcjc3R0LW1vZHVsZS1sb2dzLWZvcm0nKTtcbiAgICAgICAgdmFyICRzZWdtZW50ID0gJCgnI3N5c3RlbS1sb2dzLXNlZ21lbnQnKTtcbiAgICAgICAgdmFyICRyZXN1bHRzID0gJCgnI3N0dC1sb2ctcmVzdWx0cycpO1xuICAgICAgICB2YXIgJHN0YXRlID0gJCgnI3N0dC1sb2ctc3RhdGUnKTtcbiAgICAgICAgdmFyICRyZXN1bHRDb3VudCA9ICQoJyNzdHQtbG9nLXJlc3VsdC1jb3VudCcpO1xuICAgICAgICB2YXIgJHVwZGF0ZWRBdCA9ICQoJyNzdHQtbG9nLXVwZGF0ZWQtYXQnKTtcbiAgICAgICAgdmFyICRsZXZlbEZpbHRlciA9ICQoJyNzdHQtbG9nLWxldmVsLWZpbHRlcicpO1xuICAgICAgICB2YXIgJGNvbXBvbmVudEZpbHRlciA9ICQoJyNzdHQtbG9nLWNvbXBvbmVudC1maWx0ZXInKTtcbiAgICAgICAgdmFyICRzZWFyY2ggPSAkKCcjc3R0LWxvZy1zZWFyY2gnKTtcblxuICAgICAgICBpZiAoJGZvcm0ubGVuZ3RoID09PSAwIHx8ICRyZXN1bHRzLmxlbmd0aCA9PT0gMCB8fCAkc3RhdGUubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgZGF0YVVybCA9ICRmb3JtLmRhdGEoJ3VybCcpO1xuICAgICAgICB2YXIgJHBlcmlvZEJ1dHRvbnMgPSAkZm9ybS5maW5kKCcucGVyaW9kLWJ0bicpO1xuICAgICAgICB2YXIgJHJlZnJlc2hCdXR0b24gPSAkZm9ybS5maW5kKCcuc3R0LWxvZy1yZWZyZXNoJyk7XG4gICAgICAgIHZhciBjdXJyZW50UGVyaW9kID0gcGFyc2VJbnQoJHBlcmlvZEJ1dHRvbnMuZmlsdGVyKCcuYWN0aXZlJykuZGF0YSgncGVyaW9kJyksIDEwKTtcbiAgICAgICAgdmFyIGxvYWRlZE9uY2UgPSBmYWxzZTtcbiAgICAgICAgdmFyIHJlcXVlc3QgPSBudWxsO1xuICAgICAgICB2YXIgcmVxdWVzdEdlbmVyYXRpb24gPSAwO1xuICAgICAgICB2YXIgY3VycmVudEVudHJpZXMgPSBbXTtcbiAgICAgICAgdmFyIGN1cnJlbnRSZXNwb25zZSA9IG51bGw7XG5cbiAgICAgICAgaWYgKCFOdW1iZXIuaXNGaW5pdGUoY3VycmVudFBlcmlvZCkpIHtcbiAgICAgICAgICAgIGN1cnJlbnRQZXJpb2QgPSAzNjAwO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gbGV2ZWxOYW1lKGxldmVsKSB7XG4gICAgICAgICAgICB2YXIgbGV2ZWxzID0ge1xuICAgICAgICAgICAgICAgIGRlYnVnOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dMZXZlbERlYnVnJywgJ0RlYnVnJ10sXG4gICAgICAgICAgICAgICAgaW5mbzogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nTGV2ZWxJbmZvJywgJ0luZm8nXSxcbiAgICAgICAgICAgICAgICBub3RpY2U6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsTm90aWNlJywgJ05vdGljZSddLFxuICAgICAgICAgICAgICAgIHdhcm5pbmc6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsV2FybmluZycsICdXYXJuaW5nJ10sXG4gICAgICAgICAgICAgICAgZXJyb3I6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0xldmVsRXJyb3InLCAnRXJyb3InXVxuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIHZhciBpdGVtID0gbGV2ZWxzW2xldmVsXSB8fCBsZXZlbHMuaW5mbztcblxuICAgICAgICAgICAgcmV0dXJuIHRyYW5zbGF0aW9uKGl0ZW1bMF0sIGl0ZW1bMV0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gY29tcG9uZW50TmFtZShjb21wb25lbnQpIHtcbiAgICAgICAgICAgIHZhciBhbGlhc2VzID0ge1xuICAgICAgICAgICAgICAgIGFwaTogJ2FwaScsXG4gICAgICAgICAgICAgICAgcXVldWU6ICdxdWV1ZScsXG4gICAgICAgICAgICAgICAgc2Nhbm5lcjogJ3NjYW5uZXInLFxuICAgICAgICAgICAgICAgIHdvcmtlcjogJ3dvcmtlcicsXG4gICAgICAgICAgICAgICAgd29ya2VybG9jYWxzcGVlY2h0b3RleHRqb2JzOiAnd29ya2VyJyxcbiAgICAgICAgICAgICAgICBzZXR0aW5nczogJ3NldHRpbmdzJyxcbiAgICAgICAgICAgICAgICB3ZWJfdWk6ICdzZXR0aW5ncycsXG4gICAgICAgICAgICAgICAgc2V0dXA6ICdzZXR1cCcsXG4gICAgICAgICAgICAgICAgc3RvcmFnZTogJ3N0b3JhZ2UnLFxuICAgICAgICAgICAgICAgIHRyYW5zY3JpcHQ6ICd0cmFuc2NyaXB0JyxcbiAgICAgICAgICAgICAgICBzeXN0ZW06ICdzeXN0ZW0nLFxuICAgICAgICAgICAgICAgIGxvY2Fsc3BlZWNodG90ZXh0bWFpbjogJ3N5c3RlbScsXG4gICAgICAgICAgICAgICAgbG9nZ2VyOiAnc3lzdGVtJ1xuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIHZhciBsYWJlbHMgPSB7XG4gICAgICAgICAgICAgICAgYXBpOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRBcGknLCAnQVBJJ10sXG4gICAgICAgICAgICAgICAgcXVldWU6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFF1ZXVlJywgJ1F1ZXVlJ10sXG4gICAgICAgICAgICAgICAgc2Nhbm5lcjogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50U2Nhbm5lcicsICdSZWNvcmRpbmcgc2Nhbm5lciddLFxuICAgICAgICAgICAgICAgIHdvcmtlcjogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50V29ya2VyJywgJ1dvcmtlciddLFxuICAgICAgICAgICAgICAgIHNldHRpbmdzOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTZXR0aW5ncycsICdTZXR0aW5ncyddLFxuICAgICAgICAgICAgICAgIHNldHVwOiBbJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dDb21wb25lbnRTZXR1cCcsICdTZXR1cCddLFxuICAgICAgICAgICAgICAgIHN0b3JhZ2U6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFN0b3JhZ2UnLCAnU3RvcmFnZSddLFxuICAgICAgICAgICAgICAgIHRyYW5zY3JpcHQ6IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFRyYW5zY3JpcHQnLCAnVHJhbnNjcmlwdHMnXSxcbiAgICAgICAgICAgICAgICBzeXN0ZW06IFsnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ0NvbXBvbmVudFN5c3RlbScsICdTeXN0ZW0nXSxcbiAgICAgICAgICAgICAgICBvdGhlcjogWydtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nQ29tcG9uZW50T3RoZXInLCAnT3RoZXInXVxuICAgICAgICAgICAgfTtcbiAgICAgICAgICAgIHZhciBsYWJlbCA9IGxhYmVsc1thbGlhc2VzW2NvbXBvbmVudF0gfHwgJ290aGVyJ107XG5cbiAgICAgICAgICAgIHJldHVybiB0cmFuc2xhdGlvbihsYWJlbFswXSwgbGFiZWxbMV0pO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gZm9ybWF0VGltZXN0YW1wKHRpbWVzdGFtcCkge1xuICAgICAgICAgICAgdmFyIGRhdGUgPSBuZXcgRGF0ZSh0aW1lc3RhbXApO1xuICAgICAgICAgICAgaWYgKE51bWJlci5pc05hTihkYXRlLmdldFRpbWUoKSkpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGltZXN0YW1wIHx8ICfigJQnO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICByZXR1cm4gZGF0ZS50b0xvY2FsZVN0cmluZygpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyU3RhdGUoa2luZCwgbWVzc2FnZSkge1xuICAgICAgICAgICAgaWYgKCFtZXNzYWdlKSB7XG4gICAgICAgICAgICAgICAgJHN0YXRlLnByb3AoJ2hpZGRlbicsIHRydWUpLnJlbW92ZUNsYXNzKCdpbmZvIHdhcm5pbmcgZXJyb3InKS50ZXh0KCcnKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICRzdGF0ZVxuICAgICAgICAgICAgICAgIC5yZW1vdmVDbGFzcygnaW5mbyB3YXJuaW5nIGVycm9yJylcbiAgICAgICAgICAgICAgICAuYWRkQ2xhc3Moa2luZCB8fCAnaW5mbycpXG4gICAgICAgICAgICAgICAgLnRleHQobWVzc2FnZSlcbiAgICAgICAgICAgICAgICAucHJvcCgnaGlkZGVuJywgZmFsc2UpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gZmlsdGVyRW50cmllcyhlbnRyaWVzKSB7XG4gICAgICAgICAgICB2YXIgbGV2ZWwgPSBTdHJpbmcoJGxldmVsRmlsdGVyLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBjb21wb25lbnQgPSBTdHJpbmcoJGNvbXBvbmVudEZpbHRlci52YWwoKSB8fCAnJyk7XG4gICAgICAgICAgICB2YXIgcXVlcnkgPSBTdHJpbmcoJHNlYXJjaC52YWwoKSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNlKCk7XG5cbiAgICAgICAgICAgIHJldHVybiBlbnRyaWVzLmZpbHRlcihmdW5jdGlvbiAoZW50cnkpIHtcbiAgICAgICAgICAgICAgICBpZiAobGV2ZWwgJiYgZW50cnkubGV2ZWwgIT09IGxldmVsKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgaWYgKGNvbXBvbmVudCAmJiBlbnRyeS5jb21wb25lbnQgIT09IGNvbXBvbmVudCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGlmICghcXVlcnkpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAgICAgdmFyIGhheXN0YWNrID0gW1xuICAgICAgICAgICAgICAgICAgICBlbnRyeS5tZXNzYWdlLFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5jb21wb25lbnQsXG4gICAgICAgICAgICAgICAgICAgIGVudHJ5LmV2ZW50LFxuICAgICAgICAgICAgICAgICAgICBlbnRyeS5zZXZlcml0eSxcbiAgICAgICAgICAgICAgICAgICAgZW50cnkucGlkLFxuICAgICAgICAgICAgICAgICAgICBKU09OLnN0cmluZ2lmeShlbnRyeS5jb250ZXh0IHx8IHt9KVxuICAgICAgICAgICAgICAgIF0uam9pbignICcpLnRvTG93ZXJDYXNlKCk7XG5cbiAgICAgICAgICAgICAgICByZXR1cm4gaGF5c3RhY2suaW5kZXhPZihxdWVyeSkgIT09IC0xO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiByZW5kZXJFbnRyaWVzKGVudHJpZXMpIHtcbiAgICAgICAgICAgICRyZXN1bHRzLmVtcHR5KCk7XG4gICAgICAgICAgICBlbnRyaWVzLmZvckVhY2goZnVuY3Rpb24gKGVudHJ5KSB7XG4gICAgICAgICAgICAgICAgdmFyICRjYXJkID0gJCgnPGFydGljbGU+JykuYWRkQ2xhc3MoJ3N0dC1sb2ctZW50cnkgc3R0LWxvZy1sZXZlbC0nICsgZW50cnkubGV2ZWwpO1xuICAgICAgICAgICAgICAgIHZhciAkaGVhZGVyID0gJCgnPGRpdj4nKS5hZGRDbGFzcygnc3R0LWxvZy1lbnRyeS1oZWFkZXInKTtcbiAgICAgICAgICAgICAgICB2YXIgJGlkZW50aXR5ID0gJCgnPGRpdj4nKS5hZGRDbGFzcygnc3R0LWxvZy1lbnRyeS1pZGVudGl0eScpO1xuICAgICAgICAgICAgICAgIHZhciAkbGV2ZWwgPSAkKCc8c3Bhbj4nKS5hZGRDbGFzcygndWkgdGlueSBsYWJlbCBzdHQtbG9nLWxldmVsLWxhYmVsJykudGV4dChsZXZlbE5hbWUoZW50cnkubGV2ZWwpKTtcbiAgICAgICAgICAgICAgICB2YXIgJGNvbXBvbmVudCA9ICQoJzxzcGFuPicpLmFkZENsYXNzKCdzdHQtbG9nLWNvbXBvbmVudCcpLnRleHQoY29tcG9uZW50TmFtZShlbnRyeS5jb21wb25lbnQpKTtcbiAgICAgICAgICAgICAgICB2YXIgJHRpbWUgPSAkKCc8dGltZT4nKS5hZGRDbGFzcygnc3R0LWxvZy10aW1lJykudGV4dChmb3JtYXRUaW1lc3RhbXAoZW50cnkudHMpKTtcbiAgICAgICAgICAgICAgICB2YXIgJG1lc3NhZ2UgPSAkKCc8ZGl2PicpLmFkZENsYXNzKCdzdHQtbG9nLW1lc3NhZ2UnKS50ZXh0KGVudHJ5Lm1lc3NhZ2UgfHwgJ+KAlCcpO1xuICAgICAgICAgICAgICAgIHZhciAkZGV0YWlscyA9ICQoJzxkZXRhaWxzPicpLmFkZENsYXNzKCdzdHQtbG9nLWRldGFpbHMnKTtcbiAgICAgICAgICAgICAgICB2YXIgJHN1bW1hcnkgPSAkKCc8c3VtbWFyeT4nKS50ZXh0KHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NUZWNobmljYWxEZXRhaWxzJyxcbiAgICAgICAgICAgICAgICAgICAgJ1RlY2huaWNhbCBkZXRhaWxzJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHZhciBkZXRhaWxUZXh0ID0gdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRXZlbnQnLCAnRXZlbnQnKVxuICAgICAgICAgICAgICAgICAgICArICc6ICcgKyAoZW50cnkuZXZlbnQgfHwgJ+KAlCcpO1xuICAgICAgICAgICAgICAgIGRldGFpbFRleHQgKz0gJ1xcbnNldmVyaXR5OiAnICsgKGVudHJ5LnNldmVyaXR5IHx8ICfigJQnKTtcbiAgICAgICAgICAgICAgICBkZXRhaWxUZXh0ICs9ICdcXG5waWQ6ICcgKyAoZW50cnkucGlkID09PSB1bmRlZmluZWQgPyAn4oCUJyA6IGVudHJ5LnBpZCk7XG4gICAgICAgICAgICAgICAgaWYgKGVudHJ5LmNvbnRleHQgJiYgT2JqZWN0LmtleXMoZW50cnkuY29udGV4dCkubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICBkZXRhaWxUZXh0ICs9ICdcXG4nICsgSlNPTi5zdHJpbmdpZnkoZW50cnkuY29udGV4dCwgbnVsbCwgMik7XG4gICAgICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAgICAgJGlkZW50aXR5LmFwcGVuZCgkbGV2ZWwsICRjb21wb25lbnQpO1xuICAgICAgICAgICAgICAgICRoZWFkZXIuYXBwZW5kKCRpZGVudGl0eSwgJHRpbWUpO1xuICAgICAgICAgICAgICAgICRkZXRhaWxzLmFwcGVuZCgkc3VtbWFyeSwgJCgnPHByZT4nKS50ZXh0KGRldGFpbFRleHQpKTtcbiAgICAgICAgICAgICAgICAkY2FyZC5hcHBlbmQoJGhlYWRlciwgJG1lc3NhZ2UsICRkZXRhaWxzKTtcbiAgICAgICAgICAgICAgICAkcmVzdWx0cy5hcHBlbmQoJGNhcmQpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVDb21wb25lbnRPcHRpb25zKGVudHJpZXMpIHtcbiAgICAgICAgICAgIHZhciBzZWxlY3RlZCA9IFN0cmluZygkY29tcG9uZW50RmlsdGVyLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgICAgIHZhciBjb21wb25lbnRzID0gW107XG4gICAgICAgICAgICBlbnRyaWVzLmZvckVhY2goZnVuY3Rpb24gKGVudHJ5KSB7XG4gICAgICAgICAgICAgICAgaWYgKGVudHJ5LmNvbXBvbmVudCAmJiBjb21wb25lbnRzLmluZGV4T2YoZW50cnkuY29tcG9uZW50KSA9PT0gLTEpIHtcbiAgICAgICAgICAgICAgICAgICAgY29tcG9uZW50cy5wdXNoKGVudHJ5LmNvbXBvbmVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICBjb21wb25lbnRzLnNvcnQoZnVuY3Rpb24gKGxlZnQsIHJpZ2h0KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIGNvbXBvbmVudE5hbWUobGVmdCkubG9jYWxlQ29tcGFyZShjb21wb25lbnROYW1lKHJpZ2h0KSk7XG4gICAgICAgICAgICB9KTtcblxuICAgICAgICAgICAgJGNvbXBvbmVudEZpbHRlci5lbXB0eSgpLmFwcGVuZChcbiAgICAgICAgICAgICAgICAkKCc8b3B0aW9uPicpLnZhbCgnJykudGV4dCh0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzQ29tcG9uZW50QWxsJyxcbiAgICAgICAgICAgICAgICAgICAgJ0FsbCBjb21wb25lbnRzJ1xuICAgICAgICAgICAgICAgICkpXG4gICAgICAgICAgICApO1xuICAgICAgICAgICAgY29tcG9uZW50cy5mb3JFYWNoKGZ1bmN0aW9uIChjb21wb25lbnQpIHtcbiAgICAgICAgICAgICAgICAkY29tcG9uZW50RmlsdGVyLmFwcGVuZCgkKCc8b3B0aW9uPicpLnZhbChjb21wb25lbnQpLnRleHQoY29tcG9uZW50TmFtZShjb21wb25lbnQpKSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIGlmIChzZWxlY3RlZCAmJiBjb21wb25lbnRzLmluZGV4T2Yoc2VsZWN0ZWQpICE9PSAtMSkge1xuICAgICAgICAgICAgICAgICRjb21wb25lbnRGaWx0ZXIudmFsKHNlbGVjdGVkKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZU1ldGEoY291bnQsIHJlc3BvbnNlKSB7XG4gICAgICAgICAgICB2YXIgZm91bmQgPSB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NGb3VuZCcsICclY291bnQlIGV2ZW50cycpXG4gICAgICAgICAgICAgICAgLnJlcGxhY2UoJyVjb3VudCUnLCBjb3VudCk7XG4gICAgICAgICAgICB2YXIgdXBkYXRlZCA9IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1VwZGF0ZWQnLCAnVXBkYXRlZDogJXRpbWUlJylcbiAgICAgICAgICAgICAgICAucmVwbGFjZSgnJXRpbWUlJywgcmVzcG9uc2UgJiYgcmVzcG9uc2UuZ2VuZXJhdGVkQXRcbiAgICAgICAgICAgICAgICAgICAgPyByZXNwb25zZS5nZW5lcmF0ZWRBdFxuICAgICAgICAgICAgICAgICAgICA6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc05ldmVyJywgJ25ldmVyJykpO1xuICAgICAgICAgICAgJHJlc3VsdENvdW50LnRleHQoZm91bmQpO1xuICAgICAgICAgICAgJHVwZGF0ZWRBdC50ZXh0KHVwZGF0ZWQpO1xuICAgICAgICB9XG5cbiAgICAgICAgZnVuY3Rpb24gcmVuZGVyQ3VycmVudCgpIHtcbiAgICAgICAgICAgIGlmICghY3VycmVudFJlc3BvbnNlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgdmFyIGZpbHRlcmVkID0gZmlsdGVyRW50cmllcyhjdXJyZW50RW50cmllcyk7XG4gICAgICAgICAgICByZW5kZXJFbnRyaWVzKGZpbHRlcmVkKTtcbiAgICAgICAgICAgIHVwZGF0ZU1ldGEoZmlsdGVyZWQubGVuZ3RoLCBjdXJyZW50UmVzcG9uc2UpO1xuXG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnNvdXJjZVN0YXR1cyA9PT0gJ21pc3NpbmcnKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2luZm8nLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzTWlzc2luZycsXG4gICAgICAgICAgICAgICAgICAgICdUaGUgbW9kdWxlIGpvdXJuYWwgaGFzIG5vdCBiZWVuIGNyZWF0ZWQgeWV0LidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoY3VycmVudFJlc3BvbnNlLnNvdXJjZVN0YXR1cyA9PT0gJ3VuYXZhaWxhYmxlJykge1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdlcnJvcicsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NVbmF2YWlsYWJsZScsXG4gICAgICAgICAgICAgICAgICAgICdUaGUgbW9kdWxlIGpvdXJuYWwgaXMgdW5hdmFpbGFibGUuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAnY29ycnVwdCcpIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnZXJyb3InLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzQ29ycnVwdCcsXG4gICAgICAgICAgICAgICAgICAgICdUaGUgam91cm5hbCBjb250YWlucyBubyByZWFkYWJsZSBldmVudHMuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmIChjdXJyZW50RW50cmllcy5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICByZW5kZXJTdGF0ZSgnaW5mbycsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NFbXB0eScsXG4gICAgICAgICAgICAgICAgICAgICdObyBtb2R1bGUgZXZlbnRzIHdlcmUgZm91bmQgZm9yIHRoaXMgcGVyaW9kLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoZmlsdGVyZWQubGVuZ3RoID09PSAwKSB7XG4gICAgICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2luZm8nLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAgICAgJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9Mb2dzRmlsdGVyZWRFbXB0eScsXG4gICAgICAgICAgICAgICAgICAgICdObyBldmVudHMgbWF0Y2ggdGhlIHNlbGVjdGVkIGZpbHRlcnMuJ1xuICAgICAgICAgICAgICAgICkpO1xuICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgIH1cblxuICAgICAgICAgICAgdmFyIG5vdGljZXMgPSBbXTtcbiAgICAgICAgICAgIGlmIChjdXJyZW50UmVzcG9uc2Uuc291cmNlU3RhdHVzID09PSAncGFydGlhbCcpIHtcbiAgICAgICAgICAgICAgICBub3RpY2VzLnB1c2godHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1BhcnRpYWwnLFxuICAgICAgICAgICAgICAgICAgICAnJWNvdW50JSBkYW1hZ2VkIGpvdXJuYWwgbGluZXMgd2VyZSBza2lwcGVkLidcbiAgICAgICAgICAgICAgICApLnJlcGxhY2UoJyVjb3VudCUnLCBjdXJyZW50UmVzcG9uc2UubWFsZm9ybWVkQ291bnQgfHwgMCkpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKGN1cnJlbnRSZXNwb25zZS50cnVuY2F0ZWQpIHtcbiAgICAgICAgICAgICAgICBub3RpY2VzLnB1c2godHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfTG9nc1RydW5jYXRlZCcsXG4gICAgICAgICAgICAgICAgICAgICdPbmx5IHRoZSBuZXdlc3QgMTAwMCBldmVudHMgYXJlIHNob3duLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJlbmRlclN0YXRlKG5vdGljZXMubGVuZ3RoID4gMCA/ICd3YXJuaW5nJyA6ICcnLCBub3RpY2VzLmpvaW4oJyAnKSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBzZXRMb2FkaW5nKCkge1xuICAgICAgICAgICAgJHNlZ21lbnQuYWRkQ2xhc3MoJ3N0dC1sb2dzLWxvYWRpbmcnKTtcbiAgICAgICAgICAgICRyZXN1bHRzLmVtcHR5KCk7XG4gICAgICAgICAgICB1cGRhdGVNZXRhKDAsIG51bGwpO1xuICAgICAgICAgICAgcmVuZGVyU3RhdGUoJ2luZm8nLCB0cmFuc2xhdGlvbihcbiAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NMb2FkaW5nJyxcbiAgICAgICAgICAgICAgICAnTG9hZGluZyBtb2R1bGUgZXZlbnRzLi4uJ1xuICAgICAgICAgICAgKSk7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiBsb2FkTG9ncyhmb3JjZSkge1xuICAgICAgICAgICAgaWYgKCFmb3JjZSAmJiBsb2FkZWRPbmNlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKCFkYXRhVXJsKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmVxdWVzdEdlbmVyYXRpb24gKz0gMTtcbiAgICAgICAgICAgIHZhciByZXF1ZXN0SWQgPSByZXF1ZXN0R2VuZXJhdGlvbjtcbiAgICAgICAgICAgIGlmIChyZXF1ZXN0ICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgcmVxdWVzdC5hYm9ydCgpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBzZXRMb2FkaW5nKCk7XG4gICAgICAgICAgICAkcmVmcmVzaEJ1dHRvbi5hZGRDbGFzcygnbG9hZGluZyBkaXNhYmxlZCcpO1xuICAgICAgICAgICAgdmFyIGN1cnJlbnRSZXF1ZXN0ID0gJC5hamF4KHtcbiAgICAgICAgICAgICAgICB1cmw6IGRhdGFVcmwsXG4gICAgICAgICAgICAgICAgbWV0aG9kOiAnR0VUJyxcbiAgICAgICAgICAgICAgICBkYXRhVHlwZTogJ2pzb24nLFxuICAgICAgICAgICAgICAgIGRhdGE6IHtcbiAgICAgICAgICAgICAgICAgICAgcGVyaW9kOiBjdXJyZW50UGVyaW9kXG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSkuZG9uZShmdW5jdGlvbiAocmVzcG9uc2UpIHtcbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdElkICE9PSByZXF1ZXN0R2VuZXJhdGlvbikge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGN1cnJlbnRSZXNwb25zZSA9IHJlc3BvbnNlIHx8IHt9O1xuICAgICAgICAgICAgICAgIGN1cnJlbnRFbnRyaWVzID0gQXJyYXkuaXNBcnJheShjdXJyZW50UmVzcG9uc2UuZW50cmllcykgPyBjdXJyZW50UmVzcG9uc2UuZW50cmllcyA6IFtdO1xuICAgICAgICAgICAgICAgIHVwZGF0ZUNvbXBvbmVudE9wdGlvbnMoY3VycmVudEVudHJpZXMpO1xuICAgICAgICAgICAgICAgIHJlbmRlckN1cnJlbnQoKTtcbiAgICAgICAgICAgICAgICBsb2FkZWRPbmNlID0gdHJ1ZTtcbiAgICAgICAgICAgIH0pLmZhaWwoZnVuY3Rpb24gKHhociwgc3RhdHVzKSB7XG4gICAgICAgICAgICAgICAgaWYgKHJlcXVlc3RJZCAhPT0gcmVxdWVzdEdlbmVyYXRpb24gfHwgc3RhdHVzID09PSAnYWJvcnQnKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY3VycmVudFJlc3BvbnNlID0gbnVsbDtcbiAgICAgICAgICAgICAgICBjdXJyZW50RW50cmllcyA9IFtdO1xuICAgICAgICAgICAgICAgIHJlbmRlclN0YXRlKCdlcnJvcicsIHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0xvZ3NFcnJvcicsXG4gICAgICAgICAgICAgICAgICAgICdVbmFibGUgdG8gbG9hZCBtb2R1bGUgZXZlbnRzLidcbiAgICAgICAgICAgICAgICApKTtcbiAgICAgICAgICAgIH0pLmFsd2F5cyhmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgaWYgKHJlcXVlc3RJZCAhPT0gcmVxdWVzdEdlbmVyYXRpb24pIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAocmVxdWVzdCA9PT0gY3VycmVudFJlcXVlc3QpIHtcbiAgICAgICAgICAgICAgICAgICAgcmVxdWVzdCA9IG51bGw7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICRzZWdtZW50LnJlbW92ZUNsYXNzKCdzdHQtbG9ncy1sb2FkaW5nJyk7XG4gICAgICAgICAgICAgICAgJHJlZnJlc2hCdXR0b24ucmVtb3ZlQ2xhc3MoJ2xvYWRpbmcgZGlzYWJsZWQnKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgcmVxdWVzdCA9IGN1cnJlbnRSZXF1ZXN0O1xuICAgICAgICB9XG5cbiAgICAgICAgJHBlcmlvZEJ1dHRvbnMub24oJ2NsaWNrJywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdmFyICRidXR0b24gPSAkKHRoaXMpO1xuICAgICAgICAgICAgY3VycmVudFBlcmlvZCA9IHBhcnNlSW50KCRidXR0b24uZGF0YSgncGVyaW9kJyksIDEwKTtcbiAgICAgICAgICAgIGlmICghTnVtYmVyLmlzRmluaXRlKGN1cnJlbnRQZXJpb2QpKSB7XG4gICAgICAgICAgICAgICAgY3VycmVudFBlcmlvZCA9IDM2MDA7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICAkcGVyaW9kQnV0dG9ucy5yZW1vdmVDbGFzcygnYWN0aXZlJyk7XG4gICAgICAgICAgICAkYnV0dG9uLmFkZENsYXNzKCdhY3RpdmUnKTtcbiAgICAgICAgICAgIGxvYWRlZE9uY2UgPSBmYWxzZTtcbiAgICAgICAgICAgIGxvYWRMb2dzKHRydWUpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkcmVmcmVzaEJ1dHRvbi5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBsb2FkTG9ncyh0cnVlKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGxldmVsRmlsdGVyLmFkZCgkY29tcG9uZW50RmlsdGVyKS5vbignY2hhbmdlJywgcmVuZGVyQ3VycmVudCk7XG4gICAgICAgICRzZWFyY2gub24oJ2lucHV0JywgcmVuZGVyQ3VycmVudCk7XG5cbiAgICAgICAgJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tZW51IC5pdGVtW2RhdGEtdGFiPVwibG9nc1wiXScpLm9uKCdjbGljaycsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHdpbmRvdy5zZXRUaW1lb3V0KGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICBsb2FkTG9ncyhmYWxzZSk7XG4gICAgICAgICAgICB9LCAwKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgdXBkYXRlTWV0YSgwLCBudWxsKTtcbiAgICAgICAgaWYgKHJlcXVlc3RlZFRhYigpID09PSAnbG9ncycgfHwgJGZvcm0uY2xvc2VzdCgnLnRhYi5zZWdtZW50JykuaGFzQ2xhc3MoJ2FjdGl2ZScpKSB7XG4gICAgICAgICAgICBsb2FkTG9ncyhmYWxzZSk7XG4gICAgICAgIH1cbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplQ2RySGlzdG9yeVNsaWRlcigkc2V0dGluZ3NGb3JtKSB7XG4gICAgICAgIHZhciAkc2xpZGVyID0gJCgnI3N0dC1jZHItaGlzdG9yeS1kYXlzLXNsaWRlcicpO1xuICAgICAgICB2YXIgJGlucHV0ID0gJHNldHRpbmdzRm9ybS5maW5kKCdpbnB1dFtuYW1lPVwiY2RyX2hpc3RvcnlfZGF5c1wiXScpO1xuXG4gICAgICAgIGlmICgkc2xpZGVyLmxlbmd0aCA9PT0gMCB8fCAkaW5wdXQubGVuZ3RoID09PSAwIHx8IHR5cGVvZiAkc2xpZGVyLnNsaWRlciAhPT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICB9XG5cbiAgICAgICAgdmFyIHZhbHVlcyA9IFsnMScsICc3JywgJzMwJywgJzkwJywgJzE4MCcsICczNjUnLCAnMCddO1xuICAgICAgICB2YXIgbGFiZWxzID0ge1xuICAgICAgICAgICAgMDogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5MURheScsICcxIGRheScpLFxuICAgICAgICAgICAgMTogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5N0RheXMnLCAnNyBkYXlzJyksXG4gICAgICAgICAgICAyOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnkzMERheXMnLCAnMzAgZGF5cycpLFxuICAgICAgICAgICAgMzogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9DZHJIaXN0b3J5OTBEYXlzJywgJzkwIGRheXMnKSxcbiAgICAgICAgICAgIDQ6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTE4MERheXMnLCAnNiBtb250aHMnKSxcbiAgICAgICAgICAgIDU6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfQ2RySGlzdG9yeTM2NURheXMnLCAnMSB5ZWFyJyksXG4gICAgICAgICAgICA2OiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X0Nkckhpc3RvcnlBbGwnLCAnQWxsJylcbiAgICAgICAgfTtcbiAgICAgICAgdmFyIGN1cnJlbnRWYWx1ZSA9IFN0cmluZygkaW5wdXQudmFsKCkgfHwgJzMwJyk7XG4gICAgICAgIHZhciBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZihjdXJyZW50VmFsdWUpO1xuICAgICAgICB2YXIgaW5pdGlhbGl6ZWQgPSBmYWxzZTtcblxuICAgICAgICBpZiAoaW5pdGlhbEluZGV4IDwgMCkge1xuICAgICAgICAgICAgaW5pdGlhbEluZGV4ID0gdmFsdWVzLmluZGV4T2YoJzMwJyk7XG4gICAgICAgICAgICAkaW5wdXQudmFsKCczMCcpO1xuICAgICAgICB9XG5cbiAgICAgICAgJHNsaWRlci5zbGlkZXIoe1xuICAgICAgICAgICAgbWluOiAwLFxuICAgICAgICAgICAgbWF4OiB2YWx1ZXMubGVuZ3RoIC0gMSxcbiAgICAgICAgICAgIHN0ZXA6IDEsXG4gICAgICAgICAgICBzbW9vdGg6IHRydWUsXG4gICAgICAgICAgICBhdXRvQWRqdXN0TGFiZWxzOiBmYWxzZSxcbiAgICAgICAgICAgIGludGVycHJldExhYmVsOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gbGFiZWxzW3ZhbHVlXSB8fCAnJztcbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBvbkNoYW5nZTogZnVuY3Rpb24gKHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdmFyIHNlbGVjdGVkID0gdmFsdWVzW3ZhbHVlXSB8fCAnMzAnO1xuXG4gICAgICAgICAgICAgICAgJGlucHV0LnZhbChzZWxlY3RlZCk7XG4gICAgICAgICAgICAgICAgaWYgKGluaXRpYWxpemVkKSB7XG4gICAgICAgICAgICAgICAgICAgICRpbnB1dC50cmlnZ2VyKCdjaGFuZ2UnKTtcbiAgICAgICAgICAgICAgICAgICAgdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgICAgICAgICAgICAgIGlmICh0eXBlb2YgRm9ybSAhPT0gJ3VuZGVmaW5lZCcgJiYgdHlwZW9mIEZvcm0uY2hlY2tWYWx1ZXMgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIEZvcm0uY2hlY2tWYWx1ZXMoKTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRzbGlkZXIuc2xpZGVyKCdzZXQgdmFsdWUnLCBpbml0aWFsSW5kZXgsIGZhbHNlKTtcbiAgICAgICAgaW5pdGlhbGl6ZWQgPSB0cnVlO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0UmV0ZW50aW9uU2xpZGVyKCRzZXR0aW5nc0Zvcm0pIHtcbiAgICAgICAgdmFyICRzbGlkZXIgPSAkKCcjc3R0LXRyYW5zY3JpcHQtcmV0ZW50aW9uLWRheXMtc2xpZGVyJyk7XG4gICAgICAgIHZhciAkaW5wdXQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJ0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzXCJdJyk7XG5cbiAgICAgICAgaWYgKCRzbGlkZXIubGVuZ3RoID09PSAwIHx8ICRpbnB1dC5sZW5ndGggPT09IDAgfHwgdHlwZW9mICRzbGlkZXIuc2xpZGVyICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgdmFsdWVzID0gWyczMCcsICc5MCcsICcxODAnLCAnMzY1JywgJzAnXTtcbiAgICAgICAgdmFyIGxhYmVscyA9IHtcbiAgICAgICAgICAgIDA6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbjMwRGF5cycsICczMCBkYXlzJyksXG4gICAgICAgICAgICAxOiB0cmFuc2xhdGlvbignbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb245MERheXMnLCAnOTAgZGF5cycpLFxuICAgICAgICAgICAgMjogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uMTgwRGF5cycsICcxODAgZGF5cycpLFxuICAgICAgICAgICAgMzogdHJhbnNsYXRpb24oJ21vZHVsZV9sb2NhbF9zcGVlY2hfdG9fdGV4dF9UcmFuc2NyaXB0UmV0ZW50aW9uMzY1RGF5cycsICcxIHllYXInKSxcbiAgICAgICAgICAgIDQ6IHRyYW5zbGF0aW9uKCdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvblVubGltaXRlZCcsICdVbmxpbWl0ZWQnKVxuICAgICAgICB9O1xuICAgICAgICB2YXIgY3VycmVudFZhbHVlID0gU3RyaW5nKCRpbnB1dC52YWwoKSB8fCAnMzY1Jyk7XG4gICAgICAgIHZhciBpbml0aWFsSW5kZXggPSB2YWx1ZXMuaW5kZXhPZihjdXJyZW50VmFsdWUpO1xuICAgICAgICB2YXIgaW5pdGlhbGl6ZWQgPSBmYWxzZTtcblxuICAgICAgICBpZiAoaW5pdGlhbEluZGV4IDwgMCkge1xuICAgICAgICAgICAgaW5pdGlhbEluZGV4ID0gdmFsdWVzLmluZGV4T2YoJzM2NScpO1xuICAgICAgICAgICAgJGlucHV0LnZhbCgnMzY1Jyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2xpZGVyLnNsaWRlcih7XG4gICAgICAgICAgICBtaW46IDAsXG4gICAgICAgICAgICBtYXg6IHZhbHVlcy5sZW5ndGggLSAxLFxuICAgICAgICAgICAgc3RlcDogMSxcbiAgICAgICAgICAgIHNtb290aDogdHJ1ZSxcbiAgICAgICAgICAgIGF1dG9BZGp1c3RMYWJlbHM6IGZhbHNlLFxuICAgICAgICAgICAgaW50ZXJwcmV0TGFiZWw6IGZ1bmN0aW9uICh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHJldHVybiBsYWJlbHNbdmFsdWVdIHx8ICcnO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG9uQ2hhbmdlOiBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgc2VsZWN0ZWQgPSB2YWx1ZXNbdmFsdWVdIHx8ICczNjUnO1xuXG4gICAgICAgICAgICAgICAgJGlucHV0LnZhbChzZWxlY3RlZCk7XG4gICAgICAgICAgICAgICAgaWYgKGluaXRpYWxpemVkKSB7XG4gICAgICAgICAgICAgICAgICAgICRpbnB1dC50cmlnZ2VyKCdjaGFuZ2UnKTtcbiAgICAgICAgICAgICAgICAgICAgdXBkYXRlUmV0ZW50aW9uQ29uZmxpY3QoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgICAgICAgICAgICAgIGlmICh0eXBlb2YgRm9ybSAhPT0gJ3VuZGVmaW5lZCcgJiYgdHlwZW9mIEZvcm0uY2hlY2tWYWx1ZXMgPT09ICdmdW5jdGlvbicpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgIEZvcm0uY2hlY2tWYWx1ZXMoKTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgICAgICRzbGlkZXIuc2xpZGVyKCdzZXQgdmFsdWUnLCBpbml0aWFsSW5kZXgsIGZhbHNlKTtcbiAgICAgICAgaW5pdGlhbGl6ZWQgPSB0cnVlO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIHJldGVudGlvbkFsbG93c1Byb2Nlc3NpbmcocHJvY2Vzc2luZ0RheXMsIHJldGVudGlvbkRheXMpIHtcbiAgICAgICAgcmV0dXJuIHJldGVudGlvbkRheXMgPT09IDAgfHwgKHByb2Nlc3NpbmdEYXlzID4gMCAmJiBwcm9jZXNzaW5nRGF5cyA8PSByZXRlbnRpb25EYXlzKTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiB1cGRhdGVSZXRlbnRpb25Db25mbGljdCgkc2V0dGluZ3NGb3JtKSB7XG4gICAgICAgIHZhciBwcm9jZXNzaW5nRGF5cyA9IE51bWJlcigkc2V0dGluZ3NGb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJjZHJfaGlzdG9yeV9kYXlzXCJdJykudmFsKCkpO1xuICAgICAgICB2YXIgcmV0ZW50aW9uRGF5cyA9IE51bWJlcigkc2V0dGluZ3NGb3JtLmZpbmQoJ2lucHV0W25hbWU9XCJ0cmFuc2NyaXB0X3JldGVudGlvbl9kYXlzXCJdJykudmFsKCkpO1xuICAgICAgICB2YXIgY29tcGF0aWJsZSA9IE51bWJlci5pc0Zpbml0ZShwcm9jZXNzaW5nRGF5cylcbiAgICAgICAgICAgICYmIE51bWJlci5pc0Zpbml0ZShyZXRlbnRpb25EYXlzKVxuICAgICAgICAgICAgJiYgcmV0ZW50aW9uQWxsb3dzUHJvY2Vzc2luZyhwcm9jZXNzaW5nRGF5cywgcmV0ZW50aW9uRGF5cyk7XG4gICAgICAgIHZhciAkbWVzc2FnZSA9ICRzZXR0aW5nc0Zvcm0uZmluZCgnLnN0dC1yZXRlbnRpb24tY29uZmxpY3QtbWVzc2FnZScpO1xuXG4gICAgICAgICRtZXNzYWdlLnRvZ2dsZUNsYXNzKCdoaWRkZW4nLCBjb21wYXRpYmxlKS5hdHRyKCdhcmlhLWhpZGRlbicsIGNvbXBhdGlibGUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcblxuICAgICAgICByZXR1cm4gY29tcGF0aWJsZTtcbiAgICB9XG5cbiAgICBmdW5jdGlvbiBpbml0aWFsaXplTW9kZWxNYXJrZXRwbGFjZSgkbW9kZWxGb3JtKSB7XG4gICAgICAgIHZhciAkc2F2ZUJ1dHRvbiA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbW9kZWwtc2F2ZScpO1xuICAgICAgICB2YXIgJG1vZGVsSW5wdXRzID0gJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LW1vZGVsLW1hcmtldHBsYWNlLXRhYmxlIGlucHV0W25hbWU9XCJzZWxlY3RlZF9tb2RlbFwiXScpO1xuICAgICAgICB2YXIgJGNvbXBhdGliaWxpdHlNZXNzYWdlID0gJCgnI3N0dC1tb2RlbC1sYW5ndWFnZS1jb21wYXRpYmlsaXR5Jyk7XG4gICAgICAgIHZhciBpbml0aWFsU2VsZWN0ZWRNb2RlbCA9IFN0cmluZygkbW9kZWxGb3JtLmRhdGEoJ2luaXRpYWwtc2VsZWN0ZWQtbW9kZWwnKSB8fCAkbW9kZWxJbnB1dHMuZmlsdGVyKCc6Y2hlY2tlZCcpLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgdmFyIHNlbGVjdGVkTGFuZ3VhZ2UgPSBTdHJpbmcoJG1vZGVsRm9ybS5kYXRhKCdzZWxlY3RlZC1sYW5ndWFnZScpIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTtcblxuICAgICAgICBmdW5jdGlvbiBzZWxlY3RlZE1vZGVsKCkge1xuICAgICAgICAgICAgcmV0dXJuIFN0cmluZygkbW9kZWxJbnB1dHMuZmlsdGVyKCc6Y2hlY2tlZCcpLnZhbCgpIHx8ICcnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHNlbGVjdGVkTW9kZWxTdXBwb3J0c0xhbmd1YWdlKCkge1xuICAgICAgICAgICAgdmFyICRzZWxlY3RlZElucHV0ID0gJG1vZGVsSW5wdXRzLmZpbHRlcignOmNoZWNrZWQnKTtcbiAgICAgICAgICAgIHZhciBzdXBwb3J0ZWRMYW5ndWFnZXM7XG5cbiAgICAgICAgICAgIGlmIChzZWxlY3RlZExhbmd1YWdlID09PSAnJyB8fCBzZWxlY3RlZExhbmd1YWdlID09PSAnYXV0bycpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICgkc2VsZWN0ZWRJbnB1dC5sZW5ndGggPT09IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHN1cHBvcnRlZExhbmd1YWdlcyA9IFN0cmluZygkc2VsZWN0ZWRJbnB1dC5hdHRyKCdkYXRhLXN1cHBvcnRlZC1sYW5ndWFnZXMnKSB8fCAnJylcbiAgICAgICAgICAgICAgICAuc3BsaXQoJywnKVxuICAgICAgICAgICAgICAgIC5tYXAoZnVuY3Rpb24gKGxhbmd1YWdlKSB7IHJldHVybiBsYW5ndWFnZS50cmltKCkudG9Mb3dlckNhc2UoKTsgfSlcbiAgICAgICAgICAgICAgICAuZmlsdGVyKGZ1bmN0aW9uIChsYW5ndWFnZSkgeyByZXR1cm4gbGFuZ3VhZ2UgIT09ICcnOyB9KTtcblxuICAgICAgICAgICAgcmV0dXJuIHN1cHBvcnRlZExhbmd1YWdlcy5pbmRleE9mKHNlbGVjdGVkTGFuZ3VhZ2UpICE9PSAtMTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIHVwZGF0ZUNvbXBhdGliaWxpdHlTdGF0ZSgpIHtcbiAgICAgICAgICAgIHZhciBjb21wYXRpYmxlID0gc2VsZWN0ZWRNb2RlbFN1cHBvcnRzTGFuZ3VhZ2UoKTtcblxuICAgICAgICAgICAgJGNvbXBhdGliaWxpdHlNZXNzYWdlXG4gICAgICAgICAgICAgICAgLnRvZ2dsZUNsYXNzKCdoaWRkZW4nLCBjb21wYXRpYmxlKVxuICAgICAgICAgICAgICAgIC5hdHRyKCdhcmlhLWhpZGRlbicsIGNvbXBhdGlibGUgPyAndHJ1ZScgOiAnZmFsc2UnKTtcblxuICAgICAgICAgICAgcmV0dXJuIGNvbXBhdGlibGU7XG4gICAgICAgIH1cblxuICAgICAgICBmdW5jdGlvbiB1cGRhdGVTYXZlQnV0dG9uU3RhdGUoKSB7XG4gICAgICAgICAgICB2YXIgY29tcGF0aWJsZSA9IHVwZGF0ZUNvbXBhdGliaWxpdHlTdGF0ZSgpO1xuICAgICAgICAgICAgdmFyIGhhc0NoYW5nZXMgPSBzZWxlY3RlZE1vZGVsKCkgIT09ICcnXG4gICAgICAgICAgICAgICAgJiYgc2VsZWN0ZWRNb2RlbCgpICE9PSBpbml0aWFsU2VsZWN0ZWRNb2RlbFxuICAgICAgICAgICAgICAgICYmIGNvbXBhdGlibGU7XG5cbiAgICAgICAgICAgICRzYXZlQnV0dG9uLnRvZ2dsZUNsYXNzKCdkaXNhYmxlZCcsICFoYXNDaGFuZ2VzKTtcbiAgICAgICAgICAgICRzYXZlQnV0dG9uLnByb3AoJ2Rpc2FibGVkJywgIWhhc0NoYW5nZXMpO1xuICAgICAgICB9XG5cbiAgICAgICAgaWYgKHR5cGVvZiAkLmZuLmFjY29yZGlvbiA9PT0gJ2Z1bmN0aW9uJykge1xuICAgICAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAuc3R0LWFkdmFuY2VkLXNldHRpbmdzLWRyb3Bkb3duJykuYWNjb3JkaW9uKCk7XG4gICAgICAgIH1cblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtbW9kZWwtbWFya2V0cGxhY2UtdGFibGUgdGJvZHkgdHInKS5vbignY2xpY2snLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmICgkKGV2ZW50LnRhcmdldCkuY2xvc2VzdCgnYSwgYnV0dG9uLCBmb3JtLCBpbnB1dCwgbGFiZWwnKS5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAkKHRoaXMpLmZpbmQoJy51aS5yYWRpby5jaGVja2JveCcpLmNoZWNrYm94KCdjaGVjaycpO1xuICAgICAgICB9KTtcblxuICAgICAgICAkbW9kZWxJbnB1dHMub24oJ2NoYW5nZScsIGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHZhciAkcm93ID0gJCh0aGlzKS5jbG9zZXN0KCd0cicpO1xuXG4gICAgICAgICAgICAkcm93LmFkZENsYXNzKCdwb3NpdGl2ZScpLnNpYmxpbmdzKCkucmVtb3ZlQ2xhc3MoJ3Bvc2l0aXZlJyk7XG4gICAgICAgICAgICB1cGRhdGVTYXZlQnV0dG9uU3RhdGUoKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJG1vZGVsRm9ybS5vbignc3VibWl0JywgZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgaWYgKCFzZWxlY3RlZE1vZGVsU3VwcG9ydHNMYW5ndWFnZSgpXG4gICAgICAgICAgICAgICAgfHwgc2VsZWN0ZWRNb2RlbCgpID09PSAnJ1xuICAgICAgICAgICAgICAgIHx8IHNlbGVjdGVkTW9kZWwoKSA9PT0gaW5pdGlhbFNlbGVjdGVkTW9kZWwpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAoJHNhdmVCdXR0b24ubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICRzYXZlQnV0dG9uLmFkZENsYXNzKCdsb2FkaW5nIGRpc2FibGVkIGFjdGl2ZScpO1xuICAgICAgICAgICAgICAgICRzYXZlQnV0dG9uLnByb3AoJ2Rpc2FibGVkJywgdHJ1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuXG4gICAgICAgIHVwZGF0ZVNhdmVCdXR0b25TdGF0ZSgpO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVDb25maXJtTW9kYWwoKSB7XG4gICAgICAgIHZhciAkbW9kYWwgPSAkKCcjc3R0LWNvbmZpcm0tbW9kYWwnKTtcblxuICAgICAgICBpZiAoJG1vZGFsLmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJG1vZGFsLm1vZGFsICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICAvLyBQcmUtaW5pdGlhbGl6ZSBzbyBTZW1hbnRpYyBVSSBtb3ZlcyB0aGUgZWxlbWVudCB0byA8Ym9keT4gYW5kIGhpZGVzIGl0IHByb3Blcmx5XG4gICAgICAgICRtb2RhbC5tb2RhbCh7fSk7XG5cbiAgICAgICAgZnVuY3Rpb24gc2hvd0NvbmZpcm0obWVzc2FnZSwgb25Db25maXJtKSB7XG4gICAgICAgICAgICAkbW9kYWwuZmluZCgnLnN0dC1jb25maXJtLW1lc3NhZ2UnKS50ZXh0KG1lc3NhZ2UpO1xuICAgICAgICAgICAgJG1vZGFsLm1vZGFsKHtcbiAgICAgICAgICAgICAgICBjbG9zYWJsZTogdHJ1ZSxcbiAgICAgICAgICAgICAgICBvbkFwcHJvdmU6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgb25Db25maXJtKCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSkubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgfVxuXG4gICAgICAgIGZ1bmN0aW9uIGJpbmRDb25maXJtYWJsZUZvcm0oJGZvcm0pIHtcbiAgICAgICAgICAgICRmb3JtLm9uKCdzdWJtaXQuc3R0LWNvbmZpcm0nLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgICAgICB2YXIgbWVzc2FnZSA9ICQodGhpcykuZGF0YSgnY29uZmlybScpIHx8ICcnO1xuXG4gICAgICAgICAgICAgICAgaWYgKG1lc3NhZ2UgPT09ICcnKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgICAgIGV2ZW50LnN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbigpO1xuXG4gICAgICAgICAgICAgICAgdmFyICRzZWxmID0gJCh0aGlzKTtcbiAgICAgICAgICAgICAgICBzaG93Q29uZmlybShtZXNzYWdlLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICRzZWxmLm9mZignc3VibWl0LnN0dC1jb25maXJtJykudHJpZ2dlcignc3VibWl0Jyk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfVxuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1jb25maXJtYWJsZS1mb3JtJykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBiaW5kQ29uZmlybWFibGVGb3JtKCQodGhpcykpO1xuICAgICAgICB9KTtcbiAgICB9XG5cbiAgICAvLyBUcmFuc2NyaXB0IGRlbGV0aW9uIGlzIGlycmV2ZXJzaWJsZSwgc28gaXQgbmVlZHMgYW4gZXhwbGljaXRcbiAgICAvLyBhY2tub3dsZWRnZW1lbnQ6IHRoZSBkaWFsb2cgbGlzdHMgd2hhdCBpcyBhbmQgaXMgbm90IHJlbW92ZWQsIHRoZVxuICAgIC8vIERlbGV0ZSBidXR0b24gc3RheXMgZGlzYWJsZWQgdW50aWwgdGhlIGNoZWNrYm94IGlzIHRpY2tlZCwgYW5kIG9ubHkgdGhlblxuICAgIC8vIGlzIGNvbmZpcm1fZGVsZXRlIGFkZGVkIHRvIHRoZSBmb3JtIHRoZSBzZXJ2ZXIgcmVxdWlyZXMgaXQgb24uXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHREZWxldGlvbigpIHtcbiAgICAgICAgdmFyICRtb2RhbCA9ICQoJyNzdHQtZGVsZXRlLXRyYW5zY3JpcHQtbW9kYWwnKTtcbiAgICAgICAgdmFyICRmb3JtcyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC1kZWxldGUtdHJhbnNjcmlwdC1mb3JtJyk7XG5cbiAgICAgICAgaWYgKCRtb2RhbC5sZW5ndGggPT09IDAgfHwgJGZvcm1zLmxlbmd0aCA9PT0gMCB8fCB0eXBlb2YgJG1vZGFsLm1vZGFsICE9PSAnZnVuY3Rpb24nKSB7XG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIH1cblxuICAgICAgICB2YXIgJGFja25vd2xlZGdlID0gJG1vZGFsLmZpbmQoJyNzdHQtZGVsZXRlLXRyYW5zY3JpcHQtYWNrbm93bGVkZ2UnKTtcbiAgICAgICAgdmFyICRhcHByb3ZlID0gJG1vZGFsLmZpbmQoJy5hcHByb3ZlLmJ1dHRvbicpO1xuICAgICAgICB2YXIgcGVuZGluZ0Zvcm0gPSBudWxsO1xuXG4gICAgICAgIGZ1bmN0aW9uIHNldEFwcHJvdmVFbmFibGVkKGVuYWJsZWQpIHtcbiAgICAgICAgICAgICRhcHByb3ZlLnRvZ2dsZUNsYXNzKCdkaXNhYmxlZCcsICFlbmFibGVkKS5wcm9wKCdkaXNhYmxlZCcsICFlbmFibGVkKTtcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFByZS1pbml0aWFsaXplIHNvIFNlbWFudGljIFVJIG1vdmVzIHRoZSBlbGVtZW50IHRvIDxib2R5PiBhbmQgaGlkZXMgaXQgcHJvcGVybHlcbiAgICAgICAgJG1vZGFsLm1vZGFsKHt9KTtcbiAgICAgICAgJGFja25vd2xlZGdlLm9uKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICBzZXRBcHByb3ZlRW5hYmxlZCgkYWNrbm93bGVkZ2UucHJvcCgnY2hlY2tlZCcpKTtcbiAgICAgICAgfSk7XG5cbiAgICAgICAgJGZvcm1zLm9uKCdzdWJtaXQuc3R0LWRlbGV0ZS10cmFuc2NyaXB0JywgZnVuY3Rpb24gKGV2ZW50KSB7XG4gICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICAgICAgcGVuZGluZ0Zvcm0gPSB0aGlzO1xuICAgICAgICAgICAgJCh0aGlzKS5jbG9zZXN0KCdkZXRhaWxzJykucHJvcCgnb3BlbicsIGZhbHNlKTtcbiAgICAgICAgICAgICRhY2tub3dsZWRnZS5wcm9wKCdjaGVja2VkJywgZmFsc2UpO1xuICAgICAgICAgICAgc2V0QXBwcm92ZUVuYWJsZWQoZmFsc2UpO1xuICAgICAgICAgICAgJG1vZGFsLm1vZGFsKHtcbiAgICAgICAgICAgICAgICBjbG9zYWJsZTogdHJ1ZSxcbiAgICAgICAgICAgICAgICBvbkFwcHJvdmU6IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgaWYgKCEkYWNrbm93bGVkZ2UucHJvcCgnY2hlY2tlZCcpIHx8IHBlbmRpbmdGb3JtID09PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgdmFyIGZvcm0gPSBwZW5kaW5nRm9ybTtcbiAgICAgICAgICAgICAgICAgICAgcGVuZGluZ0Zvcm0gPSBudWxsO1xuICAgICAgICAgICAgICAgICAgICAkKCc8aW5wdXQ+JywgeyB0eXBlOiAnaGlkZGVuJywgbmFtZTogJ2NvbmZpcm1fZGVsZXRlJywgdmFsdWU6ICcxJyB9KS5hcHBlbmRUbyhmb3JtKTtcbiAgICAgICAgICAgICAgICAgICAgLy8gVGhlIG5hdGl2ZSBzdWJtaXQgc2tpcHMgdGhpcyBoYW5kbGVyLCBzbyB0aGUgZGlhbG9nIGlzIG5vdCBzaG93biBhZ2Fpbi5cbiAgICAgICAgICAgICAgICAgICAgZm9ybS5zdWJtaXQoKTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgICAgICAgICAgfSxcbiAgICAgICAgICAgICAgICBvbkhpZGRlbjogZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgICAgICBwZW5kaW5nRm9ybSA9IG51bGw7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSkubW9kYWwoJ3Nob3cnKTtcbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRSb3dzKCkge1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtdHJhbnNjcmlwdC1yb3cnKS5vbignY2xpY2snLCBmdW5jdGlvbiAoZXZlbnQpIHtcbiAgICAgICAgICAgIGlmICgkKGV2ZW50LnRhcmdldCkuY2xvc2VzdCgnYSwgYnV0dG9uLCBpbnB1dCwgc2VsZWN0LCB0ZXh0YXJlYScpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICByZXR1cm47XG4gICAgICAgICAgICB9XG5cbiAgICAgICAgICAgIHZhciBkZXN0aW5hdGlvbiA9ICQodGhpcykuZGF0YSgndHJhbnNjcmlwdC11cmwnKTtcbiAgICAgICAgICAgIGlmIChkZXN0aW5hdGlvbikge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5sb2NhdGlvbi5ocmVmID0gZGVzdGluYXRpb247XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cblxuICAgIGZ1bmN0aW9uIGluaXRpYWxpemVUcmFuc2NyaXB0QWN0aW9uTWVudSgpIHtcbiAgICAgICAgdmFyICRtZW51cyA9ICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnN0dC10cmFuc2NyaXB0LWFjdGlvbnMnKTtcblxuICAgICAgICAkbWVudXMuZmluZCgnLm1lbnUgYSwgLm1lbnUgYnV0dG9uJykub24oJ2NsaWNrLnN0dC10cmFuc2NyaXB0LWFjdGlvbnMnLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAkKHRoaXMpLmNsb3Nlc3QoJ2RldGFpbHMnKS5yZW1vdmVBdHRyKCdvcGVuJyk7XG4gICAgICAgIH0pO1xuICAgICAgICAkKGRvY3VtZW50KS5vbignY2xpY2suc3R0LXRyYW5zY3JpcHQtYWN0aW9ucycsIGZ1bmN0aW9uIChldmVudCkge1xuICAgICAgICAgICAgJG1lbnVzLmVhY2goZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgICAgIGlmICh0aGlzICE9PSBldmVudC50YXJnZXQgJiYgIXRoaXMuY29udGFpbnMoZXZlbnQudGFyZ2V0KSkge1xuICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnJlbW92ZUF0dHIoJ29wZW4nKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgZnVuY3Rpb24gaW5pdGlhbGl6ZVRyYW5zY3JpcHRMZWdUYWJzKCkge1xuICAgICAgICB2YXIgdHJhbnNjcmlwdExvY2F0aW9uID0gd2luZG93LlN0dFRyYW5zY3JpcHRWaWV3ICYmIHdpbmRvdy5TdHRUcmFuc2NyaXB0Vmlldy5sb2NhdGlvbjtcblxuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtY2FsbC1sZWctcmV2aWV3JykuZWFjaChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgJHJldmlldyA9ICQodGhpcyk7XG4gICAgICAgICAgICB2YXIgJHRhYnMgPSAkcmV2aWV3LmZpbmQoJ1tkYXRhLXN0dC1jYWxsLWxlZ10nKTtcbiAgICAgICAgICAgIHZhciAkcGFuZWxzID0gJHJldmlldy5maW5kKCdbZGF0YS1zdHQtY2FsbC1sZWctcGFuZWxdJyk7XG5cbiAgICAgICAgICAgIGZ1bmN0aW9uIHBhbmVsTGVnKCRlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIFN0cmluZygkZWxlbWVudC5jbG9zZXN0KCdbZGF0YS1zdHQtY2FsbC1sZWctcGFuZWxdJykuZGF0YSgnc3R0LWNhbGwtbGVnLXBhbmVsJykgfHwgJycpO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICBmdW5jdGlvbiBzaG93KGNhbGxMZWcpIHtcbiAgICAgICAgICAgICAgICAkdGFicy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgdmFyIGFjdGl2ZSA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3N0dC1jYWxsLWxlZycpIHx8ICcnKSA9PT0gY2FsbExlZztcbiAgICAgICAgICAgICAgICAgICAgJCh0aGlzKS50b2dnbGVDbGFzcygnYWN0aXZlJywgYWN0aXZlKS5hdHRyKCdhcmlhLXNlbGVjdGVkJywgYWN0aXZlID8gJ3RydWUnIDogJ2ZhbHNlJyk7XG4gICAgICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICAgICAgJHBhbmVscy5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgdmFyIGhpZGRlbiA9IFN0cmluZygkKHRoaXMpLmRhdGEoJ3N0dC1jYWxsLWxlZy1wYW5lbCcpIHx8ICcnKSAhPT0gY2FsbExlZztcblxuICAgICAgICAgICAgICAgICAgICAkKHRoaXMpLnByb3AoJ2hpZGRlbicsIGhpZGRlbik7XG4gICAgICAgICAgICAgICAgICAgIGlmIChoaWRkZW4pIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICQodGhpcykuZmluZCgnYXVkaW8nKS5lYWNoKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICB2YXIgYXVkaW8gPSB0aGlzO1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIGF1ZGlvLnBhdXNlKCk7XG4gICAgICAgICAgICAgICAgICAgICAgICB9KTtcbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAkdGFicy5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgdmFyIGNhbGxMZWcgPSBTdHJpbmcoJCh0aGlzKS5kYXRhKCdzdHQtY2FsbC1sZWcnKSB8fCAnJyk7XG5cbiAgICAgICAgICAgICAgICBzaG93KGNhbGxMZWcpO1xuICAgICAgICAgICAgICAgIGlmICh0cmFuc2NyaXB0TG9jYXRpb24pIHtcbiAgICAgICAgICAgICAgICAgICAgLy8gQSBoZWxkIG1vbWVudCBpbiB0aGUgb3RoZXIgbGVnIG5vIGxvbmdlciBkZXNjcmliZXMgd2hhdCBpcyBvbiBzY3JlZW4uXG4gICAgICAgICAgICAgICAgICAgIHZhciBtb21lbnQgPSB0cmFuc2NyaXB0TG9jYXRpb24ucmVhZCh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgICAgICAgICAgICAgdmFyICRzdGFnZSA9IG1vbWVudC5zdGFnZSA/ICRyZXZpZXcuZmluZCgnLnN0dC10cmFuc2NyaXB0LXZpZXdbZGF0YS1zdHQtc3RhZ2U9XCInICsgbW9tZW50LnN0YWdlICsgJ1wiXScpIDogJCgpO1xuICAgICAgICAgICAgICAgICAgICB2YXIgY2hhbmdlcyA9IHsgbGVnOiBjYWxsTGVnIH07XG4gICAgICAgICAgICAgICAgICAgIGlmICgkc3RhZ2UubGVuZ3RoID4gMCAmJiBwYW5lbExlZygkc3RhZ2UpICE9PSBjYWxsTGVnKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjaGFuZ2VzLnN0YWdlID0gJyc7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgdHJhbnNjcmlwdExvY2F0aW9uLndyaXRlKGNoYW5nZXMpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0pO1xuXG4gICAgICAgICAgICAvLyBUaGUgYWRkcmVzcyBwaWNrcyB0aGUgbGVnOiB0aGUgbGVnIG9mIGEgc2hhcmVkIG1vbWVudCBmaXJzdCwgdGhlbiBhbiBleHBsaWNpdCBsZWcuXG4gICAgICAgICAgICBpZiAodHJhbnNjcmlwdExvY2F0aW9uKSB7XG4gICAgICAgICAgICAgICAgdmFyIGluaXRpYWwgPSB0cmFuc2NyaXB0TG9jYXRpb24ucmVhZCh3aW5kb3cubG9jYXRpb24uc2VhcmNoKTtcbiAgICAgICAgICAgICAgICB2YXIgJHRhcmdldCA9IGluaXRpYWwuc3RhZ2UgPyAkcmV2aWV3LmZpbmQoJy5zdHQtdHJhbnNjcmlwdC12aWV3W2RhdGEtc3R0LXN0YWdlPVwiJyArIGluaXRpYWwuc3RhZ2UgKyAnXCJdJykgOiAkKCk7XG4gICAgICAgICAgICAgICAgdmFyIGxlZyA9ICR0YXJnZXQubGVuZ3RoID4gMCA/IHBhbmVsTGVnKCR0YXJnZXQpIDogaW5pdGlhbC5sZWc7XG4gICAgICAgICAgICAgICAgaWYgKGxlZyAhPT0gJycgJiYgJHRhYnMuZmlsdGVyKCdbZGF0YS1zdHQtY2FsbC1sZWc9XCInICsgbGVnICsgJ1wiXScpLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgc2hvdyhsZWcpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuXG4gICAgJChmdW5jdGlvbiAoKSB7XG4gICAgICAgIC8vIE9ubHkgaXRlbXMgd2l0aCBhIGRhdGEtdGFiIHRhcmdldCBhcmUgY2xpZW50LXNpZGUgdGFicy4gVGhlXG4gICAgICAgIC8vIFRyYW5zY3JpcHRzIGl0ZW0gaXMgYSByZWFsIGxpbmsgKD90YWI9dHJhbnNjcmlwdHMsIGZ1bGwgcGFnZVxuICAgICAgICAvLyBuYXZpZ2F0aW9uKSBiZWNhdXNlIGl0cyBjb250ZW50IGlzIG9ubHkgcmVuZGVyZWQgc2VydmVyLXNpZGUgd2hlblxuICAgICAgICAvLyB0aGF0IHRhYiBpcyB0aGUgb25lIGJlaW5nIGxvYWRlZCAtIHNlZSBpbmRleC52b2x0L0luZGV4Vmlld1NlcnZpY2UuXG4gICAgICAgIHZhciAkdGFiTWVudSA9ICQoJyNtb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQtbWVudScpO1xuICAgICAgICB2YXIgJHRhYkl0ZW1zID0gJHRhYk1lbnUuZmluZCgnLml0ZW1bZGF0YS10YWJdJyk7XG4gICAgICAgIHZhciAkc2V0dGluZ3NGb3JtID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1mb3JtJyk7XG4gICAgICAgIHZhciAkbW9kZWxGb3JtID0gJCgnI21vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dC1tb2RlbC1mb3JtJyk7XG4gICAgICAgICR0YWJJdGVtcy50YWIoe1xuICAgICAgICAgICAgb25WaXNpYmxlOiBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgLy8gU2VtYW50aWMgVUkncyB0YWIgbW9kdWxlICgyLjQuMSwgc2VlXG4gICAgICAgICAgICAgICAgLy8gcHVibGljL2Fzc2V0cy9qcy92ZW5kb3IgZXF1aXZhbGVudCB1bmRlciBDb3JlKSBvbmx5XG4gICAgICAgICAgICAgICAgLy8gKGRlKWFjdGl2YXRlcyBtZW51IGl0ZW1zIGl0IHdhcyBpbml0aWFsaXplZCBvblxuICAgICAgICAgICAgICAgIC8vICgkdGFiSXRlbXMsIGkuZS4gaXRlbXMgd2l0aCBhIGRhdGEtdGFiKSAtIHNlZSBpdHNcbiAgICAgICAgICAgICAgICAvLyBhY3RpdmF0ZS5uYXZpZ2F0aW9uKCkuIFRoZSBUcmFuc2NyaXB0cyBpdGVtIGlzXG4gICAgICAgICAgICAgICAgLy8gZGVsaWJlcmF0ZWx5IG91dHNpZGUgdGhhdCBzZXQsIHNvIGEgbm9ybWFsIHRhYiBzd2l0Y2hcbiAgICAgICAgICAgICAgICAvLyBuZXZlciByZW1vdmVzIGl0cyAnYWN0aXZlJyBjbGFzczogd2l0aG91dCB0aGlzLCBsYW5kaW5nXG4gICAgICAgICAgICAgICAgLy8gb24gP3RhYj10cmFuc2NyaXB0cyAod2hpY2ggc2VydmVyLXJlbmRlcnMgaXQgYWN0aXZlKSBhbmRcbiAgICAgICAgICAgICAgICAvLyB0aGVuIGNsaWNraW5nIGFueSBvdGhlciB0YWIgd291bGQgbGVhdmUgVHJhbnNjcmlwdHNcbiAgICAgICAgICAgICAgICAvLyBoaWdobGlnaHRlZCBhbG9uZ3NpZGUgaXQuXG4gICAgICAgICAgICAgICAgJHRhYk1lbnUuZmluZCgnLml0ZW0nKS5ub3QoJ1tkYXRhLXRhYl0nKS5yZW1vdmVDbGFzcygnYWN0aXZlJyk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgICAgICBpZiAocmVxdWVzdGVkVGFiKCkgIT09ICcnICYmIHJlcXVlc3RlZFRhYigpICE9PSAndHJhbnNjcmlwdHMnKSB7XG4gICAgICAgICAgICAvLyAndHJhbnNjcmlwdHMnIGhhcyBubyBtYW5hZ2VkIG5hdiBpdGVtIChubyBkYXRhLXRhYikgYW55IG1vcmU6XG4gICAgICAgICAgICAvLyBpdHMgcGFuZWwgYW5kIGl0cyBtZW51IGxpbmsgYXJlIGFscmVhZHkgcmVuZGVyZWQgc2VydmVyLXNpZGVcbiAgICAgICAgICAgIC8vIGFzIGFjdGl2ZSBvbiA/dGFiPXRyYW5zY3JpcHRzIChzZWUgaW5kZXgudm9sdCksIHNvIHRoZXJlIGlzXG4gICAgICAgICAgICAvLyBub3RoaW5nIGhlcmUgZm9yIHRoZSB0YWIgbW9kdWxlIHRvIHN3aXRjaCB0byAtIHNraXAgdGhlIGNhbGxcbiAgICAgICAgICAgIC8vIGluc3RlYWQgb2YgcmVseWluZyBvbiBpdCBiZWluZyBhIGhhcm1sZXNzIG5vLW9wLlxuICAgICAgICAgICAgJHRhYkl0ZW1zLnRhYignY2hhbmdlIHRhYicsIHJlcXVlc3RlZFRhYigpKTtcbiAgICAgICAgfVxuXG4gICAgICAgICQoJy5tb2R1bGUtbG9jYWwtc3BlZWNoLXRvLXRleHQgLnVpLmRyb3Bkb3duJykuZHJvcGRvd24oKTtcbiAgICAgICAgJCgnLm1vZHVsZS1sb2NhbC1zcGVlY2gtdG8tdGV4dCAudWkuY2hlY2tib3gnKS5jaGVja2JveCgpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IFtkYXRhLWNvbnRlbnRdJykucG9wdXAoKTtcbiAgICAgICAgaW5pdGlhbGl6ZU1vZGVsTWFya2V0cGxhY2UoJG1vZGVsRm9ybSk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0RGF0ZUZpbHRlcigpO1xuICAgICAgICBpbml0aWFsaXplTW9kdWxlTG9ncygpO1xuICAgICAgICBpbml0aWFsaXplQ2RySGlzdG9yeVNsaWRlcigkc2V0dGluZ3NGb3JtKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRSZXRlbnRpb25TbGlkZXIoJHNldHRpbmdzRm9ybSk7XG4gICAgICAgIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICBpbml0aWFsaXplQ29uZmlybU1vZGFsKCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0RGVsZXRpb24oKTtcbiAgICAgICAgaW5pdGlhbGl6ZVRyYW5zY3JpcHRSb3dzKCk7XG4gICAgICAgIGluaXRpYWxpemVUcmFuc2NyaXB0QWN0aW9uTWVudSgpO1xuICAgICAgICBpbml0aWFsaXplVHJhbnNjcmlwdExlZ1RhYnMoKTtcblxuICAgICAgICBpZiAoJC5mbi5mb3JtICYmICQuZm4uZm9ybS5zZXR0aW5ncyAmJiAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMpIHtcbiAgICAgICAgICAgICQuZm4uZm9ybS5zZXR0aW5ncy5ydWxlcy5zdHRQcm9jZXNzaW5nUmFuZ2UgPSBmdW5jdGlvbiAodmFsdWUsIHJhbmdlKSB7XG4gICAgICAgICAgICAgICAgdmFyIGxpbWl0cyA9IFN0cmluZyhyYW5nZSB8fCAnJykuc3BsaXQoJywnKS5tYXAoTnVtYmVyKTtcbiAgICAgICAgICAgICAgICB2YXIgbnVtYmVyID0gTnVtYmVyKHZhbHVlKTtcblxuICAgICAgICAgICAgICAgIHJldHVybiBsaW1pdHMubGVuZ3RoID09PSAyXG4gICAgICAgICAgICAgICAgICAgICYmIE51bWJlci5pc0Zpbml0ZShudW1iZXIpXG4gICAgICAgICAgICAgICAgICAgICYmIG51bWJlciA+PSBsaW1pdHNbMF1cbiAgICAgICAgICAgICAgICAgICAgJiYgbnVtYmVyIDw9IGxpbWl0c1sxXTtcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICAkLmZuLmZvcm0uc2V0dGluZ3MucnVsZXMuc3R0UHJvY2Vzc2luZ092ZXJsYXAgPSBmdW5jdGlvbiAodmFsdWUpIHtcbiAgICAgICAgICAgICAgICB2YXIgb3ZlcmxhcE1TID0gTWF0aC5yb3VuZChOdW1iZXIodmFsdWUpICogMTAwMCk7XG4gICAgICAgICAgICAgICAgdmFyIGR1cmF0aW9uTVMgPSBNYXRoLnJvdW5kKE51bWJlcigkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwibWF4X3NlZ21lbnRfZHVyYXRpb25fc2VjXCJdJykudmFsKCkpICogMTAwMCk7XG5cbiAgICAgICAgICAgICAgICByZXR1cm4gTnVtYmVyLmlzRmluaXRlKG92ZXJsYXBNUylcbiAgICAgICAgICAgICAgICAgICAgJiYgTnVtYmVyLmlzRmluaXRlKGR1cmF0aW9uTVMpXG4gICAgICAgICAgICAgICAgICAgICYmIG92ZXJsYXBNUyA+PSAwXG4gICAgICAgICAgICAgICAgICAgICYmIG92ZXJsYXBNUyA8IGR1cmF0aW9uTVM7XG4gICAgICAgICAgICB9O1xuICAgICAgICAgICAgJC5mbi5mb3JtLnNldHRpbmdzLnJ1bGVzLnN0dFJldGVudGlvbkNvbXBhdGliaWxpdHkgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHVwZGF0ZVJldGVudGlvbkNvbmZsaWN0KCRzZXR0aW5nc0Zvcm0pO1xuICAgICAgICAgICAgfTtcbiAgICAgICAgfVxuXG4gICAgICAgIHZhciBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcyA9IHtcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3RlbXBlcmF0dXJlJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHRlbXBlcmF0dXJlX2ZhbGxiYWNrX3N0ZXA6IHtcbiAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndGVtcGVyYXR1cmVfZmFsbGJhY2tfc3RlcCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0ZW1wZXJhdHVyZV9mYWxsYmFja19jb3VudCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzAuLjEwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICB0b3Bfazoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICd0b3BfaycsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdpbnRlZ2VyWzEuLjEwMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgY29tcHJlc3Npb25fcmF0aW9fdGhyZXNob2xkOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2NvbXByZXNzaW9uX3JhdGlvX3RocmVzaG9sZCcsXG4gICAgICAgICAgICAgICAgcnVsZXM6IFt7IHR5cGU6ICdzdHRQcm9jZXNzaW5nUmFuZ2VbMCwxMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdsb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVstMTAsMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgZmlyc3RfdG9rZW5fbG9nX3Byb2JhYmlsaXR5X3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdmaXJzdF90b2tlbl9sb2dfcHJvYmFiaWxpdHlfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVstMTAsMF0nIH1dXG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgbm9fc3BlZWNoX3RocmVzaG9sZDoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdub19zcGVlY2hfdGhyZXNob2xkJyxcbiAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDFdJyB9XVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIG1heF9zZWdtZW50X2R1cmF0aW9uX3NlYzoge1xuICAgICAgICAgICAgICAgIGlkZW50aWZpZXI6ICdtYXhfc2VnbWVudF9kdXJhdGlvbl9zZWMnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ1JhbmdlWzEsMTIwXScgfV1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZWdtZW50X292ZXJsYXBfc2VjOiB7XG4gICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ3NlZ21lbnRfb3ZlcmxhcF9zZWMnLFxuICAgICAgICAgICAgICAgIHJ1bGVzOiBbXG4gICAgICAgICAgICAgICAgICAgIHsgdHlwZTogJ3N0dFByb2Nlc3NpbmdSYW5nZVswLDEwXScgfSxcbiAgICAgICAgICAgICAgICAgICAgeyB0eXBlOiAnc3R0UHJvY2Vzc2luZ092ZXJsYXAnIH1cbiAgICAgICAgICAgICAgICBdXG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG5cbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlVmFkRGVwZW5kZW50RmllbGRzKCkge1xuICAgICAgICAgICAgdmFyIGVuYWJsZWQgPSAkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwidm9pY2VfYWN0aXZpdHlfZGV0ZWN0aW9uXCJdJykuaXMoJzpjaGVja2VkJyk7XG5cbiAgICAgICAgICAgICRzZXR0aW5nc0Zvcm0uZmluZCgnLnN0dC12YWQtZGVwZW5kZW50JylcbiAgICAgICAgICAgICAgICAudG9nZ2xlQ2xhc3MoJ2Rpc2FibGVkJywgIWVuYWJsZWQpXG4gICAgICAgICAgICAgICAgLmF0dHIoJ2FyaWEtZGlzYWJsZWQnLCBlbmFibGVkID8gJ2ZhbHNlJyA6ICd0cnVlJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkc2V0dGluZ3NGb3JtLmZpbmQoJ1tuYW1lPVwidm9pY2VfYWN0aXZpdHlfZGV0ZWN0aW9uXCJdJykub24oJ2NoYW5nZScsIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcyk7XG4gICAgICAgIHVwZGF0ZVZhZERlcGVuZGVudEZpZWxkcygpO1xuICAgICAgICAkKCcubW9kdWxlLWxvY2FsLXNwZWVjaC10by10ZXh0IC5zdHQtY29weS1idXR0b24nKS5vbignY2xpY2snLCBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB2YXIgdGV4dCA9ICQodGhpcykuZGF0YSgnY2xpcGJvYXJkLXRleHQnKSB8fCAnJztcbiAgICAgICAgICAgIHZhciAkYnV0dG9uID0gJCh0aGlzKTtcblxuICAgICAgICAgICAgaWYgKG5hdmlnYXRvci5jbGlwYm9hcmQgJiYgbmF2aWdhdG9yLmNsaXBib2FyZC53cml0ZVRleHQpIHtcbiAgICAgICAgICAgICAgICBuYXZpZ2F0b3IuY2xpcGJvYXJkLndyaXRlVGV4dCh0ZXh0KS50aGVuKGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgICAgICAgICAgJGJ1dHRvbi5hZGRDbGFzcygncG9zaXRpdmUnKTtcbiAgICAgICAgICAgICAgICAgICAgc2V0VGltZW91dChmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICAkYnV0dG9uLnJlbW92ZUNsYXNzKCdwb3NpdGl2ZScpO1xuICAgICAgICAgICAgICAgICAgICB9LCA5MDApO1xuICAgICAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcblxuICAgICAgICBpZiAodHlwZW9mIEZvcm0gIT09ICd1bmRlZmluZWQnICYmICRzZXR0aW5nc0Zvcm0ubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgRm9ybS4kZm9ybU9iaiA9ICRzZXR0aW5nc0Zvcm07XG4gICAgICAgICAgICBGb3JtLnVybCA9ICRzZXR0aW5nc0Zvcm0uYXR0cignYWN0aW9uJyk7XG4gICAgICAgICAgICBGb3JtLm1ldGhvZCA9ICdQT1NUJztcbiAgICAgICAgICAgIEZvcm0udmFsaWRhdGVSdWxlcyA9IE9iamVjdC5hc3NpZ24oe1xuICAgICAgICAgICAgICAgIGRlZmF1bHRfbGFuZ3VhZ2U6IHtcbiAgICAgICAgICAgICAgICAgICAgaWRlbnRpZmllcjogJ2RlZmF1bHRfbGFuZ3VhZ2UnLFxuICAgICAgICAgICAgICAgICAgICBydWxlczogW3sgdHlwZTogJ2VtcHR5JyB9XVxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgY2RyX2hpc3RvcnlfZGF5czoge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAnY2RyX2hpc3RvcnlfZGF5cycsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbeyB0eXBlOiAnaW50ZWdlclswLi4zNjVdJyB9XVxuICAgICAgICAgICAgICAgIH0sXG4gICAgICAgICAgICAgICAgdHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5czoge1xuICAgICAgICAgICAgICAgICAgICBpZGVudGlmaWVyOiAndHJhbnNjcmlwdF9yZXRlbnRpb25fZGF5cycsXG4gICAgICAgICAgICAgICAgICAgIHJ1bGVzOiBbXG4gICAgICAgICAgICAgICAgICAgICAgICB7IHR5cGU6ICdpbnRlZ2VyWzAuLjM2NV0nIH0sXG4gICAgICAgICAgICAgICAgICAgICAgICB7XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgdHlwZTogJ3N0dFJldGVudGlvbkNvbXBhdGliaWxpdHknLFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHByb21wdDogdHJhbnNsYXRpb24oXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdtb2R1bGVfbG9jYWxfc3BlZWNoX3RvX3RleHRfVHJhbnNjcmlwdFJldGVudGlvbkNvbmZsaWN0JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgJ1RoZSByZWNvcmRpbmcgcHJvY2Vzc2luZyB3aW5kb3cgY2Fubm90IGV4Y2VlZCB0cmFuc2NyaXB0IHJldGVudGlvbi4gSW5jcmVhc2UgcmV0ZW50aW9uIG9yIHJlZHVjZSB0aGUgcHJvY2Vzc2luZyB3aW5kb3cuJ1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIClcbiAgICAgICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgXVxuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sIHByb2Nlc3NpbmdWYWxpZGF0aW9uRmllbGRzKTtcbiAgICAgICAgICAgIEZvcm0uY2JCZWZvcmVTZW5kRm9ybSA9IGZ1bmN0aW9uIChzZXR0aW5ncykge1xuICAgICAgICAgICAgICAgIHJldHVybiBzZXR0aW5ncztcbiAgICAgICAgICAgIH07XG4gICAgICAgICAgICBGb3JtLmNiQWZ0ZXJTZW5kRm9ybSA9IGZ1bmN0aW9uICgpIHt9O1xuICAgICAgICAgICAgRm9ybS5hcGlTZXR0aW5ncy5lbmFibGVkID0gZmFsc2U7XG4gICAgICAgICAgICBGb3JtLmFwaVNldHRpbmdzLmFwaU9iamVjdCA9IG51bGw7XG4gICAgICAgICAgICBGb3JtLnNlbmRPbmx5Q2hhbmdlZCA9IGZhbHNlO1xuICAgICAgICAgICAgRm9ybS5lbmFibGVEaXJyaXR5ID0gdHJ1ZTtcbiAgICAgICAgICAgIEZvcm0uaW5pdGlhbGl6ZSgpO1xuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgJHNldHRpbmdzRm9ybS5mb3JtKHtcbiAgICAgICAgICAgICAgICBmaWVsZHM6IE9iamVjdC5hc3NpZ24oe1xuICAgICAgICAgICAgICAgICAgICBkZWZhdWx0X2xhbmd1YWdlOiBbJ2VtcHR5J10sXG4gICAgICAgICAgICAgICAgICAgIGNkcl9oaXN0b3J5X2RheXM6IFsnaW50ZWdlclswLi4zNjVdJ10sXG4gICAgICAgICAgICAgICAgICAgIHRyYW5zY3JpcHRfcmV0ZW50aW9uX2RheXM6IFtcbiAgICAgICAgICAgICAgICAgICAgICAgICdpbnRlZ2VyWzAuLjM2NV0nLFxuICAgICAgICAgICAgICAgICAgICAgICAge1xuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHR5cGU6ICdzdHRSZXRlbnRpb25Db21wYXRpYmlsaXR5JyxcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBwcm9tcHQ6IHRyYW5zbGF0aW9uKFxuICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAnbW9kdWxlX2xvY2FsX3NwZWVjaF90b190ZXh0X1RyYW5zY3JpcHRSZXRlbnRpb25Db25mbGljdCcsXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICdUaGUgcmVjb3JkaW5nIHByb2Nlc3Npbmcgd2luZG93IGNhbm5vdCBleGNlZWQgdHJhbnNjcmlwdCByZXRlbnRpb24uIEluY3JlYXNlIHJldGVudGlvbiBvciByZWR1Y2UgdGhlIHByb2Nlc3Npbmcgd2luZG93LidcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICApXG4gICAgICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIF1cbiAgICAgICAgICAgICAgICB9LCBwcm9jZXNzaW5nVmFsaWRhdGlvbkZpZWxkcylcbiAgICAgICAgICAgIH0pO1xuICAgICAgICB9XG5cbiAgICAgICAgJG1vZGVsRm9ybS5mb3JtKHtcbiAgICAgICAgICAgIGZpZWxkczoge1xuICAgICAgICAgICAgICAgIHNlbGVjdGVkX21vZGVsOiBbJ2VtcHR5J11cbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfSk7XG59KCkpO1xuIl0sIm1hcHBpbmdzIjoiOzs7O0FBQUMsYUFBWTtFQUNULFlBQVk7O0VBRVosU0FBU0EsV0FBV0EsQ0FBQ0MsR0FBRyxFQUFFQyxRQUFRLEVBQUU7SUFDaEMsSUFBSSxPQUFPQyxlQUFlLEtBQUssV0FBVyxJQUFJQSxlQUFlLENBQUNGLEdBQUcsQ0FBQyxFQUFFO01BQ2hFLE9BQU9FLGVBQWUsQ0FBQ0YsR0FBRyxDQUFDO0lBQy9CO0lBRUEsT0FBT0MsUUFBUTtFQUNuQjtFQUVBLFNBQVNFLFlBQVlBLENBQUEsRUFBRztJQUNwQixJQUFJQyxNQUFNLEdBQUcsSUFBSUMsZUFBZSxDQUFDQyxNQUFNLENBQUNDLFFBQVEsQ0FBQ0MsTUFBTSxDQUFDO0lBQ3hELElBQUlDLE9BQU8sR0FBR0wsTUFBTSxDQUFDTSxHQUFHLENBQUMsS0FBSyxDQUFDLElBQUlKLE1BQU0sQ0FBQ0MsUUFB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