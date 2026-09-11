# Project Delivery Lab — лендінг

Односторінковий сайт практичного курсу IT Project Management (українською).
Маркетинг + збір заявок.

## Стек

Статичний `index.html` — увесь CSS і JS всередині файлу, без збірки та залежностей.

- **Шрифти:** Oswald (заголовки) + Geist Mono (текст), Google Fonts
- **Палітра:** брендова, у CSS-змінних на початку `<style>`
- **Анімації:** тільки `transform` / `opacity`, з підтримкою `prefers-reduced-motion`

## Структура

```
index.html          — уся сторінка
send-form.php       — приймає заявку й шле в Telegram
config.example.php  — зразок конфігурації; справжній config.php живе на сервері
.htaccess           — закриває config.php, стиснення й кеш
robots.txt          — індексація
sitemap.xml         — карта сайту
site.webmanifest    — PWA-маніфест та іконки
assets/             — логотипи, фавікони, og-image
assets/img/         — фотографії
brand/              — вихідні брендові файли
serve.mjs           — локальний сервер (тільки статика, без PHP)
screenshot.mjs      — знімок сторінки через puppeteer
```

## SEO

- `<title>`, `description`, `canonical`, `robots`
- Open Graph + Twitter Card, картинка `assets/og-image.jpg` (1200×630, зібрана
  з логотипа й брендових кольорів)
- Фавікони 16/32/180/192/512 + maskable, згенеровані з фірмового знака
- JSON-LD: `EducationalOrganization`, `Person` (автор), `WebSite`,
  `Course` (з переліком тарифів і цін), `FAQPage` (7 питань — може дати
  розгорнутий сніпет у Google)
- Ієрархія заголовків без пропусків: один `h1`, далі `h2` → `h3`
- Орієнтири `header` / `nav` / `main` / `footer`, `aria-label` на секціях
- Усі зображення мають `alt`, `width`/`height` (проти стрибків верстки),
  `loading="lazy"` нижче першого екрана; `hero.jpg` — `preload` + `fetchpriority`

## Локальний запуск

```bash
node serve.mjs                  # http://localhost:3000
PORT=3210 node serve.mjs        # якщо 3000 зайнятий
```

Знімок сторінки (потребує puppeteer):

```bash
node screenshot.mjs http://localhost:3000 label
DSF=1 node screenshot.mjs http://localhost:3000    # для дуже довгих сторінок
```

> `DSF=1` обов'язковий для повносторінкових знімків: сторінка вища за 16384px,
> і при масштабі 1.5 Chrome псує знімок (хвіст зображення повторює попередній вміст).

## Секції

Навігація → Hero → Інструменти → Знайомі ситуації → Що ви отримаєте →
Програма (список модулів) → Тарифи → Про автора → Кейси учасників → FAQ →
Форма заявки → Футер

## Заявки

Форма шле дані на `send-form.php`, який пересилає їх у Telegram.
Токен бота лежить у `config.php` — **тільки на сервері**, у git його немає.

Повна інструкція: **[TELEGRAM_SETUP.md](TELEGRAM_SETUP.md)**.

Коротко для деплою на Hostinger:

1. Залити в `public_html`: `index.html`, `send-form.php`, `.htaccess`,
   `robots.txt`, `sitemap.xml`, `site.webmanifest`, теку `assets/`.
2. Створити там же `config.php` за зразком `config.example.php`
   і вписати `bot_token` та `chat_id`.
3. Перевірити `https://projectdeliverylab.com/send-form.php?selftest=1`.

Потрібен PHP 7.4+ (краще 8.x). `node serve.mjs` PHP не виконує — форму
локально перевіряють через `php:8.2-cli` у Docker, див. інструкцію.

## Що треба доробити перед запуском

- [ ] **Створити `config.php` на сервері** — без нього форма віддає
      «Форма тимчасово не працює». Див. TELEGRAM_SETUP.md.
- [ ] **Кейси учасників — placeholder.** Тексти й аватари вигадані.
      Замінити на реальні після першого потоку.
- [ ] **Контакти у футері** — email, телефон, Telegram, Instagram, TikTok
      позначені «уточнюється».
- [ ] **Промо-відео.** Кнопка play відкриває модалку-заглушку; підставити YouTube/Vimeo ID.
- [ ] **Наповнення тарифів** підтвердити з автором курсу.
