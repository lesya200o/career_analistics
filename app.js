const state = {
  specialities: [],
  detailCache: new Map(),
  currentSpecialityId: null,
};

const mainView = document.getElementById("mainView");
const detailView = document.getElementById("detailView");
const searchInput = document.getElementById("searchInput");
const suggestions = document.getElementById("suggestions");
const specialitiesGrid = document.getElementById("specialitiesGrid");
const statusBox = document.getElementById("statusBox");
const specialitiesCount = document.getElementById("specialitiesCount");
const vacanciesCount = document.getElementById("vacanciesCount");
const themeToggle = document.getElementById("themeToggle");
const mirrorThemeButton = document.querySelector("[data-theme-copy]");

const detailCrumb = document.getElementById("detailCrumb");
const detailTitle = document.getElementById("detailTitle");
const detailSubtitle = document.getElementById("detailSubtitle");
const backButton = document.getElementById("backButton");
const similarButton = document.getElementById("similarButton");
const copySkillsButton = document.getElementById("copySkillsButton");
const totalMetric = document.getElementById("totalMetric");
const avgSalaryMetric = document.getElementById("avgSalaryMetric");
const avgExpMetric = document.getElementById("avgExpMetric");
const salaryRangeMetric = document.getElementById("salaryRangeMetric");
const professionalSkills = document.getElementById("professionalSkills");
const personalSkills = document.getElementById("personalSkills");

init();

async function init() {
  setupTheme();
  bindEvents();

  try {
    setStatus("Загрузка данных...");
    state.specialities = await fetchSpecialities();
    if (!state.specialities.length) throw new Error("Список специальностей пуст");

    await renderMainList(state.specialities);
    setStatus(`Готово. Специальностей: ${state.specialities.length}`);
    route();
  } catch (error) {
    setStatus(`Ошибка: ${error.message}`);
  }
}

async function fetchSpecialities() {
  const endpoints = ["/api/specialities", "/api/specialities.php", "./results/specialities.json"];
  let lastError = null;

  for (const endpoint of endpoints) {
    try {
      const response = await fetch(endpoint, { headers: { Accept: "application/json" } });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      const payload = await response.json();
      const list = Array.isArray(payload) ? payload : payload.specialities;
      if (!Array.isArray(list)) {
        throw new Error("Некорректный формат ответа");
      }
      return list;
    } catch (error) {
      lastError = error;
    }
  }

  throw new Error(`Не удалось загрузить специальности: ${lastError?.message || "unknown error"}`);
}

function bindEvents() {
  searchInput.addEventListener("input", onSearchInput);
  searchInput.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      const first = getFilteredSpecialities(searchInput.value)[0];
      if (first) window.location.hash = `#/speciality/${first.id}`;
    }
  });

  backButton.addEventListener("click", () => {
    window.location.hash = "#/";
  });
  similarButton.addEventListener("click", () => {
    window.location.hash = "#/";
  });
  copySkillsButton.addEventListener("click", copyProfessionalSkills);
  themeToggle.addEventListener("click", toggleTheme);
  mirrorThemeButton.addEventListener("click", toggleTheme);
  document.addEventListener("click", (event) => {
    if (!suggestions.contains(event.target) && event.target !== searchInput) {
      suggestions.hidden = true;
    }
  });
  window.addEventListener("hashchange", route);
}

function onSearchInput() {
  const filtered = getFilteredSpecialities(searchInput.value);
  renderSuggestions(filtered.slice(0, 5));
  renderCards(filtered);
}

function getFilteredSpecialities(query) {
  const value = String(query || "").trim().toLowerCase();
  if (!value) return state.specialities.slice();
  return state.specialities.filter((item) => item.title.toLowerCase().includes(value));
}

async function renderMainList(items) {
  const details = await Promise.all(items.map((item) => getSpecialityData(item)));
  const totals = details.reduce(
    (acc, data) => {
      const total = Number(data.statistics?.total || 0);
      acc.vacancies += Number.isNaN(total) ? 0 : total;
      return acc;
    },
    { vacancies: 0 }
  );

  specialitiesCount.textContent = String(items.length);
  vacanciesCount.textContent = String(totals.vacancies);
  renderCards(items);
}

function renderCards(items) {
  specialitiesGrid.innerHTML = "";
  if (!items.length) {
    specialitiesGrid.innerHTML = '<p class="detail-subtitle">Ничего не найдено. Попробуйте изменить запрос.</p>';
    return;
  }

  items.forEach((item) => {
    const card = document.createElement("article");
    card.className = "speciality-card";
    card.addEventListener("click", () => {
      window.location.hash = `#/speciality/${item.id}`;
    });

    const detail = state.detailCache.get(item.id);
    const parsed = parseAiSkills(detail?.ai_analysis?.raw_output || "");
    const topSkills = parsed.professional.slice(0, 3);
    const vacancies = Number(detail?.statistics?.total || 0);
    const subtitle = `${vacancies} вакансий`;

    card.innerHTML = `
      <div class="speciality-top">
        <div class="speciality-head-left">
          <span class="speciality-icon" aria-hidden="true">💼</span>
          <h3 class="speciality-name">${capitalize(item.title || "Специальность")}</h3>
        </div>
        <span class="salary-pill">${formatSalary(detail?.statistics?.avg_salary)}</span>
      </div>
      <p class="speciality-subtitle">${subtitle}</p>
      <div class="chips">${topSkills.map((skill) => `<span class="chip">${escapeHtml(skill)}</span>`).join("") || '<span class="chip">Нет данных</span>'}</div>
    `;
    specialitiesGrid.appendChild(card);
  });
}

function renderSuggestions(items) {
  suggestions.innerHTML = "";
  if (!items.length || !searchInput.value.trim()) {
    suggestions.hidden = true;
    return;
  }

  items.forEach((item) => {
    const li = document.createElement("li");
    li.textContent = item.title;
    li.addEventListener("click", () => {
      searchInput.value = item.title;
      suggestions.hidden = true;
      window.location.hash = `#/speciality/${item.id}`;
    });
    suggestions.appendChild(li);
  });
  suggestions.hidden = false;
}

async function route() {
  const hash = window.location.hash || "#/";
  const detailMatch = hash.match(/^#\/speciality\/(.+)$/);

  if (!detailMatch) {
    mainView.hidden = false;
    detailView.hidden = true;
    return;
  }

  const id = decodeURIComponent(detailMatch[1]);
  const speciality = state.specialities.find((item) => item.id === id);
  if (!speciality) {
    setStatus("Специальность не найдена, возвращаем на главную.");
    window.location.hash = "#/";
    return;
  }

  try {
    const data = await getSpecialityData(speciality);
    state.currentSpecialityId = id;
    renderDetail(speciality, data);
    mainView.hidden = true;
    detailView.hidden = false;
    suggestions.hidden = true;
    setStatus(`Открыта специальность: ${speciality.title}`);
  } catch (error) {
    setStatus(`Ошибка: ${error.message}`);
  }
}

async function getSpecialityData(item) {
  if (state.detailCache.has(item.id)) {
    return state.detailCache.get(item.id);
  }

  const endpoints = [
    `/api/speciality?id=${encodeURIComponent(item.id)}`,
    `/api/speciality.php?id=${encodeURIComponent(item.id)}`,
    `./results/${encodeURIComponent(item.fileName)}`,
  ];

  let data = null;
  let lastError = null;
  for (const endpoint of endpoints) {
    try {
      const response = await fetch(endpoint, { headers: { Accept: "application/json" } });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      data = await response.json();
      break;
    } catch (error) {
      lastError = error;
    }
  }

  if (!data) {
    throw new Error(`Не удалось загрузить данные по специальности: ${lastError?.message || "unknown error"}`);
  }

  state.detailCache.set(item.id, data);
  return data;
}

function renderDetail(meta, data) {
  const stats = data.statistics || {};
  const parsedSkills = parseAiSkills(data.ai_analysis?.raw_output || "");

  detailCrumb.textContent = capitalize(meta.title || "Специальность");
  detailTitle.textContent = capitalize(meta.title || "Специальность");
  detailSubtitle.textContent = `Количество проанализированных вакансий: ${stats.total ?? 0}`;

  totalMetric.textContent = String(stats.total ?? "-");
  avgExpMetric.textContent = formatExperience(stats.avg_experience);
  avgSalaryMetric.textContent = formatSalary(stats.avg_salary);
  salaryRangeMetric.textContent = formatSalaryRange(stats.min_salary, stats.max_salary);

  fillChips(professionalSkills, parsedSkills.professional);
  fillChips(personalSkills, parsedSkills.personal);
}

function fillChips(container, items) {
  container.innerHTML = "";
  if (!items.length) {
    container.innerHTML = '<span class="chip">Нет данных</span>';
    return;
  }
  items.forEach((value) => {
    const chip = document.createElement("span");
    chip.className = "chip";
    chip.textContent = value;
    container.appendChild(chip);
  });
}

async function copyProfessionalSkills() {
  if (!state.currentSpecialityId) return;
  const item = state.specialities.find((entry) => entry.id === state.currentSpecialityId);
  if (!item) return;
  const data = await getSpecialityData(item);
  const parsed = parseAiSkills(data.ai_analysis?.raw_output || "");
  const text = parsed.professional.join(", ");
  if (!text) {
    setStatus("Навыки для копирования не найдены.");
    return;
  }
  try {
    await navigator.clipboard.writeText(text);
    setStatus("Профессиональные навыки скопированы.");
  } catch {
    setStatus("Не удалось скопировать автоматически. Скопируй вручную из списка.");
  }
}

function parseAiSkills(rawOutput) {
  const result = { professional: [], personal: [] };
  const lines = String(rawOutput).split(/\r?\n/);
  let section = "";

  for (const lineRaw of lines) {
    const line = lineRaw.trim();
    if (!line) continue;

    if (line.toLowerCase().includes("профессиональные навыки")) {
      section = "professional";
      continue;
    }
    if (line.toLowerCase().includes("личностные навыки")) {
      section = "personal";
      continue;
    }

    const match = line.match(/^\d+\.\s*(.+)$/);
    if (!match) continue;

    const skill = match[1].trim();
    if (!skill) continue;
    if (section === "professional" && result.professional.length < 5) {
      result.professional.push(skill);
    } else if (section === "personal" && result.personal.length < 5) {
      result.personal.push(skill);
    }
  }

  return result;
}

function formatSalary(value) {
  if (typeof value !== "number" || Number.isNaN(value) || value <= 0) {
    return "Не указана";
  }
  return `${new Intl.NumberFormat("ru-RU").format(value)} ₽`;
}

function formatSalaryRange(min, max) {
  if (
    typeof min !== "number" ||
    Number.isNaN(min) ||
    typeof max !== "number" ||
    Number.isNaN(max) ||
    min <= 0 ||
    max <= 0
  ) {
    return "Не указан";
  }
  const formatter = new Intl.NumberFormat("ru-RU");
  return `${formatter.format(min)} - ${formatter.format(max)} ₽`;
}

function formatExperience(value) {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return "Не указан";
  }
  if (value <= 0) {
    return "Без опыта";
  }
  return `${value} года`;
}

function setStatus(text) {
  statusBox.textContent = text;
}

function setupTheme() {
  const saved = localStorage.getItem("theme");
  if (saved === "dark") document.body.classList.add("dark");
  updateThemeIcons();
}

function toggleTheme() {
  document.body.classList.toggle("dark");
  localStorage.setItem("theme", document.body.classList.contains("dark") ? "dark" : "light");
  updateThemeIcons();
}

function updateThemeIcons() {
  const isDark = document.body.classList.contains("dark");
  const icon = isDark ? "☀️" : "🌙";
  themeToggle.textContent = icon;
  mirrorThemeButton.textContent = icon;
}

function capitalize(value) {
  const text = String(value || "");
  return text ? text[0].toUpperCase() + text.slice(1) : "";
}

function escapeHtml(text) {
  return String(text)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
