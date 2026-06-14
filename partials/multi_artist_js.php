<style>
.multi-artist-widget { position: relative; }
.mas-tags { display: flex; flex-wrap: wrap; gap: 0.3rem; min-height: 1.8rem; padding: 0.2rem 0 0.4rem; }
.mas-tag {
  display: inline-flex; align-items: center; gap: 0.25rem;
  background: var(--accent-light, #dbeafe); color: var(--accent, #2563eb);
  border-radius: 4px; padding: 0.2rem 0.5rem; font-size: 0.82rem; font-weight: 500;
}
.mas-tag button {
  background: none; border: none; cursor: pointer; color: inherit;
  font-size: 1rem; line-height: 1; padding: 0; opacity: 0.7;
}
.mas-tag button:hover { opacity: 1; }
.mas-dropdown {
  position: absolute; top: 100%; left: 0; right: 0; z-index: 200;
  background: var(--card, #fff); border: 1px solid var(--border, #e5e7eb);
  border-radius: 6px; max-height: 220px; overflow-y: auto;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-top: 2px;
}
.mas-opt {
  padding: 0.4rem 0.75rem; cursor: pointer; font-size: 0.88rem;
  display: flex; align-items: center; gap: 0.4rem;
}
.mas-opt:hover { background: var(--bg-alt, #f9fafb); }
.mas-opt.is-selected { font-weight: 600; }
.mas-opt.is-selected::before { content: '✓'; color: var(--accent, #2563eb); font-size: 0.75rem; }
.mas-opt.is-new { color: var(--accent, #2563eb); font-style: italic; }
.mas-search {
  width: 100%; box-sizing: border-box;
}
</style>
<script>
(function() {
function htmlEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

window.initMasWidget = function(uid) {
    const wrap    = document.getElementById(uid + '-wrap');
    const tagEl   = document.getElementById(uid + '-tags');
    const searchEl= document.getElementById(uid + '-search');
    const dropEl  = document.getElementById(uid + '-dropdown');
    const hiddenEl= document.getElementById(uid + '-hidden');
    const fieldName = wrap.dataset.field;
    const allBands  = JSON.parse(wrap.dataset.bands);
    const bandById  = {};
    allBands.forEach(b => { bandById[b.id] = b.name; });

    // selected: Map<id, name> — id is int or 'new:Name'
    const sel = new Map();
    JSON.parse(wrap.dataset.selected).forEach(id => {
        if (bandById[id]) sel.set(id, bandById[id]);
    });

    function renderTags() {
        tagEl.innerHTML = '';
        hiddenEl.innerHTML = '';
        sel.forEach((name, id) => {
            const tag = document.createElement('span');
            tag.className = 'mas-tag';
            const btn = document.createElement('button');
            btn.type = 'button'; btn.textContent = '×'; btn.setAttribute('aria-label', 'Entfernen');
            btn.addEventListener('click', () => { sel.delete(id); renderTags(); });
            tag.appendChild(document.createTextNode(name + ' '));
            tag.appendChild(btn);
            tagEl.appendChild(tag);

            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = fieldName + '[]'; inp.value = id;
            hiddenEl.appendChild(inp);
        });
        // Always send at least one (empty) value so the field is present in POST
        if (sel.size === 0) {
            const emp = document.createElement('input');
            emp.type = 'hidden'; emp.name = fieldName + '[]'; emp.value = '';
            hiddenEl.appendChild(emp);
        }
    }

    function renderDropdown(q) {
        dropEl.innerHTML = '';
        const ql = q.trim().toLowerCase();
        let results = ql
            ? allBands.filter(b => b.name.toLowerCase().includes(ql))
            : allBands.slice(0, 30);

        if (ql) {
            const exactMatch = allBands.some(b => b.name.toLowerCase() === ql);
            if (!exactMatch) {
                const opt = document.createElement('div');
                opt.className = 'mas-opt is-new';
                opt.textContent = '[+ Neu: ' + q.trim() + ']';
                opt.addEventListener('mousedown', e => {
                    e.preventDefault();
                    const newKey = 'new:' + q.trim();
                    sel.set(newKey, q.trim());
                    searchEl.value = '';
                    dropEl.hidden = true;
                    renderTags();
                });
                dropEl.appendChild(opt);
            }
        }

        results.slice(0, 40).forEach(b => {
            const opt = document.createElement('div');
            opt.className = 'mas-opt' + (sel.has(b.id) ? ' is-selected' : '');
            opt.textContent = b.name;
            opt.addEventListener('mousedown', e => {
                e.preventDefault();
                if (sel.has(b.id)) sel.delete(b.id);
                else sel.set(b.id, b.name);
                searchEl.value = '';
                dropEl.hidden = true;
                renderTags();
            });
            dropEl.appendChild(opt);
        });
        dropEl.hidden = dropEl.children.length === 0;
    }

    searchEl.addEventListener('input', () => renderDropdown(searchEl.value));
    searchEl.addEventListener('focus', () => renderDropdown(searchEl.value));
    searchEl.addEventListener('blur',  () => setTimeout(() => { dropEl.hidden = true; }, 200));
    searchEl.addEventListener('keydown', e => {
        if (e.key === 'Escape') { dropEl.hidden = true; searchEl.blur(); }
    });

    renderTags();
};
// Flush any widgets that rendered before this script loaded
(window._masQueue || []).forEach(uid => window.initMasWidget(uid));
window._masQueue = [];
})();
</script>
