/**
 * Project Delivery Lab — приймач заявок у Google-таблицю.
 *
 * Цей файл НЕ завантажується на хостинг. Його вміст вставляють у редактор
 * Apps Script усередині самої таблиці (Розширення → Apps Script) і публікують
 * як веб-застосунок. Покрокова інструкція — у GOOGLE_SHEET_SETUP.md.
 *
 * Після публікації треба вписати отриманий /exec-URL і той самий SECRET
 * у config.php на сервері (поля sheet_url і sheet_secret).
 */

// Той самий рядок, що і 'sheet_secret' у config.php. Замінити на свій.
// Без нього будь-хто, хто вгадає URL, зможе писати рядки в таблицю.
const SECRET = 'PASTE_THE_SAME_SECRET_AS_IN_CONFIG_PHP';

// Аркуш, у який складати заявки. Створиться сам, якщо його ще немає.
const SHEET_NAME = 'Заявки';

const HEADERS = [
  'Дата', 'Імʼя', 'Email', 'Контакт',
  'Досвід', 'Досвід у PM', 'Ціль', 'Звідки', 'Сторінка',
];

function doPost(e) {
  try {
    const p = (e && e.parameter) || {};

    if (!secretOk(p.secret)) {
      return json({ ok: false, error: 'forbidden' });
    }

    // Паралельні заявки інакше можуть записатися в один і той самий рядок.
    const lock = LockService.getScriptLock();
    lock.waitLock(20000);
    try {
      sheet().appendRow([
        new Date(),
        str(p.name), str(p.email), str(p.phone),
        str(p.experience), str(p.pm_years), str(p.goal),
        str(p.source), str(p.page),
      ]);
    } finally {
      lock.releaseLock();
    }

    return json({ ok: true });
  } catch (err) {
    // PHP дивиться саме на тіло: Apps Script віддає 200 навіть на помилку.
    return json({ ok: false, error: String(err) });
  }
}

/** GET з правильним секретом — перевірка, що публікація вдалася. */
function doGet(e) {
  const p = (e && e.parameter) || {};
  if (!secretOk(p.secret)) {
    return json({ ok: false, error: 'forbidden' });
  }
  return json({ ok: true, sheet: SHEET_NAME, rows: sheet().getLastRow() });
}

function sheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sh = ss.getSheetByName(SHEET_NAME);

  if (!sh) {
    sh = ss.insertSheet(SHEET_NAME);
  }
  if (sh.getLastRow() === 0) {
    sh.appendRow(HEADERS);
    sh.getRange(1, 1, 1, HEADERS.length).setFontWeight('bold');
    sh.setFrozenRows(1);
    sh.getRange('A:A').setNumberFormat('dd.MM.yyyy HH:mm');
  }
  return sh;
}

/**
 * Порівняння за сталий час. Звичайне === завершується на першому розбіжному
 * символі, і за часом відповіді секрет можна добирати посимвольно.
 */
function secretOk(given) {
  const a = String(given == null ? '' : given);
  const b = SECRET;
  if (a.length !== b.length) return false;

  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}

/** Обрізаємо довгі значення: у клітинку Google пускає не більше 50 000 символів. */
function str(v) {
  return String(v == null ? '' : v).slice(0, 500);
}

function json(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
