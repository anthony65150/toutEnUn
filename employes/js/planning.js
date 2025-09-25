// planning/js/planning.js
// Exposé par planning.php :
//   window.PLANNING_DATE_START
//   window.API_MOVE
//   window.API_DELETE
let CURRENT_AGENCE = "all";

/* ===== Utils ===== */
function isAbsenceType(t) {
  return ["conges", "maladie", "rtt"].includes(String(t));
}

/* ===== Toast ===== */
function toast(msg) {
  let t = document.getElementById("plan-toast");
  if (!t) {
    t = document.createElement("div");
    t.id = "plan-toast";
    Object.assign(t.style, {
      position: "fixed",
      right: "16px",
      bottom: "16px",
      background: "#0b1220",
      color: "#e5e7eb",
      border: "1px solid rgba(255,255,255,.12)",
      padding: "10px 14px",
      borderRadius: "10px",
      boxShadow: "0 10px 25px rgba(0,0,0,.35)",
      opacity: "0",
      transform: "translateY(8px)",
      transition: ".2s",
      zIndex: 9999,
    });
    document.body.appendChild(t);
  }
  t.textContent = msg;
  requestAnimationFrame(() => {
    t.style.opacity = "1";
    t.style.transform = "translateY(0)";
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
      t.style.opacity = "0";
      t.style.transform = "translateY(8px)";
    }, 1400);
  });
}

/* ===== Drag state ===== */
// { type:'chantier'|'depot'|'conges'|'maladie'|'rtt', id:Number|null, label:String, color:String, painted:Set, mode:'drop'|'paint' }
let dragPaint = null;

function startDragPaint(type, id, color, label, mode) {
  dragPaint = {
    type: type || "chantier",
    id: isAbsenceType(type) ? null : Number(id || 0),
    color: color || "",
    label: label || (type === "depot" ? "Dépôt" : "Chantier"),
    painted: new Set(),
    mode: mode === "paint" ? "paint" : "drop",
  };
}
function endDragPaint() { dragPaint = null; }

/* ===== Chip assigné ===== */
function makeAssign(label, color, type, id) {
  const span = document.createElement("span");
  span.className = "assign-chip" + (type === "depot" ? " assign-chip-depot" : "");
  span.style.background = color || "#334155";
  span.dataset.type = type || "chantier";

  if (type === "depot") {
    span.dataset.depotId = id;
  } else if (type === "chantier") {
    span.dataset.chantierId = id;
  } else if (isAbsenceType(type)) {
    span.dataset.absence = type; // pour re-drag
  }

  span.innerHTML = `${label} <span class="x" title="Retirer">×</span>`;

  // suppression
  span.querySelector(".x")?.addEventListener("click", async (e) => {
    e.preventDefault();
    e.stopPropagation();

    const cell = span.closest(".cell-drop");
    if (!cell) return;
    const empId = cell.dataset.emp;
    const date  = cell.dataset.date;

    try {
      const r = await fetch(window.API_DELETE, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: new URLSearchParams({ emp_id: empId, date }),
      });
      const raw = await r.text();
      let d;
      try { d = JSON.parse(raw); } catch { throw new Error(`HTTP ${r.status} – ${raw.slice(0, 200)}`); }
      if (!r.ok || !d.ok) throw new Error(d.message || `HTTP ${r.status}`);

      span.remove();
      cell.classList.remove("has-chip");
      toast("Affectation retirée");

      // si week-end et plus de pastille => repasser en OFF
      if (typeof isWeekendISO === "function" && isWeekendISO(date) && !cell.querySelector('.assign-chip')) {
        const off = document.createElement('div');
        off.className = 'cell-off';
        off.dataset.emp  = empId;
        off.dataset.date = date;
        off.innerHTML = '<button type="button" class="wkx" title="Jour non travaillé — activer exceptionnellement">×</button>';
        cell.replaceWith(off);
        attachPaintToOffCell(off);
      }
    } catch (err) {
      toast(err.message || "Erreur réseau");
    }
  });

  // re-drag
  span.addEventListener("dragstart", (e) => {
    const t = span.dataset.type || "chantier";
    let idForDnD = "";
    if (t === "depot") {
      idForDnD = span.dataset.depotId || "";
      e.dataTransfer.setData("depot_id", idForDnD);
    } else if (t === "chantier") {
      idForDnD = span.dataset.chantierId || "";
      e.dataTransfer.setData("chantier_id", idForDnD);
    } else if (isAbsenceType(t)) {
      e.dataTransfer.setData("absence", t);
    }
    e.dataTransfer.setData("type", t);
    e.dataTransfer.setData("color", span.style.background || "");
    e.dataTransfer.setData("label", label);
    e.dataTransfer.effectAllowed = "move";

    const mode = e.shiftKey ? "paint" : "drop";
    const idNum = isAbsenceType(t) ? null : Number(idForDnD || 0);
    startDragPaint(t, idNum, span.style.background, label, mode);
    if (mode === "paint") toast("Mode pinceau (Maj)");
  });
  span.setAttribute("draggable", "true");

  return span;
}

/* ===== Drag sources (palettes & chips existants) ===== */
function attachDragSources() {
  // palettes chantiers + dépôts + absences
  document.querySelectorAll("#palette-chantiers .chip, #palette-depots .chip, #palette-absences .chip").forEach((chip) => {
    chip.addEventListener("dragstart", (e) => {
      const rawType = chip.dataset.type || "chantier"; // 'chantier' | 'depot' | 'absence'
      const color   = chip.dataset.chipColor || chip.style.background || "";
      const label   = chip.innerText.trim();

      let typeForPayload = rawType;
      let idForPayload   = 0;

      if (rawType === "depot") {
        idForPayload = Number(chip.dataset.depotId || 0);
        e.dataTransfer.setData("depot_id", String(idForPayload));
      } else if (rawType === "chantier") {
        idForPayload = Number(chip.dataset.chantierId || 0);
        e.dataTransfer.setData("chantier_id", String(idForPayload));
      } else if (rawType === "absence") {
        typeForPayload = chip.dataset.absence || "conges"; // 'conges'|'maladie'|'rtt'
        e.dataTransfer.setData("absence", typeForPayload);
      }

      e.dataTransfer.setData("type", typeForPayload);
      e.dataTransfer.setData("color", color);
      e.dataTransfer.setData("label", label);
      e.dataTransfer.effectAllowed = "copy";

      const mode = e.shiftKey ? "paint" : "drop";
      startDragPaint(typeForPayload, isAbsenceType(typeForPayload) ? null : idForPayload, color, label, mode);
      if (mode === "paint") toast("Mode pinceau (Maj)");
    });
    chip.addEventListener("dragend", endDragPaint);
  });

  // re-drag depuis une cellule
  document.querySelectorAll(".assign-chip").forEach((ch) => {
    ch.addEventListener("dragstart", (e) => {
      const t = ch.dataset.type || "chantier";
      if (t === "depot") {
        e.dataTransfer.setData("depot_id", ch.dataset.depotId || "");
      } else if (t === "chantier") {
        e.dataTransfer.setData("chantier_id", ch.dataset.chantierId || "");
      } else if (isAbsenceType(t)) {
        e.dataTransfer.setData("absence", t);
      }
      e.dataTransfer.setData("type", t);
      e.dataTransfer.setData("color", ch.style.background || "");
      e.dataTransfer.setData("label", (ch.childNodes[0]?.nodeValue || "").trim());
      e.dataTransfer.effectAllowed = "move";

      const mode = e.shiftKey ? "paint" : "drop";
      const id = t === "depot" ? ch.dataset.depotId : (t === "chantier" ? ch.dataset.chantierId : null);
      startDragPaint(t, isAbsenceType(t) ? null : Number(id || 0), ch.style.background, ch.innerText.trim(), mode);
      if (mode === "paint") toast("Mode pinceau (Maj)");
    });
    ch.setAttribute("draggable", "true");
  });
}

/* ===== API ===== */
function persistMove(cell, type, id) {
  const body = new URLSearchParams({
    emp_id: cell.dataset.emp,
    date: cell.dataset.date,
    type: type // 'chantier' | 'depot' | 'conges' | 'maladie' | 'rtt'
  });

  if (type === "depot") {
    body.append("depot_id", String(id || 0));
  } else if (type === "chantier") {
    body.append("chantier_id", String(id || 0));
  } // absences: pas d'ID

  return fetch(window.API_MOVE, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    credentials: "same-origin",
    body,
  }).then(async (r) => {
    const raw = await r.text();
    let d;
    try { d = JSON.parse(raw); } catch { throw new Error(`HTTP ${r.status} – ${raw.slice(0,200)}`); }
    if (!r.ok || !d?.ok) throw new Error(d?.message || `HTTP ${r.status}`);
    return d;
  });
}

/* ===== Drop targets ===== */
function attachDropTargets() {
  document.querySelectorAll(".cell-drop").forEach((cell) => {
    cell.addEventListener("dragover", (e) => {
      e.preventDefault();
      cell.classList.add("dragover");
    });
    cell.addEventListener("dragleave", () => cell.classList.remove("dragover"));

    // Hover paint : seulement si mode "paint"
    cell.addEventListener("dragenter", (e) => {
      if (!dragPaint || dragPaint.mode !== "paint") return;
      e.preventDefault();
      const key = cell.dataset.emp + "|" + cell.dataset.date;
      if (dragPaint.painted.has(key)) return;
      dragPaint.painted.add(key);

      // UI
      cell.querySelector(".assign-chip")?.remove();
      const chip = makeAssign(dragPaint.label, dragPaint.color, dragPaint.type, dragPaint.id);
      cell.appendChild(chip);
      cell.classList.add("has-chip");

      // API
      persistMove(cell, dragPaint.type, dragPaint.id)
        .catch(err => {
          console.error(err);
          chip.remove();
          cell.classList.remove("has-chip");
          toast(err.message || "Échec enregistrement");
        });
    });

    // Drop classique si mode "drop" (par défaut)
    cell.addEventListener("drop", (e) => {
      e.preventDefault();
      cell.classList.remove("dragover");

      // En mode paint, dragenter a déjà fait le boulot → on ignore drop
      if (dragPaint && dragPaint.mode === "paint") return;

      const type  = (dragPaint?.type) || e.dataTransfer.getData("type") || "chantier";
      let id = 0;
      if (type === "depot") {
        id = dragPaint?.id ?? Number(e.dataTransfer.getData("depot_id") || 0);
      } else if (type === "chantier") {
        id = dragPaint?.id ?? Number(e.dataTransfer.getData("chantier_id") || 0);
      } else if (isAbsenceType(type)) {
        id = null;
      }
      const color = (dragPaint?.color) || e.dataTransfer.getData("color") || "";
      const label = (dragPaint?.label) || e.dataTransfer.getData("label") || (type === "depot" ? "Dépôt" : (isAbsenceType(type) ? type.toUpperCase() : "Chantier"));
      if (type === "chantier" && !id) return;

      cell.querySelector(".assign-chip")?.remove();
      const chip = makeAssign(label, color, type, id);
      cell.appendChild(chip);
      cell.classList.add("has-chip");

      persistMove(cell, type, id).catch(console.error);
    });
  });
}

/* ===== Paint pour cell-off (weekend, jours off) ===== */
function attachPaintToOffCell(off) {
  // Autoriser le dragover pour afficher le curseur de drop
  off.addEventListener("dragover", (e) => {
    e.preventDefault();
  });

  // 1) MODE PINCEAU (Maj) : déjà géré au dragenter
  off.addEventListener("dragenter", (e) => {
    if (!dragPaint || dragPaint.mode !== "paint") return;
    e.preventDefault();

    // OFF -> actif
    const active = document.createElement("div");
    active.className = "cell-drop";
    active.dataset.date = off.dataset.date;
    active.dataset.emp  = off.dataset.emp;
    off.replaceWith(active);
    attachDropTargets(); // attache les handlers .cell-drop

    const key = active.dataset.emp + "|" + active.dataset.date;
    if (!dragPaint.painted.has(key)) {
      dragPaint.painted.add(key);

      // UI
      const chip = makeAssign(dragPaint.label, dragPaint.color, dragPaint.type, dragPaint.id);
      active.appendChild(chip);
      active.classList.add("has-chip");

      // API
      persistMove(active, dragPaint.type, dragPaint.id).catch(err => {
        console.error(err);
        chip.remove();
        active.classList.remove("has-chip");
        const back = document.createElement('div');
        back.className = 'cell-off';
        back.dataset.date = active.dataset.date;
        back.dataset.emp  = active.dataset.emp;
        back.innerHTML = '<button type="button" class="wkx" title="Jour non travaillé — activer exceptionnellement">×</button>';
        active.replaceWith(back);
        attachPaintToOffCell(back);
        toast('Échec enregistrement');
      });
    }
  });

  // 2) MODE DROP (par défaut) : activer au moment du drop
  off.addEventListener("drop", (e) => {
    if (!dragPaint || dragPaint.mode !== "drop") return; // si pinceau, dragenter a déjà fait le travail
    e.preventDefault();

    // OFF -> actif
    const active = document.createElement("div");
    active.className = "cell-drop";
    active.dataset.date = off.dataset.date;
    active.dataset.emp  = off.dataset.emp;
    off.replaceWith(active);
    attachDropTargets();

    // Récup des données de la pastille
    const type  = (dragPaint?.type) || e.dataTransfer.getData("type") || "chantier";
    let id = 0;
    if (type === "depot") {
      id = dragPaint?.id ?? Number(e.dataTransfer.getData("depot_id") || 0);
    } else if (type === "chantier") {
      id = dragPaint?.id ?? Number(e.dataTransfer.getData("chantier_id") || 0);
    } else if (isAbsenceType(type)) {
      id = null;
    }
    const color = (dragPaint?.color) || e.dataTransfer.getData("color") || "";
    const label = (dragPaint?.label) || e.dataTransfer.getData("label") ||
                  (type === "depot" ? "Dépôt" : (isAbsenceType(type) ? type.toUpperCase() : "Chantier"));

    // IMPORTANT : ne bloquer que les chantiers sans id, pas les dépôts (id=0 ok)
    if (type === "chantier" && !id) return;

    // UI
    const chip = makeAssign(label, color, type, id);
    active.appendChild(chip);
    active.classList.add("has-chip");

    // API
    persistMove(active, type, id).catch(err => {
      console.error(err);
      chip.remove();
      active.classList.remove("has-chip");
      const back = document.createElement('div');
      back.className = 'cell-off';
      back.dataset.date = active.dataset.date;
      back.dataset.emp  = active.dataset.emp;
      back.innerHTML = '<button type="button" class="wkx" title="Jour non travaillé — activer exceptionnellement">×</button>';
      active.replaceWith(back);
      attachPaintToOffCell(back);
      toast('Échec enregistrement');
    });
  });
}


/* ===== Filtre employé ===== */
function filterRows(q) {
  q = (q || "").toLowerCase();
  document.querySelectorAll("#gridBody tr").forEach((tr) => {
    const name = (tr.querySelector("td:first-child")?.innerText || "").toLowerCase();
    const agId = tr.dataset.agenceId || "0";
    const matchName = name.includes(q);
    const matchAgence =
      CURRENT_AGENCE === "all" ? true : String(agId) === String(CURRENT_AGENCE);
    tr.style.display = (matchName && matchAgence) ? "" : "none";
  });
}

/* ===== Navigation semaines ===== */
document.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-week-shift]");
  if (!btn) return;
  e.preventDefault();

  const nav = document.getElementById("weekNav");
  if (!nav) return;

  const shift = parseInt(btn.dataset.weekShift, 10);
  let year = parseInt(nav.dataset.year, 10);
  let week = parseInt(nav.dataset.week, 10);

  if (shift === 0) {
    const now = new Date();
    ({ week, year } = getISOWeekYear(now));
  } else {
    ({ week, year } = addWeeks(week, year, shift));
  }

  const url = new URL(window.location.href);
  url.searchParams.set("year", year);
  url.searchParams.set("week", week);
  window.location.href = url.toString();
});

// Helpers ISO
function getISOWeekYear(d) {
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const day = (date.getUTCDay() + 6) % 7;
  date.setUTCDate(date.getUTCDate() - day + 3);
  const firstThu = new Date(Date.UTC(date.getUTCFullYear(), 0, 4));
  const firstThuDay = (firstThu.getUTCDay() + 6) % 7;
  const week = 1 + Math.round(((date - firstThu) / 86400000 - 3 + firstThuDay) / 7);
  return { week, year: date.getUTCFullYear() };
}
function isoWeekMonday(year, week) {
  const d = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
  const dow = (d.getUTCDay() + 6) % 7;
  d.setUTCDate(d.getUTCDate() - dow);
  return d;
}
function addWeeks(week, year, shift) {
  const d = isoWeekMonday(year, week);
  d.setUTCDate(d.getUTCDate() + shift * 7);
  return getISOWeekYear(d);
}

/* ===== Init ===== */
document.addEventListener("DOMContentLoaded", () => {
  attachDragSources();
  attachDropTargets();
  document.querySelectorAll(".cell-off").forEach(attachPaintToOffCell);

  const input = document.getElementById("searchInput");
  if (input) {
    let t;
    input.addEventListener("input", (e) => {
      clearTimeout(t);
      const v = e.target.value;
      t = setTimeout(() => filterRows(v), 120);
    });
  }
  // Filtres agence
  const agBar = document.getElementById("agenceFilters");
  if (agBar) {
    agBar.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-agence]");
      if (!btn) return;
      // style actif
      agBar.querySelectorAll("button").forEach(b=>{
        b.classList.remove("btn-primary");
        b.classList.add("btn-outline-secondary");
      });
      btn.classList.remove("btn-outline-secondary");
      btn.classList.add("btn-primary");

      CURRENT_AGENCE = btn.dataset.agence || "all";
      const q = document.getElementById("searchInput")?.value || "";
      filterRows(q);
    });
  }
});

/* ===== Suppression via croix (sélecteur délégué de secours) ===== */
document.addEventListener('click', async (e) => {
  const x = e.target.closest('.assign-chip .x');
  if (!x) return;

  e.preventDefault();
  e.stopPropagation();
  if (e.stopImmediatePropagation) e.stopImmediatePropagation();

  const chip = x.closest('.assign-chip');
  const cell = x.closest('.cell-drop');
  if (!chip || !cell) return;

  chip.setAttribute('draggable', 'false');

  const empId   = cell.dataset.emp;
  const dateIso = cell.dataset.date;

  try {
    const resp = await fetch(window.API_DELETE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: new URLSearchParams({ emp_id: String(empId), date: dateIso })
    });

    const raw = await resp.text();
    let data;
    try { data = JSON.parse(raw); } catch { throw new Error(`HTTP ${resp.status} – ${raw.slice(0,200)}`); }
    if (!resp.ok || !data?.ok) throw new Error(data?.message || `HTTP ${resp.status}`);

    chip.remove();
    cell.classList.remove('has-chip');
    toast('Affectation retirée');

    if (typeof isWeekendISO === "function" && isWeekendISO(dateIso) && !cell.querySelector('.assign-chip')) {
      const off = document.createElement('div');
      off.className = 'cell-off';
      off.dataset.emp  = empId;
      off.dataset.date = dateIso;
      off.innerHTML = '<button type="button" class="wkx" title="Jour non travaillé — activer exceptionnellement">×</button>';
      cell.replaceWith(off);
      attachPaintToOffCell(off);
    }
  } catch (err) {
    console.error(err);
    toast(err.message || 'Erreur réseau pendant la suppression.');
  } finally {
    chip.setAttribute('draggable', 'true');
  }
});
