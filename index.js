/*
 * Spanish Bullion landing page script (plain JavaScript, no build step).
 *
 * - Signup form: 2 steps, the same field checks as the server, Cloudflare Turnstile captcha,
 *   hidden honeypot field, posts to api/submit-lead.php.
 * - After signup: a success message, then this same tab goes to the account (autologin).
 *   No new tab is opened.
 * - Live prices in the ticker, the quote cards and on the phone in the photo (api/prices.php).
 * - Pre-landing page (index.html): countdown, today's date, and buttons that show a short
 *   loader before opening the signup page (cadastro.html), keeping the query string.
 * - Meta Pixel (not on localhost).
 *
 * Both pages load this file; each part only runs when its elements are on the page.
 */
"use strict";

// ============================== Settings: edit these ==============================

const CONFIG = {
  /** Lead endpoint (keeps the CRM API key on the server). */
  LEAD_ENDPOINT: "api/submit-lead.php",
  /** Prices for the ticker; "" turns live prices off (the fallback values in index.html stay). */
  PRICES_ENDPOINT: "api/prices.php",
  /** Cloudflare Turnstile site key (public). On localhost Cloudflare's always-pass test key is always used. */
  TURNSTILE_SITE_KEY: "0x4AAAAAAF8nXtZBqKrFArs4",
  /** Meta Pixel ID; "" turns it off. Never loaded on localhost. */
  META_PIXEL_ID: "1141837911869784",
  /** How long the success message shows before the page goes to the account. */
  AUTOLOGIN_DELAY_MS: 1500,
  /** Signup page the pre-landing buttons open (the visitor's query string is added). */
  SIGNUP_PAGE: "cadastro",
  /** How long the loader shows after a pre-landing button click. */
  ROUTE_LOADER_MS: 2000,
};

// =================================================================================

const IS_LOCAL = ["localhost", "127.0.0.1", "", "[::1]"].includes(location.hostname);
const TEST_SITE_KEY = "1x00000000000000000000AA";
// The real site key only works on the live domain, so localhost always uses the test key
// (the server pairs it with the test secret when TURNSTILE_SECRET_KEY is empty in .env).
const SITE_KEY = IS_LOCAL ? TEST_SITE_KEY : CONFIG.TURNSTILE_SITE_KEY;
const CAPTCHA_ERROR = "Confirma la verificación de seguridad e inténtalo de nuevo.";
const GENERIC_ERROR = "No se ha podido enviar. Inténtalo de nuevo en unos instantes.";

// ------------------------------ Field checks ------------------------------
// Same rules (and messages) as api/submit-lead.php, so the form and the server agree.

const NAME_RE = /^[\p{L}][\p{L}\p{M}' .-]*$/u;

// Messages per field ("apellidos" is plural in Spanish).
const NAME_MSG = {
  nombre: {
    empty: "Introduce tu nombre", short: "Nombre demasiado corto", long: "Nombre demasiado largo (máx. 50 letras)",
    letters: "El nombre solo puede contener letras", invalid: "Nombre no válido",
  },
  apellidos: {
    empty: "Introduce tus apellidos", short: "Apellidos demasiado cortos", long: "Apellidos demasiado largos (máx. 50 letras)",
    letters: "Los apellidos solo pueden contener letras", invalid: "Apellidos no válidos",
  },
};

function checkName(raw, label) {
  const value = raw.trim().replace(/\s+/g, " ");
  const m = NAME_MSG[label];
  if (!value) return m.empty;
  if (value.length < 2) return m.short;
  if (value.length > 50) return m.long;
  if (!NAME_RE.test(value)) return m.letters;
  if (/(.)\1{3,}/iu.test(value)) return m.invalid;
  return "";
}

const EMAIL_RE = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
const DISPOSABLE = new Set([
  "mailinator.com", "guerrillamail.com", "10minutemail.com", "tempmail.com", "temp-mail.org",
  "yopmail.com", "trashmail.com", "sharklasers.com", "getnada.com", "throwawaymail.com",
  "maildrop.cc", "dispostable.com", "fakeinbox.com", "emailondeck.com", "mohmal.com",
]);
// Common e-mail domain typos (incl. Spanish providers) → the intended domain.
const TYPOS = {
  "gmial.com": "gmail.com", "gmai.com": "gmail.com", "gmail.con": "gmail.com",
  "gmail.co": "gmail.com", "gmail.es": "gmail.com", "gamil.com": "gmail.com",
  "gnail.com": "gmail.com", "gmaill.com": "gmail.com", "hotmal.com": "hotmail.com",
  "hotmial.com": "hotmail.com", "hotmail.con": "hotmail.com", "hotmai.com": "hotmail.com",
  "hotmail.co": "hotmail.com", "hotmil.com": "hotmail.com", "outlok.com": "outlook.com",
  "outlook.con": "outlook.com", "outllok.com": "outlook.com", "yahoo.con": "yahoo.com",
  "yaho.com": "yahoo.com", "yaho.es": "yahoo.es", "yahoo.e": "yahoo.es",
  "hotmial.es": "hotmail.es", "hotmal.es": "hotmail.es", "hotmail.e": "hotmail.es",
  "outlok.es": "outlook.es", "outlook.e": "outlook.es", "telefonica.com": "telefonica.net",
  "icloud.con": "icloud.com", "iclod.com": "icloud.com",
};

function checkEmail(raw) {
  const value = raw.trim().toLowerCase();
  if (!value) return "Introduce tu e-mail";
  if (value.length > 255 || !EMAIL_RE.test(value) || value.includes("..")) return "E-mail no válido";
  const at = value.lastIndexOf("@");
  const domain = value.slice(at + 1);
  if (TYPOS[domain]) return `¿Querías decir ${value.slice(0, at)}@${TYPOS[domain]}?`;
  if (DISPOSABLE.has(domain)) return "Usa un e-mail personal (no se aceptan e-mails temporales)";
  return "";
}

/** The 9 national digits, taken from whatever the visitor typed (drops 0034 / +34). */
function esNationalDigits(raw) {
  let d = raw.replace(/\D+/g, "").replace(/^0+/, "");
  if (d.startsWith("34") && d.length >= 11) d = d.slice(2);
  return d.slice(0, 9);
}

/** "612 34 56 78" style mask, applied while typing. */
function formatEsPhone(raw) {
  const d = esNationalDigits(raw);
  return [d.slice(0, 3), d.slice(3, 5), d.slice(5, 7), d.slice(7, 9)].filter(Boolean).join(" ");
}

/** Spanish mobiles start with 6, or with 71–74. */
function checkEsMobile(raw) {
  const d = esNationalDigits(raw);
  if (!d) return "Introduce tu número de móvil";
  if (d.length < 9) return "Número incompleto: debe tener 9 dígitos";
  if (!/^(6|7[1-4])/.test(d)) return "Introduce un móvil (empieza por 6 o 7)";
  if (/^(\d)\1{7}$/.test(d.slice(1))) return "Número de móvil no válido";
  return "";
}

const CHECKS = {
  firstName: (v) => checkName(v, "nombre"),
  lastName: (v) => checkName(v, "apellidos"),
  email: checkEmail,
  phone: checkEsMobile,
};
const STEP1_FIELDS = Object.keys(CHECKS);

// ------------------------------ Tracking ------------------------------

function loadMetaPixel() {
  if (!CONFIG.META_PIXEL_ID || IS_LOCAL) return;
  /* Meta's base code: loads fbevents.js and sends the first PageView. */
  !(function (f, b, e, v, n, t, s) {
    if (f.fbq) return;
    n = f.fbq = function () {
      n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
    };
    if (!f._fbq) f._fbq = n;
    n.push = n;
    n.loaded = !0;
    n.version = "2.0";
    n.queue = [];
    t = b.createElement(e);
    t.async = !0;
    t.src = v;
    s = b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t, s);
  })(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
  window.fbq("init", CONFIG.META_PIXEL_ID);
  window.fbq("track", "PageView");
}

/** Call only after the CRM has accepted the lead. */
function trackLead() {
  if (typeof window.fbq === "function") window.fbq("track", "Lead");
}

/** UTM tags and click ID from the page URL, sent with the lead. */
function readTracking() {
  const p = new URLSearchParams(location.search);
  const utm = {};
  p.forEach((v, k) => {
    if (k.startsWith("utm_") || k === "gclid" || k === "fbclid") utm[k] = v.slice(0, 200);
  });
  return { utm, clickId: (p.get("click_id") || p.get("clickid") || "").slice(0, 128) };
}

// ------------------------------ Captcha (Cloudflare Turnstile) ------------------------------

let turnstilePromise;
/** Loads Cloudflare's script once, only when a widget is first shown. */
function loadTurnstile() {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  turnstilePromise ??= new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    s.async = true;
    s.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error("turnstile missing")));
    s.onerror = () => {
      turnstilePromise = undefined;
      s.remove();
      reject(new Error("turnstile script failed to load"));
    };
    document.head.appendChild(s);
  });
  return turnstilePromise;
}

function fromTemplate(id) {
  return document.getElementById(id).content.firstElementChild.cloneNode(true);
}

// ------------------------------ Signup form ------------------------------

const CHOICE_BASE = "rounded-lg border px-3 py-2.5 text-left text-sm font-medium transition";
const CHOICE_ON = "border-accent bg-accent/15 text-foreground ring-2 ring-accent";
const CHOICE_OFF = "border-input bg-background hover:border-accent";

function setupLeadForm(root, tracking) {
  const $ = (sel) => root.querySelector(sel);
  const $$ = (sel) => root.querySelectorAll(sel);
  const field = (name) => $(`[data-field="${name}"]`);
  const answers = { experience: "", goal: "", deposit: "" };
  const shownAt = Date.now(); // sent as fillMs so the server can flag instant submits
  // Landing/field/click times for the leads log (form-timing.js; skipped if it didn't load).
  const timer = typeof createFormTimer === "function" ? createFormTimer() : null;
  timer?.formLoaded();
  let captchaToken = "";
  let widgetId;
  let loading = false;

  // --- errors ---
  function showFieldError(name, msg) {
    const p = $(`[data-error="${name}"]`);
    p.textContent = msg || "";
    p.hidden = !msg;
    const box = name === "phone" ? $("[data-phone-box]") : field(name);
    box.classList.toggle("border-destructive", !!msg);
    box.classList.toggle("border-input", !msg);
    field(name).setAttribute("aria-invalid", msg ? "true" : "false");
  }
  function showFormError(msg) {
    $$("[data-form-error]").forEach((p) => {
      p.textContent = msg || "";
      p.hidden = !msg;
    });
  }

  // --- steps ---
  function goToStep(step) {
    $$("[data-step]").forEach((el) => (el.hidden = el.dataset.step !== String(step)));
    $("[data-steps]").hidden = step === 3;
    if (step === 3) return;
    $("[data-step-number]").textContent = String(step);
    $("[data-step-title]").textContent = step === 1 ? "Abre tu cuenta gratis" : "Solo 3 preguntas más";
    $("[data-progress]").style.width = step === 1 ? "50%" : "100%";
    if (step === 2) renderCaptcha();
  }

  // --- step 1 fields ---
  for (const name of STEP1_FIELDS) {
    const input = field(name);
    input.addEventListener("focus", () => timer?.focus(name));
    input.addEventListener("input", () => {
      timer?.input(name);
      if (name === "phone") input.value = formatEsPhone(input.value);
      if (name === "email") input.value = input.value.replace(/\s/g, "");
      // While typing, only clear an error once the value becomes valid (no nagging mid-word).
      if (!$(`[data-error="${name}"]`).hidden && !CHECKS[name](input.value)) showFieldError(name, "");
    });
    input.addEventListener("blur", () => {
      timer?.blur(name);
      if (input.value) showFieldError(name, CHECKS[name](input.value));
    });
  }
  for (const name of ["over30", "consent"]) field(name).addEventListener("change", () => timer?.input(name));

  $("[data-next]").addEventListener("click", () => {
    timer?.continueClick();
    let firstBad;
    for (const name of STEP1_FIELDS) {
      const msg = CHECKS[name](field(name).value);
      showFieldError(name, msg);
      if (msg && !firstBad) firstBad = name;
    }
    if (firstBad) {
      field(firstBad).focus();
      return showFormError("");
    }
    if (!field("over30").checked) return showFormError("Solo disponible para mayores de 30 años");
    showFormError("");
    goToStep(2);
  });

  // --- step 2 questions ---
  $$("[data-choice]").forEach((group) => {
    const key = group.dataset.choice;
    const buttons = group.querySelectorAll("button");
    const paint = () =>
      buttons.forEach((b) => (b.className = `${CHOICE_BASE} ${answers[key] === b.dataset.value ? CHOICE_ON : CHOICE_OFF}`));
    buttons.forEach((b) =>
      b.addEventListener("click", () => {
        timer?.input(key);
        answers[key] = b.dataset.value;
        paint();
      }),
    );
    paint();
  });

  $("[data-back]").addEventListener("click", () => goToStep(1));

  // --- captcha ---
  function renderCaptcha() {
    if (widgetId !== undefined || !SITE_KEY) {
      if (!SITE_KEY) console.error("CONFIG.TURNSTILE_SITE_KEY is not set: leads will be rejected");
      return;
    }
    widgetId = null; // rendering
    const captchaFailed = () => ($("[data-captcha-failed]").hidden = false);
    loadTurnstile()
      .then((ts) => {
        widgetId = ts.render($("[data-captcha]"), {
          sitekey: SITE_KEY,
          action: "lead",
          language: "es",
          size: "flexible",
          callback: (token) => {
            captchaToken = token;
            $("[data-captcha-failed]").hidden = true;
          },
          "expired-callback": () => (captchaToken = ""),
          "error-callback": () => {
            captchaToken = "";
            captchaFailed();
          },
        });
      })
      .catch(() => {
        widgetId = undefined;
        captchaFailed();
      });
  }
  /** Tokens work once: get a new one after every failed submit. */
  function resetCaptcha() {
    captchaToken = "";
    if (widgetId && window.turnstile) window.turnstile.reset(widgetId);
  }

  // --- submit ---
  $("[data-submit]").addEventListener("click", async () => {
    if (loading) return;
    const timing = timer?.submitClick();
    const lead = {
      firstName: field("firstName").value.trim().replace(/\s+/g, " "),
      lastName: field("lastName").value.trim().replace(/\s+/g, " "),
      email: field("email").value.trim().toLowerCase(),
      phone: field("phone").value,
      over30: field("over30").checked,
      experience: answers.experience,
      goal: answers.goal,
      deposit: answers.deposit,
      consent: field("consent").checked,
      utm: tracking.utm,
      clickId: tracking.clickId || undefined,
      website: field("website").value || undefined,
      fillMs: Math.max(0, Date.now() - shownAt),
      captchaToken: captchaToken || undefined,
      timing,
    };

    // Same checks as the server, in form order.
    for (const name of STEP1_FIELDS) {
      const msg = CHECKS[name](field(name).value);
      if (msg) {
        goToStep(1);
        return showFieldError(name, msg);
      }
    }
    if (!lead.over30) {
      goToStep(1);
      return showFormError("Solo disponible para mayores de 30 años");
    }
    if (!lead.experience || !lead.goal || !lead.deposit) return showFormError("Responde a todas las preguntas");
    if (!lead.consent) return showFormError("Acepta las condiciones para continuar");
    if (!lead.captchaToken) return showFormError(CAPTCHA_ERROR);

    showFormError("");
    loading = true;
    const submitBtn = $("[data-submit]");
    submitBtn.disabled = true;
    submitBtn.textContent = "Enviando...";

    try {
      const res = await fetch(CONFIG.LEAD_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(lead),
        signal: AbortSignal.timeout(20000),
      });
      const body = await res.json().catch(() => ({}));

      if (res.ok && body.success === true) {
        // Honeypot leads reach the CRM flagged as bots; don't count them for Meta.
        if (!lead.website) trackLead();
        $("[data-first-name]").textContent = lead.firstName;
        goToStep(3);
        // Same tab: show the success message, then go to the account (when the CRM sent a link).
        const autologin = safeHttpsUrl(body.autologin_url);
        if (autologin) {
          $("[data-redirecting]").hidden = false;
          setTimeout(() => location.assign(autologin), CONFIG.AUTOLOGIN_DELAY_MS);
        }
        return;
      }

      resetCaptcha();
      if (typeof body.field === "string" && STEP1_FIELDS.includes(body.field)) {
        goToStep(1);
        showFieldError(body.field, typeof body.error === "string" ? body.error : GENERIC_ERROR);
      } else {
        showFormError(typeof body.error === "string" ? body.error : GENERIC_ERROR);
      }
    } catch (err) {
      console.error("lead submit failed", err);
      resetCaptcha();
      showFormError(GENERIC_ERROR);
    } finally {
      loading = false;
      submitBtn.disabled = false;
      submitBtn.textContent = "Quiero empezar ahora";
    }
  });

  goToStep(1);
}

/** Only follow absolute https URLs, so a bad response can't run `javascript:` etc. */
function safeHttpsUrl(value) {
  if (typeof value !== "string") return "";
  try {
    const url = new URL(value);
    return url.protocol === "https:" ? url.href : "";
  } catch {
    return "";
  }
}

// ------------------------------ Prices ------------------------------

const fmt = (n, d = 2) => n.toLocaleString("es-ES", { minimumFractionDigits: d, maximumFractionDigits: d });

const DECIMALS = { "NG=F": 3, "EURUSD=X": 4 };

async function loadPrices() {
  if (!CONFIG.PRICES_ENDPOINT) return;
  let quotes;
  try {
    const res = await fetch(CONFIG.PRICES_ENDPOINT, { signal: AbortSignal.timeout(10000) });
    quotes = await res.json();
  } catch {
    return; // keep the fallback values
  }
  if (!Array.isArray(quotes) || !quotes.length) return;

  const items = quotes.map((q) => {
    const span = document.createElement("span");
    span.className = "flex gap-2";
    const name = document.createElement("b");
    name.className = "text-deep-foreground/70";
    name.textContent = q.label;
    const change = document.createElement("span");
    change.className = q.changePct >= 0 ? "text-success" : "text-destructive";
    change.textContent = `${q.changePct >= 0 ? "+" : ""}${fmt(q.changePct)}%`;
    span.append(name, ` ${fmt(q.price, DECIMALS[q.symbol] ?? 2)} `, change);
    return span;
  });
  // Twice, so the marquee loops without a gap.
  document.querySelector("[data-ticker]")?.replaceChildren(...items, ...items.map((n) => n.cloneNode(true)));

  // Quote cards on the pre-landing page.
  for (const q of quotes) {
    document.querySelectorAll(`[data-quote-price="${q.symbol}"]`).forEach((el) => {
      el.textContent = `${fmt(q.price, DECIMALS[q.symbol] ?? 2)} $`;
    });
    document.querySelectorAll(`[data-quote-change="${q.symbol}"]`).forEach((el) => {
      el.textContent = `${q.changePct >= 0 ? "+" : ""}${fmt(q.changePct)} % hoy`;
      el.classList.toggle("text-success", q.changePct >= 0);
      el.classList.toggle("text-destructive", q.changePct < 0);
    });
  }
  const eurUsd = quotes.find((q) => q.symbol === "EURUSD=X");
  if (eurUsd?.price > 0) {
    document.querySelectorAll("[data-eur-250]").forEach((el) => (el.textContent = fmt(250 / eurUsd.price, 0)));
  }

  const gold = quotes.find((q) => q.symbol === "GC=F");
  if (!gold) return;
  const up = gold.change >= 0;
  document.querySelectorAll("[data-gold-price]").forEach((t) => {
    t.textContent = `$${gold.price.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  });
  document.querySelectorAll("[data-gold-change]").forEach((t) => {
    t.textContent = `${up ? "▲" : "▼"} ${fmt(Math.abs(gold.change))} (${up ? "+" : "-"}${fmt(Math.abs(gold.changePct))}%)`;
    t.setAttribute("class", up ? "fill-success" : "fill-destructive");
  });
  document.querySelectorAll("[data-gold-round]").forEach((el) => (el.textContent = fmt(gold.price, 0)));
}

// ------------------------------ Pre-landing page ------------------------------

/** 15-minute countdown in the top alert bar. */
function startCountdown(el) {
  let s = 15 * 60;
  const show = () => (el.textContent = `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`);
  show();
  const id = setInterval(() => {
    s = Math.max(0, s - 1);
    show();
    if (!s) clearInterval(id);
  }, 1000);
}

function setupPrelander() {
  const today = new Date().toLocaleDateString("es-ES", { day: "numeric", month: "long", year: "numeric" });
  document.querySelectorAll("[data-today]").forEach((el) => (el.textContent = today));
  document.querySelectorAll("[data-countdown]").forEach(startCountdown);

  const ctas = document.querySelectorAll("a[data-cta]");
  if (!ctas.length) return;
  const target = CONFIG.SIGNUP_PAGE + location.search; // keeps utm_* and click_id
  const loader = document.querySelector("[data-route-loader]");
  const bar = document.querySelector("[data-route-loader-bar]");
  let leaving = false;

  ctas.forEach((a) => {
    a.href = target;
    a.addEventListener("click", (e) => {
      // Ctrl/Cmd/Shift/middle clicks keep the browser's normal "open in new tab" behaviour.
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !loader) return;
      e.preventDefault();
      if (leaving) return;
      leaving = true;
      loader.hidden = false;
      if (bar) {
        bar.style.transition = `width ${CONFIG.ROUTE_LOADER_MS}ms ease-out`;
        requestAnimationFrame(() => requestAnimationFrame(() => (bar.style.width = "100%")));
      }
      setTimeout(() => location.assign(target), CONFIG.ROUTE_LOADER_MS);
    });
  });

  // Coming back with the Back button can restore the page with the loader still showing.
  window.addEventListener("pageshow", (e) => {
    if (!e.persisted || !loader) return;
    leaving = false;
    loader.hidden = true;
    if (bar) {
      bar.style.transition = "none";
      bar.style.width = "0%";
    }
  });
}

// ------------------------------ Page setup ------------------------------

/** Scroll to the first form without putting "#registro" in the address bar. */
function scrollToForm() {
  const target = document.getElementById("registro");
  target?.scrollIntoView({ behavior: "smooth", block: "start" });
  target?.querySelector("input")?.focus({ preventScroll: true });
}

const STAR =
  '<svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>';

function init() {
  loadMetaPixel();
  const tracking = readTracking();

  document.querySelectorAll("[data-stars]").forEach((el) => (el.innerHTML = STAR.repeat(5)));
  document.querySelectorAll("[data-year]").forEach((el) => (el.textContent = String(new Date().getFullYear())));
  document.querySelectorAll("[data-scroll-to-form]").forEach((b) => b.addEventListener("click", scrollToForm));

  document.querySelectorAll("[data-lead-slot]").forEach((slot) => {
    const form = fromTemplate("lead-form-template");
    slot.replaceChildren(form);
    setupLeadForm(form, tracking);
  });
  setupPrelander();

  loadPrices();
  setInterval(loadPrices, 60000);
}

init();
