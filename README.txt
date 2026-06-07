Что внутри:
- index.html, styles.css, app.js - фронтенд
- api/ - PHP эндпоинты
- results/ - JSON данные для аналитики
- server.py - локальный сервер (статика + API), если php-cli не установлен

Быстрый запуск (рекомендуется):
1)  Запусти:
   python server.py
2) Открой в браузере:
   http://127.0.0.1:4173

Какие API есть:
- GET /api/specialities
- GET /api/speciality?id=<id>
- GET /api/specialities/<id>

Как работает фронт:
1) Берет список специальностей из API (/api/specialities)
2) При открытии карточки берет JSON выбранной специальности из API (/api/speciality?id=...)
3) Если API недоступен, есть fallback на results/specialities.json и results/<file>.json

Примечание:
Если нужен именно запуск на PHP-сервере, должен быть установлен php-cli.
