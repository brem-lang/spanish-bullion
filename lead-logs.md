Add the "leads log" feature to this landing page.

Reference implementation (read it first and copy its approach):
`C:\Users\Admin\Documents\Works\k1 landing page\brasil-bullion-2`, commit `0df7d1f`
(`git -C "../brasil-bullion-2" show 0df7d1f`). Relevant files there:

- src/lib/form-timing.ts – browser timer + zod schema for the timing data
- src/lib/form-timing-report.ts – turns raw timing into readable text for the log
- src/lib/lead-proxy.server.ts – sendLeadLog() and where it is called
- src/lib/lead.ts – `timing` field on the lead schema
- src/components/landing/LeadForm.tsx – the 6 timer hooks
- src/test/form-timing\*.test.ts, src/test/lead-proxy.test.ts – tests

This project may differ (other fields, language, form steps, extra features such as a bonus
tab). Adapt to it; don't overwrite unrelated code. If a file applies cleanly, a patch is fine;
otherwise make the edits by hand.

## What to build

1. Server-side POST to the leads log, only after the CRM accepted the lead
   (the same place the success response is returned). Never on CRM rejection, duplicate (409),
   timeout or network error. Not awaited (`void sendLeadLog(...)`), never throws, 10s timeout,
   so it can't delay or break the visitor's response. Skip it if the env vars are missing.

   ```
   POST https://alphamastercrm.online/api/leads-logs
   Authorization: Bearer <LEADS_LOG_API_KEY>
   Content-Type: application/json
   Accept: application/json

   {
     "email": <payload.email>,
     "offer_name": <payload.offer_name>,
     "ip_address": <payload.ip_address>,
     "data": { ...the exact payload sent to the CRM, "timing": <readable timing report> }
   }
   ```

   The token must stay server-side (never a VITE\_\* var, never in the browser bundle).

2. Env vars (server only): `LEADS_LOG_ENDPOINT` (default the URL above) and `LEADS_LOG_API_KEY`.
   Add both to `.env.example` with an empty key. Add them to `.env` (check it is git-ignored
   first) with key: <PASTE KEY HERE>

3. Browser timing, sent with the lead as `timing` and forwarded only to the log (not the CRM):
   - landed_at (ISO, from performance.timeOrigin), submitted_at, timezone, utc_offset_min
   - form_loaded_ms, first/last "continue" (next step) click, first/last submit click,
     submit_clicks, form_fill_ms
   - per field: first_focus_ms, last_blur_ms, focus_total_ms (text inputs);
     first_input_ms, last_input_ms, input_count (all fields incl. choices/checkboxes)
   - all \*\_ms are ms since page entry (performance.now())
   - in the lead schema: `timing: timingSchema.optional().catch(undefined)` so bad timing data
     is dropped and never rejects a lead
   - field list and labels must match THIS form's fields (not the honeypot)

4. Readable report in the log (server-side, from the raw timing): summary sentence,
   landed_at/submitted_at in the visitor's local time with time zone, durations as "1.2s" /
   "1m 05s", a sorted `steps` list ("1.1s - Started First name", 'Clicked "Continuar"' ...)
   and one sentence per field ("4.8s in the field, 14 changes, typed"; one change on a text
   field = "filled in one go (autofill or paste)"; untouched = "not touched").
   Use the field labels and button texts of this page.

5. Tests: log sent on CRM success with the right headers/body; not sent on CRM rejection or
   network error; timing goes to the log but not the CRM payload; malformed timing doesn't
   reject the lead; timer unit test; report test.

## Finish

Run tests, typecheck and lint (ignore line-ending-only `␍` lint errors if they were there
before). Don't commit. Tell me: what changed, test results, and the `offer_name` this page
sends (so I can tell the pages apart in the logs). Remind me to add LEADS_LOG_API_KEY to the
production `.env` on the VPS and restart the service.
