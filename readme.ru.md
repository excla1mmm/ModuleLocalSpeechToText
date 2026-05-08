# ModuleLocalSpeechToText

Модуль локальной транскрибации для MikoPBX.

Модуль хранит очередь заданий и результаты в локальной SQLite-БД, предоставляет REST API для внешнего Mac worker на Apple Silicon и интерфейс администратора для настроек, очереди, воркеров, транскриптов и просмотра диалога.

Модель Whisper выбирается в веб-интерфейсе MikoPBX. `jobs:getNext` отдаёт worker-у preset, Hugging Face repo выбранной модели и выбранные термины словарей, поэтому worker скачивает модель лениво только при первом использовании и добавляет термины в контекстный prompt.

## PBX worker

`Modules\ModuleLocalSpeechToText\Lib\WorkerCreateTranscriptionJobs` регистрируется через `LocalSpeechToTextConf::getModuleWorkers()`.

Он периодически читает завершенные CDR внутри monitor-директории MikoPBX и создает по одному `m_STTJob` на доступный путь записи. Дубликаты предотвращаются проверкой/индексом `file_path`, записи с задержкой появления аудиофайла сохраняются в retry-буфере, а зависшие `processing`-задания возвращаются в очередь после настроенного таймаута.

## Интерфейс администратора

Главная страница модуля использует вкладки в стиле MikoPBX:

- `Настройки`: язык (`auto` по умолчанию плюс 20 популярных языков Whisper), интервал опроса, максимальный размер файла, CDR за проход, таймаут задания, модель Whisper, предустановленные словари и кастомные термины.
- `Очередь`: счетчики `pending`, `processing`, `done today`, `errors`.
- `Воркеры`: зарегистрированные worker'ы, IP, модель, статус и последняя активность.
- `Транскрипты`: последние 100 результатов с переходом к диалогу.

Карточка диалога показывает stereo-транскрипт двухколоночной лентой: левый канал как `Спикер 1`, правый канал как `Спикер 2`.

## API воркера

Базовый путь:

```text
/pbxcore/api/v3/module-local-speech-to-text
```

Эндпоинты:

- `POST /workers:register`
- `GET /jobs:getNext?worker_uid=...`
- `GET /jobs/{job_id}:download?worker_uid=...`
- `POST /jobs/{job_id}:heartbeat`
- `POST /jobs/{job_id}:submitResult`
- `POST /jobs/{job_id}:submitError`

Все JSON-эндпоинты используют стандартную обёртку MikoPBX REST v3 `PBXApiResult` и защищены Bearer token или localhost-правилами.

`jobs:getNext` возвращает `model_id`, `model_repo` и `vocabulary_terms`; внешний worker использует `model_repo` для ленивой локальной загрузки модели и добавляет термины в контекстный prompt. Download, `heartbeat`, `submitResult` и `submitError` проверяют, что задание сейчас закреплено именно за этим worker'ом.

`submitResult` может передавать необязательный JSON `technical_info` с метриками качества и производительности worker'а. Модуль сохраняет его вместе с транскриптом и показывает в разделе "Техническая информация" карточки диалога.
