const form = document.querySelector("#commentForm");
const passcodeInput = document.querySelector("#passcode");
const commentOutput = document.querySelector("#commentOutput");
const checklistOutput = document.querySelector("#checklistOutput");
const revisionNotes = document.querySelector("#revisionNotes");
const modelLabel = document.querySelector("#modelLabel");
const statusPill = document.querySelector("#statusPill");
const copyButton = document.querySelector("#copyComment");
const downloadButton = document.querySelector("#downloadComment");
const saveDraftButton = document.querySelector("#saveDraft");
const clearDraftButton = document.querySelector("#clearDraft");
const loadingTemplate = document.querySelector("#loadingTemplate");

const draftKey = "comment-compass-draft-v1";
const passcodeKey = "comment-compass-passcode-v1";
let currentComment = "";

const checklistLabels = {
  formal_genre: "Formal report style",
  positive_balance: "Positive balance",
  evidence_used: "Specific evidence",
  learner_profile_used: "Learner profile included",
  approaches_to_learning_used: "Approaches to learning included",
  improvement_goals_count: "One or two positive goals",
  name_rule_applied: "Name rule applied",
  capitalization_reviewed: "Capitalization reviewed",
  parent_friendly: "Parent-friendly wording",
  final_encouragement: "Final encouragement"
};

function formToData() {
  const data = new FormData(form);
  return {
    passcode: passcodeInput.value.trim(),
    chineseName: data.get("chineseName").trim(),
    englishName: data.get("englishName").trim(),
    grade: data.get("grade"),
    period: data.get("period"),
    pronouns: data.get("pronouns"),
    strengthEvidence: data.get("strengthEvidence").trim(),
    englishEvidence: data.get("englishEvidence").trim(),
    chineseEvidence: data.get("chineseEvidence").trim(),
    mathEvidence: data.get("mathEvidence").trim(),
    uoiEvidence: data.get("uoiEvidence").trim(),
    p4cEvidence: data.get("p4cEvidence").trim(),
    eventEvidence: data.get("eventEvidence").trim(),
    learnerProfile: data.getAll("learnerProfile"),
    learnerProfileEvidence: data.get("learnerProfileEvidence").trim(),
    atlSkills: data.getAll("atlSkills"),
    atlEvidence: data.get("atlEvidence").trim(),
    goalOne: data.get("goalOne").trim(),
    goalTwo: data.get("goalTwo").trim(),
    support: data.get("support").trim(),
    sentenceTarget: Number(data.get("sentenceTarget")),
    encouragement: data.get("encouragement").trim()
  };
}

function applyDataToForm(data) {
  Object.entries(data).forEach(([key, value]) => {
    if (key === "passcode") {
      return;
    }

    const field = form.elements[key];
    if (!field) {
      return;
    }

    if (field instanceof RadioNodeList) {
      const inputs = Array.from(field);

      if (inputs.some((input) => input.type === "checkbox")) {
        const values = Array.isArray(value) ? value : [];
        inputs.forEach((input) => {
          input.checked = values.includes(input.value);
        });
      } else {
        const selected = inputs.find((input) => input.value === value);
        if (selected) {
          selected.checked = true;
        }
      }
      return;
    }

    if (field instanceof HTMLSelectElement && field.multiple && Array.isArray(value)) {
      Array.from(field.options).forEach((option) => {
        option.selected = value.includes(option.value);
      });
      return;
    }

    field.value = value ?? "";
  });
}

function validateData(data) {
  const missing = [];

  if (!data.chineseName && !data.englishName) {
    missing.push("a Chinese name or English given name");
  }

  [
    ["grade", "Grade"],
    ["strengthEvidence", "strongest learning praise"],
    ["learnerProfileEvidence", "learner profile evidence"],
    ["atlEvidence", "approaches to learning evidence"],
    ["goalOne", "most important goal"]
  ].forEach(([key, label]) => {
    if (!data[key] || (Array.isArray(data[key]) && data[key].length === 0)) {
      missing.push(label);
    }
  });

  if (!data.learnerProfile.length) {
    missing.push("at least one learner profile attribute");
  }

  if (!data.atlSkills.length) {
    missing.push("at least one approach to learning");
  }

  if (!Number.isInteger(data.sentenceTarget) || data.sentenceTarget < 5 || data.sentenceTarget > 30) {
    missing.push("a target length from 5 to 30 sentences");
  }

  return missing;
}

function setLoading(isLoading) {
  const submitButton = form.querySelector("button[type='submit']");
  submitButton.disabled = isLoading;
  submitButton.innerHTML = isLoading
    ? loadingTemplate.innerHTML
    : `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v3M12 18v3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M3 12h3M18 12h3M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"></path></svg>Generate comment`;
}

function renderResult(payload) {
  const result = payload.result;
  currentComment = result.comment;
  commentOutput.innerHTML = "";
  const paragraph = document.createElement("p");
  paragraph.textContent = result.comment;
  commentOutput.append(paragraph);
  modelLabel.textContent = payload.model ? `Generated with ${payload.model}` : "Generated";
  statusPill.textContent = "Draft ready";
  renderChecklist(result.checklist, result.local_audit);
  renderNotes(result.revision_notes || []);
}

function renderChecklist(checklist, localAudit) {
  checklistOutput.innerHTML = "";
  const entries = Object.entries(checklistLabels);

  entries.forEach(([key, label]) => {
    const item = document.createElement("li");
    const value = checklist?.[key];
    item.className = value === true ? "pass" : value === false ? "fail" : "warn";

    if (key === "improvement_goals_count") {
      item.textContent = `${label}: ${value}`;
    } else {
      item.textContent = label;
    }

    checklistOutput.append(item);
  });

  if (localAudit) {
    Object.entries(localAudit).forEach(([key, value]) => {
      const item = document.createElement("li");
      item.className = value.pass ? "pass" : "warn";
      item.textContent = value.label;
      checklistOutput.append(item);
    });
  }
}

function renderNotes(notes) {
  const list = revisionNotes.querySelector("ul");
  list.innerHTML = "";

  if (!notes.length) {
    revisionNotes.hidden = true;
    return;
  }

  notes.forEach((note) => {
    const item = document.createElement("li");
    item.textContent = note;
    list.append(item);
  });
  revisionNotes.hidden = false;
}

function showToast(message, type = "success") {
  const toast = document.createElement("div");
  toast.className = `toast ${type === "error" ? "error" : ""}`;
  toast.textContent = message;
  document.body.append(toast);
  window.setTimeout(() => toast.remove(), 3400);
}

async function generateComment(data) {
  const response = await fetch("api/generate.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify(data)
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.error || "The comment could not be generated.");
  }

  return payload;
}

function saveDraft() {
  const data = formToData();
  const { passcode, ...draft } = data;
  localStorage.setItem(draftKey, JSON.stringify(draft));
  localStorage.setItem(passcodeKey, passcode);
  showToast("Draft saved");
}

function loadDraft() {
  const saved = localStorage.getItem(draftKey);
  const savedPasscode = localStorage.getItem(passcodeKey);

  if (savedPasscode) {
    passcodeInput.value = savedPasscode;
  }

  if (!saved) {
    return;
  }

  try {
    applyDataToForm(JSON.parse(saved));
  } catch {
    localStorage.removeItem(draftKey);
  }
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const data = formToData();
  const missing = validateData(data);

  if (missing.length) {
    showToast(`Add ${missing.join(", ")}.`, "error");
    return;
  }

  setLoading(true);
  modelLabel.textContent = "Generating";
  statusPill.textContent = "Working";

  try {
    const payload = await generateComment(data);
    renderResult(payload);
    saveDraft();
  } catch (error) {
    showToast(error.message, "error");
    modelLabel.textContent = "Needs attention";
    statusPill.textContent = "Error";
  } finally {
    setLoading(false);
  }
});

saveDraftButton.addEventListener("click", saveDraft);

document.querySelectorAll("[data-sentence-preset]").forEach((button) => {
  button.addEventListener("click", () => {
    form.elements.sentenceTarget.value = button.dataset.sentencePreset;
    form.elements.sentenceTarget.focus();
  });
});

clearDraftButton.addEventListener("click", () => {
  localStorage.removeItem(draftKey);
  localStorage.removeItem(passcodeKey);
  form.reset();
  passcodeInput.value = "";
  currentComment = "";
  commentOutput.innerHTML = `<p class="empty-state">Generated comments will appear here after the essential student evidence has been entered.</p>`;
  checklistOutput.innerHTML = `
    <li>Formal report style</li>
    <li>Positive balance</li>
    <li>Specific evidence</li>
    <li>One or two positive goals</li>
    <li>Correct names and capitalization</li>
  `;
  revisionNotes.hidden = true;
  modelLabel.textContent = "Ready";
  statusPill.textContent = "No draft";
  showToast("Draft cleared");
});

copyButton.addEventListener("click", async () => {
  if (!currentComment) {
    showToast("Generate a comment first.", "error");
    return;
  }

  await navigator.clipboard.writeText(currentComment);
  showToast("Comment copied");
});

downloadButton.addEventListener("click", () => {
  if (!currentComment) {
    showToast("Generate a comment first.", "error");
    return;
  }

  const data = formToData();
  const name = data.chineseName || data.englishName || "student";
  const filename = `${name.replace(/[^\p{L}\p{N}]+/gu, "-")}-report-comment.txt`;
  const blob = new Blob([currentComment], { type: "text/plain;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
});

loadDraft();
