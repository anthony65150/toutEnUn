// planning/js/planning.js
// Variables fournies par planning.php :
//   window.PLANNING_DATE_START
//   window.API_MOVE
//   window.API_DELETE


/* ===== Toast utilitaire ===== */
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



/* ===== Drag utilitaires ===== */
let dragPaint = null; // {id,label,color,painted:Set}

function startDragPaint(id, color, label) {
  dragPaint = {
    id: Number(id),
    color: color || "",
    label: label || "Chantier",
    painted: new Set(),
  };
}

function endDragPaint() {
  dragPaint = null;
}

/* ===== Création de chip assigné ===== */
function makeAssign(label, color, chantierId) {
  const span = document.createElement("span");
  span.className = "assign-chip";
  span.style.background = color || "#334155";
  span.dataset.chantierId = chantierId;
  span.innerHTML = `${label} <span class="x" title="Retirer">×</span>`;

  // retirer
span.querySelector(".x")?.addEventListener("click", (e) => {
  e.preventDefault();
  e.stopPropagation();

  const cell = span.closest(".cell-drop");
  if (!cell) return;
  const empId = cell.dataset.emp;
  const date  = cell.dataset.date;

  fetch(window.API_DELETE, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    credentials: "same-origin",
    body: new URLSearchParams({ emp_id: empId, date }),
  })
  .then(async (r) => {
    const raw = await r.text();
    let d;
    try { d = JSON.parse(raw); } catch { throw new Error(`HTTP ${r.status} – ${raw.slice(0, 200)}`); }
    if (!r.ok || !d.ok) throw new Error(d.message || `HTTP ${r.status}`);

    span.remove();
    cell.classList.remove("has-chip");
    toast("Affectation retirée");

    // si week-end et plus de pastille => repasser en OFF
    if (isWeekendISO(date) && !cell.querySelector('.assign-chip')) {
      const off = document.createElement('div');
      off.className = 'cell-off';
      off.dataset.emp  = empId;
      off.dataset.date = date;
      off.innerHTML = '<button type="button" class="wkx" title="Jour non travaillé — activer exceptionnellement">×</button>';
      cell.replaceWith(off);
      attachPaintToOffCell(off);
    }
  })
  .catch((err) => toast(err.message || "Erreur réseau"));
});


  // re-drag
  span.addEventListener("dragstart", (e) => {
    e.dataTransfer.setData("chantier_id", chantierId);
    e.dataTransfer.setData("color", span.style.background || "");
    e.dataTransfer.setData("label", label);
    e.dataTransfer.effectAllowed = "move";
    startDragPaint(chantierId, span.style.background, label);
  });
  span.setAttribute("draggable", "true");

  return span;
}

/* ===== Helpers ===== */
function sameAssignment(cell, chantierId) {
  const cur = cell.querySelector(".assign-chip");
  if (!cur) return false;
  return String(cur.dataset.chantierId || "") === String(chantierId || "");
}

/* ===== Drag sources ===== */
function attachDragSources() {
  // palette
  document.querySelectorAll("#palette .chip").forEach((chip) => {
    chip.addEventListener("dragstart", (e) => {
      e.dataTransfer.setData("chantier_id", chip.dataset.chantierId);
      e.dataTransfer.setData("color", chip.dataset.chipColor || "");
      e.dataTransfer.setData("label", chip.innerText.trim());
      e.dataTransfer.effectAllowed = "copy";
      startDragPaint(
        chip.dataset.chantierId,
        chip.dataset.chipColor,
        chip.innerText.trim()
      );
    });
    chip.addEventListener("dragend", endDragPaint);
  });

  // permettre de re-drag une assignation depuis une cellule
  document.querySelectorAll(".assign-chip").forEach((ch) => {
    ch.addEventListener("dragstart", (e) => {
      const p = ch.closest(".cell-drop");
      e.dataTransfer.setData("chantier_id", ch.dataset.chantierId);
      e.dataTransfer.setData("color", ch.style.background || "");
      e.dataTransfer.setData("label", (ch.childNodes[0]?.nodeValue || "").trim());
      e.dataTransfer.effectAllowed = "move";
      startDragPaint(ch.dataset.chantierId, ch.style.background, ch.innerText.trim());
    });
    ch.setAttribute("draggable", "true");
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

    // Survol = remplir direct si dragPaint actif
    cell.addEventListener("dragenter", (e) => {
      if (!dragPaint) return;
      e.preventDefault();
      const key = cell.dataset.emp + "|" + cell.dataset.date;
      if (dragPaint.painted.has(key)) return;
      dragPaint.painted.add(key);

      // applique la pastille (UI)
      cell.querySelector(".assign-chip")?.remove();
      const chip = makeAssign(dragPaint.label, dragPaint.color, dragPaint.id);
      cell.appendChild(chip);
      cell.classList.add("has-chip");

      // API MOVE (le back met is_active=1)
      fetch(window.API_MOVE, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: new URLSearchParams({
          emp_id: cell.dataset.emp,
          chantier_id: String(dragPaint.id),
          date: cell.dataset.date,
        }),
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) throw new Error(d.message || "Erreur API");
      })
      .catch(err => {
        console.error(err);
        // rollback UI
        chip.remove();
        cell.classList.remove("has-chip");
      });
    });

    // Drop classique (si on lâche pile sur la cellule sans “peinture” active)
    cell.addEventListener("drop", (e) => {
      e.preventDefault();
      cell.classList.remove("dragover");
      if (!dragPaint) {
        const chantierId = Number(e.dataTransfer.getData("chantier_id") || 0);
        const color = e.dataTransfer.getData("color") || "";
        const label = e.dataTransfer.getData("label") || "Chantier";
        if (!chantierId) return;

        cell.querySelector(".assign-chip")?.remove();
        const chip = makeAssign(label, color, chantierId);
        cell.appendChild(chip);
        cell.classList.add("has-chip");

        fetch(window.API_MOVE, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          credentials: "same-origin",
          body: new URLSearchParams({
            emp_id: cell.dataset.emp,
            chantier_id: String(chantierId),
            date: cell.dataset.date,
          }),
        }).catch(console.error);
      }
    });
  });
}


/* ===== Paint pour cell-off (weekend, jours off) ===== */
function attachPaintToOffCell(off) {
  off.addEventListener("dragenter", (e) => {
    if (!dragPaint) return;
    e.preventDefault();
    const active = document.createElement("div");
    active.className = "cell-drop";
    active.dataset.date = off.dataset.date;
    active.dataset.emp = off.dataset.emp;
    off.replaceWith(active);
    attachDropTargets();
    const key = active.dataset.emp + "|" + active.dataset.date;
    if (!dragPaint.painted.has(key)) {
      dragPaint.painted.add(key);
      const chip = makeAssign(dragPaint.label, dragPaint.color, dragPaint.id);
      active.appendChild(chip);
      active.classList.add("has-chip");

      // active le jour + enregistre affectation
      fetch(window.API_PREFILL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams({
          emp_id: active.dataset.emp,
          date: active.dataset.date,
          chantier_id: String(dragPaint.id),
          active: '1'
        })
      }).catch(console.error);

      fetch(window.API_MOVE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams({
          emp_id: active.dataset.emp,
          chantier_id: String(dragPaint.id),
          date: active.dataset.date
        })
      }).catch(console.error);
    }
  });
}



/* ===== Paint pour cell-off (weekend, jours off) ===== */
function attachPaintToOffCell(off) {
  off.addEventListener("dragenter", (e) => {
    if (!dragPaint) return;
    e.preventDefault();

    // transformer OFF -> active
    const active = document.createElement("div");
    active.className = "cell-drop";
    active.dataset.date = off.dataset.date;
    active.dataset.emp  = off.dataset.emp;
    off.replaceWith(active);
    attachDropTargets();

    const key = active.dataset.emp + "|" + active.dataset.date;
    if (!dragPaint.painted.has(key)) {
      dragPaint.painted.add(key);

      // poser la pastille (UI)
      const chip = makeAssign(dragPaint.label, dragPaint.color, dragPaint.id);
      active.appendChild(chip);
      active.classList.add("has-chip");

      // persister l'affectation (MOVE) — back => is_active=1
      fetch(window.API_MOVE, {
        method: 'POST',
        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams({
          emp_id: active.dataset.emp,
          chantier_id: String(dragPaint.id),
          date: active.dataset.date
        })
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) throw new Error(d.message || 'Erreur API');
      })
      .catch(err => {
        console.error(err);
        // rollback UI complet
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
}


/* ===== Filtre employé ===== */
function filterRows(q) {
  q = (q || "").toLowerCase();
  document.querySelectorAll("#gridBody tr").forEach((tr) => {
    const name =
      (tr.querySelector("td:first-child")?.innerText || "").toLowerCase();
    tr.style.display = name.includes(q) ? "" : "none";
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
  const week =
    1 +
    Math.round(((date - firstThu) / 86400000 - 3 + firstThuDay) / 7);
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
});
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

    // week-end -> OFF s'il ne reste plus de pastille
    if (isWeekendISO(dateIso) && !cell.querySelector('.assign-chip')) {
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

