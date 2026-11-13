// node --test tests/form-timing.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";

const { createFormTimer, TIMED_FIELDS } = createRequire(import.meta.url)("../form-timing.js");

test("records per-field focus, input and click times", () => {
  let t = 0;
  const timer = createFormTimer(() => t);
  t = 1_000;
  timer.formLoaded();
  t = 2_000;
  timer.focus("email");
  t = 2_500;
  timer.input("email");
  t = 3_000;
  timer.input("email");
  t = 4_000;
  timer.blur("email");
  t = 6_000;
  timer.focus("email");
  t = 7_000;
  timer.blur("email");
  t = 8_000;
  timer.continueClick();
  t = 8_500;
  timer.continueClick();
  t = 9_000;
  timer.input("goal");
  timer.input("website"); // honeypot: not tracked
  t = 10_000;
  timer.submitClick();
  t = 12_000;
  const timing = timer.submitClick();

  assert.match(timing.landed_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/);
  assert.match(timing.submitted_at, /^\d{4}-\d{2}-\d{2}T/);
  assert.equal(typeof timing.utc_offset_min, "number");
  assert.equal(timing.form_loaded_ms, 1_000);
  assert.equal(timing.first_continue_click_ms, 8_000);
  assert.equal(timing.continue_click_ms, 8_500);
  assert.equal(timing.first_submit_click_ms, 10_000);
  assert.equal(timing.submit_click_ms, 12_000);
  assert.equal(timing.submit_clicks, 2);
  assert.equal(timing.form_fill_ms, 11_000);
  assert.deepEqual(timing.fields.email, {
    first_focus_ms: 2_000,
    last_blur_ms: 7_000,
    focus_total_ms: 3_000,
    first_input_ms: 2_500,
    last_input_ms: 3_000,
    input_count: 2,
  });
  assert.deepEqual(timing.fields.goal, { first_input_ms: 9_000, last_input_ms: 9_000, input_count: 1 });
  assert.deepEqual(Object.keys(timing.fields).sort(), ["email", "goal"]);
});

test("returns a copy, so later events don't change a sent timing", () => {
  let t = 100;
  const timer = createFormTimer(() => t);
  timer.input("phone");
  const first = timer.submitClick();
  t = 200;
  timer.input("phone");
  assert.equal(first.fields.phone.input_count, 1);
});

test("tracks exactly this form's fields", () => {
  assert.deepEqual(TIMED_FIELDS, [
    "firstName", "lastName", "email", "phone", "over30", "experience", "goal", "deposit", "consent",
  ]);
});
