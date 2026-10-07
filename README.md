# Место — web-service

Symfony 7.4 + Twig. Структура Kernel, config, src, public и bin основана на verification-service; его бизнес-логика, база и секреты не копируются.

Главная `/` и страницы `/*.html` отрисовываются Symfony через Twig. CSS/JS отдаёт Nginx. Сценарии прототипа пока используют демонстрационные данные и localStorage; настоящая авторизация, микросервисные API и ИИ не подключены.

Из ONMI_infra: `make web-up`, затем http://localhost:8080. Остановка: `make web-down`.

Первый запуск устанавливает зависимости Composer; composer.lock фиксирует версии. Локальный запуск поднимает только web-service и Nginx, без базы/Kafka. Основной `make up` использует web-service вместо Next.js и существующий NGINX_PORT.

Для production задайте APP_SECRET, APP_ENV=prod, APP_DEBUG=0 и HTTPS. Текущая отдельная сборка предназначена для локальной разработки.
