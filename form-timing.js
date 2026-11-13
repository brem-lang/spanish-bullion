/*
 * Form timing for the leads log (plain JavaScript, loaded before index.js on cadastro.html).
 *
 * Records when the visitor landed, how they filled each form field, and when they clicked.
 * Sent with the lead as `timing`; api/submit-lead.php forwards it only to the leads log
 * (never to the CRM) and api/lib/lead-log.php turns it into readable text.
 * Every *_ms value is milliseconds since the visitor entered the page (performance.now()).
 *
 * Adapted from the brasil-bullion app's src/lib/form-timing.ts.
 */
"use strict";

/** The tracked fields (same names as the lead, see TIMED_FIELDS in api/lib/lead-log.php). */
const TIMED_FIELDS = ["firstName", "lastName", "email", "phone", "over30", "experience", "goal", "deposit", "consent"];

/** `now` is only replaced by the tests. */
function createFormTimer(now = () => Math.round(performance.now())) {
  const fields = {};
  const focusedSince = {};
  let formLoaded;
  let firstContinue;
  let lastContinue;
  let firstSubmit;
  let submitClicks = 0;
  const field = (k) => (fields[k] ??= {});
  const known = (k) => TIMED_FIELDS.includes(k);

  return {
    formLoaded() {
      formLoaded ??= now();
    },
    /** Text fields: got focus. */
    focus(k) {
      if (!known(k)) return;
      const t = now();
      field(k).first_focus_ms ??= t;
      focusedSince[k] = t;
    },
    /** Text fields: lost focus. */
    blur(k) {
      if (!known(k)) return;
      const t = now();
      const f = field(k);
      f.last_blur_ms = t;
      if (focusedSince[k] !== undefined) f.focus_total_ms = (f.focus_total_ms ?? 0) + (t - focusedSince[k]);
      delete focusedSince[k];
    },
    /** Any field: the value changed (typing, choosing an option, ticking a box). */
    input(k) {
      if (!known(k)) return;
      const t = now();
      const f = field(k);
      f.first_input_ms ??= t;
      f.last_input_ms = t;
      f.input_count = (f.input_count ?? 0) + 1;
    },
    /** "Continuar" (step 1 → 2). */
    continueClick() {
      lastContinue = now();
      firstContinue ??= lastContinue;
    },
    /** Call on each "Quiero empezar ahora" click; returns the timing to send with the lead. */
    submitClick() {
      const t = now();
      firstSubmit ??= t;
      submitClicks += 1;
      let timezone;
      try {
        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      } catch {
        // old browser
      }
      return {
        landed_at: new Date(performance.timeOrigin).toISOString(),
        submitted_at: new Date().toISOString(),
        timezone,
        utc_offset_min: -new Date().getTimezoneOffset(),
        form_loaded_ms: formLoaded,
        first_continue_click_ms: firstContinue,
        continue_click_ms: lastContinue,
        first_submit_click_ms: firstSubmit,
        submit_click_ms: t,
        submit_clicks: submitClicks,
        form_fill_ms: formLoaded !== undefined ? t - formLoaded : undefined,
        fields: JSON.parse(JSON.stringify(fields)),
      };
    },
  };
}

if (typeof module !== "undefined") module.exports = { TIMED_FIELDS, createFormTimer };
