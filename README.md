⚡ WebP Image Converter v2.0

Мощный веб-инструмент для пакетной конвертации изображений в WebP с предпросмотром, аналитикой и дополнительными утилитами.

Разработан на PHP 8.3 + GD, без сторонних зависимостей.

🚀 Возможности
📂 Работа с файлами
Сканирование директорий (рекурсивно)
Поддержка форматов:
JPEG
PNG
GIF
WebP (для анализа)
Drag & Drop загрузка изображений
Фильтрация по:
формату
размеру
статусу (готов / уже WebP / слишком большой)
🔄 Конвертация
Пакетная конвертация в WebP
Настройка качества (1–100)
Ограничение максимальной ширины
Два режима:
separate — в отдельную папку
replace — замена оригиналов
Пропуск уже существующих файлов
Удаление метаданных (strip EXIF)
🖼 Превью и визуализация
Миниатюры (thumbnail API)
Полноразмерный preview
Сравнение "до / после"
Trial-конвертация (без сохранения)
EXIF auto-rotation (исправление поворота)
⚡ Оптимизация
Генерация LQIP (Low Quality Image Placeholder)
→ base64 WebP для lazy-loading
Подсчёт:
экономии размера
процента сжатия
общего веса до/после
📊 Отчёты и экспорт
CSV экспорт результатов
Статистика:
общее количество файлов
готовые к конвертации
уже WebP
слишком большие
🌐 Интеграция с сервером

Автогенерация конфигов:

Nginx
автоматическая подмена на WebP через Accept
Apache (.htaccess)
rewrite правила для WebP
fallback на оригинал
📤 Upload API
Загрузка изображений через форму
Валидация MIME
Авто-уникализация имён
🧪 System Check

Проверка окружения:

GD library
WebP support
EXIF support
PHP version
memory_limit
upload limits
🧠 Как это работает
Сканируется директория (scanImages)
Определяется MIME и размер
Отбираются конвертируемые файлы
Для каждого файла:
загрузка через GD
исправление EXIF ориентации
ресайз (если задан)
конвертация через imagewebp()
Считается статистика
⚙️ Установка
1. Требования
PHP ≥ 8.0 (рекомендуется 8.3)
GD с поддержкой WebP
(опционально) EXIF
2. Установка

3. Запуск

Просто открой в браузере:

http://localhost/converter.php
📁 Структура
/converter_3.php       # основной файл (backend + UI)
/webp_output/          # выходная папка (по умолчанию)
/webp_uploads/         # загруженные файлы
/tmp/webp_converter_trial/  # временные preview
🔌 API (POST)
scan
action=scan
directory=/path/to/images
convert
action=convert
quality=80
mode=separate|replace
max_width=1200
upload
action=upload
images[]=@file.jpg
check
action=check
🌍 API (GET)
?thumb=path — миниатюра
?preview=path — preview
?trial=hash — тестовый WebP
?export_csv=base64 — экспорт CSV
⚠️ Ограничения
Максимальный размер файла: 50 MB (настраивается)
Поддержка только GD (без Imagick)
GIF → конвертируется как статичное изображение
💡 Применение
Оптимизация сайта
SEO (ускорение загрузки)
Генерация LQIP для lazy-loading
Подготовка изображений для CDN
🛠 TODO / идеи
Поддержка AVIF
CLI версия
очередь задач (batch processing)
WebSocket прогресс
интеграция с CDN
📜 License

MIT License
