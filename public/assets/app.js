document.querySelectorAll('[data-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.dialog).showModal()));
document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));

// Spins the submit button's icon and disables it while a plain (non-fetch) form
// submission is in flight, so a request that takes a few seconds still gives
// feedback. The full-page navigation that follows naturally stops it.
document.querySelectorAll('[data-spin-on-submit]').forEach(form => {
  form.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.disabled = true;
    button.classList.add('spinning');
  });
});

document.querySelectorAll('[data-copy-catalog-url]').forEach(button => {
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(button.dataset.copyCatalogUrl);
    } catch (_) {
      const temporary = document.createElement('textarea');
      temporary.value = button.dataset.copyCatalogUrl;
      temporary.style.position = 'fixed';
      temporary.style.opacity = '0';
      document.body.appendChild(temporary);
      temporary.select();
      document.execCommand('copy');
      temporary.remove();
    }
    button.classList.add('copied');
    button.title = 'Catalog URL copied';
    window.setTimeout(() => {
      button.classList.remove('copied');
      button.title = 'Copy catalog URL';
    }, 1800);
  });
});

document.querySelectorAll('.bulk-plugin-form').forEach(form => {
  const boxes = [...form.querySelectorAll('input[name="plugins[]"]')];
  const selectAll = form.querySelector('[data-check-all]');
  const count = form.querySelector('[data-selection-count]');
  const rows = [...form.querySelectorAll('[data-operation-entry]')];
  const search = form.querySelector('[data-operation-search]');
  const chips = [...form.querySelectorAll('[data-operation-filter]')];
  const visibleCount = form.querySelector('[data-operation-visible-count]');
  const clear = form.querySelector('[data-operation-filter-clear]');
  const noMatches = form.querySelector('[data-operation-no-matches]');
  const filterToggle = form.querySelector('[data-operation-filter-toggle]');
  const filterPanel = form.querySelector('.operation-filters');
  const filters = { type: new Set(), maturity: new Set() };
  const update = () => {
    const selected = boxes.filter(box => box.checked).length;
    const visibleBoxes = boxes.filter(box => !box.closest('tr').hidden);
    const visibleSelected = visibleBoxes.filter(box => box.checked).length;
    count.textContent = selected;
    selectAll.checked = visibleBoxes.length > 0 && visibleSelected === visibleBoxes.length;
    selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleBoxes.length;
    selectAll.disabled = visibleBoxes.length === 0;
    boxes.forEach(box => box.closest('tr').classList.toggle('selected', box.checked));
  };
  const applyFilters = () => {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    rows.forEach(row => {
      const types = JSON.parse(row.dataset.filterTypes || '[]');
      const searchMatch = query === '' || row.dataset.searchText.includes(query);
      const typeMatch = filters.type.size === 0 || types.some(type => filters.type.has(type));
      const maturityMatch = filters.maturity.size === 0 || filters.maturity.has(row.dataset.filterMaturity);
      row.hidden = !(searchMatch && typeMatch && maturityMatch);
      if (!row.hidden) visible += 1;
    });
    if (visibleCount) visibleCount.textContent = visible;
    if (noMatches) noMatches.hidden = visible !== 0;
    if (clear) clear.hidden = query === '' && filters.type.size === 0 && filters.maturity.size === 0;
    update();
  };
  selectAll.addEventListener('change', () => {
    boxes.filter(box => !box.closest('tr').hidden).forEach(box => { box.checked = selectAll.checked; });
    update();
  });
  boxes.forEach(box => box.addEventListener('change', update));
  filterToggle?.addEventListener('click', () => {
    const opening = filterPanel.hidden;
    filterPanel.hidden = !opening;
    filterToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening) search?.focus();
  });
  search?.addEventListener('input', applyFilters);
  chips.forEach(chip => chip.addEventListener('click', () => {
    const values = filters[chip.dataset.filterGroup];
    const value = chip.dataset.filterValue;
    values.has(value) ? values.delete(value) : values.add(value);
    const active = values.has(value);
    chip.classList.toggle('active', active);
    chip.setAttribute('aria-pressed', active ? 'true' : 'false');
    applyFilters();
  }));
  clear?.addEventListener('click', () => {
    if (search) search.value = '';
    filters.type.clear();
    filters.maturity.clear();
    chips.forEach(chip => {
      chip.classList.remove('active');
      chip.setAttribute('aria-pressed', 'false');
    });
    applyFilters();
    search?.focus();
  });
  form.addEventListener('submit', event => {
    if (!boxes.some(box => box.checked)) {
      event.preventDefault();
      boxes[0]?.focus();
      alert('Select at least one plugin.');
    }
  });
  applyFilters();
});

const taskWatch = document.querySelector('[data-task-watch-url]');
if (taskWatch) {
  const initialToken = taskWatch.dataset.taskCompletionToken;
  const checkCompletion = async () => {
    try {
      const response = await fetch(taskWatch.dataset.taskWatchUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      if (response.ok) {
        const state = await response.json();
        if (state.completion_token !== initialToken) {
          window.location.reload();
          return;
        }
      }
    } catch (_) {
      // A temporary network error should not disturb the administration page.
    }
    window.setTimeout(checkCompletion, document.hidden ? 10000 : 2500);
  };
  window.setTimeout(checkCompletion, 1000);
}

document.querySelectorAll('[data-catalog-filter-root]').forEach(catalog => {
  const chips = [...catalog.querySelectorAll('[data-catalog-filter]')];
  const entries = [...catalog.querySelectorAll('[data-catalog-entry]')];
  const visibleCount = catalog.querySelector('[data-catalog-visible-count]');
  const clear = catalog.querySelector('[data-catalog-filter-clear]');
  const noMatches = catalog.querySelector('[data-catalog-no-matches]');
  const search = catalog.querySelector('[data-catalog-search]');
  const selected = { type: new Set(), maturity: new Set() };

  const apply = () => {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    entries.forEach(entry => {
      const types = JSON.parse(entry.dataset.filterTypes || '[]');
      const typeMatch = selected.type.size === 0 || types.some(type => selected.type.has(type));
      const maturityMatch = selected.maturity.size === 0 || selected.maturity.has(entry.dataset.filterMaturity);
      const searchMatch = query === '' || entry.dataset.searchText.includes(query);
      entry.hidden = !(searchMatch && typeMatch && maturityMatch);
      if (!entry.hidden) visible += 1;
    });
    if (visibleCount) visibleCount.textContent = visible;
    if (noMatches) noMatches.hidden = visible !== 0;
    if (clear) clear.hidden = query === '' && selected.type.size === 0 && selected.maturity.size === 0;
  };

  search?.addEventListener('input', apply);
  chips.forEach(chip => chip.addEventListener('click', () => {
    const values = selected[chip.dataset.filterGroup];
    const value = chip.dataset.filterValue;
    values.has(value) ? values.delete(value) : values.add(value);
    const active = values.has(value);
    chip.classList.toggle('active', active);
    chip.setAttribute('aria-pressed', active ? 'true' : 'false');
    apply();
  }));
  clear?.addEventListener('click', () => {
    if (search) search.value = '';
    selected.type.clear();
    selected.maturity.clear();
    chips.forEach(chip => {
      chip.classList.remove('active');
      chip.setAttribute('aria-pressed', 'false');
    });
    apply();
    search?.focus();
  });
});

document.querySelectorAll('[data-copy-token]').forEach(button => {
  button.addEventListener('click', async () => {
    const original = button.textContent;
    try {
      await navigator.clipboard.writeText(button.dataset.copyToken);
      button.textContent = 'Copied';
    } catch (_) {
      const temporary = document.createElement('textarea');
      temporary.value = button.dataset.copyToken;
      temporary.style.position = 'fixed';
      temporary.style.opacity = '0';
      document.body.appendChild(temporary);
      temporary.select();
      document.execCommand('copy');
      temporary.remove();
      button.textContent = 'Copied';
    }
    window.setTimeout(() => { button.textContent = original; }, 1600);
  });
});
