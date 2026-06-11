import { readFileSync } from "node:fs";
import assert from "node:assert/strict";

const api = readFileSync(new URL("../api/generate.php", import.meta.url), "utf8");
const html = readFileSync(new URL("../index.html", import.meta.url), "utf8");

[
  "Use a formal report-comment genre with passive voice",
  "Keep each sentence concise",
  "Positive achievement comments must outweigh improvement comments",
  "Include one or two areas for improvement only",
  "If a Chinese name is supplied",
  "Do not use abbreviations",
  "Write maths or mathematics in lowercase",
  "Avoid unnecessary information",
  "Use clear parent-friendly language",
  "Approved comment style to follow",
  "start the second with \"Another goal...\""
].forEach((rule) => {
  assert.match(api, new RegExp(rule.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")), `Missing prompt rule: ${rule}`);
});

[
  "Chinese name",
  "English given name",
  "Grade",
  "Strongest learning praise",
  "Learner profile attributes",
  "Approaches to learning",
  "Most important goal",
  "max=\"30\""
].forEach((label) => {
  assert.match(html, new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")), `Missing essential field: ${label}`);
});

assert.match(api, /gpt-5\.4-mini/, "Default model should be documented in the API file.");
assert.match(api, /APP_PASSCODE/, "Endpoint should support an access code.");

console.log("Rule and field checks passed.");
